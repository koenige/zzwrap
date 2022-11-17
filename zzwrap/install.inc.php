<?php 

/**
 * zzwrap
 * Installation of CMS
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2020-2022 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function wrap_install() {
	global $zz_setting;
	global $zz_conf;
	if (!$zz_setting['local_access']) return;
	$zz_conf['user'] = 'Crew droid Robot 571';

	$zz_setting['template'] = 'install-page';
	wrap_include_ext_libraries();
	$zz_setting['cache'] = false;
	
	wrap_session_start();
	$_SESSION['cms_install'] = true;
	if (empty($_SESSION['step'])) $_SESSION['step'] = 1;
	elseif (empty($_SESSION['db_name_local'])) $_SESSION['step'] = 1;
	else {
		$db = mysqli_select_db($zz_conf['db_connection'], wrap_db_escape($_SESSION['db_name_local']));
		if (!$db) $_SESSION['step'] = 1;
	}

	$files = wrap_collect_files('install/install', 'modules/custom');
	foreach ($files as $module => $file) {
		require_once $file;
		$_SESSION['module_install'][$module] = $module;
	}

	switch ($_SESSION['step']) {
		case 1: $page['text'] = wrap_install_dbname(); break;
		case 2: $page['text'] = wrap_install_user(); break;
		case 3: $page['text'] = wrap_install_settings(); break;
		case 4: $page['text'] = wrap_install_modules(); break;
		case 5: $page['text'] = wrap_install_remote_db(); break;
	}

	if ($page['text'] === true) {
		return wrap_redirect_change();
	} elseif ($page['text'] === false) {
		return false;
	}
	
	$page['status'] = 200;
	wrap_set_encoding('utf-8');
	wrap_htmlout_page($page);
	exit;
}

/**
 * ask for database name, create database and database content per module
 *
 * @param void
 * @return mixed
 */
function wrap_install_dbname() {
	global $zz_conf;
	global $zz_setting;

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (!empty($_POST['db_name_local'])) {
			$db = mysqli_select_db($zz_conf['db_connection'], wrap_db_escape($_POST['db_name_local']));
			if ($db) {
				$zz_conf['db_name'] = $_POST['db_name_local'];
				$_SESSION['step'] = 2;
				$_SESSION['db_name_local'] = $_POST['db_name_local'];
				return false;
			}
			$sql = sprintf('CREATE DATABASE `%s`', wrap_db_escape($_POST['db_name_local']));
			wrap_db_query($sql);
			$db = mysqli_select_db($zz_conf['db_connection'], wrap_db_escape($_POST['db_name_local']));
			if (!$db) $out['error'] = sprintf(
				'Unable to create database %s. Please check the database error log.'
				, wrap_html_escape($_POST['db_name_local'])
			);
			$_SESSION['db_name_local'] = $_POST['db_name_local'];
			wrap_sql_ignores();
			wrap_install_module('default');
			wrap_install_module('zzform');
			foreach ($zz_setting['modules'] as $module) {
				if (in_array($module, ['zzform', 'default'])) continue;
				wrap_install_module($module);
			}
			$_SESSION['step'] = 2;
			return true;
		}
	}

	$page['text'] = wrap_template('install-dbname');
	return $page;
}

/**
 * install database structure and content per module
 *
 * @param string $module
 * @return bool
 */
function wrap_install_module($module) {
	global $zz_setting;
	global $zz_conf;
	require_once $zz_conf['dir'].'/database.inc.php';

	$logging_table = wrap_database_table_check(wrap_sql_query('zzform_logging__table'), true);
	
	$files = wrap_collect_files('configuration/install.sql', $module);
	if (!$files) return false;
	$queries = wrap_sql_file($files[$module]);
	foreach ($queries as $table => $queries_per_table) {
		if (wrap_sql_ignores($module, $table)) continue;
		if (array_key_exists('query', $queries_per_table)) {
			// -- query SHOW TABLES LIKE `bla` -- with single value as result
			// true (or value) or false
			foreach ($queries_per_table['query'] as $sql) {
				$result = wrap_db_fetch($sql, '', 'single value');
				if (!$result) continue 2;
			}
			unset($queries_per_table['query']);
		}
		foreach ($queries_per_table as $index => $query) {
			// install already in logging table?
			if ($logging_table) {
				$sql = 'SELECT log_id FROM %s WHERE query = "%s"';
				$sql = sprintf($sql, wrap_sql_query('zzform_logging__table'), wrap_db_escape($query));
				$record = wrap_db_fetch($sql);
				if ($record) continue;
			}
			$success = wrap_db_query($query);
			if ($success)
				zz_log_sql($query, 'Crew droid Robot 571');
		}
	}
	wrap_setting_write('mod_'.$module.'_install_date', date('Y-m-d H:i:s'));
	return true;
}

/**
 * prepare for zzform usage
 *
 * @param void
 * @return void
 */
function wrap_install_zzform() {
	global $zz_conf;
	global $zz_page;
	global $zz_setting;
	require_once $zz_conf['dir'].'/zzform.php';

	if (!isset($zz_setting['brick_default_tables']))
		$zz_setting['brick_default_tables'] = true;
	$zz_page['url']['full'] = [
		'scheme' => $zz_setting['protocol'],
		'host' => $zz_setting['hostname'],
		'path' => $zz_setting['request_uri']
	];
	if (!empty($_SESSION['db_name_local']))
		$db = mysqli_select_db($zz_conf['db_connection'], $_SESSION['db_name_local']);
	return;
}

/**
 * ask for username and password and create a user in logins table
 *
 * @param void
 * @return mixed
 */
function wrap_install_user() {
	global $zz_setting;
	global $zz_conf;
	require_once $zz_conf['dir'].'/_functions.inc.php';

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		wrap_install_zzform();
		$values = [];
		$values['action'] = 'insert';
		$values['POST']['username'] = $_POST['username'];
		if (empty($zz_setting['install_without_login_rights']))
			$values['POST']['login_rights'] = 'admin';
		$values['POST']['password'] = $_POST['password'];
		$values['POST']['password_change'] = 'no';
		$ops = zzform_multi('logins', $values);
		if ($ops['id']) {
			$_SESSION['step'] = 3;
			return true;
		} else {
			echo wrap_print($values);
			echo wrap_print($ops);
			exit;
		}
	}
	$page['text'] = wrap_template('install-user');
	return $page;
}

/**
 * ask for setting values, create folders
 *
 * @param void
 * @return mixed
 */
function wrap_install_settings() {
	wrap_install_zzform();

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		wrap_install_settings_write();
		wrap_install_settings_folders();
		wrap_config('write');
		$_SESSION['step'] = 4;
		return true;
	}
	$page['text'] = wrap_install_settings_page();
	return $page;
}

/**
 * show settings on a page
 *
 * @param string $module (optional)
 * @return string
 */
function wrap_install_settings_page($module = false) {
	$cfg = wrap_cfg_files('settings', $module);
	$data = [];
	$found = false;
	foreach ($cfg as $key => $line) {
		if (!empty($line['required']) OR !empty($line['install'])) $found = true;
		$line['id'] = str_replace('[', '%5B', $key);
		if (empty($line['type'])) $line['type'] = 'text';
		$data[] = $line + ['key' => $key, $line['type'] => 1];
	}
	if (!$found) return false;
	if ($module) $data['module'] = $module;
	return wrap_template('install-settings', $data);
}

/**
 * write posted settings
 *
 * @param void
 * @return bool
 */
function wrap_install_settings_write() {
	foreach ($_POST as $key => $value) {
		if (!$value) continue;
		$key = str_replace('%5B', '[', $key);
		wrap_setting_write($key, $value);
	}
	return true;
}

/**
 * create folders for CMS
 *
 * @param void
 * @return bool
 */
function wrap_install_settings_folders() {
	global $zz_setting;
	global $zz_conf;

	$configs = ['zz_setting', 'zz_conf'];
	foreach ($configs as $config) {
		foreach ($$config as $key => $value) {
			if (!str_ends_with($key, '_dir')
				AND !str_ends_with($key, '_folder')) continue;
			if (file_exists($value)) continue;
			wrap_mkdir($value);
		}
	}
	$folders = [
		'_inc/custom/zzbrick_forms', '_inc/custom/zzbrick_make', 
		'_inc/custom/zzbrick_page', '_inc/custom/zzbrick_request', 
		'_inc/custom/zzbrick_request_get', '_inc/custom/zzbrick_tables', 
		'_inc/custom/zzform', '_inc/custom/configuration', 'docs/data', 'docs/examples',
		'docs/screenshots', 'docs/templates', 'docs/todo'
	];
	foreach ($folders as $folder) {
		$folder = $zz_setting['cms_dir'].'/'.$folder;
		if (file_exists($folder)) continue;
		wrap_mkdir($folder);
	}
	return true;
}

/**
 * look for module specific install scripts in module folders
 *
 * @param void
 * @return mixed
 */
function wrap_install_modules() {
	if (empty($_SESSION['module_install'])) {
		$_SESSION['step'] = 5;
		return true;
	}
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		foreach ($_SESSION['module_install'] as $module) {
			$function = sprintf('mf_%s_install', $module);
			if (function_exists($function))
				$function();
		}
		$_SESSION['step'] = 5;
		return true;
	}
	foreach ($_SESSION['module_install'] as $module) {
		$data['modules'][] = ['module' => $module]; 
	}
	$page['text'] = wrap_template('install-modules', $data);
	return $page;
}

/**
 * ask for remote database credentials, write to .json script
 *
 * @param void
 * @return mixed
 */
function wrap_install_remote_db() {
	global $zz_setting;
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$all = true;
		if (empty($_POST['db_name'])) $all = false;
		if (empty($_POST['db_user'])) $all = false;
		if (empty($_POST['db_pwd'])) $all = false;
		if (empty($_POST['db_host'])) $all = false;
		if ($all) {
			$data = json_encode($_POST, JSON_PRETTY_PRINT);
			$filename = $zz_setting['custom'].'/zzwrap_sql/pwd.json';
			file_put_contents($filename, $data);
			return wrap_install_finish();
		}
	}
	$page['text'] = wrap_template('install-remote-db');
	return $page;
}

/**
 * finish install by writing db_name_local to database and return to internal area
 *
 * @param void
 * @return mixed
 */
function wrap_install_finish() {
	global $zz_setting;
	wrap_install_zzform();

	wrap_setting_write('zzwrap_install_date', date('Y-m-d H:i:s'));
	$success = wrap_setting_write('zzform_db_name_local', $_SESSION['db_name_local']);
	if ($success) {
		$_SESSION['step'] = 'finish';
		return wrap_redirect_change($zz_setting['login_entryurl']);
	}
}

/**
 * insert content into database table(s) depending on content of .cfg file
 *
 * @param string $table name of table
 * @return array IDs
 */
function wrap_install_cfg($table) {
	global $zz_setting;

	$ids = [];

	// read definitions
	$data = wrap_cfg_files('install-'.$table);
	$fields = !empty($data['_table_definition']['fields']) ? $data['_table_definition']['fields'] : [];
	$tables = [];
	foreach ($fields as $index => $field) {
		if (!strstr($field, '.')) continue;
		$field = explode('.', $field);
		$tables[$index] = $field[0];
		$fields[$index] = $field[1];
	}

	$removes = !empty($data['_table_definition']['remove']) ? $data['_table_definition']['remove'] : [];
	$prefixes = !empty($data['_table_definition']['prefix']) ? $data['_table_definition']['prefix'] : [];
	$no_prefixes_if_start = !empty($data['_table_definition']['no_prefix_if_begin']) ? $data['_table_definition']['no_prefix_if_begin'] : [];
	$replaces = !empty($data['_table_definition']['replace']) ? $data['_table_definition']['replace'] : [];
	$keys = !empty($data['_table_definition']['keys']) ? $data['_table_definition']['keys'] : [];
	$hierarchy = !empty($data['_table_definition']['hierarchy_field']) ? $data['_table_definition']['hierarchy_field'] : false;
	$hierarchy_source = !empty($data['_table_definition']['hierarchy_source']) ? $data['_table_definition']['hierarchy_source'] : false;
	unset($data['_table_definition']);

	$subkeys = []; // for use with subtables
	foreach ($tables as $my_table) {
		$subkeys[$my_table] = [];
	}

	// interpret lines
	foreach ($data as $identifier => $line) {
		$line['identifier'] = $identifier;
		$values = [];
		$values['action'] = 'insert';
		foreach ($line as $key => $value) {
			// change values
			if (array_key_exists($key, $replaces)) {
				// 1. replace part of the value
				$replace = explode(' ', $replaces[$key]);
				if (!empty($zz_setting[$replace[0]])) {
					if ($value === $replace[1]) $value = $replace[2];
				}
			}
			if (array_key_exists($key, $prefixes)) {
				// 2. prefix value
				$add_prefix = true;
				if (array_key_exists($key, $no_prefixes_if_start)) {
					if (str_starts_with($value, $no_prefixes_if_start[$key]))
						$add_prefix = false;
				}
				if ($add_prefix) {
					if (!empty($zz_setting[$prefixes[$key]]))
						$value = $zz_setting[$prefixes[$key]].$value;
					else
						$value = $prefixes[$key].$value;
				}
			}
			if (array_key_exists($key, $removes)) {
				// 3. remove part of the value
				$remove = explode(' ', $removes[$key]);
				$remove_key = array_shift($remove);
				$remove = implode(' ', $remove);
				if (!empty($zz_setting[$remove_key]))
					$value = str_replace($remove, '', $value);
			}
			
			// assign values
			if (is_array($value) AND (in_array($key, $fields) OR in_array($key.'_id', $fields))) {
				if (in_array($key, $fields)) {
					$index = array_search($key, $fields);
				} else {
					$index = array_search($key.'_id', $fields);
					$key .= '_id';
				}
				if (!array_key_exists($index, $tables)) {
					echo wrap_print($key);
					echo wrap_print($value);
					echo 'Error in table definition';
					exit;
				}
				foreach ($value as $subkey => $subval) {
					if (!in_array($subkey, $subkeys[$tables[$index]])) {
						$subkeys[$tables[$index]][] = $subkey;
					}
					$subindex = array_search($subkey, $subkeys[$tables[$index]]);
					$values['POST'][$tables[$index]][$subindex][$key] = $subval;
					if (!empty($keys[$tables[$index]])) {
						$values['POST'][$tables[$index]][$subindex][$keys[$tables[$index]]] = $subkey.' ';
					}
				}
			} elseif (in_array($key, $fields)) {
				$values['POST'][$key] = $value;
			} elseif (in_array($key.'_id', $fields)) {
				$values['POST'][$key.'_id'] = $value.' ';
				// is integer or integer cast as string?
				if (intval($value).'' === $value.'')
					$values['ids'][] = $key.'_id';
			} elseif (is_array($value)) {
				foreach ($value as $subkey => $subvalue)
					$values['POST']['parameters'][] = sprintf('%s[%s]=%s', $key, $subkey, $subvalue);
			} else {
				$values['POST']['parameters'][] = sprintf('%s=%s', $key, $value);
			}
		}
		// hierarchy?
		if ($hierarchy AND $hierarchy_source) {
			$hierarchy_value = explode('/', $line[$hierarchy_source]);
			array_pop($hierarchy_value);
			if ($hierarchy_value)
				$values['POST'][$hierarchy] = implode('/', $hierarchy_value);
		}
		if (!empty($values['POST']['parameters']))
			$values['POST']['parameters'] = implode('&', $values['POST']['parameters']);

		$ops = zzform_multi($table, $values);
		if (!$ops['id']) {
			echo wrap_print($ops);
			echo wrap_print($values);
			exit;
		}
		$ids[$ops['id']] = $identifier;
	}
	return $ids;
}
