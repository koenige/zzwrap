<?php 

/**
 * zzwrap
 * File functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2023-2026 Gustaf Mossakowski
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
	if (!is_writable($file))
		return wrap_text('File %s is not writable.', ['values' => $file]);
	if (!$lines)
		return wrap_text('No lines deleted.');

	$delete = wrap_file_delete_line_numbers($lines);
	if (!$delete['numbers'])
		return wrap_text('No lines deleted.');

	$source = fopen($file, 'rb');
	if (!$source)
		return wrap_text('Cannot open %s for reading.', ['values' => $file]);

	$mode = fileperms($file) & 0777;
	$temp = tempnam(dirname($file), basename($file).'.');
	if (!$temp) {
		fclose($source);
		return wrap_text('Cannot create temporary file for %s.', ['values' => $file]);
	}

	$target = fopen($temp, 'wb');
	if (!$target) {
		fclose($source);
		unlink($temp);
		return wrap_text('Cannot open temporary file for %s.', ['values' => $file]);
	}

	$line_no = 0;
	while (($line = fgets($source)) !== false) {
		if (!isset($delete['numbers'][$line_no]))
			fwrite($target, $line);
		$line_no++;
	}
	fclose($source);
	fclose($target);

	if (!rename($temp, $file)) {
		unlink($temp);
		return wrap_text('Cannot write %s.', ['values' => $file]);
	}
	chmod($file, $mode);

	return wrap_text('%d lines deleted.', ['values' => $delete['count']]);
}

/**
 * line numbers to delete from wrap_file_delete_line()
 *
 * @param array $lines list entries, line numbers, ranges, or comma lists
 * @return array{numbers: array<int, true>, count: int}
 */
function wrap_file_delete_line_numbers($lines) {
	$numbers = [];
	$count = 0;

	foreach ($lines as $line) {
		$line = (string) $line;
		if (str_contains($line, '-')) {
			$parts = explode('-', $line);
			$range = range($parts[0], $parts[1]);
		} else {
			$range = explode(',', $line);
		}
		foreach ($range as $no) {
			$no = (int) $no;
			if (!isset($numbers[$no]))
				$numbers[$no] = true;
			$count++;
		}
	}
	return ['numbers' => $numbers, 'count' => $count];
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
	if ($pos = strpos($name, '[')) {
		$detail = '-'.substr($name, $pos + 1, -1);
		$name = substr($name, 0, $pos);
	} else {
		$detail = '';
	}
	if (!wrap_setting($logprefix.$name)) return $data;
	$fields = wrap_setting($logprefix.$name.'_fields') ?? [];
	$validity_seconds = wrap_setting($logprefix.$name.'_validity_in_minutes') * 60;
	if (!$validity_seconds) return $data;

	$logfile = sprintf('%s/%s%s%s.log', wrap_setting('log_dir'), $folder, $name, $detail);
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
			if (wrap_setting($logprefix.$name.'_spaces') AND count($line) > count($fields)) {
				while (count($line) !== count($fields)) {
					$last = array_pop($line);
					$line[count($line) - 1] .= ' '.$last;
				}
			}
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
		wrap_error(['Unable to determine which file this package belongs to: %s', ['values' => [$filename]]]);
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

/**
 * Key/value pairs from a zzwrap file header Variables section
 *
 * Reads the first lines of a source file for a comment block section (default
 * “Variables”) with lines like `translate_pot = admin` (# or * prefixes).
 *
 * @param string $content file contents
 * @param string $section section title line before key = value pairs
 * @return array<string, string|string[]> comma-containing values are string lists
 */
function wrap_file_header_variables($content, $section = 'Variables') {
	$content = str_replace(["\r\n", "\r"], "\n", $content);
	$lines = explode("\n", $content);
	$limit = min(count($lines), 40);
	$in_section = false;
	$variables = [];

	for ($index = 0; $index < $limit; $index++) {
		$text = wrap_file_header_line_text($lines[$index]);
		if ($text === null) {
			if ($in_section) break;
			continue;
		}
		if ($text === '') {
			if ($in_section) break;
			continue;
		}
		if ($text === $section) {
			$in_section = true;
			continue;
		}
		if (!$in_section) continue;
		if (!preg_match('/^(\w+)\s*=\s*(.+)$/', $text, $match)) break;
		$value = trim($match[2]);
		if (str_contains($value, ','))
			$variables[$match[1]] = wrap_file_header_split_list($value);
		else
			$variables[$match[1]] = $value;
	}
	return $variables;
}

/**
 * Comma-separated items from one file header variable value
 *
 * @param string $value
 * @return string[]
 */
function wrap_file_header_split_list($value) {
	$items = [];
	foreach (preg_split('/\s*,\s*/', $value) as $item) {
		$item = trim($item);
		if ($item !== '') $items[] = $item;
	}
	return $items;
}

/**
 * Text from a header comment line (# or block-comment * prefix)
 *
 * @param string $line
 * @return string|null trimmed inner text, empty string for blank comment lines, null if not a header comment line
 */
function wrap_file_header_line_text($line) {
	$line = rtrim($line);
	if ($line === '') return '';
	if (str_starts_with($line, '#')) return trim(substr($line, 1));
	if (preg_match('/^\s*\*+(.*)$/', $line, $match))
		return trim($match[1]);
	return null;
}
