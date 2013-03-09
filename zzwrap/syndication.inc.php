<?php 

/**
 * zzwrap
 * Syndication functions
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2012 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


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
	if (!empty($zz_setting['cache'])) {
		$files = array(wrap_cache_filename('url', $url), wrap_cache_filename('headers', $url));
		// does a cache file exist?
		if (file_exists($files[0]) AND file_exists($files[1])) {
			$fresh = wrap_cache_freshness($files, $zz_setting['cache_age_syndication']);
			if ($fresh) {
				$data = file_get_contents($files[0]);
			} else {
				// get ETag and Last-Modified from cache file
				$etag = wrap_cache_get_header($files[1], 'ETag');
				$last_modified = filemtime($files[1]);
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

		if (!function_exists('curl_init')) {
			// file_get_contents does not allow to send additional headers
			// e. g. IF_NONE_MATCH, so we'll always try to get the data
			// do not log error here
			$opts = array(
				'http' => array(
					'method' => 'GET',
					'header' => implode("\r\n", $headers_to_send)
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
			if ($etag) {
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_to_send);
			}
			$data = curl_exec($ch);
			if (substr($url, 0, 8) === 'https://') {
				$ssl_verify = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
				if (!$ssl_verify) {
					wrap_error(sprintf('Syndication from URL %s: SSL certificate could not be validated.', $url), E_USER_NOTICE);
					if (!empty($zz_setting['curl_ignore_ssl_verifyresult'])) {
						// try again without SSL verification
						curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
						$data = curl_exec($ch);
					}
				}
			}
			$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			//$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
			curl_close($ch);
			// separate headers from data
			if ($status === 200) {
				$headers = substr($data, 0, strpos($data, "\r\n\r\n"));
				$headers = explode("\r\n", $headers);
				$data = substr($data, strpos($data, "\r\n\r\n") + 4);
			}
		}

		switch ($status) {
		case 200:
			$my_etag = '';
			foreach ($headers as $header) {
				if (substr($header, 0, 6) != 'ETag: ') continue;
				$my_etag = substr($header, 7, -1);
			}
			if ($data and !empty($zz_setting['cache'])) {
				wrap_cache_ressource($data, $my_etag, $url, $headers);
			}
			break;
		case 304:
			// cache file must exist, we would not have an etag header
			// so use it
			$data = file_get_contents($files[0]);
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
		return $object;
	default:
		return -1;
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

?>