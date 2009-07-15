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

require_once $zz_setting['db_inc']; // Datenbankverbindung herstellen

// Module einbinden
// TODO: nicht alle Module einbinden, nur die, die nötig sind.
$dirhandle = opendir($zz_setting['modules']); 			// CMS-Module einbinden
while ($file = readdir($dirhandle))
	if (substr($file, -8) == '.inc.php')
		include_once $zz_setting['modules'].'/'.$file;
		// TODO: Modul-Liste generieren, z. B. $zz_setting['modules_loaded'][] = '';
closedir($dirhandle);

// Authentifizierung aktivieren
if (!empty($zz_setting['auth_urls'])) {
	$url = parse_url($_SERVER['REQUEST_URI']);
	foreach($zz_setting['auth_urls'] as $auth_url) {
		if (substr($url['path'], 0, strlen($auth_url)) == $auth_url
			&& $url['path'] != $zz_setting['login_url']) {
			require_once $zz_setting['scripts'].'/inc/auth.inc.php';
		}
	}
}

// Standardfunktionen einbinden (z. B. Markup-Sprachen)
if (!empty($zz_setting['standard_extensions']))	
	foreach ($zz_setting['standard_extensions'] as $function)
		require_once $zz_setting['scripts'].'/zzform/ext/'.$function.'.php';
require_once $zz_conf['dir'].'/inc/numbers.inc.php';
if (file_exists($zz_setting['scripts'].'/inc/_functions.inc.php'))
	require_once $zz_setting['scripts'].'/inc/_functions.inc.php';

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
$db_page = cms_look_for_page($zz_conf, $zz_access);

// Falls kein Eintrag in Datenbank, Umleitungen pruefen, ggf. 404 Fehler ausgeben.
if (!$db_page) quit_cms();

// --------------------------------------------------------------------------
// Zusammenbau der Seite
// --------------------------------------------------------------------------

if (function_exists('cms_lese_medien'))
	$medien = cms_lese_medien($db_page['seite_id']);
$page = cms_format($db_page['inhalt'], $db_page['parameter'], $db_page['seite_id']);
if (empty($page)) quit_cms();
$found = true; // Seiteninhalt vorhanden!
if (empty($page['titel'])) 
	$page['titel'] = $db_page['titel']; // Titel ggf. aus CMS-Skript!

// Falls Datenbank anbietet, dass man eine Endung angibt, kanonische URL finden
// TODO: erlauben über Parameter, dass auch cms_format sowas zurückgibt.
if (!empty($db_page['endung'])) cms_check_canonical($page, $db_page['endung']);

if (function_exists('cms_lese_brotkrumen')) {
	$page['brotkrumen'] = cms_lese_brotkrumen($db_page['seite_id'], 
	$db_page['kennung'], $page['extra_brotkrumen']);
}
$page['seitentitel'] = strip_tags($page['titel']);
if ($db_page['url'] != '/')
	$page['seitentitel'] .=' ('.$zz_conf['project'].')';
if (!empty($page['last_update'])) $page['letzte_aenderung'] = $page['last_update'];
if (empty($page['letzte_aenderung']))
	$page['letzte_aenderung'] = $db_page['letzte_aenderung'];
$page['letzte_aenderung'] = datum_de($page['letzte_aenderung']);
if (function_exists('cms_lese_autoren'))
	$page['autor_kuerzel'] = 
	cms_lese_autoren($page['autoren'], $db_page['autor_person_id']);
if (function_exists('cms_zeige_menue')) {
	$nav = cms_hole_menue();
	$page['nav'] = cms_zeige_menue($nav);
}

$ausgabe = '';
if (function_exists('cms_matrix')) {
	// Matrix for several projects
	// TODO: solve better, don't hardcode.
	if (empty($medien)) {
		if (!empty($page['medien'])) $medien = $page['medien'];
		else $medien = false;
	}
	$ausgabe = cms_matrix($page, $medien);
} else {
	if (empty($page['dont_show_h1']))
		$ausgabe .= "\n".markdown('# '.$page['titel']."\n")."\n";
	$ausgabe .= $page['text'];
}
if (function_exists('cms_content_replace')) {
	$ausgabe = cms_content_replace($ausgabe);
}

// Zeichenrepertoire einstellen
if (!empty($zz_conf['character_set']))
	header('Content-Type: text/html; charset='.$zz_conf['character_set']);

// Ausgabe der Inhalte
if (empty($page['no_page_head'])) include $zz_page['head'];
echo $ausgabe;
if (empty($page['no_page_foot'])) include $zz_page['foot'];

// --------------------------------------------------------------------------

?>