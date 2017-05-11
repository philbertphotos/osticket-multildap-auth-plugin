<?php
require_once (INCLUDE_DIR . 'class.plugin.php');
require_once (INCLUDE_DIR . 'class.forms.php');

class LdapMultiAuthPluginConfig extends PluginConfig {

	function translate() {
		if (!method_exists('Plugin', 'translate')) {
			return array(
				function ($x) {
					return $x;
				}
				,
				function ($x, $y, $n) {
					return $n != 1 ? $y : $x;
				}
				,
			);
		}
		return Plugin::translate('multiauth');
	}

	function pluginpath() {
		$id = substr($this->section, -1);
		$plugin_path;
		$sql = "SELECT `install_path` FROM " . TABLE_PREFIX . "plugin WHERE `id` = " . $id . ";";
		$result = db_query($sql);
		//echo $sql;
		while ($row = db_fetch_array($result)) {
			$plugin_path = $row['install_path'];
		}
		return 'include/' . $plugin_path;
	}

	function getschedule() {
		$sync_val = json_decode($this->config['sync_data']
			->ht['value']);
		$time_zone = db_result(db_query("SELECT value FROM `" . TABLE_PREFIX . "config` WHERE `key` = 'default_timezone'"));
		$scheduletime = LdapMultiAuthPlugin::DateFromTimezone(strftime("%Y-%m-%d %H:%M", $sync_val->schedule) , 'UTC', $time_zone, 'F d Y g:i a');
		//echo json_encode($this->config['sync_data']);
		return $scheduletime;
	}

	function checkschedule() {
		$current_schedule = $this->config['sync_schedule']
			->ht['value'];
		$new_schedule = $this->getForm()
			->getField('sync_schedule')
			->getValue();

		if ($current_schedule != $new_schedule) {
			$id = substr($this->section, -1);
			$json_str = db_fetch_row(db_query('SELECT value FROM ' . TABLE_PREFIX . 'config 
						WHERE namespace = "plugin.' . $id . '" AND `key` = "sync_data";')) [0];
			$json_arr = json_decode($json_str);
			$key = 'schedule';
			$json_arr->$key = strtotime($new_schedule);
			//$sql = 'UPDATE `' . TABLE_PREFIX . 'config` SET `value` = \'' . json_encode($json_arr) . '\', updated = CURRENT_TIMESTAMP
			$sql = 'UPDATE `' . TABLE_PREFIX . 'config` SET `value` = \'\', updated = CURRENT_TIMESTAMP
							WHERE `key` = "sync_data" AND `namespace` = "plugin.' . $id . '";';
			return db_query($sql);
		}
		return false;
	}
	//List osticket email accounts
	function FromMail() {
		$frommail = array();
		$sql = 'SELECT email_id,email,name,smtp_active FROM ' . EMAIL_TABLE . ' email ORDER by name';
		if (($res = db_query($sql)) && db_num_rows($res)) {
			while (list($id, $email, $name, $smtp) = db_fetch_row($res)) {
				//$selected=($info['email_id'] && $id==$info['email_id'])?'selected="selected"':'';
				if ($name) $email = Format::htmlchars("$name <$email>");
				if ($smtp) $email .= ' (' . __('SMTP') . ')';
				$frommail[] = $id = $email;
			}
			return $frommail;
		}
	}

	function getOptions() {
		global $ost;
		$from_choices = $this->FromMail();
		list($__, $_N) = self::translate();
		return array(
			'javascript' => new FreeTextField(array(
				'configuration' => array(
					'content' => __('
		<script type="text/javascript">		
            $(function() {
				$("#_sync_data").parent().parent().hide();
            });
			</script>')
				)
			)) ,
			'msad' => new SectionBreakField(array(
				'label' => 'LDAP Information',
				'hint' => $__('Enter all required for LDAP settings /.../ use semicolons to seperate values') ,
			)) ,
			'basedn' => new TextareaField(array(
				'id' => 'base',
				'label' => $__('BaseDN') ,
				'hint' => $__('The base DN (e.g. "dc=foo,dc=com;dc=doo,dc=com;")') ,
				'configuration' => array(
					'html' => false,
					'rows' => 2,
					'cols' => 40
				) ,
				//'validators' => array(
				// function($self, $val) use ($__) {
				//	$domains = explode('|', $val);
				//foreach ($domains as $domain) {
				// if (strpos($domain, ',') === false)
				//   $self->addError(
				//     $__('Fully-qualified domain name is expected'));
				//}
				//}),
				
			)) ,
			'shortdomain' => new TextboxField(array(
				'id' => 'sd',
				'label' => $__('Short Domain') ,
				'configuration' => array(
					'size' => 40,
					'length' => 60
				) ,
				'hint' => $__('Use your netbios domain seperated by "," FOO;DOO') ,
			)) ,
			'servers' => new TextareaField(array(
				'id' => 'servers',
				'label' => $__('LDAP servers') ,
				'configuration' => array(
					'html' => false,
					'rows' => 2,
					'cols' => 40
				) ,
				'hint' => $__('Use "server" or "server:port". Type server seperated by a ";" and carragie return for next entry of LDAP servers') ,
			)) ,
			'tls' => new BooleanField(array(
				'id' => 'tls',
				'label' => $__('Use TLS') ,
				'configuration' => array(
					'desc' => $__('Use TLS to communicate with the LDAP server')
				)
			)) ,
			'conn_info' => new SectionBreakField(array(
				'label' => $__('Useful only for information lookups') ,
				'hint' => $__('NOTE this data is not necessary if your server allows anonymous searches')
			)) ,
			'bind_dn' => new TextareaField(array(
				'label' => $__('Search User') ,
				'hint' => $__('Bind DN (distinguished name) to bind to the LDAP
                    server as in order to perform searches') ,
				'configuration' => array(
					'html' => false,
					'rows' => 2,
					'cols' => 70
				) ,
			)) ,
			'bind_pw' => new TextboxField(array(
				//'widget' => 'PasswordWidget',
				'label' => $__('Password') ,
				'hint' => $__("Password associated with the 'Seach User' account") ,
				'configuration' => array(
					'size' => 40,
					'length' => 60
				) ,
			)) ,
			'search_base' => new TextboxField(array(
				'label' => $__('Search Filter') ,
				'hint' => $__('Filter used when searching for users') ,
				'configuration' => array(
					'size' => 70,
					'length' => 120
				) ,
			)) ,
			'auth' => new SectionBreakField(array(
				'label' => $__('Authentication Modes') ,
				'hint' => $__('Authentication modes for clients and staff
                    members can be enabled independently') ,
			)) ,
			'multiauth-staff' => new BooleanField(array(
				'label' => $__('Staff Authentication') ,
				'default' => true,
				'configuration' => array(
					'desc' => $__('Enable authentication of staff members')
				)
			)) ,
			'multiauth-client' => new BooleanField(array(
				'label' => $__('Client Authentication') ,
				'default' => false,
				'configuration' => array(
					'desc' => $__('Enable authentication of clients')
				)
			)) ,
			'reg-modes' => new SectionBreakField(array(
				'label' => $__('Registration Modes') ,
				'hint' => $__('Registration modes for clients and staff
                    members can be enabled independently') ,
			)) ,
			'multiauth-force-register' => new BooleanField(array(
				'label' => $__('Force client registration') ,
				'default' => true,
				'configuration' => array(
					'desc' => $__('This is useful if you have public registration disabled')
				)
			)) ,
			'multiauth-staff-register' => new BooleanField(array(
				'label' => $__('Enable Staff registration') ,
				'default' => false,
				'configuration' => array(
					'desc' => $__('Register staff member to be registered automatically')
				)
			)) ,
			'multiauth-staff-group' => new TextboxField(array(
				'id' => 'staffldapgroup',
				'label' => $__('Staff Group') ,
				'default' => 'Domain Admins',
				'configuration' => array(
					'size' => 40,
					'length' => 60
				) ,
				'hint' => $__('Staff registration group membership (use semicolons for multiple groups)') ,
			)) ,
			'multiauth-sync' => new SectionBreakField(array(
				'label' => $__('Sync Mode') ,
				'hint' => $__('Various options for syncing users with LDAP') ,
			)) ,
			'sync-users' => new BooleanField(array(
				'label' => $__('Sync Users') ,
				'default' => false,
				'configuration' => array(
					'desc' => $__('Enable user synchronization')
				)
			)) ,
			'sync-agents' => new BooleanField(array(
				'label' => $__('Sync Agents') ,
				'default' => false,
				'configuration' => array(
					'desc' => $__('Enable agent synchronization')
				)
			)) ,
			'sync_schedule' => new TextboxField(array(
				'id' => 'sync_schedule',
				'label' => $__('Sync Schedule') ,
				'hint' => $__('Set the schedule to sync users') ,
				'default' => '1 day 12AM',
				'configuration' => array(
					'size' => 40,
					'length' => 40
				) ,
				'hint' => $__('Set schedule based on string examples: "+10 minutes", "+1 hour", "+1 day", 
				"next Thursday", "+1 week", "weekdays 1AM", "+2 weekends", "+2 days", "4 hours", "10 September 2000"') ,
			)) ,
			'sync_schedule_show' => new SectionBreakField(array(
				'label' => $__('Next schedule: ' . $this->getschedule()) ,
			)) ,
			'sync_reports' => new BooleanField(array(
				'id' => 'sync_reports',
				'label' => $__('Enable Sync Reports') ,
				'default' => false,
				'configuration' => array(
					'hint' => $__('Enable reports to be emailed')
				)
			)) ,
			'sync_mailfrom' => new ChoiceField(array(
				'id' => 'sync_mailfrom',
				'label' => 'From email address',
				'default' => 1,
				'choices' => $from_choices,
				'configuration' => array(
					'hint' => 'This list the internal email accounts choose one that fits best.'
				)
			)) ,
			'sync_mailto' => new TextboxField(array(
				'id' => 'sync_mailto',
				'label' => 'Report address',
				'default' => '',
				'hint' => 'Email address that reports will be sent to',
				'configuration' => array(
					'size' => 30,
					'length' => 30
				) ,
			)) ,
			'sync_attr' => new TextareaField(array(
				'label' => $__('LDAP Attributes') ,
				'hint' => $__('List the LDAP attributes to use" ') ,
				'default' => 'cn,telephonenumber,physicalDeliveryOfficeName',
				'configuration' => array(
					'html' => false,
					'rows' => 2,
					'cols' => 70
				) ,
			)) ,
			'sync_map' => new TextareaField(array(
				'label' => $__('Sync Map') ,
				'hint' => $__('Map contact variables to LDAP attributes example: <br>"<strong>name:cn, phone:telephonenumber</strong>" ') ,
				'default' => 'name:cn, phone:telephonenumber',
				'configuration' => array(
					'html' => false,
					'rows' => 2,
					'cols' => 70
				) ,
			)) ,
			'sync_filter' => new TextboxField(array(
				'label' => $__('LDAP Sync Filter') ,
				'hint' => $__('Custom Filtering for syncing users') ,
				'default' => '(&(sAMAccountType=805306368)(mail=*))',
				'configuration' => array(
					'size' => 70,
					'length' => 150
				) ,
			)) ,

			'sync_btn' => new FreeTextField(array(
				'configuration' => array(
					'content' => __('
		<script type="text/javascript">		
            $(function() {
    		$(".sync").click(function() {
        $.ajax({ //Send the val to php file using Ajax in POST method
            type: "POST",
            data: {data: "' . $this->section . '"},
            url: "' . OST_ROOT . '/scp/sync_mldap.php?ajax=true",
            success: function(data) { // Get the result and assign to each cases
                console.log(data);
                var json = $.parseJSON(data);
            }
        });
    });
});
			</script>
<div class="sync button">sync users <i class="icon-refresh"></i></div>')
					//icon-spin
					
				)
			)) ,
			'sync_data' => new TextboxField(array(
				'id' => 'sync_data',
				'label' => $__('Sync Lastrun') ,
				'configuration' => array(
					'size' => 40,
					'length' => 20,
					'visibility' => false
				) ,
			)) ,
			'sync_full' => new BooleanField(array(
				'label' => $__('Full Sync') ,
				'hint' => $__('This does a full sync on the next scheduled time (this happens only once)') ,
				'configuration' => array(
					'desc' => $__('Check this to do a Full Sync')
				)
			)) ,
			'multiauth-debug' => new SectionBreakField(array(
				'label' => $__('Debug Mode') ,
				'hint' => $__('Turns debugging on or off check the "System Logs" for entires') ,
			)) ,
			'debug-choice' => new BooleanField(array(
				'label' => $__('Debug') ,
				'default' => false,
				'configuration' => array(
					'desc' => $__('Enable debugging')
				)
			)) ,
		);
	}

	function pre_save(&$config, &$errors) {
		require_once ('class.AuthLdap.php');
		list($__, $_N) = self::translate();
		global $ost;
		if ($ost && !extension_loaded('ldap')) {
			$ost->setWarning($__('LDAP extension is not available'));
			$errors['err'] = $__('LDAP extension is not available. Please
                install or enable the `php-ldap` extension on your web
                server');
			return;
		}
		if (!$config['basedn']) {
			if (!($servers = LDAPAuthentication::connectcheck($config['servers']))) $this->getForm()
				->getField('basedn')
				->addError($__("No basedn specified. Example of DN attributes 'dc=foo,dc=com'."));
		}
		if (!$config['shortdomain']) {
			$this->getForm()
				->getField('shortdomain')
				->addError($__("No Domain Netbios names specified."));
		}
		else {
			if (!$config['servers']) $this->getForm()
				->getField('servers')
				->addError($__("No servers specified. Either specify a FQDN
                    or ip address of servers"));
			else {
				$servers = array();
				foreach (preg_split('/\s+/', $config['servers']) as $server) {
					$server = trim($server);
					$servers[] = array(
						$server
					);
				}
			}
		}
		$this->checkschedule();

		$ldapdata = array();
		foreach (preg_split('/;/', $config['basedn']) as $i => $dn) {
			$dn = trim($dn);

			$servers = preg_split('/\s+/', $config['servers']);

			$sd = preg_split('/;|,/', $config['shortdomain']);

			$ldapdata[] = array(
				'dn' => $dn,
				'sd' => $sd[$i],
				'servers' => $servers[$i]
			);
		}

		foreach (LDAPMultiAuthentication::connectcheck($ldapdata) as $i => $connerror) {
			//LDAPAuthentication::console($connerror);
			if (!$connerror['bool']) {
				$this->getForm()
					->getField('servers')
					->addError($connerror['msg']);
				$errors['err'] = $__('Unable to connect any listed LDAP servers');
			}
		}
		global $msg;
		if (!$errors) $msg = $__('LDAP configuration updated successfully');
		return !$errors;
	}
}
?>
