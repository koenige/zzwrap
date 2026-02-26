<?php 

/**
 * zzwrap
 * Database functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
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
 *	- wrap_sql_query()
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Establishes a database connection (if not already established)
 * selects database, sets NAMES to character encoding
 *
 * @return bool true: database connection established, false: no connection
 */
function wrap_db_connect() {
	// do we already have a connection?
	if (wrap_db_connection()) return true;
	
	// local access: get local database name
	if (wrap_setting('local_access')) {
		if (wrap_setting('db_name_local')) {
			wrap_setting('db_name', wrap_setting('db_name_local'));
		} else {
			wrap_setting('authentication_possible', false);
			wrap_session_start();
			if (!empty($_SESSION['db_name_local']) AND !empty($_SESSION['step']) AND $_SESSION['step'] === 'finish') {
				wrap_setting('db_name', $_SESSION['db_name_local']);
				wrap_session_stop();
			}
			session_write_close();
		}
	}
	
	// connect to database
	$db = wrap_db_credentials();
	if (empty($db['db_port'])) $db['db_port'] = NULL;
	try {
		wrap_db_connection($db);
	} catch (Exception $e) {
		wrap_db_connection(false);
		wrap_error(sprintf('Error with database connection: %s', $e->getMessage()), E_USER_NOTICE, ['collect_start' => true]);
	}
	if (!wrap_db_connection()) return false;
	mysqli_report(MYSQLI_REPORT_OFF);

	wrap_db_charset();
	wrap_mysql_mode();
	return true;
}

/**
 * establish a database connection, return status without parameters
 *
 * @param mixed $db (optional, establish or kill a database connection)
 * @return object
 */
function wrap_db_connection($db = []) {
	static $connection = NULL;
	if ($db === false) {
		if ($connection) mysqli_close($connection);
		$connection = NULL;
		return NULL;
	}
	if (!$db) return $connection;
	$connection = mysqli_connect($db['db_host'], $db['db_user'], $db['db_pwd'], $db['db_name'] ?? wrap_setting('db_name'), $db['db_port']);
	return $connection;
}

/**
 * get connection details
 * files need to define
 * db_host, db_user, db_pwd, db_name, db_port (optional)
 *
 * @return array
 */
function wrap_db_credentials() {
	static $db = [];
	if ($db) return $db;
	
	$db_password_files = wrap_setting('db_password_files');
	$db_password_files[] = '';
	if (wrap_setting('local_access'))
		array_unshift($db_password_files, wrap_setting('local_pwd'));

	$found = false;
	$rewrite = false;
	foreach ($db_password_files as $file) {
		if (substr($file, 0, 1) !== '/') {
			$filename = wrap_setting('custom').'/zzwrap_sql/pwd'.$file.'.inc.php';
			if (!file_exists($filename)) {
				$filename = wrap_config_filename('pwd'.$file);
				if (!file_exists($filename)) continue;
				$db = json_decode(file_get_contents($filename), true);
				wrap_setting('db_name', $db['db_name']);
			} else {
				include $filename;
				$rewrite = true;
			}
		} elseif (!file_exists($file)) {
			continue;
		} else {
			if (str_ends_with($file, '.json')) {
				$db = json_decode(file_get_contents($file), true);
				if (!empty($db['db_name'])) {
					wrap_setting('db_name', $db['db_name']);
					if ($file === wrap_setting('local_pwd'))
						wrap_setting('db_name_local', $db['db_name']);
				}
			} else {
				include $file;
				$rewrite = true;
			}
		}
		$found = true;
		break;
	}
	if (!$found) wrap_error('No password file for database found.', E_USER_ERROR);
	if ($rewrite) {
		// @deprecated
		// $zz_conf['db_name'] should be set in pwd.inc.php
		$db = [
			'db_host' => $db_host,
			'db_user' => $db_user,
			'db_pwd' => $db_pwd,
			'db_port' => isset($db_port) ? $db_port : false,
		];
		wrap_setting('db_name', $zz_conf['db_name']);
	}
	return $db;
}

/**
 * set a character encoding for the database connection
 *
 * @param string $charset (optional)
 * @return void
 */
function wrap_db_charset($charset = '') {
	if (!$charset) {
		$charset = wrap_setting('encoding_to_mysql_encoding['.wrap_setting('character_set').']');
		if (!$charset) {
			wrap_error(sprintf('No character set for %s found.', wrap_setting('character_set')), E_USER_NOTICE);
			return;
		}
	}
	if (strtolower($charset) === 'utf8') {
		// use utf8mb4, the real 4-byte utf-8 encoding if database is in utf8mb4
		// instead of proprietary 3-byte utf-8
		$sql = 'SELECT @@character_set_database';
		$result = wrap_db_fetch($sql, '', 'single value');
		if ($result === 'utf8mb4') $charset = 'utf8mb4';
	}
	mysqli_set_charset(wrap_db_connection(), $charset);
}

/**
 * gets character encoding which is used for current db connection
 *
 * @param void
 * @return string
 */
function wrap_db_encoding() {
	static $character_set = '';
	if (!$character_set) {
		$sql = 'SHOW VARIABLES LIKE "character_set_connection"';
		$data = wrap_db_fetch($sql);
		$character_set = $data['Value'];
	}
	return $character_set;
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
	if (!$sql) return $sql;
	$prefix = '/*_PREFIX_*/';
	if (!strstr($sql, $prefix)) return $sql;
	return wrap_sql_replace($prefix, wrap_setting('db_prefix'), $sql);
}

/**
 * remove table prefix at the beginning
 *
 * @param string $sql
 * @return string
 */
function wrap_db_prefix_remove($sql) {
	if (!$sql) return $sql;
	$prefix = '/*_PREFIX_*/';
	if (!str_starts_with($sql, $prefix)) return $sql;
	return substr($sql, strlen($prefix));
}

/**
 * queries database and does the error handling in case an error occurs
 * the query will be changed: trimmed and prefix is replaced
 *
 * @param string $sql
 * @param int $error (optional) error code
 * @return mixed
 *		bool: false = query failed, true = query was succesful
 *		array:	'id' => on INSERT: inserted ID if applicable
 *				'rows' => number of inserted/deleted/updated rows
 */
function wrap_db_query(&$sql, $error = E_USER_ERROR) {
	if (!wrap_db_connection()) return false;

	$sql = trim($sql);
	if (str_starts_with($sql, 'SET NAMES ')) {
		$charset = trim(substr($sql, 10));
		return wrap_db_charset($charset);
	}

	$sql = wrap_sql_placeholders($sql);
	$statement = wrap_sql_statement($sql);		

	wrap_db_warnings('delete');
	if (wrap_setting('debug')) $time = microtime(true);
	$result = mysqli_query(wrap_db_connection(), $sql);
	if (wrap_setting('debug')) wrap_error_sql($sql, $time);
	
	$return = [];
	switch ($statement) {
	case 'INSERT INTO':
		// return inserted ID
		$return['id'] = mysqli_insert_id(wrap_db_connection());
	case 'UPDATE':
	case 'DELETE FROM':
		// return number of updated or deleted rows
		$return['rows'] = mysqli_affected_rows(wrap_db_connection());
		break;
	}
	if (!in_array($statement, ['SET', 'SELECT']) AND $sql !== 'SHOW WARNINGS') {
		$warnings = wrap_db_warnings();
		if ($warnings) {
			$db_msg = [];
			$warning_error = E_USER_WARNING;
			foreach ($warnings as $warning) {
				$db_msg[] = $warning['Level'].': '.$warning['Message'];
				if ($warning['Level'] === 'Error') $warning_error = $error;
			}
			if ($db_msg and $error)
				wrap_error('['.$_SERVER['REQUEST_URI'].'] MySQL reports a problem.'
					.sprintf("\n\n%s\n\n%s", implode("\n\n", $db_msg), $sql), $warning_error);
		}
	} else {
		$warnings = [];
	}
	if ($result) {
		if ($return) return $return;
		return $result;
	}

	$error_no = wrap_db_error_no();
	if ($error_no === 2006 AND in_array($statement, ['SET', 'SELECT'])) {
		// retry connection
		$success = wrap_db_connect();
		if ($success) {
			$result = mysqli_query(wrap_db_connection(), $sql);
			if ($result) return $result;
			wrap_db_error_no();
		}
	}
	if (!wrap_db_connection()) {
		// try to send file from cache
		wrap_send_cache();
		// if there’s no cache, send error
		wrap_error('['.$_SERVER['REQUEST_URI'].'] Database server has gone away', $error);
	} elseif ($error) {
		$error_msg = mysqli_error(wrap_db_connection());
		if (!$warnings) {
			wrap_error('['.$_SERVER['REQUEST_URI'].'] '
				.sprintf('Error in SQL query:'."\n\n%s\n\n%s", $error_msg, $sql), $error);
		} elseif (wrap_setting('error_handling') === 'output') {
			global $zz_page;
			$zz_page['error_msg'] = '<p class="error">'.$error_msg.'<br>'.$sql.'</p>';
		}
	}
	return false;	
}

/**
 * get warnings from database
 *
 * @param int $error (optional)
 * @param string $action (optional)
 * @return array
 */
function wrap_db_warnings($action = 'check') {
	static $saved_warnings = [];
	
	switch ($action) {
	case 'check':
		$warnings = wrap_db_fetch('SHOW WARNINGS', '_dummy_', 'numeric');
		foreach ($warnings as $warning)
			$saved_warnings[] = $warning;
		break;
	case 'list':
		break;
	case 'delete':
		$saved_warnings = [];
		break;
	}
	return $saved_warnings;
}

/**
 * close connection if there's a database error, read error number
 *
 * @return int
 */
function wrap_db_error_no() {
	$close_connection_errors = [
		1030,	// Got error %d from storage engine
		1317,	// Query execution was interrupted
		2006,	// MySQL server has gone away
		2008	// MySQL client ran out of memory
	];

	$error_no = mysqli_errno(wrap_db_connection());
	if (in_array($error_no, $close_connection_errors))
		wrap_db_connection(false);
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
 *  'count' = returns count of rows
 *	'id as key' = returns [$id_field_value => true]
 *	"key/value" = returns [$key => $value]
 *	"key/values" = returns [$key => [$values]]
 *	"single value" = returns $value
 *	"object" = returns object
 *	"numeric" = returns lines in numerical array [0 ... n] instead of using field ids
 *	"list field_name_1 field_name_2" = returns lines in hierarchical array
 *	for direct use in zzbrick templates, e. g. 0 => [
 *		field_name_1 = value, field_name_2 = []], 1 => ..
 * @param int $error_type let's you set error level, default = E_USER_ERROR
 * @return array with queried database content
 * @todo give a more detailed explanation of how function works
 */
function wrap_db_fetch($sql, $id_field_name = false, $format = false, $error_type = E_USER_ERROR) {
	static $last_query;
	if ($sql === 'last_query') return $last_query;
	$result = wrap_db_query($sql, $error_type);
	$last_query = $sql;
	if (!$result) return [];

	$lines = wrap_db_fetch_values($result, $id_field_name, $format);
	$errors = wrap_db_error_log('', 'clear');
	foreach ($errors as $error)
		wrap_error($lines, E_USER_WARNING);
	return $lines;
}

/**
 * get values from database
 *
 * @param resource $result
 * @param mixed $id_field_name
 * @param string $format
 * @return mixed
 */
function wrap_db_fetch_values($result, $id_field_name, $format) {
	$lines = [];
	$error = NULL;

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
				if (is_null($error) AND $error = wrap_db_fields_in_record($id_field_name, $line)) return $error;
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
				if (is_null($error) AND $error = wrap_db_fields_in_record($id_field_name, $line)) return $error;
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
				} elseif ($format === 'key/values') {
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
		} elseif ($format === 'id as key') {
			while ($line = mysqli_fetch_array($result)) {
				if (is_null($error) AND $error = wrap_db_fields_in_record($id_field_name, $line)) return $error;
				$lines[$line[$id_field_name]] = true;
			}
		} elseif ($format === 'key/value') {
			// return array in pairs
			while ($line = mysqli_fetch_array($result)) {
				$lines[$line[0]] = $line[1];
			}
		} elseif ($format === 'object') {
			while ($line = mysqli_fetch_object($result)) {
				if (is_null($error) AND $error = wrap_db_fields_in_record($id_field_name, $line)) return $error;
				$lines[$line->$id_field_name] = $line;
			}
		} elseif ($format === 'numeric') {
			while ($line = mysqli_fetch_assoc($result))
				$lines[] = $line;
		} else {
			// default or unknown format
			while ($line = mysqli_fetch_assoc($result)) {
				if (is_null($error) AND $error = wrap_db_fields_in_record($id_field_name, $line)) return $error;
				$lines[$line[$id_field_name]] = $line;
			}
		}
	}
	return $lines;
}

/**
 * checks if a field is present in a record
 *
 * @param mixed $fields
 * @param array $record
 * @return bool
 */
function wrap_db_fields_in_record($fields, $record) {
	if (!is_array($fields)) $fields = [$fields];
	$missing_fields = array_diff($fields, array_keys($record));
	if ($missing_fields)
		wrap_db_error_log(wrap_text('Fields <code>%s</code> are missing in SQL query'
			, ['values' => implode(', ', $missing_fields)]
		));
	return false;
}

/**
 * log errors in database operations for later use
 *
 * @param string $error_msg
 * @return array
 */
function wrap_db_error_log($error_msg = '', $action = 'add') {
	static $errors = [];
	switch ($action) {
	case 'add':
		if (!$error_msg) break;
		$errors[] = $error_msg;
		break;
	case 'clear':
		$return = $errors;
		$errors = [];
		return $return;
	}
	return $errors;
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
	static $updates = [];
	$key = json_encode($tables);
	if (array_key_exists($key, $updates)) return $updates[$key];
	
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
		$updates[$key] = false;
	else
		$updates[$key] = $last_update;
	return $updates[$key];
}

/**
 * Escapes values for database input
 *
 * @param string $value
 * @return string escaped $value
 */
function wrap_db_escape($value) {
	// should never happen, just during development
	if (!$value AND $value !== '0' AND $value !== 0) return '';
	if (is_array($value) OR is_object($value)) {
		wrap_error(__FUNCTION__.'() - value is not a string: '.json_encode($value));
		return '';
	}
	if (!wrap_db_connection()) {
		return addslashes($value);
	}
	return mysqli_real_escape_string(wrap_db_connection(), $value);
}

/**
 * get auto increment of table
 *
 * @param string $table
 * @return int
 */
function wrap_mysql_increment($table) {
	$sql = 'SELECT `AUTO_INCREMENT`
		FROM  INFORMATION_SCHEMA.TABLES
		WHERE TABLE_SCHEMA = "/*_SETTING db_name _*/"
		AND   TABLE_NAME   = "%s";';
	$sql = sprintf($sql, $table);
	return wrap_db_fetch($sql, '', 'single value');
}

/**
 * do a simple query (shortcut function)
 *
 * @param string $query
 * @param array $values (optional, values for query)
 * @return mixed
 */
function wrap_db_data($query, $values = []) {
	$sql = wrap_sql_query($query);
	if ($values) $sql = vsprintf($sql, $values);
	$data = wrap_db_fetch($sql, '_dummy_', 'numeric');
	// one record? only return that
	if (count($data) === 1) $data = reset($data);
	// one field? only return that
	if (count($data) === 1) $data = reset($data);
	return $data;
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
		$sqls = wrap_edit_sql_statement($sql, 'UNION SELECT');
		if (count($sqls) > 1) {
			foreach ($sqls as $index => $single_sql) {
				if ($index) $single_sql = ' SELECT '.$single_sql;
				$sqls[$index] = trim(wrap_edit_sql($single_sql, $n_part, $values, $mode));
			}
			$sql = implode(' UNION ', $sqls);
		}
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
		$explodes = wrap_edit_sql_statement($sql, $statement);
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
 * @param string $string part after SELECT and before FROM
 * @return array
 */
function wrap_edit_sql_fieldlist($string) {
	$fields = [];
	$i = 0;
	$fields[$i] = '';
	$strings = mb_str_split($string);
	$stop = false;
	$last_string = '';
	$inside_string = false;
	$inside_brackets = 0;
	foreach ($strings as $string) {
		switch ($string) {
		case ',':
			if ($inside_string) break;
			if ($inside_brackets) break;
			$stop = true;
			break;
		case '"':
			if ($last_string === '\\') break;
			$inside_string = $inside_string ? false : true;
			break;
		case '(':
			if ($inside_string) break;
			$inside_brackets++;
			break;
		case ')':
			if ($inside_string) break;
			$inside_brackets--;
			break;
		}
		if ($stop) {
			$i++;
			$fields[$i] = '';
			$stop = false;
			continue;
		}
		$last_string = $string;
		$fields[$i] .= $string;
	}
	
	$new = [];
	foreach ($fields as $index => $field) {
		$field = trim($field);
		preg_match('/^(.+) AS ([a-z_]+)$/i', $field, $matches);
		if ($matches) {
			$new[$index]['field_name'] = $matches[1];
			$new[$index]['as'] = $matches[2];
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
 * get SQL statement and following code from query
 *
 * SQL statement may be inside quotation marks: go backwards through partial
 * queries, count quotation marks; if uneven, there is a quotation mark
 * not closed, so append query to previous query
 * take escaped quotation marks (\") into account
 * @param string $sql
 * @param string $statement
 * @return array
 */
function wrap_edit_sql_statement($sql, $statement) {
	$queries = explode(' '.$statement.' ', $sql);
	$i = count($queries) - 1;
	while ($i) {
		$marks = substr_count($queries[$i], '"') - substr_count($queries[$i], '\"');
		if ($marks & 1) {
			$queries[$i - 1] .= $queries[$i];
			unset($queries[$i]);
		}
		$i--;
	}
	return $queries;
}

/**
 * modify some queries if there are mulitple websites hosted
 *
 * @param string $key
 * @param array $queries
 * @return string
 */
function wrap_sql_modify($key, $queries) {
	static $modifications = [];
	if (!wrap_setting('multiple_websites')) return $queries[$key];
	if (in_array($key, $modifications)) return $queries[$key];

	$modify_queries = [
		'core_pages', 'core_redirects', 'core_redirects_*', 'core_redirects*_',
		'page_breadcrumbs','page_menu'
	];
	if (!in_array($key, $modify_queries)) return $queries[$key];
	
	$modifications[] = $key;
	$where_key = $key.'_websites_where';
	if (array_key_exists($where_key, $queries)) {
		// allow local modifications, e. g. page_menu_websites_where
		if (!$queries[$where_key]) return $queries[$key];
		$condition = $queries[$where_key][0];
	} else {
		$condition = 'website_id = /*_SETTING website_id _*/';
	}
	foreach ($queries[$key] as $index => $query)
		$queries[$key][$index] = wrap_edit_sql($query, 'WHERE', $condition);
	return $queries[$key];
}

/**
 * read a query from [filename].sql, default: queries.sql
 *
 * @param string $key
 * @param string $file
 * @return mixed
 */
function wrap_sql_query($key, $file = 'queries') {
	static $queries = [];
	static $collected = [];

	$system_prefixes = ['page', 'auth', 'core', 'ids'];
	$prefix = $package = substr($key, 0, strpos($key, '_'));
	if (in_array($package, $system_prefixes)) {
		$package = 'modules';
		$file = 'system';
		$prefix_check = $system_prefixes;
	} else {
		$prefix_check = [$package];
	}
	
	$filename = sprintf('configuration/%s.sql', $file);

	// first check custom queries, won’t be overwritten by later queries
	if (!in_array('custom-'.$filename, $collected)) {
		$files = wrap_collect_files($filename, 'custom');
		if ($files) {
			$this_file = reset($files);
			$custom_queries = wrap_sql_file($this_file);
			foreach ($custom_queries as $p_key => $p_query)
				$queries[$p_key] = $p_query;
		}
		$collected[] = 'custom-'.$filename;
	}
	// then check queries that are there explicitly to overwrite later queries
	if (!in_array('overwrite-'.$filename, $collected)) {
		$overwrite_filename = sprintf('configuration/overwrite-%s.sql', $file);
		$files = wrap_collect_files($overwrite_filename, 'modules');
		foreach ($files as $file) {
			$overwrite_queries = wrap_sql_file($file);
			foreach ($overwrite_queries as $p_key => $p_query) {
				if (array_key_exists($p_key, $queries)) continue;
				$queries[$p_key] = $p_query;
			}
		}
		$collected[] = 'overwrite-'.$filename;
	}
	
	if (!in_array($package.'-'.$filename, $collected)) {
		$files = wrap_collect_files($filename, $package);
		// move default to end
		if (array_key_exists('default', $files))
			$files['default'] = array_shift($files);
		foreach ($files as $file) {
			$package_queries = wrap_sql_file($file);
			foreach ($package_queries as $p_key => $p_query) {
				$p_query_prefix = substr($p_key, 0, strpos($p_key, '_'));
				if (!in_array($p_query_prefix, $prefix_check)) continue;
				if (array_key_exists($p_key, $queries)) continue;
				$queries[$p_key] = $p_query;
			}
		}
		$collected[] = $package.'-'.$filename;
	}
	if (str_ends_with($key, '**')) {
		foreach (array_keys($queries) as $query_key) {
			if (!str_starts_with($query_key, substr($key, 0, -2))) continue;
			$keys[] = $query_key;
		}
	} else {
		$keys = [$key];
	}

	foreach ($keys as $my_key) {
		$my_key_new = $my_key; // keep key for replacements
		if ($replace_key = wrap_setting('sql_query_key['.$my_key.']'))
			$my_key_new = $replace_key;
		if (!array_key_exists($my_key_new, $queries)) return '';
		$queries[$my_key] = wrap_sql_modify($my_key_new, $queries);
		$queries[$my_key] = wrap_sql_placeholders($queries[$my_key]);
	}
	if (str_ends_with($key, '**')) {
		$selected_queries = [];
		foreach ($keys as $my_key)
			$selected_queries[$my_key] = $queries[$my_key];
		return $selected_queries;
	}
	if (count($queries[$key]) > 1) return $queries[$key];
	return $queries[$key][0];
}

/**
 * read a table name from queries.sql
 *
 * @param string $key
 * @param string $filename (optional)
 * @return string
 */
function wrap_sql_table($key, $filename = 'queries') {
	$key .= '__table';
	$def = wrap_sql_query($key, $filename);
	if (is_array($def)) $def = reset($def);
	$def = wrap_setting('db_prefix').$def;
	return trim($def);
}

/**
 * read one or more fields from queries.sql
 *
 * @param string $key
 * @param string $filename (optional)
 * @return string
 */
function wrap_sql_fields($key, $filename = 'queries') {
	$key .= '__fields';
	$def = wrap_sql_query($key, $filename);
	if (is_array($def)) $def = implode(', ', $def);
	return trim($def);
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
				$check = substr($line, 0, strpos($line, ' '));
				if ($check === 'query') {
					$check = ltrim($line, 'query ');
					$check = rtrim($check, ' --');
					if (!empty($data[$key][0])) {
						// query no applying to table name before
						$key = $check;
						$index[$key] = 0;
						$data[$key][$index[$key]] = '';
					} else {
						// table name, directly followed by query
					}
					$data[$key]['query'][] = $check;
				} else {
					$key = $check;
					$index[$key] = 0;
					$data[$key][$index[$key]] = '';
				}
			}
		} else {
			if (empty($key)) {
				$key = 'file';
				$index[$key] = 0;
			}
			if ($key_separator) {
				$data[$key][$subkey] .= $line.' ';
			} else {
				if (str_ends_with($key, '__fields') OR str_ends_with($key, '__table'))
					$line = trim(rtrim(ltrim(trim($line), '/*'), '*/'));
				if (str_ends_with($key, '__table'))
					$line = wrap_setting('db_prefix').$line;
				if (empty($data[$key][$index[$key]])) {
					$data[$key][$index[$key]] = '';
				}
				$data[$key][$index[$key]] .= $line.' ';
				if (substr($line, -1) === ';') $index[$key]++;
			}
		}
	}
	// remove trailing semicola per query
	foreach ($data as $key => $queries)
		foreach ($queries as $index => $query) {
			if (is_array($query)) {
				foreach ($query as $subindex => $subquery)
					$data[$key][$index][$subindex] = rtrim(trim($subquery), ';');
			} else {
				$data[$key][$index] = rtrim(trim($query), ';');
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
	static $ignores = [];
	
	if ($module and $table) {
		if (!$ignores) return false;
		if (!array_key_exists($module, $ignores)) return false;
		if (empty($ignores[$module][$table])) return false;
		return true;
	}
	
	if (!empty($ignores)) return false;
	$files = wrap_collect_files('configuration/install-ignore.sql');
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
 * get login query depending on setting
 *
 * @return string
 */
function wrap_sql_login() {
	if (wrap_setting('login_with_email'))
		return wrap_sql_query('auth_login_email');
	if (wrap_setting('login_with_contact_id'))
		return wrap_sql_query('auth_login_contact');
	return wrap_sql_query('auth_login');
}

/**
 * read system SQL queries from system.sql file
 *
 * @param string $subtree return only queries for key
 * @return array
 */
function wrap_system_sql($subtree) {
	static $data = [];

	if (!$data) {
		$files = wrap_collect_files('configuration/system.sql', 'modules/custom');
		foreach ($files as $filename)
			$data = wrap_array_merge($data, wrap_sql_file($filename, '_'));
	}

	if (!array_key_exists($subtree, $data)) return [];
	$separate = [];
	if ($subtree === 'ids') {
		foreach ($data[$subtree] as $key => $query) {
			if (!strstr($query, '/*_ID')) continue;
			$separate[$key] = $query;
			unset($data[$subtree][$key]);
		}
	}
	$data[$subtree] = wrap_sql_placeholders($data[$subtree]);
	if ($separate)
		$data[$subtree] += wrap_sql_placeholders($separate);
	return $data[$subtree];
}

/**
 * replace placeholders in queries
 *
 * _PREFIX_ with wrap_setting('db_prefix')
 * _ID LANGUAGES ENG_ with wrap_id('languages', 'eng')
 * _ID LANGUAGES SETTING LANG3_ with wrap_id('languages', wrap_setting('lang3'))
 * _SETTING LANG3_ with wrap_setting('lang3')
 * _TABLE zzform_logging_ with wrap_sql_table('zzform_logging')
 *
 * @param mixed $queries
 * @return array
 */
function wrap_sql_placeholders($queries) {
	if (!is_array($queries)) {
		$queries = [$queries];
		$single_query = true;
	} else {
		$single_query = false;
	}

	$pattern_template = '~/\*_%s (.+?)_\*/~';
	$keywords = ['id', 'setting', 'table', 'text'];
	
	foreach ($queries as $key => &$query) {
		$query = wrap_db_prefix($query);
		foreach ($keywords as $keyword) {
			$pattern = sprintf($pattern_template, strtoupper($keyword));
			preg_match_all($pattern, $query, $matches);
			if (!$matches[1]) continue;
			foreach ($matches[1] as $index => $match) {
				$replace = wrap_sql_placeholders_replace($keyword, $match);
				$query = wrap_sql_replace($matches[0][$index], $replace, $query);
			}
		}
	}
	
	if ($single_query) return reset($queries);
	return $queries;
}

/**
 * find replacements for placholders
 *
 * @param stryng $keyword
 * @param string $match
 * @return string
 */
function wrap_sql_placeholders_replace($keyword, $match) {
	switch ($keyword) {
	case 'id':
		$match = explode(' ', trim(strtolower($match)));
		if (count($match) === 3 AND $match[1] === 'setting')
			$value = wrap_id($match[0], wrap_setting($match[2]), 'check');
		else
			$value = wrap_id($match[0], $match[1], 'check');
		if (!$value) $value = 0;
		return $value;
	case 'setting':
		$match = explode(' ', trim(strtolower($match)));
		$value = wrap_setting($match[0]);
		if (is_null($value))
			wrap_error(sprintf(
				'Unable to replace placeholder %s (%s)'
				, $keyword, implode(': ', $match)
			));
		return $value;
	case 'table':
		$match = explode(' ', trim(strtolower($match)));
		return wrap_sql_table($match[0]);
	case 'text':
		return wrap_text(trim($match));
	}
	return '';
}

/**
 * Do we have a database connection?
 * if not: send cache or exit
 * 
 * @return bool true: everything is okay
 */
function wrap_check_db_connection() {
	if (wrap_db_connection()) return true;
	wrap_send_cache();
	wrap_error(sprintf('No connection to SQL server. (Host: %s)', wrap_setting('hostname')), E_USER_ERROR);
	wrap_error(false, false, ['collect_end' => true]);
	exit;
}

/**
 * Change MySQL mode
 *
 * @param void
 * @return void
 */
function wrap_mysql_mode() {
	if (!wrap_setting('unwanted_mysql_modes')) return;

	$sql = 'SELECT @@SESSION.sql_mode';
	$mode = wrap_db_fetch($sql, '', 'single value');
	$modes = explode(',', $mode);
	foreach ($modes as $index => $mode) {
		if (!in_array($mode, wrap_setting('unwanted_mysql_modes'))) continue;
		unset($modes[$index]);
	}
	$mode = implode(',', $modes);
	$sql = sprintf("SET SESSION sql_mode = '%s'", $mode);
	wrap_db_query($sql);
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
	static $data = [];

	if (!wrap_database_table_check('categories', true)) return NULL;

	if (empty($data[$table])) {
		$data[$table] = wrap_id_read($table, $sql);
		if (!$data[$table]) return []; // array, eases check
	}

	if ($identifier)
		$identifier = strtolower($identifier);

	switch ($action) {
	case 'write':
		$data[$table][$identifier] = $value;
		return $value;
	case 'check':
	case 'read':
		if (!array_key_exists($identifier, $data[$table])) {
			if ($action === 'read' AND $identifier)
				wrap_error(sprintf(
					'ID value for table `%s`, key `%s` not found.', $table, $identifier
				));
			return NULL;
		}
		return $data[$table][$identifier];
	case 'read-id':
		foreach ($data[$table] as $key => $value)
			if ($value.'' === $identifier.'') return $key;
		return NULL;
	case 'list':
		if (!$identifier) return $data[$table];
		$my_data = [];
		foreach ($data[$table] as $key => $value) {
			if (str_starts_with($key, $identifier)) $my_data[$key] = $value;
		}
		return $my_data;
	default:
		return NULL;
	}
}

/**
 * read IDs and aliases for table
 *
 * @param string $table
 * @param string $sql
 * @return array
 */
function wrap_id_read($table, $sql) {
	if (!$sql) {
		$queries = wrap_system_sql('ids');
		if (array_key_exists($table, $queries) AND $queries[$table]) {
			$sql = $queries[$table];
			$sql_table = wrap_edit_sql($sql, 'FROM', false, 'list');
			if (!wrap_database_table_check($sql_table[0])) return [];
		} else {
			wrap_error(sprintf('Table %s is not supported by wrap_id()', $table));
			return [];
		}
	}
	$data = wrap_db_fetch($sql, '_dummy_', 'key/value');
	$queries = wrap_system_sql('ids-aliases');
	if (!array_key_exists($table, $queries)) {
		$data = array_change_key_case($data, CASE_LOWER);
		return $data;
	}

	$sql_aliases = $queries[$table];
	if (!$sql_aliases) return $data;

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
			$data[$my_alias] = strval($id);
			$identifier_prefix = array_search($id, $data);
			foreach ($data as $identifier => $value) {
				if (!str_starts_with($identifier, $identifier_prefix.'/')) continue;
				$new_identifier = $my_alias.substr($identifier, strlen($identifier_prefix));
				if (array_key_exists($new_identifier, $data)) continue;
				$data[$new_identifier] = $value;
			}
		}
	}
	$data = array_change_key_case($data, CASE_LOWER);
	return $data;
}

/**
 * get IDs from a tree for items that have children
 *
 * @param string $table
 * @param string $path
 * @return array
 */
function wrap_id_tree($table, $path) {
	$list = wrap_id($table, $path, 'list');
	$ids = [reset($list)];
	if (count($list) === 1)
		return $ids;

	foreach ($list as $item => $id) {
		$item_path = substr($item, strlen($path));
		if (substr_count($item_path, '/') <= 1) continue;
		$parent_path = substr($item, 0, strrpos($item, '/'));
		if (array_key_exists($parent_path, $list)) // should exist, but path might be not hierarchical
			$ids[] = $list[$parent_path];
	}
	$ids = array_unique($ids);
	return $ids;
}

/**
 * check if a database table exists
 * first check, if there is a database connection
 * then check table
 *
 * @param string $table
 * @param bool $only_if_install (optional) only check if CMS install is active
 * @return bool true: exists (or go on with code, won’t check)
 */
function wrap_database_table_check($table, $only_if_install = false) {
	static $table_exists = [];
	if (array_key_exists($table, $table_exists)) return $table_exists[$table] ? true : false;
	if ($only_if_install AND empty($_SESSION['cms_install'])) return true;
	$table = wrap_db_prefix_remove($table);
	$table = wrap_db_prefix(sprintf('/*_PREFIX_*/%s', $table));
	
	$sql = 'SELECT DATABASE()';
	$database = wrap_db_fetch($sql, '', 'single value');
	if (!$database) return false;
	
	$sql = 'SHOW TABLES';
	$table_exists += wrap_db_fetch($sql, '_dummy_', 'single value');
	if (!array_key_exists($table, $table_exists)) return false;
	return true;
}

/**
 * split query in string and non-string parts
 * to replace some placeholders only in non-string parts
 *
 * @param string $sql
 * @return array
 */
function wrap_sql_split($sql) {
	$strings = [];
	$no_strings = [];
	$current = '';
	$in_string = false;
	$quote_char = '';
	$len = strlen($sql);
	
	for ($i = 0; $i < $len; $i++) {
		$char = $sql[$i];
		
		if (!$in_string) {
			// Not in a string
			if ($char === '"' || $char === "'") {
				// Start of a string
				if ($current !== '') {
					$no_strings[] = $current;
					$current = '';
				}
				$in_string = true;
				$quote_char = $char;
				$current = $char;
			} else {
				// Regular character
				$current .= $char;
			}
		} else {
			// Inside a string
			$current .= $char;
			
			if ($char === '\\' && $i + 1 < $len) {
				// Escape sequence - add next character too
				$i++;
				$current .= $sql[$i];
			} elseif ($char === $quote_char) {
				// End of string
				$strings[] = $current;
				$current = '';
				$in_string = false;
				$quote_char = '';
			}
		}
	}
	
	// Add any remaining content
	if ($current !== '') {
		if ($in_string) {
			$strings[] = $current;
		} else {
			$no_strings[] = $current;
		}
	}
	
	// Pad arrays to same length for compatibility
	$max = max(count($strings), count($no_strings));
	while (count($strings) < $max) $strings[] = '';
	while (count($no_strings) < $max) $no_strings[] = '';
	
	return [
		'strings' => $strings,
		'no_strings' => $no_strings
	];
}

/**
 * concatenate parts of query
 *
 * @param array $matches
 * @return string
 */
function wrap_sql_concat($matches) {
    $sql = [];
    $count = max(count($matches['strings']), count($matches['no_strings']));

    for ($i = 0; $i < $count; $i++) {
        if (isset($matches['no_strings'][$i]))
            $sql[] = $matches['no_strings'][$i];
        if (isset($matches['strings'][$i]))
            $sql[] = $matches['strings'][$i];
    }
    return implode('', $sql);
}

/**
 * replace parts outside strings or if equal to full strings in SQL queries
 *
 * @param string $search
 * @param string $replace
 * @param string $sql
 * @return string
 */
function wrap_sql_replace($search, $replace, $sql) {
	$sql = wrap_sql_split($sql);
	// outside strings: replace all values
	foreach ($sql['no_strings'] as $index => $part) {
		if (!$part) continue;
		if (!strstr($part, $search)) continue;
		$sql['no_strings'][$index] = str_replace($search, $replace, $part);
	}
	// inside strings: only replace if searched value is full string
	// to avoid replacing inside string when describing it
	$searches = [
		sprintf('"%s"', $search) => sprintf('"%s"', $replace),
		sprintf("'%s'", $search) => sprintf("'%s'", $replace)
	];
	foreach ($searches as $search => $replace) {
		foreach ($sql['strings'] as $index => $part) {
			if (!$part) continue;
			if ($part !== $search) continue;
			$sql['strings'][$index] = str_replace($search, $replace, $part);
		}
	}
	return wrap_sql_concat($sql);
}

/**
 * get SQL statement from query
 *
 * @param string $sql
 * @return string
 */
function wrap_sql_statement($sql) {
	// get rid of extra whitespace, just to check statements
	$sql_ws = preg_replace('~\s~', ' ', trim($sql));
	$tokens = explode(' ', $sql_ws);
	$multitokens = [
		'UNION', 'CREATE', 'DROP', 'ALTER', 'RENAME', 'TRUNCATE', 'LOAD', 'INSERT',
		'DELETE'
	];
	if (in_array($tokens[0], $multitokens))
		$keyword = sprintf('%s %s', $tokens[0], $tokens[1]);
	else
		$keyword = $tokens[0];
	return strtoupper($keyword);
}

/**
 * get plural from field name for table
 *
 * @param string $field_name
 * @param bool $shorten shorten the field name, by cutting part before last _ off
 * @return string
 */
function wrap_sql_plural($field_name, $shorten = true) {
	if (str_ends_with($field_name, '_id'))
		$field_name = substr($field_name, 0, -3);
	if ($shorten AND strstr($field_name, '_'))
		$field_name = substr($field_name, strrpos($field_name, '_') + 1);

	if (str_ends_with($field_name, 'y')) // country = countries
		return substr($field_name, 0, -1).'ies';
	if (str_ends_with($field_name, 's')) // class = classes
		return $field_name.'es';
	return $field_name.'s';
}

/**
 * get definition for fields of a given query
 *
 * @param string $sql
 * @return array
 */
function wrap_mysql_fields($sql) {
	// get all fields
	$fields = wrap_edit_sql($sql, 'SELECT', '', 'list');
	// SHOW DATABASE, SHOW TABLES won’t yield any results, then return
	if (!$fields) return [];

	// get table and character encoding
	$result = wrap_db_query($sql);
	$index = 0;
	$tables = [];
	while ($field_info = mysqli_fetch_field($result)) {
		$fields[$index]['table_alias'] = $field_info->table;
		$fields[$index]['table'] = $field_info->orgtable;
		$fields[$index]['type_no'] = $field_info->type;
		$fields[$index]['character_encoding'] = wrap_mysql_character_encoding($field_info->orgtable, $field_info->orgname);
		$fields[$index]['character_encoding_prefix'] = '';
		if ($fields[$index]['character_encoding'] AND $fields[$index]['character_encoding'] !== wrap_db_encoding())
			$fields[$index]['character_encoding_prefix'] = sprintf('_%s', $fields[$index]['character_encoding']);
		if ($field_info->orgtable)
			$tables[] = $field_info->orgtable;
		$index++;
	}
	
	// get field type
	foreach ($tables as $table) {
		$sql = sprintf('SHOW COLUMNS FROM `%s`', $table);
		$columns = wrap_db_fetch($sql, 'Field');
		foreach ($fields as $index => $field) {
			if ($field['table'] !== $table) continue;
			if (!array_key_exists($field['field_name'], $columns)) continue;
			$fields[$index]['type'] = $columns[$field['field_name']]['Type'];
			// remove unsigned attribute
			if ($pos = strpos($fields[$index]['type'], ' '))
				$fields[$index]['type'] = substr($fields[$index]['type'], 0, $pos);
			// remove length
			if ($pos = strpos($fields[$index]['type'], '('))
				$fields[$index]['type'] = substr($fields[$index]['type'], 0, $pos);
		}
	}
	return $fields;
}

/**
 * get character encoding from charsetnr
 *
 * @param string $table
 * @param string $field
 * @return string
 */
function wrap_mysql_character_encoding($table, $field) {
	static $character_encodings = [];
	if (!$character_encodings) {
		$sql = 'SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME
			FROM information_schema.COLUMNS 
			WHERE TABLE_SCHEMA = DATABASE()';
		$character_encodings = wrap_db_fetch($sql, ['TABLE_NAME', 'COLUMN_NAME', 'CHARACTER_SET_NAME'], 'key/value');
	}
	return $character_encodings[$table][$field] ?? NULL;
}

/**
 * get primary key of database table
 *
 * @param string $table
 * @return string
 */
function wrap_mysql_primary_key($table) {
	static $keys = [];
	if (!$keys) {
		$sql = 'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION
			FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
			WHERE TABLE_SCHEMA = "%s"
			AND CONSTRAINT_NAME = "PRIMARY"
			ORDER BY TABLE_NAME, ORDINAL_POSITION';
		$sql = sprintf($sql, wrap_setting('db_name'));
		$keys = wrap_db_fetch($sql, ['TABLE_NAME', 'ORDINAL_POSITION']);
	}
	if (!array_key_exists($table, $keys)) {
		wrap_error(wrap_text('Primary Key for table %s was not found.', ['values' => [$table]]), E_USER_WARNING);
		return '';
	}
	if (count($keys[$table]) > 1) {
		wrap_error(wrap_text('Primary Key for table %s spans over more than one field.', ['values' => [$table]]), E_USER_WARNING);
		return '';
	}
	
	return $keys[$table][1]['COLUMN_NAME'];
}
