<?php
return array(
    'id' =>             'multiauth:ldap',
    'version' =>        '1.8',
	'ost_version' =>    '1.16', # Require osTicket v1.17+
    'name' =>           'Multi LDAP Authentication and Lookup',
    'author' =>         'Joseph Philbert',
    'description' =>    'Provides a configurable authentication backend which works against multiple LDAP servers',
    'url' =>            'http://www.vide.vi',
    'plugin' =>         'auth.php:LdapMultiAuthPlugin'
);
?>
