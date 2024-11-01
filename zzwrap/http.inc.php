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
