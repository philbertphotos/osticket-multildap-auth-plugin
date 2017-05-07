<?php
	   $sync_info;
	   $sync_results;
	   $config = LdapMultiAuthPlugin::getConfig();
	   //LdapMultiAuthPlugin::logger('warning', 'Config Sync', json_encode($config->get()));
	   //LdapMultiAuthPlugin::logger('warning', 'Config SyncGet', json_encode($config->get('basedn')));
        function user_list() {
			//global $config;
			$config = LdapMultiAuthPlugin::getConfig();
            $userlist          = array();
	        $ldapinfo;
			foreach (preg_split('/;/', $config->get('basedn')) as $i => $dn)
			{
				$dn = trim($dn);
				$servers = $config->get('servers');
				$serversa = preg_split('/\s+/', $servers);

				$sd = $config->get('shortdomain');
				$sda = preg_split('/;|,/', $sd);

				$bind_dn = $config->get('bind_dn');
				$bind_dna = preg_split('/;/', $bind_dn) [$i];

				$bind_pw = $config->get('bind_pw');
				$bind_pwa = preg_split('/;|,/', $bind_pw) [$i];

				$ldapinfo[] = array(
					'dn' => $dn,
					'sd' => $sda[$i],
					'servers' => trim($serversa[$i]) ,
					'bind_dn' => trim($bind_dna) ,
					'bind_pw' => trim($bind_pwa)
				);
			}
            $combined_userlist = array();
			
            foreach ($ldapinfo as $data) {
                $ldap                 = new AuthLdap();
                $ldap->serverType     = 'ActiveDirectory';
                $ldap->server 		  = preg_split('/;|,/', $data['servers']);
                $ldap->dn             = $data['dn'];
                $ldap->searchUser     = $data['bind_dn'];
                $ldap->searchPassword = $data['bind_pw'];

                if ($ldap->connect()) {
                    $attr = str_getcsv(strtolower("samaccountname,mail,givenname,sn,whenchanged,useraccountcontrol,objectguid," . $config->get('sync_attr')), ',');

                    if ($userlist = $ldap->getUsers('', $attr, $config->get('sync_filter'))) {
                        $combined_userlist = array_merge($combined_userlist, $userlist);
                    }
                    //var_export($ldap->getRoot());
                } else {
                    $conninfo[] = array(
                        false,
                        $data['sd'] . " error: " . $ldap->ldapErrorCode . " - " . $ldap->ldapErrorText
                    );
                }
            }
            return $combined_userlist;
        }
              
        function sendAlertMsg($msg) {
		$config = LdapMultiAuthPlugin::getConfig();
		$ostmail = Email::lookup($config->get('sync_mailfrom'));
		Crypto::decrypt($ostmail->ht['userpass'], SECRET_SALT, $ostmail->ht['userid']);
		$alert_mail = new pssm_Mail();
		//$alert_mail->setTo($usr->mail, ($usr->sn .', ' .$usr->givenname))
		$alert_mail->setTo($config->get('sync_mailto'))
			->setSubject('MultiLdap Report')
			->setParameters('-f admin')
			->setFrom($ostmail->ht['email'], $ostmail->ht['name'])
			//->addMailHeader('Reply-To', _get_setting('system_email'), trim(_get_setting('system_name')))
			->addGenericHeader('X-Priority', '1 (Highest)')
			->addGenericHeader('Importance', 'High')
			->addGenericHeader('X-Mailer', $_pssm_name . ' ' . $_pssm_version)
			->setMessage($msg, true);
		$send = $alert_mail->send();
		$alert_mail->reset();
        }
       
        /*function sendNoticeMsg($message) {
        $alertmailer = null;
        $alertmailer = Email::lookup("1");
        $to = 'jphilbert@sttj.k12.vi';
       
        $subject = 'Website Change Reqest';
       
        $headers = "From: " . Email::lookup("1") . "\r\n";
        $headers .= "Reply-To: ". strip_tags('jphilbert@sttj.k12.vi') . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=ISO-8859-1\r\n";
        $headers .= "X-Mailer: LDAP-Sync";
        $headers .= "X-MS-Exchange-Organization-BypassClutter', 'true";
       
        mail($to, $subject, $message, $headers);
        }*/
		
    function _userobject($values) {
        $object = new stdClass();
    foreach ($values as $key => $value) {
                if (is_string($value)) {
                $object->$key = $value;
                } else if (is_array($value)) {
                    if ($value['count'] > 1){
                    unset($value['count']);
                    $object->$key = $value;
                } else {
                    $object->$key = $value[0]; 
                }
            }
    }
    return $object;
    }

	function contains($obj, $str) {
		return strpos($obj, $str) !== false;
	}	
	
	function changetime($when) {
	$time = gmmktime(substr($when,8,2),substr($when,10,2),substr($when,12,2),substr($when,4,2),substr($when,6,2),substr($when,0,4));
	return date("Y-m-d H:i:s", $time); //2017-04-11 22:53:20 Tue Nov 30 1999, 12:00:00 AM
	}	

	function ldapTimestamp($wintime) {
	if($wintime==0){return "Unknown";}
		$win_secs = substr($wintime,0,strlen($wintime)-7); // divide by 10,000,000 to get seconds
		$ts       = ($win_secs - 11644473600);           // 1.1.1601 -> 1.1.1970 : difference in seconds
	return date("Y-m-d H:i:s", $ts);
	}
	
	function formatmilliseconds($milliseconds)	{
    $seconds = floor($milliseconds / 1000);
    $minutes = floor($seconds / 60);
    $hours = floor($minutes / 60);
    $milliseconds = $milliseconds % 1000;
    $seconds = $seconds % 60;
    $minutes = $minutes % 60;

    $format = '%u:%02u:%02u.%03u';
    $time = sprintf($format, $hours, $minutes, $seconds, $milliseconds);
    return rtrim($time, '0');
	}
	
    /* Returns instead a manageable array:
     * array (
     *     [CN] => array( username )
    * [OU] => array( UNITNAME, Region, Country )
     *     [DC] => array ( subdomain, domain, com )
     */
    function parseLdapDn($dn, $lower = true)
    {
        $dn = ($lower ? strtolower($dn) : trim($dn));
        $parsr=ldap_explode_dn($dn, 0);
        $out = array();
        foreach($parsr as $key=>$value){
            if(FALSE !== strstr($value, '=')){
                list($prefix,$data) = explode("=",$value);
                $data=preg_replace("/\\\([0-9A-Fa-f]{2})/e", "''.chr(hexdec('\\1')).''", $data);
                if(isset($current_prefix) && $prefix == $current_prefix){
                    $out[strtolower($prefix)][] = $data;
                } else {
                    $current_prefix = strtolower($prefix);
                    $out[strtolower($prefix)][] = $data;
                }
            }
        }
        return $out;
    }
     
    function pluginpath($data) {
        $id = substr($data, -1);
        $plugin_path;
        $sql    = "SELECT `install_path` FROM " . TABLE_PREFIX . "plugin WHERE `id` = " . $id . ";";
        $result = db_query($sql);
        //echo $sql;
        while ($row = db_fetch_array($result)) {
            $plugin_path = $row['install_path'];
        }
        return 'include/' . $plugin_path;
    }
       
    function config($id) {
        //$num = substr($val, -1);
        $configvalues;
        $sql    = "SELECT `key`,`value` FROM " . TABLE_PREFIX . "config WHERE `namespace` = 'plugin." . $id . "';";
        $result = db_query($sql);
       
        while ($row = db_fetch_array($result)) {
            $configvalues[$row['key']] = $row['value'];
        }
        return $configvalues;
    }

        //Sanitize Number and add the correct extention format.
	function sanitize_phone($phone, $international = false) {
		$phone     = trim($phone);
		$phone     = preg_replace('/\s+(#|x|ext(ension)?)\.?:?\s*(\d+)/', 'X\3', $phone);
		$us_number = preg_match('/^(\+\s*)?((0{0,2}1{1,3}[^\d]+)?\(?\s*([2-9][0-9]{2})\s*[^\d]?\s*([2-9][0-9]{2})\s*[^\d]?\s*([\d]{4})){1}(\s*([[:alpha:]#][^\d]*\d.*))?$/', $phone, $matches);
		if ($us_number) {
			return $matches[4] . '-' . $matches[5] . '-' . $matches[6] . (!empty($matches[8]) ? '' . $matches[8] : '');
		}
		if (!$international) {
			/* SET ERROR: The field must be a valid U.S. phone number (e.g. 888-888-8888) */
			return false;
		}
		$valid_number = preg_match('/^(\+\s*)?(?=([.,\s()-]*\d){8})([\d(][\d.,\s()-]*)([[:alpha:]#][^\d]*\d.*)?$/', $phone, $matches) && preg_match('/\d{2}/', $phone);
		if ($valid_number) {
			return trim($matches[1]) . trim($matches[3]) . (!empty($matches[4]) ? ' ' . $matches[4] : '');
		}
		/* SET ERROR: The field must be a valid phone number (e.g. 888-888-8888) */
		return false;
	}
       
	   	function update_users($users) {
			global $log_report;
			$config = LdapMultiAuthPlugin::getConfig();
			//echo "User: ". json_encode($users)." </br>";		
			$i;
			foreach($users as $user) {
				$i++;
				
				$user_id = $user->user_id;
				$cn = $user->cn;
				$mail = $user->mail;
				$office = $user->physicaldeliveryofficename;
				$phone = $user->telephonenumber;
				$full_name = $user->givenname . ' ' . $user->sn;
				$user_name = $user->samaccountname;
				$mobile = $user->mobile;
				$objectguid = trim($user->objectguid);
				$logString = "User information from LDAP: ";
				$synckey = $sync_info[$user->user_id];
				
				//Get a list of attributes
				$attrs = str_getcsv($config->get('sync_attr'), ',');
				
				foreach($attrs as $attr) {
					//$user->$attr = $user->$attr;
					$logString = $logString . "'" . $attr . "'=" . $user->$attr . " ; ";
				}
				
				//Update account if user is disabled to "Administratively Locked" ... enabled users are renabled.
				if ($user->useraccountcontrol == 514 || $user->useraccountcontrol == 66050 && $synckey['status'] == 1) {
					$lock_user_sql = db_query("UPDATE ".TABLE_PREFIX."user_account
									SET status = '3' WHERE user_id = " . $user->user_id);
						$log_report['status'] = "(Acct Disabled)";
				} else {
					if ($synckey['status'] == 3) {
					$lock_user_sql = db_query("UPDATE ".TABLE_PREFIX."user_account
									SET status = '1' WHERE user_id = " . $user->user_id);
						$log_report['status'] = "(Acct Enabled)";
					}
				}
				
				//Update Email			
				if ($synckey['mail'] != $user->mail){
				$result = db_query("UPDATE ".TABLE_PREFIX."user_email
									SET address = \"" . $user->mail . "\" WHERE ".TABLE_PREFIX."user_email.user_id = " . $user->user_id);
				$changed_attr[] = "email";
				}
					// Update LDAP Attributes from AD for the osTicket user configured in config.php
					preg_match_all('/(.*?):\s?(.*?)(,|$)/', strtolower($config->get('sync_map')) , $matches);
					$ost_contact_info_fields = array_combine(array_map('trim', $matches[1]) , $matches[2]);
					
			foreach ($ost_contact_info_fields as $ost_contact_field => $ost_contact_info_field_ldapattr) {
		
						// Debug
						//echo "attr: ".$ost_contact_field.'</br>';
		
						$current_field = $ost_contact_field;
						$check_duplicate = "SELECT ".TABLE_PREFIX."form_field.id, ".TABLE_PREFIX."form_field.name, ".TABLE_PREFIX."form_entry_values.value FROM ".TABLE_PREFIX."user
										LEFT JOIN ".TABLE_PREFIX."user_account on ".TABLE_PREFIX."user.id=".TABLE_PREFIX."user_account.user_id
										LEFT JOIN ".TABLE_PREFIX."form_entry on ".TABLE_PREFIX."user.id=".TABLE_PREFIX."form_entry.object_id
										LEFT JOIN ".TABLE_PREFIX."form on ".TABLE_PREFIX."form.id=".TABLE_PREFIX."form_entry.form_id
										LEFT JOIN ".TABLE_PREFIX."form_entry_values on ".TABLE_PREFIX."form_entry.id=".TABLE_PREFIX."form_entry_values.entry_id
										LEFT JOIN ".TABLE_PREFIX."form_field on ".TABLE_PREFIX."form_entry_values.field_id=".TABLE_PREFIX."form_field.id
										WHERE  ".TABLE_PREFIX."user_account.user_id =" . $user->user_id . " AND ".TABLE_PREFIX."form.id = '1' AND ".TABLE_PREFIX."form_field.name = '$current_field';";
				
				if ($current_field == 'phone' || $current_field == 'mobile') {
							$current_ldap_value = sanitize_phone($user->$ost_contact_info_field_ldapattr);
						} else {
							$current_ldap_value = $user->$ost_contact_info_field_ldapattr;
						}
		
						// Check for empty or Duplicates values
						// $sql_value = db_fetch_field(db_query($check_duplicate))->fetch_object()->value.'<br />';
		
						$res = db_query($check_duplicate);
						if (db_num_rows($res) == 1) {
							while ($row = db_fetch_array($res)) {
								if ($row['name'] == 'phone' || $row['name'] == 'mobile') {
									$sql_value = sanitize_phone($row['value']);
								}
								else {
									$sql_value = $row['value'];
								}
		
								if (empty($row['value'])) $sql_value = NULL;
							}
						}
						else {
							$sql_value = NULL;
						}
		
						if ($ost_contact_field == 'name') {
							$sql_value = db_result(db_query("SELECT name FROM ost_user									
								WHERE id = " . $user->user_id));
						}
		
						if ($sql_value != $current_ldap_value) {
		
							// Debug
							// echo "<p>Check $ost_contact_field - SQL: $sql_value LDAP: $current_ldap_value</p>";
		
							if (!empty($current_ldap_value)) {
								if ($ost_contact_field != 'name') {
									$update_ostuser_sql = "INSERT INTO ".TABLE_PREFIX."form_entry_values(entry_id, field_id, value)
									values (
									(SELECT id FROM `".TABLE_PREFIX."form_entry` WHERE `object_id` = (SELECT user_id FROM `".TABLE_PREFIX."user_account` WHERE `user_id` = '" . $user->user_id . "') AND form_id = 1),
									(SELECT id FROM `".TABLE_PREFIX."form_field` WHERE `name` = '" . $ost_contact_field . "' AND form_id = 1), \"" . $current_ldap_value . "\")
									ON DUPLICATE KEY UPDATE value = \"" . $current_ldap_value . "\";";
								} else {
								$default_user = db_result(db_query("SELECT address FROM `".TABLE_PREFIX."user_email` WHERE `user_id` = " . $user->user_id));
								if ($default_user != $full_name)
									$update_ostuser_sql = "UPDATE ".TABLE_PREFIX."user
									SET name = \"" . $full_name . "\" WHERE id = ". $user->user_id;
								}
		
								// update changed field
								$result = db_query($update_ostuser_sql);
								if (!$result){
									$log_report['status'] .= " (Field Write Error[$ost_contact_field])";
									//error_log ($ost_contact_field ." : ". $update_ostuser_sql);
									$changed_attr = NULL;
									continue;
								}
								//update the field that was changed
								$changed_attr[] = $ost_contact_field;
							}
						}
					}
		
					if (!empty($changed_attr)){
					$log_report['status'] .= "(Updated)";
						
					} else {
					$log_report['status'] .= "(No Changes)";
					}
					//Update user When Change time.
						if ($synckey['updated'] != changetime($user->whenchanged))
						$result = db_query("UPDATE ".TABLE_PREFIX."ldap_sync SET updated =
								\"" . changetime($user->whenchanged) . "\" WHERE id = " . $user->user_id);
						//echo "<p>$user_name create time: ".changetime($user->whenchanged)." - ". $synckey['updated'] ." ". json_encode($result)."</p>";	
						$log_report['body'].= "<tr>
							<td>$i</td>
							<td>" . $user->samaccountname . "</td>
							<td><strong>" . (!empty($changed_attr) ? implode(", ", $changed_attr) : '0') . "</strong></td>
							<td>" . $log_report['status'] . "</td></tr>";
					
					$changed_attr = NULL;
					$log_report['status'] = NULL;
			}
		}
			
    function check_users() {
            global $log_report;
			 $sync_time_start = microtime(true);
			$config = LdapMultiAuthPlugin::getConfig();
			$sync_results;
            //if (!empty($this)) {
                //$config      = config($this->ht['id']);
			//	LdapMultiAuthPlugin::logger('warning', 'check_users', json_encode($config));
		//echo "Table: ". json_encode(TABLE_PREFIX)." </br>";
            $list = user_list($config);
            $log_header = ("(" . count($list) . ") 	total ldap entries.<br>");
			$sync_results['totalldap'] = count($list);
           // $log_header = ("TimeZone " . json_encode($tt) ." !<br>");
           
            $log_table      = '<style>
									table {
										font-family: arial, sans-serif;
										border-collapse: collapse;
										width: 100%;
									}

									td, th {
										border: 1px solid #dddddd;
										text-align: left;
										padding: 8px;
									}

									tr:nth-child(even) {
										background-color: #dddddd;
									}
								</style>
										<table border="1" width="100%" cellspacing="1" cellpadding="2" border="0">
									<tbody>
										<tr>
											<th style="text-align: left;">#</th>
											<th style="text-align: left;">Username</th>
											<th style="text-align: left;">Updated</th>
											<th style="text-align: left;">Status</th>
										</tr>';
            //Clean User Array
            $ad_users = array();
            foreach($list as $arr){
                    //echo $key . " -:- " . json_encode(_userobject($value))."</br>";
                    //echo json_encode($arr['mail'][0])."</br>";
			//if (contains($arr['dn'][0], 'OU=_')) {
                $ad_users[$arr['mail'][0]] = _userobject($arr);
			//}
            }
			ksort($ad_users);
			
            $guid_users = array();
            foreach($list as $val){
			//	if (contains($val['dn'][0], 'OU=_')) {
				$guid_users[$val['objectguid']] = _userobject($val);
			//	}
            }
			ksort($guid_users);

            //echo json_encode($guid_users);
           //echo json_encode($ad_users)."<br><br>";
		   
		   //***************Sync Agents************************
            // Check if agents shall be updated with LDAP info
            if ($config->get('sync-agents')) {
                // Select all osTicket Agents            
                $qry_ostagents = "SELECT staff.username, ".TABLE_PREFIX."staff.email, ".TABLE_PREFIX."staff.phone, ".TABLE_PREFIX."staff.phone_ext as ext, ".TABLE_PREFIX."staff.mobile FROM ".TABLE_PREFIX."staff WHERE ".TABLE_PREFIX."staff.username IS NOT NULL";
               
                $res_ostagents = db_query($qry_ostagents);
                
				// Update Header - Total of osTicket agents
                $log_header .= ("Number of osTicket agents: " . db_num_rows($res_ostagents) . '<br>');
				$sync_results['totalagents'] = db_num_rows($res_ostagents);

                // Go thru every osTicket agent and modify every osTicket agents information 
				//db_assoc_array($res_ostagents)

                foreach (db_assoc_array($res_ostagents, MYSQLI_ASSOC) as $sql_ostagents) {
                    $updates = array();
                    $key     = $sql_ostagents['email']; //Key value for sorting
					//echo json_encode($sql_ostagents)."<br><br>";
                   
                    // Check if osTicket agent is also an LDAP user
                    if ($sql_ostagents['email'] == $ad_users[$key]->mail) {
                        $split_num  = $ad_users[$key]->telephonenumber;
                        $chk_number = preg_match('/\D*\(?(\d{3})?\)?\D*(\d{3})\D*(\d{4})\D*(\d{1,8})?/', $split_num, $matches);
                        if ($chk_number) {
                            $phone = (!empty($matches[1]) ? $matches[1] . '-' : '') . $matches[2] . '-' . $matches[3];
                            $ext   = (!empty($matches[4]) ? $matches[4] : '');
                        }
                       
                        // Just update telephone and mobile number for agents
                        // Telephonenumber and extention if any
                        if ($ad_users[$key]->telephonenumber != $sql_ostagents['phone']) {
                            $qry_update_ostagent_telephonenumber = "UPDATE ".TABLE_PREFIX."staff
                       SET phone ='" . ($phone) . "', phone_ext = '$ext'
                       WHERE ".TABLE_PREFIX."staff.username ='" . $ad_users[$key]->samaccountname . "'";
                            $updates[] = 'phone';
							
                            $result = db_query($qry_update_ostagent_telephonenumber);
                        }
						
                        // Mobile Number
                        if ($ad_users[$key]->mobile != $sql_ostagents['mobile']) {
                            $qry_update_ostagent_mobile        = "UPDATE ".TABLE_PREFIX."staff
                       SET mobile='" . sanitize_phone($ad_users[$key]->mobile) . "'
                       WHERE (".TABLE_PREFIX."staff.username='" . $ad_users[$key]->samaccountname . "')";
                            $updates[]                         = 'mobile';
                            $result = db_query($qry_update_ostagent_mobile);
							
							if (!$result)
								$log_report['status'] = "(Error)";							
                        }
                        
                        if (!empty($changed_attr))
                        $log_report['agent'] .= "<tr>
                     <td>#agent</td>
                     <td>" . $ad_users[$key]->samaccountname . "</td>
                     <td><strong>" . db_affected_rows() . "</strong></td>
                     <td>" . $log_report['status'] . "</td></tr>";
					 //<td <?php if ($u->contains($result, "ERROR")) echo 'style="background-color: beige;"' echo $result; </td>
                    } else {
                        // DEBUG and OsTicket Agent not in LDAP
                        //echo ("osTicket Agent: " . $sql_ostagents['username'] . " not existing in LDAP. Skipping this agent..." . '<br>');
                    }
                }
            } else {
                // echo ("Configuration to update agents is set to " . $config->get('sync-agents'] . ". skipping...." . '<br>');
            }
			
		   //***************Sync Users************************
            if ($config->get('sync-users')) {
			   //Cleanup ID's with empty objectguid
			   db_query("DELETE FROM ".TABLE_PREFIX."ldap_sync WHERE guid IS NULL OR guid = ''");
			   
			   //Remove objectguid that is not in the ost_user table
			   db_query("DELETE FROM `".TABLE_PREFIX."ldap_sync` WHERE NOT EXISTS (SELECT * FROM `".TABLE_PREFIX."user` WHERE id = ".TABLE_PREFIX."ldap_sync.id);");

						//echo json_encode($emailusers).'<br>';
						
						//Update Global Array;
						$sync_array = db_query("SELECT ".TABLE_PREFIX."user.id as user_id, ".TABLE_PREFIX."user_email.id as email_id,".TABLE_PREFIX."user.name, ".TABLE_PREFIX."user_email.address as mail,  ".TABLE_PREFIX."user_account.status ,".TABLE_PREFIX."ldap_sync.updated
									FROM ".TABLE_PREFIX."user 
									LEFT JOIN ".TABLE_PREFIX."user_email on ".TABLE_PREFIX."user.id=".TABLE_PREFIX."user_email.user_id
									LEFT JOIN ".TABLE_PREFIX."user_account on ".TABLE_PREFIX."user.id = ".TABLE_PREFIX."user_account.user_id
									LEFT JOIN ".TABLE_PREFIX."ldap_sync on ".TABLE_PREFIX."user.id = ".TABLE_PREFIX."ldap_sync.id;");

						foreach (db_assoc_array($sync_array, MYSQLI_ASSOC) as $sync){
							$uid = $sync["user_id"]; 
								unset($sync["user_id"]);
							$sync_info[$uid] = $sync;													
						}
						//echo json_encode($sync_info) ."</br>";
						
						//Query only users that have no guid.
						$qry_ostusers = db_query("SELECT ".TABLE_PREFIX."user.id as user_id, 
										".TABLE_PREFIX."user_email.id as email_id,".TABLE_PREFIX."user.name, ".TABLE_PREFIX."user_email.address as mail 
										FROM ".TABLE_PREFIX."user LEFT JOIN ".TABLE_PREFIX."user_email on ".TABLE_PREFIX."user.id=".TABLE_PREFIX."user_email.user_id 
										WHERE NOT EXISTS (select ".TABLE_PREFIX."ldap_sync.id from ".TABLE_PREFIX."ldap_sync 
										WHERE user_id = ".TABLE_PREFIX."ldap_sync.id);");
			
                // Go thru every osTicket user and add them to the sync table if a match is found
				foreach (db_assoc_array($qry_ostusers, MYSQLI_ASSOC) as $sql_ostusers) {
                    $key = trim(strtolower($sql_ostusers['mail'])); //Key value for matching users
					$user_ldap = $ad_users[$key];
					//if (contains($sql_ostusers['mail'], 'coreen'))
						//echo json_encode($sql_ostusers) . " -- " . json_encode($ad_users[$key])."</br>";
					
					if (strtolower($key == $user_ldap->mail)) {
						 //echo json_encode($sql_ostusers) . " -- " . json_encode($ad_users[$key])."</br>";
						//Lets check users and add them to the guid table if a match is found
						$result = db_query("SELECT id FROM ".TABLE_PREFIX."ldap_sync WHERE id = '".$sql_ostusers['user_id']."'");
						if(db_num_rows($result) == 0 && $key == $ad_users[$key]->mail) {
							db_query ("INSERT INTO ".TABLE_PREFIX."ldap_sync(id, guid, updated)
                            values ('".$sql_ostusers['user_id']."', '".$ad_users[$key]->objectguid."', '".date('Y-m-d H:i:s')."')
							ON DUPLICATE KEY UPDATE id = \"" . $sql_ostusers['user_id'] . "\", guid = \"" . $ad_users[$key]->objectguid . "\", updated = \"".date('Y-m-d H:i:s')."\";");
						}
					}
                }
												
				//Go through and create accounts for new guest users verified in LDAP
					$sql_guests = "SELECT id, guid
					FROM ".TABLE_PREFIX."ldap_sync
					WHERE NOT EXISTS (SELECT user_id FROM ".TABLE_PREFIX."user_account WHERE user_id = ".TABLE_PREFIX."ldap_sync.id);";
					
				$default_timezone = db_result(db_query("SELECT value FROM `".TABLE_PREFIX."config` WHERE `key` = 'default_timezone'"));
				$default_lang = db_result(db_query("SELECT value FROM `".TABLE_PREFIX."config` WHERE `key` = 'system_language'"));
				
				$qry_guests = db_query($sql_guests);	
					foreach (db_assoc_array($qry_guests, MYSQLI_ASSOC) as $guests) {
						$key = $guests['guid'];
						db_query ("INSERT INTO ".TABLE_PREFIX."user_account(user_id, status, timezone, username, backend, extra, registered)
						values ('".$guests['id']."',1, '$default_timezone', '". $guid_users[$key]->samaccountname 
						."','ldap.client', '{\"browser_lang\":\"$default_lang\"}', '".date('Y-m-d H:i:s')."');");
					}
				
				// Update all users based on the ObjectID
				$sql_ostguid = db_query("SELECT * FROM `".TABLE_PREFIX."ldap_sync` WHERE guid IS NOT NULL");
				$updateusers = array();
				foreach (db_assoc_array($sql_ostguid, MYSQLI_ASSOC) as $guid) {
					$key = $guid['guid']; //Key value for sorting
				
				//Get UserID based on key
				//echo $guid['id'] . " -- " . json_encode($guid_users[$key])."</br>";
				if (array_key_exists($key, $guid_users)){	
					$guid_users[$key]->user_id = $guid['id'];
					if ($_REQUEST['full'] || $config->get('sync_full')){
							$updateusers[] = $guid_users[$key];
						} elseif ($guid['updated'] != changetime($guid_users[$key]->whenchanged)){
							$updateusers[] = $guid_users[$key];
							//echo $guid['id'] . " -- " . json_encode($guid_users[$key])."</br>";
							//echo $guid_users[$key]->samaccountname . " " . $guid['updated'] . " -- " . changetime($guid_users[$key]->whenchanged)."</br>";
						}
					}
				}
				//$log_header .= ("Users not Synced: " . db_num_rows($qry_ostusers) . '<br>');
				$log_header .= ("(" . db_num_rows($qry_ostusers) . ') 	users not in ldap.<br>');
				$log_header .= ("(" . count($updateusers) . ') 	users synced.<br>');
				//$log_header .= json_encode($updateusers) . '.<br>';
				//if ($config->get('sync_full')) $config->get('sync_full') = false;
				$sync_results['updatedusers'] = count($updateusers);
				update_users($updateusers);
            }
			
			//execution time of the script
			//$time_end = microtime(true);
			$execution_time = number_format(microtime(true) - $sync_time_start,3) * 1000;
            $log_footer .= '    </tbody>
                                    </table>
									<b>Total Execution Time:</b> '.formatmilliseconds($execution_time).' secs</br>';
			$sync_results['executetime'] = formatmilliseconds($execution_time);
            //if (isset($_REQUEST['id']))
				$msg = $log_header . $log_table . $log_report['agent'] . $log_report['body'] . $log_footer;
            //echo $msg;
			if ($sync_results['updatedusers'] >= 1 && $config->get('sync-reports'))
					sendAlertMsg($msg);
		return $sync_results;
        }
?>
