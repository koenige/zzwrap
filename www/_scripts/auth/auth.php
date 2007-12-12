<?php
session_start();

$hostname = $_SERVER['HTTP_HOST'];
$lokaler_zugriff = (substr($hostname, strlen($hostname) -6) == '.local' ? true : false);
$host = 'http'.($lokaler_zugriff ? '' : '').'://'.$hostname; // nur unsicherer Zugriff
// $host = 'http'.($lokaler_zugriff ? '' : 's').'://'.$hostname; // falls sicherer Zugriff

$jetzt = time();
$keep_alive = 10 * 60; // 10 minuten bleibt man eingeloggt

$path = '';
$lang = (isset($_GET['lang'])) ? $_GET['lang'] : 'de';

if (empty($_SESSION['logged_in']) OR $jetzt > ($_SESSION['last_click_at'] + $keep_alive)) {
	header('Location: '.$host.($path == '/' ? '' : $path).'/login/?url='.$_SERVER['REQUEST_URI']);
	exit;
}

require_once $zz_setting['db_inc'];
$_SESSION['last_click_at'] = $jetzt;
$sql = 'UPDATE example_logins 
	SET eingeloggt = "ja", letzter_klick = '.$jetzt.' 
	WHERE login_id = '.$_SESSION['user_id'];
$result = mysql_query($sql);
// falls fehler auftritt, nicht so wichtig.

?>