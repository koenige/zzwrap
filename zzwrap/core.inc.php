<?php 

/**
 * zzwrap
 * Core functions: handling of HTTP requests (URLs, HTTP
 * communication, send ressources), common functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/*
 * --------------------------------------------------------------------
 * URLs
 * --------------------------------------------------------------------
 */

/**
 * expand URL (default = own URL)
 * shortcuts allowed: starting / = own server; ? = append query string
 * # = append anchor; ?? append query string and remove existing query string
 *
 * @param string $url
 * @return string
 */
function wrap_url_expand($url = false) {
	if (!$url) {
		$url = wrap_setting('host_base').wrap_setting('request_uri');
	} elseif (substr($url, 0, 1) === '#') {
		$url = wrap_setting('host_base').wrap_setting('request_uri').$url;
	} elseif (substr($url, 0, 2) === '??') {
		$request_uri = wrap_setting('request_uri');
		if ($pos = strpos($request_uri, '?')) $request_uri = substr($request_uri, 0, $pos);
		$url = wrap_setting('host_base').$request_uri.substr($url, 1);
	} elseif (substr($url, 0, 1) === '?') {
		$request_uri = wrap_setting('request_uri');
		if (strstr($request_uri, '?')) {
			$qs = parse_url($request_uri, PHP_URL_QUERY);
			parse_str($qs, $qs);
			$qs_append = parse_url('http://www.example.com/'.$url, PHP_URL_QUERY);
			parse_str($qs_append, $qs_append);
			$qs = array_merge($qs, $qs_append);
			// + 1 = keep the ?
			$request_uri = substr($request_uri, 0, strrpos($request_uri, '?') + 1).http_build_query($qs);
			$url = '';
		}
		$url = wrap_setting('host_base').$request_uri.$url;
	} elseif (substr($url, 0, 1) === '/') {
		$url = wrap_setting('host_base').$url;
	} elseif (str_starts_with($url, './')) {
		$request_path = parse_url(wrap_setting('request_uri'), PHP_URL_PATH);
		$request_path = substr($request_path, 0, strrpos($request_path, '/') + 1);
		$url = wrap_setting('host_base').$request_path.substr($url, 2);
	} elseif (str_starts_with($url, '../')) {
		$request_path = parse_url(wrap_setting('request_uri'), PHP_URL_PATH);
		$this_url = $url;
		while (str_starts_with($this_url, '../')) {
			$this_url = substr($this_url, 3);
			$request_path = substr($request_path, 0, strrpos($request_path, '/'));
			$request_path = substr($request_path, 0, strrpos($request_path, '/') + 1);
			if (!$request_path) {
				wrap_error(wrap_text('Wrong relative path: %s', ['values' => $url]));
				$request_path = '/';
			}
		}
		$url = wrap_setting('host_base').$request_path.$this_url;
	}
	return $url;
}

/**
 * get file extension by filename
 *
 * @param string $filename
 * @return string
 */
function wrap_file_extension($file) {
	if (!strstr($file, '.')) return NULL;
	if (str_starts_with($file, '.') AND substr_count($file, '.') === 1) return NULL;
	$file = explode('.', $file);
	return array_pop($file);
}

/**
 * Glues a URL together
 *
 * @param array $url (e. g. result of parse_url())
 * @return string
 */
function wrap_glue_url($url) {
	$base = wrap_setting('base');
	if (substr($base, -1) === '/') $base = substr($base, 0, -1);
	if (!in_array($_SERVER['SERVER_PORT'], [80, 443])) {
		$url['port'] = sprintf(':%s', $_SERVER['SERVER_PORT']);
	} else {
		$url['port'] = '';
	}
	if (!empty($url['path_forwarded']) AND str_starts_with($url['path'], $url['path_forwarded'])) {
		$url['path'] = substr($url['path'], strlen($url['path_forwarded']));
	}
	// remove duplicate base
	if (str_starts_with($url['path'], $base)) $base = '';
	$url['path'] = $base.$url['path'];
	return wrap_build_url($url);
}

/**
 * build a URL from parse_url() parts
 *
 * @param array
 * @return string
 */
function wrap_build_url($parts) {
	$url = (!empty($parts['scheme']) ? $parts['scheme'].':' : '')
		.(!empty($parts['host']) ? '//' : '')
		.(!empty($parts['user']) ? $parts['user']
			.(!empty($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '')
		.($parts['host'] ?? '')
		.(!empty($parts['port']) ? ':'.$parts['port'] : '')
		.($parts['path'] ?? '')
		.(!empty($parts['query']) ? '?'.$parts['query'] : '')
		.(!empty($parts['fragment']) ? '#'.$parts['fragment'] : '');
	return $url;
}

/*
 * --------------------------------------------------------------------
 * Common functions
 * --------------------------------------------------------------------
 */

/**
 * returns integer byte value from PHP shorthand byte notation
 *
 * @param string $val
 * @return int
 */
function wrap_return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    if (!is_numeric($last)) $val = substr($val, 0, -1);
    switch($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }
    return $val;
}

/**
 * checks if a substring from beginning or end of string matches given string
 *
 * @param string $string = check against this string
 * @param string $substring = this must be beginning of string
 * @param string $mode: begin: check from begin; end: check from end
 * @param bool true: first letters of $string match $substring
 * @deprecated, use str_starts_with(), str_ends_with()
 */
function wrap_substr($string, $substring, $mode = 'begin') {
	switch ($mode) {
	case 'begin':
		if (substr($string, 0, strlen($substring)) === $substring) return true;
		break;
	case 'end':
		if (substr($string, -strlen($substring)) === $substring) return true;
		break;
	}
	return false;
}

/**
 * keep static variables
 *
 * @param array $var the variable to change
 * @param string $key key to change
 * @param mixed $value new value
 * @param string $action what to do (init, set (default), add, prepend, append, delete)
 * @return array
 */
function wrap_static($var, $key = '', $value = NULL, $action = 'set') {
	static $data = [];
	if (!array_key_exists($var, $data)) $data[$var] = [];
	
	if ($value !== NULL) {
		switch ($action) {
		case 'init':
			$data[$var] = $value;
			break;
		case 'set':
			$data[$var][$key] = $value;
			break;
		case 'add':
			if (!array_key_exists($key, $data[$var])) $data[$var][$key] = [];
			if (is_array($value)) $data[$var][$key] = array_merge($data[$var][$key], $value);
			else $data[$var][$key][] = $value;
			break;
		case 'prepend':
			if (empty($data[$var][$key])) $data[$var][$key] = $value;
			else $data[$var][$key] = $value.$data[$var][$key];
			break;
		case 'append':
			if (empty($data[$var][$key])) $data[$var][$key] = $value;
			else $data[$var][$key] .= $value;
			break;
		case 'delete':
			unset($data[$var][$key]);
			break;
		}
	}

	if (!$key) return $data[$var];
	if (!array_key_exists($key, $data[$var])) return NULL;
	return $data[$var][$key];	
}

/** 
 * Merges Array recursively: replaces old with new keys, adds new keys
 * 
 * @param array $old			Old array
 * @param array $new			New array
 * @param bool $overwrite_with_empty (optional, if false: empty values do not overwrite existing ones)
 * @return array $merged		Merged array
 */
function wrap_array_merge($old, $new, $overwrite_with_empty = true) {
	foreach ($new as $index => $value) {
		if (is_array($value)) {
			if (!empty($old[$index])) {
				$old[$index] = wrap_array_merge($old[$index], $new[$index], $overwrite_with_empty);
			} else
				$old[$index] = $new[$index];
		} else {
			if (is_numeric($index) AND (!in_array($value, $old))) {
				// numeric keys will be appended, if new
				$old[] = $value;
			} else {
				// named keys will be replaced
				if ($overwrite_with_empty OR $value OR empty($old[$index]))
					$old[$index] = $value;
			}
		}
	}
	return $old;
}

/**
 * get list of ids and levels to show a hierarchical output
 *
 * @param array $data (indexed by ID = $values)
 * @param string $main_field_name field name of main ID (must be in $values)
 * @param mixed $top_id (optional; 'NULL' = default, int = subtree)
 * @return array $id => $level, sorted as $data is sorted
 */
function wrap_hierarchy($data, $main_field_name, $top_id = 'NULL') {
	$indexed_by_main = [];
	foreach ($data as $id => $values) {
		if (!$values[$main_field_name]) $values[$main_field_name] = 'NULL';
		$indexed_by_main[$values[$main_field_name]][$id] = $values;
	}
	if (!$indexed_by_main) return $indexed_by_main;
	return wrap_hierarchy_recursive($indexed_by_main, $top_id);
}

/**
 * read hierarchy recursively
 *
 * @param array $indexed_by_main list of main_id => $id => $values
 * @param mixed $top_id (optional; 'NULL' = default, int = subtree)
 * @param int $level
 * @return array $id => $level, sorted as $data is sorted (parts of it)
 */
function wrap_hierarchy_recursive($indexed_by_main, $top_id, $level = 0) {
	if (!array_key_exists($top_id, $indexed_by_main)) {
		wrap_error(sprintf(
			'Creating hierarchy impossible because ID %d is not part of the given list'
			, $top_id)
		);
		return [];
	}
	$keys = array_keys($indexed_by_main[$top_id]);
	foreach ($keys as $id) {
		$hierarchy[$id] = $level;
		if (!empty($indexed_by_main[$id])) {
			// += preserves keys opposed to array_merge()
			$hierarchy += wrap_hierarchy_recursive($indexed_by_main, $id, $level + 1);
		}
	}
	return $hierarchy;
}

/**
 * Creates a folder and its top folders if neccessary
 *
 * @param string $folder (may contain .. and . which will be resolved)
 * @return mixed 
 * 	bool true: folder creation was successful
 * 	array: list of folders
 */
function wrap_mkdir($folder) {
	$created = [];
	if (is_dir($folder)) return true;

	// check if open_basedir restriction is in effect
	$allowed_dirs = explode(':', ini_get('open_basedir'));
	if ($allowed_dirs) {
		$basefolders = [];
		foreach ($allowed_dirs as $dir) {
			if (substr($folder, 0, strlen($dir)) === $dir) {
				$basefolders = array_filter(explode('/', $dir), 'strlen');
				$basefolders = array_values($basefolders);
				break;
			}
		}
	}
	$parts = array_filter(explode('/', $folder), 'strlen');
	$current_folder = '';

	// get rid of .. and .
    $subfolders = [];
    foreach ($parts as $part) {
        if ($part === '.') continue;
        if ($part === '..')
            array_pop($subfolders);
        else
            $subfolders[] = $part;
    }

	foreach ($subfolders as $index => $subfolder) {
		if ($subfolder === '') continue;
		$current_folder .= '/'.$subfolder;
		if (!empty($basefolders[$index]) AND $basefolders[$index] === $subfolder) {
			// it's in open_basedir, so folder should exist and we cannot
			// test whether it exists anyways
			continue;
		}
		if (!file_exists($current_folder)) {
			$success = mkdir($current_folder);
			if (!$success) {
				wrap_error(sprintf('Unable to create folder %s.', $current_folder), E_USER_WARNING);
				return false;
			}
			$created[] = $current_folder;
		}
		// check if folder is a folder, not a file
		// might happen e. g. in caching for non-existing URLs with paths below non-existing URLs
		if (!is_dir($current_folder)) {
			wrap_error(sprintf('Unable to create folder %s.', $current_folder), E_USER_WARNING);
			return false;
		}
	}
	return $created;
}

/**
 * call a job in the background, either with job manager or directly
 *
 * @param string $url
 * @param array $data (optional, further values for _jobqueue table)
 * @return bool
 */
function wrap_job($url, $data = []) {
	$path = wrap_path('jobmanager', '', false);
	if (!$path) $path = $url;
	$data['url'] = $url;
	if (!empty($data['trigger']))
		list($status, $headers, $response) = wrap_trigger_protected_url($path, false, true, $data);
	else
		list($status, $headers, $response) = wrap_get_protected_url($path, [], 'POST', $data);
	if ($status === 200) return true;
	wrap_error(sprintf('Job with URL %s failed. (Status: %d, Headers: %s)', $url, $status, json_encode($headers)));
	return false;
}

/**
 * add scheme and hostname to URL if missing
 * look for `admin_hostname`
 *
 * @param string $url
 * @return string
 */
function wrap_job_url_base($url) {
	if (!str_starts_with($url, '/')) return $url;
	if (!wrap_setting('admin_hostname')) return wrap_setting('host_base').$url;

	$hostname = wrap_setting('admin_hostname');
	if (str_ends_with(wrap_setting('hostname'), '.local')) $hostname .= '.local';
	elseif (str_starts_with(wrap_setting('hostname'), 'dev.')) $hostname = 'dev.'.$hostname;
	elseif (str_starts_with(wrap_setting('hostname'), 'dev-')) $hostname = 'dev-'.$hostname;
	return wrap_setting('protocol').'://'.$hostname.$url;
}

/**
 * check if a job can be started
 *
 * @param string $type
 * @return array
 */
function wrap_job_check($type) {
	if (!wrap_job_page($type)) return true;
	// check if jobmanager is present; since function is called regularly,
	// do not use wrap_path() which needs a database connection
	if (!wrap_setting('jobmanager_path')) return false;
	return mod_default_make_jobmanager_check();
}

/**
 * automatically finish a job
 *
 * @param array $job
 * @param string $type
 * @param array $content
 * @return void
 */
function wrap_job_finish($job, $type, $content) {
	if (!wrap_job_page($type)) return true;
	if (!wrap_setting('jobmanager_path')) return false;

	if (!$content) {
		$content = [
			'status' => 404,
			'text' => wrap_text('not found')
		];
	}
	
	if (!empty($content['content_type']) AND $content['content_type'] === 'json')
		$content['text'] = json_decode($content['text']);
	if (!empty($_POST['job_logfile_result'])) {
		wrap_include('file', 'zzwrap');
		wrap_file_log($_POST['job_logfile_result'], 'write', [time(), $content['extra']['job'] ?? 'job', json_encode($content['data'] ?? $content['text'])]);
	}
	if (!empty($_POST['job_url_next']))
		wrap_trigger_protected_url($_POST['job_url_next'], wrap_username($job['username'] ?? '', false));
	
	mod_default_make_jobmanager_finish($job, $content['status'] ?? 200, $content['text']);
}

/**
 * is page a job page?
 *
 * @param string $type
 * @return bool
 */
function wrap_job_page($type) {
	global $zz_page;
	if ($type !== 'make') return false;
	if (empty($zz_page['db']['parameters']['job'])) return false;
	
	$path = wrap_path('jobmanager', '', false);
	if (!$path) return false; // no job manager active

	wrap_include('zzbrick_make/jobmanager', 'default');
	return true;
}

/**
 * trigger next job, independent from current/last job
 * use new hash for that
 *
 * @param string $url
 * @return void
 */
function wrap_job_next($url) {
	wrap_trigger_protected_url($url, false, true, ['regenerate_hash' => 1]);
}

/**
 * call a website in the background via http
 * https is not supported
 *
 * @param string $url
 * @return array $page
 */
function wrap_trigger_url($url) {
	$port = 80;
	if (substr($url, 0, 1) === '/') {
		global $zz_page;
		$host = $zz_page['url']['full']['host'];
		$path = $url;
	} else {
		$parsed = parse_url($url);
		if ($parsed['scheme'] !== 'http') {
			$page['status'] = 503;
			$page['text'] = sprintf('Scheme %s not supported.', wrap_html_escape($parsed['scheme']));
			return $page;
		}
		if ($parsed['user'] OR $parsed['pass']) {
			$page['status'] = 503;
			$page['text'] = 'Authentication not supported.';
			return $page;
		}
		if ($parsed['port']) $port = $parsed['port'];
		$host = $parsed['host'];
		$path = $parsed['path'].($path['query'] ? '?'.$path['query'] : '');
	}
	$fp = fsockopen($host, $port);
	if ($fp === false) {
		$page['status'] = 503;
		$page['text'] = sprintf('Connection to server %s failed.', wrap_html_escape($host));
		return $page;
	}
	$out = "GET ".$path." HTTP/1.1\r\n";
	$out .= "Host: ".$host."\r\n";
	$out .= "Connection: Close\r\n\r\n";
	// @todo retry if 503 error in 10 seconds
	fwrite($fp, $out);
	// read at least one byte because some servers won't establish a connection
	// otherwise
	fread($fp, 1);
	fclose($fp);
	$page['text'] = 'Connection successful.';
	return $page;
}

/**
 * trigger a protected URL
 *
 * @param string $url
 * @param string $username (optional)
 * @param bool $send_lock defaults to true, send lock hash to child process
 * @param array $data (optional)
 * @return array from wrap_syndication_retrieve_via_http()
 */
function wrap_trigger_protected_url($url, $username = false, $send_lock = true, $data = []) {
	$username = wrap_username($username, false);
	if (wrap_setting('log_trigger')) {
		$logfile = wrap_setting('log_trigger') === true ? '' : wrap_setting('log_trigger');
		wrap_log(
			sprintf('trigger URL %s %s -> %s', date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']), wrap_setting('request_uri'), $url)
			, E_USER_NOTICE, $logfile
		);
	}
	$headers[] = 'X-Timeout-Ignore: 1';
	if (function_exists('wrap_lock_hash') AND $send_lock) {
		$headers[] = sprintf('X-Lock-Hash: %s', wrap_lock_hash($data['regnerate_hash'] ?? false));
	}
	return wrap_get_protected_url($url, $headers, 'POST', $data, $username);
}

/**
 * get a protected URL
 *
 * settings: login_key, login_key_validity_in_minutes must be set
 * @param string $url
 * @param array $headers
 * @param string $method
 * @param array $data
 * @param string $username (optional)
 * @return array from wrap_syndication_retrieve_via_http()
 */

function wrap_get_protected_url($url, $headers = [], $method = 'GET', $data = [], $username = false) {
	$username = wrap_username($username, false);
	$pwd = sprintf('%s:%s', $username, wrap_password_token($username));
	$headers[] = 'X-Request-WWW-Authentication: 1';
	// localhost: JSON
	if (wrap_get_protected_url_local($url) AND wrap_get_protected_url_html($url))
		$headers[] = 'Accept: application/json';
	$url = wrap_job_url_base($url);

	require_once __DIR__.'/syndication.inc.php';
	$result = wrap_syndication_retrieve_via_http($url, $headers, $method, $data, $pwd);
	return $result;
}

/**
 * is URL on a local or admin server?
 *
 * @param string $url
 * @return bool true: local or admin server, false: remote server
 */
function wrap_get_protected_url_local($url) {
	if (str_starts_with($url, '/')) return true;
	if (str_starts_with($url, wrap_setting('host_base'))) return true;
	if (!wrap_setting('admin_hostname')) return false;
	if (str_starts_with($url, wrap_setting('protocol').'://'.wrap_setting('admin_hostname').'/')) return true;
	if (str_starts_with($url, wrap_setting('protocol').'://dev.'.wrap_setting('admin_hostname').'/')) return true;
	if (str_starts_with($url, wrap_setting('protocol').'://'.wrap_setting('admin_hostname').'.local/')) return true;
	return false;		
}

/**
 * is URL most likely a HTML resource? check ending for that
 *
 * @param string $url
 * @return bool true: probably is HTML, false: no HTML
 */
function wrap_get_protected_url_html($url) {
	$path = parse_url($url, PHP_URL_PATH);
	if (!$path) return true; // homepage
	if (str_ends_with($path, '/')) return true;
	if (str_ends_with($path, '.html')) return true;
	if (str_ends_with($path, '.htm')) return true;
	$path = explode('/', $path);
	if (!strstr(end($path), '.')) return true;
	return false;
}

/**
 * check if a number is an integer or a string with an integer in it
 *
 * @param mixed $var
 * @return bool
 */
function wrap_is_int($var) {
	if (!is_numeric($var)) return false;
	$i = intval($var);
	if ("$i" === "$var") {
  		return true;
	} else {
    	return false;
    }
}

/**
 * get additional page IDs for menu hierarchy
 *
 * @param string $area
 * @param array $paths (optional)
 * @param string $setting_key (optional, defaults to category=)
 * @return array
 */
function wrap_menu_hierarchy($area, $paths = [], $setting_key = '') {
	sort($paths);
	$setting = sprintf('%s_page_id[%s]', $area, implode(';', $paths));
	if ($id = wrap_setting($setting)) return $id;
	
	// get brick
	$cfg = wrap_cfg_files('settings');
	if (empty($cfg[$area.'_path']['brick'])) return false;
	$block = $cfg[$area.'_path']['brick'];
	
	// get all matching pages
	$sql = 'SELECT page_id, content
		FROM /*_PREFIX_*/webpages
		WHERE content LIKE "%%\%%\%%\%% %s %%"';
	$sql = sprintf($sql, $block);
	$pages = wrap_db_fetch($sql, 'page_id');
	if (!$pages) return wrap_setting($setting, []);

	// prepare blocks for comparison
	if ($setting_key)
		foreach ($paths as $index => $path)
			$paths[$index] = sprintf('%s=%s', $setting_key, $path);
	$block = sprintf('%s %s', $block, implode(' ', $paths));

	$page_ids = [];
	foreach ($pages as $page) {
		preg_match_all('/%%%(.+?)%%%/', $page['content'], $matches);
		if (empty($matches[1])) continue;
		foreach ($matches[1] as $match_block) {
			$match = brick_blocks_match($block, $match_block);
			if (!$match) continue;
			$page_ids[] = $page['page_id'];
		}
	}
	wrap_setting_write($setting, sprintf('[%s]', implode(',', $page_ids)));
	return $page_ids;
}

/**
 * get hostname for website ID
 *
 * @param int $website_id
 * @return string
 */
function wrap_host_base($website_id) {
	static $host_bases = [];
	if (!$website_id) return ''; // probably database disconnected
	if (array_key_exists($website_id, $host_bases))
		return $host_bases[$website_id];

	$sql = 'SELECT setting_value
		FROM /*_PREFIX_*/_settings
		WHERE setting_key = "canonical_hostname"
		AND _settings.website_id = %d';
	$sql = sprintf($sql, $website_id);
	$hostname = wrap_db_fetch($sql, '', 'single value');
	if (!$hostname) {
		$hostnames = array_flip(wrap_id('websites', '', 'list'));
		if (!array_key_exists($website_id, $hostnames)) return '';
		$hostname = $hostnames[$website_id];
	}
	return $host_bases[$website_id] = sprintf('https://%s', $hostname);
}



/**
 * recursively delete folders
 *
 * @param string $folder
 */
function wrap_unlink_recursive($folder) {
	$files = array_diff(scandir($folder), ['.', '..']);
	foreach ($files as $file) {
		$path = $folder.'/'.$file;
		is_dir($path) ? wrap_unlink_recursive($path) : unlink($path);
	}
	rmdir($folder);
}

/**
 * list filetypes
 *
 * @param string $filetype read configuration values for this filetype
 * @param string $action (optional, default 'read', 'write')
 * @param array $definition new definition
 * @return 
 */
function wrap_filetypes($filetype = false, $action = 'read', $definition = []) {
	static $filetypes = [];
	if (!$filetypes) $filetypes = wrap_filetypes_init();

	switch ($action) {
	case 'read':
		if (!$filetype) return $filetypes;
		if (!array_key_exists($filetype, $filetypes)) return [];
		return $filetypes[$filetype];
	case 'read-per-extension':
	case 'check-per-extension':
		$found = [];
		foreach ($filetypes as $ftype => $filetype_def) {
			if (empty($filetype_def['extension']) AND $filetype !== $ftype) continue;
			elseif (!in_array($filetype, $filetype_def['extension'])) continue;
			$found[] = $ftype;
		}
		if (count($found) === 1) return $filetypes[$found[0]];
		if ($action === 'read-per-extension')
			wrap_error(sprintf('Cannot determine filetype by extension: `%s`', $filetype));
		break;
	case 'write':
		// @todo not yet supported
		break;
	case 'merge':
		if (!array_key_exists($filetype, $filetypes)) {
			$definition = wrap_filetypes_normalize([$filetype => $definition]);
			$filetypes[$filetype] = reset($definition);
		} else {
			$old = $filetypes[$filetype];
			foreach ($definition as $key => $value) {
				if ($key === 'filetype') continue; // never changes
				if (empty($filetypes[$filetype][$key])) {
				// add
					$filetypes[$filetype][$key] = $value;
				} elseif (is_array($filetypes[$filetype][$key])) {
				// merge, prepend new values at the beginning
					if (!is_array($value)) $value = [$value];
					$value = array_reverse($value);
					foreach ($value as $item) {
						array_unshift($filetypes[$filetype][$key], $item);
					}
					$filetypes[$filetype][$key] = array_unique($filetypes[$filetype][$key]);
					$filetypes[$filetype][$key] = array_values($filetypes[$filetype][$key]);
				} else {
				// overwrite
					$filetypes[$filetype][$key] = $value;
				}
			}
		}
		break;
	}
}

/**
 * read filetypes from filetypes.cfg, settings
 *
 * @return array
 */
function wrap_filetypes_init() {
	$filetypes = [];
	$files = wrap_collect_files('configuration/filetypes.cfg', 'modules/custom');
	foreach ($files as $filename)
		$filetypes = wrap_filetypes_add($filename, $filetypes);
	// support changes via settings, too, e. g. filetypes[m4v][multipage_thumbnail_frame] = 50
	foreach (wrap_setting('filetypes') as $filetype => $config)
		if (!array_key_exists($filetype, $filetypes))
			wrap_error(sprintf('No filetype `%s` exists.', $filetype));
		else $filetypes[$filetype] = wrap_array_merge($filetypes[$filetype], $config);
	$filetypes = wrap_filetypes_array($filetypes);
	return $filetypes;
}

/**
 * add content of file to filetypes configuration
 *
 * @param string $filename
 * @param array $filetypes
 * @return array
 */
function wrap_filetypes_add($filename, $filetypes) {
	$new_filetypes = parse_ini_file($filename, true);
	$new_filetypes = wrap_filetypes_normalize($new_filetypes);
	foreach ($new_filetypes as $filetype => $definition) {
		// add or overwrite existing definitions
		$filetypes[$filetype] = $definition;
	}
	return $filetypes;
}

/**
 * set some values for filetypes array, allow shortcuts in definition
 *
 * @param array $filetypes
 * @return array $filetypes
 *		indexed by string type
 *		string 'description'
 *		array 'mime'
 *		array 'extension'
 *		bool 'thumbnail'
 *		bool 'multipage'
 */
function wrap_filetypes_normalize($filetypes) {
	foreach ($filetypes as $type => $values) {
		$filetypes[$type]['filetype'] = $type;
		if (empty($values['mime']))
			$filetypes[$type]['mime'] = ['application/octet-stream'];
		if (empty($values['extension']))
			$filetypes[$type]['extension'] = [$type];
		if (!array_key_exists('thumbnail', $values))
			 $filetypes[$type]['thumbnail'] = 0;
		if (!array_key_exists('multipage', $values))
			 $filetypes[$type]['multipage'] = 0;
	}
	$filetypes = wrap_filetypes_array($filetypes);
	return $filetypes;
}

/**
 * make sure that some keys in wrap_filetypes() are an array
 *
 * @param array $filetypes
 * @return array
 */
function wrap_filetypes_array($filetypes) {
	$keys = [
		'mime', 'extension', 'php', 'convert'
	];
	foreach ($filetypes as $type => $values) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $values)) continue;
			if (is_array($values[$key])) continue;
			$filetypes[$type][$key] = [$values[$key]];
		}
	}
	return $filetypes;
}

/**
 * username inside system
 *
 * @param string $username (optional)
 * @param bool $add_suffix (optional)
 * @return string
 */
function wrap_username($username = '', $add_suffix = true) {
	if ($username)
		$username = $username;
	elseif (wrap_setting('log_username'))
		$username = wrap_setting('log_username');
	elseif (!empty($_SESSION['username']))
		$username = $_SESSION['username'];
	elseif (!empty($_SERVER['PHP_AUTH_USER']))
		$username = $_SERVER['PHP_AUTH_USER'];
	elseif (wrap_setting('log_username_default'))
		$username = wrap_setting('log_username_default');
	
	// suffix?
	if ($suffix = wrap_setting('log_username_suffix')) {
		// remove existing
		$suffix_end = sprintf(' (%s)', $suffix);
		if ($username AND str_ends_with($username, $suffix_end))
			$username = substr($username, 0, strlen($suffix_end));
		// add new in case there is one
		if ($username AND $add_suffix) $username = sprintf('%s (%s)', $username, $suffix);
		elseif (!$username) $username = wrap_setting('log_username_suffix');
	}

	return $username;
}

/**
 * check if a file of a given filetype can be used as webimage
 *
 * @param string $filetype
 * @return bool
 */
function wrap_webimage($filetype) {
	$def = wrap_filetypes($filetype);
	if (!$def) return false;
	if (!empty($def['php'])) return true;
	if (!empty($def['webimage'])) return true;
	return false;
}
