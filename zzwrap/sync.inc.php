<?php

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2011
// CMS synchronization functions


/**
 * Sync data from CSV file with database content
 *
 * @param array $import
 *		string	'filename' = local filename of import file
 *		string	'delimiter' = delimiter of fields
 *		string	'enclosure' = enclosure of field value
 *		int		'key' = row with unique key (0...n)
 *		string	'comments' = character that marks commenting lines
 * @global array $zz_setting
 *		int		'sync_records_per_run'
 *		int		'sync_page_refresh'
 *		string	'sync_lists_dir'
 * @global array $zz_page		'url'['full']['path']
 * @return array $page
 */
function wrap_sync_csv($import) {
	global $zz_setting;
	global $zz_page;
	
	// set defaults global
	if (!isset($zz_setting['sync_records_per_run']))
		$zz_setting['sync_records_per_run'] = 1000;
	if (!isset($zz_setting['sync_page_refresh']))
		$zz_setting['sync_page_refresh'] = 2;
	if (!isset($zz_setting['sync_lists_dir']))
		$zz_setting['sync_lists_dir'] = $zz_setting['media_folder'];

	// set defaults per file
	if (!isset($import['comments']))
		$import['comments'] = '#';
	if (!isset($import['enclosure']))
		$import['enclosure'] = '"';
	if (!isset($import['delimiter']))
		$import['delimiter'] = ',';
	if (!isset($import['first_line_headers']))
		$import['first_line_headers'] = true;
	if (!isset($import['values']))
		$import['values'] = array();
	
	if (empty($_GET['limit'])) $limit = 0;
	else $limit = intval($_GET['limit']);
	$end = $limit + $zz_setting['sync_records_per_run'];

	$source = $zz_setting['sync_lists_dir'].$import['filename'];
	if (!file_exists($source)) {
		$page['text'] = sprintf(wrap_text('Import: File %s does not exist. Please set a different filename'), $source);
		return $page;
	}

	// open CSV file
	$i = 0;
	$handle = fopen($source, "r");
	while (!feof($handle)) {
		$line = fgetcsv($handle, 8192, $import['delimiter'], $import['enclosure']);
		$i++;
		// ignore first line = field names
		if ($import['first_line_headers'] AND $i === 1) continue;		
		// ignore lines that were already processed
		if ($i < $limit) continue;
		// ignore empty lines
		if (!$line) continue;
		if (!trim(implode('', $line))) continue;
		// ignore comments
		if ($import['comments']) {
			if (substr($line[0], 0, 1) == $import['comments']) continue;
		}

		// save lines in $raw
		if (is_array($import['key'])) {
			$key = '';
			foreach ($import['key'] AS $no) {
				$key .= $line[$no];
			}
		} else {
			$key = $line[$import['key']];
		}
		$key = trim($key);
		foreach (array_keys($line) AS $id)
			$line[$id] = trim($line[$id]);
		$raw[$key] = $line;
		if ($i === ($end -1)) break;
	}
	fclose($handle);

	// sync data
	list($updated, $inserted, $nothing, $errors) = wrap_sync_zzform($raw, $import);

	// output results
	$lines = array();
	if ($updated) 
		if ($updated === 1) $lines[] = wrap_text('1 update was made.');
		else $lines[] = sprintf(wrap_text('%s updates were made.'), $updated);
	if ($inserted) 
		if ($inserted === 1) $lines[] = wrap_text('1 insert was made.');
		else $lines[] = sprintf(wrap_text('%s inserts were made.'), $inserted);
	if ($nothing) 
		if ($nothing === 1) $lines[] = wrap_text('1 record was left as is.');
		else $lines[] = sprintf(wrap_text('%s records were left as is.'), $nothing);
	if ($errors) 
		if (count($errors) == 1) $lines[] = sprintf(wrap_text('1 record had errors. (%s)'), implode(', ', $errors));
		else $lines[] = sprintf(wrap_text('%s records had errors.'), count($errors))
			."<ul><li>\n".implode("</li>\n<li>", $errors)."</li>\n</ul>\n";

	if (!$lines) {
		$page['text'] = wrap_text('No updates/inserts were made.');
		return $page;
	}

	$page['text'] = implode('<br>', $lines);
	if ($i === ($end -1)) {
		$page['head'] = sprintf("\t".'<meta http-equiv="refresh" content="%s; URL=%s?limit=%s">'."\n",
			$zz_setting['sync_page_refresh'], $zz_setting['host_base'].$zz_page['url']['full']['path'], $end);
	}
	return $page;
}


/**
 * Sync of raw data to import with existing data, updates or inserts raw data
 * as required
 *
 * @param array $raw raw data, indexed by identifier
 * @param array $import import settings
 *		string	'existing_sql' = SQL query to get pairs of identifier/IDs
 *		array 	'fields' = list of fields, indexed by position
 *		array 	'values' = values for fields, indexed by field name
 *		string	'id_field_name' = field name of PRIMARY KEY of database table
 *		string	'form_script' = table script for sync
 * @global array $zz_conf string 'dir'
 * @return array $updated, $inserted, $nothing = count of records, $errors
 */
function wrap_sync_zzform($raw, $import) {
	global $zz_conf;
	// include form scripts
	require_once $zz_conf['dir'].'/zzform.php';

	$updated = 0;
	$inserted = 0;
	$nothing = 0;
	$errors = array();

	// get existing keys from database
	$keys = '"'.implode('", "', array_keys($raw)).'"';
	$sql = sprintf($import['existing_sql'], $keys);
	$ids = wrap_db_fetch($sql, '_dummy_', 'key/value');

	foreach ($raw as $identifier => $line) {
		$values = array();
		if (count($line) > count($import['fields'])) {
			// remove whitespace only fields at the end of the line
			do {
				$last = array_pop($line);
			} while (!$last AND count($line) >= count($import['fields']));
			$line[] = $last;
		}
		if (count($line) != count($import['fields'])) {
			$error_line = array();
			foreach ($import['fields'] as $pos => $field_name) {
				if (!isset($line[$pos])) $error_line[$field_name] = '<strong>=>||| '.wrap_text('not set').' |||<=</strong>';
				else $error_line[$field_name] = $line[$pos];
			}
			if (count($line) > count($import['fields'])) {
				$errors = array_merge($errors, array('too many values: '.wrap_print($error_line).wrap_print($line)));
			} else {
				$errors = array_merge($errors, array('not enough values: '.wrap_print($error_line).wrap_print($line)));
			}
			continue;
		}
		foreach ($import['fields'] as $pos => $field_name) {
			$values['POST'][$field_name] = trim($line[$pos]);
		}
		foreach ($import['values'] as $field_name => $value) {
			$values['POST'][$field_name] = $value;
		}
		if (!empty($ids[$identifier])) {
			$values['POST']['zz_action'] = 'update';
			$values['GET']['where'][$import['id_field_name']] = $ids[$identifier];
		} else {
			$values['POST']['zz_action'] = 'insert';
		}
		$ops = zzform_multi($import['form_script'], $values, 'record');
		if (!empty($ops['record_new'][0][$import['id_field_name']])) {
			$ids[$identifier][$import['id_field_name']] 
				= $ops['record_new'][0][$import['id_field_name']];
		}
		if ($ops['result'] == 'successful_insert') {
			$inserted++;
		} elseif ($ops['result'] == 'successful_update') {
			$updated++;
		} elseif ($ops['error']) {
			$errors = array_merge($errors, $ops['error']);
		} else {
			$nothing++;
		}
	}
	return array($updated, $inserted, $nothing, $errors);
}

?>