<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007
// Fehlerseite 503 - Service Unavailable


// in case config has already been included
global $zz_page;
global $zz_setting;	

// basic files
if (empty($zz_setting['scripts']))
	require_once realpath(dirname(__FILE__).'/../paths.inc.php');
require_once $zz_setting['scripts'].'/config.inc.php'; // configuration

// establish database connection
require_once $zz_setting['db_inc'];

header('HTTP/1.1 503 Service Unavailable');

if (!empty($sql)) {
	echo 'Zugriff auf die Website ist nicht m&ouml;glich (Datenbankst&ouml;rung).';
	if (!empty($zz_conf['error_handling']) AND $zz_conf['error_handling'] == 'mail') {
		$mailtext = false;
		if (!empty($sql)) $mailtext = "Database error:\n\n".mysql_error()."\n\nSQL: ".$sql;
		$mailtext = sprintf($text['The following error(s) occured in project %s:'], $zz_conf['project'])."\n\n".$mailtext;
		$mailtext .= "\n\n-- \nURL: http://".$_SERVER['SERVER_NAME']
			.$_SERVER['REQUEST_URI']
			."\nIP: ".$_SERVER['REMOTE_ADDR']
			."\nBrowser: ".$_SERVER['HTTP_USER_AGENT'];		
		if ($zz_conf['user'])
			$mailtext .= "\nUser: ".$zz_conf['user'];
		mail ($zz_conf['error_mail_to'], '['.$zz_conf['project'].'] '
			.$text['Error during database operation'], 
			$mailtext, 'MIME-Version: 1.0
Content-Type: text/plain; charset='.$zz_conf['charset'].'
Content-Transfer-Encoding: 8bit
From: '.$zz_conf['error_mail_from']);
	// TODO: check what happens with utf8 mails
	}
} else {
	echo '<h1>503 Service Unavailable</h1>';
}

exit;

?>