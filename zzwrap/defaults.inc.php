<?php 

/**
 * zzwrap
 * Default variables, post config
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2008-2020 Gustaf Mossakowski
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
	wrap_config('read', $zz_setting['site']);
	if (file_exists($file = $zz_setting['inc'].'/config.inc.php'))
		require_once $file;
	wrap_set_defaults_post_conf();

	// module configs
	$module_config_included = false;
	foreach ($zz_setting['modules'] as $module) {
		if (file_exists($file = $zz_setting['modules_dir'].'/'.$module.'/config.inc.php')) {
			require_once $file;
			$module_config_included = true;
		}
	}
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

	// check if it's a local development server
	$zz_setting['local_access'] = (substr($zz_setting['hostname'], -6) === '.local') ? true : false;

	// get site name without www. and .local
	$zz_setting['site'] = $zz_setting['hostname'];
	if (substr($zz_setting['site'], 0, 4) === 'www.')
		$zz_setting['site'] = substr($zz_setting['site'], 4);
	if ($zz_setting['local_access'])
		$zz_setting['site'] = substr($zz_setting['site'], 0, -6);

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

	$zz_setting['homepage_url']	= '/';
	$zz_setting['login_entryurl'] = '/';

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
	$zz_conf['tmp_dir']			= $zz_setting['cms_dir'].'/_temp';
	$zz_conf['backup']			= true;
	$zz_conf['backup_dir']		= $zz_setting['cms_dir'].'/_backup';

	// Logfiles
	$zz_setting['log_dir']		= $zz_setting['cms_dir'].'/_logs';

// -------------------------------------------------------------------------
// Modules
// -------------------------------------------------------------------------

	// modules
	$zz_setting['ext_libraries'][] = 'markdown-extra';

	// Forms: zzform upload module
	$zz_conf['graphics_library'] = 'imagemagick';
	
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
		'markdown', 'wrap_date', 'rawurlencode', 'wordwrap', 'nl2br',
		'htmlspecialchars', 'wrap_html_escape', 'wrap_latitude',
		'wrap_longitude', 'wrap_number', 'ucfirst', 'wrap_time', 'wrap_bytes',
		'wrap_duration', 'strip_tags', 'strtoupper', 'strtolower', 'wrap_money',
		'quoted_printable_encode'
	];

	if (!$zz_setting['local_access']) {
		$zz_setting['gzip_encode'] = true;
	}

// -------------------------------------------------------------------------
// Database
// -------------------------------------------------------------------------

	$zz_conf['prefix']			= ''; // prefix for all database tables
	$zz_conf['logging']			= true;
	$zz_conf['logging_id']		= true;
	$zz_setting['unwanted_mysql_modes'] = [
		'NO_ZERO_IN_DATE'
	];

// -------------------------------------------------------------------------
// Error Logging, Mail
// -------------------------------------------------------------------------

	$zz_conf['error_mail_level'] = ['error', 'warning'];
	$zz_conf['error_handling']	= 'mail';
	if ($zz_setting['local_access']) {
		$zz_conf['error_handling']	= 'output';
	}
	if (!$zz_setting['local_access']) {
		// just in case it's a bad ISP and php.ini must not be changed
		@ini_set('display_errors', 0);
	}
	$zz_setting['mail_with_signature'] = true;

// -------------------------------------------------------------------------
// Authentication
// -------------------------------------------------------------------------

	$zz_setting['login_url']	= '/login/';
	$zz_setting['logout_url']	= '/logout/';
	// minutes until you will be logged out automatically while inactive
	$zz_setting['logout_inactive_after'] = 30;

// -------------------------------------------------------------------------
// Language, character set
// -------------------------------------------------------------------------

	$zz_setting['lang']			= '';
	$zz_conf['character_set']	= 'utf-8';

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

	if ($site) {
		$file = $zz_setting['log_dir'].'/config-'.$site.'.json';
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
		foreach ($existing_config as $skey => $value) {
			if (wrap_substr($skey, 'zzform_')) {
				$skey = substr($skey, 7);
				$var = 'zz_conf';
			} else {
				$var = 'zz_setting';
			}
			$keys_values = wrap_setting_key($skey, wrap_setting_value($value));
			foreach ($keys_values as $key => $value) {
				if (!is_array($value)) {
					if (!$value OR $value === 'false') $value = false;
					elseif ($value === 'true') $value = true;
				}
				if (empty($$var[$key]) OR !is_array($$var[$key]))
					$$var[$key] = $value;
				else
					$$var[$key] = array_merge_recursive($$var[$key], $value);
			}
		}
		break;
	case 'write':
		$sql = 'SHOW TABLES';
		$tables = wrap_db_fetch($sql, '_dummy_key_', 'single value');
		if (in_array($zz_conf['prefix'].'websites', $tables)) {
			$zz_setting['websites'] = true;

			$sql = sprintf('SELECT website_id
				FROM websites WHERE domain = "%s"', wrap_db_escape($site));
			$website_id = wrap_db_fetch($sql, '', 'single value');
			if (!$website_id) $website_id = 1;
			else $zz_setting['website_id'] = $website_id;

			$sql = sprintf('SELECT setting_key, setting_value
				FROM /*_PREFIX_*/_settings
				WHERE website_id = %d
				ORDER BY setting_key', $website_id);
		} else {
			$zz_setting['websites'] = false;

			$sql = 'SELECT setting_key, setting_value
				FROM /*_PREFIX_*/_settings ORDER BY setting_key';
		}
		$settings = wrap_db_fetch($sql, '_dummy_', 'key/value');
		if (!$settings) break;
		$new_config = json_encode($settings, JSON_PRETTY_PRINT + JSON_NUMERIC_CHECK);
		if ($new_config !== $existing_config)
			file_put_contents($file, $new_config);
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
		mb_internal_encoding(strtoupper($zz_conf['character_set']));
	}
	if (empty($zz_setting['language_translations'])) {
		// fields in table languages.language_xx
		$zz_setting['language_translations'] = ['en', 'de', 'fr'];
	}
	if (!empty($zz_setting['timezone']))
		date_default_timezone_set($zz_setting['timezone']);
	if (!empty($zz_setting['translate_text_db']))
		$zz_conf['text_table'] = $zz_conf['prefix'].'text';

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
		$languages = !empty($zz_setting['languages_allowed']) ? $zz_setting['languages_allowed'] : [];
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
		OR $_SERVER['REMOTE_ADDR'] === $_SERVER['SERVER_ADDR']) {
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
	
	if (empty($zz_setting['syndication_trigger_timeout_ms'])) {
		// timeout when triggering an URL with cURL
		// increase on slow servers
		$zz_setting['syndication_trigger_timeout_ms'] = 100;
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

	// customized sql queries, db connection
	if (empty($zz_setting['custom_rights_dir']))	
		$zz_setting['custom_rights_dir'] = $zz_setting['custom'].'/zzbrick_rights';
	
	// customized sql queries, db connection
	if (empty($zz_setting['custom_wrap_template_dir']))	
		$zz_setting['custom_wrap_template_dir'] = $zz_setting['inc'].'/templates';
	
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
		$zz_setting['local_pwd'] = '/Users/pwd.inc';

	if (!is_dir($zz_conf['tmp_dir'])) {
		$success = wrap_mkdir($zz_conf['tmp_dir']);
		if (!$success) {
			wrap_error(sprintf('Temp directory %s does not exist.', $zz_conf['tmp_dir']));
			$zz_conf['tmp_dir'] = false;
			if ($dir = ini_get('upload_tmp_dir')) {
				if ($dir AND is_dir($dir)) $zz_conf['tmp_dir'] = $dir;
			}
		}
	}
	if (empty($zz_setting['session_save_path']) AND $zz_conf['tmp_dir']) {
		$zz_setting['session_save_path'] = $zz_conf['tmp_dir'].'/sessions';
	}
	
	// cainfo
	// Certficates are bundled with CURL from 7.10 onwards, PHP 5 requires at least 7.10
	// so there should be currently no need to include an own PEM file
	// if (empty($zz_setting['cainfo_file']))
	//	$zz_setting['cainfo_file'] = __DIR__.'/cacert.pem';
	
	// images
	if (empty($zz_setting['media_preview_size']))
		$zz_setting['media_preview_size'] = 80;
	
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
	
	// breadcrumbs
	if (!isset($zz_page['breadcrumbs_separator']))
		$zz_page['breadcrumbs_separator'] = '&gt;';
	
	// page title and project title
	if (!isset($zz_page['template_pagetitle']))
		$zz_page['template_pagetitle'] = '%1$s (%2$s)';
	if (!isset($zz_page['template_pagetitle_home']))
		$zz_page['template_pagetitle_home'] = '%1$s';
	
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
	if (empty($zz_setting['active_theme'])) $zz_setting['active_theme'] = '';
	
	// Page template
	if (empty($zz_page['template'])) {
		$zz_page['template'] = 'page';
	}
	
	// HTML paths, relative to DOCUMENT_ROOT
	if (empty($zz_setting['layout_path']))
		$zz_setting['layout_path'] = '/_layout';
	if (empty($zz_setting['behaviour_path']))
		$zz_setting['behaviour_path'] = '/_behaviour';
	if (empty($zz_setting['files_path']))
		$zz_setting['files_path'] = '/files';
	if (!isset($zz_setting['dont_negotiate_language_paths'])) {
		$zz_setting['dont_negotiate_language_paths'] = [
			$zz_setting['layout_path'], $zz_setting['behaviour_path'],
			$zz_setting['files_path']
		];
	}
	if (!isset($zz_setting['ignore_scheme_paths'])) {
		$zz_setting['ignore_scheme_paths'] = [
			$zz_setting['layout_path'], $zz_setting['behaviour_path'],
			$zz_setting['files_path']
		];
	}

	// zzform: local zzform-colours.css?		
	if (!isset($zz_setting['zzform_colours'])) {
		$zz_setting['zzform_colours'] = true;
	}

	// -------------------------------------------------------------------------
	// Debugging
	// -------------------------------------------------------------------------
	
	if (!isset($zz_conf['debug']))
		$zz_conf['debug']			= false;
	
	// -------------------------------------------------------------------------
	// Database structure
	// -------------------------------------------------------------------------
	
	if (!isset($zz_conf['relations_table']))
		$zz_conf['relations_table']	= '/*_PREFIX_*/_relations';
	
	if (!empty($zz_conf['logging']) AND !isset($zz_conf['logging_table']))
		$zz_conf['logging_table']	= '/*_PREFIX_*/_logging';
	
	if (!empty($zz_conf['translations_of_fields']) AND empty($zz_conf['translations_table']))
		$zz_conf['translations_table']  = '/*_PREFIX_*/_translationfields';

	if (!empty($zz_conf['revisions'])) {
		if (!isset($zz_conf['revisions_table']))
			$zz_conf['revisions_table']	= '/*_PREFIX_*/_revisions';
		if (!isset($zz_conf['revisions_data_table']))
			$zz_conf['revisions_data_table']	= '/*_PREFIX_*/_revisiondata';
	}
	
	
	// -------------------------------------------------------------------------
	// Error Logging
	// -------------------------------------------------------------------------
	
	if (!isset($zz_conf['error_log']['error']))
		$zz_conf['error_log']['error']	= ini_get('error_log');
	
	if (!isset($zz_conf['error_log']['warning']))
		$zz_conf['error_log']['warning']	= ini_get('error_log');
	
	if (!isset($zz_conf['error_log']['notice']))
		$zz_conf['error_log']['notice']	= ini_get('error_log');
	
	if (!isset($zz_conf['log_errors']))
		$zz_conf['log_errors'] 			= ini_get('log_errors');
	
	if (!isset($zz_conf['log_errors_max_len']))
		$zz_conf['log_errors_max_len'] 	= ini_get('log_errors_max_len');
	
	if (!isset($zz_conf['translate_log_encodings']))
		$zz_conf['translate_log_encodings'] = [
			'iso-8859-2' => 'iso-8859-1'
		];
	if (!isset($zz_conf['error_log_post']))
		$zz_conf['error_log_post']	= false;
	
	if (!isset($zz_conf['error_mail_parameters']) AND isset($zz_conf['error_mail_from']))
		$zz_conf['error_mail_parameters'] = '-f '.$zz_conf['error_mail_from'];
	
	
	// -------------------------------------------------------------------------
	// Authentication
	// -------------------------------------------------------------------------
	
	if (!isset($zz_setting['authentication_possible']))
		$zz_setting['authentication_possible'] = true;
	
	if ($zz_setting['local_access']) {
		$zz_setting['logout_inactive_after'] *= 20;
	}
	
	
	// -------------------------------------------------------------------------
	// Encryption
	// -------------------------------------------------------------------------
	
	if (empty($zz_conf['hash_password'])) 
		$zz_conf['hash_password'] = 'password_hash';

	// Base-2 logarithm of the iteration count used for password stretching
	if (!isset($zz_conf['hash_cost_log2']))
		$zz_conf['hash_cost_log2'] = 11;
	
	if (in_array($zz_conf['hash_password'], ['phpass', 'phpass-md5'])) {
		// Do we require the hashes to be portable to older systems (less secure)?
		if (!isset($zz_conf['hash_portable']))
			$zz_conf['hash_portable'] = FALSE;
		
		// path to script
		if (!isset($zz_conf['hash_script']))
			$zz_conf['hash_script'] = $zz_setting['lib'].'/phpass/PasswordHash.php';
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
	// no wrap_substr() here, function is not yet loaded
	if (substr($_SERVER['REQUEST_URI'], 0, strlen($zz_setting['dav_url'])) === $zz_setting['dav_url']) {
		return true;
	}
	return false;
}
