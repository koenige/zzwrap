<?php

/**
 * zzwrap
 * validation of query strings, rules from configuration/query-strings.cfg
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * get validated value of a query string
 *
 * Identifier of rule = section in query-strings.cfg: package, optional scope and
 * key of query string, e. g. `zzform.edit`, `media.medium.v`
 *
 * @param string $identifier
 * @return mixed validated value; if query string is missing or invalid:
 *		`default` from rule, NULL if there is no default
 */
function wrap_url_qs($identifier) {
	$def = wrap_url_qs_definition($identifier);
	if (!$def) return NULL;

	$key = wrap_url_qs_key($identifier);
	wrap_page_meta('query_strings', $key);
	if (!array_key_exists($key, $_GET))
		return wrap_url_qs_default($identifier, $def);

	$value = wrap_url_qs_validate($_GET[$key], $def);
	if ($value !== NULL) return $value;

	wrap_url_qs_error($key, $def, $identifier);
	return wrap_url_qs_default($identifier, $def);
}

/**
 * get definition for query string from query-strings.cfg
 *
 * @param string $identifier
 * @return array
 */
function wrap_url_qs_definition($identifier) {
	$cfg = wrap_cfg_files('query-strings');
	if (!array_key_exists($identifier, $cfg)) {
		wrap_error([
			'Query string rule `%s` is not defined.',
			['values' => [$identifier]]
		], E_USER_WARNING);
		return [];
	}
	$package = strtok($identifier, '.');
	if ($cfg[$identifier]['package'] !== $package) {
		wrap_error([
			'Query string rule `%s` has to be defined in package `%s`.',
			['values' => [$identifier, $package]]
		], E_USER_WARNING);
		return [];
	}
	return $cfg[$identifier];
}

/**
 * get key of query string from ID of rule (last part)
 *
 * @param string $identifier
 * @return string
 */
function wrap_url_qs_key($identifier) {
	$parts = explode('.', $identifier);
	return end($parts);
}

/**
 * get default value of rule, cast to type of rule
 *
 * @param string $identifier
 * @param array $def
 * @return mixed NULL if there is no default
 */
function wrap_url_qs_default($identifier, $def) {
	if (!isset($def['default'])) return NULL;
	// validation is not really necessary here, but getting the type of the variable right
	$value = wrap_url_qs_validate($def['default'], $def);
	if ($value !== NULL) return $value;
	wrap_error([
		'Query string rule `%s`: default value is invalid: %s',
		['values' => [$identifier, $def['default']]]
	], E_USER_WARNING);
	return NULL;
}

/**
 * check value of query string against rule
 *
 * @param mixed $value
 * @param array $def
 * @return mixed validated value, NULL if invalid
 */
function wrap_url_qs_validate($value, $def) {
	if (is_array($value)) return NULL;
	if (!empty($def['pattern']) AND !preg_match($def['pattern'], $value)) return NULL;

	switch ($def['type'] ?? 'text') {
	case 'int':
		if (intval($value).'' !== $value) return NULL;
		$value = intval($value);
		if (isset($def['min']) AND $value < $def['min']) return NULL;
		if (isset($def['max']) AND $value > $def['max']) return NULL;
		return $value;
	case 'number':
		if (!is_numeric($value)) return NULL;
		$value = $value + 0;
		if (isset($def['min']) AND $value < $def['min']) return NULL;
		if (isset($def['max']) AND $value > $def['max']) return NULL;
		return $value;
	case 'bool':
		if (in_array($value, ['', '1'], true)) return true;
		if ($value === '0') return false;
		return NULL;
	case 'language':
		if (!wrap_url_qs_valid_language($value)) return NULL;
		return $value;
	}
	return $value;
}

/**
 * check if value is a language that can be used on this website
 *
 * @param string $value
 * @return bool
 */
function wrap_url_qs_valid_language($value) {
	if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $value)) return false;
	if (wrap_setting('languages_allowed'))
		return in_array($value, wrap_setting('languages_allowed'), true);
	if (!wrap_db_connection()) return true;
	// @todo the following check is not a good solution since languages_2c is only
	// used on systems with languages with three letters
	if (array_key_exists($value, wrap_id('languages', '', 'list'))) return true;
	if (array_key_exists($value, wrap_id('languages_2c', '', 'list'))) return true;
	return false;
}

/**
 * remove invalid query string, react as defined in `on_error`
 *
 * on_error: 404, redirect-nocache, nocache or function of a package,
 * e. g. `zzform.404` calls zz_query_string_404()
 *
 * @param string $key
 * @param array $def
 * @param string $identifier
 */
function wrap_url_qs_error($key, $def, $identifier) {
	unset($_GET[$key]);
	unset($_REQUEST[$key]);
	if (empty($def['on_error'])) return;

	switch ($def['on_error']) {
	case '404':
		wrap_quit(404);
		exit;
	case 'nocache':
		wrap_setting('cache', false);
		return;
	case 'redirect-nocache':
		wrap_url('redirect_cache', false);
		// go on whith redirect
	case 'redirect':
		parse_str(wrap_url('query'), $params);
		unset($params[$key]);
		wrap_url('query', http_build_query($params));
		wrap_url('redirect', true);
		return;
	}

	$function = wrap_url_qs_error_function($def['on_error']);
	if (!$function) {
		wrap_error([
			'Query string rule `%s`: unknown value for `on_error`: %s',
			['values' => [$identifier, $def['on_error']]]
		], E_USER_WARNING);
		return;
	}
	$function($key, $identifier);
}

/**
 * get function name for `on_error` value of a package, e. g. `zzform.404`
 *
 * @param string $on_error
 * @return string empty if function does not exist
 */
function wrap_url_qs_error_function($on_error) {
	if (!strstr($on_error, '.')) return '';
	list($package, $action) = explode('.', $on_error, 2);
	$function = sprintf('%squery_string_%s', wrap_function_prefix($package), str_replace('-', '_', $action));
	if (!function_exists($function)) return '';
	return $function;
}

/**
 * query strings that are allowed on every page (`always = 1`)
 *
 * @return array ID of rule => key of query string
 */
function wrap_url_qs_always() {
	$keys = [];
	foreach (wrap_cfg_files('query-strings') as $identifier => $def) {
		if (empty($def['always'])) continue;
		$keys[$identifier] = wrap_url_qs_key($identifier);
	}
	return $keys;
}

/**
 * validate query strings that are allowed on every page, before page is built
 */
function wrap_url_qs_always_check() {
	if (!wrap_url('query')) return;
	foreach (array_keys(wrap_url_qs_always()) as $identifier)
		wrap_url_qs($identifier);
}
