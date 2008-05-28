<?php 

// Zugzwang CMS
// SQL Queries

global $zz_conf;

$zz_sql['logout'] = 'UPDATE '.$zz_conf['prefix'].'logins 
	SET eingeloggt = "nein"
	WHERE login_id = %s';	// $_SESSION['login_id']

// Login: Passwort muss erstes Feld sein!
$zz_sql['login'] = 'SELECT passwort 
	, benutzername AS username
	, logins.login_id AS user_id
	, logins.login_id AS login_id
	, letzter_klick AS letzter_login
	FROM '.$zz_conf['prefix'].'logins logins
	WHERE aktiv = "ja"
	AND benutzername = "%s"';	// $login_username

?>