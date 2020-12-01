<?php 

/**
 * zzwrap
 * data functions for request_get-functions
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2020 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * get list of IDs from data
 * either data is indexed by ID or there is a separate ID field name
 *
 * @param array $data
 * @param string $id_field_name
 * @return array
 */
function wrap_data_ids($data, $id_field_name = '') {
	if (!$id_field_name) return array_keys($data);

	foreach ($data as $id => $line) {
		$ids[$id] = $line[$id_field_name];
	}
	return $ids;
}

/**
 * get list of language codes from data
 * either use standard language code from settings
 * or use a separate language field name
 *
 * @param array $data
 * @param string $lang_field_name
 * @return array
 */
function wrap_data_langs($data, $lang_field_name = '') {
	global $zz_setting;
	if (!$lang_field_name) return [$zz_setting['lang']];

	foreach ($data as $id => $line) {
		$langs[$line[$lang_field_name]] = $line[$lang_field_name];
	}
	return $langs;
}	

/**
 * get media for data
 *
 * @param array $data
 * @param array $ids
 * @param array $langs
 * @param string $table
 * @param string $id_field
 * @return array
 */
function wrap_data_media($data, $ids, $langs, $table, $id_field) {
	$mediadata = wrap_get_media(array_unique($ids), $table, $id_field);
	foreach ($langs as $lang) {
		$media[$lang] = wrap_translate($mediadata, 'media', 'medium_id', true, $lang);
	}
	foreach ($media as $lang => $media_per_lang) {
		foreach ($media_per_lang as $line_id => $line_media) {
			foreach ($langs as $lang) {
				$data[$lang][$line_id] += $line_media;
			}
		}
	}
	return $data;
}

/**
 * merge language specific data to existing $data array
 *
 * @param array $data
 * @param array $new_data
 * @param string $id_field_name
 * @param string $lang_field_name
 * @return array
 */
function wrap_data_merge($data, $new_data, $id_field_name = '', $lang_field_name = '') {
	global $zz_setting;

	foreach ($data as $id => $line) {
		if ($lang_field_name)
			$lang = $line[$lang_field_name];
		else
			$lang = $zz_setting['lang'];
		if ($id_field_name)
			$data[$id] = array_merge($new_data[$lang][$line[$id_field_name]], $line);
		else
			$data[$id] = array_merge($new_data[$lang][$id], $line);
	}
	return $data;
}
