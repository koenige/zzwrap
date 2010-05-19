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

// duplicate function zz_text here

/**
 * Translate text from textfile if possible or write back text string to be translated
 * 
 * @param string $string		Text string to be translated
 * @return string $string		Translation of text
 * @author Gustaf Mossakowski <gustaf@koenige.org>
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
 * Globally required variables:
 * - $zz_sql['translations'] in sql-core.inc.php
 * - $zz_conf['translations_of_fields']
 *
 * @param array $data	Array of data, indexed by ID 
 * 				array(34 => array('field1' = 34, 'field2' = 'text') ...);
 * @param mixed $matrix 	(string) name of database.table, translates all fields
 * 					that allow translation, write back to $data[$id][$field_name]
 *					(array) 'wrap_table' => name of database.table
 * @param bool $mark_incomplete	 write back if fields are not translated?
 * @param string $lang different target language than set in $zz_setting['translation_lang']
 * @return array $data input array with translations where possible, extra array
 *		ID => wrap_source_language => field_name => en [iso_lang]
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_translate($data, $matrix, $mark_incomplete = true, $target_language = false) {
	global $zz_conf;
	global $zz_setting;
	global $zz_sql;
	if (empty($zz_conf['translations_of_fields'])) return false;

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

	$all_fields_to_translate = 0;
	$translated_fields = 0;
	foreach ($matrix as $field_type => $fields) {
		$all_fields_to_translate += count($fields)*count($data);
		// get translations corresponding to matrix from database
		$sql = sprintf($zz_sql['translations'], $field_type, implode(',', array_keys($fields)), 
			implode(',', array_keys($data)), $target_language);
		$translations = wrap_db_fetch($sql, 'translation_id');
		// merge $translations into $data
		foreach ($translations as $tl) {
			$field_key = $fields[$tl['translationfield_id']]['field_key'];
			if (!empty($data[$tl['field_id']][$field_key])) {
				// only save fields that already existed beforehands
				$data[$tl['field_id']][$field_key] = $tl['translation'];
				$translated_fields++;
				if (!empty($tl['source_language'])) {
					// language information if inside query, otherwise existing information
					// in $data will be left as is
					$data[$tl['field_id']]['wrap_source_language'][$field_key] = $tl['source_language'];
				}
			} else {
				// ok, we do not care about this field, so don't count on it
				$all_fields_to_translate--;
			}
		}
	}

	// check if something is untranslated!
	if ($translated_fields < $all_fields_to_translate AND $mark_incomplete)
		$zz_setting['translation_incomplete'] = true;
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