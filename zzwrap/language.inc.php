<?php 

/**
 * zzwrap
 * Language and internationalization functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2011, 2014-2018, 2020-2025 Gustaf Mossakowski
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
function wrap_language_set() {
	global $zz_page;

	// check for language code in URL
	wrap_prepare_url();

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
	if (!empty($_GET['lang']) AND in_array($_GET['lang'], wrap_setting('languages_allowed')))
		$language = $_GET['lang'];
	else
		$language = wrap_negotiate_language();
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
	// remove lang= from URL
	if (isset($_GET['lang']) AND $zz_page['url']['full']['query']) {
		parse_str($zz_page['url']['full']['query'], $qs);
		unset($qs['lang']);
		$zz_page['url']['full']['query'] = http_build_query($qs);
	}
	$zz_page['url']['redirect'] = true;
	$zz_page['url']['redirect_cache'] = false;
	// vary header for caching
	wrap_cache_header('Vary: Accept-Language');
	wrap_cache_header('Cache-Control: private');
}

/**
 * Reads the language from the URL and returns without it
 * looking for /en/ or similar
 * Liest die Sprache aus der URL aus und gibt die URL ohne Sprache zurück 
 * 
 * settings: 'lang' (will be changed), 'base' (will be changed)
 * @return bool
 */
function wrap_prepare_url() {
	global $zz_page;

	if (!wrap_setting('languages_allowed')) return false;
	if (empty($zz_page['url']['full']['path'])) return false;
	// if /en/ is not there, /en still may be, so check full URL
	if (!$pos = strpos(substr($zz_page['url']['full']['path'], 1), '/')) {
		$pos = strlen($zz_page['url']['full']['path']);
	}
	$lang = substr($zz_page['url']['full']['path'], 1, $pos);
	// check if it’s a language
	// read from array
	if (!in_array($lang, wrap_setting('languages_allowed'))) 
		// if no language can be extracted from URL, return without changes
		return false;
		
	// save language in settings
	wrap_setting('lang', $lang);
	// add language to base URL
	wrap_setting('base', wrap_setting('base').'/'.$lang);
	// modify internal URL
	wrap_setting('language_in_url', true);
	$zz_page['url']['full']['path'] = substr($zz_page['url']['full']['path'], $pos + 1);
	if (!$zz_page['url']['full']['path']) {
		$zz_page['url']['full']['path'] = '/';
		$zz_page['url']['redirect'] = true;
		$zz_page['url']['redirect_cache'] = false;
	}
	return true;
}

/**
 * Gets text from a database table
 * 
 * @param string $lang (ISO 639-1 two letter code)
 * @return array $text
 * @todo this is not 100 % correct, because language_variation refers to current language
 * not to any language
 */
function wrap_language_get_text($lang) {
	static $text = [];
	$key = sprintf('%s-%s', $lang, wrap_setting('language_variation'));
	if (array_key_exists($key, $text)) return $text[$key];

	$sql = 'SELECT text_id, text, more_text
		FROM /*_TABLE default_text _*/';
	$sourcetext = wrap_db_fetch($sql, 'text_id');
	if (!$sourcetext) return [];
	$translations = wrap_translate($sourcetext, wrap_sql_table('default_text'), false, true, $lang);

	$text[$key] = [];
	foreach ($sourcetext as $id => $values) {
		if (!empty($translations[$id]['text']))
			$text[$key][$values['text']] = $translations[$id]['text']
				.($translations[$id]['more_text'] ? ' '.$translations[$id]['more_text'] : '');
		else
			$text[$key][$values['text']] = $values['text']
				.($values['more_text'] ? ' '.$values['more_text'] : '');
	}
	return $text[$key];
}

/**
 * Translate text from textfile if possible 
 * or write back text string to be translated
 * 
 * @param string $string	Text string to be translated
 * @param mixed $params
 *		array list of parameters
 *			'lang': different language
 *			'set': set new key for translation
 *			'values': values for sprintf() use
 *		string	Language to translate into (if different from
 *		actively used language on website @deprecated)
 * @global array $zz_conf	configuration variables
 * @return string $string	Translation of text
 */
function wrap_text($string, $params = []) {
	global $zz_conf;
	static $text = [];
	static $text_included = '';
	static $module_text = [];
	static $context = [];
	static $replacements = [];
	static $deprecation_error = false;
	static $plurals = [];
	
	if (!$string) return $string;
	if (wrap_setting('character_set') !== 'utf-8' AND mb_detect_encoding($string, 'UTF-8', true))
		$string = wrap_text_recode($string, 'utf-8');

	// @deprecated
	if (!is_array($params)) $params = ['lang' => $params];

	// get filename for translated texts
	$language = $params['lang'] ??  wrap_setting('lang');
	if (wrap_setting('language_default_for['.$language.']'))
		$language = wrap_setting('language_default_for['.$language.']');

	// replacements?
	if (!empty($params['set'])) {
		if (is_array($params['set'])) {
			if (array_key_exists($language, $params['set']))
				$replacements[$string] = $params['set'][$language];
		} else {
			$replacements[$string] = $params['set'];
		}
	}

	if (!empty($zz_conf['text'][$language])) {
		// @deprecated
		if (!$deprecation_error)
			wrap_error('Deprecated use of $zz_conf["text"]["'.$language.'"], use wrap_text_set() instead.', E_USER_DEPRECATED);
		$deprecation_error = true;
		$replacements = array_merge($replacements, $zz_conf['text'][$language]);
	}
	if (!empty($zz_conf['text']['--'])) {
		// @deprecated
		if (!$deprecation_error)
			wrap_error('Deprecated use of $zz_conf["text"]["--"], use wrap_text_set() instead.', E_USER_DEPRECATED);
		$deprecation_error = true;
		$replacements = array_merge($replacements, $zz_conf['text']['--']);
	}

	if (!$text_included OR $text_included !== $language) {
		$text = [];
		$module_text = [];
		$context = [];
		// standard text english
		$files[] = wrap_setting('custom').'/custom/text-en.inc.php'; // @deprecated
		$files[] = wrap_setting('custom').'/custom/text-en.po'; // @deprecated
		$files[] = wrap_setting('custom').'/languages/text-en.po';
		// default translated text
		if ($language === 'en')
			$files[] = __DIR__.'/../languages/zzwrap.pot';
		$files[] = __DIR__.'/../languages/zzwrap-'.$language.'.po';
		// module text(s)
		foreach (wrap_setting('modules') as $module) {
			if ($module === 'zzwrap') continue;
			$modules_dir_deprecated = wrap_setting('modules_dir').'/'.$module.'/'.$module;
			$modules_dir = wrap_setting('modules_dir').'/'.$module.'/languages';
			if ($language === 'en') // plurals, if .po file exists, included below, overwrite
				$files[] = $modules_dir.'/'.$module.'.pot';
			// zzform: for historical reasons, include -en text here as well
			if ($module === 'zzform' AND $language !== 'en')
				$files[] = $modules_dir.'/'.$module.'-en.po';
			$files[] = $modules_dir_deprecated.'/'.$module.'-'.$language.'.po'; // @deprecated
			$files[] = $modules_dir.'/'.$module.'-'.$language.'.po';
			if (wrap_setting('language_variation')) {
				$files[] = $modules_dir_deprecated.'/'.$module.'-'.$language.'-'.wrap_setting('language_variation').'.po'; // @deprecated
				$files[] = $modules_dir.'/'.$module.'-'.$language.'-'.wrap_setting('language_variation').'.po';
			}
		}
		// standard translated text 
		$files[] = wrap_setting('custom').'/custom/text-'.$language.'.inc.php'; // @deprecated
		$files[] = wrap_setting('custom').'/custom/text-'.$language.'.po'; // @deprecated
		if ($language === 'en') // plurals, if .po file exists, included below, overwrite
			$files[] = wrap_setting('custom').'/languages/text.pot';
		$files[] = wrap_setting('custom').'/languages/text-'.$language.'.po';
		if (wrap_setting('language_variation')) {
			// language variantes contain only some translations
			// and are added on top of the existing translations
			$files[] = wrap_setting('custom').'/custom/text-'.$language.'-'.wrap_setting('language_variation').'.po'; // @deprecated
			$files[] = wrap_setting('custom').'/languages/text-'.$language.'-'.wrap_setting('language_variation').'.po';
		}

		$plurals[$language] = [];
		foreach ($files as $file) {
			if (str_ends_with($file, '.po') OR str_ends_with($file, '.pot')) {
				if (!file_exists($file)) continue;
				$po_text = wrap_po_parse($file);
				if (!empty($po_text['_po_header']['Language']))
					$plurals[$po_text['_po_header']['Language']] = $po_text['_plural_forms'] ?? [];
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
	}

	// get translations from database
	if (wrap_setting('translate_text_db'))
		$text = array_merge($text, wrap_language_get_text($language));

	$my_text = $text;
	// active module?
	if (wrap_setting('active_module') AND !empty($module_text[wrap_setting('active_module')]))
		$my_text = array_merge($module_text[wrap_setting('active_module')], $text);
	// replacements?
	foreach ($replacements as $old => $new)
		$my_text[$old] = $my_text[$new] ?? $new;

	// if string came from preg_replace_callback, it might be an array
	if (is_array($string) AND !empty($string[1])) $string = $string[1];
	
	$params['plurals'] = $plurals[$language] ?? [];
	
	if (!empty($params['context']))
		if (array_key_exists($params['context'], $context))
			if (array_key_exists($string, $context[$params['context']]))
				return wrap_text_values($context[$params['context']], $string, $params);
	
	if (!array_key_exists($string, $my_text)) {
		if (empty($params['ignore_missing_translation']))
			wrap_text_log($string, $params['source'] ?? '');
		return wrap_text_values([], $string, $params);
	}
	return wrap_text_values($my_text, $string, $params);
}

/**
 * format string with sprintf
 *
 * @param array $text
 * @param string $key
 * @param array $params
 * @return string
 */
function wrap_text_values($text, $key, $params) {
	$translation = $text[$key] ?? $key;
	if (!isset($params['values'])) {
		if (!is_array($translation)) return $translation;
		$params['values'] = [0]; // array = plural, no value = plural for 0 occurences
	}
	if (!is_array($params['values'])) $params['values'] = [$params['values']];
	if (!is_array($translation)) return vsprintf($translation, $params['values']);
	// @todo check for %d and support strings with more than one placeholder
	// currently, has to be first placeholder
	$counter = wrap_text_counter($params['values']);
	$index = wrap_text_plurals($counter, $params['plurals']);
	// translation might be missing, other language might only have one plural
	if (!array_key_exists($index, $translation)) $index = 1;
	return vsprintf($translation[$index], $params['values']);
}

/**
 * get counter for plurals
 *
 * @param array $values
 * @return int
 */
function wrap_text_counter($values) {
	$counter = '';
	while (!is_numeric($counter)) {
		if (!$values) {
			$counter = 0;
			break;
		}
		$counter = array_shift($values);
	}
	return $counter;
}

/**
 * check if there is a plural form for a certain value
 *
 * @param int $counter
 * @param array $plurals
 * @return int
 */
function wrap_text_plurals($counter, $plurals) {
	if (!$plurals) return 1; // no .po-file: return plural
	if (is_array($counter)) {
		// happens if we have several placeholder values
		$array = $counter;
		while ($array) {
			$counter = array_pop($array);
			if (is_numeric($counter)) break;
		}
	}
	
	foreach ($plurals as $index => $plural) {
		$check = str_replace('n', $counter, $plural);
		// since we create the .po files, eval is not evil here
		$result = eval("return $check;");
		if ($result) return $index;
	}
	return $index; // return last index
}

/**
 * set a new key for a text
 * shortcut for wrap_text()
 *
 * @param string $old
 * @param string $new
 */
function wrap_text_set($old, $new) {
	wrap_text($old, ['set' => $new]);
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
						$data[$tl_id]['wrap_source_language'][$field_name] = wrap_sql_placeholders($tl['source_language']);
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
	static $data = [];
	$key = sprintf('%s/%s', $field_key, $db_field);
	if (isset($data[$key])) return $data[$key];

	$field = explode('.', $db_field);
	$field[1] = wrap_db_prefix($field[1]);
	if (end($field) === '*') {
		array_pop($field);
		$sql = 'SELECT translationfield_id, %s AS field_key, field_type
			FROM /*_TABLE default_translationfields _*/
			WHERE db_name = "%%s" AND table_name = "%%s"';
	} else {
		$sql = 'SELECT translationfield_id, %s AS field_key, field_type
			FROM /*_TABLE default_translationfields _*/
			WHERE db_name = "%%s" AND table_name = "%%s" AND field_name = "%%s"';
	}
	$sql = sprintf($sql, $field_key);
	$sql = vsprintf($sql, $field);
	$data[$key] = wrap_db_fetch($sql, ['field_type', 'translationfield_id']);
	return $data[$key];
}

/** 
 * translate page (that was not possible in wrap_match_page() because we
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
	if (!wrap_setting('translate_fields')) return $data;

	// are there translations for webpages.identifier?
	$field = wrap_translate_identifier_field();
	if (!$field) return $data;
	
	$identifiers = [];
	foreach ($data as $line)
		$identifiers[] = sprintf('/%s', $line['url']);

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
	if (!wrap_setting('translate_fields')) return [];
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
 * get all translated identifiers for ID
 *
 * @param string $identifier
 * @param string $table
 * @param string $identifier_field_name (optional)
 * @return array
 */
function wrap_translate_id_identifier($id, $table, $identifier_field_name = 'identifier') {
	$sql = 'SELECT language_id, translation
	    FROM _translations_varchar
		LEFT JOIN /*_TABLE default_translationfields _*/ USING (translationfield_id)
		WHERE field_id = %d
		AND db_name = (SELECT DATABASE())
		AND table_name = "%s"
		AND field_name = "%s"';
	$sql = sprintf($sql, $id, $table, $identifier_field_name);
	return wrap_db_fetch($sql, '_dummy_', 'key/value');
}

/**
 * get translated identifier
 *
 * @param string $identifier
 * @param string $id_field_name
 * @param string $table (optional)
 * @param string $identifier_field_name (optional)
 * @return int
 */
function wrap_translate_identifier($identifier, $id_field_name, $table = '', $identifier_field_name = 'identifier') {
	if (!$table) $table = wrap_sql_plural($id_field_name);
	$language_id = wrap_id('languages', wrap_setting('lang'));
	$sql = 'SELECT %s
		FROM _translations_varchar
		LEFT JOIN /*_TABLE default_translationfields _*/ USING (translationfield_id)
		LEFT JOIN %s
			ON %s.%s = _translations_varchar.field_id
		WHERE translation = "%s"
		AND db_name = (SELECT DATABASE())
		AND table_name = "%s"
		AND field_name = "%s"
		AND language_id = %d';
	$sql = sprintf($sql
		, $identifier_field_name
		, $table, $table, $id_field_name
		, wrap_db_escape($identifier)
		, $table
		, $identifier_field_name
		, $language_id
	);
	$translated_identifier = wrap_db_fetch($sql, '', 'single value');
	if ($translated_identifier) return $translated_identifier;
	return $identifier;
}

/**
 * get definition of field which contains translations for webpages.identifier
 *
 * @return array
 */
function wrap_translate_identifier_field() {
	static $field = [];
	if ($field) return $field;

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
 * @return array
 */
function wrap_negotiate_language() {
	// check accepted languages
	$accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? NULL;
	// if no language information was sent, HTTP 1.1 says, every language is fine
	// for HTTP 1.0 you could send an 406 instead, but user may be confused by these
	if (empty($accept)) return wrap_setting('default_source_language');
	// all tags are case-insensitive
	$accept = mb_strtolower($accept);
	$languages = preg_split('/,\s*/', $accept);
	$allowed_languages = array_diff(
		wrap_setting('languages_allowed'), wrap_setting('languages_hidden')
	);
	
	$current_lang = wrap_setting('default_source_language');
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
			if (wrap_setting('negotiate_language_strict_mode')) break;
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
		case 'tr':
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
		case 'tr':
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
	$translated = mb_convert_encoding($str, wrap_setting('character_set'), $in_charset);
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
	$is_language_variation = str_ends_with($file, wrap_setting('language_variation').'.po') ? true : false;
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
		if (!$plurals AND $chunk['msgstr']) {
			// singular, there is a translation:
			$text[$context][$chunk['msgid']] = $chunk['msgstr'];
		} elseif ($plurals AND (!$is_language_variation OR !empty($chunk['msgstr[0]']))) {
			// plural, there is a translation
			$text[$context][$chunk['msgid_plural']][0] = $chunk['msgstr[0]'] ?? $chunk['msgid'];
			$i = 1;
			while (isset($chunk['msgstr['.$i.']'])) {
				$text[$context][$chunk['msgid_plural']][$i] = $chunk['msgstr['.$i.']'];
				$i++;
			}
			// set english plural
			if ($i === 1)
				$text[$context][$chunk['msgid_plural']][1] = $chunk['msgid_plural'];
			$text['_plural'][$context][$chunk['msgid_plural']] = true;
		}
		if ($format) {
			$text['_format'][$context][$chunk['msgid']] = true;
			if ($plurals) $text['_format'][$context][$chunk['msgid_plural']] = true;
		}
	}
	if (!empty($header['Plural-Forms'])) {
		$text['_plural_forms'] = wrap_po_plurals($header['Plural-Forms']);
	}
	$text['_po_header'] = $header;
	return $text;
}

/**
 * parse PO plural forms
 *
 * @param string $definition
 * @return array
 */
function wrap_po_plurals($definition) {
	$plurals = [];

	$def = explode(';', trim($definition, ';'));
	// get no. of plurals
	$no = intval(trim($def[0], 'nplurals='));

	// get bare calculation
	$plurals = trim($def[1]);
	$plurals = ltrim($plurals, 'plural=(');
	$plurals = rtrim($plurals, ')');
	$plurals = explode(':', $plurals);

	if (count($plurals) === 1 AND $no === 2) {
		array_unshift($plurals, '');
		$plurals = array_reverse($plurals, true);
	} else
		while (count($plurals) !== $no)
			$plurals[] = '';

	// we only support plural definitions in correct order
	foreach ($plurals as $index => &$plural) {
		$plural = trim($plural);
		if (str_ends_with($plural, ' ? '.$index))
			$plural = rtrim($plural, ' ? '.$index);
	}
	return $plurals;
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
	$append_header = '';
	foreach ($headers as $header) {
		if ($append_header) $header = $append_header.$header;
		if (substr($header, -2) !== '\n') {
			$append_header = $header;
			continue;
		} else {
			$append_header = '';
			$header = substr($header, 0, -2);
		}
		if (substr($header, -2) === '\n') $header = substr($header, 0, -2);
		$tokens = explode(': ', $header);
		$key = array_shift($tokens);
		$my_headers[$key] = implode(': ', $tokens);
		if ($key === 'Content-Type') {
			if (substr($my_headers[$key], 0, 20) !== 'text/plain; charset=') continue;
			$my_headers['X-Character-Encoding'] = strtolower(substr($my_headers[$key], 20));
		}
	}
	return $my_headers;
}

/**
 * write missing translation to somewhere.
 *
 * @param string $string
 * @param string $source (optional) source file name
 * @return bool
 * @todo check logfile for duplicates
 * @todo optional log directly in database
 * @todo log missing text in a .pot file
 */
function wrap_text_log($string, $source = '') {
	if (!wrap_setting('log_missing_text')) return false;
	wrap_include('file', 'zzwrap');

	$calls = debug_backtrace();
	$log = [];
	foreach ($calls as $index => $call) {
		switch ($call['function']) {
		case 'wrap_text':
		case 'wrap_text_log':
			continue 2;
		case 'brick_text':
			// from template
			// @todo does not work 100% if templates are included in other templates
			$log = wrap_file_package(wrap_setting('current_template_file'));
			$log['line'] = false;
			break 2;
		case 'wrap_cfg_translate':
			if (!$source) break 2;
			$log = wrap_file_package($source);
			$log['line'] = false;
			break 2;
		default:
			if ($source) {
				$log = wrap_file_package($source);
				$log['line'] = false;
				break 2;
			}
			// get last item
			$log = wrap_file_package($calls[$index - 1]['file']);
			if (!$log) {
				wrap_error('Unable to determine translation log: '.json_encode($call));
				return false;
			}
			$log['line'] = $calls[$index - 1]['line'];
			break 2;
		}
	}
	if (!$log) return false;
	
	if ($log['package'] === 'custom')
		$pot_file = sprintf('%s/languages/text.pot', wrap_setting('custom'));
	else
		$pot_file = sprintf('%s/%s/languages/%s.pot', wrap_setting('modules_dir'), $log['package'], $log['package']);
	if (!file_exists($pot_file)) {
		wrap_mkdir(dirname($pot_file));
		touch($pot_file);
	}

	$translation = [];
	$translation[] = '';
	if ($log['line'])
		$translation[] = sprintf('#: %s:%d', $log['path'], $log['line']);
	else
		$translation[] = sprintf('#: %s', $log['path']);
	$translation[] = sprintf('msgid "%s"', $string);
	$translation[] = 'msgstr ""';
	$translation[] = '';
	$translation = implode("\n", $translation);
	
	$pot_contents = file_get_contents($pot_file);
	if (strstr($pot_contents, $translation)) return;
	
	file_put_contents($pot_file, $translation, FILE_APPEND);
}
