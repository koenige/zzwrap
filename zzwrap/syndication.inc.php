<?php 

/**
 * zzwrap
 * Syndication functions
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2012-2016 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Get content from a foreign URL (syndication), cache the result if possible
 * (and wanted)
 *
 * @param string $url
 * @param string $type Type of ressource, defaults to 'json'
 * @param string $cache_filename (optional)
 * @return array $data
 */
function wrap_syndication_get($url, $type = 'json', $cache_filename = false) {
	global $zz_setting;
	// you may change the error code if e. g. only pictures will be fetched
	// via JSON to E_USER_WARNING or E_USER_NOTICE
	if (empty($zz_setting['syndication_error_code']))
		$zz_setting['syndication_error_code'] = E_USER_ERROR;
	$data = array();
	$etag = '';
	$last_modified = '';
	if (!$url) return false;
	if (!$cache_filename) $cache_filename = $url;

	if (!isset($zz_setting['cache_age_syndication'])) {
		$zz_setting['cache_age_syndication'] = 0;
	}
	$files = array();
	if (!empty($zz_setting['cache'])) {
		$files = array(
			wrap_cache_filename('url', $cache_filename),
			wrap_cache_filename('headers', $cache_filename)
		);
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
				if ($cache_filename !== $url) {
					$headers[] = sprintf('X-Source-URL: %s', $url);
				}
				wrap_cache_ressource($data, $my_etag, $cache_filename, $headers);
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
			wrap_error(sprintf(
				'Syndication from URL %s failed with redirect status code %s. Use URL %s instead.',
				$url, $status, wrap_syndication_http_header('Location', $headers)
			), $zz_setting['syndication_error_code']);
			break;
		case 404:
			$data = array();
			break;
		default:
			if (file_exists($files[0])) {
				// connection error, use (possibly stale) cache file
				$data = file_get_contents($files[0]);
				wrap_error(sprintf(
					'Syndication from URL %s failed with status code %s. Using cached file instead.',
					$url, $status
				), E_USER_NOTICE);
				if (!empty($zz_setting['cache'])) {
					$last_modified = wrap_cache_get_header($files[1], 'Last-Modified');
				}
			} else {
				$data = NULL;
				wrap_error(sprintf(
					'Syndication from URL %s failed with status code %s.',
					$url, $status
				), $zz_setting['syndication_error_code']);
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
		if (is_array($object)) {
			if ($last_modified) {
				$object['_']['Last-Modified'] = $last_modified;
			}
			$object['_']['type'] = 'json';
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
		$object['_']['type'] = $type;
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
	
	$urls['Google Maps'] = 'https://maps.googleapis.com/maps/api/geocode/json?address=%s&region=%s&sensor=false';
	// @see http://wiki.openstreetmap.org/wiki/Nominatim_usage_policy
	$urls['Nominatim'] = 'http://nominatim.openstreetmap.org/search.php?q=%s&countrycodes=%s&format=jsonv2&accept-language=de&limit=50';
	
	$add[0] = '';
	if (isset($address['locality'])) {
		$add[0] = trim($address['locality']);
	}
	if (!empty($address['postal_code'])) {
		$add[0] = trim($address['postal_code']).($add[0] ? '+' : '').$add[0];
	}
	$add[0] = urlencode($add[0]);
	if (!empty($address['street_name'])) {
		$add[0] = urlencode($address['street_name']
			.(isset($address['street_number']) ? ' '.$address['street_number'] : ''))
			.($add[0] ? ',' : '').$add[0];
	}
	if (!empty($address['state'])) {
		$add[0] .= ','.urlencode($address['state']);
	}
	$region = isset($address['country']) ? $address['country'] : '';

	// place is optional
	// remove parts of place name that are already found in other keys
	if (!empty($address['place'])) {
		$remove_keys = array('locality', 'postal_code', 'street_name', 'street_number');
		foreach ($remove_keys as $key) {
			if (empty($address[$key])) continue;
			if (strstr($address['place'], $address[$key]))
				$address['place'] = trim(str_replace($address[$key], '', $address['place']));
		}
		if ($address['place'] === ',') $address['place'] = '';
		if ($address['place']) {
			$add[-1] = urlencode($address['place']).','.$add[0];
		}
	}
	ksort($add);

	// set geocoders
	if (!isset($zz_setting['geocoder'])) {
		$zz_setting['geocoder'] = array('Nominatim', 'Google Maps');
	} elseif (!is_array($zz_setting['geocoder'])) {
		$zz_setting['geocoder'] = array($zz_setting['geocoder']);
	}
	foreach ($zz_setting['geocoder'] as $geocoder) {
		if (!array_key_exists($geocoder, $urls)) {
			$zz_error[]['msg_dev'] = sprintf('Geocoder %s not supported.', $geocoder);
			return false;
		}
		foreach (array_keys($add) as $index) {
			$geocoders[] = array(
				'geocoder' => $geocoder,
				'add' => $add[$index],
				'region' => $region
			);
		}
	}

	$cache_age_syndication = (isset($zz_setting['cache_age_syndication']) ? $zz_setting['cache_age_syndication'] : 0);
	$zz_setting['cache_age_syndication'] = -1;

	$results = array();
	$found = array();
	foreach ($geocoders as $gc) {
		// only call a geocoder twice if first call was unsuccesful
		if (in_array($gc['geocoder'], $found)) continue;

		$url = sprintf($urls[$gc['geocoder']], $gc['add'], $gc['region']);
		$coords = wrap_syndication_get($url);	

		$success = true;
		switch ($gc['geocoder']) {
		case 'Google Maps':
			if ($coords['status'] !== 'OK') {
				if ($coords['status'] === 'OVER_QUERY_LIMIT') {
					// we must not cache this.
					wrap_cache_delete(404, $url);
				}
				$success = false;
			}
			break;
		case 'Nominatim':
			if (empty($coords[0])) {
				$success = false;
				$coords['status'] = 'unknown';
			}
			break;
		}
		if (!$success) {
			wrap_error(sprintf('Syndication from %s failed with status %s. (%s)',
				$gc['geocoder'], $coords['status'], $url));
			continue;
		}

		switch ($gc['geocoder']) {
		case 'Google Maps':
			foreach ($coords['results'] as $coord) {
				if (empty($coord['geometry']['location']['lng'])) continue;
				$postal_code = '';
				foreach ($coord['address_components'] as $component) {
					if (in_array('postal_code', $component['types']))
						$postal_code = $component['long_name'];
					// check if country is the same
					if (in_array('country', $component['types'])) {
						if ($gc['region'] !== $component['short_name']) continue 2;
					}
				}
				$results[] = array(
					'longitude' => $coord['geometry']['location']['lng'], 
					'latitude' => $coord['geometry']['location']['lat'],
					'display' => $coord['formatted_address'],
					'source' => $gc['geocoder'],
					'postal_code' => $postal_code
				);
				$found[] = $gc['geocoder'];
			}
			break;
		case 'Nominatim':
			foreach ($coords as $index => $coord) {
				if (!is_numeric($index)) continue;
				$postal_code = '';
				// unfortunately, Nominatim has no way of getting the postal code directly
				// so this assumes that there is at least one numeric character in each
				// postal code
				$display = explode(', ', $coord['display_name']);
				array_pop($display); // country
				$postal_code = array_pop($display);
				if (!preg_match('~[0-9]+~', $postal_code)) $postal_code = '';
				$results[] = array(
					'longitude' => $coord['lon'], 
					'latitude' => $coord['lat'],
					'source' => $gc['geocoder'],
					'display' => $coord['display_name'],
					'postal_code' => $postal_code
				);
				$found[] = $gc['geocoder'];
			}
			break;
		}
	}
	if (!$results) return array();
	if (count($results) === 1) return $results[0];

	$remove = array(',', '.', '/', '-', '(', ')', '?');
	foreach ($remove as $token) {
		foreach ($address as $key => $value) {
			$address[$key] = trim(str_replace($token, ' ', $value));
		}
	}
	$parts = array();
	foreach ($address as $key => $value) {
		if (!$value) continue;
		while (strstr($value, '  ')) {
			$value = str_replace('  ', ' ', $value);
		}
		$values = explode(' ', $value);
		$parts = array_merge($parts, $values);
	}
	$top_match = 0;
	$result_index = false;
	foreach ($results as $index => $result) {
		$results[$index]['matches'] = 0;
		foreach ($parts as $part) {
			// add space to get correct matches for house nos.
			// which otherwise might be part of postal code etc.
			if (strstr(' '.$result['display'], ' '.$part)) $results[$index]['matches']++;
		}
		if ($results[$index]['matches'] > $top_match) {
			$result_index = $index;
			$top_match = $results[$index]['matches'];
		}
	}

	$zz_setting['cache_age_syndication'] = $cache_age_syndication;
	return $results[$result_index];
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
	global $zz_conf;

	$timeout_ignore = false;
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
		// @todo add some method to avoid timeouts because of slow name lookups
		$protocol = substr($url, 0, strpos($url, ':'));
		$ch = curl_init();
		if ($zz_conf['debug']) {
			$f = fopen($zz_conf['tmp_dir'].'/curl-request-'.time().'.txt', 'w');
			curl_setopt($ch, CURLOPT_VERBOSE, 1);
			curl_setopt($ch, CURLOPT_STDERR, $f);
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, 
			'Mozilla/5.0 (compatible; Zugzwang Project; +http://www.zugzwang.org/)'
		);
		if ($headers_to_send) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_to_send);
			if (in_array('X-Timeout-Ignore: 1', $headers_to_send)) {
				$timeout_ignore = true;
				curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
				// without NOSIGNAL, cURL might terminate immediately
				// when using the standard name resolver
				curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
				curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
			}
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
		if ($protocol === 'https') {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			// Certficates are bundled with CURL from 7.10 onwards, PHP 5 requires at least 7.10
			// so there should be currently no need to include an own PEM file
			// curl_setopt($ch, CURLOPT_CAINFO, $zz_setting['cainfo_file']);
		}
		$data = curl_exec($ch);
		if ($zz_conf['debug']) fclose($f);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($timeout_ignore) {
			if ($zz_conf['debug']) {
				$info = curl_getinfo($ch);
				wrap_error('JSON '.json_encode($info));
			}
			// we don't know what happened but can't check it
			$status = 200;
		} else {
			if (!$status) {
				if ($protocol === 'https' AND !empty($zz_setting['curl_ignore_ssl_verifyresult'])) {
					// try again without SSL verification
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
					curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
					$data = curl_exec($ch);
					$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				}
				if ($status) {
					wrap_error(sprintf(
						'Syndication from URL %s failed. Using SSL connection without validation instead. Reason: %s',
						$url, curl_error($ch)
					), E_USER_WARNING);
				} else {
					wrap_error(sprintf(
						'Syndication from URL %s failed. Reason: %s',
						$url, curl_error($ch)
					), E_USER_WARNING);
				}
			}
		}
		curl_close($ch);
		if (!$timeout_ignore) {
			// separate headers from data
			$headers = substr($data, 0, strpos($data, "\r\n\r\n"));
			$headers = explode("\r\n", $headers);
			$data = substr($data, strpos($data, "\r\n\r\n") + 4);
		} else {
			$status = 200; // not necessarily true, but we don't want to wait
			$headers = array();
			$data = array();
		}
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
