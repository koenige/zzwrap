<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007
// Fehlerseite 410 - Gone


// in case config has already been included
global $zz_page;
global $zz_setting;	

// basic files
if (empty($zz_setting['scripts']))
	require_once realpath(dirname(__FILE__).'/../paths.inc.php');
require_once $zz_setting['scripts'].'/config.inc.php'; // configuration

// establish database connection
require_once $zz_setting['db_inc'];

if (function_exists('cms_zeige_menue')) {
	$nav = cms_hole_menue();
	$page['nav'] = cms_zeige_menue($nav);
}
$page['seitentitel'] = '410 Objekt nicht mehr verf&uuml;gbar';
$page['code'] = '410';
$page['letzte_aenderung'] = false;
$page['brotkrumen'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'.$zz_conf['project'].'</a></strong> &gt; Nicht verf&uuml;gbar';
include $zz_page['head'];

?>

<h1>Objekt nicht mehr verf&uuml;gbar</h1>

<p>Der angeforderte URL existiert auf dem Server nicht mehr
    und wurde dauerhaft entfernt.
    Eine Weiterleitungsadresse ist nicht verf&uuml;gbar.</p> 
<p>Bitte versuchen Sie es &uuml;ber unsere <a href="<?php echo $zz_setting['homepage_url']; ?>">Hauptseite</a>.</p>

<?php

include $zz_page['foot'];

?>
