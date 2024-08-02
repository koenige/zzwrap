<?php 

/**
 * zzwrap
 * Caching + ETag, Last-Modified checks
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * cache a local ressource
 * in some cases, never cache a ressource
 *
 * @param string $text (optional)
 * @param string $existing_etag (optional)
 * @param string $url (optional)
 * @param array $headers (optional)
 * @return bool, NULL if caching is disabled for this request
 */
function wrap_cache($text = '', $existing_etag = '', $url = false, $headers = []) {
	if (!empty($_SESSION['logged_in'])) return NULL;
	if ($_SERVER['REQUEST_METHOD'] === 'POST') return NULL;
	return wrap_cache_ressource($text, $existing_etag, $url, $headers);
}

/**
 * cache a ressource if it not exists or a stale cache exists
 *
 * @param string $text ressource to be cached
 * @param string $existing_etag
 * @param string $url (optional) URL to be cached, if not set, use internal URL
 * @param array $headers (optional), if not set use sent headers
 * @return bool false: no new cache file was written, true: new cache file created
 */
function wrap_cache_ressource($text = '', $existing_etag = '', $url = false, $headers = []) {
	$cache = wrap_cache_filenames($url);
	if (!file_exists($cache['domain'])) {
		$success = wrap_mkdir($cache['domain']);
		if (!$success) wrap_error(sprintf('Could not create cache directory %s.', $cache['domain']), E_USER_NOTICE);
	}
	// URL with 'filename.ext/': both doc and head are false
	if (!$cache['url'] and !$cache['headers']) return NULL;
	if (file_exists($cache['headers']) AND file_exists($cache['url'])) {
		// check if something with the same ETag has already been cached
		// no need to rewrite cache, it's possible to send a Last-Modified
		// header along
		$etag = wrap_cache_get_header($cache['headers'], 'ETag');
		if ($etag AND $etag === $existing_etag) {
			wrap_cache_revalidated($cache['headers']);
			return false;
		}
	}
	// create folder, but not if it is a file
	if (!is_file(dirname($cache['headers']))) wrap_mkdir(dirname($cache['headers']));
	// save document
	if ($text) {
		file_put_contents($cache['url'], $text);
	} elseif (file_exists($cache['url'])) {
		// here: only remove $cache['url'] if this was a URL with an old cache file
		// directory = no, this is just a redirect to a URL with trailing slash
		if (!is_dir($cache['url'])) unlink($cache['url']);
	}
	// save headers
	// without '-gz'
	if (!$headers) {
		header_remove('X-Powered-By');
		header_remove('Server');
		$headers = wrap_setting('headers');
	}
	// if it is a redirect only and it redirects to an URL without trailing slash
	// for which a cache already exists, do not cache this redirect because it is
	// impossible to save example/index and example at the same time (example being folder
	// and file)
	if (is_file(dirname($cache['headers'])) AND !$text) return NULL;
	file_put_contents($cache['headers'], implode("\r\n", $headers));
	return true;
}

/**
 * send one or more HTTP header and save it for later caching
 * send extra http headers, @see defaults.inc.php
 *
 * @param string $header (optional, if not set: use wrap_setting('headers'))
 * @return bool
 */
function wrap_cache_header($header = false) {
	if ($header) {
		$headers = [$header];
	} else {
		header_remove('X-Powered-By');
		header_remove('Server');
		$headers = wrap_setting('extra_http_headers');
		if (!wrap_https()) {
			header_remove('Strict-Transport-Security'); // only for https
			foreach ($headers as $index => $header) {
				if (str_starts_with($header, 'Strict-Transport-Security'))
					unset($headers[$index]);
			}
		}
	}

	foreach ($headers as $line) {
		header($line);
		if (strstr($line, ': ')) {
			$header_parts = explode(': ', $line);
			wrap_setting_add('headers', [strtolower($header_parts[0]) => $line]);
		} else {
			wrap_setting_add('headers', $line);
		}
	}
	return true;
}

/**
 * send a default header if no other header of the same name was already sent
 *
 * @param string $header
 * @return bool true if a default header was sent
 */
function wrap_cache_header_default($header) {
	$parts = explode(': ', $header);
	if (wrap_setting('headers['.strtolower($parts[0]).']')) return false;
	
	$headers = headers_list();
	foreach ($headers as $line) {
		$line = explode(': ', $line);
		if ($line[0] === $parts[0]) return false;
	}
	wrap_cache_header($header);
	return true;	
}

/**
 * write an X-Revalidated-Header to cache file to allow it for some time
 * to be considered as fresh after the last revalidation
 *
 * @param string $file Name of header cache file
 * @return void
 */
function wrap_cache_revalidated($file) {
	$headers = file_get_contents($file);
	$headers = explode("\r\n", $headers);
	foreach ($headers as $index => $header) {
		if (str_starts_with($header, 'X-Revalidated: '))
			unset($headers[$index]);
	}
	$headers[] = sprintf('X-Revalidated: %s', wrap_date(time(), 'timestamp->rfc1123'));
	file_put_contents($file, implode("\r\n", $headers));
}

/**
 * Delete cached files which now return a 4xx-error code
 *
 * @param int $status HTTP Status Code
 * @param string $url (optional)
 * @return bool true: cache was deleted; false: cache remains intact
 */
function wrap_cache_delete($status, $url = false) {
	$delete_cache = [401, 402, 403, 404, 410];
	if (!in_array($status, $delete_cache)) return false;

	$cache = wrap_cache_filenames($url);
	if (!$cache['headers']) return false;
	if (file_exists($cache['headers'])) unlink($cache['headers']);
	if (file_exists($cache['url'])) {
		// might be directory, if there was a URL ending example/
		// and now example, redirecting to example/, is also removed
		if (is_dir($cache['url'])) {
			$files = scandir($cache['url']);
			if (count($files) <= 2) rmdir($cache['url']); // ., ..
		} else unlink($cache['url']);
	}
	return true;
}

/**
 * allow private caching of a ressource inside SESSION
 *
 * Remove some HTTP headers PHP might send because of SESSION
 * @todo do some tests if this is okay
 * @todo set sensible Expires header, according to age of file
 */
function wrap_cache_allow_private() {
	if (empty($_SESSION)) return;
	header_remove('Expires');
	header_remove('Pragma');
	// Cache-Control header private as in session_cache_limiter()
	wrap_cache_header(sprintf('Cache-Control: private, max-age=%s, pre-check=%s',
		session_cache_expire() * 60, session_cache_expire() * 60));
}

/**
 * creates ETag-Headers, checks against If-None-Match, If-Match
 *
 * @param string $etag
 * @param array $file (optional, for cleanup only)
 * @return mixed $etag_header (only if none match)
 *		!$file: array 'std' = standard header, 'gz' = header with gzip
 *		$file: string standard header
 * @see RFC 2616 14.24
 */
function wrap_if_none_match($etag, $file = []) {
	$etag_header = wrap_etag_header($etag);
	// Check If-Match header field
	if (isset($_SERVER['HTTP_IF_MATCH'])) {
		if (!wrap_etag_check($etag_header, $_SERVER['HTTP_IF_MATCH'])) {
			wrap_quit(412);
		}
	}
	if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
		if (wrap_etag_check($etag_header, $_SERVER['HTTP_IF_NONE_MATCH'])) {
			// HTTP requires to check Last-Modified date here as well
			// but we ignore it because if the Entity is identical, it does
			// not really matter if the modification date is different
			if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'])) {
				if ($file) wrap_file_cleanup($file);
				wrap_log_uri();
				wrap_cache_header('ETag: '.$etag_header['std']);
				wrap_quit(304);
			} else {
				wrap_quit(412);
			}
		}
	}
	// Neither header field affects request
	// ETag std header might be overwritten by gzip-ETag later on
	wrap_cache_header('ETag: '.$etag_header['std']);
	return $etag_header;
 }

/**
 * compares an ETag of a ressource to a HTTP request
 *
 * @param array $etag_header
 * @string $http_request, e. g. If-None-Match or If-Match
 * @return bool
 */
function wrap_etag_check($etag_header, $http_request) {
	if ($http_request === '*') {
		// If-Match: * / If-None-Match: *
		if ($etag_header) return true;
		else return false;
	}
	$entity_tags = explode(',', $http_request);
	// If-Match: "xyzzy"
	// If-Match: "xyzzy", "r2d2xxxx", "c3piozzzz"
	foreach ($entity_tags as $entity_tag) {
		$entity_tag = trim($entity_tag);
		if ($entity_tag === $etag_header['std']) return true;
		elseif ($entity_tag === $etag_header['gz']) return true;
	}
	return false;
}

/**
 * creates ETag header value from given ETag for uncompressed and gzip
 * ressources
 * W/ ETags are not supported
 *
 * @param string $etag
 * @return array
 */
function wrap_etag_header($etag) {
	if (substr($etag, 0, 1) === '"' AND substr($etag, -1) === '"') {
		$etag = substr($etag, 1, -1);
	}
	$etag_header = [
		'std' => sprintf('"%s"', $etag),
		'gz' => sprintf('"%s"', $etag.'-gz')
	];
	return $etag_header;
}

/**
 * creates Last-Modified-Header, checks against If-Modified-Since
 * and If-Unmodified-Since
 * respond to If Modified Since with 304 header if appropriate
 *
 * @param int $time (timestamp)
 * @param int $status HTTP status code
 * @param array $file (optional)
 * @return string time formatted for Last-Modified
 */
function wrap_if_modified_since($time, $status = 200, $file = []) {
	// do not send Last-Modified header for client (4xx) or server (5xx) errors
	if (substr($status, 0, 1) === '4') return '';
	if (substr($status, 0, 1) === '5') return '';

	global $zz_page;
	// Cache time: 'Sa, 05 Jun 2004 15:40:28'
	$zz_page['last_modified'] = wrap_date($time, 'timestamp->rfc1123');
	// Check If-Unmodified-Since
	if (isset($_SERVER['HTTP_IF_UNMODIFIED_SINCE'])) {
		$requested_time = wrap_date(
			$_SERVER['HTTP_IF_UNMODIFIED_SINCE'], 'rfc1123->timestamp'
		);
		if ($requested_time AND $time > $requested_time) {
			wrap_quit(412);
		}
	}
	// Check If-Modified-Since
	if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
		$requested_time = wrap_date(
			$_SERVER['HTTP_IF_MODIFIED_SINCE'], 'rfc1123->timestamp'
		);
		if ($time <= $requested_time) {
			wrap_cache_header('Last-Modified: '.$zz_page['last_modified']);
			if ($file) wrap_file_cleanup($file);
			wrap_log_uri();
			wrap_quit(304);
		}
	}
	wrap_cache_header('Last-Modified: '.$zz_page['last_modified']);
	return $zz_page['last_modified'];
}

/**
 * send cached data instead of data from database
 * (e. g. if connection is broken)
 *
 * @param int $age (optional) maximum acceptable cache age in seconds
 * @param bool $log_error (optional)
 * @return void
 */
function wrap_send_cache($age = 0, $log_error = true) {
	global $zz_page;
	
	// Some cases in which we do not cache
	if (!wrap_setting('cache')) return false;
	if (!empty($_SESSION)) return false;
	if (!empty($_POST)) return false;

	$cache = wrap_cache_filenames('', $log_error);
	// $cache['url'] might not exist (redirect!)
	if (!$cache['headers']) return false; // invalid URL or URL too long
	if (!file_exists($cache['headers'])) return false;
	$has_content = wrap_cache_exists($cache);
	if ($has_content) $cache['url'] = $has_content;

	if ($age) {
		// return cached files if they're still fresh enough
		$fresh = wrap_cache_freshness($cache, $age, $has_content);
		if (!$fresh) return false;
	}

	// get cached headers, send them as headers and write them to $zz_page
	// Content-Type HTTP header etc.
	wrap_cache_get_header($cache['headers'], '', true);

	if (wrap_setting('gzip_encode'))
		wrap_cache_header('Vary: Accept-Encoding');

	// Log if cached version is used because there's no connection to database
	if (!wrap_db_connection() AND !$age) {
		wrap_error('No connection to SQL server. Using cached file instead.', E_USER_NOTICE);
		wrap_error(false, false, ['collect_end' => true]);
	}
	
	// is it a cached redirect? that's it. exit.
	if (!$has_content) return true;

	// Content-Length HTTP header
	if (empty($zz_page['content_length'])) {
		$zz_page['content_length'] = sprintf("%u", filesize($cache['url']));
		wrap_cache_header('Content-Length: '.$zz_page['content_length']);
	}

	// ETag HTTP header
	if (empty($zz_page['etag'])) {
		$zz_page['etag'] = md5_file($cache['url']);
	}
	$etag_header = wrap_if_none_match($zz_page['etag']);

	// Last-Modified HTTP header
	if (empty($zz_page['last_modified'])) {
		$last_modified_time = filemtime($cache['url']);
	} else {
		$last_modified_time = wrap_date(
			$zz_page['last_modified'], 'rfc1123->timestamp'
		);
	}
	wrap_if_modified_since($last_modified_time);

	$file = [
		'name' => $cache['url'],
		'gzip' => true
	];
	wrap_cache_header();
	wrap_send_ressource('file', $file, $etag_header);
}

/**
 * check freshness of cache, either if it was last revalidated in a given
 * timeframe (positive values for $age) or if it was created after a given 
 * timestamp (negative values for $age)
 *
 * @param array $cache list of files
 * @param int $age (negative -1: don't care about freshness; other values: check)
 * @param bool $has_content if false, it's only a redirect
 * @return bool false: not fresh, true: cache is fresh
 */
function wrap_cache_freshness($cache, $age, $has_content = true) {
	// -1: cache will always considered to be fresh
	if ($age === -1) return true;
	$now = time();
	if ($age < 0) {
		// check if there's a cache that was not modified later than $age
		$last_mod = wrap_cache_get_header($cache['headers'], 'Last-Modified');
		$last_mod_timestamp = wrap_date($last_mod, 'rfc1123->timestamp');
		if ($last_mod_timestamp > $now + $age) {
			return true;
		}
		if ($has_content AND filemtime($cache['url']) > $now + $age) return true;
	} else {
		$host = substr($cache['url'], strlen(wrap_setting('cache_dir_zz')) + 1);
		$host = substr($host, 0, strpos($host, '/'));
		if ($host !== wrap_setting('hostname')) {
			// remote access, check cache-control of remote server
			$cache_control = wrap_cache_get_header($cache['headers'], 'Cache-Control');
			parse_str($cache_control, $cache_control);
			if (!empty($cache_control['max-age']) AND $cache_control['max-age'] > $age) {
				$age = $cache_control['max-age'];
				$date = wrap_date(wrap_cache_get_header($cache['headers'], 'Date'), 'rfc1123->timestamp');
				// sometimes, Date header is missing in cache file (why?)
				// then get new data
				if (!$date) return false;
				if ($date + $age > $now) return true;
			}					
		}
		// 0 or positive values: cache files will be checked
		// check if X-Revalidated is set
		$revalidated = wrap_cache_get_header($cache['headers'], 'X-Revalidated');
		$revalidated_timestamp = wrap_date($revalidated, 'rfc1123->timestamp');
		if ($revalidated_timestamp AND $revalidated_timestamp + $age > $now) {
			// thought of putting in Age, but Date has to be changed accordingly
			// wrap_cache_header(sprintf('Age: %d', $now - $revalidated_timestamp));
			return true;
		}
		// check if cached files date is fresh
		if ($has_content AND filemtime($cache['url']) + $age > $now) return true;
	}
	return false;
}

/**
 * Check if there's a cache file and it's newer than last modified
 * date e. g. of database tables
 *
 * @param string $datetime timestamp e.g. 2014-08-14 10:28:36
 * @return void false if no cache was found, or it will send the cache
 */
function wrap_cache_send_if_newer($datetime) {
	if (!$datetime) return false;
	$datetime = strtotime($datetime);
	$now = time();
	$diff = $now - $datetime;
	if ($diff > 0) {
		// check if there's a cache younger than $diff
		wrap_send_cache(-$diff);
	}
	return false;
}

/**
 * get header value from cache file
 *
 * @param string $file filename
 * @param string $type name of header (is case insensitive)
 * @param bool $send send headers or not
 * @return string $value
 */
function wrap_cache_get_header($file, $type, $send = false) {
	static $sent = false;
	global $zz_page;
	$type = strtolower($type);
	$headers = file_get_contents($file);
	if (substr($headers, 0, 2) === '["') {
		// @deprecated: used JSON format instead of plain text for headers
		$headers = json_decode($headers);
		file_put_contents($file, implode("\r\n", $headers));
	} else {
		$headers = explode("\r\n", $headers);
	}
	if (!$headers) {
		wrap_error(sprintf('Cache file for headers has no content (%s)', $file), E_USER_NOTICE);
		return '';
	}
	$value = '';
	foreach ($headers as $header) {
		$req_header = strtolower(substr($header, 0, strpos($header, ': ')));
		$req_value = trim(substr($header, strpos($header, ': ')+1));
		if (!$sent AND $send) {
			header($header);
			$zz_page[str_replace('-', '_', $req_header)] = $req_value;
		}
		if ($req_header === $type) {
			// check if respond with 304
			$value = substr($header, strlen($type) + 2);
			if (substr($value, 0, 1) === '"' AND substr($value, -1) === '"') {
				$value = substr($value, 1, -1);
			}
		}
	}
	if ($send) $sent = true;
	return $value;
}

/**
 * returns filename for URL for caching
 *
 * @param string $type (optional) default: 'url'; 'headers', 'domain'
 * @param string $url (optional) URL to cache, if not set, internal URL will be used
 * @global array $zz_page ($zz_page['url']['full'])
 * @return string filename
 */
function wrap_cache_filename($type = 'url', $url = '', $log_error = true) {
	global $zz_page;

	if (!$url) {
		if (empty($zz_page['url'])) return false;
		$url = $zz_page['url']['full'];
		$base = wrap_setting('base');
		if ($base === '/') $base = '';
	} else {
		$url = parse_url($url);
		$base = '';
	}
	$file = wrap_setting('cache_dir_zz');
	$file .= '/'.urlencode($url['host']);
	if ($type === 'domain') return $file;

	if (!empty($url['query'])) {
		// [ and ] are equal to %5B and %5D, so replace them
		$url['query'] = str_replace('%5B', '[', $url['query']);
		$url['query'] = str_replace('%5D', ']', $url['query']);
	}
	if (!$url['path']) $url['path'] = '/'; // always have a path
	$url['path'] = wrap_cache_extension($url['host'], $url['path']);
	if (wrap_setting('cache_directories')) {
		$url['path'] = explode('/', $url['path']);
		foreach ($url['path'] as $index => $path) {
			$url['path'][$index] = urlencode($path);
		}
		$last_path = $url['path'][count($url['path']) - 2];
		$url['path'] = implode('/', $url['path']);
		$file .= $base.$url['path'];
		if (str_ends_with($file, '/')) {
			// check if it is not some super clever search engine that adds a /
			// to a filename (dot showing this is)
			if (strstr($last_path, '.')) {
				$extension = wrap_file_extension($last_path);
				if (wrap_filetypes($extension, 'check-per-extension')) {
					if (file_exists(substr($file, 0, -1))) return false;
					if ($log_error) wrap_error(wrap_text(
						'Caching for URL %s disabled, looks like filename with / at the end?',
						['values' => wrap_setting('request_uri')]
					), E_USER_NOTICE);
					return false;
				}
			}
			$file .= 'index';
		}
	} else {
		$file .= '/'.urlencode($base.$url['path']);
	}
	if (!empty($url['query'])) $file .= urlencode('?'.$url['query']);
	if (strlen(basename($file)) > (255 - 8)) {
		if ($log_error) wrap_error(sprintf('Cache filename too long, caching disabled: %s', $file), E_USER_NOTICE);
		return false;
	}
	if ($type === 'url') {
		if (wrap_setting('cache_directories') AND !strstr(basename($file), '.'))
			$file .= '.html'; // to avoid conflicts with directories
		return $file;
	}

	$file .= '.headers';
	if ($type === 'headers') return $file;

	return false;
}

/**
 * get all cache filenames for a URL at once
 *
 * @param string $url (optional)
 * @param bool $log_error (optional)
 * @return array
 */
function wrap_cache_filenames($url = '', $log_error = true) {
	return [
		'domain' => wrap_cache_filename('domain', $url, $log_error),
		'url' => wrap_cache_filename('url', $url, $log_error),
		'headers' => wrap_cache_filename('headers', $url, $log_error)
	];
}

/**
 * add extension to cache file in some cases
 *
 * @param string $host
 * @param string $path
 * @return string
 */
function wrap_cache_extension($host, $path) {
	if (!$ext = wrap_setting('cache_extension')) return $path;
	// only for localhost
	if ($host !== wrap_setting('hostname')) return $path;
	$ext = '.'.$ext;
	if (str_ends_with($path, '/'))
		return substr($path, 0, -1).$ext;
	$parts = explode('/', $path);
	// does it have already a file extension?
	if (strstr(end($parts), '.')) return $path;
	return $path.$ext;
}

/**
 * check if a cache for a ressource exists
 *
 * @param array $cache = result of wrap_cache_filenames()
 * @return bool
 */
function wrap_cache_exists($cache) {
	if (!$cache) return '';
	if (file_exists($cache['url'])) return $cache['url'];
	if (!file_exists($cache['headers'])) return '';
	if (!$file = wrap_cache_get_header($cache['headers'], 'X-Local-Filename')) return '';
	if (file_exists($file)) return $file;
	return '';
}
