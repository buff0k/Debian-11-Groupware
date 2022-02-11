<?php
$CONF['database_type'] = 'mysqli';
$CONF['database_host'] = 'localhost';
$CONF['database_user'] = 'mailadmin';
$CONF['database_password'] = '{adminpassword}';
$CONF['database_name'] = 'mailserver';
$CONF['encrypt'] = 'dovecot:BLF-CRYPT';
$CONF['quota'] = 'YES';
$CONF['quota_multiplier'] = '1024000';
$CONF['new_quota_table'] = 'YES';
$CONF['default_aliases'] = array (
'abuse' => 'abuse@example.org',
'hostmaster' => 'hostmaster@example.org',
'postmaster' => 'postmaster@example.org',
'webmaster' => 'webmaster@example.org');
$CONF['footer_text'] = 'Return to mail.example.org';
$CONF['footer_link'] = 'https://mail.example.org';
$CONF['domain_path'] = 'NO';
$CONF['domain_in_mailbox'] = 'YES';
$CONF['setup_password'] = '{Some Secure Password String}';
$CONF['configured'] = true;
?>
