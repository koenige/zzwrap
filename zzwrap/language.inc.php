<?php 

/**
 * zzwrap
 * Language and internationalization functions
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2011, 2014 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Sets language for HTML document; checks language information from
 * URL and HTTP header
 *
 * @global array $zz_setting
 *		'lang', 'lanugage_in_url', 'language_redirect' will be set
 *		'base' might be changed
 *		'languages_allowed', 'negotiate_language', default_source_language'
 * @global array $zz_page
 *		'url', 'redirect'
 * @global array $zz_conf
 *		'language', 'translations_of_fields'
 * @return bool true: ok.
 */
function wrap_set_language() {
	global $zz_setting;
	global $zz_conf;
	global $zz_page;

	$zz_setting['language_in_url'] = false;
	$zz_setting['language_redirect'] = false;

	// page language, html lang attribute
	if (!isset($zz_setting['lang'])) {
		if (!empty($zz_conf['language']))
			$zz_setting['lang']		= $zz_conf['language'];
		else
			$zz_setting['lang']		= false;
	}

	// check for language code in URL
	if ($zz_conf['translations_of_fields']) {
		// might change $zz_setting['lang'], 'base', 'language_in_url'
		$zz_page['url'] = wrap_prepare_url($zz_page['url']);
		$zz_conf['language'] = $zz_setting['lang'];
	}

	// single language website?
	if (empty($zz_setting['languages_allowed'])) return true;

	// Content Negotiation for language?
	if (empty($zz_setting['negotiate_language'])) return true;
	// language is already in URL?
	if ($zz_setting['language_in_url']) return true;

	// Check if redirect is necessary
	if (empty($zz_setting['default_source_language']))
		$zz_setting['default_source_language'] = $zz_setting['lang'];
	$language = wrap_negotiate_language($zz_setting['languages_allowed'], 
		$zz_setting['default_source_language'], null, false);
	if (!$language) return false;
	// in case there is content, redirect to the language specific content later
	$zz_setting['base'] .= '/'.$language;
	$zz_setting['lang'] = $language;
	$zz_page['url']['redirect'] = true;
	// vary header for caching
	header('Vary: Accept-Language');
	header('Cache-Control: private');

	return true;
}

/**
 * Reads the language from the URL and returns without it
 * Liest die Sprache aus der URL aus und gibt die URL ohne Sprache zurück 
 * 
 * @param array $url ($zz_page['url'])
 * @global array $zz_setting
 *		'lang' (will be changed), 'base' (will be changed), 'languages_allowed'
 * @global array $zz_conf
 *		'db_connection'
 * @return array $url
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_prepare_url($url) {
	global $zz_setting;
	global $zz_conf;

	// looking for /en/ or similar
	if (empty($url['full']['path'])) return $url;
	// if /en/ is not there, /en still may be, so check full URL
	if (!$pos = strpos(substr($url['full']['path'], 1), '/')) {
		$pos = strlen($url['full']['path']);
	}
	$lang = substr($url['full']['path'], 1, $pos);
	// check if it's a language
	if ($sql = wrap_sql('language') AND $zz_conf['db_connection']) {
		// read from sql query
		$sql = sprintf($sql, wrap_db_escape($lang));
		$lang = wrap_db_fetch($sql, '', 'single value');
	} elseif (!empty($zz_setting['languages_allowed'])) {
		// read from array
		if (!in_array($lang, $zz_setting['languages_allowed'])) 
			$lang = false;
	} else {
		// impossible to check, so there's no language
		$lang = false;
	}
	
	// if no language can be extracted from URL, return URL without changes
	if (!$lang) return $url;
		
	// save language in settings
	$zz_setting['lang'] = $lang;
	// add language to base URL
	$zz_setting['base'] .= '/'.$lang;
	// modify internal URL
	$zz_setting['language_in_url'] = true;
	$url['full']['path'] = substr($url['full']['path'], $pos+1);
	if (!$url['full']['path']) {
		$url['full']['path'] = '/';
		$url['redirect'] = true;
	}
	return $url;
}

/**
 * Gets text from a database table
 * 
 * @param string $language (ISO 639-1 two letter code)
 * @global array $zz_conf
 * @return array $text
 */
function wrap_language_get_text($language) {
	global $zz_conf;
	$sql = 'SELECT text_id, text, more_text
		FROM '.$zz_conf['text_table'];
	$sourcetext = wrap_db_fetch($sql, 'text_id');
	if (!$sourcetext) return array();
	$translations = wrap_translate($sourcetext, $zz_conf['text_table'], false, true, $language);

	$text = array();
	foreach ($sourcetext as $id => $values) {
		if (!empty($translations[$id]['text']))
			$text[$values['text']] = $translations[$id]['text']
				.($translations[$id]['more_text'] ? ' '.$translations[$id]['more_text'] : '');
		else
			$text[$values['text']] = $values['text']
				.($values['more_text'] ? ' '.$values['more_text'] : '');
	}
	return $text;
}

/**
 * Translate text from textfile if possible or write back text string to be translated
 * 
 * @param string $string	Text string to be translated
 * @global array $zz_conf	Configuration variables, here:
 *			'log_missing_text', 'language' must both be set to log missing text
 * @return string $string	Translation of text
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @see zz_text()
 */
function wrap_text($string) {
	global $zz_conf;
	global $zz_setting;
	static $text;
	static $module_text;
	if (empty($text)) $text = array();
	if (empty($module_text)) $module_text = array();
	
	if (!$string) return $string;

	// get filename for translated texts
	$language = !empty($zz_setting['lang']) ? $zz_setting['lang'] : $zz_conf['language'];

	if (empty($zz_setting['text_included'])
		OR $zz_setting['text_included'] != $language) {

		// standard text english
		$files[] = $zz_setting['custom_wrap_dir'].'/text-en.inc.php';
		$files[] = $zz_setting['custom_wrap_dir'].'/text-en.po';
		// default translated text
		$files[] = $zz_setting['core'].'/default-text-'.$language.'.po';
		// module text(s)
		foreach ($zz_setting['modules'] as $module) {
			$modules_dir = $zz_setting['modules_dir'].'/'.$module.'/zzwrap';
			$files[] = $modules_dir.'/'.$module.'-'.$language.'.po';
		}
		// standard translated text 
		$files[] = 	$zz_setting['custom_wrap_dir'].'/text-'.$language.'.inc.php';
		$files[] = 	$zz_setting['custom_wrap_dir'].'/text-'.$language.'.po';

		foreach ($files as $file) {
			if (substr($file, -3) === '.po') {
				$po_text = wrap_po_parse($file);
				// @todo plurals
				// @todo consider scopes!
				if (!empty($po_text['_global'])) {
					$text = array_merge($text, $po_text['_global']);
				}
				foreach (array_keys($po_text) as $area) {
					if (substr($area, 0, 1) === '_') continue;
					$module_text[$area] = $po_text[$area];
				}
			} else {
				$text = array_merge($text, wrap_text_include($file));
			}
		}
		
		// set text as 'included' before database operation so if
		// database crashes just while reading values, it won't do it over and
		// over again		
		$zz_setting['text_included'] = $language;

		// get translations from database
		if (!empty($zz_conf['text_table'])) {
			$text = array_merge($text, wrap_language_get_text($language));
		}
	}

	// if string came from preg_replace_callback, it might be an array
	if (is_array($string) AND !empty($string[1])) $string = $string[1];

	$my_text = $text;
	// active module?
	if (!empty($zz_setting['active_module']) AND !empty($module_text[$zz_setting['active_module']])) {
		$my_text = array_merge($module_text[$zz_setting['active_module']], $text);
	}
	
	if (empty($my_text[$string])) {
		// write missing translation to somewhere.
		// @todo check logfile for duplicates
		// @todo optional log directly in database
		// @todo log missing text in a .pot file
		if (!empty($zz_conf['log_missing_text'])) {
			$log_message = '$text["'.addslashes($string).'"] = "'.$string.'";'."\n";
			$log_file = sprintf($zz_conf['log_missing_text'], $zz_conf['language']);
			error_log($log_message, 3, $log_file);
			chmod($log_file, 0664);
		}
		return $string;
	} else
		return $my_text[$string];
}

/**
 * include a text file
 *
 * @param string $file filename with path
 * @return array $text
 */
function wrap_text_include($file) {
	if (!file_exists($file)) return array();
	include $file;
	if (!isset($text)) return array();
	if (!is_array($text)) return array();
	return $text;
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
 * @return array $data input array with translations where possible, extra array
 *		ID => wrap_source_language => field_name => en [iso_lang]
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function wrap_translate($data, $matrix, $foreign_key_field_name = '',
	$mark_incomplete = true, $target_language = false) {
	global $zz_conf;
	global $zz_setting;
	if (empty($zz_conf['translations_of_fields'])) return $data;
	$translation_sql = wrap_sql('translations');
	if ($translation_sql === NULL) {
		wrap_error('Please set `$zz_sql["translations"]`!', E_USER_ERROR);
		return $data;
	} elseif (!$translation_sql) {
		return $data;
	}

	// get page language: $zz_setting['lang']
	if (!$target_language) {
		if (!empty($zz_setting['lang'])) { 
			$target_language = $zz_setting['lang'];
		} else {
			// we do not have a language to translate to, return data untranslated
			return $data;
		}
	}

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
		// replace existing prefixes
		$matrix = wrap_db_prefix($matrix);
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
		$field = wrap_db_prefix($field);
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
			$fields = wrap_db_fetch($sql, array('field_type', 'translationfield_id'));
			$matrix = array_merge_recursive($matrix, $fields);
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
		$sql = sprintf($translation_sql, $field_type, implode(',', array_keys($fields)), 
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
	
	// output: @todo, mark text in different languages than page language
	// as span lang="de" or div lang="de" etc.
}

function wrap_translate_get_table_db($table_db_name) {
	global $zz_conf;
	
	$table_db_name = wrap_db_prefix($table_db_name);
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

/** 
 * translate page (that was not possible in wrap_look_for_page() because we
 * did not have complete language information then.
 *
 * @global array $zz_conf
 * @global array $zz_page (array 'db' will be changed)
 * @return bool true: translation was run, false: not run
 */
function wrap_translate_page() {
	global $zz_conf;
	global $zz_page;
	if (!$zz_conf['translations_of_fields']) return false;
	$my_page = wrap_translate(array(
		$zz_page['db'][wrap_sql('page_id')] => $zz_page['db']),
		wrap_sql('translation_matrix_pages')
	);
	$zz_page['db'] = array_shift($my_page);
	return true;
}

/**
 * gets the user defined language from the browser
 *
 * HTT Protocol:
 * Accept-Language = "Accept-Language" ":"
 *		1#( language-range [ ";" "q" "=" qvalue ] )
 * language-range  = ( ( 1*8ALPHA *( "-" 1*8ALPHA ) ) | "*" )
 * 
 * Example: Accept-Language: da, en-gb;q=0.8, en;q=0.7
 * Reference: <http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.4>
 *
 * @param array $allowed_languages allowed language codes, lowercase
 * @param string $default_language default language if no language match
 * @param string $accept (optional, if not set, use HTTP_ACCEPT_LANGUAGE)
 * @param bool $strict_mode (optional) follow HTTP specification or not
 * @return array
 */
function wrap_negotiate_language($allowed_languages, $default_language, $accept = null, $strict_mode = true) {
	// check accepted languages
	if ($accept === null AND isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
		$accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
	}
	// if no language information was sent, HTTP 1.1 says, every language is fine
	// for HTTP 1.0 you could send an 406 instead, but user may be confused by these
	if (empty($accept)) return $default_language;
	// all tags are case-insensitive
	$accept = mb_strtolower($accept);
	$languages = preg_split('/,\s*/', $accept);
	
	$current_lang = $default_language;
	$current_q = 0;

	foreach ($languages as $language) {
		$res = preg_match('/^([a-z]{1,8}(?:-[a-z]{1,8})*)'.
			'(?:\s*;\s*q=(0(?:\.[0-9]{1,3})?|1(?:\.0{1,3})?))?$/i', $language, $matches);
		// ignore unparseable syntax
		if (!$res) continue;
		
		// separate language code in primary-tag and subtags
		$lang_code = explode('-', $matches[1]);

		// check quality
		if (isset($matches[2])) {
			$lang_quality = (float)$matches[2];
		} else {
			// no explicit quality given: defaults to 1
			$lang_quality = 1.0;
		}

		while(count($lang_code)) {
			if (in_array(join('-', $lang_code), $allowed_languages)) {
				// ok, we allow this language, now check if user prefers it
				if ($lang_quality > $current_q) {
					// yes!
					$current_lang = join('-', $lang_code);
					$current_q = $lang_quality;
					break;
				}
			}
			// HTTP strict says, don't try to get e. g. 'de' from 'de-at'
			if ($strict_mode) break;
			// try it ;-)
			array_pop($lang_code);
		}
	}
	return $current_lang;
}

/**
 * sets values for numbers, decimal_point and thousands_separator
 * as used in number_format()
 * this is not exhaustive, it's just a means to reduce configuration effort.
 *
 * @global array $zz_conf
 * @global array $zz_setting
 */
function wrap_set_units() {
	global $zz_conf;
	global $zz_setting;
	
	// Attention: PHP < 5.4 just supports decimal_point and thousands_separator
	// which are one byte long
	if (!isset($zz_conf['decimal_point'])) {
		switch ($zz_setting['lang']) {
		case 'de':
		case 'fr':
		case 'es':
		case 'pl':
		case 'cs':
			$zz_conf['decimal_point'] = ',';
			break;
		default:
			$zz_conf['decimal_point'] = '.';
			break;
		}
	}
	if (!isset($zz_conf['thousands_separator'])) {
		switch ($zz_setting['lang']) {
		case 'de':
		case 'fr':
		case 'es':
		case 'pl':
		case 'cs':
			$zz_conf['thousands_separator'] = ' ';
			break;
		default:
			$zz_conf['thousands_separator'] = ',';
			break;
		}
	}
}

/**
 * Parse a gettext po file as a source for translations
 *
 * @param string $file
 * @return array
 */
function wrap_po_parse($file) {
	global $zz_conf;

	if (!file_exists($file)) return array();
	$chunks = wrap_po_chunks($file);
	
	foreach ($chunks as $index => $chunk) {
		if (empty($chunk['msgid'])) {
			// chunks without msgid will be ignored
			unset ($chunks[$index]);
			if ($index) continue;
			if (empty($chunk['msgstr'])) continue;
			$header = wrap_po_headers($chunk['msgstr']);
			continue;
		}
		$scope = '_global';
		$plurals = false;
		$format = false;
		foreach (array_keys($chunk) as $key) {
			$chunk[$key] = implode('', $chunk[$key]);
			$chunk[$key] = str_replace('\"', '"', $chunk[$key]);
			if (in_array($key, array('#:'))) continue;
			if ($zz_conf['character_set'] !== $header['X-Character-Encoding']) {
				$translated = @iconv($header['X-Character-Encoding'], $zz_conf['character_set'], $chunk[$key]);
				if (!$translated) {
					// characters which are not defined in the desired character set
					// replace with htmlentities
					$translated = htmlentities($chunk[$key], ENT_NOQUOTES, $header['X-Character-Encoding'], false);
				}
				// don't translate mssgids!
				if ($key === 'msgid') {
					$dest_key = 'msgid_converted';
				} else {
					$dest_key = $key;
				}
				$chunk[$dest_key] = $translated;
			}
			switch ($key) {
			case 'msgctxt': $scope = $chunk[$key]; break;
			case 'msgid_plural': $plurals = true; break;
			case '#,':
				if (!strstr($chunk[$key], 'php-format')) break;
				$format = true; break;
			}
		}
		if (!$plurals) {
			if (!$chunk['msgstr']) {
				// if there is no translation, set text to converted msgid
				if (isset($chunk['msgid_converted'])) {
					$text[$scope][$chunk['msgid']] = $chunk['msgid_converted'];
				} else {
					$text[$scope][$chunk['msgid']] = $chunk['msgid'];
				}
			} else {
				$text[$scope][$chunk['msgid']] = $chunk['msgstr'];
			}
		} else {
			// @todo msgid_converted
			$text[$scope][$chunk['msgid']] = $chunk['msgstr[0]'];
			$i = 1;
			while (isset($chunk['msgstr['.$i.']'])) {
				$text[$scope][$chunk['msgid_plural']][$i] = $chunk['msgstr['.$i.']'];
				$i++;
			}
			$text['_plural'][$scope][$chunk['msgid_plural']] = true;
		}
		if ($format) {
			$text['_format'][$scope][$chunk['msgid']] = true;
			if ($plurals) $text['_format'][$scope][$chunk['msgid_plural']] = true;
		}
	}
	if (!empty($header['Plural-Forms'])) {
		$text['_plural_forms'] = $header['Plural-Forms'];
	}
	$text['_po_header'] = $header;
	return $text;
}

/**
 * get contents of a text file and split it into chunks separated by blank lines
 *
 * @param string $file
 * @return array
 */
function wrap_po_chunks($file) {
	$lines = file($file);
	$index = 0;
	$chunks = array();
	foreach ($lines as $line) {
		if ($line === "\n" OR $line === "\r\n") {
			$index++;
			continue;
		}
		$chunks[$index][] = $line;
	}
	$last_key = '';
	foreach ($chunks as $index => $chunk) {
		$is_header = false;
		foreach ($chunk as $line) {
			$line = trim($line);
			if (substr($line, 0, 1) === '"' AND substr($line, -1) === '"') {
				$my_chunks[$index][$last_key][] = trim($line, '"');
				continue;
			}
			
			$tokens = preg_split('~\s+~', $line);
			$key = trim(array_shift($tokens));
			$last_key = $key;
			switch ($key) {
				case '#': continue 2;
			}
			$value = trim(substr($line, strlen($key)));
			$value = trim($value, '"');
			if ($key !== 'msgstr' AND !$value) continue;
			$my_chunks[$index][$key][] = $value;
		}
	}
	return $my_chunks;
}

/**
 * format headers of PO file
 *
 * @param array e. g. [0] => Content-Type: text/plain; charset=UTF-8\n
 * @return array e. g. [Content-Type] = text/plain; charset=UTF-8
 */
function wrap_po_headers($headers) {
	$my_headers = array();
	$my_headers['X-Character-Encoding'] = '';
	foreach ($headers as $header) {
		if (substr($header, -2) == '\n') $header = substr($header, 0, -2);
		$tokens = explode(': ', $header);
		$key = array_shift($tokens);
		$my_headers[$key] = implode(' ', $tokens);
		if ($key === 'Content-Type') {
			if (substr($my_headers[$key], 0, 20) !== 'text/plain; charset=') continue;
			$my_headers['X-Character-Encoding'] = strtolower(substr($my_headers[$key], 20));
		}
	}
	return $my_headers;
}
