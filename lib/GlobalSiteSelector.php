<?php

declare(strict_types=1);


/**
 * @copyright Copyright (c) 2017 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 * @author Maxence Lange <maxence@artificial-owl.com>
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


use OCA\GlobalSiteSelector\Exceptions\MasterUrlException;
use OCP\IConfig;

/**
 * Class GlobalSiteSelector
 *
 * manage the global site selector
 *
 * @package OCA\GlobalSiteSelector
 */
class GlobalSiteSelector {


	const MASTER = 'master';
	const SLAVE = 'slave';


	/** @var  IConfig */
	private $config;

	/**
	 * GlobalSiteSelector constructor.
	 *
	 * @param IConfig $config
	 */
	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	/**
	 * the global site selector can operate as 'master' or 'slave'
	 *
	 * @return string
	 */
	public function getMode(): string {
		return strtolower($this->config->getSystemValue('gss.mode', self::SLAVE));
	}

	/**
	 * @return bool
	 */
	public function isMaster(): bool {
		return ($this->getMode() === self::MASTER);
	}

	/**
	 * @return bool
	 */
	public function isSlave(): bool {
		return ($this->getMode() === self::SLAVE);
	}


	/**
	 * get JWT key
	 *
	 * @return string
	 */
	public function getJwtKey(): string {
		// TODO: returns exception if non-existant
		return $this->config->getSystemValue('gss.jwt.key', '');
	}


	/**
	 * get the URL of the global site selector master
	 *
	 * @return string
	 * @throws MasterUrlException
	 */
	public function getMasterUrl(): string {
		$masterUrl = $this->config->getSystemValue('gss.master.url', '');
		if ($masterUrl === '') {
			throw new MasterUrlException();
		}

		return $masterUrl;
	}


	/**
	 * get lookup server URL
	 *
	 * @return string
	 */
	public function getLookupServerUrl(): string {
		// TODO: returns exception if non-existant
		return $this->config->getSystemValue('lookup_server', '');
	}

}

