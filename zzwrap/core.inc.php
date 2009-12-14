<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2008
// CMS core functions


// Local modifications to SQL queries
require_once $zz_setting['custom_wrap_sql_dir'].'/sql-core.inc.php';

/** Test, whether URL contains a correct secret key to allow page previews
 * 
 * @param $secret_key(string) shared secret key
 * @param $_GET['tle'](string) timestamp, begin of legitimite timeframe
 * @param $_GET['tld'](string) timestamp, end of legitimite timeframe
 * @param $_GET['tlh'](string) hash
 * @return $wrap_page_preview true|false i. e. true means show page, false don't
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_test_secret_key($secret_key) {
	$wrap_page_preview = false;
	if (!empty($_GET['tle']) && !empty($_GET['tld']) && !empty($_GET['tlh']))
		if (time() > $_GET['tle'] && time() < $_GET['tld'] && 
			$_GET['tlh'] == md5($_GET['tle'].'&'.$_GET['tld'].'&'.$secret_key)) {
			session_start();
			$_SESSION['wrap_page_preview'] = true;
			$wrap_page_preview = true;
		}
	return $wrap_page_preview;
}

/** Tests whether URL is in database (or a part of it ending with *), or a part 
 * of it with placeholders
 * 
 * @param $zz_conf(array) zz configuration variables
 * @param $zz_access(array) zz access rights
 * @return $page
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_look_for_page(&$zz_conf, &$zz_access, $zz_page) {
	// Variables
	global $zz_setting;
	global $zz_sql;
	$page = false;

	// Prepare URL for database request
	$url = wrap_read_url($zz_page['url']);
	$full_url[0] = $url['db'];

	// check for placeholders
	if (!empty($zz_page['url_placeholders'])) {
		// 1. cut url in parts
		$url_parts[0] = explode('/', $full_url[0]);
		$i = 1;
		// 2. replace parts that match with placeholders, if neccessary multiple times
		// note: twice the same fragment will only be replaced once, not both fragments
		// at the same time (e. g. /eng/eng/ is /%language%/eng/ and /eng/%language%/
		// but not /%language%/%language%/ because this would not make sense) 
		foreach ($zz_page['url_placeholders'] as $wildcard => $values) {
			foreach (array_keys($values) as $key) {
				foreach ($url_parts as $url_index => $parts) {
					foreach ($parts as $partkey => $part) {
						if ($part == $key) {
							// new URL parts, take the one that we match on as basis
							$url_parts[$i] = $url_parts[$url_index];
							// leftovers, get the ones as a basis we already have							
							if (!empty($leftovers[$url_index]))
								$leftovers[$i] = $leftovers[$url_index];
							// take current part and put it into leftovers
							$leftovers[$i][$partkey] = $url_parts[$i][$partkey];
							// overwrite current part with placeholder
							$url_parts[$i][$partkey] = '%'.$wildcard.'%';
							$full_url[$i] = implode('/', $url_parts[$i]); 
							$i++;
						}
					}
				}
			}
		}
	}

	// For request, remove ending (.html, /), but not for page root
	foreach ($full_url as $i => $my_url) {
		if (!$page) $parameter = false; // if more than one url will be checked, initialize variable
		while (!$page) {
			$sql = sprintf($zz_sql['pages'], '/'.mysql_real_escape_string($my_url));
			if (!$zz_access['wrap_page_preview']) $sql.= ' AND '.$zz_sql['is_public'];
			$page = wrap_db_fetch($sql);
			if (empty($page) && strstr($my_url, '/')) { // if not found, remove path parts from URL
				if ($parameter) {
					$parameter = '/'.$parameter; // '/' as a separator for variables
					$my_url = substr($my_url, 0, -1); // remove '*'
				}
				$parameter = substr($my_url, strrpos($my_url, '/') +1).$parameter;
				$my_url = substr($my_url, 0, strrpos($my_url, '/')).'*';
			} else {
				// something was found, get out of here
				// but get placeholders as parameters as well!
				if (!empty($leftovers[$i])) 
					$parameter = implode('/', $leftovers[$i]).($parameter ? '/'.$parameter : '');
				break;
			}
		}
	}

	if (!$page) return false;

	$page['parameter'] = $parameter;
	$page['url'] = $my_url;
	return $page;
}

/** Make canonical URLs (trailing slash, .html etc.)
 * 
 * @param $page(array) page array
 * @param $ending(string) ending of URL (/, .html, .php, none)
 * @return redirect to correct URL if necessary
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_check_canonical($page, $ending, $request_uri) {
	global $zz_setting;
	global $zz_page;
	$base = (!empty($zz_page['base']) ? $zz_page['base'] : '');
	if (substr($base, -1) == '/') $base = substr($base, 0, -1);
	$location = "Location: ".$zz_setting['host_base'].$base;
	// correct ending
	switch ($ending) {
	case '/':
		if (substr($request_uri['path'], -5) == '.html') {
			header($location.substr($request_uri['path'], 0, -5).'/');
			exit;
		} elseif (substr($request_uri['path'], -4) == '.php') {
			header($location.substr($request_uri['path'], 0, -4).'/');
			exit;
		} elseif (substr($request_uri['path'], -1) != '/') {
			header($location.$request_uri['path'].'/');
			exit;
		}
	break;
	case '.html':
		if (substr($request_uri['path'], -1) == '/') {
			header($location.substr($request_uri['path'], 0, -1).'.html');
			exit;
		} elseif (substr($request_uri['path'], -4) == '.php') {
			header($location.substr($request_uri['path'], 0, -4).'.html');
			exit;
		} elseif (substr($request_uri['path'], -5) != '.html') {
			header($location.$request_uri['path'].'.html');
			exit;
		}
	break;
	case 'none':
	case 'keine':
		if (substr($request_uri['path'], -5) == '.html') {
			header($location.substr($request_uri['path'], 0, -5));
			exit;
		} elseif (substr($request_uri['path'], -1) == '/' AND strlen($request_uri['path']) > 1) {
			header($location.substr($request_uri['path'], 0, -1));
			exit;
		} elseif (substr($request_uri['path'], -4) == '.php') {
			header($location.substr($request_uri['path'], 0, -4));
			exit;
		}
	break;
	}
	// todo: allow different endings depending on CMS functions
}

/** builds URL from REQUEST
 * 
 * 
 * @param $url(array) $url['full'] with result from parse_url
 * @return $url(array) with new keys ['db'] (URL in database), ['suffix_length']
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_read_url($url) {
	// better than mod_rewrite, because '&' won't always be treated correctly
	$url['db'] = $url['full']['path'];
	$url['suffix_length'] = (!empty($_GET['lang']) ? strlen($_GET['lang']) + 6 : 5);
	// cut '/' at the beginning and - if neccessary - at the end
	if (substr($url['db'], 0, 1) == '/') $url['db'] = substr($url['db'], 1);
	if (substr($url['db'], -1) == '/') $url['db'] = substr($url['db'], 0, -1);
	if (substr($url['db'], -5) == '.html') $url['db'] = substr($url['db'], 0, -5);
	if (substr($url['db'], -4) == '.php') $url['db'] = substr($url['db'], 0, -4);
	if (!empty($_GET['lang']))
		if (substr($url['db'], -$url['suffix_length']) == '.html.'.$_GET['lang']) 
			$url['db'] = substr($url['db'], 0, -$url['suffix_length']);
	return $url;
}

/** Stops execution of script, check for redirects to other pages,
 * includes http error pages
 * 
 * The execution of the CMS will be stopped. The script test if there's
 * an entry for the URL in the redirect table to redirect to another page
 * If that's true, 301 or 302 codes redirect pages, 410 redirect to gone.
 * if no error code is defined, a 404 code and the corresponding error page
 * will be shown
 * @param $errorcode(int) HTTP Error Code, default value is 404
 * @return exits function with a redirect or an error document
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
 function wrap_quit($errorcode = 404) {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;
	global $zz_sql;
	$redir = false;

	// check for redirects, if there's a corresponding table.
	if (!empty($zz_setting['check_redirects'])) {
		$url = wrap_read_url($zz_page['url']);
		$url['db'] = mysql_real_escape_string($url['db']);
		$sql = sprintf($zz_sql['redirects'], '/'.$url['db'], '/'.$url['db'], '/'.$url['db']);
		if (!empty($_GET['lang'])) {
			$sql.= ' OR '.$zz_sql['redirects_old_fieldname'].' = "/'
				.$url['db'].'.html.'.mysql_real_escape_string($_GET['lang']).'"';
		}
		$redir = wrap_db_fetch($sql);

		// If no redirect was found until now, check if there's a redirect above
		// above the current level with a placeholder (*)
		$parameter = false;
		$found = false;
		$break_next = false;
		if (!$redir) {
			while (!$found) {
				$sql = sprintf($zz_sql['redirects_*'], '/'.$url['db']);
				$redir = wrap_db_fetch($sql);
				if ($redir) break; // we have a result, get out of this loop!
				if (strrpos($url['db'], '/'))
					$parameter = '/'.substr($url['db'], strrpos($url['db'], '/')+1).$parameter;
				$url['db'] = substr($url['db'], 0, strrpos($url['db'], '/'));
				if ($break_next) break; // last round
				if (!strstr($url['db'], '/')) $break_next = true;
			}
			if ($redir) {
				// If there's an asterisk (*) at the end of the redirect
				// the cut part will be pasted to the end of the string
				if (substr($redir[$zz_sql['redirects_new_fieldname']], -1) == '*')
					$redir[$zz_sql['redirects_new_fieldname']] = substr($redir[$zz_sql['redirects_new_fieldname']], 0, -1).$parameter;
			}
		}
	}
	if (!$redir) $redir['code'] = $errorcode;

	// Set protocol
	$protocol = $_SERVER['SERVER_PROTOCOL'];
	if (!$protocol) $protocol = 'HTTP/1.0'; // default value

	// Check redirection code
	$page['code'] = $redir['code']; // we need this in the error script
	switch ($page['code']) {
	case 301:
		header($protocol." 301 Moved Permanently");
	case 302:
		// header 302 is sent automatically if using Location
		$new = parse_url($redir[$zz_sql['redirects_new_fieldname']]);
		if (!empty($new['scheme'])) {
			$new = $redir[$zz_sql['redirects_new_fieldname']];
		} else {
			$new = $zz_setting['host_base'].$redir[$zz_sql['redirects_new_fieldname']];
		}
		header("Location: ".$new);
		break;
	default: // 4xx, 5xx
		include_once $zz_setting['http_error_script'];
	}
	exit;
}

/** Checks if HTTP request should be HTTPS request instead and vice versa
 * 
 * Function will redirect request to the same URL except for the scheme part
 * Attention: POST variables will get lost
 * @param $zz_page(array) Array with full URL in $zz_page['url']['full'], 
 	this is the result of parse_url()
 * @param $zz_setting(array) settings, 'ignore_scheme' ignores redirect
 	and 'protocol' defines the protocol wanted (http or https)
 * @return redirect header
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_check_https($zz_page, $zz_setting) {
	// if it doesn't matter, get out of here
	if ($zz_setting['ignore_scheme']) return true;

	// change from http to https or vice versa
	// attention: $_POST will not be preserved
	if ((!empty($_SERVER['HTTPS']) AND $_SERVER['HTTPS'] == 'on' AND $zz_setting['protocol'] == 'http')
		OR (empty($_SERVER['HTTPS']) AND $zz_setting['protocol'] == 'https')) {
		header('Location: '.$zz_setting['protocol'].'://'.$zz_page['url']['full']['host']
			.$zz_page['url']['full']['path']
			.(!empty($zz_page['url']['full']['query']) ? '?'.$zz_page['url']['full']['query'] : ''));
		exit;
	}
}

/** Fetches records from database and returns array
 * 
 * - without $id_field_name: expects exactly one record and returns
 * the values of this record as an array
 * - with $id_field_name: uses this name as unique key for all records
 * and returns an array of values for each record under this key
 * - with $id_field_name and $array_format = "key/value": returns key/value-pairs
 * TODO: give a more detailed explanation of how function works
 * @param $sql(string) SQL query string
 * @param $id_field_name(string) optional, if more than one record will be 
 *	returned: required; field_name for array keys
 * @param $format(string) optional, currently "key/value" is implemented 
 * @return array with queried database content
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_db_fetch($sql, $id_field_name = false, $format = false) {
	$lines = array();
	$result = mysql_query($sql);
	if ($result) {
		if (!$id_field_name) {
			if (mysql_num_rows($result) == 1) {
	 			if ($format == 'single value') {
					$lines = mysql_result($result, 0, 0);
	 			} else {
					$lines = mysql_fetch_assoc($result);
				}
			}
 		} elseif (mysql_num_rows($result)) {
 			if ($format == 'key/value') {
 				// return array in pairs
				while ($line = mysql_fetch_array($result)) {
					$lines[$line[0]] = $line[1];
				}
 			} else {
 				// default or unknown format
				while ($line = mysql_fetch_assoc($result))
					$lines[$line[$id_field_name]] = $line;
			}
		}
	} else {
		if (substr($_SERVER['SERVER_NAME'], -6) == '.local')
			echo mysql_error();
		wrap_error(sprintf('Error in SQL query:'."\n\n%s\n\n%s", mysql_error(), $sql), E_USER_ERROR);
	}
	return $lines;
}


?>