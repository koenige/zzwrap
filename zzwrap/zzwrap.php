<?php 

/**
 * zzwrap
 * Main function
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2014 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Main function of zzwrap scripts, includes all required files, gets data
 * for web ressource from different sources, caches ressource and sends it
 * to the browser
 *
 * @param void
 * @return void
 */
function zzwrap() {
	global $zz_setting;		// settings for zzwrap and zzbrick
	global $zz_conf;		// settings for zzform
	global $zz_page;		// page variables

	wrap_set_defaults();
	wrap_includes();
	wrap_tests();

	// establish database connection
	wrap_db_connect();

	// local modifications to SQL queries
	// may need db connection
	wrap_sql('core', 'set');
	wrap_sql('page', 'set');
	
	// check HTTP request, build URL, set language according to URL and request
	wrap_check_request(); // affects $zz_page, $zz_setting

	// errorpages
	if (!empty($_GET['code'])) {
		wrap_errorpage(array(), $zz_page);
		exit;
	}
	
	if (file_exists($file = $zz_setting['custom_wrap_dir'].'/_functions.inc.php')) {
		require_once $file;
	}
	foreach ($zz_setting['modules'] as $module) {
		if (file_exists($file = $zz_setting['modules_dir'].'/'.$module.'/zzwrap/_functions.inc.php')) {
			require_once $file;
		}
	}

	// do not check if database connection is established until now
	// to avoid infinite recursion due to calling the error page
	wrap_check_db_connection();

	// Secret Key für Vorschaufunktion, damit auch noch nicht zur
	// Veröffentlichung freigegebene Seiten angeschaut werden können.
	if (!empty($zz_setting['secret_key']) AND !wrap_rights('preview'))
		wrap_rights('preview', 'set', wrap_test_secret_key($zz_setting['secret_key']));

	$zz_page['db'] = wrap_look_for_page($zz_page);

	// Functions which might be executed always, before possible login
	if (file_exists($zz_setting['custom_wrap_dir'].'/start.inc.php'))
		require_once $zz_setting['custom_wrap_dir'].'/start.inc.php';
	
	if ($zz_setting['authentication_possible']) {
		wrap_auth();
	}
	wrap_check_https($zz_page, $zz_setting);

	// @todo: check if we can start this earlier
	if (!empty($zz_setting['cache_age'])) {
		wrap_send_cache($zz_setting['cache_age']);
	}

	// include standard functions (e. g. markup languages)
	// Standardfunktionen einbinden (z. B. Markup-Sprachen)
	if (!empty($zz_setting['standard_extensions']))	{
		foreach ($zz_setting['standard_extensions'] as $function) {
			if (file_exists($zz_setting['lib'].'/'.$function.'.php')) 
				require_once $zz_setting['lib'].'/'.$function.'.php';
			elseif (file_exists($zz_setting['lib'].'/'.$function.'/'.$function.'.php'))
				require_once $zz_setting['lib'].'/'.$function.'/'.$function.'.php';
			else
				wrap_error(sprintf(wrap_text('Required library %s does not exist.'), '`'.$function.'`'), E_USER_ERROR);
		}
	}

	if (file_exists($zz_setting['custom_wrap_dir'].'/_settings_post_login.inc.php'))
		require_once $zz_setting['custom_wrap_dir'].'/_settings_post_login.inc.php';

	// on error exit, after all files are included, check redirects
	// Falls kein Eintrag in Datenbank, Umleitungen pruefen, ggf. 404 Fehler ausgeben.
	if (!$zz_page['db']) wrap_quit();
	
	wrap_translate_page();
	wrap_set_units();
	$page = wrap_get_page();
	
	// output of content if not already sent by wrap_get_page()
	if ($zz_setting['brick_page_templates'] == true) {
		wrap_htmlout_page($page);
	} else {
		wrap_htmlout_page_without_templates($page);
	}
	exit;
}

/**
 * includes required files for zzwrap
 */
function wrap_includes() {
	global $zz_setting;

	// function libraries
	require_once $zz_setting['core'].'/errorhandling.inc.php';
	require_once $zz_setting['core'].'/database.inc.php';
	require_once $zz_setting['core'].'/core.inc.php';
	require_once $zz_setting['core'].'/mail.inc.php';
	require_once $zz_setting['core'].'/access.inc.php';
	require_once $zz_setting['core'].'/language.inc.php';
	require_once $zz_setting['core'].'/page.inc.php';
	require_once $zz_setting['core'].'/format.inc.php';
	if ($zz_setting['authentication_possible']) {
		require_once $zz_setting['core'].'/auth.inc.php';
	}
}

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
	if (file_exists($zz_setting['inc'].'/config.inc.php'))
		require_once $zz_setting['inc'].'/config.inc.php';
	if (empty($zz_setting['lib']))
		$zz_setting['lib']	= $zz_setting['inc'].'/library';
	if (empty($zz_setting['core']))
		$zz_setting['core'] = $zz_setting['lib'].'/zzwrap';
	require_once $zz_setting['core'].'/defaults.inc.php';
	wrap_set_defaults_post_conf();
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
	// includes
	if (!isset($zz_setting['inc']))
		$zz_setting['inc'] = $zz_conf['root'].'/../_inc';

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
		$zz_setting['hostname'] == 'www.example.org';
	// make hostname lowercase to avoid duplicating caches
	$zz_setting['hostname'] = strtolower($zz_setting['hostname']);

	// check if it's a local development server
	$zz_setting['local_access'] = (substr($zz_setting['hostname'], -6) == '.local') ? true : false;

	// base URL, e. g. for languages
	$zz_setting['base'] = '';

// -------------------------------------------------------------------------
// URLs
// -------------------------------------------------------------------------

	$zz_setting['homepage_url']	= '/';
	$zz_setting['login_entryurl'] = '/';

// -------------------------------------------------------------------------
// Paths
// -------------------------------------------------------------------------

	// Caching	
	$zz_setting['cache']		= $zz_conf['root'].'/../_cache';
	$zz_setting['cache_age']	= 10;
	if ($zz_setting['local_access']) {
		$zz_setting['cache_age']	= 1;
	}

	// Media
	$zz_setting['media_folder']	= $zz_conf['root'].'/../files';

	// Forms: zzform upload module
	$zz_conf['tmp_dir']			= $zz_conf['root'].'/../_temp';
	$zz_conf['backup']			= true;
	$zz_conf['backup_dir']		= $zz_conf['root'].'/../_backup';

// -------------------------------------------------------------------------
// Modules
// -------------------------------------------------------------------------

	// modules
	$zz_setting['standard_extensions'][] = 'markdown-extra';

	// Forms
	$zz_conf['ext_modules']		= array('markdown-extra');

	// Forms: zzform upload module
	$zz_conf['graphics_library'] = 'imagemagick';

// -------------------------------------------------------------------------
// Page
// -------------------------------------------------------------------------

	// Use redirects table / Umleitungs-Tabelle benutzen
	$zz_setting['check_redirects'] = true;

	// zzbrick: brick types
	$zz_setting['brick_types_translated']['tables'] = 'forms';

	// zzbrick: use brick page templates
	// allow %%% page ... %%%-syntax
	$zz_setting['brick_page_templates'] = true;
	$zz_setting['brick_fulltextformat'] = 'markdown';
	// functions that might be used for formatting (zzbrick)
	$zz_setting['brick_formatting_functions'] = array('markdown', 'wrap_date', 
		'rawurlencode', 'wordwrap', 'nl2br', 'htmlspecialchars');

	if (!$zz_setting['local_access']) {
		$zz_setting['gzip_encode'] = true;
	}

// -------------------------------------------------------------------------
// Database structure
// -------------------------------------------------------------------------

	$zz_conf['prefix']			= ''; // prefix for all database tables
	$zz_conf['logging']			= true;
	$zz_conf['logging_id']		= true;

// -------------------------------------------------------------------------
// Error Logging
// -------------------------------------------------------------------------

	$zz_conf['error_mail_level'] = array('error', 'warning');
	$zz_conf['error_handling']	= 'mail';
	if ($zz_setting['local_access']) {
		$zz_conf['error_handling']	= 'output';
	}
	if (!$zz_setting['local_access']) {
		// just in case it's a bad ISP and php.ini must not be changed
		@ini_set('display_errors', 0);
	}

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

	$zz_conf['character_set'] = 'utf-8';

}
