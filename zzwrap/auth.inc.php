<?php 

/**
 * zzwrap
 * Authentication functions
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 *	- wrap_auth()
 *		- wrap_authenticate_url()
 *		- wrap_session_stop()
 *	- cms_logout()
 *		- wrap_session_stop()
 *	- cms_login()
 *		- wrap_register()
 *		- wrap_login()
 *		- wrap_login_format()
 *		- cms_login_redirect()
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2012, 2014-2015 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Checks if current URL needs authentication (will be called from zzwrap)
 *
 * - if current URL needs authentication: check if user is logged in, if not:
 * redirect to login page, else save last_click in database
 * - if current URL needs no authentication, but user is logged in: show that 
 * she or he is logged in, do not prolong login time, set person as logged out
 * if login time has passed
 * @param bool $force explicitly force authentication
 * @global array $zz_setting
 * @global array $zz_page
 * @return bool true if login is necessary, false if no login is required
 */
function wrap_auth($force = false) {
	global $zz_setting;
	global $zz_page;
	static $authentication_was_called;

	if (!$force) {
		if ($authentication_was_called) return true; // don't run this function twice
	}
	$authentication_was_called = true;

	// check if there are URLs that need authentication
	if (empty($zz_setting['auth_urls'])) return false;

	// send header for IE for P3P (Platform for Privacy Preferences Project)
	// if cookie is needed
	header('P3P: CP="NOI NID ADMa OUR IND UNI COM NAV"');

	// Local modifications to SQL queries
	wrap_sql('auth', 'set');

	// check if current URL needs authentication
	if (!$force) {
		$authentication = false;
		foreach ($zz_setting['auth_urls'] as $auth_url) {
			if (strtolower(substr($zz_page['url']['full']['path'], 0, strlen($auth_url))) !== strtolower($auth_url))
				continue;
			if ($zz_page['url']['full']['path'] === $zz_setting['login_url'])
				continue;
			if (wrap_authenticate_url())
				$authentication = true;
		}

		if (!$authentication) {
			// Keep session if logged in and clicking on the public part of the page
			// but do not prolong time until automatically logging out someone
			if (isset($_SESSION)) return false;
			if (empty($_COOKIE['zugzwang_sid'])) return false;
			wrap_session_start();
			// calculate maximum login time
			// you'll stay logged in for x minutes
			$keep_alive = $zz_setting['logout_inactive_after'] * 60;
			if (empty($_SESSION['last_click_at']) OR
				$_SESSION['last_click_at'] + $keep_alive < time()) {
				// automatically logout
				wrap_session_stop();
			}
			return false;
		}
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
	
	$logged_in = true;
	// Falls nicht oder zu lange eingeloggt, auf Login-Seite umlenken
	// initialize request, should be in front of nocookie
	if (empty($_SESSION['logged_in']) 
		OR $now > ($_SESSION['last_click_at'] + $keep_alive)
		OR (isset($_SESSION['domain']) AND !in_array($_SESSION['domain'], wrap_sql('domain')))) {
		// get rid of domain, since user is not logged in anymore
		$logged_in = false;
	}

	if (!$logged_in) $logged_in = wrap_login_ip();
	if (!$logged_in) $logged_in = wrap_login_http_auth();

	if (!$logged_in) {
		wrap_session_stop();
		wrap_auth_loginpage();
		// = exit;
	}
	$_SESSION['logged_in'] = true;

	// remove no-cookie from URL
	$zz_page['url'] = wrap_remove_query_strings($zz_page['url'], 'no-cookie');
	
	// save successful request in database to prolong login time
	$_SESSION['last_click_at'] = $now;
	if (!empty($_SESSION['login_id'])) {
		$sql = sprintf(wrap_sql('last_click'), $now, $_SESSION['login_id']);
		// it's not important if an error occurs here
		$result = wrap_db_query($sql, E_USER_NOTICE);
	}
	if (!empty($_SESSION['mask_id']) AND $sql_mask = wrap_sql('last_masquerade')) {
		$logout = (time() + $zz_setting['logout_inactive_after'] * 60);
		$keep_alive = date('Y-m-d H:i:s', $logout);
		$sql_mask = sprintf($sql_mask, '"'.$keep_alive.'"', $_SESSION['mask_id']);
		// it's not important if an error occurs here
		$result = wrap_db_query($sql_mask, E_USER_NOTICE);
	}
	return true;
}

/**
 * redirect to login page if user is not logged in
 *
 * @return void (exit)
 */
function wrap_auth_loginpage() {
	global $zz_page;
	global $zz_setting;

	$qs = array();
	$qs['request'] = false; 
	$request = $zz_page['url']['full']['path'];
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
		if ($query_string) {
			$request .= '?'.http_build_query($query_string);
		}
	}
		// do not unnecessarily expose URL structure
	if ($request === $zz_setting['login_entryurl']
		OR (is_array($zz_setting['login_entryurl']) 
			AND in_array($request, $zz_setting['login_entryurl']))) unset($qs['request']); 
	else $qs['request'] = 'url='.urlencode($request);
	wrap_http_status_header(307);
	header('Location: '.$zz_setting['host_base'].$zz_setting['login_url']
		.(count($qs) ? '?'.implode('&', $qs) : ''));
	exit;
}

/**
 * Checks current URL against no auth URLs
 *
 * @param string $url URL from database
 * @param array $no_auth_urls ($zz_setting['no_auth_urls'])
 * @return bool true if authentication is required, false if not
 */
function wrap_authenticate_url($url = false, $no_auth_urls = array()) {
	global $zz_page;
	global $zz_setting;
	if (!$url) {
		$url = $zz_page['url']['full']['path'];
	}
	if (!$no_auth_urls AND !empty($zz_setting['no_auth_urls'])) {
		$no_auth_urls = $zz_setting['no_auth_urls'];
	}
	foreach ($no_auth_urls AS $test_url) {
		if (substr($url, 0, strlen($test_url)) === $test_url) {
			return false; // no authentication required
		}
	}
	return true; // no matches: authentication required
}

/**
 * Logout from restricted area
 *
 * should be used via %%% request logout %%%
 * @param array $params -
 * @return - (redirect to main page)
 */
function cms_logout($params) {
	global $zz_setting;
	global $zz_conf;

	// Local modifications to SQL queries
	wrap_sql('auth', 'set');
	
	// Stop the session, delete all session data
	wrap_session_stop();

	wrap_http_status_header(307);
	header('Location: '.$zz_setting['host_base'].$zz_setting['login_url'].'?logout');
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
	$login['different_sign_on'] = false;

	$loginform = array();
	$loginform['msg'] = false;

	// Check if there are parameters for single sign on
	if (!empty($_GET['auth'])) {
		$login = wrap_login_hash($_GET['auth'], $login);
		// if successful, redirect
		$loginform['msg'] = wrap_text('Link for login is wrong or out of date. Please get a new one.');
	} elseif (!empty($params[0]) AND $params[0] === 'Single Sign On') {
		if (count($params) > 4) return false;
		if (count($params) < 3) return false;
		if ($params[1] !== $zz_setting['single_sign_on_secret']) return false;
		$login['username'] = $params[2];
		if (!empty($params[3])) $login['context'] = $params[3];
		$login['different_sign_on'] = true;
	} elseif (!empty($params[0])) {
		return false; // other parameters are not allowed
	}

	// default settings
	if (empty($zz_setting['login_fields'])) {
		$zz_setting['login_fields'][] = 'Username';
	}

	// someone tried to login via POST
	if ($_SERVER['REQUEST_METHOD'] === 'POST' AND !empty($_POST['request_password'])) {
		$loginform['name'] = !empty($_POST['name']) ? $_POST['name'] : '';
		if (!empty($_POST['mail'])) {
			$loginform['mail'] = $_POST['mail'];
			if (wrap_mail_valid($_POST['mail'])) {
				$loginform['mail_sent'] = true;
				$loginform['login_link_valid'] = wrap_get_setting('password_key_validity_in_minutes');
				wrap_password_reminder($_POST['mail']);
			} else {
				$loginform['mail_invalid'] = true;
				wrap_error(sprintf(
					'Request for password with invalid e-mail address: %s (%s)',
					$_POST['mail'], $loginform['name']
				));
			}
		} else {
			$loginform['mail_missing'] = true;
		}
	} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' OR $login['different_sign_on']) {
		// send header for IE for P3P (Platform for Privacy Preferences Project)
		// if cookie is needed
		header('P3P: CP="NOI NID ADMa OUR IND UNI COM NAV"');

		$try_login = true;
		
		// get password and username
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
				// remove pwd so we don't log it accidentally --> error_log_post
				unset($_POST['password']);
			}
		} else {
			$full_login[] = $login['username'];
			if (!empty($login['context'])) $full_login[] = $login['context'];
		}

		// Session will be saved in Cookie so check whether we got a cookie or not
		wrap_session_start();
		$_SESSION['logged_in'] = wrap_login($login);
	}

	// get URL where redirect is done to after logging in
	$url = false;
	if (!empty($zz_page['url']['full']['query'])) {
		parse_str($zz_page['url']['full']['query'], $querystring);
		if (!empty($querystring['url']))
			$url = $querystring['url'];
	}

	// everything was tried, so check if $_SESSION['logged_in'] is true
	// and in that case, redirect to wanted URL in database
	if ($try_login) {
		if (empty($_SESSION['logged_in'])) { // Login not successful
			if (!$loginform['msg']) {
				$loginform['msg'] = wrap_text('Password or username incorrect. Please try again.');
			}
			$user = implode('.', $full_login);
			if (empty($zz_conf['user'])) {
				// Log failed login name in user name column, once.
				$zz_conf['user'] = $user;
				$user = '';
			} else {
				$user .= "\n";
			}
			$error_settings = array(
				'log_post_data' => false
			);
			wrap_error(sprintf(wrap_text('Password or username incorrect:')."\n\n%s%s", 
				$user, wrap_password_hash($login['password'])), E_USER_NOTICE, $error_settings);
		} else {
			// Hooray! User has been logged in
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
		AND substr($zz_page['url']['full']['query'], 0, 6) === 'logout') {
		// Stop the session, delete all session data
		wrap_session_stop();
		$loginform['logout'] = true;
	} else {
		$loginform['logout'] = false;
	}
	$loginform['no_cookie'] = isset($_GET['no-cookie']) ? true : false;
	$loginform['logout_inactive_after'] = $zz_setting['logout_inactive_after'];

	$params = array();
	if (!empty($url)) {
		$params[] = 'url='.urlencode($url);
		$zz_setting['cache'] = false;
	}
	if (isset($querystring['no-cookie'])) {
		$params[] = 'no-cookie';
	}
	if (!empty($querystring)) {
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
				? wrap_html_escape($_POST[strtolower($login_field)]) : ''
		);
	}
	$loginform['password_link'] = wrap_get_setting('password_link');
	if ($loginform['password_link'] === true) {
		$loginform['password_link'] = '?password';
	}
	$page['query_strings'] = array('password', 'auth');
	if (isset($_GET['password'])) {
		$page['text'] = wrap_template('login-password', $loginform);
		$page['breadcrumbs'][] = sprintf('<a href="./">%s</a>', wrap_text('Login'));
		$page['breadcrumbs'][] = wrap_text('Request password');
	} else {
		$page['text'] = wrap_template('login', $loginform);
	}
	$page['meta'][] = array(
		'name' => 'robots',
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
 */
function cms_login_redirect($url, $querystring = array()) {
	global $zz_setting;
	
	// get correct protocol/hostname
	$zz_setting['protocol'] = 'http'.($zz_setting['no_https'] ? '' : 's');
	$zz_setting['host_base'] = $zz_setting['protocol'].'://'.$zz_setting['hostname'];

	// test whether COOKIEs for session management are allowed
	// if not, add no-cookie to URL so that wrap_auth() can hand that
	// back over to cms_login() if login was unsuccessful because of
	// lack of acceptance of cookies
	if (empty($_COOKIE) OR isset($querystring['no-cookie'])) {
		$redir_query_string = parse_url($zz_setting['host_base'].$url);
		if (!empty($redir_query_string['query']))
			$url .= '&no-cookie';
		else
			$url .= '?no-cookie';
	}
	wrap_http_status_header(303);
	header('Location: '.$zz_setting['host_base'].$url);
	exit;
}

/**
 * Login to a website and register user if successful
 *
 * @param array $login
 *		string 'username'
 *		string 'password'
 *		bool 'different_sign_on'
 * @return bool true: login was successful, false: login was not successful
 */
function wrap_login($login) {
	global $zz_setting;
	$logged_in = false;

	// check username and password
	$data = wrap_login_db($login);
	if ($data) $logged_in = true;

	// if database login does not work, try different sources
	// ... LDAP ...
	// ... different database server ...
	if (!empty($zz_setting['ldap_login']) AND !$logged_in) {
		$data = cms_login_ldap($login);
		if ($data) $logged_in = true;
	}
	if (!$logged_in) return false;
	wrap_register(false, $data);
	return true;
}

/**
 * check login credentials against website, get userdata
 *
 * @param array $login
 *		string 'username'
 *		string 'password'
 *		bool 'different_sign_on'
 * @return array $data
 */
function wrap_login_db($login) {
	$sql = sprintf(wrap_sql('login'), wrap_db_escape($login['username']));
	$data = wrap_db_fetch($sql);
	if (!$data) return array();

	$hash = array_shift($data);
	if (!empty($login['different_sign_on'])) {
		return $data;
	} elseif (wrap_password_check($login['password'], $hash, $data['login_id'])) {
		return $data;
	}
	return array();
}

/**
 * Check if a Login via IP address is allowed
 *
 * @param void
 * @return bool true: login was successful
 */
function wrap_login_ip() {
	$sql = wrap_sql('login_ip');
	if (!$sql) return false;
	$sql = sprintf($sql, wrap_db_escape(inet_pton($_SERVER['REMOTE_ADDR'])));
	$username = wrap_db_fetch($sql, '', 'single value');
	if (!$username) return false;

	$login['different_sign_on'] = true;
	$login['username'] = $username;
	return wrap_login($login);
}

/**
 * Login via HTTP auth
 * can be forced by sending a custom HTTP header X-Request-WWW-Authentication
 *
 * @param void
 * @return bool true: login was successful
 */
function wrap_login_http_auth() {
	global $zz_conf;
	if (empty($_SERVER['HTTP_X_REQUEST_WWW_AUTHENTICATION'])) return false;

	// Fast-CGI workaround
	// needs this line in Apache server configuration:
	// RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
	if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
		list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = 
			explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
	}

	// send WWW-Authenticate header to get username if not yet there
	if (empty($_SERVER['PHP_AUTH_USER'])) {
		header(sprintf('WWW-Authenticate: Basic realm="%s"', $zz_conf['project']));
		wrap_http_status_header(401);
		exit;
	}
	if (empty($_SERVER['PHP_AUTH_USER'])) return false;
	if (empty($_SERVER['PHP_AUTH_PW'])) return false;

	// check password
	$password = wrap_password_token($_SERVER['PHP_AUTH_USER']);
	if ($password !== $_SERVER['PHP_AUTH_PW']) return false;
	
	$login['different_sign_on'] = true;
	$login['username'] = $_SERVER['PHP_AUTH_USER'];
	return wrap_login($login);
}

/**
 * Login via hash
 *
 * @param string $hash (id-hash)
 * @param array $login
 * @return bool true: login was successful
 */
function wrap_login_hash($hash, $login) {
	$hash = explode('-', $hash);
	$username = array_shift($hash);
	$hash = implode('-', $hash);

	$password = wrap_password_token($username, 'password_key');
	if ($password !== $hash) return $login;
	$login['different_sign_on'] = true;
	$login['username'] = $username;
	return $login;
}

/**
 * Writes SESSION-variables specific to different user ID
 *
 * @param int $user_id
 * @param array (optional) $data result of wrap_sql('login') or custom LDAP function
 * @global array $zz_setting
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
	$usergroups_file = $zz_setting['custom_rights_dir'].'/usergroups.inc.php';
	if (file_exists($usergroups_file)) {
		include_once $usergroups_file;
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
	$field_value = wrap_db_escape($field_value);
	
	if (!empty($zz_setting['login_fields_format']))
		$field_value = $zz_setting['login_fields_format']($field_value, $field_name);

	return $field_value;
}

/**
 * check given password against database password hash
 *
 * @param string $pass password as entered by user
 * @param string $hash hash as stored in database
 * @param int $login_id
 * @global array $zz_conf
 *		'hash_password', 'hash_script'
 * @return bool true: given credentials are correct, false: no access!
 * @see zz_passsword_check()
 */
function wrap_password_check($pass, $hash, $login_id) {
	global $zz_conf;
	if (!empty($zz_conf['hash_script']))
		require_once $zz_conf['hash_script'];
	// password must not be longer than 72 characters
	if (strlen($pass) > 72) return false;
	
	switch ($zz_conf['hash_password']) {
	case 'phpass':
		$hasher = new PasswordHash($zz_conf['hash_cost_log2'], $zz_conf['hash_portable']);
		if ($hasher->CheckPassword($pass, $hash)) return true;
		return false;
	case 'phpass-md5':
		// to transfer old double md5 hashed logins without salt to more secure logins
		$hasher = new PasswordHash($zz_conf['hash_cost_log2'], $zz_conf['hash_portable']);
		if ($hasher->CheckPassword($pass, $hash)) return true;
		if ($hasher->CheckPassword(md5($pass), $hash)) {
			// Update existing password
			require_once $zz_conf['dir'].'/zzform.php';
			$values['action'] = 'update';
			$values['POST']['login_id'] = $login_id;
			$values['POST']['secure_password'] = 'yes';
			$values['POST'][wrap_sql('password')] = $pass;
			$ops = zzform_multi('logins', $values);
			return true;
		}
		return false;
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
	case 'phpass-md5':
		$hasher = new PasswordHash($zz_conf['hash_cost_log2'], $zz_conf['hash_portable']);
		$hash = $hasher->HashPassword($pass);
		if (strlen($hash) < 20) return false;
		return $hash;
	}
	if (!isset($zz_conf['password_salt'])) 
		$zz_conf['password_salt'] = '';
	return $zz_conf['hash_password']($pass.$zz_conf['password_salt']);
}

/**
 * create a password token for a user to login without password
 * dependent on user ID, username, existing password, secret and timeframe
 *
 * @param string $username (will be taken from SESSION if not set)
 * @param string $secret_key name of secret key in settings
 * @return string
 */
function wrap_password_token($username = '', $secret_key = 'login_key') {
	if (!$username) $username = $_SESSION['username'];
	// get password, even if it is empty
	$sql = wrap_sql('login');
	$sql = sprintf($sql, $username);
	$userdata = wrap_db_fetch($sql);
	if (!$userdata AND $sql = wrap_sql('login_user_id')) {
		$sql = sprintf($sql, $username);
		$userdata = wrap_db_fetch($sql);
	}
	if (!$userdata) return false;
	$password_in_db = array_shift($userdata);
	$password = wrap_set_hash(
		sprintf('%s %d %s', $userdata['username'], $userdata['user_id'], $password_in_db),
		$secret_key
	);
	return $password;
}

/**
 * send a password reminder
 *
 * @param string $address E-Mail
 * @return bool
 */
function wrap_password_reminder($address) {
	$sql = wrap_sql('password_reminder');
	$sql = sprintf($sql, wrap_db_escape($address));
	$data = wrap_db_fetch($sql);
	if (!$data) {
		wrap_error(sprintf('A password was requested for e-mail %s, but there was no login in the database.', $address));
		return false;
	} elseif (!$data['active']) {
		wrap_error(sprintf('A password was requested for e-mail %s, but the login is disabled.', $address));
		return false;
	}
	$data['token'] = $data['username'].'-'.wrap_password_token($data['username'], 'password_key');

	$mail = array();
	$mail['to']['name'] = $data['recipient'];
	$mail['to']['e_mail'] = $data['e_mail'];
	$mail['subject'] = wrap_text('Forgotten Password');
	$mail['message'] = wrap_template('password-reminder-mail', $data);
	return wrap_mail($mail);
}
