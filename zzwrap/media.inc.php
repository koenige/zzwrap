<?php 

/**
 * zzwrap
 * Media functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * get media for one or several records of a table
 *
 * @param mixed $ids one ID or list of IDs of the records
 * @param string $table table name of records
 * @param array $settings (optional)
 *		array 'where'
 * @return array
 */
function wrap_media($ids, $table, $settings = []) {
	$id_field = $settings['id_field'] ?? '';
	if (!$id_field) {
		$id_field = wrap_mysql_primary_key($table);
		$id_field = substr($id_field, 0, -3);
	}
	if (!$id_field) return [];

	if (function_exists('wrap_get_media')) {
		return wrap_get_media($ids, $table, $id_field, $settings);
	}
	if (wrap_package('media')) {
		wrap_include('media', 'media');
		return mf_media_get($ids, $table, $id_field, $settings);
	}

	return [];
}
