<?php

/**
 * zzwrap
 * url functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * read and prepare URL
 */
function wrap_url_prepare() {
	global $zz_page;

	// check REQUEST_URI
	wrap_url_encode();
	wrap_url_slashes();
	wrap_url_read();
	wrap_url_forwarded();
	wrap_url_check();

	$zz_page['url']['full'] = wrap_url_normalize($zz_page['url']['full']);
	
	// get rid of unwanted query strings, set redirect if necessary
	$zz_page['url'] = wrap_url_remove_query_strings($zz_page['url']);
}

/**
 * sometimes, Apache decodes URL parts, e. g. %E2 is changed to a latin-1
 * character, encode that again
 *
 * @param string $path
 * @return string
 */
function wrap_url_encode() {
	$path = wrap_setting('request_uri');
	$new_path = '';
	for ($i = 0; $i < strlen($path); $i++) {
		if (ord(substr($path, $i, 1)) < 128)
			$new_path .= substr($path, $i, 1);
		else
			$new_path .= urlencode(substr($path, $i, 1)); 
	}
	wrap_setting('request_uri', $new_path);
}

/**
 * check if URL has too many slashes
 *
 */
function wrap_url_slashes() {
	if (!wrap_setting('url_path_max_parts')) return;
	if (substr_count(wrap_setting('request_uri'), '/') <= wrap_setting('url_path_max_parts')) return;
	wrap_quit(414, wrap_text(
		'URIs with more than %d slashes are not processed.',
		['values' => wrap_setting('url_path_max_parts')]
	));
}

/**
 * set URL of webpage
 *
 */
function wrap_url_read() {
	global $zz_page;
	// Base URL, allow it to be set manually (handle with care!)
	// e. g. for Content Management Systems without mod_rewrite or websites in subdirectories
	// @deprecated
	if (!empty($zz_page['url']['full'])) return;

	$zz_page['url']['full'] = parse_url(wrap_setting('host_base').wrap_setting('request_uri'));
	if (empty($zz_page['url']['full']['path'])) {
		// in case, some script requests GET ? HTTP/1.1 or so:
		$zz_page['url']['full']['path'] = '/';
		$zz_page['url']['redirect'] = true;
		$zz_page['url']['redirect_cache'] = false;
	} elseif (strstr($zz_page['url']['full']['path'], '//')) {
		// replace duplicate slashes for getting path, some bots add one
		// redirect later if content was found
		$zz_page['url']['full']['path'] = str_replace('//', '/', $zz_page['url']['full']['path']);
		$zz_page['url']['redirect'] = true;
		$zz_page['url']['redirect_cache'] = false;
	}
}

/**
 * replace forwarded host in URL
 *
 */
function wrap_url_forwarded() {
	global $zz_page;

	if (empty($_SERVER['HTTP_X_FORWARDED_HOST'])) return;
	if (!wrap_setting('hostname_in_url')) return;
	$forwarded_host = $_SERVER['HTTP_X_FORWARDED_HOST'];
	if (wrap_setting('local_access') AND str_ends_with($forwarded_host, '.local')) {
		$forwarded_host = substr($forwarded_host, 0, -6);
	} elseif (wrap_setting('local_access') AND str_starts_with($forwarded_host, 'dev.')) {
		$forwarded_host = substr($forwarded_host, 4);
	}
	$forwarded_host = sprintf('/%s', $forwarded_host);
	if (str_starts_with($zz_page['url']['full']['path'], $forwarded_host)) {
		$zz_page['url']['full']['path_forwarded'] = $forwarded_host;
		wrap_setting('request_uri', substr(wrap_setting('request_uri'), strlen($forwarded_host)));
	}
}

/**
 * return 404 if path or query includes Unicode Replacement Character 
 * U+FFFD (hex EF BF BD, dec 239 191 189)
 * since that does not make sense
 */
function wrap_url_check() {
	global $zz_page;

	if (strstr($zz_page['url']['full']['path'], '%EF%BF%BD')) wrap_quit(404);
	if (empty($zz_page['url']['full']['query'])) return;
	if (strstr($zz_page['url']['full']['query'], '%EF%BF%BD')) wrap_quit(404);
}

/**
 * normalizes a URL
 *
 * @param array $url (scheme, host, path, ...)
 * @return array
 */
function wrap_url_normalize($url) {
	// RFC 3986 Section 6.2.2.3. Path Segment Normalization
	// Normally, the browser will already do that
	if (strstr($url['path'], '/../')) {
		// /path/../ = /
		// while, not foreach, takes into account multiple occurences of /../../
		while (strstr($url['path'], '/../')) {
			$path = explode('/', $url['path']);
			$index = array_search('..', $path);
			unset($path[$index]);
			if (array_key_exists($index - 1, $path)) unset($path[$index - 1]);
			$url['path'] = implode('/', $path);
		}
	}
	if (strstr($url['path'], '/./')) {
		// /path/./ = /path/
		$url['path'] = str_replace('/./', '/', $url['path']);
	}

	// RFC 3986 Section 6.2.2.2. Percent-Encoding Normalization
	$url['path'] = wrap_url_normalize_percent_encoding($url['path'], 'path');
	$url['query'] = wrap_url_normalize_percent_encoding($url['query'] ?? '', 'query');
	return $url;
}

/**
 * normalize path of URL
 *
 * @param string $path
 * @param string $type (path, query or all)
 * @return string
 */
function wrap_url_normalize_percent_encoding($string, $type) {
	if (!$string) return '';
	if (!strstr($string, '%')) return $string;
	return preg_replace_callback('/%[2-7][0-9A-F]/i', sprintf('wrap_url_%s_decode', $type), $string);
}

function wrap_url_query_decode($input) {
	return wrap_url_decode($input, 'query');
}

function wrap_url_path_decode($input) {
	return wrap_url_decode($input, 'path');
}

function wrap_url_all_decode($input) {
	return wrap_url_decode($input, 'all');
}

/**
 * Normalizes percent encoded characters in URL path or query string into 
 * equivalent characters if encoding is superfluous
 * @see RFC 3986 Section 6.2.2.2. Percent-Encoding Normalization
 *
 * Characters which will remain percent encoded (range: 0020 - 007F) are
 * 0020    0022 "  0023 #  0025 %  002F /
 * 003C <  003E >  003F ?
 * 005B [  005C \  005D ]  005E ^
 * 0060 `  
 * 007B {  007C |  007D }  007F [DEL]
 *
 * @param $input array
 * @return string
 */
function wrap_url_decode($input, $type = 'path') {
	$codepoint = substr(strtoupper($input[0]), 1);
	if (hexdec($codepoint) < hexdec('20')) return '%'.$codepoint;
	if (hexdec($codepoint) > hexdec('7E')) return '%'.$codepoint;
	$dont_encode = [
		'20', '22', '23', '2F',
		'3C', '3E', '3F',
		'5C', '5E',
		'60',
		'7B', '7C', '7D'
	];
	switch ($type) {
	case 'path':
		$dont_encode[] = '25'; // /
		$dont_encode[] = '5B';
		$dont_encode[] = '5D';
		break;
	case 'query':
		$dont_encode[] = '3D'; // =
		$dont_encode[] = '26'; // &
		break;
	case 'all':
		$dont_encode = [];
		break;
	}
	if (in_array($codepoint, $dont_encode)) {
		return '%'.$codepoint;
	}
	return chr(hexdec($codepoint));
}

/**
 * Get rid of unwanted query strings
 * 
 * since we do not use session-IDs in the URL, get rid of these since sometimes
 * they might be used for session_start()
 * e. g. GET http://example.com/?PHPSESSID=5gh6ncjh00043PQTHTTGY%40DJJGV%5D
 * @param array $url ($zz_page['url'])
 * @param array $objectionable_qs key names of query strings
 * @todo get objectionable querystrings from setting
 */
function wrap_url_remove_query_strings($url, $objectionable_qs = []) {
	if (empty($url['full']['query'])) return $url;

	// ignore_query_string = query string is ignored, without redirect
	$ext = wrap_file_extension($url['full']['path']);
	if ($ext) $filetype_config = wrap_filetypes($ext, 'check-per-extension');
	if (!empty($filetype_config['ignore_query_string'])) {
		$url['full']['query'] = NULL;
		return $url;
	}

	if (empty($objectionable_qs))
		$objectionable_qs = ['PHPSESSID'];
	if (!is_array($objectionable_qs))
		$objectionable_qs = [$objectionable_qs];
	parse_str($url['full']['query'], $query);
	// furthermore, keys with % signs are not allowed (propably errors in
	// some parsing script)
	foreach (array_keys($query) AS $key) {
		if (strstr($key, '%')) $objectionable_qs[] = $key;
	}
	if ($remove = array_intersect(array_keys($query), $objectionable_qs)) {
		foreach ($remove as $key) {
			unset($query[$key]);
			unset($_GET[$key]);
			unset($_REQUEST[$key]);
		}
		$url['full']['query'] = http_build_query($query);
		$url['redirect'] = true;
		$url['redirect_cache'] = false;
	}
	return $url;
}

/**
 * set relative path to root
 *
 * @global array $zz_page
 */
function wrap_url_relative() {
	global $zz_page;
	if (!empty($zz_page['deep'])) return;
	if (!empty($zz_page['url']['full']['path']))
		$zz_page['deep'] = str_repeat('../', (substr_count('/'.$zz_page['url']['full']['path'], '/') -2));
	else
		$zz_page['deep'] = '/';
}

/**
 * if page is not found, after all files are included,
 * check 1. well known URLs, 2. template files, 3. redirects
 *
 * @param array $zz_page
 * @param bool $quit (optional) true: call wrap_quit(), false: just return
 * @return array
 */
function wrap_url_from_ressource($zz_page, $quit = true) {
	$well_known = wrap_url_well_known($zz_page['url']['full']);
	if ($well_known) {
		$zz_page['well_known'] = $well_known;
	} else {
		$zz_page['tpl_file'] = wrap_look_for_file($zz_page['url']['full']['path']);
		if (!$zz_page['tpl_file'] AND $quit) wrap_quit();
		$languagecheck = wrap_url_language();
		if (!$languagecheck AND $quit) wrap_quit();
		if (!empty($_GET)) {
			$cacheable = ['lang'];
			foreach (array_keys($_GET) as $key) {
				if (in_array($key, $cacheable)) continue;
				wrap_setting('cache', false);
				break;
			}
		}
	}
	return $zz_page;
}

/**
 * support some standard URLs if there’s no entry in webpages table for them
 *
 * @param array $url
 * @return mixed false: nothing found, array: $page
 */
function wrap_url_well_known($url) {
	switch ($url['path']) {
	case '/robots.txt':
		$page['content_type'] = 'txt';
		$page['text'] = '# robots.txt for '.wrap_setting('site');
		$page['status'] = 200;
		return $page;
	case '/.well-known/change-password':
		if (!$path = wrap_domain_path('change_password')) return false;
		wrap_redirect_change($path);
	}
	return false;
}

/**
 * check lang parameter from GET
 *
 * @return bool
 */
function wrap_url_language() {
	if (empty($_GET['lang'])) return true;
	
	if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $_GET['lang'])) return false;
	// @todo the following check is not a good solution since languages_2c is only
	// used on systems with languages with three letters
	if (in_array($_GET['lang'], array_keys(wrap_id('languages', '', 'list')))) {
		wrap_setting('lang', $_GET['lang']);
		return true;
	} elseif (in_array($_GET['lang'], array_keys(wrap_id('languages_2c', '', 'list')))) {
		wrap_setting('lang', $_GET['lang']);
		return true;
	}
	return false;
}
