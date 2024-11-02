<?php 

/**
 * zzwrap
 * Core functions: handling of HTTP requests (URLs, HTTP
 * communication, send ressources), common functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/*
 * --------------------------------------------------------------------
 * URLs
 * --------------------------------------------------------------------
 */

/**
 * expand URL (default = own URL)
 * shortcuts allowed: starting / = own server; ? = append query string
 * # = append anchor; ?? append query string and remove existing query string
 *
 * @param string $url
 * @return string
 */
function wrap_url_expand($url = false) {
	if (!$url) {
		$url = wrap_setting('host_base').wrap_setting('request_uri');
	} elseif (substr($url, 0, 1) === '#') {
		$url = wrap_setting('host_base').wrap_setting('request_uri').$url;
	} elseif (substr($url, 0, 2) === '??') {
		$request_uri = wrap_setting('request_uri');
		if ($pos = strpos($request_uri, '?')) $request_uri = substr($request_uri, 0, $pos);
		$url = wrap_setting('host_base').$request_uri.substr($url, 1);
	} elseif (substr($url, 0, 1) === '?') {
		$request_uri = wrap_setting('request_uri');
		if (strstr($request_uri, '?')) {
			$qs = parse_url($request_uri, PHP_URL_QUERY);
			parse_str($qs, $qs);
			$qs_append = parse_url('http://www.example.com/'.$url, PHP_URL_QUERY);
			parse_str($qs_append, $qs_append);
			$qs = array_merge($qs, $qs_append);
			// + 1 = keep the ?
			$request_uri = substr($request_uri, 0, strrpos($request_uri, '?') + 1).http_build_query($qs);
			$url = '';
		}
		$url = wrap_setting('host_base').$request_uri.$url;
	} elseif (substr($url, 0, 1) === '/') {
		$url = wrap_setting('host_base').$url;
	} elseif (str_starts_with($url, './')) {
		$request_path = parse_url(wrap_setting('request_uri'), PHP_URL_PATH);
		$request_path = substr($request_path, 0, strrpos($request_path, '/') + 1);
		$url = wrap_setting('host_base').$request_path.substr($url, 2);
	} elseif (str_starts_with($url, '../')) {
		$request_path = parse_url(wrap_setting('request_uri'), PHP_URL_PATH);
		$this_url = $url;
		while (str_starts_with($this_url, '../')) {
			$this_url = substr($this_url, 3);
			$request_path = substr($request_path, 0, strrpos($request_path, '/'));
			$request_path = substr($request_path, 0, strrpos($request_path, '/') + 1);
			if (!$request_path) {
				wrap_error(wrap_text('Wrong relative path: %s', ['values' => $url]));
				$request_path = '/';
			}
		}
		$url = wrap_setting('host_base').$request_path.$this_url;
	}
	return $url;
}

/**
 * get file extension by filename
 *
 * @param string $filename
 * @return string
 */
function wrap_file_extension($file) {
	if (!strstr($file, '.')) return NULL;
	if (str_starts_with($file, '.') AND substr_count($file, '.') === 1) return NULL;
	$file = explode('.', $file);
	return array_pop($file);
}

/**
 * Glues a URL together
 *
 * @param array $url (e. g. result of parse_url())
 * @return string
 */
function wrap_glue_url($url) {
	$base = wrap_setting('base');
	if (substr($base, -1) === '/') $base = substr($base, 0, -1);
	if (!in_array($_SERVER['SERVER_PORT'], [80, 443])) {
		$url['port'] = sprintf(':%s', $_SERVER['SERVER_PORT']);
	} else {
		$url['port'] = '';
	}
	if (!empty($url['path_forwarded']) AND str_starts_with($url['path'], $url['path_forwarded'])) {
		$url['path'] = substr($url['path'], strlen($url['path_forwarded']));
	}
	// remove duplicate base
	if (str_starts_with($url['path'], $base)) $base = '';
	$url['path'] = $base.$url['path'];
	return wrap_build_url($url);
}

/**
 * build a URL from parse_url() parts
 *
 * @param array
 * @return string
 */
function wrap_build_url($parts) {
	$url = (!empty($parts['scheme']) ? $parts['scheme'].':' : '')
		.(!empty($parts['host']) ? '//' : '')
		.(!empty($parts['user']) ? $parts['user']
			.(!empty($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '')
		.($parts['host'] ?? '')
		.(!empty($parts['port']) ? ':'.$parts['port'] : '')
		.($parts['path'] ?? '')
		.(!empty($parts['query']) ? '?'.$parts['query'] : '')
		.(!empty($parts['fragment']) ? '#'.$parts['fragment'] : '');
	return $url;
}

/**
 * Logs URL in URI table for statistics and further reference
 * sends only notices if some update does not work because it's just for the
 * statistics
 *
 * @param int $status
 * @return bool
 */
function wrap_log_uri($status = 0) {
	global $zz_page;
	
	if (!$status)
		$status = $zz_page['error_code'] ?? 200;

	if (wrap_setting('http_log')) {
		$logdir = sprintf('%s/access/%s/%s'
			, wrap_setting('log_dir')
			, date('Y', $_SERVER['REQUEST_TIME'])
			, date('m', $_SERVER['REQUEST_TIME'])
		);
		wrap_mkdir($logdir);
		$logfile = sprintf('%s/%s%s-access-%s.log'
			, $logdir
			, str_replace('/', '-', wrap_setting('site'))
			, wrap_https() ? '-ssl' : ''
			, date('Y-m-d', $_SERVER['REQUEST_TIME'])
		);
		$line = sprintf(
			'%s - %s [%s] "%s %s %s" %d %d "%s" "%s" %s'."\n"
			, wrap_setting('remote_ip')
			, $_SESSION['username'] ?? $_SERVER['REMOTE_USER'] ?? '-'
			, date('d/M/Y:H:i:s O', $_SERVER['REQUEST_TIME'])
			, $_SERVER['REQUEST_METHOD']
			, $_SERVER['REQUEST_URI']
			, $_SERVER['SERVER_PROTOCOL']
			, $status
			, $zz_page['content_length'] ?? 0
			, $_SERVER['HTTP_REFERER'] ?? '-'
			, $_SERVER['HTTP_USER_AGENT'] ?? '-'
			, wrap_setting('hostname')
		);
		error_log($line, 3, $logfile);
	}

	if (!wrap_setting('uris_table')) return false;
	if (empty($zz_page['url'])) return false;

	$scheme = $zz_page['url']['full']['scheme'];
	$host = $zz_page['url']['full']['host'];
	$base = str_starts_with($_SERVER['REQUEST_URI'], wrap_setting('base')) ? wrap_setting('base') : '';
	$path = $base.wrap_db_escape($zz_page['url']['full']['path']);
	$query = !empty($zz_page['url']['full']['query'])
		? '"'.wrap_db_escape($zz_page['url']['full']['query']).'"'
		: 'NULL';
	$etag = $zz_page['etag'] ?? 'NULL';
	if (substr($etag, 0, 1) !== '"' AND $etag !== 'NULL')
		$etag = '"'.$etag.'"';
	$last_modified = !empty($zz_page['last_modified'])
		? '"'.wrap_date($zz_page['last_modified'], 'rfc1123->datetime').'"'
		: 'NULL';
	$content_type = $zz_page['content_type'] ?? 'unknown';
	$encoding = !empty($zz_page['character_set'])
		? '"'.$zz_page['character_set'].'"'
		: 'NULL';
	if (strstr($content_type, '; charset=')) {
		$content_type = explode('; charset=', $content_type);
		$encoding = '"'.$content_type[1].'"';
		$content_type = $content_type[0];
	}
	
	$sql = 'SELECT uri_id
		FROM /*_PREFIX_*/_uris
		WHERE uri_scheme = "%s"
		AND uri_host = "%s"
		AND uri_path = "%s"';
	$sql = sprintf($sql, $scheme, $host, $path);
	if ($query === 'NULL') {
		$sql .= ' AND ISNULL(uri_query)';
	} else {
		$sql .= sprintf(' AND uri_query = %s', $query);
	}
	$uri_id = wrap_db_fetch($sql, '', 'single value', E_USER_NOTICE);
	
	if (!wrap_db_connection()) {
		return false;
	} elseif ($uri_id) {
		$sql = 'UPDATE /*_PREFIX_*/_uris
			SET hits = hits +1
				, status_code = %d
				, etag_md5 = %s
				, last_modified = %s
				, last_access = NOW(), last_update = NOW()
				, character_encoding = %s
		';
		if ($content_type)
			$sql .= sprintf(' , content_type = "%s"', $content_type);
		if (!empty($zz_page['content_length'])) 
			$sql .= sprintf(' , content_length = %d', $zz_page['content_length']);
		$sql .= ' WHERE uri_id = %d';
		$sql = sprintf($sql, $status, $etag, $last_modified, $encoding, $uri_id);
		wrap_db_query($sql, E_USER_NOTICE);
	} elseif (strlen($path) < 128 AND strlen($query) < 128) {
		$sql = 'INSERT INTO /*_PREFIX_*/_uris (uri_scheme, uri_host, uri_path,
			uri_query, content_type, character_encoding, content_length,
			status_code, etag_md5, last_modified, hits, first_access,
			last_access, last_update) VALUES ("%s", "%s", 
			"%s", %s, "%s", %s, %d, %d, %s, %s, 1, NOW(), NOW(), NOW())';
		$sql = sprintf($sql,
			$scheme, $host, $path, $query, $content_type, $encoding
			, ($zz_page['content_length'] ?? 0)
			, $status, $etag, $last_modified
		);
		wrap_db_query($sql, E_USER_NOTICE);
	} elseif (strlen($path) >= 128) {
		wrap_error(sprintf('URI path too long: %s', $path));
	} else {
		wrap_error(sprintf('URI query too long: %s', $query));
	}
	return true;
}

/*
 * --------------------------------------------------------------------
 * HTTP: checks
 * --------------------------------------------------------------------
 */

/**
 * Stops execution of script, check for redirects to other pages,
 * includes http error pages
 * 
 * The execution of the CMS will be stopped. The script test if there's
 * an entry for the URL in the redirect table to redirect to another page
 * If that's true, 30x codes redirect pages, 410 redirect to gone.
 * if no error code is defined, a 404 code and the corresponding error page
 * will be shown
 * @param int $statuscode HTTP Status Code, default value is 404
 * @param string $error_msg (optional, error message for user)
 * @param array $page (optional, if normal output shall be shown, not error msg)
 * @return exits function with a redirect or an error document
 */
function wrap_quit($statuscode = 404, $error_msg = '', $page = []) {
	global $zz_page;

	// for pages matching every URL, check if there’s a ressource somewhere else
	if (!empty($zz_page['db']['identifier']) AND $zz_page['db']['identifier'] === '/*' AND $statuscode === 404) {
		$zz_page = wrap_match_ressource($zz_page, false);
	}

	if ($canonical_hostname = wrap_url_canonical_hostname()) {
		if (wrap_setting('hostname') !== $canonical_hostname) {
			// fix links on error page to real destinations
			wrap_setting('host_base', 
				wrap_setting('protocol').'://'.$canonical_hostname);
			wrap_setting('homepage_url', wrap_setting('host_base').wrap_setting('homepage_url'));
		}
	}

	$page['status'] = $statuscode;
	if ($statuscode === 404) {
		$redir = wrap_match_redirects($zz_page['url']);
		if ($redir) {
			$page['status'] = $redir['code'];
		} else {
			$page = wrap_match_redirects_from_cache($page, $zz_page['url']['full']);
		}
	}

	// Check redirection code
	switch ($page['status']) {
	case 301:
	case 302:
	case 303:
	case 307:
		// (header 302 is sent automatically if using Location)
		if (!empty($page['redirect'])) {
			if (is_array($page['redirect']) AND array_key_exists('languagelink', $page['redirect'])) {
				$old_base = wrap_setting('base');
				if (wrap_setting('language_in_url')
					AND str_ends_with(wrap_setting('base'), '/'.wrap_setting('lang'))) {
					wrap_setting('base', substr(wrap_setting('base'), 0, -3));
				}
				if ($page['redirect']['languagelink']) {
					wrap_setting('base', wrap_setting('base').'/'.$page['redirect']['languagelink']);
				}
				$new = wrap_glue_url($zz_page['url']['full']);
				wrap_setting('base', $old_base); // keep old base for caching
			} elseif (is_array($page['redirect'])) {
				wrap_error(sprintf('Redirect to array not supported: %s', json_encode($page['redirect'])));
			} else {
				$new = $page['redirect'];
			}
		} else {
			$field_name = wrap_sql_fields('core_redirects_new_url');
			$new = $redir[$field_name];
		}
		if (!parse_url($new, PHP_URL_SCHEME)) {
			if (wrap_setting('base') AND file_exists(wrap_setting('root_dir').'/'.$new)) {
				// no language redirect if it's an existing file
				$new = wrap_setting('host_base').$new;
			} else {
				$new = wrap_setting('host_base').wrap_setting('base').$new;
			}
		}
		wrap_redirect($new, $page['status']);
		exit;
	case 304:
	case 412:
	case 416:
		wrap_log_uri($page['status']);
		wrap_http_status_header($page['status']);
		header('Content-Length: 0');
		exit;
	default: // 4xx, 5xx
		// save error code for later access to avoid infinite recursion
		if (empty($zz_page['error_code'])) {
			$zz_page['error_code'] = $statuscode;
		}
		if ($error_msg) {
			if (empty($zz_page['error_msg'])) $zz_page['error_msg'] = '';
			if (empty($zz_page['error_html']))
				$zz_page['error_html'] = '<p class="error">%s</p>';
			$zz_page['error_msg'] .= sprintf($zz_page['error_html'], $error_msg);
		}
		wrap_errorpage($page, $zz_page);
		exit;
	}
}

/*
 * --------------------------------------------------------------------
 * HTTP: send ressources
 * --------------------------------------------------------------------
 */

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
 *		'etag_generate_md5' => creates 'etag' if not send with MD5,
 *		'caching' => bool; defaults to true, false = no caching allowed,
 *		'ext' => use this extension, do not try to determine it from file ending
 * @todo send pragma public header only if browser that is affected by this bug
 * @todo implement Ranges for bytes
 */
function wrap_file_send($file) {
	global $zz_page;

	if (is_dir($file['name'])) {
		if (wrap_setting('cache')) wrap_cache_delete(404);
		return false;
	}
	if (!file_exists($file['name'])) {
		if (!empty($file['error_code'])) {
			if (!empty($file['error_msg'])) {
				global $zz_page;
				$zz_page['error_msg'] = $file['error_msg'];
			}
			wrap_quit($file['error_code']);
		}
		wrap_file_cleanup($file);
		if (wrap_setting('cache')) wrap_cache_delete(404);
		return false;
	}
	if (!empty($zz_page['url']['redirect'])) {
		wrap_redirect(wrap_glue_url($zz_page['url']['full']), 301, $zz_page['url']['redirect_cache']);
	}
	if (empty($file['send_as'])) $file['send_as'] = basename($file['name']);
	$extension = $file['ext'] ?? wrap_file_extension($file['name']);
	if (!str_ends_with($file['send_as'], '.'.$extension))
		$file['send_as'] .= '.'.$extension;
	if (!isset($file['caching'])) $file['caching'] = true;

	// Accept-Ranges HTTP header
	wrap_cache_header('Accept-Ranges: bytes');

	// Content-Length HTTP header
	$zz_page['content_length'] = sprintf("%u", filesize($file['name']));
	wrap_cache_header('Content-Length: '.$zz_page['content_length']);
	// Maybe the problem is we are running into PHPs own memory limit, so:
	if ($zz_page['content_length'] + 1 > wrap_return_bytes(ini_get('memory_limit'))
		&& intval($zz_page['content_length'] * 1.5) <= 1073741824) { 
		// Not higher than 1GB
		ini_set('memory_limit', intval($zz_page['content_length'] * 1.5));
	}

	// Content-Type HTTP header
	// Read mime type from .cfg or database
	// Canonicalize suffices
	$filetype_cfg = wrap_filetypes($extension, 'read-per-extension');
	if (!empty($filetype_cfg['mime'][0]))
		$zz_page['content_type'] = $filetype_cfg['mime'][0];
	elseif ($sql = sprintf(wrap_sql_query('core_filetypes'), $extension))
		$zz_page['content_type'] = wrap_db_fetch($sql, '', 'single value');
	if (!$zz_page['content_type']) $zz_page['content_type'] = 'application/octet-stream';
	wrap_cache_header('Content-Type: '.$zz_page['content_type']);

	// ETag HTTP header
	if (!empty($file['etag_generate_md5']) AND empty($file['etag'])) {
		$file['etag'] = md5_file($file['name']);
	}
	if (!empty($file['etag'])) {
		wrap_if_none_match($file['etag'], $file);
	}
	
	// Last-Modified HTTP header
	wrap_if_modified_since(filemtime($file['name']), 200, $file);

	if ($file['caching'])
		wrap_cache_allow_private();

	// Download files if generic mimetype
	// or HTML, since this might be of unknown content with javascript or so
	$download_filetypes = [
		'application/octet-stream', 'application/zip', 'text/html',
		'application/xhtml+xml'
	];
	if (in_array($zz_page['content_type'], $download_filetypes)
		OR !empty($_GET['download'])
	) {
		wrap_http_content_disposition('attachment', $file['send_as']);
			// d. h. bietet save as-dialog an, geht nur mit application/octet-stream
		wrap_cache_header('Pragma: public');
			// dieser Header widerspricht im Grunde dem mit SESSION ausgesendeten
			// Cache-Control-Header
			// Wird aber für IE 5, 5.5 und 6 gebraucht, da diese keinen Dateidownload
			// erlauben, wenn Cache-Control gesetzt ist.
			// http://support.microsoft.com/kb/323308/de
	} else {
		wrap_http_content_disposition('inline', $file['send_as']);
	}
	
	wrap_cache_header();
	if ($file['caching'])
		wrap_cache_header_default(sprintf('Cache-Control: max-age=%d', wrap_setting('cache_control_file')));

	// Caching?
	if (wrap_setting('cache')) {
		wrap_cache_header('X-Local-Filename: '.$file['name']);
		wrap_cache();
	}

	wrap_send_ressource('file', $file);
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
	if (!empty($file['cleanup_dir'])) {
		if (!file_exists($file['cleanup_dir'])) return false; // some parallel process
		$files = scandir($file['cleanup_dir']);
		if ($files) $files = array_diff($files, ['.', '..']);
		if (!$files) rmdir($file['cleanup_dir']);
	}
	return true;
}

/**
 * sends a ressource via HTTP regarding some headers
 *
 * @param string $text content to be sent
 * @param string $type (optional, default html) HTTP content type
 * @param int $status (optional, default 200) HTTP status code
 * @param array $headers (optional):
 *		'filename': download filename
 *		'character_set': character encoding
 * @global array $zz_page
 * @return void
 */
function wrap_send_text($text, $type = 'html', $status = 200, $headers = []) {
	global $zz_page;

	$filetype_cfg = wrap_filetypes($type);

	// positions: text might be array
	if (is_array($text) AND count($text) === 1) $text = array_shift($text);
	if (is_array($text) AND $type !== 'html') {
		// disregard webpage content on other positions
		if (array_key_exists('text', $text))
			$text = $text['text'];
		else 
			$text = array_shift($text);
	}
	if (empty($filetype_cfg['no_trim']))
		$text = trim($text);

	if (wrap_setting('gzip_encode'))
		wrap_cache_header('Vary: Accept-Encoding');
	header_remove('Accept-Ranges');

	// Content-Type HTTP header
	if ($filetype_cfg) {
		$zz_page['content_type'] = $filetype_cfg['mime'][0];
		$mime = explode('/', $zz_page['content_type']);
		if (in_array($mime[0], ['text', 'application'])) {
			$zz_page['character_set']
				= $filetype_cfg['encoding'] ?? $headers['character_set'] ?? wrap_setting('character_set');
			if ($zz_page['character_set'] === 'utf-16le') {
				// Add BOM, little endian
				$text = chr(255).chr(254).$text;
			}
		}
		if (!empty($zz_page['character_set'])) {
			wrap_cache_header(sprintf('Content-Type: %s; charset=%s', $zz_page['content_type'], 
				$zz_page['character_set']));
		} else {
			wrap_cache_header(sprintf('Content-Type: %s', $zz_page['content_type']));
		}
	}

	// Content-Disposition HTTP header
	if (!empty($filetype_cfg['content_disposition'])) {
		wrap_http_content_disposition(
			$filetype_cfg['content_disposition'],
			$headers['filename'] ?? 'download.'.$filetype_cfg['extension'][0]
		);
	}
	if ($type === 'csv') {
		if (!empty($_SERVER['HTTP_USER_AGENT']) 
			AND strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE'))
		{
			wrap_cache_header('Cache-Control: max-age=1'); // in seconds
			wrap_cache_header('Pragma: public');
		}
	}

	// Content-Length HTTP header
	// might be overwritten later
	$zz_page['content_length'] = strlen($text);
	wrap_cache_header('Content-Length: '.$zz_page['content_length']);

	// ETag HTTP header
	// check whether content is identical to previously sent content
	// @todo: do not send 304 immediately but with a Last-Modified header
	$etag_header = [];
	if ($status === 200) {
		// only compare ETag in case of status 2xx
		$zz_page['etag'] = md5($text);
		$etag_header = wrap_if_none_match($zz_page['etag']);
	}

	$last_modified_time = time();
	if (!empty($_SERVER['REQUEST_TIME'])
		AND $_SERVER['REQUEST_TIME'] < $last_modified_time) {
		$last_modified_time = $_SERVER['REQUEST_TIME'];
	}

	// send all headers
	wrap_cache_header();
	if (wrap_setting('cache') AND !isset($_GET['nocache'])) {
		wrap_cache_header_default(sprintf('Cache-Control: max-age=%d', wrap_setting('cache_control_text')));
	} else {
		wrap_cache_header_default('Cache-Control: max-age=0');
	}

	// Caching?
	if (wrap_setting('cache') AND $status === 200) {
		$cache_saved = wrap_cache($text, $zz_page['etag']);
		if ($cache_saved === false) {
			// identical cache file exists
			// set older value for Last-Modified header
			$doc = wrap_cache_filename();
			if ($time = filemtime($doc)) // if it exists
				$last_modified_time = $time;
		}
	} elseif (wrap_setting('cache')) {
		wrap_cache_delete($status);
	}

	// Last Modified HTTP header
	wrap_if_modified_since($last_modified_time, $status);

	wrap_send_ressource('memory', $text, $etag_header);
}

/**
 * Send a HTTP Content-Disposition-Header with a filename
 *
 * @param string $type 'inline' or 'attachment'
 * @param string $filename
 * @return void
 */
function wrap_http_content_disposition($type, $filename) {
	// no double quotes in filenames
	$filename = str_replace('"', '', $filename);

	// RFC 2616: filename must consist of all ASCII characters
	$filename_ascii = wrap_filename($filename, ' ', ['.' => '.']);
	$filename_ascii = preg_replace('/[^(\x20-\x7F)]*/', '', $filename_ascii);

	// RFC 5987: filename* may be sent with UTF-8 encoding
	if (wrap_setting('character_set') !== 'utf-8')
		$filename = mb_convert_encoding($filename, 'UTF-8', wrap_setting('character_set'));

	if ($filename_ascii !== $filename) {
		wrap_cache_header(sprintf(
			'Content-Disposition: %s; filename="%s"; filename*=utf-8\'\'%s',
			$type, $filename_ascii, rawurlencode($filename))
		);
	} else {
		wrap_cache_header(sprintf('Content-Disposition: %s; filename="%s"', $type, $filename_ascii));
	}
}

/**
 * Sends the ressource to the browser after all headers have been sent
 *
 * @param string $type 'memory' = content is in memory, 'file' => is in file
 * @param mixed $content full content or array $file, depending on $type
 * @param array $etag_header
 */
function wrap_send_ressource($type, $content, $etag_header = []) {
	global $zz_page;

	// remove internal headers
	header_remove('X-Local-Filename');

	// HEAD HTTP request
	if (strtoupper($_SERVER['REQUEST_METHOD']) === 'HEAD') {
		if ($type === 'file') wrap_file_cleanup($content);
		wrap_log_uri();
		exit;
	}

	// Since we do gzip compression on the fly, we cannot guarantee
	// that ranges work with gzip compression (bytes will be different).
	// Using gzip won't give you any advantage for most binary files, so
	// we do not use gzip for these. On the other hand, we won't allow ranges
	// for text content. So we do not have gzip and ranges at the same time.

	// Text output, with gzip, without ranges
	if ($type === 'memory' OR !empty($content['gzip'])) {
		wrap_log_uri();
		if ($type === 'file') {
			$content = file_get_contents($content['name']);
		}
		// output content
		if (wrap_setting('gzip_encode')) {
			wrap_send_gzip($content, $etag_header);
		} else {
			echo $content;
		}
		exit;
	}
	
	// Binary output, without gzip, with ranges
	// Ranges HTTP header field
	ignore_user_abort(1); // make sure we can delete temporary files at the end
	$chunksize = 1 * (1024 * 16); // how many bytes per chunk
	$ranges = wrap_ranges_check($zz_page);
	if (!$ranges) {
		wrap_log_uri();
		// no ranges: resume to normal

		// following block and server lighttpd: replace with
		// header('X-Sendfile: '.$content['name']);
	
		// If it's a large file we don't want the script to timeout, so:
		set_time_limit(300);
		// If it's a large file, readfile might not be able to do it in one go, so:
		if ($zz_page['content_length'] > $chunksize) {
			$handle = fopen($content['name'], 'rb');
			if ($handle) {
				$buffer = '';
				ob_start();
				while (!feof($handle) AND !connection_aborted()) {
					$buffer = fread($handle, $chunksize);
					print $buffer;
					ob_flush();
					flush();
				}
				fclose($handle);
			} else {
				wrap_error(wrap_text('Unable to open file %s', ['values' => [$content['name']]]), E_USER_ERROR);
			}
		} else {
			readfile($content['name']);
		}
	} else {
		wrap_log_uri(206); // @todo log correct range content length
		if (count($ranges) !== 1) {
			$boundary = 'THIS_STRING_SEPARATES_'.md5(time());
			header(sprintf('Content-Type: multipart/byteranges; boundary=%s', $boundary));
			$bottom = "--".$boundary."--\r\n";
			$content_length_total = strlen($bottom);
			$separator = "\r\n\r\n";
			$content_length_total += strlen($separator) * count($ranges);
		}
		$handle = fopen($content['name'], 'rb');
		$top = [];
		foreach ($ranges as $range) {
			$length = $range['end'] - $range['start'] + 1;
			$content_range = sprintf('Content-Range: bytes %u-%u/%u', $range['start'],
				$range['end'], $zz_page['content_length']);
			$content_length = sprintf('Content-Length: %u', $length);
			if (count($ranges) !== 1) {
				$content_length_total += $length;
				$top_text = "--".$boundary."\r\n"
					.'Content-Type: '.$zz_page['content_type']."\r\n"
					.$content_range."\r\n"
					.$content_length."\r\n"
					."\r\n";
				$content_length_total += strlen($top_text);
				$top[] = $top_text;
			} else {
				header($content_range);
				header($content_length);
			}
		}
		if (count($ranges) !== 1) {
			header('Content-Length: '.$content_length_total);
		}
		foreach ($ranges as $index => $range) {
			if (count($ranges) !== 1) {
				echo $top[$index];
			}
			$current = $range['start'];
			fseek($handle, $current, SEEK_SET);
			while (!feof($handle) AND $current < $range['end'] AND !connection_aborted()) {
				print fread($handle, min($chunksize, $range['end'] - $current + 1));
				$current += $chunksize;
				flush();
			}
			if (count($ranges) !== 1) {
				echo $separator;
			}
		}
		if (count($ranges) !== 1) echo $bottom;
		fclose($handle);
	}
	wrap_file_cleanup($content);
	exit;
}

/**
 * Checks whether ressource should be sent in ranges of bytes
 *
 * @param array $zz_page
 * @return array
 */
function wrap_ranges_check($zz_page) {
	if (empty($_SERVER['HTTP_RANGE'])) return [];

	// check if Range is syntactically valid
	// if invalid, return 200 + full content
	// Range: bytes=10000-49999,500000-999999,-250000
	if (!preg_match('~^bytes=\d*-\d*(,\d*-\d*)*$~', $_SERVER['HTTP_RANGE'])) {
		header('Content-Range: bytes */'.$zz_page['content_length']);
		wrap_quit(416);
	}

	if (!empty($_SERVER['HTTP_IF_UNMODIFIED_SINCE']) OR !empty($_SERVER['HTTP_IF_MATCH'])) {
		// Range + (If-Unmodified-Since OR If-Match), have already been checked
		// go on
	} elseif (!empty($_SERVER['HTTP_IF_RANGE'])) {
		// Range + If-Range (ETag or Date)
		$etag_header = wrap_etag_header($zz_page['etag'] ?? '');
		$time = wrap_date($_SERVER['HTTP_IF_RANGE'], 'rfc1123->timestamp');
		if ($_SERVER['HTTP_IF_RANGE'] === $etag_header['std']
			OR $_SERVER['HTTP_IF_RANGE'] === $etag_header['gz']) {
			// go on
		} elseif ($time AND $time >= $zz_page['last_modified']) {
			// go on
		} else {
			// - no match: 200 + full content
			return [];
		}
	}
	
	// - if Range not valid	416 (Requested range not satisfiable), Content-Range: *
	// - else 206 + partial content
	$raw_ranges = explode(',', substr($_SERVER['HTTP_RANGE'], 6));
	$ranges = [];
	foreach ($raw_ranges as $range) {
		$parts = explode('-', $range);
		$start = $parts[0];
		if (!$start) $start = 0;
		$end = $parts[1];
		if (!$end) {
			$end = $zz_page['content_length'] - 1;
		} elseif ($end > $zz_page['content_length']) {
			$end = $zz_page['content_length'] - 1;
		}
        if ($start > $end) {
            header('Content-Range: bytes */'.$zz_page['content_length']);
			wrap_quit(416);
        }
        $ranges[] = [
        	'start' => $start,
        	'end' => $end
        ];
    }
	wrap_http_status_header(206);
	return $ranges;
}

/**
 * Send a ressource with gzip compression
 *
 * @param string $text content of ressource, not compressed
 * @param array $etag_header
 * @return void
 */
function wrap_send_gzip($text, $etag_header) {
	// start output
	ob_start();
	ob_start('ob_gzhandler');
	echo $text;
	ob_end_flush();  // The ob_gzhandler one
	if ($etag_header) {
		// only if HTTP status = 200
		if (!empty($_SERVER['HTTP_ACCEPT_ENCODING'])
			AND strstr($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
			// overwrite ETag with -gz ending
			header('ETag: '.$etag_header['gz']);
		}
	}
	header('Content-Length: '.ob_get_length());
	ob_end_flush();  // The main one
}


/*
 * --------------------------------------------------------------------
 * Common functions
 * --------------------------------------------------------------------
 */

/**
 * returns integer byte value from PHP shorthand byte notation
 *
 * @param string $val
 * @return int
 */
function wrap_return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    if (!is_numeric($last)) $val = substr($val, 0, -1);
    switch($last) {
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
 * checks if a substring from beginning or end of string matches given string
 *
 * @param string $string = check against this string
 * @param string $substring = this must be beginning of string
 * @param string $mode: begin: check from begin; end: check from end
 * @param bool true: first letters of $string match $substring
 * @deprecated, use str_starts_with(), str_ends_with()
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
 * keep static variables
 *
 * @param array $var the variable to change
 * @param string $key key to change
 * @param mixed $value new value
 * @param string $action what to do (init, set (default), add, prepend, append, delete)
 * @return array
 */
function wrap_static($var, $key = '', $value = NULL, $action = 'set') {
	static $data = [];
	if (!array_key_exists($var, $data)) $data[$var] = [];
	
	if ($value !== NULL) {
		switch ($action) {
		case 'init':
			$data[$var] = $value;
			break;
		case 'set':
			$data[$var][$key] = $value;
			break;
		case 'add':
			if (!array_key_exists($key, $data[$var])) $data[$var][$key] = [];
			if (is_array($value)) $data[$var][$key] = array_merge($data[$var][$key], $value);
			else $data[$var][$key][] = $value;
			break;
		case 'prepend':
			if (empty($data[$var][$key])) $data[$var][$key] = $value;
			else $data[$var][$key] = $value.$data[$var][$key];
			break;
		case 'append':
			if (empty($data[$var][$key])) $data[$var][$key] = $value;
			else $data[$var][$key] .= $value;
			break;
		case 'delete':
			unset($data[$var][$key]);
			break;
		}
	}

	if (!$key) return $data[$var];
	if (!array_key_exists($key, $data[$var])) return NULL;
	return $data[$var][$key];	
}

/**
 * read or write settings
 *
 * @param string $key
 * @param mixed $value (if set, assign value, if not read value)
 * @param int $login_id (optional, get setting for user)
 * @return mixed
 * @todo support writing of setting per login ID (necessary?)
 * @todo support array structure like setting[subsetting] for $key
 */
function wrap_setting($key, $value = NULL, $login_id = NULL) {
	global $zz_setting;
	// write?
	if (isset($value) AND !isset($login_id)) {
		$value = wrap_setting_value($value);
		if (strstr($key, '['))
			$zz_setting = wrap_setting_key($key, $value, $zz_setting);
		else
			$zz_setting[$key] = $value;
	}
	// read
	return wrap_get_setting($key, $login_id);
}

/**
 * add setting to a list
 *
 * @param string $key
 * @param mixed $value
 */
function wrap_setting_add($key, $value) {
	if (!is_array(wrap_setting($key)))
		wrap_error(sprintf('Unable to add value %s to key %s, it is not an array.', $key, json_encode($value)), E_USER_WARNING);

	$existing = wrap_setting($key);
	if (is_array($value))
		$existing = array_merge($existing, $value);
	else
		$existing[] = $value;
	wrap_setting($key, $existing);
}

/**
 * delete setting
 *
 * @param string $key
 */
function wrap_setting_delete($key) {
	global $zz_setting;
	unset($zz_setting[$key]);
}

/**
 * gets setting from configuration (default: zz_setting)
 *
 * @param string $key
 * @param int $login_id
 * @return mixed $setting (if not found, returns NULL)
 */
function wrap_get_setting($key, $login_id = 0) {
	global $zz_setting;

	$cfg = wrap_cfg_files('settings');
	if (!$login_id AND $value = wrap_get_setting_local($key, $cfg)) return $value;

	// check if in settings.cfg
	wrap_setting_log_missing($key, $cfg);

	// setting is set in $zz_setting (from _settings-table or directly)
	// if it is an array, check if all keys are there later
	if (isset($zz_setting[$key]) AND !$login_id
		AND (!is_array($zz_setting[$key]) OR is_numeric(key($zz_setting[$key])))
	) {
		$zz_setting[$key] = wrap_get_setting_prepare($zz_setting[$key], $key, $cfg);
		return $zz_setting[$key];
	}

	// shorthand notation with []?
	$shorthand = wrap_setting_key_read($zz_setting, $key);
	if (isset($shorthand)) return $shorthand;

	// read setting from database
	if (!wrap_db_connection() AND $login_id) {
		$values = wrap_setting_read($key, $login_id);
		if (array_key_exists($key, $values)) {
			return wrap_get_setting_prepare($values[$key], $key, $cfg);
		}
	}

	// default value set in one of the current settings.cfg files?
	if (array_key_exists($key, $cfg) AND !isset($zz_setting[$key])) {
		$default = wrap_get_setting_default($key, $cfg[$key]);
		return wrap_get_setting_prepare($default, $key, $cfg);
	} elseif ($pos = strpos($key, '[') AND array_key_exists(substr($key, 0, $pos), $cfg)) {
		$default = wrap_get_setting_default($key, $cfg[substr($key, 0, $pos)]);
		$sub_key = substr($key, $pos +1, -1);
		if (is_array($default) AND array_key_exists($sub_key, $default)) {
			$default = $default[$sub_key];
			return wrap_get_setting_prepare($default, $key, $cfg);
		}
		// @todo add support for key[sub_key][sub_sub_key] notation
	}

	// check for keys that are arrays
	$my_keys = [];
	foreach ($cfg as $cfg_key => $cfg_value) {
		if (!str_starts_with($cfg_key, $key.'[')) continue;
		$my_keys[] = $cfg_key;
	}

	$return = [];
	foreach ($my_keys as $my_key) {
		$return = array_merge_recursive($return, wrap_setting_key($my_key, wrap_get_setting_default($my_key, $cfg[$my_key])));
	}
	if (!empty($return[$key])) {
		// check if some of the keys have already been set, overwrite these
		if (isset($zz_setting[$key])) {
			$return[$key] = array_merge($return[$key], $zz_setting[$key]);
		}
		return $return[$key];
	} else {
		// no defaults, so return existing settings unchanged
		if (isset($zz_setting[$key])) return $zz_setting[$key];
	}
	return NULL;
}

/**
 * check if a local setting is available
 * ending with _local
 *
 * @param string $key
 * @param array $cfg
 * @return mixed
 */
function wrap_get_setting_local($key, $cfg) {
	global $zz_setting;
	if (empty($zz_setting['local_access'])) return NULL;

	static $keys = [];
	if (in_array($key, $keys)) return NULL; // already tried
	$keys[] = $key;

	$parts = explode('[', $key);
	if (str_ends_with($parts[0], '_local')) return NULL; // is already local
	$parts[0] .= '_local';
	$key = implode('[', $parts);
	if (!array_key_exists($key, $cfg)) return NULL;
	return wrap_get_setting($key);
}

/**
 * gets default value from .cfg file
 *
 * @param string $key
 * @param array $params = $cfg[$key]
 * @return mixed $setting (if not found, returns NULL)
 */
function wrap_get_setting_default($key, $params) {
	global $zz_conf;

	if (!empty($params['default_from_php_ini']) AND ini_get($params['default_from_php_ini'])) {
		return ini_get($params['default_from_php_ini']);
	} elseif (!empty($params['default_empty_string'])) {
		return '';
	} elseif (!empty($params['default'])) {
		return wrap_setting_value($params['default']);
	} elseif (!empty($params['default_from_setting'])) {
		if (str_starts_with($params['default_from_setting'], 'zzform_')) {
			$default_setting_key = substr($params['default_from_setting'], 7);
			if (is_array($zz_conf) AND array_key_exists($default_setting_key, $zz_conf))
				return $zz_conf[$default_setting_key];
			else
				return wrap_setting($params['default_from_setting']);
		} elseif (!is_null(wrap_setting($params['default_from_setting']))) {
			return wrap_setting($params['default_from_setting']);
		}
	}
	if (!empty($params['default_from_function']) AND function_exists($params['default_from_function'])) {
		return $params['default_from_function']();
	}
	if (!wrap_db_connection() AND !empty($params['brick'])) {
		$path = wrap_setting_path($key, $params['brick']);
		if ($path) return wrap_setting($key);
	}
	return NULL;
}

/**
 * prepare setting before returning, according to settings.cfg
 *
 * @param mixed $setting
 * @param string $key
 * @param array $cfg
 * @return mixed
 */
function wrap_get_setting_prepare($setting, $key, $cfg) {
	if (!array_key_exists($key, $cfg)) return $setting;

	// depending on type
	if (!empty($cfg[$key]['type']))
		switch ($cfg[$key]['type']) {
			case 'bytes': $setting = wrap_byte_to_int($setting); break;
		}
	
	// list = 1 means values need to be array!
	if (!empty($cfg[$key]['list']) AND !is_array($setting)) {
		if (!empty($cfg[$key]['levels'])) {
			$levels = wrap_setting_value($cfg[$key]['levels']);
			$return = [];
			foreach ($levels as $level) {
				$return[] = $level;
				if ($level === $setting) break;
			}
			$setting = $return;
		} elseif ($setting) {
			$setting = [$setting];
		} else {
			$setting = [];
		}
	}
	return $setting;
}

/**
 * log missing keys in settings.cfg
 *
 * @param string $key
 * @param array $cfg
 * @return void
 */
function wrap_setting_log_missing($key, $cfg) {
	global $zz_setting;
	if (empty($zz_setting['debug'])) return;

	$base_key = $key;
	if ($pos = strpos($base_key, '[')) $base_key = substr($base_key, 0, $pos);
	if (array_key_exists($base_key, $cfg)) return;
	$log_dir = $zz_setting['log_dir'] ?? false;
	if (!$log_dir AND !empty($cfg['log_dir']['default']))
		$log_dir = wrap_setting_value_placeholder($cfg['log_dir']['default']);
	if (!$log_dir) return;
	error_log(sprintf("%s: Setting %s not found in settings.cfg [%s].\n", date('Y-m-d H:i:s'), $base_key, $_SESSION['username'] ?? wrap_setting('remote_ip')), 3, $log_dir.'/settings.log');
}


/** 
 * Merges Array recursively: replaces old with new keys, adds new keys
 * 
 * @param array $old			Old array
 * @param array $new			New array
 * @param bool $overwrite_with_empty (optional, if false: empty values do not overwrite existing ones)
 * @return array $merged		Merged array
 */
function wrap_array_merge($old, $new, $overwrite_with_empty = true) {
	foreach ($new as $index => $value) {
		if (is_array($value)) {
			if (!empty($old[$index])) {
				$old[$index] = wrap_array_merge($old[$index], $new[$index], $overwrite_with_empty);
			} else
				$old[$index] = $new[$index];
		} else {
			if (is_numeric($index) AND (!in_array($value, $old))) {
				// numeric keys will be appended, if new
				$old[] = $value;
			} else {
				// named keys will be replaced
				if ($overwrite_with_empty OR $value OR empty($old[$index]))
					$old[$index] = $value;
			}
		}
	}
	return $old;
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
	$indexed_by_main = [];
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
	if (!array_key_exists($top_id, $indexed_by_main)) {
		wrap_error(sprintf(
			'Creating hierarchy impossible because ID %d is not part of the given list'
			, $top_id)
		);
		return [];
	}
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

/**
 * Creates a folder and its top folders if neccessary
 *
 * @param string $folder (may contain .. and . which will be resolved)
 * @return mixed 
 * 	bool true: folder creation was successful
 * 	array: list of folders
 */
function wrap_mkdir($folder) {
	$created = [];
	if (is_dir($folder)) return true;

	// check if open_basedir restriction is in effect
	$allowed_dirs = explode(':', ini_get('open_basedir'));
	if ($allowed_dirs) {
		$basefolders = [];
		foreach ($allowed_dirs as $dir) {
			if (substr($folder, 0, strlen($dir)) === $dir) {
				$basefolders = array_filter(explode('/', $dir), 'strlen');
				$basefolders = array_values($basefolders);
				break;
			}
		}
	}
	$parts = array_filter(explode('/', $folder), 'strlen');
	$current_folder = '';

	// get rid of .. and .
    $subfolders = [];
    foreach ($parts as $part) {
        if ($part === '.') continue;
        if ($part === '..')
            array_pop($subfolders);
        else
            $subfolders[] = $part;
    }

	foreach ($subfolders as $index => $subfolder) {
		if ($subfolder === '') continue;
		$current_folder .= '/'.$subfolder;
		if (!empty($basefolders[$index]) AND $basefolders[$index] === $subfolder) {
			// it's in open_basedir, so folder should exist and we cannot
			// test whether it exists anyways
			continue;
		}
		if (!file_exists($current_folder)) {
			$success = mkdir($current_folder);
			if (!$success) {
				wrap_error(sprintf('Unable to create folder %s.', $current_folder), E_USER_WARNING);
				return false;
			}
			$created[] = $current_folder;
		}
		// check if folder is a folder, not a file
		// might happen e. g. in caching for non-existing URLs with paths below non-existing URLs
		if (!is_dir($current_folder)) {
			wrap_error(sprintf('Unable to create folder %s.', $current_folder), E_USER_WARNING);
			return false;
		}
	}
	return $created;
}

/**
 * call a job in the background, either with job manager or directly
 *
 * @param string $url
 * @param array $data (optional, further values for _jobqueue table)
 * @return bool
 */
function wrap_job($url, $data = []) {
	$path = wrap_path('jobmanager', '', false);
	if (!$path) $path = $url;
	$data['url'] = $url;
	if (!empty($data['trigger']))
		list($status, $headers, $response) = wrap_trigger_protected_url($path, false, true, $data);
	else
		list($status, $headers, $response) = wrap_get_protected_url($path, [], 'POST', $data);
	if ($status === 200) return true;
	wrap_error(sprintf('Job with URL %s failed. (Status: %d, Headers: %s)', $url, $status, json_encode($headers)));
	return false;
}

/**
 * add scheme and hostname to URL if missing
 * look for `admin_hostname`
 *
 * @param string $url
 * @return string
 */
function wrap_job_url_base($url) {
	if (!str_starts_with($url, '/')) return $url;
	if (!wrap_setting('admin_hostname')) return wrap_setting('host_base').$url;

	$hostname = wrap_setting('admin_hostname');
	if (str_ends_with(wrap_setting('hostname'), '.local')) $hostname .= '.local';
	elseif (str_starts_with(wrap_setting('hostname'), 'dev.')) $hostname = 'dev.'.$hostname;
	elseif (str_starts_with(wrap_setting('hostname'), 'dev-')) $hostname = 'dev-'.$hostname;
	return wrap_setting('protocol').'://'.$hostname.$url;
}

/**
 * check if a job can be started
 *
 * @param string $type
 * @return array
 */
function wrap_job_check($type) {
	if (!wrap_job_page($type)) return true;
	// check if jobmanager is present; since function is called regularly,
	// do not use wrap_path() which needs a database connection
	if (!wrap_setting('jobmanager_path')) return false;
	return mod_default_make_jobmanager_check();
}

/**
 * automatically finish a job
 *
 * @param array $job
 * @param string $type
 * @param array $content
 * @return void
 */
function wrap_job_finish($job, $type, $content) {
	if (!wrap_job_page($type)) return true;
	if (!wrap_setting('jobmanager_path')) return false;

	if (!$content) {
		$content = [
			'status' => 404,
			'text' => wrap_text('not found')
		];
	}
	
	if (!empty($content['content_type']) AND $content['content_type'] === 'json')
		$content['text'] = json_decode($content['text']);
	if (!empty($_POST['job_logfile_result'])) {
		wrap_include('file', 'zzwrap');
		wrap_file_log($_POST['job_logfile_result'], 'write', [time(), $content['extra']['job'] ?? 'job', json_encode($content['data'] ?? $content['text'])]);
	}
	if (!empty($_POST['job_url_next']))
		wrap_trigger_protected_url($_POST['job_url_next'], wrap_username($job['username'] ?? '', false));
	
	mod_default_make_jobmanager_finish($job, $content['status'] ?? 200, $content['text']);
}

/**
 * is page a job page?
 *
 * @param string $type
 * @return bool
 */
function wrap_job_page($type) {
	global $zz_page;
	if ($type !== 'make') return false;
	if (empty($zz_page['db']['parameters']['job'])) return false;
	
	$path = wrap_path('jobmanager', '', false);
	if (!$path) return false; // no job manager active

	wrap_include('zzbrick_make/jobmanager', 'default');
	return true;
}

/**
 * trigger next job, independent from current/last job
 * use new hash for that
 *
 * @param string $url
 * @return void
 */
function wrap_job_next($url) {
	wrap_trigger_protected_url($url, false, true, ['regenerate_hash' => 1]);
}

/**
 * call a website in the background via http
 * https is not supported
 *
 * @param string $url
 * @return array $page
 */
function wrap_trigger_url($url) {
	$port = 80;
	if (substr($url, 0, 1) === '/') {
		global $zz_page;
		$host = $zz_page['url']['full']['host'];
		$path = $url;
	} else {
		$parsed = parse_url($url);
		if ($parsed['scheme'] !== 'http') {
			$page['status'] = 503;
			$page['text'] = sprintf('Scheme %s not supported.', wrap_html_escape($parsed['scheme']));
			return $page;
		}
		if ($parsed['user'] OR $parsed['pass']) {
			$page['status'] = 503;
			$page['text'] = 'Authentication not supported.';
			return $page;
		}
		if ($parsed['port']) $port = $parsed['port'];
		$host = $parsed['host'];
		$path = $parsed['path'].($path['query'] ? '?'.$path['query'] : '');
	}
	$fp = fsockopen($host, $port);
	if ($fp === false) {
		$page['status'] = 503;
		$page['text'] = sprintf('Connection to server %s failed.', wrap_html_escape($host));
		return $page;
	}
	$out = "GET ".$path." HTTP/1.1\r\n";
	$out .= "Host: ".$host."\r\n";
	$out .= "Connection: Close\r\n\r\n";
	// @todo retry if 503 error in 10 seconds
	fwrite($fp, $out);
	// read at least one byte because some servers won't establish a connection
	// otherwise
	fread($fp, 1);
	fclose($fp);
	$page['text'] = 'Connection successful.';
	return $page;
}

/**
 * trigger a protected URL
 *
 * @param string $url
 * @param string $username (optional)
 * @param bool $send_lock defaults to true, send lock hash to child process
 * @param array $data (optional)
 * @return array from wrap_syndication_retrieve_via_http()
 */
function wrap_trigger_protected_url($url, $username = false, $send_lock = true, $data = []) {
	$username = wrap_username($username, false);
	if (wrap_setting('log_trigger')) {
		$logfile = wrap_setting('log_trigger') === true ? '' : wrap_setting('log_trigger');
		wrap_log(
			sprintf('trigger URL %s %s -> %s', date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']), wrap_setting('request_uri'), $url)
			, E_USER_NOTICE, $logfile
		);
	}
	$headers[] = 'X-Timeout-Ignore: 1';
	if (function_exists('wrap_lock_hash') AND $send_lock) {
		$headers[] = sprintf('X-Lock-Hash: %s', wrap_lock_hash($data['regnerate_hash'] ?? false));
	}
	return wrap_get_protected_url($url, $headers, 'POST', $data, $username);
}

/**
 * get a protected URL
 *
 * settings: login_key, login_key_validity_in_minutes must be set
 * @param string $url
 * @param array $headers
 * @param string $method
 * @param array $data
 * @param string $username (optional)
 * @return array from wrap_syndication_retrieve_via_http()
 */

function wrap_get_protected_url($url, $headers = [], $method = 'GET', $data = [], $username = false) {
	$username = wrap_username($username, false);
	$pwd = sprintf('%s:%s', $username, wrap_password_token($username));
	$headers[] = 'X-Request-WWW-Authentication: 1';
	// localhost: JSON
	if (wrap_get_protected_url_local($url) AND wrap_get_protected_url_html($url))
		$headers[] = 'Accept: application/json';
	$url = wrap_job_url_base($url);

	require_once __DIR__.'/syndication.inc.php';
	$result = wrap_syndication_retrieve_via_http($url, $headers, $method, $data, $pwd);
	return $result;
}

/**
 * is URL on a local or admin server?
 *
 * @param string $url
 * @return bool true: local or admin server, false: remote server
 */
function wrap_get_protected_url_local($url) {
	if (str_starts_with($url, '/')) return true;
	if (str_starts_with($url, wrap_setting('host_base'))) return true;
	if (!wrap_setting('admin_hostname')) return false;
	if (str_starts_with($url, wrap_setting('protocol').'://'.wrap_setting('admin_hostname').'/')) return true;
	if (str_starts_with($url, wrap_setting('protocol').'://dev.'.wrap_setting('admin_hostname').'/')) return true;
	if (str_starts_with($url, wrap_setting('protocol').'://'.wrap_setting('admin_hostname').'.local/')) return true;
	return false;		
}

/**
 * is URL most likely a HTML resource? check ending for that
 *
 * @param string $url
 * @return bool true: probably is HTML, false: no HTML
 */
function wrap_get_protected_url_html($url) {
	$path = parse_url($url, PHP_URL_PATH);
	if (!$path) return true; // homepage
	if (str_ends_with($path, '/')) return true;
	if (str_ends_with($path, '.html')) return true;
	if (str_ends_with($path, '.htm')) return true;
	$path = explode('/', $path);
	if (!strstr(end($path), '.')) return true;
	return false;
}

/**
 * check if a number is an integer or a string with an integer in it
 *
 * @param mixed $var
 * @return bool
 */
function wrap_is_int($var) {
	if (!is_numeric($var)) return false;
	$i = intval($var);
	if ("$i" === "$var") {
  		return true;
	} else {
    	return false;
    }
}

/**
 * write settings to database
 *
 * @param string $key
 * @param string $value
 * @param int $login_id (optional)
 * @return bool
 */
function wrap_setting_write($key, $value, $login_id = 0) {
	$existing_setting = wrap_setting_read($key, $login_id);
	if ($existing_setting) {
		// support for keys that are arrays
		$new_setting = wrap_setting_key($key, wrap_setting_value($value));
		if ($existing_setting === $new_setting) return false;
		$sql = 'UPDATE /*_PREFIX_*/%s_settings SET setting_value = "%%s" WHERE setting_key = "%%s"';
		if (wrap_setting('multiple_websites') AND !$login_id)
			$sql .= sprintf(' AND website_id IN (1, %d)', wrap_setting('website_id'));
		$sql = sprintf($sql, $login_id ? 'logins' : '');
		$sql = sprintf($sql, wrap_db_escape($value), wrap_db_escape($key));
		$sql .= wrap_setting_login_id($login_id);
	} elseif ($login_id) {
		$sql = 'INSERT INTO /*_PREFIX_*/logins_settings (setting_value, setting_key, login_id) VALUES ("%s", "%s", %s)';
		$sql = sprintf($sql, wrap_db_escape($value), wrap_db_escape($key), $login_id);
	} else {
		$cfg = wrap_cfg_files('settings');
		$explanation = (in_array($key, array_keys($cfg)) AND !empty($cfg[$key]['description']))
			? sprintf('"%s"', $cfg[$key]['description'])  : 'NULL';
		if (wrap_setting('multiple_websites'))
			$sql = 'INSERT INTO /*_PREFIX_*/_settings (setting_value, setting_key, explanation, website_id) VALUES ("%s", "%s", %s, %d)';
		else
			$sql = 'INSERT INTO /*_PREFIX_*/_settings (setting_value, setting_key, explanation) VALUES ("%s", "%s", %s)';
		if (wrap_setting('multiple_websites'))
			$sql = sprintf($sql, wrap_db_escape($value), wrap_db_escape($key), $explanation, wrap_setting('website_id'));
		else
			$sql = sprintf($sql, wrap_db_escape($value), wrap_db_escape($key), $explanation);
	}
	$result = wrap_db_query($sql);
	if ($result) {
		if (wrap_include('database', 'zzform')) {
			wrap_setting('log_username_default', 'Servant Robot 247');
			zz_log_sql($sql, '', $result['id'] ?? false);
			wrap_setting_delete('log_username_default');
		}
		// activate setting
		if (!$login_id) wrap_setting($key, $value);
		return true;
	}

	wrap_error(sprintf(
		wrap_text('Could not change setting. Key: %s, value: %s, login: %s'),
		wrap_html_escape($key), wrap_html_escape($value), $login_id
	));	
	return false;
}

/**
 * read settings from database
 *
 * @param string $key (* at the end used as wildcard)
 * @param int $login_id (optional)
 * @return array
 */
function wrap_setting_read($key, $login_id = 0) {
	static $setting_table = '';
	static $login_setting_table = '';
	static $settings = [];
	if (array_key_exists($login_id, $settings))
		if (array_key_exists($key, $settings[$login_id]))
			return $settings[$login_id][$key];

	if (!$login_id AND !$setting_table) {
		$setting_table = wrap_database_table_check('_settings');
		if (!$setting_table) return [];
	} elseif ($login_id AND !$login_setting_table) {
		$login_setting_table = wrap_database_table_check('logins_settings');
		if (!$login_setting_table) return [];
	}
	$sql = 'SELECT setting_key, setting_value
		FROM /*_PREFIX_*/%s_settings
		WHERE setting_key %%s "%%s"';
	if (wrap_setting('multiple_websites') AND !$login_setting_table)
		$sql .= sprintf(' AND website_id IN (1, %d)', wrap_setting('website_id'));
	$sql = sprintf($sql, $login_id ? 'logins' : '');
	if (substr($key, -1) === '*') {
		$sql = sprintf($sql, 'LIKE', substr($key, 0, -1).'%');
	} else {
		$sql = sprintf($sql, '=', $key);
	}
	$sql .= wrap_setting_login_id($login_id);
	$settings_raw = wrap_db_fetch($sql, 'setting_key', 'key/value');
	$settings[$login_id][$key] = [];
	foreach ($settings_raw as $skey => $value) {
		$settings[$login_id][$key]
			= array_merge_recursive($settings[$login_id][$key], wrap_setting_key($skey, wrap_setting_value($value)));
	}
	return $settings[$login_id][$key];
}

/**
 * add login_id or not to setting query
 *
 * @param int $login_id (optional)
 * @return string WHERE query part
 */
function wrap_setting_login_id($login_id = 0) {
	if (!$login_id) return '';
	return sprintf(' AND login_id = %d', $login_id);
}

/**
 * sets key/value pairs, key may be array in form of
 * key[subkey], value may be array in form (1, 2, 3)
 *
 * @param string $key
 * @param string $value
 * @param array $settings (existing settings or no settings)
 * @return array
 */
function wrap_setting_key($key, $value, $settings = []) {
	if (!strstr($key, '[')) {
		$settings[$key] = $value;
		return $settings;
	}

	$keys = wrap_setting_key_array($key);
	switch (count($keys)) {
		case 2:
			$settings[$keys[0]][$keys[1]] = $value;
			break;
		case 3:
			$settings[$keys[0]][$keys[1]][$keys[2]] = $value;
			break;
		case 4:
			$settings[$keys[0]][$keys[1]][$keys[2]][$keys[3]] = $value;
			break;
		default:
			wrap_error(sprintf('Too many arrays in %s, not implemented.', $key), E_USER_ERROR);
	}
	return $settings;
}

/**
 * reads key/value pairs, key may be array in form of key[subkey]
 *
 * @param array $source
 * @param string $key
 * @return mixed
 */
function wrap_setting_key_read($source, $key) {
	if (!strstr($key, '['))
		return $source[$key] ?? NULL;

	$keys = wrap_setting_key_array($key);
	switch (count($keys)) {
		case 2:
			return $source[$keys[0]][$keys[1]] ?? NULL;
		case 3:
			return $source[$keys[0]][$keys[1]][$keys[2]] ?? NULL;
		case 4:
			return $source[$keys[0]][$keys[1]][$keys[2]][$keys[3]] ?? NULL;
		default:
			wrap_error(sprintf('Too many arrays in %s, not implemented.', $key), E_USER_ERROR);
	}
}

/**
 * change key[subkey][subsubkey] into array key, subkey, subsubkey
 *
 * @param string $key
 * @return array
 */
function wrap_setting_key_array($key) {
	$keys = explode('[', $key);
	foreach ($keys as $index => $key)
		$keys[$index] = rtrim($key, ']');
	return $keys;
}

/**
 * allows settings from db to be in the format [1, 2, 3]; first \ will be
 * removed and allows settings starting with [
 *
 * list items inside [] are allowed to be enclosed in "" to preserve spaces
 * @param string $setting
 * @return mixed
 */
function wrap_setting_value($setting) {
	if (is_array($setting)) {
		foreach ($setting as $index => $value)
			$setting[$index] = wrap_setting_value_placeholder($value);
		return $setting;
	}
	$setting = wrap_setting_value_placeholder($setting);

	// if setting value is a constant, convert it to its numerical value	
	if (preg_match('/^[A-Z_]+$/', $setting) AND defined($setting))
		return constant($setting);

	switch (substr($setting, 0, 1)) {
	case '\\':
		return substr($setting, 1);
	case '[':
		if (!substr($setting, -1) === ']') break;
		$setting = substr($setting, 1, -1);
		$settings = explode(',', $setting);
		foreach ($settings as $index => $setting) {
			$settings[$index] = trim($setting);
			if (str_starts_with($settings[$index], '"') AND str_ends_with($settings[$index], '"'))
				$settings[$index] = trim($settings[$index], '"');
		}
		return $settings;
	case '?':
	case '&':
		$setting = substr($setting, 1);
		parse_str($setting, $settings);
		return $settings;
	}
	return $setting;
}

/**
 * check if setting has a setting placeholder in it
 *
 * @param string $string
 * @return string
 */
function wrap_setting_value_placeholder($string) {
	if (!is_string($string)) return $string;
	if (!strstr($string, '%%%')) return $string;
	$parts = explode('%%%', $string);
	$parts[1] = trim($parts[1]);
	if (!str_starts_with($parts[1], 'setting ')) return $string;
	$setting = substr($parts[1], 8);
	if (is_null(wrap_setting($setting))) return $string;
	$parts[1] = wrap_setting($setting);
	$string = implode('', $parts);
	return $string;
}

/**
 * write settings from array to $zz_setting or $zz_conf
 *
 * @param array $config
 * @return void
 */
function wrap_setting_register($config) {
	global $zz_setting;
	global $zz_conf;
	
	$zzform_cfg = wrap_cfg_files('settings', ['package' => 'zzform']);

	foreach ($config as $skey => $value) {
		if (wrap_setting_zzconf($zzform_cfg, $skey)) {
			$skey = substr($skey, 7);
			$var = 'zz_conf';
		} else {
			$var = 'zz_setting';
		}
		if (is_array($value) AND reset($value) === '__defaults__') {
			$$var[$skey] = wrap_setting($skey);
			array_shift($value);
		}
		$keys_values = wrap_setting_key($skey, wrap_setting_value($value));
		foreach ($keys_values as $key => $value) {
			if (!is_array($value)) {
				if (!$value OR $value === 'false') $value = false;
				elseif ($value === 'true') $value = true;
			}
			if (empty($$var[$key]) OR !is_array($$var[$key]))
				$$var[$key] = $value;
			else
				$$var[$key] = array_merge_recursive($$var[$key], $value);
		}
	}
}

/**
 * for zzform, check if key belongs to $zz_conf or to wrap_setting()
 *
 * @param array $cfg
 * @param string $key
 * @return bool
 */
function wrap_setting_zzconf($cfg, $key) {
	if (!str_starts_with($key, 'zzform_')) return false;
	$key_short = ($pos = strpos($key, '[')) ? substr($key, 0, $pos) : '';
	if ($key_short AND empty($cfg[$key_short]['zz_conf'])) return false;
	if (empty($cfg[$key]['zz_conf'])) return false;
	return true;
}

/**
 * add settings from website if backend is on a different website
 *
 * @return void
 */
function wrap_setting_backend() {
	if (!$backend_website_id = wrap_setting('backend_website_id')) return;
	if ($backend_website_id === wrap_setting('website_id')) return;

	$cfg = wrap_cfg_files('settings');
	foreach ($cfg as $index => $parameters) {
		if (!empty($parameters['backend_for_website'])) continue;
		unset($cfg[$index]);
	}
	if (!$cfg) return;

	$sql = 'SELECT setting_key, setting_value
		FROM /*_PREFIX_*/_settings
		WHERE website_id = %d
		AND setting_key IN ("%s")';
	$sql = sprintf($sql
		, wrap_setting('backend_website_id')
		, implode('","', array_keys($cfg))
	);
	$extra_settings = wrap_db_fetch($sql, 'setting_key', 'key/value');
	wrap_setting_register($extra_settings);
}

/**
 * read default settings from .cfg files
 *
 * @param string $type (settings, access, etc.)
 * @param array $settings (optional)
 * @return array
 */
function wrap_cfg_files($type, $settings = []) {
	static $cfg = [];
	static $single_cfg = [];
	static $translated = false;

	// check if wrap_cfg_files() was called without database connection
	// then translate all config variables read so far

	if (!$translated AND wrap_db_connection() AND $cfg AND !empty($settings['translate'])) {
		foreach (array_keys($cfg) as $this_type) {
			wrap_cfg_translate($cfg[$this_type], $this_type);
		}
		foreach ($single_cfg as $this_type => $this_config) {
			foreach (array_keys($this_config) as $this_module) {
				wrap_cfg_translate($single_cfg[$this_type][$this_module], $this_type);
			}
		}
		$translated = true;
	}
	
	// read data
	if (empty($cfg[$type]))
		list($cfg[$type], $single_cfg[$type]) = wrap_cfg_files_parse($type);
	if (empty($cfg[$type]))
		return [];

	// return existing values
	if (!empty($settings['package'])) {
		if (!array_key_exists($settings['package'], $single_cfg[$type])) return [];
		return $single_cfg[$type][$settings['package']];
	} elseif (!empty($settings['scope'])) {
		// restrict array to a certain scope, 'website' being implicit default scope
		$cfg_return = $cfg[$type];
		foreach ($cfg_return as $key => $config) {
			if (empty($config['scope'])) {
				if ($settings['scope'] === 'website') continue;
				unset($cfg_return[$key]);
				continue;
			}
			$scope = is_array($config['scope']) ? $config['scope'] : wrap_setting_value($config['scope']);
			if (in_array($settings['scope'], $scope)) continue;
			unset($cfg_return[$key]);
		}
		return $cfg_return;
	}
	return $cfg[$type];
}

/**
 * parse .cfg files per type
 *
 * @param string $type
 * @return array
 *		array $cfg configuration data indexed by package
 *		array $single_cfg configuration data, all keys in one array
 */
function wrap_cfg_files_parse($type) {
	$files = wrap_collect_files('configuration/'.$type.'.cfg', 'modules/themes/custom');
	if (!$files) return [[], []];

	$cfg = [];
	foreach ($files as $package => $cfg_file) {
		$single_cfg[$package] = parse_ini_file($cfg_file, true);
		foreach (array_keys($single_cfg[$package]) as $index)
			$single_cfg[$package][$index]['package'] = $package;
		// might be called before database connection exists
		if (wrap_db_connection()) {
			wrap_cfg_translate($single_cfg[$package], $cfg_file);
			$translated = true;
		}
		// no merging, let custom cfg overwrite single settings from modules
		foreach ($single_cfg[$package] as $key => $line) {
			if (array_key_exists($key, $cfg))
				foreach ($line as $subkey => $value) {
					if ($subkey === 'package') continue;
					$cfg[$key][$subkey] = $value;
				}
			else
				$cfg[$key] = $line;
		}
	}
	return [$cfg, $single_cfg];
}

/**
 * translate description per config
 *
 * @param array $cfg
 * @param string $filename
 * @return array
 */
function wrap_cfg_translate(&$cfg, $filename) {
	$my_filename = $filename;
	foreach ($cfg as $index => $config) {
		if (empty($config['description'])) continue;
		if (is_array($config['description'])) continue;
		if (!str_starts_with($filename, '/'))
			$my_filename = sprintf('%s/%s/configuration/%s.cfg', wrap_setting('modules_dir'), $config['package'], $filename);
		$cfg[$index]['description'] = wrap_text($config['description'], ['source' => $my_filename]);
	}
	return $cfg;
}

/**
 * get URL path of a page depending on a brick, write as setting
 *
 * @param string $setting_key
 * @param string $brick (optional, read from settings.cfg)
 * @param array $params (optional)
 * @return bool
 */
function wrap_setting_path($setting_key, $brick = '', $params = []) {
	static $tries = [];
	if (in_array($setting_key, $tries)) return false; // do not try more than once per request
	$tries[] = $setting_key;
	
	if (!$brick) {
		// get it from settings.cfg
		$cfg = wrap_cfg_files('settings');
		if (empty($cfg[$setting_key]['brick'])) return false;
		$brick = $cfg[$setting_key]['brick'];
		if (!$params)
			$params = $cfg[$setting_key]['brick_local_settings'] ?? [];
	}
	
	$sql = 'SELECT CONCAT(identifier, IF(ending = "none", "", ending)) AS path, content
		FROM /*_PREFIX_*/webpages
		WHERE content LIKE "%\%\%\% '.$brick.'% \%\%\%%"';
	if (wrap_setting('website_id'))
		$sql .= sprintf(' AND website_id = %d', wrap_setting('website_id'));
	$paths = wrap_db_fetch($sql, '_dummy_', 'numeric');
	
	// build parameters
	$no_params = [];
	foreach ($params as $key => $value) {
		if ($value) continue;
		$no_params[] = $key;
		unset($params[$key]);
	}
	$params = $params ? http_build_query($params) : '';
	$params = explode('&', $params);
	foreach ($params as $param) {
		if (!$param) continue;
		// if parameter: only leave pages having this parameter
		foreach ($paths as $index => $path) {
			if (strstr($path['content'], $param)) continue;
			unset($paths[$index]);
		}
	}
	foreach ($no_params as $param) {
		// if parameter=0: only leave pages without this parameter
		$param .= '=';
		foreach ($paths as $index => $path) {
			if (!strstr($path['content'], $param)) continue;
			unset($paths[$index]);
		}
	}

	if (count($paths) !== 1 AND !str_ends_with($setting_key, '*')) {
		// check if one ends with asterisk
		foreach ($paths as $index => $path) {
			if (strstr($path['content'], $brick.' *')) unset($paths[$index]);
		}
	}
	if (count($paths) !== 1) {
		$brick = explode(' ', $brick);
		if (count($brick) !== 2) return false;
		if ($brick[0] !== 'tables') return false;
		$path = wrap_path('default_tables', $brick[1]);
		if (!$path) return false;
	} else {
		$path = reset($paths);
		$path = $path['path'];
	}
	$path = str_replace('*', '/%s', $path);
	$path = str_replace('//', '/', $path);
	wrap_setting_write($setting_key, $path);
	return true;
}

/**
 * get a path based on a setting, check for access
 *
 * e. g. for 'default_masquerade' get path from 'default_masquerade_path'
 * for 'activities_profile[usergroup]' use 'activities_profile_path[usergroup]'
 * @param string $area
 * @param mixed $value (optional)
 * @param mixed $check_rights (optional) false: no check; array: use as details
 * @param bool $testing (optional) true: checks if path exists, regardless of values
 * @return string
 */
function wrap_path($area, $value = [], $check_rights = true, $testing = false) {
	// check rights
	$detail = is_bool($check_rights) ? '' : $check_rights;
	if ($check_rights AND !wrap_access($area, $detail)) return NULL;

	// add _path to setting, check if it exists
	$check = false;
	if (strstr($area, '[')) {
		$keys = explode('[', $area);
		$keys[0] = sprintf('%s_path', $keys[0]);
		$setting = implode('[', $keys);
	} else {
		$setting = sprintf('%s_path', $area);
	}
	if (!wrap_setting($setting)) $check = true;

	if ($check) {
		$success = wrap_setting_path($setting);
		if (!$success) return NULL;
	}
	$this_setting = wrap_setting($setting);
	if (!$this_setting) return '';
	// if you address e. g. news_article and it is in fact news_article[publication_path]:
	if (is_array($this_setting)) return '';
	// replace page placeholders with %s
	$this_setting = wrap_path_placeholder($this_setting);
	$required_count = substr_count($this_setting, '%');
	if (!is_array($value)) $value = [$value];
	if (count($value) < $required_count) {
		if (wrap_setting('backend_path'))
			array_unshift($value, wrap_setting('backend_path'));
		if (count($value) < $required_count) {
			if (!$testing) return '';
			while (count($value) < $required_count)
				$value[] = 'testing';
		}
	}
	$path = vsprintf(wrap_setting('base').$this_setting, $value);
	if (str_ends_with($path, '#')) $path = substr($path, 0, -1);
	if ($website_id = wrap_setting('backend_website_id')
		AND $website_id !== wrap_setting('website_id')) {
		$cfg = wrap_cfg_files('settings');
		if (!empty($cfg[$setting]['backend_for_website']))
			$path = wrap_host_base($website_id).$path;
	}
	return $path;
}

/**
 * replace URL placeholders in path (e. g. %year%) with %s
 *
 * @param string $path
 * @param string $char whith what to replace
 * @return string
 */
function wrap_path_placeholder($path, $char = '%s') {
	global $zz_page;
	if (empty($zz_page['url_placeholders'])) return $path;
	foreach (array_keys($zz_page['url_placeholders']) as $placeholder) {
		$placeholder = sprintf($char === '*' ? '/%%%s%%' : '%%%s%%', $placeholder);
		if (!strstr($path, $placeholder)) continue;
		$path = str_replace($placeholder, $char, $path);
	}
	// remove duplicate *
	while (strstr($path, $char.'/'.$char))
		$path = str_replace($char.'/'.$char, $char, $path);
	while (strstr($path, $char.$char))
		$path = str_replace($char.$char, $char, $path);
	return $path;
}

/**
 * get additional page IDs for menu hierarchy
 *
 * @param string $area
 * @param array $paths (optional)
 * @param string $setting_key (optional, defaults to category=)
 * @return array
 */
function wrap_menu_hierarchy($area, $paths = [], $setting_key = '') {
	sort($paths);
	$setting = sprintf('%s_page_id[%s]', $area, implode(';', $paths));
	if ($id = wrap_setting($setting)) return $id;
	
	// get brick
	$cfg = wrap_cfg_files('settings');
	if (empty($cfg[$area.'_path']['brick'])) return false;
	$block = $cfg[$area.'_path']['brick'];
	
	// get all matching pages
	$sql = 'SELECT page_id, content
		FROM /*_PREFIX_*/webpages
		WHERE content LIKE "%%\%%\%%\%% %s %%"';
	$sql = sprintf($sql, $block);
	$pages = wrap_db_fetch($sql, 'page_id');
	if (!$pages) return wrap_setting($setting, []);

	// prepare blocks for comparison
	if ($setting_key)
		foreach ($paths as $index => $path)
			$paths[$index] = sprintf('%s=%s', $setting_key, $path);
	$block = sprintf('%s %s', $block, implode(' ', $paths));

	$page_ids = [];
	foreach ($pages as $page) {
		preg_match_all('/%%%(.+?)%%%/', $page['content'], $matches);
		if (empty($matches[1])) continue;
		foreach ($matches[1] as $match_block) {
			$match = brick_blocks_match($block, $match_block);
			if (!$match) continue;
			$page_ids[] = $page['page_id'];
		}
	}
	wrap_setting_write($setting, sprintf('[%s]', implode(',', $page_ids)));
	return $page_ids;
}

/**
 * get hostname for website ID
 *
 * @param int $website_id
 * @return string
 */
function wrap_host_base($website_id) {
	static $host_bases = [];
	if (!$website_id) return ''; // probably database disconnected
	if (array_key_exists($website_id, $host_bases))
		return $host_bases[$website_id];

	$sql = 'SELECT setting_value
		FROM /*_PREFIX_*/_settings
		WHERE setting_key = "canonical_hostname"
		AND _settings.website_id = %d';
	$sql = sprintf($sql, $website_id);
	$hostname = wrap_db_fetch($sql, '', 'single value');
	if (!$hostname) {
		$hostnames = array_flip(wrap_id('websites', '', 'list'));
		if (!array_key_exists($website_id, $hostnames)) return '';
		$hostname = $hostnames[$website_id];
	}
	return $host_bases[$website_id] = sprintf('https://%s', $hostname);
}



/**
 * recursively delete folders
 *
 * @param string $folder
 */
function wrap_unlink_recursive($folder) {
	$files = array_diff(scandir($folder), ['.', '..']);
	foreach ($files as $file) {
		$path = $folder.'/'.$file;
		is_dir($path) ? wrap_unlink_recursive($path) : unlink($path);
	}
	rmdir($folder);
}

/**
 * list filetypes
 *
 * @param string $filetype read configuration values for this filetype
 * @param string $action (optional, default 'read', 'write')
 * @param array $definition new definition
 * @return 
 */
function wrap_filetypes($filetype = false, $action = 'read', $definition = []) {
	static $filetypes = [];
	if (!$filetypes) $filetypes = wrap_filetypes_init();

	switch ($action) {
	case 'read':
		if (!$filetype) return $filetypes;
		if (!array_key_exists($filetype, $filetypes)) return [];
		return $filetypes[$filetype];
	case 'read-per-extension':
	case 'check-per-extension':
		$found = [];
		foreach ($filetypes as $ftype => $filetype_def) {
			if (empty($filetype_def['extension']) AND $filetype !== $ftype) continue;
			elseif (!in_array($filetype, $filetype_def['extension'])) continue;
			$found[] = $ftype;
		}
		if (count($found) === 1) return $filetypes[$found[0]];
		if ($action === 'read-per-extension')
			wrap_error(sprintf('Cannot determine filetype by extension: `%s`', $filetype));
		break;
	case 'write':
		// @todo not yet supported
		break;
	case 'merge':
		if (!array_key_exists($filetype, $filetypes)) {
			$definition = wrap_filetypes_normalize([$filetype => $definition]);
			$filetypes[$filetype] = reset($definition);
		} else {
			$old = $filetypes[$filetype];
			foreach ($definition as $key => $value) {
				if ($key === 'filetype') continue; // never changes
				if (empty($filetypes[$filetype][$key])) {
				// add
					$filetypes[$filetype][$key] = $value;
				} elseif (is_array($filetypes[$filetype][$key])) {
				// merge, prepend new values at the beginning
					if (!is_array($value)) $value = [$value];
					$value = array_reverse($value);
					foreach ($value as $item) {
						array_unshift($filetypes[$filetype][$key], $item);
					}
					$filetypes[$filetype][$key] = array_unique($filetypes[$filetype][$key]);
					$filetypes[$filetype][$key] = array_values($filetypes[$filetype][$key]);
				} else {
				// overwrite
					$filetypes[$filetype][$key] = $value;
				}
			}
		}
		break;
	}
}

/**
 * read filetypes from filetypes.cfg, settings
 *
 * @return array
 */
function wrap_filetypes_init() {
	$filetypes = [];
	$files = wrap_collect_files('configuration/filetypes.cfg', 'modules/custom');
	foreach ($files as $filename)
		$filetypes = wrap_filetypes_add($filename, $filetypes);
	// support changes via settings, too, e. g. filetypes[m4v][multipage_thumbnail_frame] = 50
	foreach (wrap_setting('filetypes') as $filetype => $config)
		if (!array_key_exists($filetype, $filetypes))
			wrap_error(sprintf('No filetype `%s` exists.', $filetype));
		else $filetypes[$filetype] = wrap_array_merge($filetypes[$filetype], $config);
	$filetypes = wrap_filetypes_array($filetypes);
	return $filetypes;
}

/**
 * add content of file to filetypes configuration
 *
 * @param string $filename
 * @param array $filetypes
 * @return array
 */
function wrap_filetypes_add($filename, $filetypes) {
	$new_filetypes = parse_ini_file($filename, true);
	$new_filetypes = wrap_filetypes_normalize($new_filetypes);
	foreach ($new_filetypes as $filetype => $definition) {
		// add or overwrite existing definitions
		$filetypes[$filetype] = $definition;
	}
	return $filetypes;
}

/**
 * set some values for filetypes array, allow shortcuts in definition
 *
 * @param array $filetypes
 * @return array $filetypes
 *		indexed by string type
 *		string 'description'
 *		array 'mime'
 *		array 'extension'
 *		bool 'thumbnail'
 *		bool 'multipage'
 */
function wrap_filetypes_normalize($filetypes) {
	foreach ($filetypes as $type => $values) {
		$filetypes[$type]['filetype'] = $type;
		if (empty($values['mime']))
			$filetypes[$type]['mime'] = ['application/octet-stream'];
		if (empty($values['extension']))
			$filetypes[$type]['extension'] = [$type];
		if (!array_key_exists('thumbnail', $values))
			 $filetypes[$type]['thumbnail'] = 0;
		if (!array_key_exists('multipage', $values))
			 $filetypes[$type]['multipage'] = 0;
	}
	$filetypes = wrap_filetypes_array($filetypes);
	return $filetypes;
}

/**
 * make sure that some keys in wrap_filetypes() are an array
 *
 * @param array $filetypes
 * @return array
 */
function wrap_filetypes_array($filetypes) {
	$keys = [
		'mime', 'extension', 'php', 'convert'
	];
	foreach ($filetypes as $type => $values) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $values)) continue;
			if (is_array($values[$key])) continue;
			$filetypes[$type][$key] = [$values[$key]];
		}
	}
	return $filetypes;
}

/**
 * username inside system
 *
 * @param string $username (optional)
 * @param bool $add_suffix (optional)
 * @return string
 */
function wrap_username($username = '', $add_suffix = true) {
	if ($username)
		$username = $username;
	elseif (wrap_setting('log_username'))
		$username = wrap_setting('log_username');
	elseif (!empty($_SESSION['username']))
		$username = $_SESSION['username'];
	elseif (!empty($_SERVER['PHP_AUTH_USER']))
		$username = $_SERVER['PHP_AUTH_USER'];
	elseif (wrap_setting('log_username_default'))
		$username = wrap_setting('log_username_default');
	
	// suffix?
	if ($suffix = wrap_setting('log_username_suffix')) {
		// remove existing
		$suffix_end = sprintf(' (%s)', $suffix);
		if ($username AND str_ends_with($username, $suffix_end))
			$username = substr($username, 0, strlen($suffix_end));
		// add new in case there is one
		if ($username AND $add_suffix) $username = sprintf('%s (%s)', $username, $suffix);
		elseif (!$username) $username = wrap_setting('log_username_suffix');
	}

	return $username;
}

/**
 * check if a file of a given filetype can be used as webimage
 *
 * @param string $filetype
 * @return bool
 */
function wrap_webimage($filetype) {
	$def = wrap_filetypes($filetype);
	if (!$def) return false;
	if (!empty($def['php'])) return true;
	if (!empty($def['webimage'])) return true;
	return false;
}
