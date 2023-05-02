<?php 

/**
 * zzwrap
 * Authentication functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
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
 * @copyright Copyright © 2007-2012, 2014-2023 Gustaf Mossakowski
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
 * @global array $zz_page
 * @return bool true if login is necessary, false if no login is required
 */
function wrap_auth($force = false) {
	global $zz_page;
	static $authentication_was_called;

	if (!$force AND $authentication_was_called) return true; // don't run this function twice
	$authentication_was_called = true;

	// check if there are URLs that need authentication
	if (!wrap_setting('auth_urls')) return false;

	// send header for IE for P3P (Platform for Privacy Preferences Project)
	// if cookie is needed
	header('P3P: CP="This site does not have a p3p policy."');

	// check if current URL needs authentication
	if (!$force) {
		$authentication = false;
		foreach (wrap_setting('auth_urls') as $auth_url) {
			if (!str_starts_with(strtolower($zz_page['url']['full']['path']), strtolower($auth_url)))
				continue;
			if ($zz_page['url']['full']['path'] === wrap_setting('login_url'))
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
			$keep_alive = wrap_setting('logout_inactive_after') * 60;
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
	wrap_setting('protocol', 'http'.((wrap_setting('no_https')
		OR (wrap_setting('local_access') AND !wrap_setting('local_https'))) ? '' : 's'));
	// calculate maximum login time
	// you'll stay logged in for x minutes
	
	$logged_in = wrap_auth_logged_in($now);
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
		$sql = sprintf(wrap_sql_query('auth_last_click'), $now, $_SESSION['login_id']);
		// it's not important if an error occurs here
		wrap_db_query($sql, E_USER_NOTICE);
	}
	if (!empty($_SESSION['mask_id']) AND $sql_mask = wrap_sql_query('auth_last_masquerade')) {
		$logout = (time() + wrap_setting('logout_inactive_after') * 60);
		$keep_alive = date('Y-m-d H:i:s', $logout);
		$sql_mask = sprintf($sql_mask, '"'.$keep_alive.'"', $_SESSION['mask_id']);
		// it's not important if an error occurs here
		wrap_db_query($sql_mask, E_USER_NOTICE);
	}
	if (!empty($zz_page['url']['redirect'])) {
		wrap_redirect(wrap_glue_url($zz_page['url']['full']), 301, false);
	}
	return true;
}

/**
 * check if user is logged in
 *
 * @param int $now
 * @return bool
 */
function wrap_auth_logged_in($now) {
	// session says no
	if (empty($_SESSION['logged_in'])) return false;

	// logout because of inactivty?
	$keep_alive = wrap_setting('logout_inactive_after') * 60;
	if ($now > ($_SESSION['last_click_at'] + $keep_alive)) return false;

	// logged in, but under different domain?
	if (isset($_SESSION['domain'])) {
		if (in_array($_SESSION['domain'], wrap_setting('domains'))) return true;
		// is it just not the canonical hostname?
		$canonical_hostname = wrap_canonical_hostname();
		if ($canonical_hostname
			AND wrap_setting('hostname') !== wrap_canonical_hostname()
			AND $_SESSION['domain'] === $canonical_hostname
		) return true;
		return false;
	}

	// everything ok
	return true;
}

/**
 * redirect to login page if user is not logged in
 *
 * @return void (exit)
 */
function wrap_auth_loginpage() {
	global $zz_page;

	$qs = [];
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
	if ($request === wrap_domain_path('login_entry')) unset($qs['request']); 
	else $qs['request'] = 'url='.urlencode($request);
	wrap_redirect(wrap_setting('host_base').wrap_setting('login_url')
		.(count($qs) ? '?'.implode('&', $qs) : ''), 307, false);
	exit;
}

/**
 * Checks current URL against no auth URLs
 *
 * @param string $url URL from database
 * @param array $no_auth_urls
 * @return bool true if authentication is required, false if not
 */
function wrap_authenticate_url($url = false, $no_auth_urls = []) {
	global $zz_page;
	if (!$url)
		$url = $zz_page['url']['full']['path'];
	if (!$no_auth_urls)
		$no_auth_urls = wrap_setting('no_auth_urls');
	foreach ($no_auth_urls AS $test_url)
		// no authentication required
		if (str_starts_with($url, $test_url)) return false;
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
	// Stop the session, delete all session data
	wrap_session_stop();

	$host_base = str_starts_with(wrap_setting('login_url'), '/') ? wrap_setting('host_base') : '';
	wrap_redirect($host_base.wrap_setting('login_url').'?logout', 307, false);
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
 * @param array $settings (optional)
 *		[action] = set a different action URL, defaults to ./
 * @global array $_GET
 *		string 'auth': [username]-[hash] for login if password is forgotten
 *		bool 'via': check login data from a different server, POST some JSON
 *		bool 'no-cookie': for cookie check only
 *		bool 'password': show form to retrieve forgotten password
 * @global array $zz_page
 * @return mixed bool false: login failed; array $page: login form; or redirect
 *		to (wanted) landing page
 */
function cms_login($params, $settings = []) {
	global $zz_page;

	wrap_setting_add('extra_http_headers', 'X-Frame-Options: Deny');
	wrap_setting_add('extra_http_headers', "Content-Security-Policy: frame-ancestors 'self'");

	if (!empty($_SESSION['logged_in'])) {
		$url = cms_login_redirect_url();
		if ($url) return cms_login_redirect($url);
	}

	// Set try_login to true if login credentials shall be checked
	// if set to false, first show login form
	$try_login = false;

	$login['username'] = '';
	$login['password'] = '';
	$login['different_sign_on'] = false;

	$loginform = [];
	$loginform['msg'] = false;
	$loginform['action_url'] = !empty($settings['action_url']) ? $settings['action_url'] : './';
	$loginform['password_link'] = wrap_setting('password_link');
	if ($loginform['password_link'] === true) {
		$loginform['password_link'] = $loginform['action_url'].'?password';
	}

	// Check if there are parameters for single sign on
	if (!empty($_GET['add']) OR !empty($_GET['link']) OR !empty($_GET['request'])) {
		if (!in_array('contacts', wrap_setting('modules'))) wrap_quit(404);
		if (!empty($_GET['add']))
			return brick_format('%%% make addlogin '.implode(' ', explode('-', $_GET['add'])).'%%%');
		// @deprecated
		if (!empty($_GET['request']))
			return brick_format('%%% make addlogin '.implode(' ', explode('-', $_GET['request'])).'%%%');
		if (!empty($_GET['link']))
			return brick_format('%%% make linklogin '.implode(' ', explode('-', $_GET['link'])).'%%%');
	} elseif (!empty($_GET['auth'])) {
		$login = wrap_login_hash($_GET['auth'], $login);
		// if successful, redirect
		$loginform['msg'] = sprintf('%s <a href="%s">%s</a>'
			, wrap_text('Link for login is wrong or out of date.')
			, $loginform['password_link']
			, wrap_text('Please get a new one.')
		);
	} elseif (!empty($params[0]) AND $params[0] === 'Single Sign On') {
		if (count($params) > 5) return false;
		if (count($params) < 4) return false;
		switch ($params[1]) {
		case 'sso_token':
			$login['sso_token'] = $params[2];
			break;
		case 'sso_hash':
			if ($params[2] !== wrap_setting('single_sign_on_secret')) return false;
			break;
		}
		$login['username'] = $params[3];
		if (!empty($params[4])) $login['context'] = $params[4];
		$login['different_sign_on'] = true;
		$login['create_missing_user'] = true;
	} elseif (!empty($params[0])) {
		return false; // other parameters are not allowed
	}

	// someone tried to login via POST
	if ($_SERVER['REQUEST_METHOD'] === 'POST' AND !empty($_POST['zz_action'])
		AND empty($_POST['zz_review_via_login'])) {
		wrap_include_files('functions', 'zzform');
		$loginform['hidden_fields'] = zz_session_via_login();
	} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' AND !empty($_POST['request_password'])) {
		$loginform['name'] = !empty($_POST['name']) ? $_POST['name'] : '';
		if (!empty($_POST['mail'])) {
			$loginform['mail'] = trim($_POST['mail']);
			if (wrap_mail_valid($loginform['mail'])) {
				$loginform['mail_sent'] = true;
				$loginform['login_link_valid'] = wrap_setting('password_key_validity_in_minutes');
				wrap_password_reminder($loginform['mail'], !empty($settings['reminder_data']) ? $settings['reminder_data'] : []);
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
		header('P3P: CP="This site does not have a p3p policy."');

		$try_login = true;
		
		// get password and username
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			if (empty($_POST['username']) OR empty($_POST['password']))
				$loginform['msg'] = wrap_text('Password or username are empty. Please try again.');
			$full_login = [];
			foreach (wrap_setting('login_fields') AS $login_field) {
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
		if (!empty($login['change_password'])) {
			$_SESSION['change_password'] = true;
		}
		if (!empty($login['dont_require_old_password'])) {
			$_SESSION['dont_require_old_password'] = true;
		}
		if (!empty($_POST['zz_review_via_login'])) {
			$_SESSION['zzform']['review_via_login'] = $_POST['zz_review_via_login'];
		}
	}

	// get URL where redirect is done to after logging in
	$url = cms_login_redirect_url();

	// everything was tried, so check if $_SESSION['logged_in'] is true
	// and in that case, redirect to wanted URL in database
	if ($try_login) {
		if (empty($_SESSION['logged_in'])) { // Login not successful
			if (!$loginform['msg'])
				$loginform['msg'] = wrap_text('Password or username incorrect. Please try again.');
			$user = implode('.', $full_login);
			if ($username = wrap_username() AND $username !== $user) {
				// alread a user is logged in, tried to log in to another account
				$user .= "\n";
			} else {
				// Log failed login name in user name column, once.
				wrap_setting('log_username', $user);
				$user = '';
			}
			$error_settings = [
				'log_post_data' => false
			];
			wrap_error(sprintf(wrap_text('Password or username incorrect:')."\n\n%s%s", 
				$user, wrap_password_hash($login['password'])), E_USER_NOTICE, $error_settings);
		} else {
			// Hooray! User has been logged in
			if (!empty($_SESSION['change_password']) AND wrap_domain_path('change_password')) {
			// if password has to be changed, redirect to password change page
				if ($url) $url = '?url='.urlencode($url);
				$url = wrap_domain_path('change_password').$url;
			} elseif (!$url) {
			// if there is no url= in query string, use default value
				$url = wrap_domain_path('login_entry');
			}
			// Redirect to protected landing page
			if ((!empty($_GET) AND array_key_exists('via', $_GET))
				OR !empty($login['single_sign_on'])) {
				return wrap_auth_show_session();
			}
			return cms_login_redirect($url);
		}
	}
	
	if (isset($zz_page['url']['full']['query']) 
		AND str_starts_with($zz_page['url']['full']['query'], 'logout')) {
		// Stop the session, delete all session data
		wrap_session_stop();
		$loginform['logout'] = true;
	} else {
		$loginform['logout'] = false;
	}
	$loginform['no_cookie'] = isset($_GET['no-cookie']) ? true : false;

	$params = [];
	if (!empty($url)) {
		$params[] = 'url='.urlencode($url);
		wrap_setting('cache', false);
	}
	if (isset($querystring['no-cookie'])) {
		$params[] = 'no-cookie';
	}
	if (isset($querystring['via'])) {
		$params[] = 'via';
	}
	if (!empty($querystring)) {
		wrap_setting('cache', false);
	}
	$loginform['params'] = $params ? '?'.implode('&amp;', $params) : '';

	$loginform['fields'] = [];
	$login_fields_output = wrap_setting('login_fields_output');
	foreach (wrap_setting('login_fields') AS $login_field) {
		$loginform['fields'][] = [
			'title' => wrap_text($login_field.':'),
			'fieldname' => strtolower($login_field),
			// separate input, e. g. dropdown etc.
			'output' => !empty($login_fields_output[$login_field])
				? $login_fields_output[$login_field] : '',
			// text input
			'value' => !empty($_POST[strtolower($login_field)])
				? wrap_html_escape($_POST[strtolower($login_field)]) : ''
		];
	}
	$page['query_strings'] = [
		'password', 'auth', 'via', 'request', 'add', 'link', 'username', 'token', 'url'
	];
	if (isset($_GET['password'])) {
		$page['text'] = wrap_template('login-password', $loginform);
		$page['breadcrumbs'][] = sprintf('<a href="./">%s</a>', wrap_text('Login'));
		$page['breadcrumbs'][] = wrap_text('Request password');
	} elseif (isset($_GET['via'])) {
		$page['status'] = 403;
		// @todo JSON content type will be overwritten with HTML in errorhandling
		$page['content_type'] = 'json';
		$page['text'] = json_encode('Login failed. Password or username are incorrect');
	} else {
		$page['text'] = wrap_template('login', $loginform);
	}
	$page['meta'][] = [
		'name' => 'robots',
		'content' => 'noindex, follow, noarchive'
	];
	return $page;
}

/**
 * get redirect URL from query string
 *
 * @global array $zz_page
 * @return string
 */
function cms_login_redirect_url() {
	global $zz_page;
	if (empty($zz_page['url']['full']['query'])) return false;
	parse_str($zz_page['url']['full']['query'], $querystring);
	if (empty($querystring['url'])) return false;
	return $querystring['url'];
}

/**
 * Redirects to landing page after successful login
 *
 * @param string $url URL of landing page
 * @param array (optional) $querystring query string of current URL
 * @return - (redirect to different page)
 */
function cms_login_redirect($url, $querystring = []) {
	// protocol/hostname is already part of URL?
	$host_base = str_starts_with($url, '/') ? wrap_setting('host_base') : '';
	
	// test whether COOKIEs for session management are allowed
	// if not, add no-cookie to URL so that wrap_auth() can hand that
	// back over to cms_login() if login was unsuccessful because of
	// lack of acceptance of cookies
	if (empty($_COOKIE) OR isset($querystring['no-cookie'])) {
		$redir_query_string = parse_url($host_base.$url);
		if (!empty($redir_query_string['query']))
			$url .= '&no-cookie';
		else
			$url .= '?no-cookie';
	}
	wrap_redirect_change($url);
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
	$logged_in = false;

	// check username and password
	$data = wrap_login_db($login);
	if ($data) $logged_in = true;

	// if database login does not work, try different sources
	// ... LDAP ...
	// ... different database server ...
	if (wrap_setting('ldap_login') AND !$logged_in) {
		$data = cms_login_ldap($login);
		if ($data) $logged_in = true;
	}
	if (wrap_setting('formauth_login') AND !$logged_in) {
		$data = cms_login_formauth($login);
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
	$sql = sprintf(wrap_sql_login(), wrap_db_escape(strtolower($login['username'])));
	$data = wrap_db_fetch($sql);
	if (!$data) return [];

	$hash = array_shift($data);
	if (!empty($login['different_sign_on'])) {
		return $data;
	} elseif (wrap_password_check($login['password'], $hash, $data['login_id'])) {
		return $data;
	}
	return [];
}

/**
 * return URL bound to specific domain
 *
 * @param string $setting, e. g. login_entry_url, change_password_url
 * @return string
 */
function wrap_domain_path($setting) {
	// @todo rename login_entryurl to login_entry_url
	$setting .= ($setting === 'login_entry' ? 'url' : '_url');
	$domain_url = wrap_setting($setting);
	if (!is_array($domain_url))
		return $domain_url;

	if (empty($_SESSION['domain'])) return '';
	if (!array_key_exists($_SESSION['domain'], $domain_url)) return '';
	return $domain_url[$_SESSION['domain']];
}

/**
 * Check if a Login via IP address is allowed
 *
 * @param void
 * @return bool true: login was successful
 */
function wrap_login_ip() {
	$sql = wrap_sql_query('auth_login_ip');
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
	if (empty($_SERVER['HTTP_X_REQUEST_WWW_AUTHENTICATION'])) return false;

	// Fast-CGI workaround
	// needs this line in Apache server configuration:
	// RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
	// or
	// SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
	// HTTP_AUTHORIZATION
	if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
		if (str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Bearer '))
			return wrap_login_http_oauth(substr($_SERVER['HTTP_AUTHORIZATION'], strlen('Bearer ')));
		list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = 
			explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
	}

	// send WWW-Authenticate header to get username if not yet there
	if (empty($_SERVER['PHP_AUTH_USER']))
		wrap_login_http_auth_request();
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
 * check OAuth access token if it is valid
 * if it is valid, login, otherwise send WWW-Authenticate
 *
 * @param string $access_token
 * @return bool
 */
function wrap_login_http_oauth($access_token) {
	if (wrap_setting('login_with_contact_id'))
		$sql = wrap_sql_query('auth_access_token_contact');
	else
		$sql = wrap_sql_query('auth_access_token');
	$sql = sprintf($sql, wrap_db_escape($access_token));
	$login['username'] = wrap_db_fetch($sql, '', 'single value');
	if (!$login['username']) return wrap_login_http_auth_request();
	$login['different_sign_on'] = true;
	return wrap_login($login);
}

/**
 * send WWW-Authenticate header
 *
 * @return void
 */
function wrap_login_http_auth_request() {
	header(sprintf('WWW-Authenticate: Basic realm="%s"', wrap_setting('project')));
	wrap_http_status_header(401);
	wrap_log_uri(401);
	exit;
}

/**
 * Login via hash
 *
 * @param string $hash (id-hash)
 * @param array $login
 * @return bool true: login was successful
 */
function wrap_login_hash($hash, $login) {
	if (str_starts_with($hash, 'sso_')) {
		$login_key = 'sso_key';
		$hash = substr($hash, 4);
		$login['single_sign_on'] = true;
	} else {
		$login_key = 'password_key';
	}
	$user_hash = explode('-', $hash);
	$hash = array_pop($user_hash);
	$username = implode('-', $user_hash);

	$password = wrap_password_token($username, $login_key);
	if ($password !== $hash) return $login;
	$login['different_sign_on'] = true;
	$login['username'] = $username;
	$login['change_password'] = true;
	$login['dont_require_old_password'] = true;
	return $login;
}

/**
 * Writes SESSION-variables specific to different user ID
 *
 * @param int $user_id
 * @param array (optional) $data result of wrap_sql_query('auth_login') or custom LDAP function
 */
function wrap_register($user_id = false, $data = []) {
	if (!$data) {
		// keep login ID
		$login_id = $_SESSION['login_id'];
		$_SESSION = [];
		$_SESSION['logged_in'] = true;
		$_SESSION['login_id'] = $login_id;
		$_SESSION['user_id'] = $user_id;
		// masquerade login
		if ($sql = wrap_sql_query('auth_login_masquerade')) {
			$sql = sprintf($sql, $user_id);
			$data = wrap_db_fetch($sql);
			$_SESSION['masquerade'] = true;
		}
		// data from cms_login_ldap() has to be dealt with in masquerade script
	}
	
	foreach ($data as $key => $value)
		$_SESSION[$key] = $value; 
	if (empty($_SESSION['domain']))
		$_SESSION['domain'] = wrap_setting('hostname');

	// Login: no user_id set so far, get it from SESSION
	if (!$user_id) $user_id = $_SESSION['user_id'];

	if ($sql = wrap_sql_query('auth_login_settings') AND !empty($user_id)) {
		$sql = sprintf($sql, $user_id);
		$_SESSION['settings'] = wrap_db_fetch($sql, 'dummy_id', 'key/value');
	}
	// get user groups, if module present
	$usergroups_file = wrap_setting('custom_rights_dir').'/usergroups.inc.php';
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
 * @return string $field_value, reformatted
 */
function wrap_login_format($field_value, $field_name) {
	$field_value = wrap_db_escape($field_value);
	
	if ($function = wrap_setting('login_fields_format'))
		$field_value = $function($field_value, $field_name);

	return $field_value;
}

/**
 * check given password against database password hash
 *
 * @param string $pass password as entered by user
 * @param string $hash hash as stored in database
 * @param int $login_id
 * @return bool true: given credentials are correct, false: no access!
 */
function wrap_password_check($pass, $hash, $login_id = 0) {
	// password must not be longer than 72 characters
	if (strlen($pass) > 72) return false;

	switch (wrap_setting('hash_password')) {
	case 'password_hash':
		if (password_verify($pass, $hash)) return true;
		return false;
	case 'phpass':
	case 'phpass-md5':
		require_once wrap_setting('lib').'/phpass/PasswordHash.php';
		$hasher = new PasswordHash(wrap_setting('hash_cost_log2'), wrap_setting('hash_portable'));
		if ($hasher->CheckPassword($pass, $hash)) return true;
		if (wrap_setting('hash_password') === 'phpass') return false;
		if (!$login_id) return false;
		// to transfer old double md5 hashed logins without salt to more secure logins
		if (!$hasher->CheckPassword(md5($pass), $hash)) return false;
		// Update existing password
		$values['action'] = 'update';
		$values['POST']['login_id'] = $login_id;
		$values['POST']['secure_password'] = 'yes';
		$values['POST'][wrap_sql_fields('auth_password')] = $pass;
		$ops = zzform_multi('logins', $values);
		return true;
	default:
		if ($hash === wrap_password_hash($pass)) return true;
		return false;
	}
}

/**
 * hash password
 *
 * @param string $pass password as entered by user
 * @return string hash
 */
function wrap_password_hash($pass) {
	// password must not be longer than 72 characters
	if (strlen($pass) > 72) return false;

	switch (wrap_setting('hash_password')) {
	case 'password_hash':
		$hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => wrap_setting('hash_cost_log2')]);
		if (strlen($hash) < 20) return false;
		return $hash;
	case 'phpass':
	case 'phpass-md5':
		require_once wrap_setting('lib').'/phpass/PasswordHash.php';
		$hasher = new PasswordHash(wrap_setting('hash_cost_log2'), wrap_setting('hash_portable'));
		$hash = $hasher->HashPassword($pass);
		if (strlen($hash) < 20) return false;
		return $hash;
	}
	return wrap_setting('hash_password')($pass.wrap_setting('hash_password_salt'));
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
	static $tokens;
	if (empty($tokens)) $tokens = [];
	
	if (!$username) {
		$username = wrap_username();
		if (!$username) wrap_error('No username found for password token');
	}
	if ($secret_key === 'sso_key') {
		// don't check against database, user might not exist yet
		// it will be created and a check is performed later on
		$string = sprintf('%s Single Sign On via zzproject', $username);
	} elseif (array_key_exists($username, $tokens)) {
		$string = $tokens[$username];
	} else {
		// get password, even if it is empty
		$sql = sprintf(wrap_sql_login(), $username);
		$userdata = wrap_db_fetch($sql);
		if (!$userdata AND $sql = wrap_sql_query('auth_login_foreign')) {
			if ($login_foreign_ids = wrap_setting('login_foreign_ids')) {
				foreach ($login_foreign_ids as $id) {
					$sql = sprintf($sql, $id, $username);
					$userdata = wrap_db_fetch($sql);
					if ($userdata) break;
				}
			} else {
				$sql = sprintf($sql, $username);
				$userdata = wrap_db_fetch($sql);
			}
		}
		if (!$userdata AND $sql = wrap_sql_query('auth_login_user_id')) {
			$sql = sprintf($sql, $username);
			$userdata = wrap_db_fetch($sql);
		}
		if (!$userdata) return false;
		$password_in_db = array_shift($userdata);
		$string = sprintf('%s %d %s', $userdata['username'], $userdata['user_id'], $password_in_db);
		$tokens[$username] = $string;
	}
	$password = wrap_set_hash($string, $secret_key);
	return $password;
}

/**
 * send a password reminder
 *
 * @param string $address E-Mail
 * @param array $additional_data (optional)
 * @return bool
 */
function wrap_password_reminder($address, $additional_data = []) {
	$sql = wrap_sql_query('auth_password_reminder');
	// add address twice, if it's only once in the query, last parameter gets ignored
	$sql = sprintf($sql, wrap_db_escape($address), wrap_db_escape($address));
	$data = wrap_db_fetch($sql);
	if (!$data) {
		wrap_error(sprintf('A password was requested for e-mail %s, but there was no login in the database.', $address));
		return false;
	} elseif (!$data['active']) {
		wrap_error(sprintf('A password was requested for e-mail %s, but the login is disabled.', $address));
		return false;
	}
	$data = array_merge($additional_data, $data);
	$data['token'] = $data['username'].'-'.wrap_password_token($data['username'], 'password_key');
	if (empty($data['subject'])) $data['subject'] = 'Forgotten Password';

	$mail = [];
	$mail['to']['name'] = $data['recipient'];
	$mail['to']['e_mail'] = $data['e_mail'];
	$mail['subject'] = wrap_text($data['subject']);
	$mail['message'] = wrap_template('password-reminder-mail', $data);
	return wrap_mail($mail);
}

/**
 * Login and return SESSION variables as JSON for further use
 *
 * @return array
 */
function wrap_auth_show_session() {
	$page['text'] = json_encode($_SESSION);
	$page['content_type'] = 'json';
	$page['query_strings'] = ['via', 'auth'];
	$page['headers']['filename'] = 'session-'.$_SESSION['user_id'].'.json';
	return $page;
}

/**
 * create a single sign on link for a different server
 * and redirect to that server and login
 *
 * @param string $login_url full URL on server where to log on
 * @param string $dest_url local URL on server where to redirect to
 * @return string Link
 */
function wrap_sso_login($login_url, $dest_url = '/') {
	$token = wrap_password_token($_SESSION['username'], 'sso_key');
	$url = sprintf('%s?username=%s&token=%s&url=%s', 
		$login_url, $_SESSION['username'], $token, urlencode($dest_url)
	);
	return wrap_redirect($url, 307, false);
}
