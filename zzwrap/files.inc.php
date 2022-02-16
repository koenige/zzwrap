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
 * @return array list of included files
 */
function wrap_include_files($filename, $paths = 'custom/modules') {
	global $zz_setting;
	$files = wrap_collect_files($filename, $paths);
	if (!$files) return false;
	foreach ($files as $file) include_once $file;
	return $files;
}

/**
 * check for files in modules and custom folders
 * allow to define order of files (custom/modules); search just custom folder,
 * just modules folders or single module
 *
 * @param string $filename filename to look for, may include path, may omit .inc.php
 * @param string $search where to look for files: custom folder, modules etc.
 * @return array
 */
function wrap_collect_files($filename, $search = 'custom/modules') {
	global $zz_setting;

	$modules = [];
	$custom = false;
	$files = [];

	switch ($search) {
	case 'custom/modules':
	case 'modules/custom':
		$modules = $zz_setting['modules'];
		$custom = true;
		break;
	case 'custom':
		// only look into custom folder
		$custom = true;
		break;
	case 'modules':
		// only look into modules
		$modules = $zz_setting['modules'];
		break;
	default:
		// only look into single module folder
		$modules = [$search];
		break;
	}
	
	// has filename path in it?
	if (strpos($filename, '/')) {
		$path = dirname($filename);
		$filename = basename($filename);
	} else {
		$path = '';
	}
	// no file extension given? add .inc.php
	if (!strpos($filename, '.')) $filename = sprintf('%s.inc.php', $filename);

	// check modules (default always is first module)
	foreach ($modules as $module) {
		$this_path = $path ? $path : $module;
		// disable default module?
		if ($module === 'default' AND !empty($zz_setting['default_dont_collect'][$filename]))
			continue;
		$file = sprintf('%s/%s/%s/%s', $zz_setting['modules_dir'], $module, $this_path, $filename);
		if (file_exists($file)) $files[$module] = $file;
	}

	if ($custom) {
		// check custom folder
		$this_path = $path ? $path : 'zzwrap';
		$file = sprintf('%s/%s/%s', $zz_setting['custom'], $this_path, $filename);
		if (file_exists($file)) {
			if ($search === 'custom/modules') {
				array_unshift($files, $file);
			} else {
				$files[] = $file;
			}
		}
	}

	return $files;
}

/*
 * --------------------------------------------------------------------
 * External Libraries
 * --------------------------------------------------------------------
 */

function wrap_include_ext_libraries() {
	global $zz_setting;
	static $included;
	if ($included) return true;

	if (empty($zz_setting['ext_libraries'])) return false;
	foreach ($zz_setting['ext_libraries'] as $function) {
		if (file_exists($file = $zz_setting['lib'].'/'.$function.'.php')) 
			require_once $file;
		elseif (file_exists($file = $zz_setting['lib'].'/'.$function.'/'.$function.'.php'))
			require_once $file;
		else {
			$found = false;
			foreach ($zz_setting['modules'] as $module) {
				$file = $zz_setting['modules_dir'].'/'.$module.'/libraries/'.$function.'.inc.php';
				if (!file_exists($file)) continue;
				require_once $file;
				$found = true;
				break;
			}
			if (!$found) {
				wrap_error(sprintf(wrap_text('Required library %s does not exist.'), '`'.$function.'`'), E_USER_ERROR);
			}
		}
	}
	$included = true;
	return true;
}
