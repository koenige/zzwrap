<?php 

/**
 * zzwrap
 * collect record data
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2020, 2023-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * collect record data for a list of IDs
 * including linked data
 *
 * @param string $table
 * @param array $data
 * @param array $settings (optional)
 * @return array
 */
function wrap_data($table, $data, $settings = []) {
	if (!$data) return $data;

	$files = wrap_include('data/'.$table);
	if (!$files)
		wrap_error(sprintf('No data function found for `%s`.', $table), E_USER_ERROR);

	$data_function = NULL;
	$finalize_function = NULL;
	foreach ($files['functions'] as $function) {
		if (!array_key_exists('short', $function)) {
			wrap_error(sprintf('Function `%s` uses not recommended naming scheme', $function['function']));
			continue;
		}
		if ($function['package'] === $table AND $function['short'] === 'data')
			$data_function = $function['function'];
		elseif ($function['package'] === $table AND $function['short'] === 'data_finalize')
			$finalize_function = $function['function'];
		elseif ($function['short'] === $table.'_data')
			$data_function = $function['function'];
		elseif ($function['short'] === $table.'_data_finalize')
			$finalize_function = $function['function'];
	}
	if (!$data_function)
		wrap_error(sprintf('No data function found for `%s`.', $table), E_USER_ERROR);

	// (optional, if key does not equal primary key)
	$id_field_name = $settings['id_field_name'] ?? NULL;
	// (optional, if not the current language shall be used)
	$lang_field_name = $settings['lang_field_name'] ?? NULL;

	$ids = wrap_data_ids($data, $id_field_name);
	$langs = wrap_data_langs($data, $lang_field_name);
	
	$results = $data_function($ids, $langs, $settings);
	foreach ($results as $key => $result) {
		if ($key === 'deleted') {
			foreach ($result as $deleted_id)
				unset($data[$deleted_id]);
		} else {
			$data = wrap_data_merge($data, $result, $id_field_name, $lang_field_name);
		}
	}
	
	if ($finalize_function)
		$data = $finalize_function($data, $ids);

	return $data;
}

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
 * @return array
 */
function wrap_data_media($data, $ids, $langs, $table) {
	$mediadata = wrap_media(array_unique($ids), $table);
	if (!$mediadata) return $data;
	$id_field = wrap_mysql_primary_key($table);
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

/**
 * get further data from packages
 *
 * @param string $key
 * @param array $data
 * @param array $ids
 * @return array
 */
function wrap_data_packages($key, $data, $ids) {
	$files = wrap_include($key);
	if (!$files) {
		$data['templates'] = [];
		return $data;
	}
	foreach ($files['packages'] as $package)
		wrap_package_activate($package);

	foreach ($files['functions'] as $function) {
		if (empty($function['short'])) continue;
		if ($function['short'] !== $key) continue;
		$data = $function['function']($data, $ids);
	}
	return $data;
}
