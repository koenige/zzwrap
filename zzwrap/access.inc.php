<?php 

/**
 * zzwrap
 * Access and authorization functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2012, 2018-2026 Gustaf Mossakowski
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
	static $config = [];
	if (!$config) $config = wrap_cfg_files('access');

	// no access rights function: allow everything	
	if (!function_exists('brick_access_rights')) {
		// super simple rights system: allow everything, but not preview
		$logged_in = wrap_session_value('logged_in');
		if (($area === 'preview' OR str_ends_with($area, '_preview')) AND !$logged_in) {
			if (wrap_setting('debug_access')) wrap_error(sprintf('Access debug [%s]: denied, no brick_access_rights(), preview area, not logged in', $area), E_USER_NOTICE);
			return false;
		}
		if (wrap_setting('debug_access')) wrap_error(sprintf('Access debug [%s]: granted, no brick_access_rights() function', $area), E_USER_NOTICE);
		return true;
	}
	
	// @todo global access rights for local users can be overwritten
	// if user has access to webpages table and can write bricks
	if (wrap_setting('access_global')) {
		if (wrap_setting('debug_access')) wrap_error(sprintf('Access debug [%s]: granted via access_global', $area), E_USER_NOTICE);
		return true;
	}
	
	// directly given access via session or setting?
	if (in_array($area, wrap_setting('no_access'))) {
		if (wrap_setting('debug_access')) wrap_error(sprintf('Access debug [%s]: denied via no_access setting', $area), E_USER_NOTICE);
		return false;
	}
	if (in_array($area, wrap_setting('access'))) {
		if (wrap_setting('debug_access')) wrap_error(sprintf('Access debug [%s]: granted via access setting', $area), E_USER_NOTICE);
		return true;
	}
	if (!empty($_SESSION['no_access']) AND in_array($area, $_SESSION['no_access'])) {
		if (wrap_setting('debug_access')) wrap_error(sprintf('Access debug [%s]: denied via session no_access', $area), E_USER_NOTICE);
		return false;
	}
	if (!empty($_SESSION['access']) AND in_array($area, $_SESSION['access'])) {
		if (wrap_setting('debug_access')) wrap_error(sprintf('Access debug [%s]: granted via session access', $area), E_USER_NOTICE);
		return true;
	}

	// check if access rights are met
	$access = wrap_access_groups($config, $area, $detail);
	if (!$access) {
		if (wrap_setting('debug_access')) {
			$groups = $config[$area]['group'] ?? '(none)';
			if (is_array($groups)) $groups = implode(', ', $groups);
			wrap_error(sprintf(
				'Access debug [%s]: denied by wrap_access_groups(), required group: %s, login_rights: %s',
				$area, $groups, wrap_session_value('login_rights') ?? '(none)'
			), E_USER_NOTICE);
		}
		return false;
	}
	
	// check if there are conditions if access is granted
	if ($conditions AND array_key_exists($area, $config)) {
		$condition = wrap_conditions($config[$area], $detail);
		if (!$condition) {
			if (wrap_setting('debug_access')) wrap_error(sprintf('Access debug [%s]: denied by condition check', $area), E_USER_NOTICE);
			return NULL;
		}
	}

	if (wrap_setting('debug_access')) wrap_error(sprintf('Access debug [%s]: granted', $area), E_USER_NOTICE);
	return $access;
}

/**
 * check if access rights are met via usergroups or config groups
 *
 * @param array $config access configuration
 * @param string $area
 * @param string $detail access detail string
 * @return bool true: access granted, false: access denied
 */
function wrap_access_groups($config, $area, $detail) {
	static $usergroups = [];

	// localhost and public cannot be overwritten in database
	// so check these first
	$group_rights = $config[$area]['group'] ?? [];
	if (!$group_rights) {
		$pos = strpos($area, '[');
		$area_short = $pos !== false ? substr($area, 0, $pos) : '';
		if ($area_short) $group_rights = $config[$area_short]['group'] ?? [];
	}
	if (!is_array($group_rights)) $group_rights = [$group_rights];
	if (in_array('public', $group_rights)) return true;
	if (in_array('localhost', $group_rights) AND wrap_http_localhost_ip()) return true;

	// read usergroup rights from database
	if (wrap_package('activities')
		AND !array_key_exists($area, $usergroups)
		AND wrap_setting('mod_activities_install_date')
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

	if (!empty($usergroups[$area])) {
		$detail = wrap_access_detail_key($detail, $config[$area]);
		foreach ($usergroups[$area] as $usergroup) {
			$access = brick_access_rights($usergroup, $detail);
			if ($access) return true;
		}
		return false;
	}

	$group_rights = array_diff($group_rights, ['localhost', 'public']);
	if (!$group_rights) return false;
	return brick_access_rights($group_rights);
}

/**
 * extract specific parameter key from detail string for access rights check
 *
 * if access_detail_key is configured, extracts just that key:value pair
 * from a multi-part detail string (e.g., "event_id:234+registration_id:456" -> "event_id:234")
 *
 * @param string $detail access detail string (may contain multiple key:value pairs joined by +)
 * @param array $area_config configuration for this access area
 * @return string filtered detail string or original if no filtering needed
 */
function wrap_access_detail_key($detail, $area_config) {
	if (!$detail) return $detail;
	if (!array_key_exists('access_detail_key', $area_config)) return $detail;
	if (!$key_to_find = $area_config['access_detail_key']) return $detail;
	if (strpos($detail, ':') === false) return $detail;

	// extract specific parameter key for access rights check
	$parts = explode('+', $detail);
	foreach ($parts as $part) {
		if (strpos($part, $key_to_find.':') === 0) {
			return $part;
		}
	}
	return $detail;
}

/**
 * quit with a 403 if access rights are not met
 *
 * @param string $area
 * @param string $detail (optional, e. g. event_id:234 or event:2022/test)
 * @param bool $conditions check conditions or not
 * @return bool
 */
function wrap_access_quit($area, $detail = '', $conditions = true) {
	$access = wrap_access($area, $detail, $conditions);
	if (!$access) {
		if (wrap_setting('debug_access'))
			wrap_error(sprintf(
				'Access debug [%s]: 403 quit, IP: %s, session ID: %s',
				$area,
				$_SERVER['REMOTE_ADDR'] ?? '(unknown)',
				session_id() ?: '(no session)'
			), E_USER_NOTICE);
		wrap_quit(403, wrap_text('You need `%s` access rights. (Login: %s)'
			, ['values' => [$area, wrap_username()]]
		));
	}
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
	static $data = [];

	$definitions = [
		'condition', 'condition_unless', 'condition_if_setting', 'condition_if_lib'
	];
	foreach ($definitions as $def) {
		if (empty($config[$def])) $config[$def] = [];
		elseif (!is_array($config[$def])) $config[$def] = [$config[$def]];
	}

	// check if library exists
	foreach ($config['condition_if_lib'] as $condition)
		if (!is_dir(wrap_setting('lib').'/'.$condition)) return false; // not applicable = 404

	if (!$detail) return true;
	if (!$config['condition']) return true;
	$module = $config['condition_queries_module'] ?? $config['package'];
	if (!$module) $module = 'custom';
	if (!empty($config['condition_query']))
		$key = sprintf('%s_%s_%s', $module, $config['condition_query'], $detail);
	else
		$key = sprintf('%s_%s', $module, $detail);
	// if there are two keys, just look at the first key here
	if ($pos = strpos($key, '+')) $key = substr($key, 0, $pos);
	
	// get the data
	if (!array_key_exists($key, $data)) {
		$keys = explode(':', $key);
		$sql = wrap_sql_query($keys[0]);
		if ($sql) {
			if (trim($sql) === '/* ignore */') return true;
			$sql = sprintf($sql, $keys[1]);
			$data[$key] = wrap_db_fetch($sql);
			$data[$key] = wrap_parameters($data[$key]);
		} else {
			wrap_error(sprintf('No query for %s found.', $keys[0]));
		}
	}

	foreach ($config['condition'] as $condition)
		if (empty($data[$key][$condition])) return false; // not applicable = 404
	
	foreach ($config['condition_unless'] as $condition)
		if (!empty($data[$key][$condition])) return false; // not applicable = 404

	foreach ($config['condition_if_setting'] as $condition)
		if (!wrap_setting($condition)) return false; // not applicable = 404

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
		if (!str_ends_with($key, '_parameters') AND $key !== 'parameters') continue;
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
 * @param mixed $parameters
 * @param array $details (optional)
 * @param bool $quit
 * @return bool
 */
function wrap_access_page($parameters, $details = [], $quit = true) {
	static $config = [];
	if (!$parameters) return true;
	if (is_string($parameters))
		parse_str($parameters, $parameters);
	if (empty($parameters['access'])) return true;
	// check later with placeholders?
	if (!$config) $config = wrap_cfg_files('access');
	// allow to have access restrictions for different no. of placeholders
	// here only the first restriction is considered
	if (is_array($parameters['access']))
		$parameters['access'] = reset($parameters['access']);
	if (!empty($config[$parameters['access']]['page_placeholder_check']) AND !$details) return true;
	return wrap_access_details($parameters['access'], $details, 'OR', $quit);
}

/**
 * check access rights with details and quit if no access
 *
 * @param string $access_key
 * @param array $details (optional)
 * @param string $operand (optional) OR = either one of the details needs to be true; AND: both need to be true
 * @param bool $quit
 * @return bool
 */
function wrap_access_details($access_key, $details = [], $operand = 'AND', $quit = true) {
	static $config = [];
	if (!$config) $config = wrap_cfg_files('access');

	$access = false;
	if ($details)
		foreach ($details as $index => $detail) {
			if (!$detail) continue; // do not check if nothing is defined
			// check condition only for first detail
			// do not go on if condition check was false (return = NULL)
			$access = wrap_access($access_key, $detail, false);
			if (!$index AND array_key_exists($access_key, $config)) {
				$condition = wrap_conditions($config[$access_key], $detail);
				if (!$condition) $access = NULL;
			}
			if (is_null($access)) break;
			if ($operand === 'OR' AND $access) break;
			if ($operand === 'AND' AND !$access) break;
		}
	else
		$access = wrap_access($access_key);

	if (!$access)
		if ($quit) wrap_quit(is_null($access) ? 404 : 403);
		else return false;
	return true;
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
	$secret_key = wrap_setting($key);
	$minutes_valid = wrap_setting($key.'_validity_in_minutes');
	if ($minutes_valid) {
		$now = time();
		$seconds = $minutes_valid * 60;
		$timeframe_start = floor($now/$seconds)*$seconds + $period*$seconds;
		$secret_key .= $timeframe_start;
	}
	$secret = $string.$secret_key;
	if (wrap_setting('character_set') !== 'utf-8')
		$secret = mb_convert_encoding($secret, 'UTF-8', wrap_setting('character_set'));
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
 * erlaubt Zugriff nur von berechtigten IP-Adressen, bricht andernfalls mit 403
 * Fehlercode ab
 *
 * Supports CIDR notation subnets (e.g., "192.168.1.0/24", "2001:db8::/32")
 * and wildcard patterns for IPv4 (e.g., "192.168.*.*")
 *
 * @param string $ip_list key in setting, that holds list of allowed IP addresses
 * @return bool true: access granted; exit function: access forbidden
 * @todo combine with ipfilter from zzbrick
 */
function wrap_restrict_ip_access($ip_list) {
	$ip_list = wrap_setting($ip_list);
	if ($ip_list === NULL) {
		wrap_error(wrap_text('List of allowed IPs not found in configuration (%s).',
			 ['values' => $ip_list]), E_USER_NOTICE);
		wrap_quit(403);
	}
	if (!is_array($ip_list)) $ip_list = [$ip_list];
	$remote_ip = wrap_http_remote_ip();
	if (!wrap_http_ip_in_list($remote_ip, $ip_list)) {
		wrap_error(wrap_text('Your IP address %s is not in the allowed range.',
			 ['values' => wrap_html_escape($remote_ip)]), E_USER_NOTICE);
		wrap_quit(403);
	}
	return true;
}
