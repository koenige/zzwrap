<?php 

/**
 * zzwrap
 * Syndication functions, Locking functions, Watchdog
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2012-2023 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Get content from a foreign URL (syndication), cache the result if possible
 * (and wanted)
 *
 * @param string $url
 * @param string $type Type of ressource, defaults to 'json'
 * @param array $settings (optional)
 *		string 'cache_filename'
 *		int 'cache_age_syndication'
 *		array 'headers_to_send'
 * @return array $data
 */
function wrap_syndication_get($url, $type = 'json', $settings = []) {
	// you may change the syndication_error_code if e. g. only pictures will be fetched
	// via JSON to E_USER_WARNING or E_USER_NOTICE
	if (!$url) return [];

	$data = [];
	$etag = '';
	$last_modified = '';
	$cache_filename = $settings['cache_filename'] ?? $url;
	$cache_age_syndication = $settings['cache_age_syndication'] ?? wrap_setting('cache_age_syndication');

	$files = [];
	if (wrap_setting('cache')) {
		$files = [
			wrap_cache_filename('url', $cache_filename),
			wrap_cache_filename('headers', $cache_filename)
		];
		// does a cache file exist?
		if ($files[1] AND file_exists($files[0]) AND file_exists($files[1])) {
			$fresh = wrap_cache_freshness($files, $cache_age_syndication);
			$last_modified = wrap_cache_get_header($files[1], 'Last-Modified');
			if (!$last_modified)
				$last_modified = wrap_date(filemtime($files[0]), 'timestamp->rfc1123');
			if ($fresh) {
				$data = file_get_contents($files[0]);
			} else {
				// get ETag and Last-Modified from cache file
				$etag = wrap_cache_get_header($files[1], 'ETag');
			}
		}
	}
	if (!$data) {
		wrap_error(false, false, ['collect_start' => true]);
		$headers_to_send = $settings['headers_to_send'] ?? [];
		if ($etag) $headers_to_send[] = 'If-None-Match: "'.$etag.'"';
		foreach ($headers_to_send as $header) {
			if (!str_starts_with($header, 'Authorization: Bearer')) continue;
			$headers_to_send[] = 'X-Request-WWW-Authentication: 1';
		}
		$pwd = $settings['pwd'] ?? false;
		// @todo Last-Modified
		
		list($status, $headers, $data) = wrap_syndication_retrieve_via_http($url, $headers_to_send, 'GET', [], $pwd);

		switch ($status) {
		case 200:
			$my_etag = substr(wrap_syndication_http_header('ETag', $headers), 1, -1);
			if ($data and wrap_setting('cache') AND $files[1]) {
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
			if (wrap_setting('cache')) {
				$last_modified = wrap_cache_get_header($files[1], 'Last-Modified');
			}
			wrap_cache_revalidated($files[1]);
			break;
		case 302:
		case 303:
		case 307:
			$data = NULL;
			wrap_error(sprintf(
				'Syndication from URL %s failed with redirect status code %s. Use URL %s instead.',
				$url, $status, wrap_syndication_http_header('Location', $headers)
			), wrap_setting('syndication_error_code'));
			break;
		case 404:
			$data = [];
			break;
		default:
			if (!empty($files[0]) AND file_exists($files[0])) {
				// connection error, use (possibly stale) cache file
				$data = file_get_contents($files[0]);
				wrap_error(sprintf(
					'Syndication from URL %s failed. Status code %s. Using cached file instead.',
					$url, $status
				), E_USER_NOTICE);
				if (wrap_setting('cache'))
					$last_modified = wrap_cache_get_header($files[1], 'Last-Modified');
			} else {
				$data = NULL;
				wrap_error(sprintf(
					'Syndication from URL %s failed. Status code %s.',
					$url, $status
				), wrap_setting('syndication_error_code'));
			}
			break;
		}
		wrap_error(false, false, ['collect_end' => true]);
	}

	if (!$data) return [];
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
		$object = [];
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

function wrap_syndication_errors($errno, $errstr, $errfile = '', $errline = 0, $errcontext = []) {
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
	$add[0] = '';
	if (isset($address['locality'])) {
		$add[0] = trim(urlencode($address['locality']));
	}
	if (!empty($address['postal_code'])) {
		$add[0] = trim(urlencode($address['postal_code'])).($add[0] ? '+' : '').$add[0];
	}
	$is_postbox = false;
	if (!empty($address['street_name'])) {
		$street = explode("\n", $address['street_name']);
		foreach ($street as $index => $line) {
			if (count($street) > 1) {
				// do not remove if this is single information about street
				// geocoders do not know German Ortsteil
				if (str_starts_with($line, 'OT ')) unset($street[$index]);
			}
			// care of: no use for geocoding
			foreach (wrap_setting('geocoder_care_of') as $care_of_string)
				if (str_starts_with($line, $care_of_string)) unset($street[$index]);
			// geocoders do not know postbox
			foreach (wrap_setting('geocoder_postbox') as $postbox_string) {
				if (!str_starts_with($line, $postbox_string.' ')) continue;
				unset($street[$index]); 
				$is_postbox = true; // never change postal_code
			}
		}
		$add[0] = urlencode(implode("\n", $street)
			.(isset($address['street_number']) ? ' '.$address['street_number'] : ''))
			.($add[0] ? ',' : '').$add[0];
	}
	if (!empty($address['state'])) {
		$add[0] .= ','.urlencode($address['state']);
	}
	$region = isset($address['country']) ? $address['country'] : '';
	// virtual place?
	if ($region === '-' OR $region === '--' OR $region === '---') return [];

	// place is optional
	// remove parts of place name that are already found in other keys
	if (!empty($address['place'])) {
		$remove_keys = ['locality', 'postal_code', 'street_name', 'street_number'];
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

	// replace line breaks with ','
	foreach ($add as $index => $line) {
		if (strstr($line, "%0D%0A")) {
			$add[$index] = str_replace("%0D%0A", ",", $line);
		}
	}
	// remove duplicate tokens
	foreach ($add as $index => $line) {
		$line = explode(',', $line);
		$last_token = '';
		foreach ($line as $lindex => $token) {
			if ($token === $last_token) unset($line[$lindex]);
			$last_token = $token;
		}
		$add[$index] = implode(',', $line);
	}
	ksort($add);
	
	$urls = wrap_setting('geocoder_urls');

	// set geocoders
	$geocoders = wrap_setting('geocoder');
	if (!$geocoders) return false;
	foreach ($geocoders as $geocoder) {
		if (!array_key_exists($geocoder, $urls)) {
			wrap_error(sprintf('Geocoder %s not supported.', $geocoder), E_USER_WARNING);
			return false;
		}
		foreach ($add as $index => $add_values) {
			if (empty($add_values)) continue;
			$gcs[] = [
				'geocoder' => $geocoder,
				'add' => $add_values,
				'region' => $region
			];
		}
	}
	if (empty($gcs)) return false;

	$results = [];
	$found = [];
	foreach ($gcs as $gc) {
		// only call a geocoder twice if first call was unsuccesful
		if (in_array($gc['geocoder'], $found)) continue;

		$url = sprintf($urls[$gc['geocoder']], $gc['add'], $gc['region']);
		if ($gc['geocoder'] === 'Nominatim') {
			wrap_lock_wait('nominatim', 1);
		}
		$coords = wrap_syndication_get($url, 'json', ['cache_age_syndication' => -1]);	
		if ($gc['geocoder'] === 'Nominatim') {
			wrap_unlock('nominatim');
		}

		$success = true;
		switch ($gc['geocoder']) {
		case 'Google Maps':
			if ($coords['status'] !== 'OK') {
				if ($coords['status'] === 'OVER_QUERY_LIMIT') {
					// we must not cache this.
					wrap_cache_delete(404, $url);
				}
				if ($coords['status'] === 'REQUEST_DENIED') {
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
		default:
			wrap_error(sprintf('%s is not supported for geocoding.', $gc['geocoder']));
			break;
		}
		if (!$success) {
			wrap_error(sprintf('Syndication from %s failed with status %s. %s',
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
				if ($is_postbox) $postal_code = '';
				$results[] = [
					'longitude' => $coord['geometry']['location']['lng'], 
					'latitude' => $coord['geometry']['location']['lat'],
					'display' => $coord['formatted_address'],
					'source' => $gc['geocoder'],
					'postal_code' => $postal_code
				];
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
				if ($is_postbox) $postal_code = '';
				$results[] = [
					'longitude' => $coord['lon'], 
					'latitude' => $coord['lat'],
					'source' => $gc['geocoder'],
					'display' => $coord['display_name'],
					'postal_code' => $postal_code
				];
				$found[] = $gc['geocoder'];
			}
			break;
		}
	}
	if (!$results) return [];
	if (count($results) === 1) return $results[0];

	$remove = [',', '.', '/', '-', '(', ')', '?'];
	foreach ($remove as $token) {
		foreach ($address as $key => $value) {
			$address[$key] = trim(str_replace($token, ' ', $value));
		}
	}
	$parts = [];
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
function wrap_syndication_retrieve_via_http($url, $headers_to_send = [], $method = 'GET', $data_to_send = [], $pwd = false) {
	$timeout_ignore = false;
	if (!function_exists('curl_init')) {
		// file_get_contents does not allow to send additional headers
		// e. g. IF_NONE_MATCH, so we'll always try to get the data
		// do not log error here
		$content = false;
		if (in_array($method, ['POST', 'PATCH'])) {
			$headers_to_send[] = 'Content-Type: application/x-www-form-urlencoded';
			// do not send Expect: 100-continue with POST
			$headers_to_send[] = 'Expect:';
			$content = wrap_syndication_http_post($data_to_send);
		}
		if ($pwd) {
			$headers_to_send[] = "Authorization: Basic " . base64_encode($pwd);
		}
		$opts = [
			'http' => [
				'method' => $method,
				'header' => implode("\r\n", $headers_to_send),
				'content' => $content
			]
		];
		if ($timeout_ms = wrap_setting('syndication_timeout_ms')) {
			$opts['http']['timeout'] = $timeout_ms / 1000;
		}
		$context = stream_context_create($opts);
		set_error_handler('wrap_syndication_errors');
		$data = file_get_contents($url, false, $context);
		restore_error_handler();
		if (!empty($http_response_header)) {
			$headers = $http_response_header;
		} else {
			$headers = [];
			$status = 503;
		}
		foreach ($headers as $header) {
			if (str_starts_with($header, 'HTTP/')) {
				$status = explode(' ', $header);
				$status = intval($status[1]);
			}
		}
	} else {
		// @todo add some method to avoid timeouts because of slow name lookups
		$protocol = substr($url, 0, strpos($url, ':'));
		$ch = curl_init();
		if (wrap_setting('debug')) {
			$f = fopen(wrap_setting('tmp_dir').'/curl-request-'.time().'.txt', 'w');
			curl_setopt($ch, CURLOPT_VERBOSE, 1);
			curl_setopt($ch, CURLOPT_STDERR, $f);
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, 
			'Zugzwang Project; +https://www.zugzwang.org/'
		);
		if (in_array($method, ['DELETE', 'PATCH', 'PUT'])) {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		}
		if ($headers_to_send) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_to_send);
			if (in_array('X-Timeout-Ignore: 1', $headers_to_send)) {
				$timeout_ignore = true;
				curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
				// without NOSIGNAL, cURL might terminate immediately
				// when using the standard name resolver
				curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
				curl_setopt($ch, CURLOPT_TIMEOUT_MS, wrap_setting('syndication_trigger_timeout_ms'));
			}
		}
		if (!$timeout_ignore AND $timeout_ms = wrap_setting('syndication_timeout_ms')) {
			curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout_ms);
		}
		if (in_array($method, ['POST', 'PATCH'])) {
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
			// ignore verification on development server if target is
			// development server, too
			if (wrap_setting('local_access')) {
				$remote_host = parse_url($url, PHP_URL_HOST);
				if (str_ends_with($remote_host, '.local')
				    OR str_starts_with($remote_host, 'dev.')) {
					$old_curl_ignore_ssl_verifyresult = wrap_setting('curl_ignore_ssl_verifyresult');
					wrap_setting('curl_ignore_ssl_verifyresult', true);
				}
			}
			if (wrap_setting('curl_ignore_ssl_verifyresult')) {
				// not recommended, mainly for debugging!
				// only set this if you know what you are doing
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			} else {
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			}
			if (isset($old_curl_ignore_ssl_verifyresult))
				wrap_setting('curl_ignore_ssl_verifyresult', $old_curl_ignore_ssl_verifyresult);
			// Certficates are bundled with cURL from 7.10 onwards, PHP 5 requires at least 7.10
			// so there should be currently no need to include an own PEM file
			// curl_setopt($ch, CURLOPT_CAINFO, wrap_setting('cainfo_file'));
		}
		$data = curl_exec($ch);
		if (wrap_setting('debug')) fclose($f);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($timeout_ignore) {
			if (wrap_setting('debug')) {
				$info = curl_getinfo($ch);
				wrap_error('JSON '.json_encode($info));
			}
			// we don't know what happened but can't check it
			$status = 200;
		} else {
			if (!$status) {
				$curl_error = curl_error($ch);
				$syndication_error_code = wrap_setting('syndication_error_code');
				if (str_starts_with($curl_error, 'Could not resolve host:'))
					$syndication_error_code = E_USER_NOTICE;
				wrap_error(sprintf(
					'Syndication from URL %s failed. Reason: %s',
					$url, $curl_error
				), $syndication_error_code);
			}
		}
		if ($status === 200 AND !$data AND !$timeout_ignore) {
			$info = curl_getinfo($ch);
			if ($info['download_content_length'] > $info['size_download']) {
				wrap_error(sprintf(
					'cURL incomplete download, URL %s: total %s, received %s'
					, $url, $info['download_content_length'], $info['size_download']
				));
			} else {
				wrap_error(sprintf('cURL error, URL %s: %s', $url, json_encode($info)));
			}
		}
		curl_close($ch); // @deprecated in php8 (has no effect anymore)
		if (!$timeout_ignore) {
			$lines = explode("\r\n", $data);
			$headers = [];
			$skip_empty = false;
			foreach ($lines as $index => $line) {
				unset($lines[$index]);
				if (str_starts_with($line, 'HTTP/') AND str_ends_with($line, '100 Continue')) {
					$skip_empty = true;
					continue;
				} elseif ($line) {
					$skip_empty = false;
				}
				if (!$line AND !$skip_empty) break;
				if ($line) $headers[] = $line;
			}
			$data = implode("\r\n", $lines);
		} else {
			$status = 200; // not necessarily true, but we don't want to wait
			$headers = [];
			$data = [];
		}
	}
	return [$status, $headers, $data];
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
	// not an array: string is already URL encoded
	if (!is_array($data)) return $data;
	$postdata = [];
	foreach ($data as $key => $value) {
		$postdata[] = urlencode($key).'='.urlencode($value);
	}
	return implode('&', $postdata);
}


/*
 * --------------------------------------------------------------------
 * Locks
 * --------------------------------------------------------------------
 */

/**
 * check if in a realm, a lock is set
 * e. g. to avoid race conditions
 *
 * @param string $realm
 * @param string $type
 *		'sequential': allow just one call after the other ended
 *		'wait': just wait the time in seconds before starting the next call, no
 *			matter if the old call ended 
 * @param int $seconds time in seconds, either to wait or till automatic release
 * @return bool true: locked; false: lock is free, occupied for own process
 */
function wrap_lock($realm, $type = 'sequential', $seconds = 30) {
	$lockfile = wrap_lock_file($realm);
	$hash = wrap_lock_hash();
	$time = time();
	$newly_created = false;
	if (!file_exists($lockfile)) {
		// lockfile should always exist, here for first call, a non-existent
		// lockfile prolongs waiting time
		file_put_contents($lockfile, $hash."\n");
		$newly_created = true;
	}
	$last_touched = filemtime($lockfile);
	$locking_hash = trim(file_get_contents($lockfile));

	switch ($type) {
	case 'sequential':
		// 1. check if own process locked 
		if ($hash === $locking_hash) {
			file_put_contents($lockfile, $hash."\n"); // change last modification
			return false;
		}
		// 2. check if it's unlocked
		if (!$locking_hash) {
			file_put_contents($lockfile, $hash."\n");
			if (trim(file_get_contents($lockfile)) === $hash) return false;
			return true; // another process took over the lockfile
		}
		// 3. it's locked, so check if we overwrite the lock (no)
		if (!$seconds) return true;
		// 4. yes, we overwrite, but not if not enough time has passed
		if ($time - $seconds < $last_touched) return true;
		break;
	case 'wait':
		if ($newly_created) return false;
		if ($time - $seconds - 1 < $last_touched) return true;
		break;
	}
	file_put_contents($lockfile, $hash."\n");
	if (trim(file_get_contents($lockfile)) === $hash) return false;
	return true; // another process took over the lockfile
}

/**
 * unlock a realm
 *
 * @param string $realm
 * @param string $mode
 * @return bool
 */
function wrap_unlock($realm, $mode = 'clear') {
	$lockfile = wrap_lock_file($realm);
	$hash = wrap_lock_hash();
	$locking_hash = trim(file_get_contents($lockfile));
	if ($locking_hash !== $hash) return false;
	switch ($mode) {
	case 'clear':
		file_put_contents($lockfile, '');
		break;
	case 'delete':
		unlink($lockfile);
		break;
	}
	return true;
}

/**
 * wait n seconds until lock is released
 *
 * @param string $realm
 * @param int $sec
 * @return bool
 */
function wrap_lock_wait($realm, $sec) {
	while (wrap_lock($realm, 'wait', $sec)) sleep($sec);
	return true;
}

/**
 * generate a hash for this request
 *
 * @return string
 */
function wrap_lock_hash() {
	static $hash = '';
	if ($hash) return $hash;
	if (!empty($_SERVER['HTTP_X_LOCK_HASH'])) {
		return $_SERVER['HTTP_X_LOCK_HASH'];
	}
	$hash = wrap_random_hash(32);
	return $hash;
}

/**
 * get lock filename
 *
 * @param string $realm
 * @return string filename
 */
function wrap_lock_file($realm) {
	return wrap_setting('tmp_dir').'/'.basename($realm).'.lock';
}

/**
 * watchdog checks if files have changed and if so, move files to another place
 *
 * @param string $source
 * @param string $destination
 * @param array $params
 *		array 'destination' vsprintf fields from $my to filename
 *		bool 'log_destination' logs destination as well (in case the same file
 *			has to be put to multiple destinations)
 * @param bool $delete delete source file?
 * @return bool true: file was moved to destination, false: either
 *		an error occured or source file does not exist or source file is equal
 *		to destination, nothing was transfered
 */
function wrap_watchdog($source, $destination, $params = [], $delete = false) {
	require_once __DIR__.'/file.inc.php';
	$logfile = wrap_setting('log_dir').'/watchdog.log';
	if (!file_exists($logfile)) touch($logfile);

	if (str_starts_with($source, 'http://')
		OR str_starts_with($source, 'https://')) {
		$source = str_replace('.local', '', $source);
		$source = str_replace('://dev.', '://', $source);
		$data = wrap_syndication_get($source, 'file');
		if (empty($data['_']['filename'])) return false;
		$source_file = $data['_']['filename'];
	} elseif (str_starts_with($source, 'brick ')) {
		$source_file = wrap_setting('tmp_dir').'/'.str_replace(' ', '/', $source);
		$filename = basename($source_file);
		if (!strstr($filename, '.')) $source_file .= '.html';
		if (!file_exists($source_file)) {
			wrap_mkdir(dirname($source_file));
		} else {
			// equal?
			if (filemtime($source_file) === time()) return false;
		} 
		$content = brick_format('%%% request '.substr($source, 6).' %%%');
		if (!$content) return false;
		if (!file_exists($source_file)) {
			$stale = true;
		} else {
			$stale = md5_file($source_file) === md5($content['text']) ? false : true;
		}
		if (!$stale) return false;
		file_put_contents($source_file, $content['text']);
	} else {
		$source_file = $source;
	}
	if (!file_exists($source_file)) return false;
	
	$my['sha1'] = sha1_file($source_file);
	$my['timestamp'] = filemtime($source_file);
	// beware, there is a lot of buggy software that changes timestamps after
	// e. g. FTP upload to some date in the past
	if (filectime($source_file) > $my['timestamp'])
		$my['timestamp'] = filectime($source_file);
	if (!empty($params['destination'])) {
		$substitutes = [];
		foreach ($params['destination'] as $var) {
			$substitutes[] = $my[$var];
		}
		$destination = vsprintf($destination, $substitutes);
	}

	// check log
	$watched_files = file($logfile);
	$remove = false;
	$delete_lines = [];
	foreach ($watched_files as $index => $line) {
		if (str_starts_with($line, hex2bin('00000000'))) {
			$delete_lines[] = $index;
			continue;
		}
		$file = explode(' ', trim($line)); // 0 = timestamp, 1 = sha1, 2 = filename
		if (empty($file[2])) {
			$delete_lines[] = $index;
			continue;
		}
		if ($file[2] !== $source) continue;
		if (!empty($params['log_destination'])) {
			if ($file[3] !== $destination) continue;
		}
		if ($file[1] === $my['sha1']) {
			// file was not changed, do nothing
			return false;
		}
		$remove = $index;
		break;
	}
	if ($delete_lines) {
		wrap_file_delete_line($logfile, $delete_lines);
	}
	
	// do something
	if (str_starts_with($destination, 'ftp://')) {
		$url = parse_url($destination);
		$ftp_stream = ftp_connect($url['host'], $url['port'] ?? 21);
		if (!$ftp_stream) {
			wrap_error(sprintf(
				'FTP: Failed to connect to %s (Port: %d)',
				$url['host'], $url['port'] ?? 21
			));
			return false;
		}
		$success = ftp_login($ftp_stream, $url['user'], $url['pass']);
		if (!$success) {
			wrap_error(sprintf(
				'FTP: Failed login to %s (User: %s, Password: %s)',
				$url['host'], $url['user'], $url['pass']
			));
			return false;
		}
		$dir = dirname($url['path']);
		$success = @ftp_chdir($ftp_stream, $dir);
		if (!$success) {
			// check folder hierarchy if all folders exist, top to bottom
			$folders = explode('/', substr($dir, 1));
			$my_dir = '';
			foreach ($folders as $folder) {
				$my_dir .= '/'.$folder;
				$success = @ftp_chdir($ftp_stream, $my_dir);
				if (!$success) ftp_mkdir($ftp_stream, $my_dir);
			}
		}
		if (!$success) {
			wrap_error(sprintf(
				'FTP: Directory was not changed to %s',
				dirname($url['path'])
			));
			return false;
		}
		ftp_pasv($ftp_stream, true);
		$upload = ftp_put($ftp_stream, basename($url['path']), $source_file, FTP_BINARY);
		if (!$upload) {
			wrap_error(sprintf(
				'FTP: Upload local file %s to remote file %s failed',
				$source_file, basename($url['path'])
			));
			return false;
		}
		ftp_close($ftp_stream);
	} else {
		wrap_mkdir(dirname($destination));
		if ($delete) {
			$success = rename($source_file, $destination);
			if (!$success) {
				wrap_error(sprintf(
					'It was not possible to rename file %s to file %s',
					$source_file, $destination
				));
				return false;
			}
		} else {
			$success = copy($source_file, $destination);
			if (!$success) {
				wrap_error(sprintf(
					'It was not possible to copy file %s to file %s',
					$source_file, $destination
				));
				return false;
			}
		}
	}
	
	// after successful moving of file, remove old log and write new one
	// so when moving fails, next call of function tries to move file again
	if ($remove !== false) {
		// file was changed, remove old line, add new line
		unset($watched_files[$remove]);
		if (!$handle = fopen($logfile, 'w+'))
			return false; //wrap_text('Cannot open %s for writing.', ['values' => $file]);
		foreach ($watched_files as $line)
			fwrite($handle, $line);
		fclose($handle);
	}
	if (!empty($params['log_destination'])) {
		error_log(sprintf("%s %s %s %s\n", $my['timestamp'], $my['sha1'], $source, $destination), 3, $logfile);
	} else {
		error_log(sprintf("%s %s %s\n", $my['timestamp'], $my['sha1'], $source), 3, $logfile);
	}

	// move was successful
	return true;
}
