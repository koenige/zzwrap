<?php 

/**
 * zzwrap
 * Main function
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2025 Gustaf Mossakowski
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
	wrap_defaults();
	wrap_http_restrict_ip();
	wrap_includes_postconf();
	wrap_mail_queue_send(); // @todo allow this to be done via cron job for better performance
	wrap_validate_post();

	// make all _function files available, check_request() might already quit and needs a page
	wrap_include('_functions', 'custom/modules/themes');

	// check HTTP request, build URL, set language according to URL and request
	wrap_http_check_request();
	wrap_url_prepare(); // affects $zz_page
	// check language
	wrap_language_set();
	wrap_url_match();
	// Relative linking
	wrap_url_relative();

	// page offline?
	if (wrap_setting('site_offline')) {
		if ($tpl = wrap_setting('site_offline_template')) {
			wrap_setting('template', $tpl);
		}
		wrap_quit(503, wrap_text('This page is currently offline.'));
		exit;
	}

	wrap_tests();
	if (wrap_setting('cache_age'))
		wrap_send_cache(wrap_setting('cache_age'), false);

	// establish database connection
	wrap_db_connect();
	if (!wrap_setting('db_name')) {
		require_once __DIR__.'/install.inc.php';
		wrap_install();
	}

	wrap_config_write();
	if (wrap_setting('multiple_websites'))
		wrap_config_write(wrap_setting('site'));

	// errorpages
	// only if accessed without rewriting, 'code' may be used as a query string
	// from different functions as well
	if (!empty($_GET['code'])
		AND str_starts_with($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'])) {
		wrap_defaults_post_match();
		wrap_errorpage([], $zz_page);
		exit;
	}

	// do not check if database connection is established until now
	// to avoid infinite recursion due to calling the error page
	wrap_check_db_connection();
	
	$zz_page['db'] = wrap_match_page($zz_page);
	wrap_defaults_post_match();
	wrap_language_redirect();

	// Functions which might be executed always, before possible login
	wrap_include('start');
	
	if (wrap_setting('authentication_possible')) {
		wrap_auth();
		wrap_access_page($zz_page['db']['parameters'] ?? []);
	}
	wrap_https_check($zz_page);

	// include standard functions (e. g. markup languages)
	// Standardfunktionen einbinden (z. B. Markup-Sprachen)
	wrap_lib();

	wrap_include('_settings_post_login');

	// on error exit, after all files are included, check
	// 1. well known URLs, 2. template files, 3. redirects
	if (!$zz_page['db']) {
		$zz_page = wrap_match_ressource($zz_page);
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
	require_once __DIR__.'/files.inc.php';
	if (file_exists(__DIR__.'/compatibility.inc.php'))
		require_once __DIR__.'/compatibility.inc.php';

	wrap_include('errorhandling', '_core');
	wrap_include('database', '_core');
	wrap_include('core', '_core');
	wrap_include('settings', '_core');
	wrap_include('cache', '_core');
	wrap_include('access', '_core');
	wrap_include('language', '_core');
	wrap_include('match', '_core');
	wrap_include('template', '_core');
	wrap_include('defaults', '_core');
	wrap_include('http', '_core');
	wrap_include('url', '_core');
	wrap_include('send', '_core');
	wrap_include('session', '_core');
	wrap_include('background', '_core');
	wrap_include('mail', '_core');
	wrap_include('format', '_core');
	wrap_include('page', '_core');
}

/**
 * includes required files for zzwrap
 */
function wrap_includes_postconf() {
	if (wrap_setting('authentication_possible'))
		wrap_include('auth', '_core');
}
