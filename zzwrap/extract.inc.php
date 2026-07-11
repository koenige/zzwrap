<?php 

/**
 * zzwrap
 * Extract translatable strings from package source files
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
 * Scans template text blocks, wrap_text() string literals, `_msg` and `_msg_dev`
 * assignments (including `$variable ?? 'fallback'`), brick_xhr_error() message
 * literals, translatable configuration fields (see configuration/cfg.cfg), and
 * configuration/*.tsv files with a Variables translate list. `_msg_dev` strings
 * are collected for the admin .pot. Each entry includes file references with line
 * references with line numbers where the scanner could determine them.
 *
 * @param string $package package folder name, or custom
 * @return array list of entries: msgid, context, references[], pot (translate_pot suffix)
 */
function wrap_text_sources($package) {
	wrap_include('pot', 'zzwrap');
	if (!$package) return [];

	$package_dir = wrap_package_folder($package);
	if (!$package_dir) return [];

	$entries = [];
	wrap_text_sources_scan($package_dir, $entries);

	$sources = array_values($entries);
	usort($sources, function ($left, $right) {
		$compare = strcmp($left['pot'], $right['pot']);
		if ($compare !== 0) return $compare;
		return wrap_pot_compare_entries($left, $right);
	});
	foreach ($sources as $index => $source) {
		wrap_pot_sort_references($source['references']);
		$sources[$index] = $source;
	}
	return $sources;
}

/**
 * Walk package files and merge translatable strings into $entries
 *
 * Dispatches per extension (.template.txt, .css, .js, .php, gated .cfg via
 * wrap_cfg_translate_fields(), configuration/*.tsv). PHP parsing follows multiline
 * wrap_text() calls and zz_error_validation_log() message arguments.
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
		} elseif (str_ends_with($relative_path, '.tsv')
			AND str_starts_with($relative_path, 'configuration/')) {
			$content = file_get_contents($file->getPathname());
			if ($content === false OR $content === '') continue;
			$content = str_replace(["\r\n", "\r"], "\n", $content);
			wrap_text_sources_scan_tsv($content, $relative_path, $entries);
			continue;
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
			wrap_text_sources_scan_msg_keys($content, $pot, $relative_path, $entries);
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
 * Scan a configuration/*.tsv file for translatable column values
 *
 * Opt-in via a header Variables block: `translate = col, …` (comma-separated
 * column names from the `#:` header line). Optional `translate_pot` selects
 * the .pot file (default: package .pot). Optional `translate_context = col:ctx`
 * pairs (comma-separated) set msgctxt from another column on the same row
 * (e.g. `abbr:label` for compass bearings). Skips comment and blank lines;
 * empty cells are ignored.
 * @param string $relative_path path relative to package folder
 * @param array $entries collected entries (by reference)
 * @return void
 */
function wrap_text_sources_scan_tsv($content, $relative_path, &$entries) {
	$variables = wrap_file_header_variables($content);
	if (empty($variables['translate'])) return;

	$columns = wrap_tsv_translate_columns($variables['translate']);
	if (!$columns) return;

	$context_columns = wrap_tsv_translate_context($variables['translate_context'] ?? '');
	$pot = wrap_text_sources_translate_pot($content);
	$head = [];
	foreach (explode("\n", $content) as $line_number => $line) {
		if (!trim($line)) continue;
		if (str_starts_with($line, '#:')) {
			$head = explode("\t", trim(substr($line, 2)));
			continue;
		}
		if (str_starts_with($line, '#')) continue;
		if (!$head) continue;

		$cells = explode("\t", rtrim($line, "\r"));
		$reference = sprintf('%s:%d', $relative_path, $line_number + 1);
		foreach ($columns as $column) {
			$index = array_search($column, $head, true);
			if ($index === false) continue;
			$msgid = trim($cells[$index] ?? '');
			if ($msgid === '') continue;
			$context = '';
			if (!empty($context_columns[$column])) {
				$context_spec = $context_columns[$column];
				$context_index = array_search($context_spec, $head, true);
				if ($context_index !== false)
					$context = trim($cells[$context_index] ?? '');
				else
					$context = $context_spec;
			}
			wrap_text_sources_add($entries, $msgid, $reference, $pot, $context);
		}
	}
}

/**
 * Scan `_msg` assignments in PHP source
 * Scan `_msg` and `_msg_dev` assignments in PHP source
 *
 * Matches `…['_msg'] = …`, `'_msg' => …`, `zz_error_validation_log('_msg', …)`,
 * and the same for `_msg_dev` (not reads like `$error['_msg']`). Extracts string
 * literals and dot-concatenated strings; array values yield one entry per string
 * element (bare variables are skipped). Also handles `'_msg' => $variable ?? 'fallback'`.
 * `_msg_dev` uses the admin translate_pot suffix.
 *
 * @param string $content PHP file contents with Unix line endings
 * @param string $pot translate_pot suffix for `_msg`
 * @param string $relative_path path relative to package folder
 * @param array $entries collected entries (by reference)
 * @return void
 */
function wrap_text_sources_scan_msg_keys($content, $pot, $relative_path, &$entries) {
	$sites = [];

	if (preg_match_all(
		"/\['(_msg(?:_dev)?)'\]\s*(?:\n\s*)?=|'(_msg(?:_dev)?)'\s*=>/",
		$content,
		$matches,
		PREG_OFFSET_CAPTURE
	)) {
		foreach ($matches[0] as $index => $match) {
			$sites[] = [
				'key' => $matches[1][$index][0] ?: $matches[2][$index][0],
				'pos' => $match[1] + strlen($match[0]),
			];
		}
	}

	if (preg_match_all(
		"/zz_error_validation_log\s*\(\s*'(_msg(?:_dev)?)'\s*,\s*/",
		$content,
		$matches,
		PREG_OFFSET_CAPTURE
	)) {
		foreach ($matches[0] as $index => $match) {
			$sites[] = [
				'key' => $matches[1][$index][0],
				'pos' => $match[1] + strlen($match[0]),
			];
		}
	}

	if (!$sites) return;

	foreach ($sites as $site) {
		$entry_pot = ($site['key'] === '_msg_dev') ? 'admin' : $pot;
		wrap_text_sources_msg_extract_at(
			$content, $site['pos'], $relative_path, $entry_pot, $entries
		);
	}
}

/**
 * Extract translatable `_msg` / `_msg_dev` value at byte offset
 *
 * @param string $content PHP file contents with Unix line endings
 * @param int $pos byte offset of the value
 * @param string $relative_path path relative to package folder
 * @param string $pot translate_pot suffix for this entry
 * @param array $entries collected entries (by reference)
 * @return void
 */
function wrap_text_sources_msg_extract_at($content, $pos, $relative_path, $pot, &$entries) {
	if (preg_match('/\G\s*/', $content, $whitespace, 0, $pos))
		$pos += strlen($whitespace[0]);
	if ($pos >= strlen($content)) return;

	if ($content[$pos] === '[') {
		foreach (wrap_text_sources_msg_array_literals($content, $pos) as $literal) {
			$reference = sprintf(
				'%s:%d',
				$relative_path,
				wrap_text_sources_line_number($content, $literal['offset'])
			);
			wrap_text_sources_add($entries, $literal['msgid'], $reference, $pot);
		}
		return;
	}

	$literals = wrap_text_sources_msg_value_literals($content, $pos);
	if (!$literals)
		$literals = wrap_text_sources_msg_null_coalesce_literals($content, $pos);
	if (!$literals) return;
	$msgid = implode('', array_column($literals, 'msgid'));
	if ($msgid === '') return;
	$reference = sprintf(
		'%s:%d',
		$relative_path,
		wrap_text_sources_line_number($content, $literals[0]['offset'])
	);
	wrap_text_sources_add($entries, $msgid, $reference, $pot);
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
 * `_msg` value after `$variable ??`: the fallback string literal(s)
 *
 * @param string $content
 * @param int $offset byte offset after `=>` or `=`
 * @return array list of entries with msgid, offset, and end keys
 */
function wrap_text_sources_msg_null_coalesce_literals($content, $offset) {
	if (!preg_match(
		'/\G\s*\$[a-zA-Z_][a-zA-Z0-9_]*(?:\[[^\]]+\])*/',
		$content,
		$variable,
		0,
		$offset
	)) return [];

	$pos = $offset + strlen($variable[0]);
	if (!preg_match('/\G\s*\?\?/', $content, $match, 0, $pos)) return [];

	$pos += strlen($match[0]);
	return wrap_text_sources_msg_value_literals($content, $pos);
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
	$tail = wrap_text_sources_code_context_tail($content, $offset);
	if ($tail === '') return '';
	if (!preg_match(
		"/['\"]context['\"]\\s*=>\\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")/"
		, $tail, $match
	)) return '';
	return wrap_text_sources_code($match[1]) ?? '';
}

/**
 * Remainder of the current wrap_text() call after the msgid string literal
 *
 * @param string $content PHP file contents
 * @param int $offset byte offset after the msgid string literal
 * @return string empty string when the call ends immediately
 */
function wrap_text_sources_code_context_tail($content, $offset) {
	$length = strlen($content);
	$pos = $offset;
	$depth = 1;
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
			$depth--;
			if ($depth === 0)
				return substr($content, $offset, $pos - $offset);
			$pos++;
			continue;
		}
		$pos++;
	}
	return substr($content, $offset, $pos - $offset);
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


/**
 * Source strings grouped by .pot file, sorted by first #: reference
 *
 * @param string $package
 * @return array keyed by translate_pot suffix (empty string key = default .pot)
 */
function wrap_text_sources_by_pot($package) {
	wrap_include('pot', 'zzwrap');
	$by_pot = [];
	foreach (wrap_text_sources($package) as $entry) {
		$by_pot[$entry['pot']][] = $entry;
	}
	foreach ($by_pot as $pot_suffix => $entries) {
		usort($entries, 'wrap_pot_compare_entries');
		$by_pot[$pot_suffix] = $entries;
	}
	ksort($by_pot);
	return $by_pot;
}

/**
 * Source strings not yet present in the corresponding .pot file(s)
 *
 * @param string $package
 * @return array keyed by translate_pot suffix (empty string key = default .pot)
 */
function wrap_text_sources_new($package) {
	wrap_include('pot', 'zzwrap');
	$new = [];
	foreach (wrap_text_sources($package) as $entry) {
		$pot_file = wrap_text_log_pot_file($package, $entry['pot']);
		if (array_key_exists(
			wrap_pot_entry_key($entry),
			wrap_pot_parse_entries(file_exists($pot_file) ? file_get_contents($pot_file) : '')
		))
			continue;
		$new[$entry['pot']][] = $entry;
	}
	return $new;
}
