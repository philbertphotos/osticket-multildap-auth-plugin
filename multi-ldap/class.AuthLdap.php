<?php
/**
 * class.AuthLdap.php , version 1.2
 * Joseph Philbert, October 2015
 * Provides LDAP authentication and user functions.
 *
 * Not intended as a full-blown LDAP access class - but it does provide
 * several useful functions for dealing with users.
 * Note - This version as been primary modified to work with 
 * Active Directory.
 * See the README file for more information and examples.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * ChangeLog
 * ---------
 * version 1.1, 08.03.2016, Joseph Philbert <joe@philbertphotos.com> 
 * Added ObjectSid and ObjectGUID functions
 * Uploaded to Github
 *
 * version 1.0, 10.13.2015, Joseph Philbert <joe@philbertphotos.com> 
 * Updated functions for better integration with Active Directory
 * Removed several PHP warnings for unset variables
 * Added Timeout updated code to support LDAP PROTOCOL_VERSION # fixed a bind bug
 * 
 * version 0.2, 11.04.2003, Michael Joseph <michael@jamwarehouse.com> 
 * - Added switches and workarounds for Active Directory integration
 * - Change documentation to phpdoc style (http://phpdocu.sourceforge.net)
 * - Added a constructor
 * - Added an attribute array parameter to the getUsers method
 * Original Author - Mark Round, April 2002 - http://www.markround.com/unix
 */

class AuthLdap {

    // 1.1 Public properties -----------------------------------------------------
    /**
     * Array of server IP address or hostnames
     */
    var $server;
    /**
     * The base DN (e.g. "dc=foo,dc=com")
     */
    var $dn;
    /**
     * the directory server, currently supports iPlanet and Active Directory
     */
    var $serverType;
    /**
     * Active Directory authenticates using user@domain
     */
    var $domain;
    /**
     * The user to authenticate with when searching
     * Active Directory doesn't support anonymous access
     */
    var $searchUser;
    /**
     * The password to authenticate with when searching
     * Active Directory doesn't support anonymous access
     */
    var $searchPassword;
    /**
     *  Where the user records are kept
     */
    var $people;
    /**
     * Where the group definitions are kept
     */
    var $groups;
    /**
     * The last error code returned by the LDAP server
     */
    var $ldapErrorCode;
    /**
     * Text of the error message
     */
    var $ldapErrorText;

    // 1.2 Private properties ----------------------------------------------------
    /**
     * The internal LDAP connection handle
     */
    var $connection;
    /**
     * Result of any connections etc.
     */
    var $result;

    /**
     * Constructor- creates a new instance of the authentication class
     *
     * @param string the ldap server to connect to
     * @param string the base dn
     * @param string the server type- current supports iPlanet and ActiveDirectory
     * @param string the domain to use when authenticating against Active Directory
     * @param string the username to authenticate with when searching if anonymous binding is not supported
     * @param string the password to authenticate with when searching if anonymous binding is not supported
     */
    function AuthLdap ($sLdapServer = "", $sBaseDN = "", $sServerType = "", $sDomain = "", $searchUser = "", $searchPassword = "") {
        @$this->server = array($sLdapServer);
        @$this->dn = $sBaseDN;
        @$this->serverType = $sServerType;
        $this->domain = $sDomain;
        $this->searchUser = $searchUser;
        $this->searchPassword = $searchPassword;
    }
    
    // 2.1 Connection handling methods -------------------------------------------

    /**
     * 2.1.1 : Connects to the server. Just creates a connection which is used
     * in all later access to the LDAP server. If it can't connect and bind
     * anonymously, it creates an error code of -1. Returns true if connected,
     * false if failed. Takes an array of possible servers - if one doesn't work,
     * it tries the next and so on.
     */
    function connect() {
        foreach ($this->server as $key => $host) {
            $this->connection = ldap_connect( $host);
			ldap_set_option ($this->connection, LDAP_OPT_REFERRALS, 0) or die('Unable to set LDAP opt referrals');
			ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION, 3) or die('Unable to set LDAP protocol version');
			ldap_set_option($this->connection, LDAP_OPT_TIMELIMIT, 5) or die('Timelimit reached');
            ldap_set_option($this->connection, LDAP_OPT_NETWORK_TIMEOUT, 5) or die('Network timed out');

            if ( $this->connection) {
                if ($this->serverType == "ActiveDirectory") {
                    return true;
                } else {
                    // Connected, now try binding anonymously
                    $this->result=@ldap_bind( $this->connection);
                }
                return true;
            }
        }

        $this->ldapErrorCode = -1;
        $this->ldapErrorText = "Unable to connect to any server";
        return false;
    }

    /**
     * 2.1.2 : Simply closes the connection set up earlier.
     * Returns true if OK, false if there was an error.
     */
    function close() {
        if ( !@ldap_close( $this->connection)) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        } else {
            return true;
        }
    }

    /**
     * 2.1.3 : Anonymously binds to the connection. After this is done,
     * queries and searches can be done - but read-only.
     */
    function bind() {
        if ( !$this->result=@ldap_bind( $this->connection)) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        } else {
            return true;
        }
    }



    /**
     * 2.1.4 : Binds as an authenticated user, which usually allows for write
     * access. The FULL dn must be passed. For a directory manager, this is
     * "cn=Directory Manager" under iPlanet. For a user, it will be something
     * like "uid=jbloggs,ou=People,dc=foo,dc=com".
     */    
    function authBind( $bindDn,$pass) {
        if ( !$this->result = @ldap_bind( $this->connection,$bindDn,$pass)) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        } else {
            return true;
        }
    }

    // 2.2 Password methods ------------------------------------------------------

    /**
     * 2.2.1 : Checks a username and password - does this by logging on to the
     * server as a user - specified in the DN. There are several reasons why
     * this login could fail - these are listed below.
     */
    function checkPass( $uname,$pass) {
        /* Construct the full DN, eg:-
        ** "uid=username, ou=People, dc=orgname,dc=com"
        */
        if ($this->serverType == "ActiveDirectory") {
            $checkDn = "$uname@$this->domain";
        } else {
            $checkDn = $this->getUserIdentifier() . "=$uname, " . $this->setDn(true);
        }
        // Try and connect...
        $this->result = @ldap_bind( $this->connection,$checkDn,$pass);
        if ( $this->result) {
            // Connected OK - login credentials are fine!
            return true;
        } else {
            /* Login failed. Return false, together with the error code and text from
            ** the LDAP server. The common error codes and reasons are listed below :
            ** (for iPlanet, other servers may differ)
            ** 19 - Account locked out (too many invalid login attempts)
            ** 32 - User does not exist
            ** 49 - Wrong password
            ** 53 - Account inactive (manually locked out by administrator)
            */
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        }
    }


    /**
     * 2.2.2 : Allows a password to be changed. Note that on most LDAP servers,
     * a new ACL must be defined giving users the ability to modify their
     * password attribute (userPassword). Otherwise this will fail.
	 * supports password history
     */
    function changePass( $uname,$oldPass,$newPass, $history=false) {
        // builds the appropriate dn, based on whether $this->people and/or $this->group is set
        if ($this->serverType == "ActiveDirectory") {
            $checkDn = "$uname@$this->domain";
        } else {
            $checkDn = $this->getUserIdentifier() . "=$uname, " . $this->setDn(true);
        }
        $this->result = @ldap_bind( $this->connection,$checkDn,$oldPass);
		
		if ($history && $this->result) {
			$ctrl1 = array(
				// LDAP_SERVER_POLICY_HINTS_OID for Windows 2012 and above
				"oid" => "1.2.840.113556.1.4.2239",
				"value" => sprintf("%c%c%c%c%c", 48, 3, 2, 1, 1));

			$ctrl2 = array(
				// LDAP_SERVER_POLICY_HINTS_DEPRECATED_OID for Windows 2008 R2 SP1 and above
				"oid" => "1.2.840.113556.1.4.2066",
				"value" => sprintf("%c%c%c%c%c", 48, 3, 2, 1, 1));

			if (!ldap_set_option($this->connection, LDAP_OPT_SERVER_CONTROLS, array($ctrl1, $ctrl2))) {
				//error_log("ERROR: Failed to set server controls");
				$this->ldapErrorCode = ldap_errno( $this->connection);
                $this->ldapErrorText = ldap_error( $this->connection);
                return false;
			}

			$this->result = ldap_mod_replace($this->connection, $checkDn, $entry);
			   if ( $this->result) {
                // Change went OK
                return true;
            } else {
                // Couldn't change password...
                $this->ldapErrorCode = ldap_errno( $this->connection);
                $this->ldapErrorText = ldap_error( $this->connection);
                return false;
            }
			
		} else {

        if ( $this->result) {
            // Connected OK - Now modify the password...
            $info["userPassword"] = $newPass;
            $this->result = @ldap_modify( $this->connection, $checkDn, $info);
            if ( $this->result) {
                // Change went OK
                return true;
            } else {
                // Couldn't change password...
                $this->ldapErrorCode = ldap_errno( $this->connection);
                $this->ldapErrorText = ldap_error( $this->connection);
                return false;
            }
        } else {
            // Login failed - see checkPass method for common error codes
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        }
	  }
    }


    /**
     * 2.2.3 : Returns days until the password will expire.
     * We have to explicitly state this is what we want returned from the
     * LDAP server - by default, it will only send back the "basic"
     * attributes.
     */
    function checkPassAge ( $uname) {

        $results[0] = "passwordexpirationtime";
        // builds the appropriate dn, based on whether $this->people and/or $this->group is set
        $checkDn = $this->setDn(true);
        $this->result = @ldap_search( $this->connection,$checkDn,$this->getUserIdentifier()."=$uname",$results);

        if ( !$info=@ldap_get_entries( $this->connection, $this->result)) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        } else {
            /* Now work out how many days remaining....
            ** Yes, it's very verbose code but I left it like this so it can easily 
            ** be modified for your needs.
            */
            $date  = $info[0]["passwordexpirationtime"][0];
            $year  = substr( $date,0,4);
            $month = substr( $date,4,2);
            $day   = substr( $date,6,2);
            $hour  = substr( $date,8,2);
            $min   = substr( $date,10,2);
            $sec   = substr( $date,12,2);

            $timestamp = mktime( $hour,$min,$sec,$month,$day,$year);
            $today  = mktime();
            $diff   = $timestamp-$today;
            return round( ( ( ( $diff/60)/60)/24));
        }
    }

    // 2.3 Group methods ---------------------------------------------------------

    /**
     * 2.3.1 : Checks to see if a user is in a given group. If so, it returns
     * true, and returns false if the user isn't in the group, or any other
     * error occurs (eg:- no such user, no group by that name etc.)
     */
    function checkGroup ( $uname,$group) {
        // builds the appropriate dn, based on whether $this->people and/or $this->group is set
        $checkDn = $this->setDn(true);

		if (!$this->authBind($this->searchUser, $this->searchPassword)) 
			return false;

        // We need to search for the group in order to get it's entry.
		$this->result = ldap_search($this->connection, $checkDn, "(&(sAMAccountName=$uname))", array('memberOf' ));
		$entry = $this->cleanEntry(ldap_get_entries($this->connection, $this->result))->memberof;
	
		foreach ($entry as $val){
			$grp = str_replace('CN=', '', explode(",",$val)[0]);
			if (strtolower($grp) == strtolower($group))
				return true;
		}

        if ( !$entry) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( 'user not in group');
            return false;  // Couldn't find user in group...
        }
    }

    /* Groups the user is a member of
    * 
    * @param string $username The username to query
    * @param bool $recursive Recursive list of groups
    * @param bool $isGUID Is the username passed a GUID or a samAccountName
    * @return array
    */
    public function groups($username, $recursive = NULL, $isGUID = false)
    {
        if ($username === NULL) { return false; }
        if ($recursive === NULL) { $recursive = $this->adldap->getRecursiveGroups(); } // Use the default option if they haven't set it
        if (!$this->adldap->getLdapBind()) { return false; }
        
        // Search the directory for their information
        $info = @$this->info($username, array("memberof", "primarygroupid"), $isGUID);
        $groups = $this->adldap->utilities()->niceNames($info[0]["memberof"]); // Presuming the entry returned is our guy (unique usernames)

        if ($recursive === true){
            foreach ($groups as $id => $groupName){
                $extraGroups = $this->adldap->group()->recursiveGroups($groupName);
                $groups = array_merge($groups, $extraGroups);
            }
        }
        //Remove duplicate groups
        return array_unique ($groups);
    }
	
    // 2.4 Attribute methods -----------------------------------------------------
    /**
     * 2.4.1 : Returns an array containing a set of attribute values.
     * For most searches, this will just be one row, but sometimes multiple
     * results are returned (eg:- multiple email addresses)
     */
    function getAttribute ( $uname,$attribute, $raw = false) {
        // builds the appropriate dn, based on whether $this->people and/or $this->group is set
        $checkDn = $this->setDn( true);
        $results = array($attribute);

        // We need to search for this user in order to get their entry.
        $this->result = ldap_search( $this->connection,$checkDn,"(&(sAMAccountName=$uname))",$results);
        // Only one entry should ever be returned (no user will have the same uid)
		$entry = ldap_get_entries($this->connection, $this->result);

        if ( !$entry) {
            $this->ldapErrorCode = -1;
            $this->ldapErrorText = "Couldn't find attribute";
            return false;  // Couldn't find the user...
        } else {
			if (!$raw) {
			$value = $entry[0][$attribute][0];
			} else {
				$value = $entry[0];
			}
		}

        // Return attribute.
        return $value;
    }

    /**
     * 2.4.2 : Allows an attribute value to be set.
     * This can only usually be done after an authenticated bind as a
     * directory manager - otherwise, read/write access will not be granted.
     */
    function setAttribute( $uname, $attributes) {
		 $userDn = $this->userDn($uname);
		 if ($userDn === false) return false; 
		
		if (!$attributes) return (false);

		//Check for NULL values
		foreach($attributes as $key => $attribute) {
			 // Change attribute
			if(empty(trim($attribute))){
				$this->result = ldap_modify( $this->connection, $userDn, array($key=>array()));
				} else {
				$this->result = ldap_modify( $this->connection, $userDn, array($key=>$attribute));
			}
			if (is_array($attribute)){
				$this->result = ldap_modify( $this->connection, $userDn, array($key=>$attribute));
			}
			 if ($this->result == false){
				$this->ldapErrorCode = ldap_errno( $this->connection);
				$this->ldapErrorText = "Could not modify attribute"; 
				return false; 
			 }
		}
        
        return true;		
    }

    // 2.5 User methods ----------------------------------------------------------
    /**
     * 2.5.1 : Returns an array containing a details of users, sorted by
     * username. The search criteria is a standard LDAP query - * returns all
     * users.  The $attributeArray variable contains the required user detail field names
     */
    function getUsers( $search, $attributeArray, $filter = null) {
        // builds the appropriate dn, based on whether $this->people and/or $this->group is set
        $checkDn = $this->setDn( true);

        // Perform the search and get the entry handles
        
        // if the directory is AD, then bind first with the search user first if it fails return false
        if ($this->serverType == "ActiveDirectory") {
            if (!$this->authBind($this->searchUser, $this->searchPassword)) 
				return false;
        }

		// Checks for custom filter replaces all {q} with $search string.
		if ($filter == null) {
			$filter = $this->getUserIdentifier() . "=$search*";
		} else {
			$filter = str_replace("{q}", "$search", $filter);
		}
		
        $this->result = @ldap_search( $this->connection, $checkDn, $filter, $attributeArray,0,0);
		if (empty($this->result)) {
			$this->ldapErrorCode = 0;
            $this->ldapErrorText = "(" . ldap_error($this->connection).") No users found matching search criteria ".$search;
			return false;
		}
		
        $info = @ldap_get_entries( $this->connection, $this->result);
        for( $i = 0; $i < $info["count"]; $i++) {
            // Get the username, and create an array indexed by it...
            // Modify these as you see fit.
            $uname = $info[$i][$this->getUserIdentifier()][0];
            // add to the array for each attribute in my list
			//echo  json_encode($attributeArray);
            for ( $j = 0; $j < count( $attributeArray); $j++) {
                if (strtolower($attributeArray[$j]) == "dn") {
                    $userslist["$i"]["$attributeArray[$j]"]      = $info[$i][strtolower($attributeArray[$j])];
                } else if (strtolower($attributeArray[$j]) == "objectsid") {
					$userslist["$i"]["$attributeArray[$j]"]      = $this->SIDtoString($info[$i][strtolower($attributeArray[$j])][0]);              
				} else if (strtolower($attributeArray[$j]) == "objectguid") {
					$userslist["$i"]["$attributeArray[$j]"] = bin2hex($info[$i][strtolower($attributeArray[$j])][0]);                               
				} else {
					//Check if value is array
					if (is_array($info[$i][strtolower($attributeArray[$j])])){
					$userslist["$i"]["$attributeArray[$j]"] = $info[$i][strtolower($attributeArray[$j])];
					} else {
						$userslist["$i"]["$attributeArray[$j]"] = $info[$i][strtolower($attributeArray[$j])][0];
					}
                }
            }
        }

        if ( !@is_array( $userslist)) {
            /* Sort into alphabetical order. If this fails, it's because there
            ** were no results returned (array is empty) - so just return false.
            */
            $this->ldapErrorCode = -1;
            $this->ldapErrorText = "(" . ldap_error($this->connection).") No users found matching search criteria ".$search;
            return false;
        }
        return $userslist;
    }

	function pagedUsers ($attributeArray, $filter = null) {
			$checkDn = $this->setDn( true);
			$filter    = "(&(objectClass=user)(objectCategory=person)(sn=*))";
			// enable pagination with a page size of 100.
			$pageSize = 100;

			$x = '';

			do {
				ldap_control_paged_result($this->connection, $pageSize, true, $x);

				$this->result = ldap_search( $this->connection, $checkDn, $filter, $attributeArray,0,0);
				$entries = ldap_get_entries($this->connection, $this->result);
				echo json_encode($entries);
				if(!empty($entries)){
					for ($i = 0; $i < $entries["count"]; $i++) {
						$data['usersLdap'][] = array(
								'name' => $entries[$i]["cn"][0],
								'username' => $entries[$i]["userprincipalname"][0]
						);
					}
				}
				ldap_control_paged_result_response($this->connection, $this->result, $x);
			}
			
			while($x !== null && $x != '');
			
			return $data;
		}
		
    // 2.6 helper methods
    function SIDtoString($ADsid)
		{
		   $sid = "S-";
		   //$ADguid = $info[0]['objectguid'][0];
		   $sidinhex = str_split(bin2hex($ADsid), 2);
		   // Byte 0 = Revision Level
		   $sid = $sid.hexdec($sidinhex[0])."-";
		   // Byte 1-7 = 48 Bit Authority
		   $sid = $sid.hexdec($sidinhex[6].$sidinhex[5].$sidinhex[4].$sidinhex[3].$sidinhex[2].$sidinhex[1]);
		   // Byte 8 count of sub authorities - Get number of sub-authorities
		   $subauths = hexdec($sidinhex[7]);
		   //Loop through Sub Authorities
		   for($i = 0; $i < $subauths; $i++) {
			  $start = 8 + (4 * $i);
			  // X amount of 32Bit (4 Byte) Sub Authorities
			  $sid = $sid."-".hexdec($sidinhex[$start+3].$sidinhex[$start+2].$sidinhex[$start+1].$sidinhex[$start]);
		   }
		   return $sid;
		}
		
	function GUIDtoString($ADguid)
		{
		   $guidinhex = str_split(bin2hex($ADguid), 2);
		   $guid = "";
		   //Take the first 4 octets and reverse their order
		   $first = array_reverse(array_slice($guidinhex, 0, 4));
		   foreach($first as $value)
		   {
			  $guid .= $value;
		   }
		   $guid .= "-";
		   // Take the next two octets and reverse their order
		   $second = array_reverse(array_slice($guidinhex, 4, 2, true), true);
		   foreach($second as $value)
		   {
			  $guid .= $value;
		   }
		   $guid .= "-";
		   // Repeat for the next two
		   $third = array_reverse(array_slice($guidinhex, 6, 2, true), true);
		   foreach($third as $value)
		   {
			  $guid .= $value;
		   }
		   $guid .= "-";
		   // Take the next two but do not reverse
		   $fourth = array_slice($guidinhex, 8, 2, true);
		   foreach($fourth as $value)
		   {
			  $guid .= $value;
		   }
		   $guid .= "-";
		   //Take the last part
		   $last = array_slice($guidinhex, 10, 16, true);
		   foreach($last as $value)
		   {
			  $guid .= $value;
		   }
		   return $guid;
		}
    /**
     * Sets and returns the appropriate dn, based on whether there
     * are values in $this->people and $this->groups.
     *
     * @param boolean specifies whether to build a groups dn or a people dn 
     * @return string if true ou=$this->people,$this->dn, else ou=$this->groups,$this->dn
     */
    function setDn($peopleOrGroups) {

        if ($peopleOrGroups) {
            if ( isset($this->people) && (strlen($this->people) > 0) ) {
                $checkDn = "ou=" .$this->people. ", " .$this->dn;
            }
        } else {
            if ( isset($this->groups) && (strlen($this->groups) > 0) ) {
                $checkDn = "ou=" .$this->groups. ", " .$this->dn;
            }
        }

        if ( !isset($checkDn) ) {
            $checkDn = $this->dn;
        }
        return $checkDn;
    }

    /**
    * Take an LDAP query and return the clean names, without all the LDAP prefixes (eg. CN, DN)
    *
    * @param array $groups
    * @return array
    */
    public function cleanNames($groups)
    {

        $groupArray = array();
        for ($i=0; $i<$groups["count"]; $i++){ // For each group
            $line = $groups[$i];
            
            if (strlen($line)>0) { 
                // More presumptions, they're all prefixed with CN=
                // so we ditch the first three characters and the group
                // name goes up to the first comma
                $bits=explode(",", $line);
                $groupArray[] = substr($bits[0], 3, (strlen($bits[0])-3));
            }
        }
        return $groupArray;    
    }
	
    /**
    * Obtain the user's distinguished name based on their userid 
    * 
    * 
    * @param string $username The username
    * @return string
    */
    public function userDn($uname)
    {
        $user = $this->getAttribute($uname, "cn", true);
        if ($user["dn"] === NULL) { 
            return false; 
        }
        return $user["dn"];
    }
	
	/**
     * Convert LDAP resulting array to clean entries array with attributes and values
     *
     * @param $resultArray
     * @return array
     */
		function cleanEntry($values) {
			$object = new stdClass();
		foreach ($values[0] as $key => $value) {
			if(preg_match('/(?<!\S)\d{1,2}(?![^\s.,?!])/', $key) > 0 || $key == 'count')
				continue;	
					if ($key == 'dn') {
					$object->$key = $value;
					} else if ($value['count'] > 1){
						unset($value['count']);
						$object->$key = $value;
					} else {
						$object->$key = $value[0];	
					}
				}
		return $object;
		}
	
    /**
    * Get the RootDSE properties from a domain controller
    * 
    * @param array $attributes The attributes you wish to query e.g. defaultnamingcontext
    * @return array
    */
    function getRoot($attributes = array("*", "+")) {
       // if (!$this->bind){ return (false); }
        
        $sr = @ldap_read($this->connection, $this->dn, 'objectClass=*', $attributes);
        $entries = @ldap_get_entries($this->connection, $sr);
        return $entries;
    }
    
    /**
     * Returns the correct user identifier to use, based on the ldap server type
     */
    function getUserIdentifier() {
        if ($this->serverType == "ActiveDirectory") {
            return "samaccountname";
        } else {
            return "uid";
        }
    }
} // End of class
?>
