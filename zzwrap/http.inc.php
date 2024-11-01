<?php

/**
 * zzwrap
 * http functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * restrict access to website per IP
 *
 * @return void
 */
function wrap_http_restrict_ip() {
	if (!$access_restricted_ips = wrap_setting('access_restricted_ips')) return;
	if (!in_array(wrap_setting('remote_ip'), $access_restricted_ips)) return;
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
	if (!empty($_SERVER['REMOTE_ADDR']) AND $_SERVER['REMOTE_ADDR'] === wrap_setting('cron_ip')) return true;
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
