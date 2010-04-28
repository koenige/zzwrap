<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2009
// Error handling


/*
	possible parameters
	$zz_conf['error_mail_to'] = <email>
	$zz_conf['error_mail_from'] = <email>
	$zz_conf['error_handling'] = <email>
	
*/
function wrap_error($msg, $errorcode) {
	global $zz_setting;
	global $zz_conf;

	$return = false;
	switch ($errorcode) {
	case E_USER_ERROR: // critical error: stop!
		$level = 'error';
		$return = 'exit'; // get out of this function immediately
		break;
	default:
	case E_USER_WARNING: // acceptable error, go on
		$level = 'warning';
		break;
	case E_USER_NOTICE: // unimportant error only show in debug mode
		$level = 'notice';
		break;
	}
	
	// Log output
	$log_output = trim(html_entity_decode($msg));
	$log_output = str_replace('<br>', "\n\n", $log_output);
	$log_output = str_replace('<br class="nonewline_in_mail">', "; ", $log_output);
	$log_output = strip_tags($log_output);

	$user = (!empty($_SESSION['username']) ? $_SESSION['username'] : '');

	// reformat log output
	if (!empty($zz_conf['error_log'][$level]) AND $zz_conf['log_errors']) {
		$error_line = '['.date('d-M-Y H:i:s').'] zzwrap '.ucfirst($level).': '.preg_replace("/\s+/", " ", $log_output);
		$error_line = substr($error_line, 0, $zz_conf['log_errors_max_len'] -(strlen($user)+2)).' '.$user."\n";
		error_log($error_line, 3, $zz_conf['error_log'][$level]);
	}
		
	if (!empty($zz_conf['debug']))
		$zz_conf['error_handling'] = 'screen';
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
		if (in_array($level, $zz_conf['error_mail_level'])) {
			$email_head = 'From: "'.html_entity_decode($zz_conf['project']).'" <'.$zz_conf['error_mail_from'].'>
MIME-Version: 1.0
Content-Type: text/plain; charset='.$zz_conf['character_set'].'
Content-Transfer-Encoding: 8bit';
			mail($zz_conf['error_mail_to'], '['.html_entity_decode($zz_conf['project']).'] '
				.(function_exists('wrap_text') ? wrap_text('Error on website') : 'Error on website'), 
			$msg, $email_head, '-f '.$zz_conf['error_mail_from']);
		}
		break;
	case 'screen':
		echo '<pre>';
		echo htmlentities($msg); //str_replace("\n", "<br>", $msg);
		echo '</pre>';
		break;
	default:
		break;
	}

	if ($return == 'exit') {
		if (function_exists('wrap_quit')) wrap_quit(503);
		else {
//			$page['status'] = 503;
//			include_once $zz_setting['http_error_script'];
//			exit;
			
			// no error function, so it's a problem with the database connection
			$codes = wrap_read_errorcodes();
			$error_messages = $codes[503];
			header($_SERVER['SERVER_PROTOCOL'].' '.$error_messages['code'].' '.$error_messages['title']);
			if (!empty($zz_conf['character_set']))
				header('Content-Type: text/html; charset='.$zz_conf['character_set']);
			echo '<title>503 - '.$error_messages['title'].'</title>';
			echo '<h1>'.$error_messages['title'].'</h1>';
			echo '<p>'.$error_messages['description'].'</p>';
			exit;
		}

	}
}

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