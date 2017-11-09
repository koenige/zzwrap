<?php 

/**
 * zzwrap
 * Default variables, post config
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2008-2017 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Default variables, post config
 *
 * @global array $zz_setting
 * @global array $zz_conf
 */
function wrap_set_defaults_post_conf() {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;

	// -------------------------------------------------------------------------
	// Internationalization, Language, Character Encoding
	// -------------------------------------------------------------------------

	if (function_exists('mb_internal_encoding')) {
		// (if PHP does not know character set, will default to
		// ISO-8859-1)
		mb_internal_encoding(strtoupper($zz_conf['character_set']));
	}

	// -------------------------------------------------------------------------
	// Request method
	// -------------------------------------------------------------------------
	
	if (empty($zz_setting['http']['allowed'])) {
		if (!wrap_is_dav_url()) {
			$zz_setting['http']['allowed'] = ['GET', 'HEAD', 'POST', 'OPTIONS'];
		} else {
			$zz_setting['http']['allowed'] = [
				'GET', 'HEAD', 'POST', 'OPTIONS', 'PUT', 'DELETE', 'PROPFIND',
				'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK'
			];
		}
	} else {
		// The following REQUEST methods must always be allowed in general:
		if (!in_array('GET', $zz_setting['http']['allowed']))
			$zz_setting['http']['allowed'][] = 'GET';
		if (!in_array('HEAD', $zz_setting['http']['allowed']))
			$zz_setting['http']['allowed'][] = 'HEAD';
	}
	if (empty($zz_setting['http']['not_allowed'])) {
		if (!wrap_is_dav_url()) {
			$zz_setting['http']['not_allowed'] = ['PUT', 'DELETE', 'TRACE', 'CONNECT'];
		} else {
			$zz_setting['http']['not_allowed'] = ['TRACE', 'CONNECT'];
		}
	}
	
	// -------------------------------------------------------------------------
	// HTTP, Hostname, Access via HTTPS or not
	// -------------------------------------------------------------------------
	
	if (empty($zz_setting['https'])) $zz_setting['https'] = false;
	// HTTPS; zzwrap authentication will always be https
	if (empty($zz_setting['https_urls'])) $zz_setting['https_urls'] = [];
	// Logout must go via HTTPS because of secure cookie
	$zz_setting['https_urls'][] = $zz_setting['logout_url'];
	$zz_setting['https_urls'][] = $zz_setting['login_url'];
	if (!empty($zz_setting['auth_urls'])) {
		$zz_setting['https_urls'] = array_merge($zz_setting['https_urls'], $zz_setting['auth_urls']);
	}
	foreach ($zz_setting['https_urls'] AS $url) {
		// check language strings
		// @todo: add support for language strings at some other position of the URL
		$languages = !empty($zz_setting['languages_allowed']) ? $zz_setting['languages_allowed'] : [];
		$languages[] = ''; // without language string should be checked always
		foreach ($languages as $lang) {
			if ($lang) $lang = '/'.$lang;
			if ($zz_setting['base'].$lang.strtolower($url) 
				== substr(strtolower($_SERVER['REQUEST_URI']), 0, strlen($zz_setting['base'].$lang.$url))) {
				$zz_setting['https'] = true;
			}
		}
	}
	// local (development) connections are never made via https
	if (!empty($zz_setting['local_access'])) {
		$zz_setting['https'] = false;
		$zz_setting['no_https'] = true;
	}
	// connections from local don't need to go via https
	// makes it easier for some things
	if ($_SERVER['REMOTE_ADDR'] === '127.0.0.1'
		OR $_SERVER['REMOTE_ADDR'] === '::1'
		OR $_SERVER['REMOTE_ADDR'] === $_SERVER['SERVER_ADDR']) {
		// don't set https to false, just allow non-https connections
		$zz_setting['ignore_scheme'] = true; 
	}
	// explicitly do not want https even for authentication (not recommended)
	if (!empty($zz_setting['no_https'])) $zz_setting['https'] = false;
	else $zz_setting['no_https'] = false;
	
	// allow to choose manually whether one uses https or not
	if (!isset($zz_setting['ignore_scheme'])) $zz_setting['ignore_scheme'] = false;
	if ($zz_setting['ignore_scheme']) 
		$zz_setting['https'] = empty($_SERVER['HTTPS']) ? false : true;

	if (!isset($zz_setting['session_secure_cookie'])) {
		$zz_setting['session_secure_cookie'] = true;
	}
	if ($zz_setting['no_https'] OR !$zz_setting['https']) {
		$zz_setting['session_secure_cookie'] = false;
	}

	if (empty($zz_setting['protocol']))
		$zz_setting['protocol'] 	= 'http'.($zz_setting['https'] ? 's' : '');
	if (empty($zz_setting['host_base'])) {
		$zz_setting['host_base'] 	= $zz_setting['protocol'].'://'.$zz_setting['hostname'];
		if (!in_array($_SERVER['SERVER_PORT'], [80, 443])) {
			$zz_setting['host_base'] .= sprintf(':%s', $_SERVER['SERVER_PORT']);
		}
	}
	
	if (empty($zz_setting['syndication_trigger_timeout_ms'])) {
		// timeout when triggering an URL with cURL
		// increase on slow servers
		$zz_setting['syndication_trigger_timeout_ms'] = 100;
	}
	
	// -------------------------------------------------------------------------
	// Paths
	// -------------------------------------------------------------------------
	
	// library
	if (empty($zz_setting['lib']))
		$zz_setting['lib']			= $zz_setting['inc'].'/library';
		
	// localized includes
	if (empty($zz_setting['custom']))	
		$zz_setting['custom'] 	= $zz_setting['inc'].'/custom';
	
	// customized cms includes
	if (empty($zz_setting['custom_wrap_dir']))	
		$zz_setting['custom_wrap_dir'] = $zz_setting['custom'].'/zzwrap';
	
	// customized sql queries, db connection
	if (empty($zz_setting['custom_wrap_sql_dir']))	
		$zz_setting['custom_wrap_sql_dir'] = $zz_setting['custom'].'/zzwrap_sql';

	// customized sql queries, db connection
	if (empty($zz_setting['custom_rights_dir']))	
		$zz_setting['custom_rights_dir'] = $zz_setting['custom'].'/zzbrick_rights';
	
	// customized sql queries, db connection
	if (empty($zz_setting['custom_wrap_template_dir']))	
		$zz_setting['custom_wrap_template_dir'] = $zz_setting['inc'].'/templates';
	
	// database connection
	if (empty($zz_setting['db_inc']))
		$zz_setting['db_inc']		= $zz_setting['custom_wrap_sql_dir'].'/db.inc.php';
	
	// modules
	if (empty($zz_setting['modules_dir'])) {
		$zz_setting['modules_dir'] = realpath($zz_setting['inc'].'/modules');
	}
	if (empty($zz_setting['modules'])) {
		$zz_setting['modules'] = [];
		if (is_dir($zz_setting['modules_dir'])) {
			$handle = opendir($zz_setting['modules_dir']);
			while ($file = readdir($handle)) {
				if (substr($file, 0, 1) === '.') continue;
				if (!is_dir($zz_setting['modules_dir'].'/'.$file)) continue;
				$zz_setting['modules'][] = $file;
			}
			closedir($handle);
		}
		// some hosters sort files in reverse order
		sort($zz_setting['modules']);
		// put default module always on top to have the possibility to
		// add functions with the same name in other modules
		if ($key = array_search('default', $zz_setting['modules'])) {
			unset($zz_setting['modules'][$key]);
			array_unshift($zz_setting['modules'], 'default');
		}
	}
	
	// cms core
	if (empty($zz_setting['core']))
		$zz_setting['core']			= __DIR__;
	
	// zzform path
	if (empty($zz_conf['dir']))
		if (file_exists($dir = $zz_setting['modules_dir'].'/zzform/zzform')) {
			$zz_conf['dir']				= $dir;
		} else {
			$zz_conf['dir']				= $zz_setting['lib'].'/zzform';
		}
	if (empty($zz_conf['dir_custom']))
		$zz_conf['dir_custom']		= $zz_setting['custom'].'/zzform';
	if (empty($zz_conf['dir_inc']))
		$zz_conf['dir_inc']			= $zz_conf['dir'];
	
	// zzform db scripts
	if (empty($zz_conf['form_scripts']))
		$zz_conf['form_scripts']	= $zz_setting['custom'].'/zzbrick_tables';
	
	// local pwd
	if (empty($zz_setting['local_pwd']))
		$zz_setting['local_pwd'] = "/Users/pwd.inc";

	if (empty($zz_setting['session_save_path']) AND is_dir($zz_conf['tmp_dir'])) {
		$zz_setting['session_save_path'] = $zz_conf['tmp_dir'].'/sessions';
	}
	
	// cainfo
	// Certficates are bundled with CURL from 7.10 onwards, PHP 5 requires at least 7.10
	// so there should be currently no need to include an own PEM file
	// if (empty($zz_setting['cainfo_file']))
	//	$zz_setting['cainfo_file'] = __DIR__.'/cacert.pem';
	
	// -------------------------------------------------------------------------
	// Page
	// -------------------------------------------------------------------------
	
	// translations
	if (!isset($zz_conf['translations_of_fields']))
		$zz_conf['translations_of_fields'] = false;
	
	// breadcrumbs
	if (!isset($zz_page['breadcrumbs_separator']))
		$zz_page['breadcrumbs_separator'] = '&gt;';
	
	// page title and project title
	if (!isset($zz_page['template_pagetitle']))
		$zz_page['template_pagetitle'] = '%1$s (%2$s)';
	if (!isset($zz_page['template_pagetitle_home']))
		$zz_page['template_pagetitle_home'] = '%1$s';
	
	// allowed HTML rel attribute values
	if (!isset($zz_setting['html_link_types'])) {
		$zz_setting['html_link_types'] = [
			'Alternate', 'Stylesheet', 'Start', 'Next', 'Prev', 'Contents',
			'Index', 'Glossary', 'Copyright', 'Chapter', 'Section',
			'Subsection', 'Appendix', 'Help', 'Bookmark'
		];
	}
	
	// XML mode? for closing tags
	if (!isset($zz_setting['xml_close_empty_tags']))
		$zz_setting['xml_close_empty_tags'] = false;
	
	// Page template
	if ($zz_setting['brick_page_templates'] AND empty($zz_page['template'])) {
		$zz_page['template'] = 'page';
	}
	
	// HTML paths, relative to DOCUMENT_ROOT
	if (empty($zz_setting['layout_path']))
		$zz_setting['layout_path'] = '/_layout';
	if (empty($zz_setting['behaviour_path']))
		$zz_setting['behaviour_path'] = '/_behaviour';
	if (empty($zz_setting['files_path']))
		$zz_setting['files_path'] = '/files';

	// zzform: local zzform-colours.css?		
	if (!isset($zz_setting['zzform_colours'])) {
		$zz_setting['zzform_colours'] = true;
	}

	// -------------------------------------------------------------------------
	// Page paths
	// -------------------------------------------------------------------------
	
	if (!$zz_setting['brick_page_templates']) {
		// page head
		if (empty($zz_page['head']))
			$zz_page['head']		= $zz_setting['custom_wrap_dir'].'/html-head.inc.php';
		// page foot
		if (empty($zz_page['foot']))			
			$zz_page['foot']		= $zz_setting['custom_wrap_dir'].'/html-foot.inc.php';
	}
	
	// -------------------------------------------------------------------------
	// Debugging
	// -------------------------------------------------------------------------
	
	if (!isset($zz_conf['debug']))
		$zz_conf['debug']			= false;
	
	// -------------------------------------------------------------------------
	// Database structure
	// -------------------------------------------------------------------------
	
	if (!isset($zz_conf['relations_table']))
		$zz_conf['relations_table']	= '/*_PREFIX_*/_relations';
	
	if (!empty($zz_conf['logging']) AND !isset($zz_conf['logging_table']))
		$zz_conf['logging_table']	= '/*_PREFIX_*/_logging';
	
	if (!empty($zz_conf['translations_of_fields']) AND empty($zz_conf['translations_table']))
		$zz_conf['translations_table']  = '/*_PREFIX_*/_translationfields';

	if (!empty($zz_conf['revisions'])) {
		if (!isset($zz_conf['revisions_table']))
			$zz_conf['revisions_table']	= '/*_PREFIX_*/_revisions';
		if (!isset($zz_conf['revisions_data_table']))
			$zz_conf['revisions_data_table']	= '/*_PREFIX_*/_revisiondata';
	}
	
	
	// -------------------------------------------------------------------------
	// Error Logging
	// -------------------------------------------------------------------------
	
	if (!isset($zz_conf['error_log']['error']))
		$zz_conf['error_log']['error']	= ini_get('error_log');
	
	if (!isset($zz_conf['error_log']['warning']))
		$zz_conf['error_log']['warning']	= ini_get('error_log');
	
	if (!isset($zz_conf['error_log']['notice']))
		$zz_conf['error_log']['notice']	= ini_get('error_log');
	
	if (!isset($zz_conf['log_errors']))
		$zz_conf['log_errors'] 			= ini_get('log_errors');
	
	if (!isset($zz_conf['log_errors_max_len']))
		$zz_conf['log_errors_max_len'] 	= ini_get('log_errors_max_len');
	
	if (!isset($zz_conf['translate_log_encodings']))
		$zz_conf['translate_log_encodings'] = [
			'iso-8859-2' => 'iso-8859-1'
		];
	if (!isset($zz_conf['error_log_post']))
		$zz_conf['error_log_post']	= false;
	
	if (!isset($zz_conf['error_mail_parameters']) AND isset($zz_conf['error_mail_from']))
		$zz_conf['error_mail_parameters'] = '-f '.$zz_conf['error_mail_from'];
	
	
	// -------------------------------------------------------------------------
	// Authentication
	// -------------------------------------------------------------------------
	
	if (!isset($zz_setting['authentication_possible']))
		$zz_setting['authentication_possible'] = true;
	
	if ($zz_setting['local_access']) {
		$zz_setting['logout_inactive_after'] *= 20;
	}
	
	
	// -------------------------------------------------------------------------
	// Encryption
	// -------------------------------------------------------------------------
	
	if (empty($zz_conf['hash_password'])) 
		$zz_conf['hash_password'] = 'password_hash';

	// Base-2 logarithm of the iteration count used for password stretching
	if (!isset($zz_conf['hash_cost_log2']))
		$zz_conf['hash_cost_log2'] = 11;
	
	if (in_array($zz_conf['hash_password'], ['phpass', 'phpass-md5'])) {
		// Do we require the hashes to be portable to older systems (less secure)?
		if (!isset($zz_conf['hash_portable']))
			$zz_conf['hash_portable'] = FALSE;
		
		// path to script
		if (!isset($zz_conf['hash_script']))
			$zz_conf['hash_script'] = $zz_setting['lib'].'/phpass/PasswordHash.php';
	}
	
	// -------------------------------------------------------------------------
	// Mail
	// -------------------------------------------------------------------------
	
	if (!isset($zz_setting['mail_header_eol'])) {
		// mail header lines should be separated by \r\n
		// some postfix versions handle mail internally with \n and
		// replace \n with \r\n for outgoing mail, ending with \r\r\n = CRCRLF
		$zz_setting['mail_header_eol'] = "\r\n";
	}

	// -------------------------------------------------------------------------
	// Libraries
	// -------------------------------------------------------------------------

	if (empty($zz_setting['ext_libraries']) OR !in_array('zzbrick', $zz_setting['ext_libraries'])) {
		$zz_setting['ext_libraries'][] = 'zzbrick';
	}

}

/**
 * tests some expected environment settings
 *
 * @return bool
 */
function wrap_tests() {
	global $zz_setting;
	// check if cache directory exists
	if (!empty($zz_setting['cache'])) {
		if (!file_exists($zz_setting['cache_dir'])) {
			wrap_error(sprintf('Cache directory %s does not exist. Caching disabled.', 
				$zz_setting['cache_dir']), E_USER_WARNING);
			$zz_setting['cache'] = '';
		}
		// @todo: not is writable
	}
	return true;
}

/**
 * check if a URL is inside a WebDAV library and will not be handled by zzproject
 *
 * @global array $zz_setting
 * @return bool
 */
function wrap_is_dav_url() {
	global $zz_setting;
	if (empty($zz_setting['dav_url'])) return false;
	if (wrap_substr($_SERVER['REQUEST_URI'], $zz_setting['dav_url'])) {
		return true;
	}
	return false;
}
