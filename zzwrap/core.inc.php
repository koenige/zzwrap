<?php 

/**
 * zzwrap
 * Core functions: session handling, handling of HTTP requests (URLs, HTTP
 * communication, send ressources), caching + common functions
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2012 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/*
 * --------------------------------------------------------------------
 * Session handling
 * --------------------------------------------------------------------
 */

/**
 * will start a session with some parameters set before
 *
 * @return bool
 */
function wrap_session_start() {
	global $zz_setting;
	global $zz_conf;
	
	// is already a session active?
	if (session_id()) return false;
	// change session_save_path
	if (!empty($zz_setting['session_save_path'])) {
		$success = wrap_mkdir($zz_setting['session_save_path']);
		if ($success) 
			session_save_path($zz_setting['session_save_path']);
	}
	// Cookie: httpOnly, i. e. no access for JavaScript if browser supports this
	$last_error = false;
	if (version_compare(PHP_VERSION, '5.2.0', '>=')) {
		session_set_cookie_params(0, '/', $zz_setting['hostname'], $zz_setting['session_secure_cookie'], true);
		$last_error = error_get_last();
	} else {
		session_set_cookie_params(0, '/', $zz_setting['hostname'], $zz_setting['session_secure_cookie']);
	}
	// don't collide with other PHPSESSID on the same server, set own name:
	session_name('zugzwang_sid');
	$success = session_start();
	// try it twice, some providers have problems with ps_files_cleanup_dir()
	// accessing the /tmp-directory and failing temporarily with
	// insufficient access rights
	if (version_compare(PHP_VERSION, '5.2.0', '>=')) {
		// only throw 503 error if authentication is a MUST HAVE
		// otherwise, page might still be accessible without authentication
		if ($zz_setting['authentication_possible'] AND wrap_authenticate_url()) {
			$session_error = error_get_last();
			if ($last_error != $session_error
				AND wrap_substr($session_error['message'], 'session_start()')) {
				wrap_error('Session start not possible: '.json_encode($session_error));
				wrap_quit(503, wrap_text('Temporarily, a login is not possible.'));
			}
		}
	} else {
		// prior to PHP 5.3.0:
		// If a session fails to start, then FALSE is returned. Previously TRUE was returned. 
		if ($success) wrap_quit(503, wrap_text('Temporarily, a login is not possible.'));
	}
	return true;
}

/**
 * Stops session if cookie exists but time is up
 *
 * @return bool
 */
function wrap_session_stop() {
	$sql = false;
	$sql_mask = false;

	// start session
	wrap_session_start();
	
	// check if SESSION should be kept
	if (!empty($_SESSION['keep_session'])) {
		unset($_SESSION['login_id']);
		unset($_SESSION['mask_id']);
		unset($_SESSION['last_click_at']);
		unset($_SESSION['domain']);
		unset($_SESSION['logged_in']);
		unset($_SESSION['user_id']);
		unset($_SESSION['masquerade']);
		unset($_SESSION['change_password']);
		return false;
	}

	// update login db if logged in, set to logged out
	if (!empty($_SESSION['login_id']) AND $sql = wrap_sql('logout'))
		$sql = sprintf($sql, $_SESSION['login_id']);
	if (!empty($_SESSION['mask_id']) AND $sql_mask = wrap_sql('last_masquerade'))
		$sql_mask = sprintf($sql_mask, 'NOW()', $_SESSION['mask_id']);
	// Unset all of the session variables.
	$_SESSION = array();
	// If it's desired to kill the session, also delete the session cookie.
	// Note: This will destroy the session, and not just the session data!
	if (ini_get("session.use_cookies")) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $params["path"],
	        $params["domain"], $params["secure"], $params["httponly"]
		);
	}
	session_destroy();
	if ($sql) wrap_db_query($sql, E_USER_NOTICE);
	if ($sql_mask) wrap_db_query($sql_mask, E_USER_NOTICE);
	return true;
}

/*
 * --------------------------------------------------------------------
 * URLs
 * --------------------------------------------------------------------
 */

/**
 * Tests whether URL is in database (or a part of it ending with *), or a part 
 * of it with placeholders
 * 
 * @param array $zz_page
 * @global array $zz_conf zz configuration variables
 * @global array $zz_setting
 * @return array $page
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_look_for_page($zz_page) {
	// no database connection or settings are missing
	if (!wrap_sql('pages')) wrap_quit(503); 

	global $zz_setting;
	global $zz_conf;
	$page = false;

	// Prepare URL for database request
	$url = wrap_read_url($zz_page['url']);
	$full_url[0] = $url['db'];

	list($full_url, $leftovers) = wrap_look_for_placeholders($zz_page, $full_url);
	
	// For request, remove ending (.html, /), but not for page root
	foreach ($full_url as $i => $my_url) {
		// if more than one URL to be tested against: count of rounds
		$loops[$i] = 0;
		$page[$i] = false;
		$parameter[$i] = false;
		while (!$page[$i]) {
			$loops[$i]++;
			$sql = sprintf(wrap_sql('pages'), '/'.wrap_db_escape($my_url));
			if (!wrap_rights('preview')) {
				$sql = wrap_edit_sql($sql, 'WHERE', wrap_sql('is_public'));
			}
			$page[$i] = wrap_db_fetch($sql);
			if (empty($page[$i]) && strstr($my_url, '/')) {
				// if not found, remove path parts from URL
				if ($parameter[$i]) {
					$parameter[$i] = '/'.$parameter[$i]; // '/' as a separator for variables
					$my_url = substr($my_url, 0, -1); // remove '*'
				}
				$parameter[$i] = substr($my_url, strrpos($my_url, '/') +1).$parameter[$i];
				$my_url = substr($my_url, 0, strrpos($my_url, '/')).'*';
			} else {
				// something was found, get out of here
				// but get placeholders as parameters as well!
				if (!empty($leftovers[$i])) 
					$parameter[$i] = implode('/', $leftovers[$i]).($parameter[$i] ? '/'.$parameter[$i] : '');
				$url[$i] = $my_url;
				break;
			}
		}
		if (!$page[$i]) unset($loops[$i]);
	}
	if (empty($loops)) return false;
	
	// get best match, sort twice:
	// 1. get match with least loops
	// 2. get match with lowest index of loops
	asort($loops);
	asort($loops);
	$i = key($loops);
	$page = $page[$i];
	if (!$page) return false;

	$page['parameter'] = $parameter[$i];
	$page['url'] = $url[$i];
	return $page;
}

/**
 * check for placeholders in URL
 *
 * replaces parts that match with placeholders, if necessary multiple times
 * note: twice the same fragment will only be replaced once, not both fragments
 * at the same time (e. g. /eng/eng/ is /%language%/eng/ and /eng/%language%/
 * but not /%language%/%language%/ because this would not make sense) 
 * @param array $zz_page
 * @param array $full_url
 * @return array (array $full_url, array $leftovers)
 */
function wrap_look_for_placeholders($zz_page, $full_url) {
	if (empty($zz_page['url_placeholders'])) return array($full_url, array());
	// cut url in parts
	$url_parts[0] = explode('/', $full_url[0]);
	$i = 1;
	$leftovers = array();
	foreach ($zz_page['url_placeholders'] as $wildcard => $values) {
		foreach (array_keys($values) as $key) {
			foreach ($url_parts as $url_index => $parts) {
				foreach ($parts as $partkey => $part) {
					if ($part != $key) continue;
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
	return array($full_url, $leftovers);
}

/**
 * Make canonical URLs
 * 
 * @param array $zz_page
 * @param array $page
 * @return array $url
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_check_canonical($zz_page, $page) {
	global $zz_setting;
	// if database allows field 'ending', check if the URL is canonical
	if (!empty($zz_page['db'][wrap_sql('ending')])) {
		$ending = $zz_page['db'][wrap_sql('ending')];
		// if brick_format() returns a page ending, use this
		if (isset($page['url_ending'])) $ending = $page['url_ending'];
		$zz_page['url'] = wrap_check_canonical_ending($ending, $zz_page['url']);
	}
	if ($zz_page['url']['full']['path'] === '//') {
		$zz_page['url']['full']['path'] = '/';
		$zz_page['url']['redirect'] = true;
	}
	$types = array('query_strings', 'query_strings_redirect');
	foreach ($types as $type) {
		// initialize
		if (empty($page[$type])) $page[$type] = array();
		// merge from settings
		if (!empty($zz_setting[$type])) {
			$page[$type] = array_merge($page[$type], $zz_setting[$type]);
		}
	}
	// set some query strings which are used by zzwrap
	$page['query_strings'] = array_merge($page['query_strings'],
		array('no-cookie', 'tle', 'tld', 'tlh', 'lang', 'code', 'url', 'logout'));
	if (!empty($zz_page['url']['full']['query'])) {
		parse_str($zz_page['url']['full']['query'], $params);
		foreach (array_keys($params) as $param) {
			if (in_array($param, $page['query_strings'])) continue;
			$param_value = $params[$param];
			unset($params[$param]);
			$zz_page['url']['redirect'] = true;
			// no error logging for query strings which shall be redirected
			if (in_array($param, $page['query_strings_redirect'])) continue;
			if (is_array($param_value)) $param_value = http_build_query($param_value);
			wrap_error(sprintf('Wrong URL: query string %s=%s [%s]', $param, $param_value, $_SERVER['REQUEST_URI']), E_USER_NOTICE);
		}
		$zz_page['url']['full']['query'] = http_build_query($params);
	}
	return $zz_page['url'];
}

/**
 * Make canonical URLs, here: endings (trailing slash, .html etc.)
 * 
 * @param string $ending ending of URL (/, .html, .php, none)
 * @param array $url ($zz_page['url'])
 * @return array $url, with new 'path' and 'redirect' set to 1 if necessary
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_check_canonical_ending($ending, $url) {
	$new = false;
	switch ($ending) {
	case '/':
		if (substr($url['full']['path'], -5) == '.html') {
			$new = substr($url['full']['path'], 0, -5);
		} elseif (substr($url['full']['path'], -4) == '.php') {
			$new = substr($url['full']['path'], 0, -4);
		} elseif (substr($url['full']['path'], -1) != '/') {
			$new = $url['full']['path'];
		}
		if ($new) $new .= '/';
		break;
	case '.html':
	case '.html%3E':
		if (substr($url['full']['path'], -1) == '/') {
			$new = substr($url['full']['path'], 0, -1);
		} elseif (substr($url['full']['path'], -4) == '.php') {
			$new = substr($url['full']['path'], 0, -4);
		} elseif (substr($url['full']['path'], -8) == '.html%3E') {
			$new = substr($url['full']['path'], 0, -8);
		} elseif (substr($url['full']['path'], -5) != '.html') {
			$new = $url['full']['path'];
		}
		if ($new) $new .= '.html';
		break;
	case 'none':
	case 'keine':
		if (substr($url['full']['path'], -5) == '.html') {
			$new = substr($url['full']['path'], 0, -5);
		} elseif (substr($url['full']['path'], -1) == '/' AND strlen($url['full']['path']) > 1) {
			$new = substr($url['full']['path'], 0, -1);
		} elseif (substr($url['full']['path'], -4) == '.php') {
			$new = substr($url['full']['path'], 0, -4);
		}
		break;
	}
	if (!$new) return $url;

	$url['redirect'] = true;
	$url['full']['path'] = $new;
	return $url;
}

/**
 * builds URL from REQUEST
 * 
 * @param array $url $url['full'] with result from parse_url
 * @return array $url with new keys ['db'] (URL in database), ['suffix_length']
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_read_url($url) {
	// better than mod_rewrite, because '&' won't always be treated correctly
	$url['db'] = $url['full']['path'];
	$url['suffix_length'] = !empty($_GET['lang']) ? strlen($_GET['lang']) + 6 : 5;
	// cut '/' at the beginning and - if necessary - at the end
	if (substr($url['db'], 0, 1) == '/') $url['db'] = substr($url['db'], 1);
	if (substr($url['db'], -1) == '/') $url['db'] = substr($url['db'], 0, -1);
	if (substr($url['db'], -5) == '.html') $url['db'] = substr($url['db'], 0, -5);
	elseif (substr($url['db'], -8) == '.html%3E') $url['db'] = substr($url['db'], 0, -8);
	elseif (substr($url['db'], -4) == '.php') $url['db'] = substr($url['db'], 0, -4);
	if (!empty($_GET['lang']))
		if (substr($url['db'], -$url['suffix_length']) == '.html.'.$_GET['lang']) 
			$url['db'] = substr($url['db'], 0, -$url['suffix_length']);
	return $url;
}

/**
 * check for redirects, if there's a corresponding table.
 *
 * @param array $page_url = $zz_page['url']
 * @global array $zz_setting
 * @global array $zz_page
 * @return mixed (bool false: no redirect; array: fields needed for redirect)
 */
function wrap_check_redirects($page_url) {
	global $zz_setting;
	global $zz_page;

	if (empty($zz_setting['check_redirects'])) return false;
	$url = wrap_read_url($zz_page['url']);
	$url['db'] = wrap_db_escape($url['db']);
	$where_language = (!empty($_GET['lang']) 
		? ' OR '.wrap_sql('redirects_old_fieldname').' = "/'
			.$url['db'].'.html.'.wrap_db_escape($_GET['lang']).'"'
		: ''
	);
	$sql = sprintf(wrap_sql('redirects'), '/'.$url['db'], '/'.$url['db'], '/'.$url['db'], $where_language);
	// not needed anymore, but set to false hinders from getting into a loop
	// (wrap_db_fetch() will call wrap_quit() if table does not exist)
	$zz_setting['check_redirects'] = false; 
	$redir = wrap_db_fetch($sql);
	if ($redir) return $redir;

	// If no redirect was found until now, check if there's a redirect above
	// the current level with a placeholder (*)
	$parameter = false;
	$found = false;
	$break_next = false;
	while (!$found) {
		$sql = sprintf(wrap_sql('redirects_*'), '/'.$url['db']);
		$redir = wrap_db_fetch($sql);
		if ($redir) break; // we have a result, get out of this loop!
		if (strrpos($url['db'], '/'))
			$parameter = '/'.substr($url['db'], strrpos($url['db'], '/')+1).$parameter;
		$url['db'] = substr($url['db'], 0, strrpos($url['db'], '/'));
		if ($break_next) break; // last round
		if (!strstr($url['db'], '/')) $break_next = true;
	}
	if (!$redir) return false;
	// If there's an asterisk (*) at the end of the redirect
	// the cut part will be pasted to the end of the string
	$field_name = wrap_sql('redirects_new_fieldname');
	if (substr($redir[$field_name], -1) == '*')
		$redir[$field_name] = substr($redir[$field_name], 0, -1).$parameter;
	return $redir;
}

/**
 * Logs URL in URI table for statistics and further reference
 * sends only notices if some update does not work because it's just for the
 * statistics
 *
 * @return bool
 */
function wrap_log_uri() {
	global $zz_conf;
	global $zz_page;
	if (empty($zz_conf['uris_table'])) return false;

	$scheme = $zz_page['url']['full']['scheme'];
	$host = $zz_page['url']['full']['host'];
	$path = $zz_page['url']['full']['path'];
	$query = !empty($zz_page['url']['full']['query'])
		? '"'.$zz_page['url']['full']['query'].'"'
		: 'NULL';
	$etag = !empty($zz_page['etag'])
		? $zz_page['etag']
		: 'NULL';
	if (substr($etag, 0, 1) !== '"' AND $etag !== 'NULL')
		$etag = '"'.$etag.'"';
	$last_modified = !empty($zz_page['last_modified'])
		? '"'.wrap_date($zz_page['last_modified'], 'rfc1123->datetime').'"'
		: 'NULL';
	$status = !empty($zz_page['error_code'])
		? $zz_page['error_code']
		: 200;
	$content_type = !empty($zz_page['content_type'])
		? $zz_page['content_type']
		: 'unknown';
	$encoding = !empty($zz_page['character_set'])
		? '"'.$zz_page['character_set'].'"'
		: 'NULL';
	if (strstr($content_type, '; charset=')) {
		$content_type = explode('; charset=', $content_type);
		$encoding = '"'.$content_type[1].'"';
		$content_type = $content_type[0];
	}
	
	$sql = 'SELECT uri_id
		FROM /*_PREFIX_*/_uris
		WHERE uri_scheme = "'.$scheme.'"
		AND uri_host = "'.$host.'"
		AND uri_path = "'.$path.'"';
	if ($query === 'NULL') {
		$sql .= ' AND ISNULL(uri_query)';
	} else {
		$sql .= ' AND uri_query = '.$query;
	}
	$uri_id = wrap_db_fetch($sql, '', 'single value', E_USER_NOTICE);
	
	if (is_null($uri_id)) {
		return false;
	} elseif ($uri_id) {
		$sql = 'UPDATE /*_PREFIX_*/_uris
			SET hits = hits +1
				, status_code = '.$status.'
				, etag_md5 = '.$etag.'
				, last_modified = '.$last_modified.'
				, last_access = NOW(), last_update = NOW()
				, character_encoding = '.$encoding.'
		';
		if ($content_type)
			$sql .= ' , content_type = "'.$content_type.'"';
		if (!empty($zz_page['content_length'])) 
			$sql .= ' , content_length = '.$zz_page['content_length'];
		$sql .= ' WHERE uri_id = '.$uri_id;
		$result = wrap_db_query($sql, E_USER_NOTICE);
	} else {
		$sql = 'INSERT INTO /*_PREFIX_*/_uris (uri_scheme, uri_host, uri_path,
			uri_query, content_type, character_encoding, content_length,
			status_code, etag_md5, last_modified, hits, first_access,
			last_access, last_update) VALUES ("'.$scheme.'", "'.$host.'", 
			"'.$path.'", '.$query.', "'.$content_type.'",
			'.$encoding.', '.$zz_page['content_length'].', '.$status.',
			'.$etag.', '.$last_modified.', 1, NOW(), NOW(), NOW())';
		$result = wrap_db_query($sql, E_USER_NOTICE);
	}
	return true;
}

/**
 * Get rid of unwanted query strings
 * 
 * since we do not use session-IDs in the URL, get rid of these since sometimes
 * they might be used for session_start()
 * e. g. GET http://example.com/?PHPSESSID=5gh6ncjh00043PQTHTTGY%40DJJGV%5D
 * @param array $url ($zz_page['url'])
 * @param array $objectionable_qs key names of query strings
 * @todo get objectionable querystrings from setting
 */
function wrap_remove_query_strings($url, $objectionable_qs = array()) {
	if (empty($url['full']['query'])) return $url;
	if (empty($objectionable_qs)) {
		$objectionable_qs = array('PHPSESSID');
	}
	if (!is_array($objectionable_qs)) {
		$objectionable_qs = array($objectionable_qs);
	}
	parse_str($url['full']['query'], $query);
	// furthermore, keys with % signs are not allowed (propably errors in
	// some parsing script)
	foreach (array_keys($query) AS $key) {
		if (strstr($key, '%')) $objectionable_qs[] = $key;
	}
	if ($remove = array_intersect(array_keys($query), $objectionable_qs)) {
		foreach ($remove as $key) {
			unset($query[$key]);
			unset($_GET[$key]);
			unset($_REQUEST[$key]);
		}
		$url['full']['query'] = http_build_query($query);
		$url['redirect'] = true;
	}
	return $url;
}

/*
 * --------------------------------------------------------------------
 * HTTP: checks
 * --------------------------------------------------------------------
 */

/**
 * Stops execution of script, check for redirects to other pages,
 * includes http error pages
 * 
 * The execution of the CMS will be stopped. The script test if there's
 * an entry for the URL in the redirect table to redirect to another page
 * If that's true, 301 or 302 codes redirect pages, 410 redirect to gone.
 * if no error code is defined, a 404 code and the corresponding error page
 * will be shown
 * @param int $statuscode HTTP Status Code, default value is 404
 * @param string $error_msg (optional, error message for user)
 * @param array $page (optional, if normal output shall be shown, not error msg)
 * @return exits function with a redirect or an error document
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_quit($statuscode = 404, $error_msg = '', $page = array()) {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;

	$page['status'] = $statuscode;
	$no_redirection = array(304, 412, 416);
	if (!in_array($statuscode, $no_redirection)) {
		$redir = wrap_check_redirects($zz_page['url']);
		if ($redir) $page['status'] = $redir['code'];
	}

	// Check redirection code
	switch ($page['status']) {
	case 301:
	case 302:
	case 303:
	case 307:
		// (header 302 is sent automatically if using Location)
		wrap_http_status_header($page['status']);
		$field_name = wrap_sql('redirects_new_fieldname');
		$new = parse_url($redir[$field_name]);
		if (!empty($new['scheme'])) {
			$new = $redir[$field_name];
		} else {
			$new = $zz_setting['host_base'].$zz_setting['base'].$redir[$field_name];
		}
		header('Location: '.$new);
		exit;
	case 304:
	case 412:
	case 416:
		wrap_http_status_header($page['status']);
		header('Content-Length: 0');
		exit;
	default: // 4xx, 5xx
		// save error code for later access to avoid infinite recursion
		if (empty($zz_page['error_code'])) {
			$zz_page['error_code'] = $statuscode;
		}
		if ($error_msg) {
			if (empty($zz_page['error_msg'])) $zz_page['error_msg'] = '';
			if (empty($zz_page['error_html']))
				$zz_page['error_html'] = '<p class="error">%s</p>';
			$zz_page['error_msg'] .= sprintf($zz_page['error_html'], $error_msg);
		}
		wrap_errorpage($page, $zz_page);
		exit;
	}
}

/**
 * sends a HTTP status header corresponding to server settings and HTTP version
 *
 * @param int $code
 * @return bool true if header was sent, false if not
 * @see zz_http_status_header() (duplicate function)
 */
function wrap_http_status_header($code) {
	// Set protocol
	$protocol = $_SERVER['SERVER_PROTOCOL'];
	if (!$protocol) $protocol = 'HTTP/1.0'; // default value
	if (substr(php_sapi_name(), 0, 3) == 'cgi') $protocol = 'Status:';
	
	if ($protocol === 'HTTP/1.0' AND in_array($code, array(302, 303, 307))) {
		header($protocol.' 302 Moved Temporarily');
		return true;
	}
	$status = wrap_http_status_list($code);
	if ($status) {
		header($protocol.' '.$status['code'].' '.$status['text']);
		return true;
	}
	return false;
}

/**
 * reads HTTP status codes from http-statuscodes.txt
 *
 * @global array $zz_setting
 *		'core'
 * @return array $codes
 */
function wrap_http_status_list($code) {
	global $zz_setting;
	$status = array();
	
	// read error codes from file
	$pos[0] = 'code';
	$pos[1] = 'text';
	$pos[2] = 'description';
	$codes_from_file = file($zz_setting['core'].'/http-statuscodes.txt');
	foreach ($codes_from_file as $line) {
		if (substr($line, 0, 1) == '#') continue;	// Lines with # will be ignored
		elseif (!trim($line)) continue;				// empty lines will be ignored
		if (substr($line, 0, 3) != $code) continue;
		$values = explode("\t", trim($line));
		$i = 0;
		$code = '';
		foreach ($values as $val) {
			if (trim($val)) {
				if (!$i) $code = trim($val);
				$status[$pos[$i]] = trim($val);
				$i++;
			}
		}
		if ($i < 3) {
			for ($i; $i < 3; $i++) {
				$status[$pos[$i]] = '';
			}
		}
	}
	return $status;
}

/**
 * Checks if HTTP request should be HTTPS request instead and vice versa
 * 
 * Function will redirect request to the same URL except for the scheme part
 * Attention: POST variables will get lost
 * @param array $zz_page Array with full URL in $zz_page['url']['full'], 
 *		this is the result of parse_url()
 * @param array $zz_setting settings, 'ignore_scheme' ignores redirect
 *		and 'protocol' defines the protocol wanted (http or https)
 * @return redirect header
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_check_https($zz_page, $zz_setting) {
	// if it doesn't matter, get out of here
	if ($zz_setting['ignore_scheme']) return true;

	// change from http to https or vice versa
	// attention: $_POST will not be preserved
	if (!empty($_SERVER['HTTPS']) AND $_SERVER['HTTPS'] === 'on') {
		if ($zz_setting['protocol'] === 'https') return true;
	} else {
		if ($zz_setting['protocol'] === 'http') return true;
	}
	header('Location: '.$zz_setting['protocol'].'://'.$zz_page['url']['full']['host']
		.$zz_setting['base'].$zz_page['url']['full']['path']
		.(!empty($zz_page['url']['full']['query']) ? '?'.$zz_page['url']['full']['query'] : ''));
	exit;
}

/**
 * checks the HTTP request made, builds URL
 * sets language according to URL and request
 *
 * @global array $zz_conf
 * @global array $zz_setting
 * @global array $zz_page
 */
function wrap_check_request() {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;

	// check REQUEST_URI
	// Base URL, allow it to be set manually (handle with care!)
	// e. g. for Content Management Systems without mod_rewrite or websites in subdirectories
	if (empty($zz_page['url']['full'])) {
		$zz_page['url']['full'] = parse_url($zz_setting['host_base'].$_SERVER['REQUEST_URI']);
		// in case, some script requests GET ? HTTP/1.1 or so:
		if (empty($zz_page['url']['full']['path'])) {
			$zz_page['url']['full']['path'] = '/';
			$zz_page['url']['redirect'] = true;
		}
	}

	// check REQUEST_METHOD, quit if inappropriate
	// $zz_page['url'] needed for wrap_quit()
	wrap_check_http_request_method();

	// get rid of unwanted query strings, set redirect if necessary
	$zz_page['url'] = wrap_remove_query_strings($zz_page['url']);

	// check language
	wrap_set_language();

	// Relative linking
	if (empty($zz_page['deep'])) {
		if (!empty($zz_page['url']['full']['path']))
			$zz_page['deep'] = str_repeat('../', (substr_count('/'.$zz_page['url']['full']['path'], '/') -2));
		else
			$zz_page['deep'] = '/';
	}
}

/**
 * Test HTTP REQUEST method
 * 
 * @global array $zz_setting
 * @return void
 */
function wrap_check_http_request_method() {
	global $zz_setting;
	if (in_array($_SERVER['REQUEST_METHOD'], $zz_setting['http']['allowed']))
		return true;
	if (in_array($_SERVER['REQUEST_METHOD'], $zz_setting['http']['not_allowed'])) {
		wrap_quit(405);	// 405 Not Allowed
	}
	wrap_quit(501); // 501 Not Implemented
}

/*
 * --------------------------------------------------------------------
 * HTTP: send ressources
 * --------------------------------------------------------------------
 */

/**
 * sends a file to the browser from a directory below document root
 *
 * @param array $file
 *		'name' => string full filename; 'etag' string (optional) ETag-value for 
 *		header; 'cleanup' => bool if file shall be deleted after sending it;
 *		'cleanup_folder' => string name of folder if it shall be deleted as well
 *		'send_as' => send filename under a different name (default: basename)
 *		'error_code' => HTTP error code to send in case of file not found error
 *		'error_msg' => additional error message that appears on error page,
 *		'etag_generate_md5' => creates 'etag' if not send with MD5
 * @global array $zz_conf
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @todo send pragma public header only if browser that is affected by this bug
 * @todo implement Ranges for bytes
 */
function wrap_file_send($file) {
	global $zz_conf;
	global $zz_page;
	if (!file_exists($file['name'])) {
		if (!empty($file['error_code'])) {
			if (!empty($file['error_msg'])) {
				global $zz_page;
				$zz_page['error_msg'] = $file['error_msg'];
			}
			wrap_quit($file['error_code']);
		}
		wrap_file_cleanup($file);
		return false;
	}
	if (empty($file['send_as'])) $file['send_as'] = basename($file['name']);
	$suffix = substr($file['name'], strrpos($file['name'], ".") +1);

	// Accept-Ranges HTTP header
	header('Accept-Ranges: bytes');

	// Content-Length HTTP header
	$zz_page['content_length'] = sprintf("%u", filesize($file['name']));
	header('Content-Length: '.$zz_page['content_length']);
	// Maybe the problem is we are running into PHPs own memory limit, so:
	if ($zz_page['content_length'] + 1 > wrap_return_bytes(ini_get('memory_limit'))
		&& intval($zz_page['content_length'] * 1.5) <= 1073741824) { 
		// Not higher than 1GB
		ini_set('memory_limit', intval($zz_page['content_length'] * 1.5));
	}

	// Content-Type HTTP header
	// Canonicalize suffices
	$suffix_map = array(
		'jpg' => 'jpeg',
		'tif' => 'tiff'
	);
	if (in_array($suffix, array_keys($suffix_map))) $suffix = $suffix_map[$suffix];
	// Read mime type from database
	$sql = sprintf(wrap_sql('filetypes'), $suffix);
	$zz_page['content_type'] = wrap_db_fetch($sql, '', 'single value');
	if (!$zz_page['content_type']) $zz_page['content_type'] = 'application/octet-stream';
	header('Content-Type: '.$zz_page['content_type']);

	// ETag HTTP header
	if (!empty($file['etag_generate_md5']) AND empty($file['etag'])) {
		$file['etag'] = md5_file($file['name']);
	}
	if (!empty($file['etag'])) {
		wrap_if_none_match($file['etag'], $file);
	}
	
	// Last-Modified HTTP header
	wrap_if_modified_since(filemtime($file['name']), $file);

	// Remove some HTTP headers PHP might send because of SESSION
	// @todo: do some tests if this is okay
	// @todo: set sensible Expires header, according to age of file
	if (!empty($_SESSION)) {
		header_remove('Expires');
		header_remove('Pragma');
		// Cache-Control header private as in session_cache_limiter()
		header(sprintf('Cache-Control: private, max-age=%s, pre-check=%s',
			session_cache_expire() * 60, session_cache_expire() * 60));
	}

	// Download files if generic mimetype
	// or HTML, since this might be of unknown content with javascript or so
	$download_filetypes = array('application/octet-stream', 'application/zip', 
		'text/html', 'application/xhtml+xml');
	if (in_array($zz_page['content_type'], $download_filetypes)) {
		header('Content-Disposition: attachment; filename="'.$file['send_as'].'"');
			// d. h. bietet save as-dialog an, geht nur mit application/octet-stream
		header('Pragma: public');
			// dieser Header widerspricht im Grunde dem mit SESSION ausgesendeten
			// Cache-Control-Header
			// Wird aber für IE 5, 5.5 und 6 gebraucht, da diese keinen Dateidownload
			// erlauben, wenn Cache-Control gesetzt ist.
			// http://support.microsoft.com/kb/323308/de
	} else {
		header('Content-Disposition: inline; filename="'.$file['send_as'].'"');
	}
	
	wrap_send_ressource('file', $file);
}

/**
 * does cleanup after a file was sent
 *
 * @param array $file
 * @return bool
 */
function wrap_file_cleanup($file) {
	if (empty($file['cleanup'])) return false;
	// clean up
	unlink($file['name']);
	if (!empty($file['cleanup_dir'])) rmdir($file['cleanup_dir']);
	return true;
}

/**
 * sends a ressource via HTTP regarding some headers
 *
 * @param string $text content to be sent
 * @param string $type (optional, default html) HTTP content type
 * @param int $status (optional, default 200) HTTP status code
 * @param array $headers (optional) further HTTP headers
 * @global array $zz_conf
 * @global array $zz_setting
 * @return void
 */
function wrap_send_text($text, $type = 'html', $status = 200, $headers = array()) {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;

	$text = trim($text);

	if (!empty($zz_setting['gzip_encode'])) {
		header('Vary: Accept-Encoding');
	}
	header_remove('Accept-Ranges');

	// Content-Length HTTP header
	// might be overwritten later
	$zz_page['content_length'] = strlen($text);
	header('Content-Length: '.$zz_page['content_length']);

	// Content-Type HTTP header
	// Content-Disposition HTTP header
	$filename = '';
	switch ($type) {
	case 'html':
		$zz_page['content_type'] = 'text/html';
		if (!empty($zz_conf['character_set']))
			$zz_page['character_set'] = $zz_conf['character_set'];
		break;
	case 'json':
		$zz_page['content_type'] = 'application/json';
		$zz_page['character_set'] = 'utf-8';
		$filename = !empty($headers['filename']) ? $headers['filename'] : 'download.json';
		break;
	case 'kml':
		$zz_page['content_type'] = 'application/vnd.google-earth.kml+xml';
		$zz_page['character_set'] = 'utf-8';
		$filename = !empty($headers['filename']) ? $headers['filename'] : 'download.kml';
		break;
	case 'mediarss':
		$zz_page['content_type'] = 'application/xhtml+xml';
		$zz_page['character_set'] = 'utf-8';
		break;
	case 'xml':
		$zz_page['content_type'] = 'application/xml';
		$zz_page['character_set'] = $zz_conf['character_set'];
		break;
	case 'txt':
		$zz_page['content_type'] = 'text/plain';
		$zz_page['character_set'] = $zz_conf['character_set'];
		break;
	case 'csv':
		$zz_page['content_type'] = 'text/csv';
		$zz_page['character_set'] = $zz_conf['character_set'];
		$filename = !empty($headers['filename']) ? $headers['filename'] : 'download.csv';
		break;
	case 'ics':
		$zz_page['content_type'] = 'text/calendar';
		$zz_page['character_set'] = 'utf-8';
		$filename = !empty($headers['filename']) ? $headers['filename'] : 'download.ics';
		break;
	default:
		break;
	}
	if ($filename) {
		header(sprintf('Content-Disposition: attachment; filename="%s"', $filename));
	}
	if (!empty($zz_page['content_type'])) {
		if (!empty($zz_page['character_set'])) {
			header(sprintf('Content-Type: %s; charset=%s', $zz_page['content_type'], 
				$zz_page['character_set']));
		} else {
			header(sprintf('Content-Type: %s', $zz_page['content_type']));
		}
	}

	// ETag HTTP header
	// check whether content is identical to previously sent content
	// @todo: do not send 304 immediately but with a Last-Modified header
	$etag_header = array();
	if ($status == 200) {
		// only compare ETag in case of status 2xx
		$zz_page['etag'] = md5($text);
		$etag_header = wrap_if_none_match($zz_page['etag']);
	}

	$last_modified_time = time();

	// Caching?
	if (!empty($zz_setting['cache']) AND empty($_SESSION['logged_in'])
		AND empty($_POST) AND $status == 200) {
		$cache_saved = wrap_cache_ressource($text, $zz_page['etag']);
		if (!$cache_saved) {
			// identical cache file exists
			// set older value for Last-Modified header
			$doc = wrap_cache_filename();
			if ($time = filemtime($doc)) // if it exists
				$last_modified_time = $time;
		}
	} elseif (!empty($zz_setting['cache'])) {
		wrap_cache_delete($status);
	}

	// Last Modified HTTP header
	wrap_if_modified_since($last_modified_time);

	wrap_send_ressource('memory', $text, $etag_header);
}

/**
 * Sends the ressource to the browser after all headers have been sent
 *
 * @param string $type 'memory' = content is in memory, 'file' => is in file
 * @param mixed $content full content or array $file, depending on $type
 * @param array $etag_header
 */
function wrap_send_ressource($type, $content, $etag_header = array()) {
	global $zz_setting;
	global $zz_page;

	header_remove('X-Powered-By');

	// HEAD HTTP request
	if (stripos($_SERVER['REQUEST_METHOD'], 'HEAD') !== FALSE) {
		if ($type === 'file') wrap_file_cleanup($content);
		wrap_log_uri();
		exit;
	}

	// Since we do gzip compression on the fly, we cannot guarantee
	// that ranges work with gzip compression (bytes will be different).
	// Using gzip won't give you any advantage for most binary files, so
	// we do not use gzip for these. On the other hand, we won't allow ranges
	// for text content. So we do not have gzip and ranges at the same time.

	// Text output, with gzip, without ranges
	if ($type === 'memory' OR !empty($content['gzip'])) {
		wrap_log_uri();
		if ($type === 'file') {
			$content = file_get_contents($content['name']);
		}
		// output content
		if (!empty($zz_setting['gzip_encode'])) {
			wrap_send_gzip($content, $etag_header);
		} else {
			echo $content;
		}
		exit;
	}
	
	// Binary output, without gzip, with ranges
	// Ranges HTTP header field
	$chunksize = 1 * (1024 * 16); // how many bytes per chunk
	$ranges = wrap_ranges_check($zz_page);
	if (!$ranges) {
		wrap_log_uri();
		// no ranges: resume to normal

		// following block and server lighttpd: replace with
		// header('X-Sendfile: '.$content['name']);
	
		// If it's a large file we don't want the script to timeout, so:
		set_time_limit(300);
		// If it's a large file, readfile might not be able to do it in one go, so:
		if ($zz_page['content_length'] > $chunksize) {
			$handle = fopen($content['name'], 'rb');
			$buffer = '';
			ob_start();
			while (!feof($handle) AND !connection_aborted()) {
				$buffer = fread($handle, $chunksize);
				print $buffer;
				ob_flush();
				flush();
			}
			fclose($handle);
		} else {
			readfile($content['name']);
		}
	} else {
		if (count($ranges) !== 1) {
			$boundary = 'THIS_STRING_SEPARATES_'.md5(time());
			header(sprintf('Content-Type: multipart/byteranges; boundary=%s', $boundary));
			$bottom = "--".$boundary."--\r\n";
			$content_length_total = strlen($bottom);
			$separator = "\r\n\r\n";
			$content_length_total += strlen($separator) * count($ranges);
		}
		$handle = fopen($content['name'], 'rb');
		$top = array();
		foreach ($ranges as $range) {
			$length = $range['end'] - $range['start'] + 1;
			$content_range = sprintf('Content-Range: bytes %u-%u/%u', $range['start'],
				$range['end'], $zz_page['content_length']);
			$content_length = sprintf('Content-Length: %u', $length);
			if (count($ranges) !== 1) {
				$content_length_total += $length;
				$top_text = "--".$boundary."\r\n"
					.'Content-Type: '.$zz_page['content_type']."\r\n"
					.$content_range."\r\n"
					.$content_length."\r\n"
					."\r\n";
				$content_length_total += strlen($top_text);
				$top[] = $top_text;
			} else {
				header($content_range);
				header($content_length);
			}
		}
		if (count($ranges) !== 1) {
			header('Content-Length: '.$content_length_total);
		}
		foreach ($ranges as $index => $range) {
			if (count($ranges) !== 1) {
				echo $top[$index];
			}
			$current = $range['start'];
			fseek($handle, $current, SEEK_SET);
			while (!feof($handle) AND $current < $range['end'] AND !connection_aborted()) {
				print fread($handle, min($chunksize, $range['end'] - $current + 1));
				$current += $chunksize;
				flush();
			}
			if (count($ranges) !== 1) {
				echo $separator;
			}
		}
		if (count($ranges) !== 1) echo $bottom;
		fclose($handle);
	}
	wrap_file_cleanup($content);
	exit;
}

/**
 * Checks whether ressource should be sent in ranges of bytes
 *
 * @param array $zz_page
 * @return array
 */
function wrap_ranges_check($zz_page) {
	if (empty($_SERVER['HTTP_RANGE'])) return array();

	// check if Range is syntactically valid
	// if invalid, return 200 + full content
	// Range: bytes=10000-49999,500000-999999,-250000
	if (!preg_match('~^bytes=\d*-\d*(,\d*-\d*)*$~', $_SERVER['HTTP_RANGE'])) {
		header('Content-Range: bytes */'.$zz_page['content_length']);
		wrap_quit(416);
	}

	if (!empty($_SERVER['HTTP_IF_UNMODIFIED_SINCE']) OR !empty($_SERVER['HTTP_IF_MATCH'])) {
		// Range + (If-Unmodified-Since OR If-Match), have already been checked
		// go on
	} elseif (!empty($_SERVER['HTTP_IF_RANGE'])) {
		// Range + If-Range (ETag or Date)
		$etag_header = wrap_etag_header(!empty($zz_page['etag']) ? $zz_page['etag'] : '');
		$time = wrap_date($_SERVER['HTTP_IF_RANGE'], 'rfc1123->timestamp');
		if ($_SERVER['HTTP_IF_RANGE'] === $etag_header['std']
			OR $_SERVER['HTTP_IF_RANGE'] === $etag_header['gz']) {
			// go on
		} elseif ($time AND $time >= $zz_page['last_modified']) {
			// go on
		} else {
			// - no match: 200 + full content
			return array();
		}
	}
	
	// - if Range not valid	416 (Requested range not satisfiable), Content-Range: *
	// - else 206 + partial content
	$raw_ranges = explode(',', substr($_SERVER['HTTP_RANGE'], 6));
	$ranges = array();
	foreach ($raw_ranges as $range) {
		$parts = explode('-', $range);
		$start = $parts[0];
		if (!$start) $start = 0;
		$end = $parts[1];
		if (!$end) {
			$end = $zz_page['content_length'] - 1;
		} elseif ($end > $zz_page['content_length']) {
			$end = $zz_page['content_length'] - 1;
		}
        if ($start > $end) {
            header('Content-Range: bytes */'.$zz_page['content_length']);
			wrap_quit(416);
        }
        $ranges[] = array(
        	'start' => $start,
        	'end' => $end
        );
    }
	wrap_http_status_header(206);
	return $ranges;
}

/**
 * Send a ressource with gzip compression
 *
 * @param string $text content of ressource, not compressed
 * @param array $etag_header
 * @return void
 */
function wrap_send_gzip($text, $etag_header) {
	// start output
	ob_start();
	ob_start('ob_gzhandler');
	echo $text;
	ob_end_flush();  // The ob_gzhandler one
	if ($etag_header) {
		// only if HTTP status = 200
		foreach (headers_list() AS $header) {
			if (!wrap_substr($header, 'Content-Encoding: ')) continue;
			// overwrite ETag with -gz ending
			header('ETag: '.$etag_header['gz']);
		}
	}
	header('Content-Length: '.ob_get_length());
	ob_end_flush();  // The main one
}

/*
 * --------------------------------------------------------------------
 * Caching
 * --------------------------------------------------------------------
 */

/**
 * cache a ressource if it not exists or a stale cache exists
 *
 * @param string $text ressource to be cached
 * @param string $existing_etag
 * @param string $url (optional) URL to be cached, if not set, use internal URL
 * @param array $headers (optional), if not set use sent headers
 * @return bool false: no new cache file was written, true: new cache file created
 */
function wrap_cache_ressource($text, $existing_etag, $url = false, $headers = array()) {
	$host = wrap_cache_filename('domain', $url);
	if (!file_exists($host)) {
		$success = mkdir($host);
		if (!$success) wrap_error(sprintf('Could not create cache directory %s.', $host), E_USER_NOTICE);
	}
	$doc = wrap_cache_filename('url', $url);
	$head = wrap_cache_filename('headers', $url);
	if (file_exists($head)) {
		// check if something with the same ETag has already been cached
		// no need to rewrite cache, it's possible to send a Last-Modified
		// header along
		$etag = wrap_cache_get_header($head, 'ETag');
		if ($etag === $existing_etag) {
			return false;
		}
	}
	// save document
	file_put_contents($doc, $text);
	// save headers
	// without '-gz'
	if (!$headers) {
		header_remove('X-Powered-By');
		$headers = headers_list();
	}
	file_put_contents($head, implode("\r\n", $headers));
	return true;
}

/**
 * Delete cached files which now return a 4xx-error code
 *
 * @param int $status HTTP Status Code
 * @param string $url (optional)
 * @return bool true: cache was deleted; false: cache remains intact
 */
function wrap_cache_delete($status, $url = false) {
	$delete_cache = array(401, 402, 403, 404, 410);
	if (!in_array($status, $delete_cache)) return false;

	$doc = wrap_cache_filename('url', $url);
	$head = wrap_cache_filename('headers', $url);
	if (file_exists($head)) unlink($head);
	if (file_exists($doc)) unlink($doc);
	return true;
}

/**
 * creates ETag-Headers, checks against If-None-Match, If-Match
 *
 * @param string $etag
 * @param array $file (optional, for cleanup only)
 * @return mixed $etag_header (only if none match)
 *		!$file: array 'std' = standard header, 'gz' = header with gzip
 *		$file: string standard header
 * @see RFC 2616 14.24
 */
function wrap_if_none_match($etag, $file = array()) {
	$etag_header = wrap_etag_header($etag);
	// Check If-Match header field
	if (isset($_SERVER['HTTP_IF_MATCH'])) {
		if (!wrap_etag_check($etag_header, $_SERVER['HTTP_IF_MATCH'])) {
			wrap_quit(412);
		}
	}
	if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
		if (wrap_etag_check($etag_header, $_SERVER['HTTP_IF_NONE_MATCH'])) {
			// HTTP requires to check Last-Modified date here as well
			// but we ignore it because if the Entity is identical, it does
			// not really matter if the modification date is different
			if (in_array($_SERVER['REQUEST_METHOD'], array('GET', 'HEAD'))) {
				if ($file) wrap_file_cleanup($file);
				wrap_log_uri();
				header('ETag: '.$etag_header['std']);
				wrap_quit(304);
			} else {
				wrap_quit(412);
			}
		}
	}
	// Neither header field affects request
	// ETag std header might be overwritten by gzip-ETag later on
	header('ETag: '.$etag_header['std']);
	return $etag_header;
 }

/**
 * compares an ETag of a ressource to a HTTP request
 *
 * @param array $etag_header
 * @string $http_request, e. g. If-None-Match or If-Match
 * @return bool
 */
function wrap_etag_check($etag_header, $http_request) {
	if ($http_request === '*') {
		// If-Match: * / If-None-Match: *
		if ($etag_header) return true;
		else return false;
	}
	$entity_tags = explode(',', $http_request);
	// If-Match: "xyzzy"
	// If-Match: "xyzzy", "r2d2xxxx", "c3piozzzz"
	foreach ($entity_tags as $entity_tag) {
		$entity_tag = trim($entity_tag);
		if ($entity_tag === $etag_header['std']) return true;
		elseif ($entity_tag === $etag_header['gz']) return true;
	}
	return false;
}

/**
 * creates ETag header value from given ETag for uncompressed and gzip
 * ressources
 * W/ ETags are not supported
 *
 * @param string $etag
 * @return array
 */
function wrap_etag_header($etag) {
	$etag_header = array(
		'std' => sprintf('"%s"', $etag),
		'gz' => sprintf('"%s"', $etag.'-gz')
	);
	return $etag_header;
}

/**
 * creates Last-Modified-Header, checks against If-Modified-Since
 * and If-Unmodified-Since
 * respond to If Modified Since with 304 header if appropriate
 *
 * @param int $time (timestamp)
 * @param array $file (optional)
 * @return string time formatted for Last-Modified
 */
function wrap_if_modified_since($time, $file = array()) {
	global $zz_page;
	// Cache time: 'Sa, 05 Jun 2004 15:40:28'
	$zz_page['last_modified'] = wrap_date($time, 'timestamp->rfc1123');
	// Check If-Unmodified-Since
	if (isset($_SERVER['HTTP_IF_UNMODIFIED_SINCE'])) {
		$requested_time = wrap_date(
			$_SERVER['HTTP_IF_UNMODIFIED_SINCE'], 'rfc1123->timestamp'
		);
		if ($requested_time AND $time > $requested_time) {
			wrap_quit(412);
		}
	}
	// Check If-Modified-Since
	if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
		$requested_time = wrap_date(
			$_SERVER['HTTP_IF_MODIFIED_SINCE'], 'rfc1123->timestamp'
		);
		if ($time <= $requested_time) {
			header('Last-Modified: '.$zz_page['last_modified']);
			if ($file) wrap_file_cleanup($file);
			wrap_log_uri();
			wrap_quit(304);
		}
	}
	header('Last-Modified: '.$zz_page['last_modified']);
	return $zz_page['last_modified'];
}

/**
 * send cached data instead of data from database
 * (e. g. if connection is broken)
 *
 * @param int $age maximum acceptable cache age in seconds
 * @global array $zz_setting
 * @return void
 */
function wrap_send_cache($age = 0) {
	global $zz_setting;
	global $zz_page;
	global $zz_conf;
	
	// Some cases in which we do not cache
	if (empty($zz_setting['cache'])) return false;
	if (!empty($_SESSION)) return false;
	if (!empty($_POST)) return false;

	$files = array(wrap_cache_filename(), wrap_cache_filename('headers'));
	if (!file_exists($files[0]) OR !file_exists($files[1])) return false;

	if ($age) {
		// return cached files if they're still fresh enough
		$fresh = wrap_cache_freshness($files, $age);
		if (!$fresh) return false;
	}

	// get cached headers, send them as headers and write them to $zz_page
	// Content-Type HTTP header etc.
	wrap_cache_get_header($files[1], '', true);

	if (!empty($zz_setting['gzip_encode'])) {
		header('Vary: Accept-Encoding');
	}

	// Content-Length HTTP header
	if (empty($zz_page['content_length'])) {
		$zz_page['content_length'] = sprintf("%u", filesize($files[0]));
		header('Content-Length: '.$zz_page['content_length']);
	}

	// ETag HTTP header
	if (empty($zz_page['etag'])) {
		$zz_page['etag'] = md5_file($files[0]);
	}
	$etag_header = wrap_if_none_match($zz_page['etag']);

	// Last-Modified HTTP header
	if (empty($zz_page['last_modified'])) {
		$last_modified_time = filemtime($files[0]);
	} else {
		$last_modified_time = wrap_date(
			$zz_page['last_modified'], 'rfc1123->timestamp'
		);
	}
	wrap_if_modified_since($last_modified_time);

	// Log if cached version is used because there's no connection to database
	if (empty($zz_conf['db_connection'])) {
		wrap_error('No connection to SQL server. Using cached file instead.', E_USER_NOTICE);
	}

	$file = array(
		'name' => $files[0],
		'gzip' => true
	);
	wrap_send_ressource('file', $file, $etag_header);
}

/**
 * @param array $files list of files
 * @param int $age (negative -1: don't care about freshness; other values: check)
 * @return bool false: not fresh, true: cache is fresh
 */
function wrap_cache_freshness($files, $age) {
	// -1: cache will always considered to be fresh
	if ($age === -1) return true;
	// 0 or positive values: cache files will be checked
	foreach ($files as $file) {
		if ((filemtime($file) + $age) < time()) return false;
	}
	return true;
}

/**
 * get header value from cache file
 *
 * @param string $file filename
 * @param string $type name of header
 * @param bool $send send headers or not
 * @return string $value
 */
function wrap_cache_get_header($file, $type, $send = false) {
	static $sent;
	global $zz_page;
	$headers = file_get_contents($file);
	if (substr($headers, 0, 2) == '["') {
		// @deprecated: used JSON format instead of plain text for headers
		$headers = json_decode($headers);
		file_put_contents($file, implode("\r\n", $headers));
	} else {
		$headers = explode("\r\n", $headers);
	}
	if (!$headers) {
		wrap_error(sprintf('Cache file for headers has no content (%s)', $file), E_USER_NOTICE);
		return '';
	}
	$value = '';
	foreach ($headers as $header) {
		$req_header = substr($header, 0, strpos($header, ': '));
		$req_value = trim(substr($header, strpos($header, ': ')+1));
		if (!$sent AND $send) {
			header($header);
			$zz_page[str_replace('-', '_', strtolower($req_header))] = $req_value;
		}
		if ($req_header == $type) {
			// check if respond with 304
			$value = substr($header, strlen($type) + 2);
			if (substr($value, 0, 1) === '"' AND substr($value, -1) === '"') {
				$value = substr($value, 1, -1);
			}
		}
	}
	$sent = true;
	return $value;
}

/**
 * returns filename for URL for caching
 *
 * @param string $type (optional) default: 'url'; 'headers', 'domain'
 * @param string $url (optional) URL to cache, if not set, internal URL will be used
 * @global array $zz_page ($zz_page['url']['full'])
 * @global array $zz_setting 'cache'
 * @return string filename
 */
function wrap_cache_filename($type = 'url', $url = '') {
	global $zz_page;
	global $zz_setting;

	if (!$url) {
		$url = $zz_page['url']['full'];
		$base = $zz_setting['base'];
		if ($base == '/') $base = '';
	} else {
		$url = parse_url($url);
		$base = '';
	}
	$file = $zz_setting['cache'].'/'.urlencode($url['host']);
	if ($type === 'domain') return $file;

	if (!empty($url['query'])) {
		// [ and ] are equal to %5B and %5D, so replace them
		$url['query'] = str_replace('%5B', '[', $url['query']);
		$url['query'] = str_replace('%5D', ']', $url['query']);
		$url['path'] .= '?'.$url['query'];
	}
	$file .= '/'.urlencode($base.$url['path']);
	if ($type === 'url') return $file;

	$file .= '.headers';
	if ($type === 'headers') return $file;

	return false;
}

/*
 * --------------------------------------------------------------------
 * Common functions
 * --------------------------------------------------------------------
 */

/**
 * returns integer byte value from PHP shorthand byte notation
 *
 * @param string $val
 * @return int
 * @see zz_return_bytes(), identical
 */
function wrap_return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    switch($last) {
        // The 'G' modifier is available since PHP 5.1.0
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }

    return $val;
}

/**
 * checks if a substring from beginning or end of string matches given string
 *
 * @param string $string = check against this string
 * @param string $substring = this must be beginning of string
 * @param string $mode: begin: check from begin; end: check from end
 * @param bool true: first letters of $string match $substring
 */
function wrap_substr($string, $substring, $mode = 'begin') {
	switch ($mode) {
	case 'begin':
		if (substr($string, 0, strlen($substring)) === $substring) return true;
		break;
	case 'end':
		if (substr($string, -strlen($substring)) === $substring) return true;
		break;
	}
	return false;
}

/**
 * gets setting from configuration (default: zz_setting)
 *
 * @param string $key
 * @return mixed $setting (if not found, returns NULL)
 */
function wrap_get_setting($key) {
	if (function_exists('my_get_setting')) {
		return my_get_setting($key);
	} else {
		global $zz_setting;
		if (!isset($zz_setting[$key])) return NULL;
		return $zz_setting[$key];
	}
}

/**
 * get list of ids and levels to show a hierarchical output
 *
 * @param array $data (indexed by ID = $values)
 * @param string $main_field_name field name of main ID (must be in $values)
 * @param mixed $top_id (optional; 'NULL' = default, int = subtree)
 * @return array $id => $level, sorted as $data is sorted
 */
function wrap_hierarchy($data, $main_field_name, $top_id = 'NULL') {
	$indexed_by_main = array();
	foreach ($data as $id => $values) {
		if (!$values[$main_field_name]) $values[$main_field_name] = 'NULL';
		$indexed_by_main[$values[$main_field_name]][$id] = $values;
	}
	if (!$indexed_by_main) return $indexed_by_main;
	return wrap_hierarchy_recursive($indexed_by_main, $top_id);
}

/**
 * read hierarchy recursively
 *
 * @param array $indexed_by_main list of main_id => $id => $values
 * @param mixed $top_id (optional; 'NULL' = default, int = subtree)
 * @param int $level
 * @return array $id => $level, sorted as $data is sorted (parts of it)
 */
function wrap_hierarchy_recursive($indexed_by_main, $top_id, $level = 0) {
	$keys = array_keys($indexed_by_main[$top_id]);
	foreach ($keys as $id) {
		$hierarchy[$id] = $level;
		if (!empty($indexed_by_main[$id])) {
			// += preserves keys opposed to array_merge()
			$hierarchy += wrap_hierarchy_recursive($indexed_by_main, $id, $level + 1);
		}
	}
	return $hierarchy;
}

/**
 * Creates a folder and its top folders if neccessary
 *
 * @param string $folder (may contain .. and . which will be resolved)
 * @param bool true: folder creation was successful
 */
function wrap_mkdir($folder) {
	if (is_dir($folder)) return true;
	$subfolders = explode('/', $folder);
	$current_folder = '';
	foreach ($subfolders as $subfolder) {
		if (!$subfolder) continue;
		if ($subfolder === '..') {
			$current_folder = substr($current_folder, 0, strrpos($current_folder, '/'));
		} elseif ($subfolder === '.') {
			$current_folder .= '';
		} else {
			$current_folder .= '/'.$subfolder;
		}
		if (!file_exists($current_folder)) {
			$success = mkdir($current_folder);
			if (!$success) {
				wrap_error(sprintf('Could not create folder %s.', $current_folder), E_USER_ERROR);
				return false;
			}
		}
	}
	return true;
}

/**
 * header_remove for old PHP 5.2
 *
 * @param string $header
 * @deprecated
 */
if (!function_exists('header_remove')) {
	function header_remove($header) {
		header($header.':');
	}
}

?>