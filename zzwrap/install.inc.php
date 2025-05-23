<?php 

/**
 * zzwrap
 * Installation of CMS
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2020-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function wrap_install() {
	if (!wrap_setting('local_access')) return;
	wrap_setting('log_username', 'Crew droid Robot 571');
	wrap_setting('install', true);

	wrap_setting('template', 'install-page');
	wrap_lib();
	wrap_setting('cache', false);
	
	wrap_session_start();
	$_SESSION['cms_install'] = true;
	if (empty($_SESSION['step'])) $_SESSION['step'] = 1;
	elseif (empty($_SESSION['db_name_local'])) $_SESSION['step'] = 1;
	else {
		$db = mysqli_select_db(wrap_db_connection(), wrap_db_escape($_SESSION['db_name_local']));
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
		wrap_redirect_change();
	} elseif ($page['text'] === false) {
		return false;
	}
	
	wrap_set_encoding('utf-8');
	$page = wrap_page_defaults($page);
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
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (!empty($_POST['db_name_local'])) {
			$db = mysqli_select_db(wrap_db_connection(), wrap_db_escape($_POST['db_name_local']));
			if ($db) {
				wrap_setting('db_name', $_POST['db_name_local']);
				$_SESSION['step'] = 2;
				$_SESSION['db_name_local'] = $_POST['db_name_local'];
				return false;
			}
			$sql = sprintf('CREATE DATABASE `%s`', wrap_db_escape($_POST['db_name_local']));
			wrap_db_query($sql);
			$db = mysqli_select_db(wrap_db_connection(), wrap_db_escape($_POST['db_name_local']));
			if (!$db) $out['error'] = sprintf(
				'Unable to create database %s. Please check the database error log.'
				, wrap_html_escape($_POST['db_name_local'])
			);
			$_SESSION['db_name_local'] = $_POST['db_name_local'];
			wrap_sql_ignores();
			wrap_install_module('default');
			wrap_install_module('zzform');
			foreach (wrap_setting('modules') as $module) {
				if (in_array($module, ['zzform', 'default'])) continue;
				wrap_install_module($module);
			}
			wrap_install_alter_table();
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
	wrap_include('database', 'zzform');

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
		wrap_install_queries($queries_per_table, 'create');
	}
	wrap_setting_write('mod_'.$module.'_install_date', date('Y-m-d H:i:s'));
	return true;
}

/**
 * execute a list of queries, but check if they were alread logged
 *
 * @param array $queries
 * @param string $scope
 * @return bool
 */
function wrap_install_queries($queries, $scope = 'create') {
	$logging_table = wrap_database_table_check(wrap_sql_table('zzform_logging'), true);

	foreach ($queries as $query) {
		// install already in logging table?
		if ($logging_table) {
			$sql = 'SELECT log_id FROM /*_TABLE zzform_logging _*/ WHERE query = "%s"';
			$sql = sprintf($sql, wrap_db_escape($query));
			$record = wrap_db_fetch($sql);
			if ($record) continue;
		}
		if ($scope === 'create' AND str_starts_with($query, 'ALTER')) {
			// do ALTER TABLE etc. later
			wrap_install_alter_table($query);
			continue;
		}
		$success = wrap_db_query($query);
		if ($success)
			zz_db_log($query, 'Crew droid Robot 571');
	}
	return true;
}

/**
 * save and execute ALTER TABLE queries for later; tables need to be created first
 *
 * @param string $query (optional)
 * @return bool
 */
function wrap_install_alter_table($query = '') {
	static $queries = [];
	if ($query) {
		$queries[] = $query;
		return false;
	}
	wrap_install_queries($queries, 'all');
	return true;
}

/**
 * prepare for zzform usage
 *
 * @param void
 * @return void
 */
function wrap_install_zzform() {
	global $zz_page;

	$zz_page['url']['full'] = [
		'scheme' => wrap_setting('protocol'),
		'host' => wrap_setting('hostname'),
		'path' => wrap_setting('request_uri')
	];
	if (!empty($_SESSION['db_name_local']))
		$db = mysqli_select_db(wrap_db_connection(), $_SESSION['db_name_local']);
	return;
}

/**
 * ask for username and password and create a user in logins table
 *
 * @param void
 * @return mixed
 */
function wrap_install_user() {
	wrap_include('_functions', 'zzform');

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		wrap_install_zzform();
		$line = [
			'username' => $_POST['username'],
			'password' => $_POST['password'],
			'password_change' => 'no'
		];
		if (!wrap_setting('install_without_login_rights'))
			$line['login_rights'] = 'admin';
		$login_id = zzform_insert('logins', $line);
		if (!$login_id)
			wrap_quit(503, wrap_text('The main user could not be created.'));

		$_SESSION['step'] = 3;
		return true;
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
		wrap_config_write();
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
	$cfg = wrap_cfg_files('settings', ['package' => $module]);
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
	$cfg = wrap_cfg_files('settings');
	foreach ($cfg as $key => $definitions)
		if (empty($definitions['install_folder'])) continue;
		else wrap_mkdir(wrap_setting($key));

	$folders = [
		'_inc/custom/zzbrick_forms', '_inc/custom/zzbrick_make', 
		'_inc/custom/zzbrick_page', '_inc/custom/zzbrick_request', 
		'_inc/custom/zzbrick_request_get', '_inc/custom/zzbrick_tables', 
		'_inc/custom/zzform', '_inc/custom/configuration', 'docs/data', 'docs/examples',
		'docs/screenshots', 'docs/templates', 'docs/todo'
	];
	foreach ($folders as $folder) {
		$folder = wrap_setting('cms_dir').'/'.$folder;
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
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$all = true;
		if (empty($_POST['db_name'])) $all = false;
		if (empty($_POST['db_user'])) $all = false;
		if (empty($_POST['db_pwd'])) $all = false;
		if (empty($_POST['db_host'])) $all = false;
		if ($all) {
			$data = json_encode($_POST, JSON_PRETTY_PRINT);
			wrap_mkdir(wrap_setting('config_dir'));
			$filename = wrap_setting('config_dir').'/pwd.json';
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
	wrap_install_zzform();

	wrap_setting_write('zzwrap_install_date', date('Y-m-d H:i:s'));
	$success = wrap_setting_write('db_name_local', $_SESSION['db_name_local']);
	if ($success) {
		$_SESSION['step'] = 'finish';
		wrap_redirect_change(wrap_domain_path('login_entry'));
	}
}

/**
 * insert content into database table(s) depending on content of .cfg file
 *
 * @param string $table name of table
 * @return array IDs
 */
function wrap_install_cfg($table) {
	$ids = [];

	// read definitions
	$data = wrap_cfg_files('install-'.$table);
	$fields = $data['_table_definition']['fields'] ?? [];
	$tables = [];
	foreach ($fields as $index => $field) {
		if (!strstr($field, '.')) continue;
		$field = explode('.', $field);
		$tables[$index] = $field[0];
		$fields[$index] = $field[1];
	}

	$removes = $data['_table_definition']['remove'] ?? [];
	$prefixes = $data['_table_definition']['prefix'] ?? [];
	$no_prefixes_if_start = $data['_table_definition']['no_prefix_if_begin'] ?? [];
	$replaces = $data['_table_definition']['replace'] ?? [];
	$keys = !$data['_table_definition']['keys'] ?? [];
	$hierarchy = $data['_table_definition']['hierarchy_field'] ?? false;
	$hierarchy_source = $data['_table_definition']['hierarchy_source'] ?? false;
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
				if (wrap_setting($replace[0])) {
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
					if (wrap_setting($prefixes[$key]))
						$value = wrap_setting($prefixes[$key]).$value;
					else
						$value = $prefixes[$key].$value;
				}
			}
			if (array_key_exists($key, $removes)) {
				// 3. remove part of the value
				$remove = explode(' ', $removes[$key]);
				$remove_key = array_shift($remove);
				$remove = implode(' ', $remove);
				if (wrap_setting($remove_key))
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
