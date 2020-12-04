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
 * @copyright Copyright © 2007-2020 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/*
 * --------------------------------------------------------------------
 * External Libraries
 * --------------------------------------------------------------------
 */

function wrap_include_ext_libraries() {
	global $zz_setting;
	static $included;
	if ($included) return true;

	if (empty($zz_setting['ext_libraries'])) return false;
	foreach ($zz_setting['ext_libraries'] as $function) {
		if (file_exists($file = $zz_setting['lib'].'/'.$function.'.php')) 
			require_once $file;
		elseif (file_exists($file = $zz_setting['lib'].'/'.$function.'/'.$function.'.php'))
			require_once $file;
		else {
			$found = false;
			foreach ($zz_setting['modules'] as $module) {
				$file = $zz_setting['modules_dir'].'/'.$module.'/libraries/'.$function.'.inc.php';
				if (!file_exists($file)) continue;
				require_once $file;
				$found = true;
				break;
			}
			if (!$found) {
				wrap_error(sprintf(wrap_text('Required library %s does not exist.'), '`'.$function.'`'), E_USER_ERROR);
			}
		}
	}
	$included = true;
	return true;
}


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
	if (session_id()) {
		session_write_close();
		session_start();
		return true;
	}
	// change session_save_path
	if (!empty($zz_setting['session_save_path'])) {
		$success = wrap_mkdir($zz_setting['session_save_path']);
		if ($success) 
			session_save_path($zz_setting['session_save_path']);
	}
	// Cookie: httpOnly, i. e. no access for JavaScript if browser supports this
	$last_error = false;
	session_set_cookie_params(0, '/', $zz_setting['hostname'], $zz_setting['session_secure_cookie'], true);
	$last_error = error_get_last();
	// don't collide with other PHPSESSID on the same server, set own name:
	session_name('zugzwang_sid');
	$success = session_start();
	// try it twice, some providers have problems with ps_files_cleanup_dir()
	// accessing the /tmp-directory and failing temporarily with
	// insufficient access rights
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
	$_SESSION = [];
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

/**
 * checks if cookies are allowed and sets a session token to true if successful
 *
 * example for calling this function:
 * 	$page = wrap_session_check('clubedit');
 *	if ($page !== true) return $page;
 *
 * @param string $token name of the token
 * @return mixed
 *		array $page => cookies are not allowed, output message
 *		bool true => everything ok
 */
function wrap_session_check($token) {
	wrap_session_start();
	if (array_key_exists('no-cookie', $_GET)) {
		return wrap_session_cookietest_end($token);
	}
	if (empty($_SESSION[$token])) {
		// Cookietest durch redirect auf dieselbe URL mit ?cookie am Ende
		return wrap_session_cookietest_start($token);
	}
	session_write_close();
	return true;
}

/**
 * start a session and redirect to another URL with ?no-cookie to check if
 * the session is still active
 *
 * @param string $token name of the token
 * @return void redirect to another URL
 */
function wrap_session_cookietest_start($token) {
	global $zz_page;
	$_SESSION[$token] = true;
	$_SESSION['last_click_at'] = time();
	session_write_close();

	$url = $zz_page['url']['full'];
	if (empty($url['query'])) {
		$url['query'] = 'no-cookie';
	} else {
		$url['query'] .= '&no-cookie';
	}
	$url = wrap_glue_url($url);
	return brick_format('%%% redirect '.$url.' %%%');
}

/**
 * check if session exists and if yes, redirect to old URL
 *
 * @param string $token name of the token
 * @return mixed
 *		void redirect to old URL if everything is ok
 *		array $page if a cookie message should be sent back to user
 */
function wrap_session_cookietest_end($token) {
	global $zz_page;
	session_write_close();
	$url = $zz_page['url']['full'];
	parse_str($url['query'], $query);
	unset($query['no-cookie']);
	$url['query'] = http_build_query($query);
	$data['url'] = wrap_glue_url($url);
	if (!empty($_SESSION[$token])) {
		return brick_format('%%% redirect '.$data['url'].' %%%');
	}
	// return cookie missing message
	$page['dont_show_h1'] = true;
	$page['meta'][] = ['name' => 'robots', 'content' => 'noindex'];
	$page['breadcrumbs'][] = 'Cookies';
	$page['text'] = wrap_template('cookie', $data);
	return $page;
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
 * @return array $page
 */
function wrap_look_for_page($zz_page) {
	// no database connection or settings are missing
	if (!wrap_sql('pages')) wrap_quit(503); 

	global $zz_conf;
	$page = false;

	// Prepare URL for database request
	$url = wrap_read_url($zz_page['url']);
	// sometimes, bots add second / to URL, remove and redirect
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
			if (empty($page[$i])) {
				// if not found, remove path parts from URL
				if ($parameter[$i]) {
					$parameter[$i] = '/'.$parameter[$i]; // '/' as a separator for variables
					$my_url = substr($my_url, 0, -1); // remove '*'
				}
				if ($pos = strrpos($my_url, '/')) {
					$parameter[$i] = substr($my_url, $pos + 1).$parameter[$i];
					$my_url = substr($my_url, 0, $pos).'*';
				} elseif (($my_url OR $my_url === '0' OR $my_url === 0) AND substr($my_url, 0, 1) !== '_') {
					$parameter[$i] = $my_url.$parameter[$i];
					$my_url = '*';
				} else {
					break;
				}
			} else {
				// something was found, get out of here
				// but get placeholders as parameters as well!
				if (!empty($leftovers[$i])) {
					$parameter[$i] = implode('/', $leftovers[$i]).($parameter[$i] ? '/'.$parameter[$i] : '');
				}
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
	if (!empty($page['parameters'])) wrap_page_parameters($page['parameters']);
	if (!$page) return false;

	$page['parameter'] = $parameter[$i];
	$page['url'] = $url[$i];
	return $page;
}

/**
 * add page parameters to settings
 *
 * whitelist of possible parameters is generated from settings.cfg in modules
 * setting needs page_parameter = 1
 * @param string $params
 * @global array $zz_setting
 * @return bool
 */
function wrap_page_parameters($params) {
	global $zz_setting;
	
	parse_str($params, $params);
	if (!$params) return false;
	$cfg = wrap_setting_cfg();
	
	foreach ($params as $key => $value) {
		if (!array_key_exists($key, $cfg)) continue;
		if (empty($cfg[$key]['page_parameter'])) continue;
		$zz_setting[$key] = $value; 
	}
	return true;
}

/**
 * support some standard URLs if there’s no entry in webpages table for them
 *
 * @param array $url
 * @return mixed false: nothing found, array: $page
 */
function wrap_well_known_url($url) {
	global $zz_setting;

	switch ($url['path']) {
	case '/robots.txt':
		$page['content_type'] = 'txt';
		$page['text'] = '# robots.txt for '.$zz_setting['site'];
		$page['status'] = 200;
		return $page;
	case '/.well-known/change-password':
		if (empty($zz_setting['change_password_url'])) return false;
		wrap_redirect_change($zz_setting['change_password_url']);
	}
	return false;
}

/**
 * check if there's a layout or behaviour file in one of the modules
 * then send it out
 *
 * @param array $url_path ($zz_page['url']['full']['path'])
 * @global array $zz_setting
 *		array 'modules', string 'modules_dir', string 'layout_path',
 *		string 'behaviour_path'
 * @return
 */
function wrap_look_for_file($url_path) {
	global $zz_setting;
	if (empty($zz_setting['modules']) AND !$zz_setting['active_theme']) return false;
	if (!$url_path) return false;
	$folders = !empty($zz_setting['modules']) ? $zz_setting['modules'] : [];
	if ($zz_setting['active_theme']) array_unshift($folders, $zz_setting['active_theme']);

	if ($zz_setting['active_theme'] AND !empty($zz_setting['icon_paths'])) {
		if (in_array($url_path, $zz_setting['icon_paths'])) {
			$file['name'] = sprintf('%s/%s%s', $zz_setting['themes_dir'], $zz_setting['active_theme'], $url_path);
			if (file_exists($file['name'])) {
				$file['etag_generate_md5'] = true;
				wrap_file_send($file);
			}
		}
	}

	$paths = ['layout', 'behaviour'];
	foreach ($paths as $path) {
		if (empty($zz_setting[$path.'_path'])) continue;
		if (!wrap_substr($url_path, $zz_setting[$path.'_path'])) continue;
		$url_folders = explode('/', substr($url_path, strlen($zz_setting[$path.'_path'])));
		if (count($url_folders) < 2) continue;
		if (!in_array($url_folders[1], $folders)) continue;
		array_shift($url_folders);
		$module = array_shift($url_folders);
		$dir = ($module === $zz_setting['active_theme']) ? $zz_setting['themes_dir'] : $zz_setting['modules_dir'];
		$file['name'] = sprintf('%s/%s/%s/%s',
			$dir, $module, $path, implode('/', $url_folders));
		if (in_array($ext = wrap_file_extension($file['name']), ['css', 'js'])) {
			wrap_cache_allow_private();
			return $file['name'];
		}
		$file['etag_generate_md5'] = true;
		wrap_file_send($file);
	}
	return false;
}

/**
 * get file extension by filename
 *
 * @param string $filename
 * @return string
 */
function wrap_file_extension($file) {
	$file = explode('.', $file);
	return array_pop($file);
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
	if (empty($zz_page['url_placeholders'])) return [$full_url, []];
	// cut url in parts
	$url_parts[0] = explode('/', $full_url[0]);
	$i = 1;
	$leftovers = [];
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
	return [$full_url, $leftovers];
}

/**
 * Make canonical URLs
 * 
 * @param array $zz_page
 * @param array $page
 * @return array $url
 */
function wrap_check_canonical($zz_page, $page) {
	global $zz_setting;
	
	// canonical hostname?
	if (!empty($zz_setting['canonical_hostname'])) {
		if (!empty($zz_setting['local_access'])) {
			$canonical = $zz_setting['canonical_hostname'].'.local';
		} else {
			$canonical = $zz_setting['canonical_hostname'];
		}
		if ($zz_setting['hostname'] !== $canonical) {
			$zz_page['url']['full']['host'] = $canonical;
			$zz_page['url']['redirect'] = true;
			$zz_page['url']['redirect_cache'] = false;
		}
	}
	
	// if database allows field 'ending', check if the URL is canonical
	// just for HTML output!
	if (!empty($page['content_type']) AND $page['content_type'] !== 'html'
		AND !empty($zz_page['db']['identifier'])
		AND substr($zz_page['db']['identifier'], -1) === '*') {
		if (empty($page['url_ending'])) $page['url_ending'] = 'none';
	}
	if (!empty($zz_page['db'][wrap_sql('ending')])) {
		$ending = $zz_page['db'][wrap_sql('ending')];
		// if brick_format() returns a page ending, use this
		if (isset($page['url_ending'])) $ending = $page['url_ending'];
		$zz_page['url'] = wrap_check_canonical_ending($ending, $zz_page['url']);
	}

	$types = ['query_strings', 'query_strings_redirect'];
	foreach ($types as $type) {
		// initialize
		if (empty($page[$type])) $page[$type] = [];
		// merge from settings
		if (!empty($zz_setting[$type])) {
			$page[$type] = array_merge($page[$type], $zz_setting[$type]);
		}
	}
	// set some query strings which are used by zzwrap
	$page['query_strings'] = array_merge($page['query_strings'],
		['no-cookie', 'lang', 'code', 'url', 'logout']);
	if ($qs = wrap_get_setting('query_strings')) {
		$page['query_strings'] = array_merge($page['query_strings'], $qs);
	}
	if (!empty($zz_page['url']['full']['query'])) {
		parse_str($zz_page['url']['full']['query'], $params);
		foreach (array_keys($params) as $param) {
			if (in_array($param, $page['query_strings'])) continue;
			if (wrap_get_setting('no_query_strings_redirect')) {
				$zz_setting['cache'] = false; // do not cache these
				continue;
			}
			$param_value = $params[$param];
			unset($params[$param]);
			$zz_page['url']['redirect'] = true;
			$zz_page['url']['redirect_cache'] = false;
			// no error logging for query strings which shall be redirected
			if (in_array($param, $page['query_strings_redirect'])) continue;
			if (is_array($param_value)) $param_value = http_build_query($param_value);
			if (!wrap_errorpage_ignore('qs', $param)) {
				wrap_error(sprintf('Wrong URL: query string %s=%s [%s], Referer: %s'
					, $param, $param_value, $zz_setting['request_uri']
					, isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '--'
				), E_USER_NOTICE);
			}
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
 */
function wrap_check_canonical_ending($ending, $url) {
	// no changes for root path
	if ($url['full']['path'] === '/') return $url;
	if ($ending === 'ignore') return $url;
	$new = false;
	$possible_endings = ['.html', '.html%3E', '.php', '/', '/%3E'];
	foreach ($possible_endings as $p_ending) {
		if (!wrap_substr($url['full']['path'], $p_ending, 'end')) continue;
		if ($p_ending === $ending) return $url;
		$url['full']['path'] = substr($url['full']['path'], 0, -strlen($p_ending));
		$new = true;
		break;
	}
	if (!in_array($ending, ['none', 'keine'])) {
		$url['full']['path'] .= $ending;
		$new = true;
	}
	if (!$new) return $url;
	$url['redirect'] = true;
	$url['redirect_cache'] = true;
	return $url;
}

/**
 * builds URL from REQUEST
 * 
 * @param array $url $url['full'] with result from parse_url
 * @return array $url with new keys ['db'] (URL in database), ['suffix_length']
 */
function wrap_read_url($url) {
	// better than mod_rewrite, because '&' won't always be treated correctly
	$url['db'] = $url['full']['path'];
	if (!empty($_GET['lang']) AND !is_array($_GET['lang'])) {
		// might be array if someone constructs an invalid URL
		$url['suffix_length'] = strlen($_GET['lang']) + 6;
	} else {
		$url['suffix_length'] = 5;
	}
	// cut '/' at the beginning and - if necessary - at the end
	if (substr($url['db'], 0, 1) === '/') {
		$url['db'] = substr($url['db'], 1);
	}
	$possible_endings = ['.html', '.html%3E', '.php', '/', '/%3E'];
	foreach ($possible_endings as $p_ending) {
		if (substr($url['db'], -strlen($p_ending)) !== $p_ending) continue;
		$url['db'] = substr($url['db'], 0, -strlen($p_ending));
		break; // just one!
	}
	if (!empty($_GET['lang']) AND !is_array($_GET['lang'])) {
		if (substr($url['db'], -$url['suffix_length']) === '.html.'.$_GET['lang']) {
			$url['db'] = substr($url['db'], 0, -$url['suffix_length']);
		}
	}
	return $url;
}

/**
 * Glues a URL together
 *
 * @param array $url (e. g. result of parse_url())
 * @return string
 */
function wrap_glue_url($url) {
	global $zz_setting;
	$base = !empty($zz_setting['base']) ? $zz_setting['base'] : '';
	if (substr($base, -1) === '/') $base = substr($base, 0, -1);
	if (!in_array($_SERVER['SERVER_PORT'], [80, 443])) {
		$url['port'] = sprintf(':%s', $_SERVER['SERVER_PORT']);
	} else {
		$url['port'] = '';
	}
	if (!empty($url['path_forwarded']) AND wrap_substr($url['path'], $url['path_forwarded'])) {
		$url['path'] = substr($url['path'], strlen($url['path_forwarded']));
	}
	// remove duplicate base
	if (wrap_substr($url['path'], $base)) $base = '';
	$url['path'] = $base.$url['path'];
	return wrap_build_url($url);
}

/**
 * build a URL from parse_url() parts
 *
 * @param array
 * @return string
 */
function wrap_build_url($parts) {
	$url = $parts['scheme'].':'
		.(!empty($parts['host']) ? '//' : '')
		.(!empty($parts['user']) ? $parts['user']
			.(!empty($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '')
		.(!empty($parts['host']) ? $parts['host'] : '')
		.(!empty($parts['port']) ? ':'.$parts['port'] : '')
		.(!empty($parts['path']) ? $parts['path'] : '')
		.(!empty($parts['query']) ? '?'.$parts['query'] : '')
		.(!empty($parts['fragment']) ? '#'.$parts['fragment'] : '');
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
	$where_language = (!empty($_GET['lang']) AND !is_array($_GET['lang']))
		? sprintf(' OR %s = "/%s.html.%s"', wrap_sql('redirects_old_fieldname')
			, wrap_db_escape($url['db']), wrap_db_escape($_GET['lang']))
		: '';
	$sql = sprintf(wrap_sql('redirects')
		, '/'.wrap_db_escape($url['db'])
		, '/'.wrap_db_escape($url['db'])
		, '/'.wrap_db_escape($url['db']), $where_language
	);
	// not needed anymore, but set to false hinders from getting into a loop
	// (wrap_db_fetch() will call wrap_quit() if table does not exist)
	$zz_setting['check_redirects'] = false; 
	$redir = wrap_db_fetch($sql);
	if ($redir) return $redir;

	// check full URL with query strings or ending for migration from a different CMS
	$check = $url['full']['path'].(!empty($url['full']['query']) ? '?'.$url['full']['query'] : '');
	$check = wrap_db_escape($check);
	$sql = sprintf(wrap_sql('redirects'), $check, $check, $check, $where_language);
	$redir = wrap_db_fetch($sql);
	if ($redir) return $redir;

	// If no redirect was found until now, check if there's a redirect above
	// the current level with a placeholder (*)
	$redir = wrap_check_redirects_placeholder($url, 'behind');
	if ($redir) return $redir;
	$redir = wrap_check_redirects_placeholder($url, 'before');
	if ($redir) return $redir;
	return false;
}

/**
 * check for redirects with placeholder
 *
 * @param array $url
 * @param string $position
 * @return mixed
 */
function wrap_check_redirects_placeholder($url, $position) {
	$redir = false;
	$parameter = false;
	$found = false;
	$break_next = false;
	$separators = ['/', '-', '.'];

	switch ($position) {
	case 'before':
		$r_query = 'redirects*_';
		break;
	case 'behind':
		$r_query = 'redirects_*';
		break;
	}

	while (!$found) {
		$current_path = sprintf('/%s', wrap_db_escape($url['db']));
		$sql = sprintf(wrap_sql($r_query), $current_path);
		$redir = wrap_db_fetch($sql);
		if ($redir) break; // we have a result, get out of this loop!
		$last_pos = 0;
		if ($position === 'before') {
			foreach ($separators as $separator) {
				$pos = strpos($url['db'], $separator);
				if ($pos > $last_pos) {
					$last_pos = $pos;
					$last_separator = $separator;
				}
			}
			if ($last_pos) {
				$parameter .= substr($url['db'], 0, $last_pos + 1);
			}
			$url['db'] = substr($url['db'], $last_pos + 1);
		} else {
			foreach ($separators as $separator) {
				$pos = strrpos($url['db'], $separator);
				if ($pos > $last_pos) {
					$last_pos = $pos;
					$last_separator = $separator;
				}
			}
			if ($last_pos) {
				$parameter = substr($url['db'], $last_pos).$parameter;
			}
			$url['db'] = substr($url['db'], 0, $last_pos);
		}
		if ($break_next) break; // last round
		if (!strstr($url['db'], '/')) $break_next = true;
	}
	if (!$redir) return false;

	// parameters starting with - will be changed to start with /
	if (empty($last_separator)) $last_separator = '/'; // default
	elseif ($last_separator === '-') $last_separator = '/';
	// If there's an asterisk (*) at the end of the redirect
	// the cut part will be pasted to the end of the string
	$field_name = wrap_sql('redirects_new_fieldname');
	if (substr($redir[$field_name], -1) === '*') {
		$parameter = substr($parameter, 1);
		$redir[$field_name] = substr($redir[$field_name], 0, -1).$last_separator.$parameter;
	} elseif (substr($redir[$field_name], 0, 1) === '*') {
		$parameter = substr($parameter, 0, -1);
		$redir[$field_name] = $last_separator.$parameter.substr($redir[$field_name], 1);
	}
	return $redir;
}

/**
 * redirect to URL if it's a known error in adding space or quotes to URL
 * and a corresponding cache file exists
 *
 * @param array $page
 * @param array $url
 * @return array $page
 */
function wrap_check_redirect_from_cache($page, $url) {
	// %E2%80%8B = zero width space, sometimes added to URL from some systems
	$redirect_endings = [
		'%20', ')', '%5C', '%22', '%3E', '.', '%E2%80%8B'
	];
	foreach ($redirect_endings as $ending) {
		if (substr($url['path'], -strlen($ending)) !== $ending) continue;
		$url['path'] = substr($url['path'], 0, -strlen($ending));
		$new_url = wrap_glue_url($url);
		$filename = wrap_cache_filename('url', $new_url);
		if (!file_exists($filename)) continue;
		$page['status'] = 307;
		$page['redirect'] = $new_url;
		break;
	}
	return $page;
}

/**
 * Logs URL in URI table for statistics and further reference
 * sends only notices if some update does not work because it's just for the
 * statistics
 *
 * @param int $status
 * @return bool
 */
function wrap_log_uri($status = 0) {
	global $zz_setting;
	global $zz_page;
	
	if (!$status)
		$status = !empty($zz_page['error_code']) ? $zz_page['error_code'] : 200;

	if (!empty($zz_setting['http_log'])) {
		$logdir = sprintf('%s/access/%s/%s'
			, $zz_setting['log_dir']
			, date('Y', $_SERVER['REQUEST_TIME'])
			, date('m', $_SERVER['REQUEST_TIME'])
		);
		wrap_mkdir($logdir);
		$logfile = sprintf('%s/%s%s-access-%s.log'
			, $logdir
			, str_replace('/', '-', $zz_setting['site'])
			, $_SERVER['REQUEST_SCHEME'] === 'https' ? '-ssl' : ''
			, date('Y-m-d', $_SERVER['REQUEST_TIME'])
		);
		$line = sprintf(
			'%s - %s [%s] "%s %s %s" %d %d "%s" "%s" %s'."\n"
			, $zz_setting['remote_ip']
			, !empty($_SESSION['username']) ? $_SESSION['username']
				: (!empty($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : '-')
			, date('d/M/Y:H:i:s O', $_SERVER['REQUEST_TIME'])
			, $_SERVER['REQUEST_METHOD']
			, $_SERVER['REQUEST_URI']
			, $_SERVER['SERVER_PROTOCOL']
			, $status
			, !empty($zz_page['content_length']) ? $zz_page['content_length'] : 0
			, !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '-'
			, !empty($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '-'
			, $zz_setting['hostname']
		);
		error_log($line, 3, $logfile);
	}

	if (empty($zz_setting['uris_table'])) return false;
	if (empty($zz_page['url'])) return false;

	$scheme = $zz_page['url']['full']['scheme'];
	$host = $zz_page['url']['full']['host'];
	$base = wrap_substr($_SERVER['REQUEST_URI'], $zz_setting['base']) ? $zz_setting['base'] : '';
	$path = $base.wrap_db_escape($zz_page['url']['full']['path']);
	$query = !empty($zz_page['url']['full']['query'])
		? '"'.wrap_db_escape($zz_page['url']['full']['query']).'"'
		: 'NULL';
	$etag = !empty($zz_page['etag'])
		? $zz_page['etag']
		: 'NULL';
	if (substr($etag, 0, 1) !== '"' AND $etag !== 'NULL')
		$etag = '"'.$etag.'"';
	$last_modified = !empty($zz_page['last_modified'])
		? '"'.wrap_date($zz_page['last_modified'], 'rfc1123->datetime').'"'
		: 'NULL';
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
		WHERE uri_scheme = "%s"
		AND uri_host = "%s"
		AND uri_path = "%s"';
	$sql = sprintf($sql, $scheme, $host, $path);
	if ($query === 'NULL') {
		$sql .= ' AND ISNULL(uri_query)';
	} else {
		$sql .= sprintf(' AND uri_query = %s', $query);
	}
	$uri_id = wrap_db_fetch($sql, '', 'single value', E_USER_NOTICE);
	
	if (is_null($uri_id)) {
		return false;
	} elseif ($uri_id) {
		$sql = 'UPDATE /*_PREFIX_*/_uris
			SET hits = hits +1
				, status_code = %d
				, etag_md5 = %s
				, last_modified = %s
				, last_access = NOW(), last_update = NOW()
				, character_encoding = %s
		';
		if ($content_type)
			$sql .= sprintf(' , content_type = "%s"', $content_type);
		if (!empty($zz_page['content_length'])) 
			$sql .= sprintf(' , content_length = %d', $zz_page['content_length']);
		$sql .= ' WHERE uri_id = %d';
		$sql = sprintf($sql, $status, $etag, $last_modified, $encoding, $uri_id);
		wrap_db_query($sql, E_USER_NOTICE);
	} elseif (strlen($path) < 128 AND strlen($query) < 128) {
		$sql = 'INSERT INTO /*_PREFIX_*/_uris (uri_scheme, uri_host, uri_path,
			uri_query, content_type, character_encoding, content_length,
			status_code, etag_md5, last_modified, hits, first_access,
			last_access, last_update) VALUES ("%s", "%s", 
			"%s", %s, "%s", %s, %d, %d, %s, %s, 1, NOW(), NOW(), NOW())';
		$sql = sprintf($sql,
			$scheme, $host, $path, $query, $content_type, $encoding,
			$zz_page['content_length'], $status, $etag, $last_modified
		);
		wrap_db_query($sql, E_USER_NOTICE);
	} elseif (strlen($path) >= 128) {
		wrap_error(sprintf('URI path too long: %s', $path));
	} else {
		wrap_error(sprintf('URI query too long: %s', $query));
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
function wrap_remove_query_strings($url, $objectionable_qs = []) {
	if (empty($url['full']['query'])) return $url;
	if (empty($objectionable_qs)) {
		$objectionable_qs = ['PHPSESSID'];
	}
	if (!is_array($objectionable_qs)) {
		$objectionable_qs = [$objectionable_qs];
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
		$url['redirect_cache'] = false;
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
 * If that's true, 30x codes redirect pages, 410 redirect to gone.
 * if no error code is defined, a 404 code and the corresponding error page
 * will be shown
 * @param int $statuscode HTTP Status Code, default value is 404
 * @param string $error_msg (optional, error message for user)
 * @param array $page (optional, if normal output shall be shown, not error msg)
 * @return exits function with a redirect or an error document
 */
function wrap_quit($statuscode = 404, $error_msg = '', $page = []) {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;

	$page['status'] = $statuscode;
	if ($statuscode === 404) {
		$redir = wrap_check_redirects($zz_page['url']);
		if ($redir) {
			$page['status'] = $redir['code'];
		} else {
			$page = wrap_check_redirect_from_cache($page, $zz_page['url']['full']);
		}
	}

	// Check redirection code
	switch ($page['status']) {
	case 301:
	case 302:
	case 303:
	case 307:
		// (header 302 is sent automatically if using Location)
		if (!empty($page['redirect'])) {
			if (is_array($page['redirect']) AND array_key_exists('languagelink', $page['redirect'])) {
				$old_base = $zz_setting['base'];
				if (!empty($zz_setting['language_in_url'])
					AND substr($zz_setting['base'], -3) === '/'.$zz_setting['lang']) {
					$zz_setting['base'] = substr($zz_setting['base'], 0, -3);
				}
				if ($page['redirect']['languagelink']) {
					$zz_setting['base'] .= '/'.$page['redirect']['languagelink'];
				}
				$new = wrap_glue_url($zz_page['url']['full']);
				$zz_setting['base'] = $old_base; // keep old base for caching
			} elseif (is_array($page['redirect'])) {
				wrap_error(sprintf('Redirect to array not supported: %s', json_encode($page['redirect'])));
			} else {
				$new = $page['redirect'];
			}
		} else {
			$field_name = wrap_sql('redirects_new_fieldname');
			$new = $redir[$field_name];
		}
		$newurl = parse_url($new);
		if (empty($newurl['scheme'])) {
			if ($zz_setting['base'] AND file_exists($zz_conf['root'].'/'.$new)) {
				// no language redirect if it's an existing file
				$new = $zz_setting['host_base'].$new;
			} else {
				$new = $zz_setting['host_base'].$zz_setting['base'].$new;
			}
		}
		wrap_redirect($new, $page['status']);
		exit;
	case 304:
	case 412:
	case 416:
		wrap_log_uri($page['status']);
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
 */
function wrap_http_status_header($code) {
	global $zz_setting;
	// Set protocol
	$protocol = $_SERVER['SERVER_PROTOCOL'];
	if (!$protocol) $protocol = 'HTTP/1.0'; // default value
	if (wrap_substr(php_sapi_name(), 'cgi')) $protocol = 'Status:';
	
	if ($protocol === 'HTTP/1.0' AND in_array($code, [302, 303, 307])) {
		header($protocol.' 302 Moved Temporarily');
		return true;
	}
	$status = wrap_http_status_list($code);
	if ($status) {
		$header = $protocol.' '.$status['code'].' '.$status['text'];
		header($header);
		$zz_setting['headers'][] = $header;
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
	$status = [];
	
	// read error codes from file
	$pos[0] = 'code';
	$pos[1] = 'text';
	$pos[2] = 'description';
	$codes_from_file = file($zz_setting['core'].'/http-statuscodes.txt');
	foreach ($codes_from_file as $line) {
		if (wrap_substr($line, '#')) continue;	// Lines with # will be ignored
		elseif (!trim($line)) continue;				// empty lines will be ignored
		if (!wrap_substr($line, $code.'')) continue;
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
 * get remote IP address even if behind proxy
 *
 * @return string
 */
function wrap_http_remote_ip() {
	if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$remote_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		if ($pos = strpos($remote_ip, ',')) {
			$remote_ip = substr($remote_ip, 0, $pos);
		}
		// do not forward connections that say they're localhost
		if ($remote_ip === '::1') $remote_ip = '';
		if (substr($remote_ip, 0, 4) === '127.') $remote_ip = '';
		if ($remote_ip) return $remote_ip;
	}
	if (empty($_SERVER['REMOTE_ADDR']))
		return '';
	return $_SERVER['REMOTE_ADDR'];
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
 */
function wrap_check_https($zz_page, $zz_setting) {
	// if it doesn't matter, get out of here
	if ($zz_setting['ignore_scheme']) return true;
	foreach ($zz_setting['ignore_scheme_paths'] as $path) {
		if (wrap_substr($_SERVER['REQUEST_URI'], $path)) return true;
	}

	// change from http to https or vice versa
	// attention: $_POST will not be preserved
	if (!empty($_SERVER['HTTPS']) AND $_SERVER['HTTPS'] === 'on') {
		if ($zz_setting['protocol'] === 'https') return true;
		// if user is logged in, do not redirect
		if (!empty($_SESSION)) return true;
	} else {
		if ($zz_setting['protocol'] === 'http') return true;
	}
	$url = $zz_page['url']['full'];
	$url['scheme'] = $zz_setting['protocol'];
	wrap_redirect(wrap_glue_url($url), 302, false); // no cache
	exit;
}

/**
 * redirects to https URL if explicitly wanted
 *
 * @global array $zz_setting
 * @global array $zz_page
 * @return bool
 */
function wrap_https_redirect() {
	global $zz_setting;
	global $zz_page;

	// access must be possible via both http and https
	// check to avoid infinite redirection
	if (empty($zz_setting['ignore_scheme'])) return false;
	// connection is already via https?
	if (!empty($zz_setting['https'])) return false;
	// local connection?
	if ($zz_setting['local_access'] AND empty($zz_setting['local_https'])) return false;

	$url = $zz_page['url']['full'];
	$url['scheme'] = 'https';
	wrap_redirect(wrap_glue_url($url), 302, false); // no cache
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

	// check REQUEST_METHOD, quit if inappropriate
	wrap_check_http_request_method();

	// check if REMOTE_ADDR is valid IP
	if (!filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP))
		wrap_quit(400, sprintf('Request with a malformed IP address: %s', wrap_html_escape($_SERVER['REMOTE_ADDR'])));

	// check REQUEST_URI
	// Base URL, allow it to be set manually (handle with care!)
	// e. g. for Content Management Systems without mod_rewrite or websites in subdirectories
	$zz_setting['request_uri'] = wrap_percent_encode_non_ascii($zz_setting['request_uri']);
	
	if (empty($zz_page['url']['full'])) {
		$zz_page['url']['full'] = parse_url($zz_setting['host_base'].$zz_setting['request_uri']);
		if (empty($zz_page['url']['full']['path'])) {
			// in case, some script requests GET ? HTTP/1.1 or so:
			$zz_page['url']['full']['path'] = '/';
			$zz_page['url']['redirect'] = true;
			$zz_page['url']['redirect_cache'] = false;
		} elseif (strstr($zz_page['url']['full']['path'], '//')) {
			// replace duplicate slashes for getting path, some bots add one
			// redirect later if content was found
			$zz_page['url']['full']['path'] = str_replace('//', '/', $zz_page['url']['full']['path']);
			$zz_page['url']['redirect'] = true;
			$zz_page['url']['redirect_cache'] = false;
		}
		if (!empty($_SERVER['HTTP_X_FORWARDED_HOST']) AND !empty($zz_setting['hostname_in_url'])) {
			$forwarded_host = '/'.$_SERVER['HTTP_X_FORWARDED_HOST'];
			if ($zz_setting['local_access'] AND substr($forwarded_host, -6) === '.local') {
				$forwarded_host = substr($forwarded_host, 0, -6);
			}
			if (wrap_substr($zz_page['url']['full']['path'], $forwarded_host)) {
				$zz_page['url']['full']['path_forwarded'] = $forwarded_host;
				$zz_setting['request_uri'] = substr($zz_setting['request_uri'], strlen($forwarded_host));
			}
		}
	}

	// return 404 if path or query includes Unicode Replacement Character 
	// U+FFFD (hex EF BF BD, dec 239 191 189)
	// since that does not make sense
	if (strstr($zz_page['url']['full']['path'], '%EF%BF%BD')) wrap_quit(404);
	if (!empty($zz_page['url']['full']['query'])) {
		if (strstr($zz_page['url']['full']['query'], '%EF%BF%BD')) wrap_quit(404);
	}

	$zz_page['url']['full'] = wrap_url_normalize($zz_page['url']['full']);
	
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
 * sometimes, Apache decodes URL parts, e. g. %E2 is changed to a latin-1
 * character, encode that again
 *
 * @param string $path
 * @return string
 */
function wrap_percent_encode_non_ascii($path) {
	$new_path = '';
	for ($i = 0; $i < strlen($path); $i++) {
		if (ord(substr($path, $i, 1)) < 128)
			$new_path .= substr($path, $i, 1);
		else
			$new_path .= urlencode(substr($path, $i, 1)); 
	}
	return $new_path;
}

/**
 * normalizes a URL
 *
 * @param array $url (scheme, host, path, ...)
 * @return array
 */
function wrap_url_normalize($url) {
	// RFC 3986 Section 6.2.2.3. Path Segment Normalization
	// Normally, the browser will already do that
	if (strstr($url['path'], '/../')) {
		// /path/../ = /
		// @todo implement that
	}
	if (strstr($url['path'], '/./')) {
		// /path/./ = /path/
		$url['path'] = str_replace('/./', '/', $url['path']);
	}

	// RFC 3986 Section 6.2.2.2. Percent-Encoding Normalization
	if (strstr($url['path'], '%')) {
		$url['path'] = preg_replace_callback('/%[2-7][0-9A-F]/i', 'wrap_url_path_decode', $url['path']);
	}
	if (!empty($url['query']) AND strstr($url['query'], '%')) {
		$url['query'] = preg_replace_callback('/%[2-7][0-9A-F]/i', 'wrap_url_query_decode', $url['query']);
	}
	return $url;
}

function wrap_url_query_decode($input) {
	return wrap_url_decode($input, 'query');
}

function wrap_url_path_decode($input) {
	return wrap_url_decode($input, 'path');
}

function wrap_url_all_decode($input) {
	return wrap_url_decode($input, 'all');
}

/**
 * Normalizes percent encoded characters in URL path or query string into 
 * equivalent characters if encoding is superfluous
 * @see RFC 3986 Section 6.2.2.2. Percent-Encoding Normalization
 *
 * Characters which will remain percent encoded (range: 0020 - 007F) are
 * 0020    0022 "  0023 #  0025 %  002F /
 * 003C <  003E >  003F ?
 * 005B [  005C \  005D ]  005E ^
 * 0060 `  
 * 007B {  007C |  007D }  007F [DEL]
 *
 * @param $input array
 * @return string
 */
function wrap_url_decode($input, $type = 'path') {
	$codepoint = substr(strtoupper($input[0]), 1);
	if (hexdec($codepoint) < hexdec('20')) return '%'.$codepoint;
	if (hexdec($codepoint) > hexdec('7E')) return '%'.$codepoint;
	$dont_encode = [
		'20', '22', '23', '25', '2F',
		'3C', '3E', '3F',
		'5C', '5E',
		'60',
		'7B', '7C', '7D'
	];
	switch ($type) {
	case 'path':
		$dont_encode[] = '5B';
		$dont_encode[] = '5D';
		break;
	case 'query':
		$dont_encode[] = '3D'; // =
		$dont_encode[] = '26'; // &
		break;
	case 'all':
		$dont_encode = [];
		break;
	}
	if (in_array($codepoint, $dont_encode)) {
		return '%'.$codepoint;
	}
	return chr(hexdec($codepoint));
}

/**
 * Test HTTP REQUEST method
 * 
 * @global array $zz_setting
 * @return void
 */
function wrap_check_http_request_method() {
	global $zz_setting;
	if (in_array($_SERVER['REQUEST_METHOD'], $zz_setting['http']['allowed'])) {
		if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'OPTIONS') return true;
		if (wrap_is_dav_url()) return true;
		// @todo allow checking request methods depending on ressources
		// e. g. GET only ressources may forbid POST
		header('Allow: '.implode(',', $zz_setting['http']['allowed']));
		header('Content-Length: 0');
		exit;
	}
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
 *		'etag_generate_md5' => creates 'etag' if not send with MD5,
 *		'caching' => bool; defaults to true, false = no caching allowed,
 *		'ext' => use this extension, do not try to determine it from file ending
 * @global array $zz_conf
 * @todo send pragma public header only if browser that is affected by this bug
 * @todo implement Ranges for bytes
 */
function wrap_file_send($file) {
	global $zz_conf;
	global $zz_page;
	global $zz_setting;

	if (is_dir($file['name'])) return false;
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
	if (!empty($zz_page['url']['redirect'])) {
		wrap_redirect(wrap_glue_url($zz_page['url']['full']), 301, $zz_page['url']['redirect_cache']);
	}
	if (empty($file['send_as'])) $file['send_as'] = basename($file['name']);
	$suffix = !empty($file['ext']) ? $file['ext'] : wrap_file_extension($file['name']);
	if (!wrap_substr($file['send_as'], '.'.$suffix, 'end'))
		$file['send_as'] .= '.'.$suffix;
	if (!isset($file['caching'])) $file['caching'] = true;

	// Accept-Ranges HTTP header
	wrap_cache_header('Accept-Ranges: bytes');

	// Content-Length HTTP header
	$zz_page['content_length'] = sprintf("%u", filesize($file['name']));
	wrap_cache_header('Content-Length: '.$zz_page['content_length']);
	// Maybe the problem is we are running into PHPs own memory limit, so:
	if ($zz_page['content_length'] + 1 > wrap_return_bytes(ini_get('memory_limit'))
		&& intval($zz_page['content_length'] * 1.5) <= 1073741824) { 
		// Not higher than 1GB
		ini_set('memory_limit', intval($zz_page['content_length'] * 1.5));
	}

	// Content-Type HTTP header
	// Read mime type from database
	$sql = sprintf(wrap_sql('filetypes'), $suffix);
	$zz_page['content_type'] = wrap_db_fetch($sql, '', 'single value');
	if (!$zz_page['content_type']) {
		// Canonicalize suffices
		$suffix_map = [
			'jpg' => 'jpeg',
			'tif' => 'tiff'
		];
		if (in_array($suffix, array_keys($suffix_map))) $suffix = $suffix_map[$suffix];
		$sql = sprintf(wrap_sql('filetypes'), $suffix);
		$zz_page['content_type'] = wrap_db_fetch($sql, '', 'single value');
	}
	if (!$zz_page['content_type']) $zz_page['content_type'] = 'application/octet-stream';
	wrap_cache_header('Content-Type: '.$zz_page['content_type']);

	// ETag HTTP header
	if (!empty($file['etag_generate_md5']) AND empty($file['etag'])) {
		$file['etag'] = md5_file($file['name']);
	}
	if (!empty($file['etag'])) {
		wrap_if_none_match($file['etag'], $file);
	}
	
	// Last-Modified HTTP header
	wrap_if_modified_since(filemtime($file['name']), 200, $file);

	if ($file['caching'])
		wrap_cache_allow_private();

	// Download files if generic mimetype
	// or HTML, since this might be of unknown content with javascript or so
	$download_filetypes = [
		'application/octet-stream', 'application/zip', 'text/html',
		'application/xhtml+xml'
	];
	if (in_array($zz_page['content_type'], $download_filetypes)) {
		wrap_http_content_disposition('attachment', $file['send_as']);
			// d. h. bietet save as-dialog an, geht nur mit application/octet-stream
		wrap_cache_header('Pragma: public');
			// dieser Header widerspricht im Grunde dem mit SESSION ausgesendeten
			// Cache-Control-Header
			// Wird aber für IE 5, 5.5 und 6 gebraucht, da diese keinen Dateidownload
			// erlauben, wenn Cache-Control gesetzt ist.
			// http://support.microsoft.com/kb/323308/de
	} else {
		wrap_http_content_disposition('inline', $file['send_as']);
	}
	
	wrap_cache_header();
	if ($file['caching'])
		wrap_cache_header_default(sprintf('Cache-Control: max-age=%d', $zz_setting['cache_control_file']));
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
 * @param array $headers (optional):
 *		'filename': download filename
 *		'character_set': character encoding
 * @global array $zz_conf
 * @global array $zz_setting
 * @return void
 */
function wrap_send_text($text, $type = 'html', $status = 200, $headers = []) {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;

	// positions: text might be array
	if (is_array($text) AND count($text) === 1) $text = array_shift($text);
	if (is_array($text) AND $type !== 'html') {
		// disregard webpage content on other positions
		if (array_key_exists('text', $text))
			$text = $text['text'];
		else 
			$text = array_shift($text);
	}
	if ($type !== 'csv') {
		$text = trim($text);
	}

	if (!empty($zz_setting['gzip_encode'])) {
		wrap_cache_header('Vary: Accept-Encoding');
	}
	header_remove('Accept-Ranges');

	// Content-Type HTTP header
	$filetype_cfg = wrap_filetypes($type);
	if ($filetype_cfg) {
		$zz_page['content_type'] = $filetype_cfg['mime'][0];
		$mime = explode('/', $zz_page['content_type']);
		if (in_array($mime[0], ['text', 'application'])) {
			if (!empty($filetype_cfg['encoding'])) {
				$zz_page['character_set'] = $filetype_cfg['encoding'];
			} elseif (!empty($headers['character_set'])) {
				$zz_page['character_set'] = $headers['character_set'];
			} else {
				$zz_page['character_set'] = $zz_conf['character_set'];
			}
			if ($zz_page['character_set'] === 'utf-16le') {
				// Add BOM, little endian
				$text = chr(255).chr(254).$text;
			}
		}
		if (!empty($zz_page['character_set'])) {
			wrap_cache_header(sprintf('Content-Type: %s; charset=%s', $zz_page['content_type'], 
				$zz_page['character_set']));
		} else {
			wrap_cache_header(sprintf('Content-Type: %s', $zz_page['content_type']));
		}
	}

	// Content-Disposition HTTP header
	if (!empty($filetype_cfg['content_disposition'])) {
		wrap_http_content_disposition(
			$filetype_cfg['content_disposition'],
			isset($headers['filename']) ? $headers['filename'] : 'download.'.$filetype_cfg['extension'][0]
		);
	}
	if ($type === 'csv') {
		if (!empty($_SERVER['HTTP_USER_AGENT']) 
			AND strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE'))
		{
			wrap_cache_header('Cache-Control: max-age=1'); // in seconds
			wrap_cache_header('Pragma: public');
		}
	}

	// Content-Length HTTP header
	// might be overwritten later
	$zz_page['content_length'] = strlen($text);
	wrap_cache_header('Content-Length: '.$zz_page['content_length']);

	// ETag HTTP header
	// check whether content is identical to previously sent content
	// @todo: do not send 304 immediately but with a Last-Modified header
	$etag_header = [];
	if ($status === 200) {
		// only compare ETag in case of status 2xx
		$zz_page['etag'] = md5($text);
		$etag_header = wrap_if_none_match($zz_page['etag']);
	}

	$last_modified_time = time();
	if (!empty($_SERVER['REQUEST_TIME'])
		AND $_SERVER['REQUEST_TIME'] < $last_modified_time) {
		$last_modified_time = $_SERVER['REQUEST_TIME'];
	}

	// send all headers
	wrap_cache_header();
	if (!empty($zz_setting['cache'])) {
		wrap_cache_header_default(sprintf('Cache-Control: max-age=%d', $zz_setting['cache_control_text']));
	} else {
		wrap_cache_header_default('Cache-Control: max-age=0');
	}

	// Caching?
	if (!empty($zz_setting['cache']) AND empty($_SESSION['logged_in'])
		AND empty($_POST) AND $status === 200) {
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
	wrap_if_modified_since($last_modified_time, $status);

	wrap_send_ressource('memory', $text, $etag_header);
}

/**
 * Send a HTTP Content-Disposition-Header with a filename
 *
 * @param string $type 'inline' or 'attachment'
 * @param string $filename
 * @global $zz_conf
 * @return void
 */
function wrap_http_content_disposition($type, $filename) {
	global $zz_conf;

	// no double quotes in filenames
	$filename = str_replace('"', '', $filename);
	// RFC 2616: filename must consist of all ASCII characters
	// RFC 5987: filename* may be sent with UTF-8 encoding
	$filename_ascii = $filename;
	if ($zz_conf['character_set'] === 'utf-8') {
		$filename_ascii = utf8_decode($filename_ascii);
	} else {
		// @todo use iconv for encodings different from latin1
		$filename = utf8_encode($filename);
	}
	$filename_ascii = preg_replace('/[^(\x20-\x7F)]*/','', $filename_ascii);
	if ($filename_ascii !== $filename) {
		wrap_cache_header(sprintf(
			'Content-Disposition: %s; filename="%s"; filename*=utf-8\'\'%s',
			$type, $filename_ascii, rawurlencode($filename))
		);
	} else {
		wrap_cache_header(sprintf('Content-Disposition: %s; filename="%s"', $type, $filename_ascii));
	}
}

/**
 * Sends the ressource to the browser after all headers have been sent
 *
 * @param string $type 'memory' = content is in memory, 'file' => is in file
 * @param mixed $content full content or array $file, depending on $type
 * @param array $etag_header
 */
function wrap_send_ressource($type, $content, $etag_header = []) {
	global $zz_setting;
	global $zz_page;

	// HEAD HTTP request
	if (strtoupper($_SERVER['REQUEST_METHOD']) === 'HEAD') {
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
	ignore_user_abort(1); // make sure we can delete temporary files at the end
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
		wrap_log_uri(206); // @todo log correct range content length
		if (count($ranges) !== 1) {
			$boundary = 'THIS_STRING_SEPARATES_'.md5(time());
			header(sprintf('Content-Type: multipart/byteranges; boundary=%s', $boundary));
			$bottom = "--".$boundary."--\r\n";
			$content_length_total = strlen($bottom);
			$separator = "\r\n\r\n";
			$content_length_total += strlen($separator) * count($ranges);
		}
		$handle = fopen($content['name'], 'rb');
		$top = [];
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
	if (empty($_SERVER['HTTP_RANGE'])) return [];

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
			return [];
		}
	}
	
	// - if Range not valid	416 (Requested range not satisfiable), Content-Range: *
	// - else 206 + partial content
	$raw_ranges = explode(',', substr($_SERVER['HTTP_RANGE'], 6));
	$ranges = [];
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
        $ranges[] = [
        	'start' => $start,
        	'end' => $end
        ];
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
		if (!empty($_SERVER['HTTP_ACCEPT_ENCODING'])
			AND strstr($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
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
function wrap_cache_ressource($text = '', $existing_etag = '', $url = false, $headers = []) {
	global $zz_setting;
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
			wrap_cache_revalidated($head);
			return false;
		}
	}
	// save document
	if ($text) {
		file_put_contents($doc, $text);
	} elseif (file_exists($doc)) {
		unlink($doc);
	}
	// save headers
	// without '-gz'
	if (!$headers) {
		header_remove('X-Powered-By');
		header_remove('Server');
		$headers = $zz_setting['headers'];
	}
	file_put_contents($head, implode("\r\n", $headers));
	return true;
}

/**
 * send one or more HTTP header and save it for later caching
 * send extra http headers, @see defaults.inc.php
 *
 * @param string $header (optional, if not set: use $zz_setting['headers'])
 * @return bool
 */
function wrap_cache_header($header = false) {
	global $zz_setting;
	if ($header) {
		$headers = [$header];
	} else {
		header_remove('X-Powered-By');
		header_remove('Server');
		$headers = $zz_setting['extra_http_headers'];
	}

	foreach ($headers as $line) {
		header($line);
		if (strstr($line, ': ')) {
			$header_parts = explode(': ', $line);
			$zz_setting['headers'][$header_parts[0]] = $line;
		} else {
			$zz_setting['headers'][] = $line;
		}
	}
	return true;
}

/**
 * send a default header if no other header of the same name was already sent
 *
 * @param string $header
 * @return bool true if a default header was sent
 */
function wrap_cache_header_default($header) {
	global $zz_setting;
	$parts = explode(': ', $header);
	if (!empty($zz_setting['headers'][$parts[0]])) return false;
	
	$headers = headers_list();
	foreach ($headers as $line) {
		$line = explode(': ', $line);
		if ($line[0] === $parts[0]) return false;
	}
	wrap_cache_header($header);
	return true;	
}

/**
 * write an X-Revalidated-Header to cache file to allow it for some time
 * to be considered as fresh after the last revalidation
 *
 * @param string $file Name of header cache file
 * @return void
 */
function wrap_cache_revalidated($file) {
	$headers = file_get_contents($file);
	$headers = explode("\r\n", $headers);
	foreach ($headers as $index => $header) {
		if (wrap_substr($header, 'X-Revalidated: '))
			unset($headers[$index]);
	}
	$headers[] = sprintf('X-Revalidated: %s', wrap_date(time(), 'timestamp->rfc1123'));
	file_put_contents($file, implode("\r\n", $headers));
}

/**
 * Delete cached files which now return a 4xx-error code
 *
 * @param int $status HTTP Status Code
 * @param string $url (optional)
 * @return bool true: cache was deleted; false: cache remains intact
 */
function wrap_cache_delete($status, $url = false) {
	$delete_cache = [401, 402, 403, 404, 410];
	if (!in_array($status, $delete_cache)) return false;

	$doc = wrap_cache_filename('url', $url);
	$head = wrap_cache_filename('headers', $url);
	if (file_exists($head)) unlink($head);
	if (file_exists($doc)) unlink($doc);
	return true;
}

/**
 * allow private caching of a ressource inside SESSION
 *
 * Remove some HTTP headers PHP might send because of SESSION
 * @todo do some tests if this is okay
 * @todo set sensible Expires header, according to age of file
 */
function wrap_cache_allow_private() {
	if (empty($_SESSION)) return;
	header_remove('Expires');
	header_remove('Pragma');
	// Cache-Control header private as in session_cache_limiter()
	wrap_cache_header(sprintf('Cache-Control: private, max-age=%s, pre-check=%s',
		session_cache_expire() * 60, session_cache_expire() * 60));
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
function wrap_if_none_match($etag, $file = []) {
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
			if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'])) {
				if ($file) wrap_file_cleanup($file);
				wrap_log_uri();
				wrap_cache_header('ETag: '.$etag_header['std']);
				wrap_quit(304);
			} else {
				wrap_quit(412);
			}
		}
	}
	// Neither header field affects request
	// ETag std header might be overwritten by gzip-ETag later on
	wrap_cache_header('ETag: '.$etag_header['std']);
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
	if (substr($etag, 0, 1) === '"' AND substr($etag, -1) === '"') {
		$etag = substr($etag, 1, -1);
	}
	$etag_header = [
		'std' => sprintf('"%s"', $etag),
		'gz' => sprintf('"%s"', $etag.'-gz')
	];
	return $etag_header;
}

/**
 * creates Last-Modified-Header, checks against If-Modified-Since
 * and If-Unmodified-Since
 * respond to If Modified Since with 304 header if appropriate
 *
 * @param int $time (timestamp)
 * @param int $status HTTP status code
 * @param array $file (optional)
 * @return string time formatted for Last-Modified
 */
function wrap_if_modified_since($time, $status = 200, $file = []) {
	// do not send Last-Modified header for client (4xx) or server (5xx) errors
	if (substr($status, 0, 1) === '4') return '';
	if (substr($status, 0, 1) === '5') return '';

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
			wrap_cache_header('Last-Modified: '.$zz_page['last_modified']);
			if ($file) wrap_file_cleanup($file);
			wrap_log_uri();
			wrap_quit(304);
		}
	}
	wrap_cache_header('Last-Modified: '.$zz_page['last_modified']);
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

	$files = [wrap_cache_filename(), wrap_cache_filename('headers')];
	// $files[0] might not exist (redirect!)
	if (!file_exists($files[1])) return false;
	$has_content = file_exists($files[0]);

	if ($age) {
		// return cached files if they're still fresh enough
		$fresh = wrap_cache_freshness($files, $age, $has_content);
		if (!$fresh) return false;
	}

	// get cached headers, send them as headers and write them to $zz_page
	// Content-Type HTTP header etc.
	wrap_cache_get_header($files[1], '', true);

	if (!empty($zz_setting['gzip_encode'])) {
		wrap_cache_header('Vary: Accept-Encoding');
	}

	// Log if cached version is used because there's no connection to database
	if (empty($zz_conf['db_connection'])) {
		wrap_error('No connection to SQL server. Using cached file instead.', E_USER_NOTICE);
	}
	
	// is it a cached redirect? that's it. exit.
	if (!$has_content) return true;

	// Content-Length HTTP header
	if (empty($zz_page['content_length'])) {
		$zz_page['content_length'] = sprintf("%u", filesize($files[0]));
		wrap_cache_header('Content-Length: '.$zz_page['content_length']);
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

	$file = [
		'name' => $files[0],
		'gzip' => true
	];
	wrap_cache_header();
	wrap_send_ressource('file', $file, $etag_header);
}

/**
 * check freshness of cache, either if it was last revalidated in a given
 * timeframe (positive values for $age) or if it was created after a given 
 * timestamp (negative values for $age)
 *
 * @param array $files list of files
 * @param int $age (negative -1: don't care about freshness; other values: check)
 * @param bool $has_content if false, it's only a redirect
 * @return bool false: not fresh, true: cache is fresh
 */
function wrap_cache_freshness($files, $age, $has_content = true) {
	global $zz_setting;
	// -1: cache will always considered to be fresh
	if ($age === -1) return true;
	$now = time();
	if ($age < 0) {
		// check if there's a cache that was not modified later than $age
		$last_mod = wrap_cache_get_header($files[1], 'Last-Modified');
		$last_mod_timestamp = wrap_date($last_mod, 'rfc1123->timestamp');
		if ($last_mod_timestamp > $now + $age) {
			return true;
		}
		if ($has_content AND filemtime($files[0]) > $now + $age) return true;
	} else {
		$host = substr($files[0], strlen($zz_setting['cache_dir']) + 1);
		$host = substr($host, 0, strpos($host, '/'));
		if ($host !== $zz_setting['hostname']) {
			// remote access, check cache-control of remote server
			$cache_control = wrap_cache_get_header($files[1], 'Cache-Control');
			parse_str($cache_control, $cache_control);
			if (!empty($cache_control['max-age']) AND $cache_control['max-age'] > $age) {
				$age = $cache_control['max-age'];
				$date = wrap_date(wrap_cache_get_header($files[1], 'Date'), 'rfc1123->timestamp');
				if ($date + $age > $now) return true;
			}					
		}
		// 0 or positive values: cache files will be checked
		// check if X-Revalidated is set
		$revalidated = wrap_cache_get_header($files[1], 'X-Revalidated');
		$revalidated_timestamp = wrap_date($revalidated, 'rfc1123->timestamp');
		if ($revalidated_timestamp AND $revalidated_timestamp + $age > $now) {
			// thought of putting in Age, but Date has to be changed accordingly
			// wrap_cache_header(sprintf('Age: %d', $now - $revalidated_timestamp));
			return true;
		}
		// check if cached files date is fresh
		if ($has_content AND filemtime($files[0]) + $age > $now) return true;
	}
	return false;
}

/**
 * Check if there's a cache file and it's newer than last modified
 * date e. g. of database tables
 *
 * @param string $datetime timestamp e.g. 2014-08-14 10:28:36
 * @return void false if no cache was found, or it will send the cache
 */
function wrap_cache_send_if_newer($datetime) {
	if (!$datetime) return false;
	$datetime = strtotime($datetime);
	$now = time();
	$diff = $now - $datetime;
	if ($diff > 0) {
		// check if there's a cache younger than $diff
		wrap_send_cache(-$diff);
	}
	return false;
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
	if (substr($headers, 0, 2) === '["') {
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
		if ($req_header === $type) {
			// check if respond with 304
			$value = substr($header, strlen($type) + 2);
			if (substr($value, 0, 1) === '"' AND substr($value, -1) === '"') {
				$value = substr($value, 1, -1);
			}
		}
	}
	if ($send) $sent = true;
	return $value;
}

/**
 * returns filename for URL for caching
 *
 * @param string $type (optional) default: 'url'; 'headers', 'domain'
 * @param string $url (optional) URL to cache, if not set, internal URL will be used
 * @global array $zz_page ($zz_page['url']['full'])
 * @global array $zz_setting 'cache_dir'
 * @return string filename
 */
function wrap_cache_filename($type = 'url', $url = '') {
	global $zz_page;
	global $zz_setting;

	if (!$url) {
		$url = $zz_page['url']['full'];
		$base = $zz_setting['base'];
		if ($base === '/') $base = '';
	} else {
		$url = parse_url($url);
		$base = '';
	}
	$file = $zz_setting['cache_dir'].'/'.urlencode($url['host']);
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
 */
function wrap_return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    if (!is_numeric($last)) $val = substr($val, 0, -1);
    switch($last) {
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
 * @param int $login_id
 * @return mixed $setting (if not found, returns NULL)
 */
function wrap_get_setting($key, $login_id = 0) {
	if (function_exists('my_get_setting')) {
		return my_get_setting($key, $login_id);
	}
	global $zz_setting;
	if (isset($zz_setting[$key]) AND !$login_id) {
		return $zz_setting[$key];
	}
	$values = wrap_setting_read($key, $login_id);
	if ($login_id AND array_key_exists($key, $values)) {
		return $values[$key];
	}
	// @deprecated
	if (substr($key, -1) === '*') {
		return $values;
	}
	return NULL;
}

/** 
 * Merges Array recursively: replaces old with new keys, adds new keys
 * 
 * @param array $old			Old array
 * @param array $new			New array
 * @return array $merged		Merged array
 * @see zz_array_merge
 */
function wrap_array_merge($old, $new) {
	foreach ($new as $index => $value) {
		if (is_array($value)) {
			if (!empty($old[$index])) {
				$old[$index] = wrap_array_merge($old[$index], $new[$index]);
			} else
				$old[$index] = $new[$index];
		} else {
			if (is_numeric($index) AND (!in_array($value, $old))) {
				// numeric keys will be appended, if new
				$old[] = $value;
			} else {
				// named keys will be replaced
				$old[$index] = $value;
			}
		}
	}
	return $old;
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
	$indexed_by_main = [];
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
	if (!array_key_exists($top_id, $indexed_by_main)) {
		wrap_error(sprintf(
			'Creating hierarchy impossible because ID %d is not part of the given list'
			, $top_id)
		);
		return [];
	}
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
 * @return mixed 
 * 	bool true: folder creation was successful
 * 	array: list of folders
 */
function wrap_mkdir($folder) {
	$created = [];
	if (is_dir($folder)) return true;

	// check if open_basedir restriction is in effect
	$allowed_dirs = explode(':', ini_get('open_basedir'));
	if ($allowed_dirs) {
		$basefolders = [];
		foreach ($allowed_dirs as $dir) {
			if (substr($folder, 0, strlen($dir)) === $dir) {
				$basefolders = explode('/', $dir);
				break;
			}
		}
	}
	$subfolders = explode('/', $folder);
	$current_folder = '';

	// get rid of .. and .
	foreach ($subfolders as $index => $subfolder) {
		if (!$subfolder) continue;
		if ($subfolder === '..') {
			unset($subfolders[$index]);
			unset($subfolders[$index - 1]);
			continue;
		} elseif ($subfolder === '.') {
			unset($subfolders[$index]);
			continue;
		}
	}

	// get indices straight
	$subfolders = array_values($subfolders);

	foreach ($subfolders as $index => $subfolder) {
		if (!$subfolder) continue;
		$current_folder .= '/'.$subfolder;
		if (!empty($basefolders[$index]) AND $basefolders[$index] === $subfolder) {
			// it's in open_basedir, so folder should exist and we cannot
			// test whether it exists anyways
			continue;
		}
		if (!file_exists($current_folder)) {
			$success = mkdir($current_folder);
			if (!$success) {
				wrap_error(sprintf('Could not create folder %s.', $current_folder), E_USER_ERROR);
				return false;
			}
			$created[] = $current_folder;
		}
	}
	return $created;
}

/**
 * call a website in the background via http
 * https is not supported
 *
 * @param string $url
 * @return array $page
 */
function wrap_trigger_url($url) {
	$port = 80;
	if (substr($url, 0, 1) === '/') {
		global $zz_page;
		$host = $zz_page['url']['full']['host'];
		$path = $url;
	} else {
		$parsed = parse_url($url);
		if ($parsed['scheme'] !== 'http') {
			$page['status'] = 503;
			$page['text'] = sprintf('Scheme %s not supported.', wrap_html_escape($parsed['scheme']));
			return $page;
		}
		if ($parsed['user'] OR $parsed['pass']) {
			$page['status'] = 503;
			$page['text'] = 'Authentication not supported.';
			return $page;
		}
		if ($parsed['port']) $port = $parsed['port'];
		$host = $parsed['host'];
		$path = $parsed['path'].($path['query'] ? '?'.$path['query'] : '');
	}
	$fp = fsockopen($host, $port);
	if ($fp === false) {
		$page['status'] = 503;
		$page['text'] = sprintf('Connection to server %s failed.', wrap_html_escape($host));
		return $page;
	}
	$out = "GET ".$path." HTTP/1.1\r\n";
	$out .= "Host: ".$host."\r\n";
	$out .= "Connection: Close\r\n\r\n";
	// @todo retry if 503 error in 10 seconds
	fwrite($fp, $out);
	// read at least one byte because some servers won't establish a connection
	// otherwise
	fread($fp, 1);
	fclose($fp);
	$page['text'] = 'Connection successful.';
	return $page;
}

/**
 * trigger a protected URL
 *
 * @param string $url
 * @param string $username (optional)
 * @param bool $send_lock defaults to true, send lock hash to child process
 * @return array from wrap_syndication_retrieve_via_http()
 */
function wrap_trigger_protected_url($url, $username = false, $send_lock = true) {
	$headers[] = 'X-Timeout-Ignore: 1';
	if (function_exists('wrap_lock_hash') AND $send_lock) {
		$headers[] = sprintf('X-Lock-Hash: %s', wrap_lock_hash());
	}
	return wrap_get_protected_url($url, $headers, 'POST', [], $username);
}

/**
 * get a protected URL
 *
 * @param string $url
 * @param array $headers
 * @param string $method
 * @param array $data
 * @param string $username (optional, $zz_conf/SESSION['username'] will be used unless set)
 * @global array $zz_setting
 *	login_key, login_key_validity_in_minutes must be set
 * @return array from wrap_syndication_retrieve_via_http()
 */

function wrap_get_protected_url($url, $headers = [], $method = 'GET', $data = [], $username = false) {
	global $zz_setting;
	global $zz_conf;

	if (!$username) $username = !empty($_SESSION['username']) ? $_SESSION['username'] : $zz_conf['user'];
	$pwd = sprintf('%s:%s', $username, wrap_password_token($username));
	$headers[] = 'X-Request-WWW-Authentication: 1';
	if (substr($url, 0, 1) === '/') $url = $zz_setting['host_base'].$url;

	require_once $zz_setting['core'].'/syndication.inc.php';
	$result = wrap_syndication_retrieve_via_http($url, $headers, $method, $data, $pwd);
	return $result;
}

/**
 * check if a number is an integer or a string with an integer in it
 *
 * @param mixed $var
 * @return bool
 */
function wrap_is_int($var) {
	if (!is_numeric($var)) return false;
	$i = intval($var);
	if ("$i" === "$var") {
  		return true;
	} else {
    	return false;
    }
}

/**
 * write settings to database
 *
 * @param string $key
 * @param string $value
 * @param int $login_id (optional)
 * @return bool
 */
function wrap_setting_write($key, $value, $login_id = 0) {
	global $zz_conf;

	$existing_setting = wrap_setting_read($key, $login_id);
	if ($existing_setting) {
		// support for keys that are arrays
		$new_setting = wrap_setting_key($key, wrap_setting_value($value));
		if ($existing_setting === $new_setting) return false;
		$sql = 'UPDATE /*_PREFIX_*/%s_settings SET setting_value = "%%s" WHERE setting_key = "%%s"';
		$sql = wrap_db_prefix($sql);
		$sql = sprintf($sql, $login_id ? 'logins' : '');
		$sql = sprintf($sql, wrap_db_escape($value), wrap_db_escape($key));
		$sql .= wrap_setting_login_id($login_id);
	} elseif ($login_id) {
		$sql = 'INSERT INTO /*_PREFIX_*/logins_settings (setting_value, setting_key, login_id) VALUES ("%s", "%s", %s)';
		$sql = wrap_db_prefix($sql);
		$sql = sprintf($sql, wrap_db_escape($value), wrap_db_escape($key), $login_id);
	} else {
		$cfg = wrap_setting_cfg();
		$explanation = (in_array($key, array_keys($cfg)) AND !empty($cfg[$key]['description']))
			? sprintf('"%s"', $cfg[$key]['description'])  : 'NULL';
		$sql = 'INSERT INTO /*_PREFIX_*/_settings (setting_value, setting_key, explanation) VALUES ("%s", "%s", %s)';
		$sql = wrap_db_prefix($sql);
		$sql = sprintf($sql, wrap_db_escape($value), wrap_db_escape($key), $explanation);
	}
	$result = wrap_db_query($sql);
	if ($result) {
		$id = !empty($result['id']) ? $result['id'] : false;
		if (file_exists($zz_conf['dir_inc'].'/database.inc.php')) {
			require_once $zz_conf['dir_inc'].'/database.inc.php';
			zz_log_sql($sql, (!empty($_SESSION['username']) ? $_SESSION['username'] : $zz_conf['user']), $id);
		}
		return true;
	}

	wrap_error(sprintf(
		wrap_text('Could not change setting. Key: %s, value: %s, login: %s'),
		wrap_html_escape($key), wrap_html_escape($value), $login_id
	));	
	return false;
}

/**
 * read settings from database
 *
 * @param string $key (* at the end used as wildcard)
 * @param int $login_id (optional)
 * @return array
 */
function wrap_setting_read($key, $login_id = 0) {
	static $setting_table;
	static $login_setting_table;
	static $settings;
	if (empty($settings)) $settings = [];
	if (array_key_exists($login_id, $settings))
		if (array_key_exists($key, $settings[$login_id]))
			return $settings[$login_id][$key];

	if (!$login_id AND !$setting_table) {
		$sql = 'SHOW TABLES LIKE "/*_PREFIX_*/_settings"';
		$setting_table = wrap_db_fetch($sql);
	} elseif ($login_id AND !$login_setting_table) {
		$sql = 'SHOW TABLES LIKE "/*_PREFIX_*/logins_settings"';
		$login_setting_table = wrap_db_fetch($sql);
	}
	if (!$setting_table) return [];
	$sql = 'SELECT setting_key, setting_value
		FROM /*_PREFIX_*/%s_settings
		WHERE setting_key %%s "%%s"';
	$sql = sprintf($sql, $login_id ? 'logins' : '');
	if (substr($key, -1) === '*') {
		$sql = sprintf($sql, 'LIKE', substr($key, 0, -1).'%');
	} else {
		$sql = sprintf($sql, '=', $key);
	}
	$sql .= wrap_setting_login_id($login_id);
	$settings_raw = wrap_db_fetch($sql, 'setting_key', 'key/value');
	$settings[$login_id][$key] = [];
	foreach ($settings_raw as $skey => $value) {
		$settings[$login_id][$key]
			= array_merge_recursive($settings[$login_id][$key], wrap_setting_key($skey, wrap_setting_value($value)));
	}
	return $settings[$login_id][$key];
}

/**
 * add login_id or not to setting query
 *
 * @param int $login_id (optional)
 * @return string WHERE query part
 */
function wrap_setting_login_id($login_id = 0) {
	if (!$login_id) return '';
	return sprintf(' AND login_id = %d', $login_id);
}

/**
 * sets key/value pairs in $zz_setting, key may be array in form of
 * key[subkey], value may be array in form (1, 2, 3)
 *
 * @param string $key
 * @param string $value
 */
function wrap_setting_key($key, $value) {
	$settings = [];
	if (strstr($key, '[')) {
		$keys = explode('[', $key);
		if (count($keys) === 2)
			$settings[$keys[0]][substr($keys[1], 0, -1)] = $value;
		elseif (count($keys) === 3)
			$settings[$keys[0]][substr($keys[1], 0, -1)][substr($keys[2], 0, -1)] = $value;
	} else {
		$settings[$key] = $value;
	}
	return $settings;
}

/**
 * allows settings from db to be in the format [1, 2, 3]; first \ will be
 * removed and allows settings starting with [
 *
 * @param string $string
 * @return mixed
 */
function wrap_setting_value($string) {
	switch (substr($string, 0, 1)) {
	case '\\':
		return substr($string, 1);
	case '[':
		if (!substr($string, -1) === ']') break;
		$string = substr($string, 1, -1);
		$strings = explode(',', $string);
		foreach ($strings as $index => $string) {
			$strings[$index] = trim($string);
		}
		return $strings;
	case '?':
	case '&':
		$string = substr($string, 1);
		parse_str($string, $strings);
		return $strings;
	}
	return $string;
}

/**
 * read default settings from .cfg files
 *
 * @param string $single_module (optional)
 * @return array
 */
function wrap_setting_cfg($single_module = false) {
	global $zz_setting;
	static $cfg;
	if ($single_module) {
		if (!empty($single_cfg[$single_module]))
			return $single_cfg[$single_module];
	} elseif (!empty($cfg)) {
		return $cfg;
	}

	$cfg_file_template = sprintf('%s/%%s/docs/sql/settings.cfg', $zz_setting['modules_dir']);
	$cfg = [];
	foreach ($zz_setting['modules'] as $module) {
		$cfg_file = sprintf($cfg_file_template, $module);
		if (!file_exists($cfg_file)) continue;
		$single_cfg[$module] = parse_ini_file($cfg_file, true);
		foreach ($single_cfg[$module] as $index => $config) {
			if (empty($config['description'])) continue;
			if (is_array($config['description'])) continue;
			$single_cfg[$module][$index]['description'] = wrap_text($config['description']);
		}
		$cfg += $single_cfg;
	}
	if ($single_module) {
		if (!array_key_exists($single_module, $single_cfg)) return [];
		return $single_cfg[$single_module];
	}
	return $cfg;
}

/**
 * recursively delete folders
 *
 * @param string $folder
 */
function wrap_unlink_recursive($folder) {
	$files = array_diff(scandir($folder), ['.', '..']);
	foreach ($files as $file) {
		$path = $folder.'/'.$file;
		is_dir($path) ? wrap_unlink_recursive($path) : unlink($path);
	}
	rmdir($folder);
}

/**
 * check for files in modules and custom folders, start with default folder
 *
 * @param string $tpl template for filename with two placeholders
 * @param string $order modules/custom or custom/modules
 * @return array
 */
function wrap_collect_files($filename, $order = 'custom/modules') {
	global $zz_setting;

	$files = [];
	// check modules (default always is first module)
	foreach ($zz_setting['modules'] as $module) {
		// disable default module?
		if ($module === 'default' AND !empty($zz_setting['default_dont_collect'][$filename]))
			continue;
		$file = sprintf('%s/%s/%s/%s', $zz_setting['modules_dir'], $module, $module, $filename);
		if (file_exists($file)) $files[] = $file;
	}

	// check custom folder
	$file = sprintf('%s/%s', $zz_setting['custom_wrap_dir'], $filename);
	if (file_exists($file)) {
		if ($order === 'custom/modules') {
			array_unshift($files, $file);
		} else {
			$files[] = $file;
		}
	}

	return $files;
}

/**
 * list filetypes
 *
 * @param string $filetype read configuration values for this filetype
 * @param string $action (optional, default 'read', 'write')
 * @return 
 */
function wrap_filetypes($filetype = false, $action = 'read') {
	global $zz_setting;
	static $filetypes;
	
	if (empty($filetypes)) {
		$filetypes = [];
		$files = wrap_collect_files('filetypes.cfg', 'modules/custom');
		foreach ($files as $filename) {
			$filetypes = wrap_filetypes_add($filename, $filetypes);
		}
	}

	switch ($action) {
	case 'read':
		if (!$filetype) return $filetypes;
		if (!array_key_exists($filetype, $filetypes)) return [];
		return $filetypes[$filetype];
	case 'write':
		// @todo not yet supported
		break;
	}
}

/**
 * add content of file to filetypes configuration
 *
 * @param string $filename
 * @param array $filetypes
 * @return array
 */
function wrap_filetypes_add($filename, $filetypes) {
	$new_filetypes = parse_ini_file($filename, true);
	$new_filetypes = wrap_filetypes_normalize($new_filetypes);
	foreach ($new_filetypes as $filetype => $definition) {
		// add or overwrite existing definitions
		$filetypes[$filetype] = $definition;
	}
	return $filetypes;
}

/**
 * set some values for filetypes array, allow shortcuts in definition
 *
 * @param array $filetypes
 * @return array $filetypes
 *		indexed by string type
 *		string 'description'
 *		array 'mime'
 *		array 'extension'
 *		bool 'thumbnail'
 *		bool 'multipage'
 */
function wrap_filetypes_normalize($filetypes) {
	foreach ($filetypes as $type => $values) {
		$filetypes[$type]['filetype'] = $type;
		if (empty($values['mime'])) {
			$filetypes[$type]['mime'][0] = 'application/octet-stream';
		} elseif (!is_array($values['mime'])) {
			$filetypes[$type]['mime'] = [0 => $values['mime']];
		}
		if (empty($values['extension'])) {
			$filetypes[$type]['extension'][0] = $type;
		} elseif (!is_array($values['extension'])) {
			$filetypes[$type]['extension'] = [0 => $values['extension']];
		}
		if (!array_key_exists('thumbnail', $values)) {
			 $filetypes[$type]['thumbnail'] = 0;
		}
		if (!array_key_exists('multipage', $values)) {
			 $filetypes[$type]['multipage'] = 0;
		}
	}
	return $filetypes;
}
