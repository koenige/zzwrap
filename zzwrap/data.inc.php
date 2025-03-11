<?php 

/**
 * zzwrap
 * data functions for request_get-functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2020, 2023-2025 Gustaf Mossakowski
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
	if (!$lang_field_name) return [wrap_setting('lang')];

	foreach ($data as $id => $line)
		$langs[$line[$lang_field_name]] = $line[$lang_field_name];
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
	if (!function_exists('wrap_get_media')) return $data;
	$mediadata = wrap_get_media(array_unique($ids), $table, $id_field);
	if (!$mediadata) return $data;
	$id_field = sprintf('%s_id', $id_field);
	// remove database table if in field name
	if ($pos = strpos($id_field, '.')) $id_field = substr($id_field, $pos + 1);
	foreach ($langs as $lang) {
		$media = wrap_translate($mediadata, 'media', 'medium_id', true, $lang);
		foreach ($data[$lang] as $line_id => $line) {
			if (!array_key_exists($line[$id_field], $media)) continue;
			$data[$lang][$line_id] += $media[$line[$id_field]];
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
	foreach ($data as $id => $line) {
		if ($lang_field_name)
			$lang = $line[$lang_field_name];
		else
			$lang = wrap_setting('lang');
		if ($id_field_name) {
			if (empty($new_data[$lang][$line[$id_field_name]])) continue;
			$data[$id] = array_merge($new_data[$lang][$line[$id_field_name]], $line);
		} else {
			if (empty($new_data[$lang][$id])) continue;
			$data[$id] = array_merge($new_data[$lang][$id], $line);
		}
	}
	return $data;
}

/**
 * cleanup data e. g. for API output
 * (id fields, parameters, internal remarks)
 *
 * @param array $data
 * @return array
 */
function wrap_data_cleanup($data) {
	foreach ($data as $index => &$line) {
		foreach (wrap_setting('data_cleanup_ignore') as $field_name) {
			if (str_starts_with($field_name, '_')) {
				if (str_ends_with($index, $field_name)) unset($data[$index]);
			} else {
				if ($index === $field_name) unset($data[$index]);
				elseif (str_ends_with($index, '_'.$field_name)) unset($data[$index]);
			}
		}
		if (is_array($line)) $line = wrap_data_cleanup($line);
	}
	return $data;
}
