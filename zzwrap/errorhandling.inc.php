<?php 

/**
 * zzwrap
 * Error handling
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2022 Gustaf Mossakowski
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
	static $collect;
	static $collect_messages;
	static $collect_error_type;

	wrap_include_ext_libraries(); // for mail template, maybe zzbrick is used

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
	case E_USER_NOTICE:
	case E_USER_DEPRECATED:
		// unimportant error only show in debug mode
		$level = 'notice';
		break;
	}

	$log_encoding = $zz_setting['character_set'];
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

	if (!isset($settings['log_post_data'])) $settings['log_post_data'] = true;
	if (empty($_POST)) $settings['log_post_data'] = false;
	elseif (!$zz_conf['error_log_post']) $settings['log_post_data'] = false;

	// reformat log output
	if (!empty($zz_conf['error_log'][$level]) AND $zz_conf['log_errors']) {
		wrap_log((!empty($settings['logfile']) ? $settings['logfile'].' ' : '').$log_output, $level, 'zzwrap');
		if ($settings['log_post_data']) wrap_log('postdata', 'notice', 'zzwrap');
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
		if ($user = wrap_user()) $foot .= sprintf("\n%s: %s", wrap_text('User'), $user);
		if ($foot) $msg .= "\n\n-- ".$foot;

		$mail['to'] = $zz_conf['error_mail_to'];
		$mail['message'] = $msg;
		if (!empty($zz_conf['error_mail_parameters']))
			$mail['parameters'] = $zz_conf['error_mail_parameters']; 
		$mail['subject'] = '';
		if (empty($zz_setting['mail_subject_prefix']))
			$mail['subject'] = '['.wrap_get_setting('project').'] ';
		$mail['subject'] .= (function_exists('wrap_text') ? wrap_text('Error on website') : 'Error on website')
			.(!empty($settings['subject']) ? ' '.$settings['subject'] : '');
		$mail['headers']['X-Originating-URL'] = $zz_setting['host_base'].$zz_setting['request_uri'];
		$mail['headers']['X-Originating-Datetime'] = date('Y-m-d H:i:s');
		$mail['queue'] = true;
		wrap_mail($mail);
		break;
	case 'output':
		if (empty($zz_page['error_msg'])) $zz_page['error_msg'] = '';
		if (str_starts_with($msg, 'JSON ')) {
			$msg = json_decode(substr($msg, 5));
			$msg = json_encode($msg, JSON_PRETTY_PRINT);
			$msg = sprintf('<pre>%s</pre>', wrap_html_escape($msg));
		} else {
			$msg = wrap_html_escape($msg);
		}
		$zz_page['error_msg'] .= '<p class="error">'
			.str_replace("\n", "<br>", $msg).'</p>';
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
		AND $_SERVER['SCRIPT_NAME'] !== '/_scripts/main.php') {
		if (preg_match('/[a-zA-Z0-9]/', substr($_SERVER['REQUEST_URI'], 1, 1))) {
			wrap_error('mod_rewrite does not work as expected: '
				.$_SERVER['REQUEST_URI'].' ('.$_SERVER['SCRIPT_NAME'].')', E_USER_NOTICE);
			$page['status'] = 503;
		}
	}
	
	if (empty($page['status']) AND !empty($_GET['code'])) {
		if (str_starts_with($_SERVER['REQUEST_URI'], '/_scripts/main.php?code='))
			$page['status'] = 404;
		else
			// get 'code' if main.php is directly accessed as an error page
			$page['status'] = $_GET['code'];
	} elseif (empty($page['status'])) {
		// default error code
		$page['status'] = 404;
	}
	if ($page['status'] == 404 AND str_starts_with($_SERVER['REQUEST_URI'], 'http://')) {
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
	
	if (empty($page['lang'])) $page['lang'] = $zz_setting['lang'];
	$page['last_update'] = false;
	if (empty($zz_setting['error_breadcrumbs_without_homepage_url'])) {
		$page['breadcrumbs'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'
			.wrap_get_setting('project').'</a></strong> '.$zz_setting['breadcrumbs_separator'].' ';
	} else {
		$page['breadcrumbs'] = '';
	}
	$page['breadcrumbs'] .= wrap_text($status['text']); 
	$page['pagetitle'] = strip_tags($page['status'].' '.wrap_text($status['text'])
		.' ('.wrap_get_setting('project').')'); 
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
	
	wrap_htmlout_page($page);
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
	
	$log_encoding = $zz_setting['character_set'];
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
	case 401:
		// do not log an error if no credentials were send
		if (empty($_SERVER['PHP_AUTH_USER']) AND empty($_SERVER['PHP_AUTH_PW'])) break;
		$msg .= sprintf(' (IP: %s, User agent: %s)'
			, $_SERVER['REMOTE_ADDR']
			, (!empty($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown')
		);
	case 400:
	case 405:
	case 501:
		wrap_error($msg, E_USER_NOTICE, $settings);
		break;
	case 500:
		if (!str_starts_with($_SERVER['SERVER_PROTOCOL'], 'HTTP')) {
			$msg .= sprintf(
				wrap_text('Unsupported server protocol (%s)'),
				wrap_html_escape($_SERVER['SERVER_PROTOCOL'])
			);
			wrap_error($msg, E_USER_NOTICE, $settings);
		} else {
			$msg .= "\n".json_encode($_SERVER);
			if (!empty($_POST)) $msg .= "\n\n".json_encode($_POST);
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
			if (!$line) continue;
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
			case 'request':
				if (wrap_error_checkmatch($zz_setting['request_uri'], $line[2])) return true;
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
			case 'post':
				if (empty($_POST)) break;
				if (wrap_error_checkmatch(json_encode($_POST), $line[2])) return true;
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

	$valid = wrap_error_referer_valid();
	if (!$valid) return true;
	return false;
}

/**
 * check if a referer is valid
 *
 * @param bool $non_urls defaults to false, true: allow referers that
 *		are not URLs
 * @param bool $local_redirects defaults to true, false: do not allow referer to be a redirect
 * @return bool true: referer is valid, false: referer is invalid
 */
function wrap_error_referer_valid($non_urls = false, $local_redirects = true) {
	global $zz_setting;
	global $zz_page;

	$referer = parse_url($_SERVER['HTTP_REFERER']);
	// not parseable = invalid
	if (!$referer) return false;

	// no real referer comes without scheme
	if (empty($referer['scheme'])) return $non_urls;
	// not really parseable = invalid
	if (empty($referer['host'])) return $non_urls;
	// there's always a path if referer is created by browser
	if (empty($referer['path'])) return false;

	// referer from external domain?
	$external_request = false;
	if (strtolower($zz_setting['hostname']) !== strtolower($referer['host'])) {
		$external_request = true;
	} elseif (!empty($zz_setting['canonical_hostname']) AND $zz_setting['canonical_hostname'] !== $zz_setting['hostname']) {
		$external_request = true;
	}
	if ($external_request) {
		if (!wrap_error_referer_local_redirect($referer['host'])) {
			if (strstr($referer['path'], '../')) return false;
			if ($referer['host'] === $zz_setting['hostname']
				AND $referer['path'] !== $zz_page['url']['full']['path']
				AND $referer['scheme'] !== 'https' AND in_array('/', $zz_setting['https_urls'])
			) {
				// it is no https redirect but from the same hostname and from a different path
				// i. e. it is a forged referer, @see wrap_error_referer_local_https()
				// but with a non-canonical hostname here
				return false;
			}
			if ($zz_page['url']['full'] === $referer) {
				// made up hostname purportedly accessing from the same, non-existent URL
				return false;
			}
			// if yes, return true, we don't know more about it
			return true;
		}
		if (!$local_redirects) return false;
		// is it a local redirect but paths differ? impossible, the referring URL
		// would have been redirected as well
		if ($referer['path'] !== $zz_page['url']['full']['path']) return false;
	}
	// referer from own domain, but invalid because should be https?
	if (wrap_error_referer_local_https($referer, $zz_page['url']['full'])) {
		return false;
	}

	// check for identical URL in referer, no page links to itself
	if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'])) return true;
	// query string different?
	if (!empty($referer['query'])) {
		// referer has query string, URL not, probably valid referer
		if (empty($zz_page['url']['full']['query'])) return true;
		// referer has different query string
		if (wrap_error_url_decode($referer['query']) !== wrap_error_url_decode($zz_page['url']['full']['query']))
			return true;
	} else {
		// URL has query string, referer not
		if (!empty($zz_page['url']['full']['query'])) return true;
	}

	// query string is equal, path is left
	if ($referer['path'] === $zz_page['url']['full']['path']) return false;
	if (str_replace('//', '/', $referer['path']) === $zz_page['url']['full']['path']) return false;
	if ($referer['path'] === $zz_setting['base'].$zz_page['url']['full']['path']) return false;
	if ($referer['path'] === $zz_setting['request_uri']) return false; // for malformed URIs
	// check if equal if path has %-encoded values
	if (wrap_error_url_decode($referer['path']) === wrap_error_url_decode($zz_setting['base'].$zz_page['url']['full']['path']))
		return false;

	return true;
}

/**
 * check if a referer is a redirect on localhost
 *
 * @param string $referer_host
 * @return bool true: localhost, false: external referer
 */
function wrap_error_referer_local_redirect($referer_host) {
	global $zz_setting;

	// missing www. redirect
	if (strtolower('www.'.$referer_host) === strtolower($zz_setting['hostname']))
		return true;

	// canonical host name e. g. starts with www., access is from and to
	// server without www. = referer is wrong
	if (!empty($zz_setting['canonical_hostname']) AND strtolower($referer_host) === strtolower($zz_setting['hostname']))
		return true;

	// IP redirect
	if (!empty($_SERVER['SERVER_ADDR']) AND $referer_host === $_SERVER['SERVER_ADDR']) return true;

	// referer from canonical hostname
	$hostnames = [];
	if (!empty($zz_setting['canonical_hostname']))
		$hostnames[] = $zz_setting['canonical_hostname'];
	if (!empty($zz_setting['external_redirect_hostnames'])) // external redirects
		$hostnames = array_merge($hostnames, $zz_setting['external_redirect_hostnames']);
	foreach ($hostnames as $hostname) {
		if (strtolower($hostname) === strtolower($referer_host)) return true;
	}
	return false;
}

/**
 * check if local referer must have https but has not
 *
 * @param array $referer
 * @return bool true: https required but not there, false: ok
 */
function wrap_error_referer_local_https($referer) {
	global $zz_setting;
	global $zz_page;

	// just if referer URL path differs
	if (!$referer['path']) return false;
	if (!$zz_page['url']['full']['path']) return false;
	if ($referer['path'] === $zz_page['url']['full']['path']) return false;

	// check for https
	if ($referer['scheme'] === 'https') return false;
	if (empty($zz_setting['canonical_hostname'])) return false;
	if ($referer['host'] !== $zz_setting['canonical_hostname']) return false;

	// if all URLs are https, then real referer from same domain must be https, too
	if (in_array('/', $zz_setting['https_urls'])) return true;

	return false;
}

function wrap_error_url_decode($url) {
	$i = 0;
	while (strpos($url, '//') !== false) {
		$url = str_replace('//', '/', $url);
		$i++;
		if ($i > 10) break;
	}
	return preg_replace_callback('/%[2-7][0-9A-F]/i', 'wrap_url_all_decode', $url);
}

/**
 * format a line for logfile
 *
 * @param string $line 
 * @param mixed $level (optional)
 * @param string $module (optional)
 * @param string $file (optional)
 * @return string
 */
function wrap_log($line, $level = 'notice', $module = '', $file = false) {
	global $zz_conf;
	global $zz_setting;
	static $postdata;
	if ($line === 'postdata') {
		if (!empty($postdata)) return false;
		$line = sprintf('POST[json] %s', json_encode($_POST));
		$postdata = true; // just log POST data once per request
	}

	switch ($level) {
		case E_USER_ERROR: $level = 'error'; break;
		case E_USER_WARNING: $level = 'warning'; break;
		case E_USER_NOTICE: $level = 'notice'; break;
		case E_USER_DEPRECATED: $level = 'deprecated'; break;
	}

	if (!$module) {
		$module = !empty($zz_setting['active_module']) ? $zz_setting['active_module'] : 'custom';
	}

	$user = wrap_user();
	$line = sprintf('[%s] %s %s: %s'
		, date('d-M-Y H:i:s')
		, $module
		, ucfirst($level)
		, preg_replace("/\s+/", " ", $line) 
	);
	$line = substr($line, 0, $zz_conf['log_errors_max_len'] - (strlen($user) + 4));
	$line .= sprintf(" [%s]\n", $user);
	if (!$file) {
		if (in_array($module, ['zzform', 'zzwrap'])
			AND array_key_exists($level, $zz_conf['error_log']))
			$file = $zz_conf['error_log'][$level];
		else {
			$log_filename = !empty($zz_setting['log_filename'])
				? $module.'/'.$zz_setting['log_filename'] : $module;
			$file = sprintf('%s/%s.log', $zz_setting['log_dir'], $log_filename);
			wrap_mkdir(dirname($file));
		}
	}
	error_log($line, 3, $file);
	return true;
}

/**
 * read username
 * either from setting log_username, SESSION or from $zz_conf
 *
 * @return string
 */
function wrap_user() {
	global $zz_conf;
	global $zz_setting;
	if (!empty($zz_setting['log_username'])) return $zz_setting['log_username'];
	if (!empty($_SESSION['username'])) return $_SESSION['username'];
	if (!empty($zz_conf['user'])) return $zz_conf['user'];
	return '';
}
