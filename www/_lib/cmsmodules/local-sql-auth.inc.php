<?php 

// Zugzwang CMS
// SQL Queries

global $zz_conf;

$zz_sql['logout'] = 'UPDATE '.$zz_conf['prefix'].'logins 
	SET eingeloggt = "nein"
	WHERE login_id = %s';	// $_SESSION['login_id']

// Login: Passwort muss erstes Feld sein!
$zz_sql['login'] = 'SELECT passwort 
	, kennung AS username
	, logins.person_id AS user_id
	, logins.login_id
	, vorname, nachname, 
	CONCAT(vorname, " ", IFNULL(CONCAT(namenszusatz, " "), ""), nachname) AS person 
	FROM '.$zz_conf['prefix'].'logins logins
	LEFT JOIN '.$zz_conf['prefix'].'personen personen 
		ON (logins.person_id = personen.person_id)
	WHERE aktiv = "ja"
	AND kennung = "%s"';	// $login_username

$zz_sql['last_click'] = 'UPDATE '.$zz_conf['prefix'].'logins 
	SET eingeloggt = "ja", letzter_klick = %s 
	WHERE login_id = %s';

$zz_sql['login_extra']['settings'] = '';

?>