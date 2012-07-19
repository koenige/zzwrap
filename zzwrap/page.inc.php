<?php 

/**
 * zzwrap
 * Standard page functions (menu, breadcrumbs, authors, page)
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
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
 *	wrap_mailto()
 *	wrap_dates()
 *	wrap_print()
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2012 Gustaf Mossakowski
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
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_template($template, $data = array(), $mode = false) {
	global $zz_setting;

	// Template einbinden und füllen
	$tpl = $zz_setting['custom_wrap_template_dir'].'/'.$template.'.template.txt';
	if (!file_exists($tpl)) {
		// check if there's a default template
		$tpl = $zz_setting['wrap_template_dir'].'/'.$template.'.template.txt';
		if (!file_exists($tpl)) {
			global $zz_page;
			$error_msg = sprintf(wrap_text('Template <code>%s</code> does not exist.'), htmlspecialchars($template));
			if (!empty($zz_page['error_code']) AND $zz_page['error_code'] === 503) {
				echo $error_msg;
				return false;
			} else {
				wrap_quit(503, $error_msg);
			}
		}
	}
	$zz_setting['current_template'] = $template;
	$template = file($tpl);
	// remove comments and next empty line from the start
	foreach ($template as $index => $line) {
		if (substr($line, 0, 1) == '#') unset($template[$index]); // comments
		elseif (!trim($line)) unset($template[$index]); // empty lines
		else break;
	}
	$template = implode("", $template);
	// now we have the template as string, in case of error, return
	if ($mode === 'error') return $template;

	// replace placeholders in template
	// save old setting regarding text formatting
	if (!isset($zz_setting['brick_fulltextformat'])) 
		$zz_setting['brick_fulltextformat'] = '';
	$old_brick_fulltextformat = $zz_setting['brick_fulltextformat'];
	// apply new text formatting
	$zz_setting['brick_fulltextformat'] = 'brick_textformat_html';
	$page = brick_format($template, $data);
	// restore old setting regarding text formatting
	$zz_setting['brick_fulltextformat'] = $old_brick_fulltextformat;

	// get rid of if / else text that will be put to hidden
	if (count($page['text']) == 2 
		AND is_array($page['text'])
		AND in_array('_hidden_', array_keys($page['text']))
		AND in_array($zz_setting['brick_default_position'], array_keys($page['text']))) {
		unset($page['text']['_hidden_']);
		$page['text'] = end($page['text']);
	}
	if ($mode === 'ignore positions' AND is_array($page['text']) AND count($page['text']) == 1) {
		$page['text'] = current($page['text']);
	}
	// check if errors occured while filling in the template
	wrap_page_check_if_error($page);
	return $page['text'];
}

/**
 * Creates valid HTML id value from string
 *
 * @param string $id_title string to be formatted
 * @return string $id_title
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_create_id($id_title) {
	$not_allowed_in_id = array('(', ')');
	foreach ($not_allowed_in_id as $char) {
		$id_title = str_replace($char, '', $id_title);
	}
	$id_title = strtolower(forceFilename($id_title));
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
 * global variables: 
 * $zz_setting['menu']
 *
 * @return array $menu: 'title', 'url', 'current_page', 'id', 'subtitle'
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_menu() {
	global $zz_setting;
	if (empty($zz_setting['menu'])) 
		$zz_setting['menu'] = 'webpages';
	
	if ($zz_setting['menu'] == 'navigation') {
		// Menu from separate navigation table
		$menu = wrap_get_menu_navigation();
	} else {
		// Menu settings included in webpages table
		$menu = wrap_get_menu_webpages();
	}
	if (empty($menu)) return array();

	// set current_page, id, subtitle, url with base for _ALL_ menu items
	foreach (array_keys($menu) as $id) {
		foreach ($menu[$id] as $nav_id => $item) {
			// add base_url for non-http links
			if (substr($item['url'], 0, 1) == '/') 
				$menu[$id][$nav_id]['url'] = $zz_setting['base'].$item['url'];
			// mark current page in menus
			$menu[$id][$nav_id]['current_page'] = 
				($item['url'] == $_SERVER['REQUEST_URI']) ? true : false;
			// create ID for CSS, JavaScript
			if (function_exists('forceFilename') AND !empty($item['id_title']))
				$menu[$id][$nav_id]['id'] = 'menu-'.wrap_create_id($item['id_title'], '-');
			// initialize subtitle
			if (empty($item['subtitle'])) $menu[$id][$nav_id]['subtitle'] = '';
		}
	}
	return $menu;
}

/**
 * Read data for menu from db table 'navigation', translate if required
 * Liest Daten für Menü aus der DB-Tabelle 'navigation' aus, übersetzt ggf. Menü
 * 
 * @return array $menu: 'title', 'url', 'subtitle' (optional), 'id_title' (optional)
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_menu_navigation() {
	global $zz_setting;
	global $zz_conf;
	// no menu query, so we don't have a menu
	if (!wrap_sql('menu')) return array();

	// get data from database
	$unsorted_menu = wrap_db_fetch(wrap_sql('menu'), 'nav_id');
	// translation if there's a function for it
	if (function_exists('wrap_translate_menu'))
		$unsorted_menu = wrap_translate_menu($unsorted_menu);
	// write database output into hierarchical array
	foreach ($unsorted_menu as $item) {
		if ($item['title'] == '*') {
			$menufunc = 'wrap_menu_'.substr($item['url'], 1, strrpos($item['url'], '*')-1);
			if (function_exists($menufunc)) {
				$entries = $menufunc($item);
				if ($entries) foreach ($entries as $index => $entry) {
					$menu[$item['main_nav_id']][$item['nav_id'].'-'.$index] = $entry;
				}
			}
		} else {
			$menu[$item['main_nav_id']][$item['nav_id']] = $item;
		}
	}
	return $menu;
}

/**
 * Read data for menu from db table 'webpages/, translate if required
 * Liest Daten für Menü aus der DB-Tabelle 'webpages' aus, übersetzt ggf. Menü
 *
 * @return array $menu: 'title', 'url', 'subtitle'
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_menu_webpages() {
	global $zz_setting;
	// no menu query, so we don't have a menu
	if (!$sql = wrap_sql('menu')) return array(); 

	$menu = array();
	// get top menus
	$entries = wrap_db_fetch($sql, wrap_sql('page_id'));
	if ($menu_table = wrap_sql('menu_table'))
		$entries = wrap_translate($entries, $menu_table);
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
	// get second hierarchy level
	if ($sql = wrap_sql('menu_level2')) {
		$sql = sprintf($sql, '"'.implode('", "', array_keys($menu)).'"');
		$entries = wrap_db_fetch($sql, wrap_sql('page_id'));
		if ($menu_table = wrap_sql('menu_table'))
			$entries = wrap_translate($entries, $menu_table);
		foreach ($entries as $line) {
			$menu_key = 'sub-'.$line['menu'].'-'.$line['mother_page_id'];
			// URLs ending in * or */ or *.html are different
			if ($my_item = wrap_menu_asterisk_check($line, $menu, $menu_key))
				$menu[$menu_key] = $my_item;
		}
	}
	return $menu;
}

/**
 * checks if URL ends in * and if yes, returns function output or nothing
 *
 * @param array $line
 * @param array $menu
 * @param string $menu_key
 * @return array $menu[$menu_key]
 */
function wrap_menu_asterisk_check($line, $menu, $menu_key) {
	if (substr($line['url'], -1) != '*' AND substr($line['url'], -2) != '*/'
		AND substr($line['url'], -6) != '*.html') {
		$menu[$menu_key][$line[wrap_sql('page_id')]] = $line;
	}
	// get name of function either from sql query
	// (for multilingual pages) or from the part until *
	$url = (!empty($line['function_url']) ? $line['function_url'] 
		: substr($line['url'], 0, strrpos($line['url'], '*')+1));
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
	if (!empty($menu[$menu_key])) return $menu[$menu_key];
	return false;
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
 * @global array $zz_setting
 *		'main_menu', 'menu_mark_active_open', 'menu_mark_active_close',
 *		'menu_display_submenu_items'
 * @return string HTML-Output
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_htmlout_menu(&$nav, $menu_name = false, $page_id = false) {
	if (!$nav) return false;

	global $zz_setting;
	
	// when to display submenu items
	// 'all': always display all submenu items
	// 'current': only display submenu items when item from menu branch is selected
	// 'none'/false: never display submenu items
	if (!isset($zz_setting['menu_display_submenu_items'])) 
		$zz_setting['menu_display_submenu_items'] = 'current';
		
	// format active menu entry
	if (!isset($zz_setting['menu_mark_active_open']))
		$zz_setting['menu_mark_active_open'] = '<strong>';
	if (!isset($zz_setting['menu_mark_active_close']))
		$zz_setting['menu_mark_active_close'] = '</strong>';

	$output = false;
	// as default, menu comes from database table 'webpages'
	// wrap_get_menu_webpages()
	$fn_page_id = wrap_sql('page_id');
	$fn_prefix = 'sub-'.$menu_name.'-';
	// no menu_name: use default menu name
	if (!$menu_name AND !empty($zz_setting['main_menu'])) {
		$menu_name = $zz_setting['main_menu'];
		$fn_prefix = 'sub-'.$menu_name.'-';
	}

	// if we have a separate navigation table, the $nav-array comes from
	// wrap_get_menu_navigation()
	if (!$menu_name OR is_numeric($menu_name)) {
		$fn_page_id = 'nav_id';
		$fn_prefix = '';
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
	// and add these entries to the main menu
	if (!empty($zz_setting['session_menu']) AND !empty($_SESSION['logged_in'])
		AND !empty($nav[$zz_setting['session_menu']])
		AND $menu_name === $zz_setting['main_menu']) {
		$nav[$menu_name] = array_merge($nav[$menu_name], $nav[$zz_setting['session_menu']]);
	}

	// OK, finally, we just get the menu together
	// $i to know which is the first-child, some old browsers don't support :first-child in CSS
	$i = 0;
	foreach ($nav[$menu_name] as $item) {
		if (empty($item['subtitle'])) $item['subtitle'] = '';
		if ($page_id AND $item[$fn_page_id] != $page_id) continue;
		if (isset($item['ignore'])) continue;
		$page_below = (substr($_SERVER['REQUEST_URI'], 0, strlen($item['url'])) == $item['url']) ? true : false;

		$class = array();
		if (!$i) $class[] = 'first-child';
		if ($i == count($nav[$menu_name])-1) $class[] = 'last-child';
		if (!empty($item['class'])) $class[] = $item['class'];
		// output each navigation entry with its id, first entry in a ul as first-child
		$output .= "\t".'<li'.(!empty($item['id']) ? ' id="'.$item['id'].'"' : '')
			.($class ? ' class="'.implode(' ', $class).'"' : '').'>';
		if ($item['url']) {
			$output .= (!$item['current_page'] ? '<a href="'.$item['url'].'"'
				.($page_below ? ' class="below"' : '')
				.(!empty($item['long_title']) ? ' title="'.$item['long_title'].'"' : '')
				.'>' : $zz_setting['menu_mark_active_open']);
		} 
		$title = ($page_id ? ucfirst($item['long_title']) : ucfirst($item['title'])).$item['subtitle'];
		$title = str_replace('& ', '&amp; ', $title);
		$title = str_replace('-', '-&shy;', $title);
		$output .= $title;
		if ($item['url']) {
			$output .= !$item['current_page'] ? '</a>' : $zz_setting['menu_mark_active_close'];
		} 
		// get submenu if there is one and if it shall be shown
		if (!empty($nav[$fn_prefix.$item[$fn_page_id]]) // there is a submenu and at least one of:
			AND ($zz_setting['menu_display_submenu_items'] != 'none')
			AND ($zz_setting['menu_display_submenu_items'] == 'all' 	// all menus shall be shown
				OR $item['current_page'] 				// it's the submenu of the current page
				OR $page_below)) {						// it has a url one level below this page
			$id = $fn_prefix.$item[$fn_page_id];
			$output .= "\n".'<ul class="submenu obj'.count($nav[$id]).'">'."\n";
			$output .= wrap_htmlout_menu($nav, $id);
			$output .= '</ul>'."\n";
		}
		$output .= '</li>'."\n";
		$i++;
	}
	
	return $output;
}


/**
 * gibt zur aktuellen Seite die ID der obersten Menüebene aus
 * 
 * @param array $menu Alle Menüeintrage, wie aus wrap_get_menu() zurückgegeben
 * @return int ID des obersten Menüs
 * @author Gustaf Mossakowski <gustaf@koenige.org>
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

	$breadcrumbs = wrap_get_breadcrumbs($zz_page['db'][wrap_sql('page_id')]);
	array_pop($breadcrumbs); // own page, we do not need this
	while ($breadcrumbs) {
		$upper_breadcrumb = array_pop($breadcrumbs);
		// set current_page for next upper page in hierarchy
		foreach (array_keys($menu) as $main_nav_id) {
			foreach ($menu[$main_nav_id] as $nav_id => $element) {
				if ($element[wrap_sql('page_id')] == $upper_breadcrumb['page_id']) {
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
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_top_nav_recursive($menu, $nav_id = false) {
	$main_nav_id = false;
	foreach (array_keys($menu) as $index) {
		foreach (array_keys($menu[$index]) AS $subindex) {
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
 * Reads webpages from database, creates breadcrumbs hierarchy
 * 
 * @param int $page_id ID of current webpage in database
 * @global array $zz_conf
 * @return array breadcrumbs, hierarchical ('title' => title of menu, 'url_path' = link)
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_breadcrumbs($page_id) {
	if (!($sql = wrap_sql('breadcrumbs'))) return array();
	global $zz_conf;

	$breadcrumbs = array();
	// get all webpages
	if (!wrap_rights('preview')) $sql = wrap_edit_sql($sql, 'WHERE', wrap_sql('is_public'));
	$pages = wrap_db_fetch($sql, wrap_sql('page_id'));
	if ($zz_conf['translations_of_fields']) {
		$pages = wrap_translate($pages, wrap_sql('translation_matrix_breadcrumbs'), '', false);
	}

	// get all breadcrumbs recursively
	$breadcrumbs = wrap_get_breadcrumbs_recursive($page_id, $pages);
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
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_breadcrumbs_recursive($page_id, &$pages) {
	$breadcrumbs[] = array(
		'title' => $pages[$page_id]['title'],
		'url_path' => $pages[$page_id]['identifier'],
		'page_id' => $pages[$page_id][wrap_sql('page_id')]
	);
	if ($pages[$page_id]['mother_page_id'] 
		&& !empty($pages[$pages[$page_id]['mother_page_id']]))
		$breadcrumbs = array_merge($breadcrumbs, 
			wrap_get_breadcrumbs_recursive($pages[$page_id]['mother_page_id'], $pages));
	return $breadcrumbs;
}

/**
 * Creates html output of breadcrumbs, retrieves breadcrumbs from database
 * 
 * @param int $page_id ID of webpage in hierarchy in database
 * @param array $brick_breadcrumbs Array of additional breadcrumbs from brick_format()
 * @return string HTML output, plain linear, of breadcrumbs
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
 function wrap_htmlout_breadcrumbs($page_id, $brick_breadcrumbs) {
	global $zz_page;
	global $zz_setting;
	
	// get breadcrumbs from database
	$breadcrumbs = wrap_get_breadcrumbs($page_id);
	if (!$breadcrumbs) return false;

	$html_output = false;
	// set default values
	if (empty($zz_page['breadcrumbs_separator']))
		$zz_page['breadcrumbs_separator'] = '&gt;';
	if (empty($zz_setting['base'])) $zz_setting['base'] = '';
	// cut last slash because breadcrumb URLs always start with slash
	if (substr($zz_setting['base'], -1) == '/') 
		$zz_setting['base'] = substr($zz_setting['base'], 0, -1);

	// if there are breadcrumbs returned from brick_format, remove the last
	// and append later these breadcrumbs instead
	if (!empty($brick_breadcrumbs)) array_pop($breadcrumbs);

	// format breadcrumbs
	$formatted_breadcrumbs = array();
	foreach ($breadcrumbs as $crumb) {
		// don't show placeholder paths
		if (substr($crumb['url_path'], 0, 2) == '/%' AND substr($crumb['url_path'], -2) == '%/') continue;
		$current = ($zz_setting['base'].$crumb['url_path'] == $_SERVER['REQUEST_URI'] ? true : false);
		$formatted_breadcrumbs[] = 
			(($current OR !$crumb['url_path'])
				? '<strong>' : '<a href="'.$zz_setting['base'].$crumb['url_path'].'">')
			.$crumb['title']
			.(($current OR !$crumb['url_path']) ? '</strong>' : '</a>');
	}
	if (!$formatted_breadcrumbs) return false;
	
	$html_output = implode(' '.$zz_page['breadcrumbs_separator'].' ', $formatted_breadcrumbs);
	if (!empty($brick_breadcrumbs)) {
		foreach ($brick_breadcrumbs as $index => $crumb) {
			if (!is_array($crumb)) continue;
			$current = ($zz_setting['base'].$crumb['url_path'] == $_SERVER['REQUEST_URI'] ? true : false);
			$brick_breadcrumbs[$index] = 
				(($current OR !$crumb['url_path'])
					? '<strong>' : '<a href="'.$zz_setting['base'].$crumb['url_path'].'">')
				.$crumb['title']
				.(($current OR !$crumb['url_path'])
					? '</strong>' : '</a>');
		}
		$html_output.= ' '.$zz_page['breadcrumbs_separator']
			.' '.implode(' '.$zz_page['breadcrumbs_separator'].' ', $brick_breadcrumbs);
	}
	return $html_output;
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
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_authors($brick_authors, $author_id = false) {
	if (!($sql = wrap_sql('authors'))) return false;

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
	$last_update = '';
	if (!empty($page['last_update'])) {
		$last_update = $page['last_update'];
	}
	if (!$last_update) {
		$last_update = $zz_page['db'][wrap_sql('lastupdate')];
		$last_update = datum_de($last_update);
	}
	return $last_update;
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
	$media = !empty($page['media']) ? $page['media'] : array();
	if (function_exists('wrap_get_media')) {
		$page_id = $zz_page['db'][wrap_sql('page_id')];
		$media = array_merge(wrap_get_media($page_id), $media);
	}
	return $media;
}

/**
 * get page main H1 element; default: from brick script, 2nd choice: database
 * 
 * @param array $page
 * @global array $zz_page
 * @return array
 */
function wrap_page_h1($page) {
	global $zz_page;
	if (!empty($page['title']))
		$title = $page['title'];
	else
		$title = $zz_page['db'][wrap_sql('title')];
	if (!empty($zz_setting['translate_page_title']))
		$title = wrap_text($title);
	return $title;
}

/**
 * get value for HTML title element
 *
 * @param array $page
 * @global array $zz_page
 * @return string HTML code for title
 */
function wrap_page_title($page) {
	global $zz_page;
	$pagetitle = strip_tags($page['title']);
	if ($zz_page['url']['full']['path'] == '/')
		$pagetitle = sprintf($zz_page['template_pagetitle_home'], $pagetitle, $page['project']);
	else
		$pagetitle = sprintf($zz_page['template_pagetitle'], $pagetitle, $page['project']);
	return $pagetitle;
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
			$msg = vsprintf(wrap_text($page['error']['msg_text']), $page['error']['msg_vars']);
		} elseif (!empty($page['error']['msg_text'])) {
			$msg = wrap_text($page['error']['msg_text']);
		} else {
			$msg = wrap_text('zzbrick returned with an error. Sorry, that\'s all we know.');
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
 * @global array $zz_setting
 * @global array $zz_conf
 * @global array $zz_page
 */
function wrap_get_page() {
	global $zz_setting;
	global $zz_conf;
	global $zz_page;
	require_once $zz_setting['lib'].'/zzbrick/zzbrick.php';
	
	$page = brick_format($zz_page['db'][wrap_sql('content')], $zz_page['db']['parameter']);
	wrap_page_check_if_error($page);

	if (!empty($page['content_type']) AND $page['content_type'] != 'html') {
		if (empty($page['headers'])) $page['headers'] = array();
		wrap_send_text($page['text'], $page['content_type'], $page['status'], $page['headers']);
	}

	if (!empty($page['no_output'])) exit;

	$zz_page['url'] = wrap_check_canonical($zz_page, $page);
	wrap_redirect($zz_page['url']);

	$page['status']		= 200; // Seiteninhalt vorhanden!
	$page['lang']		= !empty($page['lang']) ? $page['lang'] : $zz_setting['lang'];
	$page['media']		= wrap_page_media($page);
	$page['title']		= wrap_page_h1($page);
	$page['project']	= !empty($page['project']) ? $page['project'] : $zz_conf['project'];
	$page['pagetitle']	= wrap_page_title($page);
	$page['nav_db']		= wrap_get_menu();
	$page[wrap_sql('lastupdate')] = wrap_page_last_update($page);
	if (!empty($zz_page['db'][wrap_sql('author_id')]))
		$page['authors'] = wrap_get_authors($page['authors'], $zz_page['db'][wrap_sql('author_id')]);

	$page['breadcrumbs'] = wrap_htmlout_breadcrumbs($zz_page['db'][wrap_sql('page_id')], $page['breadcrumbs']);
	
	return $page;
}

/**
 * Redirects to a canonical URL or a URL with language information etc.
 * 
 * @param array $url $zz_page['url']
 */
function wrap_redirect($url) {
	if (empty($url['redirect'])) return false;
	global $zz_setting;
	
	$base = !empty($zz_setting['base']) ? $zz_setting['base'] : '';
	if (substr($base, -1) == '/') $base = substr($base, 0, -1);
	wrap_http_status_header(301);
	$location = $zz_setting['host_base'].$base.$url['full']['path'];
	if (!empty($url['full']['query'])) $location .= '?'.$url['full']['query'];
	header('Location: '.$location);
	exit;
}

/**
 * HTML output of page without brick templates
 * deprecated class mix of HTML and PHP, not recommended for new projects
 *
 * @param array $page
 * @global array $zz_page
 * @global array $zz_conf
 * @return void
 */
function wrap_htmlout_page_without_templates($page) {
	global $zz_page;
	global $zz_conf;
	
	$page['nav'] = wrap_htmlout_menu($page['nav_db']);

	$output = '';
	if (function_exists('wrap_matrix')) {
		// Matrix for several projects
		$output = wrap_matrix($page, $page['media']);
	} else {
		if (empty($page['dont_show_h1']) AND empty($zz_page['dont_show_h1']))
			$output .= "\n".markdown('# '.$page['title']."\n")."\n";
		$output .= $page['text'];
	}
	if (function_exists('wrap_content_replace')) {
		$output = wrap_content_replace($output);
	}

	// Output page
	// set character set
	if (!empty($zz_conf['character_set']))
		header('Content-Type: text/html; charset='.$zz_conf['character_set']);

	if (empty($page['no_page_head'])) include $zz_page['head'];
	echo $output;
	if (!empty($zz_page['error_msg']) AND $page['status'] == 200) {
		// show error message in case there is one and it's not already shown
		// by wrap_errorpage() (status != 200)
		echo '<div class="error">'.$zz_page['error_msg'].'</div>'."\n";
	}
	if (empty($page['no_page_foot'])) include $zz_page['foot'];
	exit;
}

/**
 * Outputs a HTML page from a %%%-template
 * 
 * allow %%% page ... %%%-syntax
 * @param array $page
 * @return string HTML output
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_htmlout_page($page) {
	global $zz_setting;
	global $zz_page;
	global $zz_conf;

	// format page with brick_format()
	require_once $zz_setting['lib'].'/zzbrick/zzbrick.php';

	// if globally dont_show_h1 is set, don't show it
	if (!empty($zz_page['dont_show_h1'])) $page['dont_show_h1'] = true;

	if (!isset($page['text'])) $page['text'] = '';
	// init page
	if (file_exists($zz_setting['custom'].'/zzbrick_page/_init.inc.php'))
		require_once $zz_setting['custom'].'/zzbrick_page/_init.inc.php';

	// Use different template if set in function or _init
	if (!empty($page['template'])) {
		if (substr($page['template'], -5) != '-page')
			$page['template'] .= '-page';
		$template_path = $zz_setting['custom_wrap_template_dir']
			.'/'.$page['template'].'.template.txt';
		if (file_exists($template_path)) $zz_page['template'] = $page['template'];
	}

	// Add title to page
	if (empty($page['dont_show_h1']) AND !empty($page['title']))
		$page['text'] = "\n".markdown('# '.$page['title']."\n")."\n"
			.$page['text'];

	// bring together page output
	// do not modify html, since this is a template
	$zz_setting['brick_fulltextformat'] = 'brick_textformat_html';

	if (!empty($page['nav_db'])) {
		$page['nav'] = wrap_htmlout_menu($page['nav_db']);
		foreach (array_keys($page['nav_db']) AS $menu) {
			$page['nav_'.$menu] = wrap_htmlout_menu($page['nav_db'], $menu);
		}
	}
	$output = brick_format($page['text'], $page);
	$page['text'] = $output['text'];
	if (!empty($zz_page['error_msg']) AND $page['status'] == 200) {
		// show error message in case there is one and it's not already shown
		// by wrap_errorpage() (status != 200)
		$page['text'] .= $zz_page['error_msg']."\n";
	}

	$text = wrap_template($zz_page['template'], $page);
	wrap_send_text($text, 'html', $page['status']);
}

/**
 * HTML output of an E-Mail-Link, nice way with Name encoded
 * small protection against spammers
 *
 * @param string $person name of the person
 * @param string $mail e-mail address
 * @param string $attributes (optional attributes for the anchor)
 * @return string HTML anchor with mailto-Link
 */
function wrap_mailto($person, $mail, $attributes = false) {
	$mailto = str_replace('@', '&#64;', urlencode('<'.$mail.'>'));
	$mail = str_replace('@', '&#64;', $mail);
	$output = '<a href="mailto:'.str_replace(' ', '%20', $person)
		.'%20'.$mailto.'"'.$attributes
		.'>'.$mail.'</a>';
	return $output;
}

/**
 * Format a date
 *
 * @param string $begin date in ISO format, e. g. "2004-03-12"
 * @param string $end date in ISO format, e. g. "2004-03-12"
 * @param string $format format which should be used:
 *		dates-de: 12.03.2004, 12.-14.03.2004, 12.04.-13.05.2004, 
 *			31.12.2004-06.01.2005
 *		rfc1123->datetime,
 *		rfc1123->timestamp,
 *		timestamp->rfc1123
 *		timestamp->datetime
 * @return string
 * @todo rewrite function so it is possible to use only one parameter
 */
function wrap_dates($begin, $end = '', $output_format = false) {
	global $zz_conf;
	global $zz_setting;
	if (!function_exists('datum_de')) 
		include_once $zz_conf['dir'].'/numbers.inc.php';

	if ($begin === $end) $end = '';

	if (!$output_format AND isset($zz_setting['date_format']))
		$output_format = $zz_setting['date_format'];
	if (!$output_format) {
		wrap_error('Please set at least a default format for wrap_dates().
			via $zz_setting["date_format"] = "dates-de" or so');
		return $begin;
	}
	
	if (strstr($output_format, '->')) {
		// reformat all inputs to timestamps
		$format = explode('->', $output_format);
		$input_format = $format[0];
		$output_format = $format[1];
	} else {
		$input_format = 'iso8601';
	}

	switch ($input_format) {
	case 'iso8601':
		break;
	case 'rfc1123':
		// input = Sun, 06 Nov 1994 08:49:37 GMT
		// remove GMT, so we are not affected by time zones and get UTC
		$time = strtotime(substr($begin, 0, -4));
		// @todo: what happens with dates outside the timestamp scope?
		break;
	case 'timestamp':
		// input = 784108177
		$time = $begin;
		break;
	default:
		wrap_error(sprintf('Unknown input format %s', $input_format));
		break;
	}

	switch ($output_format) {
	case 'dates-de':
		if (!$end) {
			// 12.03.2004 or 03.2004 or 2004
			$output = datum_de($begin);
		} elseif (substr($begin, 7) == substr($end, 7)
			AND substr($begin, 7) === '-00'
			AND substr($begin, 4) !== '-00-00') {
			// 2004-03-00 2004-04-00 = 03-04.2004
			$output = substr($begin, 5, 2).'&#8211;'.datum_de($end);
		} elseif (substr($begin, 0, 7) === substr($end, 0, 7)
			AND substr($begin, 7) !== '-00') {
			// 12.-14.03.2004
			$output = substr($begin, 8).'.&#8211;'.datum_de($end);
		} elseif (substr($begin, 0, 4) === substr($end, 0, 4)
			AND substr($begin, 7) !== '-00') {
			// 12.04.-13.05.2004
			$output = substr(datum_de($begin), 0, 6).'&#8203;&#8211;'.datum_de($end);
		} else {
			// 2004-03-00 2005-04-00 = 03.2004-04.2005
			// 2004-00-00 2005-00-00 = 2004-2005
			// 31.12.2004-06.01.2005
			$output = datum_de($begin).'&#8203;&#8211;'.datum_de($end);
		}
		return $output;
	case 'datetime':
		// output 1994-11-06 08:49:37
		return date('Y-m-d H:i:s', $time);
	case 'timestamp':
		// output = 784108177
		return $time;
	case 'rfc1123':
		// output Sun, 06 Nov 1994 08:49:37 GMT
		return gmdate('D, d M Y H:i:s', $time). ' GMT';
	}
	wrap_error(sprintf('Unknown output format %s', $format));
	return '';
}

/**
 * debug: print_r included in text so we do not get problems with headers, zip
 * etc.
 *
 * @param array $array
 * @return string
 */
function wrap_print($array, $color = 'FFF') {
	ob_start();
	echo '<pre style="text-align: left; background-color: #'.$color
		.'; position: relative; z-index: 10;">';
	print_R($array);
	echo '</pre>';
	return ob_get_clean();
}

?>