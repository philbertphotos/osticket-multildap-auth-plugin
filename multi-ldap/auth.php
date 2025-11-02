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
	static $pluginInstance = null;

    private function getPluginInstance(?int $id){
		if ($id && ($i = $this->getInstance($id))){
			return $i;
		}
		return $this->getInstances()->first();
    }
	
	public function __construct($id) {
    parent::__construct($id);
	}
	
	function bootstrap() {
		$this->plugininstance();
		if ($this->firstRun()) {
			if (!$this->configureFirstRun()) {
				return false;
			}
		}
		else if ($this->needUpgrade()) {}

		$this->loadSync();
		$config = $this->getConfig();
		$id = $this->id;
		Signal::connect('cron', array(
			$this,
			'onCronProcessed'
		));
			
		if ($config->get('multiauth-staff')) StaffAuthenticationBackend::register(new StaffLDAPMultiAuthentication($config));
		if ($config->get('multiauth-client')) UserAuthenticationBackend::register(new ClientLDAPMultiAuthentication($config));
	}
	
	//Checks osticket instances if any
	function plugininstance() {
			self::$pluginInstance = self::getPluginInstance(null);
			$this->instance = new stdClass();
			$ins = self::$pluginInstance->id;
			$plugin_id = self::$pluginInstance->plugin_id;
			$this->instance->plugin = "plugin.".$plugin_id.".instance.".$ins;
			$this->instance->backend = ".p".$plugin_id."i".$ins;
			$this->instance->staff = ".p".$plugin_id."i".$ins;
	}
	
	function loadSync() {
		$sql = "SELECT * FROM " . PLUGIN_TABLE . " WHERE `isactive`=1 AND `id`='" . $this->id . "'";
		if (db_num_rows(db_query($sql))) {
			if (!file_exists(ROOT_DIR.'scp/sync_mldap.php') || (md5_file(MULTI_PLUGIN_ROOT.'sync_mldap.php') != @md5_file(ROOT_DIR.'scp/sync_mldap.php'))){
				$this->sync_copy();
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
		if (!$this->getConfig(self::$pluginInstance)->get('sync-users') || !$this->getConfig(self::$pluginInstance)->get('sync-agents')){
			//return;
		}
		$instance = $this->getConfig(self::$pluginInstance)->config['sync_data']->ht['namespace'];
		$this->time_zone = db_result(db_query("SELECT value FROM `" . TABLE_PREFIX . "config` WHERE `key` = 'default_timezone'"));		
		$this->logger('warning', 'MLA instance - '.$this->getConfig()->get('shortdomain'), $instance, true);
		
		$sync_info = json_decode(db_fetch_row(db_query('SELECT value FROM ' . TABLE_PREFIX . 'config WHERE namespace = "' . $instance . '" AND `key` = "sync_data";'))[0]);

			$this->logger('warning', "MLA sync info", ($sync_info), true);
			
		$schedule = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M:%S", $sync_info->schedule) , 'UTC', $this->time_zone, 'F j, Y, H:i');
		$lastrun = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M:%S", $sync_info->lastrun) , 'UTC', $this->time_zone, 'F j, Y, H:i');
		$this->executed = time();
		$date = new DateTime('now', new DateTimeZone($this->time_zone));

		$this->crontime = $this->millisecsBetween($schedule, $lastrun, false) / 1000 / 60;

		$this->sync_cron($this->crontime);
		$this->loadSync();
			
			$allowaction = $this->allowAction(); //check to see if Action is allowed.
				$this->logger('warning', 'MLA Allow Check - '.$this->getConfig()->get('shortdomain'), 'allow action : '. var_export($allowaction, 1), true);
					
		if ($allowaction) {
			//Load Sync info
			$sync = new SyncLDAPMultiClass($instance);
			if ($this->getConfig()->get('sync-users') || $this->getConfig()->get('sync-agents')) {
				$excu = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M", $this->lastExec) , 'UTC', $this->time_zone, 'F d Y g:i a');
				$nextexcu = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M", $this->nextExec) , 'UTC', $this->time_zone, 'F d Y g:i a');
				$results = $sync->check_users();				
					$this->logger('warning', 'MLA Check Users', ($results), true);
					
				if (empty($results)) {
					$this->logger('warning', 'MLA LDAP Sync', 'Sync executed on (' . ($excu) . ') next execution in (' . $nextexcu . ')', true);
				} else {
					$this->logger('warning', 'MLA LDAP Sync', 'Sync executed on (' . ($excu) . ')
					Next execution in (' . $nextexcu . ')' . "
					Total ldapusers: (" . $results['totalldap'] . ")
					Total agents: (" . $results['totalagents'] . ") 
					Total Updated Users: (" . $results['updatedusers'] . ") 
					Execute Time: (" . $results['executetime'] . ")", true);
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
			if (self::getConfig()->get('debug-verbose'))
				$ost->logDebug('MLA updateLastrun', ($sql), false);
			error_log($sql);
		return db_query($sql);
	}

	function updateSchedule() {
		global $ost;
		$data = $this->sync_data('schedule', $this->cront['schedule']);
		$sql = 'UPDATE `' . TABLE_PREFIX . 'config` SET `value` = \'' . ($data) . '\', updated = CURRENT_TIMESTAMP
                        WHERE `key` = "sync_data" AND `namespace` = "' . $this->instance->plugin . '";';
			if (self::getConfig()->get('debug-verbose'))
				$ost->logDebug('MLA updateSchedule', ($sql), false);
		$result = db_query($sql);
		return $result;
	}
 
 	/**
	 * Checks if this is the first run of our plugin.
	 *
	 * @return boolean
	 */
	function firstRun() {
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
				$this->logger('warning', 'Update MLA_Version ', 'Version updated to: ' .$this->info['version'], false, true);
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
					
		$staffsql = "UPDATE `" . TABLE_PREFIX ."staff as st SET `backend` = 'mldap".$this->instance->backend."' WHERE `backend` LIKE CONCAT('ldap', '%') AND staff.staff_Id IN 
					(SELECT Id FROM " . TABLE_PREFIX ."ldap_sync WHERE Id = st.staff_Id);";

			//Update User table for new plug-in instance information.
			if (db_query($clientsql))
				$this->logger('warning', 'MLA User backend updated', 'Rows affected: ' . db_affected_rows(), false, true);
			if (db_query($staffsql))
				$this->logger('warning', 'MLA Staff backend updated', 'Rows affected: ' . db_affected_rows(), false, true);
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
			$this->logger('warning', 'MLA SyncTables', "SUCCESS: ".$sqlsync, false, true);
			return true;
		} else {
			$this->logger('warning', 'MLA SyncTables', "FAILED: ".$sqlsync, false, true);
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
		db_query("DROP TABLE IF EXISTS " . TABLE_PREFIX . "ldap_sync");
			$this->logger('warning', 'MLA-uninstall', "removed ldap_sync Table". $errors, false, true);
		$result = unlink(ROOT_DIR.'scp/sync_mldap.php');
		if ($result) {
			return true;
		} else {
			$this->logger('warning', 'MLA-uninstal', "File removal error " . $errors, false, true);
		return false;
		}
	}

	/**
	 * Check if this is array or object
	 *
	 */
	function isRealObject($arrOrObject) {
		if (is_object($arrOrObject))
			return true;
		$keys = array_keys($arrOrObject);
		return implode('', $keys) != implode(range(0, count($keys)-1));
	}
	
	/**
	 * Write information to system LOG
	 *
	 */
	function logger($priority, $title, $message, $verbose = false, $force = false) {
		
			if (!is_scalar($message)) {
				$message = json_encode($message, JSON_PARTIAL_OUTPUT_ON_ERROR);
			}
			
			//We are providing only 3 levels of logs. Windows style.
			switch ($priority) {
				case "error":
				case LOG_EMERG:
				case LOG_ALERT:
				case LOG_CRIT:
				case LOG_ERR:
					$level = 0; //Error
					
				break;
				case "warning":
				case LOG_WARN:
				case LOG_WARNING:
					$level = 1; //Warning
					
				break;
				case "debug":
				case LOG_NOTICE:
				case LOG_INFO:
				case LOG_DEBUG:
				default:
					$level = 2; //Debug
					
			}
			$loglevel = array('Error', 'Warning','Debug'
			);
			//Save log based on system log level settings.
			$sql = 'INSERT INTO ' . TABLE_PREFIX . "syslog" . ' SET created=NOW(), updated=NOW() ' . ',title=' . db_input(Format::sanitize($title, true)) . ',log_type=' . db_input($loglevel[$level]) . ',log=\'' . $message . '\',ip_address=' . db_input($_SERVER['REMOTE_ADDR']);

			if ($force) {
				db_query($sql, false);
				return;
			}
				
			switch (self::getConfig()->get('debug-verbose')){
				case true:
					db_query($sql, false);
				break;
				case false:						
				if (self::getConfig()->get('debug-choice') && !$verbose) {
					db_query($sql, false);
				}
				break;				
			}
	}
	
	function sync_copy() {
		$pgfile = MULTI_PLUGIN_ROOT.'sync_mldap.php';
		$scpfile = ROOT_DIR.'scp/sync_mldap.php';
		if (!file_exists($scpfile)){
			if(!copy($pgfile,$scpfile)){
				$this->logger('error', 'MLA File Copy (failed)', "Copying new file '" . $pgfile . "' to SCP folder failed",false, true);
				return false;
			} else {
				$this->logger('info', 'MLA File (success)', "Copying new file '" . $pgfile . "' to SCP folder successful",false, true);
				return true;
			}
		} else if (md5_file($pgfile) != @md5_file($scpfile)){
				unlink($scpfile);
				if(!copy($pgfile,$scpfile)){
					$this->logger('error', 'MLA File Updated (failed)', "Replacing file '" . $pgfile . "' to SCP folder failed",false, true);
					return false;
				} else {
					$this->logger('info', 'MLA File Updated (success)', "Replacing file '" . $pgfile . "' to SCP folder successful",false, true);
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
			'samaccountname',
			'givenname',
			'sn',
			'displayname',
			'mail',
			'telephonenumber',
			'mobile',
			'distinguishedname'
		) , array(
			'username',
			'first',
			'last',
			'full',
			'email',
			'phone',
			'mobile',
			'dn'
		)));
		return $keys;
	}

	function adschema() {
		return array(
			'samaccountname',
			'givenname',
			'sn',
			'displayname',
			'mail',
			'telephonenumber',
			'mobile',
			'distinguishedname'
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

	static function connectcheck($ldapinfo) {
		$conninfo = array();
		foreach ($ldapinfo as $data) {
			$ldap = new AuthLdap();
			$ldap->serverType = 'ActiveDirectory';
			$ldap->server = preg_split('/;|,/', $data['servers']);
			$ldap->domain = $data['sd'];
			$ldap->dn = $data['dn'];
			$ldap->ssl = $data['ssl'];

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
		global $ost;			
		$object = array();
		foreach ($values as $k => $items) {
			foreach ($items as $key => $item) {
				$object[$k][$key] = $item["0"];
			}
		}
		//$ost->logWarning('object', json_encode($object), false);
		return $object; //remove [0] inclulde multiple arrays
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
			
			$ssl = $this->getConfig($this->instance->ins)->get('tls');

			$ldapinfo[] = array(
				'dn' => $dn,
				'sd' => $sda[$i],
				'servers' => trim($serversa[$i]) ,
				'bind_dn' => trim($bind_dna) ,
				'bind_pw' => trim($bind_pwa),
				'ssl' => $ssl
			);
		}
		return $ldapinfo;
	}

	function authenticate($username, $password = null) {		
		global $ost;
		if (!$password) {
			$ost->logWarning('MLA auth (' . $username . ')', "password blank or null", false);
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
			$ldap->ssl = $data['ssl'];
			
			if ($ldap->connect()) {
				$conninfo[] = array(
					'bool' => true,
					'msg' => 'System connected to (' . $data['sd'] . ')'
				);
			}
			else {
				$conninfo['bool'] = false;
				$conninfo['msg'] = ($data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText);
					$ost->logWarning('MLA connect error (' . $username . ')', $conninfo['msg'], false);
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

			if ($chkUser) break; //Break if user authenticated			
		} //end foreach
		
		if (($conninfo['bool'] == false || $loginfo['bool'] == false) && !$chkUser) {
			$errmsg;
			foreach ($loginfo as $err) {
				$errmsg .= $err['msg'] . " ";
			}

		if (self::getConfig()->get('debug-choice'))
			$ost->logWarning('MLA login error (' . $username . ')', trim($errmsg), false);
			
		}
		if ($chkUser) {
			if (self::getConfig()->get('debug-choice'))
				$ost->logWarning('MLA login success(' . $username . ')', $loginfo[0]['msg'], false);
			return $this->authOrCreate($username);
		}
		else {
			return;
		}
	}

	function authOrCreate($username) {
		global $cfg, $ost;
		$mode = $cfg->getClientRegistrationMode();
		$instance = $this->config->config['sync_data']->ht['namespace'];
		$ins = explode("." , $instance);
			if (self::getConfig()->get('debug-choice')){
				$ost->logDebug('MLA Registraion Mode', 'System set to : '.$mode, false);
				$ost->logWarning('MLA Instance (' . $username . ')', $instance, false);
			}
		switch ($this->type) {
			case 'staff':
			if (self::getConfig()->get('debug-verbose'))
				$ost->logDebug('MLA StaffSession', json_encode(StaffSession::lookup($username)), false);
				if (($user = StaffSession::lookup($username)) && $user->getId()) {
					if (!$user instanceof StaffSession) {
						// osTicket <= v1.9.7 or so
						$user = new StaffSession($user->getId());
					}
					return $user;
				} else {
					
					$staff_groups = preg_split('/;|,/', $this->config->get('multiauth-staff-group'));
					if ($this->config->get('debug-verbose'))
						$ost->logWarning('MLA ldap staffnotfound (' . $username . ')', json_encode($staff_groups), false);					
					$chkgroup;
						foreach ($this->ldapinfo() as $data) {
							$ldap = new AuthLdap();
							$ldap->serverType = 'ActiveDirectory';
							$ldap->server = preg_split('/;|,/', $data['servers']);
							$ldap->dn = $data['dn'];
							$ldap->domain = $data['sd'];
							$ldap->searchUser = $data['bind_dn'];
							$ldap->searchPassword = $data['bind_pw'];
							$ldap->ssl = $data['ssl'];
							
							if ($ldap->connect()) {								
								foreach ($staff_groups as $staff_group) {
									if ($ldap->checkGroup($username, trim($staff_group))) {
										$chkgroup = true;
										break 2;
									} else {
										$conninfo[] = array(
											false,
											$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
										);
										if ($this->config->get('debug-verbose'))
											$ost->logWarning('MLA ldap checkgrp (' . $username . ')', json_encode($conninfo[1]), false);
										$chkgroup = false;
									}
								}
							} else {
								$conninfo[] = array(
									false,
									$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
								);

								if (self::getConfig()->get('debug-verbose'))
									$ost->logWarning('MLA ldap ConnInfo (' . $username . ')', $conninfo[1], false);
							}
						}
					if ($this->getConfig($this->instance->ins)->get('multiauth-staff-register') && $chkgroup) {
						if (!($info = $this->search($username, true)[0])) {
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
						$staff['backend'] = "mldap.".($ins[0][0].$ins[1].$ins[2][0].$ins[3]);
						$staff['assign_use_pri_role'] = "on";
						$staff['isvisible'] = 1;
						$staff['prems'] = array("visibility.agents", "visibility.departments");
						
						$staffcreate = Staff::create();
						if ($staffcreate->update($staff, $errors)) {
							$ost->logWarning('MLA Staff Created (' . $username . ')', json_encode($staff), false, true);
							if (($user = StaffSession::lookup($username))) {
								if (!$user instanceof StaffSession) {
									$user = new StaffSession($user->getId());
								}
								return $user;
							}							
						} else {
							$ost->logWarning('MLA Staff Creation Error (' . $username . ')', json_encode($staff), false);
						}
					}
				}
			break;
			case 'client':
				$client_groups = preg_split('/;|,/', $this->config->get('multiauth-client-group'));
				// Lookup all the information on the user. Try to get the email
				// addresss as well as the username when looking up the user
				// locally.
				if (!$info = $this->search($username, true)['0']) {
					$ost->logWarning('MLA ldap info (' . $username . ')',json_encode($info), false);
				return;
				}
				
				$acct = ClientAccount::lookupByUsername($username);

				if ($acct && $acct->getId()) {
					$client = new ClientSession(new EndUser($acct->getUser()));
					$ost->logWarning('MLA session (' . $username . ')',json_encode($client), false);
				}
				
				//If client does not exist MLA will create it.
				if (!$acct) {
					$info['name'] = $info['first'] . " " . $info['last'];
					$info['email'] = $info['email'];
					$info['full'] = $info['full'];
					$info['first'] = $info['first'];
					$info['last'] = $info['last'];
					$info['username'] = $info['username'];
					$info['sendemail'] = false;
					$info['backend'] = 'mldap.client.'.($ins[0][0].$ins[1].$ins[2][0].$ins[3]);
					$info['timezone'] = $cfg->getTimezone();
					switch ($mode) {
							case 'public':								
								if ($client = new ClientCreateRequest($this, $username, $info)) {									
									$ost->logWarning('MLA user-creation-success (' . $username . ')', 'user was created '.$mode, false);
								} else {
									$ost->logWarning('MLA user-creation-failed (' . $username . ')', 'user creation failed unknown error '.$mode, false);
								}
							break;
							case 'closed':
								
								if ($this->getConfig()->get('multiauth-force-register')) {
									//only needed if closed
									//$info['backend'] = 'mldap.client.'.$ins[0][0].$ins[1]$ins[2][0].$ins[3];
									//$info['timezone'] = $cfg->getTimezone();
									//$info['lang'] = $cfg->getLanguage(); needs more testing
									
									$create   = User::fromVars($info);
									$register = UserAccount::register($create, $info, $errors);
									$client   = new ClientSession(new EndUser($register->getUser()));
									$ost->logWarning('MLA user-creation-success (' . $username . ')', 'user was created', false);
								} else {
									$ost->logWarning('MLA user-creation-failed (' . $username . ')', 'user creation failed (enable force user creation)', false);
								}
							break;
						}
				
				}
				return $client;
			}
			return;
	}

	function create_account($username, $type) {
	}
	
	function lookup($lookup_dn) {
		$lookup_user = array();
		preg_match('/(dc=(?:[^C]|C(?!N=))*)(?:;|$)/i', $lookup_dn, $match);
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
		
		$ssl = $this->getConfig($this->instance->ins)->get('tls');

		$data = array(
			'dn' => trim($dn) ,
			'sd' => trim($sda) ,
			'servers' => trim($serversa) ,
			'bind_dn' => trim($bind_dna) ,
			'bind_pw' => trim($bind_pwa) ,
			'ssl' => $ssl
		);

		$ldap = new AuthLdap();
		$ldap->serverType = 'ActiveDirectory';
		$ldap->server = preg_split('/;|,/', $data['servers']);
		$ldap->dn = $data['dn'];
		$ldap->domain = $data['sd'];
		$ldap->searchUser = $data['bind_dn'];
		$ldap->searchPassword = $data['bind_pw'];
		$ldap->ssl = $data['ssl'];

		if ($ldap->connect()) {

			$filter = '(&(objectCategory=person)(distinguishedName={q}))';
			if ($temp_user = $ldap->getUsers($lookup_dn, $this->adschema(), $filter)) {
				$lookup_user = $this->keymap($temp_user);
			}
			else {
				$conninfo[] = array(
					false,
					$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
				);
			}
		}
		else {
			$conninfo[] = array(
				false,
				$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
			);
				LdapMultiAuthPlugin::logger('info', 'MLA ldap-ConnInfo', $conninfo);
		}
		$lookup_user = self::flatarray($lookup_user);
		$lookup_user[0]['name'] = $lookup_user[0]['full'];
		$lookup_user[0]['mobile'] = null;
		return $lookup_user[0];
	}

	function search($query, $single = false) {
		global $ost;
		$userlist = array();
		$combined_userlist = array();
		$ldapinfo = $this->ldapinfo();

		foreach ($ldapinfo as $data) {
			$ldap = new AuthLdap();
			$ldap->serverType = 'ActiveDirectory';
			$ldap->server = preg_split('/;|,/', $data['servers']);
			$ldap->dn = $data['dn'];
			$ldap->domain = $data['sd'];
			$ldap->searchUser = $data['bind_dn'];
			$ldap->searchPassword = $data['bind_pw'];
			$ldap->ssl = $data['ssl'];

			if ($ldap->connect()) {
				$search = str_replace("((", "(|(", self::getConfig($this->instance->ins)->get('search_base'));
				$filter =  $search;
				if (self::getConfig()->get('debug-choice'))
					$ost->logDebug('MLA search filter', $search, false);
				if ($single) {
					$userlist = $ldap->getUser($query, $this->adschema() , $filter);
					} else {
					$userlist = $ldap->getUsers($query, $this->adschema() , $filter);
				}

				if ($userlist) {
					if (self::getConfig()->get('debug-choice'))
						$ost->logDebug('MLA '. strtolower($data['sd']).' ldap userlist (' . $query . ')', json_encode($userlist), false);
					$temp_userlist = self::flatarray($this->keymap($userlist));
					$combined_userlist = array_merge($combined_userlist, $temp_userlist);
				} else {
					if (self::getConfig()->get('debug-choice'))
						$ost->logError('MLA search-error (' .$query. ')', $data['sd'] . ' #' .$ldap->ldapErrorCode . " - " . $ldap->ldapErrorText, false);
				}
			} else {
				$conninfo[] = array(
					false,
					$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
				);
				$ost->logWarning('MLA search-info', $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText, false);
			}
		}
		if (self::getConfig()->get('debug-verbose'))
			$ost->logDebug('MLA system userlist (' . $query . ')', json_encode($combined_userlist), false);
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
	//queries the user information
	function authenticate($username, $password = false, $errors = array()) {
		return $this
			->_ldap
			->authenticate($username, $password);		
	}
	
	function getName() {
		$config = $this->config;
		list($__, $_N) = $config::translate();
		return $__(static ::$name);
	}
	//lookup local and remote users
	function lookup($dn) {
		$list = $this
			->_ldap
			->lookup($dn);
		if ($list) {
			$list['backend'] = static ::$id;
			$list['id'] = $this->getBkId() . ':' . $list['dn'];
		}
		return $list;
	}

	//General searching of users
	function search($query) {
		global $ost;
		if (strlen($query) < 3) 
			return array();
							
		$ost->logWarning('MLA search', $query, false);
		$list = array(
			$this
				->_ldap
				->search($query))[0];
		foreach ($list as &$l) {
			$l['backend'] = static::$id;
			$l['id'] = $this->getBkId() . ':' . $l['dn'];
		}
		//if (self::getConfig()->get('debug-verbose'))
			$ost->logWarning('MLA search list', json_encode($list, false));
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
