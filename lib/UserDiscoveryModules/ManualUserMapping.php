<?php
/**
 * @copyright Copyright (c) 2018 Bjoern Schiessle <bjoern@schiessle.org>
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

namespace OCA\GlobalSiteSelector\UserDiscoveryModules;
use OCP\IConfig;
use OCP\ILogger;

/**
 * Class ManualUserMapping
 *
 * allows you to manage a local JSON encoded file which maps a arbitrary SAML parameter to
 * a initial location of the user.
 *
 * Therefore you have to define to values in the config.php
 *
 * 'gss.discovery.manual.mapping.file' => '/path/to/json-file'
 *
 * And then there is another optional parameter if you want to use regular expressions:
 *
 * 'gss.discovery.manual.mapping.parameter' => 'uid'
 * 'gss.discovery.manual.mapping.regex' => true
 *
 * @package OCA\GlobalSiteSelector\UserDiscoveryModules
 */
class ManualUserMapping implements IUserDiscoveryModule {

	/** @var string */
	private $idpParameter;
	/** @var string */
	private $file;
	/** @var bool */
	private $useRegularExpressions;
	/** @var ILogger */
	private $logger;

	/**
	 * ManualUserMapping constructor.
	 *
	 * @param IConfig $config
	 */
	public function __construct(IConfig $config, ILogger $logger) {
		$this->idpParameter = $config->getSystemValue('gss.discovery.manual.mapping.parameter', 'uid');
		$this->file = $config->getSystemValue('gss.discovery.manual.mapping.file', '');
		$this->useRegularExpressions = $config->getSystemValue('gss.discovery.manual.mapping.regex', false);
		$this->logger = $logger;

		$this->logger->debug('Init ManualUserMapping');
		$this->logger->debug('IdP Parameter: ' . $this->idpParameter);
		$this->logger->debug('file: ' . $this->file);
		$this->logger->debug('use regular expression: ' . ($this->useRegularExpressions ? 'true' : 'false'));
	}


	/**
	 * get the initial user location
	 *
	 * @param array $data idp parameters
	 * @return string
	 */
	public function getLocation($data) {

		$location = '';
		$dictionary = $this->getDictionary();
		$key = $this->getKey($data);

		$this->logger->debug('Lookup key is: "' . $key . '"');

		// regular lookup
		if (!empty($key) && is_array($dictionary) && !$this->useRegularExpressions) {
			$location = isset($dictionary[$key]) ? $dictionary[$key] : '';
		}

		// dictionary contains regular expressions
		if (!empty($key) && is_array($dictionary) && $this->useRegularExpressions) {
			foreach ($dictionary as $regex => $nextcloudNode) {
				$this->logger->debug('Testing regex: "' . $regex . '"');
				if (preg_match($regex, $key) === 1) {
					$this->logger->debug('Regex matched');
					$location = $nextcloudNode;
					break;
				}
				$this->logger->debug('Regex did not match');
			}
		}

		$this->logger->debug('Location is: "' . $location . '"');

		return $location;
	}

	/**
	 * get dictionary which maps idp parameters to nextcloud nodes
	 *
	 * @return array
	 */
	private function getDictionary() {
		$dictionary = [];
		$isValidFile = !empty($this->file) && file_exists($this->file);
		if ($isValidFile) {
			$mapString = file_get_contents($this->file);
			$dictionary = json_decode($mapString, true);

			if ($dictionary === NULL || !is_array($dictionary)) {
				$this->logger->critical('Your json file at "' . $this->file . '" is not valid!');
			}
		}

		return is_array($dictionary) ? $dictionary : [];
	}

	/**
	 * get key from IDP parameter
	 *
	 * @param array $data idp parameters
	 * @return string
	 */
	private function getKey($data) {
		$key = '';
		if (!empty($this->idpParameter) && isset($data['manual'][$this->idpParameter])) {
			$key = $data['manual'][$this->idpParameter];
			$this->logger->debug('Found idpPrameter ' . $this->idpParameter . ' with value "' . $key . '"');
		} else {
			$debugdata = var_export($data['manual'], true);
			$this->logger->debug('Could not find idpParamter: ' . $this->idpParameter
					    . ' in user data: ' . $debugdata);
		}

		return $this->normalizeKey($key);
	}

	/**
	 * the keys are build like email addresses, we only need the "domain part"
	 *
	 * @param $key
	 * @return string
	 */
	private function normalizeKey($key) {
		$normalized = $key;
		$pos = strrpos($key, '@');
		if ($pos !== false) {
			$normalized = substr($key, $pos+1);
		}

		$this->logger->debug('Normalized key: ' . $normalized);
		return $normalized;
	}
}
