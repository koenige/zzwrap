<?php 

/**
 * zzwrap
 * Test functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * get one or all tests for a package
 *
 * @param string $package (optional)
 * @return array
 */
function mf_zzwrap_test_functions($package = 'custom/modules') {
	$files = wrap_collect_files('tests/*.json', $package);
	if (!$files) return [];
	
	foreach ($files as $key => $file) {
		$file_function = substr($file, strrpos($file, '/') + 1);
		if (str_ends_with($file_function, '.json'))
			$file_function = substr($file_function, 0, -5);
		$functions[$file_function] = [
			'function' => $file_function,
			'file' => $file,
			'package' => substr($key, 0, strpos($key, '/'))
		];
	}
	return $functions;
}

/**
 * call a function with path
 *
 * @param string $function
 * @param string $value
 * @return string
 */
function mf_zzwrap_test_function($function, $value) {
	if (strstr($function, '/')) {
		list($package, $file, $function) = explode('/', $function);
		wrap_include($file, $package);
	}
	return $function($value);
}

/**
 * check if a function exists in a package
 *
 * @param string $function
 * @param string $package
 * @param array $files
 * @return bool
 */
function mf_zzwrap_test_function_exists($function, $package, $files) {
	if (empty($files['functions'])) return NULL;

	foreach ($files['functions'] as $index => $line) {
		if ($line['package'] !== $package) continue;
		if ($line['function'] !== $function) continue;
		return true;
	}

	return NULL;
}

