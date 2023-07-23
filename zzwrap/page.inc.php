<?php 

/**
 * zzwrap
 * Standard page functions (menu, breadcrumbs, authors, page)
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 *	wrap_template()
 *	wrap_get_menu()					-- gets menu from database
 *		wrap_get_menu_navigation()	-- gets menu from separate navigation table
 *		wrap_get_menu_webpages()	-- gets menu from webpages table
 *	wrap_htmlout_menu()				-- outputs menu in HTML
 *	wrap_get_breadcrumbs()			-- gets breadcrumbs from database
 *		wrap_get_breadcrumbs_recursive()	-- recursively gets breadcrumbs
 *	wrap_htmlout_breadcrumbs()		-- outputs breadcrumbs in HTML
 *	wrap_get_authors()				-- gets authors from database
 *	wrap_htmlout_page()				-- outputs webpage from %%%-template in HTML
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2023 Gustaf Mossakowski
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
		$template = explode("\n", $template);
		// add newline that explode removed to each line
		foreach (array_keys($template) as $no) {
			$template[$no] .= "\n";
		}
	} elseif (str_starts_with($template, '/') AND file_exists($template)) {
		$tpl_file = $template;
		wrap_setting('current_template', $template);
		$template = file($tpl_file);
	} else {
		$tpl_file = wrap_template_file($template);
		if (!$tpl_file) return false;
		wrap_setting('current_template', $template);
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
//	menu
//

/**
 * Gets menu entries depending on source
 * 
 * reads menu settings from webpages- or navigation-table, sets current page to
 * 'current_page', adds base URL if neccessary
 *
 * @param array $page
 * @return array
 *   array 'nav_db': 'title', 'url', 'current_page', 'id', 'subtitle'
 *   int current_navitem
 *   string current_menu
 */
function wrap_get_menu($page) {
	global $zz_page;
	
	if ($sql = wrap_sql_query('page_menu_hierarchy') AND !empty($zz_page['db']))
		$hierarchy = wrap_db_parents($zz_page['db'][wrap_sql_fields('page_id')], $sql);
	else
		$hierarchy = [];
	
	$page['current_navitem'] = 0;
	$page['current_menu'] = '';

	if (wrap_sql_table('page_menu') === 'navigation') {
		// Menu from separate navigation table
		$menu = wrap_get_menu_navigation();
	} else {
		// Menu settings included in webpages table
		$menu = wrap_get_menu_webpages();
	}
	if (empty($menu)) return $page;

	// set current_page, id, subtitle, url with base for _ALL_ menu items
	$base = wrap_nav_base();
	foreach (array_keys($menu) as $id) {
		// $i to know which is the first-child, some old browsers don't support :first-child in CSS
		$i = 0;
		unset($previous_section);
		foreach ($menu[$id] as $nav_id => $item) {
			// class?
			if (empty($item['class'])) $item['class'] = [];
			elseif (!is_array($item['class'])) $item['class'] = [$item['class']];
			// add base_url for non-http links
			if ($item['url'] AND substr($item['url'], 0, 1) === '/') 
				$menu[$id][$nav_id]['url'] = $base.$item['url'];
			// mark current page in menus
			$menu[$id][$nav_id]['current_page'] = 
				($menu[$id][$nav_id]['url'] === wrap_setting('request_uri')) ? true : false;
			if ($menu[$id][$nav_id]['current_page']) {
				$page['current_navitem'] = $nav_id;
				$page['current_menu'] = $id;
			}
			// create ID for CSS, JavaScript
			if (!empty($item['id_title']))
				$menu[$id][$nav_id]['id'] = 'menu-'.wrap_create_id($item['id_title']);
			// initialize subtitle
			if (empty($item['subtitle'])) $menu[$id][$nav_id]['subtitle'] = '';
			if (isset($item['section'])) {
				// sections can put spacers inbetween menu items via classes
				if (!isset($previous_section)) {
					$previous_section = $item['section'];
				} elseif ($previous_section !== $item['section']) {
					$menu[$id][$nav_id]['spacer'] = true;
					$item['class'][] = 'menuspacer';
					$previous_section = $item['section'];
				}
			}
			if (!isset($menu[$id][$nav_id]['below'])) {
				if ($item['url'] === wrap_setting('base').'/') {
					// all pages are below homepage, don't highlight this
					$menu[$id][$nav_id]['below'] = false;
				} else {
					$menu[$id][$nav_id]['below']
						= (substr(wrap_setting('request_uri'), 0, ($item['url'] ? strlen($item['url']) : 0)) === $item['url']) ? true
						: (in_array($item[wrap_sql_fields('page_id')], $hierarchy) ? true : false);
				}
			}
			if ($menu[$id][$nav_id]['below'] OR $menu[$id][$nav_id]['current_page']) {
				$menu[$id]['pos'] = $i + 1;
			}
			if (!$i) $item['class'][] = 'first-child';
			if ($i === count($menu[$id]) - 1) $item['class'][] = 'last-child';
			$menu[$id][$nav_id]['class'] = implode(' ', $item['class']);
			$i++;
		}
	}

	$page['nav_db'] = $menu;
	return $page;
}

/**
 * Read data for menu from db table 'navigation', translate if required
 * Liest Daten für Menü aus der DB-Tabelle 'navigation' aus, übersetzt ggf. Menü
 * 
 * @return array $menu: 'title', 'url', 'subtitle' (optional), 'id_title' (optional)
 */
function wrap_get_menu_navigation() {
	// no menu query, so we don't have a menu
	if (!wrap_sql_query('page_menu')) return [];

	// get data from database
	$unsorted_menu = wrap_db_fetch(wrap_sql_query('page_menu'), 'nav_id');
	// translation if there's a function for it
	if (function_exists('wrap_translate_menu'))
		$unsorted_menu = wrap_translate_menu($unsorted_menu);
	// write database output into hierarchical array
	$menu = [];
	foreach ($unsorted_menu as $item) {
		$my_item = wrap_menu_asterisk_check($item, $menu, $item['main_nav_id'], 'nav_id');
		if ($my_item) {
			if (!empty($menu[$item['main_nav_id']])) {
				$menu[$item['main_nav_id']] += $my_item;
			} else {
				$menu[$item['main_nav_id']] = $my_item;
			}
		}
	}
	return $menu;
}

/**
 * Read data for menu from db table 'webpages/, translate if required
 * Liest Daten für Menü aus der DB-Tabelle 'webpages' aus, übersetzt ggf. Menü
 *
 * @return array $menu: 'title', 'url', 'subtitle'
 */
function wrap_get_menu_webpages() {
	// no menu query, so we don't have a menu
	if (!$sql = wrap_sql_query('page_menu')) return []; 

	$menu = [];
	// get top menus
	$entries = wrap_db_fetch($sql, wrap_sql_fields('page_id'));
	if (!$entries) return false;
	if ($menu_table = wrap_sql_table('page_menu')) {
		$entries = wrap_translate($entries, $menu_table);
		$entries = wrap_get_menu_shorten($entries, $sql);
	}
	foreach ($entries as $line) {
		if (strstr($line['menu'], ',')) {
			$mymenus = explode(',', $line['menu']);
			foreach ($mymenus as $menu_key) {
				$line['menu'] = $menu_key;
				if ($my_item = wrap_menu_asterisk_check($line, $menu, $menu_key))
					$menu[$menu_key] = $my_item;
			}
		} else {
			if ($my_item = wrap_menu_asterisk_check($line, $menu, $line['menu']))
				$menu[$line['menu']] = $my_item;
		}
	}
	if (!empty($_SESSION) AND function_exists('wrap_menu_session')) {
		wrap_menu_session($menu);
	}
	// get second (and third or fourth) hierarchy level
	$levels = [2, 3, 4];
	foreach ($levels as $level) {
		if (!$sql = wrap_sql_query('page_menu_level'.$level)) continue;
		$sql = sprintf($sql, '"'.implode('", "', array_keys($menu)).'"');
		$entries = wrap_db_fetch($sql, wrap_sql_fields('page_id'));
		if ($menu_table = wrap_sql_table('page_menu'))
			$entries = wrap_translate($entries, $menu_table);
		foreach ($entries as $line) {
			if (empty($line['top_ids'])) {
				// backwards compatibility
				$line['top_ids'] = $line['mother_page_id'];
			}
			$menu_key = $line['menu'].'-'.$line['top_ids'];
			// URLs ending in * or */ or *.html are different
			if ($my_item = wrap_menu_asterisk_check($line, $menu, $menu_key))
				$menu[$menu_key] = $my_item;
		}
	}
	return $menu;
}

/**
 * shorten translated menu entries
 *
 * @param array $entries
 * @param string $sql
 * @return array
 */
function wrap_get_menu_shorten($entries, $sql) {
	// use of SUBSTRING_INDEX? get separator character(s)
	preg_match('/SUBSTRING_INDEX\(title, ["\'](.+)["\'], 1\) AS title/', $sql, $matches);
	if (empty($matches[1])) return $entries;

	foreach ($entries as $id => $entry) {
		$pos = strpos($entry['title'], $matches[1]);
		if (!$pos) continue;
		$entries[$id]['title'] = trim(substr($entry['title'], 0, $pos));
	}
	return $entries;
}

/**
 * checks if URL ends in * and if yes, returns function output or nothing
 *
 * @param array $line
 * @param array $menu
 * @param string $menu_key
 * @param string $id (optional, page_id or nav_id, depending on where data comes from)
 * @return array $menu[$menu_key]
 */
function wrap_menu_asterisk_check($line, $menu, $menu_key, $id = 'page_id') {
	if (!$line['url'] OR (substr($line['url'], -1) !== '*' AND substr($line['url'], -2) !== '*/'
		AND substr($line['url'], -6) !== '*.html')) {
		if ($id === 'page_id') $id = wrap_sql_fields('page_id');
		$menu[$menu_key][$line[$id]] = $line;
		return $menu[$menu_key];
	}
	// get name of function either from sql query
	// (for multilingual pages) or from the part until *
	$url = $line['function_url'] ?? substr($line['url'], 0, strrpos($line['url'], '*')+1);
	$url = substr($url, 1, -1);
	if (strstr($url, '/')) {
		$url = str_replace('/', '_', $url);
	}
	$menufunc = 'wrap_menu_'.$url;
	if (function_exists($menufunc)) {
		$menu_entries = $menufunc($line);
		if (!empty($menu[$menu_key])) {
			$menu[$menu_key] += $menu_entries;
		} else {
			$menu[$menu_key] = $menu_entries;
		}
	}
	return $menu[$menu_key] ?? false;
}

/**
 * Gibt in HTML formatiertes Navigationsmenü von wrap_get_menu() aus
 * 
 * HTML-Ausgabe erfolgt als verschachtelte Liste mit id="menu" und role
 * auf oberster Ebene, darunter obj2, obj3, .. je nach Anzahl der Menüeinträge
 * aktuelle Seite wird mit '<strong>' ausgezeichnet. Gibt komplettes Menü
 * zurück
 * @param array $nav Ausgabe von wrap_get_menu();
 *	required keys: 'title', 'url', 'current_page'
 *	optional keys: 'long_title', 'id', 'class', 'subtitle', 'ignore'
 * @param string $menu_name optional; 0 bzw. für Untermenüs $nav_id des jeweiligen 
 *	Eintrags oder Name des Menüs
 * @param int $page_id optional; show only the one correspondig entry from the menu
 *	and show it with a long title
 * @param int $level: if it's a submenu, show the level of the menu
 * @param bool $avoid_duplicates avoid duplicate menus, can be set to false if
 *  for some reasons menus shall be recreated (e. g. settings differ)
 * @return string HTML-Output
 */
function wrap_htmlout_menu(&$nav, $menu_name = '', $page_id = 0, $level = 0, $avoid_duplicates = true) {
	static $menus;

	if (!$nav) return false;
	// avoid duplicate menus
	if ($avoid_duplicates) {
		if (empty($menus)) $menus = [];
		if (in_array($menu_name, $menus)) return false;
	}
	
	// no menu_name: use default menu name but only if it exists, otherwise keep ''
	if (!$menu_name AND wrap_setting('main_menu'))
		$menu_name = wrap_setting('main_menu');

	if (!$menu_name OR is_numeric($menu_name)) {
		// if we have a separate navigation table, the $nav-array comes from
		// wrap_get_menu_navigation()
		$fn_page_id = 'nav_id';
	} else {
		// as default, menu comes from database table 'webpages'
		// wrap_get_menu_webpages()
		$fn_page_id = wrap_sql_fields('page_id');
	}
	
	if (empty($nav[$menu_name]) AND !$page_id) {
	// There is no Menu for this entry -> get upper next level
	// this only works for two hierarchy levels
		$page = substr($menu_name, strrpos($menu_name, '-')+1);
		foreach ($nav as $id => $entries) {
			if (in_array($page, array_keys($entries)))
				$menu_name = $id;
		}
		if (empty($nav[$menu_name])) return false;
	} elseif ($page_id AND !in_array($page_id, array_keys($nav[$menu_name]))) {
	// A single page shall be shown, but it's not in this menu -> get upper next level
	// this only works for two hierarchy levels
		$new_page_id = false;
		foreach ($nav as $id => $entries) {
			if (in_array($page_id, array_keys($entries))) {
				$new_page_id = $entries[$page_id]['mother_page_id'];
			}
		}
		if (!$new_page_id) return false;
		else $page_id = $new_page_id;
	}
	
	// check if there's a menu defined for SESSION
	// and add these entries to the main menu or another menu
	if (wrap_setting('session_menu') AND !empty($_SESSION['logged_in'])
		AND !empty($nav[wrap_setting('session_menu')])
		AND $menu_name === wrap_setting('session_menu_in_menu')) {
		$nav[$menu_name] = array_merge($nav[$menu_name], $nav[wrap_setting('session_menu')]);
	}

	// OK, finally, we just get the menu together
	$menu = [];
	foreach ($nav[$menu_name] as $id => $item) {
		if (!is_array($item)) continue;
		if ($page_id AND $item[$fn_page_id] != $page_id) continue;
		if (isset($item['ignore'])) continue;

		// do some formatting in advance
		// @todo move to template or to wrap_get_menu()
		$item['title'] = $page_id ? $item['long_title'] : $item['title'];
		$item['title'] = str_replace('& ', '&amp; ', $item['title']);
		$item['title'] = str_replace('-', '-&shy;', $item['title']);
		if (!empty($item['subtitle'])) {
			$item['subtitle'] = str_replace('& ', '&amp; ', $item['subtitle']);
			$item['subtitle'] = str_replace('-', '-&shy;', $item['subtitle']);
		}
		
		// get submenu if there is one and if it shall be shown
		$id = ($fn_page_id === 'nav_id' ? '' : $item['menu']);
		if (!empty($item['top_ids'])) {
			// create ID for menus level 3 and downwards
			$top_id = explode('-', $id);
			if (in_array(array_pop($top_id), explode('-', $item['top_ids']))) {
				// if ID is somewhere in top_ids, remove it
				$id = implode('-', $top_id);
			}
			$id .= ($id ? '-' : '').$item['top_ids'];
		}
		$id .= ($id ? '-' : '').$item[$fn_page_id];
		if (!empty($nav[$id]) // there is a submenu and at least one of:
			AND (wrap_setting('menu_display_submenu_items') !== 'none')
			AND (wrap_setting('menu_display_submenu_items') === 'all' 	// all menus shall be shown
				OR $item['current_page'] 	// it's the submenu of the current page
				OR $item['below'])) {		// it has a url one or more levels below this page
			$item['submenu_rows'] = count($nav[$id]);
			$item['submenu'] = wrap_htmlout_menu($nav, $id, false, $level + 1, $avoid_duplicates);
		}
		$item['menu_'.$menu_name] = true;
		$menu[] = $item;
	}
	$menu['pos'] = $nav[$menu_name]['pos'] ?? false;
	$menu['level'] = $level;
	$menu['menu_'.$menu_name] = true;
	if ($level) $menu['is_submenu'] = true;
	$output = wrap_template('menu', $menu);
	if ($avoid_duplicates) {
		$menus[] = $menu_name;
	}
	return $output;
}


/**
 * gibt zur aktuellen Seite die ID der obersten Menüebene aus
 * 
 * @param array $menu Alle Menüeintrage, wie aus wrap_get_menu() zurückgegeben
 * @return int ID des obersten Menüs
 */
function wrap_get_top_nav_id($menu) {
	$top_nav = wrap_get_top_nav_recursive($menu);
	if ($top_nav) return $top_nav;
	
	// current page is not in this menu
	// check next page(s) in breadcrumb trail if it's (they're) in menu
	global $zz_page;
	// 404 and other error pages don't correspond to a database entry
	// so exit, this page is not in navigation
	if (empty($zz_page['db'])) return false;

	$breadcrumbs = wrap_get_breadcrumbs($zz_page['db'][wrap_sql_fields('page_id')]);
	array_pop($breadcrumbs); // own page, we do not need this
	while ($breadcrumbs) {
		$upper_breadcrumb = array_pop($breadcrumbs);
		// set current_page for next upper page in hierarchy
		foreach (array_keys($menu) as $main_nav_id) {
			foreach ($menu[$main_nav_id] as $nav_id => $element) {
				if (is_array($element) AND $element[wrap_sql_fields('page_id')] == $upper_breadcrumb['page_id']) {
					// current_page in $menu may be set to true
					// without problems since $menu is not used for anything anymore
					// i. e. there will still be a link to the navigation menu entry
					$menu[$main_nav_id][$nav_id]['current_page'] = true;
					break;
				}
			}
		}
	}
	$top_nav = wrap_get_top_nav_recursive($menu);
	return $top_nav;
}
	

/**
 * Hilfsfunktion zu wrap_get_top_nav_id()
 * 
 * @param array $menu Alle Menüeintrage, wie aus wrap_get_menu() zurückgegeben
 * @param int $nav_id internal value
 * @return int ID des obersten Menüs
 */
function wrap_get_top_nav_recursive($menu, $nav_id = false) {
	$main_nav_id = false;
	foreach (array_keys($menu) as $index) {
		foreach (array_keys($menu[$index]) AS $subindex) {
			if (!is_numeric($subindex)) continue;
			if (!$nav_id) {
				// 'main_entry' allows to define in which part of
				// the page tree this entry finds its main residence if its
				// part of several subtrees
				if (!isset($menu[$index][$subindex]['main_entry']))
					$menu[$index][$subindex]['main_entry'] = true;
				if ($menu[$index][$subindex]['current_page']
					AND $menu[$index][$subindex]['main_entry']) {
					if ($index) $main_nav_id = wrap_get_top_nav_recursive($menu, $index);
					else $main_nav_id = $subindex;
				}
			} elseif ($subindex == $nav_id) {
				if ($index) $main_nav_id = wrap_get_top_nav_recursive($menu, $index);
				else $main_nav_id = $subindex;
			}
		}
	}
	return $main_nav_id;
}


//
//	breadcrumbs
//

/**
 * set breadcrumbs for page
 * merge breadcrumbs from database with breadcrumbs from script (if any)
 * set breadcrumbs for error pages
 *
 * @param array $page
 * @return string
 */
function wrap_breadcrumbs($page) {
	global $zz_page;
	$page_breadcrumbs = $page['breadcrumbs'] ?? [];

	$page_id = $zz_page['db'][wrap_sql_fields('page_id')] ?? false;
	if ($page_id AND (empty($page['error_no_content']) OR $page['status'] !== 404)) {
		// read breadcrumbs from database
		$breadcrumbs = wrap_get_breadcrumbs($page_id);
		// if there are breadcrumbs returned from brick_format, remove the last
		// and append these breadcrumbs instead
		if ($page_breadcrumbs) array_pop($breadcrumbs);
	} else {
		// page not in database/no database connection = error page
		$breadcrumbs = [];
		if (!wrap_setting('error_breadcrumbs_without_homepage_url')) {
			$breadcrumbs[] = [
				'url_path' => wrap_setting('homepage_url'),
				'title' => wrap_setting('project')
			];
		}
		$status = wrap_http_status_list($page['status']);
		$breadcrumbs[]['title'] = wrap_text($status['text']);
	}
	$breadcrumbs = array_merge($breadcrumbs, $page_breadcrumbs);
	return wrap_htmlout_breadcrumbs($breadcrumbs);
}

/**
 * Reads webpages from database, creates breadcrumbs hierarchy
 * 
 * @param int $page_id ID of current webpage in database
 * @return array breadcrumbs, hierarchical ('title' => title of menu, 'url_path' = link)
 */
function wrap_get_breadcrumbs($page_id) {
	if (!$sql = wrap_sql_query('page_breadcrumbs')) return [];
	global $zz_page;

	$breadcrumbs = [];
	// get all webpages
	if (!wrap_rights('preview')) $sql = wrap_edit_sql($sql, 'WHERE', wrap_sql_fields('page_live'));
	$pages = wrap_db_fetch($sql, wrap_sql_fields('page_id'));
	if (wrap_setting('translate_fields'))
		$pages = wrap_translate($pages, wrap_sql_table('default_translation_breadcrumbs'), '', false);

	// get all breadcrumbs recursively
	$breadcrumbs = wrap_get_breadcrumbs_recursive($page_id, $pages);
	// check for placeholders
	$breadcrumb_placeholder = $zz_page['breadcrumb_placeholder'] ?? [];
	$placeholder_url_paths = [];
	foreach ($breadcrumb_placeholder as $index => $placeholder) {
		$placeholder_url_paths[] = $placeholder['url_path'];
		if (!empty($placeholder['add_next'])) {
			$breadcrumbs = array_reverse($breadcrumbs);
			$append = false;
			$b_index = 0;
			$pos = $index + 1;
			while ($b_index < count($breadcrumbs)) {
				if (substr_count($breadcrumbs[$b_index]['url_path'], '*') < $pos) {
					// not interesting so far
					$b_index++;
					continue;
				}
				if (!$append) {
					// ok, first element, will be duplicated
					array_splice($breadcrumbs, $b_index, 0, [$breadcrumbs[$b_index]]);
					$append = true;
					$b_index++;
					// no duplication of asterisks, therefore continue
					continue;
				}
				// duplicate asterisks at position
				$url_path = explode('*', $breadcrumbs[$b_index]['url_path']);
				$url_path[$pos] = '*'.$url_path[$pos];
				$breadcrumbs[$b_index]['url_path'] = implode('*', $url_path);
				$b_index++;
			}
			$breadcrumbs = array_reverse($breadcrumbs);
		}
	}
	// only unique URL paths
	$last_url = '';
	foreach ($breadcrumbs as $index => $breadcrumb) {
		if ($breadcrumb['url_path'] === $last_url) unset($breadcrumbs[$index]);
		$last_url = $breadcrumb['url_path'];
	}
	foreach ($breadcrumbs as $index => &$breadcrumb) {
		if (!$count = substr_count($breadcrumb['url_path'], '*')) continue;
		if ($breadcrumb_placeholder) {
			if (str_ends_with($breadcrumb['url_path'], '*/') AND array_key_exists(($count - 1), $breadcrumb_placeholder))
				$breadcrumb['title'] = $breadcrumb_placeholder[($count - 1)]['title'];
			$breadcrumb['url_path'] = str_replace('*', '/%s', $breadcrumb['url_path']);
			while ($count > count($placeholder_url_paths))
				$placeholder_url_paths[] = '*';
			$breadcrumb['url_path'] = vsprintf($breadcrumb['url_path'], $placeholder_url_paths);
		}
		if (!$count = substr_count($breadcrumb['url_path'], '*')) continue;
		// remove breadcrumbs with * in URL except for current page
		if ($index) unset($breadcrumbs[$index]);
	}
	// remove url_path of current page, here, not before, because of breadcrumb title!
	if (array_key_exists(0, $breadcrumbs))
		$breadcrumbs[0]['url_path'] = '';
	// sort breadcrumbs in descending order
	krsort($breadcrumbs);
	// finished!
	return $breadcrumbs;
}

/**
 * Creates breadcrumbs hierarchy, recursively
 * 
 * @param int $page_id ID of webpage in hierarchy in database
 * @param array $pages Array with all pages from database, indexed page_id
 * @return array breadcrumbs ('title' => title of menu, 'url_path' = link)
 */
function wrap_get_breadcrumbs_recursive($page_id, &$pages) {
	$breadcrumbs[] = [
		'title' => $pages[$page_id]['title'],
		'url_path' => wrap_path_placeholder($pages[$page_id]['identifier'].($pages[$page_id]['ending'] ?? ''), '*'),
		'page_id' => $pages[$page_id][wrap_sql_fields('page_id')]
	];
	if ($pages[$page_id]['mother_page_id'] 
		&& !empty($pages[$pages[$page_id]['mother_page_id']]))
		$breadcrumbs = array_merge($breadcrumbs, 
			wrap_get_breadcrumbs_recursive($pages[$page_id]['mother_page_id'], $pages));
	return $breadcrumbs;
}

/**
 * Creates HTML output of breadcrumbs
 * 
 * @param array $breadcrumbs
 * @return string HTML output, plain linear, of breadcrumbs
 */
 function wrap_htmlout_breadcrumbs($breadcrumbs) {
	// set default values
	$base = wrap_nav_base();

	// format breadcrumbs
	$formatted_breadcrumbs = [];
	foreach ($breadcrumbs as $crumb) {
		if (!is_array($crumb)) { // from $brick_breadcrumbs
			$formatted_breadcrumbs[] = $crumb;
			continue;
		}
		if (array_key_exists('linktext', $crumb)) {
			// @deprecated notation
			$crumb = [
				'url_path' => $crumb['url'] ?? '',
				'title' => $crumb['linktext'],
				'title_attr' => $crumb['title'] ?? ''
			];
			wrap_error('Use notation with `title` and `url_path` instead of `linktext` and `url`', E_USER_DEPRECATED);
		}
		// don't show placeholder paths
		if (!isset($crumb['url_path'])) $crumb['url_path'] = '';
		$paths = explode('/', $crumb['url_path']);
		foreach ($paths as $path) {
			if (substr($path, 0, 1) === '%' AND substr($path, -1) === '%') continue 2;
		}
		if ($crumb['url_path']) $crumb['url_path'] = $base.$crumb['url_path'];
		$current = ($crumb['url_path'] === wrap_setting('request_uri') ? true : false);
		if ($current) $crumb['url_path'] = '';

		if ($crumb['url_path'] AND !empty($crumb['title_attr']))
			$formatted_breadcrumbs[] = sprintf('<a href="%s" title="%s">%s</a>',
				$crumb['url_path'], $crumb['title_attr'], $crumb['title']
			);
		elseif ($crumb['url_path'])
			$formatted_breadcrumbs[] = sprintf('<a href="%s">%s</a>',
				$crumb['url_path'], $crumb['title']
			);
		elseif (!empty($crumb['title_attr']))
			$formatted_breadcrumbs[] = sprintf('<strong title="%s">%s</strong>',
				$crumb['title_attr'], $crumb['title']
			);
		else
			$formatted_breadcrumbs[] = sprintf('<strong>%s</strong>', $crumb['title']);
	}
	if (!$formatted_breadcrumbs) return '';
	return implode(' '.wrap_setting('breadcrumbs_separator').' ', $formatted_breadcrumbs);
}

/**
 * get base URL for navigation
 * if 'language_not_in_nav' is set, remove language strings
 *
 * @return string
 */
function wrap_nav_base() {
	if (!wrap_setting('lang')) return '';
	$base = wrap_setting('base');
	if (wrap_setting('language_not_in_nav')) {
		if (substr($base, -strlen(wrap_setting('lang')) - 1) === '/'. wrap_setting('lang')) {
			 $base = substr($base, 0, -3);
		}
	}
	// cut last slash because breadcrumb URLs always start with slash
	if (substr($base, -1) === '/') 
		$base = substr($base, 0, -1);
	return $base;
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
		$page['title'] = wrap_text($page['title']);

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

	if (!empty($_POST['httpRequest']) AND substr($_POST['httpRequest'], 0, 6) !== 'zzform') {
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
	if (!empty($zz_page['url']['redirect'])) {
		// redirect to canonical URL or URL with language code
		wrap_redirect(wrap_glue_url($zz_page['url']['full']), 301, $zz_page['url']['redirect_cache']);
	}

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
	!empty($page['project']) OR $page['project'] = wrap_text(wrap_setting('project'));
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

	if (!empty($page['content_type_original']) AND wrap_setting('send_as_json')) {
		$page['text'] = json_encode([
			$page['content_type_original'] => $page['text'],
			'title' => $page['pagetitle'],
			'url' => $page['url'] ?? wrap_setting('request_uri')
		]);
	}

	if (!empty($page['content_type']) AND $page['content_type'] !== 'html') {
		if (empty($page['headers'])) $page['headers'] = [];
		wrap_send_text($page['text'], $page['content_type'], $page['status'], $page['headers']);
		exit;
	}
	
	// if globally dont_show_h1 is set, don't show it
	if (wrap_setting('dont_show_h1')) $page['dont_show_h1'] = true;

	if (!isset($page['text'])) $page['text'] = '';
	// init page
	if (file_exists($file = wrap_setting('custom').'/zzbrick_page/_init.inc.php'))
		require_once $file;
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
	if (in_array('breadcrumbs', $blocks))
		$page['breadcrumbs'] = wrap_breadcrumbs($page);
	if (in_array('nav', $blocks) AND wrap_db_connection()) {
		// get menus, if database connection active
		$page = wrap_get_menu($page);
		if (!empty($page['nav_db'])) {
			$page['nav'] = wrap_htmlout_menu($page['nav_db']);
			foreach (array_keys($page['nav_db']) AS $menu) {
				$page['nav_'.$menu] = wrap_htmlout_menu($page['nav_db'], $menu);
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
		$output = [
			'html' => $text,
			'title' => $page['pagetitle'],
			'url' => $page['url']
		];
		wrap_send_text(json_encode($output), 'json', $page['status']);
		exit;
	}

	wrap_send_text($text, 'html', $page['status']);
}

/**
 * check which block exist in template
 *
 * @param string $template name of template
 * @return array
 */
function wrap_check_blocks($template) {
	static $includes;
	if (empty($includes)) $includes = [];

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
 * include format.inc.php file from custom project or active module
 *
 * @return void
 */
function wrap_page_format_files() {
	static $included;
	if (empty($included)) $included = [];
	$format_files = wrap_collect_files('format', 'custom');
	$format_files += wrap_collect_files('format', 'active');
	foreach ($format_files as $package => $format_file) {
		if (in_array($package, $included)) continue;
		$included[] = $package;
		$new_functions = wrap_page_new_functions($format_file);
		$prefix = $package === 'custom' ? 'my' : 'mf_'.$package;
		wrap_page_add_format_functions($new_functions, $prefix);
	}
}

/**
 * get all function names in a file
 *
 * @param string $filed filename
 * @return array
 */
function wrap_page_new_functions($file) {
	$existing = get_defined_functions();
	require_once $file;
	$new = get_defined_functions();
	$diff = array_diff($new['user'], $existing['user']);
	return $diff;
}

/**
 * add functions to brick_formatting_functions, register prefix
 *
 * @param string $filed filename
 * @return array
 */
function wrap_page_add_format_functions($functions, $prefix) {
	foreach ($functions as $function) {
		if (str_starts_with($function, $prefix.'_')) {
			$function = substr($function, strlen($prefix.'_'));
			wrap_setting_add('brick_formatting_functions_prefix', [$function => $prefix]);
		}
		wrap_setting_add('brick_formatting_functions', $function);
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
