<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2009
// CMS Authentification functions and activation


// Activate authentification mechanism
// (c) Gustaf Mossakowski, gustaf@koenige.org, 2007
if (!empty($zz_setting['auth_urls'])) {
	foreach($zz_setting['auth_urls'] as $auth_url) {
		if (substr($zz_page['url']['full']['path'], 0, strlen($auth_url)) == $auth_url
			&& $zz_page['url']['full']['path'] != $zz_setting['login_url']) {
			require_once $zz_setting['core'].'/auth.inc.php';
		}
	}
}

// Local modifications to SQL queries
require_once $zz_setting['inc_local'].'/cms-sql-auth.inc.php';

// Logout from restricted area
// (c) Gustaf Mossakowski, gustaf@koenige.org, 2007
function cms_logout($variablen) {
	global $zz_setting;
	global $zz_conf;
	global $zz_sql;
	
	if (empty($_SESSION)) session_start();				// start session
	if (!empty($_SESSION['login_id']))					// update login db if logged in, set to logged out
		$sql = sprintf($zz_sql['logout'], $_SESSION['login_id']);
	// Unset all of the session variables.
	$_SESSION = '';
	// If it's desired to kill the session, also delete the session cookie.
	// Note: This will destroy the session, and not just the session data!
	if (isset($_COOKIE[session_name()])) {
    	setcookie(session_name(), '', time()-42000, '/');
	}
	session_destroy();
	if (!empty($sql)) $result = mysql_query($sql);

	header('Location: '.$zz_setting['protocol'].'://'.$zz_setting['hostname']
		.$zz_setting['login_url'].'?logout');
	exit;
}

// Login to restricted area
// (c) Gustaf Mossakowski, gustaf@koenige.org, 2007
function cms_login($variablen) {
	global $zz_setting;
	global $zz_conf;
	global $zz_sql;
	global $zz_page;

	// default settings
	if (empty($zz_setting['login_fields'])) {
		$zz_setting['login_fields'][] = 'Username';
	}

	// get URL where redirect is done to after logging in
	$url = false;
	if (!empty($zz_page['url']['full']['query'])) {
		parse_str($zz_page['url']['full']['query'], $querystring);
		if (!empty($querystring['url']))
			$url = $querystring['url'];
	}
	// if there is no url= in query string, use default value
	if (!$url) $url = $zz_setting['login_entryurl'];
	$msg = false;
	// someone tried to login via POST
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		// Session will be saved in Cookie so check whether we got a cookie or not

		if (empty($_SESSION)) session_start();
		
		// get password and username
		$login['username'] = '';
		$login['password'] = '';
		if (empty($_POST['username']) OR empty($_POST['password']))
			$msg = cms_text('Password or username are empty. Please try again.');
		$full_login = array();
		foreach ($zz_setting['login_fields'] AS $login_field) {
			$login_field = strtolower($login_field);
			if (!empty($_POST[$login_field])) {
				$login[$login_field] = $_POST[$login_field]; // todo: addslashes, get_magix_quotes
				$full_login[] = $login[$login_field];
			} else {
				$full_login[] = '%empty%';
			}
		}
		if (!empty($_POST['password']))
			$login['password'] = $_POST['password'];

		$zz_setting['protocol'] = 'http'.($zz_setting['no_https'] ? '' : 's');
		$zz_setting['myhost'] = $zz_setting['protocol'].'://'.$zz_setting['hostname'];

	// Benutzername und Passwort werden ueberprueft
		$sql = sprintf($zz_sql['login'], mysql_real_escape_string($login['username']));
		$_SESSION['logged_in'] = false;
		$data = cms_db_fetch($sql, false);
		if ($data) {
			if (array_shift($data) == md5($login['password'])) {
				$_SESSION['logged_in'] = true;
			}
		}
		// if MySQL-Login does not work, try different sources
		// ... LDAP ...
		// ... different MySQL-server ...
		if (!empty($zz_setting['ldap_login']) AND !$_SESSION['logged_in']) {
			include $zz_setting['inc_local'].'/ldap-login.inc.php';
			$data = cms_login_ldap($login);
			if ($data) $_SESSION['logged_in'] = true;
		}
		if (!$_SESSION['logged_in']) { // Benutzername oder Passwort falsch
			if (!$msg) $msg = cms_text('Password or username incorrect. Please try again.');
			zz_errorhandling(sprintf(cms_text('Password or username incorrect:')."\n\n%s\n%s", implode('.', $full_login), md5($login['password'])), E_USER_WARNING);
		} else {
			$_SESSION['last_click_at'] = time();
			foreach ($data as $key => $value) {
				$_SESSION[$key] = $value; 
			}
			if (!empty($zz_sql['login_settings']) AND !empty($_SESSION['user_id'])) {
				$sql = sprintf($zz_sql['login_settings'], $_SESSION['user_id']);
				$result = mysql_query($sql);
				if ($result && mysql_num_rows($result))
					while ($line = mysql_fetch_array($result))
						$_SESSION['settings'][$line[0]] = $line[1];
			}
			// get user groups, if module present
			if (file_exists($zz_setting['inc_local'].'/usergroups.inc.php')) {
				include $zz_setting['inc_local'].'/usergroups.inc.php';
				cms_register_usergroups();
			}
			
			// Redirect to protected landing page
			if ($_SERVER['SERVER_PROTOCOL'] == 'HTTP/1.1')
				if (php_sapi_name() == 'cgi')
					header('Status: 303 See Other');
				else
					header('HTTP/1.1 303 See Other');
			// test whether COOKIEs for session management are allowed
			// if not, add no-cookie to URL so that auth.inc can hand that
			// back over to login.inc if login was unsuccessful because of
			// lack of acceptance of cookies
			if (empty($_COOKIE) OR isset($querystring['no-cookie'])) {
				$redir_query_string = parse_url($zz_setting['myhost'].$url);
				if (!empty($redir_query_string['query']))
					$url .= '&no-cookie';
				else
					$url .= '?no-cookie';
			}
			header('Location: '.$zz_setting['myhost'].$url);
			exit;
		}
	}

	$page['text'] = '<div id="login">';
	if (isset($zz_page['url']['full']['query']) && $zz_page['url']['full']['query'] == 'logout') {
		if (empty($_SESSION)) session_start();
		if (!empty($_SESSION['login_id']))
			$sql = sprintf($zz_sql['logout'], $_SESSION['login_id']);
		// Unset all of the session variables.
		$_SESSION = '';
		// If it's desired to kill the session, also delete the session cookie.
		// Note: This will destroy the session, and not just the session data!
		if (isset($_COOKIE[session_name()])) {
	    	setcookie(session_name(), '', time()-42000, '/');
		}
		session_destroy();
		if (!empty($sql)) $result = mysql_query($sql);
		$page['text'] .= '<p><strong class="error">'.cms_text('You have been logged out.').'</strong></p>';
	}
	if (isset($_GET['no-cookie'])) $page['text'] .= '<p><strong class="error">'.cms_text('Please allow us to set a cookie!').'</strong></p>'; 
	$page['text'] .='
<p>'.cms_text('To access the internal area, a registration is required. Please enter below your username and password.').'</p>
<p>'.sprintf(cms_text('Please allow cookies after sending your login credentials. For security reasons, after %d minutes of inactivity you will be logged out automatically.'), $zz_setting['logout_inactive_after']).'</p>

<form action="./';
	$params = array();
	if (isset($url)) $params[] = 'url='.urlencode($url); 
	if (isset($querystring['no-cookie'])) $params[] = 'no-cookie';
	if ($params) $page['text'] .= '?'.implode('&amp;', $params);
	$page['text'].= '" method="POST" class="login">
<fieldset><legend>'.cms_text('Login').'</legend>'."\n";
	foreach ($zz_setting['login_fields'] AS $login_field) {
		$fieldname = strtolower($login_field);
		$page['text'] .= '<p><label for="'.$fieldname.'"><strong>'.cms_text($login_field.':').'</strong></label>';
		if (!empty($zz_setting['login_fields_output'][$login_field]))
			// separate input, e. g. dropdown etc.
			$page['text'] .= $zz_setting['login_fields_output'][$login_field];
		else
			// text input
			$page['text'] .= '<input type="text" name="'.$fieldname.'" id="'.$fieldname.'">';
		$page['text'] .= '</p>'."\n";
	}


	$page['text'] .= '<p><label for="password"><strong>'.cms_text('Password:').'</strong></label> <input type="password" name="password" id="password"></p>
<p class="submit"><input type="submit" value="'.cms_text('Sign in').'"></p>
</fieldset>
';
	if ($msg) $page['text'].= '<p class="error">'.$msg.'</p>';

	$page['text'].= '</form></div>';
	$page['meta']['robots'] = 'noindex,follow';
	return $page;
}

?>