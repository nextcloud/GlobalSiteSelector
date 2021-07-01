<?php
/**
 * @copyright Copyright (c) 2017 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\GlobalSiteSelector\Controller;


use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use OCA\GlobalSiteSelector\Exceptions\MasterUrlException;
use OCA\GlobalSiteSelector\GlobalSiteSelector;
use OCA\GlobalSiteSelector\TokenHandler;
use OCA\GlobalSiteSelector\UserBackend;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\OCSController;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;

/**
 * Class SlaveController
 *
 * Endpoints in case the global site selector operates as a slave
 *
 * @package OCA\GlobalSiteSelector\Controller
 */
class SlaveController extends OCSController {

	/** @var GlobalSiteSelector  */
	private $gss;

	/** @var ILogger */
	private $logger;

	/** @var IUserSession */
	private $userSession;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var ICrypto */
	private $crypto;

	/** @var TokenHandler */
	private $tokenHandler;

	/** @var IUserManager */
	private $userManager;

	/** @var UserBackend */
	private $userBackend;

	/** @var ISession */
	private $session;

	/**
	 * SlaveController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param GlobalSiteSelector $gss
	 * @param ILogger $logger
	 * @param IUserSession $userSession
	 * @param ISession $session
	 * @param IURLGenerator $urlGenerator
	 * @param ICrypto $crypto
	 * @param TokenHandler $tokenHandler
	 * @param IUserManager $userManager
	 * @param UserBackend $userBackend
	 */
	public function __construct($appName,
								IRequest $request,
								GlobalSiteSelector $gss,
								ILogger $logger,
								IUserSession $userSession,
								ISession $session,
								IURLGenerator $urlGenerator,
								ICrypto $crypto,
								TokenHandler $tokenHandler,
								IUserManager $userManager,
								UserBackend $userBackend
	) {
		parent::__construct($appName, $request);
		$this->gss = $gss;
		$this->logger = $logger;
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->crypto = $crypto;
		$this->tokenHandler = $tokenHandler;
		$this->userManager = $userManager;
		$this->userBackend = $userBackend;
		$this->session = $session;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 *
	 * @param string $jwt
	 * @return RedirectResponse
	 */
	public function autoLogin($jwt) {
		try {
			$masterUrl = $this->gss->getMasterUrl();
		} catch (MasterUrlException $e) {
			return new RedirectResponse('');
		}

		if ($this->gss->isMaster()) {
			return new RedirectResponse($masterUrl);
		}

		if ($jwt === '') {
			return new RedirectResponse($masterUrl);
		}

		try {

			list($uid, $password, $options) = $this->decodeJwt($jwt);

			$target = $options['target'];

			if(is_array($options) && isset($options['backend']) && $options['backend'] === 'saml') {
				$this->autoprovisionIfNeeded($uid, $options);
				try {
					$user = $this->userManager->get($uid);
					if(!($user instanceof IUser)) {
						throw new \InvalidArgumentException('User is not valid');
					}
					$user->updateLastLoginTimestamp();
				} catch (\Exception $e) {
					$this->logger->logException($e, ['app' => $this->appName]);
					throw $e;
				}
				$this->session->set('globalScale.uid', $uid);
				$result = true;
			} else {
				$result = $this->userSession->login($uid, $password);
			}
			if ($result === false) {
				throw new \Exception('wrong username or password given for: ' . $uid);
			}

		} catch (ExpiredException $e) {
			$this->logger->info('token expired', ['app' => 'globalsiteselector']);
			return new RedirectResponse($masterUrl);
		} catch (\Exception $e) {
			$this->logger->logException($e, ['app' => 'globalsiteselector']);
			return new RedirectResponse($masterUrl);
		}

		$this->userSession->createSessionToken($this->request, $uid, $uid, null, 0);
		$home = $this->urlGenerator->getAbsoluteURL($target);
		return new RedirectResponse($home);

	}

	/**
	 * Create app token
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function createAppToken($jwt) {

		if($this->gss->getMode() === 'master' || empty($jwt)) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		try {
			list($uid, $password, $options) = $this->decodeJwt($jwt);

			if(is_array($options) && isset($options['backend']) && $options['backend'] === 'saml') {
				$this->autoprovisionIfNeeded($uid, $options);
			}

			if ($this->userManager->userExists($uid)) {
				// if we have a password, we verify it
				if (!empty($password)) {
					$result = $this->userSession->login($uid, $password);
				} else {
					$result = true;
				}
				if ($result) {
					$token = $this->tokenHandler->generateAppToken($uid);
					return new DataResponse($token);
				}
			}
		}  catch (ExpiredException $e) {
			$this->logger->info('Create app password: JWT token expired', ['app' => 'globalsiteselector']);
		} catch (\Exception $e) {
			$this->logger->logException('Create app password: ' . $e, ['app' => 'globalsiteselector']);
		}

		return new DataResponse([], Http::STATUS_BAD_REQUEST);

	}

	/**
	 * decode jwt and return the uid and the password
	 *
	 * @param string $jwt
	 * @return array
	 * @throws \Exception
	 */
	protected function decodeJwt($jwt) {
		$key = $this->gss->getJwtKey();
		$decoded = (array)JWT::decode($jwt, $key, ['HS256']);

		if (!isset($decoded['uid'])) {
			throw new Exception('"uid" not set in JWT');
		}

		if (!isset($decoded['password'])) {
			throw new \Exception('"password" not set in JWT');
		}

		$uid = $decoded['uid'];
		$password = $this->crypto->decrypt($decoded['password'], $key);
		$options = isset($decoded['options']) ? $decoded['options'] : json_encode([]);

		return [$uid, $password, json_decode($options, true)];
	}


	/**
	 * create new user if the user doesn't exist yet on the client node
	 *
	 * @param string $uid
	 * @param array $options
	 */
	protected function autoprovisionIfNeeded($uid, $options) {

		// make sure that a valid UID is given
		if (empty($uid)) {
			$this->logger->error('Uid "{uid}" is not valid.', ['app' => $this->appName, 'uid' => $uid]);
			throw new \InvalidArgumentException('No valid uid given. Given uid: ' . $uid);
		}

		$this->userBackend->createUserIfNotExists($uid);
		$this->userBackend->updateAttributes($uid, $options);

	}

}
