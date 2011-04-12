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

if (!in_array($_SERVER['REQUEST_METHOD'], $zz_setting['http']['allowed'])) {
	if (!in_array($_SERVER['REQUEST_METHOD'], $zz_setting['http']['not_allowed'])) {
		wrap_quit(405);	// 405 Not Allowed
	} else {
		wrap_quit(501); // 501 Not Implemented
	}
}

// --------------------------------------------------------------------------
// Get rid of unwanted query strings
// --------------------------------------------------------------------------

// since we do not use session-IDs in the URL, get rid of these since sometimes
// they might be used for session_start()
// e. g. GET http://example.com/?PHPSESSID=5gh6ncjh00043PQTHTTGY%40DJJGV%5D
if (!empty($_GET['PHPSESSID'])) unset($_GET['PHPSESSID']);
if (!empty($_REQUEST['PHPSESSID'])) unset($_REQUEST['PHPSESSID']);

// --------------------------------------------------------------------------
// Request page from database via URL
// Abfrage der Seite nach URL in der Datenbank
// --------------------------------------------------------------------------

// Do we have a database connection?
if (!$zz_conf['db_connection']) {
	if (!empty($zz_setting['cache'])) wrap_send_cache();
	wrap_error(sprintf('No connection to SQL server.'), E_USER_ERROR);
}

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
		$zz_translation_matrix['pages']);
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

// get media
if (function_exists('wrap_get_media'))
	$media = wrap_get_media($zz_page['db'][wrap_sql('page_id')]);
if (empty($page['media']) AND !empty($media))
	$page['media'] = $media;
elseif (!empty($page['media']) AND !empty($media))
	$page['media'] = array_merge($media, $page['media']);
elseif (empty($page['media']) AND empty($media))
	$page['media'] = false;

// set HTML language code if not set so far
if (!isset($page['lang'])) $page['lang'] = $zz_setting['lang'];

// $page['title'] == H1 element
// default: from brick script, 2nd choice: database
if (empty($page['title'])) $page['title'] = $zz_page['db'][wrap_sql('title')];

// $page['pagetitle'] TITLE element
$page['pagetitle'] = strip_tags($page['title']);
if (!empty($zz_setting['translate_page_title']))
	$page['title'] = wrap_text($page['title']);
if (empty($page['project'])) $page['project'] = $zz_conf['project'];

if ($zz_page['url']['full']['path'] == '/')
	$page['pagetitle'] = sprintf($zz_page['template_pagetitle_home'], $page['pagetitle'], $page['project']);
else
	$page['pagetitle'] = sprintf($zz_page['template_pagetitle'], $page['pagetitle'], $page['project']);

// last update
if (!empty($page['last_update'])) $page[wrap_sql('lastupdate')] = $page['last_update'];
if (empty($page[wrap_sql('lastupdate')])) {
	$page[wrap_sql('lastupdate')] = $zz_page['db'][wrap_sql('lastupdate')];
	$page[wrap_sql('lastupdate')] = datum_de($page[wrap_sql('lastupdate')]);
}

// breadcrumbs (from cmscore/page.inc.php)
if (wrap_sql('breadcrumbs'))
	$page['breadcrumbs'] = wrap_htmlout_breadcrumbs($zz_page['db'][wrap_sql('page_id')], $page['breadcrumbs']);

// authors (from cmscore/page.inc.php)
if (!empty($zz_page['db'][wrap_sql('author_id')]))
	$page['authors'] = wrap_get_authors($page['authors'], $zz_page['db'][wrap_sql('author_id')]);

// navigation menu (from cmscore/page.inc.php)
if (wrap_sql('menu')) {
	$page['nav_db'] = wrap_get_menu();
	if ($page['nav_db']) $page['nav'] = wrap_htmlout_menu($page['nav_db']);
}

// output of content
if ($zz_setting['brick_page_templates'] == true) {
	// use wrap templates
	wrap_htmlout_page($page);
} else {
	// DEPRECATED!
	// classic: mix of HTML and PHP
	$output = '';
	if (function_exists('wrap_matrix')) {
		// Matrix for several projects
		$output = wrap_matrix($page, $page['media']);
	} else {
		if (empty($page['dont_show_h1']) AND empty($zz_page['dont_show_h1']))
			$output .= "\n".markdown('# '.$page['title']."\n")."\n";
		$output .= $page['text'];
	}
	if (function_exists('wrap_content_replace')) {
		$output = wrap_content_replace($output);
	}

	// Output page
	// set character set
	if (!empty($zz_conf['character_set']))
		header('Content-Type: text/html; charset='.$zz_conf['character_set']);

	if (empty($page['no_page_head'])) include $zz_page['head'];
	echo $output;
	if (!empty($zz_page['error_msg']) AND $page['status'] == 200) {
		// show error message in case there is one and it's not already shown
		// by wrap_errorpage() (status != 200)
		echo '<div class="error">'.$zz_page['error_msg'].'</div>'."\n";
	}
	if (empty($page['no_page_foot'])) include $zz_page['foot'];
}
exit;

?>