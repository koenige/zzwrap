<?php 

/**
 * zzwrap
 * Mail functions
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2012-2014 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Sends an e-mail
 *
 * @param array $mail
 *		mixed 'to' (string: To:-Line; array: 'name', 'e_mail'),
 *		string 'subject' (subject of message)
 *		string 'message' (body of message)
 *		array 'headers' (optional)
 * @global $zz_conf
 *		'error_mail_from', 'project', 'character_set', 'mail_subject_prefix'
 * @global $zz_setting
 *		'local_access', bool 'show_local_mail' log mail or show mail
 * @return bool true: message was sent; false: message was not sent
 */
function wrap_mail($mail) {
	global $zz_conf;
	global $zz_setting;

	// To
	$mail['to'] = wrap_mail_name($mail['to']);

	// Subject
	if (!empty($zz_conf['mail_subject_prefix']))
		$mail['subject'] = $zz_conf['mail_subject_prefix'].' '.$mail['subject'];
	$mail['subject'] = mb_encode_mimeheader($mail['subject']);

	// Signature?
	if (wrap_template_file('signature-mail', false)) {
		$mail['message'] .= "\r\n".wrap_template('signature-mail');
	}

	// From
	if (!isset($mail['headers']['From'])) {
		$mail['headers']['From']['name'] = $zz_conf['project'];
		$mail['headers']['From']['e_mail'] = $zz_conf['error_mail_from'];
	}
	$mail['headers']['From'] = wrap_mail_name($mail['headers']['From']);
	
	// Reply-To
	if (!empty($mail['headers']['Reply-To'])) {
		$mail['headers']['Reply-To'] = wrap_mail_name($mail['headers']['Reply-To']);
	}
	
	// Additional headers
	if (!isset($mail['headers']['MIME-Version']))
		$mail['headers']['MIME-Version'] = '1.0';
	if (!isset($mail['headers']['Content-Type']))
		$mail['headers']['Content-Type'] = 'text/plain; charset='.$zz_conf['character_set'];
	if (!isset($mail['headers']['Content-Transfer-Encoding']))
		$mail['headers']['Content-Transfer-Encoding'] = '8bit';

	$additional_headers = '';
	foreach ($mail['headers'] as $field_name => $field_body) {
		// set but empty headers will be ignored
		if (!$field_body) continue;
		// newlines and carriage returns: probably some injection, ignore
		if (strstr($field_body, "\n")) continue;
		if (strstr($field_body, "\r")) continue;
		// @todo field name ASCII characters 33-126 except colon
		// @todo field body any ASCII characters except CR LF
		// @todo field body longer than 78 characters SHOULD / 998 
		// characters MUST be folded with CR LF WSP
		$additional_headers .= $field_name.': '.$field_body.$zz_setting['mail_header_eol'];
	}

	// Additional parameters
	if (!isset($mail['parameters'])) $mail['parameters'] = '';

	$old_error_handling = $zz_conf['error_handling'];
	if ($zz_conf['error_handling'] == 'mail') {
		$zz_conf['error_handling'] = false; // don't send mail, does not work!
	}

	// if local server, show e-mail, don't send it
	if ($zz_setting['local_access']) {
		$mail = 'Mail '.wrap_html_escape('To: '.$mail['to']."\n"
			.'Subject: '.$mail['subject']."\n".
			$additional_headers."\n".$mail['message']);
		if (!empty($zz_setting['show_local_mail'])) {
			echo '<pre>', $mail, '</pre>';
			exit;
		} else {
			wrap_error($mail, E_USER_NOTICE);
		}
	} else {
		// if real server, send mail
		$success = mail($mail['to'], $mail['subject'], $mail['message'], $additional_headers, $mail['parameters']);
		if (!$success) {
			wrap_error('Mail could not be sent. (To: '.str_replace('<', '&lt;', $mail['to']).', From: '
				.str_replace('<', '&lt;', $mail['headers']['From']).', Subject: '.$mail['subject']
				.', Parameters: '.$mail['parameters'].')', E_USER_NOTICE);
		}
	}
	$zz_conf['error_handling'] = $old_error_handling;
	return true;
}

/**
 * Combine Name and e-mail address for mail header
 *
 * @param array $name
 * @return string
 */
function wrap_mail_name($name) {
	if (!is_array($name)) return $name;
	$mail = '';
	if (!empty($name['name'])) {
		// remove line feeds
		$name['name'] = str_replace("\n", "", $name['name']);
		$name['name'] = str_replace("\r", "", $name['name']);
		// patterns that are allowed for atom
		$pattern_unquoted = "/^[a-z0-9 \t!#$%&'*+\-^?=~{|}_`\/]*$/i";
		$name['name'] = mb_encode_mimeheader($name['name']);
		if (!preg_match($pattern_unquoted, $name['name'])) {
			// alternatively use quoted-string
			// @todo: allow quoted-pair
			$name['name'] = str_replace('"', '', $name['name']);
			$name['name'] = str_replace('\\', '', $name['name']);
			$name['name'] = '"'.$name['name'].'"';
		}
		$mail .= $name['name'].' ';
	}
	$mail .=  '<'.$name['e_mail'].'>';
	return $mail;
}

/**
 * check a single e-mail address if it's valid
 *
 * @param string $e_mail
 * @return string $e_mail if it's correct, empty string if address is invalid
 * @see zz_check_mail_single
 */
function wrap_mail_valid($e_mail) {
	// remove <>-brackets around address
	if (substr($e_mail, 0, 1) == '<' && substr($e_mail, -1) == '>') 
		$e_mail = substr($e_mail, 1, -1); 

	// check address if syntactically correct
	$e_mail_pm = '/^[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\\.[a-z0-9!#$%&\'*+\\/=?^_`{|}~-]+)*'
		.'@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i';
	if (!preg_match($e_mail_pm, $e_mail, $check)) return false;

	// check if hostname has MX record
	$host = explode('@', $e_mail);
	if (count($host) !== 2) return false;
	$exists = checkdnsrr($host[1], 'ANY');
	if (!$exists) return false;

	return $e_mail;
}
