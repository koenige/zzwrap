<?php 

/**
 * zzwrap
 * File functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2023-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * deletes lines from a file
 *
 * @param string $file path to file
 * @param array $lines list of line numbers to be deleted
 * @return string
 */
function wrap_file_delete_line($file, $lines) {
	// check if file exists and is writable
	if (!is_writable($file))
		return wrap_text('File %s is not writable.', ['values' => $file]);

	$deleted = 0;
	$content = file($file);
	foreach ($lines as $line) {
		if (strstr($line, '-')) {
			$line = explode('-', $line);
			$line = range($line[0], $line[1]);
		} else {
			$line = explode(',', $line);
		}
		foreach ($line as $no) {
			unset($content[$no]);
			$deleted++;
		}
	}

	// open file for writing
	if (!$handle = fopen($file, 'w+'))
		return wrap_text('Cannot open %s for writing.', ['values' => $file]);

	foreach($content as $line)
		fwrite($handle, $line);

	fclose($handle);
	return wrap_text('%d lines deleted.', ['values' => $deleted]);
}

/**
 * read custom log files, separated by space
 *
 * @param string $file
 * @param string $action
 * @param array $input
 * @return array
 */
function wrap_file_log($file, $action = 'read', $input = []) {
	$data = [];
	$logprefix = 'logfile_';
	if (strstr($file, '/')) {
		list($folder, $name) = explode('/', $file);
		$logprefix = sprintf('%s_%s', $folder, $logprefix);
		$folder .= '/';
	} else {
		$name = $file;
		$folder = '';
	}
	if (!wrap_setting($logprefix.$name)) return $data;
	$fields = wrap_setting($logprefix.$name.'_fields') ?? [];
	$validity_seconds = wrap_setting($logprefix.$name.'_validity_in_minutes') * 60;
	if (!$validity_seconds) return $data;

	$logfile = sprintf('%s/%s%s.log', wrap_setting('log_dir'), $folder, $name);
	if (!file_exists($logfile)) {
		wrap_mkdir(dirname($logfile));
		touch($logfile);
	}

	switch ($action) {
	case 'read':
	case 'delete':
		$lines = file($logfile);
		$delete_lines = [];
		foreach ($lines as $index => $line) {
			if (str_starts_with($line, hex2bin('00000000'))) {
				$delete_lines[] = $index;
				continue;
			}
			$line = explode(' ', trim($line));
			if (count($line) !== count($fields)) {
				$delete_lines[] = $index;
				continue;
			}
			foreach ($fields as $field_index => $field)
				$values[$field] = $line[$field_index];
			if (array_key_exists('timestamp', $values)) {
				if ($values['timestamp'] < time() - $validity_seconds) {
					$delete_lines[] = $index;
					continue;
				}
			}
			$found = false;
			if ($action === 'delete') {
				$found = true;
				foreach ($input as $field_name => $value)
					if ($values[$field_name] !== $value) $found = false;
				if ($found) $delete_lines[] = $index;
			}
			if (!$found) $data[] = $values;
		}
		if ($delete_lines)
			wrap_file_delete_line($logfile, $delete_lines);
		break;
	case 'write':
		$line = implode(' ', $input)."\n";
		error_log($line, 3, $logfile);
		break;
	}
	return $data;
}

/**
 * get package and path inside package
 *
 * @param string filename
 * @return array
 */
function wrap_file_package($filename) {
	$package = '';
	if (str_starts_with($filename, wrap_setting('modules_dir').'/')) {
		$prefix_len = strlen(wrap_setting('modules_dir'));
	} elseif (str_starts_with($filename, wrap_setting('themes_dir').'/')) {
		$prefix_len = strlen(wrap_setting('themes_dir'));
	} elseif (str_starts_with($filename, wrap_setting('custom'))) {
		$package = 'custom';
		$prefix_len = strlen(wrap_setting('custom'))
			- strlen(substr(wrap_setting('custom'), strrpos(wrap_setting('custom'), '/')));
	} else {
		wrap_error(sprintf('Unable to determine which file this package belongs to: %s', $filename));
		return [];
	}
	$filename = substr($filename, $prefix_len + 1);
	if (!$package)
		$package = substr($filename, 0, strpos($filename, '/'));
	return [
		'package' => $package,
		'path' => substr($filename, strpos($filename, '/') + 1)
	];
}
