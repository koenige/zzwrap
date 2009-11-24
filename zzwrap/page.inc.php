<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2008
// standard functions for page (menu, breadcrumbs, authors, page)


// intialize SQL-queries
$zz_sql['breadcrumbs'] = '';
$zz_sql['menu'] = '';
$zz_sql['menu_level2'] = '';
// If we like to have menus or breadcrumbs, put an SQL query here:
if (file_exists($zz_setting['custom_wrap_sql_dir'].'/sql-page.inc.php'))
	require_once $zz_setting['custom_wrap_sql_dir'].'/sql-page.inc.php';

/*	List of functions in this file

		wrap_get_menu()					-- gets menu from database
			wrap_get_menu_navigation()	-- gets menu from separate navigation table
			wrap_get_menu_webpages()		-- gets menu from webpages table
		wrap_htmlout_menu()				-- outputs menu in HTML
		wrap_get_breadcrumbs()			-- gets breadcrumbs from database
			wrap_get_breadcrumbs_recursive()	-- recursively gets breadcrumbs
		wrap_htmlout_breadcrumbs()		-- outputs breadcrumbs in HTML
		wrap_get_authors()				-- gets authors from database
		wrap_htmlout_page()				-- outputs webpage from %%%-template in HTML

*/

//
//	menu
//

/** Gets menu entries depending on source
 * 
 * reads menu settings from webpages- or navigation-table
 * global variables: 
 * $zz_setting['menu']
 *
 * @param none
 * @return array hierarchical menu, output of wrap_get_menu_...()-function
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_menu() {
	global $zz_setting;
	if (empty($zz_setting['menu'])) 
		$zz_setting['menu'] = 'webpages';
	
	// Menu from separate navigation table
	if ($zz_setting['menu'] == 'navigation') return wrap_get_menu_navigation();
	// Menu settings included in webpages table
	else return wrap_get_menu_webpages();
}

/** Liest Daten für Navigationsmenü aus der Datenbank aus, incl. Übersetzung
 * 
 * Die Funktion liest das Navigationsmenü aus, setzt die aktuelle Seite 
 * ('current_page'), setzt eine menu-ID, übersetzt die Menüeinträge und URLs
 * und gibt ein hierarchisches Array zurück
 * $zz_sql['menu'] expects: nav_id, title, main_nav_id, url
 *	optional parameters: id_title, rest is free
 * @param none
 * @return array $menu: 'title', 'url', 'current_page', 'id', 'subtitle'
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_menu_navigation() {
	global $zz_conf;
	global $zz_sql;
	global $zz_setting;

	// get data from database
	$unsorted_menu = wrap_db_fetch($zz_sql['menu'], 'nav_id');
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
	// set current_page, id, subtitle, url with base for _ALL_ menu items
	foreach (array_keys($menu) as $id) {
		foreach ($menu[$id] as $nav_id => $item) {
			// add base_url for non-http links
			if (substr($item['url'], 0, 1) == '/') 
				$menu[$id][$nav_id]['url'] = $zz_setting['base_url'].$item['url'];
			// mark current page in menus
			$menu[$id][$nav_id]['current_page'] = 
				($item['url'] == $_SERVER['REQUEST_URI'] ? true : false);
			// create ID for CSS, JavaScript
			if (function_exists('forceFilename') AND !empty($item['id_title']))
				$menu[$id][$nav_id]['id'] = 'menu-'.strtolower(forceFilename($item['id_title'], '-'));
			// initialize subtitle
			if (empty($item['subtitle'])) $menu[$id][$nav_id]['subtitle'] = '';
			// write everything into $nav array
		}
	}
	return $menu;
}

/** Liest Daten für Navigationsmenü aus der Datenbank aus
 *
 * $zz_sql['menu'] expects: page_id, title, (id_title), mother_page_id, url, menu
 * $zz_sql['menu_level2'] expects: page_id, title, (id_title), mother_page_id, url (function_url), menu
 * @param none
 * @return array $menu: 'title', 'url', 'current_page', 'id', 'subtitle'
 * @author Gustaf Mossakowski <gustaf@koenige.org>
*/
function wrap_get_menu_webpages() {
	global $zz_sql;
	global $zz_setting;
	if (empty($zz_sql['menu'])) return false; // no menu query, so we don't have a menu

	$menu = array();
	// get top menus
	$result = mysql_query($zz_sql['menu']);
	if ($result AND mysql_num_rows($result)) {
		while ($line = mysql_fetch_assoc($result)) {
			if (strstr($line['menu'], ',')) {
				$mymenus = explode(',', $line['menu']);
				foreach ($mymenus as $mymenu) {
					$line['menu'] = $mymenu;
					$menu[$mymenu][$line['page_id']] = $line;
				}
			} else {
				$menu[$line['menu']][$line['page_id']] = $line;
			}
		}
	}
	if (!empty($_SESSION) AND function_exists('wrap_menu_session')) {
		wrap_menu_session($menu);
	}
	// get second hierarchy level
	$sql = sprintf($zz_sql['menu_level2'], '"'.implode('", "', array_keys($menu)).'"');
	$result = mysql_query($sql);
	if ($result AND mysql_num_rows($result)) {
		while ($line = mysql_fetch_assoc($result)) {
			// URLs ending in * or */ or *.html are different
			if (substr($line['url'], -1) != '*' AND substr($line['url'], -2) != '*/'
				AND substr($line['url'], -6) != '*.html') {
				$menu['sub-'.$line['menu'].'-'.$line['mother_page_id']][$line['page_id']] = $line;
			} else {
				// get name of function either from sql query
				// (for multilingual pages) or from the part until *
				$url = (!empty($line['function_url']) ? $line['function_url'] 
					: substr($line['url'], 0, strrpos($line['url'], '*')+1));
				$menufunc = 'wrap_menu_'.substr($url, 1, -1);
				if (function_exists($menufunc)) {
					$menu['sub-'.$line['menu'].'-'.$line['mother_page_id']] = $menufunc($line);
				}
			}
		}
	}
	// set current_page, id, subtitle, url with base for _ALL_ menu items
	foreach (array_keys($menu) as $id) {
		foreach ($menu[$id] as $nav_id => $item) {
			// add base_url for non-http links
			if (substr($item['url'], 0, 1) == '/') 
				$menu[$id][$nav_id]['url'] = $zz_setting['base_url'].$item['url'];
			// mark current page in menus
			$menu[$id][$nav_id]['current_page'] = 
				($item['url'] == $_SERVER['REQUEST_URI'] ? true : false);
			// create ID for CSS, JavaScript
			if (function_exists('forceFilename') AND !empty($item['id_title']))
				$menu[$id][$nav_id]['id'] = 'menu-'.strtolower(forceFilename($item['id_title'], '-'));
			// initialize subtitle
			if (empty($item['subtitle'])) $menu[$id][$nav_id]['subtitle'] = '';
		}
	}
	return $menu;
}

/** Gibt in HTML formatiertes Navigationsmenü von wrap_get_menu() aus
 * 
 * HTML-Ausgabe erfolgt als verschachtelte Liste mit id="menu" und role
 * auf oberster Ebene, darunter obj2, obj3, .. je nach Anzahl der Menüeinträge
 * aktuelle Seite wird mit '<strong>' ausgezeichnet. Gibt komplettes Menü
 * zurück
 * global variables: 
 * $zz_setting['main_menu']
 * $zz_setting['menu_mark_active_open']
 * $zz_setting['menu_mark_active_close']
 * $zz_setting['never_display_submenues']
 * $zz_setting['show_all_menu_entries']
 *
 * @param $nav Ausgabe von wrap_get_menu();
 	required keys: 'title', 'url', 'current_page'
 	optional keys: 'long_title', 'id', 'class', 'subtitle'
 * @param $menu_name optional; 0 bzw. für Untermenüs $nav_id des jeweiligen Eintrags
 	oder Name des Menüs
 * @param $page_id optional; show only the one correspondig entry from the menu
 	and show it with a long title
 * @return string HTML-Output
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_htmlout_menu(&$nav, $menu_name = false, $page_id = false) {
	if (!$nav) return false;

	global $zz_setting;
	// display menu entries with all submenues
	if (!isset($zz_setting['show_all_menu_entries']))
		$zz_setting['show_all_menu_entries'] = false;
	// display menu entries - never any submenues
	if (!isset($zz_setting['never_display_submenues']))
		$zz_setting['never_display_submenues'] = false;
	
	// format active menu entry
	if (!isset($zz_setting['menu_mark_active_open']))
		$zz_setting['menu_mark_active_open'] = '<strong>';
	if (!isset($zz_setting['menu_mark_active_close']))
		$zz_setting['menu_mark_active_close'] = '</strong>';

	$output = false;
	// as default, menu comes from database table 'webpages'
	// wrap_get_menu_webpages()
	$fn_page_id = 'page_id';
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

	// OK, finally, we just get the menu together
	// $i to know which is the first-child, some old browsers don't support :first-child in CSS
	$i = 0;
	foreach ($nav[$menu_name] as $item) {
		if (empty($item['subtitle'])) $item['subtitle'] = '';
		if ($page_id AND $item[$fn_page_id] != $page_id) continue;
		$page_below = (substr($_SERVER['REQUEST_URI'], 0, strlen($item['url'])) == $item['url'] ? true : false);

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
			$output .= (!$item['current_page'] ? '</a>' : $zz_setting['menu_mark_active_close']);
		} 
		// get submenu if there is one and if it shall be shown
		if (!empty($nav[$fn_prefix.$item[$fn_page_id]]) // there is a submenu and at least one of:
			AND (!$zz_setting['never_display_submenues'])
			AND ($zz_setting['show_all_menu_entries'] 	// all menus shall be shown
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

//
//	breadcrumbs
//

/** Reads webpages from database, creates breadcrumbs hierarchy
 * 
 * needs global $zz_sql['breadcrumbs']!
 * @param $page_id(int) ID of current webpage in database
 * @return array breadcrumbs, hierarchical ('title' => title of menu, 'url_path' = link)
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_breadcrumbs($page_id) {
	global $zz_sql;
	if (empty($zz_sql['breadcrumbs'])) return array();

	$breadcrumbs = array();
	// get all webpages
	$pages = wrap_db_fetch($zz_sql['breadcrumbs'], 'page_id');
	// get all breadcrumbs recursively
	$breadcrumbs = wrap_get_breadcrumbs_recursive($page_id, $pages);
	// sort breadcrumbs in descending order
	krsort($breadcrumbs);
	// finished!
	return $breadcrumbs;
}

/** Creates breadcrumbs hierarchy, recursively
 * 
 * @param $page_id(int) ID of webpage in hierarchy in database
 * @param $pages(array) Array with all pages from database, indexed page_id
 * @return array breadcrumbs ('title' => title of menu, 'url_path' = link)
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_breadcrumbs_recursive($page_id, &$pages) {
	$breadcrumbs[] = array(
		'title' => $pages[$page_id]['title'],
		'url_path' => $pages[$page_id]['identifier']);
	if ($pages[$page_id]['mother_page_id'] 
		&& !empty($pages[$pages[$page_id]['mother_page_id']]))
		$breadcrumbs = array_merge($breadcrumbs, 
			wrap_get_breadcrumbs_recursive($pages[$page_id]['mother_page_id'], $pages));
	return $breadcrumbs;
}

/** Creates html output of breadcrumbs, retrieves breadcrumbs from database
 * 
 * @param $page_id(int) ID of webpage in hierarchy in database
 * @param $brick_breadcrumbs(array) Array of additional breadcrumbs from brick_format()
 * @return string HTML output, plain linear, of breadcrumbs
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
 function wrap_htmlout_breadcrumbs($page_id, $brick_breadcrumbs) {
	global $zz_page;
	$breadcrumbs = array();
	$html_output = false;
	// set default values
	if (empty($zz_page['breadcrumbs_separator']))
		$zz_page['breadcrumbs_separator'] = '&gt;';
	if (empty($zz_page['base']))
		$zz_page['base'] = '/';
	// cut last slash because breadcrumb URLs always start with slash
	if (substr($zz_page['base'], -1) == '/') 
		$zz_page['base'] = substr($zz_page['base'], 0, -1);

	// get breadcrumbs from database
	$breadcrumbs = wrap_get_breadcrumbs($page_id);
	// if there are breadcrumbs returned from brick_format, remove the last
	// and append later these breadcrumbs instead
	if (!empty($brick_breadcrumbs)) array_pop($breadcrumbs);

	// format breadcrumbs
	$formatted_breadcrumbs = array();
	foreach ($breadcrumbs as $crumb)
		$formatted_breadcrumbs[] = 
			($crumb['url_path'] == $_SERVER['REQUEST_URI'] ? '<strong>' : '<a href="'.$zz_page['base'].$crumb['url_path'].'">')
			.$crumb['title']
			.($crumb['url_path'] == $_SERVER['REQUEST_URI'] ? '</strong>' : '</a>');
	if (!$formatted_breadcrumbs) return false;
	
	$html_output = implode(' '.$zz_page['breadcrumbs_separator'].' ', $formatted_breadcrumbs);
	if (!empty($brick_breadcrumbs)) {
		foreach ($brick_breadcrumbs as $index => $crumb) {
			if (is_array($crumb)) {
				$brick_breadcrumbs[$index] = 
					($crumb['url_path'] == $_SERVER['REQUEST_URI'] ? '<strong>' : '<a href="'.$zz_page['base'].$crumb['url_path'].'">')
					.$crumb['title']
					.($crumb['url_path'] == $_SERVER['REQUEST_URI'] ? '</strong>' : '</a>');
			}
		}
		$html_output.= ' '.$zz_page['breadcrumbs_separator']
		.' '.implode(' '.$zz_page['breadcrumbs_separator'].' ', $brick_breadcrumbs);
	}
	return $html_output;
}


//
//	authors
//

/** Reads authors from database, adds initials and gives back array
 * 
 * needs global $zz_sql['authors']!
 * @param $brick_authors IDs of authors
 * @param $author_id extra ID of author, may be false
 * @return array authors, person = name, initials = initials, lowercase
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_get_authors($brick_authors, $author_id = false) {
	global $zz_sql;
	if (empty($zz_sql['authors'])) return false;

	// add to extra page author to authos from brick_format()
	if ($author_id) $brick_authors[] = $author_id;
	
	$authors = wrap_db_fetch(sprintf($zz_sql['authors'], implode(', ', $brick_authors)), 'person_id');

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

/** Outputs a HTML page from a %%%-template
 * 
 * allow %%% page ... %%%-syntax
 * @param $brick_authors IDs of authors
 * @param $author_id extra ID of author, may be false
 * @return string HTML output
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_htmlout_page($page) {
	global $zz_setting;
	global $zz_page;
	
	require_once $zz_setting['lib'].'/zzbrick/zzbrick.php';

	// do not modify html, since this is a template
	$zz_setting['brick_fulltextformat'] = 'brick_textformat_html';
	if (empty($page['no_page_head']) AND empty($page['no_page_foot'])) {
		$output = brick_format($page['output'], $page);
		$page['output'] = $output['text'];
		$page_part = implode("", file($zz_page['brick_template']));
		$page_part = brick_format($page_part, $page);
		return trim($page_part['text']);
	}
	return false;	
}

?>