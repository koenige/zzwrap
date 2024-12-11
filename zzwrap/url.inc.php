<?php

/**
 * zzwrap
 * url functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
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
	if (!str_starts_with($_SERVER['REQUEST_URI'], '/')) {
		wrap_quit(400, wrap_text(wrap_text(
			'Invalid Request URI: %s%s',
			['values' => [wrap_setting('host_base'), $_SERVER['REQUEST_URI']]]
		)));
	}
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
	$forwarded_host = wrap_url_dev_remove($_SERVER['HTTP_X_FORWARDED_HOST']);
	$forwarded_host = sprintf('/%s', $forwarded_host);
	if (str_starts_with($zz_page['url']['full']['path'], $forwarded_host)) {
		$zz_page['url']['full']['path_forwarded'] = $forwarded_host;
		wrap_setting('request_uri', substr(wrap_setting('request_uri'), strlen($forwarded_host)));
	}
}

/**
 * return 400 if path or query includes Unicode Replacement Character 
 * U+FFFD (hex EF BF BD, dec 239 191 189)
 * since that does not make sense
 */
function wrap_url_check() {
	global $zz_page;

	if (strstr($zz_page['url']['full']['path'], '%EF%BF%BD')) wrap_quit(400);
	if (empty($zz_page['url']['full']['query'])) return;
	if (strstr($zz_page['url']['full']['query'], '%EF%BF%BD')) wrap_quit(400);
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
 * builds URL from REQUEST
 * better than mod_rewrite, because '&' won't always be treated correctly
 * 
 * @param array $url $url['full'] with result from parse_url
 * @return array $url with new keys ['db'] (URL in database)
 */
function wrap_url_match() {
	global $zz_page;
	
	$zz_page['url']['db'] = trim($zz_page['url']['full']['path'], '/');
	if (!empty($_GET['lang']) AND !is_array($_GET['lang'])) {
		$lang_suffix = '.'.$_GET['lang'];
		if (str_ends_with($zz_page['url']['db'], $lang_suffix))
			$zz_page['url']['db'] = substr($zz_page['url']['db'], 0, -strlen($lang_suffix));
	}
	$zz_page['url']['db'] = wrap_url_ending($zz_page['url']['db']);
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

/**
 * remove ending that indicates it is text content from URL path
 *
 * @param string $path
 * @return string
 */
function wrap_url_ending($path) {
	$possible_endings = ['.html', '.html%3E', '.php', '/', '/%3E', '/%C2%A0', '/&nbsp;'];
	foreach ($possible_endings as $ending) {
		if (!str_ends_with($path, $ending)) continue;
		return substr($path, 0, -strlen($ending));
	}
	return $path;
}

/**
 * Make canonical URLs
 * 
 * @param array $zz_page
 * @param array $page
 * @return array $url
 */
function wrap_url_canonical($zz_page, $page) {
	// canonical hostname?
	$zz_page['url'] = wrap_url_canonical_hostname_check($zz_page['url']);
	
	// if database allows field 'ending', check if the URL is canonical
	// just for HTML output!
	if (!empty($page['content_type']) AND $page['content_type'] !== 'html'
		AND (empty($page['content_type_original']) OR $page['content_type_original'] !== 'html')
		AND !empty($zz_page['db']['identifier'])
		AND substr($zz_page['db']['identifier'], -1) === '*'
		AND strstr(basename($zz_page['db']['parameter']), '.')) {
		if (empty($page['url_ending'])) $page['url_ending'] = 'none';
	}
	if (!empty($zz_page['db'][wrap_sql_fields('page_ending')])) {
		$ending = $zz_page['db'][wrap_sql_fields('page_ending')];
		// if brick_format() returns a page ending, use this
		if (isset($page['url_ending'])) $ending = $page['url_ending'];
		$zz_page['url'] = wrap_url_canonical_ending($ending, $zz_page['url']);
	}

	$types = ['query_strings', 'query_strings_redirect'];
	foreach ($types as $type) {
		// initialize
		if (empty($page[$type])) $page[$type] = [];
		// merge from settings
		if (wrap_setting($type)) {
			$page[$type] = array_merge($page[$type], wrap_setting($type));
		}
	}
	// set some query strings which are used by zzwrap
	$page['query_strings'] = array_merge($page['query_strings'],
		['no-cookie', 'lang', 'code', 'url', 'logout']);
	if ($qs = wrap_setting('query_strings')) {
		$page['query_strings'] = array_merge($page['query_strings'], $qs);
	}
	if (!empty($zz_page['url']['full']['query'])) {
		parse_str($zz_page['url']['full']['query'], $params);
		$wrong_qs = [];
		foreach (array_keys($params) as $param) {
			if (in_array($param, $page['query_strings'])) continue;
			if (wrap_setting('no_query_strings_redirect')) {
				wrap_setting('cache', false); // do not cache these
				continue;
			}
			$param_value = $params[$param];
			unset($params[$param]);
			$zz_page['url']['redirect'] = true;
			$zz_page['url']['redirect_cache'] = false;
			// no error logging for query strings which shall be redirected
			if (in_array($param, $page['query_strings_redirect'])) continue;
			if (is_array($param_value)) $param_value = http_build_query($param_value);
			$qs_key_value = sprintf('%s=%s', $param, $param_value);
			$ignore = false;
			if (wrap_error_ignore('qs', $qs_key_value))
				$ignore = true;
			elseif (wrap_error_ignore('qs', $param))
				$ignore = true;
			if (!$ignore)
				$wrong_qs[] = $qs_key_value;
		}
		if ($wrong_qs)
			wrap_error(sprintf('Wrong URL: query string %s [%s], Referer: %s'
				, implode('&', $wrong_qs)
				, wrap_setting('request_uri')
				, $_SERVER['HTTP_REFERER'] ?? '--'
			), E_USER_NOTICE);

		$zz_page['url']['full']['query'] = http_build_query($params);
	}
	return $zz_page['url'];
}

/**
 * Make canonical URLs, here: endings (trailing slash, .html etc.)
 * 
 * @param string $ending ending of URL (/, .html, .php, none)
 * @param array $url ($zz_page['url'])
 * @return array $url, with new 'path' and 'redirect' set to 1 if necessary
 */
function wrap_url_canonical_ending($ending, $url) {
	// no changes for root path
	if ($url['full']['path'] === '/') return $url;
	if ($ending === 'ignore') return $url;

	// get correct path
	$path = wrap_url_ending($url['full']['path']);
	if (!in_array($ending, ['none', 'keine']))
		$path .= $ending;

	// path is already correct? don’t do anything
	if ($path === $url['full']['path']) return $url;

	$url['full']['path'] = $path;
	$url['redirect'] = true;
	$url['redirect_cache'] = true;
	return $url;
}

/**
 * check if canonical hostname is used
 *
 * @param array $url
 * @return array
 */
function wrap_url_canonical_hostname_check($url) {
	$canonical = wrap_url_canonical_hostname();
	if (!$canonical) return $url;
	if (wrap_setting('hostname') === $canonical) return $url;

	$url['full']['host'] = $canonical;
	$url['redirect'] = true;
	$url['redirect_cache'] = false;
	return $url;
}

/**
 * get canonical hostname, care for development server
 *
 * @return string
 */
function wrap_url_canonical_hostname() {
	if (!wrap_setting('canonical_hostname')) return '';

	$canonical = wrap_setting('canonical_hostname');
	$canonical = wrap_url_dev_add($canonical);
	return $canonical;	
}

/**
 * redirect to canonical URL or URL with language code
 *
 * @param array $url
 * @return bool
 */
function wrap_url_canonical_redirect($url) {
	if (empty($url['redirect'])) return false;
	wrap_redirect(wrap_glue_url($url['full']), 301, $url['redirect_cache']);
}

/**
 * check if a hostname is a development hostname
 * and return position to cut from/to
 *
 * @param string hostname
 * @param bool $check_local (optional, default = check local_access)
 * @return string
 */
function wrap_url_dev($hostname, $check_local = true) {
	if ($check_local AND !wrap_setting('local_access')) return '';
	if (str_ends_with($hostname, '.local')) return '.local';
	if (str_starts_with($hostname, 'dev.')) return 'dev.';
	if (str_starts_with($hostname, 'dev-')) return 'dev-';
	return '';
}

/**
 * add dev parts to hostname
 *
 * @param string $hostname
 * @return string
 */
function wrap_url_dev_add($hostname) {
	$add = wrap_url_dev(wrap_setting('hostname'));
	if (!$add) return $hostname;
	if (str_starts_with($add, '.')) return $hostname.$add;
	return $add.$hostname;
}

/**
 * remove dev parts from hostname
 *
 * @param string $hostname
 * @param bool $check_local (optional, default = check local_access)
 * @return string
 */
function wrap_url_dev_remove($hostname, $check_local = true) {
	$cut = wrap_url_dev($hostname, $check_local);
	if (!$cut) return $hostname;
	if (str_starts_with($cut, '.')) return substr($hostname, 0, -strlen($cut));
	return substr($hostname, strlen($cut));
}
