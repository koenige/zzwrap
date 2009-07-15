<?php

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007
// Authentification


// Local modifications to SQL queries
require $zz_setting['inc_local'].'/cms-sql-auth.inc.php';

// Set variables
global $zz_setting;
global $zz_page;
$now = time();

// start PHP session
if (empty($_SESSION)) session_start();

// if it's not local access (e. g. on development server), all access should go via secure connection
$zz_setting['protocol'] = 'http'.((!empty($zz_setting['no_https']) OR $zz_setting['local_access']) ? '' : 's');
// calculate maximum login time
// you'll stay logged in for x minutes
$keep_alive = $zz_setting['logout_inactive_after'] * 60;

// Falls nicht oder zu lange eingeloggt, auf Login-Seite umlenken
$qs['request'] = false; // initialize request, should be in front of nocookie
if (empty($_SESSION['logged_in']) OR 
	$now > ($_SESSION['last_click_at'] + $keep_alive)) {
	if (!empty($zz_page['url']['full']['query'])) {
		// parse URL for no-cookie to hand it over to login.inc
		// in case cookies are not allowed
		parse_str($zz_page['url']['full']['query'], $query_string);
		if (isset($query_string['no-cookie'])) {
			$qs['nocookie'] = 'no-cookie'; // add no-cookie to query string so login knows that there's no cookie (in case SESSIONs don't work here)
			unset($query_string['no-cookie']);
		}
		$full_query_string = '';
		// glue query string back together
		if ($query_string)
			foreach($query_string as $key => $value) {
				if (is_array($value)) {
					foreach ($value as $subkey => $subvalue)
						$full_query_string[] = $key.'['.$subkey.']='.urlencode($subvalue);
				} else {
					$full_query_string[] = $key.'='.urlencode($value);
				}
			}
		if ($full_query_string) {
			$query_string = '?'.implode('&', $full_query_string);
		} else
			$query_string = '';
	} else $query_string = '';
	$request = $zz_page['url']['full']['path'].$query_string;
	// do not unneccessarily expose URL structure
	if ($request == $zz_setting['login_entryurl']) unset($qs['request']); 
	else $qs['request'] = 'url='.urlencode($request);
	header('Location: '.$zz_setting['protocol'].'://'.$zz_setting['hostname']
		.$zz_setting['login_url']
		.(count($qs) ? '?'.implode('&', $qs) : ''));
	exit;
}

// start database connection
require_once $zz_setting['db_inc'];

// save successful request in database to prolong login time
$_SESSION['last_click_at'] = $now;
if (!empty($_SESSION['login_id'])) {
	$sql = sprintf($zz_sql['last_click'], $now, $_SESSION['login_id']);
	$result = mysql_query($sql);
	// it's not important if an error occurs here
	if (!$result)
		zz_errorhandling(sprintf(cms_text('Could not save "last_click" in database.')."\n\n%s\n%s", mysql_error(), $sql), E_USER_NOTICE);
}

?>