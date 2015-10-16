<?php
require_once(INCLUDE_DIR.'class.auth.php');
require_once(INCLUDE_DIR.'class.osticket.php');
require_once('class.AuthLdap.php');
class LDAPAuthentication {

    var $config;
    var $type = 'staff';

    function __construct($config, $type='staff') {
        $this->config = $config;
        $this->type = $type;
    }
    function getConfig() {
        return $this->config;
    }
	
    function getServers() {
        if (!($servers = $this->getConfig()->get('servers'))
                || !($servers = preg_split('/\s+/', $servers))) {
			return $servers;
        }
    }

	 function getDomain() {
        if (!($shortdomain = $this->getConfig()->get('shortdomain'))
                || !($shortdomain = preg_split(',', $shortdomain))) {
			return $shortdomain;
        }
    }
	
	function multi_re_key( & $array, $old_keys, $new_keys) {
    if (!is_array($array)) {
        ($array == "") ? $array = array(): false;
        return $array;
    }
    foreach($array as & $arr) {
        if (is_array($old_keys)) {
            foreach($new_keys as $k => $new_key) {
                (isset($old_keys[$k])) ? true: $old_keys[$k] = NULL;
                $arr[$new_key] = (isset($arr[$old_keys[$k]]) ? $arr[$old_keys[$k]] : null);
                unset($arr[$old_keys[$k]]);
            }
        } else {
            $arr[$new_keys] = (isset($arr[$old_keys]) ? $arr[$old_keys] : null);
            unset($arr[$old_keys]);
        }
    }
    return $array;
}

function errorlog($level, $title, $msg) {
	global $ost;
       if(is_array($msg) || is_object($msg)) {
            $output = json_encode($msg);
        } else {
            $output = $msg;
        }
        switch ($level) {
        case 'debug':
		$ost -> logDebug($title, $output);
            break;
        case 'info':
		$ost -> logInfo($title, $output);
			break;
		case 'error':
		$ost -> logError($title, $output);
			break;
		}
}

   /**
     * @param string $subject The subject string
     * @param string $ignore Set of characters to leave untouched
     * @param int $flags Any combination of LDAP_ESCAPE_* flags to indicate the
     *                   set(s) of characters to escape.
     * @return string
     */
    function ldap_escape($subject, $ignore = '', $flags = 0)
    {
        static $charMaps = array(
            LDAP_ESCAPE_FILTER => array('\\', '*', '(', ')', "\x00"),
            LDAP_ESCAPE_DN     => array('\\', ',', '=', '+', '<', '>', ';', '"', '#'),
        );

        // Pre-process the char maps on first call
        if (!isset($charMaps[0])) {
            $charMaps[0] = array();
            for ($i = 0; $i < 256; $i++) {
                $charMaps[0][chr($i)] = sprintf('\\%02x', $i);;
            }

            for ($i = 0, $l = count($charMaps[LDAP_ESCAPE_FILTER]); $i < $l; $i++) {
                $chr = $charMaps[LDAP_ESCAPE_FILTER][$i];
                unset($charMaps[LDAP_ESCAPE_FILTER][$i]);
                $charMaps[LDAP_ESCAPE_FILTER][$chr] = $charMaps[0][$chr];
            }

            for ($i = 0, $l = count($charMaps[LDAP_ESCAPE_DN]); $i < $l; $i++) {
                $chr = $charMaps[LDAP_ESCAPE_DN][$i];
                unset($charMaps[LDAP_ESCAPE_DN][$i]);
                $charMaps[LDAP_ESCAPE_DN][$chr] = $charMaps[0][$chr];
            }
        }

        // Create the base char map to escape
        $flags = (int)$flags;
        $charMap = array();
        if ($flags & LDAP_ESCAPE_FILTER) {
            $charMap += $charMaps[LDAP_ESCAPE_FILTER];
        }
        if ($flags & LDAP_ESCAPE_DN) {
            $charMap += $charMaps[LDAP_ESCAPE_DN];
        }
        if (!$charMap) {
            $charMap = $charMaps[0];
        }

        // Remove any chars to ignore from the list
        $ignore = (string)$ignore;
        for ($i = 0, $l = strlen($ignore); $i < $l; $i++) {
            unset($charMap[$ignore[$i]]);
        }

        // Do the main replacement
        $result = strtr($subject, $charMap);

        // Encode leading/trailing spaces if LDAP_ESCAPE_DN is passed
        if ($flags & LDAP_ESCAPE_DN) {
            if ($result[0] === ' ') {
                $result = '\\20' . substr($result, 1);
            }
            if ($result[strlen($result) - 1] === ' ') {
                $result = substr($result, 0, -1) . '\\20';
            }
        }

        return $result;
    }

    function setConnection() {
			$ldap             = new AuthLdap();
			$ldap->serverType = 'ActiveDirectory';
			return $ldap;
		}
	
	function connectcheck($ldapinfo) {
		$conninfo = array();
		foreach ($ldapinfo as $data) {
			$ldap             = new AuthLdap();
			$ldap->serverType = 'ActiveDirectory';

			$ldap->server = preg_split('/;|,/', $data['servers']);
			$ldap->domain = $data['sd'];
			$ldap->dn     = $data['dn'];
			
		if ($ldap->connect()) {
		  $conninfo[] = array('bool' => true,'msg' => $data['sd'] . ' Connected OK!');
        } else {
			$conninfo[] = array(false,$data['sd'] . " error:" . $ldap->ldapErrorCode . ": " . $ldap->ldapErrorText);
        }
    }
	return $conninfo;
	}
	
    function getEmail($ldapinfo, $user) {
			$filter = "(&(objectCategory=person)(objectClass=user)(|(sAMAccountName={q}*)(firstName={q}*)(lastName={q}*)(displayName={q}*)))";
			if ($userinfo = $ldapinfo->getUsers($user, array(
				'mail'
			) , $filter))
			return $userlist;
		}
	
function authenticate($username, $password = null)
	{
	$this->errorlog('debug', 'authenticate', $username . " " . $password);
	if (!$password) return null;
	// check if they used thier email to login.

	if (eregi("^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$", $username))
		{
		$username = str_replace(strrchr($username, '@') , '', $username);
		}

	$ldapinfo = array();
	foreach(preg_split('/\n/', $this->getConfig()->get('basedn')) as $i => $dn)
		{
		$dn = trim($dn);
		$servers = $this->getConfig()->get('servers');
		$serversa = preg_split('/\s+/', $servers);
		$sd = $this->getConfig()->get('shortdomain');
		$sda = preg_split('/;|,/', $sd);
		$ldapinfo[] = array(
			'dn' => $dn,
			'sd' => $sda[$i],
			'servers' => $serversa[$i]
		);
		}
	$chkUser = null;
	foreach($ldapinfo as $data)
		{
		$ldap = new AuthLdap();
		$ldap->serverType = 'ActiveDirectory';
		$ldap->server = preg_split('/;|,/', $data['servers']);
		$ldap->domain = $data['sd'];
		$ldap->dn = $data['dn'];
		if ($ldap->connect())
			{
			$conninfo[] = array(
				'bool' => true,
				'msg' => $data['sd'] . ' Connected OK!'
			);
			}
		  else
			{
			$conninfo[0]['bool'] = false;
			$conninfo[0]['msg'] = ($data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText);
			}

		if ($chkUser = $ldap->checkPass($username, $password) != false)
			{
			$loginfo[] = array(
				'bool' => true,
				'msg' => $data['sd'] . ' Password OK!'
			);
			}
		  else
			{
			$loginfo[0]['bool'] = false;
			$loginfo[0]['msg'] = ($data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText);
			}
			//$this->errorlog('debug', 'AuthStaffType', $this->type);
			//$this->errorlog('debug', 'LogInfo', $loginfo);
			if ($chkUser)
			break;
			}
		if ($chkUser)
				{
			return $this->authOrCreate($username);
				} else {
				return;
			}
		}

function authOrCreate($username) {
	global $cfg;
    switch($this->type) {
      case 'staff':
        if (($user = StaffSession::lookup($username)) && $user->getId()) {
          if (!$user instanceof StaffSession) {
            // osTicket <= v1.9.7 or so
            $user = new StaffSession($user->getId());
          }
          return $user;
        }
        break;
      case 'client':
	        // Lookup all the information on the user. Try to get the email
            // addresss as well as the username when looking up the user
            // locally.
            if (!($info = $this->search($username)[0]))
                return;

			$acct = ClientAccount::lookupByUsername($username);

      if ($acct && $acct->getId()) {
        $client = new ClientSession(new EndUser($acct->getUser()));
      }
      if (!$client) {
        $client = new ClientCreateRequest($this, $username, $info);
        if (!$cfg || !$cfg->isClientRegistrationEnabled() && self::$config->get('multiauth-force-register')) {
          $client = $client->attemptAutoRegister();
        }
      }
      return $client;
    }
    return null;
  }
   
function lookup($lookup_dn) {
	$this->errorlog('info', 'function lookup', $lookup_dn);
$lookup_user = array();
   preg_match('/(dc=(?:[^C]|C(?!N=))*)(?:;|$)/i', $lookup_dn, $match);
   $base_dn = str_replace(' ', '', $match[0]);
   
   $key = array_search($base_dn, preg_split('/\n/', $this->getConfig()->get('basedn')));
   
   $key = (!isset($key) || is_null($key)) ? 0 : $key; 
   
  $dn = trim($base_dn);
  
		$servers = $this->getConfig()->get('servers');
		$serversa = preg_split('/\s+/', $servers)[$key];
		
		$sd = $this->getConfig()->get('shortdomain');
		$sda = preg_split('/;|,/', $sd)[$key];
		
		$bind_dn = $this->getConfig()->get('bind_dn');
		$bind_dna = preg_split('/\n/', $bind_dn)[$key];
		
		$bind_pw = $this->getConfig()->get('bind_pw');
		$bind_pwa = preg_split('/;|,/', $bind_pw)[$key];
		
		$data = array(
			'dn' => trim($dn),
			'sd' => trim($sda),
			'servers' => trim($serversa),
			'bind_dn' => trim($bind_dna),
			'bind_pw' => trim($bind_pwa)
		);
		
		$ldap = new AuthLdap();
		$ldap->serverType = 'ActiveDirectory';
		$ldap->server = preg_split('/;|,/', $data['servers']);
		$ldap->dn = $data['dn'];
		$ldap->searchUser = $data['bind_dn'];
		$ldap->searchPassword = $data['bind_pw'];
		
		/*if ($ldap->connect()) {
			$this->errorlog('debug', 'LookupConnected', 'Connected OK!');
		        } else {
            $this->errorlog('debug', 'LookupProblem', 'Error code : ' . $ldap->ldapErrorCode . ' Error text : ' . $ldap->ldapErrorText);
        }*/
		
		if ($ldap->connect())
			{ 
		$filter = '(distinguishedName={q})';
			if ($temp_user = $ldap->getUsers($this->ldap_escape($lookup_dn), array(
				'sAMAccountName',
				'sn',
				'givenName',
				'displayName',
				'mail',
				'telephoneNumber',
				'distinguishedName'
			) , $filter))
				{
					
				$name = $temp_user[0]['givenName'] . ' ' . $temp_user[0]['sn'];

				$temp_user[0]['name'] = $name;
				
				$lookup_user = ($this->multi_re_key($temp_user, array(
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
				}	else {
				$conninfo[] = array(
				false,
				$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
			);
			
			$this->errorlog('debug', 'UserconnInfo', json_encode($conninfo));
				}
			}	else {
			$conninfo[] = array(
				false,
				$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
			);
			
			$this->errorlog('debug', 'ConnInfo', json_encode($conninfo));
			}
 //$this->errorlog('info', 'LookupInfo', json_encode($lookup_user));
  	return $lookup_user;
 }

function search($query)
	{
	$userlist = array();
	$ldapinfo = array();
	$combined_userlist = array();
	foreach(preg_split('/\n/', $this->getConfig()->get('basedn')) as $i => $dn)
		{
		$dn = trim($dn);
		$servers = $this->getConfig()->get('servers');
		$serversa = preg_split('/\s+/', $servers);
		
		$sd = $this->getConfig()->get('shortdomain');
		$sda = preg_split('/;|,/', $sd);
		
		$bind_dn = $this->getConfig()->get('bind_dn');
		$bind_dna = preg_split('/\n/', $bind_dn);
		
		$bind_pw = $this->getConfig()->get('bind_pw');
		$bind_pwa = preg_split('/;|,/', $bind_pw);
		
		$ldapinfo[] = array(
			'dn' => trim($dn),
			'sd' => trim($sda[$i]),
			'servers' => trim($serversa[$i]),
			'bind_dn' => trim($bind_dna[$i]),
			'bind_pw' => trim($bind_pwa[$i])
		);
		}

	foreach($ldapinfo as $data)
		{
		$ldap = new AuthLdap();
		$ldap->serverType = 'ActiveDirectory';
		$ldap->server = preg_split('/;|,/', $data['servers']);
		$ldap->dn = $data['dn'];
		$ldap->searchUser = $data['bind_dn'];
		$ldap->searchPassword = $data['bind_pw'];
			
		if ($ldap->connect())
			{
			$filter = "(&(objectCategory=person)(objectClass=user)(|(sAMAccountName={q}*)(firstName={q}*)(lastName={q}*)(displayName={q}*)))";
			if ($userlist = $ldap->getUsers($query, array(
				'sAMAccountName',
				'sn',
				'givenName',
				'displayName',
				'mail',
				'telephoneNumber',
				'distinguishedName'
			) , $filter))
				{
				//echo 'userlist: ' . json_encode($userlist);
				
				$temp_userlist = ($this->multi_re_key($userlist, array(
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
				$combined_userlist = array_merge($combined_userlist, $temp_userlist);
				}
			}
		  else
			{
			$conninfo[] = array(
				false,
				$data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
			);
			}
		}
	return $combined_userlist;
	}
}

class StaffLDAPAuthentication extends StaffAuthenticationBackend
        implements AuthDirectorySearch {
    static $name = "LDAP Authentication";
    static $id = "ldap";
    function __construct($config) {
        $this->_ldap = new LDAPAuthentication($config);
        $this->config = $config;
    }
    function authenticate($username, $password=false, $errors=array()) {
        return $this->_ldap->authenticate($username, $password);
		//queries the user information
    }
    function getName() {
        $config = $this->config;
        list($__, $_N) = $config::translate();
        return $__(static::$name);
    }
    function lookup($query) {
        $hit =  $this->_ldap->lookup($query);
        if ($hit) {
            $hit['backend'] = static::$id;
            $hit['id'] = static::$id . ':' . $hit['dn'];
			//$hit[0]['backend'] = static::$id;
            //$hit[0]['id'] = static::$id . ':' . $hit[0]['dn'];
        }
		//$this->_ldap->errorlog('debug', 'MainSearchHit2', $hit);
        return ($hit);
    }
    function search($query) {
        if (strlen($query) < 3)
            return array();
        $hits = $this->_ldap->search($query);
	    foreach ($hits as &$h) {
            $h['backend'] = static::$id;
            $h['id'] = static::$id . ':' . $h['dn'];
        }
		//$this->_ldap->errorlog('debug', 'MainSearchHits', $hits);
        return $hits;
    }
}
class ClientLDAPAuthentication extends UserAuthenticationBackend {
    static $name = "LDAP Authentication";
    static $id = "ldap.client";
    function __construct($config) {
        $this->_ldap = new LDAPAuthentication($config, 'client');
        $this->config = $config;
        if ($domain = $config->get('basedn'))
            self::$name .= sprintf(' (%s)', $domain);
    }
    function getName() {
	//$this->_ldap->errorlog('debug', 'getName', $this->config);
        $config = $this->config;
        list($__, $_N) = $config::translate();
        return $__(static::$name);
    }
    function authenticate($username, $password=false, $errors=array()) {
		//$this->_ldap->errorlog('debug', 'authenticateclient', $username);
        $object = $this->_ldap->authenticate($username, $password);
        if ($object instanceof ClientCreateRequest)
            $object->setBackend($this);
        return $object;
    }
}

require_once(INCLUDE_DIR.'class.plugin.php');
require_once('config.php');
class LdapMultiAuthPlugin extends Plugin {
    var $config_class = 'LdapMultiAuthPluginConfig';
    function bootstrap() {
        $config = $this->getConfig();
        if ($config->get('multiauth-staff'))
            StaffAuthenticationBackend::register(new StaffLDAPAuthentication($config));
        if ($config->get('multiauth-client'))
            UserAuthenticationBackend::register(new ClientLDAPAuthentication($config));
    }
}