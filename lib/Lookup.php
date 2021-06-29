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


namespace OCA\GlobalSiteSelector;


use OCP\Federation\ICloudIdManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ILogger;

/**
 * Class Lookup
 *
 * query the lookup server to find the users location
 *
 * @package OCA\GlobalSiteSelector
 */
class Lookup {

	/** @var IClientService */
	private $httpClientService;

	/** @var  string */
	private $lookupServerUrl;

	/** @var ILogger */
	private $logger;

	/** @var  ICloudIdManager */
	private $cloudIdManager;

	/**
	 * Lookup constructor.
	 *
	 * @param IClientService $clientService
	 * @param IConfig $config
	 * @param ILogger $logger
	 * @param ICloudIdManager $cloudIdManager
	 */
	public function __construct(IClientService $clientService,
								IConfig $config,
								ILogger $logger,
								ICloudIdManager $cloudIdManager
	) {
		$this->httpClientService = $clientService;
		$this->lookupServerUrl = $config->getSystemValue('lookup_server', '');
		$this->logger = $logger;
		$this->cloudIdManager = $cloudIdManager;
	}

	/**
	 * try to find the exact user at the lookup server, we allow to search for
	 * email addresses and federated cloud ids and internal UIDs.
	 *
	 * @param string $uid
	 * @return string the url of the server where the user is located
	 */
	public function search($uid) {

		$result = '';

		// admin need to specify a lookup server with GSS capabilities
		if (empty($this->lookupServerUrl)) {
			$this->logger->error(
				'Can not lookup user, no lookup server registered',
				['app' => 'globalsiteselector']
			);
			return $result;
		}

		try {
			$body = $this->queryLookupServer($uid);

			if (isset($body['federationId'])) {
				$result = $this->getUserLocation($body['federationId']);
			} else {
				$this->logger->debug('search: federationId not set for ' . $uid);
			}

		} catch (\Exception $e) {
			$emsg = var_export($e, true);
			$this->logger->debug('exception caught querying lookup server: ' . $emsg);
		}

		$this->logger->debug('serach: location for ' . $uid . ' is ' . $result);
		return $result;

	}

	/**
	 * query lookup server and return result
	 *
	 * @param $uid
	 * @return mixed
	 * @throws \Exception
	 */
	protected function queryLookupServer($uid) {
		$this->logger->debug('queryLookupServer: asking lookup server for: ' . $uid);
		$client = $this->httpClientService->newClient();
		$response = $client->get(
			$this->lookupServerUrl . '/users',
			$this->configureClient(
				[
					'query' => [
						'search' => $uid,
						'exact'  => '1',
					]
				]
			)
		);

		$body = json_decode($response->getBody(), true);

		return $body;
	}

	/**
	 * split user and remote from federated cloud id
	 *
	 * @param string $address federated share address
	 * @return array [user, remoteURL]
	 * @throws HintException
	 */
	protected function getUserLocation($address) {
		try {
			$cloudId = $this->cloudIdManager->resolveCloudId($address);
			$location = $cloudId->getRemote();
			return rtrim($location, '/');
		} catch (\InvalidArgumentException $e) {
			$hint = $this->l->t('Invalid Federated Cloud ID');
			throw new HintException('Invalid Federated Cloud ID', $hint, 0, $e);
		}
	}


	/**
	 * @param array $options
	 *
	 * @return array
	 */
	public function configureClient(array $options): array {
		return array_merge(
			$options,
			[
				'timeout'         => 10,
				'connect_timeout' => 3,
				'nextcloud'       => ['allow_local_address' => true]
			]
		);
	}

}
