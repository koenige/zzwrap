<?php 

/**
 * zzwrap
 * Default variables, post config
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2008-2023 Gustaf Mossakowski
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
	if ($module_config_included AND file_exists($file = wrap_setting('inc').'/config-modules.inc.php'))
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
		$zz_setting['hostname'] = 'www.example.org';
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

	$zz_setting['request_uri'] = $_SERVER['REQUEST_URI'];
	$zz_setting['remote_ip'] = wrap_http_remote_ip();

// -------------------------------------------------------------------------
// Error Logging, Mail
// -------------------------------------------------------------------------

	if (!$zz_setting['local_access'])
		// just in case it's a bad ISP and php.ini must not be changed
		@ini_set('display_errors', 0);

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

	$re_read_config = false;
	if (wrap_db_connection() AND $site AND empty($zz_setting['websites'])) {
		// multiple websites on server?
		// only possible to check after db connection was established
		$sql = 'SHOW TABLES';
		$tables = wrap_db_fetch($sql, '_dummy_key_', 'single value');
		$websites_table = (!empty($zz_setting['db_prefix']) ? $zz_setting['db_prefix'] : '').'websites';
		if (in_array($websites_table, $tables)) {
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
	// Paths
	// -------------------------------------------------------------------------
	
	$zz_setting['inc'] = realpath($zz_setting['inc']);

	// localized includes
	if (empty($zz_setting['custom']))	
		$zz_setting['custom'] 	= $zz_setting['inc'].'/custom';

	// modules
	if (empty($zz_setting['modules_dir']))
		$zz_setting['modules_dir'] = $zz_setting['inc'].'/modules';
	if (empty($zz_setting['themes_dir']))
		$zz_setting['themes_dir'] = $zz_setting['inc'].'/themes';

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
	
	// now we can use wrap_setting()
	

	// -------------------------------------------------------------------------
	// Internationalization, Language, Character Encoding
	// -------------------------------------------------------------------------

	if (function_exists('mb_internal_encoding')) {
		// (if PHP does not know character set, will default to
		// ISO-8859-1)
		mb_internal_encoding(strtoupper(wrap_setting('character_set')));
	}
	if (wrap_setting('timezone'))
		date_default_timezone_set(wrap_setting('timezone'));

	// -------------------------------------------------------------------------
	// Request method
	// -------------------------------------------------------------------------
	
	if (!wrap_setting('http[allowed]')) {
		if (!wrap_is_dav_url())
			wrap_setting('http[allowed]', ['GET', 'HEAD', 'POST', 'OPTIONS']);
		else
			wrap_setting('http[allowed]', [
				'GET', 'HEAD', 'POST', 'OPTIONS', 'PUT', 'DELETE', 'PROPFIND',
				'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK'
			]);
	} else {
		// The following REQUEST methods must always be allowed in general:
		if (!in_array('GET', wrap_setting('http[allowed]')))
			wrap_setting_add('http[allowed]', 'GET');
		if (!in_array('HEAD', wrap_setting('http[allowed]')))
			wrap_setting_add('http[allowed]', 'HEAD');
	}
	if (!wrap_setting('http[not_allowed]')) {
		if (!wrap_is_dav_url())
			wrap_setting('http[not_allowed]', ['PUT', 'DELETE', 'TRACE', 'CONNECT']);
		else
			wrap_setting('http[not_allowed]', ['TRACE', 'CONNECT']);
	}

	// -------------------------------------------------------------------------
	// URLs
	// -------------------------------------------------------------------------

	// HTML paths, relative to DOCUMENT_ROOT
	wrap_setting_add('dont_negotiate_language_paths', wrap_setting('icon_paths'));
	if (wrap_setting('extra_dont_negotiate_language_paths'))
		wrap_setting_add('dont_negotiate_language_paths', wrap_setting('extra_dont_negotiate_language_paths'));
	
	// -------------------------------------------------------------------------
	// HTTP, Hostname, Access via HTTPS or not
	// -------------------------------------------------------------------------
	
	// HTTPS; zzwrap authentication will always be https
	if (!in_array('/', wrap_setting('https_urls'))) {
		// Logout must go via HTTPS because of secure cookie
		wrap_setting_add('https_urls', wrap_setting('logout_url'));
		wrap_setting_add('https_urls', wrap_setting('login_url'));
		wrap_setting_add('https_urls', wrap_setting('auth_urls'));
	}
	foreach (wrap_setting('https_urls') AS $url) {
		// check language strings
		// @todo add support for language strings at some other position of the URL
		$languages = wrap_setting('languages_allowed');
		$languages[] = ''; // without language string should be checked always
		foreach ($languages as $lang) {
			if ($lang) $lang = '/'.$lang;
			if (wrap_setting('base').$lang.strtolower($url) 
				== substr(strtolower($_SERVER['REQUEST_URI']), 0, strlen(wrap_setting('base').$lang.$url))) {
				wrap_setting('https', true);
			}
		}
	}
	// local (development) connections are never made via https
	if (wrap_setting('local_access') AND !wrap_setting('local_https')) {
		wrap_setting('https', false);
		wrap_setting('no_https', true);
	}
	// connections from local don't need to go via https
	// makes it easier for some things
	if ($_SERVER['REMOTE_ADDR'] === '127.0.0.1'
		OR $_SERVER['REMOTE_ADDR'] === '::1'
		OR (!empty($_SERVER['SERVER_ADDR']) AND $_SERVER['REMOTE_ADDR'] === $_SERVER['SERVER_ADDR'])) {
		// don't set https to false, just allow non-https connections
		wrap_setting('ignore_scheme', true); 
	}
	// explicitly do not want https even for authentication (not recommended)
	if (wrap_setting('no_https')) wrap_setting('https', false);
	
	// allow to choose manually whether one uses https or not
	if (wrap_setting('ignore_scheme'))
		wrap_setting('https', wrap_https());

	if (wrap_setting('no_https') OR !wrap_setting('https'))
		wrap_setting('session_secure_cookie', false);

	if (!wrap_setting('protocol'))
		wrap_setting('protocol', 'http'.(wrap_setting('https') ? 's' : ''));
	if (!wrap_setting('host_base')) {
		wrap_setting('host_base', wrap_setting('protocol').'://'.wrap_setting('hostname'));
		if (!in_array($_SERVER['SERVER_PORT'], [80, 443])) {
			wrap_setting('host_base', wrap_setting('host_base').sprintf(':%s', $_SERVER['SERVER_PORT']));
		}
	}

	// -------------------------------------------------------------------------
	// Paths
	// -------------------------------------------------------------------------

	// customized access rights checks
	if (!wrap_setting('custom_rights_dir')) {
		if (wrap_setting('default_rights')) {
			$path = sprintf('%s/default/zzbrick_rights/%s', wrap_setting('modules_dir'), wrap_setting('default_rights'));
			if (is_dir($path))
				wrap_setting('custom_rights_dir', $path);
		}
		if (!wrap_setting('custom_rights_dir'))
			wrap_setting('custom_rights_dir', wrap_setting('custom').'/zzbrick_rights');
	}
	$file = wrap_setting('custom_rights_dir').'/access_rights.inc.php';
	if (file_exists($file)) include_once $file;

	if (wrap_setting('cache_dir')) {
		wrap_setting('cache_dir_zz', !wrap_setting('cache_directories')
			? wrap_setting('cache_dir') : wrap_setting('cache_dir').'/d');
	}

	// cms core
	wrap_setting('core', __DIR__);
	
	// zzform path
	if (empty($zz_conf['dir']))
		if (file_exists($dir = wrap_setting('modules_dir').'/zzform/zzform')) {
			$zz_conf['dir']				= $dir;
		}
	if (empty($zz_conf['dir_custom']))
		$zz_conf['dir_custom']		= wrap_setting('custom').'/zzform';
	if (empty($zz_conf['dir_inc']))
		$zz_conf['dir_inc']			= $zz_conf['dir'];
	
	// zzform db scripts
	if (empty($zz_conf['form_scripts']))
		$zz_conf['form_scripts']	= wrap_setting('custom').'/zzbrick_tables';
	
	// local pwd
	if (!wrap_setting('local_pwd'))
		if (file_exists(wrap_setting('cms_dir').'/pwd.json'))
			wrap_setting('local_pwd', wrap_setting('cms_dir').'/pwd.json');
		else
			wrap_setting('local_pwd', wrap_setting('cms_dir').'/../pwd.json');

	if (!is_dir(wrap_setting('tmp_dir'))) {
		$success = wrap_mkdir(wrap_setting('tmp_dir'));
		if (!$success) {
			wrap_error(sprintf('Temp directory %s does not exist.', wrap_setting('tmp_dir')));
			wrap_setting('tmp_dir', false);
			if ($dir = ini_get('upload_tmp_dir')) {
				if ($dir AND is_dir($dir)) wrap_setting('tmp_dir', $dir);
			}
		}
	}
	
	// -------------------------------------------------------------------------
	// Error Logging
	// -------------------------------------------------------------------------
	
	if (is_null(wrap_setting('error_mail_parameters')) AND wrap_setting('error_mail_from'))
		wrap_setting('error_mail_parameters', '-f '.wrap_setting('error_mail_from'));


	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------
	
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
	
	// Theme
	if (wrap_setting('active_theme'))
		wrap_package_activate(wrap_setting('active_theme'), 'theme');
	
	// -------------------------------------------------------------------------
	// Development server
	// -------------------------------------------------------------------------
	
	if (wrap_setting('local_access')) {
		wrap_setting('logout_inactive_after', wrap_setting('logout_inactive_after') * 20);
		wrap_setting('cache_age', 1);
		wrap_setting('gzip_encode', 0);
		wrap_setting('error_handling', 'output');
	}
	
	// -------------------------------------------------------------------------
	// Mail
	// -------------------------------------------------------------------------
	
	if (is_null(wrap_setting('mail_header_eol')))
		wrap_setting('mail_header_eol', "\r\n");


}

/**
 * tests some expected environment settings
 *
 * @return bool
 */
function wrap_tests() {
	// check if cache directory exists
	if (wrap_setting('cache')) {
		if (!file_exists(wrap_setting('cache_dir'))) {
			wrap_error(sprintf('Cache directory %s does not exist. Caching disabled.', 
				wrap_setting('cache_dir')), E_USER_WARNING);
			wrap_setting('cache', false);
		}
		// @todo not is writable
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
	if (!wrap_setting('dav_url')) return false;
	if (str_starts_with(wrap_setting('request_uri'), wrap_setting('dav_url'))) return true;
	return false;
}
