<?php 

/**
 * zzwrap
 * Error handling
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2019 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * error handling: log errors, mail errors, exits script if critical error
 *
 * @param mixed $msg error message (arrays will be JSON-encoded)
 * @param int $error_type E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE
 * @param array $settings (optional internal settings)
 *		'logfile': extra text for logfile only, 'no_return': does not return but
 *		exit, 'mail_no_request_uri', 'mail_no_ip', 'mail_no_user_agent',
 *		'subject', bool 'log_post_data', bool 'collect_start', bool 'collect_end'
 * @global array $zz_conf cofiguration settings
 *		'error_mail_to', 'error_mail_from', 'error_handling', 'error_log',
 *		'log_errors', 'log_errors_max_len', 'debug', 'error_mail_level',
 *		'project', 'character_set'
 * @global array $zz_page
 */
function wrap_error($msg, $error_type = E_USER_NOTICE, $settings = []) {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;
	static $post_errors_logged;
	static $collect;
	static $collect_messages;
	static $collect_error_type;

	if (!empty($settings['collect_start'])) {
		$collect = true;
		$collect_messages = [];
	}
	if ($collect AND $msg) {
		// Split message per sentence to avoid redundant messages
		$msg .= ' ';
		$msg = explode('. ', $msg);
		foreach ($msg as $mymsg) {
			$mymsg = trim($mymsg);
			if (!$mymsg) continue;
			$collect_messages[$mymsg] = $mymsg.'. ';
		}
		$msg = false;
		if (!empty($collect_error_type)) {
			if ($collect_error_type < $error_type) {
				$collect_error_type = $error_type;
			}
		} else {
			$collect_error_type = $error_type;
		}
		$collect_error_type = $error_type < $collect_error_type ? $error_type : $collect_error_type;
	}
	if (!empty($settings['collect_end'])) {
		$collect = false;
		$msg = implode('', $collect_messages);
		$collect_messages = NULL;
		if (!empty($collect_error_type)) {
			$error_type = $collect_error_type;
		}
	}
	if (!$msg) return false;

	$return = false;
	switch ($error_type) {
	case E_USER_ERROR: // critical error: stop!
		if (!empty($zz_conf['exit_503'])) $settings['no_return'] = true;
		$level = 'error';
		if (empty($settings['no_return'])) 
			$return = 'exit'; // get out of this function immediately
		$zz_conf['exit_503'] = true;
		break;
	default:
	case E_USER_WARNING: // acceptable error, go on
		$level = 'warning';
		break;
	case E_USER_NOTICE: // unimportant error only show in debug mode
		$level = 'notice';
		break;
	}

	$log_encoding = $zz_conf['character_set'];
	// PHP does not support all encodings
	if (in_array($log_encoding, array_keys($zz_conf['translate_log_encodings'])))
		$log_encoding = $zz_conf['translate_log_encodings'][$log_encoding];

	if (is_array($msg)) $msg = 'JSON '.json_encode($msg);

	// Log prefix?
	if (!empty($zz_setting['error_prefix'])) {
		$msg = $zz_setting['error_prefix'].' '.$msg;
	}
	
	// Log output
	$log_output = $msg;
	$log_output = str_replace('<br>', "\n\n", $log_output);
	$log_output = str_replace('<br class="nonewline_in_mail">', "; ", $log_output);
	$log_output = strip_tags($log_output);
	$log_output = trim(html_entity_decode($log_output, ENT_QUOTES, $log_encoding));

	$user = !empty($_SESSION['username']) ? $_SESSION['username'] : '';
	if (!$user AND !empty($zz_conf['user'])) $user = $zz_conf['user'];

	if (!isset($settings['log_post_data'])) $settings['log_post_data'] = true;
	if (empty($_POST)) $settings['log_post_data'] = false;
	elseif (!$zz_conf['error_log_post']) $settings['log_post_data'] = false;
	elseif ($post_errors_logged) $settings['log_post_data'] = false;

	// reformat log output
	if (!empty($zz_conf['error_log'][$level]) AND $zz_conf['log_errors']) {
		$error_line = '['.date('d-M-Y H:i:s').'] zzwrap '.ucfirst($level).': '
			.(!empty($settings['logfile']) ? $settings['logfile'].' ' : '')
			.preg_replace("/\s+/", " ", $log_output);
		$error_line = substr($error_line, 0, $zz_conf['log_errors_max_len'] 
			- (strlen($user)+4)).' ['.$user."]\n";
		error_log($error_line, 3, $zz_conf['error_log'][$level]);
		if ($settings['log_post_data']) {
			$error_line = '['.date('d-M-Y H:i:s').'] zzwrap Notice: POST';
			if (function_exists('json_encode')) {
				$error_line .= '[json] '.json_encode($_POST);
			} else {
				$error_line .= ' '.serialize($_POST);
			}
			$error_line = substr($error_line, 0, $zz_conf['log_errors_max_len'] 
				- (strlen($user)+4)).' ['.$user."]\n";
			error_log($error_line, 3, $zz_conf['error_log'][$level]);
			// Log POST output only once per request
			$post_errors_logged = true;
		}
	}
		
	if (!empty($zz_conf['debug']))
		$zz_conf['error_handling'] = 'output';
	if (empty($zz_conf['error_handling']))
		$zz_conf['error_handling'] = false;

	if (!is_array($zz_conf['error_mail_level'])) {
		if ($zz_conf['error_mail_level'] === 'error') 
			$zz_conf['error_mail_level'] = ['error'];
		elseif ($zz_conf['error_mail_level'] === 'warning') 
			$zz_conf['error_mail_level'] = ['error', 'warning'];
		elseif ($zz_conf['error_mail_level'] === 'notice') 
			$zz_conf['error_mail_level'] = ['error', 'warning', 'notice'];
		else
			$zz_conf['error_mail_level'] = [];
	}
	switch ($zz_conf['error_handling']) {
	case 'mail_summary':
		if (!in_array($level, $zz_conf['error_mail_level'])) break;
		$zz_setting['mail_summary'][$level][] = $msg;
		break;
	case 'mail':
		if (!in_array($level, $zz_conf['error_mail_level'])) break;
		if (empty($zz_conf['error_mail_to'])) break;
		$msg = html_entity_decode($msg, ENT_QUOTES, $log_encoding);
		// add some technical information to mail
		$foot = false;
		if (empty($settings['mail_no_request_uri']))
			$foot .= "\nURL: ".$zz_setting['host_base'].$zz_setting['request_uri'];
		if (empty($settings['mail_no_ip']))
			$foot .= "\nIP: ".$zz_setting['remote_ip'];
		if (empty($settings['mail_no_user_agent']))
			$foot .= "\nBrowser: ".(!empty($_SERVER['HTTP_USER_AGENT']) 
				? $_SERVER['HTTP_USER_AGENT'] : wrap_text('unknown'));	
		// add user name to mail message if there is one
		if ($user) $foot .= "\n".wrap_text('User').': '.$user;
		if ($foot) $msg .= "\n\n-- ".$foot;

		$mail['to'] = $zz_conf['error_mail_to'];
		$mail['message'] = $msg;
		if (!empty($zz_conf['error_mail_parameters']))
			$mail['parameters'] = $zz_conf['error_mail_parameters']; 
		$mail['subject'] = '';
		if (empty($zz_conf['mail_subject_prefix']))
			$mail['subject'] = '['.$zz_conf['project'].'] ';
		$mail['subject'] .= (function_exists('wrap_text') ? wrap_text('Error on website') : 'Error on website')
			.(!empty($settings['subject']) ? ' '.$settings['subject'] : '');
		$mail['headers']['X-Originating-URL'] = $zz_setting['host_base'].$zz_setting['request_uri'];
		$mail['headers']['X-Originating-Datetime'] = date('Y-m-d H:i:s');
		wrap_mail($mail);
		break;
	case 'output':
		if (empty($zz_page['error_msg'])) $zz_page['error_msg'] = '';
		$zz_page['error_msg'] .= '<p class="error">'
			.str_replace("\n", "<br>", wrap_html_escape($msg)).'</p>';
		break;
	default:
		break;
	}

	if ($return === 'exit') {
		$page['status'] = 503;
		wrap_errorpage($page, $zz_page, false);
		exit;
	}
}

/**
 * sends a large mail instead of several one liners if errors occured
 *
 * @global array $zz_conf
 *		string 'error_handling' will be reset to 'mail'
 * @global array $zz_setting
 *		array 'mail_summary' contains all error messages, indexed by level and
 *		numerical; will be unset after content is sent
 *		string 'start_process' (optional, time that process was started)
 * @return bool = mail was sent (true), not sent (false)
 * @see wrap_error()
 */
function wrap_error_summary() {
	global $zz_conf;
	global $zz_setting;
	if ($zz_conf['error_handling'] !== 'mail_summary') return false;
	$zz_conf['error_handling'] = 'mail';
	if (empty($zz_setting['mail_summary'])) return false;
	
	// no need to log these errors again
	$log_errors = $zz_conf['log_errors'];
	$zz_conf['log_errors'] = false;
	
	foreach ($zz_setting['mail_summary'] AS $error_level => $errors) {
		$msg = implode("\n\n", $errors);
		if (!empty($zz_setting['start_process']) AND $error_level === 'warning') {
			$msg = $zz_setting['start_process']."\n\n".$msg;
			unset($zz_setting['start_process']);
		}
		wrap_error($msg, $error_level);
	}
	unset($zz_setting['mail_summary']);
	$zz_conf['log_errors'] = $log_errors;
	return true;
}

/**
 * outputs an error page
 * checks which error it is, set page elements, output HTTP header, HTML, log
 *
 * @param array $page
 * @param array $zz_page
 * @param bool $log_errors whether errors shall be logged or not
 * @global array $zz_setting
 * @global array $zz_conf
 */ 
function wrap_errorpage($page, $zz_page, $log_errors = true) {
	global $zz_setting;	
	global $zz_conf;
	global $zz_page;

	wrap_include_ext_libraries();

	// -- 1. check what kind of error page it is
	// if wanted, check if mod_rewrite works
	if (!empty($zz_setting['mod_rewrite_error']) 
		AND $_SERVER['SCRIPT_NAME'] != '/_scripts/main.php') {
		if (preg_match('/[a-zA-Z0-9]/', substr($_SERVER['REQUEST_URI'], 1, 1))) {
			wrap_error('mod_rewrite does not work as expected: '
				.$_SERVER['REQUEST_URI'].' ('.$_SERVER['SCRIPT_NAME'].')', E_USER_NOTICE);
			$_GET['code'] = 503;
		}
	}
	
	if (!empty($_GET['code'])) {
		// get 'code' if main.php is directly accessed as an error page
		$page['status'] = $_GET['code'];
	} elseif (empty($page['status'])) {
		// default error code
		$page['status'] = 404;
	}
	if ($page['status'] == 404 AND wrap_substr($_SERVER['REQUEST_URI'], 'http://')) {
		// probably badly designed robot, away with it
		$page['status'] = 400;
	}
	$status = wrap_http_status_list($page['status']);
	if (!$status) {
		wrap_error(sprintf('Status %s is not a valid HTTP status code.', $page['status']), E_USER_NOTICE);
		$page['status'] = 404;
		$status = wrap_http_status_list($page['status']);
	}
	
	// some codes get a link to the homepage
	$extra_description_codes = [404];
	
	// -- 2. set page elements
	
	if (empty($page['lang'])) $page['lang'] = $zz_conf['language'];
	$page['last_update'] = false;
	$page['breadcrumbs'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'
		.$zz_conf['project'].'</a></strong> '.$zz_page['breadcrumbs_separator'].' '
		.wrap_text($status['text']); 
	$page['pagetitle'] = strip_tags($page['status'].' '.wrap_text($status['text'])
		.' ('.$zz_conf['project'].')'); 
	$page['h1'] = wrap_text($status['text']);
	$page['error_description'] = sprintf(wrap_text($status['description']), 
		$_SERVER['REQUEST_METHOD']);
	if (in_array($page['status'], $extra_description_codes)) {
		$page['error_explanation'] = ' '.sprintf(wrap_text('Please try to find the '
			.'content you were looking for from our <a href="%s">main page</a>.'),
			$zz_setting['homepage_url']);
	} else {
		$page['error_explanation'] = '';
	}
	if (!empty($zz_page['error_msg'])) {
		$page['error_explanation'] = $zz_page['error_msg'].' '.$page['error_explanation'];
		$zz_page['error_msg'] = '';
	}

	// get own or default http-error template
	if (empty($page['text'])) {
		$page['text'] = wrap_template('http-error', [], 'error');
	} elseif (is_array($page['text']) AND empty($page['text']['text'])) {
		$page['text']['text'] = wrap_template('http-error', [], 'error');
	}

	if (function_exists('wrap_htmlout_menu') AND $zz_conf['db_connection']) { 
		// get menus, if function and database connection exist
		$page = wrap_get_menu($page);
	}
	
	// error pages have no last update
	$page[wrap_sql('lastupdate')] = false;
	if (!empty($zz_page['db'][wrap_sql('lastupdate')])) {
		$zz_page['db'][wrap_sql('lastupdate')] = false;
	}
	
	// -- 3. output HTTP header
	header($_SERVER['SERVER_PROTOCOL'].' '.$status['code'].' '.$status['text']);
	if ($page['status'] == 405) {
		header('Allow: '.implode(',', $zz_setting['http']['allowed']));
	}
	
	// -- 4. error logging

	if ($log_errors) wrap_errorpage_log($page['status'], $page);

	// -- 5. output page
	
	if ($zz_setting['brick_page_templates'] === true) {
		wrap_htmlout_page($page);
	} else {
		if (!empty($zz_conf['character_set']))
			header('Content-Type: text/html; charset='.$zz_conf['character_set']);
		$lines = explode("\n", $page['text']);
		foreach ($lines as $index => $line) {
			if (substr($line, 0, 1) === '#') unset($lines[$index]);
		}
		$page['text'] = implode("\n", $lines);
		$page['text'] = str_replace('%%% page h1 %%%', $page['h1'], $page['text']);
		$page['text'] = str_replace('%%% page code %%%', $page['status'], $page['text']);
		$page['text'] = str_replace('%%% page error_description %%%', $page['error_description'], $page['text']);
		$page['text'] = str_replace('%%% page error_explanation %%%', $page['error_explanation'], $page['text']);
		$page['text'] = str_replace('%%% page error_explanation "<p>%s</p>" %%%', '<p>'.$page['error_explanation'].'</p>', $page['text']);

		include $zz_page['head'];
		echo $page['text'];
		include $zz_page['foot'];
	}
	
	exit;
}

/**
 * Logs errors if an error page is shown
 *
 * @param int $status Errorcode
 * @param array $page
 *		'title', 'error_description', 'error_explanation'
 * @return bool true if something was logged, false if not
 */
function wrap_errorpage_log($status, $page) {
	global $zz_setting;
	global $zz_conf;
	global $zz_page;

	if (in_array($status, [401, 403, 404, 410, 503])) {
		$ignore = wrap_errorpage_ignore($status);
		if ($ignore) return false;
	}
	
	$log_encoding = $zz_conf['character_set'];
	// PHP does not support all encodings
	if (in_array($log_encoding, array_keys($zz_conf['translate_log_encodings'])))
		$log_encoding = $zz_conf['translate_log_encodings'][$log_encoding];

	$msg = html_entity_decode(strip_tags($page['h1'])."\n\n"
		.strip_tags($page['error_description'])."\n"
		.strip_tags($page['error_explanation'])."\n\n", ENT_QUOTES, $log_encoding);
	$settings = [];
	$settings['subject'] = '('.$status.')';
	$settings['logfile'] = '['.$status.' '.$zz_setting['request_uri'].']';
	switch ($status) {
	case 503:
		$settings['no_return'] = true; // don't exit function again
		wrap_error($msg, E_USER_ERROR, $settings);
		break;
	case 404:
		if (wrap_errorpage_logignore()) return false;
		// own error message!
		$requested = $zz_page['url']['full']['scheme'].'://'
			.$zz_setting['hostname'].$zz_setting['request_uri'];
		$msg = sprintf(wrap_text("The URL\n\n%s\n\nwas requested via %s\n"
			." with the IP address %s\nBrowser %s\n\n"
			." but could not be found on the server"), $requested, 
			$_SERVER['HTTP_REFERER'], $zz_setting['remote_ip'], $_SERVER['HTTP_USER_AGENT']);
		if (!empty($_POST)) {
			$msg .= "\n\n".wrap_print($_POST, false, false);
		}
		$settings['mail_no_request_uri'] = true;		// we already have these
		$settings['mail_no_ip'] = true;
		$settings['mail_no_user_agent'] = true;
		$settings['logfile'] = '['.$status.']';
		wrap_error($msg, !empty($page['error_type']) ? $page['error_type'] : E_USER_WARNING, $settings);
		break;
	case 403:
		$settings['logfile'] .= ' (User agent: '
			.(!empty($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown').')';
		wrap_error($msg, E_USER_NOTICE, $settings);
		break;
	case 410:
		if (wrap_errorpage_logignore()) return false;
		$msg .= ' Referer: '.$_SERVER['HTTP_REFERER'];
		wrap_error($msg, E_USER_NOTICE, $settings);
		break;
	case 400:
	case 401:
	case 405:
	case 501:
		wrap_error($msg, E_USER_NOTICE, $settings);
		break;
	case 500:
		if (!wrap_substr($_SERVER['SERVER_PROTOCOL'], 'HTTP')) {
			$msg .= sprintf(
				wrap_text('Unsupported server protocol (%s)'),
				wrap_html_escape($_SERVER['SERVER_PROTOCOL'])
			);
			wrap_error($msg, E_USER_NOTICE, $settings);
		} else {
			$msg .= "\n".json_encode($_SERVER);
			wrap_error($msg, E_USER_WARNING, $settings);
		}
		break;
	default:
		wrap_error($msg, E_USER_WARNING, $settings);
		break;
	}
	return true;
}

/**
 * Checks errorlog-ignores whether to ignore this error for log or not
 *
 * Because some internet providers consider it clever to send a 503 status
 * code if a script is looking for security holes, a 503 might not be logged.
 * This is not recommended for other cases when a 503 might occur.
 *
 * @param mixed $status
 * @param string $string (optional) string to compare
 * @return bool true: ignore for logging, false: log error
 */
function wrap_errorpage_ignore($status, $string = false) {
	global $zz_setting;
	
	$files = [];
	if (empty($zz_setting['errors_not_logged_no_defaults'])) {
		$files[] = $zz_setting['core'].'/errors-not-logged.txt';
	}
	$files[] = $zz_setting['custom_wrap_dir'].'/errors-not-logged.txt';
	foreach ($files as $file) {
		if (!file_exists($file)) continue;
		$handle = fopen($file, 'r');
		$i = 0;
		while (!feof($handle)) {
			$i++;
			$line = fgetcsv($handle, 8192, "\t");
			if ($line[0] != $status) continue;
			if (count($line) !== 3) {
				wrap_error(sprintf('File %s is wrong in line %s.', $file, $i), E_USER_NOTICE);
			}
			switch ($line[1]) {
			case 'string':
				if ($string === $line[2]) {
					return true;
				}
				break;
			case 'all':
				if ($_SERVER['REQUEST_URI'] === $line[2]) {
					return true;
				}
				break;
			case 'end':
				if (substr($_SERVER['REQUEST_URI'], -(strlen($line[2]))) === $line[2]) {
					return true;
				}
				break;
			case 'begin':
				if (substr($_SERVER['REQUEST_URI'], 0, (strlen($line[2]))) === $line[2]) {
					return true;
				}
				break;
			case 'regex':
				if (preg_match($line[2], $_SERVER['REQUEST_URI'])) {
					return true;
				}
				break;
			case 'ip':
				if (substr($zz_setting['remote_ip'], 0, (strlen($line[2]))) === $line[2]) {
					return true;
				}
				break;
			case 'ua':
				if (empty($_SERVER['HTTP_USER_AGENT'])) break;
				if (wrap_error_checkmatch($_SERVER['HTTP_USER_AGENT'], $line[2])) return true;
				break;
			case 'referer':
				if (empty($_SERVER['HTTP_REFERER'])) break;
				if (wrap_error_checkmatch($_SERVER['HTTP_REFERER'], $line[2])) return true;
				break;
			default:
				wrap_error(sprintf('Case %s in file %s in line %s not supported.', $line[1], $file, $i), E_USER_NOTICE);
			}
		}
	}
	return false;
}

/**
 * simple matching check with wildcard (asterisk) allowed at both
 * beginning and end of match
 *
 * @param string $string string to check
 * @param string $match match to check against
 * @return bool true: match was found
 */
function wrap_error_checkmatch($string, $match) {
	if ($string === $match) return true;
	if (substr($match, 0, 1) === '*' AND substr($match, -1) === '*') {
		$without = substr($match, 1, -1);
		if (strstr($string, $without)) return true;
	} elseif (substr($match, 0, 1) === '*') {
		$without = substr($match, 1);
		if (substr($string, -strlen($without)) === $without) return true;
	} elseif (substr($match, -1) === '*') {
		$without = substr($match, 0, -1);
		if (substr($string, 0, strlen($without)) === $without) return true;
	}
	return false;
}

/**
 * determine whether to log an error
 *
 * @return bool true: do not log
 */
function wrap_errorpage_logignore() {
	global $zz_page;
	global $zz_setting;

	// access without REFERER will be ignored (may be typo, ...)
	if (!isset($_SERVER['HTTP_REFERER'])) return true;
	if (!trim($_SERVER['HTTP_REFERER'])) return true;

	// access without USER_AGENT will be ignored, badly programmed script
	if (empty($_SERVER['HTTP_USER_AGENT'])) return true;

	// access from the same existing page to this page nonexisting
	// is impossible (there are some special circumstances, e. g. a 
	// script behaves differently the next time it was uploaded, but we
	// ignore these), bad programmed script

	$referer = parse_url($_SERVER['HTTP_REFERER']);
	if (!$referer) return true; // not parseable = invalid
	if (empty($referer['scheme'])) return true; // no real referer comes without scheme
	if (empty($referer['host'])) return true; // not really parseable = invalid
	if (strtolower($zz_setting['hostname']) !== strtolower($referer['host'])) {
		$ok = false;
		// missing www. redirect
		if (strtolower('www.'.$referer['host']) === strtolower($zz_setting['hostname'])) $ok = true;
		// IP redirect
		if ($referer['host'] === $_SERVER['SERVER_ADDR']) $ok = true;
		// referer from canonical hostname
		if (!empty($zz_setting['canonical_hostname'])
			AND strtolower($zz_setting['canonical_hostname']) === strtolower($referer['host'])) $ok = true;
		if (!$ok) return false;
	}
	// ignore scheme, port, user, pass
	// query
	if (!empty($referer['query']) AND (
		empty($zz_page['url']['full']['query'])
		OR (wrap_error_url_decode($referer['query']) !== wrap_error_url_decode($zz_page['url']['full']['query']))
	)) {
		return false;
	}
	// path is left
	if (empty($referer['path'])) $referer['path'] = '/';
	if ($referer['path'] === $zz_page['url']['full']['path']) return true;
	if (str_replace('//', '/', $referer['path']) === $zz_page['url']['full']['path']) return true;
	if ($referer['path'] === $zz_setting['base'].$zz_page['url']['full']['path']) return true;
	if ($referer['path'] === $zz_setting['request_uri']) return true; // for malformed URIs
	// check if equal if path has %-encoded values
	if (wrap_error_url_decode($referer['path']) === wrap_error_url_decode($zz_setting['base'].$zz_page['url']['full']['path']))
		return true;

	return false;
}

function wrap_error_url_decode($url) {
	$url = str_replace('//', '/', $url);
	return preg_replace_callback('/%[2-7][0-9A-F]/i', 'wrap_url_all_decode', $url);
}
