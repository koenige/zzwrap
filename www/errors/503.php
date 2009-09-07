<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2009
// Error page 503 - Service Unavailable


// in case config has already been included
global $zz_page;
global $zz_setting;	

// basic files
if (empty($zz_setting['inc']))
	require_once realpath(dirname(__FILE__).'/../paths.inc.php');
require_once $zz_setting['inc'].'/config.inc.php'; 		// configuration
require_once $zz_setting['core'].'/defaults.inc.php';	// default configuration
require_once $zz_setting['core'].'/language.inc.php';	// language

// establish database connection
require_once $zz_setting['db_inc'];

header($_SERVER['SERVER_PROTOCOL'].' 503 Service Unavailable');
if (!empty($zz_conf['character_set']))
	header('Content-Type: text/html; charset='.$zz_conf['character_set']);

if (empty($page['lang']))
	$page['lang'] = $zz_conf['language'];
$page['code'] = 503;
$page['last_update'] = false;
$page['breadcrumbs'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'
	.$zz_conf['project'].'</a></strong> '.$zz_page['breadcrumbs_separator'].' '
	.cms_text('Service Unavailable');
$page['pagetitle'] = cms_text('Service Unavailable');
include $zz_page['head'];

if (empty($zz_page['error503'])) {
	echo '<div class="errorpage">
<h1>'.cms_text('Service Unavailable').'</h1>
<div id="text">
<p>'.cms_text('The server is currently unable to handle the request due to a temporary overloading or maintenance of the server.').'</p><p>'.
cms_text('Please try again later.').'</p>
</div>
</div>
';
} else {
	include $zz_page['error503'];
}

include $zz_page['foot'];

if (!empty($sql)) {
	if (!empty($zz_conf['error_handling']) AND $zz_conf['error_handling'] == 'mail') {
		$mailtext = false;
		if (!empty($sql)) $mailtext = "Database error:\n\n".mysql_error()."\n\nSQL: ".$sql;
		$mailtext = sprintf($text['The following error(s) occured in project %s:'], $zz_conf['project'])."\n\n".$mailtext;
		$mailtext .= "\n\n-- \nURL: http://".$_SERVER['SERVER_NAME']
			.$_SERVER['REQUEST_URI']
			."\nIP: ".$_SERVER['REMOTE_ADDR']
			."\nBrowser: ".$_SERVER['HTTP_USER_AGENT'];		
		if ($zz_conf['user'])
			$mailtext .= "\n".cms_text('User').': '.$zz_conf['user'];
		elseif (!empty($_SESSION['username'])) 
			$mailtext .= "\n".cms_text('User').': '.$_SESSION['username'];
		mail ($zz_conf['error_mail_to'], '['.$zz_conf['project'].'] '
			.$text['Error during database operation'], 
			$mailtext, 'MIME-Version: 1.0
Content-Type: text/plain; charset='.$zz_conf['charset'].'
Content-Transfer-Encoding: 8bit
From: '.$zz_conf['error_mail_from']);
	// TODO: check what happens with utf8 mails
	}
}

exit;

?>