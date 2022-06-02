<?php
require_once (INCLUDE_DIR . 'class.auth.php');
require_once (INCLUDE_DIR . 'class.plugin.php'); //Plugin Local Libary
require_once ('class.AuthLdap.php');
require_once ('class.Mail.php');

//FOLDERS
define('MULTI_PLUGIN_ROOT', __DIR__ . '/');
require_once ('config.php');

/**
 * Write information to system LOG
 *
 */
function logger($priority, $title, $message, $verbose = false) {
    global $ost;
    //error_log("config3: ".json_encode($this->config ));
    // if (!empty(config->get('debug-choice')) && $this->config->get('debug-choice') && !$verbose
    //|| ($this->config->get('debug-verbose') && $verbose)) {
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
    $ost->logInfo('old', "test", false);

    //Save log based on system log level settings.
    $sql = 'INSERT INTO ' . SYSLOG_TABLE . ' SET created=NOW(), updated=NOW() ' . ',title=' . db_input(Format::sanitize($title, true)) . ',log_type=' . db_input($loglevel[$level]) . ',log=' . db_input(Format::sanitize($message, false)) . ',ip_address=' . db_input($_SERVER['REMOTE_ADDR']);
    db_query($sql, false);
    // }
    
}

class LdapMultiAuthPlugin extends Plugin {
    var $crontime;
    var $config_class = 'LdapMultiAuthPluginConfig';
    private static $config;

    function bootstrap() {
        if ($this->firstRun()) {
            if (!$this->configureFirstRun()) {
                return false;
            }
            else if ($this->upgradeCheck()) {
                $this->runUpgrade();
            }
        }

        $this->loadSync();

        $config = $this->getConfig();
        $this->config = $config;
        Signal::connect('cron', array(
            $this,
            'onCronProcessed'
        ));
		$this->parent = new LDAPMultiAuthentication($config);
        if ($config->get('multiauth-staff')) StaffAuthenticationBackend::register(new StaffLDAPMultiAuthentication($config));
        if ($config->get('multiauth-client')) UserAuthenticationBackend::register(new ClientLDAPMultiAuthentication($config));
    }

    function loadSync() {
        $sql = 'SELECT * FROM ' . PLUGIN_TABLE . ' WHERE isactive=1 AND id=' . db_input($this->getId());
        if (db_num_rows(db_query($sql))) {
            if (!file_exists(ROOT_DIR . 'scp/sync_mldap.php') || (filemtime(MULTI_PLUGIN_ROOT . '/sync_mldap.php') != @filemtime(ROOT_DIR . 'scp/sync_mldap.php'))) $this->sync_copy();
            include_once (ROOT_DIR . 'scp/sync_mldap.php');
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
        //logger('warning', 'entry', json_encode($entry));
        $this->sync_cron($this->crontime);
        include_once (ROOT_DIR . 'scp/sync_mldap.php');
        $sync = new SyncLDAPMultiClass($this->id);
        //logger('warning', 'Sync Config', $sync->config);
        if ($this->allowAction()) {
            if ($this
                ->config
                ->get('sync-users') || $this
                ->config
                ->get('sync-agents')) {
                $excu = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M", $this->lastExec) , 'UTC', $this->time_zone, 'F d Y g:i a');
                $nextexcu = $this->DateFromTimezone(strftime("%Y-%m-%d %H:%M", $this->nextExec) , 'UTC', $this->time_zone, 'F d Y g:i a');

                $results = $sync->check_users();
                //logger('warning', 'check_users results', json_encode($results));
                if (empty($results)) {
                    //$this->logs('warning', 'LDAP Sync1', 'Sync executed on (' . ($excu) . ') next execution in (' . $nextexcu . ')');
                    logger('warning', 'LDAP Sync', 'Sync executed on (' . ($excu) . ') next execution in (' . $nextexcu . ')');
                }
                else {
                    logger('warning', 'LDAP Sync', '<div>Sync executed on (' . ($excu) . ')</div> <div>Next execution in (' . $nextexcu . ')</div>' . "<div>Total ldapusers: (" . $results['totalldap'] . ")</div> <div>Total agents: (" . $results['totalagents'] . ") </div>Total Updated Users: (" . $results['updatedusers'] . ") <div>Execute Time: (" . $results['executetime'] . ")</div>");

                    //$this->logs('warning', 'LDAP Sync2', '<div>Sync executed on (' . ($excu) . ')</div> <div>Next execution in (' . $nextexcu . ')</div>' . "<div>Total ldapusers: (" . $results['totalldap'] . ")</div> <div>Total agents: (" . $results['totalagents'] . ") </div>Total Updated Users: (" . $results['updatedusers'] . ") <div>Execute Time: (" . $results['executetime'] . ")</div>");
                    
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
                $output['format'] = "+" . $info['value'];
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
     * Checks if this is the first run of our plugin.
     *
     * @return boolean
     */
    function firstRun() {
        $sql = "SHOW TABLES LIKE '" . TABLE_PREFIX . "ldap_sync'";
        $res = db_query($sql);
        $rows = db_num_rows($res);

        //adds "sync_data" to database.
        $chk_sync = db_fetch_row(db_query("SELECT * FROM " . TABLE_PREFIX . "config WHERE `namespace` = 'plugin." . $this->getId() . "' AND `key` = 'sync_data';"));
        if (empty($chk_sync)) db_query("INSERT INTO " . TABLE_PREFIX . "config (`namespace`, `key`, `value`, `updated`) VALUES ('plugin." . $this->getId() . "', 'sync_data', '', '" . date("Y-m-d H:i:s") . "');");

        if ($rows <= 0) {
            $this->sync_copy();
            $this->createSyncTables();
        }
        return (db_num_rows($res) == 0);
    }

    function sync_copy() {
        $file = MULTI_PLUGIN_ROOT . '/sync_mldap.php';
        $newfile = ROOT_DIR . 'scp/sync_mldap.php';
        $path = ROOT_DIR . 'scp';
        if (!file_exists($newfile)) {
            if (!copy($file, $newfile)) {
                logger('error', 'MultiLdap Sync Copy Filed', "failed to copy LDAP_Sync API to SCP folder");
                return false;
            }
            else {
                logger('warning', 'MultiLdap Sync Copy Success', "Copied LDAP_Sync API to SCP folder");
                return true;
            }
        }
    }

    function upgradeCheck() {
        $sql = 'SELECT version FROM ' . PLUGIN_TABLE . ' WHERE `name` LIKE \'%Multi LDAP%\'';
        if (!($res = db_query($sql))) {
            return true;
        }
        else {
            $ht = db_fetch_array($res);
            if (floatval($ht['version']) < floatval($this->info['version'])) {
                return true;
            }
        }
        return false;
    }

    //Run update if plugin version change.
    function runUpdate() {

    }

    /**
     * Necessary functionality to configure first run of the application
     */
    function configureFirstRun() {
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
        logger('warning', 'MLA-createSyncTables', $sqlsync);
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
        logger('warning', 'MLA-uninstall', $errors);
        db_query("DROP TABLE IF EXISTS " . TABLE_PREFIX . "ldap_sync");
        $result = unlink(ROOT_DIR . 'scp/sync_mldap.php');
        return true;
    }
}

class LDAPMultiAuthentication {

    private $config;
    var $type = 'staff';

    function __construct($conf, $type = 'staff') {
        $this->config = $conf;
        $this->type = $type;
    }

    public static function getConfig() {
        return $config;
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
    /**
     * Logging function, Ensures we have permission to log before doing so
     * Attempts to log to the Admin logs, and to the web-server logs if debugging
     * is enabled.
     *
     * @param string $title, string $message
     */
    function logs($priority = 'warning', $title, $message) {
        global $ost;
        if (is_array($message)) $message = json_encode($message);

        if ($this
            ->config
            ->get('debug-choice') && $priority == 'warning') {
            $ost->logWarning($title, $message, false);
        }
        else if ($this
            ->config
            ->get('debug-choice') && $priority == 'error') {
            $ost->logError($title, $message, false);
        }

        //Verbose logging
        if ($this
            ->config
            ->get('debug-verbose') && $message) {
            $ost->logDebug($title, $message, false);
        }
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

    //Loads LDAP environment
    function ldapenv($data, $auth = false) {
        $ldap = new AuthLdap();
        $ldap->serverType = 'ActiveDirectory';
        $ldap->server = preg_split('/;|,/', $data['servers']);
        $ldap->dn = $data['dn'];
        $ldap->useSSL = $data['ssl'];
        if ($auth) {
            $ldap->searchUser = $data['bind_dn'];
            $ldap->searchPassword = $data['bind_pw'];
        }

        return $ldap;
    }

    function connectcheck($ldapinfo) {
        $conninfo = array();
        foreach ($ldapinfo as $data) {
            $ldap = $this->ldapenv($data);
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
        logger('warning', 'connect-check', json_encode($conninfo));
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

    function flat($values) {
        $object = array();
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $object[$key] = $value;
            }
            else if (is_array($value)) {
                if ($value['count'] > 1) {
                    unset($value['count']);
                    $object[$key] = $value;
                }
                else {
                    $object[$key] = $value[0];
                }
            }
        }
        return $object;
    }

    function ldapinfo() {
        $ldapinfo;
        foreach (preg_split('/;/', $this
            ->config
            ->get('basedn')) as $i => $dn) {
            $dn = trim($dn);
            $servers = $this
                ->config
                ->get('servers');
            $serversa = preg_split('/\s+/', $servers);

            $sd = $this
                ->config
                ->get('shortdomain');
            $sda = preg_split('/;|,/', $sd);

            $bind_dn = $this
                ->config
                ->get('bind_dn');
            $bind_dna = preg_split('/;/', $bind_dn) [$i];

            $bind_pw = $this
                ->config
                ->get('bind_pw');
            $bind_pwa = preg_split('/;|,/', $bind_pw) [$i];

            $tls = $this
                ->config
                ->get('tls');

            $ldapinfo[] = array(
                'dn' => $dn,
                'sd' => $sda[$i],
                'servers' => trim($serversa[$i]) ,
                'bind_dn' => trim($bind_dna) ,
                'bind_pw' => trim($bind_pwa) ,
                'ssl' => $tls
            );
        }
        return $ldapinfo;
    }

    function authenticate($username, $password = null) {
        if (!$password) {
            logger('warning', 'auth (' . $username . ')', "");
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
            $ldap->useSSL = $data['ssl'];

            if ($ldap->connect()) {
                $conninfo[] = array(
                    'bool' => true,
                    'msg' => 'System connected to (' . $data['sd'] . ')'
                );
            }
            else {
                $conninfo['bool'] = false;
                $conninfo['msg'] = ($data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText);
                logger(LOG_INFO, 'connect error (' . $username . ')', $conninfo['msg']);
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

            logger(LOG_INFO, 'login error (' . $username . ')', trim($errmsg));
        }
        if ($chkUser) {
            if (!empty($user_info)) logger(LOG_INFO, 'ldap-login (' . $username . '[' . $this->type . '])', $loginfo[0]['msg']);
            return $this->authOrCreate($user_info);
        }
        else {
            return;
        }
    }

    function authOrCreate($user_info) {
        //convert userinfo
        $key_user = $this->keymap($user_info);
        foreach ($key_user as $key => $val) $info = $this->flat($val);

        switch ($this->type) {
            case 'staff':
                if (($user = StaffSession::lookup($info['username'])) && $user->getId()) {
                    if (!$user instanceof StaffSession) {
                        // osTicket <= v1.9.7 or so
                        $user = new StaffSession($user->getId());
                    }
                    return $user;
                }
                else {
                    $staff_groups = preg_split('/;|,/', $this
                        ->config
                        ->get('multiauth-staff-group'));
                    $chkgroup;
                    foreach ($staff_groups as $staff_group) {
                        if ($ldap->checkGroup($name, $staff_group)) {
                            $chkgroup = true;
                            break;
                        }
                    }

                    if ($config->get('multiauth-staff-register') && $chkgroup) {

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
                        if (($user = StaffSession::lookup($info['username'])) && $user->getId()) {
                            if (!$user instanceof StaffSession) {
                                $user = new StaffSession($user->getId());
                            }
                            return $user;
                        }
                    }
                }
            break;
            case 'client':
                $acct = ClientAccount::lookupByUsername($info['username']);

                if ($acct && $acct->getId()) {
                    $client = new ClientSession(new EndUser($acct->getUser()));
                    logger(LOG_INFO, 'ldap session (' . $info['username'] . ')', json_encode($client));
                }

                if (!$client) {

                    $info['name'] = $info['first'] . " " . $info['last'];

                    $client = new ClientCreateRequest($this, $info['username'], $info);
                    logger(LOG_INFO, 'ldap client (' . $info['username'] . ')', json_encode($info));
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
        logger(LOG_DEBUG, 'ldap-lookup (' . $lookup_dn . ')', $lookup_dn);
        $base_dn = strtolower($match[0]);

        $key = array_search($base_dn, preg_split('/;/', strtolower($this
            ->config
            ->get('basedn'))));

        $key = (!isset($key) || is_null($key)) ? 0 : $key;

        $dn = trim($base_dn);

        $servers = $this
            ->config
            ->get('servers');
        $serversa = preg_split('/\s+/', $servers) [$key];

        $sd = $this
            ->config
            ->get('shortdomain');
        $sda = preg_split('/;|,/', $sd) [$key];

        $bind_dn = $this
            ->config
            ->get('bind_dn');
        $bind_dna = preg_split('/;/', $bind_dn) [$key];

        $bind_pw = $this
            ->config
            ->get('bind_pw');
        $bind_pwa = preg_split('/;|,/', $bind_pw) [$key];

        $data = array(
            'dn' => trim($dn) ,
            'sd' => trim($sda) ,
            'servers' => trim($serversa) ,
            'bind_dn' => trim($bind_dna) ,
            'bind_pw' => trim($bind_pwa) ,
            'ssl' => $tls
        );

        $ldap = new AuthLdap();
        $ldap->serverType = 'ActiveDirectory';
        $ldap->server = preg_split('/;|,/', $data['servers']);
        $ldap->dn = $data['dn'];
        $ldap->useSSL = $data['ssl'];
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

                logger('warning', 'ldap-UserconnInfo', $conninfo[1]);
            }
        }
        else {
            $conninfo[] = array(
                false,
                $data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
            );

            logger('warning', 'ldap-ConnInfo', $conninfo);
        }
        $lookup_user = self::flatarray($lookup_user);
        $lookup_user['name'] = $lookup_user['full'];
        return $lookup_user;
    }

    function search($query) {
        $userlist = array();
        $combined_userlist = array();
        $ldapinfo = $this->ldapinfo();

        foreach ($ldapinfo as $key => $data) {
            $ldap = new AuthLdap();
            $ldap->serverType = 'ActiveDirectory';
            $ldap->server = preg_split('/;|,/', $data['servers']);
            $ldap->dn = $data['dn'];
            $ldap->useSSL = $data['ssl'];
            $ldap->searchUser = $data['bind_dn'];
            $ldap->searchPassword = $data['bind_pw'];

            if ($ldap->connect()) {
                $filter = $this
                    ->config
                    ->get('search_base');
                ini_set('memory_limit', '512M');
                if ($userlist = $ldap->getUsers($query, $this->adschema() , $filter)) {
                    $key_userlist = $this->keymap($userlist);

                    foreach ($key_userlist as $key => $val) $combined_userlist[] = $this->flat($val);
                }
                else {
                    logger('debug', 'search-error', $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText);
                }
            }
            else {
                $conninfo[] = array(
                    false,
                    $data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
                );
                logger('warning', 'search-error', $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText);
            }
        }
        //$this->logs('warning', 'searchtest', json_encode($combined_userlist));
        logger(LOG_DEBUG, 'ldap-search (' . $query . ')', json_encode($combined_userlist) , true);
        return array_slice($combined_userlist, 0, 5);

    }
}

class StaffLDAPMultiAuthentication extends StaffAuthenticationBackend implements AuthDirectorySearch {
    static $name = "LDAP Authentication";
    static $id = "ldap";
    function __construct($config) {
        $this->_static = new LDAPMultiAuthentication($config);
        $this->config = $config;
    }
    function authenticate($username, $password = false, $errors = array()) {
        return $this
            ->_static
            ->authenticate($username, $password);
        //queries the user information
        
    }
    //General searching of new users
    function lookup($query) {
        $list = $this
            ->_static
            ->lookup($query);
        if ($list) {
            $list['backend'] = static ::$id;
            $list['id'] = static ::$id . ':' . $list['dn'];
        }
        logger('warning', 'lookup-result', $list, true);
        return ($list);
    }

    //General searching of users/staff
    function search($query) {
        if (strlen($query) < 3) return array();
        $list = $this
            ->_static
            ->search($query);
        foreach ($list as & $l) {
            $l['backend'] = static ::$id;
            $l['id'] = static ::$id . ':' . $l['dn'];
        }
        //$this
        // ->_static
        //  ->logs('warning', 'search', json_encode($list));
        return $list;
    }
}
class ClientLDAPMultiAuthentication extends UserAuthenticationBackend {
    static $name = "LDAP Authentication";
    static $id = "ldap.client";
    function __construct($config) {
        $this->_static = new LDAPMultiAuthentication($config, 'client');
        $this->config = $config;
        if ($domain = $config->get('basedn')) self::$name .= sprintf(' (%s)', $domain);
    }
    function authenticate($username, $password = false, $errors = array()) {
        $object = $this
            ->_static
            ->authenticate($username, $password);
        if ($object instanceof ClientCreateRequest) $object->setBackend($this);
        return $object;
    }
}
