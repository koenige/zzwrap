<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2009
// Language functions


$language = (!empty($zz_setting['lang']) ? $zz_setting['lang'] : $zz_conf['language']);

// standard text english
if (file_exists($zz_setting['custom_wrap_dir'].'/text-en.inc.php')) 
	include $zz_setting['custom_wrap_dir'].'/text-en.inc.php';

// default translated text
if (file_exists($zz_setting['core'].'/default-text-'.$language.'.inc.php'))
	include $zz_setting['core'].'/default-text-'.$language.'.inc.php';

// standard translated text
if (file_exists($zz_setting['custom_wrap_dir'].'/text-'.$language.'.inc.php'))
	include $zz_setting['custom_wrap_dir'].'/text-'.$language.'.inc.php';

global $text;

// duplicate function zz_text here

/** Translate text if possible or write back text string to be translated
 * 
 * @param $string		Text string to be translated
 * @return $string		Translation of text
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_text($string) {
	global $text;
	global $zz_conf;
	if (empty($text[$string])) {
		// write missing translation to somewhere.
		// TODO: check logfile for duplicates
		// TODO: optional log directly in database
		if (!empty($zz_conf['log_missing_text'])) {
			$log_message = '$text["'.addslashes($string).'"] = "'.$string.'";'."\n";
			$log_file = sprintf($zz_conf['log_missing_text'], $zz_conf['language']);
			error_log($log_message, 3, $log_file);
			chmod($log_file, 0664);
		}
		return $string;
	} else
		return $text[$string];
}

?>