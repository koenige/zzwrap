<?php

require_once $_SERVER['DOCUMENT_ROOT'].'/../scripts/base.inc.php';
include_once $zz_setting['db_inc'];

session_start();
if (!empty($_SESSION['user_id']))
	$sql = 'UPDATE example_logins 
	SET eingeloggt = "nein"
	WHERE person_id = '.$_SESSION['user_id'];
session_destroy();
if (!empty($sql)) $result = mysql_query($sql);

$hostname = $_SERVER['HTTP_HOST'];
$lokaler_zugriff = (substr($hostname, strlen($hostname) -6) == '.local' ? true : false);
$host = 'http'.($lokaler_zugriff ? '' : 's').'://'.$hostname;
$path = '';
$lang = (isset($_GET['lang'])) ? $_GET['lang'] : 'en';

header('Location: '.$host.($path == '/' ? '' : $path).'/login/?logout=true');
?>
