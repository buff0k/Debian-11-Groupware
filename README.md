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

## Configure Apache Webserver

Eventually I would like to move over to NginX for this, however deploying on Apache is easier at a small perormance cost and will work fine for our purposes until I can figure out the NginX recipe for the same deployment.

 Enable required Apache modules
 
 ```bash
a2enmod ssl rewrite headers proxy proxy_http
```
