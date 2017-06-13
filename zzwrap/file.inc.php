<?php 

/**
 * zzwrap
 * File functions
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2017 Gustaf Mossakowski
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
		return sprintf(wrap_text('File %s is not writable.'), $file);

	$deleted = 0;
	$content = file($file);
	foreach ($lines as $line) {
		$line = explode(',', $line);
		foreach ($line as $no) {
			unset($content[$no]);
			$deleted++;
		}
	}

	// open file for writing
	if (!$handle = fopen($file, 'w+'))
		return sprintf(wrap_text('Cannot open %s for writing.'), $file);

	foreach($content as $line)
		fwrite($handle, $line);

	fclose($handle);
	return sprintf(wrap_text('%s lines deleted.'), $deleted);
}
