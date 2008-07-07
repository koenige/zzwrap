<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007
// CMS Authentifizierungsfunktionen und -aktivierung


// Activate authentification mechanism
// (c) Gustaf Mossakowski, gustaf@koenige.org, 2007
if (!empty($zz_setting['auth_urls'])) {
	$url = parse_url($_SERVER['REQUEST_URI']);
	foreach($zz_setting['auth_urls'] as $auth_url) {
		if (substr($url['path'], 0, strlen($auth_url)) == $auth_url
			&& $url['path'] != $zz_setting['login_url']) {
			require_once $zz_setting['scripts'].'/inc/auth.inc.php';
		}
	}
}

// Local modifications to SQL queries
require_once $zz_setting['modules'].'/local-sql-auth.inc.php';

// Logout from restricted area
// (c) Gustaf Mossakowski, gustaf@koenige.org, 2007
function cms_logout($variablen) {
	global $zz_setting;
	global $zz_conf;
	global $zz_sql;
	
	if (empty($_SESSION)) session_start();				// start session
	if (!empty($_SESSION['login_id']))					// update login db if logged in, set to logged out
		$sql = sprintf($zz_sql['logout'], $_SESSION['login_id']);
	session_destroy();
	if (!empty($sql)) $result = mysql_query($sql);

	header('Location: '.$zz_setting['protocol'].'://'.$zz_setting['hostname']
		.$zz_setting['login_url'].'?logout=true');
	exit;
}

// Login from restricted area
// (c) Gustaf Mossakowski, gustaf@koenige.org, 2007
function cms_login($variablen) {
	global $zz_setting;
	global $zz_conf;
	global $zz_sql;

	$myurl = parse_url($_SERVER['REQUEST_URI']);
	if (!empty($myurl['query']) && substr($myurl['query'], 0, 4) == 'url=')
		$url = substr(rawurldecode($myurl['query']), 4);
	else
		$url = $zz_setting['login_entryurl'];
	$msg = false;

	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		if (empty($_SESSION)) session_start();
		if (!empty($_POST['username']))
			$login_username = $_POST['username']; // todo: addslashes, get_magix_quotes
		if (!empty($_POST['password']))
			$password = $_POST['password'];

		$zz_setting['hostname'] = $_SERVER['HTTP_HOST'];
		if (empty($zz_setting['no_https']))
			$zz_setting['local_access'] 
				= (substr($zz_setting['hostname'], -6) == '.local' ? true : false);
		else
			$zz_setting['local_access'] = true;
		$zz_setting['protocol'] = 'http'.($zz_setting['local_access'] ? '' : 's');
		
		$zz_setting['myhost'] = $zz_setting['protocol'].'://'.$zz_setting['hostname'];

	// Benutzername und Passwort werden ueberprueft
		$sql = sprintf($zz_sql['login'], mysql_real_escape_string($login_username));
		$result = mysql_query($sql);
		$zz_conf['debug'] = true;
		if ($zz_conf['debug']) if (mysql_error()) {
			echo mysql_error();
			echo '<br>'.$sql;
		}
		$_SESSION['logged_in'] = false;
		if ($result) {
			if (mysql_num_rows($result) == 1) {
				$data = mysql_fetch_assoc($result);
				if (mysql_result($result, 0, 0) == md5($password)) {
					$_SESSION['logged_in'] = true;
				}
			}
		} else {
			require $zz_setting['http_errors'].'/503.php';
			exit;
		}
		
		if (!$_SESSION['logged_in']) { // Benutzername oder Passwort falsch
			$msg = 'Passwort oder Benutzername falsch. Bitte versuchen Sie es erneut.';
		} else {
			$_SESSION['letzter_klick_um'] = time();
			$i = 0;
			foreach ($data as $key => $value) {
				if ($i) $_SESSION[$key] = $value; 
				$i++;
			}
			// Weiterleitung zur geschuetzten Startseite
			if ($_SERVER['SERVER_PROTOCOL'] == 'HTTP/1.1')
				if (php_sapi_name() == 'cgi')
					header('Status: 303 See Other');
				else
					header('HTTP/1.1 303 See Other');
			header('Location: '.$zz_setting['myhost'].$url);
			exit;
		}
	}
	
	$page['text'] = '<div id="login">';
	if (isset($myurl['query']) && $myurl['query'] == 'logout') {
		if (empty($_SESSION)) session_start();
		if (!empty($_SESSION['login_id']))
			$sql = sprintf($zz_sql['logout'], $_SESSION['login_id']);
		session_destroy();
		if (!empty($sql)) $result = mysql_query($sql);
		$page['text'] .= '<p><strong class="error">Sie haben sich abgemeldet.</strong></p>';
	}

	$page['text'] .='
<p>F&uuml;r den Zugang zum internen Bereich ist eine Anmeldung erforderlich. Bitte geben Sie unten den von uns erhaltenen Benutzernamen mit Pa&szlig;wort ein.</p>
<p>Damit der Login funktioniert, m&uuml;ssen Sie nach &Uuml;bermittlung der Anmeldedaten einen Cookie akzeptieren. Nach zehn Minuten Inaktivit&auml;t werden Sie aus Sicherheitsgr&uuml;nden automatisch wieder abgemeldet!</p>

<form action="./';
	if (isset($url)) $page['text'].= '?url='.urlencode($url); 
	$page['text'].= '" method="POST" class="login">
<fieldset><legend>Login</legend>
<p><label for="username"><strong>Benutzername:</strong></label> <input type="text" name="username" id="username"></p>
<p><label for="password"><strong>Passwort:</strong></label> <input type="password" name="password" id="password"></p>
<p class="submit"><input type="submit" value="Anmelden"></p>
</fieldset>
';
	if ($msg) $page['text'].= '<p class="error">'.$msg.'</p>';

	$page['text'].= '</form></div>';
	return $page;
}

?>