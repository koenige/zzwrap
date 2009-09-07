<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007
// Fehlerseite 410 - Gone


// in case config has already been included
global $zz_page;
global $zz_setting;	

// basic files
if (empty($zz_setting['inc']))
	require_once realpath(dirname(__FILE__).'/../paths.inc.php');
require_once $zz_setting['inc'].'/config.inc.php'; // configuration
require_once $zz_setting['core'].'/defaults.inc.php'; // default configuration

// establish database connection
require_once $zz_setting['db_inc'];

header($_SERVER['SERVER_PROTOCOL'].' 410 Gone');
if (!empty($zz_conf['character_set']))
	header('Content-Type: text/html; charset='.$zz_conf['character_set']);

if (function_exists('cms_htmlout_menu')) {
	$nav = cms_get_menu();
	$page['nav'] = cms_htmlout_menu($nav);
}
$page['pagetitle'] = '410 Objekt nicht mehr verf&uuml;gbar';
$page['code'] = '410';
$page['lang'] = 'de';
$page['letzte_aenderung'] = false;
$page['breadcrumbs'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'.$zz_conf['project'].'</a></strong> &gt; Nicht verf&uuml;gbar';
include $zz_page['head'];

?>
<div class="errorpage">
<h1>Objekt nicht mehr verf&uuml;gbar</h1>

<p>Der angeforderte URL existiert auf dem Server nicht mehr
    und wurde dauerhaft entfernt.
    Eine Weiterleitungsadresse ist nicht verf&uuml;gbar.</p> 
<p>Bitte versuchen Sie es &uuml;ber unsere <a href="<?php echo $zz_setting['homepage_url']; ?>">Hauptseite</a>.</p>
</div>
<?php

include $zz_page['foot'];

?>
