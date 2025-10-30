<!--
# zzwrap
# preparation of install
#
# Part of »Zugzwang Project«
# https://www.zugzwang.org/modules/zzwrap
#
# @author Gustaf Mossakowski <gustaf@koenige.org>
# @copyright Copyright © 2024-2025 Gustaf Mossakowski
# @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
#
-->

# Installation

## System requirements

Zugzwang Project runs on an Apache Webserver with PHP as scripting
language and a MySQL database. For image manipulation, you can use PHP’s
GD Library or ImageMagick.

- Webserver
  - Apache 2.4 (should work on older webservers) with `mod_rewrite`
  enabled. To use proxy functions, enable `mod_proxy`.
  - Nginx will probably work, too, but it is not tested. You will need
  to create your own rewriting configuration.

- PHP
  - Tested with PHP 8.4. If you need to run older versions, it should
  work with versions as old as 7.2.
  - These extensions are required: `php-mbstring`, `php-mysqli`,
  `php-exif`, `php-iconv`
  - These extensions are recommended:
    - `php-gd` if you don’t have ImageMagick
    - `php-zip` if you would like to create ZIP archives
    - `php-curl` for data synchronisation, but there’s a fallback
    - `php-libxml` e. g. for the `events` module if you use iCalendar

- MySQL
  - MySQL 5.7, 8 or 9 are supported
  - MariaDB is supported, too

- ImageMagick
- GPL Ghostscript

## Dependencies

The following dependencies are defined in the form of Git submodules.
They are installed automatically by calling `git clone --recursive
git@github.com:{user}/{repository}.git`:

* CMS: Zugzwang Project, <https://www.zugzwang.org> with the following
modules:
  * default module, <https://github.com/koenige/modules-default>
  * zzform module, <https://github.com/koenige/zzform>
  * zzwrap (Core CMS Library), <https://github.com/koenige/zzwrap>
  * zzbrick (Templating System), <https://github.com/koenige/zzbrick>
  * … and more, depending on what you need, for a full list, see
  <https:/www.zugzwang.org/modules/>
* CMS Themes
* Markdown
  * PHP Markdown, <https://github.com/michelf/php-markdown>
  * Pagedown Markdown editor and converter,
  <https://github.com/StackExchange/pagedown>
* vxJS JavaScript Library, <https://github.com/Vectrex/vxJS>

## Setup

This instruction sets up a local instance of `example.org` (replace this
with your website domain) which can be accessed via
`http://example.org.local/`.

### Create a Folder

1. Create a folder for the website, like `/var/www/example.org`. Make
sure it is accessible to the Apache web user, which is on a server
oftentimes `www-data`.

### Webserver Setup

2. Create a new Apache site

Create the file `/etc/apache2/sites-available/example.org.conf` with
the following content:

    <VirtualHost *:80>
        ServerName www.example.org.local
        DocumentRoot /var/www/example.org/www

        ErrorLog ${APACHE_LOG_DIR}/example.org.local_error.log
        CustomLog ${APACHE_LOG_DIR}/example.org.local_access.log combined

        <Directory "/var/www/example.org/">
            Require all granted
        </Directory>
    </VirtualHost>

3. Create a local TLS certificate

For instance using [mkcert](https://github.com/FiloSottile/mkcert). You
need to add a HTTPS configuration for your Apache server:

	<VirtualHost *:443>
		 ServerName www.example.org.local
		 DocumentRoot /var/www/example.org/www
	
		 SSLEngine on
		 SSLCertificateFile "/var/www/certs/www.example.org.local.pem"
		 SSLCertificateKeyFile "/var/www/certs/www.example.org.local-key.pem"
		 <Directory "/var/www/example.org/">
			Require all granted
		</Directory>
	</VirtualHost>

4. Enable new Apache site

   `a2ensite example.org.conf` (depending on what OS you are using)

### Database Setup

5. Create a local MySQL database and user. If you are cloning an
existing website, use the value for `db_name_local` as database name.

6.  Create `pwd.json` with database credentials

Create the file `/var/www/pwd.json` with the following content:

    {
        "db_host": "localhost",
        "db_user": "…",
        "db_pwd": "…"
    }

This needs to be filled in with your local database user and password.

### Get CMS files

7. Clone repository and submodules

If you do not have a repository yet, you can create a basic repository
in your example.org folder like this:

    git init

    git submodule add -b main git@github.com:koenige/zzwrap _inc/modules/zzwrap
    git submodule add -b main git@github.com:koenige/zzbrick _inc/modules/zzbrick
    git submodule add -b main git@github.com:koenige/zzform _inc/modules/zzform
    git submodule add -b main git@github.com:koenige/modules-default _inc/modules/default
    git submodule add -b main git@github.com:koenige/modules-media _inc/modules/media

    git submodule add -b lib git@github.com:michelf/php-markdown _inc/library/markdown-extra
    git submodule add -b master git@github.com:Vectrex/vxJS www/_behaviour/vxjs

    cp _inc/modules/zzwrap/www/.htaccess www
    cp -r _inc/modules/zzwrap/www/_scripts www/_scripts

This sample repository is available at
<https://github.com/koenige/example.org>. 

If you use an existing repository, you can install it like this:

    git clone --recursive git@github.com:koenige/example.org /var/www/example.org

8. Browser-based installation

To go on with the browser based installation, just call your local URL,
e. g. <https://www.example.org.local>.
