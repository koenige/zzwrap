<?php 

/**
 * zzwrap
 * Main function
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2023 Gustaf Mossakowski
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
	global $zz_page;		// page variables

	wrap_includes();
	set_error_handler('wrap_error_handler');
	register_shutdown_function('wrap_shutdown');
	wrap_set_defaults();
	wrap_restrict_ip();
	wrap_includes_postconf();
	wrap_mail_queue_send(); // @todo allow this to be done via cron job for better performance

	// establish database connection
	wrap_db_connect();
	if (!wrap_setting('db_name')) {
		require_once __DIR__.'/install.inc.php';
		wrap_install();
	}

	wrap_tests();
	wrap_config('write');
	if (wrap_setting('multiple_websites'))
		wrap_config('write', wrap_setting('site'));

	// check HTTP request, build URL, set language according to URL and request
	wrap_check_request(); // affects $zz_page

	// errorpages
	// only if accessed without rewriting, 'code' may be used as a query string
	// from different functions as well
	if (!empty($_GET['code'])
		AND str_starts_with($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'])) {
		wrap_errorpage([], $zz_page);
		exit;
	}
	
	wrap_include_files('_functions', 'custom/modules/themes');

	// do not check if database connection is established until now
	// to avoid infinite recursion due to calling the error page
	wrap_check_db_connection();
	
	// page offline?
	if (wrap_setting('site_offline')) {
		if ($tpl = wrap_setting('site_offline_template')) {
			wrap_setting('template', $tpl);
		}
		wrap_quit(503, wrap_text('This page is currently offline.'));
		exit;
	}

	// Secret Key für Vorschaufunktion, damit auch noch nicht zur
	// Veröffentlichung freigegebene Seiten angeschaut werden können.
	if (wrap_setting('secret_key') AND !wrap_rights('preview'))
		wrap_rights('preview', 'set', wrap_test_secret_key(wrap_setting('secret_key')));

	$zz_page['db'] = wrap_look_for_page($zz_page);
	wrap_language_redirect();

	// Functions which might be executed always, before possible login
	wrap_include_files('start');
	
	if (wrap_setting('authentication_possible')) {
		wrap_auth();
		wrap_access_page($zz_page['db']['parameters'] ?? '');
	}
	wrap_check_https($zz_page);

	// @todo check if we can start this earlier
	if (wrap_setting('cache_age'))
		wrap_send_cache(wrap_setting('cache_age'));

	// include standard functions (e. g. markup languages)
	// Standardfunktionen einbinden (z. B. Markup-Sprachen)
	wrap_lib();

	wrap_include_files('_settings_post_login');

	// on error exit, after all files are included, check
	// 1. well known URLs, 2. template files, 3. redirects
	if (!$zz_page['db']) {
		$zz_page = wrap_ressource_by_url($zz_page);
	}
	
	wrap_set_encoding(wrap_setting('character_set'));
	wrap_translate_page();
	wrap_set_units();
	if (!empty($_SESSION['logged_in'])) session_write_close();
	$page = wrap_get_page();
	$page = wrap_page_defaults($page);
	
	// output of content if not already sent by wrap_get_page()
	wrap_htmlout_page($page);
	exit;
}

/**
 * includes required files for zzwrap
 */
function wrap_includes() {
	// function library scripts
	require_once __DIR__.'/errorhandling.inc.php';
	require_once __DIR__.'/database.inc.php';
	require_once __DIR__.'/core.inc.php';
	require_once __DIR__.'/mail.inc.php';
	require_once __DIR__.'/access.inc.php';
	require_once __DIR__.'/language.inc.php';
	require_once __DIR__.'/page.inc.php';
	require_once __DIR__.'/format.inc.php';
	require_once __DIR__.'/defaults.inc.php';
	require_once __DIR__.'/files.inc.php';
}

/**
 * includes required files for zzwrap
 */
function wrap_includes_postconf() {
	if (wrap_setting('authentication_possible'))
		require_once __DIR__.'/auth.inc.php';
}

/**
 * if page is not found, after all files are included,
 * check 1. well known URLs, 2. template files, 3. redirects
 *
 * @param array $zz_page
 * @param bool $quit (optional) true: call wrap_quit(), false: just return
 * @return array
 */
function wrap_ressource_by_url($zz_page, $quit = true) {
	$well_known = wrap_well_known_url($zz_page['url']['full']);
	if ($well_known) {
		$zz_page['well_known'] = $well_known;
	} else {
		$zz_page['tpl_file'] = wrap_look_for_file($zz_page['url']['full']['path']);
		if (!$zz_page['tpl_file'] AND $quit) wrap_quit();
		if (!empty($_GET['lang']) AND in_array($_GET['lang'], array_keys(wrap_id('languages', '', 'list'))))
			wrap_setting('lang', $_GET['lang']);
	}
	return $zz_page;
}
