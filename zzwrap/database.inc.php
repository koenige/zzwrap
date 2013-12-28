<?php 

/**
 * zzwrap
 * Database functions
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 *	Database functions
 *	- wrap_db_connect()
 *	- wrap_db_query()
 *	- wrap_db_fetch()
 *	- wrap_db_children()
 *	- wrap_db_parents()
 *	- wrap_db_tables_last_update()
 *	- wrap_check_db_connection()
 *
 *	SQL functions
 *	- wrap_edit_sql()
 *	- wrap_sql()
 *
 *	Miscellaneous functions
 *	- wrap_microtime_float()
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2012 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Establishes a database connection (if not already established)
 * selects database, sets NAMES to character encoding
 *
 * @global array $zz_conf
 *		'db_connection', 'db_name', 'db_name_local', 'character_set'
 * @global array $zz_setting
 *		'local_access', 'local_pwd', 'custom_wrap_sql_dir', 'db_password_files',
 *		'encoding_to_mysql_encoding'
 * @return bool true: database connection established, false: no connection
 */
function wrap_db_connect() {
	global $zz_setting;
	global $zz_conf;
	
	// do we already have a connection?
	if (!empty($zz_conf['db_connection'])) return true;
	
	// get connection details, files need to define
	// $db_host, $db_user, $db_pwd, $zz_conf['db_name']
	if (!isset($zz_setting['db_password_files']))
		$zz_setting['db_password_files'] = array('');
	elseif (!is_array($zz_setting['db_password_files']))
		$zz_setting['db_password_files'] = array($zz_setting['db_password_files']);
	
	if ($zz_setting['local_access']) {
		if (!empty($zz_conf['db_name_local'])) {
			$zz_conf['db_name'] = $zz_conf['db_name_local'];
		}
		array_unshift($zz_setting['db_password_files'], $zz_setting['local_pwd']);
	}
	$found = false;
	foreach ($zz_setting['db_password_files'] as $file) {
		if (substr($file, 0, 1) !== '/') {
			$file = $zz_setting['custom_wrap_sql_dir'].'/pwd'.$file.'.inc.php';
		}
		if (!file_exists($file)) continue;
		include $file;
		$found = true;
		break;
	}
	if (!$found) wrap_error('No password file for database found.', E_USER_ERROR);
	
	// connect to database
	$zz_conf['db_connection'] = @mysql_connect($db_host, $db_user, $db_pwd);
	if (!$zz_conf['db_connection']) return false;

	mysql_select_db($zz_conf['db_name']);
	// mySQL uses different identifiers for character encoding than HTML
	// mySQL verwendet andere Kennungen für die Zeichencodierung als HTML
	$charset = '';
	if (empty($zz_setting['encoding_to_mysql_encoding'][$zz_conf['character_set']])) {
		switch ($zz_conf['character_set']) {
			case 'iso-8859-1': $charset = 'latin1'; break;
			case 'iso-8859-2': $charset = 'latin2'; break;
			case 'utf-8': $charset = 'utf8'; break;
			default: 
				wrap_error(sprintf('No character set for %s found.', $zz_conf['character_set']), E_USER_NOTICE);
				break;
		}
	} else {
		$charset = $zz_setting['encoding_to_mysql_encoding'][$zz_conf['character_set']];
	}
	if ($charset) mysql_set_charset($charset);
	return true;
}

/**
 * replace table prefix with configuration variable
 *
 * @param string $sql some SQL query or part of it
 * @return string
 * @todo: parse SQL to check whether it's not a comment but something
 * from inside a query. Until then the value of $prefix must not appear
 * legally inside queries
 */
function wrap_db_prefix($sql) {
	global $zz_conf;
	$prefix = '/*_PREFIX_*/';
	if (strstr($sql, $prefix)) {
		$sql = str_replace($prefix, $zz_conf['prefix'], $sql);
	}
	return $sql;
}

/**
 * queries database and does the error handling in case an error occurs
 *
 * $param string $sql
 * @global array $zz_conf
 * @return ressource $result
 */
function wrap_db_query($sql, $error = E_USER_ERROR) {
	global $zz_conf;
	if (!empty($zz_conf['debug'])) {
		$time = wrap_microtime_float();
	}
	if (!$zz_conf['db_connection']) return array();
	
	if (substr($sql, 0, 10) === 'SET NAMES ') {
		$charset = trim(substr($sql, 10));
		return mysql_set_charset($charset);
	}

	$sql = wrap_db_prefix($sql);
	$result = mysql_query($sql);
	if (!empty($zz_conf['debug'])) {
		$time = wrap_microtime_float() - $time;
		wrap_error('SQL query in '.$time.' - '.$sql, E_USER_NOTICE);
	}
	if ($result) return $result;

	// error
	$close_connection_errors = array(
		1030,	// Got error %d from storage engine
		1317,	// Query execution was interrupted
		2006,	// MySQL server has gone away
		2008	// MySQL client ran out of memory
	);
	if (in_array(mysql_errno(), $close_connection_errors)) {
		mysql_close($zz_conf['db_connection']);
		$zz_conf['db_connection'] = NULL;
	}
	
	if (function_exists('wrap_error')) {
		wrap_error('['.$_SERVER['REQUEST_URI'].'] '
			.sprintf('Error in SQL query:'."\n\n%s\n\n%s", mysql_error(), $sql), $error);
	} else {
		if (!empty($zz_conf['error_handling']) AND $zz_conf['error_handling'] === 'output') {
			global $zz_page;
			$zz_page['error_msg'] = '<p class="error">'.mysql_error().'<br>'.$sql.'</p>';
		}
	}
	return false;	
}

/**
 * Return current Unix timestamp with microseconds as float
 * = microtime(true) in PHP 5
 *
 * @return float
 * @deprecated
 * @todo remove from zzwrap
 */
function wrap_microtime_float() {
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
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
 * @param int $errorcode let's you set error level, default = E_USER_ERROR
 * @return array with queried database content, NULL if query failed
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @todo give a more detailed explanation of how function works
 */
function wrap_db_fetch($sql, $id_field_name = false, $format = false, $errorcode = E_USER_ERROR) {
	global $zz_conf;
	if (!$zz_conf['db_connection']) return array();
	
	$result = wrap_db_query($sql, $errorcode);
	if (!$result) return NULL;

	$lines = array();

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
				if (count($id_field_name) == 4) {
					$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]][$line[$id_field_name[2]]][$line[$id_field_name[3]]] = $values;
				} elseif (count($id_field_name) == 3) {
					if ($format == 'key/value') {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]] = $line[$id_field_name[2]];
					} else {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]][$line[$id_field_name[2]]] = $values;
					}
				} else {
					if ($format == 'key/value') {
						$lines[$line[$id_field_name[0]]] = $line[$id_field_name[1]];
					} elseif ($format == 'numeric') {
						$lines[$line[$id_field_name[0]]][] = $values;
					} else {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]] = $values;
					}
				}
			}
		}
	} elseif (mysql_num_rows($result)) {
		if ($format == 'count') {
			$lines = mysql_num_rows($result);
		} elseif ($format == 'single value') {
			// you can reach this part here with a dummy id_field_name
			// because no $id_field_name is needed!
			while ($line = mysql_fetch_array($result)) {
				if (!$line[0]) continue;
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
 *		if mode is set to hierarchy, you'll get a hierarchical list in 'level'
 *		with ID as key and the level (0, 1, 2, ..., n) as value
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
		$top_id = key($ids);
		$data['level'][$top_id] = 0;
	}
	// as long as we have IDs in the pool, check if the current ID has child records
	$used_ids = array();
	while ($ids) {
		switch ($mode) {
		case 'hierarchy':
			$my_id = array_shift($ids);
			if (!trim($my_id)) continue 2;
			if (in_array($my_id, $used_ids)) {
				continue 2; // avoid infinite recursion
			} else {
				$used_ids[] = $my_id;
			}
			break;
		case 'flat':
			// take current ID from $ids
			foreach ($ids as $id) {
				// avoid infinite recursion
				if (in_array($id, $used_ids)) {
					$key = array_search($id, $ids);
					unset($ids[$key]);
				} else {
					$used_ids[] = $id;
				}
			}
			if (!$ids) continue 2;
			$my_id = implode(',', $ids);
			break;
		}

		if ($key_field_name) {
			// get ID and full record as specified in SQL query
			$my_data = wrap_db_fetch(sprintf($sql, $my_id), $key_field_name);
		} else {
			// just get the ID, a dummy key_field_name must be set here
			$my_data = wrap_db_fetch(sprintf($sql, $my_id), 'dummy', 'single value');
		}
		if (!$my_data) continue;
		
		switch ($mode) {
		case 'hierarchy':
			if (isset($data['level'][$my_id]))
				$my_level = $data['level'][$my_id] + 1;
			else
				$my_level = 1;
			$level = array();
			foreach (array_keys($my_data) AS $id) {
				$level[$id] = $my_level;
			}
			$pos = array_search($my_id, array_keys($data['level']))+1;
			$data['level'] = array_slice($data['level'], 0, $pos, true)
				+ $level + array_slice($data['level'], $pos, NULL, true);

			// append new records to $data-Array
			if (empty($data[$my_id])) $data[$my_id] = array();
			$data[$my_id] += $my_data;
			// append new IDs to $ids-Array
			$ids = array_merge($ids, array_keys($my_data));
			$data['ids'] = array_merge($data['ids'], array_keys($my_data));
			break;
		case 'flat':
			// append new records to $data-Array
			$data += $my_data;
			// put new IDs into $ids-Array
			$ids = array_keys($my_data);
			break;
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
		// allow several parent IDs as well
		$new_ids = wrap_db_fetch(sprintf($sql, $id), '_dummy_', 'single value');
		if ($new_ids) {
			foreach ($new_ids as $id) {
				// no infinite recursion please:
				if (in_array($id, $ids)) {
					// throw a notice because warnings might be sent via mail
					wrap_error(sprintf('Infinite recursion in query %s with ID %d', $sql, $id), E_USER_NOTICE);
					break 2;
				} else {
					$ids[] = $id;
				}
			}
		} else {
			$result = false;
		}
	}
	$ids = array_reverse($ids); // top-down
	return $ids;
}

/**
 * checks tables for last update and returns the newest last update timestamp
 * if sync_date is set, checks against sync date and returns false if no sync
 * is neccessary
 *
 * @param array $tables tables which will be checked for changes
 * @param string $last_sync (optional) datetime when last update was made
 * @return mixed false: no sync neccessary, datetime: date of last update in tables
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_db_tables_last_update($tables, $last_sync = false) {
	if (!is_array($tables)) $tables = array($tables);
	foreach ($tables as $table) {
		$table = wrap_db_prefix($table);
		$db_table = explode('.', $table);
		if (count($db_table) == 2)
			$my_tables[$db_table[0]][] = $db_table[1];
		elseif (count($db_table) == 1)
			$my_tables['NULL'][] = $db_table[0];
		else {
			wrap_error('Checking table updates. Error: Table name '.$table.' has too many dots.', E_USER_WARNING);
			wrap_quit(503);
		}
	}
	$last_update = '';	
	foreach ($my_tables AS $db => $these_tables) {
		$sql = 'SHOW TABLE STATUS '
			.($db == 'NULL' ? '' : 'FROM `'.$db.'`')
			.' WHERE Name IN ("'.implode('","', $these_tables).'")';
		$status = wrap_db_fetch($sql, 'Name');
		foreach ($status as $table) {
			if ($table['Update_time'] > $last_update) $last_update = $table['Update_time'];
		}
	}
	
	if ($last_sync AND strtotime($last_update) <= strtotime($last_sync))
		// database on the other end is already up to date
		return false;
	else
		return $last_update;
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
		if (empty($o_parts[$statement])) continue;
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
				if (stristr($o_parts[$statement][1], $statement) 
					AND preg_match($search, $o_parts[$statement][1], $o_parts[$statement])) {
					$o_parts[$statement][2] = $o_parts[$statement][2].' '.$lastpart;
				} else {
					unset($o_parts[$statement]); // ignore all this.
					$found = true;
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
 * Local database structure, modifications to SQL queries and field_names
 *
 * wrap_get_menu_navigation():
 * $zz_sql['menu'] expects: nav_id, title, main_nav_id, url
 *		optional parameters: id_title, rest is free
 * wrap_get_menu_wepages():
 * $zz_sql['menu'] expects: page_id, title, mother_page_id, url, menu
 * $zz_sql['menu_level2'] expects: page_id, title, (id_title), mother_page_id, 
 *		url (function_url), menu
 * $zz_sql['page_id'] Name of ID field in webpages-table
 * $zz_sql['authors'] person_id = ID, person = name of author
 * @param string $key
 * @param string $mode (optional: get(default), set, add)
 * @return mixed true: set was succesful; string: SQL query or field name 
 *		corresponding to $key
 */
function wrap_sql($key, $mode = 'get', $value = false) {
	global $zz_conf;
	global $zz_setting;
	static $zz_sql;
	static $set;

	// set variables
	switch ($mode) {
	case 'get':
		// return variables
		if (isset($zz_sql[$key])) return $zz_sql[$key];
		else return NULL;
	case 'set':
		switch ($key) {
		case 'core':
			if (!empty($set['core'])) return true;
			$set['core'] = true;
			$zz_sql['pages'] = 'SELECT webpages.*
				FROM /*_PREFIX_*/webpages webpages
				WHERE webpages.identifier = "%s"';
			
			$zz_sql['is_public'] = 'live = "yes"';

			$zz_sql['redirects_new_fieldname'] = 'new_url';
			$zz_sql['redirects_old_fieldname'] = 'old_url';

			$zz_sql['redirects'] = 'SELECT * FROM /*_PREFIX_*/redirects
				WHERE old_url = "%s/"
				OR old_url = "%s.html"
				OR old_url = "%s"';

			$zz_sql['redirects_*'] = 'SELECT * FROM /*_PREFIX_*/redirects
				WHERE old_url = "%s*"';
				
			$zz_sql['filetypes'] = 'SELECT CONCAT(mime_content_type, "/", mime_subtype)
				FROM /*_PREFIX_*/filetypes
				WHERE extension = "%s"';

			$zz_sql['page_id']		= 'page_id';
			$zz_sql['content']		= 'content';
			$zz_sql['title']		= 'title';
			$zz_sql['ending']		= 'ending';
			$zz_sql['identifier']	= 'identifier';
			$zz_sql['lastupdate']	= 'last_update';
			$zz_sql['author_id']	= 'author_person_id';

			$zz_sql['language'] = false;

			if (!empty($zz_conf['translations_of_fields'])) {
				$zz_sql['translations'] = '';
				$zz_sql['translation_matrix_pages'] = '/*_PREFIX_*/webpages';
				$zz_sql['translation_matrix_breadcrumbs'] = array();

				if (!empty($zz_setting['default_source_language'])) {
					$zz_sql['translations'] = 'SELECT translation_id, translationfield_id, translation, field_id,
					"'.$zz_setting['default_source_language'].'" AS source_language
					FROM /*_PREFIX_*/_translations_%s translations
					LEFT JOIN /*_PREFIX_*/languages languages USING (language_id)
					WHERE translationfield_id IN (%s) 
						AND field_id IN (%s)
						AND languages.iso_639_1 = "%s"';
				}
			}
			
			break;
		case 'page':
			if (!empty($set['page'])) return true;
			$set['page'] = true;
			$zz_sql['breadcrumbs']	= '';
			$zz_sql['menu']			= '';
			$zz_sql['menu_level2']	= '';
			break;
		case 'auth':
			if (!empty($set['auth'])) return true;
			$set['auth'] = true;
			if (empty($zz_sql['domain']))
				$zz_sql['domain'] = array($zz_setting['hostname']);

			$zz_sql['logout'] = 'UPDATE /*_PREFIX_*/logins 
				SET logged_in = "no"
				WHERE login_id = %s';	// $_SESSION['login_id']
			$zz_sql['last_click'] = 'UPDATE /*_PREFIX_*/logins 
				SET logged_in = "yes", last_click = %s 
				WHERE login_id = %s';
			$zz_sql['login'] = 'SELECT password 
				, username
				, logins.login_id AS user_id
				, logins.login_id
				FROM /*_PREFIX_*/logins logins
				WHERE active = "yes"
				AND username = "%s"';
			$zz_sql['last_masquerade'] = false;
			$zz_sql['login_masquerade'] = false;
			$zz_sql['login_settings'] = false;

			break;
		default:
			break;
		}
		if (file_exists($zz_setting['custom_wrap_sql_dir'].'/sql-'.$key.'.inc.php')
			AND !empty($zz_conf['db_connection']))
			require_once $zz_setting['custom_wrap_sql_dir'].'/sql-'.$key.'.inc.php';

		if (!empty($zz_sql['domain']) AND !is_array($zz_sql['domain']))
			$zz_sql['domain'] = array($zz_sql['domain']);

		return true;
	case 'add':
		if (empty($zz_sql[$key])) {
			$zz_sql[$key] = array($value);
			return true;
		}
		if (is_array($zz_sql[$key])) {
			$zz_sql[$key][] = $value;
			return true;
		}
	default:
		return false;	
	}
}

/**
 * Do we have a database connection?
 * if not: send cache or exit
 * 
 * @global array $zz_conf
 * @global array $zz_setting
 * @return bool true: everything is okay
 */
function wrap_check_db_connection() {
	global $zz_conf;
	if ($zz_conf['db_connection']) return true;
	wrap_send_cache();
	wrap_error('No connection to SQL server.', E_USER_ERROR);
	exit;
}

/**
 * Escapes values for database input
 *
 * @param string $value
 * @return string escaped $value
 * @see zz_db_escape(), equivalent function in zzform
 */
function wrap_db_escape($value) {
	// should never happen, just during development
	if (!$value) return '';
	if (is_array($value) OR is_object($value)) {
		wrap_error(__FUNCTION__.'() - value is not a string: '.json_encode($value));
		return '';
	}
	if (function_exists('mysql_real_escape_string')) { 
		// just from PHP 4.3.0 on
		return mysql_real_escape_string($value);
	} else {
		return addslashes($value);
	}
}
?>