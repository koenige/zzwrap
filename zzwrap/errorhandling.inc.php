<?php 

/**
 * zzwrap
 * Error handling
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2024 Gustaf Mossakowski
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
 *		'subject', bool 'log_post_data', bool 'collect_start', bool 'collect_end',
 *		string 'class'
 * @global array $zz_page
 */
function wrap_error($msg, $error_type = E_USER_NOTICE, $settings = []) {
	global $zz_page;
	static $collect = false;
	static $collect_messages = [];
	static $collect_error_type = NULL;

	if (wrap_setting('install')) {
		echo $msg;
		exit;
	}
	wrap_lib(); // for mail template, maybe zzbrick is used

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
		if ($collect_error_type) {
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
		if ($collect_error_type) {
			$error_type = $collect_error_type;
		}
	}
	if (!$msg) return false;

	$return = false;
	switch ($error_type) {
	case E_ERROR:
	case E_USER_ERROR: // critical error: stop!
		if (wrap_setting('error_exit_503')) $settings['no_return'] = true;
		$level = ($error_type === E_USER_ERROR ? 'error' : 'fatal');
		if (empty($settings['no_return'])) 
			$return = 'exit'; // get out of this function immediately
		wrap_setting('error_exit_503', true);
		break;
	default:
	case E_WARNING:
	case E_RECOVERABLE_ERROR:
	case E_USER_WARNING: // acceptable error, go on
		$level = 'warning';
		break;
	case E_NOTICE:
	case E_DEPRECATED:
	case E_USER_NOTICE:
	case E_USER_DEPRECATED:
		// unimportant error only show in debug mode
		$level = 'notice';
		break;
	}

	$log_encoding = wrap_log_encoding();

	if (is_array($msg)) $msg = 'JSON '.json_encode($msg);

	// Log prefix?
	if (wrap_setting('error_prefix'))
		$msg = wrap_setting('error_prefix').' '.$msg;
	
	// Log output
	$log_output = $msg;
	$log_output = str_replace('<br>', "\n\n", $log_output);
	$log_output = str_replace('<br class="nonewline_in_mail">', "; ", $log_output);
	$log_output = strip_tags($log_output);
	$log_output = trim(html_entity_decode($log_output, ENT_QUOTES, $log_encoding));

	if (!isset($settings['log_post_data'])) $settings['log_post_data'] = true;
	if (empty($_POST)) $settings['log_post_data'] = false;
	elseif (!wrap_setting('error_log_post')) $settings['log_post_data'] = false;

	$error_handling = wrap_setting('error_handling');
	if (wrap_setting('debug')) $error_handling = 'output';

	$log_status = wrap_error_log($log_output, $error_type, $level, $settings);
	if ($log_status === false) $error_handling = false;

	switch ($error_handling) {
	case 'mail_summary':
		if (!in_array($level, wrap_setting('error_mail_level'))) break;
		wrap_error_summary($msg, $level);
		break;
	case 'mail':
		if (!in_array($level, wrap_setting('error_mail_level'))) break;
		if (!wrap_setting('error_mail_to')) break;
		$msg = html_entity_decode($msg, ENT_QUOTES, $log_encoding);
		// add some technical information to mail
		$foot = false;
		if (empty($settings['mail_no_request_uri']))
			$foot .= "\nURL: ".wrap_setting('host_base').wrap_setting('request_uri');
		if (empty($settings['mail_no_ip']))
			$foot .= "\nIP: ".wrap_setting('remote_ip');
		if (empty($settings['mail_no_user_agent']))
			$foot .= "\nBrowser: ".(!empty($_SERVER['HTTP_USER_AGENT']) 
				? $_SERVER['HTTP_USER_AGENT'] : wrap_text('unknown'));	
		// add user name to mail message if there is one
		if ($user = wrap_username()) $foot .= sprintf("\n%s: %s", wrap_text('User'), $user);
		if ($foot) $msg .= "\n\n-- ".$foot;

		$mail['to'] = wrap_setting('error_mail_to');
		$mail['message'] = $msg;
		$mail['parameters'] = wrap_setting('error_mail_parameters');
		$mail['subject'] = '';
		if (!wrap_setting('mail_subject_prefix'))
			$mail['subject'] = '['.wrap_setting('project').'] ';
		$mail['subject'] .= (function_exists('wrap_text') ? wrap_text('Error on website') : 'Error on website')
			.(!empty($settings['subject']) ? ' '.$settings['subject'] : '');
		$mail['headers']['X-Originating-URL'] = wrap_setting('host_base').wrap_setting('request_uri');
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
		$class = $settings['class'] ?? 'error';
		$zz_page['error_msg'] .= '<p class="'.$class.'">'
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
 * log an error
 *
 * @param string $log_output
 * @param int $error_type
 * @param string $level
 * @param array $settings
 * @return mixed bool or NULL
 */
function wrap_error_log($log_output, $error_type, $level, $settings) {
	if (!wrap_setting('log_errors')) return NULL;
	if (!wrap_setting('error_log['.$level.']')) return NULL;

	switch ($error_type) {
	case E_USER_ERROR:
	case E_USER_WARNING:
	case E_USER_NOTICE:
	case E_USER_DEPRECATED:
		$module = 'zzwrap';
		break;
	default:
		$module = 'PHP';
	}
	if (wrap_error_ignore($module, $log_output)) return false;

	wrap_log(trim(($settings['logfile'] ?? '').' '.$log_output), $level, $module);
	if ($settings['log_post_data']) wrap_log('postdata', 'notice', $module);
	return true;
}

/**
 * log SQL query and time passed
 *
 * @param string $sql SQL query
 * @param int $time time when query started
 */
function wrap_error_sql($sql, $time) {
	$time = microtime(true) - $time;
	wrap_error(
		'-- SQL query in '.$time." --\n".$sql,
		E_USER_NOTICE,
		['class' => wrap_error_sql_class($time)]
	);
}

/**
 * add CSS class depending on time passed
 *
 * @param int $time time passed
 * @return string
 */
function wrap_error_sql_class($time) {
	if ($time > 0.1) return 'error';
	if ($time > 0.01) return 'warning';
	if ($time > 0.001) return 'notice';
	return 'good';

}

/**
 * sends a large mail instead of several one liners if errors occured; if parameters
 * are given, saves log entries for later mailing
 *
 * @param string $line (optional)
 * @param string $lerror_leveline (optional)
 * @param bool $prefix_line (optional)
 * @return bool = mail was sent (true), not sent (false)
 * @see wrap_error()
 */
function wrap_error_summary($line = '', $error_level = '', $prefix_line = false) {
	static $log = [];
	static $prefixes = [];
	if ($line) {
		if ($prefix_line) $prefixes[$error_level] = $line;
		else $log[$error_level] = $line;
		return;
	}
	
	if (!$log) return false;
	if (wrap_setting('error_handling') !== 'mail_summary') return false;
	wrap_setting('error_handling', 'mail');
	
	// no need to log these errors again
	$log_errors = wrap_setting('log_errors');
	wrap_setting('log_errors', false);
	
	foreach ($log AS $error_level => $errors) {
		$msg = implode("\n\n", $errors);
		if (!empty($prefixes[$error_level])) {
			$msg = implode("\n\n", $prefixes[$error_level])."\n\n".$msg;
			unset($prefixes[$error_level]);
		}
		wrap_error($msg, $error_level);
	}
	$log = [];
	wrap_setting('log_errors', $log_errors);
	return true;
}

/**
 * outputs an error page
 * checks which error it is, set page elements, output HTTP header, HTML, log
 *
 * @param array $page
 * @param array $zz_page
 * @param bool $log_errors whether errors shall be logged or not
 */ 
function wrap_errorpage($page, $zz_page, $log_errors = true) {
	global $zz_page;

	wrap_lib();

	// -- 1. check what kind of error page it is
	// if wanted, check if mod_rewrite works
	if (wrap_setting('log_mod_rewrite_error') 
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
	
	if (empty($page['lang'])) $page['lang'] = wrap_setting('lang');
	$page['last_update'] = false;
	if (empty($page['text'])) $page['error_no_content'] = true; // do not show title etc. from db
	$page['error_title'] = wrap_text($status['text']);
	$page['error_description'] = wrap_text($status['description'], ['values' =>  
		$_SERVER['REQUEST_METHOD']]);
	if (in_array($page['status'], $extra_description_codes)) {
		$page['error_explanation'] = ' '.wrap_text('Please try to find the '
			.'content you were looking for from our <a href="%s">main page</a>.',
			['values' => wrap_setting('homepage_url')]);
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
	$page[wrap_sql_fields('page_last_update')] = false;
	if (!empty($zz_page['db'][wrap_sql_fields('page_last_update')])) {
		$zz_page['db'][wrap_sql_fields('page_last_update')] = false;
	}
	
	// -- 3. output HTTP header
	header($_SERVER['SERVER_PROTOCOL'].' '.$status['code'].' '.$status['text']);
	if ($page['status'] == 405)
		header('Allow: '.implode(',', wrap_setting('http[allowed]')));
	
	// -- 4. error logging

	if ($log_errors) wrap_errorpage_log($page['status'], $page);

	// -- 5. output page
	
	$page = wrap_page_defaults($page);

	if (wrap_setting('send_as_json')) {
		$page['text'] = [
			'status' => $page['status'],
			'title' => $page['error_title'],
			'error_description' => $page['error_description'],
			'error_explanation' => $page['error_explanation'] ?? '',
			'url' => $page['url'] ?? wrap_setting('request_uri')
		];
		return wrap_send_text(json_encode($page['text']), 'json', $page['status'], $page['headers'] ?? []);
		exit;
	}
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
	global $zz_page;

	if (in_array($status, [401, 403, 404, 410, 503]))
		if (wrap_error_ignore($status)) return false;
	
	$log_encoding = wrap_log_encoding();

	$msg = html_entity_decode(strip_tags($page['error_title'])."\n\n"
		.strip_tags($page['error_description'])."\n"
		.strip_tags($page['error_explanation'])."\n\n", ENT_QUOTES, $log_encoding);
	$settings = [];
	$settings['subject'] = '('.$status.')';
	$settings['logfile'] = '['.$status.' '.wrap_setting('request_uri').']';
	switch ($status) {
	case 503:
		$settings['no_return'] = true; // don't exit function again
		wrap_error($msg, E_USER_ERROR, $settings);
		break;
	case 404:
		if (wrap_errorpage_logignore()) return false;
		// send mail?
		wrap_error_repeated_404();
		// own error message!
		$requested = $zz_page['url']['full']['scheme'].'://'
			.wrap_setting('hostname').wrap_setting('request_uri');
		$msg = wrap_text("The URL\n\n%s\n\nwas requested via %s\n"
			." with the IP address %s\nBrowser %s\n\n"
			." but could not be found on the server", ['values' => [$requested, 
			$_SERVER['HTTP_REFERER'], wrap_setting('remote_ip'), $_SERVER['HTTP_USER_AGENT']]]);
		if (!empty($_POST)) {
			$msg .= "\n\n".wrap_print($_POST, false, false);
		}
		$settings['mail_no_request_uri'] = true;		// we already have these
		$settings['mail_no_ip'] = true;
		$settings['mail_no_user_agent'] = true;
		$settings['logfile'] = '['.$status.']';
		wrap_error($msg, $page['error_type'] ?? E_USER_WARNING, $settings);
		break;
	case 403:
		$settings['logfile'] .= ' (User agent: '
			.($_SERVER['HTTP_USER_AGENT'] ?? 'unknown').')';
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
			, wrap_setting('remote_ip')
			, $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
		);
	case 400:
	case 405:
	case 414:
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
 * log no. of 404 errors per IP, disable error mails if no. is too high
 *
 * @param void
 * @return void
 */
function wrap_error_repeated_404() {
	wrap_include('file', 'zzwrap');
	if (in_array(wrap_setting('error_handling'), ['mail', 'mail_summary'])) {
		$lines = wrap_file_log('error404');
		$count = 0;
		foreach ($lines as $line) {
			if ($line['ip'] !== wrap_setting('remote_ip')) continue;
			$count++;
		}
		if ($count >= wrap_setting('logfile_error404_stop_mail_after_requests'))
			wrap_setting('error_handling', false);
	}
	wrap_file_log('error404', 'write', [time(), wrap_setting('remote_ip'), wrap_setting('request_uri')]);
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
function wrap_error_ignore($status, $string = false) {
	static $ignores = [];
	if (!$ignores) $ignores = wrap_tsv_parse('errors-not-logged');
	$status = strtolower($status);
	if (!array_key_exists($status, $ignores)) return false;

	foreach ($ignores[$status] as $line) {
		if (count($line) !== 3) {
			wrap_error(sprintf('File %s is wrong in line %s.', $file, implode(' ', $line)), E_USER_NOTICE);
			continue;
		}
		switch ($line['type']) {
		case 'string':
			if ($string === $line['string']) {
				return true;
			}
			break;
		case 'string_regex':
			if (substr($line['string'], 0, 1) !== substr($line['string'], -1)) {
				$line['string'] = sprintf('/%s/i', str_replace('/', '\/', $line['string']));
			}
			if (preg_match($line['string'], $string)) {
				return true;
			}
			break;
		case 'all':
			if ($_SERVER['REQUEST_URI'] === $line['string']) {
				return true;
			}
			break;
		case 'end':
			if (substr($_SERVER['REQUEST_URI'], -(strlen($line['string']))) === $line['string']) {
				return true;
			}
			break;
		case 'begin':
			if (substr($_SERVER['REQUEST_URI'], 0, (strlen($line['string']))) === $line['string']) {
				return true;
			}
			break;
		case 'regex':
			if (preg_match($line['string'], $_SERVER['REQUEST_URI'])) {
				return true;
			}
			break;
		case 'request':
			if (wrap_error_checkmatch(wrap_setting('request_uri'), $line['string'])) return true;
			break;
		case 'ip':
			if (substr(wrap_setting('remote_ip'), 0, (strlen($line['string']))) === $line['string']) {
				return true;
			}
			break;
		case 'ua':
			if (empty($_SERVER['HTTP_USER_AGENT'])) break;
			if (wrap_error_checkmatch($_SERVER['HTTP_USER_AGENT'], $line['string'])) return true;
			break;
		case 'referer':
			if (empty($_SERVER['HTTP_REFERER'])) break;
			if (wrap_error_checkmatch($_SERVER['HTTP_REFERER'], $line['string'])) return true;
			break;
		case 'post':
			if (empty($_POST)) break;
			if (wrap_error_checkmatch(json_encode($_POST), $line['string'])) return true;
			break;
		default:
			wrap_error(sprintf('Case %s in line %s not supported.', $line['type'], implode(' ', $line)), E_USER_NOTICE);
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
	// access without REFERER will be ignored (may be typo, ...)
	if (!isset($_SERVER['HTTP_REFERER'])) return true;
	if (!trim($_SERVER['HTTP_REFERER'])) return true;

	// access without USER_AGENT will be ignored, badly programmed script
	if (empty($_SERVER['HTTP_USER_AGENT'])) return true;
	
	// hostname is IP
	if (!empty($_SERVER['SERVER_ADDR']) AND wrap_setting('hostname') === $_SERVER['SERVER_ADDR']) return true;

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
	if (strtolower(wrap_setting('hostname')) !== strtolower($referer['host'])) {
		$external_request = true;
	} elseif (wrap_setting('canonical_hostname') AND wrap_setting('canonical_hostname') !== wrap_setting('hostname')) {
		$external_request = true;
	}
	if ($external_request) {
		if (!wrap_error_referer_local_redirect($referer['host'])) {
			if (strstr($referer['path'], '../')) return false;
			if ($referer['host'] === wrap_setting('hostname')
				AND $referer['path'] !== $zz_page['url']['full']['path']
				AND $referer['scheme'] !== 'https' AND in_array('/', wrap_setting('https_urls'))
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
	if ($referer['path'] === wrap_setting('base').$zz_page['url']['full']['path']) return false;
	if ($referer['path'] === wrap_setting('request_uri')) return false; // for malformed URIs
	// check if equal if path has %-encoded values
	if (wrap_error_url_decode($referer['path']) === wrap_error_url_decode(wrap_setting('base').$zz_page['url']['full']['path']))
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
	// missing www. redirect
	if (strtolower('www.'.$referer_host) === strtolower(wrap_setting('hostname')))
		return true;
	if (strtolower($referer_host) === strtolower('www.'.wrap_setting('hostname')))
		return true;

	// canonical host name e. g. starts with www., access is from and to
	// server without www. = referer is wrong
	if (wrap_setting('canonical_hostname') AND strtolower($referer_host) === strtolower(wrap_setting('hostname')))
		return true;

	// IP redirect
	if (!empty($_SERVER['SERVER_ADDR']) AND $referer_host === $_SERVER['SERVER_ADDR']) return true;

	// referer from canonical hostname
	$hostnames = [];
	if (wrap_setting('canonical_hostname'))
		$hostnames[] = wrap_setting('canonical_hostname');
	if (wrap_setting('external_redirect_hostnames')) // external redirects
		$hostnames = array_merge($hostnames, wrap_setting('external_redirect_hostnames'));
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
	global $zz_page;

	// just if referer URL path differs
	if (!$referer['path']) return false;
	if (!$zz_page['url']['full']['path']) return false;
	if ($referer['path'] === $zz_page['url']['full']['path']) return false;

	// check for https
	if ($referer['scheme'] === 'https') return false;
	if (!wrap_setting('canonical_hostname')) return false;
	if ($referer['host'] !== wrap_setting('canonical_hostname')) return false;

	// if all URLs are https, then real referer from same domain must be https, too
	if (in_array('/', wrap_setting('https_urls'))) return true;

	return false;
}

function wrap_error_url_decode($url) {
	$i = 0;
	while (strpos($url, '//') !== false) {
		$url = str_replace('//', '/', $url);
		$i++;
		if ($i > 10) break;
	}
	return wrap_url_normalize_percent_encoding($url, 'all');
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
	static $postdata = false;
	if ($line === 'postdata') {
		if ($postdata) return false;
		$line = sprintf('POST[json] %s', json_encode($_POST));
		$postdata = true; // just log POST data once per request
	}

	switch ($level) {
		case E_ERROR: $level = 'fatal'; break;
		case E_USER_ERROR: $level = 'error'; break;
		case E_WARNING: case E_USER_WARNING: $level = 'warning'; break;
		case E_NOTICE: case E_USER_NOTICE: $level = 'notice'; break;
		case E_DEPRECATED: case E_USER_DEPRECATED: $level = 'deprecated'; break;
		case E_RECOVERABLE_ERROR: $level = 'recoverable error'; break;
	}

	if (!$module)
		$module = wrap_setting('active_module') ?? 'custom';

	$user = wrap_username();
	if (!$user) $user = wrap_setting('remote_ip');
	$line = sprintf('[%s] %s %s: %s'
		, date('d-M-Y H:i:s')
		, $module
		, ucfirst($level)
		, preg_replace("/\s+/", " ", $line) 
	);
	$line = substr($line, 0, wrap_setting('log_errors_max_len') - (strlen($user) + 4));
	$line .= sprintf(" [%s]\n", $user);
	if (!$file) {
		if (in_array($module, ['zzform', 'zzwrap', 'PHP'])
			AND wrap_setting('error_log['.$level.']'))
			$file = wrap_setting('error_log['.$level.']');
		else {
			$log_filename = wrap_setting('log_filename')
				? $module.'/'.wrap_setting('log_filename') : $module;
			$file = sprintf('%s/%s.log', wrap_setting('log_dir'), $log_filename);
			wrap_mkdir(dirname($file));
		}
	}
	error_log($line, 3, $file);
	return true;
}

/**
 * get character encoding for logfile
 *
 * @return string
 */
function wrap_log_encoding() {
	$log_encoding = wrap_setting('character_set');
	// PHP does not support all encodings
	if (!$log_recode = wrap_setting('log_recode')) return $log_encoding;
	if (!array_key_exists($log_encoding, $log_recode)) return $log_encoding;
	return $log_recode[$log_encoding];
}

/**
 * shutdown function, checks if there is a severe error not handled by error_handler
 * calls error_handler with this error
 */
function wrap_shutdown() {
    $error = error_get_last();
    if (!$error) return;
    wrap_error_handler($error['type'], $error['message'], $error['file'], $error['line']);
}

/**
 * custom error handler
 *
 * @param int $type
 * @param string $message
 * @param string $file
 * @param int $line
 * @return bool
 */
function wrap_error_handler($type, $message, $file, $line) {
	if (!strstr($message, $file))
		$message = sprintf('%s in %s:%d', $message, $file, $line);
	wrap_error($message, $type);
	return true;
}
