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
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2015 Gustaf Mossakowski
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
function wrap_template($template, $data = array(), $mode = false) {
	global $zz_setting;

	$tpl_file = wrap_template_file($template);
	$zz_setting['current_template'] = $template;
	$template = file($tpl_file);
	// remove comments and next empty line from the start
	foreach ($template as $index => $line) {
		if (substr($line, 0, 1) === '#') unset($template[$index]); // comments
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
	if (count($page['text']) === 2 
		AND is_array($page['text'])
		AND in_array('_hidden_', array_keys($page['text']))
		AND in_array($zz_setting['brick_default_position'], array_keys($page['text']))) {
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
 * @global array $zz_setting
 * @return string $filename
 */
function wrap_template_file($template, $show_error = true) {
	global $zz_setting;	

	$found = array();
	$tpl_file = $zz_setting['custom_wrap_template_dir'].'/'.$template.'.template.txt';
	if (!file_exists($tpl_file)) {
		// check if there's a module template
		if (strstr($template, '/')) {
			$templateparts = explode('/', $template);
			$my_module = array_shift($template_parts);
			$template = implode('/', $template_parts);
		} else {
			$my_module = '';
		}
		foreach ($zz_setting['modules'] as $module) {
			if ($my_module AND $module !== $my_module) continue;
			$tpl_file = $zz_setting['modules_dir'].'/'.$module.'/templates/'.$template.'.template.txt';
			if (file_exists($tpl_file)) $found[] = $tpl_file;
		}
		if (count($found) !== 1) {
			if (!$show_error) return false;
			global $zz_page;
			if (!$found) {
				$error_msg = sprintf(wrap_text('Template <code>%s</code> does not exist.'), wrap_html_escape($template));
			} else {
				$error_msg = sprintf(wrap_text('More than one template with the name <code>%s</code> exists.'), wrap_html_escape($template));
			}
			if (!empty($zz_page['error_code'])) {
				echo $error_msg;
				return false;
			} else {
				wrap_quit(503, $error_msg);
			}
		} else {
			$tpl_file = $found[0];
		}
	}
	return $tpl_file;
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
	if (!preg_match('~^[a-z0-9_]*$~', $id_title)) {
		$new_id = '';
		for ($i = 0; $i < strlen($id_title); $i++) {
			// 48-57 => 0-9; 97-122 => a-z
			if (ord($id_title[$i]) > 122) continue;
			if (ord($id_title[$i]) > 57 AND ord($id_title[$i]) < 97) continue;
			if (ord($id_title[$i]) < 48) continue;
			$new_id .= $id_title[$i];
		}
		$id_title = $new_id;
	}
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
 * global variables: 
 * $zz_setting['menu']
 *
 * @param array $page
 * @return array
 *	$page['nav_db']: 'title', 'url', 'current_page', 'id', 'subtitle'
 */
function wrap_get_menu($page) {
	global $zz_setting;
	if (empty($zz_setting['menu'])) 
		$zz_setting['menu'] = 'webpages';
	
	$page['current_navitem'] = 0;
	$page['current_menu'] = '';

	if ($zz_setting['menu'] === 'navigation') {
		// Menu from separate navigation table
		$menu = wrap_get_menu_navigation();
	} else {
		// Menu settings included in webpages table
		$menu = wrap_get_menu_webpages();
	}
	if (empty($menu)) return $page;

	// set current_page, id, subtitle, url with base for _ALL_ menu items
	foreach (array_keys($menu) as $id) {
		foreach ($menu[$id] as $nav_id => $item) {
			// add base_url for non-http links
			if (substr($item['url'], 0, 1) === '/') 
				$menu[$id][$nav_id]['url'] = $zz_setting['base'].$item['url'];
			// mark current page in menus
			$menu[$id][$nav_id]['current_page'] = 
				($menu[$id][$nav_id]['url'] === $_SERVER['REQUEST_URI']) ? true : false;
			if ($menu[$id][$nav_id]['current_page']) {
				$page['current_navitem'] = $nav_id;
				$page['current_menu'] = $id;
			}
			// create ID for CSS, JavaScript
			if (!empty($item['id_title']))
				$menu[$id][$nav_id]['id'] = 'menu-'.wrap_create_id($item['id_title']);
			// initialize subtitle
			if (empty($item['subtitle'])) $menu[$id][$nav_id]['subtitle'] = '';
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
	$menu = array();
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
	global $zz_setting;
	// no menu query, so we don't have a menu
	if (!$sql = wrap_sql('menu')) return array(); 

	$menu = array();
	// get top menus
	$entries = wrap_db_fetch($sql, wrap_sql('page_id'));
	if (!$entries) return false;
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
 * @param string $id (optional, page_id or nav_id, depending on where data comes from)
 * @return array $menu[$menu_key]
 */
function wrap_menu_asterisk_check($line, $menu, $menu_key, $id = 'page_id') {
	if (substr($line['url'], -1) !== '*' AND substr($line['url'], -2) !== '*/'
		AND substr($line['url'], -6) !== '*.html') {
		if ($id === 'page_id') $id = wrap_sql('page_id');
		$menu[$menu_key][$line[$id]] = $line;
		return $menu[$menu_key];
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
		$page_below = (substr($_SERVER['REQUEST_URI'], 0, strlen($item['url'])) === $item['url']) ? true : false;
		// all pages are below homepage, don't highlight this
		if ($item['url'] === '/') $page_below = false;

		$class = array();
		if (!$i) $class[] = 'first-child';
		if ($i === count($nav[$menu_name])-1) $class[] = 'last-child';
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
			AND ($zz_setting['menu_display_submenu_items'] !== 'none')
			AND ($zz_setting['menu_display_submenu_items'] === 'all' 	// all menus shall be shown
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
	if (substr($zz_setting['base'], -1) === '/') 
		$zz_setting['base'] = substr($zz_setting['base'], 0, -1);

	// if there are breadcrumbs returned from brick_format, remove the last
	// and append later these breadcrumbs instead
	if (!empty($brick_breadcrumbs)) array_pop($breadcrumbs);

	// format breadcrumbs
	$formatted_breadcrumbs = array();
	foreach ($breadcrumbs as $crumb) {
		// don't show placeholder paths
		$paths = explode('/', $crumb['url_path']);
		foreach ($paths as $path) {
			if (substr($path, 0, 1) === '%' AND substr($path, -1) === '%') continue 2;
		}
		$current = ($zz_setting['base'].$crumb['url_path'] === $_SERVER['REQUEST_URI'] ? true : false);
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
			$current = ($zz_setting['base'].$crumb['url_path'] === $_SERVER['REQUEST_URI'] ? true : false);
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
		$last_update = $last_update;
	}
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
	if ($zz_page['url']['full']['path'] === '/')
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

	if (!empty($_POST['httpRequest'])) {
		$page = brick_xhr($_POST, $zz_page['db']['parameter']);
	} else {
		$page = brick_format($zz_page['db'][wrap_sql('content')], $zz_page['db']['parameter']);
	}
	wrap_page_check_if_error($page);

	if (!empty($page['content_type']) AND $page['content_type'] !== 'html') {
		if (empty($page['headers'])) $page['headers'] = array();
		wrap_send_text($page['text'], $page['content_type'], $page['status'], $page['headers']);
	}

	if (!empty($page['no_output'])) exit;

	$zz_page['url'] = wrap_check_canonical($zz_page, $page);
	wrap_redirect($zz_page['url']);

	$page['status']		= 200; // Seiteninhalt vorhanden!
	!empty($page['lang']) OR $page['lang'] = $zz_setting['lang'];
	$page['media']		= wrap_page_media($page);
	$page['title']		= wrap_page_h1($page);
	!empty($page['project']) OR $page['project'] = wrap_text($zz_conf['project']);
	$page['pagetitle']	= wrap_page_title($page);
	$page				= wrap_get_menu($page);
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
	if (substr($base, -1) === '/') $base = substr($base, 0, -1);
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
		if (substr($page['template'], -5) !== '-page')
			$page['template'] .= '-page';
		$tpl_file = wrap_template_file($page['template'], false);
		if ($tpl_file) $zz_page['template'] = $page['template'];
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
	// allow %%% notation on page with an escaping backslash
	$text = str_replace('\%%%', '%%%', $text);
	wrap_send_text($text, 'html', $page['status']);
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
	$keys = array_keys($records);
	$pos = array_search($record_id, $keys);
	if ($pos === false) return array(0 => false, 1 => false);
	$prev = $pos - 1;
	if ($prev >= 0) {
		$return[0] = $records[$keys[$prev]];
	} else {
		if ($endless) $return[0] = $records[$keys[count($records) - 1]];
		else $return[0] = array();
	}
	$next = $pos + 1;
	if ($next < count($records)) {
		$return[1] = $records[$keys[$next]];
	} else {
		if ($endless) $return[1] = $records[$keys[0]];
		else $return[1] = array();
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
	$return = array();
	foreach ($prev as $key => $value) {
		$return['_prev_'.$key] = $value;
	}
	foreach ($next as $key => $value) {
		$return['_next_'.$key] = $value;
	}
	return $return;
}
