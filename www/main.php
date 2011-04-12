<?php 

// zzwrap (Zugzwang Project)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2010
// Main script


// Initalize parameters
$zz_access = false;

require_once 'paths.inc.php';
if (file_exists($zz_setting['inc'].'/config.inc.php'))
	require_once $zz_setting['inc'].'/config.inc.php'; 		// configuration
require_once $zz_setting['inc'].'/library/zzwrap/zzwrap.php';	// CMS
zzwrap();

?>