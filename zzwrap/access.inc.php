<?php 

/**
 * zzwrap
 * Access and authorization functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2012, 2018-2023 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * check access for a certain area
 *
 * @param string $area
 * @param string $detail (optional, e. g. event_id:234 or event:2022/test)
 * @param bool $conditions check conditions or not
 * @return bool true: access granted
 */
function wrap_access($area, $detail = '', $conditions = true) {
	global $zz_conf;
	global $zz_setting;
	static $config;
	static $usergroups;
	if (empty($config)) $config = wrap_cfg_files('access');
	if (empty($usergroups)) $usergroups = [];
	$area_short = substr($area, 0, strpos($area, '['));

	// no access rights function: allow everything	
	if (!function_exists('brick_access_rights')) return true;
	
	// zzform multi?
	// @todo access rights for local users can be overwritten
	// if user has access to webpages table and can write bricks
	if (!empty($zz_conf['multi'])) return true;

	// read settings from database
	if (in_array('activities', $zz_setting['modules'])
		AND !array_key_exists($area, $usergroups)
		AND wrap_get_setting('mod_activities_install_date')
	) {
		$sql = 'SELECT usergroup_id, usergroup
			FROM usergroups
			LEFT JOIN access_usergroups USING (usergroup_id)
			LEFT JOIN access USING (access_id)
			WHERE access.access_key IN("%s")';
		$areas = [wrap_db_escape($area)];
		if (!empty($config[$area]['include_access'])) {
			if (!is_array($config[$area]['include_access']))
				$areas[] = $config[$area]['include_access'];
			else
				$areas = array_merge($areas, $config[$area]['include_access']);
		}
		$sql = sprintf($sql, implode('","', $areas));
		$usergroups[$area] = wrap_db_fetch($sql, 'usergroup_id', 'key/value');
	}
	
	// are there access rights? no: = no access!
	if (empty($usergroups[$area]) AND empty($config[$area]['group']) AND empty($config[$area_short]['group']))
		return false;

	// directly given access via session or setting?
	$keys = ['zz_setting', '_SESSION'];
	foreach ($keys as $key) {
		if (!empty($$key['no_access'])) {
			if (in_array($area, $$key['no_access'])) return false;
		}
		if (!empty($$key['access'])) {
			if (in_array($area, $$key['access'])) return true;
		}
	}

	// check if access rights are met
	if (!empty($usergroups[$area])) {
		foreach ($usergroups[$area] as $usergroup) {
			$access = brick_access_rights($usergroup, $detail);
			if ($access) break;
		}
	} else {
		$group_rights = $config[$area]['group'] ?? $config[$area_short]['group'];
		if ($group_rights === 'public') $access = true;
		else $access = brick_access_rights($group_rights);
	}
	if (!$access) return false;
	
	// check if there are conditions if access is granted
	if ($conditions AND array_key_exists($area, $config))
		$access = wrap_conditions($config[$area], $detail);
	
	return $access;
}

/**
 * check condition if access is granted
 *
 * @param array $config
 * @param string $detail
 * @return bool
 */
function wrap_conditions($config, $detail) {
	static $data;
	if (empty($data)) $data = [];
	if (!$detail) return true;
	if (empty($config['condition'])) return true;
	$module = $config['condition_queries_module'] ?? $config['module'];
	if (!$module) $module = 'custom';
	if (!empty($config['condition_query']))
		$key = sprintf('%s_%s_%s', $module, $config['condition_query'], $detail);
	else
		$key = sprintf('%s_%s', $module, $detail);
	
	// get the data
	if (!array_key_exists($key, $data)) {
		$keys = explode(':', $key);
		$sql = wrap_sql_query($keys[0]);
		if ($sql) {
			$sql = sprintf($sql, $keys[1]);
			$data[$key] = wrap_db_fetch($sql);
			$data[$key] = wrap_parameters($data[$key]);
		} else {
			wrap_error(sprintf('No query for %s found.', $keys[0]));
		}
	}
	if (!is_array($config['condition']))
		$config['condition'] = [$config['condition']];
	foreach ($config['condition'] as $condition)
		if (empty($data[$key][$condition])) return NULL; // not applicable = 404

	if (empty($config['condition_unless']))
		$config['condition_unless'] = [];
	elseif (!is_array($config['condition_unless']))
		$config['condition_unless'] = [$config['condition_unless']];
	foreach ($config['condition_unless'] as $condition)
		if (!empty($data[$key][$condition])) return NULL; // not applicable = 404

	return true;
}

/**
 * merge all _parameters fields into $data
 *
 * @param array $fields
 * @return array
 */
function wrap_parameters($fields) {
	foreach ($fields as $key => $value) {
		if (!str_ends_with($key, '_parameters')) continue;
		if (!$value) continue;
		parse_str($value, $parameters);
		$fields = array_merge($fields, $parameters);
	}
	return $fields;
}

/**
 * check access rights on page level
 * if access=access_key is set, check if rights suffice
 *
 * @param array $page
 * @param array $details (optional)
 * @param bool $quit
 * @return bool
 */
function wrap_access_page($page, $details = [], $quit = true) {
	static $config;
	if (empty($page['parameters'])) return true;
	if (is_string($page['parameters']))
		parse_str($page['parameters'], $parameters);
	if (empty($parameters['access'])) return true;
	// check later with placeholders?
	if (empty($config)) $config = wrap_cfg_files('access');
	if (!empty($config[$parameters['access']]['page_placeholder_check']) AND !$details) return true;
	$access = false;
	if ($details)
		foreach ($details as $index => $detail) {
			if (!$detail) continue; // do not check if nothing is defined
			// check condition only for first detail
			// do not go on if condition check was false (return = NULL)
			$access = wrap_access($parameters['access'], $detail, ($index ? false : true));
			if ($access OR is_null($access)) break;
		}
	else
		$access = wrap_access($parameters['access']);

	if (!$access)
		if ($quit) wrap_quit(is_null($access) ? 404 : 403);
		else return false;
	return true;
}

/**
 * checks or sets rights
 *
 * @param string $right key:
 *		'preview' for preview of not yet published content
 *		'access' for access rights
 * @param string $mode (optional): get, set
 * @param string $value (optional): in combination with set, sets value to right
 */
function wrap_rights($right, $mode = 'get', $value = NULL) {
	static $rights;
	switch ($mode) {
	case 'get':
		if (isset($rights[$right])) return $rights[$right];
		else return NULL;
	case 'set':
		if ($value === NULL) return false;
		$rights[$right] = $value;
		return $value;
	}
	return false;
}

/**
 * checks hash against string
 *
 * @param string $string
 * @param string $hash hash to check against
 * @param string $error_msg (optional, defaults to 'Incorrect credentials')
 * @param string $key name of key (optional, defaults to 'secret_key')
 * @return bool
 */
function wrap_check_hash($string, $hash, $error_msg = '', $key = 'secret_key') {
	// check timeframe: current, previous, next
	if (wrap_set_hash($string, $key) == $hash) return true;
	if (wrap_set_hash($string, $key, -1) == $hash) return true;
	if (wrap_set_hash($string, $key, +1) == $hash) return true;

	if ($error_msg) {
		wrap_error($error_msg, E_USER_NOTICE);
		wrap_quit(403);
	}
	return false;
}

/**
 * creates hash with secret key
 *
 * - needs a setting 'secret_key', i. e. a key that is shared with the foreign
 * server; 
 * - optional setting 'secret_key_validity_in_minutes' for a timeframe during
 * which the key is valid. Example: = 60, current time is 14:23: timestamp set
 * to 14:00, valid from 13:00-15:59; i. e. min. 60 minutes, max. 120 min.
 * depending on actual time
 * hashes will be made in UTF 8, if it's not UTF 8 here, we assume it's Latin1
 * if you use some other encoding, make sure, your secret key is encoded in ASCII
 * @param string $string
 * @param string $key name of key (optional, defaults to 'secret_key')
 * @param string $period (optional) 0: current, -1: previous, 1: next
 *		this parameter is internal, it should be used only from wrap_check_hash
 * @return string hash
 * @see wrap_check_hash()
 * @todo support other character encodings as utf-8 and iso-8859-1
 */
function wrap_set_hash($string, $key = 'secret_key', $period = 0) {
	$secret_key = wrap_get_setting($key);
	$minutes_valid = wrap_get_setting($key.'_validity_in_minutes');
	if ($minutes_valid) {
		$now = time();
		$seconds = $minutes_valid * 60;
		$timeframe_start = floor($now/$seconds)*$seconds + $period*$seconds;
		$secret_key .= $timeframe_start;
	}
	$secret = $string.$secret_key;
	if (wrap_get_setting('character_set') !== 'utf-8')
		$secret = mb_convert_encoding($secret, 'UTF-8', wrap_get_setting('character_set'));
	$hash = sha1($secret);
	$hash = wrap_base_convert($hash, 16, 62);
	return $hash;
}

/**
 * converts a number from one base to another (2 – 62)
 *
 * this function treats values out of scope differently than base_convert()
 * source of this function: https://stackoverflow.com/questions/1938029/php-how-to-base-convert-up-to-base-62
 * @param string $input
 * @param int $frombase
 * @param int $tobase
 * @return string
 */
function wrap_base_convert($input, $frombase, $tobase) {
	if ($frombase < 2 OR $tobase < 2)
		wrap_error('At least one base is below 2, this is not allowed.', E_USER_ERROR);
	elseif ($frombase > 62 OR $tobase > 62)
		wrap_error('At least one base is above 62, this is not allowed.', E_USER_ERROR);

	$all_chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$chars = substr($all_chars, 0, $frombase);
	$tostring = substr($all_chars, 0, $tobase);
	$length = strlen($input);
    $output = '';
    for ($i = 0; $i < $length; $i++) {
    	$number[$i] = strpos($chars, $input[$i]);
	}
	do {
		$divide = 0;
		$newlen = 0;
		for ($i = 0; $i < $length; $i++) {
			$divide = $divide * $frombase + $number[$i];
			if ($divide >= $tobase) {
				$number[$newlen++] = (int)($divide / $tobase);
				$divide = $divide % $tobase;
			} elseif ($newlen > 0) {
				$number[$newlen++] = 0;
			}
		}
		$length = $newlen;
		$output = $tostring[$divide] . $output;
	} while ($newlen != 0);
	return $output;
}

/**
 * Test whether URL contains a correct secret key to allow page previews
 * 
 * @param string $secret_key shared secret key
 * @param string $_GET['tle'] timestamp, begin of legitimate timeframe
 * @param string $_GET['tld'] timestamp, end of legitimate timeframe
 * @param string $_GET['tlh'] hash
 * @return bool $wrap_page_preview true|false i. e. true means show page, false don't
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @todo replace with wrap_check_hash()
 */
function wrap_test_secret_key($secret_key) {
	$wrap_page_preview = false;
	if (empty($_GET['tle'])) return false;
	if (empty($_GET['tld'])) return false;
	if (empty($_GET['tlh'])) return false;
	if (time() > $_GET['tle'] && time() < $_GET['tld'] && 
		$_GET['tlh'] == md5($_GET['tle'].'&'.$_GET['tld'].'&'.$secret_key)) {
		wrap_session_start();
		$_SESSION['wrap_page_preview'] = true;
		$wrap_page_preview = true;
	}
	return $wrap_page_preview;
}

/**
 * erlaubt Zugriff nur von berechtigten IP-Adressen, bricht andernfalls mit 403
 * Fehlercode ab
 *
 * @param string $ip_list Schlüssel in $zz_setting, der Array mit den erlaubten
 *		IP-Adressen enthält
 * @return bool true: access granted; exit function: access forbidden
 * @todo make compatible to IPv6
 * @todo combine with ipfilter from zzbrick
 */
function wrap_restrict_ip_access($ip_list) {
	$ip_list = wrap_get_setting($ip_list);
	if ($ip_list === NULL) {
		wrap_error(sprintf(wrap_text('List of allowed IPs not found in configuration (%s).'),
			$ip_list), E_USER_NOTICE);
		wrap_quit(403);
	}
	if (!is_array($ip_list)) $ip_list = [$ip_list];
	if (!in_array($_SERVER['REMOTE_ADDR'], $ip_list)) {
		wrap_error(sprintf(wrap_text('Your IP address %s is not in the allowed range.'),
			wrap_html_escape($_SERVER['REMOTE_ADDR'])), E_USER_NOTICE);
		wrap_quit(403);
	}
	return true;
}
