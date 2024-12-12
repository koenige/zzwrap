<?php 

/**
 * zzwrap
 * Login to restricted area
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2012, 2014-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


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
function mod_zzwrap_login($params, $settings = []) {
	global $zz_page;

	wrap_setting_add('extra_http_headers', 'X-Frame-Options: Deny');
	wrap_setting_add('extra_http_headers', "Content-Security-Policy: frame-ancestors 'self'");

	if (!empty($_SESSION['logged_in'])) {
		$url = mod_zzwrap_login_redirect_url();
		if ($url) return wrap_auth_login_redirect($url);
	}

	// Set try_login to true if login credentials shall be checked
	// if set to false, first show login form
	$try_login = false;

	$login['username'] = '';
	$login['password'] = '';
	$login['different_sign_on'] = false;

	$loginform = [];
	$loginform['msg'] = false;
	$loginform['action_url'] = $settings['action_url'] ?? './';
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
		wrap_include('session', 'zzform');
		$loginform['hidden_fields'] = zz_session_via_login();
	} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' AND !empty($_POST['request_password'])) {
		$loginform['name'] = $_POST['name'] ?? '';
		if (!empty($_POST['mail'])) {
			if (is_array($_POST['mail'])) wrap_quit(400, 'Invalid data sent for mail address.');
			$loginform['mail'] = trim($_POST['mail']);
			if (wrap_mail_valid($loginform['mail'])) {
				$loginform['mail_sent'] = true;
				$loginform['login_link_valid'] = wrap_setting('password_key_validity_in_minutes');
				wrap_password_reminder($loginform['mail'], $settings['reminder_data'] ?? []);
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
			if (empty($_POST['username']) OR is_array($_POST['username'])
				OR empty($_POST['password']) OR is_array($_POST['password']))
				$loginform['msg'] = wrap_text('Password or username are empty. Please try again.');
			$full_login = [];
			foreach (wrap_setting('login_fields') AS $login_field) {
				$login_field = strtolower($login_field);
				if (!empty($_POST[$login_field])) {
					$login[$login_field] = wrap_login_format($_POST[$login_field], $login_field);
					$full_login[] = $login[$login_field];
				} else {
					$full_login[] = wrap_setting('remote_ip');
				}
			}
			if (!empty($_POST['password']) AND !is_array($_POST['password'])) {
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
	$url = mod_zzwrap_login_redirect_url();

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
				wrap_setting('log_username', sprintf('%s (IP: %s)', $user, wrap_setting('remote_ip')));
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
			return wrap_auth_login_redirect($url);
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
			'output' => $login_fields_output[$login_field] ?? '',
			// text input
			'value' => !empty($_POST[strtolower($login_field)])
				? wrap_html_escape($_POST[strtolower($login_field)]) : ''
		];
	}
	$page['query_strings'] = [
		'password', 'auth', 'via', 'request', 'add', 'link', 'username', 'token', 'url'
	];
	if (isset($_GET['password'])) {
		if (!wrap_setting('password_link'))
			wrap_quit(404, wrap_text('The “Forgot password” link is not activated.'));
		if (!wrap_sql_query('auth_password_reminder'))
			wrap_quit(404, wrap_text('The “Forgot password” query is missing.'));
		$page['text'] = wrap_template('login-password', $loginform);
		$page['breadcrumbs'][] = ['title' => wrap_text('Login'), 'url_path' => './'];
		$page['breadcrumbs'][]['title'] = wrap_text('Request password');
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
function mod_zzwrap_login_redirect_url() {
	global $zz_page;
	if (empty($zz_page['url']['full']['query'])) return false;
	parse_str($zz_page['url']['full']['query'], $querystring);
	if (empty($querystring['url'])) return false;
	return $querystring['url'];
}
