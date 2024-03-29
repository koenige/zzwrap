<?php 

/**
 * zzwrap
 * File functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2017, 2020, 2023 Gustaf Mossakowski
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
