<?php

/**
 * zzwrap
 * routes and path functions
 *
 * - wrap_routes_read(), wrap_routes_write(), wrap_routes_path_prepare()
 * - wrap_path(), wrap_path_fallback(), wrap_path_placeholder(), wrap_path_helptext()
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * read routes.json, generate it if it does not exist
 *
 * @return array
 */
function wrap_routes_read() {
	static $routes = NULL;
	if ($routes !== NULL) return $routes;

	$file = wrap_setting('config_dir').'/routes.json';
	$lock = wrap_setting('tmp_dir').'/routes-update.lock';

	if (!file_exists($file)) {
		wrap_routes_write();
	} elseif (!file_exists($lock)
		OR filemtime($lock) < time() - wrap_setting('routes_cache_seconds')) {
		wrap_routes_write();
	}

	if (file_exists($file))
		$routes = json_decode(file_get_contents($file), true);
	if (!$routes)
		$routes = [];
	return $routes;
}

/**
 * write routes.json
 *
 * @return void
 */
function wrap_routes_write() {
	$lock = wrap_setting('tmp_dir').'/routes-update.lock';
	$routes = wrap_cfg_files('routes');
	if (!$routes) { touch($lock); return; }
	if (!wrap_db_connection()) { touch($lock); return; }

	$sql = 'SELECT CONCAT(identifier, IF(ending = "none", "", ending)) AS path
			, content, parameters
		FROM /*_PREFIX_*/webpages
		WHERE (content LIKE "%\%\%\%%" OR parameters LIKE "%&route=%")
		AND website_id = /*_SETTING website_id _*/';
	$pages = wrap_db_fetch($sql, '_dummy_', 'numeric');
	if (!$pages) { touch($lock); return; }

	$paths = [];
	foreach ($routes as $key => $route) {
		if (wrap_routes_write_params($key, $route, $pages, $paths)) continue;
		wrap_routes_write_brick($key, $route, $pages, $paths);
	}

	ksort($paths);
	$file = wrap_setting('config_dir').'/routes.json';
	$new_content = json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	$existing_content = file_exists($file) ? file_get_contents($file) : '';
	if ($new_content !== $existing_content) {
		wrap_mkdir(dirname($file));
		file_put_contents($file, $new_content);
	}
	touch($lock);
}

/**
 * resolve route from webpages.parameters (e. g. route=login_entry)
 *
 * @param string $key
 * @param array $route
 * @param array $pages
 * @param array $paths (will be changed)
 * @return bool true if route was handled
 */
function wrap_routes_write_params($key, $route, $pages, &$paths) {
	if (empty($route['match_parameters'])) return false;
	foreach ($pages as $page) {
		if (!$page['parameters']) continue;
		parse_str($page['parameters'], $params);
		if (!empty($params['route']) AND $params['route'] === $key) {
			$paths[$key] = $page['path'];
			break;
		}
	}
	return true;
}

/**
 * resolve route from brick in webpage content
 *
 * @param string $key
 * @param array $route
 * @param array $pages
 * @param array $paths (will be changed)
 */
function wrap_routes_write_brick($key, $route, $pages, &$paths) {
	if (empty($route['brick'])) return;
	$brick = $route['brick'];

	$matches = [];
	foreach ($pages as $page) {
		if (!$page['content']) continue;
		$pattern = '%%% '.$brick;
		if (!strstr($page['content'], $pattern)) continue;
		// remove found pattern, keep local settings
		$page['content'] = substr($page['content'], strlen($pattern));
		$pos = strpos($page['content'], '%%%');
		if ($pos !== false)
			$page['content'] = substr($page['content'], 0, $pos);
		$page['content'] = trim($page['content']);
		$matches[] = $page;
	}
	if (!$matches) {
		if (str_ends_with($brick, ' *')) {
			$base_regex = preg_quote(substr($brick, 0, -2), '/');
			foreach ($pages as $page) {
				if (!$page['content']) continue;
				if (!preg_match('/%%% '.$base_regex.' (.+?) \*/', $page['content'], $m)) continue;
				$subkey = str_replace(['-', ' '], '_', trim($m[1]));
				if (!$subkey) continue;
				$path = wrap_routes_path_prepare($page['path']);
				if (!is_array($paths[$key] ?? null))
					$paths[$key] = [];
				if (array_key_exists($subkey, $paths[$key]))
					$paths[$key][$subkey] = NULL; // no ambiguous paths
				else
					$paths[$key][$subkey] = $path;
			}
		}
		return;
	}

	// filter by brick_local_settings
	$params = $route['brick_local_settings'] ?? [];
	$no_params = [];
	foreach ($params as $param_key => $param_value) {
		if ($param_value) continue;
		$no_params[] = $param_key;
		unset($params[$param_key]);
	}
	if ($params) {
		$param_str = http_build_query($params);
		foreach (explode('&', $param_str) as $param) {
			if (!$param) continue;
			// if parameter: only leave pages having this parameter
			foreach ($matches as $index => $match) {
				if ($match['content'] AND strstr($match['content'], $param)) continue;
				unset($matches[$index]);
			}
		}
	}
	foreach ($no_params as $param) {
		// if parameter=0: only leave pages without this parameter
		foreach ($matches as $index => $match) {
			if (!$match['content']) continue;
			if (!strstr($match['content'], $param.'=')) continue;
			unset($matches[$index]);
		}
	}

	if (!empty($route['expand'])) {
		foreach ($matches as $match) {
			$path = wrap_routes_path_prepare($match['path']);
			if (!$match['content'] || !preg_match('/'.preg_quote($route['expand'], '/').'=([^\s&]+)/', $match['content'], $m)) {
				$paths[$key.'[*]'] = $path;
			} else {
				$subkey = str_replace(['-', ' '], '_', trim($m[1]));
				if (!$subkey) continue;
				$path_key = $key.'['.$subkey.']';
				$paths[$path_key] = array_key_exists($path_key, $paths) ? NULL : $path;
			}
		}
		return;
	}

	// disambiguation: prefer non-wildcard if brick has no *
	if (count($matches) !== 1 AND !str_ends_with($brick, '*')) {
		foreach ($matches as $index => $match) {
			if ($match['content'] AND str_starts_with($match['content'], '*'))
				unset($matches[$index]);
		}
	}
	// disambiguation: prefer exact brick match over brick with parameters
	if (count($matches) !== 1) {
		$removes = [];
		foreach ($matches as $index => $match) {
			if ($match['content']) $removes[] = $index;
		}
		if (count($removes) + 1 === count($matches)) {
			foreach ($removes as $index) unset($matches[$index]);
		}
	}
	// fallback for `tables` brick
	if (count($matches) !== 1) {
		$brick = explode(' ', $brick);
		if (count($brick) !== 2) return;
		if ($brick[0] !== 'tables') return;
		$path = wrap_path('default_tables', $brick[1]);
		if ($path) $paths[$key] = $path;
		return;
	}

	$path = reset($matches)['path'];
	$paths[$key] = wrap_routes_path_prepare($path);
}

/**
 * prepare route path
 *
 * @param string $path
 * @return string
 */
function wrap_routes_path_prepare($path) {
	$path = str_replace('*', '/%s', $path);
	$path = str_replace('//', '/', $path);
	return $path;
}

/**
 * get a path based on a route, check for access
 *
 * @param string $area Route name (key in routes.json / routes.cfg).
 * @param mixed $value (optional) Path segments for placeholders; string or array, e.g. ['id'] or 'slug'.
 * @param array $settings (optional) Options:
 *   - check_rights (bool): Check wrap_access($area, detail); default true.
 *   - testing (bool): If true, only test whether the route exists (e.g. for %%% if path area %%%); no "No route found" warning, missing placeholders filled with 'testing'; default false.
 *   - detail (string): Passed to wrap_access($area, $detail) for access detail, e.g. 'restrict_to:123'; default ''.
 *   - hide_missing (bool): If true, do not emit E_USER_WARNING when the route is not found; default false.
 *   - no_base (bool): If set, do not prepend wrap_setting('base') to the path; default false.
 * @return string|null Path string, or NULL if route not found / access denied.
 */
function wrap_path($area, $value = [], $settings = [], $testing = false, $settings_old = []) {
	// cater for old signature (3rd = check_rights/detail, 4th = testing, 5th = settings)
	if (!is_array($settings)) {
		$third = $settings;
		$settings = [];
		$caller = _wrap_path_deprecation_caller();
		if (is_bool($third)) {
			$settings['check_rights'] = $third;
			wrap_error(sprintf(
				'wrap_path(): boolean as third parameter is deprecated, use wrap_path($area, $value, [\'check_rights\' => %s])%s',
				$third ? 'true' : 'false',
				$caller
			), E_USER_DEPRECATED);
		} elseif (is_string($third)) {
			$settings['detail'] = $third;
			$settings['check_rights'] = true;
			wrap_error('wrap_path(): string as third parameter is deprecated, use wrap_path($area, $value, [\'detail\' => \'...\'])'.$caller, E_USER_DEPRECATED);
		} else {
			$settings['check_rights'] = true;
		}
		$settings['testing'] = $testing;
		if ($settings_old) $settings += $settings_old;
	} else {
		if (func_num_args() >= 4) {
			wrap_error('wrap_path(): 4th and 5th parameters are deprecated, use wrap_path($area, $value, [\'testing\' => ..., ...])'._wrap_path_deprecation_caller(), E_USER_DEPRECATED);
		}
		if ($testing === true) $settings['testing'] = true;
		if ($settings_old) $settings += $settings_old;
	}
	$settings['check_rights'] = $settings['check_rights'] ?? true;
	$settings['testing'] = $settings['testing'] ?? false;
	$settings['detail'] = $settings['detail'] ?? '';

	$routes = wrap_routes_read();
	if (!array_key_exists($area, $routes)) {
		// fallback: use base[*] when base[subkey] is missing (e.g. contacts_profile[organisation] -> contacts_profile[*])
		$area_fallback = NULL;
		if (preg_match('/^(.+)\[[^\]]+\]$/', $area, $m) && array_key_exists($m[1].'[*]', $routes)) {
			$area_fallback = $m[1].'[*]';
		}
		if ($area_fallback) {
			$area = $area_fallback;
		} else {
			$path = wrap_path_fallback($area, $value, $settings);
			if ($path !== NULL) return $path;
			if (empty($settings['testing']) AND empty($settings['hide_missing']))
				wrap_error(wrap_text('No route found for `%s`.', ['values' => [$area]]), E_USER_WARNING);
			return NULL;
		}
	}

	// check rights
	if ($settings['check_rights'] AND !wrap_access($area, $settings['detail'])) return NULL;

	$path = $routes[$area];
	if (!$path) return '';
	// route has parameterized variants, e. g. news_article[news], news_article[projects]
	// extract first identifier fragment to find the matching sub-route
	if (is_array($path)) {
		if (!is_array($value)) $value = [$value];
		$values = implode('/', $value);
		$pos = strpos($values, '/');
		if ($pos === false) return '';
		$path = $path[substr($values, 0, $pos)] ?? '';
		if (!$path) return '';
		$value = [substr($values, $pos + 1)];
	}

	// replace page placeholders with %s
	$path = wrap_path_placeholder($path);
	$required_count = substr_count($path, '%');
	if (!is_array($value)) $value = [$value];
	if (count($value) < $required_count) {
		if (wrap_setting('backend_path'))
			array_unshift($value, wrap_setting('backend_path'));
		if (count($value) < $required_count AND wrap_setting('path_placeholder_function')) {
			$new_value = wrap_setting('path_placeholder_function')();
			if ($new_value) array_unshift($value, $new_value);
		}
		if (count($value) < $required_count) {
			if (!$settings['testing']) return '';
			while (count($value) < $required_count)
				$value[] = 'testing';
		}
	}
	if (count($value) > $required_count AND $required_count === 1)
		$value = [implode('/', $value)];
	$base = !empty($settings['no_base']) ? '' : wrap_setting('base');
	$path = vsprintf($base.$path, $value);
	if (str_ends_with($path, '#')) $path = substr($path, 0, -1);
	if ($website_id = wrap_setting('backend_website_id')
		AND $website_id !== wrap_setting('website_id')) {
		$cfg = wrap_cfg_files('settings');
		if (!empty($cfg[$setting]['backend_for_website']))
			$path = wrap_host_base($website_id).$path;
	}
	return $path;
}

/**
 * build path from route config fallback when area is not in routes.json
 *
 * In routes.cfg set fallback_area, fallback_value, and optionally
 * fallback_query (e. g. "?filter[maincategory]=%d") and fallback_id_table
 * (table name for wrap_id(), e. g. categories).
 *
 * @return string|null
 */
function wrap_path_fallback($area, $value, $settings) {
	$cfg = wrap_cfg_files('routes');
	if (empty($cfg[$area]['fallback_area'])) return NULL;
	if (empty($cfg[$area]['fallback_value'])) return NULL;

	$path = wrap_path(
		$cfg[$area]['fallback_area'], $cfg[$area]['fallback_value'], $settings
	);
	if (!$path) return NULL;
	if (empty($cfg[$area]['fallback_query'])) return $path;

	if ($value) {
		$val = is_array($value) ? $value[0] : $value;
	} else {
		$val = '';
	}
	$qs = $cfg[$area]['fallback_query'];
	if (empty($cfg[$area]['fallback_id_table']))
		$qs = sprintf($qs, $val);
	else
		$qs = sprintf($qs, wrap_id($cfg[$area]['fallback_id_table'], $val));
	return $path.$qs;
}

/**
 * replace URL placeholders in path (e. g. %year%) with %s
 *
 * @param string $path
 * @param string $char whith what to replace
 * @return string
 */
function wrap_path_placeholder($path, $char = '%s') {
	if (!wrap_setting('url_placeholders')) return $path;
	foreach (array_keys(wrap_setting('url_placeholders')) as $placeholder) {
		$placeholder = sprintf($char === '*' ? '/%%%s%%' : '%%%s%%', $placeholder);
		if (!strstr($path, $placeholder)) continue;
		$path = str_replace($placeholder, $char, $path);
	}
	// remove duplicate *
	while (strstr($path, $char.'/'.$char))
		$path = str_replace($char.'/'.$char, $char, $path);
	while (strstr($path, $char.$char))
		$path = str_replace($char.$char, $char, $path);
	return $path;
}

/**
 * get a path for a certain help text
 *
 * @param string $helptext
 * @return string
 */
function wrap_path_helptext($help) {
	$identifier = str_replace('_', '-', $help);
	wrap_include('zzbrick_request_get/helptexts', 'default');
	$files = mf_default_helptexts_files();
	if (!array_key_exists($identifier, $files)) return '';
	return wrap_path('default_helptext', $help);
}

/**
 * caller info for wrap_path() deprecation messages
 *
 * @return string e.g. " (called from path/to/file.inc.php:42 in function_name)"
 */
function _wrap_path_deprecation_caller() {
	$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
	// [0] = _wrap_path_deprecation_caller, [1] = wrap_path, [2] = caller
	if (empty($trace[2])) return '';
	$t = $trace[2];
	$file = isset($t['file']) ? basename($t['file']) : '';
	$line = $t['line'] ?? 0;
	$func = isset($t['function']) ? $t['function'] : '';
	if ($file && $line)
		return sprintf(' (called from %s:%d%s)', $file, $line, $func ? ' in '.$func : '');
	return '';
}
