<?php

/**
 * zzwrap
 * files functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2022-2026 Gustaf Mossakowski
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
	if (!$files) return [];
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
 */
function wrap_collect_files($filename, $search = 'custom/modules') {
	global $zz_setting;

	// has filename path in it?
	if (strpos($filename, '/')) {
		$path = dirname($filename);
		$filename = basename($filename);
	} else {
		$path = '';
	}
	// no file extension given? add .inc.php
	if (!str_contains($filename, '.')) $filename = sprintf('%s.inc.php', $filename);

	// get all matches
	$matches = is_array($search) ? $search : explode('/', $search);

	// first, collect from all packages
	$packages = [];
	if (in_array('themes', $matches)) {
		$sources['themes'] = $zz_setting['themes'] ?? [];
		$packages = array_merge($packages, $sources['themes']);
	}
	if (in_array('modules', $matches)) {
		$sources['modules'] = $zz_setting['modules'] ?? [];
		$packages = array_merge($packages, $sources['modules']);
	}
	if (in_array('active', $matches)) {
		if (!empty($zz_setting['activated_modules'])) {
			$sources['active'] = $zz_setting['activated_modules'];
			$packages = array_merge($packages, $sources['active']);
		} elseif (!empty($zz_setting['active_module'])) {
			$sources['active'] = [$zz_setting['active_module']];
			$packages[] = $zz_setting['active_module'];
		} else {
			$sources['active'] = [];
		}
	}
	$checked = ['files', 'themes', 'modules', 'custom', 'active'];
	if ($extra_packages = array_diff($matches, $checked))
		$packages = array_unique(array_merge($packages, $extra_packages));

	$files_packages = $packages ? wrap_collect_files_list($packages, $filename, $path) : [];
	$files = [];
	foreach ($matches as $match) {
		switch ($match) {
		case '_core':
			// wrap_collect_files_list() uses key `zzwrap`, not `_core`
			foreach ($files_packages as $key => $file_path) {
				$files[$key] = $file_path;
			}
			break;
		case 'themes':
		case 'modules':
		case 'active':
			foreach ($files_packages as $key => $file_path) {
				$package = strpos($key, '/') !== false ? strstr($key, '/', true) : $key;
				if (!in_array($package, $sources[$match], true)) continue;
				$files[$key] = $file_path;
			}
			break;
		case 'custom':
			// check custom folder
			$this_path = $path ? $path : 'custom';
			$file = sprintf('%s/%s/%s', $zz_setting['custom'], $this_path, $filename);
			if (strstr($filename, '*')) {
				$name_matches = glob($file, \GLOB_BRACE);
				foreach ($name_matches as $index => $file) {
					if (str_starts_with(basename($file), '.')) continue;
					$files['custom/'.$index] = $file;
				}
			} elseif (file_exists($file)) {
				$files['custom'] = $file;
			}
			break;
		case 'files':
			if (!wrap_package('media')) break;
			if (!wrap_setting('media_folder')) break;
			$file = [
				'filename' => ($path ? $path.'/' : '').pathinfo($filename, PATHINFO_FILENAME),
				'extension' => pathinfo($filename, PATHINFO_EXTENSION)
			];
			$full_filename = mf_media_filename($file, 'original', true);
			if (!file_exists($full_filename)) break;
			$files['files'] = $full_filename;
			break;
		default:
			foreach ($files_packages as $key => $file_path) {
				$package = strpos($key, '/') !== false ? strstr($key, '/', true) : $key;
				if ($package !== $match) continue;
				unset($files[$key]);
				$files[$key] = $file_path;
			}
			break;
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
 * $match is the full short name for an exact match. If it ends with *, the
 * asterisk is stripped and remaining characters are matched as a prefix
 * (suffix is set to the rest of short, after the next character, or NULL).
 *
 * @param array $files wrap_include() result
 * @param string $match e.g. logging or logging*
 * @return array
 */
function wrap_functions($files, $match) {
	$matches = [];
	$prefix = str_ends_with($match, '*');
	$needle = $prefix ? substr($match, 0, -1) : $match;
	foreach (($files['functions'] ?? []) as $function) {
		if (empty($function['short'])) continue;
		if (!empty($function['private'])) continue;
		if ($prefix) {
			if (!str_starts_with($function['short'], $needle)) continue;
			$suffix = substr($function['short'], strlen($needle) + 1);
			$function['suffix'] = $suffix ? $suffix : NULL;
		} else {
			if ($function['short'] !== $needle) continue;
			$function['suffix'] = NULL;
		}
		$matches[] = $function;
	}
	return $matches;
}

/**
 * parse a .tsv tab separated values file
 *
 * @param string $filename
 * @param string $paths (optional)
 * @param array $settings (optional)
 *		'key_with_package': bool, append package name to key (default: false)
 * @return array
 */
function wrap_tsv_parse($filename, $paths = '', $settings = []) {
	if (!array_key_exists('key_with_package', $settings))
		$settings['key_with_package'] = false;
	
	$filename = sprintf('configuration/%s.tsv', $filename);
	$files = $paths ? wrap_collect_files($filename, $paths) : wrap_collect_files($filename);
	if (!$files) return [];
	$data = [];
	$i = 0; // With multiple files, `#key numeric` row numbers continue across files, so later files do not overwrite earlier rows.
	foreach ($files as $package => $file) {
		$content = file($file);
		$head = [];
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
				if ($settings['key_with_package'])
					$key = sprintf('%s-%s', $key, $package);
				$data[$key] = trim($line[1]);
			} elseif (!is_null($subkey)) {
				if ($head) {
					$this_line = [];
					foreach ($head as $index => $title)
						$this_line[$title] = trim($line[$index] ?? '');
					$this_line['_package'] = $package;
					$data[$key][] = $this_line;
				} else {
					$line['_package'] = $package;
					$data[$key][] = $line;
				}
			} else {
				if ($settings['key_with_package'])
					$key = sprintf('%s-%s', $key, $package);
				$data[$key] = $line;
				if ($head) {
					foreach ($head as $index => $title)
						$data[$key][$title] = trim($line[$index] ?? '');
				}
				$data[$key]['_package'] = $package;
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
 * settings active_module, active_theme, activated_modules, activated_themes
 * @param string $package
 * @param string $type (optional, default = module)
 * @return void
 */
function wrap_package_activate($package, $type = 'module') {
	switch ($type) {
	case 'module':
		if (!wrap_setting('active_module'))
			wrap_setting('active_module', $package);
		if (!in_array($package, wrap_setting('activated_modules')))
			wrap_setting_add('activated_modules', $package);
		wrap_include('functions', $package);
		break;

	case 'theme':
		if (!wrap_setting('active_theme'))
			wrap_setting('active_theme', $package);
		if (!in_array($package, wrap_setting('activated_themes')))
			wrap_setting_add('activated_themes', $package);
		break;
	}

	// dependencies?
	$package_info = wrap_cfg_files('package', ['package' => $package]);
	if (empty($package_info['dependencies'])) return;

	foreach ($package_info['dependencies'] as $dependency_type => $dependencies) {
		if ($dependency_type === 'package') continue;
		foreach ($dependencies as $dependency) {
			switch ($dependency_type) {
			case 'modules':
				wrap_setting_add('activated_modules', $dependency);
				break;
			case 'themes':
				wrap_setting_add('activated_themes', $dependency);
				break;
			}
		}
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
