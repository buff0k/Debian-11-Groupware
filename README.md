# Debian 11 Groupware Server
## Dovecot Postfix PostfixAdmin SOGo Rspamd

Installing a mailserver on a Debian 11 LXC Container with Groupware Features contained in SOGo

Since Google has decided to retire their Google Apps for Business and force migration to thier paid Workspace Product, I found the need to migrate some of my deployed domains to a self-hosted Mail Service. My requirements were for a groupware server which can deal with shared calendars, mailboxes and contacts.

Solutions like iRedMail insisting on charging for realy basic functionality (Alias) and Mailcow's refusal to support deployment outside of Docker and thier maintainers refusal to engage on the issues raised when they implenment changes for no reason other than to break Docker on LXC lead me down a realy deep rabit hole to build my solution from existing standards and open-source software.

The short-term goal is to get the deployment to actually work (Done), medium term to get some advanced features to work (Partially Working) and ultimately develop an installation script to automate the deployment.

I give thanks, attribution and recognition to the following projects which gave sufficient insight into making all of these disparate products work:

1. The original [ISPMail tutorial](https://workaround.org/ispmail) (I suggest this as a great starting point) which expertly describes the disparate technologies and how they are brought together in the modern email server.

2. [PostfixAdmin Guidle for ISPMail](https://gist.github.com/yajrendrag/203b0172fee96a8b002a026362d27bf2) to get PostfixAdmin to work on earlier Debian versions, however does not describe the deployment steps in sufficient detail for a complete newbie to use on a fresh Debian 11 system.

3. [This tutorial](https://vogasec.wordpress.com/2012/07/01/ubuntu-postfix-dovecot-shared-mailboxes/) on vogan which got me moving in the right direction (I hope) in getting IMAP Mailbox Sharing to work (It's all ACL's and Databases and right now, not yet in a working state).

4. The [SOGo Installation Guide](https://www.sogo.nu/files/docs/SOGoInstallationGuide.html) which, while not very well written, does get you there in the end.

So let's get started with this process, again, if you want to understand the steps involved in getting a working Email Server off the ground, read the ISPMail tutorial, this is not about holding your hand, it's about getting your server working.

## Why Debian 11?

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

In order to get secure passwords, use https://passwordsgenerator.net/ make sure that you exclude special characters when generating the passwords to avoid problems in .config files, I suggest 30 character length.

## What Are We Installing?

Debian shifts with nano by default (I know VIM could be better, but I prefer the interface and simplicity of nano). We will assume a fresh base install of Debian 11 (Bullseye) and will be installing the following additional packages (And their dependencies):

 1. [Debian](https://www.debian.org/) 11 (GNU/Linux OS)
 2. [MariaDB](https://mariadb.org/) (Database Server)
 3. [Postfix](https://www.postfix.org/) (Mail Server)
 4. [Dovecot](https://www.dovecot.org/) (Mailbox Server)
 5. [Apache](https://httpd.apache.org/) (Webserver, properly apache2)
 6. [PHP](https://www.php.net/) (Scripting Language)
 7. [Redis](https://redis.io/) (Caching Server)
 8. [Rspamd](https://rspamd.com/) (Spam Filter Server)
 9. [Certbot](https://certbot.eff.org/) (LetsEncrypt SSL Certificate Provider)
 10. [SOGo](https://www.sogo.nu/) (Groupware Software including IMAP client)

A huge thank you to the developers and maintainers of each of the above packages, without whom, none of this would work. Seriously, support these people.

## Ensure that we are starting with an up to date server

```bash
apt update && apt upgrade -y
```

## Install required packages

This installs all the packages we require to get everything to work:

```bash
apt install -y mariadb-server postfix postfix-mysql apache2 php php-imap php-mbstring php-mysql rspamd redis-server certbot dovecot-mysql dovecot-pop3d dovecot-imapd dovecot-managesieved dovecot-lmtpd sogo ca-certificates
```

## Prepare MariaDB

We need to create the mailserver database, as well as give permissions to both an admin user and a read-only user, note that the admin user is only given rights on localhost, while the read-only user is given rights via 127.0.0.1, this is important.

```bash
mysql
```
```bash
CREATE DATABASE mailserver;
```
```bash
GRANT ALL ON mailserver.* TO 'mailadmin'@'localhost' IDENTIFIED BY '{adminpassword}';
```
```bash
GRANT SELECT ON mailserver.* to 'mailserver'@'127.0.0.1' IDENTIFIED BY '{userpassword}';
```
```bash
FLUSH PRIVILEGES;
```
```bash
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
 certbot certonly --webroot --webroot-path /var/www/html -d mail.example.org --agree-tos --email admin@example.org
 ```
 
 8. Give the apache user (www-data) access to the certificates, this is necessary to address a known bug with PostfixAdmin:

 ```bash
 usermod -aG dovecot www-data
 ```
 ```bash
 chown www-data:www-data /etc/letsencrypt/live/mail.example.org/privkey.pem
 ```
 ```bash
 chown www-data:www-data /etc/letsencrypt/live
 ```
 ```bash
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
 ```
 ```bash
 chown www-data /var/www/html/autoconfig-mail/
 ```
 ```bash
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
 ```
 ```bash
 tar -zxvf postfixadmin.tgz
 ```
 ```bash
 mv postfixadmin-postfixadmin-3.3.10 /srv/postfixadmin
 ```
 
 2. Create the required templates_c folder and give Apache privileges to write to it:

 ```bash
 mkdir -p /srv/postfixadmin/templates_c
 ```
 ```bash
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
 ```
 ```bash
 USE mailserver;
 ```
 ```bash
 CREATE VIEW sogo_view AS SELECT username AS c_uid, username AS c_name, password AS c_password, name AS c_cn, username AS mail FROM mailserver.mailbox;
 ```
 ```bash
 quit
 ```
 
 2. Configure SOGo:
 
 I suggest going through every line, however key issues will be {adminpassword}, {userpassword} and your timezone (I use Africa/Johannesburg).
 
 ```bash
 nano /etc/sogo/sogo.conf
 ```

 The Debian standard sogo.conf (For clarity on each of the configurable fields, check out the [SOGo Installation Guide](https://www.sogo.nu/files/docs/SOGoInstallationGuide.html)) (sucks, clear out everything (Don't rm the file unless you know how to fix permissions on a newly created file). Once you are finished, your sogo.conf file will look something like this:
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
   //SOGoMailAuxiliaryUserAccountsEnabled = NO;
   
   /* Calendar Settings */
   //SOGoCalendarDefaultRoles = (
   //     PublicModifier,
   //     ConfidentialDAndTViewer,
   //     PrivateDandTViewer,
   //     ObjectCreator
   // );
   //SOGoDayStartTime = 8;
   //SOGoDayEndTime = 17;
   //SOGoFirstDayOfWeek = 1;
   //SOGoCalendarEventsDefaultClassification = PUBLIC;
   //SOGoCalendarTasksDefaultClassification = PUBLIC;
   

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
 a2enconf SOGo
 ```
 
 6. Reload Apache Configuration

 ```bash
 systemctl reload apache2
 ```
 
## Configure Postfix

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
  ```
  ```bash
  chmod u=rw,g=r,o= /etc/postfix/mysql-*.cf
  ```

## Configure Dovecot

Dovecot does all the important mail handling, moving emails to the appropriate users folder and we need to make some changes so that we don't use systemmail (Where each user must be a specific PAM user) but rather use vmail.

 1. Create vmail user and group
 
 We want to use UID 5000 for this, so make sure that it is not used (On a clean install this should not be an issue)
 
 ```bash
 groupadd -g 5000 vmail
 ```
 ```bash
 useradd -g vmail -u 5000 vmail -d /var/vmail -m
 ```
 ```bash
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
  nano /etc/dovecot/conf.d/10-mail.conf
  ```
  Uncomment and edit the mail_location line:
  ```bash
  mail_location = maildir:~/Maildir
  ```
  Modify the namespace inbox { line section to resemble this:
  ```bash
  namespace {
    type = private
    separator = /
    prefix =
    location =
    inbox = yes
  }
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
  ```
  ```bash
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
  Edit the lmtp.conf file
  ```bash
  nano /etc/dovecot/conf.d/20-lmtp.conf
  ```
  In the protocol lmtp { section, edit the mail_plugins line as follows:
  ```bash
  mail_plugins = $mail_plugins sieve
  ```
  
  h. Configure Quotas
  
  We need to configure Quotas to work, which is used to set storage limits per user in PostfixAdmin:
  
  ```bash
  nano /etc/dovecot/conf.d/90-quota.conf
  ```
  Edit any plugin { section to look like this:
  ```bash
  plugin {
    quota = maildir:User quota
    quota_status_success = DUNNO
    quota_status_nouser = DUNNO
    quota_status_overquota = "452 4.2.2 Mailbox is full and cannot receive any more emails"
  }
  ```
  Add the following new sections:
  ```bash
  service quota-status {
    executable = /usr/lib/dovecot/quota-status -p postfix
    unix_listener /var/spool/postfix/private/quota-status {
      user = postfix
    }
  }

  plugin {
   quota_warning = storage=95%% quota-warning 95 %u
   quota_warning2 = storage=80%% quota-warning 80 %u
  }
  service quota-warning {
     executable = script /usr/local/bin/quota-warning.sh
     unix_listener quota-warning {
       user = vmail
       group = vmail
       mode = 0660
     }
  }
  ```
  Create a shell script to set the email which will be sent to your users when they approach or reach thier quota:
  ```bash
  nano /usr/local/bin/quota-warning.sh
  ```
  And make it look like this (Note tha From: email line, change this to your postmaster address):
  ```bash
  #!/bin/sh
  PERCENT=$1
  USER=$2
  cat << EOF | /usr/lib/dovecot/dovecot-lda -d $USER -o "plugin/quota=maildir:User quota:noenforcing"
  From: postmaster@example.org
  Subject: Quota warning - $PERCENT% reached

  Your mailbox can only store a limited amount of emails.
  Currently it is $PERCENT% full. If you reach 100% then
  new emails cannot be stored. Thanks for your understanding.
  EOF
  ```
  Make this file executable:
  ```bash
  chmod +x /usr/local/bin/quota-warning.sh
  ```
  
  i. Restart Dovecot
  
  ```bash
  systemctl restart dovecot
  ```

## Finalize Postfix Configuration

Now that we have Dovecot configured, we can finalize our Postfix configuration.

 1. Enable some Postfix configurations:
 
 ```bash
 postconf smtpd_sasl_type=dovecot
 ```
 ```bash
 postconf smtpd_sasl_path=private/auth
 ```
 ```bash
 postconf smtpd_sasl_auth_enable=yes
 ```
 ```bash
 postconf smtpd_tls_security_level=may
 ```
 ```bash
 postconf smtpd_tls_auth_only=yes
 ```
 ```bash
 postconf smtpd_tls_cert_file=/etc/letsencrypt/live/mail.example.org/fullchain.pem
 ```
 ```bash
 postconf smtpd_tls_key_file=/etc/letsencrypt/live/mail.example.org/privkey.pem
 ```
 ```bash
 postconf smtp_tls_security_level=may
 ```
 
 2. Make some changes to the Postfix master.cf file:

 ```bash
 nano /etc/postfix/master.cf
 ```
 Uncomment the submission inet line and uncomment the listed options, note, the indentation is important, only delete the preceding # and not any spaces:
 ```bash
 submission inet n       -       y       -       -       smtpd
  -o syslog_name=postfix/submission
  -o smtpd_tls_security_level=encrypt
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_tls_auth_only=yes
  -o smtpd_reject_unlisted_recipient=no
  -o smtpd_recipient_restrictions=
  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject
  -o milter_macro_daemon_name=ORIGINATING
 ```
 Enable our configurations:
 ```bash
 postconf smtpd_sender_login_maps=mysql:/etc/postfix/mysql-email2email.cf
 ```
 ```bash
 postconf inet_interfaces=all
 ```
 ```bash
 postconf 'smtpd_recipient_restrictions = reject_unauth_destination check_policy_service unix:private/quota-status'
 ```
 ```bash
 postconf smtpd_milters=inet:127.0.0.1:11332
 ```
 ```bash
 postconf non_smtpd_milters=inet:127.0.0.1:11332
 ```
 ```bash
 postconf milter_mail_macros="i {mail_addr} {client_addr} {client_name} {auth_authen}"
 ```
 
 3. Restart Postfix

 ```bash
 systemctl restart postfix
 ```
 
## Configure Rspamd

Rspamd is a very good Spam Filter, we need to make some configurations to allow Rspamd to work with Dovecot and our Sieve Plugin.

 1. Set "Sensitivity" for Rspamd to be quite permissive
 
 Create a new actions.conf file:
 ```bash
 nano /etc/rspamd/local.d/actions.conf
 ```
 Make the settings:
 ```bash
 reject = 150;
 add_header = 6;
 greylist = 4;
 ```
 
 2. Enable Extended Headers

 This will allow RSAPMD to add fields to the email headers which will tell recipients that the email is trusted (Subject to DKIM being configured).
 
 Create a milter_headers.conf file:
  ```bash
 nano /etc/rspamd/override.d/milter_headers.conf
 ```
 Enter the following string:
 ```bash
 extended_spam_headers = true;
 ```
 
 3. Configure Dovecot's Sieve plugin

 We want Dovecot to filter messages flagged as Spam to the user's Junk folder, so we need to configure this:
 
  a. Enable the use of a sieve-after folder:
  
  ```bash
  nano /etc/dovecot/conf.d/90-sieve.conf
  ```
  Uncomment and Edit the sieve_after string:
  ```bash
  sieve_after = /etc/dovecot/sieve-after
  ```
  
  b. Create the sieve-after folder as configured in step a. above, and create a spam-to-folder sieve instruction:
  
  ```bash
  mkdir /etc/dovecot/sieve-after
  ```
  Create spam-to-folder.sieve file:
  ```bash
  nano /etc/dovecot/sieve-after/spam-to-folder.sieve
  ```
  And add the following string:
  ```bash
  require ["fileinto"];
  if header :contains "X-Spam" "Yes" {
   fileinto "Junk";
   stop;
  }
  ```
  Now convert this human-readable file to a sieve instruction:
  ```bash
  sievec /etc/dovecot/sieve-after/spam-to-folder.sieve
  ```

 4. Configure Rspamd Redis server:
 
 Create a file so Rspamd knows where to find Redis:
 
 ```bash
 nano /etc/rspamd/override.d/redis.conf
 ```
 And enter the following string:
 ```bash
 servers = "127.0.0.1";
 ```
 Restart Rspamd:
 ```bash
 systemctl restart rspamd
 ```
 
 5. Configure Rspamd to learn
 
 Rspamd can learn to identify spam by monitoring user actions (When users move an email to their Junk folder, it will be marked as ham, and if this action is repeated by other users, it will be marked as Spam).
 
  a. Enable learning:
  
  ```bash
  nano /etc/rspamd/override.d/classifier-bayes.conf
  ```
  Paste this line to the file:
  ```bash
  autolearn = true;
  ```
  
  b. Configure the Dovecot Sieve plugin for IMAP folders:
  
  ```bash
  nano /etc/dovecot/conf.d/20-imap.conf
  ```
  Uncomment and edit the mail_plugins line:
  ```bash
  mail_plugins = $mail_plugins quota imap_sieve
  ```
  
  c. Configure the Dovecot Sieve plugin:
  
  ```bash
  nano /etc/dovecot/conf.d/90-sieve.conf
  ```
  Add the following lines in the plugin { section:
  ```bash
  # From elsewhere to Junk folder
  imapsieve_mailbox1_name = Junk
  imapsieve_mailbox1_causes = COPY
  imapsieve_mailbox1_before = file:/etc/dovecot/sieve/learn-spam.sieve

  # From Junk folder to elsewhere
  imapsieve_mailbox2_name = *
  imapsieve_mailbox2_from = Junk
  imapsieve_mailbox2_causes = COPY
  imapsieve_mailbox2_before = file:/etc/dovecot/sieve/learn-ham.sieve

  sieve_pipe_bin_dir = /etc/dovecot/sieve
  sieve_global_extensions = +vnd.dovecot.pipe
  sieve_plugins = sieve_imapsieve sieve_extprograms
  ```
  
  d. Create the Dovecot sieve folder:
  
  ```bash
  mkdir /etc/dovecot/sieve
  ```
  
  e. Create Sieve files
  
  Create a learn-spam file:
  ```bash
  nano /etc/dovecot/sieve/learn-spam.sieve
  ```
  Enter the following strings:
  ```bash
  require ["vnd.dovecot.pipe", "copy", "imapsieve"];
  pipe :copy "rspamd-learn-spam.sh";
  ```
  
  Create a learn-ham file:
  ```bash
  nano /etc/dovecot/sieve/learn-ham.sieve
  ```
  Enter the following strings:
  ```bash
  require ["vnd.dovecot.pipe", "copy", "imapsieve", "variables"];
  if string "${mailbox}" "Trash" {
    stop;
  }
  pipe :copy "rspamd-learn-ham.sh";
  ```
  
  f. Restart Dovecot
  
  ```bash
  systemctl restart dovecot
  ```
  
  g. Convert sieve files
  
  ```bash
  sievec /etc/dovecot/sieve/learn-spam.sieve
  ```
  ```bash
  sievec /etc/dovecot/sieve/learn-ham.sieve
  ```
  ```bash
  chmod u=rw,go= /etc/dovecot/sieve/learn-{spam,ham}.{sieve,svbin}
  ```
  ```bash
  chown vmail.vmail /etc/dovecot/sieve/learn-{spam,ham}.{sieve,svbin}
  ```
  
  h. Create Learning Scripts for Rspamd
  
  Create learn-spam.sh
  ```bash
  nano /etc/dovecot/sieve/rspamd-learn-spam.sh
  ```
  Enter follwowing script:
  ```bash
  #!/bin/sh
  exec /usr/bin/rspamc learn_spam
  ```
  Create learn-ham.sh
  ```bash
  nano /etc/dovecot/sieve/rspamd-learn-ham.sh
  ```
  Enter following script:
  ```bash
  #!/bin/sh
  exec /usr/bin/rspamc learn_ham
  ```
  Make these .sh files executible by the vmail user:
  ```bash
  chmod u=rwx,go= /etc/dovecot/sieve/rspamd-learn-{spam,ham}.sh
  ```
  ```bash
  chown vmail.vmail /etc/dovecot/sieve/rspamd-learn-{spam,ham}.sh
  ```
  
  i. Restart Dovecot
  
  ```bash
  systemctl restart dovecot
  ```
  
  j. Configure Rspamd Password
  
  Generate password hash:
  ```bash
  rspamadm pw
  ```
  Copy the hashed paswowrd {Rspamd Password Hash}
  Create the Rspamd controller.inc file
  ```bash
  nano /etc/rspamd/local.d/worker-controller.inc
  ```
  Edit the file using the hashed password generated above (Note the leading and trailing ":
  ```bash
  password = "{Rspamd Password Hash}"
  ```
  
  k. Restart Rspamd
  
  ```bash
  systemctl restart rspamd
  ``` 
 
## Configure DKIM

DKIM verifies that emails received by other persons and purporting to be from your domain, are, in-fact, from your domain, it relies on a DNS Record with your public key to do this.

 1. Create dkim folder and give Rspamd privileges:

 ```bash
 mkdir /var/lib/rspamd/dkim
 ```
 ```bash
 chown _rspamd:_rspamd /var/lib/rspamd/dkim
 ```
 
 2. Generate DKIM Key

 Choose a string of around 8 characters (I use the date on the day, ex. 20220209), we will refer to this as {DKIMKey}:
 
 ```bash
 rspamadm dkim_keygen -d example.org -s {DKIMKey}
 ```
 
 Copy the PUBLIC part of the key (p={RANDOM STRING OF NUMBERS}). This should be one uninterrupted line so edit any spaces or linebreaks out. This is your {DKIMPublikKey}
 
 3. Configure your DNS Server for DKIM

 Create a TXT Record in your DNS Server for the maildomain you wish to certify, the name of the record will be:
 
 ```bash
 {DKIMKey}._domainkey.example.org
 ```
 And the Content of the TXT Record will be {DKIMPublicKey} (including the p= section).
 
 4. Configure Rspamd to use the DKIM Key:

  a. Create the dkim_signing.conf file
  
  ```bash
  nano /etc/rspamd/local.d/dkim_signing.conf
  ```
  Paste the following strings:
  ```bash
  path = "/var/lib/rspamd/dkim/$domain.$selector.key";
  selector_map = "/etc/rspamd/dkim_selectors.map";
  ```
  
  b. Link Rspamd to the DKIM Key you created
  
  Edit dkim_selectors.map
  ```bash
  nano /etc/rspamd/dkim_selectors.map
  ```
  Enter a string which is based on your domain {DKIMKey}:
  ```bash
  example.org {DKIMKey}
  ```
  
  c. Reload Rspamd
  
  ```bash
  systemctl reload rspamd
  ```

## Deploy your first user

You can now login to https://mail.example.org/admin/setup.php with your setup password and create an admin user. Once you have done this, you can browse to https://mail.example.org/admin and login with your admin user created in this step.

From here, you can create your first domain (example.org) and your first mailuser (user@example.org) as well as your first alias (alias@example.org) and test that everything works.

Username: user@example.org
Password: Whatever you configured for the user

You can login with any IMAP mail client (Microsoft Outlook, Thunderbird, KMail, etc.) and the configurations are:

IMAP Server: mail.example.org
Port: 143

SMTP Server: mail.example.org
Port: 587

Encryption: STARTTLS
Password Type: PLAIN

CalDAV/CarDAV (WebDAV) Access to Shared Calendars and Contacts:

WebDAV Server: https://mail.example.org/SOGo/dav

## Enable Mailbox Sharing (Not Yet Working)

This is not yet working, what does work for now, is when I share a folder from SOGo, the user_shares table is updated with the from_user and to_user fields correctly.

doveadm acl debug -u to_user@example.org shared/from_user@example.org indicates that the user does not have permission to view the folder.

 1. Create additional tables in the existing database:
 
 ```bash
 mysql
 ```
 ```bash
 USE mailserver;
 ```
 ```bash
 CREATE TABLE user_shares (
  from_user varchar(100) not null,
  to_user varchar(100) not null,
  dummy char(1) DEFAULT '1',    -- always '1' currently
  primary key (from_user, to_user)
  );
 ```
 ```bash
 CREATE TABLE anyone_shares (
  from_user varchar(100) not null,
  dummy char(1) DEFAULT '1',    -- always '1' currently
  primary key (from_user)
 );
 ```
 ```bash
 quit
 ```

 2. Edit Dovecot mail.conf file to enable shared mailboxes:

  ```bash
  nano /etc/dovecot/conf.d/10-mail.conf
  ```
  
  Uncomment and edit the sample shared namespace section to look like this:

 ```bash
 namespace {
  type = shared
  seperator - /
  prefix = shared/%%u/
  location = maildir:%%h:INDEX=~/shared/%%u
  subscriptions = no
  list = yes
 }
 ```

 Edit the mail_plugins section to enable acl

 ```bash
 mail_plugins = quota acl
 ```

 3. Configure the Dovecot ACL plugin

 ```bash
 nano /etc/dovecot/conf.d/90-acl.conf
 ```
 Edit it to look as follows:
 
 ```bash
 plugin {
   acl = vfile
 }
 
 plugin {
   acl_shared_dict = proxy::acl
 }
 
 dict {
   acl = mysql:/etc/dovecot/dovecot-dict-sql.conf.ext
 }
 ```
 
 4. Enable the dict service in master.conf
 
 ```bash
 nano /etc/dovecot/conf.d/10-master.conf
 ```
 Uncomment and edit the service dict { section like this:
 ```bash
 service dict {
   unix_listener dict {
     mode = 0600
     user = vmail
     group = vmail
   }
 }
 ```
 
 5. Configure dict-sql-conf.ext

 ```bash
 nano /etc/dovecot/dovecot-dict-sql.conf.ext
 ```
 Uncomment and Edit the file to resemble this:
 ```bash
 connect = host=localhost dbname=mailserver user=mailadmin password={adminpassword}

 map {
   pattern = shared/shared-boxes/user/$to/$from
   table = user_shares
   value_field = dummy
 
   fields {
     from_user = $from
     to_user = $to
   }
 }
 
 map {
   pattern = shared/shared-boxes/anyone/$from
   table = anyone_shares
   value_field = dummy
 
   fields {
     from_user = $from
   }
 }
 ```
 
 6. Enable imap_acl plugin in imap.conf

 ```bash
 nano /etc/dovecot/conf.d/20-imap.conf
 ```
 Edit the mail_plugins section to resembler this:
 ```bash
 mail_plugins = $mail_plugins quota imap_sieve imap_acl
 ```
 
## Enable ClamAV Scanning - Testing (Packages not included in first step)
 
ClamAV is an opensource Antivirus Scanner and while it is not as effective as some commercial options, integration with Rspamd is quite simple. Antivirus scanning on a mail server by itself is not good enough and a holistic approach to security is still necessary, but every little bit helps.
 
 1. Install ClamAV Components:
 
 ```bash
 apt install -y clamav clamav-daemon clamav-unofficial-sigs
 ```
 
 2. Configure Rspamd to use ClamAV
 
  ```bash
  nano /etc/rspamd/modules.d/antivirus.conf
  ```
  Modify file to resemble (This rejects mail with Virus, modify to move to Junk):
  ```bash
  clamav {
    scan_mime_parts = false;
    scan_text_mime = true;
    scan_image_mime = true;
    symbol = "CLAM_VIRUS";
    type = "clamav";
    log_clean = true;
    servers = "clamd:3310";
    max_size = 20971520;
  }
  ```
 
 3. Enable ClamAV daemon

 ```bash
 systemctl enable clamav-daemon
 ```
 
 4. Restart Rspamd
 
 ```bash
 systemctl restart rspamd
 ```

## Enable Firewall with nftables - Testing (Packages not included in first step)

 1. Install nftables:

 ```bash
 apt install -y nftables
 ```
 
 2. Change Debian settings to have the iptables command use nftables

 ```bash
 update-alternatives --config iptables
 ```
 
 3. Create Firewall Rules file:

 ```bash
 nano /etc/nftables.conf
 ```
 Configuring as follows:
 ```bash
 #!/usr/sbin/nft -f
 flush ruleset
 table inet filter {
   chain input {
     type filter hook input priority 0; policy drop;

     iifname lo accept
     ct state established,related accept
     tcp dport { ssh, http, https, imap2, imaps, pop3, pop3s, submission, smtp } ct state new accept

     # ICMP: errors, pings
     ip protocol icmp icmp type { echo-request, echo-reply, destination-unreachable, time-exceeded, parameter-problem, router-solicitation, router-advertisement } accept
     # ICMPv6: errors, pings, routing
     ip6 nexthdr icmpv6 counter accept comment "accept all ICMP types"

     # Reject other packets
     ip protocol tcp reject with tcp reset
   }
 }
 ```
 
 4. Have nftables start at boot
 
 ```bash
 systemctl enable nftables
 ```
 
 5. Start nftables
 
 ```bash
 systemctl start nftables
 ```
 
## Brute force mitigation with fail2ban - Testing (Packages not included in first step)

We will use fail2ban to monitor various system logs and look for patterns which might suggest abuse. Ideally this will prevent bad actors from gaining access to your system, however it can result in legitimate users being blocked too.

Fail2ban uses "Jails" along with your firewall to block IP addresses that are missbehaving.

You can use fail2ban-client status to see which jails are active, and you can use fail2ban-client status sshd to see which clients are currently rejected and why.

 1. Install fail2ban
 
 ```bash
 apt install -y fail2ban
 ```
 
 2. Restart nftables
 
 ```bash
 systemctl restart nftables
 ```
 
 3. Enable Postfix and Dovecot monitoring to fail2ban
 
 While you can look at /etc/fail2ban/jail.conf for some sample configs for various servers, we need to create a config for our servers:

 ```bash
 nano /etc/fail2ban/jail.local
 ```
 Making it look like this:
 ```bash
 [apache-auth]
 enabled = true

 [dovecot]
 enabled = true
 port    = pop3,pop3s,imap2,imaps,submission,465,sieve

 [postfix]
 enabled = true

 [sieve]
 enabled = true
 ```
 Test your config
 ```bash
 fail2ban-server -t
 ```
 
 Restart fail2ban
 ```bash
 systemctl restart fail2ban
 ```
