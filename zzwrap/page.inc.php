<?php 

/**
 * zzwrap
 * Standard page functions (menu, breadcrumbs, authors, page)
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 *	wrap_page_authors()				-- gets authors from database
 *	wrap_htmlout_page()				-- outputs webpage from %%%-template in HTML
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Creates valid HTML id value from string
 * must match [A-Za-z][-A-Za-z0-9_:.]* (HTML 4.01)
 * here we say only lowercase, only underscore besides letters and numbers
 * Note: it's better to save an ID in the database while creating a record
 *
 * @param string $id_title string to be formatted
 * @return string $id_title
 */
function wrap_create_id($id_title) {
	$id_title = strtolower($id_title);
	$id_title = wrap_filename($id_title);
	if (is_numeric(substr($id_title, 0, 1))) {
		// add a random letter, first letter must not be numeric
		$id_title = 'n_'.$id_title;
	}
	return $id_title;
}


//
//	authors
//

/**
 * Reads authors from database, adds initials and gives back array
 * 
 * @param array $brick_authors IDs of authors
 * @param int $author_id extra ID of author, may be false
 * @return array authors, person = name, initials = initials, lowercase
 */
function wrap_page_authors($brick_authors, $author_id = false) {
	if (!($sql = wrap_sql_query('page_authors'))) return false;

	// add to extra page author to authors from brick_format()
	if ($author_id) $brick_authors[] = $author_id;
	
	$authors = wrap_db_fetch(sprintf($sql, implode(', ', $brick_authors)), 'person_id');

	if ($authors) {
		foreach ($authors as $index => $author) {
			$nameparts = explode(' ', strtolower($author['person']));
			$authors[$index]['initials'] = '';
			foreach ($nameparts as $part) {
				$authors[$index]['initials'] .= substr($part, 0, 1);
			}
		}
	}
	return $authors;
}

/**
 * get last update date
 *
 * @param array $page
 * @global array $zz_page
 * @return string
 */
function wrap_page_last_update($page) {
	global $zz_page;
	$last_update = $page['last_update'] ?? '';
	if (!$last_update AND !empty($zz_page['db']))
		$last_update = $zz_page['db'][wrap_sql_fields('page_last_update')];
	return wrap_date($last_update);
}

/**
 * get page media
 * 
 * @param array $page
 * @global array $zz_page
 * @return array
 */
function wrap_page_media($page) {
	global $zz_page;
	$media = $page['media'] ?? [];
	$page_id = $zz_page['db'][wrap_sql_fields('page_id')] ?? false;
	if (!$page_id) return $media;
	if (!function_exists('wrap_get_media')) return $media;
	$media = array_merge(wrap_get_media($page_id), $media);
	return $media;
}

/**
 * get page main H1 element; default: from brick script, 2nd choice: database
 * get value for HTML title element
 * 
 * @param array $page
 * @global array $zz_page
 * @return array
 */
function wrap_page_title($page) {
	global $zz_page;

	if (empty($page['title'])) {
		if (!empty($zz_page['db']) AND empty($page['error_no_content']))
			$page['title'] = $zz_page['db'][wrap_sql_fields('page_title')];
		else {
			$status = wrap_http_status_list($page['status']);
			$page['title'] = $status['text'];
		}
	}
	if (wrap_setting('translate_page_title') OR !empty($status))
		$page['title'] = wrap_text($page['title'], ['ignore_missing_translation' => true]);

	if (!empty($zz_page['db']) AND $zz_page['url']['full']['path'] === '/' AND empty($page['extra']['not_home'])) {
		$page['pagetitle'] = strip_tags($zz_page['db'][wrap_sql_fields('page_title')]);
		$page['pagetitle'] = sprintf(wrap_setting('template_pagetitle_home'), $page['pagetitle'], $page['project']);
	} else {
		$page['pagetitle'] = strip_tags($page['title']);
		if (wrap_setting('page_subtitle'))
			$page['pagetitle'] .= wrap_setting('page_subtitle_concat').wrap_setting('page_subtitle');
		if (!empty($status))
			$page['pagetitle'] = $page['status'].' '.$page['pagetitle'];
		$page['pagetitle'] = sprintf(wrap_setting('template_pagetitle'), $page['pagetitle'], $page['project']);
	}
	return $page;
}

/**
 * checks whether there's a reason to send an error back to the visitor
 * 
 * @param array $page
 * @param string $scope (optional)
 * @return bool true if everything is okay
 */
function wrap_page_check_if_error($page, $scope = 'page') {
	if (empty($page)) wrap_quit();

	if (!empty($page['error']['level'])) {
		if (!empty($page['error']['msg_text']) AND !empty($page['error']['msg_vars'])) {
			$msg = wrap_text($page['error']['msg_text'], ['values' => $page['error']['msg_vars']]);
		} elseif (!empty($page['error']['msg_text'])) {
			$msg = wrap_text($page['error']['msg_text']);
		} else {
			$msg = wrap_text('zzbrick returned with an error. Sorry, that’s all we know.');
		}
		wrap_error($msg, $page['error']['level']);
	} elseif ($page['status'] != 200) {
		if ($scope === 'template' AND wrap_setting('current_template') === '(from variable)'
			AND $page['status'] === 403) {
			// allow 403 from variables that are used as templates, i. e. only hidden results
			$page['status'] = 200;
		} elseif ($scope === 'template') {
			wrap_error(wrap_text(
				'An error occurred while filling the template %s. Status code %d',
				['values' => [wrap_setting('current_template'), $page['status']]]
			), E_USER_WARNING);
		} elseif (!in_array($page['status'], [403, 404, 410])) {
			wrap_error(wrap_text(
				'An error occurred while creating the ressource %s. Status code %d',
				['values' => [wrap_setting('request_uri'), $page['status']]]
			), E_USER_WARNING);
		}
	}
	if ($page['status'] != 200) {
		wrap_quit($page['status'], '', $page);
		exit;
	}
	return true;
}

/**
 * puzzle page elements together
 *
 * @global array $zz_page
 */
function wrap_get_page() {
	global $zz_page;
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$zz_page['url'] = wrap_url_canonical_hostname_check($zz_page['url']);
		// redirect will kill POST data
		// this is a feature, since a POST from a non-accessible URL is not possible
		wrap_url_canonical_redirect($zz_page['url']);
	}

	if (!empty($_POST['httpRequest']) AND is_array($_POST['httpRequest'])) {
		$page['status'] = 400;
		$page['error']['level'] = E_USER_NOTICE;
		$page['error']['msg_text'] = 'Illegal value for XML HTTP request: %s';
		$page['error']['msg_vars'] = [json_encode($_POST['httpRequest'])];
	} elseif (!empty($_POST['httpRequest']) AND substr($_POST['httpRequest'], 0, 6) !== 'zzform') {
		$page = brick_xhr($_POST, $zz_page['db']['parameter']);
		$page['url_ending'] = 'ignore';
	} elseif (array_key_exists('well_known', $zz_page)) {
		$page = $zz_page['well_known'];
	} elseif (array_key_exists('tpl_file', $zz_page)) {
		$page = wrap_page_from_file($zz_page['tpl_file']);
	} else {
		$page = brick_format($zz_page['db'][wrap_sql_fields('page_content')], $zz_page['db']['parameter']);
	}
	wrap_page_check_if_error($page);

	if (!empty($page['no_output'])) exit;

	$zz_page['url'] = wrap_url_canonical($zz_page, $page);
	wrap_url_canonical_redirect($zz_page['url']);

	$page['media']		= wrap_page_media($page);
	$page[wrap_sql_fields('page_last_update')] = wrap_page_last_update($page);
	if (!empty($zz_page['db'][wrap_sql_fields('page_author_id')]) AND !empty($page['authors']))
		$page['authors'] = wrap_page_authors($page['authors'], $zz_page['db'][wrap_sql_fields('page_author_id')]);

	return $page;
}

/**
 * set some page defaults
 *
 * @param array $page
 * @return array
 */
function wrap_page_defaults($page) {
	!empty($page['project']) OR $page['project'] = wrap_text(wrap_setting('project'), ['ignore_missing_translation' => true]);
	!empty($page['status']) OR $page['status'] = 200;
	!empty($page['lang']) OR $page['lang'] = wrap_setting('lang');
	$page = wrap_page_title($page);
	return $page;
}

/**
 * send page content from file in filesystem
 * treat as template
 * 
 * @param string $filename
 * @return array
 */
function wrap_page_from_file($filename) {
	$page['text'] = wrap_template($filename);
	if (!$page['text']) wrap_quit(404);
	$page['content_type'] = wrap_file_extension($filename);
	wrap_setting('character_set', wrap_detect_encoding($page['text']));
	$page['status'] = 200;
	$page['query_strings'][] = 'v';
	$page['query_strings'][] = 'nocache';
	
	// check filename if it has version no. in it = extend cache to a year
	// check for 1.0, 1.1.0 etc.
	$basename = basename($filename);
	if (preg_match('/\b\d+(?:\.\d+){1,3}\b/', $basename, $matches))
		$page['headers']['cache_max_age'] = wrap_setting('page_cache_age_from_file_with_version');
	return $page;
}

/**
 * Redirects to another URL
 * 
 * @param string $location URL to redirect to
 * @param int $status (defaults to 302)
 * @param bool $cache cache redirect, defaults to true
 */
function wrap_redirect($location = false, $status = 302, $cache = true) {
	wrap_http_status_header($status);
	$header = sprintf('Location: %s', wrap_url_expand($location));
	wrap_setting_add('headers', $header);
	if ($cache AND wrap_setting('cache')) {
		// provide cache URL since internal URL might already be rewritten
		wrap_cache('', '', wrap_setting('host_base').wrap_setting('request_uri'));
	}
	wrap_log_uri($status);
	wrap_cache_header();
	header($header);
	exit;
}

/**
 * Redirects after something was POSTed
 * won‘t be cached
 * 
 * @param string $url (default = own URL)
 */
function wrap_redirect_change($url = false) {
	return wrap_redirect($url, 303, false);
}

/**
 * Outputs a HTML page from a %%%-template
 * 
 * allow %%% page ... %%%-syntax
 * @param array $page
 * @return string HTML output
 */
function wrap_htmlout_page($page) {
	global $zz_page;

	if (wrap_setting('send_as_json')) {
		$page['text'] = wrap_page_json($page);
		$page['content_type_original'] = $page['content_type'];
		$page['content_type'] = 'json';
	}

	if (!empty($page['content_type']) AND $page['content_type'] !== 'html') {
		$page = wrap_page_replace($page);
		wrap_send_text($page['text'], $page['content_type'], $page['status'], $page['headers'] ?? []);
		exit;
	}
	
	// if globally dont_show_h1 is set, don't show it
	if (wrap_setting('dont_show_h1')) $page['dont_show_h1'] = true;
	$dont_show_h1 = $page['dont_show_h1'] ?? false;
	if (wrap_setting('h1_via_template')) $dont_show_h1 = true;

	if (!isset($page['text'])) $page['text'] = '';
	// init page
	if (file_exists($file = wrap_setting('custom').'/zzbrick_page/_init.inc.php'))
		require_once $file;
	wrap_page_extra($page, $zz_page);
	if (empty($page['description']))
		$page['description'] = $zz_page['db']['description'] ?? '';

	// bring together page output
	// do not modify html, since this is a template
	wrap_setting('brick_fulltextformat', 'brick_textformat_html');

	// Use different template if set in function or _init
	if (!empty($page['template'])) {
		if (str_starts_with($page['template'], '/')) {
			$tpl_file = file_exists($page['template']) ? true : false;
		} else {
			if (substr($page['template'], -5) !== '-page')
				$page['template'] .= '-page';
			$tpl_file = wrap_template_file($page['template'], false);
		}
		if ($tpl_file) wrap_setting('template', $page['template']);
	}
	
	$blocks = wrap_check_blocks(wrap_setting('template'));
	if (in_array('breadcrumbs', $blocks) OR wrap_setting('breadcrumbs_h1_prefix')) {
		require_once __DIR__.'/nav.inc.php';
		list($page['breadcrumbs'], $page['breadcrumbs_h1_prefix']) = wrap_breadcrumbs($page);
	}
	$page['link'] = wrap_page_sequential($page['link'] ?? []);
	if (in_array('nav', $blocks) AND wrap_db_connection()) {
		// get menus, if database connection active
		require_once __DIR__.'/nav.inc.php';
		$page = wrap_menu_get($page);
		if (!empty($page['nav_db'])) {
			$page['nav'] = wrap_menu_out($page['nav_db']);
			foreach (array_keys($page['nav_db']) AS $menu) {
				$page['nav_'.$menu] = wrap_menu_out($page['nav_db'], $menu);
			}
		}
	}

	if (!is_array($page['text'])) $textblocks = ['text' => $page['text']];
	else $textblocks = $page['text'];
	unset($page['text']);
	foreach ($textblocks as $position => $text) {
		// add title to page, main text block
		if ($position === 'text') {
			if (!$dont_show_h1 AND !empty($page['title']))
				$text = wrap_template('h1', $page).$text;
			elseif (!empty($page['breadcrumbs_h1_prefix']))
				$text = wrap_page_h1_prefix($text, $page['breadcrumbs_h1_prefix']);
		}
		// do not overwrite other keys
		if ($position !== 'text') $position = 'text_'.$position;
		// allow return of %%% encoding for later decoding, e. g. for image
		if (strstr($text, '<p>%%%') AND strstr($text, '%%%</p>')) {
			$text = str_replace('<p>%%%', '%%%', $text);
			$text = str_replace('%%%</p>', '%%%', $text);
		}
		$output = brick_format($text, $page);
		if (array_key_exists($position, $page)) {
			$page[$position] .= $output['text'];
		} else {
			$page[$position] = $output['text'];
		}
		if (isset($output['media']) AND $output['media'] !== [])
			$page['media'] = $output['media'];
	}
	if (!empty($page['text']) AND is_array($page['text'])) {
		// positions?
		foreach ($page['text'] as $position => $text) {
			if ($position !== 'text') $position = 'text_'.$position;
			if (array_key_exists($position, $page)) {
				$page[$position] .= $text;
			} else {
				$page[$position] = $text;
			}
		}
	}
	if (!empty($zz_page['error_msg']) AND $page['status'] == 200) {
		// show error message in case there is one and it's not already shown
		// by wrap_errorpage() (status != 200)
		if (empty($page['text'])) $page['text'] = '';
		$page['text'] .= $zz_page['error_msg']."\n";
	}
	
	$page = wrap_page_replace($page);
	$page = brick_head_format($page, true);
	$text = wrap_template(wrap_setting('template'), $page);

	// allow %%% notation on page with an escaping backslash
	// but do not replace this in textareas because editing needs to be possible
	if (strstr($text, '<textarea')) {
		$text = preg_split("/(<[\/]?textarea)/", $text);
		$i = 0;
		foreach (array_keys($text) as $index) {
			if ($i & 1) {
				// allow to remove escaping if wanted
				if (strstr($text[$index], 'data-noescape="1"'))
					$text[$index] = str_replace('%\%\%', '%%%', $text[$index]);
				$text[$index] = '<textarea'.$text[$index].'</textarea';
			} else {
				$text[$index] = str_replace('%\%\%', '%%%', $text[$index]);
			}
			$i++;
		}
		$text = implode('', $text);
	} else {
		$text = str_replace('%\%\%', '%%%', $text);
	}
	if (!empty($page['send_as_json'])) {
		// @deprecated, check if wrap_setting('send_as_json') is an option
		wrap_send_text(wrap_page_json($page, $text), 'json', $page['status']);
		exit;
	}

	wrap_send_text($text, 'html', $page['status']);
}

/**
 * send page as JSON
 *
 * @param array $page
 * @param string $text (optional)
 * @return string
 */
function wrap_page_json($page, $text = NULL) {
	$content = $page['content_type_original'] ?? 'html';
	$output = [
		$content => $text ?? $page['text']['text'] ?? $page['text'],
		'title' => $page['pagetitle'],
		'url' => $page['url'] ?? wrap_setting('request_uri')
	];
	if (!empty($page['data']))
		foreach ($page['data'] as $key => $data)
			$output[$key] = $data;
	$json = json_encode($output);
	if ($output and !$json) {
		$log_dir = sprintf('%s/zzwrap', wrap_setting('log_dir'));
		if (!file_exists($log_dir)) wrap_mkdir($log_dir);
		file_put_contents(sprintf('%s/json-error-%s.log', $log_dir, time()), $output);
		if (json_last_error() === JSON_ERROR_UTF8) {
			$output = wrap_convert_encoding($output, 'UTF-8');
			$json = json_encode($output);
			if ($json) return $json;
		}
		wrap_quit(503, 'JSON error: '.json_last_error_msg());
	}
	return $json;
}

/**
 * write some keys from webpages.parameters to $page['extra']
 *
 * @param array $page
 * @param array $zz_page
 * @return array
 */
function wrap_page_extra(&$page, $zz_page) {
	// check webpages.parameters
	if (!empty($zz_page['db']['parameters'])) {
		foreach (wrap_setting('page_extra_parameters') as $key) {
			if (!array_key_exists($key, $zz_page['db']['parameters'])) continue;
			$page['extra'][$key] = $zz_page['db']['parameters'][$key];
			$page['extra_'.$key] = is_array($page['extra'][$key]) ? true : $page['extra'][$key];
		}
	}
	
	// check extra, write to extra_body_attributes
	if (empty($page['extra'])) return true;
	$attributes = [];
	if (!empty($page['extra_body_attributes'])) {
		// deprecated
		preg_match_all('/([a-z]+)="([^"]+)"/', $page['extra_body_attributes'], $matches);
		if (!empty($matches[1]) AND !empty($matches[2])) {
			foreach ($matches[1] as $index => $key)
				$attributes[$key][] = $matches[2][$index];
		}
	}
	foreach (wrap_setting('page_extra_attributes') as $key) {
		if (!array_key_exists($key, $page['extra'])) continue;
		if (is_array($page['extra'][$key]))
			$attributes[$key] = array_merge($attributes[$key], $page['extra'][$key]);
		else
			$attributes[$key][] = $page['extra'][$key];
	}
	$page['extra_body_attributes'] = [];
	foreach ($attributes as $key => $values)
		$page['extra_body_attributes'][] = sprintf('%s="%s"', $key, implode(' ', $values));
	$page['extra_body_attributes'] = ' '.implode(' ', $page['extra_body_attributes']);
	return true;
}

/**
 * check which block exist in template
 *
 * @param string $template name of template
 * @return array
 */
function wrap_check_blocks($template) {
	static $includes = [];

	$file = str_starts_with($template, '/') ? $template : wrap_template_file($template);
	if (!$file) return [];
	$file = file_get_contents($file);
	$blocks = [];
	if (strstr($file, '%%% page breadcrumbs')) $blocks[] = 'breadcrumbs';
	if (strstr($file, '%%% page nav')) $blocks[] = 'nav';
	if ($block = strstr($file, '%%% include')) {
		$block = substr($block, 11);
		$block = trim(substr($block, 0, strpos($block, '%%%')));
		if (in_array($block, $includes)) {
			wrap_error(sprintf('Template %s includes itself', $block), E_USER_ERROR);
		} else {
			$includes[] = $block;
			$blocks = array_merge($blocks, wrap_check_blocks($block));
		}
	}
	return $blocks;
}

/**
 * returns random hash to use as a short identifier
 *
 * @param int $length
 * @param string $charset (optional)
 * @return string
 */
function wrap_random_hash($length, $charset='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789') {
    $str = '';
    $count = strlen($charset) - 1;
    while ($length--) {
        $str .= $charset[mt_rand(0, $count)];
    }
    return $str;
}

/**
 * Get previous and next record in a list of records
 *
 * @param array $records all records, indexed by ID
 * @param int $record_id current record ID
 * @param bool $endless true: endless navigation; false: start to end
 * @return array
 *		array data of previous record
 *		array data of next record
 */
function wrap_get_prevnext($records, $record_id, $endless = true) {
	// remove non-numerical record indexes
	foreach (array_keys($records) as $index) {
		if (is_int($index)) continue;
		unset($records[$index]);
	}
	$keys = array_keys($records);
	$pos = array_search($record_id, $keys);
	if ($pos === false) return [0 => [], 1 => []];
	$prev = $pos - 1;
	if ($prev >= 0) {
		$return[0] = $records[$keys[$prev]];
	} else {
		if ($endless) $return[0] = $records[$keys[count($records) - 1]];
		else $return[0] = [];
	}
	$next = $pos + 1;
	if ($next < count($records)) {
		$return[1] = $records[$keys[$next]];
	} else {
		if ($endless) $return[1] = $records[$keys[0]];
		else $return[1] = [];
	}
	return $return;
}

/**
 * Get previous and next records with all values prefixed by _next, _prev
 *
 * @param array $records all records, indexed by ID
 * @param int $record_id current record ID
 * @param bool $endless true: endless navigation; false: start to end
 * @return array
 */
function wrap_get_prevnext_flat($records, $record_id, $endless = true) {
	list($prev, $next) = wrap_get_prevnext($records, $record_id, $endless);
	if (!$prev AND !$next) return [];
	$return = [];
	foreach ($prev as $key => $value) {
		$return['_prev_'.$key] = $value;
	}
	foreach ($next as $key => $value) {
		$return['_next_'.$key] = $value;
	}
	return $return;
}

/**
 * set $page['link'] for single item
 *
 * @param array $data (_next_identifier, _next_title, _prev_identifier, _prev_title,
 *		_main_identifier or identifier, _main_title)
 * @param string $path
 * @param string $path_overview
 * @return array
 */
function wrap_page_links($data, $path = false, $path_overview = false) {
	global $zz_page;
	if (!$path) {
		if (empty($zz_page['db']['url'])) return [];
		$path = str_replace('*', '/%s', $zz_page['db']['url']);
		$ending = $zz_page['db']['ending'];
		if ($ending === 'none') $ending = '';
		$path = sprintf('/%s%s', $path, $ending);
	}
	if (!$path_overview) $path_overview = $path;
	$link = [];
	if (!empty($data['_next_identifier'])) {
		$href = wrap_page_links_path($path, $data['_next_identifier']);
		if ($href) {
			$link['next'][0]['href'] = $href;	
			$link['next'][0]['title'] = $data['_next_title'];
		}
	} else {
		$href = wrap_page_links_path($path_overview, $data['_main_identifier'] ?? dirname($data['identifier']));
		if ($href) {
			$link['next'][0]['href'] = $href;
			$link['next'][0]['title'] = $data['_main_title'] ?? wrap_text('Overview');
		}
	}
	if (!empty($data['_prev_identifier'])) {
		$href = wrap_page_links_path($path, $data['_prev_identifier']);
		if ($href) {
			$link['prev'][0]['href'] = $href;	
			$link['prev'][0]['title'] = $data['_prev_title'];
		}
	} else {
		$href = wrap_page_links_path($path_overview, $data['_main_identifier'] ?? dirname($data['identifier']));
		if ($href) {
			$link['prev'][0]['href'] = $href;
			$link['prev'][0]['title'] = $data['_main_title'] ?? wrap_text('Overview');
		}
	}
	return $link;
}

/**
 * return path
 * if path contains % treat as full path
 * otherwise use wrap_path()
 *
 * @param string $path
 * @param string $identifier
 * @return string
 */
function wrap_page_links_path($path, $identifier) {
	if (strstr($path, '%s')) $link = sprintf($path, $identifier);
	else $link = wrap_path($path, $identifier);
	if ($link AND str_ends_with($link, '//')) $link = substr($link, 0, -1); // top folder
	return $link;
}

/**
 * include format.inc.php file from custom project or active module
 *
 * @return void
 */
function wrap_page_format_files() {
	static $functions = [];
	$files = wrap_include('format', 'custom/modules/themes');
	if (!$files) return;
	foreach ($files['functions'] as $function) {
		if (in_array($function, $functions)) continue;
		if (!empty($function['short'])) {
			wrap_setting_add('brick_formatting_functions_prefix', [$function['short'] => $function['prefix']]);
			wrap_setting_add('brick_formatting_functions', $function['short']);
		} else {
			wrap_setting_add('brick_formatting_functions', $function['function']);
		}
		$functions[] = $function;
	}
}

/**
 * last revision of page content before it will be put into page template
 * with custom function
 *
 * @param array $page
 * @return array
 */
function wrap_page_replace($page) {
	$function = wrap_setting('page_replace_function');
	if ($function AND function_exists($function))
		$page = $function($page);
	return $page;
}

/**
 * create sequential navigation
 *
 * @param array $links = $page['links']
 * @return array
 */
function wrap_page_sequential($links) {
	global $zz_page;

	$link_relations = ['prev', 'next', 'up'];
	foreach ($link_relations as $rel) {
		if (empty($zz_page[$rel])) continue;
		if (!empty($links[$rel])) continue;
		$links[$rel] = [
			0 => [
				'href' => $zz_page[$rel]['url'],
				'title' => $zz_page[$rel]['title']
			]
		];
	}
	return $links;
}

/**
 * add prefix to first h1 heading
 *
 * @param string $text
 * @param string $prefix
 * @return string
 */
function wrap_page_h1_prefix($text, $prefix) {
	if (!$prefix) return $text;
	if (!strstr($text, '<h1')) return $text;
	$text = preg_replace('/(<h1\b[^>]*>)(.*?<\/h1>)/i', sprintf('$1%s$2', $prefix), $text, 1);
	return $text;
}

/**
 * limit entries displayed on a page
 *
 * @param string $type
 * @param int $value
 * @return int
 * @todo move setting `zzform_limit` to `page_limit`
 */
function wrap_page_limit($type = false, $value = false) {
	static $init = false;
	static $this_limit = NULL;
	static $limit_last = false;
	if (!$init) {
		$this_limit = wrap_setting('zzform_limit');
		if (array_key_exists('limit', $_GET)) {
			if ($_GET['limit'] === 'last') {
				$limit_last = true;
			} elseif (!is_array($_GET['limit']) AND intval($_GET['limit']).'' === $_GET['limit']) {
				$this_limit = intval($_GET['limit']);
			}
			if ($this_limit AND $this_limit < wrap_setting('zzform_limit'))
				$this_limit = wrap_setting('zzform_limit');
		}
		$init = true;
	}
	
	switch ($type) {
	case 'start':
		if ($value) $limit = $this_limit - $value;
		else $limit = $this_limit - wrap_setting('zzform_limit');
		if ($limit < 0) $limit = 0;
		return $limit;
	case 'end':
		if ($value AND $value < $this_limit) return intval($value) - 1;
		return $this_limit - 1;
	case 'remove':
		return $this_limit = NULL;
	case 'page':
		if (!$this_limit) return 1;
		return $this_limit / wrap_setting('zzform_limit');
	case 'last':
		// replace keyword limit=last with the correct numeric value 
		if (!$limit_last) return $this_limit;
		if ($value <= $this_limit) return $this_limit;
		return $this_limit = (ceil($value / wrap_setting('zzform_limit')) * wrap_setting('zzform_limit'));
	case 'last_qs':
		return $limit_last;
	default:
		return $this_limit;
	}
}

/**
 * check if there are hook functions in other packages
 *
 * @param array $settings
 *		string hook = name of package to look for a hook function
 * @return array
 */
function wrap_hook($settings) {
	$hook = [];
	$hook_types = ['init', 'finish'];
	foreach ($hook_types as $hook_type)
		$hook[$hook_type] = NULL;
	if (!array_key_exists('hook', $settings)) return $hook;

	$functions = debug_backtrace();
	$filename = basename($functions[0]['file']);
	$filename = substr($filename, 0, strpos($filename, '.'));
	$files = wrap_include($filename, $settings['hook']);
	foreach ($files['functions'] as $function) {
		foreach ($hook_types as $hook_type) {
			if ($function['short'] === sprintf('%s_%s', $filename, $hook_type))
				$hook[$hook_type] = $function['function'];
		}
	}
	return $hook;
}
