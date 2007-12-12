<?php

/*
	Zugzwang Project
	interner Bereich, Basisskript

	(c) 2006 Gustaf Mossakowski, <gustaf@koenige.org>
*/


$zz_page['inc'] = $_SERVER['DOCUMENT_ROOT'].'/www/_scripts';
require_once $zz_page['inc'].'/zzform/local/config.inc.php';
require_once $zz_page['inc'].'/auth/auth.php';

require_once $zz_conf['dir'].'/inc/edit.inc.php';

if (!empty($_SESSION)) {
	$zz_conf['user'] = $_SESSION['username'];
}

?>