<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2008
// CMS core functions


// Local modifications to SQL queries
if (!empty($zz_setting['custom_wrap_sql_dir']) AND !empty($zz_conf['db_connection']))
	require_once $zz_setting['custom_wrap_sql_dir'].'/sql-core.inc.php';

/**
 * Test, whether URL contains a correct secret key to allow page previews
 * 
 * @param string $secret_key shared secret key
 * @param string $_GET['tle'] timestamp, begin of legitimite timeframe
 * @param string $_GET['tld'] timestamp, end of legitimite timeframe
 * @param string $_GET['tlh'] hash
 * @return bool $wrap_page_preview true|false i. e. true means show page, false don't
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_test_secret_key($secret_key) {
	$wrap_page_preview = false;
	if (!empty($_GET['tle']) && !empty($_GET['tld']) && !empty($_GET['tlh']))
		if (time() > $_GET['tle'] && time() < $_GET['tld'] && 
			$_GET['tlh'] == md5($_GET['tle'].'&'.$_GET['tld'].'&'.$secret_key)) {
			session_start();
			$_SESSION['wrap_page_preview'] = true;
			$wrap_page_preview = true;
		}
	return $wrap_page_preview;
}

/**
 * Tests whether URL is in database (or a part of it ending with *), or a part 
 * of it with placeholders
 * 
 * @param array $zz_conf zz configuration variables
 * @param array $zz_access zz access rights
 * @return array $page
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_look_for_page(&$zz_conf, &$zz_access, $zz_page) {
	// Variables
	global $zz_setting;
	global $zz_sql;
	$page = false;

	// Prepare URL for database request
	$url = wrap_read_url($zz_page['url']);
	$full_url[0] = $url['db'];

	// check for placeholders
	if (!empty($zz_page['url_placeholders'])) {
		// 1. cut url in parts
		$url_parts[0] = explode('/', $full_url[0]);
		$i = 1;
		// 2. replace parts that match with placeholders, if neccessary multiple times
		// note: twice the same fragment will only be replaced once, not both fragments
		// at the same time (e. g. /eng/eng/ is /%language%/eng/ and /eng/%language%/
		// but not /%language%/%language%/ because this would not make sense) 
		foreach ($zz_page['url_placeholders'] as $wildcard => $values) {
			foreach (array_keys($values) as $key) {
				foreach ($url_parts as $url_index => $parts) {
					foreach ($parts as $partkey => $part) {
						if ($part == $key) {
							// new URL parts, take the one that we match on as basis
							$url_parts[$i] = $url_parts[$url_index];
							// leftovers, get the ones as a basis we already have							
							if (!empty($leftovers[$url_index]))
								$leftovers[$i] = $leftovers[$url_index];
							// take current part and put it into leftovers
							$leftovers[$i][$partkey] = $url_parts[$i][$partkey];
							// overwrite current part with placeholder
							$url_parts[$i][$partkey] = '%'.$wildcard.'%';
							$full_url[$i] = implode('/', $url_parts[$i]); 
							$i++;
						}
					}
				}
			}
		}
	}
	
	// For request, remove ending (.html, /), but not for page root
	foreach ($full_url as $i => $my_url) {
		$loops[$i] = 0; // if more than one URL to be tested against: count of rounds
		$page[$i] = false;
		if (!$page[$i]) $parameter[$i] = false; // if more than one url will be checked, initialize variable
		while (!$page[$i]) {
			$loops[$i]++;
			$sql = sprintf($zz_sql['pages'], '/'.mysql_real_escape_string($my_url));
			if (!$zz_access['wrap_preview']) $sql.= ' AND '.$zz_sql['is_public'];
			$page[$i] = wrap_db_fetch($sql);
			if (empty($page[$i]) && strstr($my_url, '/')) { // if not found, remove path parts from URL
				if ($parameter[$i]) {
					$parameter[$i] = '/'.$parameter[$i]; // '/' as a separator for variables
					$my_url = substr($my_url, 0, -1); // remove '*'
				}
				$parameter[$i] = substr($my_url, strrpos($my_url, '/') +1).$parameter[$i];
				$my_url = substr($my_url, 0, strrpos($my_url, '/')).'*';
			} else {
				// something was found, get out of here
				// but get placeholders as parameters as well!
				if (!empty($leftovers[$i])) 
					$parameter[$i] = implode('/', $leftovers[$i]).($parameter[$i] ? '/'.$parameter[$i] : '');
				$url[$i] = $my_url;
				break;
			}
		}
		if (!$page[$i]) unset($loops[$i]);
	}
	if (empty($loops)) return false;
	
	// get best match, sort twice:
	// 1. get match with least loops
	// 2. get match with lowest index of loops
	asort($loops);
	asort($loops);
	$i = key($loops);
	$page = $page[$i];
	if (!$page) return false;

	$page['parameter'] = $parameter[$i];
	$page['url'] = $url[$i];
	return $page;
}

/**
 * Make canonical URLs (trailing slash, .html etc.)
 * 
 * @param array $page page array
 * @param string $ending ending of URL (/, .html, .php, none)
 * @return - redirect to correct URL if necessary
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_check_canonical($page, $ending, $request_uri) {
	global $zz_setting;
	global $zz_page;
	$base = (!empty($zz_setting['base']) ? $zz_setting['base'] : '');
	if (substr($base, -1) == '/') $base = substr($base, 0, -1);
	$location = "Location: ".$zz_setting['host_base'].$base;
	// correct ending
	switch ($ending) {
	case '/':
		if (substr($request_uri['path'], -5) == '.html') {
			header($location.substr($request_uri['path'], 0, -5).'/');
			exit;
		} elseif (substr($request_uri['path'], -4) == '.php') {
			header($location.substr($request_uri['path'], 0, -4).'/');
			exit;
		} elseif (substr($request_uri['path'], -1) != '/') {
			header($location.$request_uri['path'].'/');
			exit;
		}
	break;
	case '.html':
		if (substr($request_uri['path'], -1) == '/') {
			header($location.substr($request_uri['path'], 0, -1).'.html');
			exit;
		} elseif (substr($request_uri['path'], -4) == '.php') {
			header($location.substr($request_uri['path'], 0, -4).'.html');
			exit;
		} elseif (substr($request_uri['path'], -5) != '.html') {
			header($location.$request_uri['path'].'.html');
			exit;
		}
	break;
	case 'none':
	case 'keine':
		if (substr($request_uri['path'], -5) == '.html') {
			header($location.substr($request_uri['path'], 0, -5));
			exit;
		} elseif (substr($request_uri['path'], -1) == '/' AND strlen($request_uri['path']) > 1) {
			header($location.substr($request_uri['path'], 0, -1));
			exit;
		} elseif (substr($request_uri['path'], -4) == '.php') {
			header($location.substr($request_uri['path'], 0, -4));
			exit;
		}
	break;
	}
	// todo: allow different endings depending on CMS functions
}

/**
 * builds URL from REQUEST
 * 
 * @param array $url $url['full'] with result from parse_url
 * @return array $url with new keys ['db'] (URL in database), ['suffix_length']
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_read_url($url) {
	// better than mod_rewrite, because '&' won't always be treated correctly
	$url['db'] = $url['full']['path'];
	$url['suffix_length'] = (!empty($_GET['lang']) ? strlen($_GET['lang']) + 6 : 5);
	// cut '/' at the beginning and - if neccessary - at the end
	if (substr($url['db'], 0, 1) == '/') $url['db'] = substr($url['db'], 1);
	if (substr($url['db'], -1) == '/') $url['db'] = substr($url['db'], 0, -1);
	if (substr($url['db'], -5) == '.html') $url['db'] = substr($url['db'], 0, -5);
	if (substr($url['db'], -4) == '.php') $url['db'] = substr($url['db'], 0, -4);
	if (!empty($_GET['lang']))
		if (substr($url['db'], -$url['suffix_length']) == '.html.'.$_GET['lang']) 
			$url['db'] = substr($url['db'], 0, -$url['suffix_length']);
	return $url;
}

/**
 * Stops execution of script, check for redirects to other pages,
 * includes http error pages
 * 
 * The execution of the CMS will be stopped. The script test if there's
 * an entry for the URL in the redirect table to redirect to another page
 * If that's true, 301 or 302 codes redirect pages, 410 redirect to gone.
 * if no error code is defined, a 404 code and the corresponding error page
 * will be shown
 * @param int $errorcode HTTP Error Code, default value is 404
 * @return exits function with a redirect or an error document
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_quit($errorcode = 404) {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;
	global $zz_sql;
	$redir = false;

	// check for redirects, if there's a corresponding table.
	if (!empty($zz_setting['check_redirects'])) {
		$url = wrap_read_url($zz_page['url']);
		$url['db'] = mysql_real_escape_string($url['db']);
		$where_language = (!empty($_GET['lang']) 
			? ' OR '.$zz_sql['redirects_old_fieldname'].' = "/'
				.$url['db'].'.html.'.mysql_real_escape_string($_GET['lang']).'"'
			: ''
		);
		$sql = sprintf($zz_sql['redirects'], '/'.$url['db'], '/'.$url['db'], '/'.$url['db'], $where_language);
		// not needed anymore, but set to false hinders from getting into a loop
		// (wrap_db_fetch() will call wrap_quit() if table does not exist)
		$zz_setting['check_redirects'] = false; 
		$redir = wrap_db_fetch($sql);

		// If no redirect was found until now, check if there's a redirect above
		// above the current level with a placeholder (*)
		$parameter = false;
		$found = false;
		$break_next = false;
		if (!$redir) {
			while (!$found) {
				$sql = sprintf($zz_sql['redirects_*'], '/'.$url['db']);
				$redir = wrap_db_fetch($sql);
				if ($redir) break; // we have a result, get out of this loop!
				if (strrpos($url['db'], '/'))
					$parameter = '/'.substr($url['db'], strrpos($url['db'], '/')+1).$parameter;
				$url['db'] = substr($url['db'], 0, strrpos($url['db'], '/'));
				if ($break_next) break; // last round
				if (!strstr($url['db'], '/')) $break_next = true;
			}
			if ($redir) {
				// If there's an asterisk (*) at the end of the redirect
				// the cut part will be pasted to the end of the string
				if (substr($redir[$zz_sql['redirects_new_fieldname']], -1) == '*')
					$redir[$zz_sql['redirects_new_fieldname']] = substr($redir[$zz_sql['redirects_new_fieldname']], 0, -1).$parameter;
			}
		}
	}
	if (!$redir) $page['status'] = $errorcode; // we need this in the error script
	else $page['status'] = $redir['code'];

	// Set protocol
	$protocol = $_SERVER['SERVER_PROTOCOL'];
	if (!$protocol) $protocol = 'HTTP/1.0'; // default value

	// Check redirection code
	switch ($page['status']) {
	case 301:
		header($protocol." 301 Moved Permanently");
	case 302:
		// header 302 is sent automatically if using Location
		$new = parse_url($redir[$zz_sql['redirects_new_fieldname']]);
		if (!empty($new['scheme'])) {
			$new = $redir[$zz_sql['redirects_new_fieldname']];
		} else {
			$new = $zz_setting['host_base'].$zz_setting['base'].$redir[$zz_sql['redirects_new_fieldname']];
		}
		header("Location: ".$new);
		break;
	default: // 4xx, 5xx
		include_once $zz_setting['http_error_script'];
	}
	exit;
}

/**
 * Checks if HTTP request should be HTTPS request instead and vice versa
 * 
 * Function will redirect request to the same URL except for the scheme part
 * Attention: POST variables will get lost
 * @param array $zz_page Array with full URL in $zz_page['url']['full'], 
 *		this is the result of parse_url()
 * @param array $zz_setting settings, 'ignore_scheme' ignores redirect
 *		and 'protocol' defines the protocol wanted (http or https)
 * @return redirect header
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_check_https($zz_page, $zz_setting) {
	// if it doesn't matter, get out of here
	if ($zz_setting['ignore_scheme']) return true;

	// change from http to https or vice versa
	// attention: $_POST will not be preserved
	if ((!empty($_SERVER['HTTPS']) AND $_SERVER['HTTPS'] == 'on' AND $zz_setting['protocol'] == 'http')
		OR (empty($_SERVER['HTTPS']) AND $zz_setting['protocol'] == 'https')) {
		header('Location: '.$zz_setting['protocol'].'://'.$zz_page['url']['full']['host']
			.$zz_page['url']['full']['path']
			.(!empty($zz_page['url']['full']['query']) ? '?'.$zz_page['url']['full']['query'] : ''));
		exit;
	}
}

/**
 * Puts data from request into template and returns full page
 *
 * @param string $template Name of template that will be filled
 * @param array $data Data which will be used to fill the template
 * @return array $page
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_template($template, $data) {
	global $zz_setting;

	// Template einbinden und füllen
	$tpl = $zz_setting['custom_wrap_template_dir'].'/'.$template
		.'.template.txt';
	// save old setting regarding text formatting
	if (!isset($zz_setting['brick_fulltextformat'])) 
		$zz_setting['brick_fulltextformat'] = '';
	$old_brick_fulltextformat = $zz_setting['brick_fulltextformat'];
	// apply new text formatting
	$zz_setting['brick_fulltextformat'] = 'brick_textformat_html';
	$template = implode("", file($tpl));
	$page = brick_format($template, $data);
	// restore old setting regarding text formatting
	$zz_setting['brick_fulltextformat'] = $old_brick_fulltextformat;
	return $page;
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


/**
 * Fetches records from database and returns array
 * 
 * - without $id_field_name: expects exactly one record and returns
 * the values of this record as an array
 * - with $id_field_name: uses this name as unique key for all records
 * and returns an array of values for each record under this key
 * - with $id_field_name and $array_format = "key/value": returns key/value-pairs
 * - with $id_field_name = 'dummy' and $array_format = "single value": returns
 * just first value as an array e. g. [3] => 3
 * @param string $sql SQL query string
 * @param string $id_field_name optional, if more than one record will be 
 *	returned: required; field_name for array keys
 *  if it's an array with two strings, this will be used to construct a 
 *  hierarchical array for the returned array with both keys
 * @param string $format optional, currently implemented
 *	"key/value" = returns array($key => $value)
 *	"single value" = returns $value
 *	"object" = returns object
 *	"numeric" = returns lines in numerical array [0 ... n] instead of using field ids
 * @return array with queried database content
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @todo give a more detailed explanation of how function works
 */
function wrap_db_fetch($sql, $id_field_name = false, $format = false) {
	global $zz_conf;
	$lines = array();
	$result = mysql_query($sql);
	if ($result) {
		if (!$id_field_name) {
			// only one record
			if (mysql_num_rows($result) == 1) {
	 			if ($format == 'single value') {
					$lines = mysql_result($result, 0, 0);
	 			} elseif ($format == 'object') {
					$lines = mysql_fetch_object($result);
				} else {
					$lines = mysql_fetch_assoc($result);
				}
			}
 		} elseif (is_array($id_field_name) AND mysql_num_rows($result)) {
			if ($format == 'object') {
				while ($line = mysql_fetch_object($result)) {
					if (count($id_field_name) == 3) {
						$lines[$line->$id_field_name[0]][$line->$id_field_name[1]][$line->$id_field_name[2]] = $line;
					} else {
						$lines[$line->$id_field_name[0]][$line->$id_field_name[1]] = $line;
					}
				}
 			} else {
 				// default or unknown format
				while ($line = mysql_fetch_assoc($result)) {
		 			if ($format == 'single value') {
						// just get last field, make sure that it's not one of the id_field_names!
		 				$values = array_pop($line);
		 			} else {
		 				$values = $line;
		 			}
					if (count($id_field_name) == 3) {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]][$line[$id_field_name[2]]] = $values;
					} else {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]] = $values;
					}
				}
			}
 		} elseif (mysql_num_rows($result)) {
 			if ($format == 'single value') {
 				// you can reach this part here with a dummy id_field_name
 				// because no $id_field_name is needed!
				while ($line = mysql_fetch_array($result)) {
					$lines[$line[0]] = $line[0];
				}
 			} elseif ($format == 'key/value') {
 				// return array in pairs
				while ($line = mysql_fetch_array($result)) {
					$lines[$line[0]] = $line[1];
				}
			} elseif ($format == 'object') {
				while ($line = mysql_fetch_object($result))
					$lines[$line->$id_field_name] = $line;
			} elseif ($format == 'numeric') {
				while ($line = mysql_fetch_assoc($result))
					$lines[] = $line;
 			} else {
 				// default or unknown format
				while ($line = mysql_fetch_assoc($result))
					$lines[$line[$id_field_name]] = $line;
			}
		}
	} else {
		if (substr($_SERVER['SERVER_NAME'], -6) == '.local') {
			echo mysql_error();
			echo '<br>'.$sql;
		}
		if (function_exists('wrap_error')) {
			wrap_error(sprintf('Error in SQL query:'."\n\n%s\n\n%s", mysql_error(), $sql), E_USER_ERROR);
		}
	}
	if (!empty($zz_conf['modules']['debug'])) zz_debug('wrap_db_fetch(): '.$sql);

	return $lines;
}

/**
 * Recursively gets a tree of records or just IDs from the database
 * 
 * to get just IDs of records, the input array needs to be either the output
 * of wrap_db_fetch($sql, $key_field_name, 'single value') or an array of
 * IDs (array(3, 4, 5)); to get full records as specified in the SQL query, the
 * input array must be the output of wrap_db_fetch($sql, $key_field_name) or an
 * array with the records, e. g. array(3 => array('id' => 3, 'title' => "blubb"),
 * 4 => array('id' => 4, title => "another title"))
 * @param array $data Array with records from database, indexed on ID
 * @param string $sql SQL query to get child records for each selected record
 * @param string $key_field_name optional: Fieldname of primary key
 * @param string $mode optional: flat = without hierarchy, hierarchy = with.
 * @return array with queried database content or just the IDs
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_db_children($data, $sql, $key_field_name = false, $mode = 'flat') {
	// get all IDs that were submitted to the function
	if ($key_field_name)
		foreach ($data as $record) $ids[] = $record[$key_field_name];
	else
		$ids = $data;
	if ($mode == 'hierarchy') {
		$old_data = $data;
		unset($data);
		$data[0] = $old_data; // 0 is the top hierarchy, means nothing stands above this
		$data['ids'] = $ids;
	}
	// as long as we have IDs in the pool, check if the current ID has child records
	$used_ids = array();
	while ($ids) {
		// take current ID from $ids
		$my_id = array_shift($ids);
		if (in_array($my_id, $used_ids)) {
			continue; // avoid infinite recursion
		} else {
			$used_ids[] = $my_id;
		}
		if ($key_field_name) {
			// get ID and full record as specified in SQL query
			$my_data = wrap_db_fetch(sprintf($sql, $my_id), $key_field_name);
		} else {
			// just get the ID, a dummy key_field_name must be set here
			$my_data = wrap_db_fetch(sprintf($sql, $my_id), 'dummy', 'single value');
		}
		if ($my_data) {
			// append new records to $data-Array
			if ($mode == 'flat') $data += $my_data;
			elseif ($mode == 'hierarchy') {
				if (empty($data[$my_id])) $data[$my_id] = array();
				$data[$my_id] += $my_data;
			}
			// append new IDs to $ids-Array
			$ids = array_merge($ids, array_keys($my_data));
			if ($mode == 'hierarchy') {
				$data['ids'] = array_merge($data['ids'], array_keys($my_data));
			}
		}
	}
	if ($mode == 'hierarchy') sort($data['ids']);
	return $data;
}

/**
 * Recursively gets a tree of IDs from the database (here: parent IDs)
 * 
 * @param int $id ID of child
 * @param string $sql SQL query with placeholder %s for ID
 * @return array set of IDs
 */
function wrap_db_parents($id, $sql) {
	$ids = array();
	$result = true;
	while ($result) {
		$id = wrap_db_fetch(sprintf($sql, $id), '', 'single value');
		if ($id) $ids[] = $id;
		else $result = false;
	}
	$ids = array_reverse($ids); // top-down
	return $ids;
}

/**
 * puts parts of SQL query in correct order when they have to be added
 *
 * this function works only for sql queries without UNION:
 * might get problems with backticks that mark fieldname that is equal with SQL 
 * keyword
 * mode = add until now default, mode = replace is only implemented for SELECT
 * identical to zz_edit_sql()!
 * @param string $sql original SQL query
 * @param string $n_part SQL keyword for part shall be edited or replaced
 *		SELECT ... FROM ... JOIN ...
 * 		WHERE ... GROUP BY ... HAVING ... ORDER BY ... LIMIT ...
 * @param string $values new value for e. g. WHERE ...
 * @param string $mode Mode, 'add' adds new values while keeping the old ones, 
 *		'replace' replaces all old values
 * @return string $sql modified SQL query
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @see zz_edit_sql()
 */
function wrap_edit_sql($sql, $n_part = false, $values = false, $mode = 'add') {
	// remove whitespace
	$sql = ' '.preg_replace("/\s+/", " ", $sql); // first blank needed for SELECT
	// SQL statements in descending order
	$statements_desc = array('LIMIT', 'ORDER BY', 'HAVING', 'GROUP BY', 'WHERE', 'FROM', 'SELECT DISTINCT', 'SELECT');
	foreach ($statements_desc as $statement) {
		$explodes = explode(' '.$statement.' ', $sql);
		if (count($explodes) > 1) {
		// = look only for last statement
		// and put remaining query in [1] and cut off part in [2]
			$o_parts[$statement][2] = array_pop($explodes);
			$o_parts[$statement][1] = implode(' '.$statement.' ', $explodes).' '; // last blank needed for exploding SELECT from DISTINCT
		}
		$search = '/(.+) '.$statement.' (.+?)$/i'; 
//		preg_match removed because it takes way too long if nothing is found
//		if (preg_match($search, $sql, $o_parts[$statement])) {
		if (!empty($o_parts[$statement])) {
			$found = false;
			$lastpart = false;
			while (!$found) {
				// check if there are () outside '' or "" and count them to check
				// whether we are inside a subselect
				$temp_sql = $o_parts[$statement][1]; // look at first part of query
	
				// 1. remove everything in '' and "" which are not escaped
				// replace \" character sequences which escape "
				$temp_sql = preg_replace('/\\\\"/', '', $temp_sql);
				// replace "strings" without " inbetween, empty "" as well
				$temp_sql = preg_replace('/"[^"]*"/', "away", $temp_sql);
				// replace \" character sequences which escape '
				$temp_sql = preg_replace("/\\\\'/", '', $temp_sql);
				// replace "strings" without " inbetween, empty '' as well
				$temp_sql = preg_replace("/'[^']*'/", "away", $temp_sql);
	
				// 2. count opening and closing ()
				//  if equal ok, if not, it's a statement in a subselect
				// assumption: there must not be brackets outside " or '
				if (substr_count($temp_sql, '(') == substr_count($temp_sql, ')')) {
					$sql = $o_parts[$statement][1]; // looks correct, so go on.
					$found = true;
				} else {
					// remove next last statement, and go on until you found 
					// either something with correct bracket count
					// or no match anymore at all
					$lastpart = ' '.$statement.' '.$o_parts[$statement][2];
					// check first with strstr if $statement (LIMIT, WHERE etc.)
					// is still part of the remaining sql query, because
					// preg_match will take 2000 times longer if there is no match
					// at all (bug in php?)
					if (strstr($o_parts[$statement][1], $statement) 
						AND preg_match($search, $o_parts[$statement][1], $o_parts[$statement])) {
						$o_parts[$statement][2] = $o_parts[$statement][2].' '.$lastpart;
					} else {
						unset($o_parts[$statement]); // ignore all this.
						$found = true;
					}
				}
			}
		}
	}
	if ($n_part && $values) {
		$n_part = strtoupper($n_part);
		switch ($n_part) {
			case 'LIMIT':
				// replace complete old LIMIT with new LIMIT
				$o_parts['LIMIT'][2] = $values;
			break;
			case 'ORDER BY':
				if ($mode == 'add') {
					// append old ORDER BY to new ORDER BY
					if (!empty($o_parts['ORDER BY'][2])) 
						$o_parts['ORDER BY'][2] = $values.', '.$o_parts['ORDER BY'][2];
					else
						$o_parts['ORDER BY'][2] = $values;
				} elseif ($mode == 'delete') {
					unset($o_parts['ORDER BY']);
				}
			break;
			case 'WHERE':
			case 'GROUP BY':
			case 'HAVING':
				if ($mode == 'add') {
					if (!empty($o_parts[$n_part][2])) 
						$o_parts[$n_part][2] = '('.$o_parts[$n_part][2].') AND ('.$values.')';
					else 
						$o_parts[$n_part][2] = $values;
				}  elseif ($mode == 'delete') {
					unset($o_parts[$n_part]);
				}
			break;
			case 'SELECT':
				if (!empty($o_parts['SELECT DISTINCT'][2])) {
					if ($mode == 'add')
						$o_parts['SELECT DISTINCT'][2] .= ','.$values;
					elseif ($mode == 'replace')
						$o_parts['SELECT DISTINCT'][2] = $values;
				} else {
					if ($mode == 'add')
						$o_parts['SELECT'][2] = ','.$values;
					elseif ($mode == 'replace')
						$o_parts['SELECT'][2] = $values;
				}
			break;
			default:
				echo 'The variable <code>'.$n_part.'</code> is not supported by zz_edit_sql().';
				exit;
			break;
		}
	}
	$statements_asc = array_reverse($statements_desc);
	foreach ($statements_asc as $statement) {
		if (!empty($o_parts[$statement][2])) 
			$sql.= ' '.$statement.' '.$o_parts[$statement][2];
	}
	return $sql;
}

/**
 * sends a file to the browser from a directory below document root
 *
 * @param array $file
 *		'name' => string full filename; 'etag' string (optional) ETag-value for 
 *		header; 'cleanup' => bool if file shall be deleted after sending it;
 *		'cleanup_folder' => string name of folder if it shall be deleted as well
 *		'send_as' => send filename under a different name (default: basename)
 *		'error_code' => HTTP error code to send in case of file not found error
 *		'error_msg' => additional error message that appears on error page
 * @global array $zz_conf
 * @global array $zz_sql
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @todo send pragma public header only if browser that is affected by this bug
 */
function wrap_file_send($file) {
	global $zz_conf;
	global $zz_sql;
	if (!file_exists($file['name'])) {
		if (!empty($file['error_code'])) {
			if (!empty($file['error_msg'])) {
				global $zz_page;
				$zz_page['error_msg'] = $file['error_msg'];
			}
			wrap_quit($file['error_code']);
		}
		wrap_file_cleanup($file);
		return false;
	}
	if (empty($file['send_as'])) $file['send_as'] = basename($file['name']);
	$suffix = substr($file['name'], strrpos($file['name'], ".") +1);
	$filesize = filesize($file['name']);
	// Cache time: 'Sa, 05 Jun 2004 15:40:28'
	$cache_time = gmdate("D, d M Y H:i:s", filemtime($file['name'])); 

	// Canonicalize suffices
	$suffix_map = array(
		'jpg' => 'jpeg',
		'tif' => 'tiff'
	);
	if (in_array($suffix, array_keys($suffix_map))) $suffix = $suffix_map[$suffix];

	// Read mime type from database
	if (!empty($zz_sql['filetypes'])) 
		$sql = sprintf($zz_sql['filetypes'], $suffix);
	else {
		$sql = 'SELECT CONCAT(mime_content_type, "/", mime_subtype)
			FROM '.$zz_conf['prefix'].'filetypes
			WHERE extension = "'.$suffix.'"';
	}
	$mimetype = wrap_db_fetch($sql, '', 'single value');
	if (!$mimetype) $mimetype = 'application/octet-stream';

	// Send HTTP headers
 	header("Accept-Ranges: bytes");
 	header("Last-Modified: " . $cache_time . " GMT");
	header("Content-Length: " . $filesize);
	header("Content-Type: ".$mimetype);
	if (!empty($file['etag']))
		header("ETag: ".$file['etag']);
	// TODO: ordentlichen Expires-Header setzen, je nach Dateialter

	// Download files if generic mimetype
	// or HTML, since this might be of unknown content with javascript or so
	$download_filetypes = array('application/octet-stream', 'application/zip', 
		'text/html', 'application/xhtml+xml');
	if (in_array($mimetype, $download_filetypes)) {
		header('Content-Disposition: attachment; filename="'.$file['send_as'].'"');
			// d. h. bietet save as-dialog an, geht nur mit application/octet-stream
		header('Pragma: public');
			// dieser Header widerspricht im Grunde dem mit SESSION ausgesendeten
			// Cache-Control-Header
			// Wird aber für IE 5, 5.5 und 6 gebraucht, da diese keinen Dateidownload
			// erlauben, wenn Cache-Control gesetzt ist.
			// http://support.microsoft.com/kb/323308/de
	}

	// Respond to If Modified Since with 304 header if appropriate
	if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) 
		&& $cache_time.' GMT' == $_SERVER['HTTP_IF_MODIFIED_SINCE']) {
		wrap_file_cleanup($file);
		header("HTTP/1.1 304 Not Modified");
		exit;
	}

	if (stripos($_SERVER['REQUEST_METHOD'], 'HEAD') !== FALSE) {
		wrap_file_cleanup($file);
		// we do not need to resend file
		exit;
	}

	readfile($file['name']);
	wrap_file_cleanup($file);
	exit;
}

/**
 * does cleanup after a file was sent
 *
 * @param array $file
 */
function wrap_file_cleanup($file) {
	if (!empty($file['cleanup'])) {
		// clean up
		unlink($file['name']);
		if (!empty($file['cleanup_dir'])) rmdir($file['cleanup_dir']);
	}
}

/**
 * Reads the language from the URL and returns without it
 * Liest die Sprache aus der URL aus und gibt die URL ohne Sprache zurück 
 * 
 * @param array $url
 * @param bool $setlang write lanugage in 'lang'/ Sprache in 'lang' schreiben?
 * @global array $zz_setting
 *		'lang' (will be changed), 'base' (will be changed), 'languages_allowed'
 * @global array $zz_sql
 * 		'language' SQL query to check whether language exists
 * @return array $url
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_prepare_url($url, $setlang = true) {
	global $zz_setting;
	global $zz_sql;

	// looking for /en/ or similar
	if (empty($url['path'])) return $url;
	if (!$pos = strpos(substr($url['path'], 1), '/')) return $url;
	$lang = mysql_real_escape_string(substr($url['path'], 1, $pos));
	// check if it's a language
	if (!empty($zz_sql['language'])) {
		// read from sql query
		$sql = sprintf($zz_sql['language'], $lang);
		$lang = wrap_db_fetch($sql, '', 'single value');
	} elseif (!empty($zz_setting['languages_allowed'])) {
		// read from array
		if (!in_array($lang, $zz_setting['languages_allowed'])) 
			$lang = false;
	} else {
		// impossible to check, so there's no language
		$lang = false;
	}
	
	// if no language can be extracted from URL, return URL without changes
	if (!$lang) return $url;
		
	if ($setlang) {
		// save language in settings
		$zz_setting['lang'] = $lang;
		// add language to base URL
		$zz_setting['base'] .= '/'.$lang;
	}
	$url['path'] = substr($url['path'], $pos+1);
	return $url;
}
?>