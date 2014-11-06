<?php 

/**
 * zzwrap
 * Syndication functions
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2012-2014 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Get content from a foreign URL (syndication), cache the result if possible
 * (and wanted)
 *
 * @param string $url
 * @param string $type Type of ressource, defaults to 'json'
 * @return array $data
 */
function wrap_syndication_get($url, $type = 'json') {
	global $zz_setting;
	// you may change the error code if e. g. only pictures will be fetched
	// via JSON to E_USER_WARNING or E_USER_NOTICE
	if (empty($zz_setting['syndication_error_code']))
		$zz_setting['syndication_error_code'] = E_USER_ERROR;
	$data = array();
	$etag = '';
	$last_modified = '';
	if (!$url) return false;

	if (!isset($zz_setting['cache_age_syndication'])) {
		$zz_setting['cache_age_syndication'] = 0;
	}
	$files = array();
	if (!empty($zz_setting['cache'])) {
		$files = array(wrap_cache_filename('url', $url), wrap_cache_filename('headers', $url));
		// does a cache file exist?
		if (file_exists($files[0]) AND file_exists($files[1])) {
			$fresh = wrap_cache_freshness($files, $zz_setting['cache_age_syndication']);
			$last_modified = filemtime($files[0]);
			if ($fresh) {
				$data = file_get_contents($files[0]);
			} else {
				// get ETag and Last-Modified from cache file
				$etag = wrap_cache_get_header($files[1], 'ETag');
			}
		}
	}
	if (!$data) {
		$headers_to_send = array();
		if ($etag) {
			$headers_to_send = array(
				'If-None-Match: "'.$etag.'"'
			);
		}
		// @todo Last-Modified

		list($status, $headers, $data) = wrap_syndication_retrieve_via_http($url, $headers_to_send);

		switch ($status) {
		case 200:
			$my_etag = substr(wrap_syndication_http_header('ETag', $headers), 1, -1);
			if ($data and !empty($zz_setting['cache'])) {
				wrap_cache_ressource($data, $my_etag, $url, $headers);
				$last_modified = wrap_cache_get_header($files[1], 'Last-Modified');
			}
			break;
		case 304:
			// cache file must exist, we would not have an etag header
			// so use it
			$data = file_get_contents($files[0]);
			if (!empty($zz_setting['cache'])) {
				$last_modified = wrap_cache_get_header($files[1], 'Last-Modified');
			}
			break;
		case 302:
		case 303:
		case 307:
			$data = NULL;
			wrap_error(sprintf('Syndication from URL %s failed with redirect status code %s. Use URL %s instead.',
				$url, $status, wrap_syndication_http_header('Location', $headers)), $zz_setting['syndication_error_code']);
			break;
		case 404:
			$data = array();
			break;
		default:
			if (file_exists($files[0])) {
				// connection error, use (possibly stale) cache file
				$data = file_get_contents($files[0]);
				wrap_error(sprintf('Syndication from URL %s failed with status code %s. Using cached file instead.',
					$url, $status), E_USER_NOTICE);
				if (!empty($zz_setting['cache'])) {
					$last_modified = wrap_cache_get_header($files[1], 'Last-Modified');
				}
			} else {
				$data = NULL;
				wrap_error(sprintf('Syndication from URL %s failed with status code %s.',
					$url, $status), $zz_setting['syndication_error_code']);
			}
			break;
		}
	}

	if (!$data) return $data;
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
		if ($last_modified AND is_array($object)) {
			$object['_']['Last-Modified'] = $last_modified;
		}
		return $object;
	default:
		$object = array();
		$object['_']['data'] = true;
		if (!empty($files[0])) {
			$object['_']['filename'] = $files[0];
		}
		if ($last_modified) {
			$object['_']['Last-Modified'] = $last_modified;
		}
		return $object;
	}
}

function wrap_syndication_errors($errno, $errstr, $errfile, $errline, $errcontext) {
	// just catch the error, don't do anything
	return;
}

/**
 * Get geographic coordinates and postal code from address or parts of an
 * address
 *
 * @param array $address address data, utf8 encoded
 *	string 'country'
 *	string 'locality'
 *	string 'postal_code' (optional)
 *	string 'street_name' (optional)
 *	string 'street_number' (optional)
 * @return array
 *		double 'longitude'
 *		double 'latitude'
 *		string 'postal_code'
 */
function wrap_syndication_geocode($address) {
	global $zz_setting;
	global $zz_error;
	
	if (!isset($zz_setting['geocoder'])) {
		$zz_setting['geocoder'] = 'Google Maps';
	}
	switch ($zz_setting['geocoder']) {
	case 'Google Maps':
		$url = 'https://maps.googleapis.com/maps/api/geocode/json?address=%s&region=%s&sensor=false';
		$add = '';
		if (isset($address['locality'])) {
			$add = $address['locality'];
		}
		if (isset($address['postal_code'])) {
			$add = $address['postal_code'].($add ? ' ' : '').$add;
		}
		$add = urlencode($add);
		if (isset($address['street_name'])) {
			$add = urlencode($address['street_name']
				.(isset($address['street_number']) ? ' '.$address['street_number'] : ''))
				.($add ? ',' : '').$add;
		}
		$region = isset($address['country']) ? $address['country'] : '';
		$url = sprintf($url, $add, $region);
		break;
	default:
		$zz_error[]['msg_dev'] = sprintf('Geocoder %s not supported.', $zz_setting['geocoder']);
		break;
	}

	$cache_age_syndication = (isset($zz_setting['cache_age_syndication']) ? $zz_setting['cache_age_syndication'] : 0);
	$zz_setting['cache_age_syndication'] = -1;
	$coords = wrap_syndication_get($url);	
	$zz_setting['cache_age_syndication'] = $cache_age_syndication;
	if ($coords['status'] !== 'OK') {
		if ($coords['status'] === 'OVER_QUERY_LIMIT') {
			// we must not cache this.
			wrap_cache_delete(404, $url);
		}
		wrap_error(sprintf('Syndication from %s failed with status %s. (%s)',
			$zz_setting['geocoder'], $coords['status'], $url));
		return false;
	}
	
	if (empty($coords['results'][0]['geometry']['location']['lng'])) return false;
	$postal_code = '';
	foreach ($coords['results'][0]['address_components'] as $component) {
		if (in_array('postal_code', $component['types']))
			$postal_code = $component['long_name'];
	}
	$result = array(
		'longitude' => $coords['results'][0]['geometry']['location']['lng'], 
		'latitude' => $coords['results'][0]['geometry']['location']['lat'],
		'postal_code' => $postal_code
	);
	return $result;
}

/**
 * get content via HTTP URL
 *
 * @param string $url
 * @param array $headers_to_send
 * @param string $method (optional, defaults to GET)
 * @param array $data_to_send (optional)
 * @param string $pwd (optional, username:password)
 * @return array
 *		int $status
 *		array $headers
 *		array $data
 */
function wrap_syndication_retrieve_via_http($url, $headers_to_send = array(), $method = 'GET', $data_to_send = array(), $pwd = false) {
	global $zz_setting;

	if (!function_exists('curl_init')) {
		// file_get_contents does not allow to send additional headers
		// e. g. IF_NONE_MATCH, so we'll always try to get the data
		// do not log error here
		$content = false;
		if ($method === 'POST') {
			$headers_to_send[] = 'Content-Type: application/x-www-form-urlencoded';
			$content = wrap_syndication_http_post($data_to_send);
		}
		if ($pwd) {
			$headers_to_send[] = "Authorization: Basic " . base64_encode($pwd);
		}
		$opts = array(
			'http' => array(
				'method' => $method,
				'header' => implode("\r\n", $headers_to_send),
				'content' => $content
			)
		);
		$context = stream_context_create($opts);
		set_error_handler('wrap_syndication_errors');
		$data = file_get_contents($url, false, $context);
		restore_error_handler();
		if (!empty($http_response_header)) {
			$headers = $http_response_header;
		} else {
			$headers = array();
			$status = 503;
		}
		foreach ($headers as $header) {
			if (substr($header, 0, 5) === 'HTTP/') {
				$status = explode(' ', $header);
				$status = $status[1];
			}
		}
	} else {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; Zugzwang Project; +http://www.zugzwang.org/)');
		if ($headers_to_send) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_to_send);
		}
		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			if (!empty($data_to_send)) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, wrap_syndication_http_post($data_to_send));
			}
		}
		if ($pwd) {
			curl_setopt($ch, CURLOPT_USERPWD, $pwd);
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		}
		if (substr($url, 0, 8) === 'https://') {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			// Certficates are bundled with CURL from 7.10 onwards, PHP 5 requires at least 7.10
			// so there should be currently no need to include an own PEM file
			// curl_setopt($ch, CURLOPT_CAINFO, $zz_setting['cainfo_file']);
		}
		$data = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if (!$status) {
			if (substr($url, 0, 8) === 'https://' AND !empty($zz_setting['curl_ignore_ssl_verifyresult'])) {
				// try again without SSL verification
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
				$data = curl_exec($ch);
				$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			}
			if ($status) {
				wrap_error(sprintf('Syndication from URL %s failed. Using SSL connection without validation instead. Reason: %s', $url, curl_error($ch)), E_USER_WARNING);
			} else {
				wrap_error(sprintf('Syndication from URL %s failed. Reason: %s', $url, curl_error($ch)), E_USER_WARNING);
			}
		}
		curl_close($ch);
		// separate headers from data
		$headers = substr($data, 0, strpos($data, "\r\n\r\n"));
		$headers = explode("\r\n", $headers);
		$data = substr($data, strpos($data, "\r\n\r\n") + 4);
	}
	return array($status, $headers, $data);
}

/**
 * parse the value from a HTTP header
 *
 * @param string $which
 * @param array $headers
 * @return string
 */
function wrap_syndication_http_header($which, $headers) {
	$value = '';
	foreach ($headers as $header) {
		if (substr($header, 0, strlen($which) + 2) != $which.': ') continue;
		$value = substr($header, strlen($which) + 2);
	}
	return $value;
}

/**
 * write the form data in URL encoded form
 *
 * @param array $data
 * @return string
 */
function wrap_syndication_http_post($data) {
	$postdata = array();
	foreach ($data as $key => $value) {
		$postdata[] = urlencode($key).'='.urlencode($value);
	}
	return implode('&', $postdata);
}
