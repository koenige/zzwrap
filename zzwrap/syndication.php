<?php 

// zzwrap (Project Zugzwang)
// Copyright (c) 2012 Gustaf Mossakowski, <gustaf@koenige.org>
// syndication functions


/**
 * Get content from a foreign URL (syndication), cache the result if possible
 * (and wanted)
 *
 * @param string $url
 * @param string $type Type of ressource, defaults to 'json'
 * @return array $data
 * @todo: think about cURL if it's available
 */
function wrap_syndication_get($url, $type = 'json') {
	global $zz_setting;
	$data = array();
	$etag = '';
	$last_modified = '';

	if (!isset($zz_setting['cache_age_syndication'])) {
		$zz_setting['cache_age_syndication'] = 0;
	}
	if (!empty($zz_setting['cache'])) {
		$files = array(wrap_cache_filename(), wrap_cache_filename('headers'));
		// does a cache file exist?
		if (file_exists($files[0]) AND file_exists($files[1])) {
			$fresh = wrap_cache_freshness($files, $zz_setting['cache_age_syndication']);
			if ($fresh) {
				$data = file_get_contents($files[0]);
			} else {
				// get ETag and Last-Modified from cache file
				$etag = wrap_cache_get_header($files[1]);
				$last_modified = filemtime($files[1]);
			}
		}
	}
	if (!$data) {
		if (!function_exists('curl_init')) {
			// file_get_contents does not allow to send additional headers
			// e. g. IF_NONE_MATCH, so we'll always try to get the data
			// do not log error here
			set_error_handler('wrap_syndication_errors');
			$data = file_get_contents($url);
			restore_error_handler();
			if ($data and !empty($zz_setting['cache'])) {
				wrap_cache_ressource($data, $url, array('ETag: '.md5($data)));
			}
		} else {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_HEADER, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			if ($etag) {
				$headers_to_send = array(
					'If-None-Match: "'.$etag.'"'
				);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_to_send);
			}
			$data = curl_exec($ch);
			$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
			curl_close($ch);

			switch ($status) {
			case 200:
				$headers = substr($data, 0, strpos($data, "\r\n\r\n"));
				$data = substr($data, strpos($data, "\r\n\r\n") + 4);
				if ($data and !empty($zz_setting['cache'])) {
					wrap_cache_ressource($data, $url, $headers);
				}
				break;
			case 304:
				// cache file must exist, we would not have an etag header
				// so use it
				$data = file_get_contents($files[0]);
				break;
			default:
				$data = -1;
				wrap_error(sprintf('Syndication from URL %s failed with status code %s.',
					$url, $status), E_USER_WARNING);
			}
		}
	}

	switch ($type) {
	case 'json':
		$object = json_decode($data, true);	// Array
		if (!$object) {
			// maybe this PHP version does not know how to handle strings
			// so convert it into an array
			$object = json_decode('['.$data.']', true);
			// convert it back to a string
			if (count($object) == 1 AND isset($object[0]))
				$object = $object[0];
		}
		return $object;
	default:
		return -1;
	}
}

function wrap_syndication_errors($errno, $errstr, $errfile, $errline, $errcontext) {
	// we do not care about 404 errors, they will be logged otherwise
	if (trim($errstr) AND substr(trim($errstr), -13) != '404 Not Found') {
		// you may change the error code if e. g. only pictures will be fetched
		// via JSON to E_USER_WARNING or E_USER_NOTICE
		if (empty($errcontext['setting']['brick_import_error_code']))
			$errcontext['setting']['brick_import_error_code'] = E_USER_ERROR;
		wrap_error('JSON ['.$_SERVER['SERVER_ADDR'].']: '.$errstr,
			$errcontext['setting']['brick_import_error_code']);
	}
}

?>