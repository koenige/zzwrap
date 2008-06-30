<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007
// Fehlerseite 404 - Ressource nicht gefunden


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
$page['seitentitel'] = '404 Ressource nicht gefunden';
$page['code'] = '404';
$page['letzte_aenderung'] = false;
$page['brotkrumen'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'.$zz_conf['project'].'</a></strong> &gt; Nicht gefunden';
include $zz_page['head'];

?>

<h1>Seite nicht gefunden</h1>

<p>Die von Ihnen gew&uuml;nschte Seite oder Ressource konnte nicht gefunden werden. 
Bitte versuchen Sie es &uuml;ber unsere <a href="<?php echo $zz_setting['homepage_url']; ?>">Hauptseite</a>.</p>

<div lang="en">
<p>&nbsp;</p>
<h1>Page not found</h1>

<p>The ressource you request could not be found. 
Please try again via our <a href="<?php echo $zz_setting['homepage_url']; ?>en/">main page</a>.</p>
</div>

<?php

include $zz_page['foot'];

if (date('Y', time() <= '2008')) {
	if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_USER_AGENT'])) {
		if (!trim($_SERVER['HTTP_REFERER']) == "" 						// leere Referer uninteressant, viel Spam
			&& $_SERVER['HTTP_REFERER'] != $_SERVER['REQUEST_URI']		// keine Seite verlinkt sich selber, daher wohl auch Spam
			&& substr($_SERVER['REQUEST_URI'], -1) != '&'				// verschlüsselte Mailadressen, kapieren manche Bots nicht
		) {
			$nachricht = "Hallo Systemverantwortliche!\n\n"
				."Die Seite \n\n"
				.' http://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI']." wurde von \n\n ".$_SERVER['HTTP_REFERER']." \n\n"
				." mit der IP- Adresse  ".$_SERVER['REMOTE_ADDR']."\n"
				." (Browser ".$_SERVER['HTTP_USER_AGENT'].")\n\n"
				."angefordert, konnte auf dem Server aber nicht gefunden werden!";
			if (!empty($zz_conf['user'])) $nachricht .= "\n\n".'User: '.$zz_conf['user'];
			mail ($zz_setting['mailto'], "[".$zz_conf['project']."] Fehlende Datei in der Homepage", 
				$nachricht, 'From: "'.$zz_conf['project'].'" <'.$zz_setting['mailto'].'>');
		}
	}
}
?>
