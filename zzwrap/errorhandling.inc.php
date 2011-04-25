<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2010
// Error handling


/**
 * error handling: log errors, mail errors, exits script if critical error
 *
 * @param string $msg error message
 * @param int $error_code E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE
 * @param array $settings (optional internal settings)
 *		'logfile': extra text for logfile only, 'no_return': does not return but
 *		exit, 'mail_no_request_uri', 'mail_no_ip', 'mail_no_user_agent',
 *		'subject'
 * @global array $zz_conf cofiguration settings
 *		'error_mail_to', 'error_mail_from', 'error_handling', 'error_log',
 *		'log_errors', 'log_errors_max_len', 'debug', 'error_mail_level',
 *		'project', 'character_set'
 * @global array $zz_page
 */
function wrap_error($msg, $errorcode = E_USER_NOTICE, $settings = array()) {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;

	require_once $zz_setting['core'].'/language.inc.php';	// include language settings
	require_once $zz_setting['core'].'/core.inc.php';	// CMS core scripts

	$return = false;
	switch ($errorcode) {
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
	
	// Log output
	$log_output = $msg;
	$log_output = str_replace('<br>', "\n\n", $log_output);
	$log_output = str_replace('<br class="nonewline_in_mail">', "; ", $log_output);
	$log_output = strip_tags($log_output);
	$log_output = trim(html_entity_decode($log_output, ENT_QUOTES, $log_encoding));

	$user = (!empty($_SESSION['username']) ? $_SESSION['username'] : '');
	if (!$user AND !empty($zz_conf['user'])) $user = $zz_conf['user'];

	// reformat log output
	if (!empty($zz_conf['error_log'][$level]) AND $zz_conf['log_errors']) {
		$error_line = '['.date('d-M-Y H:i:s').'] zzwrap '.ucfirst($level).': '
			.(!empty($settings['logfile']) ? $settings['logfile'].' ' : '')
			.preg_replace("/\s+/", " ", $log_output);
		$error_line = substr($error_line, 0, $zz_conf['log_errors_max_len'] 
			- (strlen($user)+4)).' ['.$user."]\n";
		error_log($error_line, 3, $zz_conf['error_log'][$level]);
		if (!empty($_POST) AND $zz_conf['error_log_post']) {
			$error_line = '['.date('d-M-Y H:i:s').'] zzwrap Notice: POST '.serialize($_POST);
			$error_line = substr($error_line, 0, $zz_conf['log_errors_max_len'] 
				- (strlen($user)+4)).' ['.$user."]\n";
			error_log($error_line, 3, $zz_conf['error_log'][$level]);
		}
	}
		
	if (!empty($zz_conf['debug']))
		$zz_conf['error_handling'] = 'output';
	if (empty($zz_conf['error_handling']))
		$zz_conf['error_handling'] = false;

	if (!is_array($zz_conf['error_mail_level'])) {
		if ($zz_conf['error_mail_level'] == 'error') 
			$zz_conf['error_mail_level'] = array('error');
		elseif ($zz_conf['error_mail_level'] == 'warning') 
			$zz_conf['error_mail_level'] = array('error', 'warning');
		elseif ($zz_conf['error_mail_level'] == 'notice') 
			$zz_conf['error_mail_level'] = array('error', 'warning', 'notice');
		else
			$zz_conf['error_mail_level'] = array();
	}
	switch ($zz_conf['error_handling']) {
	case 'mail':
		if (!in_array($level, $zz_conf['error_mail_level'])) break;
		$msg = html_entity_decode($msg, ENT_QUOTES, $log_encoding);
		// add some technical information to mail
		$foot = false;
		if (empty($settings['mail_no_request_uri']))
			$foot .= "\nURL: http://".$_SERVER['SERVER_NAME']
				.$_SERVER['REQUEST_URI'];
		if (empty($settings['mail_no_ip']))
			$foot .= "\nIP: ".$_SERVER['REMOTE_ADDR'];
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
		wrap_mail($mail);
		break;
	case 'output':
		if (empty($zz_page['error_msg'])) $zz_page['error_msg'] = '';
		$zz_page['error_msg'] .= '<p class="error">'
			.str_replace("\n", "<br>", htmlentities($msg)).'</p>';
		break;
	default:
		break;
	}

	if ($return == 'exit') {
		$page['status'] = 503;
		wrap_errorpage($page, $zz_page, false);
		exit;
	}
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
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */ 
function wrap_errorpage($page, $zz_page, $log_errors = true) {
	global $zz_setting;	
	global $zz_conf;

	require_once $zz_setting['core'].'/language.inc.php';	// include language settings
	require_once $zz_setting['core'].'/core.inc.php';	// CMS core scripts
	require_once $zz_setting['core'].'/page.inc.php';	// CMS page scripts

	// -- 1. check what kind of error page it is
	$codes = wrap_read_errorcodes();
	
	// if wanted, check if mod_rewrite works
	if (!empty($zz_setting['mod_rewrite_error']) 
		AND $_SERVER['SCRIPT_NAME'] != '/_scripts/main.php') {
		if (preg_match('/[a-zA-Z0-9]/', substr($_SERVER['REQUEST_URI'], 1, 1))) {
			wrap_error('mod_rewrite does not work as expected: '
				.$_SERVER['REQUEST_URI'].' ('.$_SERVER['SCRIPT_NAME'].')', E_USER_NOTICE);
			$_GET['code'] = 503;
		}
	}
	
	if (!empty($_GET['code']) AND in_array($_GET['code'], array_keys($codes)))
		// get 'code' if errors.php is directly accessed as an error page
		$page['status'] = $_GET['code'];
	elseif (empty($page['status']) OR !in_array($page['status'], array_keys($codes)))
		// default error code
		$page['status'] = 404;
	if ($page['status'] == 404 AND substr($_SERVER['REQUEST_URI'], 0, 7) == 'http://') {
		// probably badly designed robot, away with it
		$page['status'] = 400;
	}
	
	$error_messages = $codes[$page['status']];
	// some codes get a link to the homepage
	$extra_description_codes = array(404);
	
	// -- 2. set page elements
	
	if (empty($page['lang'])) $page['lang'] = $zz_conf['language'];
	$page['last_update'] = false;
	$page['breadcrumbs'] = '<strong><a href="'.$zz_setting['homepage_url'].'">'
		.$zz_conf['project'].'</a></strong> '.$zz_page['breadcrumbs_separator'].' '
		.wrap_text($error_messages['title']); 
	$page['pagetitle'] = strip_tags($page['status'].' '.wrap_text($error_messages['title'])
		.' ('.$zz_conf['project'].')'); 
	$page['h1'] = wrap_text($error_messages['title']);
	$page['error_description'] = sprintf(wrap_text($error_messages['description']), 
		$_SERVER['REQUEST_METHOD']);
	if (in_array($page['status'], $extra_description_codes)) {
		$page['error_explanation'] = sprintf(wrap_text('Please try to find the '
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
	if (file_exists($file = $zz_setting['custom_wrap_template_dir'].'/http-error.template.txt'))
		$http_error_template = $file;
	else
		$http_error_template = $zz_setting['core'].'/default-http-error.template.txt';
	$page['text'] =  implode("", file($http_error_template));

	if (function_exists('wrap_htmlout_menu') AND $zz_conf['db_connection']) { 
		// get menus, if function and database connection exist
		$page['nav_db'] = wrap_get_menu();
	}
	
	// -- 3. output HTTP header
	header($_SERVER['SERVER_PROTOCOL'].' '.$error_messages['code'].' '
		.$error_messages['title']);
	if ($page['status'] == 405) {
		header('Allow: '.implode(', ', $zz_setting['http']['allowed']));
	}
	
	// -- 4. error logging

	if ($log_errors) wrap_errorpage_log($page['status'], $page);

	// -- 5. output page
	
	if ($zz_setting['brick_page_templates'] == true) {
		wrap_htmlout_page($page);
	} else {
		if (!empty($zz_conf['character_set']))
			header('Content-Type: text/html; charset='.$zz_conf['character_set']);
		$lines = explode("\n", $page['text']);
		foreach ($lines as $index => $line) {
			if (substr($line, 0, 1) == '#') unset($lines[$index]);
		}
		$page['text'] = implode("\n", $lines);
		$page['text'] = str_replace('%%% page h1 %%%', $page['h1'], $page['text']);
		$page['text'] = str_replace('%%% page code %%%', $page['status'], $page['text']);
		$page['text'] = str_replace('%%% page error_description %%%', $page['error_description'], $page['text']);
		$page['text'] = str_replace('%%% page error_explanation %%%', $page['error_explanation'], $page['text']);
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

	$log_encoding = $zz_conf['character_set'];
	// PHP does not support all encodings
	if (in_array($log_encoding, array_keys($zz_conf['translate_log_encodings'])))
		$log_encoding = $zz_conf['translate_log_encodings'][$log_encoding];

	$msg = html_entity_decode(strip_tags($page['h1'])."\n\n"
		.strip_tags($page['error_description'])."\n"
		.strip_tags($page['error_explanation'])."\n\n", ENT_QUOTES, $log_encoding);
	$settings = array();
	$settings['subject'] = '('.$status.')';
	$settings['logfile'] = '['.$status.' '.$_SERVER['REQUEST_URI'].']';
	switch ($status) {
	case 503:
		$settings['no_return'] = true; // don't exit function again
		wrap_error($msg, E_USER_ERROR, $settings);
		break;
	case 404:
		// access without REFERER will be ignored (may be typo, ...)
		if (!isset($_SERVER['HTTP_REFERER'])) return false;
		if (!trim($_SERVER['HTTP_REFERER'])) return false;
		// access without USER_AGENT will be ignored, badly programmed script
		if (empty($_SERVER['HTTP_USER_AGENT'])) return false;
		// access from the same existing page to this page nonexisting
		// is impossible (there are some special circumstances, e. g. a 
		// script behaves differently the next time it was uploaded, but we
		// ignore these), bad programmed script
		global $zz_page;
		$requested = $zz_page['url']['full']['scheme'].'://'
			.$zz_page['url']['full']['host'].$zz_setting['base']
			.$zz_page['url']['full']['path'];
		if ($_SERVER['HTTP_REFERER'] == $requested) return false;
		// ignore some URLs ending in the following strings
		if (empty($zz_setting['error_404_ignore_strings'])) {
			$zz_setting['error_404_ignore_strings'] = array(
				'&', // encoded mail addresses, some bots are too stupid for them
				'%26', // encoded mail addresses, some bots are too stupid for them
				'/./', // will normally be resolved by browser (bad script)
				'/../', // will normally be resolved by browser (bad script)
				'data:image/gif;base64,AAAA' // this is a data-URL misinterpreted
			);
		}
		foreach ($zz_setting['error_404_ignore_strings'] as $string) {
			if (substr($_SERVER['REQUEST_URI'], -(strlen($string))) == $string) return false;
		}
		if (empty($zz_setting['error_404_ignore_begin'])) {
			$zz_setting['error_404_ignore_begin'] = array(
				'/webcal://', // browsers know how to handle unkown protocols (bad script)
				'/webcal:/', // pseudo-clever script, excluding this string is not 100% correct
				// but should do no harm ('webcal:' as a part of a string is valid,
				// so if you use it, errors on pages with this URI part won't get logged)
				'/plugins/editors/tinymce/' // wrong CMS, don't send enerving errors
			);
		}
		foreach ($zz_setting['error_404_ignore_begin'] as $string) {
			if (substr($_SERVER['REQUEST_URI'], 0, (strlen($string))) == $string) return false;
		}
		// own error message!
		$msg = sprintf(wrap_text("The URL\n\n%s was requested from\n\n%s\n\n"
			." with the IP address %s\n (Browser %s)\n\n"
			." but could not be found on the server"), $requested, 
			$_SERVER['HTTP_REFERER'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
		$settings['mail_no_request_uri'] = true;		// we already have these
		$settings['mail_no_ip'] = true;
		$settings['mail_no_user_agent'] = true;
		$settings['logfile'] = '['.$status.']';
		wrap_error($msg, E_USER_WARNING, $settings);
		break;
	case 403:
		$settings['logfile'] .= ' (User agent: '.$_SERVER['HTTP_USER_AGENT'].')';
		wrap_error($msg, E_USER_NOTICE, $settings);
		break;
	case 400:
	case 410:
	case 405:
	case 501:
		wrap_error($msg, E_USER_NOTICE, $settings);
		break;
	default:
		wrap_error($msg, E_USER_WARNING, $settings);
		break;
	}
	return true;
}
	

/**
 * reads HTTP error codes from http-errors.txt
 *
 * @global array $zz_setting
 *		'core'
 * @return array $codes
 */
function wrap_read_errorcodes() {
	global $zz_setting;
	
	// read error codes from file
	$pos[0] = 'code';
	$pos[1] = 'title';
	$pos[2] = 'description';
	$codes_from_file = file($zz_setting['core'].'/http-errors.txt');
	foreach ($codes_from_file as $line) {
		if (substr($line, 0, 1) == '#') continue;	// Lines with # will be ignored
		elseif (!trim($line)) continue;				// empty lines will be ignored
		$values = explode("\t", trim($line));
		$i = 0;
		$code = '';
		foreach ($values as $val) {
			if (trim($val)) {
				if (!$i) $code = trim($val);
				$codes[$code][$pos[$i]] = trim($val);
				$i++;
			}
		}
		if ($i < 3) {
			for ($i; $i < 3; $i++) {
				$codes[$code][$pos[$i]] = '';
			}
		}
	}
	return $codes;
}


?>