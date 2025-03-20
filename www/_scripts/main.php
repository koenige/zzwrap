<?php 

/**
 * zzwrap
 * Main script sending the requests to the Content Management System
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2012, 2019, 2023 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


// modify path if modules reside in a different directory
require_once $_SERVER['DOCUMENT_ROOT'].'/../_inc/modules/zzwrap/zzwrap/zzwrap.php';
zzwrap();
