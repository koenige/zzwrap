<?php

// Zugzwang Project
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2008
// Allgemeine Abfragen und Funktionen


function cms_spalte($variablen) {
	switch ($variablen[0]) {
	case 'links':
		$page['text'] = '<div class="spalte-links">';
		break;
	case 'rechts':
		$page['text'] = '</div><div class="spalte-rechts">';
		break;
	case 'ende':
		$page['text'] = '</div>';
		break;
	}
	return $page;
}

function cms_textbausteine($bereich) {
	global $zz_conf;
	$bausteine = false;
	$sql = 'SELECT schluessel
		, IFNULL(baustein'.$zz_conf['lang_suffix'].', baustein_de) baustein
		FROM '.$zz_conf['prefix'].'textbausteine
		WHERE bereich = "'.mysql_real_escape_string($bereich).'"
			OR bereich = "Allgemein"';
	$result = mysql_query($sql);
	if ($result) if (mysql_num_rows($result))
		while ($line = mysql_fetch_assoc($result))
			$bausteine[$line['schluessel']] = $line['baustein'];
	return $bausteine;
}

function cms_magic_quotes_strip($mixed) {
   if(is_array($mixed)) {
      	return array_map('cms_magic_quotes_strip', $mixed);
	}
   return stripslashes($mixed);
}

if (get_magic_quotes_gpc() AND substr($_SERVER['REQUEST_URI'], 0, 3) != '/db') { // sometimes unwanted standard config
	$_POST = cms_magic_quotes_strip($_POST);
	$_GET = cms_magic_quotes_strip($_GET);
	$_FILES = cms_magic_quotes_strip($_FILES);
	// _COOKIE and _REQUEST are not being used
}

?>