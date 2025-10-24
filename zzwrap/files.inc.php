<?php

/**
 * zzwrap
 * files functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * include files matching file name from inside one or more packages
 * returning a list of included packages, files and functions
 *
 * @param string $file name of file, with path, unless in standard folder, without .inc.php
 * @param array $paths custom/modules, modules/custom, custom or name of module
 * @return array
 */
function wrap_include($filename, $paths = 'custom/modules') {
	static $data = [];
	$files = wrap_collect_files($filename, $paths);
	if (!$files) return false;
	if (!array_key_exists($filename, $data)) {
		$data[$filename] = [
			'packages' => [],
			'functions' => []
		];
	}
	foreach ($files as $package => $file) {
		if (array_key_exists($package, $data[$filename]['packages'])) continue;
		$data[$filename]['packages'][$package] = $file;
		$existing = get_defined_functions();
		if((include_once $file) === false) continue;
		$new = get_defined_functions();
		$diff = array_diff($new['user'], $existing['user']);
		$prefix = wrap_function_prefix($package);
		foreach ($diff as $index => $function) {
			$data[$filename]['functions'][$index]['function'] = $function;
			$data[$filename]['functions'][$index]['package'] = $package;
			if (str_starts_with($function, '_'))
				$data[$filename]['functions'][$index]['private'] = true;
			if (!str_starts_with($function, $prefix)) continue;
			$data[$filename]['functions'][$index]['short'] = substr($function, strlen($prefix));
			$data[$filename]['functions'][$index]['prefix'] = substr($prefix, 0, -1);
		}
	}
	return $data[$filename];
}

/**
 * include a file from inside one or more modules
 *
 * @param string $file name of file, with path, unless in standard folder, without .inc.php
 * @param array $paths custom/modules, modules/custom, custom or name of module
 * @return array list of included files
 * @deprecated use wrap_include() instead
 */
function wrap_include_files($filename, $paths = 'custom/modules') {
	global $zz_setting;
	global $zz_conf;	// all globals also for included files
	global $zz_page;

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
 * @param mixed $search where to look for files: custom folder, modules etc.
 * @return array
 * @todo improve code for search order, use $search here, too
 */
function wrap_collect_files($filename, $search = 'custom/modules') {
	global $zz_setting;

	$packages = [];
	$custom = false;
	$media = false;
	
	$matches = is_array($search) ? $search : explode('/', $search);
	if (in_array('files', $matches))
		$media = true;
	if (in_array('themes', $matches))
		$packages = array_merge($packages, $zz_setting['themes']);
	if (in_array('modules', $matches))
		$packages = array_merge($packages, $zz_setting['modules']);
	if (in_array('custom', $matches))
		$custom = true;
	if (in_array('active', $matches))
		if (!empty($zz_setting['activated']['modules']))
			$packages = array_merge($packages, $zz_setting['activated']['modules']);
		elseif (!empty($zz_setting['active_module']))
			$packages[] = $zz_setting['active_module'];
	$checked = ['files', 'themes', 'modules', 'custom', 'active'];
	foreach ($checked as $key)
		unset($matches[$key]);
	foreach ($matches as $key)
		$packages[] = $key;
	
	if (!$packages AND !$custom AND !$media) return [];

	// has filename path in it?
	if (strpos($filename, '/')) {
		$path = dirname($filename);
		$filename = basename($filename);
	} else {
		$path = '';
	}
	// no file extension given? add .inc.php
	if (!strpos($filename, '.')) $filename = sprintf('%s.inc.php', $filename);

	$files = wrap_collect_files_list($packages, $filename, $path);

	if ($custom) {
		// check custom folder
		$this_path = $path ? $path : 'custom';
		$file = sprintf('%s/%s/%s', $zz_setting['custom'], $this_path, $filename);
		if (strstr($filename, '*')) {
			$matches = glob($file);
			foreach ($matches as $index => $file) {
				if (str_starts_with(basename($file), '.')) continue;
				$files['custom/'.$index] = $file;
			}
			
		} elseif (file_exists($file)) {
			if (str_starts_with($search, 'custom/')
				OR str_starts_with($search, 'files/custom/')) {
				$files = array_merge(['custom' => $file], $files);
			} else {
				$files['custom'] = $file;
			}
		}
	}
	if ($media AND wrap_package('media') AND wrap_setting('media_folder')) {
		if ($extension = wrap_setting('media_original_filename_extension')) {
			$filename = explode('.', $filename);
			array_splice($filename, count($filename) - 1, 0, $extension);
			$filename = implode('.', $filename);
		}
		if ($path)
			$file = sprintf('%s/%s/%s', wrap_setting('media_folder'), $path, $filename);
		else
			$file = sprintf('%s/%s', wrap_setting('media_folder'), $filename);
		if (file_exists($file)) {
			// @todo support `media` at other positions than first
			$files = array_merge(['files' => $file], $files);
		}
	}

	return $files;
}

/**
 * get list of files
 * check modules (default always is first module)
 *
 * @param array $packages
 * @param string $filename
 * @param string $path
 */
function wrap_collect_files_list($packages, $filename, $path) {
	global $zz_setting;

	if (count($packages) === 1 AND $packages[0] === '_core') {
		$files['zzwrap'] = sprintf('%s/%s', __DIR__, $filename);
		return $files;
	}

	$files = [];
	foreach ($packages as $package) {
		$this_path = $path ? $path : $package;
		// disable default module?
		if ($package === 'default' AND !empty($zz_setting['default_dont_collect'][$filename]))
			continue;
		$type = in_array($package, $zz_setting['modules']) ? 'modules' : 'themes';
		$file = sprintf('%s/%s/%s/%s', $zz_setting[$type.'_dir'], $package, $this_path, $filename);
		if (strstr($filename, '*')) {
			$matches = glob($file, \GLOB_BRACE);
			foreach ($matches as $index => $file) {
				if (str_starts_with(basename($file), '.')) continue;
				$files[$package.'/'.$index] = $file;
			}
		} else {
			if (file_exists($file))
				$files[$package] = $file;
		}
	}
	return $files;
}

/**
 * get a list of functions that match from a list of recently included files
 *
 * @param array $files
 * @param string $match
 * @return array
 */
function wrap_functions($files, $match) {
	$matches = [];
	foreach ($files['functions'] as $function) {
		if (!str_starts_with($function['short'], $match)) continue;
		$suffix = substr($function['short'], strlen($match) + 1);
		$function['suffix'] = $suffix ? $suffix : NULL;
		$matches[] = $function;
	}
	return $matches;
}

/**
 * parse a .tsv tab separated values file
 *
 * @param string $filename
 * @param string $paths (optional)
 * @return array
 */
function wrap_tsv_parse($filename, $paths = '') {
	$filename = sprintf('configuration/%s.tsv', $filename);
	$files = $paths ? wrap_collect_files($filename, $paths) : wrap_collect_files($filename);
	if (!$files) return [];
	$data = [];
	foreach ($files as $file) {
		$content = file($file);
		$head = [];
		$i = 0;
		$key_index = [0];
		$subkey = NULL;
		foreach ($content as $line) {
			if (!trim($line)) continue;
			if (str_starts_with($line, '#:'))
				$head = explode("\t", trim(substr($line, 2)));
			if (str_starts_with($line, '#key'))
				$key_index = explode(' ', trim(substr($line, 4)));
			if (str_starts_with($line, '#')) continue;
			$i++;
			$line = explode("\t", trim($line));
			if (count($key_index) === 2 AND $key_index[1] === 'numeric') {
				$key = $line[0];
				$subkey = $i;
			} elseif ($key_index[0] === 'numeric') {
				$key = $i;
			} elseif (array_key_exists($key_index[0], $line)) {
				$key = trim($line[$key_index[0]]);
			} else {
				$key = trim($line[0]);
			}
			if (count($line) === 1 and !$head) {
				$data[] = trim($line[0]);
			} elseif (count($line) === 2 and !$head) {
				// key/value
				$data[$key] = trim($line[1]);
			} elseif (!is_null($subkey)) {
				if ($head) {
					$this_line = [];
					foreach ($head as $index => $title)
						$this_line[$title] = trim($line[$index] ?? '');
					$data[$key][] = $this_line;
				} else {
					$data[$key][] = $line;
				}
			} else {
				$data[$key] = $line;
				if ($head) {
					foreach ($head as $index => $title)
						$data[$key][$title] = trim($line[$index] ?? '');
				}
			}
		}
	}
	return $data;
}

/*
 * --------------------------------------------------------------------
 * External Libraries
 * --------------------------------------------------------------------
 */

/**
 * include libraries from library folder
 *
 * @param mixed $libraries (string = single library, array = list of libraries)
 * @return void
 */
function wrap_lib($libraries = []) {
	static $included = [];

	if (!$libraries)
		$libraries = wrap_setting('ext_libraries');
	elseif (!is_array($libraries))
		$libraries = [$libraries];
		
	$possible_paths = [
		wrap_setting('lib').'/%s.php',
		wrap_setting('lib').'/%s/%s.php',
		wrap_setting('lib').'/%s/autoload.php',
		wrap_setting('lib').'/%s/vendor/autoload.php'
	];

	foreach ($libraries as $function) {
		if (in_array($function, $included)) continue;
		$found = false;

		$folders = wrap_collect_files(sprintf('libraries/%s', $function), 'modules');
		if (array_key_exists($function, $folders)) {
			// if library name is identical to module, just look inside module
			require_once $folders[$function];
			$found = true;
		} elseif ($folders) {
			// check all modules
			$file = reset($folders);
			require_once $file;
			$found = true;
		} else {
			// look in possible paths
			$func_vars = [$function, $function];
			foreach ($possible_paths as $path) {
				$file = vsprintf($path, $func_vars);
				if (!file_exists($file)) continue;
				require_once $file;
				$found = true;
				break;
			}
		}
		if (!$found)
			wrap_error(wrap_text('The required library %s does not exist. Please install it in the `_inc/library` folder.', ['values' => '`'.$function.'`']), E_USER_ERROR);

		$included[] = $function;
	}
}

/*
 * --------------------------------------------------------------------
 * Packages
 * --------------------------------------------------------------------
 */

/**
 * check if package exists
 *
 * @param string $package name of the package
 * @return string
 */
function wrap_package($name) {
	if (in_array($name, wrap_setting('modules'))) return 'modules';
	if (in_array($name, wrap_setting('themes'))) return 'themes';
	return NULL;
}

/**
 * activate a module or a theme
 *
 * settings active_module, active_theme, activated[modules], activated[themes]
 * @param string $package
 * @param string $type (optional, default = module)
 * @return void
 */
function wrap_package_activate($package, $type = 'module') {
	$single_type = sprintf('active_%s', $type);
	$plural_type = sprintf('%ss', $type);
	
	if (!wrap_setting($single_type))
		wrap_setting($single_type, $package);
	if (!in_array($package, wrap_setting('activated['.$plural_type.']')))
		wrap_setting_add('activated['.$plural_type.']', $package);
	
	// dependencies?
	$package_info = wrap_cfg_files('package', ['package' => $package]);
	if (!empty($package_info['dependencies'])) {
		foreach ($package_info['dependencies'] as $dependency_type => $dependencies) {
			if ($dependency_type === 'package') continue;
			foreach ($dependencies as $dependency) {
				wrap_setting_add('activated['.$dependency_type.']', $dependency);
			}
		}
	}
	
	switch ($type) {
	case 'module':
		wrap_include('functions', $package);
		break;
	}
}

/**
 * get function prefix per package
 *
 * @param string $package
 * @return string
 */
function wrap_function_prefix($package) {
	switch ($package) {
		case 'zzwrap': return 'wrap_';
		case 'zzform': return 'zz_';
		case 'custom': return 'my_';
		default: return sprintf('mf_%s_', $package);
	}
}
