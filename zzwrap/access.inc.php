<?php 

/**
 * zzwrap
 * Access and authorization functions
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2012, 2018-2019 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


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
	global $zz_conf;
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
	global $zz_conf;

	$secret_key = wrap_get_setting($key);
	$minutes_valid = wrap_get_setting($key.'_validity_in_minutes');
	if ($minutes_valid) {
		$now = time();
		$seconds = $minutes_valid * 60;
		$timeframe_start = floor($now/$seconds)*$seconds + $period*$seconds;
		$secret_key .= $timeframe_start;
	}
	$secret = $string.$secret_key;
	if ($zz_conf['character_set'] !== 'utf-8') $secret = utf8_encode($secret);
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
    	$number[$i] = strpos($chars, $input{$i});
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
		$output = $tostring{$divide} . $output;
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
