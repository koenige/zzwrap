<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2009
// Language functions


// get filename for translated texts
$language = (!empty($zz_setting['lang']) ? $zz_setting['lang'] : $zz_conf['language']);

// standard text english
if (file_exists($zz_setting['custom_wrap_dir'].'/text-en.inc.php')) 
	include $zz_setting['custom_wrap_dir'].'/text-en.inc.php';

// default translated text
if (file_exists($zz_setting['core'].'/default-text-'.$language.'.inc.php'))
	include $zz_setting['core'].'/default-text-'.$language.'.inc.php';

// standard translated text
if (file_exists($zz_setting['custom_wrap_dir'].'/text-'.$language.'.inc.php'))
	include $zz_setting['custom_wrap_dir'].'/text-'.$language.'.inc.php';

global $text;

/**
 * Translate text from textfile if possible or write back text string to be translated
 * 
 * @param string $string	Text string to be translated
 * @global array $text		Translations for current language
 * @global array $zz_conf	Configuration variables, here:
 *			'log_missing_text', 'language' must both be set to log missing text
 * @return string $string	Translation of text
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @see zz_text()
 */
function wrap_text($string) {
	global $text;
	global $zz_conf;
	if (empty($text[$string])) {
		// write missing translation to somewhere.
		// TODO: check logfile for duplicates
		// TODO: optional log directly in database
		if (!empty($zz_conf['log_missing_text'])) {
			$log_message = '$text["'.addslashes($string).'"] = "'.$string.'";'."\n";
			$log_file = sprintf($zz_conf['log_missing_text'], $zz_conf['language']);
			error_log($log_message, 3, $log_file);
			chmod($log_file, 0664);
		}
		return $string;
	} else
		return $text[$string];
}

/**
 * Translate text from database
 * 
 * @param array $data	Array of data, indexed by ID 
 * 			array(34 => array('field1' = 34, 'field2' = 'text') ...);
 *			if it's just a single record not indexed by ID, the first field_name
 *			is assumed to carry the ID!
 * @param mixed $matrix (string) name of database.table, translates all fields
 * 			that allow translation, write back to $data[$id][$field_name]
 *			(array) 'wrap_table' => name of database.table
 * @param string $foreign_key_field_name (optional) if it's not the main record but
 *			a detail record indexed by $foreign_key_field_name
 * @param bool $mark_incomplete	(optional) write back if fields are not translated?
 * @param string $lang different (optional) target language than set in 
 *			$zz_setting['translation_lang']
 * @global array $zz_conf
 * 		- $zz_conf['translations_of_fields']
 * @global array $zz_setting
 * @global array $zz_sql
 * 		- $zz_sql['translations'] in sql-core.inc.php
 * @return array $data input array with translations where possible, extra array
 *		ID => wrap_source_language => field_name => en [iso_lang]
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_translate($data, $matrix, $foreign_key_field_name = '',
	$mark_incomplete = true, $target_language = false) {
	global $zz_conf;
	global $zz_setting;
	global $zz_sql;
	if (empty($zz_conf['translations_of_fields'])) return $data;

	// get page language: $zz_setting['lang']
	if (!$target_language) $target_language = $zz_setting['lang'];

	// check which of the fields of the table might have translations
	$sql_tt = 'SELECT translationfield_id, %s AS field_key, field_type
		FROM '.$zz_conf['translations_table'].'
		WHERE db_name = "%s" AND table_name = "%s"';
	$sql_ttf = 'SELECT translationfield_id, %s AS field_key, field_type
		FROM '.$zz_conf['translations_table'].'
		WHERE db_name = "%s" AND table_name = "%s" AND field_name = "%s"';

	// check the matrix and fill in the blanks
	// cross check against database
	if (!is_array($matrix)) {
		// used without other field definitions, one can write done the
		// sole db_name.table_name as well without .*
		if (substr_count($matrix, '.') < 2) {
			$matrix = array(0 => $matrix.(substr($matrix, -2) == '.*' ? '' : '.*'));
		} else {
			$matrix = array(0 => $matrix);
		}
	}
	$database = '';
	$table = '';
	if (!empty($matrix['wrap_table'])) {
		list($database, $table) = wrap_translate_get_table_db($matrix['wrap_table']);
		unset($matrix['wrap_table']);
	}
	$old_matrix = $matrix;
	$matrix = array();
	foreach ($old_matrix as $key => $field) {
		// database name is optional, so add it here for all cases
		if (substr_count($field, '.') == 1) $field = $zz_conf['db_name'].'.'.$field;
		if (is_numeric($key)) {
		// numeric key: CMS.seiten.titel, CMS.seiten.*
			if (substr($field, -2) == '.*') {
				// wildcard: all fields that are possible will be translated
				if (!$database) {
					list($database, $table) = wrap_translate_get_table_db(substr($field, 0, -2));
				}
				$sql = sprintf($sql_tt, 'field_name', $database, $table);
				$matrix += wrap_db_fetch($sql, array('field_type', 'translationfield_id'));
			} else {
				// we have a selection of fields to be translated
				$names = explode('.', $field);
				$sql = sprintf($sql_ttf, 'field_name', $names[0], $names[1], $names[2]);
				$matrix += wrap_db_fetch($sql, array('field_type', 'translationfield_id'));
			}
		} else {
		// alpha key: title => CMS.seiten.titel or seiten.titel
			$names = explode('.', $field);
			$sql = sprintf($sql_ttf, '"'.$key.'"', $names[0], $names[1], $names[2]);
			$matrix += wrap_db_fetch($sql, array('field_type', 'translationfield_id'));
		}
	}

	// check if $data is an array indexed by IDs
	$simple_data = false;
	foreach ($data as $id => $record) {
		if (!is_numeric($id)) {
			$simple_data = $data[$id];	// save ID for later
			$old_data = $data;			// save old data in array
			unset($data);				// remove all keys
			$data[$old_data[$id]] = $old_data;
			break;
		}
	}

	$all_fields_to_translate = 0;
	$translated_fields = 0;
	// record IDs that are going to be translated
	if (!$foreign_key_field_name) {
		// main table: take main IDs
		$data_ids = array_keys($data);
	} else {
		// joined table: get IDs from foreign_key and save main table ID for later
		$data_ids = array();
		foreach ($data as $id => $record) {
			if (!empty($record[$foreign_key_field_name])) {
				$data_ids[$id] = $record[$foreign_key_field_name];
			} else {
				// there is no detail record
				continue;
			}
		}
		// there are no detail records at all?
		if (empty($data_ids)) $matrix = array(); // get out of here
	}
	
	$old_empty_fields = array();
	foreach ($matrix as $field_type => $fields) {
		// check if some of the existing fields are empty, to get the correct
		// number of fields to translate (empty = nothing to translate!)
		foreach ($fields as $field) {
			foreach ($data as $id => $rec) {
				if (empty($rec[$field['field_key']])) 
					$old_empty_fields[$field['field_key'].'['.$id.']'] = true;
			}
		}

		$all_fields_to_translate += count($fields)*count($data_ids);

		// get translations corresponding to matrix from database
		$sql = sprintf($zz_sql['translations'], $field_type, implode(',', array_keys($fields)), 
			implode(',', $data_ids), $target_language);
		$translations = wrap_db_fetch($sql, 'translation_id');

		// merge $translations into $data
		foreach ($translations as $tl) {
			$field_name = $fields[$tl['translationfield_id']]['field_key'];
			$tl_ids = array();
			if (!$foreign_key_field_name) {
				// one translation = one field
				$tl_ids[] = $tl['field_id'];
			} else {
				// it's not the ID of the joined table we need but the main table
				// e. g. $data_ids = Array([1103] => 40, [1113] => 24, [1115] => 24)
				// $tl['field_id'] = 24, returns Array(1113, 1115)
				// $tl['field_id'] = 40, returns Array(1103)
				$tl_ids = array_keys($data_ids, $tl['field_id']);
			}
			foreach ($tl_ids as $tl_id) {
				if (isset($data[$tl_id][$field_name])) {
					// only save fields that already existed beforehands
					$data[$tl_id][$field_name] = $tl['translation'];
					$translated_fields++;
					if (!empty($tl['source_language'])) {
						// language information if inside query, otherwise existing information
						// in $data will be left as is
						$data[$tl_id]['wrap_source_language'][$field_name] = $tl['source_language'];
					}
				} else {
					// ok, we do not care about this field, so don't count on it
					$all_fields_to_translate--;
				}
				// check if there is a translation for an empty field, for
				// whatever reason. this must be unset to get the correct
				// count for fields
				if (isset($old_empty_fields[$field_name.'['.$tl_id.']']))
					unset($old_empty_fields[$field_name.'['.$tl_id.']']);
			}
		}
	}

	// if fields where = '' beforehands and = '' afterwards, they count as
	// translated
	$translated_fields += count($old_empty_fields);

	// check if something is untranslated!
	if ($translated_fields < $all_fields_to_translate AND $mark_incomplete)
		$zz_setting['translation_incomplete'] = true;
	// reset if array was simple
	if ($simple_data) {
		$data = $data[$simple_data];
	}
	return ($data);
	
	// output: TODO, mark text in different languages than page language
	// as span lang="de" or div lang="de" etc.
}

function wrap_translate_get_table_db($table_db_name) {
	global $zz_conf;

	if (strstr($table_db_name, '.')) {
		$table_db_name = explode('.', $table_db_name);
		$database = $table_db_name[0];
		$table = $table_db_name[1];
	} else {
		$database = $zz_conf['db_name'];
		$table = $table_db_name;
	}
	return array($database, $table);
}

?>