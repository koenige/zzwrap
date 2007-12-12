<?php 

/*
	Zugzwang Project
	Hauptskript zur Auswahl der Seiteninhalte

	(c) 2006 Gustaf Mossakowski, <gustaf@koenige.org>
*/

//// Keine Seite gewählt, z. B. bei Direktanwahl von main.php

if (empty($_GET['url']) OR substr($_SERVER['REQUEST_URI'], 0, 9) == '/_scripts') {
	header("HTTP/1.0 404 Not Found");
	include '404.php';
	exit;
}

//// Variablen initalisieren

$last_mod = false;
$page = false;
$projekt = false;
$seite = $_SERVER['REQUEST_URI'];
if (substr($seite, strlen($seite)-1) == '/') $seite = substr($seite, 0, strlen($seite)-1);

//// Benötigte Include-Dateien

require_once $_SERVER['DOCUMENT_ROOT'].'/www/_scripts/zzform/local/config.inc.php';
require_once $zz_setting['db_inc'];

global $zz_setting;
global $zz_conf;
global $zz_page;

//// Zeichenrepertoire einstellen

header('Content-Type: text/html; charset=utf-8');

//// Seite bestimmen

$url = $_GET['url']; // Sicherheitsprüfungen!
if (substr($url, 0, 6) == '/login') {
	$myurl = parse_url($url); // ggf. Query-Strings abschneiden
	$url = $myurl['path'];
}
if (strlen($url) > 1 && substr($url, strlen($url) - 1) == "/")
	$url = substr($url, 0, strlen($url)-1);
if ($url == '/sitemap') {
	$sql = 'SELECT * FROM example_seiten ORDER BY kennung';
	$result = mysql_query($sql);
	if ($result) if (mysql_num_rows($result))
		while($line = mysql_fetch_assoc($result)) {
			$pages[] = $line;
			$last_mod = ($line['letzte_aenderung'] > $last_mod) ? $line['letzte_aenderung'] : $last_mod;
		}
	$page['title'] = 'Sitemap';
} elseif (substr($url, 0, 7) == '/kunden') {
	$sql = 'SELECT * FROM example_seiten WHERE kennung = "/kunden*"';
	$result = mysql_query($sql);
	if ($result) if (mysql_num_rows($result)) {
		$page = mysql_fetch_assoc($result);
	}
} else {
	$sql = 'SELECT * FROM example_seiten WHERE kennung = "'.$url.'"';
	$result = mysql_query($sql);
	if ($result) if (mysql_num_rows($result)) {
		$page = mysql_fetch_assoc($result);
		$last_mod = ($page['letzte_aenderung'] > $last_mod) ? $page['letzte_aenderung'] : $last_mod;
	}
	// Testen, ob evtl. Projektseite
	if (!$page) {
		$projekt = addslashes(substr($url, strrpos($url, '/') +1)); 
		$seite = addslashes(substr($url, 0, strrpos($url, '/')));
		$sql = 'SELECT example_projekte.*, example_seiten.titel
			FROM example_projekte 
			LEFT JOIN example_seiten USING (seite_id)
			WHERE example_projekte.kennung = "'.$projekt.'"
			AND example_seiten.kennung = "'.$seite.'"';
		$result = mysql_query($sql);
		if ($result) if (mysql_num_rows($result)) {
			$page = mysql_fetch_assoc($result);
			$last_mod = ($page['letzte_aenderung'] > $last_mod) ? $page['letzte_aenderung'] : $last_mod;
		}
	}
}

/// falls Seite vorhanden, ggf. trailing slash / anfügen

$request_uri = parse_url($_SERVER['REQUEST_URI']);

if (!empty($page) AND substr($request_uri['path'], strlen($request_uri['path'])-1) != '/'
	AND substr($request_uri['path'], 0, 8) != '/kunden/') {
	header("Location: http://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'].'/');
	exit;
} 

/// falls Seite nicht vorhanden: 404

if (empty($page)) {
	$page['lang'] = 'de';
	$page['title'] = 'Nicht gefunden';
	header("HTTP/1.0 404 Not Found");
	include '404.php';
	exit;
}

/// Für Menü: Unterseiten $subpages suchen, falls vorhanden

$oberseite_url = substr($_SERVER['REQUEST_URI'], 0, strpos(substr($_SERVER['REQUEST_URI'], 1), '/')+1);
$sql = 'SELECT * FROM example_seiten 
	WHERE kennung LIKE "'.$oberseite_url.'%"
	AND kennung != "'.$oberseite_url.'"
	ORDER BY reihenfolge, kennung';
$result = mysql_query($sql);
if ($result) if (mysql_num_rows($result))
	while ($line = mysql_fetch_assoc($result)) {
		$subpages[] = $line;
		$last_mod = ($line['letzte_aenderung'] > $last_mod) ? $line['letzte_aenderung'] : $last_mod;
	}

if (!empty($page['seite_id'])) { 
	$projekte = false;
	$sql = 'SELECT * FROM example_projekte 
		WHERE veroeffentlicht = "ja" AND seite_id = '.$page['seite_id'];
	$result = mysql_query($sql);
	if ($result) if (mysql_num_rows($result))
		while ($line = mysql_fetch_assoc($result)) {
			$projekte[] = $line;
			$last_mod = ($line['letzte_aenderung'] > $last_mod) ? $line['letzte_aenderung'] : $last_mod;
		}

	$files = false;
	$where = ($projekt ? 'projekt_id = '.$page['projekt_id'] : 'seite_id = '.$page['seite_id']);
	$sql = 'SELECT * FROM example_dateien WHERE '.$where.'
		ORDER BY reihenfolge';
	$result = mysql_query($sql);
	if ($result) if (mysql_num_rows($result))
		while ($line = mysql_fetch_assoc($result)) {
			$files[] = $line;
			$last_mod = ($line['letzte_aenderung'] > $last_mod) ? $line['letzte_aenderung'] : $last_mod;
		}
}

if (empty($page['title'])) $page['title'] = $page['titel'];
$page['lang'] = 'de';
if ($projekt) $page['title'].= ': '.$page['projekt'];
//if ($page['kennung'] == '/') $page['pagetitle'] = $page['title'];
//else
$page['pagetitle'] = 'example: '.$page['title']; 

include 'zzform/ext/markdown.php';
include 'zzform/inc/numbers.inc.php';

$ausgabe = '<h1>_'.$page['title'].'</h1>'."\n";
$ausgabe.= '</div>'."\n".'<div id="content">'."\n";

$bilder = '';
if (!empty($files)) {
	$ausgabe.= '<div id="images">'."\n";
	$i = 1;
	foreach ($files as $file) {
		$bilder.= '<img src="/_dateien/1000/'.rawurlencode($file['dateiname']).'" class="pos'.$i.'" alt="Grafik zum Thema '.$page['title'].'">';
		$i++;
	}
	$ausgabe .= $bilder;
	$ausgabe.= "</div>\n";	
}

if (!empty($page['beschreibung'])) { // alle Seiten ausser sitemap, Projekte
	$ausgabe.= cms_format($page['beschreibung'], $page, $bilder);
} elseif (!empty($page['projekt'])) { // Projekte
	$ausgabe.= '<div id="projektgrau">&nbsp;</div><div id="projekt">';
	$ausgabe.= '<table>'."\n";
	$ausgabe.= '<tr><th>Projekt.</th><td><strong>'.$page['projekt'].'</strong></td></tr>';
	$ausgabe.= '<tr><th>Datum.</th><td>'.datum_de($page['datum']).'</td></tr>';
	$ausgabe.= '<tr><th>Auftraggeber.</th><td>'.markdown($page['auftraggeber']).'</td></tr>';
	$ausgabe.= '<tr><th>Ort.</th><td>'.$page['ort'].'</td></tr>';
	$ausgabe.= '<tr><th>Info.</th><td>'.markdown($page['info']).'</td></tr>';
	$ausgabe.= '</table>'."\n";
}

include 'inc/html-head.inc.php';
echo $ausgabe;

if (!empty($pages)) {// sitemap
	$level = 0;
	echo '<ul>';
	foreach ($pages as $s_page) {
		if ($s_page['kennung'] == '/')
			$s_level = 0;
		else
			$s_level = substr_count($s_page['kennung'], '/');
		if ($s_level > $level) echo "\n<ol>\n";
		elseif ($s_level < $level) echo '</ol></li>'."\n";
		elseif ($level) echo '</li>'."\n";
		echo '<li><a href="'.($s_page['kennung'] == '/' ? '/' : $s_page['kennung'].'/')
			.'">'.$s_page['title'].'</a>';
		$level = $s_level;
	}
	echo str_repeat("</ol></li>\n", $level);
	echo '</ul>';
}	
// Falls später mal ein Kontaktformular gewünscht ist.
//if ($url == '/kontakt') {
//	if ($_POST)
//		include ('inc/formular-danke.inc.php');
//	else
//		include ('inc/formular.inc.php');
//}

?>
</div>
</div>

<?php

include 'inc/html-foot.inc.php';


// CMS-Funktionen

function cms_format($text, $page, $bilder) {
	$ausgabe = false;
	$text = explode('%%%', $text);
	$i = 0;
	foreach ($text as $index => $part) {
		if ($index & 1) {
			$part = trim($part);
			$variablen = explode("\r", $part); // entweder durch zeilen begrenzt
			if (count($variablen) == 1)
				$variablen = explode(" ", $part); // oder durch leerzeichen
			$funktion = 'cms_'.strtolower($variablen[0]);
			array_shift($variablen);
			if (function_exists($funktion))
				$ausgabe.= $funktion($variablen, $page['seite_id']);
		} else {
			$ausgabe .= markdown($part);
		}
	}
	if (count($text) < 2) // kein besonderer Bereich, also div #text
		if (trim($ausgabe)) {
			if (substr_count($_SERVER['REQUEST_URI'], '/') >= 3)
				$ausgabe = 
					'<div id="projektgrau">&nbsp;</div>'
					.'<div id="projekt" class="ausgeklappt">'
					."\n".$ausgabe;
			else
				$ausgabe = 
					'<div id="grau">&nbsp;</div>'
					.'<div id="text">'
					."\n".$ausgabe;
		} else $ausgabe = '<div id="leer">';
	return $ausgabe;
}


function cms_impressum($variablen, $seite_id) {
	$ausgabe = '<div id="impressum">'."\n".'<table>'."\n";
	$lines = false;
	$hoch = 0;
	$i = 0;
	$inhalt = false;
	foreach ($variablen as $variable) {
		if (trim($variable)) {
			if (empty($lines[$i]['th'])) $lines[$i]['th'] = markdown($variable);
			else {
				if (empty($lines[$i]['td'])) $lines[$i]['td'] = '';
				$lines[$i]['td'] .= markdown($variable);
			}
			$lines[$i]['hoch'] = $hoch;
			$inhalt = true;
		} else {
			if ($inhalt) {
				$i++;
				$inhalt = false;
				$hoch = 0;
			}
			$hoch++;
		}
	}
	foreach ($lines as $line) {
		$ausgabe.= '<tr'
			.($line['hoch'] ? ' class="hoch'.$line['hoch'].'"' : '')
			.'><td>'.$line['td'].'</td><th>'.$line['th'].'</th></tr>';
	}
	$ausgabe .= '</table>'."\n";
	return $ausgabe;
}

function cms_kontakt($variablen, $seite_id) {
	$ausgabe = '<div id="kontakt">';
	return $ausgabe;
}

function cms_tabelle($variablen, $seite_id) {
	$ausgabe = '<table class="daten">'."\n";
	foreach ($variablen as $variable) {
		$zeile = explode("\t", $variable);
		$ausgabe .= '<tr><th>'.$zeile[0].'</th>';
		array_shift($zeile);
		foreach ($zeile as $td) {
			$ausgabe .= '<td>'.markdown($td).'</td>';
		}
		$ausgabe .= '</tr>'."\n";
	}
	$ausgabe .= '</table>'."\n";
	return $ausgabe;
}

function cms_logo($variablen, $seite_id) {
	$ausgabe = '<img src="/_layout/example-logo.gif" alt="example"><br>';
	return $ausgabe;
}

function cms_spalte($variablen, $seite_id) {
	$ausgabe = false;
	if (!empty($variablen[0]) && strtolower($variablen[0]) == 'ende')
		$ausgabe = '</div>';
	elseif (!empty($variablen[0]) && strtolower($variablen[0]) == 'rechts')
		$ausgabe = '<div class="spalte-rechts">';
	else
		$ausgabe = '<div class="spalte">';
	return $ausgabe;
}

function cms_kunden($variablen, $seite_id) {
	global $zz_setting;
	global $zz_conf;
	global $zz_page;
	require_once $_SERVER['DOCUMENT_ROOT'].'/www/_scripts/inc/base.inc.php';
	$ausgabe = '<div id="text">'; //.$_SESSION['username'];
	$kundenverzeichnis = $_SERVER['DOCUMENT_ROOT'].'/www/_kunden';
	switch ($_SESSION['typ']) {
		case 'Admin':
			if ($_GET['url'] == '/kunden/') {
			// Wenn Admin: kunden/ = Übersichtsseite, alle Kunden
				$handle = opendir($kundenverzeichnis);
				$ausgabe .= '<h3>Kunden</h3>';
				$ausgabe .= '<ul>';
				while (($file = readdir($handle)) !== false) {
					if (substr($file, 0, 1) != '.')
						$ausgabe .= '<li><a href="'.$file.'/">'.$file.'</a></li>';
				}
				$ausgabe .= '</ul>';
			} else {
			// test, ob Datei existiert, sonst 404-Fehler
			//	kunden/kundenname: kundenseiten
				//if (file_exists())
				zeige_datei();
			}
		break;
		case 'Kunde':
	// Wenn Kunde: kunden/ = Redirect zum Kunden, kunde/kundenname: kundenseite
	// kunde/nichtkundenname = Redirect zum Kunden.
	// dann Dateien aus _kunden/kundenname durchschleifen.
			$verzeichnis = '/kunden/'.$_SESSION['username'];
			$verzeichnis_laenge = strlen($verzeichnis);
			if (substr($_SERVER['REQUEST_URI'], 0, $verzeichnis_laenge) == $verzeichnis)
				zeige_datei();
			else
				header('Location: http://'.$_SERVER['SERVER_NAME'].$verzeichnis.'/');
		default:
		break;
	}
	return $ausgabe;
}

function cms_login($variablen, $seite_id) {
	$ausgabe = '<div id="login">';
	$myurl = parse_url($_SERVER['REQUEST_URI']);
	if (!empty($myurl['query']) && substr($myurl['query'], 0, 4) == 'url=')
		$url = substr(rawurldecode($myurl['query']), 4);
	else
		$url = '/_intern/';
	$msg = false;

	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		session_start();
		$login_username = $_POST['username'];
		$password = $_POST['password'];
		$hostname = $_SERVER['HTTP_HOST'];
		if ($hostname == 'www.example.org.local') 
			$myhost = 'http://'.$hostname;	// lokaler Webserver, kein https noetig (und evtl auch nicht moeglich)
		else $myhost = 'http://'.$hostname;									// Server steht im Web, also unbedingt https
		$path = '';
		$lang = 'de';

	// Benutzername und Passwort werden überprüft
	
		$password_in_db = '';
		$vorname = '';
		$nachname = '';
		$sql = 'SELECT passwort, username, login_id, typ 
			FROM example_logins 
			WHERE aktiv = "ja"
			AND username = "'.$login_username.'"';
		$result = mysql_query($sql);
		if ($result) 
			if (mysql_num_rows($result) == 1) {
				$password_in_db = mysql_result($result, 0, 0);
				$login_username = mysql_result($result, 0, 1);
				$login_user_id = mysql_result($result, 0, 2);
				$login_typ = mysql_result($result, 0, 3);
			} else
				$msg = 'Passwort oder Benutzername falsch. Bitte versuche es erneut.';
		else {
			header('HTTP/1.1 403 Forbidden');
			echo 'Zugriff auf die Website ist nicht möglich (Datenbankstörung).';
			//mail
			exit;
		}
	
		if ($password_in_db == md5($password)) {	
			$_SESSION['logged_in'] = true;
			$_SESSION['last_click_at'] = time();
			$_SESSION['user_id'] = $login_user_id;
			$_SESSION['username'] = $login_username;
			$_SESSION['typ'] = $login_typ;
			// Weiterleitung zur geschützten Startseite
			if ($_SERVER['SERVER_PROTOCOL'] == 'HTTP/1.1')
				if (php_sapi_name() == 'cgi')
					header('Status: 303 See Other');
				else
					header('HTTP/1.1 303 See Other');
			header('Location: '.$myhost.$url);
			exit;
		} else $msg = 'Passwort oder Benutzername sind falsch. Bitte versuchen Sie es erneut.';
	}
	
	if (isset($myurl['query']) && $myurl['query'] == 'logout') {
		session_start();
		if (!empty($_SESSION['user_id']))
			$sql = 'UPDATE example_logins 
			SET eingeloggt = "nein"
			WHERE login_id = '.$_SESSION['user_id'];
		session_destroy();
		if (!empty($sql)) $result = mysql_query($sql);
		$ausgabe .= '<p><strong class="error">Sie haben sich abgemeldet.</strong></p>';
	}

	$ausgabe .='
<p>Für den Zugang zum internen Bereich ist eine Anmeldung erforderlich. Bitte geben Sie unten den von uns erhaltenen Benutzernamen mit Paßwort ein.</p>
<p>Damit der Login funktioniert, müssen Sie nach Übermittlung der Anmeldedaten einen Cookie akzeptieren. Nach zehn Minuten Inaktivität werden Sie aus Sicherheitsgründen automatisch wieder abgemeldet!</p>

<form action="./';
	if (isset($url)) $ausgabe.= '?url='.urlencode($url); 
	$ausgabe.= '" method="POST" class="login">
<p><label for="username"><strong>Benutzername:</strong></label> <input type="text" name="username" id="username"></p>
<p><label for="password"><strong>Passwort:</strong></label> <input type="password" name="password" id="password"></p>
<p class="submit"><input type="submit" value="Anmelden"></p>
';
if ($msg) $ausgabe.= '<p class="error">'.$msg.'</p>';

	$ausgabe.= '</form>';
	return $ausgabe;
}

function zeige_datei () {
	$dirname = false;
	$url = parse_url($_SERVER['REQUEST_URI']);
	$filename = $_SERVER['DOCUMENT_ROOT'].'/www/_kunden/'.substr($url['path'],8);
	if (is_dir($filename)) $dirname = $filename;
	if ($dirname && substr($url['path'], (strlen($url['path'])-1)) != '/') {
		header('Location: http://'.$_SERVER['SERVER_NAME'].$url['path'].'/');
	}

	if (strstr(basename($filename), '.'))
		$suffix = substr($filename, strrpos($filename, '.')+1);
	else { 
		if (substr($filename, strlen($filename)-1) == '/')
			if (file_exists($filename.'index.php'))
				$filename .= 'index.php';
			else
				$filename .= 'index.html';
		else
			$filename .= '.php';
		$suffix = false;
	}
	if (file_exists($filename)) {
		$cache_time = filemtime($filename);
		$filesize = filesize($filename);
		$cache_time = gmdate("D, d M Y H:i:s",$cache_time);
		//$cache_time = 'Sa, 05 Jun 2004 15:40:28';
	
		switch($suffix) {
			case false:
			case 'php':
			case 'html':
			case 'htm':
				include_once $filename;
				echo '<div style="position: absolute; right: 10px; top: 10px; background: white;
	font-size: 100%;"><a href="/login/?logout" style="display: block; padding: 5px;">Logout</a></div>';
				exit;
			case 'jpeg':
			case 'jpg':
	 			header("Accept-Ranges: bytes");
	 			header("Last-Modified: " . $cache_time . " GMT");
				header("Content-Length: " . $filesize);
				header("Content-Type: image/jpeg");
				readfile($filename);
				exit;
			case 'gif':
	 			header("Accept-Ranges: bytes");
				header("Last-Modified: " . $cache_time . " GMT");
				header("Content-Length: " . $filesize);
				header("Content-Type: image/gif");
				readfile($filename);
				exit;
			case 'png':
	 			header("Accept-Ranges: bytes");
				header("Last-Modified: " . $cache_time . " GMT");
				header("Content-Length: " . $filesize);
				header("Content-Type: image/png");
				readfile($filename);
				exit;
			case 'xls':
	 			header("Accept-Ranges: bytes");
				header("Last-Modified: " . $cache_time . " GMT");
				header("Content-Length: " . $filesize);
				header("Content-Type: application/vnd.ms-excel");
				readfile($filename);
			default:
 				header("Accept-Ranges: bytes");
				header("Last-Modified: " . $cache_time . " GMT");
				header("Content-Length: " . $filesize);
				header("Content-Type: application/octet-stream");
				header("Content-Disposition: Attachment; filename=".basename($filename));
					// d. h. bietet save as-dialog an, geht nur mit application/octet-stream
				readfile($filename);
				exit;
		}
	}

// Keine Treffer bisher
include($_SERVER['DOCUMENT_ROOT'].'/www/_scripts/404.php');
exit;

}

?>