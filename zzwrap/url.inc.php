<?php

/**
 * zzwrap
 * url functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * read or write request URL state (wrap_static bucket 'url')
 *
 * Flat keys: parse_url-style components (scheme, host, port, user, pass, path, query,
 * fragment, path_forwarded) plus redirect and redirect_cache.
 *
 * @param string $key empty string: full flat map
 * @param mixed|null $value NULL: read; non-NULL: write
 * @param string $action set (default) or init (replace bucket from $value)
 * @return array|mixed|null
 */
function wrap_url($key = '', $value = NULL, $action = 'set') {
	$return_value = wrap_static('url', $key, $value, $action);
	if (is_null($return_value) AND in_array($key, ['scheme', 'host', 'port', 'user', 'pass', 'path', 'query']))
		return '';
	return $return_value;
}

/**
 * read and prepare URL
 */
function wrap_url_prepare() {
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

	$url_normal = wrap_url_normalize(wrap_url());
	foreach ($url_normal as $key => $part)
		if ($part !== wrap_url($key)) wrap_url($key, $part);

	// get rid of unwanted query strings, set redirect if necessary
	wrap_url_remove_query_strings();
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
	$url = parse_url(wrap_setting('host_base').wrap_setting('request_uri'));
	if (!is_array($url)) $url = [];
	if (empty($url['path'])) {
		// in case, some script requests GET ? HTTP/1.1 or so:
		$url['path'] = '/';
		$url['redirect'] = true;
		$url['redirect_cache'] = false;
	} elseif (strstr($url['path'], '//')) {
		// replace duplicate slashes for getting path, some bots add one
		// redirect later if content was found
		$url['path'] = str_replace('//', '/', $url['path']);
		$url['redirect'] = true;
		$url['redirect_cache'] = false;
	}
	wrap_url('', $url, 'init');
}

/**
 * replace forwarded host in URL
 *
 */
function wrap_url_forwarded() {
	if (empty($_SERVER['HTTP_X_FORWARDED_HOST'])) return;
	if (!wrap_setting('hostname_in_url')) return;
	$forwarded_host = wrap_url_dev_remove($_SERVER['HTTP_X_FORWARDED_HOST']);
	$forwarded_host = sprintf('/%s', $forwarded_host);
	if (str_starts_with(wrap_url('path'), $forwarded_host)) {
		wrap_url('path_forwarded', $forwarded_host);
		wrap_setting('request_uri', substr(wrap_setting('request_uri'), strlen($forwarded_host)));
	}
}

/**
 * return 400 if path or query includes Unicode Replacement Character 
 * U+FFFD (hex EF BF BD, dec 239 191 189)
 * since that does not make sense
 */
function wrap_url_check() {
	if (strstr(wrap_url('path'), '%EF%BF%BD')) wrap_quit(400);
	if (!wrap_url('query')) return;
	if (strstr(wrap_url('query'), '%EF%BF%BD')) wrap_quit(400);
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
 * @param array|string $objectionable_qs key names of query strings
 * @return bool
 * @todo get objectionable querystrings from setting
 */
function wrap_url_remove_query_strings($objectionable_qs = []) {
	if (!wrap_url('query')) return false;

	// ignore_query_string = query string is ignored, without redirect
	$ext = wrap_file_extension(wrap_url('path'));
	if ($ext) $filetype_config = wrap_filetypes($ext, 'check-per-extension');
	if (!empty($filetype_config['ignore_query_string'])) {
		wrap_url('query', '');
		return true;
	}

	if (empty($objectionable_qs))
		$objectionable_qs = ['PHPSESSID'];
	if (!is_array($objectionable_qs))
		$objectionable_qs = [$objectionable_qs];
	parse_str(wrap_url('query'), $query);
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
		wrap_url('query', http_build_query($query));
		wrap_url('redirect', true);
		wrap_url('redirect_cache', false);
	}
	return true;
}

/**
 * set relative path to root (wrap_setting relative_root)
 */
function wrap_url_relative() {
	if (wrap_setting('relative_root')) return;
	if (wrap_url('path'))
		wrap_setting('relative_root', str_repeat('../', (substr_count('/'.wrap_url('path'), '/') -2)));
	else
		wrap_setting('relative_root', '/');
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
	$possible_endings = [
		'.html/', '.html', '.html%3E', '.php/', '.php', '/', '/%3E', '/%C2%A0', '/&nbsp;'
	];
	foreach ($possible_endings as $ending) {
		if (!str_ends_with($path, $ending)) continue;
		return substr($path, 0, -strlen($ending));
	}
	return $path;
}

/**
 * Make canonical URLs
 * 
 * @param array $page
 * @return bool
 */
function wrap_url_canonical($page) {
	// canonical hostname?
	wrap_url_canonical_hostname_check();
	
	// if database allows field 'ending', check if the URL is canonical
	// just for HTML output!
	if (!empty($page['content_type']) AND $page['content_type'] !== 'html'
		AND (empty($page['content_type_original']) OR $page['content_type_original'] !== 'html')
		AND wrap_page_field('identifier')
		AND substr(wrap_page_field('identifier'), -1) === '*'
		AND strstr(basename(wrap_brick('parameter')), '.')) {
		if (empty($page['url_ending'])) $page['url_ending'] = 'none';
	}
	$ending = wrap_page_field('ending');
	if ($ending) {
		// if brick_format() returns a page ending, use this
		if (isset($page['url_ending'])) $ending = $page['url_ending'];
		wrap_url_canonical_ending($ending);
	}

	// set some query strings which are used by zzwrap
	wrap_page_meta('query_strings', ['no-cookie', 'lang']);
	// allow ?code= only for direct script access (e.g. ErrorDocument 403 → /_scripts/main.php?code=403)
	if (wrap_url('path')
		&& str_ends_with(wrap_url('path'), basename($_SERVER['SCRIPT_NAME']))) {
		wrap_page_meta('query_strings', 'code');
	}
	if (wrap_setting('query_strings'))
		wrap_page_meta('query_strings', wrap_setting('query_strings'));
	if (wrap_setting('query_strings_redirect'))
		wrap_page_meta('query_strings_redirect', wrap_setting('query_strings_redirect'));
	if (wrap_url('query')) {
		parse_str(wrap_url('query'), $params);
		$wrong_qs = [];
		foreach (array_keys($params) as $param) {
			if (in_array($param, wrap_page_meta('query_strings'))) continue;
			if (wrap_setting('no_query_strings_redirect')) {
				wrap_setting('cache', false); // do not cache these
				continue;
			}
			$param_value = $params[$param];
			unset($params[$param]);
			wrap_url('redirect', true);
			wrap_url('redirect_cache', false);
			// no error logging for query strings which shall be redirected
			if (in_array($param, wrap_page_meta('query_strings_redirect'))) continue;
			if (is_array($param_value)) $param_value = http_build_query($param_value);
			$qs_key_value = sprintf('%s=%s', $param, $param_value);
			$ignore = false;
			if (wrap_error_ignore('qs', $qs_key_value))
				$ignore = true;
			elseif (wrap_error_ignore('qs', $param))
				$ignore = true;
			elseif (wrap_error_weird_qs())
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

		wrap_url('query', http_build_query($params));
	}
	return true;
}

/**
 * Make canonical URLs, here: endings (trailing slash, .html etc.)
 * 
 * @param string $ending ending of URL (/, .html, .php, none)
 * @return bool
 */
function wrap_url_canonical_ending($ending) {
	// no changes for root path
	if (wrap_url('path') === '/') return false;
	if ($ending === 'ignore') return false;

	// get correct path
	$path = wrap_url_ending(wrap_url('path'));
	if (!in_array($ending, ['none', 'keine']))
		$path .= $ending;

	// path is already correct? don’t do anything
	if ($path === wrap_url('path')) return false;

	wrap_url('path', $path);
	wrap_url('redirect', true);
	wrap_url('redirect_cache', true);
	return true;
}

/**
 * check if canonical hostname is used
 *
 * @return bool
 */
function wrap_url_canonical_hostname_check() {
	$canonical = wrap_url_canonical_hostname();
	if (!$canonical) return false;
	if (wrap_setting('hostname') === $canonical) return false;
	// no redirect if in forwarded_hostnames
	if (in_array(wrap_url_dev_remove(wrap_setting('hostname')), wrap_setting('forwarded_hostnames')))
		return false;

	wrap_url('host', $canonical);
	wrap_url('redirect', true);
	wrap_url('redirect_cache', false);
	return true;
}

/**
 * get canonical hostname, care for development server
 *
 * @return string|null
 */
function wrap_url_canonical_hostname() {
	if (!wrap_setting('canonical_hostname')) return NULL;

	$canonical = wrap_setting('canonical_hostname');
	$canonical = wrap_url_dev_add($canonical);
	return $canonical;	
}

/**
 * redirect to canonical URL or URL with language code
 *
 * @return bool
 */
function wrap_url_canonical_redirect() {
	if (!wrap_url('redirect')) return false;
	wrap_redirect(wrap_glue_url(wrap_url()), 301, wrap_url('redirect_cache'));
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
