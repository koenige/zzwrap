<?php 

// zzwrap (Zugzwang Project)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2011
// Error pages


require_once 'paths.inc.php';
require_once $zz_setting['inc'].'/library/zzwrap/zzwrap.php';

zzwrap();

/*
// in case config has already been included
global $zz_page;
global $zz_setting;	

// basic zzwrap files
if (empty($zz_setting['inc'])) require_once 'paths.inc.php';
if (file_exists($zz_setting['inc'].'/config.inc.php'))
	require_once $zz_setting['inc'].'/config.inc.php'; 	// configuration
require_once $zz_setting['core'].'/defaults.inc.php';	// set default variables
require_once $zz_setting['core'].'/errorhandling.inc.php';	// CMS errorhandling
require_once $zz_setting['db_inc']; // Establish database connection
require_once $zz_setting['core'].'/core.inc.php';	// CMS core scripts

if (!isset($page)) $page = array();
wrap_errorpage($page, $zz_page);
exit;
*/

?>
