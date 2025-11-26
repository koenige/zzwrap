<?php

/**
 * zzwrap
 * http functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * restrict access to website per IP
 *
 * Supports CIDR notation subnets (e.g., "192.168.1.0/24", "2001:db8::/32")
 * and wildcard patterns for IPv4 (e.g., "192.168.*.*")
 *
 * @return void
 */
function wrap_http_restrict_ip() {
	$restricted_ips = wrap_setting('access_restricted_ips');
	if (!$restricted_ips) return;
	if (!wrap_http_ip_in_list(wrap_setting('remote_ip'), $restricted_ips)) return;
	if (str_starts_with(wrap_setting('request_uri'), wrap_setting('layout_path'))) return;
	if (str_starts_with(wrap_setting('request_uri'), wrap_setting('behaviour_path'))) return;
	wrap_quit(403, wrap_text('Access to this website for your IP address is restricted.'));
}

/**
 * checks the HTTP request made, builds URL
 * sets language according to URL and request
 *
 * @global array $zz_page
 */
function wrap_http_check_request() {
	// check Accept Header
	if (wrap_http_send_as_json()) {
		wrap_setting('cache_extension', 'json');
		wrap_setting('send_as_json', true);
	}

	// check REQUEST_METHOD, quit if inappropriate
	wrap_http_request_method();

	// check if REMOTE_ADDR is valid IP
	if (!filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP))
		wrap_quit(400, sprintf('Request with a malformed IP address: %s', wrap_html_escape($_SERVER['REMOTE_ADDR'])));
}

/**
 * determine whether to send content in JSON format
 *
 * @return bool
 */
function wrap_http_send_as_json() {
	if (!empty($_SERVER['HTTP_ACCEPT']) AND $_SERVER['HTTP_ACCEPT'] === 'application/json') return true;
	if (!empty($_SERVER['REMOTE_ADDR']) AND in_array($_SERVER['REMOTE_ADDR'], wrap_setting('cron_ips'))) return true;
	return false;
}

/**
 * Test HTTP REQUEST method
 * 
 * @return void
 */
function wrap_http_request_method() {
	if (in_array($_SERVER['REQUEST_METHOD'], wrap_setting('http[allowed]'))) {
		if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'OPTIONS') return true;
		if (wrap_is_dav_url()) return true;
		// @todo allow checking request methods depending on ressources
		// e. g. GET only ressources may forbid POST
		header('Allow: '.implode(',', wrap_setting('http[allowed]')));
		header('Content-Length: 0');
		exit;
	}
	if (in_array($_SERVER['REQUEST_METHOD'], wrap_setting('http[not_allowed]'))) {
		wrap_quit(405);	// 405 Not Allowed
	}
	wrap_quit(501); // 501 Not Implemented
}

/**
 * check if server (or proxy server) uses https
 *
 * @return bool
 */
function wrap_https() {
	if (array_key_exists('HTTP_X_FORWARDED_PROTO', $_SERVER))
		if ($_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
	if (array_key_exists('HTTPS', $_SERVER))
		if ($_SERVER['HTTPS'] === 'on') return true;
	return false;
}

/**
 * Checks if HTTP request should be HTTPS request instead and vice versa
 * 
 * Function will redirect request to the same URL except for the scheme part
 * Attention: POST variables will get lost
 * @param array $zz_page Array with full URL in $zz_page['url']['full'], 
 *		this is the result of parse_url()
 * @return redirect header
 */
function wrap_https_check($zz_page) {
	// if it doesn't matter, get out of here
	if (wrap_setting('ignore_scheme')) return true;
	foreach (wrap_setting('ignore_scheme_paths') as $path) {
		if (str_starts_with($_SERVER['REQUEST_URI'], $path)) return true;
	}

	// change from http to https or vice versa
	// attention: $_POST will not be preserved
	if (wrap_https()) {
		if (wrap_setting('protocol') === 'https') return true;
		// if user is logged in, do not redirect
		if (!empty($_SESSION)) return true;
	} else {
		if (wrap_setting('protocol') === 'http') return true;
	}
	$url = $zz_page['url']['full'];
	$url['scheme'] = wrap_setting('protocol');
	wrap_redirect(wrap_glue_url($url), 302, false); // no cache
	exit;
}

/**
 * redirects to https URL, only if explicitly called
 *
 * @global array $zz_page
 * @return bool
 * @deprecated all websites should use https
 */
function wrap_https_redirect() {
	global $zz_page;

	// access must be possible via both http and https
	// check to avoid infinite redirection
	if (!wrap_setting('ignore_scheme')) return false;
	// connection is already via https?
	if (wrap_setting('https')) return false;
	// local connection?
	if (wrap_setting('local_access') AND !wrap_setting('local_https')) return false;

	$url = $zz_page['url']['full'];
	$url['scheme'] = 'https';
	wrap_redirect(wrap_glue_url($url), 302, false); // no cache
}

/**
 * sends a HTTP status header corresponding to server settings and HTTP version
 *
 * @param int $code
 * @return bool true if header was sent, false if not
 */
function wrap_http_status_header($code) {
	// Set protocol
	$protocol = $_SERVER['SERVER_PROTOCOL'];
	if (!$protocol) $protocol = 'HTTP/1.0'; // default value
	if (str_starts_with(php_sapi_name(), 'cgi')) $protocol = 'Status:';
	
	if ($protocol === 'HTTP/1.0' AND in_array($code, [302, 303, 307])) {
		header($protocol.' 302 Moved Temporarily');
		return true;
	}
	$status = wrap_http_status_list($code);
	if ($status) {
		$header = $protocol.' '.$status['code'].' '.$status['text'];
		header($header);
		wrap_setting_add('headers', $header);
		return true;
	}
	return false;
}

/**
 * reads HTTP status codes from http-statuscodes.tsv
 *
 * @return array $codes
 */
function wrap_http_status_list($code) {
	static $data = [];
	if (!$data) $data = wrap_tsv_parse('http-statuscodes');
	if (!array_key_exists($code, $data)) return [];
	return $data[$code];
}

/**
 * get remote IP address even if behind proxy
 *
 * @return string
 */
function wrap_http_remote_ip() {
	if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
		$remote_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		if ($pos = strpos($remote_ip, ','))
			$remote_ip = substr($remote_ip, 0, $pos);

		// do not forward connections that say they're localhost
		wrap_http_forward_localhost($remote_ip);
		// ignore invalid IPs
		wrap_http_forward_valid($remote_ip);
		if (in_array($remote_ip, wrap_setting('proxy_ips')))
			return $remote_ip;
		wrap_setting('http_forward_ip_unknown', $remote_ip);
	}
	if (empty($_SERVER['REMOTE_ADDR']))
		return '';
	return $_SERVER['REMOTE_ADDR'];
}

/**
 * check if forwarded IP equals localhost
 *
 * @param string $remote_ip
 * @return bool
 */
function wrap_http_forward_localhost($remote_ip) {
	// if the access comes from the server itself, a localhost forward is legal
	if (wrap_http_localhost_ip()) return false;

	$local = false;
	if ($remote_ip === '::1') $local = true;
	if (substr($remote_ip, 0, 4) === '127.') $local = true;
	if (!$local) return false;

	wrap_setting('log_username_default', $_SERVER['REMOTE_ADDR']);
	wrap_quit(403, wrap_text(
		'HTTP Header spoofing detected. The client is attempting to impersonate localhost.'
	));
}

/**
 * check if forwarded IP is valid
 *
 * @param string $remote_ip
 * @return bool
 */
function wrap_http_forward_valid($remote_ip) {
	if (filter_var($remote_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE + FILTER_FLAG_NO_RES_RANGE)) return true;

	wrap_setting('log_username_default', $_SERVER['REMOTE_ADDR']);
	wrap_quit(400, wrap_text(
		'HTTP Header spoofing detected. The client uses an invalid forwarding IP address: %s',
		['values' => [$_SERVER['HTTP_X_FORWARDED_FOR']]]
	));
}

/**
 * is access from localhost?
 *
 * @return bool
 */
function wrap_http_localhost_ip() {
	if (empty($_SERVER['REMOTE_ADDR'])) return false;
	if ($_SERVER['REMOTE_ADDR'] === '127.0.0.1') return true;
	if ($_SERVER['REMOTE_ADDR'] === '::1') return true;
	if (empty($_SERVER['SERVER_ADDR'])) return false;
	if ($_SERVER['REMOTE_ADDR'] === $_SERVER['SERVER_ADDR']) return true;
	return false;
}

/**
 * anonymize IP address
 *
 * @param string $ip
 * @param int $anonymize_level
 * @return string
 */
function wrap_http_anonymize_ip($ip, $anonymize_level) {
	if (!filter_var($ip, FILTER_VALIDATE_IP)) {
		wrap_error(sprintf('Unknown IP Address: %s', $ip));
		return $ip;
	}
	if (!$anonymize_level) return $ip;
	if (!in_array($anonymize_level, [1, 2, 3, 4, 5, 6, 7, 8]))
		$anonymize_level = 1;

	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		if ($anonymize_level > 4) $anonymize_level = 4;
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
	for ($i = 0; $i < $anonymize_level; $i++)
		array_pop($parts);
	for ($i = 0; $i < $anonymize_level; $i++)
		$parts[] = 0;
	// @todo shorten IPv6 address
	return implode($concat, $parts);
}

/**
 * Check if a given IP address (IPv4 or IPv6) is in an array of IP addresses or subnets
 *
 * Supports:
 * - Exact IP matches (e.g., "192.168.1.1", "2001:db8::1")
 * - CIDR notation subnets (e.g., "192.168.1.0/24", "2001:db8::/32")
 * - Wildcard patterns for IPv4 (e.g., "192.168.*.*", "192.168.1.*")
 *
 * @param string $ip IP address to check (IPv4 or IPv6)
 * @param array $ip_list Array of IP addresses or subnets to check against
 * @return bool True if IP matches any entry in the list, false otherwise
 */
function wrap_http_ip_in_list($ip, $ip_list) {
	if (!$ip || !is_array($ip_list) || empty($ip_list)) {
		return false;
	}

	// Validate the IP address
	$ip_version = false;
	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		$ip_version = 4;
	} elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
		$ip_version = 6;
	} else {
		return false; // Invalid IP address
	}

	foreach ($ip_list as $entry) {
		if (!is_string($entry)) continue;

		$entry = trim($entry);
		if (!$entry) continue;

		// Check for CIDR notation (e.g., 192.168.1.0/24 or 2001:db8::/32)
		if (strpos($entry, '/') !== false) {
			list($subnet, $prefix) = explode('/', $entry, 2);
			$prefix = intval($prefix);

			// Check if IP is in subnet (IP and subnet must be same version)
			if ($ip_version === 4 && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
				if (wrap_http_ipv4_in_subnet($ip, $subnet, $prefix)) return true;
			} elseif ($ip_version === 6 && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
				if (wrap_http_ipv6_in_subnet($ip, $subnet, $prefix)) return true;
			}
		} elseif (strpos($entry, '*') !== false && $ip_version === 4) {
			// IPv4 wildcard pattern (e.g., 192.168.*.*)
			$pattern = str_replace(['.', '*'], ['\.', '\d+'], $entry);
			if (preg_match('/^' . $pattern . '$/', $ip)) {
				return true;
			}
		} else {
			// Exact IP match
			if ($ip === $entry) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Check if an IPv4 address is within a CIDR subnet
 *
 * @param string $ip IPv4 address to check
 * @param string $subnet IPv4 subnet address
 * @param int $prefix CIDR prefix length (0-32)
 * @return bool True if IP is in subnet, false otherwise
 */
function wrap_http_ipv4_in_subnet($ip, $subnet, $prefix) {
	if ($prefix < 0 || $prefix > 32) return false;
	$ip_long = ip2long($ip);
	$subnet_long = ip2long($subnet);
	if ($ip_long === false || $subnet_long === false) return false;
	$mask = -1 << (32 - $prefix);
	return ($ip_long & $mask) === ($subnet_long & $mask);
}

/**
 * Check if an IPv6 address is within a CIDR subnet
 *
 * @param string $ip IPv6 address to check
 * @param string $subnet IPv6 subnet address
 * @param int $prefix CIDR prefix length (0-128)
 * @return bool True if IP is in subnet, false otherwise
 */
function wrap_http_ipv6_in_subnet($ip, $subnet, $prefix) {
	if ($prefix < 0 || $prefix > 128) return false;
	$ip_bin = inet_pton($ip);
	$subnet_bin = inet_pton($subnet);
	if ($ip_bin === false || $subnet_bin === false) return false;

	// Calculate mask bytes
	$bytes = intval($prefix / 8);
	$bits = $prefix % 8;
	$mask = str_repeat("\xff", $bytes);
	if ($bits > 0) {
		$mask .= chr(0xff << (8 - $bits));
	}
	$mask = str_pad($mask, 16, "\x00");

	// Apply mask and compare byte by byte
	for ($i = 0; $i < 16; $i++) {
		$ip_byte = ord($ip_bin[$i]) & ord($mask[$i]);
		$subnet_byte = ord($subnet_bin[$i]) & ord($mask[$i]);
		if ($ip_byte !== $subnet_byte) {
			return false;
		}
	}
	return true;
}

