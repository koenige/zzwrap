<?php 

/**
 * zzwrap
 * diff functions for display in browser
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Render unified diff of two strings as HTML for a preview
 *
 * @param string $old
 * @param string $new
 * @return string
 */
function wrap_diff($old, $new) {
	if ($old === $new) return wrap_diff_lines($old, ' ');

	$lines = wrap_diff_exec($old, $new);
	if (!$lines) return wrap_diff_fallback($old, $new);

	$html = [];
	foreach ($lines as $line) {
		if (str_starts_with($line, '+++') OR str_starts_with($line, '---')) continue;
		if (str_starts_with($line, '@@')) continue;
		if (str_starts_with($line, '\\')) continue;
		if ($line === '') {
			$html[] = wrap_diff_line(' ', '');
			continue;
		}
		$prefix = $line[0];
		if ($prefix === '+' OR $prefix === '-')
			$html[] = wrap_diff_line($prefix, substr($line, 1));
		elseif ($prefix === ' ')
			$html[] = wrap_diff_line(' ', substr($line, 1));
		else
			$html[] = wrap_diff_line(' ', $line);
	}
	return implode("\n", $html);
}

/**
 * Render all lines of $content with the same diff prefix class
 *
 * @param string $content
 * @param string $prefix +, -, or space (context)
 * @return string HTML
 */
function wrap_diff_lines($content, $prefix) {
	if ($content === '') return wrap_diff_line($prefix, '');

	$lines = preg_split("/\r?\n/", $content);
	if (end($lines) === '') array_pop($lines);

	$html = [];
	foreach ($lines as $line)
		$html[] = wrap_diff_line($prefix, $line);
	if (!$html) return wrap_diff_line($prefix, '');
	return implode("\n", $html);
}

/**
 * Render one diff line as a coloured HTML span
 *
 * @param string $prefix +, -, or space (context)
 * @param string $text line text without diff prefix
 * @return string HTML
 */
function wrap_diff_line($prefix, $text) {
	if ($prefix === '+')
		$class = 'diff-add';
	elseif ($prefix === '-')
		$class = 'diff-del';
	else
		$class = 'diff-ctx';
	if ($text === '')
		$escaped = '&nbsp;';
	else
		$escaped = wrap_html_escape($text);
	return sprintf('<span class="diff-line %s">%s</span>', $class, $escaped);
}

/**
 * Side-by-side diff fallback when diff(1) is unavailable
 *
 * @param string $old
 * @param string $new
 * @return string HTML
 */
function wrap_diff_fallback($old, $new) {
	if ($old === $new) return wrap_diff_lines($old, ' ');

	$html = [];
	foreach (wrap_diff_split_lines($old) as $line)
		$html[] = wrap_diff_line('-', $line);
	foreach (wrap_diff_split_lines($new) as $line)
		$html[] = wrap_diff_line('+', $line);
	if (!$html) return wrap_diff_line(' ', '');
	return implode("\n", $html);
}

/**
 * Split file content into lines, preserving internal blank lines
 *
 * @param string $content
 * @return array
 */
function wrap_diff_split_lines($content) {
	if ($content === '') return [];
	$lines = preg_split("/\r?\n/", $content);
	if (end($lines) === '') array_pop($lines);
	return $lines;
}

/**
 * Run diff -u on two strings via temporary files
 *
 * @param string $old
 * @param string $new
 * @return array lines of unified diff output, or empty if diff unavailable
 */
function wrap_diff_exec($old, $new) {
	$tmp_dir = wrap_setting('tmp_dir');
	if (!$tmp_dir) return [];
	$tmp_dir .= '/zzwrap/diff';
	wrap_mkdir($tmp_dir);
	if (!is_dir($tmp_dir)) return [];

	$old_file = tempnam($tmp_dir, 'old-');
	$new_file = tempnam($tmp_dir, 'new-');
	if (!$old_file OR !$new_file) return [];

	file_put_contents($old_file, $old);
	file_put_contents($new_file, $new);

	$command = sprintf('diff -u %s %s 2>/dev/null', escapeshellarg($old_file), escapeshellarg($new_file));
	$output = shell_exec($command);

	unlink($old_file);
	unlink($new_file);

	if ($output === null) return [];
	return preg_split("/\r?\n/", rtrim($output, "\n"));
}
