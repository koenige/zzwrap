<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2008
// CMS core functions


// Local modifications to SQL queries
wrap_sql('core', 'set');


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
			$sql = sprintf(wrap_sql('pages'), '/'.mysql_real_escape_string($my_url));
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
 * Make canonical URLs (trailing slash, .html etc.)
 * 
 * @param string $ending ending of URL (/, .html, .php, none)
 * @param array $url ($zz_page['url'])
 * @return array $url, with new 'path' and 'redirect' set to 1 if necessary
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_check_canonical($ending, $url) {
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
	$url['suffix_length'] = (!empty($_GET['lang']) ? strlen($_GET['lang']) + 6 : 5);
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
 * @return exits function with a redirect or an error document
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_quit($errorcode = 404, $error_msg = '') {
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
	$url['db'] = mysql_real_escape_string($url['db']);
	$where_language = (!empty($_GET['lang']) 
		? ' OR '.wrap_sql('redirects_old_fieldname').' = "/'
			.$url['db'].'.html.'.mysql_real_escape_string($_GET['lang']).'"'
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
	// save old setting regarding text formatting
	if (!isset($zz_setting['brick_fulltextformat'])) 
		$zz_setting['brick_fulltextformat'] = '';
	$old_brick_fulltextformat = $zz_setting['brick_fulltextformat'];
	// apply new text formatting
	$zz_setting['brick_fulltextformat'] = 'brick_textformat_html';
	$template = file($tpl);
	// remove comments and next empty line from the start
	foreach ($template as $index => $line) {
		if (substr($line, 0, 1) == '#') unset($template[$index]); // comments
		elseif (!trim($line)) unset($template[$index]); // empty lines
		else break;
	}
	$template = implode("", $template);
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

function wrap_microtime_float() {
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

/**
 * Fetches records from database and returns array
 * 
 * - without $id_field_name: expects exactly one record and returns
 * the values of this record as an array
 * - with $id_field_name: uses this name as unique key for all records
 * and returns an array of values for each record under this key
 * - with $id_field_name and $array_format = "key/value": returns key/value-pairs
 * - with $id_field_name = 'dummy' and $array_format = "single value": returns
 * just first value as an array e. g. [3] => 3
 * @param string $sql SQL query string
 * @param string $id_field_name optional, if more than one record will be 
 *	returned: required; field_name for array keys
 *  if it's an array with two strings, this will be used to construct a 
 *  hierarchical array for the returned array with both keys
 * @param string $format optional, currently implemented
 *	"key/value" = returns array($key => $value)
 *	"single value" = returns $value
 *	"object" = returns object
 *	"numeric" = returns lines in numerical array [0 ... n] instead of using field ids
 * @return array with queried database content
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @todo give a more detailed explanation of how function works
 */
function wrap_db_fetch($sql, $id_field_name = false, $format = false) {
	global $zz_conf;
	if (!empty($zz_conf['debug'])) {
		$time = wrap_microtime_float();
	}
	$lines = array();
	$result = mysql_query($sql);
	if (!$result) {
		// error
		if (function_exists('wrap_error')) {
			wrap_error('['.$_SERVER['REQUEST_URI'].'] '
				.sprintf('Error in SQL query:'."\n\n%s\n\n%s", mysql_error(), $sql), E_USER_ERROR);
		} else {
			if (!empty($zz_conf['error_handling']) AND $zz_conf['error_handling'] == 'output') {
				global $zz_page;
				$zz_page['error_msg'] = '<p class="error">'.mysql_error().'<br>'.$sql.'</p>';
			}
		}
		return $lines;	
	}

	if (!$id_field_name) {
		// only one record
		if (mysql_num_rows($result) == 1) {
			if ($format == 'single value') {
				$lines = mysql_result($result, 0, 0);
			} elseif ($format == 'object') {
				$lines = mysql_fetch_object($result);
			} else {
				$lines = mysql_fetch_assoc($result);
			}
		}
	} elseif (is_array($id_field_name) AND mysql_num_rows($result)) {
		if ($format == 'object') {
			while ($line = mysql_fetch_object($result)) {
				if (count($id_field_name) == 3) {
					$lines[$line->$id_field_name[0]][$line->$id_field_name[1]][$line->$id_field_name[2]] = $line;
				} else {
					$lines[$line->$id_field_name[0]][$line->$id_field_name[1]] = $line;
				}
			}
		} else {
			// default or unknown format
			while ($line = mysql_fetch_assoc($result)) {
				if ($format == 'single value') {
					// just get last field, make sure that it's not one of the id_field_names!
					$values = array_pop($line);
				} else {
					$values = $line;
				}
				if (count($id_field_name) == 4) {
					$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]][$line[$id_field_name[2]]][$line[$id_field_name[3]]] = $values;
				} elseif (count($id_field_name) == 3) {
					if ($format == 'key/value') {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]] = $line[$id_field_name[2]];
					} else {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]][$line[$id_field_name[2]]] = $values;
					}
				} else {
					if ($format == 'key/value') {
						$lines[$line[$id_field_name[0]]] = $line[$id_field_name[1]];
					} elseif ($format == 'numeric') {
						$lines[$line[$id_field_name[0]]][] = $values;
					} else {
						$lines[$line[$id_field_name[0]]][$line[$id_field_name[1]]] = $values;
					}
				}
			}
		}
	} elseif (mysql_num_rows($result)) {
		if ($format == 'count') {
			$lines = mysql_num_rows($result);
		} elseif ($format == 'single value') {
			// you can reach this part here with a dummy id_field_name
			// because no $id_field_name is needed!
			while ($line = mysql_fetch_array($result)) {
				$lines[$line[0]] = $line[0];
			}
		} elseif ($format == 'key/value') {
			// return array in pairs
			while ($line = mysql_fetch_array($result)) {
				$lines[$line[0]] = $line[1];
			}
		} elseif ($format == 'object') {
			while ($line = mysql_fetch_object($result))
				$lines[$line->$id_field_name] = $line;
		} elseif ($format == 'numeric') {
			while ($line = mysql_fetch_assoc($result))
				$lines[] = $line;
		} else {
			// default or unknown format
			while ($line = mysql_fetch_assoc($result))
				$lines[$line[$id_field_name]] = $line;
		}
	}
	if (!empty($zz_conf['debug'])) {
		$time = wrap_microtime_float() - $time;
		wrap_error($time.' - '.$sql, E_USER_NOTICE);
	}
	return $lines;
}

/**
 * Recursively gets a tree of records or just IDs from the database
 * 
 * to get just IDs of records, the input array needs to be either the output
 * of wrap_db_fetch($sql, $key_field_name, 'single value') or an array of
 * IDs (array(3, 4, 5)); to get full records as specified in the SQL query, the
 * input array must be the output of wrap_db_fetch($sql, $key_field_name) or an
 * array with the records, e. g. array(3 => array('id' => 3, 'title' => "blubb"),
 * 4 => array('id' => 4, title => "another title"))
 * @param array $data Array with records from database, indexed on ID
 * @param string $sql SQL query to get child records for each selected record
 * @param string $key_field_name optional: Fieldname of primary key
 * @param string $mode optional: flat = without hierarchy, hierarchy = with.
 * @return array with queried database content or just the IDs
 *		if mode is set to hierarchy, you'll get a hierarchical list in 'level'
 *		with ID as key and the level (0, 1, 2, ..., n) as value
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_db_children($data, $sql, $key_field_name = false, $mode = 'flat') {
	// get all IDs that were submitted to the function
	if ($key_field_name)
		foreach ($data as $record) $ids[] = $record[$key_field_name];
	else
		$ids = $data;
	if ($mode == 'hierarchy') {
		$old_data = $data;
		unset($data);
		$data[0] = $old_data; // 0 is the top hierarchy, means nothing stands above this
		$data['ids'] = $ids;
		$top_id = key($ids);
		$data['level'][$top_id] = 0;
	}
	// as long as we have IDs in the pool, check if the current ID has child records
	$used_ids = array();
	while ($ids) {
		switch ($mode) {
		case 'hierarchy':
			$my_id = array_shift($ids);
			if (!trim($my_id)) continue 2;
			if (in_array($my_id, $used_ids)) {
				continue 2; // avoid infinite recursion
			} else {
				$used_ids[] = $my_id;
			}
			break;
		case 'flat':
			// take current ID from $ids
			foreach ($ids as $id) {
				// avoid infinite recursion
				if (in_array($id, $used_ids)) {
					$key = array_search($id, $ids);
					unset($ids[$key]);
				} else {
					$used_ids[] = $id;
				}
			}
			if (!$ids) continue 2;
			$my_id = implode(',', $ids);
			break;
		}

		if ($key_field_name) {
			// get ID and full record as specified in SQL query
			$my_data = wrap_db_fetch(sprintf($sql, $my_id), $key_field_name);
		} else {
			// just get the ID, a dummy key_field_name must be set here
			$my_data = wrap_db_fetch(sprintf($sql, $my_id), 'dummy', 'single value');
		}
		if (!$my_data) continue;
		
		switch ($mode) {
		case 'hierarchy':
			if (isset($data['level'][$my_id]))
				$my_level = $data['level'][$my_id] + 1;
			else
				$my_level = 1;
			$level = array();
			foreach (array_keys($my_data) AS $id) {
				$level[$id] = $my_level;
			}
			$pos = array_search($my_id, array_keys($data['level']))+1;
			$data['level'] = array_slice($data['level'], 0, $pos, true)
				+ $level + array_slice($data['level'], $pos, NULL, true);

			// append new records to $data-Array
			if (empty($data[$my_id])) $data[$my_id] = array();
			$data[$my_id] += $my_data;
			// append new IDs to $ids-Array
			$ids = array_merge($ids, array_keys($my_data));
			$data['ids'] = array_merge($data['ids'], array_keys($my_data));
			break;
		case 'flat':
			// append new records to $data-Array
			$data += $my_data;
			// put new IDs into $ids-Array
			$ids = array_keys($my_data);
			break;
		}
	}
	if ($mode == 'hierarchy') sort($data['ids']);
	return $data;
}

/**
 * Recursively gets a tree of IDs from the database (here: parent IDs)
 * 
 * @param int $id ID of child
 * @param string $sql SQL query with placeholder %s for ID
 * @return array set of IDs
 */
function wrap_db_parents($id, $sql) {
	$ids = array();
	$result = true;
	while ($result) {
		$id = wrap_db_fetch(sprintf($sql, $id), '', 'single value');
		if ($id) $ids[] = $id;
		else $result = false;
	}
	$ids = array_reverse($ids); // top-down
	return $ids;
}

/**
 * puts parts of SQL query in correct order when they have to be added
 *
 * this function works only for sql queries without UNION:
 * might get problems with backticks that mark fieldname that is equal with SQL 
 * keyword
 * mode = add until now default, mode = replace is only implemented for SELECT
 * identical to zz_edit_sql()!
 * @param string $sql original SQL query
 * @param string $n_part SQL keyword for part shall be edited or replaced
 *		SELECT ... FROM ... JOIN ...
 * 		WHERE ... GROUP BY ... HAVING ... ORDER BY ... LIMIT ...
 * @param string $values new value for e. g. WHERE ...
 * @param string $mode Mode, 'add' adds new values while keeping the old ones, 
 *		'replace' replaces all old values
 * @return string $sql modified SQL query
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @see zz_edit_sql()
 */
function wrap_edit_sql($sql, $n_part = false, $values = false, $mode = 'add') {
	// remove whitespace
	$sql = ' '.preg_replace("/\s+/", " ", $sql); // first blank needed for SELECT
	// SQL statements in descending order
	$statements_desc = array('LIMIT', 'ORDER BY', 'HAVING', 'GROUP BY', 'WHERE', 'FROM', 'SELECT DISTINCT', 'SELECT');
	foreach ($statements_desc as $statement) {
		$explodes = explode(' '.$statement.' ', $sql);
		if (count($explodes) > 1) {
		// = look only for last statement
		// and put remaining query in [1] and cut off part in [2]
			$o_parts[$statement][2] = array_pop($explodes);
			$o_parts[$statement][1] = implode(' '.$statement.' ', $explodes).' '; // last blank needed for exploding SELECT from DISTINCT
		}
		$search = '/(.+) '.$statement.' (.+?)$/i'; 
//		preg_match removed because it takes way too long if nothing is found
//		if (preg_match($search, $sql, $o_parts[$statement])) {
		if (empty($o_parts[$statement])) continue;
		$found = false;
		$lastpart = false;
		while (!$found) {
			// check if there are () outside '' or "" and count them to check
			// whether we are inside a subselect
			$temp_sql = $o_parts[$statement][1]; // look at first part of query

			// 1. remove everything in '' and "" which are not escaped
			// replace \" character sequences which escape "
			$temp_sql = preg_replace('/\\\\"/', '', $temp_sql);
			// replace "strings" without " inbetween, empty "" as well
			$temp_sql = preg_replace('/"[^"]*"/', "away", $temp_sql);
			// replace \" character sequences which escape '
			$temp_sql = preg_replace("/\\\\'/", '', $temp_sql);
			// replace "strings" without " inbetween, empty '' as well
			$temp_sql = preg_replace("/'[^']*'/", "away", $temp_sql);

			// 2. count opening and closing ()
			//  if equal ok, if not, it's a statement in a subselect
			// assumption: there must not be brackets outside " or '
			if (substr_count($temp_sql, '(') == substr_count($temp_sql, ')')) {
				$sql = $o_parts[$statement][1]; // looks correct, so go on.
				$found = true;
			} else {
				// remove next last statement, and go on until you found 
				// either something with correct bracket count
				// or no match anymore at all
				$lastpart = ' '.$statement.' '.$o_parts[$statement][2];
				// check first with strstr if $statement (LIMIT, WHERE etc.)
				// is still part of the remaining sql query, because
				// preg_match will take 2000 times longer if there is no match
				// at all (bug in php?)
				if (strstr($o_parts[$statement][1], $statement) 
					AND preg_match($search, $o_parts[$statement][1], $o_parts[$statement])) {
					$o_parts[$statement][2] = $o_parts[$statement][2].' '.$lastpart;
				} else {
					unset($o_parts[$statement]); // ignore all this.
					$found = true;
				}
			}
		}
	}
	if ($n_part && $values) {
		$n_part = strtoupper($n_part);
		switch ($n_part) {
			case 'LIMIT':
				// replace complete old LIMIT with new LIMIT
				$o_parts['LIMIT'][2] = $values;
			break;
			case 'ORDER BY':
				if ($mode == 'add') {
					// append old ORDER BY to new ORDER BY
					if (!empty($o_parts['ORDER BY'][2])) 
						$o_parts['ORDER BY'][2] = $values.', '.$o_parts['ORDER BY'][2];
					else
						$o_parts['ORDER BY'][2] = $values;
				} elseif ($mode == 'delete') {
					unset($o_parts['ORDER BY']);
				}
			break;
			case 'WHERE':
			case 'GROUP BY':
			case 'HAVING':
				if ($mode == 'add') {
					if (!empty($o_parts[$n_part][2])) 
						$o_parts[$n_part][2] = '('.$o_parts[$n_part][2].') AND ('.$values.')';
					else 
						$o_parts[$n_part][2] = $values;
				}  elseif ($mode == 'delete') {
					unset($o_parts[$n_part]);
				}
			break;
			case 'SELECT':
				if (!empty($o_parts['SELECT DISTINCT'][2])) {
					if ($mode == 'add')
						$o_parts['SELECT DISTINCT'][2] .= ','.$values;
					elseif ($mode == 'replace')
						$o_parts['SELECT DISTINCT'][2] = $values;
				} else {
					if ($mode == 'add')
						$o_parts['SELECT'][2] = ','.$values;
					elseif ($mode == 'replace')
						$o_parts['SELECT'][2] = $values;
				}
			break;
			default:
				echo 'The variable <code>'.$n_part.'</code> is not supported by zz_edit_sql().';
				exit;
			break;
		}
	}
	$statements_asc = array_reverse($statements_desc);
	foreach ($statements_asc as $statement) {
		if (!empty($o_parts[$statement][2])) 
			$sql.= ' '.$statement.' '.$o_parts[$statement][2];
	}
	return $sql;
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
 *		'error_msg' => additional error message that appears on error page
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
	if ($sql_filetypes = wrap_sql('filetypes')) 
		$sql = sprintf($sql_filetypes, $suffix);
	else {
		$sql = 'SELECT CONCAT(mime_content_type, "/", mime_subtype)
			FROM '.$zz_conf['prefix'].'filetypes
			WHERE extension = "'.$suffix.'"';
	}
	$mimetype = wrap_db_fetch($sql, '', 'single value');
	if (!$mimetype) $mimetype = 'application/octet-stream';

	// Remove some HTTP headers PHP might send because of SESSION
	// do some tests if this is okay
	header('Expires: ');
	header('Cache-Control: ');
	header('Pragma: ');

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
 *		'local_access'
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

	// if local server, show e-mail, don't send it
	if ($zz_setting['local_access']) {
		echo "<pre>".htmlspecialchars('To: '.$mail['to']."\n"
			.'Subject: '.$mail['subject']."\n".
			$additional_headers."\n".$mail['message'])."</pre>\n";
		exit;
	}

	// if real server, send mail
	$success = mail($mail['to'], $mail['subject'], $mail['message'], $additional_headers, $mail['parameters']);
	if (!$success) {
		$old_error_handling = $zz_conf['error_handling'];
		if ($zz_conf['error_handling'] == 'mail') {
			$zz_conf['error_handling'] = false; // don't send mail, does not work!
		}
		wrap_error('Mail could not be sent. (To: '.str_replace('<', '&lt;', $mail['to']).', From: '
			.str_replace('<', '&lt;', $mail['headers']['From']).', Subject: '.$mail['subject']
			.', Parameters: '.$mail['parameters'].')', E_USER_NOTICE);
		$zz_conf['error_handling'] = $old_error_handling;
	}
	return true;
}

function wrap_mail_name($name) {
	if (!is_array($name)) return $name;
	$mail = (!empty($name['name']) ? mb_encode_mimeheader($name['name']).' ' : '');
	$mail .=  '<'.$name['e_mail'].'>';
	return $mail;
}

/**
 * sends a ressource via HTTP regarding some headers
 *
 * @param string $text content to be sent
 * @param string $type content_type, defaults to html
 * @param int $status HTTP status code
 * @global array $zz_conf
 * @global array $zz_setting
 * @return void
 */
function wrap_send_ressource($text, $type = 'html', $status = 200) {
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
			foreach ($headers as $header) {
				if (substr($header, 0, 6) != 'ETag: ') continue;
				if (substr($header, 6) != $etag_header['std']) continue;
				$equal = true;
				// set older value for Last-Modified header
				if ($time = filemtime($doc)) // if it exists
					$last_modified_time = $time;
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
	$file = $zz_setting['cache'].'/'.urlencode($my['host']);
	if ($type === 'domain') return $file;

	$base = $zz_setting['base'];
	if ($base == '/') $base = '';
	if (!empty($my['query'])) $my['path'] .= '?'.$my['query'];
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
 * Local database structure, modifications to SQL queries and field_names
 *
 * wrap_get_menu_navigation():
 * $zz_sql['menu'] expects: nav_id, title, main_nav_id, url
 *		optional parameters: id_title, rest is free
 * wrap_get_menu_wepages():
 * $zz_sql['menu'] expects: page_id, title, mother_page_id, url, menu
 * $zz_sql['menu_level2'] expects: page_id, title, (id_title), mother_page_id, 
 *		url (function_url), menu
 * $zz_sql['page_id'] Name of ID field in webpages-table
 * $zz_sql['authors'] person_id = ID, person = name of author
 * @param string $key
 * @param string $mode (optional: get(default), set, add)
 * @return mixed true: set was succesful; string: SQL query or field name 
 *		corresponding to $key
 */
function wrap_sql($key, $mode = 'get', $value = false) {
	global $zz_conf;
	global $zz_setting;
	static $zz_sql;

	// set variables
	switch ($mode) {
	case 'get':
		// return variables
		if (isset($zz_sql[$key])) return $zz_sql[$key];
		else return NULL;
	case 'set':
		switch ($key) {
		case 'core':
			$zz_sql['page_id']		= 'page_id';
			$zz_sql['content']		= 'content';
			$zz_sql['title']		= 'title';
			$zz_sql['ending']		= 'ending';
			$zz_sql['identifier']	= 'identifier';
			$zz_sql['lastupdate']	= 'last_update';
			$zz_sql['author_id']	= 'author_person_id';
			break;
		case 'page':
			$zz_sql['breadcrumbs']	= '';
			$zz_sql['menu']			= '';
			$zz_sql['menu_level2']	= '';
			break;
		case 'auth':
			if (empty($zz_sql['domain']))
				$zz_sql['domain'] = array($zz_setting['hostname']);
			break;
		case 'translation':
			$zz_sql['translation_matrix_pages'] = array();
			$zz_sql['translation_matrix_breadcrumbs'] = array();
			$zz_sql['language'] = false;
			break;
		default:
			break;
		}
		if (file_exists($zz_setting['custom_wrap_sql_dir'].'/sql-'.$key.'.inc.php')
			AND !empty($zz_conf['db_connection']))
			require_once $zz_setting['custom_wrap_sql_dir'].'/sql-'.$key.'.inc.php';

		if (!empty($zz_sql['domain']) AND !is_array($zz_sql['domain']))
			$zz_sql['domain'] = array($zz_sql['domain']);

		return true;
	case 'add':
		if (empty($zz_sql[$key])) {
			$zz_sql[$key] = array($value);
			return true;
		}
		if (is_array($zz_sql[$key])) {
			$zz_sql[$key][] = $value;
			return true;
		}
	default:
		return false;	
	}
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
 * Do we have a database connection?
 * if not: send cache or exit
 * 
 * @global array $zz_conf
 * @global array $zz_setting
 * @return bool true: everything is okay
 */
function wrap_check_db_connection() {
	global $zz_conf;
	global $zz_setting;
	if ($zz_conf['db_connection']) return true;
	wrap_send_cache();
	wrap_error(sprintf('No connection to SQL server.'), E_USER_ERROR);
	exit;
}

/**
 * checks tables for last update and returns the newest last update timestamp
 *
 * @param array $tables tables which will be checked for changes
 * @return string datetime: date of last update in tables
 * @author Gustaf Mossakowski, <gustaf@koenige.org>
 */
function wrap_db_last_update($tables) {
	if (!is_array($tables)) $tables = array($tables);
	foreach ($tables as $table) {
		$db_table = explode('.', $table);
		if (count($db_table) == 2)
			$my_tables[$db_table[0]][] = $db_table[1];
		elseif (count($db_table) == 1)
			$my_tables['NULL'][] = $db_table[0];
		else {
			echo 'Error: '.$table.' has too many dots.';
			exit;
		}
	}
	$last_update = '';	
	foreach ($my_tables AS $db => $these_tables) {
		$sql = 'SHOW TABLE STATUS '
			.($db == 'NULL' ? '' : 'FROM `'.$db.'`')
			.' WHERE Name IN ("'.implode('","', $these_tables).'")';
		$status = wrap_db_fetch($sql, 'Name');
		foreach ($status as $table) {
			if ($table['Update_time'] > $last_update) $last_update = $table['Update_time'];
		}
	}
	
	return $last_update;
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

?>