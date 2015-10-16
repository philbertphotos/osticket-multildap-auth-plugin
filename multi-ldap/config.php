<?php
require_once(INCLUDE_DIR.'class.plugin.php');
require_once(INCLUDE_DIR.'class.forms.php');
class LdapMultiAuthPluginConfig extends PluginConfig {

    function translate() {
        if (!method_exists('Plugin', 'translate')) {
            return array(
                function($x) { return $x; },
                function($x, $y, $n) { return $n != 1 ? $y : $x; },
            );
        }
        return Plugin::translate('multiauth');
    }
	
    function getOptions() {
        list($__, $_N) = self::translate();
        return array(
            'msad' => new SectionBreakField(array(
                'label' => 'LDAP Information',
                'hint' => $__('Enter all required for LDAP settings'),
            )),
            'basedn' => new TextareaField(array(
                'label' => $__('BaseDN'),
                'hint' => $__('The base DN (e.g. "dc=foo,dc=com")'),
                'configuration' => array('html'=>false, 'rows'=>2, 'cols'=>40),
                //'validators' => array(
               // function($self, $val) use ($__) {
				//	$domains = explode('|', $val);
					//foreach ($domains as $domain) {
                   // if (strpos($domain, ',') === false)
                     //   $self->addError(
                       //     $__('Fully-qualified domain name is expected'));
					//}
                //}),
            )),
			'shortdomain' => new TextboxField(array(
                'id' => 'sd',
                'label' => $__('Short Domain'),
                'configuration' => array('size'=>40,'length'=>60),
                'hint' => $__('Use your netbios domain seperated by "," FOO,DOO'),
            )),
            'servers' => new TextareaField(array(
                'id' => 'servers',
                'label' => $__('LDAP servers'),
                'configuration' => array('html'=>false, 'rows'=>2, 'cols'=>40),
                'hint' => $__('Use "server" or "server:port". Place one server entry per line'),
            )),
            'tls' => new BooleanField(array(
                'id' => 'tls',
                'label' => $__('Use TLS'),
                'configuration' => array(
                    'desc' => $__('Use TLS to communicate with the LDAP server'))
            )),
            'conn_info' => new SectionBreakField(array(
                'label' => $__('Useful only for information lookups'),
                'hint' => $__('NOTE this data is not necessary if your server allows anonymous searches')
            )),
            'bind_dn' => new TextareaField(array(
                'label' => $__('Search User'),
                'hint' => $__('Bind DN (distinguished name) to bind to the LDAP
                    server as in order to perform searches'),
                'configuration' => array('html'=>false, 'rows'=>2, 'cols'=>70),
            )),
            'bind_pw' => new TextboxField(array(
                //'widget' => 'PasswordWidget',
                'label' => $__('Password'),
                'hint' => $__("Password associated with the 'Seach User' account"),
                'configuration' => array('size'=>40,'length'=>60),
            )),
            'search_base' => new TextboxField(array(
                'label' => $__('Search Filter'),
                'hint' => $__('Filter used when searching for users'),
                'configuration' => array('size'=>70, 'length'=>120),
            )),
            'auth' => new SectionBreakField(array(
                'label' => $__('Authentication Modes'),
                'hint' => $__('Authentication modes for clients and staff
                    members can be enabled independently'),
            )),
            'multiauth-staff' => new BooleanField(array(
                'label' => $__('Staff Authentication'),
                'default' => true,
                'configuration' => array(
                    'desc' => $__('Enable authentication of staff members')
                )
            )),
            'multiauth-client' => new BooleanField(array(
                'label' => $__('Client Authentication'),
                'default' => false,
                'configuration' => array(
                    'desc' => $__('Enable authentication of clients')
                )
            )),
            'multiauth-force-register' => new BooleanField(array(
                'label' => $__('Force client registration'),
                'default' => true,
                'configuration' => array(
                    'desc' => $__('This is useful if you have public registration disabled')
                )
            )),
            'multiauth-debug' => new SectionBreakField(array(
                'label' => $__('Debug Mode'),
                'hint' => $__('Turns debugging on or off check the "System Logs" for entires'),
            )),
            'debug-choice' => new BooleanField(array(
                'label' => $__('Debug'),
                'default' => false,
                'configuration' => array(
                    'desc' => $__('Enable debuging')
                )
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        require_once('class.AuthLdap.php');
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
            if (!($servers = LDAPAuthentication::connectcheck($config['servers'])))
                $this->getForm()->getField('basedn')->addError(
                    $__("No basedn specified. Example of DN attributes 'dc=foo,dc=com'."));
        }
		if (!$config['shortdomain']) {
            $this->getForm()->getField('shortdomain')->addError(
                    $__("No Domain Netbios names specified."));
        }
        else {
            if (!$config['servers'])
                $this->getForm()->getField('servers')->addError(
                    $__("No servers specified. Either specify a FQDN
                    or ip address of servers"));
            else {
                $servers = array();
                foreach (preg_split('/\s+/', $config['servers']) as $server) {
					$server = trim($server);
                    $servers[] = array($server);
				}
            }
        }
		
		$ldapdata = array();
                 foreach (preg_split('/\n/', $config['basedn']) as $i => $dn) {
					$dn = trim($dn);                   
					
					$servers = preg_split('/\s+/', $config['servers']);
				
					$sd = preg_split('/;|,/', $config['shortdomain']);
					
					 $ldapdata[] = array('dn' => $dn,'sd' => $sd[$i],'servers' => $servers[$i]);
				}
				
		$connection_error = LDAPAuthentication::connectcheck($ldapdata);

foreach ($connection_error as $i => $connerror) {
	//LDAPAuthentication::console($connerror);
        if (!$connerror['bool']) {
            $this->getForm()->getField('servers')->addError($connerror['msg']);
            $errors['err'] = $__('Unable to connect any listed LDAP servers');
        }
		}
        global $msg;
        if (!$errors)
            $msg = $__('LDAP configuration updated successfully');
        return !$errors;
    }
}
?>