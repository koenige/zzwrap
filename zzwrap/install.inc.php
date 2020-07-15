<?php 

/**
 * zzwrap
 * Installation of CMS
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2020 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


function wrap_install() {
	global $zz_setting;
	global $zz_page;
	if (!$zz_setting['local_access']) return;

	$zz_page['template'] = 'install-page';
	wrap_include_ext_libraries();
	$zz_setting['cache'] = false;
	
	if (empty($_SESSION)) session_start();
	$_SESSION['cms_install'] = true;
	if (empty($_SESSION['step'])) $_SESSION['step'] = 1;

	switch ($_SESSION['step']) {
		case 1: $page['text'] = wrap_install_dbname(); break;
		case 2: $page['text'] = wrap_install_user(); break;
		case 3: $page['text'] = wrap_install_remote_db(); break;
	}

	if ($page['text'] === true) {
		return brick_format('%%% redirect 303 '.$_SERVER['REQUEST_URI'].' %%%');
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
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (!empty($_POST['db_name_local'])) {
			$db = mysqli_select_db($zz_conf['db_connection'], wrap_db_escape($_POST['db_name_local']));
			if ($db) {
				$zz_conf['db_name'] = $_POST['db_name_local'];
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
			wrap_install_module('zzform');
			wrap_install_module('default');
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
	
	$dir = $zz_setting['modules_dir'].'/'.$module.'/docs/sql';
	$file = $dir.'/install.sql';
	if (!file_exists($file)) return false;
	$lines = file($file);
	$index = 0;
	$queries = [];
	foreach ($lines as $line) {
		if (substr($line, 0, 3) === '/**') continue;
		if (substr($line, 0, 2) === ' *') continue;
		if (substr($line, 0, 3) === ' */') continue;
		$line = trim($line);
		if (!$line) continue;
		$queries[$index][] = $line;
		if (substr($line, -1) === ';') $index++;
	}
	foreach ($queries as $index => $query) {
		$query = implode(' ', $query);
		$success = wrap_db_query($query);
		if ($success)
			zz_log_sql($query, 'Crew droid Robot 571');
	}
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

	$zz_setting['brick_default_tables'] = true;
	$zz_conf['user'] = 'Crew droid Robot 571';
	$zz_page['url']['full'] = [
		'scheme' => $zz_setting['protocol'],
		'host' => $zz_setting['hostname'],
		'path' => $zz_setting['request_uri']
	];
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
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		wrap_install_zzform();
		$values = [];
		$values['action'] = 'insert';
		$values['POST']['username'] = $_POST['username'];
		$values['POST']['login_rights'] = 'admin';
		$values['POST']['password'] = $_POST['password'];
		$values['POST']['password_change'] = 'no';
		$ops = zzform_multi('logins', $values);
		if ($ops['id']) {
			$_SESSION['step'] = 3;
			return true;
		}
	}
	$page['text'] = wrap_template('install-user');
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

	$values = [];
	$values['action'] = 'insert';
	$values['POST']['setting_key'] = 'zzform_db_name_local';
	$values['POST']['setting_value'] = $_SESSION['db_name_local'];
	$values['POST']['explanation'] = 'database name on local development server';
	$ops = zzform_multi('settings', $values);
	if ($ops['id']) {
		$_SESSION['step'] = 'finish';
		return brick_format('%%% redirect 303 '.$zz_setting['login_entryurl'].' %%%');
	}
}
