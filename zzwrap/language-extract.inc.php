<?php 

/**
 * zzwrap
 * Language and internationalization functions: extract text strings
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Collect translatable strings from a package for .pot maintenance
 *
 * Scans template text blocks, wrap_text() string literals, `_msg` assignments,
 * brick_xhr_error() message literals, and translatable configuration fields
 * (see configuration/cfg.cfg). Each entry includes file
 * references with line numbers where the scanner could determine them.
 *
 * @param string $package package folder name, or custom
 * @return array list of entries: msgid, context, references[], pot (translate_pot suffix)
 */
function wrap_text_sources($package) {
	if (!$package) return [];

	$package_dir = wrap_package_folder($package);
	if (!$package_dir) return [];

	$entries = [];
	wrap_text_sources_scan($package_dir, $entries);

	$sources = array_values($entries);
	usort($sources, function ($left, $right) {
		$compare = strcmp($left['pot'], $right['pot']);
		if ($compare !== 0) return $compare;
		return wrap_text_pot_compare_entries($left, $right);
	});
	foreach ($sources as $index => $source) {
		wrap_text_pot_sort_references($source['references']);
		$sources[$index] = $source;
	}
	return $sources;
}

/**
 * Scan package files and collect translatable strings into $entries
 *
 * Handles .template.txt, .css, .js, .php, and .cfg files (cfg only when listed in
 * wrap_cfg_translate_fields()). PHP wrap_text() calls may span multiple lines.
 * Also scans `_msg` assignments (string or array, including split lines) and
 * brick_xhr_error() call sites (second argument string literal).
 * translate_pot for a file may be set in a header Variables block (translate_pot = …).
 *
 * @param string $package_dir absolute path to package folder
 * @param array $entries collected entries, keyed by pot + msgid (by reference)
 * @return void
 */
function wrap_text_sources_scan($package_dir, &$entries) {
	wrap_include('file', 'zzwrap');
	$handlers = [
		'template' => [
			'pattern' => '/%%% text (.+?) %%%/',
			'parse' => 'wrap_text_sources_template',
		],
		'code' => [
			'patterns' => [
				[
					'pattern' => '/wrap_text\s*\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(?=[\s,\)])/',
					'parse' => 'wrap_text_sources_code',
					'context' => 'wrap_text_sources_code_context',
				],
				[
					'pattern' => '/brick_xhr_error\s*\(\s*[^,]+,\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(?=[\s,\)])/',
					'parse' => 'wrap_text_sources_brick_xhr_error',
				],
			],
		],
		'cfg' => [
			'parse' => 'wrap_text_sources_cfg',
		],
	];
	$translate_fields = wrap_cfg_translate_fields();

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($package_dir, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($iterator as $file) {
		if (!$file->isFile()) continue;

		$relative_path = substr($file->getPathname(), strlen($package_dir) + 1);
		if (str_starts_with($relative_path, 'languages/')) continue;

		if (str_ends_with($relative_path, '.cfg')) {
			$cfg_file = basename($relative_path);
			if (empty($translate_fields[$cfg_file])) continue;
			$handler = $handlers['cfg'];
			$handler['fields'] = $translate_fields[$cfg_file];
		} elseif (str_ends_with($relative_path, '.template.txt')) {
			$handler = $handlers['template'];
		} elseif (str_ends_with($relative_path, '.css')) {
			$handler = $handlers['template'];
		} elseif (str_ends_with($relative_path, '.js')) {
			$handler = $handlers['template'];
		} elseif (str_ends_with($relative_path, '.php')) {
			$handler = $handlers['code'];
		} else {
			continue;
		}

		if (!empty($handler['patterns'])) {
			$content = file_get_contents($file->getPathname());
			if ($content === false OR $content === '') continue;
			$content = str_replace(["\r\n", "\r"], "\n", $content);
			$pot = wrap_text_sources_translate_pot($content);
			foreach ($handler['patterns'] as $pattern) {
				if (!preg_match_all($pattern['pattern'], $content, $matches, PREG_OFFSET_CAPTURE)) continue;
				foreach ($matches[1] as $match) {
					$msgid = $pattern['parse']($match[0]);
					if ($msgid === null) continue;
					$context = '';
					if (!empty($pattern['context'])) {
						$context = $pattern['context'](
							$content,
							$match[1] + strlen($match[0])
						);
					}
					$reference = sprintf(
						'%s:%d',
						$relative_path,
						wrap_text_sources_line_number($content, $match[1])
					);
					wrap_text_sources_add($entries, $msgid, $reference, $pot, $context);
				}
			}
			wrap_text_sources_scan_msg($content, $pot, $relative_path, $entries);
			continue;
		}

		$content = file_get_contents($file->getPathname());
		if ($content === false OR $content === '') continue;
		$content = str_replace(["\r\n", "\r"], "\n", $content);
		$pot = wrap_text_sources_translate_pot($content);
		$lines = explode("\n", $content);
		if (!$lines) continue;

		foreach ($lines as $line_number => $line) {
			$reference = sprintf('%s:%d', $relative_path, $line_number + 1);
			if (array_key_exists('pattern', $handler)) {
				$found = [];
				if (preg_match_all($handler['pattern'], $line, $matches)) {
					foreach ($matches[1] as $chunk) {
						$msgid = $handler['parse']($chunk);
						if ($msgid === null) continue;
						$found[] = ['msgid' => $msgid, 'pot' => $pot];
					}
				}
			} else {
				$found = wrap_text_sources_cfg($line, $handler['fields']);
			}
			foreach ($found as $entry) {
				wrap_text_sources_add($entries, $entry['msgid'], $reference, $entry['pot']);
			}
		}
	}
}

/**
 * Scan `_msg` assignments in PHP source
 *
 * Matches `…['_msg'] = …` and `'_msg' => …` (not reads like `$error['_msg']`).
 * Extracts string literals and dot-concatenated strings; array values yield one
 * entry per string element (variables are skipped).
 *
 * @param string $content PHP file contents with Unix line endings
 * @param string $pot translate_pot suffix
 * @param string $relative_path path relative to package folder
 * @param array $entries collected entries (by reference)
 * @return void
 */
function wrap_text_sources_scan_msg($content, $pot, $relative_path, &$entries) {
	if (!preg_match_all(
		"/\['_msg'\]\s*(?:\n\s*)?=|'_msg'\s*=>/",
		$content,
		$matches,
		PREG_OFFSET_CAPTURE
	)) return;

	foreach ($matches[0] as $match) {
		$pos = $match[1] + strlen($match[0]);
		if (preg_match('/\G\s*/', $content, $whitespace, 0, $pos))
			$pos += strlen($whitespace[0]);
		if ($pos >= strlen($content)) continue;

		if ($content[$pos] === '[') {
			foreach (wrap_text_sources_msg_array_literals($content, $pos) as $literal) {
				$reference = sprintf(
					'%s:%d',
					$relative_path,
					wrap_text_sources_line_number($content, $literal['offset'])
				);
				wrap_text_sources_add($entries, $literal['msgid'], $reference, $pot);
			}
			continue;
		}

		$literals = wrap_text_sources_msg_value_literals($content, $pos);
		if (!$literals) continue;
		$msgid = implode('', array_column($literals, 'msgid'));
		if ($msgid === '') continue;
		$reference = sprintf(
			'%s:%d',
			$relative_path,
			wrap_text_sources_line_number($content, $literals[0]['offset'])
		);
		wrap_text_sources_add($entries, $msgid, $reference, $pot);
	}
}

/**
 * String literals from a `_msg` array value
 *
 * @param string $content
 * @param int $start byte offset of opening `[`
 * @return array list of entries with msgid and offset keys
 */
function wrap_text_sources_msg_array_literals($content, $start) {
	$length = strlen($content);
	if ($start >= $length OR $content[$start] !== '[') return [];

	$pos = $start + 1;
	$results = [];
	while ($pos < $length) {
		if (preg_match('/\G\s*/', $content, $whitespace, 0, $pos))
			$pos += strlen($whitespace[0]);
		if ($pos >= $length) break;
		if ($content[$pos] === ']') break;
		if ($content[$pos] === ',') {
			$pos++;
			continue;
		}

		$literals = wrap_text_sources_msg_value_literals($content, $pos);
		if ($literals) {
			$msgid = implode('', array_column($literals, 'msgid'));
			if ($msgid !== '') {
				$results[] = [
					'msgid' => $msgid,
					'offset' => $literals[0]['offset'],
				];
			}
			$pos = $literals[array_key_last($literals)]['end'];
			continue;
		}
		$pos = wrap_text_sources_msg_skip_array_element($content, $pos);
	}
	return $results;
}

/**
 * One `_msg` value: a string literal or dot-concatenated string literals
 *
 * @param string $content
 * @param int $offset
 * @return array list of entries with msgid, offset, and end keys
 */
function wrap_text_sources_msg_value_literals($content, $offset) {
	$literals = [];
	$pos = $offset;
	while (true) {
		$literal = wrap_text_sources_string_literal_at($content, $pos);
		if (!$literal) break;
		$literals[] = $literal;
		$pos = $literal['end'];
		if (!preg_match('/\G\s*\./', $content, $dot, 0, $pos)) break;
		$pos += strlen($dot[0]);
	}
	return $literals;
}

/**
 * Parse a quoted string literal at offset
 *
 * @param string $content
 * @param int $offset
 * @return array|null entry with msgid, offset, and end keys
 */
function wrap_text_sources_string_literal_at($content, $offset) {
	if (!preg_match(
		'/\G\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/',
		$content,
		$match,
		0,
		$offset
	)) return null;

	$quoted = $match[1];
	$literal_start = $offset + strpos($match[0], $quoted[0]);
	$msgid = wrap_text_sources_msg_literal($quoted);
	if ($msgid === null) return null;

	return [
		'msgid' => $msgid,
		'offset' => $literal_start,
		'end' => $offset + strlen($match[0]),
	];
}

/**
 * Skip a non-literal array element (variable, function call, nested array, …)
 *
 * @param string $content
 * @param int $offset
 * @return int byte offset after the element
 */
function wrap_text_sources_msg_skip_array_element($content, $offset) {
	$length = strlen($content);
	$pos = $offset;
	$depth = 0;
	$in_string = false;
	$quote = '';

	while ($pos < $length) {
		$char = $content[$pos];
		if ($in_string) {
			if ($char === '\\' AND $pos + 1 < $length) {
				$pos += 2;
				continue;
			}
			if ($char === $quote) {
				$in_string = false;
				$pos++;
				continue;
			}
			$pos++;
			continue;
		}
		if ($char === '\'' OR $char === '"') {
			$in_string = true;
			$quote = $char;
			$pos++;
			continue;
		}
		if ($char === '[' OR $char === '(') {
			$depth++;
			$pos++;
			continue;
		}
		if ($char === ']' OR $char === ')') {
			if ($depth > 0) {
				$depth--;
				$pos++;
				continue;
			}
			return $pos;
		}
		if ($char === ',' AND $depth === 0) return $pos;
		$pos++;
	}
	return $pos;
}

/**
 * translate_pot suffix from a file header Variables block
 *
 * @param string $content file contents
 * @return string translate_pot suffix, or empty string for the default .pot
 */
function wrap_text_sources_translate_pot($content) {
	$variables = wrap_file_header_variables($content);
	if (empty($variables['translate_pot'])) return '';
	$pot = $variables['translate_pot'];
	if (!preg_match('/^[a-z][a-z0-9_-]*$/', $pot)) return '';
	return $pot;
}

/**
 * Line number (1-based) for a byte offset in normalized file content
 *
 * @param string $content file contents with Unix line endings
 * @param int $offset byte offset of the match
 * @return int
 */
function wrap_text_sources_line_number($content, $offset) {
	return substr_count(substr($content, 0, $offset), "\n") + 1;
}

/**
 * Build msgid from a %%% text … %%% template chunk
 *
 * @param string $chunk inner part of the template text block
 * @return string|null
 */
function wrap_text_sources_template($chunk) {
	$parsed = brick_get_variables($chunk);
	if (!$parsed['vars']) return null;

	if (count($parsed['vars']) > 1
		AND (str_contains($parsed['vars'][0], ' ')
			OR !empty($parsed['in_quotes'])
			OR !empty($parsed['quoted_indices'][0]))) {
		return $parsed['vars'][0];
	}
	return implode(' ', $parsed['vars']);
}

/**
 * Build msgid from a wrap_text() string literal
 *
 * @param string $chunk quoted string including delimiters
 * @return string|null
 */
function wrap_text_sources_code($chunk) {
	if ($chunk === '') return null;
	$quote = $chunk[0];
	if ($quote !== '\'' AND $quote !== '"') return null;
	if (substr($chunk, -1) !== $quote) return null;

	return stripcslashes(substr($chunk, 1, -1));
}

/**
 * Build msgid from a translatable message string literal
 *
 * Skips empty strings and machine keys prefixed with `_`.
 *
 * @param string $chunk quoted string including delimiters
 * @return string|null
 */
function wrap_text_sources_msg_literal($chunk) {
	$msgid = wrap_text_sources_code($chunk);
	if ($msgid === null OR $msgid === '') return null;
	if ($msgid[0] === '_') return null;
	return $msgid;
}

/**
 * Build msgid from a brick_xhr_error() message string literal (2nd argument)
 *
 * @param string $chunk quoted string including delimiters
 * @return string|null
 */
function wrap_text_sources_brick_xhr_error($chunk) {
	return wrap_text_sources_msg_literal($chunk);
}

/**
 * gettext msgctxt from the wrap_text() params array, if any
 *
 * @param string $content PHP file contents
 * @param int $offset byte offset after the msgid string literal
 * @return string empty string when no context param
 */
function wrap_text_sources_code_context($content, $offset) {
	$tail = substr($content, $offset, 500);
	if (!preg_match(
		"/['\"]context['\"]\\s*=>\\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")/"
		, $tail, $match
	)) return '';
	return wrap_text_sources_code($match[1]) ?? '';
}

/**
 * Extract translatable cfg field values from one line
 *
 * @param string $line line from a .cfg file
 * @param array<string, string> $fields field name => translate_pot suffix
 * @return array list of entries with msgid and pot keys
 */
function wrap_text_sources_cfg($line, $fields) {
	$entries = [];
	foreach ($fields as $field => $pot) {
		if (!preg_match(
			'/^\s*'.preg_quote($field, '/').'\s*=\s*"((?:[^"\\\\]|\\\\.)*)"\s*(?:;.*)?$/'
			, $line, $match
		)) continue;
		$entries[] = ['msgid' => stripcslashes($match[1]), 'pot' => $pot];
	}
	return $entries;
}

/**
 * Add or merge a source entry (dedupe by pot + context + msgid, merge references)
 *
 * @param array $entries
 * @param string $msgid
 * @param string $reference file path with line number
 * @param string $pot translate_pot suffix (empty string = default .pot)
 * @param string $context gettext msgctxt, or empty string
 * @return void
 */
function wrap_text_sources_add(&$entries, $msgid, $reference, $pot = '', $context = '') {
	if ($msgid === '') return;

	$key = $pot."\0".$context."\0".$msgid;
	if (!isset($entries[$key])) {
		$entries[$key] = [
			'msgid' => $msgid,
			'context' => $context,
			'references' => [],
			'pot' => $pot,
		];
	}
	if (!in_array($reference, $entries[$key]['references'], true))
		$entries[$key]['references'][] = $reference;
}


// -------------- helper functions -------------- //

/**
 * Source strings grouped by .pot file, sorted by first #: reference
 *
 * @param string $package
 * @return array keyed by translate_pot suffix (empty string key = default .pot)
 */
function wrap_text_sources_by_pot($package) {
	$by_pot = [];
	foreach (wrap_text_sources($package) as $entry) {
		$by_pot[$entry['pot']][] = $entry;
	}
	foreach ($by_pot as $pot_suffix => $entries) {
		usort($entries, 'wrap_text_pot_compare_entries');
		$by_pot[$pot_suffix] = $entries;
	}
	ksort($by_pot);
	return $by_pot;
}

/**
 * gettext .pot header from pot.template.txt
 *
 * @param string $package
 * @param string $pot_suffix
 * @return string
 */
function wrap_text_pot_header($package, $pot_suffix = '', $creation_date = null) {
	return wrap_template('pot', wrap_text_pot_header_data($package, $pot_suffix, $creation_date));
}

/**
 * Data for pot.template.txt
 *
 * @param string $package
 * @param string $pot_suffix translate_pot suffix (empty = default .pot)
 * @param string|null $creation_date POT-Creation-Date value, or empty for preview builds
 * @return array
 */
function wrap_text_pot_header_data($package, $pot_suffix = '', $creation_date = null) {
	$data = [
		'package' => $package,
		'pot_suffix' => $pot_suffix,
		'package_type' => '',
		'package_label' => $package,
		'creation_date' => $creation_date ?? '',
	];

	if ($package === 'custom') {
		$data['package_label'] = 'custom';
		return $data;
	}

	$type = wrap_package($package);
	if (!$type) return $data;

	$data['package_type'] = $type;
	$pkg = wrap_cfg_files('package', ['package' => $package]);
	if (!empty($pkg['about']['name']))
		$data['package_label'] = $pkg['about']['name'];
	elseif ($type === 'modules')
		$data['package_label'] = $package.' module';
	else
		$data['package_label'] = $package.' theme';

	return $data;
}

/**
 * Build full .pot file content (header + entries)
 *
 * @param string $package
 * @param string $pot_suffix
 * @param array $entries wrap_text_sources() entries
 * @param string $creation_date POT-Creation-Date header value (empty for preview)
 * @return string
 */
function wrap_text_pot_build($package, $pot_suffix, array $entries, $creation_date = '') {
	$content = rtrim(wrap_text_pot_header(
		$package,
		$pot_suffix,
		$creation_date
	));
	$body = wrap_text_format_pot_chunks($entries);
	$content .= $body ? "\n\n".$body."\n" : "\n";
	return wrap_text_pot_normalize($content);
}

/**
 * Merge scanned entries with an existing .pot file
 *
 * Keeps old entries whose references have no line number. Scanned entries
 * replace line-less references in the same file when context and msgid match.
 *
 * @param array $scanned wrap_text_sources() entries for one .pot file
 * @param string $old_content existing .pot file contents
 * @return array merged entries
 */
function wrap_text_pot_merge_entries(array $scanned, $old_content) {
	$old_entries = wrap_text_pot_parse_entry_list($old_content);
	$old_by_key = [];
	$old_by_plural = [];
	foreach ($old_entries as $old) {
		$old_by_key[wrap_text_pot_entry_key($old)] = $old;
		if (!empty($old['msgid_plural']))
			$old_by_plural[wrap_text_pot_plural_lookup_key($old)] = $old;
	}

	$merged = [];
	foreach ($scanned as $entry) {
		$key = wrap_text_pot_entry_key($entry);
		if (isset($old_by_key[$key])) {
			$merged[$key] = $entry;
			wrap_text_pot_merge_entry_references($merged[$key], $old_by_key[$key]);
			wrap_text_pot_merge_entry_comments($merged[$key], $old_by_key[$key]);
			wrap_text_pot_merge_entry_plural($merged[$key], $old_by_key[$key]);
			continue;
		}
		$plural_key = wrap_text_pot_plural_lookup_key($entry);
		if (isset($old_by_plural[$plural_key])) {
			$old = $old_by_plural[$plural_key];
			$old_key = wrap_text_pot_entry_key($old);
			$merged[$old_key] = $old;
			$merged[$old_key]['references'] = $entry['references'];
			wrap_text_pot_merge_entry_references($merged[$old_key], $old);
			continue;
		}
		$merged[$key] = $entry;
	}

	foreach ($old_entries as $old) {
		$key = wrap_text_pot_entry_key($old);
		if (isset($merged[$key])) continue;
		if (!wrap_text_pot_entry_has_lineless_reference($old)) continue;
		$merged[$key] = $old;
	}

	return wrap_text_pot_sort_entries(array_values($merged));
}

/**
 * Sort .pot entries by first reference (file, line number) then msgid
 *
 * @param array $entries
 * @return array
 */
function wrap_text_pot_sort_entries(array $entries) {
	usort($entries, 'wrap_text_pot_compare_entries');
	return $entries;
}

/**
 * Keep line-less references from an old entry when merging with a scan match
 *
 * @param array $scan_entry merged entry from scan (by reference)
 * @param array $old_entry entry parsed from existing .pot file
 * @return void
 */
function wrap_text_pot_merge_entry_references(array &$scan_entry, array $old_entry) {
	$scan_files = [];
	foreach ($scan_entry['references'] as $reference)
		$scan_files[wrap_text_pot_reference_file($reference)] = true;

	foreach ($old_entry['references'] as $reference) {
		if (wrap_text_pot_reference_has_line($reference)) continue;
		$file = wrap_text_pot_reference_file($reference);
		if (!empty($scan_files[$file])) continue;
		if (!in_array($reference, $scan_entry['references'], true))
			$scan_entry['references'][] = $reference;
	}
	wrap_text_pot_sort_references($scan_entry['references']);
}

/**
 * Keep translator comments from an old entry when merging with a scan match
 *
 * @param array $scan_entry merged entry from scan (by reference)
 * @param array $old_entry entry parsed from existing .pot file
 * @return void
 */
function wrap_text_pot_merge_entry_comments(array &$scan_entry, array $old_entry) {
	if (empty($old_entry['comments'])) return;
	$scan_entry['comments'] = $old_entry['comments'];
}

/**
 * Keep plural forms from an old entry when merging with a scan match
 *
 * When the scanner only finds the plural msgid string, restore the singular
 * msgid and msgid_plural from the existing .pot entry.
 *
 * @param array $scan_entry merged entry from scan (by reference)
 * @param array $old_entry entry parsed from existing .pot file
 * @return void
 */
function wrap_text_pot_merge_entry_plural(array &$scan_entry, array $old_entry) {
	if (empty($old_entry['msgid_plural'])) return;
	$scan_entry['msgid'] = $old_entry['msgid'];
	$scan_entry['msgid_plural'] = $old_entry['msgid_plural'];
	if (!empty($old_entry['plural_style']))
		$scan_entry['plural_style'] = $old_entry['plural_style'];
}

/**
 * Whether an entry has at least one #: reference without a line number
 *
 * @param array $entry
 * @return bool
 */
function wrap_text_pot_entry_has_lineless_reference($entry) {
	foreach ($entry['references'] as $reference) {
		if (!wrap_text_pot_reference_has_line($reference)) return true;
	}
	return false;
}

/**
 * Whether a #: reference includes a line number suffix
 *
 * @param string $reference path with optional :line suffix
 * @return bool
 */
function wrap_text_pot_reference_has_line($reference) {
	return (bool) preg_match('/:\d+$/', $reference);
}

/**
 * File path from a #: reference, without the line number suffix
 *
 * @param string $reference path with optional :line suffix
 * @return string
 */
function wrap_text_pot_reference_file($reference) {
	if (preg_match('/^(.+):\d+$/', $reference, $match)) return $match[1];
	return $reference;
}

/**
 * Compare two #: references for sort order (file path, then line number)
 *
 * Paths sort with `.` before `-` at the first differing character (notes.php
 * before notes-notes.php). Line-less references sort before line-numbered ones
 * in the same file.
 *
 * @param string $left
 * @param string $right
 * @return int -1, 0, or 1
 */
function wrap_text_pot_compare_references($left, $right) {
	$left_file = wrap_text_pot_reference_file($left);
	$right_file = wrap_text_pot_reference_file($right);
	$compare = wrap_text_pot_compare_reference_paths($left_file, $right_file);
	if ($compare !== 0) return $compare;

	$left_line = 0;
	if (preg_match('/:(\d+)$/', $left, $match)) $left_line = (int) $match[1];
	$right_line = 0;
	if (preg_match('/:(\d+)$/', $right, $match)) $right_line = (int) $match[1];
	return $left_line <=> $right_line;
}

/**
 * Compare two file paths for #: reference sort order
 *
 * Like strcmp, but when paths diverge at `.` versus `-`, `.` sorts first so
 * e.g. notes.php comes before notes-notes.php.
 *
 * @param string $left
 * @param string $right
 * @return int -1, 0, or 1
 */
function wrap_text_pot_compare_reference_paths($left, $right) {
	$length = min(strlen($left), strlen($right));
	for ($index = 0; $index < $length; $index++) {
		if ($left[$index] === $right[$index]) continue;
		if ($left[$index] === '.' AND $right[$index] === '-') return -1;
		if ($left[$index] === '-' AND $right[$index] === '.') return 1;
		return $left[$index] <=> $right[$index];
	}
	return strlen($left) <=> strlen($right);
}

/**
 * Sort #: references in place (file path, then line number)
 *
 * @param array $references by reference
 * @return void
 */
function wrap_text_pot_sort_references(array &$references) {
	usort($references, 'wrap_text_pot_compare_references');
}

/**
 * Compare two .pot entries for sort order (first reference, then msgid)
 *
 * @param array $left
 * @param array $right
 * @return int -1, 0, or 1
 */
function wrap_text_pot_compare_entries($left, $right) {
	$compare = wrap_text_pot_compare_references(
		$left['references'][0] ?? '',
		$right['references'][0] ?? ''
	);
	if ($compare !== 0) return $compare;
	$compare = strcmp($left['context'] ?? '', $right['context'] ?? '');
	if ($compare !== 0) return $compare;
	return strcmp($left['msgid'], $right['msgid']);
}

/**
 * .pot files to show or write for a package (scan merged with existing files)
 *
 * @param string $package
 * @return array list of pot_file, filename, entries, old, new, pot_suffix
 */
function wrap_text_pot_items($package) {
	$items = [];
	$sources_by_pot = wrap_text_sources_by_pot($package);

	foreach (wrap_text_pot_suffixes($package) as $pot_suffix) {
		$scanned = $sources_by_pot[$pot_suffix] ?? [];
		$pot_file = wrap_text_log_pot_file($package, $pot_suffix);
		$old = file_exists($pot_file) ? file_get_contents($pot_file) : '';

		$entries = wrap_text_pot_merge_entries($scanned, $old);
		if (!$entries AND $old === '') continue;
		if (!$entries AND !wrap_text_pot_parse_entries($old)) continue;

		$items[] = [
			'pot_suffix' => $pot_suffix,
			'pot_file' => $pot_file,
			'filename' => basename($pot_file),
			'entries' => $entries,
			'old' => $old,
			'new' => wrap_text_pot_build($package, $pot_suffix, $entries),
		];
	}
	return $items;
}

/**
 * Write scanned .pot content to disk
 *
 * @param string $package
 * @return array ok (bool), message (string), written (string[] filenames)
 */
function wrap_text_pot_write($package) {
	$lang_dir = wrap_text_languages_path($package);
	if (!$lang_dir) {
		return [
			'ok' => false,
			'message' => wrap_text('Unknown package.'),
			'written' => [],
		];
	}

	wrap_include('file', 'zzwrap');
	if (!is_dir($lang_dir)) wrap_mkdir($lang_dir);

	$written = [];
	foreach (wrap_text_pot_items($package) as $pot) {
		if (wrap_text_pot_normalize_for_diff($pot['old']) === wrap_text_pot_normalize_for_diff($pot['new']))
			continue;

		$new = wrap_text_pot_build(
			$package,
			$pot['pot_suffix'],
			$pot['entries'],
			gmdate('Y-m-d H:i').'+0000'
		);
		if (file_put_contents($pot['pot_file'], $new) === false) {
			return [
				'ok' => false,
				'message' => wrap_text('Could not write file: %s', ['values' => [$pot['filename']]]),
				'written' => $written,
			];
		}
		$written[] = $pot['filename'];
	}

	if (!$written) {
		return [
			'ok' => true,
			'message' => wrap_text('No .pot files were changed.'),
			'written' => [],
		];
	}
	return [
		'ok' => true,
		'message' => wrap_text('%d .pot file(s) written.', ['values' => [count($written)]]),
		'written' => $written,
	];
}

/**
 * translate_pot suffixes from source scan and existing .pot files on disk
 *
 * @param string $package
 * @return array list of suffixes (empty string = default .pot)
 */
function wrap_text_pot_suffixes($package) {
	$suffixes = [];
	foreach (array_keys(wrap_text_sources_by_pot($package)) as $pot_suffix)
		$suffixes[$pot_suffix] = true;

	$lang_dir = wrap_text_languages_path($package);
	if (!$lang_dir OR !is_dir($lang_dir)) return array_keys($suffixes);

	$basename = wrap_text_language_basename($package);
	foreach (glob(sprintf('%s/%s*.pot', $lang_dir, $basename)) ?: [] as $file) {
		$name = basename($file, '.pot');
		if ($name === $basename)
			$suffixes[''] = true;
		elseif (str_starts_with($name, $basename.'-'))
			$suffixes[substr($name, strlen($basename) + 1)] = true;
	}

	$keys = array_keys($suffixes);
	sort($keys);
	return $keys;
}

/**
 * HTML diff of two complete .pot files (existing vs wrap_text_pot_build output)
 *
 * @param string $old_content existing .pot file contents, or empty string
 * @param string $new_content full .pot content from wrap_text_pot_build()
 * @return string HTML for .pot-diff container
 */
function wrap_text_pot_diff_html($old_content, $new_content) {
	return wrap_text_diff_html(
		wrap_text_pot_normalize_for_diff($old_content),
		wrap_text_pot_normalize_for_diff($new_content)
	);
}

/**
 * Count added, deleted, and updated entries between old .pot and scan
 *
 * @param string $old_content
 * @param array $new_entries
 * @return array added, deleted, updated, unchanged (int)
 */
function wrap_text_pot_diff_stats($old_content, array $new_entries) {
	$stats = ['added' => 0, 'deleted' => 0, 'updated' => 0, 'unchanged' => 0];
	$old = wrap_text_pot_parse_entries($old_content);
	$new = [];
	foreach ($new_entries as $entry)
		$new[wrap_text_pot_entry_key($entry)] = wrap_text_pot_entry_signature($entry);

	foreach ($new_entries as $entry) {
		$key = wrap_text_pot_entry_key($entry);
		if (!isset($old[$key]))
			$stats['added']++;
		elseif ($old[$key] !== $new[$key])
			$stats['updated']++;
		else
			$stats['unchanged']++;
	}
	foreach ($old as $key => $signature) {
		if (!isset($new[$key])) $stats['deleted']++;
	}
	return $stats;
}

/**
 * Source strings not yet present in the corresponding .pot file(s)
 *
 * @param string $package
 * @return array keyed by translate_pot suffix (empty string key = default .pot)
 */
function wrap_text_sources_new($package) {
	$new = [];
	foreach (wrap_text_sources($package) as $entry) {
		$pot_file = wrap_text_log_pot_file($package, $entry['pot']);
		if (array_key_exists(
			wrap_text_pot_entry_key($entry),
			wrap_text_pot_parse_entries(file_exists($pot_file) ? file_get_contents($pot_file) : '')
		))
			continue;
		$new[$entry['pot']][] = $entry;
	}
	return $new;
}

/**
 * Parse .pot entry bodies keyed by context + msgid
 *
 * @param string $content .pot file contents
 * @return array entry key => signature (context + msgid + sorted references)
 */
function wrap_text_pot_parse_entries($content) {
	$entries = [];
	foreach (wrap_text_pot_parse_entry_list($content) as $entry)
		$entries[wrap_text_pot_entry_key($entry)] = wrap_text_pot_entry_signature($entry);
	return $entries;
}

/**
 * Parse .pot entry bodies as a list
 *
 * @param string $content .pot file contents
 * @return array list of entries: msgid, context, references[], comments[], pot
 */
function wrap_text_pot_parse_entry_list($content) {
	$entries = [];
	foreach (wrap_text_pot_parse_chunks($content) as $chunk) {
		$entry = wrap_text_pot_parse_chunk($chunk);
		if (!$entry) continue;
		$entries[] = $entry;
	}
	return $entries;
}

/**
 * Format translation entries as gettext .pot chunks
 *
 * @param array $entries wrap_text_sources() entries
 * @return string
 */
function wrap_text_format_pot_chunks(array $entries) {
	$chunks = [];
	foreach ($entries as $entry) {
		$lines = [];
		foreach ($entry['comments'] ?? [] as $comment)
			$lines[] = $comment;
		foreach ($entry['references'] as $reference)
			$lines[] = '#: '.$reference;
		if (!empty($entry['context']))
			$lines[] = 'msgctxt "'.wrap_text_pot_escape($entry['context']).'"';
		$lines[] = 'msgid "'.wrap_text_pot_escape($entry['msgid']).'"';
		if (!empty($entry['msgid_plural'])) {
			$lines[] = 'msgid_plural "'.wrap_text_pot_escape($entry['msgid_plural']).'"';
			if (($entry['plural_style'] ?? 'indexed') === 'brackets') {
				$lines[] = 'msgstr[] ""';
				$lines[] = 'msgstr[] ""';
			} else {
				$lines[] = 'msgstr[0] ""';
				$lines[] = 'msgstr[1] ""';
			}
		} else {
			$lines[] = 'msgstr ""';
		}
		$chunks[] = implode("\n", $lines);
	}
	return implode("\n\n", $chunks);
}

/**
 * Escape a string for a msgid/msgstr line in a .pot file
 *
 * @param string $string
 * @return string
 */
function wrap_text_pot_escape($string) {
	$string = str_replace('\\', '\\\\', $string);
	$string = str_replace('"', '\\"', $string);
	return str_replace("\n", '\n', $string);
}

/**
 * Unescape a msgid value read from a single-line .pot entry
 *
 * @param string $string escaped string without surrounding quotes
 * @return string
 */
function wrap_text_pot_unescape($string) {
	return stripcslashes($string);
}

/**
 * Normalize .pot file content for build output and diff comparison
 *
 * Unix line endings, strip trailing spaces per line, single trailing newline.
 *
 * @param string $content
 * @return string
 */
function wrap_text_pot_normalize($content) {
	if ($content === '') return '';
	$content = str_replace(["\r\n", "\r"], "\n", $content);
	$content = wrap_text_pot_strip_trailing_spaces($content);
	return rtrim($content, "\n")."\n";
}

/**
 * Normalize .pot content for diff and write comparison (ignores POT-Creation-Date)
 *
 * @param string $content
 * @return string
 */
function wrap_text_pot_normalize_for_diff($content) {
	$content = wrap_text_pot_normalize($content);
	if ($content === '') return '';
	return preg_replace(
		'/"POT-Creation-Date: [^"]*\\\\n"/',
		'"POT-Creation-Date: \\n"',
		$content
	);
}

/**
 * Strip trailing spaces and tabs from each line
 *
 * @param string $content
 * @return string
 */
function wrap_text_pot_strip_trailing_spaces($content) {
	$lines = explode("\n", $content);
	foreach (array_keys($lines) as $index)
		$lines[$index] = rtrim($lines[$index], " \t");
	return implode("\n", $lines);
}

/**
 * Split .pot content into chunks separated by blank lines
 *
 * @param string $content
 * @return array
 */
function wrap_text_pot_parse_chunks($content) {
	$content = str_replace(["\r\n", "\r"], "\n", rtrim($content, "\n"));
	if ($content === '') return [];
	return preg_split("/\n\n+/", $content);
}

/**
 * Parse one .pot entry chunk (skips the empty msgid header block)
 *
 * @param string $chunk
 * @return array|null msgid, context, references[], comments[], pot
 */
function wrap_text_pot_parse_chunk($chunk) {
	if (!preg_match('/^msgid "(.*)"$/m', $chunk, $match)) return null;
	if ($match[1] === '') return null;

	$context = '';
	$msgid_plural = '';
	$plural_style = null;
	$references = [];
	$comments = [];
	foreach (explode("\n", $chunk) as $line) {
		if (wrap_text_pot_is_translator_comment($line))
			$comments[] = $line;
		if (str_starts_with($line, '#: '))
			$references[] = substr($line, 3);
		if (preg_match('/^msgctxt "(.*)"$/', $line, $context_match))
			$context = wrap_text_pot_unescape($context_match[1]);
		if (preg_match('/^msgid_plural "(.*)"$/', $line, $plural_match))
			$msgid_plural = wrap_text_pot_unescape($plural_match[1]);
		if ($line === 'msgstr[] ""' AND $plural_style === null)
			$plural_style = 'brackets';
		if (preg_match('/^msgstr\[0\] /', $line))
			$plural_style = 'indexed';
	}
	wrap_text_pot_sort_references($references);
	$entry = [
		'msgid' => wrap_text_pot_unescape($match[1]),
		'context' => $context,
		'references' => $references,
		'comments' => $comments,
		'pot' => '',
	];
	if ($msgid_plural !== '') {
		$entry['msgid_plural'] = $msgid_plural;
		$entry['plural_style'] = $plural_style ?? 'indexed';
	}
	return $entry;
}

/**
 * Whether a .pot line is a translator comment (# …, not #: #. #,)
 *
 * @param string $line
 * @return bool
 */
function wrap_text_pot_is_translator_comment($line) {
	if (!str_starts_with($line, '#')) return false;
	if ($line === '#') return true;
	return !in_array($line[1], [':', '.', ','], true);
}

/**
 * Compare key for a .pot entry (msgid + sorted references)
 *
 * @param array $entry
 * @return string
 */
function wrap_text_pot_entry_signature($entry) {
	$references = $entry['references'];
	wrap_text_pot_sort_references($references);
	return ($entry['context'] ?? '')."\0".$entry['msgid']."\0"
		.($entry['msgid_plural'] ?? '')."\0".implode("\0", $references);
}

/**
 * Unique key for a .pot entry (context + msgid)
 *
 * @param array $entry
 * @return string
 */
function wrap_text_pot_entry_key($entry) {
	return ($entry['context'] ?? '')."\0".$entry['msgid'];
}

/**
 * Lookup key for matching a scanned msgid to an existing msgid_plural
 *
 * @param array $entry
 * @return string
 */
function wrap_text_pot_plural_lookup_key($entry) {
	return ($entry['context'] ?? '')."\0".($entry['msgid_plural'] ?? $entry['msgid']);
}

/**
 * Render unified diff of two strings as HTML for the textupdate preview
 *
 * @param string $old
 * @param string $new
 * @return string
 */
function wrap_text_diff_html($old, $new) {
	if ($old === $new) return wrap_text_diff_html_lines($old, ' ');

	$lines = wrap_text_diff_lines($old, $new);
	if (!$lines) return wrap_text_diff_html_fallback($old, $new);

	$html = [];
	foreach ($lines as $line) {
		if (str_starts_with($line, '+++') OR str_starts_with($line, '---')) continue;
		if (str_starts_with($line, '@@')) continue;
		if (str_starts_with($line, '\\')) continue;
		if ($line === '') {
			$html[] = wrap_text_diff_html_line(' ', '');
			continue;
		}
		$prefix = $line[0];
		if ($prefix === '+' OR $prefix === '-')
			$html[] = wrap_text_diff_html_line($prefix, substr($line, 1));
		elseif ($prefix === ' ')
			$html[] = wrap_text_diff_html_line(' ', substr($line, 1));
		else
			$html[] = wrap_text_diff_html_line(' ', $line);
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
function wrap_text_diff_html_lines($content, $prefix) {
	if ($content === '') return wrap_text_diff_html_line($prefix, '');

	$lines = preg_split("/\r?\n/", $content);
	if (end($lines) === '') array_pop($lines);

	$html = [];
	foreach ($lines as $line)
		$html[] = wrap_text_diff_html_line($prefix, $line);
	if (!$html) return wrap_text_diff_html_line($prefix, '');
	return implode("\n", $html);
}

/**
 * Render one diff line as a coloured HTML span
 *
 * @param string $prefix +, -, or space (context)
 * @param string $text line text without diff prefix
 * @return string HTML
 */
function wrap_text_diff_html_line($prefix, $text) {
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
	return sprintf('<span class="pot-diff-line %s">%s</span>', $class, $escaped);
}

/**
 * Side-by-side diff fallback when diff(1) is unavailable
 *
 * @param string $old
 * @param string $new
 * @return string HTML
 */
function wrap_text_diff_html_fallback($old, $new) {
	if ($old === $new) return wrap_text_diff_html_lines($old, ' ');

	$html = [];
	foreach (wrap_text_diff_split_lines($old) as $line)
		$html[] = wrap_text_diff_html_line('-', $line);
	foreach (wrap_text_diff_split_lines($new) as $line)
		$html[] = wrap_text_diff_html_line('+', $line);
	if (!$html) return wrap_text_diff_html_line(' ', '');
	return implode("\n", $html);
}

/**
 * Split file content into lines, preserving internal blank lines
 *
 * @param string $content
 * @return array
 */
function wrap_text_diff_split_lines($content) {
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
function wrap_text_diff_lines($old, $new) {
	$tmp_dir = wrap_setting('tmp_dir');
	if (!$tmp_dir) return [];

	$old_file = tempnam($tmp_dir, 'pot-old-');
	$new_file = tempnam($tmp_dir, 'pot-new-');
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
