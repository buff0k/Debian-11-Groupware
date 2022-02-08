# Debian 11 Groupware Server
##Dovecot-Postfix-PostfixAdmin-SOGo

Installing a mailserver on a Debian 11 LXC Container with Groupware Features contained in SOGo

Since Google has decided to retire their Google Apps for Business and force migration to thier paid Workspace Product, I found the need to migrate some of my deployed domains to a self-hosted Mail Service. My requirements were for a groupware server which can deal with shared calendars, mailboxes and contacts.

Solutions like iRedMail insisting on charging for realy basic functionality (Alias) and Mailcow's refusal to support deployment outside of Docker and thier maintainers refusal to engage on the issues raised when they implenment changes for no reason other than to break Docker on LXC lead me down a realy deep rabit hole to build my solution from existing standards and open-source software.

The short-term goal is to get the deployment to actually work (Done), medium term to get some advanced features to work (Partially Working) and ultimately develop an installation script to automate the deployment.

I give thanks to the following projects which gave sufficient insight into making all of these disparate products work:

1. The original ISPMail tutorial (I suggest this as a great starting point: https://workaround.org/ispmail) which expertly describes the disparate technologies and how they are brought together in the modern email server.

2. PostfixAdmin Guidle for ISPMail (https://gist.github.com/yajrendrag/203b0172fee96a8b002a026362d27bf2) who got PostfixAdmin to work on earlier Debian versions, however does not describe the deployment steps in sufficient detail for a complete newbie to use.

3. This tutorial on vogan which got me moving in the right direction (I hope) in getting IMAP Mailbox Sharing to work (It's all ACL's and Databases and right now, not yet in a working condition).

So let's get started with this process, again, if you want to understand the steps involved in getting a working Email Server off the ground, read the ISPMail tutorial, this is not about holding your hand, it's about getting your server working.

## WHy Debian 11?

Because it's what I prefer for server deployments, stable and mostly secure, if you want to use any other distro, you can, but I chose Debian and I also chose to use only the official Debian repos becuase, again, stability is the most important aspect in deploying servers.

## Conventions

To make this guide more universal, I am using some conventions which therefore assumes the following, you can replace the conventional terms with those you will be deploying. I am also assuming that you have a static IPv4 address pointing to your server and that your existing DNS records are correctly configured. I am also assuming that you are running all of this as root, while this is not absolutely required, if you choose to run as a non-root user with sudo privileges, add sudo in front of each command.

Domain Name:  example.org

Server Name (fqdn): mail.example.org

MX Record (DNS): mail.example.org

Database name: mailserver

Database read only user: mailserver@127.0.0.1

Database read only password: {userpassword}

Database admin user: mailadmin@localhost

Database admin password: {adminpassword}

In order to get secure passwords, use https://passwordsgenerator.net/ make sure that you exclude special characters, I suggest 30 character length.

## What Are We Installing?

Debian shifts with nano by default (I know VIM could be better, but I prefer the interface and simplicity of nano. We will assume a fresh base install of Debian 11 (Bullseye) and will be installing the following additional packages:

 1. MariaDB (Database Server)
 2. Postfix (Mail Server)
 3. Dovecot (Mailbox Server)
 4. Apache (Webserver, properly apache2)
 5. PHP7.4 (Scripting Language)
 6. RspamD (Spam Filter Server)
 7. Certbot (Obtain LetsEncrypt SSL Certificates)
 8. SOGo (Groupware Software including IMAP client)

A huge thank you to the developers and maintainers of each of the above packages, without whom, none of this would work. Seriously, support these people.

## Ensure that we are starting with an up to date server

```bash
apt update && apt upgrade -y
```

## Install required packages

This installs all the packages we require to get everything to work:

```bash
apt install -y mariadb-server postfix postfix-mysql apache2 php7.4 php7.4-imap php7.4-mbstring php7.4-mysql rspamd redis-server certbot dovecot-mysql dovecot-pop3d dovecot-imapd dovecot-managesieved dovecot-lmtpd sogo ca-certificates
```

## Prepare MariaDB

We need to create the mailserver database, as well as give permissions to both an admin user and a read-only user, note that the admin user is only given rights on localhost, while the read-only user is given rights via 127.0.0.1, this is important.

```bash
mysql
CREATE DATABASE mailserver;
GRANT ALL ON mailserver.* TO 'mailadmin'@'localhost' IDENTIFIED BY '{adminpassword}';
GRANT SELECT ON mailserver.* to 'mailserver'@'127.0.0.1' IDENTIFIED BY '{userpassword}';
FLUSH PRIVILEGES;
quit
```

## Configure Apache Webserver and get a LetsEncrypt SSL Certificate

Eventually I would like to move over to NginX for this, however deploying on Apache is easier at a small perormance cost and will work fine for our purposes until I can figure out the NginX recipe for the same deployment.

 1. Enable required Apache modules
 
 ```bash
 a2enmod ssl rewrite headers proxy proxy_http
 ```

 2. Disable the Apache default site

 ```bash
 a2dissite 000-default.conf
 ```
 
 3. Remove the Apache default site config file

 ```bash
 rm /etc/apache2/sites-available/*.conf
 ```
 
 4. Create the intial config for the new apache2 site (we are using the fqdn of the mailserver, mail.example.org, feel free to use your own fqdn, the only thing that actually matters is that it is in the right folder and ends as .conf

 ```bash
 nano /etc/apache2/sites-available/mail.example.org-http.conf
 ```
 
 Make sure that the contents look like this:
 
 ```bash
 <VirtualHost *:80>
   ServerName mail.example.org
   DocumentRoot /var/www/html
  </VirtualHost>
  ```
  
 5. Enable your newly created site:

 ```bash
 a2ensite mail.example.org
 ```
 
 6. Restart Apache:

 ```bash
 systemctl restart apache2
 ```
 
 7. Get your SSL Certificate from LetsEncrypt:

 ```bash
 certbot certonly --webroot --webroot-path /var/www/html -d owlery.hrcity.co.za --agree-tos --email admin@hrcity.co.za
 ```
 
 8. Give the apache user (www-data) access to the certificates, this is necessary to address a known bug with PostfixAdmin:

 ```bash
 usermod -aG dovecot www-data
 chown www-data:www-data /etc/letsencrypt/live/mail.example.org/privkey.pem 
 chown www-data:www-data /etc/letsencrypt/live 
 chown www-data:www-data /etc/letsencrypt/archive
 ```
 
 9. Configure Certbot to restart the mailserver stach whenever the certificate is updated:

 ```bash
 nano /etc/letsencrypt/cli.ini
 ```
 Add the following to the end of the file:
 
 ```bash
 post-hook = systemctl restart postfix dovecot apache2
 ```
 
 10. Make a autoconfig-mail file (Works with Mozilla Thunderbird), if nothing else, to give you a reference when deploying users. Make a note of the contents of this file as well, these are the IMAP and SMTP client configurations we are going to configure later.

 ```bash
 mkdir /var/www/html/autoconfig-mail
 chown www-data /var/www/html/autoconfig-mail/
 nano /var/www/html/autoconfig-mail/config-v1.1.xml
 ```
 Paste the following contents into the new file:
 
 ```bash
 <?xml version="1.0" encoding="UTF-8"?>

 <clientConfig version="1.1">
   <emailProvider id="My Mail Server">
     <domain>example.org</domain>
     <displayName>My Mail Server</displayName>
     <displayShortName>Mailserver</displayShortName>
     <incomingServer type="imap">
       <hostname>mail.example.org</hostname>
       <port>143</port>
       <socketType>STARTTLS</socketType>
       <authentication>password-cleartext</authentication>
       <username>%EMAILADDRESS%</username>
     </incomingServer>
     <outgoingServer type="smtp">
       <hostname>mail.example.org</hostname>
       <port>587</port>
       <socketType>STARTTLS</socketType>
       <authentication>password-cleartext</authentication>
       <username>%EMAILADDRESS%</username>
     </outgoingServer>
   </emailProvider>
 </clientConfig>
 ```
 
 11. Create the SSL version of your site config file, this is the one which will direct all your website traffic to the appropriate apps.

 ```bash
 nano /etc/apache2/sites-available/mail.example.org-https.conf
 ```
 Paste the following contents there, this will be the site which points to all the apps we will be deploying in this guide.
 ```bash
 <VirtualHost *:443>
  ServerName mail.example.org
	 DocumentRoot /var/www/html
	 <Location /rspamd>
   Require all granted
	 </Location>
	 RewriteEngine On
  RewriteRule ^/rspamd$ /rspamd/ [R,L]
	 RewriteRule ^/rspamd/(.*) http://localhost:11334/$1 [P,L]
	 Alias /.well-known/autoconfig/mail /var/www/html/autoconfig-mail
  Alias /admin /srv/postfixadmin/public
	 <Location /admin>
	  Options FollowSymLinks
   AllowOverride All
	  Require all granted
	 </Location>
	 SSLEngine on
	 SSLCertificateFile /etc/letsencrypt/live/mail.example.org/fullchain.pem
	 SSLCertificateKeyFile /etc/letsencrypt/live/mail.example.org/privkey.pem
	</VirtualHost>
 ```
  
 12. Let's enable this site

 ```bash
 a2ensite mail.example.org-https
 ```
 
 13. And now let's direct all non-SSL traffic to the secured SSL site

 ```bash
 nano /etc/apache2/sites-available/mail.example.org-http.conf
 ```
 And edit the file to include the redirects:
 ```bash
 <VirtualHost *:80>
  ServerName mail.example.org
  DocumentRoot /var/www/html
  RewriteEngine On
  RewriteCond %{REQUEST_URI} !.well-known/acme-challenge
  RewriteRule ^(.*)$ https://%{SERVER_NAME}$1 [R=301,L]
 </VirtualHost>
 ```
 
 14. Finally, reload the Apache configs so that it works

 ```bash
 systemctl reload apache2
 ```
 
## Install PostfixAdmin and let it create the required database tables we will be using
Note, that since we haven't configured Dovevot to use the SSL certificates yet, you CAN NOT CREATE AN ADMIN USER YET!!!

 1. Download the latest version of PostfixAdmin from thier GitHub repo and move it to the right folder, at the time of writing this, it was 3.3.10:
 
 ```bash
 wget -O postfixadmin.tgz https://github.com/postfixadmin/postfixadmin/archive/postfixadmin-3.3.10.tar.gz
 tar -zxvf postfixadmin.tgz
 mv postfixadmin-postfixadmin-3.3.10 /srv/postfixadmin
 ```
 
 2. Create the required templates_c folder and give Apache privileges to write to it:

 ```bash
 mkdir -p /srv/postfixadmin/templates_c
 chown -R www-data /srv/postfixadmin/templates_c
 ```
 
 3. Create the required config.local.php file with the following contents (For Now):

 ```bash
 nano /srv/postfixadmin/config.local.php
 ```
 And Edit it to work with your database configured earlier {adminpassword} is the one you configured in MariaDB:
 ```bash
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
 $CONF['configured'] = true;
 ?>
 ```
 4. Get a Secure Setup Password:
 
 On your webbrowser, connect to https://mail.example.org/admin/setup.php and enter a secure Setup Password, it will show you a configuration string which you need to copy into your .conf file, it will look something like this:
 
 ```bash
 $CONF['setup_password'] = '{Some Secure Password String}';
 ```
 
 5. Configure the Setup Password in your PostfixAdmin config file:

 ```bash
 nano /srv/postfixadmin/config.local.php
 ```
 Paste the string from step 4 above so your file should now look something like this:
 ```bash
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
 ```

 6. Allow PostfixAdmin to deploy your database tables for you:
 
 From your webbrowser, go back to https://mail.example.org/admin/setup.php and login in with the Setup Password you used to create the secure string in step 4. It will tell you that there's a problem with the encryption, that is fine, it will still generate the tables in your database (You will see a string saying that tables were succesfully updated or, are up to date). For now, we are done with this, once we have finished configuring Dovecot, this will work fine.

##Install and configure SOGo Groupware

 1. Create a SQL VIEW to authenticate users:
 
 Firstly, SOGo relies on some specific table columns to authenticate users, since we don't want duplicates and since we want PostfixAdmin to manage users, domains and aliases, we want to make SOGo use this information to authenticate users. To do that, we will create a VIEW, which is like a fake table made up of data from other tables in MariaDB.
 
 ```bash
 mysql
 USE mailserver;
 CREATE VIEW sogo_view AS SELECT username AS c_uid, username AS c_name, password AS c_password, name AS c_cn, username AS mail FROM mailserver.mailbox;
 quit
 ```
 
 2. Configure SOGo:
 
 I suggest going through every line, however key issues will be {adminpassword}, {userpassword} and your timezone (I use Africa/Johannesburg).
 
 ```bash
 nano /etc/sogo/sogo.conf
 ```
 The Debian standard sogo.conf sucks, clear out everything (Don't rm the file unless you know how to fix permissions on a newly created file). Once you are finished, your sogo.conf file will look something like this:
 ```bash
 {
   /* *********************  Main SOGo configuration file  **********************
    *                                                                           *
    * Since the content of this file is a dictionary in OpenStep plist format,  *
    * the curly braces enclosing the body of the configuration are mandatory.   *
    * See the Installation Guide for details on the format.                     *
    *                                                                           *
    * C and C++ style comments are supported.                                   *
    *                                                                           *
    * This example configuration contains only a subset of all available        *
    * configuration parameters. Please see the installation guide more details. *
    *                                                                           *
    * ~sogo/GNUstep/Defaults/.GNUstepDefaults has precedence over this file,    *
    * make sure to move it away to avoid unwanted parameter overrides.          *
    *                                                                           *
    * **************************************************************************/

   /* Database configuration (mysql://, postgresql:// or oracle://) */
   SOGoProfileURL = "mysql://mailadmin:{adminpassword}@localhost:3306/mailserver/sogo_user_profile";
   OCSFolderInfoURL = "mysql://mailadmin:{adminpassword}@localhost:3306/mailserver/sogo_folder_info";
   OCSSessionsFolderURL = "mysql://mailadmin:{adminpassword}@localhost:3306/mailserver/sogo_sessions_folder";
   OCSEMailAlarmsFolderURL = "mysql://mailadmin:{adminpassword}@localhost:3306/mailserver/sogo_emailalarms_folder";

   OCSStoreURL = "mysql://mailadmin:{adminpassword}@localhost:3306/mailserver/sogo_store";
   OCSAclURL = "mysql://mailadmin:{adminpassword}@localhost:3306/mailserver/sogo_acl";
   OCSCacheFolderURL = "mysql://mailadmin:{adminpassword}@localhost:3306/mailserver/sogo_cache";

   /* Mail */
   SOGoSentFolderName = "Sent Items";
   SOGoTrashFolderName = "Deleted Items";
   SOGoDraftsFolderName = Drafts;
   SOGoJunkFolderName = "Junk Email";
   SOGoIMAPServer = "localhost";
   SOGoSieveServer = "sieve://127.0.0.1:4190";
   SOGoSMTPServer = "smtp://mail.example.org:587/?tls=YES";
   SOGoSMTPAuthenticationType = PLAIN;
   //SOGoMailDomain = acme.com;
   SOGoMailingMechanism = smtp;
   SOGoForceExternalLoginWithEmail = YES;
   //SOGoMailSpoolPath = /var/spool/sogo;
   //NGImap4ConnectionStringSeparator = "/";
   //SOGoIMAPAclConformsToIMAPExt = YES;

   /* Notifications */
   //SOGoAppointmentSendEMailNotifications = NO;
   //SOGoACLsSendEMailNotifications = NO;
   //SOGoFoldersSendEMailNotifications = NO;

   /* Authentication */
   //SOGoPasswordChangeEnabled = YES;

   /* LDAP authentication example */
   //SOGoUserSources = (
   //  {
   //    type = ldap;
   //    CNFieldName = cn;
   //    UIDFieldName = uid;
   //    IDFieldName = uid; // first field of the DN for direct binds
   //    bindFields = (uid, mail); // array of fields to use for indirect binds
   //    baseDN = "ou=users,dc=acme,dc=com";
   //    bindDN = "uid=sogo,ou=users,dc=acme,dc=com";
   //    bindPassword = qwerty;
   //    canAuthenticate = YES;
   //    displayName = "Shared Addresses";
   //    hostname = "ldap://127.0.0.1:389";
   //    id = public;
   //    isAddressBook = YES;
   //  }
   //);

   /* LDAP AD/Samba4 example */
   //SOGoUserSources = (
   //  {
   //    type = ldap;
   //    CNFieldName = cn;
   //    UIDFieldName = sAMAccountName;
   //    baseDN = "CN=users,dc=domain,dc=tld";
   //    bindDN = "CN=sogo,CN=users,DC=domain,DC=tld";
   //    bindFields = (sAMAccountName, mail);
   //    bindPassword = password;
   //    canAuthenticate = YES;
   //    displayName = "Public";
   //    hostname = "ldap://127.0.0.1:389";
   //    filter = "mail = '*'";
   //    id = directory;
   //    isAddressBook = YES;
   //  }
   //);


   /* SQL authentication example */
   /*  These database columns MUST be present in the view/table:
    *    c_uid - will be used for authentication -  it's the username or username@domain.tld)
    *    c_name - which can be identical to c_uid -  will be used to uniquely identify entries
    *    c_password - password of the user, plain-text, md5 or sha encoded for now
    *    c_cn - the user's common name - such as "John Doe"
    *    mail - the user's mail address
    *  See the installation guide for more details
    */
   SOGoUserSources =
     (
       {
         type = sql;
         id = directory;
         viewURL = "mysql://mailserver:{userpassword}@127.0.0.1:3306/mailserver/sogo_view";
         canAuthenticate = YES;
         isAddressBook = YES;
         userPasswordAlgorithm = blf-crypt;
       }
     );
 
   /* Web Interface */
   SOGoPageTitle = "My Mail Server";
   SOGoVacationEnabled = YES;
   SOGoForwardEnabled = YES;
   SOGoSieveScriptsEnabled = YES;
   SOGoMailAuxiliaryUserAccountsEnabled = YES;
   // SOGoTrustProxyAuthentication = NO;
   //SOGoXSRFValidationEnabled = NO;
 
   /* General - SOGoTimeZone *MUST* be defined */
   SOGoLanguage = English;
   SOGoTimeZone = Africa/Johannesburg;
   //SOGoCalendarDefaultRoles = (
   //  PublicDAndTViewer,
   //  ConfidentialDAndTViewer
   //);
   //SOGoSuperUsernames = (sogo1, sogo2); // This is an array - keep the parens!
   //SxVMemLimit = 384;
   //WOPidFile = "/var/run/sogo/sogo.pid";
   SOGoMemcachedHost = 127.0.0.1;
   
   /* Debug */
   //SOGoDebugRequests = YES;
   //SoDebugBaseURL = YES;
   //ImapDebugEnabled = YES;
   //LDAPDebugEnabled = YES;
   //PGDebugEnabled = YES;
   //MySQL4DebugEnabled = YES;
   //SOGoUIxDebugEnabled = YES;
   //WODontZipResponse = YES;
   //WOLogFile = /var/log/sogo/sogo.log;
 }
 ```

 3. Restart SOGo
 
 ```bash
 systemctl restart sogo
 ```
 
 4. Create a SOGo cofiguration file in Apache:

 ```bash
 nano /etc/apache2/conf-available/SOGo.conf
 ```
 And Paste the following in there (Note the RedirectMatch line and point to your fqnd, this makes the SOGo app the default app when brosing to https://mail.example.org):
 ```bash
 nano /etc/apache2/conf-available/SOGo.conf

 Alias /SOGo.woa/WebServerResources/ \
       /usr/lib/GNUstep/SOGo/WebServerResources/
 Alias /SOGo/WebServerResources/ \
       /usr/lib/GNUstep/SOGo/WebServerResources/

 <Directory /usr/lib/GNUstep/SOGo/>
     AllowOverride None

     <IfVersion < 2.4>
         Order deny,allow
         Allow from all
     </IfVersion>
     <IfVersion >= 2.4>
         Require all granted
     </IfVersion>

     # Explicitly allow caching of static content to avoid browser specific behavior.
     # A resource's URL MUST change in order to have the client load the new version.
     <IfModule expires_module>
       ExpiresActive On
       ExpiresDefault "access plus 1 year"
     </IfModule>
 </Directory>

 # Don't send the Referer header for cross-origin requests
 Header always set Referrer-Policy "same-origin"

 ## Uncomment the following to enable proxy-side authentication, you will then
 ## need to set the "SOGoTrustProxyAuthentication" SOGo user default to YES and
 ## adjust the "x-webobjects-remote-user" proxy header in the "Proxy" section
 ## below.
 #
 ## For full proxy-side authentication:
 #<Location /SOGo>
 #  AuthType XXX
 #  Require valid-user
 #  SetEnv proxy-nokeepalive 1
 #  Allow from all
 #</Location>
 #
 ## For proxy-side authentication only for CardDAV and GroupDAV from external
 ## clients:
 #<Location /SOGo/dav>
 #  AuthType XXX
 #  Require valid-user
 #  SetEnv proxy-nokeepalive 1
 #  Allow from all
 #</Location>

 ProxyRequests Off
 SetEnv proxy-nokeepalive 1
 ProxyPreserveHost On

 # When using CAS, you should uncomment this and install cas-proxy-validate.py
 # in /usr/lib/cgi-bin to reduce server overloading
 #
 # ProxyPass /SOGo/casProxy http://localhost/cgi-bin/cas-proxy-validate.py
 # <Proxy http://localhost/app/cas-proxy-validate.py>
 #   Order deny,allow
 #   Allow from your-cas-host-addr
 # </Proxy>

 # Redirect / to /SOGo
 RedirectMatch ^/$ https://mail.example.org/SOGo

 # Enable to use Microsoft ActiveSync support
 # Note that you MUST have many sogod workers to use ActiveSync.
 # See the SOGo Installation and Configuration guide for more details.
 #
 #ProxyPass /Microsoft-Server-ActiveSync \
 # http://127.0.0.1:20000/SOGo/Microsoft-Server-ActiveSync \
 # retry=60 connectiontimeout=5 timeout=360

 ProxyPass /SOGo http://127.0.0.1:20000/SOGo retry=0 nocanon

 <Proxy http://127.0.0.1:20000/SOGo>
 ## Adjust the following to your configuration
 ## and make sure to enable the headers module
 <IfModule headers_module>
   RequestHeader set "x-webobjects-server-port" "443"
   SetEnvIf Host (.*) HTTP_HOST=$1
   RequestHeader set "x-webobjects-server-name" "%{HTTP_HOST}e" env=HTTP_HOST
   RequestHeader set "x-webobjects-server-url" "https://%{HTTP_HOST}e" env=HTTP_HOST

 ## When using proxy-side autentication, you need to uncomment and
 ## adjust the following line:
   RequestHeader unset "x-webobjects-remote-user"
 #  RequestHeader set "x-webobjects-remote-user" "%{REMOTE_USER}e" env=REMOTE_USER

   RequestHeader set "x-webobjects-server-protocol" "HTTP/1.0"
 </IfModule>

   AddDefaultCharset UTF-8

   Order allow,deny
   Allow from all
 </Proxy>

 # For Apple autoconfiguration
 <IfModule rewrite_module>
   RewriteEngine On
   RewriteRule ^/.well-known/caldav/?$ /SOGo/dav [R=301]
   RewriteRule ^/.well-known/carddav/?$ /SOGo/dav [R=301]
 </IfModule>
 ```
 
 5. Enable the SOGo Apache configuration:

 ```bash
 a2enconf SOGo.conf
 ```
 
 6. Reload Apache Configuration

 ```bash
 systemctl reload apache2
 ```
 
##Configure Postfix


 1. Configure Postfix to Map mailboxes to the Database

 Earlier we configured a database in MariaDB and then populated that database with tables with PostfixAdmin. We need Postfix to actually use the data in those databases and therefore, we need to tell it where the database is as well as which fields it needs to get from each table.
 
  a. Virtual Domains
  
  A Virtual Domain is simply the Domains for which your mailserver accepts email (example.org or as many other as you want to add), we need to tell Postfix which domains it is allowed to accept mail for so we need to create a MAP file:
  
  ```bash
  nano /etc/postfix/mysql-virtual-mailbox-domains.cf
  ```
  And configure it as follows (Again, use your {userpassword}):
  ```bash
  user = mailserver
  password = {userpassword}
  hosts = 127.0.0.1
  dbname = mailserver
  query = SELECT 1 FROM domain WHERE domain='%s'
  ```
  Now tell Postfix that this is the MAP file:
  ```bash
  postconf virtual_mailbox_domains=mysql:/etc/postfix/mysql-virtual-mailbox-domains.cf
  ```
  
  b. Virtual Mailboxes
  
  A Virtual Mailbox is basically the mail user' (user@example.org) specific mailbox, so again, like in step a above, we need to tell Postfix how to verify the mailboxes:
  
  ```bash
  nano /etc/postfix/mysql-virtual-mailbox-maps.cf
  ```
  Configure this file as follows:
  ```bash
  user = mailserver
  password = {userpassword}
  hosts = 127.0.0.1
  dbname = mailserver
  query = SELECT 1 FROM mailbox WHERE username='%s'
  ```
  And tell Postfix to use this MAP:
  ```bash
  postconf virtual_mailbox_maps=mysql:/etc/postfix/mysql-virtual-mailbox-maps.cf
  ```
  
  c. Virtual Aliases
  
  A Virtual Alias is where we configure an alias (or an internal mailing list) so that an emali sent to alias@example.org will be received by any users configured to that alias. You can create an alias in PostfixAdmin.
  
  ```bash
  nano /etc/postfix/mysql-virtual-alias-maps.cf
  ```
  Configure this file as follows:
  ```bash
  user = mailserver
  password = {userpassword}
  hosts = 127.0.0.1
  dbname = mailserver
  query = SELECT goto FROM alias WHERE address='%s'
  ```
  And tell Postfix to use this MAP:
  ```bash
  postconf virtual_alias_maps=mysql:/etc/postfix/mysql-virtual-alias-maps.cf
  ```
  
  d. Email2Email
  
  This will allow a logged-in user to be able to send email as another user on the domain (Mailbox Delegation).
  
  ```bash
  nano /etc/postfix/mysql-email2email.cf
  ```
  Configure the file as follows:
  ```bash
  user = mailserver
  password = {userpassword}
  hosts = 127.0.0.1
  dbname = mailserver
  query = SELECT username FROM mailbox WHERE username='%s'
  ```
  And tell Postfix to use this MAP:
  ```bash
  postconf virtual_alias_maps=mysql:/etc/postfix/mysql-virtual-alias-maps.cf,mysql:/etc/postfix/mysql-email2email.cf
  ```
  
  e. Set Permissions for these files
  
  We need Postfix to have access to these files in order to use them, so we change permissions as follows:
  
  ```bash
  chgrp postfix /etc/postfix/mysql-*.cf
  chmod u=rw,g=r,o= /etc/postfix/mysql-*.cf
  ```

##Configure Dovecot

Dovecot does all the important mail handling, moving emails to the appropriate users folder and we need to make some changes so that we don't use systemmail (Where each user must be a specific PAM user) but rather use vmail.

 1. Create vmail user and group
 
 We want to use UID 5000 for this, so make sure that it is not used (On a clean install this should not be an issue)
 
 ```bash
 groupadd -g 5000 vmail
 useradd -g vmail -u 5000 vmail -d /var/vmail -m
 chown -R vmail:vmail /var/vmail
 ```
 
 2. Configure Dovecot
 
 Dovecot has several .conf files stored in /etc/dovecot/conf.d/ and we will be modifying some of these to enable the plugins we will be using in our deployment.
 
 a. User Authentication:
 
  ```bash
  nano /etc/dovecot/conf.d/10-auth.conf
  ```
  Find and edit the auth_mechanisms line:
  ```bash
  auth_mechanisms = plain login
  ```
  And at the bottom, comment out the !include auth-system.conf.ext line and uncomment the !include auth-sql.conf.ext lines:
  ```bash
  #!include auth-system.conf.ext
  !include auth-sql.conf.ext
  #!include auth-ldap.conf.ext
  #!include auth-passwdfile.conf.ext
  #!include auth-checkpassword.conf.ext
  #!include auth-static.conf.ext
  ```
 
 b. Mail Directory Format
 
  ```bash
  mail_location = maildir:~/Maildir
  ```
  Uncomment and edit the mail_location line:
  ```bash
  mail_location = maildir:~/Maildir
  ```
  Also Uncomment and edit the mail_plugins line:
  ```bash
  mail_plugins = quota
  ```

 c. Master Config File
 
  ```bash
  nano /etc/dovecot/conf.d/10-master.conf
  ```
  Here we need to edit the service lmtp and service auth sections as follows:
  ```bash
  service lmtp {
    unix_listener /var/spool/postfix/private/dovecot-lmtp {
      group = postfix
      mode = 0600
      user = postfix
    }
  }

  service auth { section
    unix_listener /var/spool/postfix/private/auth {
      mode = 0660
      user = postfix
      group = postfix
    }
  ```
  And add the following at the end of the file (This is for PostfixAdmin):
  ```bash
  service stats {
    unix_listener stats-reader {
      user = www-data
      group = www-data
      mode = 0660
  }
    unix_listener stats-writer {
      user = www-data
      group = www-data
      mode = 0660
    }
  }
  ```
  
  d. SSL Configuration
  
  ```bash
  nano /etc/dovecot/conf.d/10-ssl.conf
  ```
  Adit the following three lines to match our configuration:
  ```
  ssl = required

  ssl_cert = </etc/letsencrypt/live/mail.example.org/fullchain.pem
  ssl_key = </etc/letsencrypt/live/mail.example.org/privkey.pem
  ```
  
  e. SQL Connectoin
  
  We need to tell Dovecot to authenticate against our MariaDB database:
  
  ```bash
  nano /etc/dovecot/dovecot-sql.conf.ext
  ```
  Add the following to the end of the file (Again, note the {userpassword}):
  ```bash
  driver = mysql
   connect = \
   host=127.0.0.1 \
   dbname=mailserver \
   user=mailserver \
   password={userpassword}
  user_query = SELECT username as user, \
  concat('*:bytes=', quota) AS quota_rule, \
  '/var/vmail/%d/%n' AS home, \
  5000 AS uid, 5000 AS gid \
  FROM mailbox WHERE username='%u'
  password_query = SELECT password FROM mailbox WHERE username='%u'
  iterate_query = SELECT username AS user FROM mailbox
  ```
  Since we don't want this password to leak to non-root users:
  ```bash
  chown root:root /etc/dovecot/dovecot-sql.conf.ext
  chmod go= /etc/dovecot/dovecot-sql.conf.ext
  ```
  
  f. Map Virtual Mail Folders for Outlook (And SOGo) Compatibility:
  
  If you loog at our sogo.conf file from earlier, you will note the fields SOGoSentFolderName, SOGoTrashFolderName and SOGoJunkFolderName are mapped to Microsoft Outlook style names, well, we should tell Dovecot to "Alias" these folders to IMAP standard folders:
  
  ```bash
  nano /etc/dovecot/conf.d/15-mailboxes.conf
  ```
  You can add these lines below the mailbox "Sent Mail" mapping:
  ```bash
  mailbox "Sent Items" {
      special_use = \Sent
    }

  mailbox "Junk Email" {
      special_use = \Junk
    }

  mailbox "Deleted Items" {
      special_use = \Trash
    }
  ```
  
  g. Configure lmtp
  
  ```bash
  postconf virtual_transport=lmtp:unix:private/dovecot-lmtp
  ```
  
##Configure RSPAMD

##Configure DKIM

##Enable Mailbox Sharing (Not Yet Working)
