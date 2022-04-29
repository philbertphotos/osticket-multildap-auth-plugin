<?php
require_once (INCLUDE_DIR . 'class.auth.php');
require_once (INCLUDE_DIR . 'class.plugin.php'); //Plugin Local Libary
require_once ('class.AuthLdap.php');
require_once ('class.Mail.php');

define('ospath', str_replace('scp/plugins.php', '', $_SERVER['PHP_SELF']));
define('MULTI_PLUGIN_VERSION', '1.4');

//FOLDERS
define('MULTI_PLUGIN_ROOT', __DIR__ . '/');

require_once ('config.php');

class LdapMultiAuthPlugin extends Plugin {
	var $config_class = 'LdapMultiAuthPluginConfig';
	var $crontime;

	function bootstrap() {
		if ($this->firstRun()) {
			if (!$this->configureFirstRun()) {
				return false;
			}
		}
		else if ($this->needUpgrade()) {
			$this->configureUpgrade();
		}

		$this->loadSync();
		$config = $this->getConfig();
		Signal::connect('cron', array(
			$this,
			'onCronProcessed'
		));
		if ($config->get('multiauth-staff')) StaffAuthenticationBackend::register(new StaffLDAPMultiAuthentication($config));
		if ($config->get('multiauth-client')) UserAuthenticationBackend::register(new ClientLDAPMultiAuthentication($config));
	}

	function loadSync() {
		$sql = 'SELECT FROM ' . PLUGIN_TABLE . 'WHERE isactive=1 AND id=' . db_input($this->getId());
		if (db_num_rows(db_query($sql))) {
			if (!file_exists('../scp/sync_mldap.php') || (filemtime(MULTI_PLUGIN_ROOT.'/sync_mldap.php') != @filemtime('../scp/sync_mldap.php'))) $this->sync_copy();
			include_once ('../scp/sync_mldap.php');
		}
	}

	function millisecsBetween($dateOne, $dateTwo, $abs = true) {
		$func = $abs ? 'abs' : 'intval';
		return $func(strtotime($dateOne) - strtotime($dateTwo)) * 1000;
	}

	function DateFromTimezone($date, $gmt, $timezone, $format) {
		$date = new DateTime($date, new DateTimeZone($gmt));
		$date->setTimezone(new DateTimeZone($timezone));
		return $date->format($format);
	}

	function onCronProcessed() {
		$this->time_zone = db_result(db_query("SELECT value FROM `" . TABLE_PREFIX . "config` WHERE `key` = 'default_timezone'"));

		$sync_info = db_fetch_row(db_query('SELECT value FROM ' . TABLE_PREFIX . 'config 
		WHERE namespace = "plugin.' . $this->id . '" AND `key` = "sync_data";')) [0];

		$jsondata = json_decode($sync_info);
		$schedule = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M:%S", $jsondata->schedule) , 'UTC', $this->time_zone, 'F j, Y, H:i');
		$lastrun = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M:%S", $jsondata->lastrun) , 'UTC', $this->time_zone, 'F j, Y, H:i');
		$this->executed = time();
		$date = new DateTime('now', new DateTimeZone($this->time_zone));

		$this->crontime = $this->millisecsBetween($schedule, $lastrun, false) / 1000 / 60;
		//$this->logger('warning', 'entry', json_encode($entry));

		$this->sync_cron($this->crontime);
			include_once ('../scp/sync_mldap.php');
			$sync = new SyncLDAPMultiClass($this->id);
			//$this->logger('warning', 'Sync Config', $sync->config);

		if ($this->allowAction()) {
			if ($this->getConfig()->get('sync-users') || $this->getConfig()->get('sync-agents')) {
				$excu = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M", $this->lastExec) , 'UTC', $this->time_zone, 'F d Y g:i a');
				$nextexcu = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M", $this->nextExec) , 'UTC', $this->time_zone, 'F d Y g:i a');

				$results = $sync->check_users();
				//$this->logger('warning', 'results', json_encode($results));
				if (empty($results)) {
					$this->logger('warning', 'LDAP Sync', 'Sync executed on (' . ($excu) . ') next execution in (' . $nextexcu . ')');
				}
				else {
					$this->logger('warning', 'LDAP Sync', '<div>Sync executed on (' . ($excu) . ')</div> <div>Next execution in (' . $nextexcu . ')</div>' . "<div>Total ldapusers: (" . $results['totalldap'] . ")</div> <div>Total agents: (" . $results['totalagents'] . ") </div>Total Updated Users: (" . $results['updatedusers'] . ") <div>Execute Time: (" . $results['executetime'] . ")</div>");
				}
			}
		}
	}

	//Sync cron Logic
	function sync_cron($minDelay = false) {
		$sync_info = db_assoc_array(db_query('SELECT * FROM ' . TABLE_PREFIX . 'config 
		WHERE namespace = "plugin.' . $this->id . '" AND `key` = "sync_schedule" OR `key` = "sync_data";') , MYSQLI_ASSOC);
		if ($minDelay) $this->minDelay = $minDelay;

		$output;
		foreach ($sync_info as $info) {
			if ($info['key'] == 'sync_schedule') {
				//$output['schedulestr'] = $info['value']; //strtotime($info['value'] . " " . $this->time_zone);
				$output['format'] = "+".$info['value'];
				$output['scheduleupdate'] = $info['updated'];
			}

			if ($info['key'] == 'sync_data') $output['lastrun'] = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M", json_decode($info['value'])->lastrun) , 'UTC', $this->time_zone, 'Y-m-d H:i');
			$output['schedule'] = json_decode($info['value'])->schedule;
			$output['updated'] = $info['updated'];
		}

		$this->cront = $output;

		$this->lastExec = 0; // it will contain the UNIXTIME of the last action
		$this->nextExec = 0; // it will contain the UNIXTIME of the next action
		$this->secToExec = 0; // it will contain the time in seconds until of the next action
		if (isset($this->cront)) $this->check = true;
		else {
			if (!$this->updateLastrun(time())) $this->check = false;
			else {
				$this->check = true;
			}
		}
	}

	function allowAction() {
		$now = time();
		if ($this->check) $FT = $this->getEventUpdatedTime();
		if ($FT) {
			$nextExec = $FT + ($this->minDelay * 60) - $now;
			if ($nextExec < 0) {
				if (!$this->updateLastrun($now)) return false;
				else {
					$this->lastExec = $now;
					$this->nextExec = $now + ($this->minDelay * 60);
					$this->secToExec = $this->minDelay * 60;
					$this->updateSchedule();
					return true;
				}
			}
			else {
				$this->lastExec = $FT;
				$this->nextExec = $FT + $nextExec;
				$this->secToExec = $nextExec;
				return false;
			}
		}
		else return false;
	}

	//last modification time.
	function getEventUpdatedTime() {
		$updated = db_fetch_row(db_query('SELECT UNIX_TIMESTAMP(updated) as updated FROM ' . TABLE_PREFIX . 'config 
					WHERE namespace = "plugin.' . $this->id . '" AND `key` = "sync_data";')) [0];
		if (isset($updated)) {
			$FT = $updated;
		}
		else {
			$FT = false;
		}
		return $FT;
	}

	function sync_data($key, $val) {
		$json_str = db_fetch_row(db_query('SELECT value FROM ' . TABLE_PREFIX . 'config 
					WHERE namespace = "plugin.' . $this->id . '" AND `key` = "sync_data";')) [0];
		$data = @json_decode($json_str, true);
		if (!is_object($data)) return json_encode(array(
			'schedule' => strtotime($this->cront['format']) ,
			'lastrun' => time()
		));
		$data->$key = $val;
		return json_encode($data);
	}

	function updateLastrun($tme) {
		$data = $this->sync_data('lastrun', $tme);
		$sql = 'UPDATE `' . TABLE_PREFIX . 'config` SET `value` =  \'' . ($data) . '\' , updated = CURRENT_TIMESTAMP
                WHERE `key` = "sync_data" AND `namespace` = "plugin.' . $this->id . '";';
		return db_query($sql);
	}

	function updateSchedule() {
		$data = $this->sync_data('schedule', $this->cront['schedule']);
		$sql = 'UPDATE `' . TABLE_PREFIX . 'config` SET `value` = \'' . ($data) . '\', updated = CURRENT_TIMESTAMP
                        WHERE `key` = "sync_data" AND `namespace` = "plugin.' . $this->id . '";';
		$result = db_query($sql);
		return $result;
	}

	/**
	 * Write information to system LOG
	 *
	 */
	function logger($priority, $title, $message, $verbose = false) {
		if (!empty(self::getConfig()->get('debug-choice')) && self::getConfig()->get('debug-choice') && !$verbose 
		|| (self::getConfig()->get('debug-verbose') && $verbose)) {
				
			if (is_array($message) || is_object($message)) {
				$message = json_encode($message);
			}
			//We are providing only 3 levels of logs. Windows style.
			switch ($priority) {
				case 1:
				case LOG_EMERG:
				case LOG_ALERT:
				case LOG_CRIT:
				case LOG_ERR:
					$level = 1; //Error
					
				break;
				case 2:
				case LOG_WARN:
				case LOG_WARNING:
					$level = 2; //Warning
					
				break;
				case 3:
				case LOG_NOTICE:
				case LOG_INFO:
				case LOG_DEBUG:
				default:
					$level = 3; //Debug
					
			}
			$loglevel = array(
				1 => 'Error',
				'Warning',
				'Debug'
			);
			//Save log based on system log level settings.
			$sql = 'INSERT INTO ' . SYSLOG_TABLE . ' SET created=NOW(), updated=NOW() ' . ',title=' . db_input(Format::sanitize($title, true)) . ',log_type=' . db_input($loglevel[$level]) . ',log=' . db_input(Format::sanitize($message, false)) . ',ip_address=' . db_input($_SERVER['REMOTE_ADDR']);
			db_query($sql, false);
		}
	}

	/**
	 * Checks if this is the first run of our plugin.
	 *
	 * @return boolean
	 */
	function firstRun() {
		//$sql = 'SELECT version FROM ' . PLUGIN_TABLE . ' WHERE `name` LIKE \'%Multi LDAP%\'';
		$sql = "SHOW TABLES LIKE '". TABLE_PREFIX ."ldap_sync'";
		$res = db_query($sql);
		$rows = db_num_rows($res);

		if ($rows <= 0) {
			$this->sync_copy();			
			$this->createSyncTables();
		}
		return (db_num_rows($res) == 0);
	}

	function sync_copy() {
		$file = MULTI_PLUGIN_ROOT.'/sync_mldap.php';
		$newfile = '../scp/sync_mldap.php';
		$path = '../scp';
		if (!file_exists($newfile)){
			//if( chmod($path, 0755)) chmod($path, 0777);
			if(!copy($file,$newfile)){
				$this->logger('error', 'MLA-firstRun', "failed to copy LDAP_Sync API to SCP folder");
				return false;
			}else{
				$this->logger('info', 'MLA-firstRun', "Copied LDAP_Sync API to SCP folder");
				//if( chmod($path, 0777)) chmod($path, 0755);
				return true;
			}
		}
	}

	function updateVersion() {
		$sql = "UPDATE version ' . PLUGIN_TABLE . ' SET version='".MULTI_PLUGIN_VERSION."' WHERE `name` LIKE '%Multi LDAP%'";
		if (!($res = db_query($sql))) {
			return true;
		}

		return false;
	}
	
	function needUpgrade() {
		$sql = 'SELECT version FROM ' . PLUGIN_TABLE . ' WHERE `name` LIKE \'%Multi LDAP%\'';
		if (!($res = db_query($sql))) {
			return true;
		}
		else {
			$ht = db_fetch_array($res);
			if (floatval($ht['version']) < floatval(MULTI_PLUGIN_VERSION)) {
				return true;
			}
		}
		return false;
	}

	function configureUpgrade() {

	}

	/**
	 * Necessary functionality to configure first run of the application
	 */
	function configureFirstRun() {
		//$this->logger('warning', 'configureFirstRun', 'config');
			return $this->sync_copy();
	}

	/**
	 * Kicks off database installation scripts
	 *
	 * @return boolean
	 */
	function createSyncTables() {
		db_query("DROP TABLE IF EXISTS " . TABLE_PREFIX . "ldap_sync");
		$sqlsync = ("CREATE TABLE " . TABLE_PREFIX . "ldap_sync (
				  `id` bigint(20) unsigned NOT NULL,
				  `guid` varchar(40) NOT NULL,
				  `updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				  PRIMARY KEY (`id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
		$this->logger('warning', 'MLA-createSyncTables', $sqlsync);
		$result = db_query($sqlsync);
		if ($result) {
			return true;
		}
		return false;
	}

	/**
	 * Uninstall hook.
	 *
	 * @param type $errors
	 * @return boolean
	 */
	function pre_uninstall(&$errors) {
		$this->logger('warning', 'MLA-uninstall', $errors);
		db_query("DROP TABLE IF EXISTS " . TABLE_PREFIX . "ldap_sync");
		$result = unlink('../scp/sync_mldap.php');
		//if (!$result) return true;
		return true;
	}
}

class LDAPMultiAuthentication {

	var $config;
	var $type = 'staff';

	function __construct($config, $type = 'staff') {
		$this->config = $config;
		$this->type = $type;
	}
	function getConfig() {
		return $this->config;
	}

	function getServers() {
		if (!empty($servers = $this->getConfig()
			->get('servers'))) {
			return preg_split('/\s+/', $servers);
		}
	}

	function getDomain() {
		if (!empty($shortdomain = $this->getConfig()
			->get('shortdomain'))) {
			return preg_split(',', $shortdomain);
		}
	}

	function multi_re_key(&$array, $old_keys, $new_keys) {
		if (!is_array($array)) {
			($array == "") ? $array = array() : false;
			return $array;
		}
		foreach ($array as & $arr) {
			if (is_array($old_keys)) {
				foreach ($new_keys as $k => $new_key) {
					(isset($old_keys[$k])) ? true : $old_keys[$k] = NULL;
					$arr[$new_key] = (isset($arr[$old_keys[$k]]) ? $arr[$old_keys[$k]] : null);
					unset($arr[$old_keys[$k]]);
				}
			}
			else {
				$arr[$new_keys] = (isset($arr[$old_keys]) ? $arr[$old_keys] : null);
				unset($arr[$old_keys]);
			}
		}
		return $array;
	}

	function keymap($arr) {
		$keys = ($this->multi_re_key($arr, array(
			'sAMAccountName',
			'givenName',
			'sn',
			'displayName',
			'mail',
			'telephoneNumber',
			'distinguishedName',
		) , array(
			'username',
			'first',
			'last',
			'full',
			'email',
			'phone',
			'dn',
		)));
		return $keys;
	}

	function adschema() {
		return array(
			'sAMAccountName',
			'sn',
			'givenName',
			'displayName',
			'mail',
			'telephoneNumber',
			'distinguishedName'
		);
	}

	function setConnection() {
		$ldap = new AuthLdap();
		$ldap->serverType = 'ActiveDirectory';
		return $ldap;
	}
	function ldapenv($ldapinfo) {
		$ldap = new AuthLdap();
		$ldap->serverType = 'ActiveDirectory';
		$ldap->server = preg_split('/;|,/', $data['servers']);
		$ldap->dn = $data['dn'];
		return $ldap;
	}

	function connectcheck($ldapinfo) {
		$conninfo = array();
		foreach ($ldapinfo as $data) {
			$ldap = new AuthLdap();
			$ldap->serverType = 'ActiveDirectory';
			$ldap->server = preg_split('/;|,/', $data['servers']);
			$ldap->domain = $data['sd'];
			$ldap->dn = $data['dn'];

			if ($ldap->connect()) {
				$conninfo[] = array(
					'bool' => true,
					'msg' => $data['sd'] . ' Connected OK!'
				);
			}
			else {
				$conninfo[] = array(
					false,
					$data['sd'] . " error:" . $ldap->ldapErrorCode . ": " . $ldap->ldapErrorText
				);
			}
		}
		return $conninfo;
	}

	function flatarray($values) {
		$object = array();
		foreach ($values[0] as $key => $value) {
			if (preg_match('/(?<!\S)\d{1,2}(?![^\s.,?!])/', $key) > 0 || $key == 'count') continue;
			$object[$key] = $value[0];
		}
		return $object;
	}

	function ldapinfo() {
		$ldapinfo;
		foreach (preg_split('/;/', $this->getConfig()
			->get('basedn')) as $i => $dn) {
			$dn = trim($dn);
			$servers = $this->getConfig()
				->get('servers');
			$serversa = preg_split('/\s+/', $servers);

			$sd = $this->getConfig()
				->get('shortdomain');
			$sda = preg_split('/;|,/', $sd);

			$bind_dn = $this->getConfig()
				->get('bind_dn');
			$bind_dna = preg_split('/;/', $bind_dn) [$i];

			$bind_pw = $this->getConfig()
				->get('bind_pw');
			$bind_pwa = preg_split('/;|,/', $bind_pw) [$i];

			$ldapinfo[] = array(
				'dn' => $dn,
				'sd' => $sda[$i],
				'servers' => trim($serversa[$i]) ,
				'bind_dn' => trim($bind_dna) ,
				'bind_pw' => trim($bind_pwa)
			);
		}
		return $ldapinfo;
	}

	function authenticate($username, $password = null) {
		if (!$password) {
			LdapMultiAuthPlugin::logger('info', 'auth (' . $username . ')', "");
			return null;
		}
		//check if they used their email to login.
		if (!filter_var($username, FILTER_VALIDATE_EMAIL) === false) {
			$username = explode('@', $username) [0];
		}

		$ldapinfo = $this->ldapinfo();

		$chkUser = null;
		$ldap = new AuthLdap();
		foreach ($ldapinfo as $data) {
			$ldap->serverType = 'ActiveDirectory';
			$ldap->server = preg_split('/;|,/', $data['servers']);
			$ldap->domain = $data['sd'];
			$ldap->dn = $data['dn'];
			if ($ldap->connect()) {
				$conninfo[] = array(
					'bool' => true,
					'msg' => 'System connected to (' . $data['sd'] . ')'
				);
			}
			else {
				$conninfo['bool'] = false;
				$conninfo['msg'] = ($data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText);
				LdapMultiAuthPlugin::logger(LOG_INFO, 'connect error (' . $username . ')', $conninfo['msg']);
				continue;
			}

			if ($chkUser = $ldap->checkPass($username, $password) != false) {

				$loginfo[] = array(
					'bool' => $chkUser,
					'msg' => 'User authenticated on (' . $data['sd'] . ')'
				);
			}
			else {
				$loginfo[] = array(
					'bool' => $chkUser,
					'msg' => ($data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText)
				);
				continue;
			}

			$ldap->searchUser = $data['bind_dn'];
			$ldap->searchPassword = $data['bind_pw'];

			$user_info = $ldap->getUsers($username, $this->adschema()); //Update Debug Logs
			if ($chkUser) break; //Break if user autenticated
			
		} //end foreach
		if (($conninfo['bool'] == false || $loginfo['bool'] == false) && !$chkUser) {
			$errmsg;
			foreach ($loginfo as $err) {
				$errmsg .= $err['msg'] . " ";
			}

			LdapMultiAuthPlugin::logger(LOG_INFO, 'login error (' . $username . ')', trim($errmsg));
		}
		if ($chkUser) {
			if (!empty($user_info)) LdapMultiAuthPlugin::logger(LOG_INFO, 'ldap login (' . $username . '['.$this->type.'])', $loginfo[0]['msg']);
			return $this->authOrCreate($username);
		}
		else {
			return;
		}
	}

	function authOrCreate($username) {
		global $cfg, $ost;
		//$registration = $ost->config->config[client_registration]->ht[value];
		switch ($this->type) {
			case 'staff':
				if (($user = StaffSession::lookup($username)) && $user->getId()) {
					if (!$user instanceof StaffSession) {
						// osTicket <= v1.9.7 or so
						$user = new StaffSession($user->getId());
					}
					return $user;
				}
				else {
					$staff_groups = preg_split('/;|,/', $this->config->get('multiauth-staff-group'));
					$chkgroup;
					foreach ($staff_groups as $staff_group) {
						if ($ldap->checkGroup($name, $staff_group)) {
							$chkgroup = true;
							break;
						}
					}
					
					if ($config->get('multiauth-staff-register') && $chkgroup) {
						if (!($info = $this->search($username, false))) {
							return;
						}
						$errors = array();
						$staff = array();
						$staff['username'] = $info['username'];
						$staff['firstname'] = $info['first'];
						$staff['lastname'] = $info['last'];
						$staff['email'] = $info['email'];
						$staff['isadmin'] = 0;
						$staff['isactive'] = 1;
						$staff['group_id'] = 1;
						$staff['dept_id'] = 1;
						$staff['welcome_email'] = "on";
						$staff['timezone_id'] = 8;
						$staff['isvisible'] = 1;
						Staff::create($staff, $errors);
						if (($user = StaffSession::lookup($username)) && $user->getId()) {
							if (!$user instanceof StaffSession) {
								$user = new StaffSession($user->getId());
							}
							return $user;
						}
					}
				}
			break;
			case 'client':
				// Lookup all the information on the user. Try to get the email
				// addresss as well as the username when looking up the user
				// locally.

				if (!($info = $this->search($username))) {
					LdapMultiAuthPlugin::logger(LOG_INFO, 'ldap info (' . $username . ')', json_encode($info));
				return;
				}
				
				$acct = ClientAccount::lookupByUsername($username);

				if ($acct && $acct->getId()) {
					$client = new ClientSession(new EndUser($acct->getUser()));
					//LdapMultiAuthPlugin::logger(LOG_INFO, 'ldap acct (' . $username . ')', json_encode($acct));
					LdapMultiAuthPlugin::logger(LOG_INFO, 'ldap session (' . $username . ')', json_encode($client));
				}
				
				if (!$client) {

					$info['name'] = $info['first'] . " " . $info['last'];
					$info['email'] = $info['email'];
					$info['full'] = $info['full'];
					$info['first'] = $info['first'];
					$info['last'] = $info['last'];
					$info['username'] = $info['username'];
					$info['dn'] = $info['dn'];

					$client = new ClientCreateRequest($this, $username, $info);
					LdapMultiAuthPlugin::logger(LOG_INFO, 'ldap client (' . $username . ')', json_encode($info));
					//if (!$cfg || !$cfg->isClientRegistrationEnabled() && self::$config->get('multiauth-force-register')) {
					// return $client->attemptAutoRegister();
					//}
					
				}
				return $client;
			}
			return null;
	}

	function create_account($username, $type) {
	}
	
	function convert_user($ldap, $username) {
		$filter = '(mail={q})';
		if ($user_info = $ldap->getUsers($this->$username, $this->adschema() , $filter))

		$name = $user_info[0]['givenName'] . ' ' . $user_info[0]['sn'];

		$user_info[0]['name'] = $name;

		$auth_user = $this->keymap($user_info);

		return $auth_user;
	}

	function lookup($lookup_dn) {
		$lookup_user = array();
		preg_match('/(dc=(?:[^C]|C(?!N=))*)(?:;|$)/i', $lookup_dn, $match);
		//preg_match('/(dc=)(.*?),.*/i', $lookup_dn, $match);
		LdapMultiAuthPlugin::logger(LOG_DEBUG, 'ldap-lookup (' . $lookup_dn . ')', $lookup_dn);
		$base_dn = strtolower($match[0]);

		$key = array_search($base_dn, preg_split('/;/', strtolower($this->getConfig()
			->get('basedn'))));

		$key = (!isset($key) || is_null($key)) ? 0 : $key;

		$dn = trim($base_dn);

		$servers = $this->getConfig()
			->get('servers');
		$serversa = preg_split('/\s+/', $servers) [$key];

		$sd = $this->getConfig()
			->get('shortdomain');
		$sda = preg_split('/;|,/', $sd) [$key];

		$bind_dn = $this->getConfig()
			->get('bind_dn');
		$bind_dna = preg_split('/;/', $bind_dn) [$key];

		$bind_pw = $this->getConfig()
			->get('bind_pw');
		$bind_pwa = preg_split('/;|,/', $bind_pw) [$key];

		$data = array(
			'dn' => trim($dn) ,
			'sd' => trim($sda) ,
			'servers' => trim($serversa) ,
			'bind_dn' => trim($bind_dna) ,
			'bind_pw' => trim($bind_pwa)
		);

		$ldap = new AuthLdap();
		$ldap->serverType = 'ActiveDirectory';
		$ldap->server = preg_split('/;|,/', $data['servers']);
		$ldap->dn = $data['dn'];
		$ldap->searchUser = $data['bind_dn'];
		$ldap->searchPassword = $data['bind_pw'];

		if ($ldap->connect()) {
			$filter = '(distinguishedName={q})';
			if ($temp_user = $ldap->getUsers(($lookup_dn) , $this->adschema() , $filter)) {

				$lookup_user = $this->keymap($temp_user);
			}
			else {
				$conninfo[] = array(
					false,
					$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
				);

				LdapMultiAuthPlugin::logger('info', 'ldap-UserconnInfo', $conninfo[1]);
			}
		}
		else {
			$conninfo[] = array(
				false,
				$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
			);

			LdapMultiAuthPlugin::logger('info', 'ldap-ConnInfo', $conninfo);
		}
		$lookup_user = self::flatarray($lookup_user);
		$lookup_user['name'] = $lookup_user['full'];
		//$lookup_user['office'] = $lookup_user['full'];
		//LdapMultiAuthPlugin::logger('info', 'LookupInfo',($lookup_user), true);
		return $lookup_user;
	}

	function search($query) {
		$userlist = array();
		$combined_userlist = array();
		$ldapinfo = $this->ldapinfo();

		foreach ($ldapinfo as $data) {
			$ldap = new AuthLdap();
			$ldap->serverType = 'ActiveDirectory';
			$ldap->server = preg_split('/;|,/', $data['servers']);
			$ldap->dn = $data['dn'];
			$ldap->searchUser = $data['bind_dn'];
			$ldap->searchPassword = $data['bind_pw'];

			//LdapMultiAuthPlugin::logger('debug', 'ldap query(' . $query . ')', $ldap->dn . json_encode($ldap));
			if ($ldap->connect()) {
				$filter = self::getConfig()->get('search_base');
				if ($userlist = $ldap->getUsers($query, $this->adschema() , $filter)) {
					$temp_userlist = $this->keymap($userlist);
					$combined_userlist = array_merge($combined_userlist, self::flatarray($temp_userlist));
				//LdapMultiAuthPlugin::logger('debug', 'search filter(' . $query . ')', $ldap->dn . json_encode($filter));
				//LdapMultiAuthPlugin::logger('debug', 'search query(' . $query . ')', $ldap->dn . json_encode($userlist));
				//LdapMultiAuthPlugin::logger('debug', 'search query(' . $query . ')', $ldap->dn . " - " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText);
				} else {
					LdapMultiAuthPlugin::logger('debug', 'search-error', $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText);
				}
			} else {
				$conninfo[] = array(
					false,
					$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
				);
				LdapMultiAuthPlugin::logger('info', 'search-error', $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText);
			}
		}
		LdapMultiAuthPlugin::logger(LOG_DEBUG, 'ldap-search (' . $query . ')', json_encode($combined_userlist), true);
		return $combined_userlist;
	}
}
class StaffLDAPMultiAuthentication extends StaffAuthenticationBackend implements AuthDirectorySearch {
	static $name = "LDAP Authentication";
	static $id = "ldap";
	function __construct($config) {
		$this->_ldap = new LDAPMultiAuthentication($config);
		$this->config = $config;
	}
	function authenticate($username, $password = false, $errors = array()) {
		return $this
			->_ldap
			->authenticate($username, $password);
		//queries the user information
		
	}
	function getName() {
		$config = $this->config;
		list($__, $_N) = $config::translate();
		return $__(static ::$name);
	}
	//adding new users
	function lookup($query) {
		//LdapMultiAuthPlugin::logger('info', 'lookup', $query, true);
		$list = $this
			->_ldap
			->lookup($query);
		if ($list) {
			$list['backend'] = static ::$id;
			$list['id'] = static ::$id . ':' . $list['dn'];
		}
		LdapMultiAuthPlugin::logger('info', 'lookup-result', $list, true);
		return ($list);
	}
	//General searching of users
	function search($query) {
		//LdapMultiAuthPlugin::logger('info', 'search', $query, true);
		if (strlen($query) < 3) return array();
		$list = array(
			$this
				->_ldap
				->search($query)
		);
		foreach ($list as & $l) {
			$l['backend'] = static ::$id;
			$l['id'] = static ::$id . ':' . $l['dn'];
		}
		LdapMultiAuthPlugin::logger('debug', 'search-result', $list, true);
		return $list;
	}
}
class ClientLDAPMultiAuthentication extends UserAuthenticationBackend {
	static $name = "LDAP Authentication";
	static $id = "ldap.client";
	function __construct($config) {
		$this->_ldap = new LDAPMultiAuthentication($config, 'client');
		$this->config = $config;
		if ($domain = $config->get('basedn')) self::$name .= sprintf(' (%s)', $domain);
	}
	function getName() {
		//LdapMultiAuthPlugin::logger('info', 'getName', $this->config);
		$config = $this->config;
		list($__, $_N) = $config::translate();
		return $__(static ::$name);
	}
	function authenticate($username, $password = false, $errors = array()) {
		//LdapMultiAuthPlugin::logger('info', 'authenticateclient', $username);
		$object = $this
			->_ldap
			->authenticate($username, $password);
		if ($object instanceof ClientCreateRequest) $object->setBackend($this);
		return $object;
	}
}
