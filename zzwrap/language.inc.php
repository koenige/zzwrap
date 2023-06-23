<?php 

/**
 * zzwrap
 * Language and internationalization functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2011, 2014-2018, 2020-2023 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Sets language for HTML document; checks language information from
 * URL and HTTP header
 *
 * settings 'lang' will be set
 * setting 'base' might be changed, 'negotiate_language', default_source_language'
 * @global array $zz_page
 *		'url', 'redirect'
 * @return bool true: ok.
 */
function wrap_set_language() {
	global $zz_page;

	// check for language code in URL
	if (wrap_setting('translate_fields'))
		$zz_page['url'] = wrap_prepare_url($zz_page['url']);

	// single language website?
	if (!wrap_setting('languages_allowed')) return true;

	// Content Negotiation for language?
	if (!wrap_setting('negotiate_language')) return true;
	foreach (wrap_setting('dont_negotiate_language_paths') as $path) {
		if (str_starts_with($_SERVER['REQUEST_URI'], $path)) return true;
	}
	// language is already in URL?
	if (wrap_setting('language_in_url')) return true;

	// Check if redirect is necessary
	if (!wrap_setting('default_source_language'))
		wrap_setting('default_source_language', wrap_setting('lang'));
	$language = wrap_negotiate_language(wrap_setting('languages_allowed'), 
		wrap_setting('default_source_language'), null, false);
	if (!$language) return false;
	wrap_setting('lang', $language);
	// in case there is content, redirect to the language specific content later
	$zz_page['language_redirect'] = true;
	return true;
}

/**
 * redirect URL to language specific URL
 *
 * @global array $zz_page
 * @return void
 */
function wrap_language_redirect() {
	global $zz_page;
	if (empty($zz_page['language_redirect'])) return;
	if (!wrap_setting('negotiate_language')) return;

	wrap_setting('base', wrap_setting('base').'/'.wrap_setting('lang'));
	$zz_page['url']['redirect'] = true;
	$zz_page['url']['redirect_cache'] = false;
	// vary header for caching
	wrap_cache_header('Vary: Accept-Language');
	wrap_cache_header('Cache-Control: private');
}

/**
 * Reads the language from the URL and returns without it
 * Liest die Sprache aus der URL aus und gibt die URL ohne Sprache zurück 
 * 
 * settings: 'lang' (will be changed), 'base' (will be changed)
 * @param array $url ($zz_page['url'])
 * @return array $url
 */
function wrap_prepare_url($url) {
	// looking for /en/ or similar
	if (empty($url['full']['path'])) return $url;
	// if /en/ is not there, /en still may be, so check full URL
	if (!$pos = strpos(substr($url['full']['path'], 1), '/')) {
		$pos = strlen($url['full']['path']);
	}
	$lang = substr($url['full']['path'], 1, $pos);
	// check if it’s a language
	if (wrap_setting('languages_allowed')) {
		// read from array
		if (!in_array($lang, wrap_setting('languages_allowed'))) 
			$lang = false;
	} else {
		// impossible to check, so there's no language
		$lang = false;
	}
	
	// if no language can be extracted from URL, return URL without changes
	if (!$lang) return $url;
		
	// save language in settings
	wrap_setting('lang', $lang);
	// add language to base URL
	wrap_setting('base', wrap_setting('base').'/'.$lang);
	// modify internal URL
	wrap_setting('language_in_url', true);
	$url['full']['path'] = substr($url['full']['path'], $pos + 1);
	if (!$url['full']['path']) {
		$url['full']['path'] = '/';
		$url['redirect'] = true;
		$url['redirect_cache'] = false;
	}
	return $url;
}

/**
 * Gets text from a database table
 * 
 * @param string $language (ISO 639-1 two letter code)
 * @return array $text
 */
function wrap_language_get_text($language) {
	$sql = 'SELECT text_id, text, more_text
		FROM %s';
	$sql = sprintf($sql, wrap_sql_table('default_text'));
	$sourcetext = wrap_db_fetch($sql, 'text_id');
	if (!$sourcetext) return [];
	$translations = wrap_translate($sourcetext, wrap_sql_table('default_text'), false, true, $language);

	$text = [];
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
 * Translate text from textfile if possible 
 * or write back text string to be translated
 * 
 * @param string $string	Text string to be translated
 * @param mixed $params
 *		array list of parameters
 *		string	Language to translate into (if different from
 *		actively used language on website @deprecated)
 * @global array $zz_conf	configuration variables
 * @return string $string	Translation of text
 */
function wrap_text($string, $params = []) {
	global $zz_conf;
	static $text;
	static $text_included;
	static $module_text;
	static $context;
	static $replacements = [];
	static $deprecation_error = false;
	
	if (!$string) return $string;
	if (wrap_setting('character_set') !== 'utf-8' AND mb_detect_encoding($string, 'UTF-8', true))
		$string = wrap_text_recode($string, 'utf-8');

	// @deprecated
	if (!is_array($params)) $params = ['lang' => $params];

	// get filename for translated texts
	if (!empty($params['lang']))
		$language = $params['lang'];
	else
		$language = wrap_setting('lang');
	if (wrap_setting('language_default_for['.$language.']'))
		$language = wrap_setting('language_default_for['.$language.']');

	// replacements?
	if (!empty($params['replace']))
		$replacements[$string] = $params['replace'];
	if (!empty($zz_conf['text'][$language])) {
		// @deprecated
		if (!$deprecation_error)
			wrap_error('Deprecated use of $zz_conf["text"]["'.$language.'"], use wrap_text() with replace instead.', E_USER_DEPRECATED);
		$deprecation_error = true;
		$replacements = array_merge($replacements, $zz_conf['text'][$language]);
	}
	if (!empty($zz_conf['text']['--'])) {
		// @deprecated
		if (!$deprecation_error)
			wrap_error('Deprecated use of $zz_conf["text"]["--"], use wrap_text() with replace instead.', E_USER_DEPRECATED);
		$deprecation_error = true;
		$replacements = array_merge($replacements, $zz_conf['text']['--']);
	}

	if (empty($text_included) OR $text_included !== $language) {
		$text = [];
		$module_text = [];
		$context = [];
		// standard text english
		$files[] = wrap_setting('custom_wrap_dir').'/text-en.inc.php';
		$files[] = wrap_setting('custom_wrap_dir').'/text-en.po';
		// default translated text
		$files[] = __DIR__.'/default-text-'.$language.'.po';
		// module text(s)
		foreach (wrap_setting('modules') as $module) {
			$modules_dir = wrap_setting('modules_dir').'/'.$module.'/'.$module;
			// zzform: for historical reasons, include -en text here as well
			if ($module === 'zzform' AND $language !== 'en')
				$files[] = $modules_dir.'/'.$module.'-en.po';
			$files[] = $modules_dir.'/'.$module.'-'.$language.'.po';
			if (wrap_setting('language_variation')) {
				$files[] = $modules_dir.'/'.$module.'-'.$language.'-'.wrap_setting('language_variation').'.po';
			}
		}
		// standard translated text 
		$files[] = wrap_setting('custom_wrap_dir').'/text-'.$language.'.inc.php';
		$files[] = wrap_setting('custom_wrap_dir').'/text-'.$language.'.po';
		if (wrap_setting('language_variation')) {
			// language variantes contain only some translations
			// and are added on top of the existing translations
			$files[] = wrap_setting('custom_wrap_dir').'/text-'.$language.'-'.wrap_setting('language_variation').'.po';
		}

		foreach ($files as $file) {
			if (substr($file, -3) === '.po') {
				$po_text = wrap_po_parse($file);
				// @todo plurals
				if (!empty($po_text['_global'])) {
					$text = array_merge($text, $po_text['_global']);
				}
				foreach (array_keys($po_text) as $area) {
					if (substr($area, 0, 1) === '_') continue;
					if (in_array($area, wrap_setting('modules')))
						$module_text[$area] = $po_text[$area];
					else
						$context[$area] = $po_text[$area];
				}
			} else {
				$text = array_merge($text, wrap_text_include($file));
			}
		}
		
		// set text as 'included' before database operation so if
		// database crashes just while reading values, it won't do it over and
		// over again		
		$text_included = $language;

		// get translations from database
		if (wrap_setting('translate_text_db'))
			$text = array_merge($text, wrap_language_get_text($language));
	}

	$my_text = $text;
	// active module?
	if (wrap_setting('active_module') AND !empty($module_text[wrap_setting('active_module')]))
		$my_text = array_merge($module_text[wrap_setting('active_module')], $text);
	// replacements?
	foreach ($replacements as $old => $new)
		$my_text[$old] = $my_text[$new] ?? $new;

	// if string came from preg_replace_callback, it might be an array
	if (is_array($string) AND !empty($string[1])) $string = $string[1];
	
	if (!empty($params['context']))
		if (array_key_exists($params['context'], $context))
			if (array_key_exists($string, $context[$params['context']]))
				return $context[$params['context']][$string];
	
	if (!array_key_exists($string, $my_text)) {
		// write missing translation to somewhere.
		// @todo check logfile for duplicates
		// @todo optional log directly in database
		// @todo log missing text in a .pot file
		if (wrap_setting('log_missing_text')) {
			$log_message = '$text["'.addslashes($string).'"] = "'.$string.'";'."\n";
			$log_file = sprintf(wrap_setting('log_missing_text_file'), $language);
			error_log($log_message, 3, $log_file);
			chmod($log_file, 0664);
		}
		return $string;
	}
	return $my_text[$string];
}

/**
 * include a text file
 *
 * @param string $file filename with path
 * @return array $text
 */
function wrap_text_include($file) {
	if (!file_exists($file)) return [];
	include $file;
	if (!isset($text)) return [];
	if (!is_array($text)) return [];
	if (wrap_setting('character_set') !== 'utf-8') {
		foreach ($text as $key => $value) {
			$text[$key] = mb_convert_encoding($value, 'HTML-ENTITIES', 'UTF-8'); 
		}
	}
	return $text;
}

/**
 * Translate text from database
 * 
 * @param array $data	Array of data, indexed by ID 
 * 			[34 => ['field1' = 34, 'field2' = 'text'] ...];
 *			if it's just a single record not indexed by ID, the first field_name
 *			is assumed to carry the ID!
 * @param mixed $matrix (string) name of database.table, translates all fields
 * 			that allow translation, write back to $data[$id][$field_name]
 *			example: ['maincategory' => 'categories.category'] writes value
 *			from table categories, field category to resulting field maincategory
 *			(add index main_category_id as $foreign_key_field_name)
 * @param string $foreign_key_field_name (optional) if it's not the main record but
 *			a detail record indexed by $foreign_key_field_name
 * @param bool $mark_incomplete	(optional) write back if fields are not translated?
 * @param string $lang different (optional) target language than set in setting 'lang'
 * @return array $data input array with translations where possible, extra array
 *		ID => wrap_source_language => field_name => en [iso_lang]
 */
function wrap_translate($data, $matrix, $foreign_key_field_name = '',
	$mark_incomplete = true, $target_language = false) {
	if (!wrap_setting('translate_fields')) return $data;
	if (!wrap_setting('default_source_language')) return $data;
	$translation_sql = wrap_sql_query('default_translations');
	if (!$translation_sql) return $data;

	if (!$target_language)
		// if we do not have a language to translate to, return data untranslated
		if (!$target_language = wrap_setting('lang')) return $data;

	// check the matrix and fill in the blanks
	// cross check against database
	if (!is_array($matrix)) {
		// replace existing prefixes
		$matrix = wrap_db_prefix($matrix);
		// used without other field definitions, one can write done the
		// sole db_name.table_name as well without .*
		if (substr_count($matrix, '.') < 2) {
			$matrix = [0 => $matrix.(substr($matrix, -2) === '.*' ? '' : '.*')];
		} else {
			$matrix = [0 => $matrix];
		}
	}
	$old_matrix = $matrix;
	$matrix = [];
	foreach ($old_matrix as $key => $field) {
		$field = wrap_db_prefix($field);
		// database name is optional, so add it here for all cases
		if (substr_count($field, '.') === 1) $field = wrap_setting('db_name').'.'.$field;
		if (is_numeric($key)) {
			// numeric key: CMS.seiten.titel, CMS.seiten.*
			$field_list = wrap_translate_field_list('field_name', $field);
			if ($field_list) $matrix += $field_list;
		} else {
		// alpha key: title => CMS.seiten.titel or seiten.titel
			$field_list = wrap_translate_field_list('"'.$key.'"', $field);
			$matrix = array_merge_recursive($matrix, $field_list);
		}
	}

	// check if $data is an array indexed by IDs
	$simple_data = false;
	$old_indices = [];
	foreach ($data as $id => $record) {
		if (!is_numeric($id)) {
			if (!is_array($data[$id])) {
				// single record
				$simple_data = $data[$id];	// save ID for later
				$old_data = $data;			// save old data in array
				unset($data);				// remove all keys
				$data[$old_data[$id]] = $old_data;
				break;
			} else {
				// indices are non-numeric, keep them for later
				$old_indices = array_keys($data);
				$data = array_values($data);
				break;
			}
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
		$data_ids = [];
		foreach ($data as $id => $record) {
			if (!empty($record[$foreign_key_field_name])) {
				$data_ids[$id] = $record[$foreign_key_field_name];
			} else {
				// there is no detail record
				continue;
			}
		}
	}
	// there are no detail records at all?
	if (empty($data_ids)) $matrix = []; // get out of here
	
	$old_empty_fields = [];
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
		$data_ids_flat = array_unique($data_ids);
		$sql = sprintf($translation_sql, $field_type, implode(',', array_keys($fields)), 
			implode(',', $data_ids_flat), $target_language);
		$main_language_sql = $sql . ' AND ISNULL(variation)';
		$translations = wrap_db_fetch($main_language_sql, 'translation_id');
		if (!$translations) continue;

		if (wrap_setting('language_variation')) {
			$variation_language_sql = $sql . sprintf(' AND variation = "%s"', wrap_setting('language_variation'));
			$variations = wrap_db_fetch($variation_language_sql, 'translation_id');
		} else {
			$variations = [];
		}

		// merge $translations into $data
		foreach ($translations as $tl) {
			foreach ($variations as $variation) {
				if ($variation['field_id'] !== $tl['field_id']) continue;
				if ($variation['translationfield_id'] !== $tl['translationfield_id']) continue;
				$tl['translation'] = $variation['translation'];
			}
			$field_name = $fields[$tl['translationfield_id']]['field_key'];
			$tl_ids = [];
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
					if (!empty($tl['source_language'])) {
						// language information if inside query, otherwise existing information
						// in $data will be left as is
						$data[$tl_id]['wrap_source_language'][$field_name] = $tl['source_language'];
						$data[$tl_id]['wrap_source_content'][$field_name] = $data[$tl_id][$field_name];
					}
					// only save fields that already existed beforehands
					$data[$tl_id][$field_name] = $tl['translation'];
					$translated_fields++;
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
		wrap_setting('translation_incomplete', true);
	// reset if array was simple
	if ($simple_data) {
		$data = $data[$simple_data];
	}
	if (!empty($old_indices)) {
		$data = array_combine($old_indices, $data);
	}
	return ($data);
	
	// output: @todo, mark text in different languages than page language
	// as span lang="de" or div lang="de" etc.
}

/**
 * check which of the fields of the table might have translations
 *
 * @param string $field_key
 * @param string $db_field (database.table.field, field might be *)
 * @return array
 */
function wrap_translate_field_list($field_key, $db_field) {
	static $data;
	$key = sprintf('%s/%s', $field_key, $db_field);
	if (isset($data[$key])) return $data[$key];

	$field = explode('.', $db_field);
	if (end($field) === '*') {
		array_pop($field);
		$sql = 'SELECT translationfield_id, %s AS field_key, field_type
			FROM %s
			WHERE db_name = "%%s" AND table_name = "%%s"';
	} else {
		$sql = 'SELECT translationfield_id, %s AS field_key, field_type
			FROM %s
			WHERE db_name = "%%s" AND table_name = "%%s" AND field_name = "%%s"';
	}
	$sql = sprintf($sql, $field_key, wrap_sql_table('default_translationfields'));
	$sql = vsprintf($sql, $field);
	$data[$key] = wrap_db_fetch($sql, ['field_type', 'translationfield_id']);
	return $data[$key];
}

/** 
 * translate page (that was not possible in wrap_look_for_page() because we
 * did not have complete language information then.
 *
 * @global array $zz_page (array 'db' will be changed)
 * @return bool true: translation was run, false: not run
 */
function wrap_translate_page() {
	global $zz_page;
	if (!wrap_setting('translate_fields')) return false;
	if (empty($zz_page['db'])) return false; // theme files
	$my_page = wrap_translate([
		$zz_page['db'][wrap_sql_fields('page_id')] => $zz_page['db']],
		wrap_sql_table('default_translation_pages')
	);
	$zz_page['db'] = array_shift($my_page);
	return true;
}

/** 
 * translate URL
 *
 * @param array $data
 * @return array
 */
function wrap_translate_url($data) {
	// has URL language code in it?
	if (!wrap_setting('language_in_url')) return $data;

	// are there translations for webpages.identifier?
	$field = wrap_translate_identifier_field();
	if (!$field) return $data;
	
	$identifiers = [];
	foreach ($data as $line) {
		$identifiers[] = sprintf('/%s', $line['url']);
	}

	$sql = 'SELECT translation, identifier
		FROM /*_PREFIX_*/_translations_%s translations
		LEFT JOIN /*_PREFIX_*/webpages webpages
			ON translations.field_id = webpages.page_id
		WHERE translationfield_id = %d
		AND translation IN ("%s")
		AND language_id = %d';
	$sql = sprintf($sql
		, $field['field_type']
		, $field['translationfield_id']
		, implode('", "', $identifiers)
		, wrap_language_id(wrap_setting('lang'))
	);
	$translations = wrap_db_fetch($sql, '_dummy_', 'key/value');
	if (!$translations) return $data;

	foreach ($data as $index => $line) {
		$url = sprintf('/%s', $line['url']);
		if (!array_key_exists($url, $translations)) continue;
		$data[$index]['url'] = ltrim($translations[$url], '/');
	}
	return $data;
}

/**
 * get translated URLs for current page
 *
 * @return array
 * @todo support placeholders in URLs
 */
function wrap_translate_url_other() {
	global $zz_page;
	$field = wrap_translate_identifier_field();
	if (!$field) return [];

	$sql = 'SELECT iso_639_1 AS lang
			, CONCAT(translation, IF(STRCMP(ending, "none"), ending, "")) AS translation
		FROM /*_PREFIX_*/_translations_%s translations
		LEFT JOIN /*_PREFIX_*/webpages webpages
			ON translations.field_id = webpages.page_id
		LEFT JOIN /*_PREFIX_*/languages USING (language_id)
		WHERE translationfield_id = %d
		AND field_id = %d';
	$sql = sprintf($sql
		, $field['field_type']
		, $field['translationfield_id']
		, $zz_page['db']['page_id']
	);
	$translations = wrap_db_fetch($sql, '_dummy_', 'key/value');
	if (!empty($zz_page['db']['wrap_source_language']['identifier']))
		$translations[$zz_page['db']['wrap_source_language']['identifier']]
			= $zz_page['db']['wrap_source_content']['identifier']
			.($zz_page['db']['ending'] !== 'none' ? $zz_page['db']['ending'] : '');
	return $translations;
}

/**
 * get definition of field which contains translations for webpages.identifier
 *
 * @return array
 */
function wrap_translate_identifier_field() {
	static $field;
	if (!empty($field)) return $field;

	$field = wrap_translate_field_list('field_name',
		wrap_setting('db_name').'./*_PREFIX_*/webpages.identifier'
	);
	if (!$field) return [];
	$field = reset($field);
	$field = reset($field);
	return $field;
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
 */
function wrap_set_units() {
	if (is_null(wrap_setting('decimal_point'))) {
		switch (wrap_setting('lang')) {
		case 'de':
		case 'fr':
		case 'es':
		case 'pl':
		case 'cs':
			wrap_setting('decimal_point', ',');
			break;
		default:
			wrap_setting('decimal_point', '.');
			break;
		}
	}
	if (is_null(wrap_setting('thousands_separator'))) {
		switch (wrap_setting('lang')) {
		case 'de':
		case 'fr':
		case 'es':
		case 'pl':
		case 'cs':
			if (wrap_setting('character_set') === 'utf-8') {
				wrap_setting('thousands_separator', "\xC2\xA0"); // non-breaking space
			} else {
				wrap_setting('thousands_separator', ' ');
			}
			break;
		default:
			wrap_setting('thousands_separator', ',');
			break;
		}
	}
}

/**
 * recode text from one character encoding to the used character encoding
 *
 * @param string $str
 * @param string $in_charset
 * @return string
 */
function wrap_text_recode($str, $in_charset) {
	$translated = @iconv($in_charset, wrap_setting('character_set'), $str);
	if (!$translated) {
		// characters which are not defined in the desired character set
		// replace with htmlentities
		$translated = htmlentities($str, ENT_NOQUOTES, $in_charset, false);
	}
	return $translated;
}

/**
 * Parse a gettext po file as a source for translations
 *
 * @param string $file
 * @return array
 */
function wrap_po_parse($file) {
	if (!file_exists($file)) return [];
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
		$context = '_global';
		$plurals = false;
		$format = false;
		foreach (array_keys($chunk) as $key) {
			$chunk[$key] = implode('', $chunk[$key]);
			$chunk[$key] = str_replace('\"', '"', $chunk[$key]);
			if (in_array($key, ['#:'])) continue;
			// does not recognize \n as newline
			$chunk[$key] = str_replace('\n', "\n", $chunk[$key]);
			if (wrap_setting('character_set') !== $header['X-Character-Encoding']) {
				$chunk[$key] = wrap_text_recode($chunk[$key], $header['X-Character-Encoding']);
			}
			switch ($key) {
			case 'msgctxt': $context = $chunk[$key]; break;
			case 'msgid_plural': $plurals = true; break;
			case '#,':
				if (!strstr($chunk[$key], 'php-format')) break;
				$format = true; break;
			}
		}
		if (!$plurals) {
			if ($chunk['msgstr']) {
				$text[$context][$chunk['msgid']] = $chunk['msgstr'];
			}
		} else {
			$text[$context][$chunk['msgid']] = $chunk['msgstr[0]'];
			$i = 1;
			while (isset($chunk['msgstr['.$i.']'])) {
				$text[$context][$chunk['msgid_plural']][$i] = $chunk['msgstr['.$i.']'];
				$i++;
			}
			$text['_plural'][$context][$chunk['msgid_plural']] = true;
		}
		if ($format) {
			$text['_format'][$context][$chunk['msgid']] = true;
			if ($plurals) $text['_format'][$context][$chunk['msgid_plural']] = true;
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
	$chunks = [];
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
	$my_headers = [];
	$my_headers['X-Character-Encoding'] = '';
	foreach ($headers as $header) {
		if (substr($header, -2) === '\n') $header = substr($header, 0, -2);
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
