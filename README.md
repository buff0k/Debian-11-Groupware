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
