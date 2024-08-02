<?php 

/**
 * zzwrap
 * Standard page functions (menu, breadcrumbs, authors, page)
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 *	wrap_template()
 *	wrap_get_authors()				-- gets authors from database
 *	wrap_htmlout_page()				-- outputs webpage from %%%-template in HTML
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Puts data from request into template and returns full page
 *
 * @param string $template Name of template that will be filled
 * @param array $data Data which will be used to fill the template
 * @param string $mode
 *		'ignore position': ignores position, returns a string instead of an array
 *		'error': returns simple template, with placeholders
 * @return mixed $text (string or array indexed by positions)
 */
function wrap_template($template, $data = [], $mode = false) {
	wrap_page_format_files();

	if (strstr($template, "\n")) {
		wrap_setting('current_template', '(from variable)');
		wrap_setting('current_template_file', '');
		$template = explode("\n", $template);
		// add newline that explode removed to each line
		foreach (array_keys($template) as $no) {
			$template[$no] .= "\n";
		}
	} elseif (str_starts_with($template, '/') AND file_exists($template)) {
		$tpl_file = $template;
		wrap_setting('current_template', $template);
		wrap_setting('current_template_file', $tpl_file);
		$template = file($tpl_file);
	} else {
		$tpl_file = wrap_template_file($template);
		if (!$tpl_file) return false;
		wrap_setting('current_template', $template);
		wrap_setting('current_template_file', $tpl_file);
		$template = file($tpl_file);
	}
	// remove comments and next empty line from the start
	foreach ($template as $index => $line) {
		if (substr($line, 0, 1) === '#') unset($template[$index]); // comments
		elseif (!trim($line)) unset($template[$index]); // empty lines
		else break;
	}
	$template = implode("", $template);
	if (!trim($template)) return '';
	// now we have the template as string, in case of error, return
	if ($mode === 'error') return $template;

	// replace placeholders in template
	// save old setting regarding text formatting
	$old_brick_fulltextformat = wrap_setting('brick_fulltextformat');
	// apply new text formatting
	wrap_setting('brick_fulltextformat', 'brick_textformat_html');
	$page = brick_format($template, $data);
	// restore old setting regarding text formatting
	wrap_setting('brick_fulltextformat', $old_brick_fulltextformat);

	// get rid of if / else text that will be put to hidden
	if (is_array($page['text']) AND count($page['text']) === 2 
		AND in_array('_hidden_', array_keys($page['text']))
		AND in_array(wrap_setting('brick_default_position'), array_keys($page['text']))) {
		unset($page['text']['_hidden_']);
		$page['text'] = end($page['text']);
	}
	if ($mode === 'ignore positions' AND is_array($page['text']) AND count($page['text']) === 1) {
		$page['text'] = current($page['text']);
	}
	// check if errors occured while filling in the template
	wrap_page_check_if_error($page);
	return $page['text'];
}

/**
 * Gets template filename for any given template
 *
 * @param string $template
 * @param bool $show_error return with error 503 if not found or not
 * @return string $filename
 */
function wrap_template_file($template, $show_error = true) {
	if (wrap_setting('active_theme')) {
		$tpl_file = wrap_template_file_per_folder($template, wrap_setting('themes_dir').'/'.wrap_setting('active_theme').'/templates');
		if ($tpl_file) return $tpl_file;
	}
	
	$tpl_file = wrap_template_file_per_folder($template, wrap_setting('custom').'/templates');
	if ($tpl_file) return $tpl_file;

	// check if there's a module template
	if (strstr($template, '/')) {
		// is it a full file name coming from 'tpl_file'?
		// we cannot do anything with this here
		if (substr($template, 0, 1) === '/') return false;
		$template_parts = explode('/', $template);
		$my_module = array_shift($template_parts);
		$template = implode('/', $template_parts);
	} else {
		$my_module = '';
	}
	$found = [];
	$packages = array_merge(wrap_setting('modules'), wrap_setting('themes'));
	foreach ($packages as $package) {
		if ($my_module AND $package !== $my_module) continue;
		$dir = in_array($package, wrap_setting('modules'))
			? wrap_setting('modules_dir') : wrap_setting('themes_dir');
		$pathinfo = pathinfo($template);
		if (!empty($pathinfo['dirname'])
			AND in_array($pathinfo['dirname'], ['layout', 'behaviour'])
			AND !empty($pathinfo['extension'])
		) {
			// has path and extension = separate file, other folder
			$tpl_file = sprintf('%s/%s/%s', $dir, $package, $template);
		} else {
			$tpl_file = wrap_template_file_per_folder($template, $dir.'/'.$package.'/templates');
		}
		if ($tpl_file) $found[$package] = $tpl_file;
	}
	// ignore default template if there’s another template from a module
	$found = wrap_template_file_decide($found);
	
	if (count($found) !== 1) {
		if (!$show_error) return false;
		global $zz_page;
		if (!$found) {
			$error_msg = wrap_text('Template <code>%s</code> does not exist.', ['values' => wrap_html_escape($template)]);
		} else {
			$error_msg = wrap_text('More than one template with the name <code>%s</code> exists.', ['values' => wrap_html_escape($template)]);
		}
		if (!empty($zz_page['error_code'])) {
			echo $error_msg;
			return false;
		} else {
			wrap_quit(503, $error_msg);
		}
	} else {
		$package = key($found);
		if (in_array($package, wrap_setting('modules')))
			wrap_package_activate($package);
		else
			wrap_package_activate($package, 'theme');
		$tpl_file = reset($found);
	}
	return $tpl_file;
}

/**
 * decide if more than one template was found which one to use
 *
 * @param array $founde
 * @return array
 */
function wrap_template_file_decide($found) {
	if (count($found) <= 1) return $found;
	
	// remove inactive themes
	foreach ($found as $package => $path) {
		if (!str_starts_with($path, wrap_setting('themes_dir'))) continue;
		if ($package === wrap_setting('active_theme')) continue;
		unset($found[$package]);
	}
	if (count($found) <= 1) return $found;
	
	// two templates found? always overwrite default module
	if (count($found) === 2 AND array_key_exists('default', $found))
		unset($found['default']);

	return $found;
}

/**
 * Checks per folder (custom/templates, modules/templates) if there's a template
 * in that folder; checks first for language variations, then for languages
 * and at last for templates without language information
 *
 * @param string $template
 * @param string $folder
 * @return string $filename
 */
function wrap_template_file_per_folder($template, $folder) {
	if (wrap_setting('lang')) {
		if (wrap_setting('language_variation')) {
			$tpl_file = $folder.'/'.$template.'-'.wrap_setting('lang').'-'.wrap_setting('language_variation').'.template.txt';
			if (file_exists($tpl_file)) return $tpl_file;
		}
		$tpl_file = $folder.'/'.$template.'-'.wrap_setting('lang').'.template.txt';
		if (file_exists($tpl_file)) return $tpl_file;
	}
	$tpl_file = $folder.'/'.$template.'.template.txt';
	if (file_exists($tpl_file)) return $tpl_file;
	return '';
}

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
function wrap_get_authors($brick_authors, $author_id = false) {
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
 * @return bool true if everything is okay
 */
function wrap_page_check_if_error($page) {
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
		$zz_page['url'] = wrap_check_canonical_hostname($zz_page['url']);
		// redirect will kill POST data
		// this is a feature, since a POST from a non-accessible URL is not possible
		wrap_canonical_redirect($zz_page['url']);
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
		$page['text'] = wrap_template($zz_page['tpl_file']);
		if (!$page['text']) wrap_quit(404);
		$page['content_type'] = wrap_file_extension($zz_page['tpl_file']);
		wrap_setting('character_set', wrap_detect_encoding($page['text']));
		$page['status'] = 200;
		$page['query_strings'][] = 'v';
		$page['query_strings'][] = 'nocache';
	} else {
		$page = brick_format($zz_page['db'][wrap_sql_fields('page_content')], $zz_page['db']['parameter']);
	}
	wrap_page_check_if_error($page);

	if (!empty($page['no_output'])) exit;

	$zz_page['url'] = wrap_check_canonical($zz_page, $page);
	wrap_canonical_redirect($zz_page['url']);

	$page['media']		= wrap_page_media($page);
	$page[wrap_sql_fields('page_last_update')] = wrap_page_last_update($page);
	if (!empty($zz_page['db'][wrap_sql_fields('page_author_id')]) AND !empty($page['authors']))
		$page['authors'] = wrap_get_authors($page['authors'], $zz_page['db'][wrap_sql_fields('page_author_id')]);

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

	if (!empty($page['content_type_original']) AND wrap_setting('send_as_json'))
		$page['text'] = wrap_page_json($page);

	if (!empty($page['content_type']) AND $page['content_type'] !== 'html') {
		wrap_send_text($page['text'], $page['content_type'], $page['status'], $page['headers'] ?? []);
		exit;
	}
	
	// if globally dont_show_h1 is set, don't show it
	if (wrap_setting('dont_show_h1')) $page['dont_show_h1'] = true;

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
		if (substr($page['template'], -5) !== '-page')
			$page['template'] .= '-page';
		$tpl_file = wrap_template_file($page['template'], false);
		if ($tpl_file) wrap_setting('template', $page['template']);
	}
	
	$blocks = wrap_check_blocks(wrap_setting('template'));
	if (in_array('breadcrumbs', $blocks)) {
		require_once __DIR__.'/nav.inc.php';
		$page['breadcrumbs'] = wrap_breadcrumbs($page);
	}
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
		if (empty($page['dont_show_h1']) AND !empty($page['title']) AND !(wrap_setting('h1_via_template'))
			AND $position === 'text') {
			$text = "\n".markdown('# '.$page['title']."\n")."\n".$text;
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
		$content => $text ?? $page['text'],
		'title' => $page['pagetitle'],
		'url' => $page['url'] ?? wrap_setting('request_uri')
	];
	if (!empty($page['data']))
		foreach ($page['data'] as $key => $data)
			$output[$key] = $data;
	$json = json_encode($output);
	if ($output and !$json) {
		wrap_quit(503, 'JSON error: '.json_last_error_msg());
		exit;
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

	$file = wrap_template_file($template);
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
function wrap_page_links($data, $path, $path_overview = false) {
	if (!$path_overview) $path_overview = $path;
	$link = [];
	if (!empty($data['_next_identifier'])) {
		$link['next'][0]['href'] = wrap_path($path, $data['_next_identifier']);	
		$link['next'][0]['title'] = $data['_next_title'];
	} else {
		$link['next'][0]['href'] = wrap_path($path_overview, $data['_main_identifier'] ?? dirname($data['identifier']));
		$link['next'][0]['title'] = $data['_main_title'] ?? wrap_text('Overview');
	}
	if (!empty($data['_prev_identifier'])) {
		$link['prev'][0]['href'] = wrap_path($path, $data['_prev_identifier']);	
		$link['prev'][0]['title'] = $data['_prev_title'];
	} else {
		$link['prev'][0]['href'] = wrap_path($path_overview, $data['_main_identifier'] ?? dirname($data['identifier']));
		$link['prev'][0]['title'] = $data['_main_title'] ?? wrap_text('Overview');
	}
	return $link;
}

/**
 * include format.inc.php file from custom project or active module
 *
 * @return void
 */
function wrap_page_format_files() {
	static $included = [];
	$files = wrap_include('format', 'custom/active');
	if (!$files) return;
	foreach ($files['functions'] as $function) {
		if (!empty($function['short'])) {
			wrap_setting_add('brick_formatting_functions_prefix', [$function['short'] => $function['prefix']]);
			wrap_setting_add('brick_formatting_functions', $function['short']);
		} else {
			wrap_setting_add('brick_formatting_functions', $function['function']);
		}
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
