<?php 

/**
 * zzwrap
 * session handling
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * will start a session with some parameters set before
 *
 * @return bool
 */
function wrap_session_start() {
	// is already a session active?
	if (session_id()) {
		session_write_close();
		session_start();
		return true;
	}
	// change session_save_path
	if (wrap_setting('session_save_path')) {
		$success = wrap_mkdir(wrap_setting('session_save_path'));
		if ($success) 
			session_save_path(wrap_setting('session_save_path'));
	}
	// Cookie: httpOnly, i. e. no access for JavaScript if browser supports this
	$last_error = false;
	session_set_cookie_params(0, '/', wrap_setting('hostname'), wrap_setting('session_secure_cookie'), true);
	$last_error = wrap_error_handler('last_error');
	// don't collide with other PHPSESSID on the same server, set own name:
	session_name('zugzwang_sid');
	// check if not illegal characters in session cookie
	if (!empty($_COOKIE['zugzwang_sid']) AND !preg_match('/^[A-Za-z0-9-,]+$/', $_COOKIE['zugzwang_sid'])) {
		wrap_error(sprintf('Illegal session cookie value found: %s', $_COOKIE['zugzwang_sid']), E_USER_NOTICE);
		unset($_COOKIE['zugzwang_sid']);
	}
	$success = session_start();
	// try it twice, some providers have problems with ps_files_cleanup_dir()
	// accessing the /tmp-directory and failing temporarily with
	// insufficient access rights
	// only throw 503 error if authentication is a MUST HAVE
	// otherwise, page might still be accessible without authentication
	if (wrap_setting('authentication_possible') AND wrap_authenticate_url()) {
		$session_error = wrap_error_handler('last_error');
		if ($last_error != $session_error
			AND str_starts_with($session_error['message'], 'session_start()')) {
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
	if (!empty($_SESSION['login_id']) AND $sql = wrap_sql_query('auth_logout'))
		$sql = sprintf($sql, $_SESSION['login_id']);
	if (!empty($_SESSION['mask_id']) AND $sql_mask = wrap_sql_query('auth_last_masquerade'))
		$sql_mask = sprintf($sql_mask, 'NOW()', $_SESSION['mask_id']);
	// Unset all of the session variables.
	$_SESSION = [];
	// If it's desired to kill the session, also delete the session cookie.
	// Note: This will destroy the session, and not just the session data!
	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		// check if this is www.example.com and there might be session
		// coookies from example.com, remove these sessions, too
		$domains[0] = $params['domain'];
		$subdomain_dots = str_ends_with($domains[0], '.local') ? 2 : 1;
		$i = 0;
		while (substr_count($domains[$i], '.') > $subdomain_dots) {
			$i++;
			$domains[$i] = explode('.', $domains[$i-1]);
			array_shift($domains[$i]);
			$domains[$i] = implode('.', $domains[$i]);
		}
		foreach ($domains as $domain) {
			$params['domain'] = $domain;
			if (version_compare(PHP_VERSION, '7.3.0') >= 1) {
				unset($params['lifetime']);
				$params['expires'] = time() - 42000;
				setcookie(session_name(), '', $params);
			} else {
				setcookie(session_name(), '', time() - 42000, $params['path'],
					$params['domain'], $params['secure'], isset($params['httponly'])
				);
			}
		}
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
 * @param string $qs optional query string for URL
 * @return mixed
 *		array $page => cookies are not allowed, output message
 *		bool true => everything ok
 */
function wrap_session_check($token, $qs = '') {
	wrap_session_start();
	if (array_key_exists('no-cookie', $_GET)) {
		return wrap_session_cookietest_end($token, $qs);
	}
	if (empty($_SESSION[$token])) {
		// Cookietest durch redirect auf dieselbe URL mit ?cookie am Ende
		return wrap_session_cookietest_start($token, $qs);
	}
	session_write_close();
	return true;
}

/**
 * start a session and redirect to another URL with ?no-cookie to check if
 * the session is still active
 *
 * @param string $token name of the token
 * @param string $qs optional query string for URL
 * @return void redirect to another URL
 */
function wrap_session_cookietest_start($token, $qs) {
	global $zz_page;
	$_SESSION[$token] = true;
	$_SESSION['last_click_at'] = time();
	session_write_close();
	
	$qs = $qs ? $qs.'&no-cookie' : 'no-cookie';
	$url = $zz_page['url']['full'];
	if (empty($url['query'])) {
		$url['query'] = $qs;
	} else {
		$url['query'] .= '&'.$qs;
	}
	return wrap_redirect(wrap_glue_url($url), 302, false);
}

/**
 * check if session exists and if yes, redirect to old URL
 *
 * @param string $token name of the token
 * @param string $qs optional query string for URL
 * @return mixed
 *		void redirect to old URL if everything is ok
 *		array $page if a cookie message should be sent back to user
 */
function wrap_session_cookietest_end($token, $qs) {
	global $zz_page;
	session_write_close();
	$url = $zz_page['url']['full'];
	parse_str($url['query'], $query);
	unset($query['no-cookie']);
	$url['query'] = http_build_query($query);
	$data['url'] = wrap_glue_url($url);
	if (!empty($_SESSION[$token])) {
		return wrap_redirect($data['url'], 302, false);
	}

	// remove custom query string from URL, allow them for current view
	if ($qs) {
		parse_str($qs, $qs);
		foreach ($qs as $key => $value) {
			$page['query_strings'][] = $key;
			unset($query[$key]);
		}
		$url['query'] = http_build_query($query);
		$data['url'] = wrap_glue_url($url);
	}

	// return cookie missing message
	$page['dont_show_h1'] = true;
	$page['meta'][] = ['name' => 'robots', 'content' => 'noindex'];
	$page['breadcrumbs'][]['title'] = 'Cookies';
	$page['text'] = wrap_template('cookie', $data);
	return $page;
}
