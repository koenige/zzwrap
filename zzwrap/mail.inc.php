<?php 

/**
 * zzwrap
 * Mail functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2012-2024 Gustaf Mossakowski
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
 *		array 'multipart' (optional):
 *			string 'text', string 'html', array 'files'
 * @param array $list send mails to multiple recipients via a list
 * @return bool true: message was sent; false: message was not sent
 */
function wrap_mail($mail, $list = []) {
	// multipart?
	if (!empty($mail['multipart']) AND !wrap_setting('use_library_phpmailer')) {
		if (empty($mail['multipart']['files'])) $mail['multipart']['files'] = [];
		if (empty($mail['multipart']['blobs'])) $mail['multipart']['blobs'] = [];
		foreach ($mail['multipart']['files'] as $index => $file) {
			if (file_exists($file['path_local'])) {
				$binary = fread(fopen($file['path_local'], "r"), filesize($file['path_local']));
				$mail['multipart']['files'][$index]['file_base64_encoded'] = chunk_split(base64_encode($binary));
			} else {
				wrap_error('File not found. '.$file['path_local']);
			}
		}
		foreach ($mail['multipart']['blobs'] as $index => $file) {
			$file['file_base64_encoded'] = chunk_split(base64_encode($file['blob']));
			$mail['multipart']['files'][] = $file;
		}
		if (!isset($mail['multipart']['text'])) {
			$mail['multipart']['text'] = $mail['message'];
			$mail['message'] = '';
		}
		$mail['message'] .= trim(wrap_template('mail-multipart', $mail['multipart']));
	}

	// normalize line endings
	$mail['message'] = str_replace("\n", "\r\n", $mail['message']);
	$mail['message'] = str_replace("\r\r\n", "\r\n", $mail['message']);
	$mail['message'] = str_replace("\r", "\r\n", $mail['message']);
	$mail['message'] = str_replace("\r\n\n", "\r\n", $mail['message']);

	// headers in message?
	$mail = wrap_mail_headers($mail);

	// To
	$mail['to'] = wrap_mail_name($mail['to']);

	// Subject
	if (wrap_setting('mail_subject_prefix'))
		$mail['subject'] = wrap_setting('mail_subject_prefix').' '.$mail['subject'];

	// From
	if (!isset($mail['headers']['From'])) {
		$mail['headers']['From']['name'] = wrap_setting('project');
		$mail['headers']['From']['e_mail'] = wrap_setting('own_e_mail');
	}
	// From as Reply-To?
	$mail['headers'] = wrap_mail_reply_to($mail['headers']);
	$mail['headers']['From'] = wrap_mail_name($mail['headers']['From']);
	
	// Reply-To
	if (!empty($mail['headers']['Reply-To'])) {
		$mail['headers']['Reply-To'] = wrap_mail_name($mail['headers']['Reply-To']);
	}
	
	// Additional headers
	if (!isset($mail['headers']['MIME-Version']))
		$mail['headers']['MIME-Version'] = '1.0';
	if (!isset($mail['headers']['Content-Type']))
		$mail['headers']['Content-Type'] = 'text/plain; charset='.wrap_setting('character_set');
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
		$additional_headers .= $field_name.': '.$field_body.wrap_setting('mail_header_eol');
	}

	// Additional parameters
	if (!isset($mail['parameters'])) $mail['parameters'] = '';

	$old_error_handling = wrap_setting('error_handling');
	if (wrap_setting('error_handling') === 'mail')
		wrap_setting('error_handling', false); // don't send mail, does not work!

	// if local server, show e-mail, don't send it
	if (wrap_setting('local_access')) {
		$mailtext = 'Mail '.wrap_html_escape('To: '.$mail['to']."\n"
			.'Subject: '.$mail['subject']."\n".
			$additional_headers."\n".$mail['message']);
		if (wrap_setting('show_local_mail')) {
			echo '<pre>', $mailtext, '</pre>';
			exit;
		}
	} else {
		// hinder Outlook to mess with the line breaks
		// https://support.microsoft.com/en-au/help/287816/line-breaks-are-removed-in-posts-made-in-plain-text-format-in-outlook
		if (str_starts_with($mail['headers']['Content-Type'], 'text/plain')) {
			$mail['message'] = str_replace("\n", "\t\n", $mail['message']);
			$mail['message'] = str_replace("\r\t\n", "\t\r\n", $mail['message']);
		}
		// if real server, send mail
		if (!empty($mail['queue'])) {
			$success = wrap_mail_queue_add($mail, $additional_headers);
		} elseif (wrap_setting('use_library_phpmailer')) {
			$mail = wrap_mail_signature($mail);
			$success = wrap_mail_phpmailer($mail, $list);
		} else {
			$mail = wrap_mail_signature($mail);
			$success = wrap_mail_php($mail, $additional_headers);
		}
		if (!$success) {
			wrap_error('Mail could not be sent. (To: '.str_replace('<', '&lt;', $mail['to']).', From: '
				.str_replace('<', '&lt;', $mail['headers']['From']).', Subject: '.$mail['subject']
				.', Parameters: '.$mail['parameters'].')', E_USER_NOTICE);
		}
	}
	if (wrap_setting('log_mail')) {
		wrap_mail_log($mail, $additional_headers);
	}
	wrap_setting('error_handling', $old_error_handling);
	return true;
}

/**
 * look for headers at the top of message
 * empty line stops searching, unknown header as well
 * syntax: Header: Value
 *
 * important: never send a user generated e-mail without an introductory text!
 * @param array $mail
 * @return array
 */
function wrap_mail_headers($mail) {
	$headers = [
		'Date', 'To', 'Reply-To', 'Subject', 'From', 'User-Agent',
		'MIME-Version', 'Content-Type', 'Content-Transfer-Encoding'
	];
	$lines = explode("\n", $mail['message']);
	foreach ($lines as $index => $line) {
		if (!trim($line)) {
			unset($lines[$index]);
			break;
		}
		if (!preg_match('~^([A-Za-z-]+): (.*)~', $line, $matches)) break;
		if (!in_array($matches[1], $headers) AND substr($matches[1], 0, 2) !== 'X-') break;
		if (in_array($matches[1], ['To', 'Subject'])) {
			$mail[strtolower($matches[1])] = trim($matches[2]);
		} elseif (in_array($matches[1], ['Date'])) {
			// .. ignore Date header
		} else {
			$mail['headers'][$matches[1]] = trim($matches[2]);
		}
		unset($lines[$index]);
	}
	$mail['message'] = implode("\n", $lines);
	return $mail;
}

/**
 * Combine Name and e-mail address for mail header
 *
 * possible input: string single mail address, list of mail addresses separated
 * by comma; array with keys name (optional), e_mail
 * it is not possible to input a list of mail addresses combined with names
 * @param mixed $name
 * @param bool $encode (optional, defaults to true)
 * @return string
 */
function wrap_mail_name($name, $encode = true) {
	if (!is_array($name)) {
		// add brackets, there are checks that think brackets
		// show that a mail is less likely to be junk
		if (strstr($name, ',')) {
			$name = explode(',', $name);
		} else {
			$name = [$name];
		}
		foreach ($name as $index => $part) {
			$part = trim($part);
			if ($part AND !strstr($part, ' ')) {
				// add brackets, but not to empty mail addresses
				// or already formatted addresses
				if (substr($part, 0, 1) !== '<') $part = '<'.$part;
				if (substr($part, -1) !== '>') $part .= '>';
			}
			$name[$index] = $part;
		}
		$name = implode(', ', $name);
		return $name;
	}
	$mail = '';
	if (!empty($name['name'])) {
		// remove line feeds
		$name['name'] = str_replace("\n", "", $name['name']);
		$name['name'] = str_replace("\r", "", $name['name']);
		// patterns that are allowed for atom
		$pattern_unquoted = "/^[a-z0-9 \t!#$%&'*+\-^?=~{|}_`\/]*$/i";
		// do not add line break (or preg_match would not work for long lines)
		if ($encode)
			$name['name'] = mb_encode_mimeheader($name['name'], wrap_setting('character_set'), wrap_setting('character_set'), "");
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
 * check a single e-mail address if it’s valid
 *
 * @param string $e_mail
 * @param bool $mail_check_mx (default true, false: do not check MX record)
 * @return string $e_mail if it’s correct, empty string if address is invalid
 */
function wrap_mail_valid($e_mail, $mail_check_mx = true) {
	// remove <>-brackets around address
	if (substr($e_mail, 0, 1) == '<' && substr($e_mail, -1) == '>') 
		$e_mail = substr($e_mail, 1, -1); 

	if (filter_var($e_mail, FILTER_VALIDATE_EMAIL) === false) return false;
	// check address if syntactically correct
	// double check, @todo now with filter_var unnecessary
	$e_mail_pm = '/^[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\\.[a-z0-9!#$%&\'*+\\/=?^_`{|}~-]+)*'
		.'@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i';
	if (!preg_match($e_mail_pm, $e_mail, $check)) return false;

	$host = explode('@', $e_mail);
	if (count($host) !== 2) return false;
	foreach (wrap_setting('mail_invalid_domains') as $domain)
		if (str_ends_with($host[1], $domain)) return false;

	if (!$mail_check_mx) return $e_mail;
	if (wrap_setting('mail_dont_check_mx')) return $e_mail;

	// check if hostname has MX record
	if (in_array($host[1], wrap_setting('mail_mx_whitelist'))) return $e_mail;
	// trailing dot to get a FQDN
	if (substr($host[1], -1) !== '.') $host[1] .= '.';
	
	wrap_include('file', 'zzwrap');
	$lines = wrap_file_log('maildomain');
	foreach ($lines as $line) {
		if ($line['domain'] !== $host[1]) continue;
		if ($line['status'] === 'invalid') return false;
		if ($line['status'] === 'valid') return $e_mail;
		// timeout: retry
	}
	
	$time = microtime(true);
	// MX record is not obligatory, so use ANY
	$exists = checkdnsrr($host[1], 'ANY');
	if ($wait_ms = wrap_setting('mail_mx_check_wait_ms') AND microtime(true) - $time > $wait_ms / 1000) {
		wrap_error('Checking DNS record took to long, so probably it is a timeout: '.$e_mail.' host:'.$host[1]);
		$status = 'timeout';
	} elseif ($exists) {
		$status = 'valid';
	} else {
		$status = 'invalid';
	}
	wrap_file_log('maildomain', 'write', [time(), $host[1], $status]);
	if ($status === 'invalid') return false;
	return $e_mail;
}

/**
 * log mail in mail.log
 *
 * @param array $mail
 * @param array $additional_headers
 * @param string $logfile (optional)
 * @return void
 */
function wrap_mail_log($mail, $additional_headers, $logfile = '') {
	if (!$logfile) $logfile = 'mail.log';
	$logfile = wrap_setting('log_dir').'/'.$logfile;
	wrap_mkdir(dirname($logfile));
	if (!file_exists($logfile)) touch($logfile);
	
	$text = 'Date: '.wrap_date(time(), 'timestamp->rfc1123')."\n"
		.'To: '.$mail['to']."\n"
		.'Subject: '.$mail['subject']."\n"
		.$additional_headers."\n".$mail['message']
		.wrap_mail_separator();
	$text = str_replace("\r\n", "\n", $text);
	file_put_contents($logfile, $text, FILE_APPEND | LOCK_EX);
}

/**
 * set a separator for mails in mail log
 *
 * @return string
 */
function wrap_mail_separator() {
	return "\n\n==>>".str_repeat('=', 69)."<<==\n\n";
}

/**
 * use php’s mail() function to send mails
 *
 * @param array $mail
 * @param array $additional_headers
 * @return bool
 */
function wrap_mail_php($mail, $additional_headers) {
	$mail['subject'] = mb_encode_mimeheader($mail['subject'], mb_internal_encoding(), 'B', wrap_setting('mail_header_eol'));
	$success = mail($mail['to'], $mail['subject'], $mail['message'], $additional_headers, $mail['parameters']);
	return $success;
}

/**
 * use phpmailer class instead of PHP's own mail()-function
 * for support of using an external SMTP server
 *
 * @param array $msg
 * @param array $list data for sending several mails
 * @return bool
 */
function wrap_mail_phpmailer($msg, $list) {
	wrap_lib('phpmailer');
	
	$mail = new PHPMailer\PHPMailer\PHPMailer(true);
	if (wrap_setting('mail_phpmailer_debug')) {
		$mail->SMTPDebug = 3; // direct output
		$mail->Debugoutput = 'wrap_mail_phpmailer_error';
	}

	$mail->isSMTP();  
	$mail->Host = wrap_setting('mail_host');
	$mail->Port = wrap_setting('mail_smtp_port');
	$mail->SMTPAuth = true;
	$mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; 
	$mail->Username = wrap_setting('mail_username');
	$mail->Password = wrap_setting('mail_password');
	if ($list) {
		// SMTP connection will not close after each email sent, reduces SMTP overhead
		$mail->SMTPKeepAlive = true; 
	}

	$mail->Subject = $msg['subject'];
	if (!empty($msg['multipart'])) {
		foreach ($msg['multipart']['files'] as $file) {
			if (!file_exists($file['path_local'])) {
				wrap_error('File not found. '.$file['path_local']);
				continue;
			}
			if ($file['disposition'] === 'inline')
				$mail->addEmbeddedImage($file['path_local'], $file['cid']);
			else
				$mail->addAttachment($file['path_local'], $file['title'].'.'.$file['extension']);
		}
		if (!empty($msg['multipart']['blobs'])) foreach ($msg['multipart']['blobs'] as $index => $file) {
			$mail-> addStringAttachment($file['blob'], $file['title'].'.'.$file['extension'],
				PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, $file['mime_content_type'].'/'.$file['mime_subtype'], $file['disposition']);
		}
	}
	wrap_mail_phpmailer_headers($mail, $msg['headers']);

	if (!$list) $list[] = $msg;
	foreach ($list as $item) {
		if (!empty($item['multipart'])) {
			$mail->isHTML(true);
			$mail->AltBody = $msg['message'].$item['multipart']['text'];
			$mail->Body = $item['multipart']['html'];
		} else {
			$mail->Body = $item['message'];
		}
		if (!empty($item['headers']))
			wrap_mail_phpmailer_headers($mail, $item['headers'], true);
		$to = wrap_mail_name($item['to']);
		if (substr_count($to, '@') > 1)
			// @todo make sure beforehands, that there is no input like
			// "last name, first name <test@example.org>, second@example.org"
			// this would not work with wrap_mail_name()
			// names with @ characters are not allowed, too
			$to = explode(',', $to);
		else
			$to = [$to];
		foreach ($to as $recipient) {
			list($to_mail, $to_name) = wrap_mail_split($recipient);
			$mail->addAddress($to_mail, $to_name); 
		}
		try {
			$mail->send();
			if (!empty($item['sql_on_success'])) {
				wrap_db_query($item['sql_on_success']);
				if (function_exists('zz_db_log'))
					zz_db_log($item['sql_on_success'], 'Messenger Robot 329');
			}
			// write to db that message was sent
		} catch (Exception $e) {
			wrap_error('Send mail with phpmailer failed. '.$mail->ErrorInfo);
			// Reset the connection to abort sending this message
			// The loop will continue trying to send to the rest of the list
			$mail->getSMTPInstance()->reset();
		}
		$mail->clearAddresses();
	}

	if (!wrap_setting('local_access') AND wrap_setting('mail_imap_copy_sent')) {
		$success = wrap_mail_sent($mail->getSentMIMEMessage());
		if ($success) wrap_setting('log_mail', false); // do not log mails which are in SENT folder
	}

	return true;
}

/**
 * set mail headers for phpmailer
 *
 * @param object $mail
 * @param array $headers
 * @param int $clear_headers (optional) clear headers
 */
function wrap_mail_phpmailer_headers(&$mail, $headers, $clear_headers = false) {
	foreach ($headers as $field_name => $field_body) {
		if (!$field_body) continue;
		switch ($field_name) {
		case 'From':
			list($from_mail, $from_name) = wrap_mail_split($field_body);
			$mail->setFrom($from_mail, $from_name);
			break;
		case 'Content-Type':
			if (substr($field_body, 0, 20) === 'text/plain; charset=')
				$mail->CharSet = substr($field_body, 20);
			break;
		case 'MIME-Version':
		case 'Content-Transfer-Encoding':
			break;
		case 'Bcc':
		case 'BCC':
			list($this_mail, $this_name) = wrap_mail_split($field_body);
			$mail->addBCC($this_mail, $this_name);
			break;
		case 'Cc':
		case 'CC':
			list($this_mail, $this_name) = wrap_mail_split($field_body);
			$mail->addCC($this_mail, $this_name);
			break;
		case 'Reply-To':
			list($this_mail, $this_name) = wrap_mail_split($field_body);
			$mail->addReplyTo($this_mail, $this_name);
			break;
		case 'Date':
			break; // never add second Date header, some ISPs don’t like that
		default:
			if ($clear_headers)
				$mail->clearCustomHeader($field_name);
			$mail->addCustomHeader($field_name, $field_body);
			break;
		}
	}
}

/**
 * catch debug errors from PHPMailer and send them to wrap_error()
 *
 * @param string $msg
 * @param int $level
 * @return void
 */
function wrap_mail_phpmailer_error($msg, $level) {
	wrap_error('PHPMailer '.$msg);
}

/**
 * split full address "Test man" <test@example.org> to array with name and mail
 *
 * @param string $address
 * @return array
 */
function wrap_mail_split($address) {
	$address = trim($address);
	if ($pos = strrpos($address, ' ')) {
		$e_mail = trim(substr($address, $pos));
		$name = trim(substr($address, 0, $pos));
	} else {
		$e_mail = $address;
		$name = '';
	}
	$e_mail = rtrim($e_mail, '>');
	$e_mail = ltrim($e_mail, '<');
	$name = trim($name, '"');
	return [$e_mail, $name];
}

/**
 * signature? only for plain text mails
 *
 * @param array $mail
 * @return array
 */
function wrap_mail_signature($mail) {
	wrap_lib(); // for error mails necessary

	if (!empty($mail['multipart'])) return $mail;
	if (!str_starts_with($mail['headers']['Content-Type'], 'text/plain')) return $mail;
	if (!wrap_setting('mail_with_signature')) return $mail;
	if (!wrap_template_file('signature-mail', false)) return $mail;

	$mail['message'] .= "\r\n".wrap_template('signature-mail');
	return $mail;
}

/**
 * add mail to mail queue
 *
 * @param array $mail
 * @param array $additional_headers
 * @return bool
 */
function wrap_mail_queue_add($mail, $additional_headers) {
	list($to_mail, $to_name) = wrap_mail_split($mail['to']);
	wrap_mail_log($mail, $additional_headers, sprintf('mailqueue/%s@%s.log', $to_mail, date('Y-m-d H-i-s')));
	return true;
}

/**
 * check if there are mails in the mail queue to send
 *
 * @return bool
 */
function wrap_mail_queue_send() {
	require_once __DIR__.'/syndication.inc.php';
	// lock will unlock automatically, not manually, to just get error mails every n seconds
	$lock = wrap_lock('mailqueue', 'sequential', wrap_setting('error_mail_delay_seconds'));
	if ($lock) return;

	$queue_dir = wrap_setting('log_dir').'/mailqueue';
	if (!file_exists($queue_dir)) return false;
	if (!is_dir($queue_dir)) return false;
	$headers = ['To', 'Subject', 'From'];

	$mail = [];
	$mail['message'] = '';
	$used_logfiles = [];
	$logfiles = scandir($queue_dir);
	foreach ($logfiles as $logfile) {
		if (str_starts_with($logfile, '.')) continue;
		if (!str_ends_with($logfile, '.log')) continue;
		$logdata = explode('@', substr($logfile, 0, -4));
		if (count($logdata) !== 3) continue; // not a logfile
		if (strtotime($logdata[2]) + wrap_setting('error_mail_delay_seconds') >= time()) continue;
		$mail['message'] .= file_get_contents($queue_dir.'/'.$logfile);
		$used_logfiles[] = $queue_dir.'/'.$logfile;
	}
	if (!$mail['message']) return false;

	$old_mail_subject_prefix = wrap_setting('mail_subject_prefix');
	wrap_setting('mail_subject_prefix', false);
	$lines = explode("\n", $mail['message']);
	foreach ($lines as $line) {
		if (!$line) continue;
		if (!strstr($line, ':')) continue;
		foreach ($headers as $header) {
			if (str_starts_with($line, $header.': ')) {
				$mail[strtolower($header)] = substr($line, strlen($header.': '));
			}
		}
		$complete = true;
		foreach ($headers as $header) {
			if (array_key_exists(strtolower($header), $mail)) continue;
			$complete = false;
			break;
		}
		if ($complete) break;
	}
	$mail['message'] = str_replace(wrap_mail_separator(), "\n\n", $mail['message']);
	$success = wrap_mail($mail);
	if ($success) {
		foreach ($used_logfiles as $logfile) unlink($logfile);
	}
	if ($old_mail_subject_prefix AND str_starts_with($old_mail_subject_prefix, '['))
		$old_mail_subject_prefix = '\\'.$old_mail_subject_prefix;
	wrap_setting('mail_subject_prefix', $old_mail_subject_prefix);
	wrap_setting_delete('brick_url_parameter'); // needs to be set later on
	
	return false;
}

/**
 * use From: address as Reply-To: address, set From: to mailbox address
 * for use with phpmailer and e. g. Exchange Online (which does not permit
 * sending from random addresses)
 *
 * @param array $headers
 * @return array
 */
function wrap_mail_reply_to($headers) {
	if (!wrap_setting('mail_reply_to')) return $headers;
	$e_mail = wrap_setting('own_e_mail');
	if (!$e_mail)
		wrap_error('System’s E-Mail address not set (setting `own_e_mail`).', E_USER_ERROR);

	if (!is_array($headers['From'])) {
		$from = [];
		list($from['e_mail'], $from['name']) = wrap_mail_split($headers['From']);
	} else {
		$from = $headers['From'];
	}
	if ($from['e_mail'] === $e_mail) return $headers;
	$headers['From'] = $from;
	if (empty($headers['Reply-To'])) {
		// might be empty string if generated via template
		if (isset($headers['Reply-To'])) $headers['Reply-To'] = [];
		$headers['Reply-To']['e_mail'] = $headers['From']['e_mail'];
		$headers['Reply-To']['name'] = $headers['From']['name'];
	}
	$headers['From']['e_mail'] = $e_mail;
	$headers['From']['name'] = wrap_setting('project');
	return $headers;
}

/**
 * check if IMAP extension is available
 *
 * @return bool
 */
function wrap_mail_imap_extension() {
	if (function_exists('imap_open')) return true;
	wrap_error('IMAP extension for PHP not installed', E_USER_WARNING);
	return false;
}

/**
 * get IMAP path
 *
 * @param string $mailbox (optional)
 * @return string
 */
function wrap_mail_imap_path($mailbox = '') {
	$path = '{%s:%s%s}%s';
	$path = sprintf($path
		, wrap_setting('mail_imap')
		, wrap_setting('mail_imap_port')
		, wrap_setting('mail_imap_flags')
		, $mailbox
	);
	return $path;
}

/**
 * copy a sent mail to the SENT folder
 *
 * @param string $message
 * @return bool
 */
function wrap_mail_sent($message) {
	if (!wrap_mail_imap_extension()) return false;
	if (!$sent = wrap_setting('mail_imap_sent_mailbox')) {
		$sent = wrap_mail_mailboxes('sent');
		if (!$sent) return false;
		wrap_setting_write('mail_imap_sent_mailbox', $sent);
	}
	$path = wrap_mail_imap_path($sent);
	$imapStream = imap_open($path, wrap_setting('mail_username'), wrap_setting('mail_password'));
    $result = imap_append($imapStream, $path, $message);
    imap_close($imapStream);
    return $result;

}

/**
 * get all mailboxes on IMAP server, return matching mailbox
 *
 * @param string $search
 * @return bool
 */
function wrap_mail_mailboxes($search) {
	if (!wrap_mail_imap_extension()) return '';
	$path = wrap_mail_imap_path();
	$imapStream = imap_open($path, wrap_setting('mail_username'), wrap_setting('mail_password'));
	$mailboxes = imap_listmailbox($imapStream, $path, '*');
	imap_close($imapStream);
	foreach ($mailboxes as $mailbox) {
		$mailbox = substr($mailbox, strpos($mailbox, '}') + 1);
		if (preg_match('/'.$search.'/i', $mailbox)) return $mailbox;
	}
	return '';
}

/**
 * open an IMAP mailbox
 *
 * @return stream
 */
function wrap_mail_mailbox_open() {
	$mailbox = sprintf('{%s:%d/imap/ssl}INBOX', wrap_setting('mail_imap'), wrap_setting('mail_imap_port'));
	$mbox = imap_open($mailbox, wrap_setting('mail_username'), wrap_setting('mail_password'));
	if (!$mbox) {
		wrap_error(wrap_text(
			'Unable to open mailbox %s (Server: %s)',
			['values' => [wrap_setting('mail_username'), wrap_setting('mail_imap')]]
		), E_USER_ERROR);
	}
	return $mbox;
}
