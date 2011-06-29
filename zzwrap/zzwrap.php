<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2011
// Main script


function zzwrap() {
	global $zz_setting;		// settings for zzwrap and zzbrick
	global $zz_conf;		// settings for zzform
	global $zz_page;		// page variables

	wrap_set_defaults();

	// include required files
	if (file_exists($zz_setting['inc'].'/config.inc.php'))
		require_once $zz_setting['inc'].'/config.inc.php'; 		// configuration
	if (empty($zz_setting['lib']))
		$zz_setting['lib']	= $zz_setting['inc'].'/library';
	if (empty($zz_setting['core']))
		$zz_setting['core'] = $zz_setting['lib'].'/zzwrap';
	require_once $zz_setting['core'].'/defaults.inc.php';	// set default variables
	require_once $zz_setting['core'].'/errorhandling.inc.php';	// CMS errorhandling
	require_once $zz_setting['db_inc']; // Establish database connection
	require_once $zz_setting['core'].'/core.inc.php';	// CMS core scripts
	require_once $zz_setting['core'].'/language.inc.php';	// CMS language
	require_once $zz_setting['core'].'/page.inc.php';	// CMS page scripts

	// check HTTP request, build URL, set language according to URL and request
	wrap_check_request(); // affects $zz_page, $zz_setting

	// Errorpages
	if (!empty($_GET['code'])) {
		wrap_errorpage(array(), $zz_page);
		exit;
	}
	if (!empty($zz_conf['error_503'])) {
		// exit for maintenance reasons
		wrap_error($zz_conf['error_503'], E_USER_ERROR);
	}
	
	if (file_exists($zz_setting['custom_wrap_dir'].'/_functions.inc.php'))
		require_once $zz_setting['custom_wrap_dir'].'/_functions.inc.php';

	wrap_check_db_connection();

	// Secret Key für Vorschaufunktion, damit auch noch nicht zur
	// Veröffentlichung freigegebene Seiten angeschaut werden können.
	if (!empty($zz_setting['secret_key']) AND !wrap_rights('preview'))
		wrap_rights('preview', 'set', wrap_test_secret_key($zz_setting['secret_key']));

	$zz_page['db'] = wrap_look_for_page($zz_page);
	wrap_check_https($zz_page, $zz_setting);

	// Functions which may be needed for login
	if (file_exists($zz_setting['custom_wrap_dir'].'/start.inc.php'))
		require_once $zz_setting['custom_wrap_dir'].'/start.inc.php';
	
	if ($zz_setting['authentication_possible']) {
		require_once $zz_setting['core'].'/auth.inc.php';
		wrap_auth();
	}

	// TODO: check if we can start this earlier
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
	require_once $zz_conf['dir_inc'].'/numbers.inc.php';

	if (file_exists($zz_setting['custom_wrap_dir'].'/_settings_post_login.inc.php'))
		require_once $zz_setting['custom_wrap_dir'].'/_settings_post_login.inc.php';

	// on error exit, after all files are included
	// Falls kein Eintrag in Datenbank, Umleitungen pruefen, ggf. 404 Fehler ausgeben.
	if (!$zz_page['db']) wrap_quit();
	
	wrap_translate_page();
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
 * Default variables, pre config
 *
 * @global array $zz_setting
 * @global array $zz_conf
 */
function wrap_set_defaults() {
	global $zz_setting;
	global $zz_conf;

// -------------------------------------------------------------------------
// Main paths, should be set in paths.inc.php
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

	// HTTP_HOST, htmlspecialchars against XSS
	$zz_setting['hostname']		= htmlspecialchars($_SERVER['HTTP_HOST']);
	if (!$zz_setting['hostname']) $zz_setting['hostname'] = $_SERVER['SERVER_NAME'];

	// check if it's a local development server
	$zz_setting['local_access'] = (substr($zz_setting['hostname'], -6) == '.local' ? true : false);

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
	$zz_setting['brick_formatting_functions'] = array('markdown', 'datum_de', 
		'rawurlencode', 'wordwrap', 'nl2br');

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

// -------------------------------------------------------------------------
// Authentication
// -------------------------------------------------------------------------

	$zz_setting['login_url']	= '/login/';
	// minutes until you will be logged out automatically while inactive
	$zz_setting['logout_inactive_after'] = 30;
}

?>