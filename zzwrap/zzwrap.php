<?php 

// Zugzwang CMS
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

if (empty($zz_setting['core']))
	$zz_setting['core'] = $zz_setting['inc'].'/cmscore';
require_once $zz_setting['core'].'/defaults.inc.php';	// set default variables
require_once $zz_setting['core'].'/errorhandling.inc.php';	// CMS errorhandling
//require_once $zz_setting['core'].'/language.inc.php';	// include language settings
require_once $zz_setting['core'].'/core.inc.php';	// CMS Kern
require_once $zz_setting['core'].'/page.inc.php';	// CMS Seitenskripte
if (!empty($zz_conf['error_503'])) quit_cms(503);	// exit for maintenance reasons

require_once $zz_setting['db_inc']; // Datenbankverbindung herstellen

// --------------------------------------------------------------------------
// Abfrage der Seite nach URL in der Datenbank
// --------------------------------------------------------------------------

// Secret Key für Vorschaufunktion, damit auch noch nicht zur
// Veröffentlichung freigegebene Seiten angeschaut werden können.
if (!empty($zz_setting['secret_key']))
	$zz_access['cms_page_preview'] = cms_test_secret_key($zz_setting['secret_key']);

// Eintrag in Datenbank finden, nach URL
// wenn nicht gefunden, dann URL abschneiden, Sternchen anfuegen und abgeschnittene
// Werte in Parameter-Array schreiben
$zz_page['db'] = cms_look_for_page($zz_conf, $zz_access, $zz_page);

// --------------------------------------------------------------------------
// include modules
// --------------------------------------------------------------------------

// Functions which may be needed for login
if (file_exists($zz_setting['inc_local'].'/cms-start.inc.php'))
	require_once $zz_setting['inc_local'].'/cms-start.inc.php';
if (file_exists($zz_setting['inc_local'].'/_functions.inc.php'))
	require_once $zz_setting['inc_local'].'/_functions.inc.php';
if (file_exists($zz_setting['inc_local'].'/_sql-queries.inc.php'))
	require_once $zz_setting['inc_local'].'/_sql-queries.inc.php';

// modules may change page language ($zz_page['language']), so include language functions here
require_once $zz_setting['core'].'/language.inc.php';	// CMS language
if ($zz_setting['authentification_possible'])
	require_once $zz_setting['core'].'/login.inc.php';	// CMS Login/Logoutskripte, authentification

// Standardfunktionen einbinden (z. B. Markup-Sprachen)
if (!empty($zz_setting['standard_extensions']))	
	foreach ($zz_setting['standard_extensions'] as $function)
		require_once $zz_setting['inc'].'/zzform/ext/'.$function.'.php';
require_once $zz_conf['dir'].'/inc/numbers.inc.php';
if (file_exists($zz_setting['inc_local'].'/_settings_post_login.inc.php'))
	require_once $zz_setting['inc_local'].'/_settings_post_login.inc.php';

// --------------------------------------------------------------------------
// On Error Exit, after all files are included
// --------------------------------------------------------------------------

// Falls kein Eintrag in Datenbank, Umleitungen pruefen, ggf. 404 Fehler ausgeben.
if (!$zz_page['db']) quit_cms();

// --------------------------------------------------------------------------
// Zusammenbau der Seite
// --------------------------------------------------------------------------

if (function_exists('cms_get_media'))
	$media = cms_get_media($zz_page['db'][$zz_field_page_id]);

require_once $zz_setting['inc'].'/zzbrick/zzbrick.php';
$page = brick_format($zz_page['db'][$zz_field_content], $zz_page['db']['parameter'], $zz_setting);
if ($page['status'] != 200) 
	quit_cms($page['status']);
if (empty($page)) quit_cms();

$found = true; // Seiteninhalt vorhanden!

// Falls Datenbank anbietet, dass man eine Endung angibt, kanonische URL finden
// TODO: erlauben über Parameter, dass auch brick_format sowas zurückgibt.
if (!empty($zz_page['db'][$zz_field_ending])) 
	cms_check_canonical($page, $zz_page['db'][$zz_field_ending], $zz_page['url']['full']);

// $page['title'] == H1 element
if (empty($page['title'])) $page['title'] = $zz_page['db'][$zz_field_title]; // Titel ggf. aus CMS-Skript!

// $page['pagetitle'] TITLE element
$page['pagetitle'] = strip_tags($page['title']);
if ($zz_page['url']['full']['path'] != '/')
	$page['pagetitle'] .=' ('.(empty($page['project']) ? $zz_conf['project'] : $page['project']).')';

// last update
if (!empty($page['last_update'])) $page[$zz_field_lastupdate] = $page['last_update'];
if (empty($page[$zz_field_lastupdate]))
	$page[$zz_field_lastupdate] = $zz_page['db'][$zz_field_lastupdate];
$page[$zz_field_lastupdate] = datum_de($page[$zz_field_lastupdate]);

// breadcrumbs (from cmscore/page.inc.php)
if ($zz_sql['breadcrumbs'])
	$page['breadcrumbs'] = cms_htmlout_breadcrumbs($zz_page['db'][$zz_field_page_id], $page['breadcrumbs']);

// authors (from cmscore/page.inc.php)
if (!empty($zz_page['db'][$zz_field_author_id]))
	$page['authors'] = cms_get_authors($page['authors'], $zz_page['db'][$zz_field_author_id]);

// navigation menu (from cmscore/page.inc.php)
if ($zz_sql['menu']) {
	$nav = cms_get_menu();
	if ($nav) $page['nav'] = cms_htmlout_menu($nav);
}

$output = '';
if (function_exists('cms_matrix')) {
	// Matrix for several projects
	// TODO: solve better, don't hardcode.
	if (empty($media)) {
		if (!empty($page['media'])) $media = $page['media'];
		else $media = false;
	} else {
		if (!empty($page['media'])) $media = array_merge($media, $page['media']);
	}
	$output = cms_matrix($page, $media);
} else {
	if (empty($page['dont_show_h1']) AND empty($zz_page['dont_show_h1']))
		$output .= "\n".markdown('# '.$page['title']."\n")."\n";
	$output .= $page['text'];
}
if (function_exists('cms_content_replace')) {
	$output = cms_content_replace($output);
}

// Zeichenrepertoire einstellen
if (!empty($zz_conf['character_set']))
	header('Content-Type: text/html; charset='.$zz_conf['character_set']);

// Ausgabe der Inhalte
if (empty($page['no_page_head'])) include $zz_page['head'];
echo $output;
if (empty($page['no_page_foot'])) include $zz_page['foot'];

// --------------------------------------------------------------------------

?>