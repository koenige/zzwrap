<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007
// Main script


// --------------------------------------------------------------------------
// Initalize parameters, set global variables
// --------------------------------------------------------------------------

global $zz_setting;		// settings for zzwrap and zzbrick
global $zz_conf;		// settings for zzform
global $zz_page;		// page variables
global $zz_access;		// access variables

// --------------------------------------------------------------------------
// Include required files
// --------------------------------------------------------------------------

if (empty($zz_setting['lib']))
	$zz_setting['lib']			= $zz_setting['inc'].'/library';
if (empty($zz_setting['core']))
	$zz_setting['core'] = $zz_setting['lib'].'/zzwrap';
require_once $zz_setting['core'].'/defaults.inc.php';	// set default variables
require_once $zz_setting['core'].'/errorhandling.inc.php';	// CMS errorhandling
require_once $zz_setting['db_inc']; // Establish database connection
require_once $zz_setting['core'].'/core.inc.php';	// CMS core scripts
require_once $zz_setting['core'].'/page.inc.php';	// CMS page scripts
if (!empty($zz_conf['error_503'])) wrap_error($zz_conf['error_503'], E_USER_ERROR);	// exit for maintenance reasons

if (file_exists($zz_setting['custom_wrap_dir'].'/_functions.inc.php'))
	require_once $zz_setting['custom_wrap_dir'].'/_functions.inc.php';

// --------------------------------------------------------------------------
// Test HTTP REQUEST method
// --------------------------------------------------------------------------

wrap_check_http_request_method();

// --------------------------------------------------------------------------
// Get rid of unwanted query strings
// --------------------------------------------------------------------------

wrap_remove_query_strings();

// --------------------------------------------------------------------------
// Request page from database via URL
// Abfrage der Seite nach URL in der Datenbank
// --------------------------------------------------------------------------

wrap_check_db_connection();

// Secret Key für Vorschaufunktion, damit auch noch nicht zur
// Veröffentlichung freigegebene Seiten angeschaut werden können.
if (!empty($zz_setting['secret_key']) AND empty($zz_access['wrap_preview']))
	$zz_access['wrap_preview'] = wrap_test_secret_key($zz_setting['secret_key']);

// Sprachcode etc. steht evtl. in URL
if ($zz_conf['translations_of_fields']) {
	$zz_page['url']['full'] = wrap_prepare_url($zz_page['url']['full']);
}
// Eintrag in Datenbank finden, nach URL
// wenn nicht gefunden, dann URL abschneiden, Sternchen anfuegen und abgeschnittene
// Werte in Parameter-Array schreiben
$zz_page['db'] = wrap_look_for_page($zz_conf, $zz_access, $zz_page);

// Check whether we need HTTPS or not, redirect if necessary
wrap_check_https($zz_page, $zz_setting);

// --------------------------------------------------------------------------
// include modules
// --------------------------------------------------------------------------

// Functions which may be needed for login
if (file_exists($zz_setting['custom_wrap_dir'].'/start.inc.php'))
	require_once $zz_setting['custom_wrap_dir'].'/start.inc.php';

// modules may change page language ($page['lang']), so include language functions here
require_once $zz_setting['core'].'/language.inc.php';	// CMS language
if ($zz_setting['authentification_possible']) {
	require_once $zz_setting['core'].'/auth.inc.php';	// CMS authentification
	wrap_auth();
}

// Caching?
if (!empty($zz_setting['cache']) AND !empty($zz_setting['cache_age'])
	AND empty($_SESSION) AND empty($_POST)) { // TODO: check if we can start this earlier
	wrap_send_cache($zz_setting['cache_age']);
}

// include standard functions (e. g. markup languages)
// Standardfunktionen einbinden (z. B. Markup-Sprachen)
if (!empty($zz_setting['standard_extensions']))	
	foreach ($zz_setting['standard_extensions'] as $function)
		require_once $zz_setting['lib'].'/'.$function.'.php';
require_once $zz_conf['dir_inc'].'/numbers.inc.php';
if (file_exists($zz_setting['custom_wrap_dir'].'/_settings_post_login.inc.php'))
	require_once $zz_setting['custom_wrap_dir'].'/_settings_post_login.inc.php';

// --------------------------------------------------------------------------
// on error exit, after all files are included
// --------------------------------------------------------------------------

// Falls kein Eintrag in Datenbank, Umleitungen pruefen, ggf. 404 Fehler ausgeben.
if (!$zz_page['db']) wrap_quit();

// --------------------------------------------------------------------------
// puzzle page elements together
// --------------------------------------------------------------------------

// translate page (that was not possible in wrap_look_for_page() because we
// did not have complete language information then.
if ($zz_conf['translations_of_fields']) {
	$my_page = wrap_translate(array(
		$zz_page['db'][wrap_sql('page_id')] => $zz_page['db']), 
		wrap_sql('translation_matrix_pages'));
	$zz_page['db'] = array_shift($my_page);
	unset($my_page);
}

require_once $zz_setting['lib'].'/zzbrick/zzbrick.php';
$page = brick_format($zz_page['db'][wrap_sql('content')], $zz_page['db']['parameter']);

if (empty($page)) wrap_quit();
if (!empty($page['error']['level'])) {
	if (!empty($page['error']['msg_text']) AND !empty($page['error']['msg_vars'])) {
		$msg = vsprintf(wrap_text($page['error']['msg_text']), $page['error']['msg_vars']);
	} elseif (!empty($page['error']['msg_text'])) {
		$msg = wrap_text($page['error']['msg_text']);
	} else {
		$msg = wrap_text('zzbrick returned with an error. Sorry, that\'s all we know.');
	}
	wrap_error($msg, $page['error']['level']);
}
if ($page['status'] != 200) {
	wrap_quit($page['status']);
}
if (!empty($page['content_type']) AND $page['content_type'] != 'html') {
	wrap_send_ressource($page['text'], $page['content_type'], $page['status']);
}
if (!empty($page['no_output'])) exit;

$page['status'] = 200; // Seiteninhalt vorhanden!

// if database allows field 'ending', check if the URL is canonical
if (!empty($zz_page['db'][wrap_sql('ending')])) {
	$ending = $zz_page['db'][wrap_sql('ending')];
	// if brick_format() returns a page ending, use this
	if (isset($page['url_ending'])) $ending = $page['url_ending'];
	wrap_check_canonical($ending, $zz_page['url']['full']);
}

$page['media'] = wrap_page_media($page);
// set HTML language code if not set so far
if (!isset($page['lang'])) $page['lang'] = $zz_setting['lang'];
$page['title'] = wrap_page_h1($page);
if (empty($page['project'])) $page['project'] = $zz_conf['project'];
$page['pagetitle'] = wrap_page_title($page);
$page[wrap_sql('lastupdate')] = wrap_page_last_update($page);

// authors (from cmscore/page.inc.php)
if (!empty($zz_page['db'][wrap_sql('author_id')]))
	$page['authors'] = wrap_get_authors($page['authors'], $zz_page['db'][wrap_sql('author_id')]);

// navigation menu (from cmscore/page.inc.php)
if (wrap_sql('menu')) {
	$page['nav_db'] = wrap_get_menu();
}

// output of content
if ($zz_setting['brick_page_templates'] == true) {
	// use wrap templates
	wrap_htmlout_page($page);
} else {
	wrap_htmlout_page_without_templates($page);
}
exit;

?>