<?php 

/**
 * zzwrap
 * Default variables, post config
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2008-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * sets defaults for zzwrap, includes config
 *
 * @global array $zz_setting
 * @global array $zz_conf		might change in config.inc.php
 * @global array $zz_page		might change in config.inc.php
 */
function wrap_defaults() {
	global $zz_setting;
	global $zz_conf;
	global $zz_page;

	// configuration settings, defaults
	wrap_defaults_paths();
	wrap_defaults_auto();
	wrap_defaults_pre_conf();
	wrap_config_read();
	if (file_exists($file = wrap_setting('inc').'/config.inc.php'))
		require_once $file;
	wrap_defaults_post_conf();

	// module configs, will overwrite standard config
	wrap_include('./config', 'modules');
}

/**
 * default variables: paths
 *
 * @global array $zz_setting
 */
function wrap_defaults_paths() {
	global $zz_setting;

// -------------------------------------------------------------------------
// Main paths, deviations should be defined in main.php
// -------------------------------------------------------------------------
	
	// web root directory
	if (!isset($zz_setting['root_dir']))
		$zz_setting['root_dir'] = $_SERVER['DOCUMENT_ROOT'];
	if (str_ends_with($zz_setting['root_dir'], '/'))
		$zz_setting['root_dir'] = substr($zz_setting['root_dir'], 0, -1);

	// includes
	if (!isset($zz_setting['cms_dir'])) {
		$dir = explode('/', __DIR__);
		$dir = array_slice($dir, 0, -4); // _inc/modules/zzwrap/zzwrap
		$zz_setting['cms_dir'] = implode('/', $dir);
	}
	if (!isset($zz_setting['inc']))
		$zz_setting['inc'] = $zz_setting['cms_dir'].'/_inc';
	$zz_setting['inc'] = realpath($zz_setting['inc']);
	if (!$zz_setting['inc']) {
		echo 'Missing correct `cms_dir`, please set it.';
		exit;
	}

// -------------------------------------------------------------------------
// System paths
// -------------------------------------------------------------------------

	$zz_setting['custom'] 	= $zz_setting['inc'].'/custom';
	$zz_setting['modules_dir'] = $zz_setting['inc'].'/modules';
	$zz_setting['themes_dir'] = $zz_setting['inc'].'/themes';
	
	$zz_setting['modules'] = wrap_packages('modules', $zz_setting['modules_dir']);
	$zz_setting['themes'] = wrap_packages('themes', $zz_setting['themes_dir']);

	// now we can use wrap_setting()
}

/**
 * auto intialise config variables from settings.cfg
 *
 */
function wrap_defaults_auto() {
	$cfg = wrap_cfg_files('settings');
	$settings = [];
	foreach ($cfg as $setting => $definition) {
		if (empty($definition['auto_init'])) continue;
		if (empty($definition['default'])) continue;
		$settings[$setting] = $definition['default'];
	}
	wrap_setting_register($settings);
}

/**
 * Default variables, pre config
 *
 */
function wrap_defaults_pre_conf() {
	wrap_setting('zzwrap_id', rand());

// -------------------------------------------------------------------------
// Hostname
// -------------------------------------------------------------------------

	// HTTP_HOST, check against XSS
	if (!empty($_SERVER['HTTP_X_FORWARDED_SERVER'])
		AND in_array(wrap_url_dev_remove($_SERVER['HTTP_X_FORWARDED_SERVER'], false), wrap_setting('forwarded_hostnames')))
		wrap_setting('hostname', $_SERVER['HTTP_X_FORWARDED_SERVER']);
	elseif (!empty($_SERVER['HTTP_HOST']) AND preg_match('/^[a-zA-Z0-9-\.]+$/', $_SERVER['HTTP_HOST']))
		wrap_setting('hostname', $_SERVER['HTTP_HOST']);
	else
		wrap_setting('hostname', $_SERVER['SERVER_NAME']);
	// fully-qualified (unambiguous) DNS domain names have a dot at the end
	// we better not redirect these to a domain name without a dot to avoid
	// ambiguity, but we do not need to do double caching etc.
	if (substr(wrap_setting('hostname'), -1) === '.')
		wrap_setting('hostname', substr(wrap_setting('hostname'), 0, -1));
	// in case, somebody's doing a CONNECT or something similar, use some default
	if (!wrap_setting('hostname')) 
		wrap_setting('hostname', 'www.example.org');
	// make hostname lowercase to avoid duplicating caches
	wrap_setting('hostname', strtolower(wrap_setting('hostname')));

	// check if it’s a local development server
	// get site name without www. and .local
	wrap_setting('local_access', false);
	wrap_setting('site', wrap_setting('hostname'));
	if (str_starts_with(wrap_setting('site'), 'www.'))
		wrap_setting('site', substr(wrap_setting('site'), 4));
	$site = wrap_url_dev_remove(wrap_setting('site'), false);
	if ($site !== wrap_setting('site')) {
		wrap_setting('site', $site);
		wrap_setting('local_access', true);
	}

	wrap_setting('request_uri', $_SERVER['REQUEST_URI']);
	wrap_setting('remote_ip', wrap_http_remote_ip());

// -------------------------------------------------------------------------
// Error Logging, Mail
// -------------------------------------------------------------------------

	if (!wrap_setting('local_access'))
		// just in case it's a bad ISP and php.ini must not be changed
		@ini_set('display_errors', 0);
}

/**
 * register all packages (modules and themes) of installation
 *
 * @param string $type
 * @param string $folder
 * @return array
 */
function wrap_packages($type, $folder) {
	if (!is_dir($folder)) return [];
	$packages = scandir($folder);
	foreach ($packages as $index => $package) {
		if (str_starts_with($package, '.') OR !is_dir($folder.'/'.$package)) {
			unset($packages[$index]);
			continue;
		}
	}
	// some hosters sort files in reverse order
	sort($packages);

	// put default module always on top to have the possibility to
	// add functions with the same name in other modules
	if ($type === 'modules' AND $key = array_search('default', $packages)) {
		unset($packages[$key]);
		array_unshift($packages, 'default');
	}
	return $packages;
}

/**
 * read all configuration files
 *
 */
function wrap_config_read() {
	wrap_config_read_file(wrap_config_filename());

	// per site
	if (wrap_setting('multiple_websites'))
		wrap_config_read_file(wrap_config_filename('site'));

	// per module
	$files = wrap_collect_files('configuration/modules.json', 'custom/modules');
	foreach ($files as $file)
		wrap_config_read_file($file);

	$files = wrap_collect_files('configuration/modules.cfg', 'custom/modules');
	foreach ($files as $file)
		wrap_config_read_file($file);
}

/**
 * read configuration from JSON file
 *
 * @param string $file path to file
 * @return void
 */
function wrap_config_read_file($file) {
	if (!file_exists($file)) return;
	
	if (str_ends_with($file, '.json')) {
		$config = file_get_contents($file);
		$config = json_decode($config, true);
	} elseif (str_ends_with($file, '.cfg')) {
		$config = parse_ini_file($file);
	}
	if (!$config) return;
	
	wrap_setting_register($config);
}

/**
 * write configuration to JSON file
 *
 * @param string $site (optional)
 * @return void
 */
function wrap_config_write($site = '') {
	static $website_checked = false;
	if (!wrap_db_connection()) return;

	$re_read_config = false;
	if ($site AND !$website_checked AND wrap_database_table_check('websites')) {
		$website_checked = true;

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
			if (!$website AND wrap_setting('website_id_default')) {
				$sql = 'SELECT website_id, domain
					FROM websites
					WHERE website_id = /*_SETTING website_id_default _*/';
				$website = wrap_db_fetch($sql);
			}
			if ($website) {
				// different site, read config for this site
				$website_id = $website['website_id'];
				wrap_setting('site', $website['domain']);
				$re_read_config = true;
			}
		}
	}

	if (!wrap_database_table_check('_settings', true)) return;

	$file = wrap_config_filename($site ? 'site' : 'main');
	$existing_config = file_exists($file) ? file_get_contents($file) : [];
	
	if (wrap_setting('multiple_websites')) {
		if (empty($website_id)) $website_id = 1;
		wrap_setting('website_id', $website_id);
		$sql = sprintf('SELECT setting_key, setting_value
			FROM /*_PREFIX_*/_settings
			WHERE website_id = %d
			ORDER BY setting_key', $website_id);
	} else {
		$sql = 'SELECT setting_key, setting_value
			FROM /*_PREFIX_*/_settings ORDER BY setting_key';
	}
	$settings = wrap_db_fetch($sql, '_dummy_', 'key/value');
	if (!$settings) return;
	$new_config = json_encode($settings, JSON_PRETTY_PRINT + JSON_NUMERIC_CHECK);
	if ($new_config !== $existing_config)
		file_put_contents($file, $new_config);
	if ($re_read_config)
		wrap_config_read_file($file);
}

/**
 * get filename for configuration file
 *
 * @param string $type
 * @return string
 */
function wrap_config_filename($type = 'main') {
	switch ($type) {
		case 'main':
			$template = '%s/config.json';
			$deprecated = sprintf($template, wrap_setting('inc'));
			break;
		case 'site':
			$template = '%%s/config-%s.json';
			$template = sprintf($template, str_replace('/', '-', wrap_setting('site')));
			$deprecated = sprintf($template, wrap_setting('log_dir'));
			break;
		case 'pwd':
		default:
			if (!str_starts_with($type, 'pwd')) return '';
			$template = '%%s/%s.json';
			$template = sprintf($template, $type);
			$deprecated = sprintf($template, wrap_setting('custom').'/zzwrap_sql');
			break;
	}
	$preferred = sprintf($template, wrap_setting('config_dir'));
	if (file_exists($deprecated)) {
		wrap_mkdir(dirname($preferred));
		rename($deprecated, $preferred);
	}
	return $preferred;
}

/**
 * Default variables, post config
 *
 */
function wrap_defaults_post_conf() {
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
	// Background jobs
	// -------------------------------------------------------------------------

	if (!empty($_SERVER['HTTP_X_TIMEOUT_IGNORE'])) {
		ignore_user_abort(true);
		set_time_limit(0);
		wrap_setting('background_job', 1);
	}

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
	// connections from local don’t need to go via https
	// makes it easier for some things
	if (wrap_http_localhost_ip()) wrap_setting('ignore_scheme', true);

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
 * Default variables, post page matching
 * for parameters that might get changed via webpages.parameters (page_parameter = 1)
 *
 */
function wrap_defaults_post_match() {
	// Theme
	if (wrap_setting('active_theme'))
		wrap_package_activate(wrap_setting('active_theme'), 'theme');
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
 * @return bool
 */
function wrap_is_dav_url() {
	if (!wrap_setting('dav_url')) return false;
	if (str_starts_with(wrap_setting('request_uri'), wrap_setting('dav_url'))) return true;
	return false;
}
