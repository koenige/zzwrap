<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007
// Hauptskript zur Auswahl der Seiteninhalte


// --------------------------------------------------------------------------
// Parameter initalisieren, globale Variablen setzen
// --------------------------------------------------------------------------

// Parameter initialisieren
$zz_access = false;

// Globale CMS-Variablen setzen
global $zz_setting;
global $zz_conf;
global $zz_page;
global $zz_access;

// --------------------------------------------------------------------------
// Weitere Dateien einbinden
// --------------------------------------------------------------------------

if (empty($zz_setting['lib']))
	$zz_setting['lib']			= $zz_setting['inc'].'/library';
if (empty($zz_setting['core']))
	$zz_setting['core'] = $zz_setting['lib'].'/zzwrap';
require_once $zz_setting['core'].'/defaults.inc.php';	// set default variables
require_once $zz_setting['core'].'/errorhandling.inc.php';	// CMS errorhandling
//require_once $zz_setting['core'].'/language.inc.php';	// include language settings
require_once $zz_setting['core'].'/core.inc.php';	// CMS Kern
require_once $zz_setting['core'].'/page.inc.php';	// CMS Seitenskripte
if (!empty($zz_conf['error_503'])) zz_errorhandling($zz_conf['error_503'], E_USER_ERROR);	// exit for maintenance reasons

require_once $zz_setting['db_inc']; // Datenbankverbindung herstellen

// --------------------------------------------------------------------------
// Abfrage der Seite nach URL in der Datenbank
// --------------------------------------------------------------------------

// Secret Key für Vorschaufunktion, damit auch noch nicht zur
// Veröffentlichung freigegebene Seiten angeschaut werden können.
if (!empty($zz_setting['secret_key']))
	$zz_access['wrap_page_preview'] = wrap_test_secret_key($zz_setting['secret_key']);

// Eintrag in Datenbank finden, nach URL
// wenn nicht gefunden, dann URL abschneiden, Sternchen anfuegen und abgeschnittene
// Werte in Parameter-Array schreiben
$zz_page['db'] = wrap_look_for_page($zz_conf, $zz_access, $zz_page);

// Check whether we need HTTPS or not, redirect if neccessary
wrap_check_https($zz_page, $zz_setting);

// --------------------------------------------------------------------------
// include modules
// --------------------------------------------------------------------------

// Functions which may be needed for login
if (file_exists($zz_setting['custom_wrap_dir'].'/start.inc.php'))
	require_once $zz_setting['custom_wrap_dir'].'/start.inc.php';
if (file_exists($zz_setting['custom_wrap_dir'].'/_functions.inc.php'))
	require_once $zz_setting['custom_wrap_dir'].'/_functions.inc.php';
if (file_exists($zz_setting['custom_wrap_sql_dir'].'/_sql-queries.inc.php'))
	require_once $zz_setting['custom_wrap_sql_dir'].'/_sql-queries.inc.php';

// modules may change page language ($page['lang']), so include language functions here
require_once $zz_setting['core'].'/language.inc.php';	// CMS language
if ($zz_setting['authentification_possible'])
	require_once $zz_setting['core'].'/login.inc.php';	// CMS Login/Logoutskripte, authentification

// Standardfunktionen einbinden (z. B. Markup-Sprachen)
if (!empty($zz_setting['standard_extensions']))	
	foreach ($zz_setting['standard_extensions'] as $function)
		require_once $zz_setting['lib'].'/'.$function.'.php';
require_once $zz_conf['dir_inc'].'/numbers.inc.php';
if (file_exists($zz_setting['custom_wrap_dir'].'/_settings_post_login.inc.php'))
	require_once $zz_setting['custom_wrap_dir'].'/_settings_post_login.inc.php';

// --------------------------------------------------------------------------
// On Error Exit, after all files are included
// --------------------------------------------------------------------------

// Falls kein Eintrag in Datenbank, Umleitungen pruefen, ggf. 404 Fehler ausgeben.
if (!$zz_page['db']) wrap_quit();

// --------------------------------------------------------------------------
// Zusammenbau der Seite
// --------------------------------------------------------------------------

if (function_exists('wrap_get_media'))
	$media = wrap_get_media($zz_page['db'][$zz_field_page_id]);

require_once $zz_setting['lib'].'/zzbrick/zzbrick.php';
$page = brick_format($zz_page['db'][$zz_field_content], $zz_page['db']['parameter'], $zz_setting);
if ($page['status'] != 200) 
	wrap_quit($page['status']);
if (empty($page)) wrap_quit();
// set HTML language code if not set so far
if (!isset($page['lang'])) $page['lang'] = $zz_setting['lang'];

$found = true; // Seiteninhalt vorhanden!

if (empty($page['no_output'])) {
	// puzzle page elements together
	
	// Falls Datenbank anbietet, dass man eine Endung angibt, kanonische URL finden
	if (!empty($zz_page['db'][$zz_field_ending])) {
		$ending = $zz_page['db'][$zz_field_ending];
		// if brick_format() returns a page ending, use this
		if (isset($page['url_ending'])) $ending = $page['url_ending'];
		wrap_check_canonical($page, $ending, $zz_page['url']['full']);
	}

	// $page['title'] == H1 element
	if (empty($page['title'])) $page['title'] = $zz_page['db'][$zz_field_title]; // Titel ggf. aus CMS-Skript!

	// $page['pagetitle'] TITLE element
	$page['pagetitle'] = strip_tags($page['title']);
	if (!empty($zz_setting['translate_page_title']))
		$page['title'] = wrap_text($page['title']);
	if ($zz_page['url']['full']['path'] != '/')
		$page['pagetitle'] .=' ('.(empty($page['project']) ? $zz_conf['project'] : $page['project']).')';

	// last update
	if (!empty($page['last_update'])) $page[$zz_field_lastupdate] = $page['last_update'];
	if (empty($page[$zz_field_lastupdate]))
		$page[$zz_field_lastupdate] = $zz_page['db'][$zz_field_lastupdate];
	$page[$zz_field_lastupdate] = datum_de($page[$zz_field_lastupdate]);

	// breadcrumbs (from cmscore/page.inc.php)
	if ($zz_sql['breadcrumbs'])
		$page['breadcrumbs'] = wrap_htmlout_breadcrumbs($zz_page['db'][$zz_field_page_id], $page['breadcrumbs']);

	// authors (from cmscore/page.inc.php)
	if (!empty($zz_page['db'][$zz_field_author_id]))
		$page['authors'] = wrap_get_authors($page['authors'], $zz_page['db'][$zz_field_author_id]);

	// navigation menu (from cmscore/page.inc.php)
	if ($zz_sql['menu']) {
		$nav = wrap_get_menu();
		if ($nav) $page['nav'] = wrap_htmlout_menu($nav);
	}
	
	$output = '';
	if (function_exists('wrap_matrix')) {
		// Matrix for several projects
		// TODO: solve better, don't hardcode.
		if (empty($media)) {
			if (!empty($page['media'])) $media = $page['media'];
			else $media = false;
		} else {
			if (!empty($page['media'])) $media = array_merge($media, $page['media']);
		}
		$output = wrap_matrix($page, $media);
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

	// output of content
	if ($zz_setting['brick_page_templates'] == true) {
		$page['output'] = &$output;
		echo wrap_htmlout_page($page);
	} else {
		// classic: mix of HTML and PHP
		if (empty($page['no_page_head'])) include $zz_page['head'];
		echo $output;
		if (empty($page['no_page_foot'])) include $zz_page['foot'];
	}
}

// --------------------------------------------------------------------------

?>