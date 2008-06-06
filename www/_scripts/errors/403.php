<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007
// Error page 403 - Access forbidden


// in case config has already been included
global $zz_page;
global $zz_setting;	

// basic files
if (empty($zz_setting['scripts']))
	require_once realpath(dirname(__FILE__).'/../paths.inc.php');
require_once $zz_setting['scripts'].'/config.inc.php'; // configuration

// establish database connection
require_once $zz_setting['db_inc'];

$page['code'] = '403';
$page['brotkrumen'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'.$zz_conf['project'].'</a></strong> &gt; Kein Zugriff';
$page['seitentitel'] = 'Kein Zugriff';
include $zz_page['head'];

?>

<h1>Kein Zugriff</h1>

<div id="text">
<p>Auf die von Ihnen gew&uuml;nschte Seite oder Ressource konnte nicht zugegriffen werden. 
Bitte versuchen Sie es &uuml;ber unsere <a href="<?php echo $zz_setting['homepage_url']; ?>">Hauptseite</a>.</p>
</div>

<?php

include $zz_page['foot'];

?>
