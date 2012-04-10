<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2011
// CMS core functions


/**
 * Test, whether URL contains a correct secret key to allow page previews
 * 
 * @param string $secret_key shared secret key
 * @param string $_GET['tle'] timestamp, begin of legitimate timeframe
 * @param string $_GET['tld'] timestamp, end of legitimate timeframe
 * @param string $_GET['tlh'] hash
 * @return bool $wrap_page_preview true|false i. e. true means show page, false don't
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @todo replace with wrap_check_hash()
 */
function wrap_test_secret_key($secret_key) {
	$wrap_page_preview = false;
	if (empty($_GET['tle'])) return false;
	if (empty($_GET['tld'])) return false;
	if (empty($_GET['tlh'])) return false;
	if (time() > $_GET['tle'] && time() < $_GET['tld'] && 
		$_GET['tlh'] == md5($_GET['tle'].'&'.$_GET['tld'].'&'.$secret_key)) {
		wrap_session_start();
		$_SESSION['wrap_page_preview'] = true;
		$wrap_page_preview = true;
	}
	return $wrap_page_preview;
}

/**
 * will start a session with some parameters set before
 *
 * @return bool
 */
function wrap_session_start() {
	global $zz_setting;
	global $zz_page;
	
	// is already a session active?
	if (session_id()) return false;
	// Cookie: httpOnly, i. e. no access for JavaScript if browser supports this
	$last_error = false;
	if (version_compare(PHP_VERSION, '5.2.0', '>=')) {
		session_set_cookie_params(0, '/', $zz_setting['hostname'], false, true);
		$last_error = error_get_last();
	}
	$success = session_start();
	if (version_compare(PHP_VERSION, '5.2.0', '>=')) {
		// only throw 503 error if authentication is a MUST HAVE
		// otherwise, page might still be accessible without authentication
		if (wrap_authenticate_url($zz_page['url']['full']['path'], $zz_setting['no_auth_urls'])) {
			$session_error = error_get_last();
			if ($last_error != $session_error
				AND wrap_substr($session_error['message'], 'session_start()')) {
				wrap_quit(503, wrap_text('Temporarily, a login is not possible.'));
			}
		}
	}
	if (!$success) wrap_quit(503, 'Temporarily, a login is not possible.');
	return true;
}

/**
 * Checks current URL against no auth URLs
 *
 * @param string $url URL from database
 * @param array $no_auth_urls ($zz_setting['no_auth_urls'])
 * @return bool true if authentication is required, false if not
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_authenticate_url($url, $no_auth_urls) {
	foreach ($no_auth_urls AS $test_url) {
		if (substr($url, 0, strlen($test_url)) == $test_url) {
			return false; // no authentication required
		}
	}
	return true; // no matches: authentication required
}

/**
 * Tests whether URL is in database (or a part of it ending with *), or a part 
 * of it with placeholders
 * 
 * @param array $zz_page
 * @global array $zz_conf zz configuration variables
 * @global array $zz_setting
 * @return array $page
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_look_for_page($zz_page) {
	// no database connection or settings are missing
	if (!wrap_sql('pages')) wrap_quit(503); 

	global $zz_setting;
	global $zz_conf;
	$page = false;

	// Prepare URL for database request
	$url = wrap_read_url($zz_page['url']);
	$full_url[0] = $url['db'];

	list($full_url, $leftovers) = wrap_look_for_placeholders($zz_page, $full_url);
	
	// For request, remove ending (.html, /), but not for page root
	foreach ($full_url as $i => $my_url) {
		// if more than one URL to be tested against: count of rounds
		$loops[$i] = 0;
		$page[$i] = false;
		$parameter[$i] = false;
		while (!$page[$i]) {
			$loops[$i]++;
			$sql = sprintf(wrap_sql('pages'), '/'.wrap_db_escape($my_url));
			if (!wrap_rights('preview')) $sql.= ' AND '.wrap_sql('is_public');
			$page[$i] = wrap_db_fetch($sql);
			if (empty($page[$i]) && strstr($my_url, '/')) {
				// if not found, remove path parts from URL
				if ($parameter[$i]) {
					$parameter[$i] = '/'.$parameter[$i]; // '/' as a separator for variables
					$my_url = substr($my_url, 0, -1); // remove '*'
				}
				$parameter[$i] = substr($my_url, strrpos($my_url, '/') +1).$parameter[$i];
				$my_url = substr($my_url, 0, strrpos($my_url, '/')).'*';
			} else {
				// something was found, get out of here
				// but get placeholders as parameters as well!
				if (!empty($leftovers[$i])) 
					$parameter[$i] = implode('/', $leftovers[$i]).($parameter[$i] ? '/'.$parameter[$i] : '');
				$url[$i] = $my_url;
				break;
			}
		}
		if (!$page[$i]) unset($loops[$i]);
	}
	if (empty($loops)) return false;
	
	// get best match, sort twice:
	// 1. get match with least loops
	// 2. get match with lowest index of loops
	asort($loops);
	asort($loops);
	$i = key($loops);
	$page = $page[$i];
	if (!$page) return false;

	$page['parameter'] = $parameter[$i];
	$page['url'] = $url[$i];
	return $page;
}

/**
 * check for placeholders in URL
 *
 * replaces parts that match with placeholders, if necessary multiple times
 * note: twice the same fragment will only be replaced once, not both fragments
 * at the same time (e. g. /eng/eng/ is /%language%/eng/ and /eng/%language%/
 * but not /%language%/%language%/ because this would not make sense) 
 * @param array $zz_page
 * @param array $full_url
 * @return array (array $full_url, array $leftovers)
 */
function wrap_look_for_placeholders($zz_page, $full_url) {
	if (empty($zz_page['url_placeholders'])) return array($full_url, array());
	// cut url in parts
	$url_parts[0] = explode('/', $full_url[0]);
	$i = 1;
	$leftovers = array();
	foreach ($zz_page['url_placeholders'] as $wildcard => $values) {
		foreach (array_keys($values) as $key) {
			foreach ($url_parts as $url_index => $parts) {
				foreach ($parts as $partkey => $part) {
					if ($part != $key) continue;
					// new URL parts, take the one that we match on as basis
					$url_parts[$i] = $url_parts[$url_index];
					// leftovers, get the ones as a basis we already have							
					if (!empty($leftovers[$url_index]))
						$leftovers[$i] = $leftovers[$url_index];
					// take current part and put it into leftovers
					$leftovers[$i][$partkey] = $url_parts[$i][$partkey];
					// overwrite current part with placeholder
					$url_parts[$i][$partkey] = '%'.$wildcard.'%';
					$full_url[$i] = implode('/', $url_parts[$i]); 
					$i++;
				}
			}
		}
	}
	return array($full_url, $leftovers);
}

/**
 * Make canonical URLs
 * 
 * @param array $zz_page
 * @param array $page
 * @return array $url
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_check_canonical($zz_page, $page) {
	global $zz_setting;
	// if database allows field 'ending', check if the URL is canonical
	if (!empty($zz_page['db'][wrap_sql('ending')])) {
		$ending = $zz_page['db'][wrap_sql('ending')];
		// if brick_format() returns a page ending, use this
		if (isset($page['url_ending'])) $ending = $page['url_ending'];
		$zz_page['url'] = wrap_check_canonical_ending($ending, $zz_page['url']);
	}
	if ($zz_page['url']['full']['path'] === '//') {
		$zz_page['url']['full']['path'] = '/';
		$zz_page['url']['redirect'] = true;
	}
	$types = array('query_strings', 'query_strings_redirect');
	foreach ($types as $type) {
		// initialize
		if (empty($page[$type])) $page[$type] = array();
		// merge from settings
		if (!empty($zz_setting[$type])) {
			$page[$type] = array_merge($page[$type], $zz_setting[$type]);
		}
	}
	// set some query strings which are used by zzwrap
	$page['query_strings'] = array_merge($page['query_strings'],
		array('no-cookie', 'tle', 'tld', 'tlh', 'lang', 'code', 'url', 'logout'));
	if (!empty($zz_page['url']['full']['query'])) {
		parse_str($zz_page['url']['full']['query'], $params);
		foreach (array_keys($params) as $param) {
			if (in_array($param, $page['query_strings'])) continue;
			$param_value = $params[$param];
			unset($params[$param]);
			$zz_page['url']['redirect'] = true;
			// no error logging for query strings which shall be redirected
			if (in_array($param, $page['query_strings_redirect'])) continue;
			wrap_error(sprintf('Wrong URL: query string %s=%s [%s]', $param, $param_value, $_SERVER['REQUEST_URI']), E_USER_NOTICE);
		}
		$zz_page['url']['full']['query'] = http_build_query($params);
	}
	return $zz_page['url'];
}

/**
 * Make canonical URLs, here: endings (trailing slash, .html etc.)
 * 
 * @param string $ending ending of URL (/, .html, .php, none)
 * @param array $url ($zz_page['url'])
 * @return array $url, with new 'path' and 'redirect' set to 1 if necessary
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_check_canonical_ending($ending, $url) {
	$new = false;
	switch ($ending) {
	case '/':
		if (substr($url['full']['path'], -5) == '.html') {
			$new = substr($url['full']['path'], 0, -5);
		} elseif (substr($url['full']['path'], -4) == '.php') {
			$new = substr($url['full']['path'], 0, -4);
		} elseif (substr($url['full']['path'], -1) != '/') {
			$new = $url['full']['path'];
		}
		if ($new) $new .= '/';
		break;
	case '.html':
		if (substr($url['full']['path'], -1) == '/') {
			$new = substr($url['full']['path'], 0, -1);
		} elseif (substr($url['full']['path'], -4) == '.php') {
			$new = substr($url['full']['path'], 0, -4);
		} elseif (substr($url['full']['path'], -5) != '.html') {
			$new = $url['full']['path'];
		}
		if ($new) $new .= '.html';
		break;
	case 'none':
	case 'keine':
		if (substr($url['full']['path'], -5) == '.html') {
			$new = substr($url['full']['path'], 0, -5);
		} elseif (substr($url['full']['path'], -1) == '/' AND strlen($url['full']['path']) > 1) {
			$new = substr($url['full']['path'], 0, -1);
		} elseif (substr($url['full']['path'], -4) == '.php') {
			$new = substr($url['full']['path'], 0, -4);
		}
		break;
	}
	if (!$new) return $url;

	$url['redirect'] = true;
	$url['full']['path'] = $new;
	return $url;
}

/**
 * builds URL from REQUEST
 * 
 * @param array $url $url['full'] with result from parse_url
 * @return array $url with new keys ['db'] (URL in database), ['suffix_length']
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_read_url($url) {
	// better than mod_rewrite, because '&' won't always be treated correctly
	$url['db'] = $url['full']['path'];
	$url['suffix_length'] = !empty($_GET['lang']) ? strlen($_GET['lang']) + 6 : 5;
	// cut '/' at the beginning and - if necessary - at the end
	if (substr($url['db'], 0, 1) == '/') $url['db'] = substr($url['db'], 1);
	if (substr($url['db'], -1) == '/') $url['db'] = substr($url['db'], 0, -1);
	if (substr($url['db'], -5) == '.html') $url['db'] = substr($url['db'], 0, -5);
	if (substr($url['db'], -4) == '.php') $url['db'] = substr($url['db'], 0, -4);
	if (!empty($_GET['lang']))
		if (substr($url['db'], -$url['suffix_length']) == '.html.'.$_GET['lang']) 
			$url['db'] = substr($url['db'], 0, -$url['suffix_length']);
	return $url;
}

/**
 * Stops execution of script, check for redirects to other pages,
 * includes http error pages
 * 
 * The execution of the CMS will be stopped. The script test if there's
 * an entry for the URL in the redirect table to redirect to another page
 * If that's true, 301 or 302 codes redirect pages, 410 redirect to gone.
 * if no error code is defined, a 404 code and the corresponding error page
 * will be shown
 * @param int $errorcode HTTP Error Code, default value is 404
 * @param string $error_msg (optional, error message for user)
 * @param array $page (optional, if normal output shall be shown, not error msg)
 * @return exits function with a redirect or an error document
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_quit($errorcode = 404, $error_msg = '', $page = array()) {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;

	$redir = wrap_check_redirects($zz_page['url']);
	if (!$redir) $page['status'] = $errorcode; // we need this in the error script
	else $page['status'] = $redir['code'];

	// Check redirection code
	switch ($page['status']) {
	case 301:
	case 302:
	case 303:
	case 307:
		// (header 302 is sent automatically if using Location)
		wrap_http_status_header($page['status']);
		$field_name = wrap_sql('redirects_new_fieldname');
		$new = parse_url($redir[$field_name]);
		if (!empty($new['scheme'])) {
			$new = $redir[$field_name];
		} else {
			$new = $zz_setting['host_base'].$zz_setting['base'].$redir[$field_name];
		}
		header("Location: ".$new);
		break;
	default: // 4xx, 5xx
		if ($error_msg) {
			if (empty($zz_page['error_msg'])) $zz_page['error_msg'] = '';
			if (empty($zz_page['error_html']))
				$zz_page['error_html'] = '<p class="error">%s</p>';
			$zz_page['error_msg'] .= sprintf($zz_page['error_html'], $error_msg);
		}
		wrap_errorpage($page, $zz_page);
	}
	exit;
}

/**
 * sends a HTTP status header corresponding to server settings and HTTP version
 *
 * @param int $code
 * @return bool true if header was sent, false if not
 * @see zz_http_status_header() (duplicate function)
 */
function wrap_http_status_header($code) {
	// Set protocol
	$protocol = $_SERVER['SERVER_PROTOCOL'];
	if (!$protocol) $protocol = 'HTTP/1.0'; // default value
	if (substr(php_sapi_name(), 0, 3) == 'cgi') $protocol = 'Status:';
	
	switch ($code) {
	case '301':
		header($protocol." 301 Moved Permanently");
		return true;
	case '302':
		if ($protocol == 'HTTP/1.0')
			header($protocol." 302 Moved Temporarily");
		else
			header($protocol." 302 Found");
		return true;
	case '303':
		if ($protocol == 'HTTP/1.0')
			header($protocol." 302 Moved Temporarily");
		else
			header($protocol." 303 See Other");
		return true;
	case '304':
		header($protocol." 304 Not Modified");
		return true;
	case '307':
		if ($protocol == 'HTTP/1.0')
			header($protocol." 302 Moved Temporarily");
		else
			header($protocol." 307 Temporary Redirect");
		return true;
	}
	return false;
}

/**
 * check for redirects, if there's a corresponding table.
 *
 * @param array $page_url = $zz_page['url']
 * @global array $zz_setting
 * @global array $zz_page
 * @return mixed (bool false: no redirect; array: fields needed for redirect)
 */
function wrap_check_redirects($page_url) {
	global $zz_setting;
	global $zz_page;

	if (empty($zz_setting['check_redirects'])) return false;
	$url = wrap_read_url($zz_page['url']);
	$url['db'] = wrap_db_escape($url['db']);
	$where_language = (!empty($_GET['lang']) 
		? ' OR '.wrap_sql('redirects_old_fieldname').' = "/'
			.$url['db'].'.html.'.wrap_db_escape($_GET['lang']).'"'
		: ''
	);
	$sql = sprintf(wrap_sql('redirects'), '/'.$url['db'], '/'.$url['db'], '/'.$url['db'], $where_language);
	// not needed anymore, but set to false hinders from getting into a loop
	// (wrap_db_fetch() will call wrap_quit() if table does not exist)
	$zz_setting['check_redirects'] = false; 
	$redir = wrap_db_fetch($sql);
	if ($redir) return $redir;

	// If no redirect was found until now, check if there's a redirect above
	// the current level with a placeholder (*)
	$parameter = false;
	$found = false;
	$break_next = false;
	while (!$found) {
		$sql = sprintf(wrap_sql('redirects_*'), '/'.$url['db']);
		$redir = wrap_db_fetch($sql);
		if ($redir) break; // we have a result, get out of this loop!
		if (strrpos($url['db'], '/'))
			$parameter = '/'.substr($url['db'], strrpos($url['db'], '/')+1).$parameter;
		$url['db'] = substr($url['db'], 0, strrpos($url['db'], '/'));
		if ($break_next) break; // last round
		if (!strstr($url['db'], '/')) $break_next = true;
	}
	if (!$redir) return false;
	// If there's an asterisk (*) at the end of the redirect
	// the cut part will be pasted to the end of the string
	$field_name = wrap_sql('redirects_new_fieldname');
	if (substr($redir[$field_name], -1) == '*')
		$redir[$field_name] = substr($redir[$field_name], 0, -1).$parameter;
	return $redir;
}

/**
 * Checks if HTTP request should be HTTPS request instead and vice versa
 * 
 * Function will redirect request to the same URL except for the scheme part
 * Attention: POST variables will get lost
 * @param array $zz_page Array with full URL in $zz_page['url']['full'], 
 *		this is the result of parse_url()
 * @param array $zz_setting settings, 'ignore_scheme' ignores redirect
 *		and 'protocol' defines the protocol wanted (http or https)
 * @return redirect header
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_check_https($zz_page, $zz_setting) {
	// if it doesn't matter, get out of here
	if ($zz_setting['ignore_scheme']) return true;

	// change from http to https or vice versa
	// attention: $_POST will not be preserved
	if (!empty($_SERVER['HTTPS']) AND $_SERVER['HTTPS'] === 'on') {
		if ($zz_setting['protocol'] === 'https') return true;
	} else {
		if ($zz_setting['protocol'] === 'http') return true;
	}
	header('Location: '.$zz_setting['protocol'].'://'.$zz_page['url']['full']['host']
		.$zz_setting['base'].$zz_page['url']['full']['path']
		.(!empty($zz_page['url']['full']['query']) ? '?'.$zz_page['url']['full']['query'] : ''));
	exit;
}

/**
 * Puts data from request into template and returns full page
 *
 * @param string $template Name of template that will be filled
 * @param array $data Data which will be used to fill the template
 * @param string $mode
 *		'ignore position': ignores position, returns a string instead of an array
 *		'error': returns simple template, with placeholders
 * @return mixed $text (string or array indexed by positions)
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_template($template, $data = array(), $mode = false) {
	global $zz_setting;

	// Template einbinden und füllen
	$tpl = $zz_setting['custom_wrap_template_dir'].'/'.$template.'.template.txt';
	if (!file_exists($tpl)) {
		// check if there's a default template
		$tpl = $zz_setting['wrap_template_dir'].'/'.$template.'.template.txt';
		if (!file_exists($tpl)) {
			wrap_quit(503, sprintf(wrap_text('Template %s does not exist.'), htmlspecialchars($template)));
		}
	}
	$zz_setting['current_template'] = $template;
	$template = file($tpl);
	// remove comments and next empty line from the start
	foreach ($template as $index => $line) {
		if (substr($line, 0, 1) == '#') unset($template[$index]); // comments
		elseif (!trim($line)) unset($template[$index]); // empty lines
		else break;
	}
	$template = implode("", $template);
	// now we have the template as string, in case of error, return
	if ($mode === 'error') return $template;

	// replace placeholders in template
	// save old setting regarding text formatting
	if (!isset($zz_setting['brick_fulltextformat'])) 
		$zz_setting['brick_fulltextformat'] = '';
	$old_brick_fulltextformat = $zz_setting['brick_fulltextformat'];
	// apply new text formatting
	$zz_setting['brick_fulltextformat'] = 'brick_textformat_html';
	$page = brick_format($template, $data);
	// restore old setting regarding text formatting
	$zz_setting['brick_fulltextformat'] = $old_brick_fulltextformat;

	// get rid of if / else text that will be put to hidden
	if (count($page['text']) == 2 
		AND is_array($page['text'])
		AND in_array('_hidden_', array_keys($page['text']))
		AND in_array($zz_setting['brick_default_position'], array_keys($page['text']))) {
		unset($page['text']['_hidden_']);
		$page['text'] = end($page['text']);
	}
	if ($mode === 'ignore positions' AND is_array($page['text']) AND count($page['text']) == 1) {
		$page['text'] = current($page['text']);
	}
	// check if errors occured while filling in the template
	wrap_page_check_if_error($page);
	return $page['text'];
}

/**
 * Creates valid HTML id value from string
 *
 * @param string $id_title string to be formatted
 * @return string $id_title
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_create_id($id_title) {
	$not_allowed_in_id = array('(', ')');
	foreach ($not_allowed_in_id as $char) {
		$id_title = str_replace($char, '', $id_title);
	}
	$id_title = strtolower(forceFilename($id_title));
	return $id_title;
}

/**
 * sends a file to the browser from a directory below document root
 *
 * @param array $file
 *		'name' => string full filename; 'etag' string (optional) ETag-value for 
 *		header; 'cleanup' => bool if file shall be deleted after sending it;
 *		'cleanup_folder' => string name of folder if it shall be deleted as well
 *		'send_as' => send filename under a different name (default: basename)
 *		'error_code' => HTTP error code to send in case of file not found error
 *		'error_msg' => additional error message that appears on error page,
 *		'etag_generate_md5' => creates 'etag' if not send with MD5
 * @global array $zz_conf
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @todo send pragma public header only if browser that is affected by this bug
 * @todo implement Ranges for bytes
 */
function wrap_file_send($file) {
	global $zz_conf;
	if (!file_exists($file['name'])) {
		if (!empty($file['error_code'])) {
			if (!empty($file['error_msg'])) {
				global $zz_page;
				$zz_page['error_msg'] = $file['error_msg'];
			}
			wrap_quit($file['error_code']);
		}
		wrap_file_cleanup($file);
		return false;
	}
	if (empty($file['send_as'])) $file['send_as'] = basename($file['name']);
	$suffix = substr($file['name'], strrpos($file['name'], ".") +1);

	$filesize = sprintf("%u", filesize($file['name']));
	// Maybe the problem is we are running into PHPs own memory limit, so:
	if ($filesize + 1 > wrap_return_bytes(ini_get('memory_limit')) && intval($filesize * 1.5) <= 1073741824) { //Not higher than 1GB
		ini_set('memory_limit', intval($filesize * 1.5));
	}

	// Canonicalize suffices
	$suffix_map = array(
		'jpg' => 'jpeg',
		'tif' => 'tiff'
	);
	if (in_array($suffix, array_keys($suffix_map))) $suffix = $suffix_map[$suffix];

	// Read mime type from database
	$sql = sprintf(wrap_sql('filetypes'), $suffix);
	$mimetype = wrap_db_fetch($sql, '', 'single value');
	if (!$mimetype) $mimetype = 'application/octet-stream';

	// Remove some HTTP headers PHP might send because of SESSION
	// do some tests if this is okay
	header('Expires: ');
	header('Cache-Control: ');
	header('Pragma: ');

	// generate etag
	if (!empty($file['etag_generate_md5']) AND empty($file['etag'])) {
		$file['etag'] = md5_file($file['name']);
	}
	// Check for 304 or send ETag header
	if (!empty($file['etag'])) {
		$file['etag'] = wrap_if_none_match($file['etag'], $file);
		header("ETag: ".$file['etag']);
	}

	// Check for 304 and send Last-Modified header
	$last_modified = wrap_if_modified_since(filemtime($file['name']), $file);
 	header("Last-Modified: ".$last_modified);

	// Send HTTP headers
	header("Content-Length: " . $filesize);
	header("Content-Type: ".$mimetype);
	// TODO: ordentlichen Expires-Header setzen, je nach Dateialter

	// Download files if generic mimetype
	// or HTML, since this might be of unknown content with javascript or so
	$download_filetypes = array('application/octet-stream', 'application/zip', 
		'text/html', 'application/xhtml+xml');
	if (in_array($mimetype, $download_filetypes)) {
		header('Content-Disposition: attachment; filename="'.$file['send_as'].'"');
			// d. h. bietet save as-dialog an, geht nur mit application/octet-stream
		header('Pragma: public');
			// dieser Header widerspricht im Grunde dem mit SESSION ausgesendeten
			// Cache-Control-Header
			// Wird aber für IE 5, 5.5 und 6 gebraucht, da diese keinen Dateidownload
			// erlauben, wenn Cache-Control gesetzt ist.
			// http://support.microsoft.com/kb/323308/de
	} else {
		header('Content-Disposition: inline; filename="'.$file['send_as'].'"');
	}

	if (stripos($_SERVER['REQUEST_METHOD'], 'HEAD') !== FALSE) {
		wrap_file_cleanup($file);
		// we do not need to resend file
		exit;
	}

	// following block and server lighttpd: replace with
	// header('X-Sendfile: '.$file['name']);

	// If it's a large file we don't want the script to timeout, so:
	set_time_limit(300);
	// If it's a large file, readfile might not be able to do it in one go, so:
	$chunksize = 1 * (1024 * 1024); // how many bytes per chunk
	if ($filesize > $chunksize) {
		$handle = fopen($file['name'], 'rb');
		$buffer = '';
		ob_start();
		while (!feof($handle)) {
			$buffer = fread($handle, $chunksize);
			echo $buffer;
			ob_flush();
			flush();
		}
		fclose($handle);
	} else {
		readfile($file['name']);
	}

	wrap_file_cleanup($file);
	exit;
}

/**
 * returns integer byte value from PHP shorthand byte notation
 *
 * @param string $val
 * @return int
 * @see zz_return_bytes(), identical
 */
function wrap_return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    switch($last) {
        // The 'G' modifier is available since PHP 5.1.0
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }

    return $val;
}

/**
 * does cleanup after a file was sent
 *
 * @param array $file
 * @return bool
 */
function wrap_file_cleanup($file) {
	if (empty($file['cleanup'])) return false;
	// clean up
	unlink($file['name']);
	if (!empty($file['cleanup_dir'])) rmdir($file['cleanup_dir']);
	return true;
}

/**
 * checks the HTTP request made, builds URL
 * sets language according to URL and request
 *
 * @global array $zz_conf
 * @global array $zz_setting
 * @global array $zz_page
 */
function wrap_check_request() {
	global $zz_conf;
	global $zz_setting;
	global $zz_page;

	// check REQUEST_URI
	// Base URL, allow it to be set manually (handle with care!)
	// e. g. for Content Management Systems without mod_rewrite or websites in subdirectories
	if (empty($zz_page['url']['full'])) {
		$zz_page['url']['full'] = parse_url($zz_setting['host_base'].$_SERVER['REQUEST_URI']);
		// in case, some script requests GET ? HTTP/1.1 or so:
		if (empty($zz_page['url']['full']['path'])) {
			$zz_page['url']['full']['path'] = '/';
			$zz_page['url']['redirect'] = true;
		}
	}

	// check REQUEST_METHOD, quit if inappropriate
	// $zz_page['url'] needed for wrap_quit()
	wrap_check_http_request_method();

	// get rid of unwanted query strings, set redirect if necessary
	$zz_page['url'] = wrap_remove_query_strings($zz_page['url']);

	// check language
	wrap_set_language();

	// Relative linking
	if (empty($zz_page['deep'])) {
		if (!empty($zz_page['url']['full']['path']))
			$zz_page['deep'] = str_repeat('../', (substr_count('/'.$zz_page['url']['full']['path'], '/') -2));
		else
			$zz_page['deep'] = '/';
	}
}

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

	mb_internal_encoding(strtoupper($zz_conf['character_set']));

	// To
	$mail['to'] = wrap_mail_name($mail['to']);

	// Subject
	if (!empty($zz_conf['mail_subject_prefix']))
		$mail['subject'] = $zz_conf['mail_subject_prefix'].' '.$mail['subject'];
	$mail['subject'] = str_replace("\n", " ", mb_encode_mimeheader($mail['subject']));

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
	foreach ($mail['headers'] as $key => $header) {
		// set but empty headers will be ignored
		if (!$header) continue;
		// newlines and carriage returns: probably some injection, ignore
		if (strstr($header, "\n")) continue;
		if (strstr($header, "\r")) continue;
		$additional_headers .= $key.': '.$header."\r\n";
	}

	// Additional parameters
	if (!isset($mail['parameters'])) $mail['parameters'] = '';

	$old_error_handling = $zz_conf['error_handling'];
	if ($zz_conf['error_handling'] == 'mail') {
		$zz_conf['error_handling'] = false; // don't send mail, does not work!
	}

	// if local server, show e-mail, don't send it
	if ($zz_setting['local_access']) {
		$mail = 'Mail '.htmlspecialchars('To: '.$mail['to']."\n"
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
	$mail = !empty($name['name']) ? mb_encode_mimeheader($name['name']).' ' : '';
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
	// check address
	$e_mail_pm = '/^[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\\.[a-z0-9!#$%&\'*+\\/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i';
	if (preg_match($e_mail_pm, $e_mail, $check))
		return $e_mail;
	return '';
}

/**
 * sends a ressource via HTTP regarding some headers
 *
 * @param string $text content to be sent
 * @param string $type (optional, default html) HTTP content type
 * @param int $status (optional, default 200) HTTP status code
 * @param array $headers (optional) further HTTP headers
 * @global array $zz_conf
 * @global array $zz_setting
 * @return void
 */
function wrap_send_ressource($text, $type = 'html', $status = 200, $headers = array()) {
	global $zz_conf;
	global $zz_setting;

	$text = trim($text);

	// Send ETag-Header and check whether content is identical to
	// previously sent content
	$etag_header = array();
	if ($status == 200) {
		$etag = md5($text);
		$etag_header = wrap_if_none_match($etag);
		header("ETag: ".$etag_header['std']);
	}

	// headers
	// set character set
	switch ($type) {
	case 'html':
		if (!empty($zz_conf['character_set']))
			header('Content-Type: text/html; charset='.$zz_conf['character_set']);
		break;
	case 'json':
		header('Content-Type: application/json; charset=utf-8');
		break;
	case 'kml':
		header('Content-Type: application/vnd.google-earth.kml+xml; charset=utf-8');
		$filename = !empty($headers['filename']) ? $headers['filename'] : 'download.kml';
		header(sprintf('Content-Disposition: attachment; filename="%s"', $filename));
		break;
	case 'mediarss':
		header('Content-Type: application/xhtml+xml; charset=utf-8');
		break;
	}

	$last_modified_time = time();

	// Caching?
	if (!empty($zz_setting['cache']) AND empty($_SESSION['logged_in'])
		AND empty($_POST) AND $status == 200) {
		$host = wrap_cache_filename('domain');
		if (!file_exists($host)) {
			$success = mkdir($host);
			if (!$success) wrap_error(sprintf('Could not create cache directory %s.', $host), E_USER_NOTICE);
		}
		$doc = wrap_cache_filename();
		$head = wrap_cache_filename('headers');
		$equal = false;
		if (file_exists($head)) {
			// check if something with the same ETag has already been cached
			// no need to rewrite cache, it's possible to send a Last-Modified
			// header along
			$headers = json_decode(file_get_contents($head));
			if (!$headers) {
				wrap_error(sprintf('Cache file for headers has no content (%s)', $head), E_USER_NOTICE);
			} else {
				foreach ($headers as $header) {
					if (substr($header, 0, 6) != 'ETag: ') continue;
					if (substr($header, 6) != $etag_header['std']) continue;
					$equal = true;
					// set older value for Last-Modified header
					if ($time = filemtime($doc)) // if it exists
						$last_modified_time = $time;
				}
			}
		}
		if (!$equal) {
			// save document
			file_put_contents($doc, $text);
			// save headers
			// without '-gz'
			file_put_contents($head, json_encode(headers_list()));
		}
	}

	// Last Modified?
	$last_modified = wrap_if_modified_since($last_modified_time);

	// send Last-Modified header if not yet sent
	$send_last_modified = true;
	$prepared_headers = headers_list();
	foreach ($prepared_headers as $prepared_header) {
		if (!wrap_substr($prepared_header, 'Last-Modified: ')) continue;
		$send_last_modified = false;
	}
	if ($send_last_modified) {
		header('Last-Modified: '.$last_modified);
	}

	header("Content-Length: ".strlen($text));
	if (stripos($_SERVER['REQUEST_METHOD'], 'HEAD') !== FALSE) exit;
	
	// output content
	if (!empty($zz_setting['gzip_encode'])) {
		wrap_send_gzip($text, $etag_header);
	} else {
		echo $text;
	}
	exit;
}

function wrap_send_gzip($text, $etag_header) {
	// gzip?
	header("Vary: Accept-Encoding");
	// start output
	ob_start();
	ob_start('ob_gzhandler');
	echo $text;
	ob_end_flush();  // The ob_gzhandler one
	if ($etag_header) {
		// only if HTTP status = 200
		foreach (headers_list() AS $header) {
			if (!wrap_substr($header, "Content-Encoding: ")) continue;
			// overwrite ETag with -gz ending
			header("ETag: ".$etag_header['gz']);
		}
	}
	header('Content-Length: '.ob_get_length());
	ob_end_flush();  // The main one
}

/**
 * creates ETag-Headers, checks against HTTP_IF_NONE_MATCH
 *
 * @param string $etag
 * @param array $file (optional)
 * @return mixed $etag_header (only if none match)
 *		!$file: array 'std' = standard header, 'gz' = header with gzip
 *		$file: string standard header
 */
function wrap_if_none_match($etag, $file = array()) {
	$etag_header['std'] = sprintf('"%s"', $etag);
	$etag_header['gz'] = sprintf('"%s"', $etag.'-gz');
    if (!isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
    	if ($file) return $etag_header['std'];
    	else return $etag_header;
    }
    if ($_SERVER['HTTP_IF_NONE_MATCH'] != $etag_header['std']
    	AND $_SERVER['HTTP_IF_NONE_MATCH'] != $etag_header['gz']) {
		return $etag_header;
    }
    if ($file) wrap_file_cleanup($file);
	wrap_http_status_header(304);
	exit;
}

/**
 * creates Last-Modified-Header, checks against HTTP_IF_MODIFIED_SINCE
 * respond to If Modified Since with 304 header if appropriate
 *
 * @param int $time (timestamp)
 * @param array $file (optional)
 * @return string time formatted for Last-Modified
 */
function wrap_if_modified_since($time, $file = array()) {
	// Cache time: 'Sa, 05 Jun 2004 15:40:28'
	$last_modified = gmdate("D, d M Y H:i:s", $time). ' GMT';
	if (!isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) return $last_modified;
	if ($last_modified !== $_SERVER['HTTP_IF_MODIFIED_SINCE']) return $last_modified;
    if ($file) wrap_file_cleanup($file);
	wrap_http_status_header(304);
	exit;
}

/**
 * send cached data instead of data from database
 * (e. g. if connection is broken)
 *
 * @param int $age maximum acceptable cache age in seconds
 * @global array $zz_setting
 * @return void
 */
function wrap_send_cache($age = 0) {
	global $zz_setting;
	
	// Some cases in which we do not cache
	if (empty($zz_setting['cache'])) return false;
	if (!empty($_SESSION)) return false;
	if (!empty($_POST)) return false;

	$files = array(wrap_cache_filename(), wrap_cache_filename('headers'));
	if (!file_exists($files[0]) OR !file_exists($files[1])) return false;

	if ($age) {
		// return cached files if they're still fresh enough
		foreach ($files as $file) {
			if ((filemtime($file) + $age) < time()) return false;
		}
	}
	$headers = json_decode(file_get_contents($files[1]));
	$etag = false;
	foreach ($headers as $header) {
		if (wrap_substr($header, 'ETag: ')) {
			// check if respond with 304
			$etag = substr($header, 7, -1); // without ""
		}
		header($header);
	}
	// check if respond with 304; Last-Modified
	$last_modified = wrap_if_modified_since(filemtime($files[0]));
	header('Last-Modified: '.$last_modified);
	$text = file_get_contents($files[0]);

	// check if respond with 304; ETag
	if (!$etag) $etag = md5($text);
	$etag_header = wrap_if_none_match($etag);

	header("Content-Length: ".strlen($text));
	if (stripos($_SERVER['REQUEST_METHOD'], 'HEAD') !== FALSE) exit;
	
	if (!empty($zz_setting['gzip_encode'])) {
		wrap_send_gzip($text, $etag_header);
	} else {
		echo $text;
	}
	exit;
}

/**
 * returns filename for URL for caching
 *
 * @param string $type optional; default: 'url'; 'headers', 'domain'
 * @global array $zz_page ($zz_page['url']['full'])
 * @global array $zz_setting 'cache'
 * @return string filename
 */
function wrap_cache_filename($type = 'url') {
	global $zz_page;
	global $zz_setting;

	$my = $zz_page['url']['full'];
	if (substr($my['host'], -1) === '.') {
		// fully-qualified (unambiguous) DNS domain names have a dot at the end
		// we better not redirect these to a domain name without a dot to avoid
		// ambiguity, but we do not need to do double caching
		$my['host'] = substr($my['host'], 0, -1);
	}
	$file = $zz_setting['cache'].'/'.urlencode($my['host']);
	if ($type === 'domain') return $file;

	$base = $zz_setting['base'];
	if ($base == '/') $base = '';
	if (!empty($my['query'])) {
		// [ and ] are equal to %5B and %5D, so replace them
		$my['query'] = str_replace('%5B', '[', $my['query']);
		$my['query'] = str_replace('%5D', ']', $my['query']);
		$my['path'] .= '?'.$my['query'];
	}
	$file .= '/'.urlencode($base.$my['path']);
	if ($type === 'url') return $file;

	$file .= '.headers';
	if ($type === 'headers') return $file;

	return false;
}

/**
 * debug: print_r included in text so we do not get problems with headers, zip
 * etc.
 *
 * @param array $array
 * @return string
 */
function wrap_print($array, $color = 'FFF') {
	ob_start();
	echo '<pre style="text-align: left; background-color: #'.$color
		.'; position: relative; z-index: 10;">';
	print_R($array);
	echo '</pre>';
	return ob_get_clean();
}

/**
 * Test HTTP REQUEST method
 * 
 * @global array $zz_setting
 * @return void
 */
function wrap_check_http_request_method() {
	global $zz_setting;
	if (in_array($_SERVER['REQUEST_METHOD'], $zz_setting['http']['allowed']))
		return true;
	if (in_array($_SERVER['REQUEST_METHOD'], $zz_setting['http']['not_allowed'])) {
		wrap_quit(405);	// 405 Not Allowed
	}
	wrap_quit(501); // 501 Not Implemented
}

/**
 * Get rid of unwanted query strings
 * 
 * since we do not use session-IDs in the URL, get rid of these since sometimes
 * they might be used for session_start()
 * e. g. GET http://example.com/?PHPSESSID=5gh6ncjh00043PQTHTTGY%40DJJGV%5D
 * @param array $url ($zz_page['url'])
 * @param array $objectionable_qs key names of query strings
 * @todo get objectionable querystrings from setting
 */
function wrap_remove_query_strings($url, $objectionable_qs = array()) {
	if (empty($url['full']['query'])) return $url;
	if (empty($objectionable_qs)) {
		$objectionable_qs = array('PHPSESSID');
	}
	if (!is_array($objectionable_qs)) {
		$objectionable_qs = array($objectionable_qs);
	}
	parse_str($url['full']['query'], $query);
	// furthermore, keys with % signs are not allowed (propably errors in
	// some parsing script)
	foreach (array_keys($query) AS $key) {
		if (strstr($key, '%')) $objectionable_qs[] = $key;
	}
	if ($remove = array_intersect(array_keys($query), $objectionable_qs)) {
		foreach ($remove as $key) {
			unset($query[$key]);
			unset($_GET[$key]);
			unset($_REQUEST[$key]);
		}
		$url['full']['query'] = http_build_query($query);
		$url['redirect'] = true;
	}
	return $url;
}

/**
 * checks if a substring from beginning or end of string matches given string
 *
 * @param string $string = check against this string
 * @param string $substring = this must be beginning of string
 * @param string $mode: begin: check from begin; end: check from end
 * @param bool true: first letters of $string match $substring
 */
function wrap_substr($string, $substring, $mode = 'begin') {
	switch ($mode) {
	case 'begin':
		if (substr($string, 0, strlen($substring)) === $substring) return true;
		break;
	case 'end':
		if (substr($string, -strlen($substring)) === $substring) return true;
		break;
	}
	return false;
}

/**
 * gets setting from configuration (default: zz_setting)
 *
 * @param string $key
 * @return mixed $setting (if not found, returns NULL)
 */
function wrap_get_setting($key) {
	if (function_exists('custom_get_setting')) {
		return custom_get_setting($key);
	} else {
		global $zz_setting;
		if (!isset($zz_setting[$key])) return NULL;
		return $zz_setting[$key];
	}
}

/**
 * checks hash against string
 *
 * @param string $string
 * @param string $hash hash to check against
 * @param string $error_msg (optional, defaults to 'Incorrect credentials')
 * @param string $key name of key (optional, defaults to 'secret_key')
 * @return bool
 */
function wrap_check_hash($string, $hash, $error_msg = '', $key = 'secret_key') {
	// check timeframe: current, previous, next
	if (wrap_set_hash($string, $key) == $hash) return true;
	if (wrap_set_hash($string, $key, -1) == $hash) return true;
	if (wrap_set_hash($string, $key, +1) == $hash) return true;

	if (!$error_msg) $error_msg = wrap_text('Incorrect credentials');
	wrap_error($error_msg, E_USER_NOTICE);
	wrap_quit(403);
}

/**
 * creates hash with secret key
 *
 * - needs a setting 'secret_key', i. e. a key that is shared with the foreign
 * server; 
 * - optional setting 'secret_key_validity_in_minutes' for a timeframe during
 * which the key is valid. Example: = 60, current time is 14:23: timestamp set
 * to 14:00, valid from 13:00-15:59; i. e. min. 60 minutes, max. 120 min.
 * depending on actual time
 * hashes will be made in UTF 8, if it's not UTF 8 here, we assume it's Latin1
 * if you use some other encoding, make sure, your secret key is encoded in ASCII
 * @param string $string
 * @param string $key name of key (optional, defaults to 'secret_key')
 * @param string $period (optional) 0: current, -1: previous, 1: next
 *		this parameter is internal, it should be used only from wrap_check_hash
 * @return string hash
 * @see wrap_check_hash()
 * @todo support other character encodings as utf-8 and iso-8859-1
 */
function wrap_set_hash($string, $key = 'secret_key', $period = 0) {
	$secret_key = wrap_get_setting($key);
	$minutes_valid = wrap_get_setting($key.'_validity_in_minutes');
	if ($minutes_valid) {
		$now = time();
		$seconds = $minutes_valid*60;
		$timeframe_start = floor($now/$seconds)*$seconds + $period*$seconds;
		$secret_key .= $timeframe_start;
	}
	$secret = $string.$secret_key;
	global $zz_conf;
	if ($zz_conf['character_set'] != 'utf-8') $secret = utf8_encode($secret);
	$hash = sha1($secret);
	return $hash;
}

/**
 * erlaubt Zugriff nur von berechtigten IP-Adressen, bricht andernfalls mit 403
 * Fehlercode ab
 *
 * @param string $ip_list Schlüssel in $zz_setting, der Array mit den erlaubten
 *		IP-Adressen enthält
 * @return bool true: access granted; exit function: access forbidden
 * @todo make compatible to IPv6
 * @todo combine with ipfilter from zzbrick
 */
function wrap_restrict_ip_access($ip_list) {
	$ip_list = wrap_get_setting($ip_list);
	if ($ip_list === NULL) {
		wrap_error(sprintf(wrap_text('List of allowed IPs not found in configuration (%s).'),
			$ip_list), E_USER_NOTICE);
		wrap_quit(403);
	}
	if (!is_array($ip_list)) $ip_list = array($ip_list);
	if (!in_array($_SERVER['REMOTE_ADDR'], $ip_list)) {
		wrap_error(sprintf(wrap_text('Your IP address %s is not in the allowed range.'),
			htmlspecialchars($_SERVER['REMOTE_ADDR'])), E_USER_NOTICE);
		wrap_quit(403);
	}
	return true;
}

/**
 * checks or sets rights
 *
 * @param string $right key:
 *		'preview' for preview of not yet published content
 *		'access' for access rights
 * @param string $mode (optional): get, set
 * @param string $value (optional): in combination with set, sets value to right
 */
function wrap_rights($right, $mode = 'get', $value = NULL) {
	global $zz_conf;
	global $zz_setting;
	static $rights;
	switch ($mode) {
	case 'get':
		if (isset($rights[$right])) return $rights[$right];
		else return NULL;
	case 'set':
		if ($value === NULL) return false;
		$rights[$right] = $value;
		return $value;
	}
	return false;
}

/**
 * get list of ids and levels to show a hierarchical output
 *
 * @param array $data (indexed by ID = $values)
 * @param string $main_field_name field name of main ID (must be in $values)
 * @param mixed $top_id (optional; 'NULL' = default, int = subtree)
 * @return array $id => $level, sorted as $data is sorted
 */
function wrap_hierarchy($data, $main_field_name, $top_id = 'NULL') {
	$indexed_by_main = array();
	foreach ($data as $id => $values) {
		if (!$values[$main_field_name]) $values[$main_field_name] = 'NULL';
		$indexed_by_main[$values[$main_field_name]][$id] = $values;
	}
	if (!$indexed_by_main) return $indexed_by_main;
	return wrap_hierarchy_recursive($indexed_by_main, $top_id);
}

/**
 * read hierarchy recursively
 *
 * @param array $indexed_by_main list of main_id => $id => $values
 * @param mixed $top_id (optional; 'NULL' = default, int = subtree)
 * @param int $level
 * @return array $id => $level, sorted as $data is sorted (parts of it)
 */
function wrap_hierarchy_recursive($indexed_by_main, $top_id, $level = 0) {
	$keys = array_keys($indexed_by_main[$top_id]);
	foreach ($keys as $id) {
		$hierarchy[$id] = $level;
		if (!empty($indexed_by_main[$id])) {
			// += preserves keys opposed to array_merge()
			$hierarchy += wrap_hierarchy_recursive($indexed_by_main, $id, $level + 1);
		}
	}
	return $hierarchy;
}

?>