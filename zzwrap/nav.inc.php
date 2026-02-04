<?php

/**
 * zzwrap
 * page navigation (menu, breadcrumbs)
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 *	wrap_menu_get()				-- gets menu from database
 *		wrap_menu_navigation()	-- gets menu from separate navigation table
 *		wrap_menu_webpages()	-- gets menu from webpages table
 *	wrap_menu_out()				-- outputs menu in HTML
 *	wrap_breadcrumbs_read()		-- gets breadcrumbs from database
 *		wrap_breadcrumbs_read_recursive()	-- recursively gets breadcrumbs
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


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
function wrap_menu_get($page) {
	global $zz_page;
	
	if ($sql = wrap_sql_query('page_menu_hierarchy') AND !empty($zz_page['db']))
		$hierarchy = wrap_db_parents($zz_page['db'][wrap_sql_fields('page_id')], $sql);
	else
		$hierarchy = [];
	if (!empty($page['extra']['menu_hierarchy']))
		$hierarchy = array_merge($hierarchy, $page['extra']['menu_hierarchy']);
	
	$page['current_navitem'] = 0;
	$page['current_menu'] = '';

	if (wrap_sql_table('page_menu') === 'navigation') {
		// Menu from separate navigation table
		$menu = wrap_menu_navigation();
	} else {
		// Menu settings included in webpages table
		$menu = wrap_menu_webpages();
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
			elseif (!empty($item['menu_key']) AND wrap_setting('menu_with_id'))
				$menu[$id][$nav_id]['id'] = 'menu-'.wrap_create_id($item['menu_key']);
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
				if (!$item['url'])
					$menu[$id][$nav_id]['below'] = false;
				elseif ($item['url'] === wrap_setting('base_path').'/')
					// all pages are below homepage, don't highlight this
					$menu[$id][$nav_id]['below'] = false;
				else
					$menu[$id][$nav_id]['below']
						= (str_starts_with(wrap_setting('request_uri'), $item['url'])) ? true
						: (in_array($item[wrap_sql_fields('page_id')], $hierarchy) ? true : false);
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
function wrap_menu_navigation() {
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
		$my_item = wrap_menu_asterisk_check($item, $menu[$item['main_nav_id']], 'nav_id');
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
function wrap_menu_webpages() {
	// no menu query, so we don't have a menu
	if (!$sql = wrap_sql_query('page_menu')) return [];
	
	if (!wrap_database_table_check('/*_PREFIX_*/webpages_categories')) return [];
	
	wrap_menu_webpages_register();

	$menu = [];
	// get top menus
	$entries = wrap_db_fetch($sql, wrap_sql_fields('page_id'));
	if (!$entries) return [];
	if ($menu_table = wrap_sql_table('page_menu')) {
		$entries = wrap_translate($entries, $menu_table);
		$entries = wrap_menu_shorten($entries, $sql);
	}
	foreach ($entries as $line) {
		$items = wrap_menu_webpages_category($line['menu']);
		foreach ($items as $item) {
			$line['menu'] = $item;
			$my_item = wrap_menu_asterisk_check($line, $menu[$line['menu']]);
			if (!$my_item) continue;
			$menu[$line['menu']] = $my_item;
		}
	}
	if (!empty($_SESSION) AND function_exists('wrap_menu_session')) {
		wrap_menu_session($menu);
	}
	// get second (and third or fourth) hierarchy level
	$levels = [2, 3, 4];
	foreach ($levels as $level) {
		if (!wrap_setting('menu_level_'.$level)) continue;
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
			$items = wrap_menu_webpages_category($line['menu']);
			foreach ($items as $item) {
				$menu_key = $item.'-'.$line['top_ids'];
				// URLs ending in * or */ or *.html are different
				if ($my_item = wrap_menu_asterisk_check($line, $menu[$menu_key]))
					$menu[$menu_key] = $my_item;
			}
		}
	}
	return $menu;
}

/**
 * read all aliases per menu category ID
 *
 * @param string $menu
 * @return array
 */
function wrap_menu_webpages_category($menu) {
	$menu_ids = wrap_category_id('menu', 'list');
	$menu = explode(',', $menu);
	$keys = [];
	foreach ($menu as $category_id) {
		foreach ($menu_ids as $key => $id)
			if ($id === $category_id) $keys[] = substr($key, strpos($key, '/') + 1);
	}
	return $keys;
}

/**
 * check for main_menu = and the likes in menu categories
 * and register menus accordingly
 *
 */
function wrap_menu_webpages_register() {
	$sql = 'SELECT category_id, SUBSTRING_INDEX(path, "/", -1) AS path, parameters
		FROM /*_PREFIX_*/webpages_categories
		LEFT JOIN /*_PREFIX_*/categories USING (category_id)
		WHERE main_category_id = /*_ID categories menu _*/';
	$menus = wrap_db_fetch($sql, 'category_id');
	if (!$menus) return;
	$cfg = wrap_cfg_files('settings');
	foreach ($cfg as $key => $settings) {
		if (!str_ends_with($key, '_menu')) unset($cfg[$key]);
		elseif (empty($settings['scope'])) unset($cfg[$key]);
		elseif (!in_array('categories', $settings['scope'])) unset($cfg[$key]);
	}
	foreach ($menus as $menu) {
		if (!$menu['parameters']) continue;
		parse_str($menu['parameters'], $menu['parameters']);
		foreach ($cfg as $key => $settings) {
			if (!array_key_exists($key, $menu['parameters'])) continue;
			$menu_name = $menu['parameters']['alias'] ?? $menu['path'];
			if ($pos = strpos($menu_name, '/')) $menu_name = substr($menu_name, $pos + 1);
			wrap_setting($key, $menu_name);
		}
	}
}


/**
 * shorten translated menu entries
 *
 * @param array $entries
 * @param string $sql
 * @return array
 */
function wrap_menu_shorten($entries, $sql) {
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
 * @param string $id (optional, page_id or nav_id, depending on where data comes from)
 * @return array $menu
 */
function wrap_menu_asterisk_check($line, $menu, $id = 'page_id') {
	if (!$line['url'] OR (substr($line['url'], -1) !== '*' AND substr($line['url'], -2) !== '*/'
		AND substr($line['url'], -6) !== '*.html')) {
		if ($id === 'page_id') $id = wrap_sql_fields('page_id');
		$menu[$line[$id]] = $line;
		return $menu;
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
		if (!empty($menu)) {
			$menu += $menu_entries;
		} else {
			$menu = $menu_entries;
		}
	}
	return $menu ?? [];
}

/**
 * Gibt in HTML formatiertes Navigationsmenü von wrap_menu_get() aus
 * 
 * HTML-Ausgabe erfolgt als verschachtelte Liste mit id="menu" und role
 * auf oberster Ebene, darunter obj2, obj3, .. je nach Anzahl der Menüeinträge
 * aktuelle Seite wird mit '<strong>' ausgezeichnet. Gibt komplettes Menü
 * zurück
 * @param array $nav Ausgabe von wrap_menu_get();
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
function wrap_menu_out(&$nav, $menu_name = '', $page_id = 0, $level = 0, $avoid_duplicates = true) {
	static $menus = [];

	if (!$nav) return false;
	// avoid duplicate menus
	if ($avoid_duplicates)
		if (in_array($menu_name, $menus)) return false;
	
	// no menu_name: use default menu name but only if it exists, otherwise keep ''
	if (!$menu_name AND wrap_setting('main_menu'))
		$menu_name = wrap_setting('main_menu');

	if (!$menu_name OR is_numeric($menu_name)) {
		// if we have a separate navigation table, the $nav-array comes from
		// wrap_menu_navigation()
		$fn_page_id = 'nav_id';
	} else {
		// as default, menu comes from database table 'webpages'
		// wrap_menu_webpages()
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
		// @todo move to template or to wrap_menu_get()
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
			$item['submenu'] = wrap_menu_out($nav, $id, false, $level + 1, $avoid_duplicates);
		}
		$item['menu_'.$menu_name] = true;
		$item['identifier'] = $item['url'] ? wrap_filename(substr($item['url'], 1)) : $item['id'];
		$menu[] = $item;
	}
	$menu['pos'] = $nav[$menu_name]['pos'] ?? false;
	$menu['level'] = $level;
	$menu['menu_'.$menu_name] = true;
	$menu['template'] = 'menu-'.$menu_name;
	if ($level) $menu['is_submenu'] = true;
	if (wrap_template_file($menu['template'], false))
		$output = wrap_template($menu['template'], $menu);
	else
		$output = wrap_template('menu', $menu);
	if ($avoid_duplicates) {
		$menus[] = $menu_name;
	}
	return $output;
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
		$breadcrumbs = wrap_breadcrumbs_read($page_id);
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
	$breadcrumbs = wrap_breadcrumbs_prepare($breadcrumbs);
	
	$breadcrumbs_h1_prefix = wrap_breadcrumbs_h1_prefix($breadcrumbs);
	
	return [wrap_template('breadcrumbs', $breadcrumbs), $breadcrumbs_h1_prefix];
}

/**
 * Reads webpages from database, creates breadcrumbs hierarchy
 * 
 * @param int $page_id ID of current webpage in database
 * @return array breadcrumbs, hierarchical ('title' => title of menu, 'url_path' = link)
 */
function wrap_breadcrumbs_read($page_id) {
	if (!$sql = wrap_sql_query('page_breadcrumbs')) return [];
	global $zz_page;

	$breadcrumbs = [];
	// get all webpages
	if (!wrap_access('preview')) $sql = wrap_edit_sql($sql, 'WHERE', wrap_sql_fields('page_live'));
	$pages = wrap_db_fetch($sql, wrap_sql_fields('page_id'));
	if (wrap_setting('translate_fields'))
		$pages = wrap_translate($pages, wrap_sql_table('default_translation_breadcrumbs'), '', false);

	// get all breadcrumbs recursively
	$breadcrumbs = wrap_breadcrumbs_read_recursive($page_id, $pages);

	// check for sequential nav, tied to breadcrumbs
	wrap_nav_sequential($pages, $breadcrumbs);

	$breadcrumbs = array_reverse($breadcrumbs);
	wrap_breadcrumbs_placeholder($breadcrumbs);
	wrap_breadcrumbs_unique($breadcrumbs);
	$breadcrumbs = array_reverse($breadcrumbs);

	foreach ($breadcrumbs as $index => &$breadcrumb) {
		if (!$index) {
			// remove url_path of current page
			$breadcrumbs[0]['url_path'] = '';
			continue; // ignore current page
		}
		if (strpos($breadcrumb['url_path'], '*') === false) continue;
		// remove breadcrumbs with * in URL
		unset($breadcrumbs[$index]);
	}

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
function wrap_breadcrumbs_read_recursive($page_id, &$pages) {
	if (empty($pages[$page_id])) return []; // database connection failed
	$breadcrumbs[] = [
		'title' => $pages[$page_id]['title'],
		'url_path' => wrap_path_placeholder($pages[$page_id]['identifier'].($pages[$page_id]['ending'] ?? ''), '*'),
		'page_id' => $pages[$page_id][wrap_sql_fields('page_id')]
	];
	if ($pages[$page_id]['mother_page_id'] 
		&& !empty($pages[$pages[$page_id]['mother_page_id']]))
		$breadcrumbs = array_merge($breadcrumbs, 
			wrap_breadcrumbs_read_recursive($pages[$page_id]['mother_page_id'], $pages));
	return $breadcrumbs;
}

/**
 * Replace * placeholders in breadcrumbs with actual values from breadcrumb_placeholder
 *
 * @param array $breadcrumbs
 * @return void
 */
function wrap_breadcrumbs_placeholder(&$breadcrumbs) {
	global $zz_page;
	if (empty($zz_page['breadcrumb_placeholder'])) return;
	$placeholders = array_values($zz_page['breadcrumb_placeholder']);

	// replace first asterisk with placeholder url_path
	// add further breadcrumbs if `breadcrumb_placeholder` has more than one item
	$first_replacement_done = false;
	$last_placeholder = $placeholders[count($placeholders) - 1];
	foreach ($breadcrumbs as $index => $breadcrumb) {
		if (strpos($breadcrumb['url_path'], '*') === false) continue;
		if (!$first_replacement_done) {
			$first_replacement_done = true;

			// first breadcrumb with *: replace title and url_path with first placeholder
			$placeholder = array_shift($placeholders);
			$breadcrumbs[$index]['title'] = $placeholder['title'];
			$breadcrumbs[$index]['url_path'] = wrap_breadcrumbs_placeholder_path(
				$breadcrumb['url_path'], $placeholder['url_path']
			);

			if (!$placeholders) continue;

			// if there are more placeholders,
			// add them as new breadcrumbs after current one
			$new_breadcrumbs = [];
			foreach ($placeholders as $placeholder) {
				$new_breadcrumbs[] = [
					'title' => $placeholder['title'],
					'url_path' => wrap_breadcrumbs_placeholder_path(
						$breadcrumb['url_path'], $placeholder['url_path']
					),
					'page_id' => $breadcrumb['page_id'] ?? null
				];
			}
			
			// Insert new breadcrumbs after current position
			array_splice($breadcrumbs, $index + 1, 0, $new_breadcrumbs);
		} else {
			// get correct index after placeholders are added
			$index += count($placeholders);
			// subsequent breadcrumbs with *:
			// replace * with last placeholder's url_path only
			$breadcrumbs[$index]['url_path'] = wrap_breadcrumbs_placeholder_path(
				$breadcrumb['url_path'], $last_placeholder['url_path']
			);
		}
	}
}

/**
 * replace part of breadcrumb path with value from placeholder path
 *
 * @param string $breadcrumb_path
 * @param string $placeholder_path
 * @return string
 */
function wrap_breadcrumbs_placeholder_path($breadcrumb_path, $placeholder_path) {
	$pos = strpos($breadcrumb_path, '*');
	return substr_replace($breadcrumb_path, '/'.$placeholder_path, $pos, 1);
}

/**
 * remove duplicate url_paths, keeping only the first occurrence
 *
 * @param array $breadcrumbs
 * @return void
 */
function wrap_breadcrumbs_unique(&$breadcrumbs) {
	$seen_urls = [];
	foreach ($breadcrumbs as $index => $breadcrumb) {
		if (empty($breadcrumb['url_path'])) continue;
		if (in_array($breadcrumb['url_path'], $seen_urls)) {
			unset($breadcrumbs[$index]);
		} else {
			$seen_urls[] = $breadcrumb['url_path'];
		}
	}
	// get continuous indexes again
	$breadcrumbs = array_values($breadcrumbs);
}

/**
 * check different deprecated notations for breadcrumbs and rewrite these
 * remove links that are just placeholders
 *
 * @param array $breadcrumbs
 * @return array
 */
function wrap_breadcrumbs_prepare($breadcrumbs) {
	$new = [];
	foreach ($breadcrumbs as $index => $breadcrumb) {
		if (!is_array($breadcrumb)) {
			preg_match('/<a href="(.+)">(.+)<\/a>/', $breadcrumb, $matches);
			if (count($matches) === 3) {
				$new[$index] = [
					'title' => $matches[2],
					'url_path' => $matches[1]
				];
			} else {
				wrap_error(wrap_text('Unable to parse this breadcrumb : %s', ['values' => [$breadcrumb]]));
				$new[$index] = [
					'html' => $breadcrumb
				];
			}
		} elseif (array_key_exists('linktext', $breadcrumb)) {
			// @deprecated notation
			$new[$index] = [
				'title' => $crumb['linktext'],
				'url_path' => $crumb['url'] ?? '',
				'title_attr' => $crumb['title'] ?? ''
			];
			wrap_error('Use notation with `title` and `url_path` instead of `linktext` and `url`', E_USER_DEPRECATED);
		} else {
			$new[$index] = $breadcrumb;
		}
		if (!empty($breadcrumb['url_path'])) {
			$link = wrap_breadcrumbs_link($breadcrumb['url_path']);
			if (is_null($link)) unset($new[$index]);
			else $new[$index]['url_path'] = $link;
		}
	}
	return $new;
}

/**
 * do some link checks for breadcrumbs, add base if applicable
 *
 * @param string $url_path
 * @return mixed
 */
function wrap_breadcrumbs_link($url_path) {
	// don't show placeholder paths
	$paths = explode('/', $url_path);
	foreach ($paths as $path)
		if (str_starts_with($path, '%') AND str_ends_with($path, '%')) return NULL;
	if (str_starts_with($url_path, '.')) return $url_path;

	$url_path = wrap_nav_base().$url_path;
	$current = ($url_path === wrap_setting('request_uri') ? true : false);
	if ($current) return '';
	return $url_path;
}


/**
 * add prefix to h1 with breadcrumbs
 *
 * @param array $breadcrumbs
 * @return string
 * @todo allow different breadcrumb separators than for normal breadcrumbs
 */
function wrap_breadcrumbs_h1_prefix($breadcrumbs) {
	if (!wrap_setting('breadcrumbs_h1_prefix')) return '';
	array_pop($breadcrumbs);
	
	if (wrap_setting('breadcrumbs_h1_prefix') !== 'all') {
		while (count($breadcrumbs) > wrap_setting('breadcrumbs_h1_prefix'))
			array_shift($breadcrumbs);
	}
	return wrap_template('breadcrumbs-h1', $breadcrumbs);
}

//
//	common functions
//

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

function wrap_get_top_nav_id($menu) {
	wrap_error('@deprecated: Use `wrap_nav_top()` instead of `wrap_get_top_nav_id()`', E_USER_DEPRECATED);
	return wrap_nav_top($menu);
}

/**
 * gibt zur aktuellen Seite die ID der obersten Menüebene aus
 * 
 * @param array $menu Alle Menüeintrage, wie aus wrap_menu_get() zurückgegeben
 * @return int ID des obersten Menüs
 */
function wrap_nav_top($menu) {
	$top_nav = wrap_nav_top_recursive($menu);
	if ($top_nav) return $top_nav;
	
	// current page is not in this menu
	// check next page(s) in breadcrumb trail if it's (they're) in menu
	global $zz_page;
	// 404 and other error pages don't correspond to a database entry
	// so exit, this page is not in navigation
	if (empty($zz_page['db'])) return false;

	$breadcrumbs = wrap_breadcrumbs_read($zz_page['db'][wrap_sql_fields('page_id')]);
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
	$top_nav = wrap_nav_top_recursive($menu);
	return $top_nav;
}
	

/**
 * Hilfsfunktion zu wrap_nav_top()
 * 
 * @param array $menu Alle Menüeintrage, wie aus wrap_menu_get() zurückgegeben
 * @param int $nav_id internal value
 * @return int ID des obersten Menüs
 */
function wrap_nav_top_recursive($menu, $nav_id = false) {
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
					if ($index) $main_nav_id = wrap_nav_top_recursive($menu, $index);
					else $main_nav_id = $subindex;
				}
			} elseif ($subindex == $nav_id) {
				if ($index) $main_nav_id = wrap_nav_top_recursive($menu, $index);
				else $main_nav_id = $subindex;
			}
		}
	}
	return $main_nav_id;
}

/**
 * check for sequential nav
 *
 * @param array $pages
 * @return bool
 */
function wrap_nav_sequential($pages, $breadcrumbs) {
	global $zz_page;
	// not for error pages
	if (empty($zz_page['db']['page_id'])) return false;

	$page_ids = array_column($breadcrumbs, 'page_id');
	$filtered = array_intersect_key(
		$pages, array_flip(array_intersect($page_ids, array_column($pages, 'page_id')))
	);
	// only last two elements are interesting
	$filtered = array_slice($filtered, -2, 2, true);
	foreach ($filtered as $page_id => $page) {
		if (empty($page['parameters'])) continue;
		parse_str($page['parameters'], $page['parameters']);
		if (empty($page['parameters']['sequential_nav'])) continue;
		$sql = wrap_sql_query('page_sequential_nav');
		$sql = sprintf($sql, $page_id, $page_id, $page_id);
		$data = wrap_db_fetch($sql, wrap_sql_fields('page_id'));
		$data = wrap_translate($data, 'webpages');
		foreach ($data as $index => $line)
			$data[$index]['url'] = wrap_setting('base').$line['url'];
		list($zz_page['prev'], $zz_page['next'])
			= wrap_get_prevnext($data, $zz_page['db']['page_id'], false);
		if ($zz_page['prev'])
			$zz_page['prev']['rel_title'] = wrap_text('Previous page');
		if ($zz_page['next'])
			$zz_page['next']['rel_title'] = wrap_text('Next page');
		$top_id = key($data);
		if ($zz_page['db']['page_id'].'' !== $top_id.'') {
			$zz_page['up'] = $data[$top_id];
			$zz_page['up']['rel_title'] = wrap_text('Overview');
		}
		return true;
	}
	return false;
}
