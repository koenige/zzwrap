<?php 

/**
 * zzwrap
 * match URLs to content
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Tests whether URL is in database (or a part of it ending with *), or a part 
 * of it with placeholders
 * 
 * @param array $zz_page
 * @return array $page
 */
function wrap_match_page($zz_page) {
	// no database connection or settings are missing
	if (!wrap_sql_query('core_pages')) wrap_quit(503);

	// no asterisk in URL
	if (!empty($zz_page['url']['full']['path']) AND strstr($zz_page['url']['full']['path'], '*')) return false;
	// sometimes, bots add second / to URL, remove and redirect
	$full_url[0]['path'] = $zz_page['url']['db'];
	$full_url[0]['placeholders'] = [];

	list($full_url, $leftovers) = wrap_match_placeholders($zz_page, $full_url);
	
	// For request, remove ending (.html, /), but not for page root
	// core_pages__fields: notation like /* _latin1='%s' */
	$identifier_template = wrap_sql_fields('core_pages');
	$data = [];
	foreach ($full_url as $i => $my_url) {
		// add prefix to find matches for * on top level, too
		$my_url['path'] = wrap_match_prefix($my_url['path'], 'add');
		$index = 0;
		$params = [];
		$replaced = [];
		$extension = pathinfo($my_url['path'], PATHINFO_EXTENSION);
		while ($my_url['path'] !== false) {
			$idf = !array_intersect($replaced, $my_url['placeholders']) 
				? wrap_match_params($my_url['path'], $replaced, $leftovers[$i] ?? []): [];
			if ($idf) {
				if ($idf['url'] === '*') break;
				$idf['url'] = wrap_match_prefix($idf['url'], 'remove');
				if ($extension AND $idf['params']) {
					$extension_idf = [
						'url' => sprintf('%s.%s', $idf['url'], $extension),
						'params' => $idf['params']
					];
					$last = array_pop($extension_idf['params']);
					$extension_idf['params'][] = pathinfo($last, PATHINFO_FILENAME);
					$data[$i + $index * count($full_url)] = $extension_idf;
					$index++;
				}
				$data[$i + $index * count($full_url)] = $idf;
			}
			list($my_url['path'], $params, $replaced) = wrap_match_cut_path($my_url['path'], $params);
			$index++;
		}
	}
	if (!$data) return false;
	ksort($data);
	// remove empty lines after sort
	foreach ($data as $index => $line)
		if (!$line) unset($data[$index]);
	
	$data = wrap_translate_url($data);

	// get all pages that would match list of identifiers
	$identifiers = [];
	foreach ($data as $index => $line) {
		$identifiers[$index] = sprintf($identifier_template, wrap_db_escape($line['url']));
		$urls[$index] = $line['url'];
	}
	$identifiers = array_unique($identifiers);
	$sql = sprintf(wrap_sql_query('core_pages'), implode(',', $identifiers));
	if (!wrap_rights('preview')) {
		$sql = wrap_edit_sql($sql, 'WHERE', wrap_sql_fields('page_live'));
	}
	$pages = wrap_db_fetch($sql, '_dummy_', 'numeric');
	if (!$pages) return false;

	// get page whith best match
	$found = false;
	foreach ($pages as $this_page) {
		// identifier starts with /, just search for rest
		$pos = array_search(substr($this_page['identifier'], 1), $urls);
		if ($found === false OR $pos < $found) {
			$found = $pos;
			$page = $this_page;
		}
	}
	if (!empty($page['parameters'])) {
		parse_str($page['parameters'], $page['parameters']);
		wrap_match_page_parameters($page['parameters']);
	} else {
		$page['parameters'] = [];
	}
	if (empty($page)) return false;

	$page['parameter'] = implode('/', $data[$found]['params']);
	$page['url'] = $data[$found]['url'];
	
	if ($page['url'] === '*') {
		// some system paths must not match *
		if (in_array($zz_page['url']['full']['path'], wrap_setting('icon_paths'))) return false;
		if (str_starts_with($zz_page['url']['full']['path'], wrap_setting('layout_path'))) return false;
		if (str_starts_with($zz_page['url']['full']['path'], wrap_setting('behaviour_path'))) return false;
	}
	return $page;
}

/**
 * add and remove prefix for URL matching
 *
 * @param string $url
 * @param string $action
 * @return string
 */
function wrap_match_prefix($url, $action) {
	if ($action === 'add') return '_prefix_/'.$url;
	if (str_starts_with($url, '_prefix_/')) return substr($url, 9);
	if (str_starts_with($url, '_prefix_')) return substr($url, 8);
	return $url;
}

/**
 * get correct parameters for page
 *
 * @param array $params
 * @param array $replaced parameters at end of query after asterisk
 * @param array $leftovers placeholder values like %year%
 * @return array
 */
function wrap_match_params($url, $replaced, $leftovers = []) {
	if (!empty($leftovers)) {
		if (!$replaced) {
			$replaced = wrap_match_params_leftovers($leftovers);
		} else {
			$new = [];
			$leftover_placed = false;
			foreach ($replaced as $value) {
				if (!in_array($value, $leftovers['before']) AND !$leftover_placed) {
					$new = array_merge($new, wrap_match_params_leftovers($leftovers));
					$leftover_placed = true;
				}
				$new[] = $value;
			}
			if (!$leftover_placed)
				$new = array_merge($new, wrap_match_params_leftovers($leftovers));
			$replaced = $new;
		}
	}
	return [
		'url' => $url,
		'params' => array_values($replaced)
	];
}

/**
 * get all leftovers with numerical index
 *
 * @param array $leftovers
 * @return array
 */
function wrap_match_params_leftovers($leftovers) {
	$data = [];
	foreach ($leftovers as $index => $leftover) {
		if (!is_numeric($index)) continue;
		$data[] = $leftover;
	}
	return $data;
}

/**
 * cut parts of URL, replace with asterisk, as long as no entry in webpages
 * table is found
 *
 * examples:
 *	/db/persons/first.last/participations
 * if not found, looks for
 *  /db/persons/first.last*
 *  /db/persons* /participations
 *  /db/persons* etc.
 * note: currently not supported are two asterisks as placeholders
 * @param string $url
 * @param array $params
 * @return array
 */
function wrap_match_cut_path($url, $params) {
	static $counter = 0;
	static $counter_end = 0;
	static $last_url = '';
	$replaced = [];
	$my_params = [];
	
	if ($url === '*') {
		// do not go on after * is reached
		return [false, $params, $params];
	} elseif ($pos = strrpos($url, '/')) {
		$new_param = rtrim(substr($url, $pos + 1), '*');
		$url = rtrim(substr($url, 0, $pos), '*').'*';
	} elseif (($url OR $url === '0' OR $url === 0)) {
		array_unshift($params, rtrim($url, '*'));
		$new_param = '';
		$url = '*';
		// do not add URLs starting with `*` here because
		// a) too many possibilities
		// b) script needs to be adapted to that
		return [$url, $params, $params];
	} else {
		$new_param = '';
		$url = false;
	}

	if ($counter) {
		if ($counter === $counter_end) {
			$counter = 0;
			$replaced = $params;
		} else {
			$url = $last_url;
			$my_params = $params;
			$replaced[-1] = array_shift($my_params);
			$asterisks = -floor($counter/count($my_params));
			$pos = $counter + ($asterisks - 1) * count($my_params);
			$index = count($my_params) + $pos - 1;
			if ($index < 0) $index = count($my_params) - 1;
			while ($asterisks) {
				$replaced[$index] = $my_params[$index];
				$my_params[$index] = '*';
				$asterisks--;
				$index--;
				if ($index < 0) $index = count($my_params) - 1;
			}
			ksort($replaced); // might be set in different order if more than one *
			$replaced = array_values($replaced);
			$counter--;
		}
	} else {
		$replaced[] = $new_param;
		array_unshift($params, $new_param);
		$my_params = $params;
		array_shift($my_params);
		if ($my_params) {
			$counter = -1;
			$counter_end = -((count($my_params) - 1) * count($my_params) + 1);
			$last_url = $url;
		}
	}

	if ($my_params) {
		$new_url = implode('/', $my_params);
		$new_url = str_replace('/*', '*', $new_url);
		$url .= '/'.$new_url;
	}

	// remove two or more adjacent asterisks
	while (strstr($url, '**'))
		$url = str_replace('**', '*', $url);
	while (strstr($url, '*/*/'))
		$url = str_replace('*/*/', '*/', $url);

	// return result
	return [$url, $params, $replaced];
}

/**
 * add page parameters to settings
 *
 * whitelist of possible parameters is generated from settings.cfg in modules
 * setting needs page_parameter = 1
 * @param string $params
 * @return bool
 */
function wrap_match_page_parameters($params) {
	if (!$params) return false;
	$cfg = wrap_cfg_files('settings');
	
	foreach ($params as $key => $value) {
		if (!array_key_exists($key, $cfg)) continue;
		if (empty($cfg[$key]['page_parameter'])) continue;
		wrap_setting($key, $value);
	}
	return true;
}

/**
 * add module parameters to settings
 *
 * whitelist of possible parameters is generated from settings.cfg in modules
 * setting needs scope = module
 * @param string $module
 * @param mixed $params
 * @param bool $reset reset values from other functions in each call
 * @return bool
 */
function wrap_match_module_parameters($module, $params, $reset = true) {
	static $unchanged = [];
	$changed = [];
	
	if (!$params) return false;
	if (!is_array($params)) {
		parse_str($params, $params);
		if (!$params) return false;
	}

	$cfg = wrap_cfg_files('settings');
	foreach ($params as $key => $value) {
		if (!array_key_exists($key, $cfg)) continue;
		if (empty($cfg[$key]['scope'])) continue;
		$scope = wrap_setting_value($cfg[$key]['scope']);
		if (!in_array($module, $scope)) continue;
		if (is_null(wrap_setting($key))) {
			$unchanged[$key] = NULL;
			wrap_setting($key, $value);
			$changed[] = $key;
		} elseif (wrap_setting($key) !== $value) {
			$unchanged[$key] = wrap_setting($key);
			wrap_setting($key, $value);
			$changed[] = $key;
		}
	}
	// multiple calls: change unchanged parameters back to original value
	if ($reset) {
		foreach (array_keys($unchanged) as $key) {
			if (in_array($key, $changed)) continue;
			wrap_setting($key, $unchanged[$key]);
		}
	}
	return true;
}

/**
 * check if there's a layout or behaviour file in one of the modules
 * then send it out
 *
 * @param array $url_path ($zz_page['url']['full']['path'])
 * @return
 */
function wrap_match_file($url_path) {
	if (!wrap_setting('modules') AND !wrap_setting('active_theme')) return false;
	if (!$url_path) return false;

	if (wrap_setting('active_theme') AND wrap_setting('icon_paths')) {
		if (in_array($url_path, wrap_setting('icon_paths'))) {
			$path = $url_path;
			if (str_starts_with($path, wrap_setting('base_path')))
				$path = substr($path, strlen(wrap_setting('base_path')));
			$file['name'] = sprintf('%s/%s%s', wrap_setting('themes_dir'), wrap_setting('active_theme'), $path);
			if (file_exists($file['name'])) {
				$file['etag_generate_md5'] = true;
				wrap_send_file($file);
			}
		}
	}

	$folders = array_merge(wrap_setting('modules'), wrap_setting('themes'));
	$folders = array_unique($folders); // themes can be identical with modules

	$paths = ['layout', 'behaviour'];
	foreach ($paths as $path) {
		if (!wrap_setting($path.'_path')) continue;
		if (!str_starts_with($url_path, wrap_setting($path.'_path'))) continue;
		$url_folders = explode('/', substr($url_path, strlen(wrap_setting($path.'_path'))));
		if (count($url_folders) < 2) continue;
		if (!in_array($url_folders[1], $folders)) continue;
		array_shift($url_folders);
		$folder = array_shift($url_folders);
		// prefer themes over modules here if name is identical
		$dir = in_array($folder, wrap_setting('themes')) ? wrap_setting('themes_dir') : wrap_setting('modules_dir');
		$file['name'] = sprintf('%s/%s/%s/%s',
			$dir, $folder, $path, implode('/', $url_folders));
		if (in_array($ext = wrap_file_extension($file['name']), ['css', 'js'])) {
			wrap_cache_allow_private();
			return $file['name'];
		}
		$file['etag_generate_md5'] = true;
		wrap_send_file($file);
	}
	return false;
}

/**
 * check for placeholders in URL
 *
 * replaces parts that match with placeholders, if necessary multiple times
 * note: twice the same fragment will only be replaced once, not both fragments
 * at the same time (e. g. /eng/eng/ is /%language%/eng/ and /eng/%language%/
 * but not /%language%/%language%/ because this would not make sense) 
 * @param array $zz_page
 * @param array $full_url
 * @return array (array $full_url, array $leftovers)
 */
function wrap_match_placeholders($zz_page, $full_url) {
	if (empty($zz_page['url_placeholders'])) return [$full_url, []];
	// cut url in parts
	$url_parts[0] = explode('/', $full_url[0]['path']);
	$i = 1;
	$leftovers = [];
	foreach ($zz_page['url_placeholders'] as $wildcard => $values) {
		foreach (array_keys($values) as $key) {
			foreach ($url_parts as $url_index => $parts) {
				foreach ($parts as $partkey => $part) {
					if ($part != $key) continue;
					// new URL parts, take the one that we match on as basis
					$url_parts[$i] = $url_parts[$url_index];
					// leftovers, get the ones as a basis we already have							
					if (!empty($leftovers[$url_index]))
						$leftovers[$i] = $leftovers[$url_index];
					// take current part and put it into leftovers
					$leftovers[$i][$partkey] = $url_parts[$i][$partkey];
					$leftovers[$i]['before'] = array_slice($url_parts[0], 0, $partkey);
					$leftovers[$i]['after'] = array_slice($url_parts[0], $partkey + 1);
					// overwrite current part with placeholder
					$url_parts[$i][$partkey] = '%'.$wildcard.'%';
					$full_url[$i]['path'] = implode('/', $url_parts[$i]);
					if ($full_url[$url_index]['placeholders'])
						$full_url[$i]['placeholders'] = $full_url[$url_index]['placeholders'];
					$full_url[$i]['placeholders'][] = $url_parts[$i][$partkey];
					$i++;
				}
			}
		}
	}
	return [$full_url, $leftovers];
}

/**
 * check for redirects, if there's a corresponding table.
 *
 * @param array $page_url = $zz_page['url']
 * @global array $zz_page
 * @return mixed (bool false: no redirect; array: fields needed for redirect)
 */
function wrap_match_redirects($page_url) {
	global $zz_page;

	if (!wrap_setting('check_redirects')) return false;
	$where_language = (!empty($_GET['lang']) AND !is_array($_GET['lang']))
		? sprintf(' OR %s = "/%s.html.%s"', wrap_sql_fields('core_redirects_old_url')
			, wrap_db_escape($zz_page['url']['db']), wrap_db_escape($_GET['lang']))
		: '';
	$sql = sprintf(wrap_sql_query('core_redirects')
		, '/'.wrap_db_escape($zz_page['url']['db'])
		, '/'.wrap_db_escape($zz_page['url']['db'])
		, '/'.wrap_db_escape($zz_page['url']['db']), $where_language
	);
	// not needed anymore, but set to false hinders from getting into a loop
	// (wrap_db_fetch() will call wrap_quit() if table does not exist)
	wrap_setting('check_redirects', false); 
	$redir = wrap_db_fetch($sql);
	if ($redir) return $redir;

	// check full URL with query strings or ending for migration from a different CMS
	$check = $zz_page['url']['full']['path'].(!empty($zz_page['url']['full']['query']) ? '?'.$zz_page['url']['full']['query'] : '');
	$check = wrap_db_escape($check);
	$sql = sprintf(wrap_sql_query('core_redirects'), $check, $check, $check, $where_language);
	$redir = wrap_db_fetch($sql);
	if ($redir) return $redir;

	// If no redirect was found until now, check if there's a redirect above
	// the current level with a placeholder (*)
	$redir = wrap_match_redirects_placeholder($zz_page['url'], 'behind');
	if ($redir) return $redir;
	$redir = wrap_match_redirects_placeholder($zz_page['url'], 'before');
	if ($redir) return $redir;
	return false;
}

/**
 * check for redirects with placeholder
 *
 * @param array $url
 * @param string $position
 * @return mixed
 */
function wrap_match_redirects_placeholder($url, $position) {
	$redir = false;
	$parameter = false;
	$found = false;
	$break_next = false;
	$separators = ['/', '-', '.'];

	switch ($position) {
	case 'before':
		$r_query = 'core_redirects*_';
		break;
	case 'behind':
		$r_query = 'core_redirects_*';
		break;
	}

	while (!$found) {
		$current_path = sprintf('/%s', wrap_db_escape($url['db']));
		$sql = sprintf(wrap_sql_query($r_query), $current_path);
		$redir = wrap_db_fetch($sql);
		if ($redir) break; // we have a result, get out of this loop!
		$last_pos = 0;
		if ($position === 'before') {
			foreach ($separators as $separator) {
				$pos = strpos($url['db'], $separator);
				if ($pos > $last_pos) {
					$last_pos = $pos;
					$last_separator = $separator;
				}
			}
			if ($last_pos) {
				$parameter .= substr($url['db'], 0, $last_pos + 1);
			}
			$url['db'] = substr($url['db'], $last_pos + 1);
		} else {
			foreach ($separators as $separator) {
				$pos = strrpos($url['db'], $separator);
				if ($pos > $last_pos) {
					$last_pos = $pos;
					$last_separator = $separator;
				}
			}
			if ($last_pos) {
				$parameter = substr($url['db'], $last_pos).$parameter;
			}
			$url['db'] = substr($url['db'], 0, $last_pos);
		}
		if ($break_next) break; // last round
		if (!strstr($url['db'], '/')) $break_next = true;
	}
	if (!$redir) return false;

	// parameters starting with - will be changed to start with /
	if (empty($last_separator)) $last_separator = '/'; // default
	elseif ($last_separator === '-') $last_separator = '/';
	// If there's an asterisk (*) at the end of the redirect
	// the cut part will be pasted to the end of the string
	$field_name = wrap_sql_fields('core_redirects_new_url');
	if (substr($redir[$field_name], -1) === '*') {
		$parameter = substr($parameter, 1);
		$redir[$field_name] = substr($redir[$field_name], 0, -1).$last_separator.$parameter;
	} elseif (substr($redir[$field_name], 0, 1) === '*') {
		$parameter = substr($parameter, 0, -1);
		$redir[$field_name] = $last_separator.$parameter.substr($redir[$field_name], 1);
	}
	return $redir;
}

/**
 * redirect to URL if it's a known error in adding space or quotes to URL
 * and a corresponding cache file exists
 *
 * @param array $page
 * @param array $url
 * @return array $page
 */
function wrap_match_redirects_from_cache($page, $url) {
	// %E2%80%8B = zero width space, sometimes added to URL from some systems
	$redirect_endings = [
		'%20', ')', '%5C', '%22', '%3E', '.', '%E2%80%8B', '%C2%A0', ';', '!'
	];
	foreach ($redirect_endings as $ending) {
		if (substr($url['path'], -strlen($ending)) !== $ending) continue;
		$url['path'] = substr($url['path'], 0, -strlen($ending));
		$new_url = wrap_glue_url($url);
		$cache = wrap_cache_filenames($new_url);
		if (!wrap_cache_exists($cache)) continue;
		$page['status'] = 307;
		$page['redirect'] = $new_url;
		break;
	}
	return $page;
}

/**
 * if page is not found, after all files are included,
 * check 1. well known URLs, 2. template files, 3. redirects
 *
 * @param array $zz_page
 * @param bool $quit (optional) true: call wrap_quit(), false: just return
 * @return array
 */
function wrap_match_ressource($zz_page, $quit = true) {
	$well_known = wrap_match_well_known($zz_page['url']['full']);
	if ($well_known) {
		$zz_page['well_known'] = $well_known;
	} else {
		$zz_page['tpl_file'] = wrap_match_file($zz_page['url']['full']['path']);
		if (!$zz_page['tpl_file'] AND $quit) wrap_quit();
		$languagecheck = wrap_url_language();
		if (!$languagecheck AND $quit) wrap_quit();
		if (!empty($_GET)) {
			$cacheable = ['lang'];
			foreach (array_keys($_GET) as $key) {
				if (in_array($key, $cacheable)) continue;
				wrap_setting('cache', false);
				break;
			}
		}
	}
	return $zz_page;
}

/**
 * support some standard URLs if there’s no entry in webpages table for them
 *
 * @param array $url
 * @return mixed false: nothing found, array: $page
 */
function wrap_match_well_known($url) {
	switch ($url['path']) {
	case '/robots.txt':
		$page['content_type'] = 'txt';
		$page['text'] = '# robots.txt for '.wrap_setting('site');
		$page['status'] = 200;
		return $page;
	case '/.well-known/change-password':
		if (!$path = wrap_domain_path('change_password')) return false;
		wrap_redirect_change($path);
	}
	return false;
}
