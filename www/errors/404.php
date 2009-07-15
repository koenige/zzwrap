<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2008
// Fehlerseite 404 - Ressource nicht gefunden


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

if (function_exists('cms_htmlout_menu')) {
	$nav = cms_get_menu();
	$page['nav'] = cms_htmlout_menu($nav);
}
$page['pagetitle'] = '404 Ressource nicht gefunden';
$page['code'] = '404';
$page['letzte_aenderung'] = false;
$page['breadcrumbs'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'.$zz_conf['project'].'</a></strong> &gt; Nicht gefunden';
include $zz_page['head'];

?>
<div class="errorpage">
<h1>Seite nicht gefunden</h1>

<p>Die von Ihnen gew&uuml;nschte Seite oder Ressource konnte nicht gefunden werden. 
Bitte versuchen Sie es &uuml;ber unsere <a href="<?php echo $zz_setting['homepage_url']; ?>">Hauptseite</a>.</p>
</div>
<?php

include $zz_page['foot'];

if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_USER_AGENT'])) {
	$requested = (!empty($_SERVER['HTTPS']) ? 'https' : 'http')
		.'://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];

	if (trim($_SERVER['HTTP_REFERER'])		 				// leere Referer uninteressant, viel Spam
		&& $_SERVER['HTTP_REFERER'] != $requested			// keine Seite verlinkt sich selber, daher wohl auch Spam
		&& substr($_SERVER['REQUEST_URI'], -1) != '&'		// verschlüsselte Mailadressen, kapieren manche Bots nicht
		&& substr($_SERVER['REQUEST_URI'], -3) != '%26'		// verschlüsselte Mailadressen, kapieren manche Bots nicht
	) {
		$nachricht = "Hallo Systemverantwortliche!\n\n"
			."Die Seite \n\n"
			.' <'.$requested
			."> wurde von \n\n <".$_SERVER['HTTP_REFERER']."> \n\n"
			." mit der IP- Adresse  ".$_SERVER['REMOTE_ADDR']."\n"
			." (Browser ".$_SERVER['HTTP_USER_AGENT'].")\n\n"
			."angefordert, konnte auf dem Server aber nicht gefunden werden!";
		if (!empty($zz_conf['user'])) $nachricht .= "\n\n".'User: '.$zz_conf['user'];
		mail ($zz_conf['error_mail_to'], "[".$zz_conf['project']."] Fehlende Datei in der Homepage", 
			$nachricht, 'From: "'.$zz_conf['project'].'" <'.$zz_conf['error_mail_from'].'>');
	}
}

?>