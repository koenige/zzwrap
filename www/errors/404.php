<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2009
// Error page 404 - Ressource Not Found


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

header($_SERVER['SERVER_PROTOCOL'].' 404 Not Found');
if (!empty($zz_conf['character_set']))
	header('Content-Type: text/html; charset='.$zz_conf['character_set']);

// get menus
if (function_exists('cms_htmlout_menu')) {
	$nav = cms_get_menu();
	$page['nav'] = cms_htmlout_menu($nav);
}

$page['code'] = 404;
if (empty($page['lang']))
	$page['lang'] = $zz_conf['language'];
$page['last_update'] = false;
$page['breadcrumbs'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'
	.$zz_conf['project'].'</a></strong> '.$zz_page['breadcrumbs_separator'].' '
	.cms_text('Ressource Not Found'); 
$page['pagetitle'] = cms_text('404 Ressource Not Found'); 
$page['output'] = '<div class="errorpage">
<h1>'.cms_text('Ressource Not Found').'</h1>
<div id="text">
<p>'.cms_text('The ressource you requested could not be found.').'</p><p>'.
sprintf(cms_text('Please try to find the content you were looking for from our <a href="%s">main page</a>.'), $zz_setting['homepage_url']).'</p>
</div>
</div>
';

if ($zz_setting['brick_page_templates'] == true) {
	echo cms_htmlout_page($page);
} else {
	include $zz_page['head'];
	if (empty($zz_page['error404'])) echo $page['output'];
	else include $zz_page['error404'];
	include $zz_page['foot'];
}

if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_USER_AGENT'])) {
	$requested = $zz_page['url']['full']['scheme'].'://'.$zz_page['url']['full']['host'].$zz_page['url']['full']['path'];
	
	if (trim($_SERVER['HTTP_REFERER'])		 				// empty referer uninteresting, might also be spam
		&& $_SERVER['HTTP_REFERER'] != $requested			// no page that's not existent links itself (=spam)
		&& substr($_SERVER['REQUEST_URI'], -1) != '&'		// encoded mail addresses, some bots are too stupid for them
		&& substr($_SERVER['REQUEST_URI'], -3) != '%26'		// encoded mail addresses, some bots are too stupid for them
	) {
		$msg = sprintf(cms_text("The URL\n\n <%s> was requested from\n\n <%s>\n\n with the IP address %s\n (Browser %s)\n\n but could not be found on the server"), 
			$requested, $_SERVER['HTTP_REFERER'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
		if (!empty($zz_conf['user'])) $msg .= "\n\n".cms_text('User').': '.$zz_conf['user'];
		elseif (!empty($_SESSION['username'])) $msg .= "\n\n".cms_text('User').': '.$_SESSION['username'];
		zz_errorhandling($msg, E_USER_WARNING);
	}
}

exit;

?>