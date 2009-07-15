<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2009
// Error handling


/*

	possible parameters
	$zz_conf['error_mail_to'] = <email>
	$zz_conf['error_mail_from'] = <email>
	$zz_conf['error_handling'] = <email>
	
*/
function zz_errorhandling($msg, $errorcode) {
	global $zz_setting;
	global $zz_conf;

	if (!empty($zz_conf['debug']))
		$zz_conf['error_handling'] = 'screen';
	if (empty($zz_conf['error_handling']))
		$zz_conf['error_handling'] = false;

	switch ($zz_conf['error_handling']) {
	case 'mail':
		$email_head = 'From: "'.$zz_conf['project'].'" <'.$zz_conf['error_mail_from'].'>
MIME-Version: 1.0
Content-Type: text/plain; charset='.$zz_conf['character_set'].'
Content-Transfer-Encoding: 8bit';
		mail($zz_conf['error_mail_to'], '['.$zz_conf['project'].'] '.(function_exists('cms_text') ? cms_text('Error on website') : 'Error on website'), 
			$msg, $email_head, '-f '.$zz_conf['error_mail_from']);
		break;
	case 'screen':
		echo '<pre>';
		echo $msg; //str_replace("\n", "<br>", $msg);
		echo '</pre>';
		break;
	default:
	}
	switch ($errorcode) {
	case E_USER_WARNING:	// acceptable error, go on
		break;
	case E_USER_NOTICE:		// unimportant error only show in debug mode
		break;
	case E_USER_ERROR:	// critical error: stop!
	 	quit_cms(503);
	default:
	}
	
}

?>