<?php

/**
 * zzwrap
 * include functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * include a file from inside one or more modules
 *
 * @param string $file name of file, with path, unless in standard folder, without .inc.php
 * @param array $paths custom/modules, modules/custom, custom or name of module
 * @return void
 */
function wrap_include_files($filename, $paths = 'custom/modules') {
	global $zz_setting;
	$files = wrap_collect_files($filename, $paths);
	foreach ($files as $file) include_once $file;
}
