<?php 

/**
 * zzwrap
 * sending ressources back to the browser
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
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
function wrap_send_file($file) {
	global $zz_page;

	if (is_dir($file['name'])) {
		if (wrap_setting('cache')) wrap_cache_delete(404);
		wrap_error(wrap_text(
			'Unable to send file: %s is a folder, not a file.',
			['values' => [$file['name']]]
		));
		wrap_quit(503);
	}
	if (!file_exists($file['name'])) {
		if (!empty($file['error_code'])) {
			if (!empty($file['error_msg'])) {
				global $zz_page;
				$zz_page['error_msg'] = $file['error_msg'];
			}
			wrap_quit($file['error_code']);
		}
		wrap_send_file_cleanup($file);
		if (wrap_setting('cache')) wrap_cache_delete(404);
		wrap_error(wrap_text(
			'Unable to send file: %s does not exist',
			['values' => [$file['name']]]
		));
		wrap_quit(404);
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
	if ($file['caching']) {
		$max_age = $filetype_cfg['max-age'] ?? wrap_setting('cache_control_file');
		wrap_cache_header_default(sprintf('Cache-Control: max-age=%d', $max_age));
	}

	// Caching?
	if (wrap_setting('cache')) {
		wrap_cache_header('X-Local-Filename: '.$file['name']);
		wrap_cache();
	}

	wrap_send_ressource('file', $file);
}

/**
 * old function name
 * @deprecated
 */
function wrap_file_send($file) {
	wrap_error('Please use wrap_send_file() instead of wrap_file_send().', E_USER_DEPRECATED);
	return wrap_send_file($file);
}

/**
 * does cleanup after a file was sent
 *
 * @param array $file
 * @return bool
 */
function wrap_send_file_cleanup($file) {
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
 *		'cache_max_age'
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
		$max_age = $headers['cache_max_age'] ?? $filetype_cfg['max-age'] ?? wrap_setting('cache_control_text');
		wrap_cache_header_default(sprintf('Cache-Control: max-age=%d', $max_age));
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
		if ($type === 'file') wrap_send_file_cleanup($content);
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
	wrap_send_file_cleanup($content);
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
 * Quitting
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
 * Logging functions
 * --------------------------------------------------------------------
 */

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
			, wrap_log_anonymize(wrap_setting('remote_ip'))
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

/**
 * anonymize IP address
 *
 * @param string $ip
 * @return string
 */
function wrap_log_anonymize($ip) {
	if (!wrap_setting('http_log_anonymous')) return $ip;
	if (!filter_var($ip, FILTER_VALIDATE_IP)) {
		wrap_error(sprintf('Unknown IP Address: %s', $ip));
		return $ip;
	}
	$http_log_anonymous = wrap_setting('http_log_anonymous');
	if (!in_array($http_log_anonymous, [1, 2, 3, 4, 5, 6, 7, 8]))
		$http_log_anonymous = 1;

	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		if ($http_log_anonymous > 4) $http_log_anonymous = 4;
		$concat = '.';
		$parts = explode('.', $ip);
	} elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
		$concat = ':';
		$parts = explode('::', $ip);
		if (count($parts) === 2) {
			// add missing hextets
			$parts[0] = explode(':', $parts[0]);
			if (!$parts[0][0]) $parts[0][0] = 0;
			$parts[1] = explode(':', $parts[1]);
			if (!$parts[1][0]) $parts[1][0] = 0;
			$missing = 8 - count($parts[0]) - count($parts[1]);
			while ($missing) {
				$parts[0][] = 0;
				$missing--;
			}
			$parts = array_merge($parts[0], $parts[1]);
		} else {
			$parts = explode(':', $ip);
		}
	} else {
		wrap_error(sprintf('Unknown IP Address: %s', $ip));
		return $ip;
	}
	for ($i = 0; $i < $http_log_anonymous; $i++)
		array_pop($parts);
	for ($i = 0; $i < $http_log_anonymous; $i++)
		$parts[] = 0;
	// @todo shorten IPv6 address
	return implode($concat, $parts);
}
