<?php 

/**
 * zzwrap
 * Database functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
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
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2021 Gustaf Mossakowski
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
	
	// local access: get local database name
	if ($zz_setting['local_access']) {
		if (!empty($zz_conf['db_name_local'])) {
			$zz_conf['db_name'] = $zz_conf['db_name_local'];
		} else {
			$zz_setting['authentication_possible'] = false;
			wrap_session_start();
			if (!empty($_SESSION['db_name_local']) AND !empty($_SESSION['step']) AND $_SESSION['step'] === 'finish') {
				$zz_conf['db_name'] = $_SESSION['db_name_local'];
				wrap_session_stop();
			}
			session_write_close();
		}
	}
	
	// connect to database
	$db = wrap_db_credentials();
	if (empty($db['db_port'])) $db['db_port'] = NULL;
	$zz_conf['db_connection'] = @mysqli_connect($db['db_host'], $db['db_user'], $db['db_pwd'], $zz_conf['db_name'], $db['db_port']);
	if (!$zz_conf['db_connection']) return false;

	wrap_db_charset();
	wrap_mysql_mode();
	return true;
}

/**
 * get connection details
 * files need to define
 * $db_host, $db_user, $db_pwd, $zz_conf['db_name']
 *
 * @return array
 */
function wrap_db_credentials() {
	global $zz_setting;
	global $zz_conf;
	static $db;
	if (!empty($db)) return $db;

	if (!isset($zz_setting['db_password_files']))
		$zz_setting['db_password_files'] = [''];
	elseif (!is_array($zz_setting['db_password_files']))
		$zz_setting['db_password_files'] = [$zz_setting['db_password_files']];

	if ($zz_setting['local_access']) {
		array_unshift($zz_setting['db_password_files'], $zz_setting['local_pwd']);
	}

	$found = false;
	$rewrite = false;
	foreach ($zz_setting['db_password_files'] as $file) {
		if (substr($file, 0, 1) !== '/') {
			$filename = $zz_setting['custom_wrap_sql_dir'].'/pwd'.$file.'.inc.php';
			if (!file_exists($filename)) {
				$filename = $zz_setting['custom_wrap_sql_dir'].'/pwd'.$file.'.json';
				if (!file_exists($filename)) continue;
				$db = json_decode(file_get_contents($filename), true);
				$zz_conf['db_name'] = $db['db_name'];
			} else {
				include $filename;
				$rewrite = true;
			}
		} elseif (!file_exists($file)) {
			continue;
		} else {
			include $file;
			$rewrite = true;
		}
		$found = true;
		break;
	}
	if (!$found) wrap_error('No password file for database found.', E_USER_ERROR);
	if ($rewrite) {
		// $zz_conf['db_name'] should be set in pwd.inc.php
		$db = [
			'db_host' => $db_host,
			'db_user' => $db_user,
			'db_pwd' => $db_pwd,
			'db_port' => isset($db_port) ? $db_port : false,
		];	
	}
	return $db;
}

/**
 * set a character encoding for the database connection
 *
 * @param string $charset (optional)
 * @global $zz_conf
 * @global $zz_setting
 * @return void
 */
function wrap_db_charset($charset = '') {
	global $zz_conf;
	global $zz_setting;
	
	if (!$charset) {
		// mySQL uses different identifiers for character encoding than HTML
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
	}
	if (!$charset) return;
	if (strtolower($charset) === 'utf8') {
		// use utf8mb4, the real 4-byte utf-8 encoding if database is in utf8mb4
		// instead of proprietary 3-byte utf-8
		$sql = 'SELECT @@character_set_database';
		$result = wrap_db_fetch($sql, '', 'single value');
		if ($result === 'utf8mb4') $charset = 'utf8mb4';
	}
	mysqli_set_charset($zz_conf['db_connection'], $charset);
}

/**
 * replace table prefix with configuration variable
 *
 * @param string $sql some SQL query or part of it
 * @return string
 * @todo parse SQL to check whether it's not a comment but something
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
 * @return mixed
 *		bool: false = query failed, true = query was succesful
 *		array:	'id' => on INSERT: inserted ID if applicable
 *				'rows' => number of inserted/deleted/updated rows
 */
function wrap_db_query($sql, $error = E_USER_ERROR) {
	global $zz_conf;
	if (!empty($zz_conf['debug'])) {
		$time = microtime(true);
	}
	if (!$zz_conf['db_connection']) return false;
	$sql = trim($sql);
	
	if (str_starts_with($sql, 'SET NAMES ')) {
		$charset = trim(substr($sql, 10));
		return wrap_db_charset($charset);
	}

	$sql = wrap_db_prefix($sql);
	$result = mysqli_query($zz_conf['db_connection'], $sql);
	if (!empty($zz_conf['debug'])) {
		$time = microtime(true) - $time;
		wrap_error('SQL query in '.$time.' - '.$sql, E_USER_NOTICE);
	}
	$tokens = explode(' ', $sql);
	// @todo remove SET from token list after NO_ZERO_IN_DATE is not used
	// by any application anymore
	// SELECT is there for performance reasons
	$warnings = [];
	$return = [];
	switch ($tokens[0]) {
	case 'INSERT':
		// return inserted ID
		$return['id'] = mysqli_insert_id($zz_conf['db_connection']);
	case 'UPDATE':
	case 'DELETE':
		// return number of updated or deleted rows
		$return['rows'] = mysqli_affected_rows($zz_conf['db_connection']);
		break;
	}
	if (!in_array($tokens[0], ['SET', 'SELECT'])
		AND function_exists('wrap_error') AND $sql !== 'SHOW WARNINGS') {
		$warnings = wrap_db_fetch('SHOW WARNINGS', '_dummy_', 'numeric');
		$db_msg = [];
		$warning_error = E_USER_WARNING;
		foreach ($warnings as $warning) {
			$db_msg[] = $warning['Level'].': '.$warning['Message'];
			if ($warning['Level'] === 'Error') $warning_error = $error;
		}
		if ($db_msg) {
			wrap_error('['.$_SERVER['REQUEST_URI'].'] MySQL reports a problem.'
				.sprintf("\n\n%s\n\n%s", implode("\n\n", $db_msg), $sql), $warning_error);
		}
	}
	if ($result) {
		if ($return) return $return;
		return $result;
	}

	$error_no = wrap_db_error_no();
	if ($error_no === 2006 AND in_array($tokens[0], ['SET', 'SELECT'])) {
		// retry connection
		wrap_db_connect();
		$result = mysqli_query($zz_conf['db_connection'], $sql);
		if ($result) return $result;
		wrap_db_error_no();
	}
	$error_msg = mysqli_error($zz_conf['db_connection']);
	
	if (function_exists('wrap_error') AND !$warnings) {
		wrap_error('['.$_SERVER['REQUEST_URI'].'] '
			.sprintf('Error in SQL query:'."\n\n%s\n\n%s", $error_msg, $sql), $error);
	} else {
		if (!empty($zz_conf['error_handling']) AND $zz_conf['error_handling'] === 'output') {
			global $zz_page;
			$zz_page['error_msg'] = '<p class="error">'.$error_msg.'<br>'.$sql.'</p>';
		}
	}
	return false;	
}

/**
 * close connection if there's a database error, read error number
 *
 * @return int
 */
function wrap_db_error_no() {
	global $zz_conf;
	$close_connection_errors = [
		1030,	// Got error %d from storage engine
		1317,	// Query execution was interrupted
		2006,	// MySQL server has gone away
		2008	// MySQL client ran out of memory
	];

	$error_no = mysqli_errno($zz_conf['db_connection']);
	if (in_array($error_no, $close_connection_errors)) {
		mysqli_close($zz_conf['db_connection']);
		$zz_conf['db_connection'] = NULL;
	}
	return $error_no;
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
 *	"key/value" = returns [$key => $value]
 *	"key/values" = returns [$key => [$values]]
 *	"single value" = returns $value
 *	"object" = returns object
 *	"numeric" = returns lines in numerical array [0 ... n] instead of using field ids
 *	"list field_name_1 field_name_2" = returns lines in hierarchical array
 *	for direct use in zzbrick templates, e. g. 0 => [
 *		field_name_1 = value, field_name_2 = []], 1 => ..
 * @param int $error_type let's you set error level, default = E_USER_ERROR
 * @return array with queried database content, NULL if query failed
 * @todo give a more detailed explanation of how function works
 */
function wrap_db_fetch($sql, $id_field_name = false, $format = false, $error_type = E_USER_ERROR) {
	global $zz_conf;
	if (!$zz_conf['db_connection']) return [];
	
	$result = wrap_db_query($sql, $error_type);
	if (!$result) return NULL;

	$lines = [];

	if (!$id_field_name) {
		// only one record
		if (mysqli_num_rows($result) === 1) {
			if ($format === 'single value') {
				mysqli_data_seek($result, 0);
				$lines = mysqli_fetch_row($result);
				$lines = reset($lines);
			} elseif ($format === 'object') {
				$lines = mysqli_fetch_object($result);
			} else {
				$lines = mysqli_fetch_assoc($result);
			}
		}
	} elseif (is_array($id_field_name) AND mysqli_num_rows($result)) {
		if ($format === 'object') {
			while ($line = mysqli_fetch_object($result)) {
				if (count($id_field_name) === 3) {
					$lines[$line->$id_field_name[0]][$line->$id_field_name[1]][$line->$id_field_name[2]] = $line;
				} else {
					$lines[$line->$id_field_name[0]][$line->$id_field_name[1]] = $line;
				}
			}
		} elseif (str_starts_with($format, 'list ')) {
			$listkey = substr($format, 5);
			$listkey = explode(' ', $listkey);
			if (count($listkey) < count($id_field_name)) {
				$topkey = array_shift($id_field_name);
			} else {
				$topkey = $id_field_name[0];
			}
			if (count($listkey) === 2) {
				while ($line = mysqli_fetch_assoc($result)) {
					$lines[$line[$topkey]][$listkey[0]] = $line[$id_field_name[0]];
					$lines[$line[$topkey]][$listkey[1]][$line[$id_field_name[1]]] = $line;
				}
			} else {
				while ($line = mysqli_fetch_assoc($result)) {
					$lines[$line[$topkey]][$listkey[0]] = $line[$id_field_name[0]];
					if (!isset($lines[$line[$topkey]][$listkey[1]][$line[$id_field_name[1]]])) {
						$lines[$line[$topkey]][$listkey[1]][$line[$id_field_name[1]]] = $line;
					}
					$lines[$line[$topkey]][$listkey[1]][$line[$id_field_name[1]]][$listkey[2]][$line[$id_field_name[2]]] = $line;
				}
			}
		} else {
			// default or unknown format
			while ($line = mysqli_fetch_assoc($result)) {
				if ($format === 'single value') {
					// just get last field, make sure that it's not one of the id_field_names!
					$values = array_pop($line);
				} else {
					$values = $line;
				}
				if (count($id_field_name) === 4) {
					if ($format === 'key/values') {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]][$line[$id_field_name[2]]][] = $line[$id_field_name[3]];
					} elseif ($format === 'key/value') {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]][$line[$id_field_name[2]]] = $line[$id_field_name[3]];
					} else {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]][$line[$id_field_name[2]]][$line[$id_field_name[3]]] = $values;
					}
				} elseif (count($id_field_name) === 3) {
					if ($format === 'key/values') {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]][] = $line[$id_field_name[2]];
					} elseif ($format === 'key/value') {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]] = $line[$id_field_name[2]];
					} else {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]][$line[$id_field_name[2]]] = $values;
					}
				} else {
					if ($format === 'key/values') {
						$lines[$line[$id_field_name[0]]][] = $line[$id_field_name[1]];
					} elseif ($format === 'key/value') {
						$lines[$line[$id_field_name[0]]] = $line[$id_field_name[1]];
					} elseif ($format === 'numeric') {
						$lines[$line[$id_field_name[0]]][] = $values;
					} else {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]] = $values;
					}
				}
			}
		}
	} elseif (mysqli_num_rows($result)) {
		if ($format === 'count') {
			$lines = mysqli_num_rows($result);
		} elseif ($format === 'single value') {
			// you can reach this part here with a dummy id_field_name
			// because no $id_field_name is needed!
			while ($line = mysqli_fetch_array($result)) {
				if (!$line[0]) continue;
				$lines[$line[0]] = $line[0];
			}
		} elseif ($format === 'key/value') {
			// return array in pairs
			while ($line = mysqli_fetch_array($result)) {
				$lines[$line[0]] = $line[1];
			}
		} elseif ($format === 'object') {
			while ($line = mysqli_fetch_object($result))
				$lines[$line->$id_field_name] = $line;
		} elseif ($format === 'numeric') {
			while ($line = mysqli_fetch_assoc($result))
				$lines[] = $line;
		} else {
			// default or unknown format
			while ($line = mysqli_fetch_assoc($result))
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
 * IDs ([3, 4, 5]); to get full records as specified in the SQL query, the
 * input array must be the output of wrap_db_fetch($sql, $key_field_name) or an
 * array with the records, e. g. [3 => ['id' => 3, 'title' => "blubb"],
 * 4 => ['id' => 4, title => "another title"]]
 * @param array $data Array with records from database, indexed on ID
 * @param string $sql SQL query to get child records for each selected record
 * @param string $key_field_name optional: Fieldname of primary key
 * @param string $hierarchy_field_name optional: none = without hierarchy, otherwise with.
 * @return array with queried database content or just the IDs
 *		if mode is set to hierarchy, you'll get a hierarchical list in 'level'
 *		with ID as key and the level (0, 1, 2, ..., n) as value
 */
function wrap_db_children($data, $sql, $key_field_name = false, $hierarchy_field_name = '') {
	if (!is_array($data)) $data = [$data]; // allow single ID
	// get all IDs that were submitted to the function
	if ($key_field_name)
		foreach ($data as $record) $ids[] = $record[$key_field_name];
	else
		$ids = $data;
	
	if ($hierarchy_field_name) {
		if (!$key_field_name) {
			$field_names = wrap_edit_sql($sql, 'SELECT', '', 'list');
			$first_field_name = $field_names[0]['field_name'];
		}
		$sql = wrap_edit_sql($sql, 'SELECT', $hierarchy_field_name);

		$old_data = $data;
		unset($data);
		$data[0] = $old_data; // 0 is the top hierarchy, means nothing stands above this
		$data['ids'] = $ids;
		$top_id = key($ids);
		$data['level'][$top_id] = 0;
		if ($key_field_name) {
			$data['flat'][$top_id] = reset($old_data);
			$data['flat'][$top_id]['_level'] = 0;
		}
	}
	// as long as we have IDs in the pool, check if the current ID has child records
	$used_ids = [];
	while ($ids) {
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
		if (!$ids) break;
		$my_id = implode(',', $ids);

		if ($key_field_name) {
			// get ID and full record as specified in SQL query
			if ($hierarchy_field_name) {
				$my_data = wrap_db_fetch(sprintf($sql, $my_id), [$hierarchy_field_name, $key_field_name]);
			} else {
				$my_data = wrap_db_fetch(sprintf($sql, $my_id), $key_field_name);
			}
		} else {
			// just get the ID, a dummy key_field_name must be set here
			if ($hierarchy_field_name) {
				$my_data = wrap_db_fetch(sprintf($sql, $my_id), [$hierarchy_field_name, $first_field_name], 'key/values');
			} else {
				$my_data = wrap_db_fetch(sprintf($sql, $my_id), 'dummy', 'single value');
			}
		}
		if (!$my_data) continue;
		
		if ($hierarchy_field_name) {
			$ids = [];
			foreach ($my_data as $hierarchy_id => $my_ids) {
				$levels = [];
				$my_level = isset($data['level'][$hierarchy_id])
					? $data['level'][$hierarchy_id] + 1 : 1;
				foreach ($my_ids as $this_id => $this_data) {
					if ($key_field_name) {
						$key_id = $this_id;
					} else {
						$key_id = $this_data;
					}
					$data[$hierarchy_id][$key_id] = $this_data;
					$data['ids'][$key_id] = $key_id;
					$levels[$key_id] = $my_level;
					$ids[] = $key_id;
				}
				$pos = array_search($hierarchy_id, array_keys($data['level'])) + 1;
				$data['level'] = array_slice($data['level'], 0, $pos, true)
					+ $levels + array_slice($data['level'], $pos, NULL, true);
				if ($key_field_name) {
					$flat_data = $my_ids;
					foreach (array_keys($flat_data) as $level_data_id) {
						$flat_data[$level_data_id]['_level'] = $my_level;
					}
					$data['flat'] = array_slice($data['flat'], 0, $pos, true)
						+ $flat_data + array_slice($data['flat'], $pos, NULL, true);
				}
			}
		} else {
			// append new records to $data-Array
			$data += $my_data;
			// put new IDs into $ids-Array
			$ids = array_keys($my_data);
		}
	}
	if ($hierarchy_field_name) {
		sort($data['ids']);
	}
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
	$ids = [];
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
 */
function wrap_db_tables_last_update($tables, $last_sync = false) {
	if (!is_array($tables)) $tables = [$tables];
	foreach ($tables as $table) {
		$table = wrap_db_prefix($table);
		$db_table = explode('.', $table);
		if (count($db_table) === 2)
			$my_tables[$db_table[0]][] = $db_table[1];
		elseif (count($db_table) === 1)
			$my_tables['NULL'][] = $db_table[0];
		else {
			wrap_error('Checking table updates. Error: Table name '.$table.' has too many dots.', E_USER_WARNING);
			wrap_quit(503);
		}
	}
	$last_update = '';	
	foreach ($my_tables AS $db => $these_tables) {
		$sql = 'SHOW TABLE STATUS '
			.($db === 'NULL' ? '' : 'FROM `'.$db.'`')
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
 * @param string $sql original SQL query
 * @param string $n_part SQL keyword for part shall be edited or replaced
 *		SELECT ... FROM ... JOIN ...
 * 		WHERE ... GROUP BY ... HAVING ... ORDER BY ... LIMIT ...
 * @param string $values new value for e. g. WHERE ...
 * @param string $mode Mode, 'add' adds new values while keeping the old ones, 
 *		'replace' replaces all old values, 'list' returns existing values
 *		'delete' deletes values
 * @return mixed
 *		string $sql modified SQL query
 *		array $tokens list of fields if in list mode
 */
function wrap_edit_sql($sql, $n_part = false, $values = false, $mode = 'add') {
	if (str_starts_with(trim($sql), 'SHOW') AND $n_part === 'LIMIT') {
	// LIMIT, WHERE etc. is only allowed with SHOW
	// not allowed e. g. for SHOW DATABASES(), SHOW TABLES FROM ...
		return $sql;
	}
	if (str_starts_with(trim($sql), 'SHOW DATABASES') AND $n_part === 'WHERE') {
		// this is impossible and will automatically trigger an error
		return false; 
		// @todo implement LIKE here.
	}

	// remove whitespace
	$sql = ' '.preg_replace("/\s+/", " ", $sql); // first blank needed for SELECT

	// UNION: treat queries separate
	if (strstr($sql, ' UNION SELECT ')) {
		$sqls = explode(' UNION SELECT ', $sql);
		foreach ($sqls as $index => $single_sql) {
			if ($index) $single_sql = ' SELECT '.$single_sql;
			$sqls[$index] = trim(wrap_edit_sql($single_sql, $n_part, $values, $mode));
		}
		$sql = implode(' UNION ', $sqls);
		return $sql;
	}

	// SQL statements in descending order
	$statements_desc = [
		'LIMIT', 'ORDER BY', 'HAVING', 'GROUP BY', 'WHERE', 'JOIN',
		'FORCE INDEX', 'FROM', 'SELECT DISTINCT', 'SELECT'
	];
	foreach ($statements_desc as $statement) {
		// add whitespace in between brackets and statements to make life easier
		$sql = str_replace(')'.$statement.' ', ') '.$statement.' ', $sql);
		$sql = str_replace(')'.$statement.'(', ') '.$statement.' (', $sql);
		$sql = str_replace(' '.$statement.'(', ' '.$statement.' (', $sql);
		// check for statements
		$explodes = explode(' '.$statement.' ', $sql);
		if (count($explodes) > 1) {
			if ($statement === 'JOIN') {
				$o_parts[$statement][1] = array_shift($explodes);
				$last_keyword = explode(' ', $o_parts[$statement][1]);
				$last_keyword = array_pop($last_keyword);
				$o_parts[$statement][2] = $statement.' '.implode(' '.$statement.' ', $explodes);
				if (in_array($last_keyword, ['LEFT', 'RIGHT', 'OUTER', 'INNER'])) {
					$o_parts[$statement][2] = $last_keyword.' '.$o_parts[$statement][2];
					$o_parts[$statement][1] = substr($o_parts[$statement][1], 0, -strlen($last_keyword) - 1);
				}
			} else {
				// = look only for last statement
				// and put remaining query in [1] and cut off part in [2]
				$o_parts[$statement][2] = array_pop($explodes);
				// last blank needed for exploding SELECT from DISTINCT
				$o_parts[$statement][1] = implode(' '.$statement.' ', $explodes).' '; 
			}
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
			if (substr_count($temp_sql, '(') === substr_count($temp_sql, ')')) {
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
	if (($n_part && $values) OR $mode === 'list') {
		$n_part = strtoupper($n_part);
		switch ($n_part) {
		case 'LIMIT':
			// replace complete old LIMIT with new LIMIT
			$o_parts['LIMIT'][2] = $values;
			break;
		case 'ORDER BY':
			if ($mode === 'add') {
				// append old ORDER BY to new ORDER BY
				if (!empty($o_parts['ORDER BY'][2])) 
					$o_parts['ORDER BY'][2] = $values.', '.$o_parts['ORDER BY'][2];
				else
					$o_parts['ORDER BY'][2] = $values;
			} elseif ($mode === 'delete') {
				unset($o_parts['ORDER BY']);
			} elseif ($mode === 'list') {
				if (!empty($o_parts['ORDER BY'][2])) {
					$tokens = wrap_edit_sql_fieldnames($o_parts['ORDER BY'][2]);
				}
			}
			break;
		case 'WHERE':
		case 'GROUP BY':
		case 'HAVING':
			if ($mode === 'add') {
				if (!empty($o_parts[$n_part][2])) 
					$o_parts[$n_part][2] = '('.$o_parts[$n_part][2].') AND ('.$values.')';
				else 
					$o_parts[$n_part][2] = $values;
			}  elseif ($mode === 'delete') {
				unset($o_parts[$n_part]);
			}
			break;
		case 'JOIN':
			if ($mode === 'delete') {
				// don't remove JOIN in case of WHERE, HAVING OR GROUP BY
				// SELECT and ORDER BY should be removed beforehands!
				// use at your own risk
				if (isset($o_parts['WHERE'])) break;
				if (isset($o_parts['HAVING'])) break;
				if (isset($o_parts['GROUP BY'])) break;
				unset($o_parts['JOIN']);
			} elseif ($mode === 'add') {
				// add is only possible with correct JOIN statement in $values
				if (empty($o_parts[$n_part][2])) $o_parts[$n_part][2] = '';
				$o_parts[$n_part][2] .= ' '.$values;
			} elseif ($mode === 'replace') {
				// replace is only possible with correct JOIN statement in $values
				$o_parts[$n_part][2] = $values;
			}
			break;
		case 'FROM':
			if ($mode === 'list') {
				$tokens = [];
				$tokens[] = wrap_edit_sql_tablenames($o_parts['FROM'][2]);
				if (isset($o_parts['JOIN']) AND stristr($o_parts['JOIN'][2], 'JOIN')) {
					$test = explode('JOIN', $o_parts['JOIN'][2]);
					for ($i = 0; $i < count($test); $i++) {
						if (!$i & 1) continue;
						$table = explode(' ', trim($test[$i]));
						$tokens[] = wrap_edit_sql_tablenames($table[0]);
					}
				}
			}
			break;
		case 'SELECT':
			if (!empty($o_parts['SELECT DISTINCT'][2])) {
				if ($mode === 'add')
					$o_parts['SELECT DISTINCT'][2] .= ','.$values;
				elseif ($mode === 'replace')
					$o_parts['SELECT DISTINCT'][2] = $values;
				elseif ($mode === 'list')
					$tokens = wrap_edit_sql_fieldlist($o_parts['SELECT DISTINCT'][2]);
			} else {
				if ($mode === 'add')
					$o_parts['SELECT'][2] .= ','.$values;
				elseif ($mode === 'replace')
					$o_parts['SELECT'][2] = $values;
				elseif ($mode === 'list')
					if (!empty($o_parts['SELECT'][2]))
						$tokens = wrap_edit_sql_fieldlist($o_parts['SELECT'][2]);
					else
						$tokens = [];
			}
			break;
		case 'FORCE INDEX':
			if ($mode === 'delete') unset($o_parts[$n_part]);
			break;
		default:
			echo 'The variable <code>'.$n_part.'</code> is not supported by '.__FUNCTION__.'().';
			exit;
		}
	}
	if ($mode === 'list') {
		if (!isset($tokens)) return [];
		return $tokens;
	}
	$statements_asc = array_reverse($statements_desc);
	foreach ($statements_asc as $statement) {
		if (!empty($o_parts[$statement][2])) {
			$keyword = $statement === 'JOIN' ? '' : $statement;
			$sql .= ' '.$keyword.' '.$o_parts[$statement][2];
		}
	}
	return $sql;
}

/**
 * put a list of fields in an SQL query into an array of fields
 *
 * @param string $fields part after SELECT and before FROM
 * @return array
 */
function wrap_edit_sql_fieldlist($fields) {
	$fields = explode(',', $fields);
	$append_next = false;
	$open = 0;
	foreach ($fields as $index => $field) {
		$field = trim($field);
		$count_open = substr_count($field, '(');
		$count_close = substr_count($field, ')');
		$count = $count_close - $count_open;

		if ($append_next !== false) {
			$fields[$append_next] .= ', '.$field;
			if ($count > 0) {
				if ($open) $open -= $count;
				if (!$open) $append_next = false;
			}
			unset($fields[$index]);
		} else {
			$fields[$index] = $field;
		}
		if ($count < 0) {
			$open -= $count;
			if (!$append_next) $append_next = $index;
		}
	}
	$fields = array_values($fields);
	foreach ($fields as $index => $field) {
		if ($pos = stripos($field, ' AS ')) {
			$new[$index]['field_name'] = substr($field, 0, $pos);
			$new[$index]['as'] = substr($field, $pos + 4);
		} else {
			$new[$index]['field_name'] = $new[$index]['as'] = $field;
		}
	}
	return $new;
}


/**
 * get a clean table name without spaces and AS
 *
 * @param string $table 'raw' name of table
 * @return string
 */
function wrap_edit_sql_tablenames($table) {
	$table = trim($table);
	if (strstr($table, ' ')) {
		// remove AS ...
		$table = trim(substr($table, 0, strpos($table, ' ')));
	}
	return $table;
}

/**
 * get a list of fields from SQL query
 * remove sort order ASC, DESC, remove IFNULL()
 *
 * @param mixed $fields (array = list of fields, string = fields concatenated with ,)
 * @return array
 */
function wrap_edit_sql_fieldnames($fields) {
	if (!is_array($fields)) {
		$fields = trim($fields);
		$fields = explode(',', $fields);
	}
	foreach ($fields as $index => $value) {
		$value = trim($value);
		if (substr($value, 0, 7) === 'IFNULL(') $value = substr($value, 7);
		elseif (substr($value, -4) === ' ASC') $value = substr($value, 0, -4);
		elseif (substr($value, -5) === ' DESC') $value = substr($value, 0, -5);
		if (substr($value, -1) === ')') $value = substr($value, 0, -1);
		$fields[$index] = $value;
	}
	return $fields;
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
 * @param string $mode (optional: get(default), set, add, overwrite)
 * @return mixed true: set was succesful; string: SQL query or field name 
 *		corresponding to $key
 */
function wrap_sql($key, $mode = 'get', $value = false) {
	global $zz_conf;
	global $zz_setting;
	static $zz_sql;
	static $set;
	static $modifications;
	if (empty($modifications)) $modifications = [];
	if (!isset($zz_sql)) $zz_sql = [];

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
			$zz_sql += wrap_system_sql('core');

			$zz_sql['is_public'] = 'live = "yes"';

			$zz_sql['redirects_new_fieldname'] = 'new_url';
			$zz_sql['redirects_old_fieldname'] = 'old_url';

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
				$zz_sql['translation_matrix_breadcrumbs'] = '/*_PREFIX_*/webpages';

				if (!empty($zz_setting['default_source_language'])) {
					$zz_sql['translations'] = sprintf('SELECT translation_id, translationfield_id, translation, field_id,
					"%s" AS source_language
					FROM /*_PREFIX_*/_translations_%%s translations
					LEFT JOIN /*_PREFIX_*/languages languages USING (language_id)
					WHERE translationfield_id IN (%%s) 
						AND field_id IN (%%s)
						AND languages.iso_639_1 = "%%s"', $zz_setting['default_source_language']);
				}
			}
			
			break;
		case 'page':
			if (!empty($set['page'])) return true;
			$set['page'] = true;
			$zz_sql += wrap_system_sql('page');

			$zz_sql['menu_table'] = '/*_PREFIX_*/webpages';
			break;
		case 'auth':
			if (!empty($set['auth'])) return true;
			$set['auth'] = true;
			$zz_sql += wrap_system_sql('auth');
			if (empty($zz_sql['domain']))
				$zz_sql['domain'] = [$zz_setting['hostname']];

			$zz_sql['password'] = 'password';

			break;
		default:
			if (!empty($set[$key])) return true;
			$set[$key] = true;
			$zz_sql += wrap_system_sql($key);
			break;
		}
		if (file_exists($zz_setting['custom_wrap_sql_dir'].'/sql-'.$key.'.inc.php')
			AND !empty($zz_conf['db_connection']))
			require_once $zz_setting['custom_wrap_sql_dir'].'/sql-'.$key.'.inc.php';

		if (!empty($zz_sql['domain']) AND !is_array($zz_sql['domain']))
			$zz_sql['domain'] = [$zz_sql['domain']];
		
		if (!empty($zz_setting['multiple_websites'])) {
			$modify_queries = [
				'pages', 'redirects', 'redirects_*', 'redirects*_', 'breadcrumbs',
				'menu'
			];
			foreach ($modify_queries as $key) {
				if (!in_array($key, $modifications) AND !empty($zz_sql[$key])) {
					if (isset($zz_sql[$key.'_websites_where'])) {
						// allow local modifications, e. g. menu_websites_where
						if ($zz_sql[$key.'_websites_where']) {
							$zz_sql[$key] = wrap_edit_sql($zz_sql[$key], 'WHERE'
								, $zz_sql[$key.'_websites_where']
							);
						}
					} else {
						$zz_sql[$key] = wrap_edit_sql($zz_sql[$key], 'WHERE'
							, sprintf('website_id = %d', $zz_setting['website_id'])
						);
					}
					$modifications[] = $key;
				}
			}
		}
		return true;
	case 'add':
		if (empty($zz_sql[$key])) {
			$zz_sql[$key] = [$value];
			return true;
		}
		if (is_array($zz_sql[$key])) {
			$zz_sql[$key][] = $value;
			return true;
		}
		return false;
	case 'overwrite':
		$zz_sql[$key] = $value;
		return true;
	default:
		return false;	
	}
}

/**
 * read a .sql file
 *
 * @param string $filename
 * @param string $key_separator (optional) if set, cut key and overwrite
 *		existing queries, otherwise: list all queries from all files
 * @return array lines, grouped
 */
function wrap_sql_file($filename, $key_separator = '') {
	$data = [];
	$lines = file($filename);
	foreach ($lines as $line) {
		$line = trim($line);
		if (substr($line, 0, 3) === '/**') continue;
		if (substr($line, 0, 1) === '*') continue;
		if (substr($line, 0, 2) === '*/') continue;
		if (!$line) continue;
		if (substr($line, 0, 3) === '-- ') {
			$line = substr($line, 3);
			if ($key_separator) {
				$key = substr($line, 0, strpos($line, '_'));
				$line = substr($line, strlen($key) + 1);
				$subkey = substr($line, 0, strpos($line, ' '));
				$data[$key][$subkey] = '';
			} else {
				$key = substr($line, 0, strpos($line, ' '));
				$index[$key] = 0;
				$data[$key][$index[$key]] = '';
			}
		} else {
			if (empty($key)) {
				$key = 'file';
				$index[$key] = 0;
			}
			if ($key_separator) {
				$line = rtrim($line, ';');
				$data[$key][$subkey] .= $line.' ';
			} else {
				if (empty($data[$key][$index[$key]])) {
					$data[$key][$index[$key]] = '';
				}
				$data[$key][$index[$key]] .= rtrim($line, ';').' ';
				if (substr($line, -1) === ';') $index[$key]++;
			}
		}
	}
	return $data;
}

/**
 * install: read ignore list per module
 *
 * @param string $module (optional)
 * @param string $table (optional)
 * @return bool true: please ignore
 */
function wrap_sql_ignores($module = '', $table = '') {
	static $ignores;
	
	if ($module and $table) {
		if (empty($ignores)) return false;
		if (!array_key_exists($module, $ignores)) return false;
		if (empty($ignores[$module][$table])) return false;
		return true;
	}
	
	if (!empty($ignores)) return false;
	$files = wrap_collect_files('install-ignore.sql');
	$data = [];
	foreach ($files as $filename) {
		$data = array_merge($data, wrap_sql_file($filename));
	}
	foreach (array_keys($data) as $key) {
		$key = explode('.', $key);
		$ignores[$key[0]][$key[1]] = true;
	}
	return false;
}

/**
 * read system SQL queries from system.sql file
 *
 * @param string $subtree (optional) return only queries for key
 * @return array
 */
function wrap_system_sql($subtree = '') {
	global $zz_setting;
	static $data;

	if (empty($data)) {
		$data = [];
		$files = wrap_collect_files('system.sql', 'modules/custom');
		
		foreach ($files as $filename) {
			$data = wrap_array_merge($data, wrap_sql_file($filename, '_'));
		}
		$data = wrap_system_sql_placeholders($data);
	}

	if ($subtree AND array_key_exists($subtree, $data)) return $data[$subtree];
	return $data;
}

/**
 * replace placeholders in system.sql queries
 *
 * _PREFIX_ with $zz_conf['prefix']
 * _ID LANGUAGES ENG_ with wrap_id('languages', 'eng')
 * _ID LANGUAGES SETTING LANG3_ with wrap_id('languages', $zz_setting['lang3'])
 * _ID SETTING LANG3_ with $zz_setting['lang3']
 *
 * @param array $data
 * @return array
 */
function wrap_system_sql_placeholders($data) {
	global $zz_setting;
	global $zz_conf;

	$placeholders = ['ID', 'SETTING'];
	foreach ($data as $subtree => $queries) {
		foreach ($queries as $key => $query) {
			if (strstr($query, '/*_PREFIX_*/')) {
				$query = $data[$subtree][$key] = str_replace('/*_PREFIX_*/', $zz_conf['prefix'], $query);
			}
			foreach ($placeholders as $placeholder) {
				if (strstr($query, '/*_'.$placeholder)) {
					$parts = explode('/*_'.$placeholder, $query);
					$query = '';
					foreach ($parts as $index => $part) {
						if (!$index) {
							$query .= $part;
							continue;
						}
						$part = explode('_*/', $part);
						$part[0] = trim(strtolower($part[0]));
						$part[0] = explode(' ', $part[0]);
						$val = false;
						switch ($placeholder) {
						case 'ID':
							if (count($part[0]) === 3 AND $part[0][1] === 'setting') {
								$val = wrap_id($part[0][0], $zz_setting[$part[0][2]]);
							} else {
								$val = wrap_id($part[0][0], $part[0][1]);
							}
							break;
						case 'SETTING':
							if (array_key_exists($part[0][0], $zz_setting))
								$val = $zz_setting[$part[0][0]];
							break;
						}
						if ($val) $part[0] = $val;
						else {
							// no value available
							wrap_error(sprintf(
								'Unable to replace placeholder %s (%s)'
								, $placeholder, implode(': ', $part[0])
							));
							$part[0] = 0;
						}
						$query .= implode('', $part);
					}
					$data[$subtree][$key] = $query;
				}
			}
		}
	}
	return $data;
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
	global $zz_setting;
	if ($zz_conf['db_connection']) return true;
	wrap_send_cache();
	wrap_error(sprintf('No connection to SQL server. (Host: %s)', $zz_setting['hostname']), E_USER_ERROR);
	exit;
}

/**
 * Escapes values for database input
 *
 * @param string $value
 * @return string escaped $value
 */
function wrap_db_escape($value) {
	global $zz_conf;

	// should never happen, just during development
	if (!$value AND $value !== '0' AND $value !== 0) return '';
	if (is_array($value) OR is_object($value)) {
		wrap_error(__FUNCTION__.'() - value is not a string: '.json_encode($value));
		return '';
	}
	if (!$zz_conf['db_connection']) {
		return addslashes($value);
	}
	return mysqli_real_escape_string($zz_conf['db_connection'], $value);
}

/**
 * Change MySQL mode
 *
 * @param void
 * @return void
 * @global array $zz_setting
 *		'unwanted_mysql_modes'	
 */
function wrap_mysql_mode() {
	global $zz_setting;
	if (empty($zz_setting['unwanted_mysql_modes'])) return;

	$sql = 'SELECT @@SESSION.sql_mode';
	$mode = wrap_db_fetch($sql, '', 'single value');
	$modes = explode(',', $mode);
	foreach ($modes as $index => $mode) {
		if (!in_array($mode, $zz_setting['unwanted_mysql_modes'])) continue;
		unset($modes[$index]);
	}
	$mode = implode(',', $modes);
	$sql = sprintf("SET SESSION sql_mode = '%s'", $mode);
	wrap_db_query($sql);
}

/**
 * get auto increment value of a table
 *
 * @param string $table
 * @return string
 */
function wrap_db_auto_increment($table) {
	global $zz_conf;
	$sql = 'SHOW TABLE STATUS FROM `%s` WHERE `name` LIKE "%s"';
	$sql = sprintf($sql, $zz_conf['db_name'], $table);
	$data = wrap_db_fetch($sql);
	if (empty($data)) return '';
	return $data['Auto_increment'];
}

/**
 * read language IDs from database
 *
 * @param string $language
 * @param string $action (optional, default 'read', 'list')
 * @return int
 */
function wrap_language_id($language, $action = 'read') {
	return wrap_id('languages', $language, $action);
}

/**
 * read category IDs from database
 *
 * @param string $category
 * @param string $action (optional, default 'read', 'list')
 * @return int
 */
function wrap_category_id($category, $action = 'read') {
	return wrap_id('categories', $category, $action);
}

/**
 * read filetype IDs from database
 *
 * @param string $filetype
 * @param string $action (optional, default 'read', 'list')
 * @return int
 */
function wrap_filetype_id($filetype, $action = 'read') {
	return wrap_id('filetypes', $filetype, $action);
}

/**
 * read IDs from database
 *
 * @param string $table
 * @param string $identifier
 * @param string $action (optional, default 'read', 'list', 'write', 'check')
 *		check does not log an error if ID is not found
 * @param string $value (optional, for 'write')
 * @param string $sql (optional, SQL query)
 * @return mixed
 */
function wrap_id($table, $identifier, $action = 'read', $value = '', $sql = '') {
	static $data;

	if (empty($data[$table])) {
		if (!$sql) {
			$queries = wrap_system_sql('ids');
			if (array_key_exists($table, $queries)) {
				$sql = $queries[$table];
			} else {
				wrap_error(sprintf('Table %s is not supported by wrap_id()', $table));
				return [];
			}
		}
		$data[$table] = wrap_db_fetch($sql, '_dummy_', 'key/value');
		$queries = wrap_system_sql('ids-aliases');
		if (array_key_exists($table, $queries)) {
			$sql_aliases = $queries[$table];
			if ($sql_aliases) {
				$aliases = wrap_db_fetch($sql_aliases, '_dummy_', 'key/value');
				foreach ($aliases as $id => $alias) {
					parse_str($alias, $parameters);
					if (empty($parameters['alias'])) continue;
					// convert to string since PHP treats all integers
					// from database as string, too
					// and there are no calculations with IDs
					if (!is_array($parameters['alias'])) {
						$parameters['alias'] = [$parameters['alias']]; 
					}
					foreach ($parameters['alias'] as $my_alias) {
						$data[$table][$my_alias] = strval($id);
					}
				}
			}
		}
	}

	switch ($action) {
	case 'write':
		$data[$table][$identifier] = $value;
		return $value;
	case 'check':
	case 'read':
		if (!array_key_exists($identifier, $data[$table])) {
			if ($action === 'read')
				wrap_error(sprintf(
					'ID value for table `%s`, key `%s` not found.', $table, $identifier
				));
			return false;
		}
		return $data[$table][$identifier];
	case 'list':
		if (!$identifier) return $data[$table];
		$my_data = [];
		foreach ($data[$table] as $key => $value) {
			if (str_starts_with($key, $identifier)) $my_data[$key] = $value;
		}
		return $my_data;
	default:
		return false;
	}
}
