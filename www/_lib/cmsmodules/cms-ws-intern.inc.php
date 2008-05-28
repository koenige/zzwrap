<?php

// Zugzwang Project
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2008
// Abfragen zu Projekten


function cms_benutzername($variablen) {
	global $zz_conf;
	if (empty($_SESSION)) return false;
	$benutzername = '-unbekannt-';
	$sql = 'SELECT vor_nachname 
		FROM '.$zz_conf['prefix'].'logins
		WHERE login_id = '.$_SESSION['login_id'];
	$result = mysql_query($sql);
	if ($result) if (mysql_num_rows($result) == 1)
		$benutzername = mysql_result($result, 0, 0);
	
	$page['text'] = $benutzername;
	return $page;
}

function cms_letzter_login($variablen) {
	global $zz_conf;

	if (!empty($_SESSION['letzter_login']))	
		$page['text'] = 'Sie waren zuletzt am '.date('d.m.Y', $_SESSION['letzter_login'])
			.' um '.date('H:i', $_SESSION['letzter_login']).' Uhr auf der Plattform aktiv.';
	else
		$page['text'] = 'Sie haben sich soeben zum ersten Mal eingeloggt.';
	
	return $page;
}

?>
