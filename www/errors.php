<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2009
// Error pages


// in case config has already been included
global $zz_page;
global $zz_setting;	

// basic zzwrap files
if (empty($zz_setting['inc'])) require_once 'paths.inc.php';
if (file_exists($zz_setting['inc'].'/config.inc.php'))
	require_once $zz_setting['inc'].'/config.inc.php'; 		// configuration
require_once $zz_setting['core'].'/defaults.inc.php'; // default configuration
require_once $zz_setting['core'].'/errorhandling.inc.php';	// CMS errorhandling
require_once $zz_setting['core'].'/language.inc.php';	// include language settings
require_once $zz_setting['core'].'/core.inc.php';	// CMS Kern
require_once $zz_setting['core'].'/page.inc.php';	// CMS Seitenskripte

// establish database connection
require_once $zz_setting['db_inc'];

// -- 1. check what kind of error page it is

// read error codes from file
$pos[0] = 'code';
$pos[1] = 'title';
$pos[2] = 'description';
$codes_from_file = file($zz_setting['core'].'/http-errors.txt');
foreach ($codes_from_file as $line) {
	if (substr($line, 0, 1) == '#') continue;	// Lines with # will be ignored
	elseif (!trim($line)) continue;				// empty lines will be ignored
	$values = explode("\t", trim($line));
	$i = 0;
	$code = '';
	foreach ($values as $val) {
		if (trim($val)) {
			if (!$i) $code = trim($val);
			$codes[$code][$pos[$i]] = trim($val);
			$i++;
		}
	}
	if ($i < 3) {
		for ($i; $i < 3; $i++) {
			$codes[$code][$pos[$i]] = '';
		}
	}
}

if (!empty($_GET['code']) AND in_array($_GET['code'], array_keys($codes)))
	$page['code'] = $_GET['code'];
elseif (empty($page['code']) OR !in_array($page['code'], array_keys($codes)))
	$page['code'] = 404;
$error_messages = $codes[$page['code']];
$extra_description_codes = array(403, 404, 410);

// -- 2. set page elements

if (empty($page['lang'])) $page['lang'] = $zz_conf['language'];
$page['last_update'] = false;
$page['breadcrumbs'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'
	.$zz_conf['project'].'</a></strong> '.$zz_page['breadcrumbs_separator'].' '
	.wrap_text($error_messages['title']); 
$page['pagetitle'] = strip_tags($page['code'].' '.wrap_text($error_messages['title']).' ('.$zz_conf['project'].')'); 
$page['h1'] = wrap_text($error_messages['title']);
$page['error_description'] = sprintf(wrap_text($error_messages['description']), $_SERVER['REQUEST_METHOD']);
if (in_array($page['code'], $extra_description_codes)) {
	$page['error_explanation'] = sprintf(wrap_text('Please try to find the content you were looking for from our <a href="%s">main page</a>.'), $zz_setting['homepage_url']);
} else {
	$page['error_explanation'] = '';
}
if (!empty($zz_page['error_msg'])) $page['error_explanation'] = $zz_page['error_msg'].' '.$page['error_explanation'];

$page['output'] =  implode("", file($zz_page['http_error_template']));;
if (function_exists('wrap_htmlout_menu')) { // get menus
	$nav = wrap_get_menu();
	$page['nav'] = wrap_htmlout_menu($nav);
}

// -- 3. output HTTP header
header($_SERVER['SERVER_PROTOCOL'].' '.$error_messages['code'].' '.$error_messages['title']);
if (!empty($zz_conf['character_set']))
	header('Content-Type: text/html; charset='.$zz_conf['character_set']);

// -- 4. output page

if ($zz_setting['brick_page_templates'] == true) {
	echo wrap_htmlout_page($page);
} else {
	$page['output'] = str_replace('%%% page h1 %%%', $page['h1'], $page['output']);
	$page['output'] = str_replace('%%% page code %%%', $page['code'], $page['output']);
	$page['output'] = str_replace('%%% page error_description %%%', $page['error_description'], $page['output']);
	$page['output'] = str_replace('%%% page error_explanation %%%', $page['error_explanation'], $page['output']);
	include $zz_page['head'];
	echo $page['output'];
	include $zz_page['foot'];
}

// -- 5. error logging

if ($page['code'] == 404
	AND isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_USER_AGENT'])) {
	$requested = $zz_page['url']['full']['scheme'].'://'
		.$zz_page['url']['full']['host'].$zz_page['url']['full']['path'];
	
	if (trim($_SERVER['HTTP_REFERER'])		 				// empty referer uninteresting, might also be spam
		&& $_SERVER['HTTP_REFERER'] != $requested			// no page that's not existent links itself (=spam)
		&& substr($_SERVER['REQUEST_URI'], -1) != '&'		// encoded mail addresses, some bots are too stupid for them
		&& substr($_SERVER['REQUEST_URI'], -3) != '%26'		// encoded mail addresses, some bots are too stupid for them
	) {
		$msg = sprintf(wrap_text("The URL\n\n <%s> was requested from\n\n <%s>\n\n with the IP address %s\n (Browser %s)\n\n but could not be found on the server"), 
			$requested, $_SERVER['HTTP_REFERER'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
		if (!empty($zz_conf['user'])) $msg .= "\n\n".wrap_text('User').': '.$zz_conf['user'];
		elseif (!empty($_SESSION['username'])) $msg .= "\n\n".wrap_text('User').': '.$_SESSION['username'];
		zz_errorhandling($msg, E_USER_WARNING);
	}
}

// 503
if ($page['code'] == 503 AND !empty($sql)) {
	if (!empty($zz_conf['error_handling']) AND $zz_conf['error_handling'] == 'mail') {
		$mailtext = false;
		if (!empty($sql)) 
			$mailtext = "Database error:\n\n".mysql_error()."\n\nSQL: ".$sql;
		$mailtext = sprintf($text['The following error(s) occured in project %s:'], 
			$zz_conf['project'])."\n\n".$mailtext;
		$mailtext .= "\n\n-- \nURL: http://".$_SERVER['SERVER_NAME']
			.$_SERVER['REQUEST_URI']
			."\nIP: ".$_SERVER['REMOTE_ADDR']
			."\nBrowser: ".$_SERVER['HTTP_USER_AGENT'];		
		if ($zz_conf['user'])
			$mailtext .= "\n".wrap_text('User').': '.$zz_conf['user'];
		elseif (!empty($_SESSION['username'])) 
			$mailtext .= "\n".wrap_text('User').': '.$_SESSION['username'];
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