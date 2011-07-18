<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2010
// CMS Authentication functions


/*
	Functions in this file

	- wrap_auth()
		- wrap_authenticate_url()
		- wrap_session_stop()
	- cms_logout()
		- wrap_session_stop()
	- cms_login()
		- wrap_register()
		- wrap_login_format()
		- cms_login_redirect()
*/

/**
 * Checks if current URL needs authentication (will be called from zzwrap)
 *
 * - if current URL needs authentication: check if user is logged in, if not:
 * redirect to login page, else save last_click in database
 * - if current URL needs no authentication, but user is logged in: show that 
 * she or he is logged in, do not prolong login time, set person as logged out
 * if login time has passed
 * @global array $zz_setting
 * @global array $zz_page
 * @return bool true if login is necessary, false if no login is required
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_auth() {
	global $zz_setting;
	global $zz_page;

	if (!empty($zz_page['auth'])) return true; // don't run this function twice
	$zz_page['auth'] = true;

	// check if there are URLs that need authentication
	if (empty($zz_setting['auth_urls'])) return false;

	// send header for IE for P3P (Platform for Privacy Preferences Project)
	// if cookie is needed
	header('P3P: CP="NOI NID ADMa OUR IND UNI COM NAV"');

	// Local modifications to SQL queries
	wrap_sql('auth', 'set');

	// check if current URL needs authentication
	$authentication = false;
	if (!isset($zz_setting['no_auth_urls'])) 
		$zz_setting['no_auth_urls'] = array();
	foreach($zz_setting['auth_urls'] as $auth_url) {
		if (strtolower(substr($zz_page['url']['full']['path'], 0, strlen($auth_url))) != strtolower($auth_url))
			continue;
		if ($zz_page['url']['full']['path'] == $zz_setting['login_url'])
			continue;
		if (wrap_authenticate_url($zz_page['url']['full']['path'], $zz_setting['no_auth_urls']))
			$authentication = true;
	}

	if (!$authentication) {
		// Keep session if logged in and clicking on the public part of the page
		// but do not prolong time until automatically logging out someone
		if (isset($_SESSION)) return false;
		if (empty($_COOKIE[session_name()])) return false;
		wrap_session_start();
		// calculate maximum login time
		// you'll stay logged in for x minutes
		$keep_alive = $zz_setting['logout_inactive_after'] * 60;
		if (empty($_SESSION['last_click_at']) OR
			$_SESSION['last_click_at']+$keep_alive < time()) {
			// automatically logout
			wrap_session_stop();
		}
		return false;
	}

	$now = time();

	// start PHP session
	wrap_session_start();

	// if it's not local access (e. g. on development server), all access 
	// should go via secure connection
	$zz_setting['protocol'] = 'http'.((!empty($zz_setting['no_https']) OR $zz_setting['local_access']) ? '' : 's');
	// calculate maximum login time
	// you'll stay logged in for x minutes
	$keep_alive = $zz_setting['logout_inactive_after'] * 60;
	
	// Falls nicht oder zu lange eingeloggt, auf Login-Seite umlenken
	// initialize request, should be in front of nocookie
	$qs['request'] = false; 
	if (empty($_SESSION['logged_in']) 
		OR $now > ($_SESSION['last_click_at'] + $keep_alive)
		OR (isset($_SESSION['domain']) AND !in_array($_SESSION['domain'], wrap_sql('domain')))) {
		// get rid of domain, since user is not logged in anymore
		wrap_session_stop();
		if (!empty($zz_page['url']['full']['query'])) {
			// parse URL for no-cookie to hand it over to cms_login()
			// in case cookies are not allowed
			parse_str($zz_page['url']['full']['query'], $query_string);
			if (isset($query_string['no-cookie'])) {
				// add no-cookie to query string so login knows that there's no
				// cookie (in case SESSIONs don't work here)
				$qs['nocookie'] = 'no-cookie';
				unset($query_string['no-cookie']);
			}
			$full_query_string = array();
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
		// do not unnecessarily expose URL structure
		if ($request == $zz_setting['login_entryurl']
			OR (is_array($zz_setting['login_entryurl']) 
				AND in_array($request, $zz_setting['login_entryurl']))) unset($qs['request']); 
		else $qs['request'] = 'url='.urlencode($request);
		wrap_http_status_header(307);
		header('Location: '.$zz_setting['protocol'].'://'.$zz_setting['hostname']
			.$zz_setting['login_url']
			.(count($qs) ? '?'.implode('&', $qs) : ''));
		exit;
	}

	// remove no-cookie from URL
	$zz_page['url'] = wrap_remove_query_strings($zz_page['url'], 'no-cookie');
	
	// start database connection
	require_once $zz_setting['db_inc'];
	
	// save successful request in database to prolong login time
	$_SESSION['last_click_at'] = $now;
	if (!empty($_SESSION['login_id'])) {
		$sql = sprintf(wrap_sql('last_click'), $now, $_SESSION['login_id']);
		$result = mysql_query($sql);
		// it's not important if an error occurs here
		if (!$result)
			wrap_error(sprintf(wrap_text('Could not save "last_click" in database.')
				."\n\n%s\n%s", mysql_error(), $sql), E_USER_NOTICE);
	}
	if (!empty($_SESSION['mask_id']) AND $sql_mask = wrap_sql('last_masquerade')) {
		$logout = (time() + $zz_setting['logout_inactive_after'] * 60);
		$keep_alive = date('Y-m-d H:i:s', $logout);
		$sql_mask = sprintf($sql_mask, '"'.$keep_alive.'"', $_SESSION['mask_id']);
		$result = mysql_query($sql_mask);
		// it's not important if an error occurs here
		if (!$result)
			wrap_error(sprintf(wrap_text('Could not save "last_click" for masquerade in database.')
				."\n\n%s\n%s", mysql_error(), $sql_mask), E_USER_NOTICE);
	}
	return true;
}

/**
 * Stops SESSION if cookie exists but time is up
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
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
	if ($sql) $result = mysql_query($sql);
	if ($sql_mask) $result = mysql_query($sql_mask);
}


/**
 * Logout from restricted area
 *
 * should be used via %%% request logout %%%
 * @param array $params -
 * @return - (redirect to main page)
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function cms_logout($params) {
	global $zz_setting;
	global $zz_conf;

	// Local modifications to SQL queries
	wrap_sql('auth', 'set');
	
	// Stop the session, delete all session data
	wrap_session_stop();

	wrap_http_status_header(307);
	header('Location: '.$zz_setting['protocol'].'://'.$zz_setting['hostname']
		.$zz_setting['login_url'].'?logout');
	exit;
}

/**
 * Login to restricted area
 *
 * should be used via %%% request login %%%
 * @param array $params
 *		[0]: (optional) 'Single Sign On' for single sign on, then we must use
 *			[1]: {single sign on secret}
 *			[2]: {username}
 *			[3]: optional: {context}
 * @global array $zz_setting
 * @global array $zz_conf
 * @global array $zz_page
 * @return mixed bool false: login failed; array $page: login form; or redirect
 *		to (wanted) landing page
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function cms_login($params) {
	global $zz_setting;
	global $zz_conf;
	global $zz_page;

	// Local modifications to SQL queries
	wrap_sql('auth', 'set');

	// Set try_login to true if login credentials shall be checked
	// if set to false, first show login form
	$try_login = false;

	$login['username'] = '';
	$login['password'] = '';
	$login['single_sign_on'] = false;

	// Check if there are parameters for single sign on
	if (!empty($params[0]) AND $params[0] == 'Single Sign On') {
		if (empty($params[1]) OR $params[1] != $zz_setting['single_sign_on_secret']) return false;
		if (empty($params[2])) return false;
		if (!empty($params[4])) return false;
		$login['username'] = $params[2];
		if (!empty($params[3])) $login['context'] = $params[3];
		$login['single_sign_on'] = true;
	} elseif (!empty($params[0])) {
		return false; // other parameters are not allowed
	}

	// default settings
	if (empty($zz_setting['login_fields'])) {
		$zz_setting['login_fields'][] = 'Username';
	}

	$loginform = array();
	$loginform['msg'] = false;
	// someone tried to login via POST
	if ($_SERVER['REQUEST_METHOD'] == 'POST' OR $login['single_sign_on']) {
	// send header for IE for P3P (Platform for Privacy Preferences Project)
	// if cookie is needed
		header('P3P: CP="NOI NID ADMa OUR IND UNI COM NAV"');

		$try_login = true;
		// Session will be saved in Cookie so check whether we got a cookie or not
		wrap_session_start();
		
		// get password and username
		if ($_SERVER['REQUEST_METHOD'] == 'POST') {
			if (empty($_POST['username']) OR empty($_POST['password']))
				$loginform['msg'] = wrap_text('Password or username are empty. Please try again.');
			$full_login = array();
			foreach ($zz_setting['login_fields'] AS $login_field) {
				$login_field = strtolower($login_field);
				if (!empty($_POST[$login_field])) {
					$login[$login_field] = wrap_login_format($_POST[$login_field], $login_field);
					$full_login[] = $login[$login_field];
				} else {
					$full_login[] = '%empty%';
				}
			}
			if (!empty($_POST['password'])) {
				$login['password'] = $_POST['password'];
				unset($_POST['password']); // so we don't log it accidentally --> error_log_post
			}
		} else {
			$full_login[] = $login['username'];
			if (!empty($login['context'])) $full_login[] = $login['context'];
		}

		// check username and password
		$sql = sprintf(wrap_sql('login'), mysql_real_escape_string($login['username']));
		unset($_SESSION['logged_in']);
		$data = wrap_db_fetch($sql);
		if ($data) {
			$hash = array_shift($data);
			if ($login['single_sign_on']) {
				$_SESSION['logged_in'] = true;
			} elseif (wrap_password_check($login['password'], $hash)) {
				$_SESSION['logged_in'] = true;
			}
			unset($hash);
		}
		// if MySQL-Login does not work, try different sources
		// ... LDAP ...
		// ... different MySQL-server ...
		if (!empty($zz_setting['ldap_login']) AND empty($_SESSION['logged_in'])) {
			require_once $zz_setting['custom_wrap_dir'].'/ldap-login.inc.php';
			$data = cms_login_ldap($login);
			if ($data) $_SESSION['logged_in'] = true;
		}
	}

	// get URL where redirect is done to after logging in
	$url = false;
	if (!empty($zz_page['url']['full']['query'])) {
		parse_str($zz_page['url']['full']['query'], $querystring);
		if (!empty($querystring['url']))
			$url = $querystring['url'];
	}

	// everything was tried, so check if $_SESSION['logged_in'] is true
	// and in that case, register and redirect to wanted URL in media database
	if ($try_login) {
		if (empty($_SESSION['logged_in'])) { // Login not successful
			if (!$loginform['msg']) {
				$loginform['msg'] = wrap_text('Password or username incorrect. Please try again.');
			}
			wrap_error(sprintf(wrap_text('Password or username incorrect:')."\n\n%s\n%s", 
				implode('.', $full_login), wrap_password_hash($login['password'])), E_USER_NOTICE);
		} else {
			// Hooray! User has been logged in
			wrap_register(false, $data);
			if (!empty($_SESSION['change_password']) AND !empty($zz_setting['change_password_url'])) {
			// if password has to be changed, redirect to password change page
				if ($url) $url = '?url='.urlencode($url);
				if (is_array($zz_setting['change_password_url'])) {
					$url = $zz_setting['change_password_url'][$_SESSION['domain']].$url;
				} else {
					$url = $zz_setting['change_password_url'].$url;
				}
			} elseif (!$url) {
			// if there is no url= in query string, use default value
				if (is_array($zz_setting['login_entryurl'])) {
					$url = $zz_setting['login_entryurl'][$_SESSION['domain']];
				} else {
					$url = $zz_setting['login_entryurl'];
				}
			}
			// Redirect to protected landing page
			return cms_login_redirect($url);
		}
	}
	
	if (isset($zz_page['url']['full']['query']) 
		AND substr($zz_page['url']['full']['query'], 0, 6) == 'logout') {
		// Stop the session, delete all session data
		wrap_session_stop();
		$loginform['logout'] = true;
	}
	if (isset($_GET['no-cookie']))
		$loginform['no-cookie'] = true;
	$loginform['logout_inactive_after'] = $zz_setting['logout_inactive_after'];

	$params = array();
	if (!empty($url)) {
		$params[] = 'url='.urlencode($url);
		$zz_setting['cache'] = false;
	}
	if (isset($querystring['no-cookie'])) {
		$params[] = 'no-cookie';
		$zz_setting['cache'] = false;
	}
	$loginform['params'] = $params ? '?'.implode('&amp;', $params) : '';

	$loginform['fields'] = array();
	foreach ($zz_setting['login_fields'] AS $login_field) {
		$loginform['fields'][] = array(
			'title' => wrap_text($login_field.':'),
			'fieldname' => strtolower($login_field),
			// separate input, e. g. dropdown etc.
			'output' => !empty($zz_setting['login_fields_output'][$login_field])
				? $zz_setting['login_fields_output'][$login_field] : '',
			// text input
			'value' => !empty($_POST[strtolower($login_field)])
				? htmlspecialchars($_POST[strtolower($login_field)]) : ''
		);
	}
	$page['text'] = wrap_template('login', $loginform);
	$page['meta'][] = array('name' => 'robots',
		'content' => 'noindex, follow, noarchive'
	);
	return $page;
}

/**
 * Redirects to landing page after successful login
 *
 * @param string $url URL of landing page
 * @param array (optional) $querystring query string of current URL
 * @global array $zz_setting
 * @return - (redirect to different page)
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function cms_login_redirect($url, $querystring = array()) {
	global $zz_setting;
	
	// get correct protocol/hostname
	$zz_setting['protocol'] = 'http'.($zz_setting['no_https'] ? '' : 's');
	$zz_setting['myhost'] = $zz_setting['protocol'].'://'.$zz_setting['hostname'];

	// test whether COOKIEs for session management are allowed
	// if not, add no-cookie to URL so that wrap_auth() can hand that
	// back over to cms_login() if login was unsuccessful because of
	// lack of acceptance of cookies
	if (empty($_COOKIE) OR isset($querystring['no-cookie'])) {
		$redir_query_string = parse_url($zz_setting['myhost'].$url);
		if (!empty($redir_query_string['query']))
			$url .= '&no-cookie';
		else
			$url .= '?no-cookie';
	}
	wrap_http_status_header(303);
	header('Location: '.$zz_setting['myhost'].$url);
	exit;
}

/**
 * Writes SESSION-variables specific to different user ID
 *
 * @param int $user_id
 * @param array (optional) $data result of wrap_sql('login') or custom LDAP function
 * @global array $zz_setting
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_register($user_id = false, $data = array()) {
	global $zz_setting;

	// Local modifications to SQL queries
	wrap_sql('auth', 'set');

	if (!$data) {
		// keep login ID
		$login_id = $_SESSION['login_id'];
		$_SESSION = array();
		$_SESSION['logged_in'] = true;
		$_SESSION['login_id'] = $login_id;
		$_SESSION['user_id'] = $user_id;
		// masquerade login
		if ($sql = wrap_sql('login_masquerade')) {
			$sql = sprintf($sql, $user_id);
			$data = wrap_db_fetch($sql);
			$_SESSION['masquerade'] = true;
		}
		// data from cms_login_ldap() has to be dealt with in masquerade script
	}
	
	foreach ($data as $key => $value) {
		$_SESSION[$key] = $value; 
	}
	if (empty($_SESSION['domain'])) {
		$_SESSION['domain'] = $zz_setting['hostname'];
	}

	// Login: no user_id set so far, get it from SESSION
	if (!$user_id) $user_id = $_SESSION['user_id'];

	if ($sql = wrap_sql('login_settings') AND !empty($user_id)) {
		$sql = sprintf($sql, $user_id);
		$_SESSION['settings'] = wrap_db_fetch($sql, 'dummy_id', 'key/value');
	}
	// get user groups, if module present
	if (file_exists($zz_setting['custom_wrap_dir'].'/usergroups.inc.php')) {
		include $zz_setting['custom_wrap_dir'].'/usergroups.inc.php';
		wrap_register_usergroups($user_id);
	}
	$_SESSION['last_click_at'] = time();
	// writes values and regenerates IDs, against some weird bug if you entered
	// a wrong password before, php will lose the SESSION
	// see: http://www.php.net/manual/en/function.session-write-close.php
	session_regenerate_id(true); 
}

/**
 * reformats login field values with custom function
 *
 * @param string $field_value
 * @param string $field_name
 * @global array $zz_setting
 * @return string $field_value, reformatted
 */
function wrap_login_format($field_value, $field_name) {
	global $zz_setting;
	
	if (get_magic_quotes_gpc())
		$field_value = stripslashes($field_value);
	$field_value = mysql_real_escape_string($field_value);
	
	if (!empty($zz_setting['login_fields_format']))
		$field_value = $zz_setting['login_fields_format']($field_value, $field_name);

	return $field_value;
}

/**
 * check given password against database password hash
 *
 * @param string $pass password as entered by user
 * @param string $hash hash as stored in database
 * @global array $zz_conf
 *		'hash_password', 'hash_script'
 * @return bool true: given credentials are correct, false: no access!
 * @see zz_passsword_check()
 */
function wrap_password_check($pass, $hash) {
	global $zz_conf;
	if (!empty($zz_conf['hash_script']))
		require_once $zz_conf['hash_script'];
	// password must not be longer than 72 characters
	if (strlen($pass) > 72) return false;
	
	switch ($zz_conf['hash_password']) {
	case 'phpass':
		$hasher = new PasswordHash($zz_conf['hash_cost_log2'], $zz_conf['hash_portable']);
		if ($hasher->CheckPassword($pass, $hash)) return true;
		else return false;
	default:
		if ($hash === wrap_password_hash($pass)) return true;
		return false;
	}
}

/**
 * hash password
 *
 * @param string $pass password as entered by user
 * @global array $zz_conf
 *		'hash_password', 'password_salt',
 *		'hash_script', 'hash_cost_log2', 'hash_portable'
 * @return string hash
 * @see zz_passsword_hash()
 */
function wrap_password_hash($pass) {
	global $zz_conf;
	if (!empty($zz_conf['hash_script']))
		require_once $zz_conf['hash_script'];
	// password must not be longer than 72 characters
	if (strlen($pass) > 72) return false;

	switch ($zz_conf['hash_password']) {
	case 'phpass':
		$hasher = new PasswordHash($zz_conf['hash_cost_log2'], $zz_conf['hash_portable']);
		$hash = $hasher->HashPassword($pass);
		if (strlen($hash) < 20) return false;
		return $hash;
	default:
		if (!isset($zz_conf['password_salt'])) 
			$zz_conf['password_salt'] = '';
		return $zz_conf['hash_password']($pass.$zz_conf['password_salt']);
	}

	return $zz_conf['hash_password']($pass.$zz_conf['password_salt']);
}

?>