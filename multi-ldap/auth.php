<?php 
 //load various classes
foreach ([
	'plugin','email', 'csrf', 'signal'
] as $c) {
	require_once INCLUDE_DIR . "class.$c.php";
}
	
require_once ('class.AuthLdap.php');

//FOLDERS
define('MULTI_PLUGIN_ROOT', __DIR__ . '/');

require_once ('config.php');


class LdapMultiAuthPlugin extends Plugin {
	var $config_class = 'LdapMultiAuthPluginConfig';
	var $crontime;
		
	function bootstrap() {
		$this->plugininstance();
		if ($this->firstRun()) {
			if (!$this->configureFirstRun()) {
				return false;
			}
		}
		else if ($this->needUpgrade()) {}

		$this->loadSync();
		$config = $this->getConfig($this->instance->ins);
		$id = $this->id;
		Signal::connect('cron', array(
			$this,
			'onCronProcessed'
		));
			
		if ($config->get('multiauth-staff')) StaffAuthenticationBackend::register(new StaffLDAPMultiAuthentication($config));
		if ($config->get('multiauth-client')) UserAuthenticationBackend::register(new ClientLDAPMultiAuthentication($config));
	}
	
	//Checks if osticket supports instances
	function plugininstance() {
			$this->instance = new stdClass();
		if (method_exists($this,'getInstances')) {
			$ins = $this->getInstances($this->id)->key['plugin_id'];
			$this->instance->plugin = "plugin.".$this->id.".instance.".$ins;
			$this->instance->backend = ".p".$this->id."i".$ins;
			$this->instance->staff = ".p".$this->id."i".$ins;
			$this->instance->ins = $this->getInstances()->first();
		} else {
			$this->instance->plugin = "plugin.".$this->id;
		}
	}
	
	function loadSync() {
		$sql = "SELECT * FROM " . PLUGIN_TABLE . " WHERE `isactive`=1 AND `id`='" . $this->id . "'";
		if (db_num_rows(db_query($sql))) {
			if (!file_exists(ROOT_DIR.'scp/sync_mldap.php') || (md5_file(MULTI_PLUGIN_ROOT.'sync_mldap.php') != @md5_file(ROOT_DIR.'scp/sync_mldap.php'))){
				$this->sync_copy();
				//$this->logger('warning', 'Sync Copy', '');
			}
			include_once (ROOT_DIR.'scp/sync_mldap.php');
		}
	}
	
	function millisecsBetween($dateOne, $dateTwo, $abs = true) {
		$func = $abs ? 'abs' : 'intval';
		return $func(strtotime($dateOne) - strtotime($dateTwo)) * 1000;
	}

	static function DateFromTimezone($date, $gmt, $timezone, $format) {
		$date = new DateTime($date, new DateTimeZone($gmt));
		$date->setTimezone(new DateTimeZone($timezone));
		return $date->format($format);
	}
		
	function onCronProcessed() {
		global $ost;
		$this->time_zone = db_result(db_query("SELECT value FROM `" . TABLE_PREFIX . "config` WHERE `key` = 'default_timezone'"));		
		
		
		$sync_info = db_fetch_row(db_query('SELECT value FROM ' . TABLE_PREFIX . 'config WHERE namespace = "' . $this->instance->plugin . '" AND `key` = "sync_data";')) [0];		
		
		$jsondata = json_decode($sync_info);
		$schedule = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M:%S", $jsondata->schedule) , 'UTC', $this->time_zone, 'F j, Y, H:i');
		$lastrun = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M:%S", $jsondata->lastrun) , 'UTC', $this->time_zone, 'F j, Y, H:i');
		$this->executed = time();
		$date = new DateTime('now', new DateTimeZone($this->time_zone));

		$this->crontime = $this->millisecsBetween($schedule, $lastrun, false) / 1000 / 60;

		$this->sync_cron($this->crontime);
		$this->loadSync();
			
			//Load Sync info
			$sync = new SyncLDAPMultiClass($this->instance);	
		//$this->logger('warning', 'Check Sync', json_encode($this->allowAction()));
		if ($this->allowAction()) {
			if ($this->getConfig($this->instance->ins)->get('sync-users') || $this->getConfig($this->instance->ins)->get('sync-agents')) {
				//$this->logger('warning', 'Check Sync1', json_encode($this->allowAction()));
				$excu = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M", $this->lastExec) , 'UTC', $this->time_zone, 'F d Y g:i a');
				$nextexcu = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M", $this->nextExec) , 'UTC', $this->time_zone, 'F d Y g:i a');
				$results = $sync->check_users();
				//$this->logger('warning', 'Check Results', json_encode($results));
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
		//outputs both keys in array
		$sync_info = db_assoc_array(db_query('SELECT * FROM ' . TABLE_PREFIX . 'config 
		WHERE namespace = "' . $this->instance->plugin . '" AND `key` = "sync_schedule" OR `key` = "sync_data";') , MYSQLI_ASSOC);
		$this->minDelay = NULL;
		if ($minDelay) $this->minDelay = $minDelay;

		$output;
		foreach ($sync_info as $info) {
			if ($info['key'] == 'sync_schedule') {
				$output['schedule'] = $info['value'];
				$output['format'] = $info['value'];
			}

			if ($info['key'] == 'sync_data') {
				$val = json_decode($info['value']);
				$output['lastrun'] = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M", $val->lastrun) , 'UTC', $this->time_zone, 'Y-m-d H:i');
				$output['schedule'] = $val->schedule;
				$output['updated'] = $info['updated'];
			}
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
					WHERE namespace = "' . $this->instance->plugin . '" AND `key` = "sync_data";')) [0];
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
					WHERE namespace = "' . $this->instance->plugin . '" AND `key` = "sync_data";')) [0];
		$data = @json_decode($json_str, true);
		if (!is_object($data)) return json_encode(array(
			'schedule' => strtotime($this->cront['format']) ,
			'lastrun' => time()
		));
		$data->$key = $val;
		return json_encode($data);
	}

	function updateLastrun($tme) {
		global $ost;
		$data = $this->sync_data('lastrun', $tme);
		$sql = 'UPDATE `' . TABLE_PREFIX . 'config` SET `value` =  \'' . ($data) . '\' , updated = CURRENT_TIMESTAMP
                WHERE `key` = "sync_data" AND `namespace` = "' . $this->instance->plugin . '";';
				//$ost->logWarning('updateLastrun', ($sql), false);
		return db_query($sql);
	}

	function updateSchedule() {
		$data = $this->sync_data('schedule', $this->cront['schedule']);
		$sql = 'UPDATE `' . TABLE_PREFIX . 'config` SET `value` = \'' . ($data) . '\', updated = CURRENT_TIMESTAMP
                        WHERE `key` = "sync_data" AND `namespace` = "' . $this->instance->plugin . '";';
		$result = db_query($sql);
		return $result;
	}
 
 	/**
	 * Checks if this is the first run of our plugin.
	 *
	 * @return boolean
	 */
	function firstRun() {
		//echo json_encode($this->getInstance(1), JSON_PARTIAL_OUTPUT_ON_ERROR);
		//Look for plugin sync table
		//$this->logger('warning', 'firstRun', '');
		$sql = "SHOW TABLES LIKE '". TABLE_PREFIX ."ldap_sync'";
		$res = db_query($sql);
		$rows = db_num_rows($res);

		if ($rows <= 0) {
			$this->sync_copy();			
			$this->createSyncTables();
		}
		return (db_num_rows($res) == 0);
	} 
	
	/**
	 * Checks to see if plug-in application needs to be upgraded
	 * @return boolean
	 */
	function needUpgrade() {
		$checkclicent = "SELECT id, user_id FROM " . TABLE_PREFIX ."user_account as ua WHERE `backend` LIKE CONCAT('ldap.client', '%') AND ua.user_id IN (SELECT  id FROM  " . TABLE_PREFIX ."ldap_sync WHERE ua.user_id = id)";	
		
		if (db_num_rows(db_query($checkclicent))){
			$this->startUpgrade();
		}
					
		if (!($res = db_query("SELECT version FROM " . PLUGIN_TABLE . " WHERE `id` = '" . $this->id . "';"))) {
			return true;
		}
		else {
			$ht = db_fetch_array($res);
			if (floatval($ht['version']) < floatval($this->info['version'])) {
				//Lets up date the version of the plug-in in old version of OSticket.
				$versql = "UPDATE `" . TABLE_PREFIX . "plugin` SET `version` = '" . $this->info['version'] . "' , name = '" . $this->info['name'] . "', installed = CURRENT_TIMESTAMP
                WHERE `id` = '" . $this->id . "';";
			if (db_num_rows(db_query($versql))) {
				$this->logger('warning', 'Update MLA_Version ', 'Version updated to: ' .$this->info['version']);
				return true;
			}
			}
		}
		return false;
	}

	/**
	 * Start upgrade of needed tasks for plug-in application
	 * @return boolean
	 */
	function startUpgrade() {
		$clientsql = "UPDATE " . TABLE_PREFIX ."user_account as ua SET `backend` = 'mldap.client".$this->instance->backend."' WHERE `backend` LIKE CONCAT('ldap.client', '%') 
					AND ua.user_id IN (SELECT Id FROM " . TABLE_PREFIX ."ldap_sync WHERE ua.user_id = id)";
					
		$staffsql = "UPDATE `" . TABLE_PREFIX ."staff as staff SET `backend` = 'mldap".$this->instance->backend."' WHERE `backend` LIKE CONCAT('ldap', '%') AND `Id` IN 
					(SELECT Id FROM " . TABLE_PREFIX ."ldap_sync WHERE Id = ua.Id);";
					
			//Update User table for new plug-in instance information.
			if (db_query($clientsql))
				$this->logger('warning', 'MLA_User backend updated', 'Rows affected: ' . db_affected_rows());
			if (db_query($staffsql))
				$this->logger('warning', 'MLA_Staff backend updated', 'Rows affected: ' . db_affected_rows());
	}

	/**
	 * Necessary functionality to configure first run of plug-in application
	 */
	function configureFirstRun() {		
		$this->logger('warning', 'MLA_FirstRun', 'config');
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
		$result = db_query($sqlsync);
		if ($result) {
			$this->logger('warning', 'MLA-createSyncTables', $sqlsync);
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
		$result = unlink(ROOT_DIR.'scp/sync_mldap.php');
		//if (!$result) return true;
		return true;
	}
	
	/**
	 * Write information to system LOG
	 *
	 */
	function logger($priority, $title, $message, $verbose = false) {
		if (self::getConfig($this->instance->ins)->get('debug-choice') && !$verbose || (self::getConfig($this->nstance->ins)->get('debug-verbose') && $verbose)) {
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

	function sync_copy() {
		global $ost;
		$pgfile = MULTI_PLUGIN_ROOT.'sync_mldap.php';
		$scpfile = ROOT_DIR.'scp/sync_mldap.php';
		if (!file_exists($scpfile)){
			if(!copy($pgfile,$scpfile)){
				$this->logger('error', 'MLA-Copy (failed)', "Copying new file '" . $pgfile . "' to SCP folder failed");
				return false;
			} else {
				$this->logger('info', 'MLA-Copy (success)', "Copying new file '" . $pgfile . "' to SCP folder successful");
				return true;
			}
		} else if (md5_file($pgfile) != @md5_file($scpfile)){
				unlink($scpfile);
				if(!copy($pgfile,$scpfile)){
					$this->logger('error', 'MLA-Updated (failed)', "Replacing file '" . $pgfile . "' to SCP folder failed");
					return false;
				} else {
					$this->logger('info', 'MLA-Updated (success)', "Replacing file '" . $pgfile . "' to SCP folder successful");
					return true;
				}				
			}
			return false;
	}
}

class LDAPMultiAuthentication {

	var $config;
	var $type = 'staff';

	function __construct($config, $type = 'staff') {
		$this->config = $config;
		$this->type = $type;
	}

	static function DateFromTimezone($date, $gmt, $timezone, $format) {
		$date = new DateTime($date, new DateTimeZone($gmt));
		$date->setTimezone(new DateTimeZone($timezone));
		return $date->format($format);
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
	function ldapenv() {
		$ldap = new AuthLdap();
		$ldap->serverType = 'ActiveDirectory';
		$ldap->server = preg_split('/;|,/', $data['servers']);
		$ldap->dn = $data['dn'];
		return $ldap;
	}

static function _connectcheck() {
        $conninfo = array();
        $ldapinfo = array();

		foreach (preg_split('/;/', $this->config['basedn']) as $i => $dn) {
			$dn = trim($dn);
			$servers = $this->config['servers'];
			$serversa = preg_split('/\s+/', $servers);

			$sd = $this->config['shortdomain'];
			$sda = preg_split('/;|,/', $sd);

			$bind_dn = $this->config['bind_dn'];
			$bind_dna = preg_split('/;/', $bind_dn) [$i];

			$bind_pw = $this->config['bind_pw'];
			$bind_pwa = preg_split('/;|,/', $bind_pw) [$i];

			$ldapinfo[] = array(
				'dn' => $dn,
				'sd' => $sda[$i],
				'servers' => trim($serversa[$i]) ,
				'bind_dn' => trim($bind_dna) ,
				'bind_pw' => trim($bind_pwa)
			);
		}	

        foreach ($ldapinfo as $data) {
            $ldap = new AuthLdap();
            $ldap->serverType = 'ActiveDirectory';
            $ldap->server = preg_split('/;|,/', $data['servers']);
            $ldap->domain = $data['sd'];
            $ldap->dn = $data['dn'];
            $ldap->useSSL = $data['ssl'];

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

		echo json_encode($conninfo);
    }
	static function connectcheck($ldapinfo) {
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
		foreach (preg_split('/;/', $this->getConfig($this->instance->ins)
			->get('basedn')) as $i => $dn) {
			$dn = trim($dn);
			$servers = $this->getConfig($this->instance->ins)
				->get('servers');
			$serversa = preg_split('/\s+/', $servers);

			$sd = $this->getConfig($this->instance->ins)
				->get('shortdomain');
			$sda = preg_split('/;|,/', $sd);

			$bind_dn = $this->getConfig($this->instance->ins)
				->get('bind_dn');
			$bind_dna = preg_split('/;/', $bind_dn) [$i];

			$bind_pw = $this->getConfig($this->instance->ins)
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
		global $ost, $cfg;
		
		if (!$password) {
			$ost->logWarning('auth (' . $username . ')', "", false);
			return null;
		}
		//check if they used their email to login.
		if (!filter_var($username, FILTER_VALIDATE_EMAIL) === false) {
			$username = explode('@', $username) [0];
		}

		$chkUser = null;
		$ldap = new AuthLdap();
		foreach ($this->ldapinfo() as $data) {
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
				$ost->logWarning('connect error (' . $username . ')', $conninfo['msg'], false);
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
			if ($chkUser) break; //Break if user authenticated
			
		} //end foreach
		if (($conninfo['bool'] == false || $loginfo['bool'] == false) && !$chkUser) {
			$errmsg;
			foreach ($loginfo as $err) {
				$errmsg .= $err['msg'] . " ";
			}
			$ost->logWarning('login error (' . $username . ')', trim($errmsg), false);
			
		}
		if ($chkUser) {
			$ost->logWarning('ldap login (' . $username . ')', $loginfo[0]['msg'], false);
			return $this->authOrCreate($username);
		}
		else {
			return;
		}
	}

	function authOrCreate($username) {
		global $cfg, $ost;
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
						foreach ($this->ldapinfo() as $data) {
						$ldap = new AuthLdap();
						$ldap->serverType = 'ActiveDirectory';
						$ldap->server = preg_split('/;|,/', $data['servers']);
						$ldap->dn = $data['dn'];
						$ldap->domain = $data['sd'];
						$ldap->searchUser = $data['bind_dn'];
						$ldap->searchPassword = $data['bind_pw'];
						
						if ($ldap->connect()) {
							if ($ldap->checkGroup($username, $staff_group)) {
								$chkgroup = true;
								break 2;
							}
							else {
								$conninfo[] = array(
									false,
									$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
								);

								//$ost->logWarning('ldap checkgrp (' . $username . ')', $conninfo[1], false);
							}
						}
						else {
							$conninfo[] = array(
								false,
								$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
							);

							//LdapMultiAuthPlugin::logger('info', 'ldap-ConnInfo', $conninfo);
						}
						}
					}
					if ($this->getConfig($this->instance->ins)->get('multiauth-staff-register') && $chkgroup) {
						if (!($info = $this->search($username, false))) {
							return;
						}
						$errors = array();
						$staff = array();

						$staff['do'] = 'create';
						$staff['add'] = 'a';
						$staff['id'] = '';
						$staff['username'] = $info['username'];
						$staff['firstname'] = $info['first'];
						$staff['lastname'] = $info['last'];
						$staff['email'] = $info['email'];
						$staff['isadmin'] = 0;
						$staff['isactive'] = 1;
						$staff['group_id'] = 1;
						$staff['dept_id'] = $this->getConfig($this->instance->ins)->get('multiauth_staff_dept');
						$staff['role_id'] = 1;
						$staff['backend'] = "ldap";
						$staff['assign_use_pri_role'] = "on";
						$staff['isvisible'] = 1;
						$staff['prems'] = array("visibility.agents", "visibility.departments");
						
						$staffcreate = Staff::create();
						if ($staffcreate->update($staff,$errors)) {
							$ost->logWarning('ldap StaffCreateed (' . $username . ')', json_encode($staff), false);
							if (($user = StaffSession::lookup($username)) && $user->getId()) {
								if (!$user instanceof StaffSession) {
									$user = new StaffSession($user->getId());
								}
								return $user;
							}							
						} else {
							$ost->logWarning('ldap Staff CreateError (' . $username . ')', json_encode($staff), false);
						}
					}
				}
			break;
			case 'client':
				// Lookup all the information on the user. Try to get the email
				// addresss as well as the username when looking up the user
				// locally.

				if (!($info = $this->search($username))) {
					$ost->logWarning('ldap info (' . $username . ')',json_encode($info), false);
				return;
				}
				
				$acct = ClientAccount::lookupByUsername($username);

				if ($acct && $acct->getId()) {
					$client = new ClientSession(new EndUser($acct->getUser()));
					$ost->logWarning('ldap session (' . $username . ')',json_encode($client), false);
				}
				
				//If client does not exist lets create it manually.
				if (!$acct) {
					$info['name'] = $info['first'] . " " . $info['last'];
					$info['email'] = $info['email'];
					$info['full'] = $info['full'];
					$info['first'] = $info['first'];
					$info['last'] = $info['last'];
					$info['username'] = $info['username'];
					$info['backend'] = 'mldap.client';
					$info['sendemail'] = false;

					if ($cfg->getClientRegistrationMode() == "closed" && $this->getConfig($this->instance->ins)->get('multiauth-force-register')){
						$create = User::fromVars($info);
						$register = UserAccount::register($create, $info, $errors);
						$client = new ClientSession(new EndUser($register->getUser()));
						
						$ost->logWarning('ldap user-created (' . $username . ')', 'user was ceeated', false);
					}
				}
				return $client;
			}
			return null;
	}

	function create_account($username, $type) {
	}
	
	function lookup($lookup_dn) {
		$lookup_user = array();
		preg_match('/(dc=(?:[^C]|C(?!N=))*)(?:;|$)/i', $lookup_dn, $match);
		//LdapMultiAuthPlugin::logger(LOG_DEBUG, 'ldap-lookup (' . $lookup_dn . ')', $lookup_dn);
		$base_dn = strtolower($match[0]);

		$key = array_search($base_dn, preg_split('/;/', strtolower($this->getConfig($this->instance->ins)
			->get('basedn'))));

		$key = (!isset($key) || is_null($key)) ? 0 : $key;

		$dn = trim($base_dn);

		$servers = $this->getConfig($this->instance->ins)
			->get('servers');
		$serversa = preg_split('/\s+/', $servers) [$key];

		$sd = $this->getConfig($this->instance->ins)
			->get('shortdomain');
		$sda = preg_split('/;|,/', $sd) [$key];

		$bind_dn = $this->getConfig($this->instance->ins)
			->get('bind_dn');
		$bind_dna = preg_split('/;/', $bind_dn) [$key];

		$bind_pw = $this->getConfig($this->instance->ins)
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

				//LdapMultiAuthPlugin::logger('info', 'ldap-UserconnInfo', $conninfo[1]);
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
		global $ost;
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

			//$ost->logWarning('ldap query(' . $query . ')', $ldap->dn . json_encode($ldap));
			if ($ldap->connect()) {
				$filter = self::getConfig($this->instance->ins)->get('search_base');
				if ($userlist = $ldap->getUsers($query, $this->adschema() , $filter)) {
					$ost->logDebug('ldap search(' . $query . ')', json_encode($userlist), false);
					$temp_userlist = $this->keymap($userlist);
					$combined_userlist = array_merge($combined_userlist, self::flatarray($temp_userlist));
				} else {
					//$ost->logError('search-error (' .$query. ')', $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText, false);
				}
			} else {
				$conninfo[] = array(
					false,
					$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
				);
				$ost->logWarning('search-info', $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText, false);
			}
		}
		$ost->logDebug('ldap-search (' . $query . ')', json_encode($combined_userlist), false);
		return $combined_userlist;
	}
}
class StaffLDAPMultiAuthentication extends StaffAuthenticationBackend implements AuthDirectorySearch {
	static $name = "Multi LDAP Authentication";
	static $id = "mldap";
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
		global $ost;
		$list = $this
			->_ldap
			->lookup($query);
		if ($list) {
			$list['backend'] = static ::$id;
			$list['id'] = static ::$id . ':' . $list['dn'];
		}
		//$ost->logWarning('lookup-result', $list, false);
		return ($list);
	}
	//General searching of users
	function search($query) {
		global $ost;
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
		//$ost->logDebug('search-result', $list , false);
		return $list;
	}
}
class ClientLDAPMultiAuthentication extends UserAuthenticationBackend {
	static $name = "Multi LDAP Authentication";
	static $id = "mldap.client";
	function __construct($config) {
		$this->_ldap = new LDAPMultiAuthentication($config, 'client');
		$this->config = $config;
		if ($domain = $config->get('basedn')) self::$name .= sprintf(' (%s)', $domain);
	}
	function getName() {
		$config = $this->config;
		list($__, $_N) = $config::translate();
		return $__(static ::$name);
	}
	function authenticate($username, $password = false, $errors = array()) {
		$object = $this
			->_ldap
			->authenticate($username, $password);
		if ($object instanceof ClientCreateRequest) $object->setBackend($this);
		return $object;
	}
}
