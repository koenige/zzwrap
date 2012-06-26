<?php 

/**
 * zzwrap
 * Main script sending the requests to the Content Management System
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2012 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


// root directory
// some providers are not able to configure the root directory correctly
// then you'll have to correct that here
$zz_conf['root'] = $_SERVER['DOCUMENT_ROOT'];

// scripts library
// if your provider does not support putting the include scripts below
// document root, change accordingly
$zz_setting['inc'] = $zz_conf['root'].'/../_inc';

// CMS will be started
require_once $zz_setting['inc'].'/library/zzwrap/zzwrap.php';
zzwrap();

?>