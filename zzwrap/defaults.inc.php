<?php 

/**
 * zzwrap
 * Default variables, post config
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2008-2022 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * sets defaults for zzwrap, includes config
 *
 * @global array $zz_setting
 * @global array $zz_conf		might change in config.inc.php
 * @global array $zz_page		might change in config.inc.php
 */
function wrap_set_defaults() {
	global $zz_setting;
	global $zz_conf;
	global $zz_page;

	// configuration settings, defaults
	wrap_set_defaults_pre_conf();
	wrap_config('read');
	if (!empty($zz_setting['multiple_websites']))
		wrap_config('read', $zz_setting['site']);
	if (file_exists($file = $zz_setting['inc'].'/config.inc.php'))
		require_once $file;
	wrap_set_defaults_post_conf();

	// module configs
	$module_config_included = wrap_include_files('./config', 'modules');
	// module config will overwrite standard config
	// so make it possible to overwrite module config
	if ($module_config_included AND file_exists($file = $zz_setting['inc'].'/config-modules.inc.php'))
		require_once $file;
}

/**
 * Default variables, pre config
 *
 * @global array $zz_setting
 * @global array $zz_conf
 */
function wrap_set_defaults_pre_conf() {
	global $zz_setting;
	global $zz_conf;

// -------------------------------------------------------------------------
// Main paths, should be set in main.php
// -------------------------------------------------------------------------
	
	// http root directory
	if (!isset($zz_conf['root']))
		$zz_conf['root'] = $_SERVER['DOCUMENT_ROOT'];
	if (substr($zz_conf['root'], -1) === '/')
		$zz_conf['root'] = substr($zz_conf['root'], 0, -1);
	// includes
	if (!isset($zz_setting['cms_dir']))
		$zz_setting['cms_dir'] = realpath($zz_conf['root'].'/..');
	if (!isset($zz_setting['inc']))
		$zz_setting['inc'] = $zz_setting['cms_dir'].'/_inc';

// -------------------------------------------------------------------------
// Hostname
// -------------------------------------------------------------------------

	// HTTP_HOST, check against XSS
	if (!empty($_SERVER['HTTP_HOST']) AND preg_match('/^[a-zA-Z0-9-\.]+$/', $_SERVER['HTTP_HOST']))
		$zz_setting['hostname']	= $_SERVER['HTTP_HOST'];
	else
		$zz_setting['hostname'] = $_SERVER['SERVER_NAME'];
	// fully-qualified (unambiguous) DNS domain names have a dot at the end
	// we better not redirect these to a domain name without a dot to avoid
	// ambiguity, but we do not need to do double caching etc.
	if (substr($zz_setting['hostname'], -1) === '.')
		$zz_setting['hostname'] = substr($zz_setting['hostname'], 0, -1);
	// in case, somebody's doing a CONNECT or something similar, use some default
	if (empty($zz_setting['hostname'])) 
		$zz_setting['hostname'] === 'www.example.org';
	// make hostname lowercase to avoid duplicating caches
	$zz_setting['hostname'] = strtolower($zz_setting['hostname']);

	// check if it’s a local development server
	// get site name without www. and .local
	$zz_setting['local_access'] = false;
	$zz_setting['site'] = $zz_setting['hostname'];
	if (str_starts_with($zz_setting['site'], 'www.'))
		$zz_setting['site'] = substr($zz_setting['site'], 4);
	if (str_starts_with($zz_setting['site'], 'dev.')) {
		$zz_setting['site'] = substr($zz_setting['site'], 4);
		$zz_setting['local_access'] = true;
	}
	if (str_ends_with($zz_setting['site'], '.local')) {
		$zz_setting['site'] = substr($zz_setting['site'], 0, -6);
		$zz_setting['local_access'] = true;
	}

	// base URL, e. g. for languages
	$zz_setting['base'] = '';
	
	// request URI
	$zz_setting['request_uri'] = $_SERVER['REQUEST_URI'];

// -------------------------------------------------------------------------
// HTTP
// -------------------------------------------------------------------------

	$zz_setting['extra_http_headers'] = [];
	// Prevent IE > 7 from sniffing mime types
	$zz_setting['extra_http_headers'][] = 'X-Content-Type-Options: nosniff';
	// set Cache-Control defaults
	$zz_setting['cache_control_text'] = 3600; // 1 hour
	$zz_setting['cache_control_file'] = 86400; // 1 day
	$zz_setting['remote_ip'] = wrap_http_remote_ip();
	$zz_setting['https_urls'][] = '/';
	$zz_setting['local_https'] = true;

// -------------------------------------------------------------------------
// URLs
// -------------------------------------------------------------------------

	$zz_setting['base_path'] = '';

// -------------------------------------------------------------------------
// Paths
// -------------------------------------------------------------------------

	// Caching	
	$zz_setting['cache']		= true;
	$zz_setting['cache_dir']	= $zz_setting['cms_dir'].'/_cache';
	$zz_setting['cache_age']	= 10;
	if ($zz_setting['local_access']) {
		$zz_setting['cache_age']	= 1;
	}

	// Media
	$zz_setting['media_folder']	= $zz_setting['cms_dir'].'/files';

	// Forms: zzform upload module
	$zz_setting['tmp_dir']		= $zz_setting['cms_dir'].'/_temp';

	// Logfiles
	$zz_setting['log_dir']		= $zz_setting['cms_dir'].'/_logs';

// -------------------------------------------------------------------------
// Modules
// -------------------------------------------------------------------------

	// modules
	$zz_setting['ext_libraries'][] = 'markdown-extra';

	// tables from default module
	$zz_setting['brick_default_tables'] = true;

// -------------------------------------------------------------------------
// Page
// -------------------------------------------------------------------------

	// Use redirects table / Umleitungs-Tabelle benutzen
	$zz_setting['check_redirects'] = true;

	// zzbrick: brick types
	$zz_setting['brick_types_translated']['tables'] = 'forms';
	$zz_setting['brick_types_translated']['make'] = 'request';

	$zz_setting['brick_fulltextformat'] = 'markdown';
	// functions that might be used for formatting (zzbrick)
	$zz_setting['brick_formatting_functions'] = [
		'markdown', 'markdown_inline', 'markdown_attribute', 'wrap_date',
		'rawurlencode', 'wordwrap', 'nl2br', 'htmlspecialchars',
		'wrap_html_escape', 'wrap_latitude', 'wrap_longitude', 'wrap_number',
		'ucfirst', 'wrap_time', 'wrap_bytes', 'wrap_duration', 'strip_tags',
		'strtoupper', 'strtolower', 'wrap_money', 'quoted_printable_encode',
		'wrap_bearing', 'wrap_cfg_quote', 'wrap_meters', 'wrap_js_escape',
		'wrap_js_nl2br', 'wrap_percent', 'wrap_punycode_decode', 'wrap_gram',
		'wrap_currency'
	];

	if (!$zz_setting['local_access']) {
		$zz_setting['gzip_encode'] = true;
	}

// -------------------------------------------------------------------------
// Database
// -------------------------------------------------------------------------

	$zz_conf['prefix']			= ''; // prefix for all database tables
	$zz_setting['unwanted_mysql_modes'] = [
		'NO_ZERO_IN_DATE'
	];

// -------------------------------------------------------------------------
// Error Logging, Mail
// -------------------------------------------------------------------------

	if ($zz_setting['local_access'])
		$zz_setting['error_handling']	= 'output';
	if (!$zz_setting['local_access'])
		// just in case it's a bad ISP and php.ini must not be changed
		@ini_set('display_errors', 0);

// -------------------------------------------------------------------------
// Authentication
// -------------------------------------------------------------------------

	// minutes until you will be logged out automatically while inactive
	$zz_setting['logout_inactive_after'] = 30;

// -------------------------------------------------------------------------
// Language, character set
// -------------------------------------------------------------------------

	$zz_setting['lang'] = '';
	$zz_setting['character_set'] = 'utf-8';

}

/**
 * read configuration from JSON file
 *
 * @param string $mode (read, write)
 * @param string $site (optional)
 * @return void
 */
function wrap_config($mode, $site = '') {
	global $zz_setting;
	global $zz_conf;

	$re_read_config = false;
	if (!empty($zz_conf['db_connection']) AND $site AND empty($zz_setting['websites'])) {
		// multiple websites on server?
		// only possible to check after db connection was established
		$sql = 'SHOW TABLES';
		$tables = wrap_db_fetch($sql, '_dummy_key_', 'single value');
		if (in_array($zz_conf['prefix'].'websites', $tables)) {
			$zz_setting['websites'] = true;

			$sql = sprintf('SELECT website_id
				FROM websites WHERE domain = "%s"', wrap_db_escape($site));
			$website_id = wrap_db_fetch($sql, '', 'single value');

			if (!$website_id) {
				// no website, but maybe it’s a redirect hostname?
				$sql = sprintf('SELECT website_id, domain
					FROM /*_PREFIX_*/websites
					LEFT JOIN /*_PREFIX_*/_settings USING (website_id)
					WHERE setting_key = "external_redirect_hostnames"
					AND setting_value LIKE "[%%%s%%]"', wrap_db_escape($site));
				$website = wrap_db_fetch($sql);
				if (!$website AND !empty($zz_setting['website_id_default'])) {
					$sql = sprintf('SELECT website_id, domain
						FROM websites WHERE website_id = %d', $zz_setting['website_id_default']);
					$website = wrap_db_fetch($sql);
				}
				if ($website) {
					// different site, read config for this site
					$website_id = $website['website_id'];
					$site = $zz_setting['site'] = $website['domain'];
					$re_read_config = true;
				}
			}
		} else {
			$zz_setting['websites'] = false;
		}
	} else {
		if (empty($zz_setting['websites'])) $zz_setting['websites'] = false;
	}

	if ($site) {
		$file = $zz_setting['log_dir'].'/config-'.str_replace('/', '-', $site).'.json';
	} else {
		$file = $zz_setting['inc'].'/config.json';
	}
	if (!file_exists($file)) {
		if ($mode === 'read') return;
		$existing_config = [];
	} else {
		$existing_config = file_get_contents($file);
	}

	switch ($mode) {
	case 'read':
		$existing_config = json_decode($existing_config, true);
		if (!$existing_config) return;
		wrap_setting_register($existing_config);
		break;
	case 'write':
		if (!wrap_database_table_check('_settings', true)) break;
		
		if (!empty($zz_setting['multiple_websites'])) {
			if (empty($website_id)) $website_id = 1;
			$zz_setting['website_id'] = $website_id;
			$sql = sprintf('SELECT setting_key, setting_value
				FROM /*_PREFIX_*/_settings
				WHERE website_id = %d
				ORDER BY setting_key', $website_id);
		} else {
			$sql = 'SELECT setting_key, setting_value
				FROM /*_PREFIX_*/_settings ORDER BY setting_key';
		}
		$settings = wrap_db_fetch($sql, '_dummy_', 'key/value');
		if (!$settings) break;
		$new_config = json_encode($settings, JSON_PRETTY_PRINT + JSON_NUMERIC_CHECK);
		if ($new_config !== $existing_config)
			file_put_contents($file, $new_config);
		if ($re_read_config)
			wrap_config('read', $site);
		break;
	}
}

/**
 * Default variables, post config
 *
 * @global array $zz_setting
 * @global array $zz_conf
 */
function wrap_set_defaults_post_conf() {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;

	// -------------------------------------------------------------------------
	// Internationalization, Language, Character Encoding
	// -------------------------------------------------------------------------

	if (function_exists('mb_internal_encoding')) {
		// (if PHP does not know character set, will default to
		// ISO-8859-1)
		mb_internal_encoding(strtoupper($zz_setting['character_set']));
	}
	if (empty($zz_setting['language_translations'])) {
		// fields in table languages.language_xx
		$zz_setting['language_translations'] = ['en', 'de', 'fr'];
	}
	if (!empty($zz_setting['timezone']))
		date_default_timezone_set($zz_setting['timezone']);

	// -------------------------------------------------------------------------
	// Request method
	// -------------------------------------------------------------------------
	
	if (empty($zz_setting['http']['allowed'])) {
		if (!wrap_is_dav_url()) {
			$zz_setting['http']['allowed'] = ['GET', 'HEAD', 'POST', 'OPTIONS'];
		} else {
			$zz_setting['http']['allowed'] = [
				'GET', 'HEAD', 'POST', 'OPTIONS', 'PUT', 'DELETE', 'PROPFIND',
				'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK'
			];
		}
	} else {
		// The following REQUEST methods must always be allowed in general:
		if (!in_array('GET', $zz_setting['http']['allowed']))
			$zz_setting['http']['allowed'][] = 'GET';
		if (!in_array('HEAD', $zz_setting['http']['allowed']))
			$zz_setting['http']['allowed'][] = 'HEAD';
	}
	if (empty($zz_setting['http']['not_allowed'])) {
		if (!wrap_is_dav_url()) {
			$zz_setting['http']['not_allowed'] = ['PUT', 'DELETE', 'TRACE', 'CONNECT'];
		} else {
			$zz_setting['http']['not_allowed'] = ['TRACE', 'CONNECT'];
		}
	}

	// -------------------------------------------------------------------------
	// URLs
	// -------------------------------------------------------------------------

	if (empty($zz_setting['homepage_url']))
		$zz_setting['homepage_url']	= $zz_setting['base_path'].'/';
	if (empty($zz_setting['login_url']))
		$zz_setting['login_url']	= $zz_setting['base_path'].'/login/';
	if (empty($zz_setting['logout_url']))
		$zz_setting['logout_url']	= $zz_setting['base_path'].'/logout/';
	if (empty($zz_setting['login_entryurl']))
		$zz_setting['login_entryurl'] = $zz_setting['base_path'].'/';


	// HTML paths, relative to DOCUMENT_ROOT
	if (empty($zz_setting['layout_path']))
		$zz_setting['layout_path'] = $zz_setting['base_path'].'/_layout';
	if (empty($zz_setting['behaviour_path']))
		$zz_setting['behaviour_path'] = $zz_setting['base_path'].'/_behaviour';
	if (empty($zz_setting['files_path']))
		$zz_setting['files_path'] = $zz_setting['base_path'].'/files';
	if (!isset($zz_setting['dont_negotiate_language_paths'])) {
		$zz_setting['dont_negotiate_language_paths'] = [
			$zz_setting['layout_path'], $zz_setting['behaviour_path'],
			$zz_setting['files_path'], '/robots.txt'
		];
	}
	if (!isset($zz_setting['icon_paths'])) {
		$zz_setting['icon_paths'] = [
			$zz_setting['base_path'].'/apple-touch-icon.png',
			$zz_setting['base_path'].'/favicon.ico',
			$zz_setting['base_path'].'/favicon.png',
			$zz_setting['base_path'].'/opengraph.png'
		];
	}
	$zz_setting['dont_negotiate_language_paths'] =
		array_merge($zz_setting['dont_negotiate_language_paths'], $zz_setting['icon_paths']);
	if (isset($zz_setting['extra_dont_negotiate_language_paths'])) {
		$zz_setting['dont_negotiate_language_paths'] =
			array_merge($zz_setting['dont_negotiate_language_paths'], $zz_setting['extra_dont_negotiate_language_paths']);
	}
	if (!isset($zz_setting['ignore_scheme_paths'])) {
		$zz_setting['ignore_scheme_paths'] = [
			$zz_setting['layout_path'], $zz_setting['behaviour_path'],
			$zz_setting['files_path']
		];
	}
	
	// -------------------------------------------------------------------------
	// HTTP, Hostname, Access via HTTPS or not
	// -------------------------------------------------------------------------
	
	if (empty($zz_setting['https'])) $zz_setting['https'] = false;
	// HTTPS; zzwrap authentication will always be https
	if (empty($zz_setting['https_urls'])) $zz_setting['https_urls'] = [];
	// Logout must go via HTTPS because of secure cookie
	$zz_setting['https_urls'][] = $zz_setting['logout_url'];
	$zz_setting['https_urls'][] = $zz_setting['login_url'];
	if (!empty($zz_setting['auth_urls'])) {
		$zz_setting['https_urls'] = array_merge($zz_setting['https_urls'], $zz_setting['auth_urls']);
	}
	foreach ($zz_setting['https_urls'] AS $url) {
		// check language strings
		// @todo: add support for language strings at some other position of the URL
		$languages = $zz_setting['languages_allowed'] ?? [];
		$languages[] = ''; // without language string should be checked always
		foreach ($languages as $lang) {
			if ($lang) $lang = '/'.$lang;
			if ($zz_setting['base'].$lang.strtolower($url) 
				== substr(strtolower($_SERVER['REQUEST_URI']), 0, strlen($zz_setting['base'].$lang.$url))) {
				$zz_setting['https'] = true;
			}
		}
	}
	// local (development) connections are never made via https
	if (!empty($zz_setting['local_access']) AND empty($zz_setting['local_https'])) {
		$zz_setting['https'] = false;
		$zz_setting['no_https'] = true;
	}
	// connections from local don't need to go via https
	// makes it easier for some things
	if ($_SERVER['REMOTE_ADDR'] === '127.0.0.1'
		OR $_SERVER['REMOTE_ADDR'] === '::1'
		OR (!empty($_SERVER['SERVER_ADDR']) AND $_SERVER['REMOTE_ADDR'] === $_SERVER['SERVER_ADDR'])) {
		// don't set https to false, just allow non-https connections
		$zz_setting['ignore_scheme'] = true; 
	}
	// explicitly do not want https even for authentication (not recommended)
	if (!empty($zz_setting['no_https'])) $zz_setting['https'] = false;
	else $zz_setting['no_https'] = false;
	
	// allow to choose manually whether one uses https or not
	if (!isset($zz_setting['ignore_scheme'])) $zz_setting['ignore_scheme'] = false;
	if ($zz_setting['ignore_scheme']) 
		$zz_setting['https'] = empty($_SERVER['HTTPS']) ? false : true;

	if (!isset($zz_setting['session_secure_cookie'])) {
		$zz_setting['session_secure_cookie'] = true;
	}
	if ($zz_setting['no_https'] OR !$zz_setting['https']) {
		$zz_setting['session_secure_cookie'] = false;
	}

	if (empty($zz_setting['protocol']))
		$zz_setting['protocol'] 	= 'http'.($zz_setting['https'] ? 's' : '');
	if (empty($zz_setting['host_base'])) {
		$zz_setting['host_base'] 	= $zz_setting['protocol'].'://'.$zz_setting['hostname'];
		if (!in_array($_SERVER['SERVER_PORT'], [80, 443])) {
			$zz_setting['host_base'] .= sprintf(':%s', $_SERVER['SERVER_PORT']);
		}
	}
	

	// -------------------------------------------------------------------------
	// Paths
	// -------------------------------------------------------------------------
	
	$zz_setting['inc'] = realpath($zz_setting['inc']);
	
	// library
	if (empty($zz_setting['lib']))
		$zz_setting['lib']			= $zz_setting['inc'].'/library';
		
	// localized includes
	if (empty($zz_setting['custom']))	
		$zz_setting['custom'] 	= $zz_setting['inc'].'/custom';
	
	// customized cms includes
	if (empty($zz_setting['custom_wrap_dir']))	
		$zz_setting['custom_wrap_dir'] = $zz_setting['custom'].'/zzwrap';
	
	// customized sql queries, db connection
	if (empty($zz_setting['custom_wrap_sql_dir']))	
		$zz_setting['custom_wrap_sql_dir'] = $zz_setting['custom'].'/zzwrap_sql';

	// modules
	if (empty($zz_setting['modules_dir'])) {
		$zz_setting['modules_dir'] = $zz_setting['inc'].'/modules';
	}
	if (empty($zz_setting['modules'])) {
		$zz_setting['modules'] = [];
		if (is_dir($zz_setting['modules_dir'])) {
			$handle = opendir($zz_setting['modules_dir']);
			while ($file = readdir($handle)) {
				if (substr($file, 0, 1) === '.') continue;
				if (!is_dir($zz_setting['modules_dir'].'/'.$file)) continue;
				$zz_setting['modules'][] = $file;
			}
			closedir($handle);
		}
		// some hosters sort files in reverse order
		sort($zz_setting['modules']);
		// put default module always on top to have the possibility to
		// add functions with the same name in other modules
		if ($key = array_search('default', $zz_setting['modules'])) {
			unset($zz_setting['modules'][$key]);
			array_unshift($zz_setting['modules'], 'default');
		}
	}
	if (empty($zz_setting['themes_dir'])) {
		$zz_setting['themes_dir'] = $zz_setting['inc'].'/themes';
	}

	// customized access rights checks
	if (empty($zz_setting['custom_rights_dir'])) {
		if (!empty($zz_setting['default_rights'])) {
			$path = sprintf('%s/default/zzbrick_rights/%s', $zz_setting['modules_dir'], $zz_setting['default_rights']);
			if (is_dir($path))
				$zz_setting['custom_rights_dir'] = $path;
		}
		if (empty($zz_setting['custom_rights_dir']))
			$zz_setting['custom_rights_dir'] = $zz_setting['custom'].'/zzbrick_rights';
	}
	$file = $zz_setting['custom_rights_dir'].'/access_rights.inc.php';
	if (file_exists($file)) include_once $file;

	if (!empty($zz_setting['cache_dir'])) {
		$zz_setting['cache_dir_zz'] = empty($zz_setting['cache_directories'])
			? $zz_setting['cache_dir'] : $zz_setting['cache_dir'].'/d';
	}

	// cms core
	$zz_setting['core']			= __DIR__;
	
	// zzform path
	if (empty($zz_conf['dir']))
		if (file_exists($dir = $zz_setting['modules_dir'].'/zzform/zzform')) {
			$zz_conf['dir']				= $dir;
		} else {
			$zz_conf['dir']				= $zz_setting['lib'].'/zzform';
		}
	if (empty($zz_conf['dir_custom']))
		$zz_conf['dir_custom']		= $zz_setting['custom'].'/zzform';
	if (empty($zz_conf['dir_inc']))
		$zz_conf['dir_inc']			= $zz_conf['dir'];
	
	// zzform db scripts
	if (empty($zz_conf['form_scripts']))
		$zz_conf['form_scripts']	= $zz_setting['custom'].'/zzbrick_tables';
	
	// local pwd
	if (empty($zz_setting['local_pwd']))
		if (file_exists($zz_setting['cms_dir'].'/pwd.json'))
			$zz_setting['local_pwd'] = $zz_setting['cms_dir'].'/pwd.json';
		else
			$zz_setting['local_pwd'] = $zz_setting['cms_dir'].'/../pwd.json';

	if (!is_dir($zz_setting['tmp_dir'])) {
		$success = wrap_mkdir($zz_setting['tmp_dir']);
		if (!$success) {
			wrap_error(sprintf('Temp directory %s does not exist.', $zz_setting['tmp_dir']));
			$zz_setting['tmp_dir'] = false;
			if ($dir = ini_get('upload_tmp_dir')) {
				if ($dir AND is_dir($dir)) $zz_setting['tmp_dir'] = $dir;
			}
		}
	}
	if (empty($zz_setting['session_save_path']) AND $zz_setting['tmp_dir']) {
		$zz_setting['session_save_path'] = $zz_setting['tmp_dir'].'/sessions';
	}
	
	// cainfo
	// Certficates are bundled with CURL from 7.10 onwards, PHP 5 requires at least 7.10
	// so there should be currently no need to include an own PEM file
	// if (empty($zz_setting['cainfo_file']))
	//	$zz_setting['cainfo_file'] = __DIR__.'/cacert.pem';
	
	// -------------------------------------------------------------------------
	// Error Logging
	// -------------------------------------------------------------------------
	
	if (!isset($zz_setting['error_mail_parameters']) AND isset($zz_setting['error_mail_from']))
		$zz_setting['error_mail_parameters'] = '-f '.$zz_setting['error_mail_from'];


	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------
	
	// project title, default
	// (will occur only if database connection fails and json does not exist)
	if (!isset($zz_setting['project']))
		$zz_setting['project'] = preg_match('/^[a-zA-Z0-9-\.]+$/', $_SERVER['HTTP_HOST'])
			? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];

	// translations
	if (!isset($zz_conf['translations_of_fields']))
		$zz_conf['translations_of_fields'] = false;
	
	// @deprecated
	$deprecated_zz_page = [
		'breadcrumbs_separator', 'template_pagetitle', 'template_pagetitle_home',
		'dont_show_h1', 'h1_via_template', 'template'
	];
	foreach ($deprecated_zz_page as $deprecated) {
		if (empty($zz_page)) continue;
		if (!array_key_exists($deprecated, $zz_page)) continue;
		wrap_error(sprintf('@deprecated: $zz_page["%s"] is now $zz_setting["%s"]', $deprecated, $deprecated), E_USER_DEPRECATED);
		$zz_setting[$deprecated] = $zz_page[$deprecated];
	}
	
	// allowed HTML rel attribute values
	if (!isset($zz_setting['html_link_types'])) {
		$zz_setting['html_link_types'] = [
			'Alternate', 'Stylesheet', 'Start', 'Next', 'Prev', 'Contents',
			'Index', 'Glossary', 'Copyright', 'Chapter', 'Section',
			'Subsection', 'Appendix', 'Help', 'Bookmark', 'Up'
		];
	}
	
	// XML mode? for closing tags
	if (!isset($zz_setting['xml_close_empty_tags']))
		$zz_setting['xml_close_empty_tags'] = false;

	// Theme
	if (!empty($zz_setting['active_theme']))
		wrap_package_activate($zz_setting['active_theme'], 'theme');
	else
		$zz_setting['active_theme'] = '';
	
	// Page template
	if (empty($zz_setting['template'])) {
		$zz_setting['template'] = 'page';
	}

	// -------------------------------------------------------------------------
	// Debugging
	// -------------------------------------------------------------------------
	
	if (!isset($zz_conf['debug']))
		$zz_conf['debug']			= false;
	
	// -------------------------------------------------------------------------
	// Authentication
	// -------------------------------------------------------------------------
	
	if ($zz_setting['local_access']) {
		$zz_setting['logout_inactive_after'] *= 20;
	}
	
	
	// -------------------------------------------------------------------------
	// Mail
	// -------------------------------------------------------------------------
	
	if (!isset($zz_setting['mail_header_eol'])) {
		// mail header lines should be separated by \r\n
		// some postfix versions handle mail internally with \n and
		// replace \n with \r\n for outgoing mail, ending with \r\r\n = CRCRLF
		$zz_setting['mail_header_eol'] = "\r\n";
	}

	// -------------------------------------------------------------------------
	// Libraries
	// -------------------------------------------------------------------------

	if (empty($zz_setting['ext_libraries']) OR !in_array('zzbrick', $zz_setting['ext_libraries'])) {
		$zz_setting['ext_libraries'][] = 'zzbrick';
	}

}

/**
 * tests some expected environment settings
 *
 * @return bool
 */
function wrap_tests() {
	global $zz_setting;
	// check if cache directory exists
	if (!empty($zz_setting['cache'])) {
		if (!file_exists($zz_setting['cache_dir'])) {
			wrap_error(sprintf('Cache directory %s does not exist. Caching disabled.', 
				$zz_setting['cache_dir']), E_USER_WARNING);
			$zz_setting['cache'] = '';
		}
		// @todo: not is writable
	}
	return true;
}

/**
 * check if a URL is inside a WebDAV library and will not be handled by zzproject
 *
 * @global array $zz_setting
 * @return bool
 */
function wrap_is_dav_url() {
	global $zz_setting;
	if (empty($zz_setting['dav_url'])) return false;
	// no str_starts_with() here, function is not yet loaded
	if (substr($_SERVER['REQUEST_URI'], 0, strlen($zz_setting['dav_url'])) === $zz_setting['dav_url']) {
		return true;
	}
	return false;
}
