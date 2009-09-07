<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007
// Error page 403 - Access forbidden


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

header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden');
if (!empty($zz_conf['character_set']))
	header('Content-Type: text/html; charset='.$zz_conf['character_set']);

$page['code'] = 403;
$page['lang'] = 'de';
$page['breadcrumbs'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'.$zz_conf['project'].'</a></strong> &gt; Kein Zugriff';
$page['pagetitle'] = 'Kein Zugriff';
include $zz_page['head'];

?>
<div class="errorpage">
<h1>Kein Zugriff</h1>

<div id="text">
<p>Auf die von Ihnen gew&uuml;nschte Seite oder Ressource konnte nicht zugegriffen werden. 
Bitte versuchen Sie es &uuml;ber unsere <a href="<?php echo $zz_setting['homepage_url']; ?>">Hauptseite</a>.</p>
</div>
</div>
<?php

include $zz_page['foot'];

?>
