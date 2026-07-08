<?php

/**
 * zzwrap
 * Hook functions for zzform
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * update url_placeholders[year] after content changes
 *
 * @param array $ops
 * @return void
 */
function mf_zzwrap_url_placeholder_years($ops) {
	$years = mf_zzwrap_url_placeholder_year_range();
	if (!$years) return;

	$existing = wrap_setting('url_placeholders[year]');
	if ($existing == $years) return;

	wrap_setting_write('url_placeholders[year]', '['.implode(', ', $years).']');
}

/**
 * year range for url_placeholders[year] from module SQL files
 *
 * @return array
 */
function mf_zzwrap_url_placeholder_year_range() {
	if (!wrap_db_connection()) return [];

	$min_year = NULL;
	$max_year = NULL;
	$files = wrap_collect_files('configuration/url-placeholder-years.sql', 'modules');
	foreach ($files as $file) {
		$sql = trim(file_get_contents($file));
		if (!$sql) continue;
		$sql = wrap_sql_placeholders($sql);
		$row = wrap_db_fetch($sql);
		if (!$row OR (empty($row['min_year']) AND empty($row['max_year']))) continue;
		if (!empty($row['min_year'])) {
			$year = intval($row['min_year']);
			$min_year = is_null($min_year) ? $year : min($min_year, $year);
		}
		if (!empty($row['max_year'])) {
			$year = intval($row['max_year']);
			$max_year = is_null($max_year) ? $year : max($max_year, $year);
		}
	}
	if (is_null($min_year) OR is_null($max_year)) return [];

	$offset = wrap_setting('url_placeholder_year_future_offset');

	$max_year = max($max_year, intval(date('Y'))) + $offset;
	return range($min_year, $max_year);
}
