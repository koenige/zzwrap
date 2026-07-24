<?php 

/**
 * zzwrap
 * Reference data from configuration/*.tsv
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Translated reference table from configuration/{$name}.tsv
 *
 * TSV header Variables: `reference = 1` opts in; `translate`, `translate_context`,
 * optional `reference_short` / `reference_long` column names (default abbr/label),
 * optional `extra_columns` (comma-separated TSV column names exposed as extra
 * styles, e.g. `extra_columns = unicode` for `wrap_reference($name, 'unicode')`).
 *
 * @param string $name TSV basename under configuration/ (must declare reference = 1)
 * @param string $style long, short, all, or an extra_columns name
 * @param string|null $lang language code, or null for setting lang
 * @return array|null keyed lookup, or null if $name/style is invalid or TSV missing
 */
function wrap_reference($name, $style = 'long', $lang = null) {
	$variables = wrap_reference_tsv_variables($name);
	if (($variables['reference'] ?? '') !== '1') {
		wrap_error([
			'Unknown reference `%s` (configuration/%s.tsv has no `reference = 1`).',
			['values' => [$name, $name]]
		]);
		return null;
	}
	if (!in_array($style, wrap_reference_styles($variables), true)) {
		wrap_error(['Unknown reference style `%s` for `%s`.', ['values' => [$style, $name]]]);
		return null;
	}

	$all = wrap_reference_all($name, $lang, $variables);
	if (!$all) return null;
	if ($style === 'all') return $all;

	$result = [];
	foreach ($all as $key => $row)
		$result[$key] = $row[$style];
	return $result;
}

/**
 * Output style names for a reference TSV Variables block
 *
 * @param array<string, string|string[]> $variables
 * @return string[]
 */
function wrap_reference_styles($variables) {
	return array_merge(
		['short', 'long', 'all'],
		wrap_array_list($variables['extra_columns'] ?? null)
	);
}

/**
 * Long month names (1–12) from configuration/months.tsv
 *
 * @param string|null $lang
 * @return array|null
 */
function wrap_months($lang = null) {
	return wrap_reference('months', 'long', $lang);
}

/**
 * Short month names (1–12) from configuration/months.tsv
 *
 * @param string|null $lang
 * @return array|null
 */
function wrap_months_short($lang = null) {
	return wrap_reference('months', 'short', $lang);
}

/**
 * Long weekday names (1–7, ISO Mon–Sun) from configuration/weekdays.tsv
 *
 * @param string|null $lang
 * @return array|null
 */
function wrap_weekdays($lang = null) {
	return wrap_reference('weekdays', 'long', $lang);
}

/**
 * Short weekday names (1–7) from configuration/weekdays.tsv
 *
 * @param string|null $lang
 * @return array|null
 */
function wrap_weekdays_short($lang = null) {
	return wrap_reference('weekdays', 'short', $lang);
}

/**
 * Long compass bearing names keyed by degrees from configuration/bearings.tsv
 *
 * @param string|null $lang
 * @return array|null
 */
function wrap_bearings($lang = null) {
	return wrap_reference('bearings', 'long', $lang);
}

/**
 * Short compass bearing abbreviations keyed by degrees from configuration/bearings.tsv
 *
 * @param string|null $lang
 * @return array|null
 */
function wrap_bearings_short($lang = null) {
	return wrap_reference('bearings', 'short', $lang);
}

/**
 * Full translated reference rows (short and long per key), cached per name and lang
 *
 * @param string $name
 * @param string|null $lang
 * @param array<string, string|string[]> $variables parsed Variables block
 * @return array|null
 */
function wrap_reference_all($name, $lang, $variables) {
	static $cache = [];

	if (!$lang) $lang = wrap_setting('lang');
	$cache_key = $name."\0".$lang;
	if (array_key_exists($cache_key, $cache)) return $cache[$cache_key];

	$rows = wrap_tsv_parse($name);
	if (!$rows) {
		wrap_error(['Reference `%s` is empty.', ['values' => [$name]]]);
		$cache[$cache_key] = null;
		return null;
	}

	$col['short'] = $variables['reference_short'] ?? 'abbr';
	$col['long'] = $variables['reference_long'] ?? 'label';
	$translate_cols = wrap_array_list($variables['translate'] ?? null);
	$context_columns = wrap_tsv_translate_context($variables['translate_context'] ?? '');
	$extra_columns = wrap_array_list($variables['extra_columns'] ?? null);
	$tsv_columns = wrap_reference_tsv_head($rows);
	foreach ($extra_columns as $column) {
		if (in_array($column, $tsv_columns, true)) continue;
		wrap_error([
			'Reference `%s`: unknown extra_columns `%s` (TSV columns: %s).',
			['values' => [$name, $column, implode(', ', $tsv_columns)]]
		]);
		$cache[$cache_key] = null;
		return null;
	}
	foreach ($extra_columns as $column)
		$col[$column] = $column;

	$all = [];
	foreach ($rows as $key => $row) {
		if (!is_array($row)) continue;
		foreach (array_keys($col) as $column_key)
			$all[$key][$column_key] = wrap_reference_cell(
				$row, $col[$column_key], $translate_cols, $context_columns, $lang
			);
	}
	$cache[$cache_key] = $all;
	return $all;
}

/**
 * Column names from a parsed reference TSV (#: header)
 *
 * @param array $rows wrap_tsv_parse() result
 * @return string[]
 */
function wrap_reference_tsv_head($rows) {
	foreach ($rows as $row) {
		if (!is_array($row)) continue;
		return array_values(array_diff(array_keys($row), ['_package']));
	}
	return [];
}

/**
 * Variables block from configuration/{$name}.tsv
 *
 * @param string $name
 * @return array<string, string|string[]>
 */
function wrap_reference_tsv_variables($name) {
	$tsv_files = wrap_collect_files('configuration/'.$name.'.tsv');
	if (!$tsv_files) return [];

	$content = file_get_contents(end($tsv_files));
	if ($content === false OR $content === '') return [];

	wrap_include('file', 'zzwrap');
	return wrap_file_header_variables(str_replace(["\r\n", "\r"], "\n", $content));
}

/**
 * Translate one reference cell when listed in the TSV translate variable
 *
 * @param array $row
 * @param string $column
 * @param string[] $translate_cols
 * @param array<string, string> $context_columns
 * @param string $lang
 * @return string
 */
function wrap_reference_cell($row, $column, $translate_cols, $context_columns, $lang) {
	$value = trim($row[$column] ?? '');
	if ($value === '') return '';
	if (!in_array($column, $translate_cols, true)) return $value;

	$params = ['lang' => $lang];
	if (!empty($context_columns[$column])) {
		$context_spec = $context_columns[$column];
		if (isset($row[$context_spec]))
			$params['context'] = $row[$context_spec];
		else
			$params['context'] = $context_spec;
	}
	return wrap_text($value, $params);
}
