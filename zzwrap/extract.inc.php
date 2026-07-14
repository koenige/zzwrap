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
 * Loads all package extract handlers via wrap_include(), walks the package
 * directory, and dispatches matching files to registered scan functions.
 * Each entry includes file references with line numbers where the scanner
 * could determine them.
 *
 * @param string $package package folder name, or custom
 * @return array list of entries: msgid, context, references[], pot (translate_pot suffix)
 */
function wrap_extract($package) {
	if (!$package) return [];

	$package_dir = wrap_package_folder($package);
	if (!$package_dir) return [];

	$entries = [];
	wrap_extract_scan($package_dir, $entries);

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
 * Walk package files and dispatch to registered extract handlers
 *
 * Discovers handlers from all packages via *_extract_register() functions,
 * then walks the file tree and runs all matching handlers per file (non-exclusive).
 *
 * @param string $package_dir absolute path to package folder
 * @param array $entries collected entries, keyed by pot + msgid (by reference)
 * @return void
 */
function wrap_extract_scan($package_dir, &$entries) {
	wrap_include('file', 'zzwrap');
	$handlers = wrap_extract_handlers();

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($package_dir, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($iterator as $file) {
		if (!$file->isFile()) continue;

		$relative_path = substr($file->getPathname(), strlen($package_dir) + 1);
		if (str_starts_with($relative_path, 'languages/')) continue;

		$matching = wrap_extract_match_handlers($handlers, $relative_path);
		if (!$matching) continue;

		$content = file_get_contents($file->getPathname());
		if ($content === false OR $content === '') continue;
		$content = str_replace(["\r\n", "\r"], "\n", $content);

		foreach ($matching as $handler) {
			$handler['scan']($content, $relative_path, $entries);
		}
	}
}

/**
 * Collect handlers from all packages' *_extract_register() functions
 *
 * Since this file is already loaded when wrap_extract_handlers() runs,
 * include_once won't re-execute it and wrap_functions() cannot discover
 * wrap_extract_register(). Therefore zzwrap's own handlers are added
 * directly, and wrap_include() discovers the remaining packages.
 *
 * @return array list of handler definitions with match and scan keys
 */
function wrap_extract_handlers() {
	static $handlers = null;
	if ($handlers !== null) return $handlers;

	$handlers = [];
	$files = wrap_include('zzwrap/extract');
	$register_functions = wrap_functions($files, 'extract_register');

	foreach ($register_functions as $register) {
		$result = $register['function']();
		if (!is_array($result)) continue;
		wrap_extract_handlers_add($handlers, $result);
	}
	// this file is already loaded, so wrap_functions cannot discover it
	wrap_extract_handlers_add($handlers, wrap_extract_register());
	return $handlers;
}

/**
 * Normalize and append handler definitions
 *
 * @param array $handlers collected handlers (by reference)
 * @param array $definitions handler definitions from a register function
 * @return void
 */
function wrap_extract_handlers_add(&$handlers, $definitions) {
	foreach ($definitions as $handler) {
		if (empty($handler['match']) OR empty($handler['scan'])) continue;
		if (!is_array($handler['match']))
			$handler['match'] = [$handler['match']];
		$handlers[] = $handler;
	}
}

/**
 * Find all handlers whose match patterns apply to a relative path
 *
 * @param array $handlers registered handlers
 * @param string $relative_path file path relative to package folder
 * @return array matching handlers
 */
function wrap_extract_match_handlers($handlers, $relative_path) {
	$matching = [];
	foreach ($handlers as $handler) {
		foreach ($handler['match'] as $pattern) {
			if (fnmatch($pattern, $relative_path)) {
				$matching[] = $handler;
				break;
			}
		}
	}
	return $matching;
}

/**
 * Register zzwrap's own extract handlers
 *
 * @return array list of handler definitions
 */
function wrap_extract_register() {
	return [
		[
			'match' => '*.php',
			'scan' => 'wrap_extract_scan_php',
		],
		[
			'match' => '*.cfg',
			'scan' => 'wrap_extract_scan_cfg_file',
		],
		[
			'match' => 'configuration/*.tsv',
			'scan' => 'wrap_extract_scan_tsv',
		],
	];
}

/**
 * Scan a PHP file for wrap_text() calls and _msg/_msg_dev assignments
 *
 * @param string $content file contents with Unix line endings
 * @param string $relative_path path relative to package folder
 * @param array $entries collected entries (by reference)
 * @return void
 */
function wrap_extract_scan_php($content, $relative_path, &$entries) {
	$pot = wrap_extract_translate_pot($content);

	if (preg_match_all(
		'/wrap_text\s*\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(?=[\s,\)])/',
		$content, $matches, PREG_OFFSET_CAPTURE
	)) {
		foreach ($matches[1] as $match) {
			$msgid = wrap_extract_code($match[0]);
			if ($msgid === null) continue;
			$context = wrap_extract_code_context(
				$content, $match[1] + strlen($match[0])
			);
			$reference = sprintf(
				'%s:%d', $relative_path,
				wrap_extract_line_number($content, $match[1])
			);
			wrap_extract_add($entries, $msgid, $reference, $pot, $context);
		}
	}

	wrap_extract_scan_msg_keys($content, $pot, $relative_path, $entries);
}

/**
 * Scan a .cfg file for translatable field values
 *
 * Only processes files whose basename is registered in cfg.cfg with
 * translate = 1. Skips files that have no translatable fields.
 *
 * @param string $content file contents with Unix line endings
 * @param string $relative_path path relative to package folder
 * @param array $entries collected entries (by reference)
 * @return void
 */
function wrap_extract_scan_cfg_file($content, $relative_path, &$entries) {
	$cfg_file = basename($relative_path);
	$translate_fields = wrap_cfg_translate_fields();
	if (empty($translate_fields[$cfg_file])) return;

	$fields = $translate_fields[$cfg_file];
	$pot = wrap_extract_translate_pot($content);
	$lines = explode("\n", $content);

	foreach ($lines as $line_number => $line) {
		$found = wrap_extract_cfg($line, $fields);
		if (!$found) continue;
		$reference = sprintf('%s:%d', $relative_path, $line_number + 1);
		foreach ($found as $entry) {
			wrap_extract_add($entries, $entry['msgid'], $reference, $entry['pot'] ?: $pot);
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
 *
 * @param string $content file contents with Unix line endings
 * @param string $relative_path path relative to package folder
 * @param array $entries collected entries (by reference)
 * @return void
 */
function wrap_extract_scan_tsv($content, $relative_path, &$entries) {
	$variables = wrap_file_header_variables($content);
	if (empty($variables['translate'])) return;

	$columns = wrap_tsv_translate_columns($variables['translate']);
	if (!$columns) return;

	$context_columns = wrap_tsv_translate_context($variables['translate_context'] ?? '');
	$pot = wrap_extract_translate_pot($content);
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
			wrap_extract_add($entries, $msgid, $reference, $pot, $context);
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
function wrap_extract_scan_msg_keys($content, $pot, $relative_path, &$entries) {
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
		wrap_extract_msg_extract_at(
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
function wrap_extract_msg_extract_at($content, $pos, $relative_path, $pot, &$entries) {
	if (preg_match('/\G\s*/', $content, $whitespace, 0, $pos))
		$pos += strlen($whitespace[0]);
	if ($pos >= strlen($content)) return;

	if ($content[$pos] === '[') {
		foreach (wrap_extract_msg_array_literals($content, $pos) as $literal) {
			$reference = sprintf(
				'%s:%d',
				$relative_path,
				wrap_extract_line_number($content, $literal['offset'])
			);
			wrap_extract_add($entries, $literal['msgid'], $reference, $pot);
		}
		return;
	}

	$literals = wrap_extract_msg_value_literals($content, $pos);
	if (!$literals)
		$literals = wrap_extract_msg_null_coalesce_literals($content, $pos);
	if (!$literals) return;
	$msgid = implode('', array_column($literals, 'msgid'));
	if ($msgid === '') return;
	$reference = sprintf(
		'%s:%d',
		$relative_path,
		wrap_extract_line_number($content, $literals[0]['offset'])
	);
	wrap_extract_add($entries, $msgid, $reference, $pot);
}

/**
 * String literals from a `_msg` array value
 *
 * @param string $content
 * @param int $start byte offset of opening `[`
 * @return array list of entries with msgid and offset keys
 */
function wrap_extract_msg_array_literals($content, $start) {
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

		$literals = wrap_extract_msg_value_literals($content, $pos);
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
		$pos = wrap_extract_msg_skip_array_element($content, $pos);
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
function wrap_extract_msg_null_coalesce_literals($content, $offset) {
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
	return wrap_extract_msg_value_literals($content, $pos);
}

/**
 * One `_msg` value: a string literal or dot-concatenated string literals
 *
 * @param string $content
 * @param int $offset
 * @return array list of entries with msgid, offset, and end keys
 */
function wrap_extract_msg_value_literals($content, $offset) {
	$literals = [];
	$pos = $offset;
	while (true) {
		$literal = wrap_extract_string_literal_at($content, $pos);
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
function wrap_extract_string_literal_at($content, $offset) {
	if (!preg_match(
		'/\G\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/',
		$content,
		$match,
		0,
		$offset
	)) return null;

	$quoted = $match[1];
	$literal_start = $offset + strpos($match[0], $quoted[0]);
	$msgid = wrap_extract_msg_literal($quoted);
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
function wrap_extract_msg_skip_array_element($content, $offset) {
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
function wrap_extract_translate_pot($content) {
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
function wrap_extract_line_number($content, $offset) {
	return substr_count(substr($content, 0, $offset), "\n") + 1;
}

/**
 * Build msgid from a wrap_text() string literal
 *
 * @param string $chunk quoted string including delimiters
 * @return string|null
 */
function wrap_extract_code($chunk) {
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
function wrap_extract_msg_literal($chunk) {
	$msgid = wrap_extract_code($chunk);
	if ($msgid === null OR $msgid === '') return null;
	if ($msgid[0] === '_') return null;
	return $msgid;
}

/**
 * gettext msgctxt from the wrap_text() params array, if any
 *
 * @param string $content PHP file contents
 * @param int $offset byte offset after the msgid string literal
 * @return string empty string when no context param
 */
function wrap_extract_code_context($content, $offset) {
	$tail = wrap_extract_code_context_tail($content, $offset);
	if ($tail === '') return '';
	if (!preg_match(
		"/['\"]context['\"]\\s*=>\\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")/"
		, $tail, $match
	)) return '';
	return wrap_extract_code($match[1]) ?? '';
}

/**
 * Remainder of the current wrap_text() call after the msgid string literal
 *
 * @param string $content PHP file contents
 * @param int $offset byte offset after the msgid string literal
 * @return string empty string when the call ends immediately
 */
function wrap_extract_code_context_tail($content, $offset) {
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
function wrap_extract_cfg($line, $fields) {
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
function wrap_extract_add(&$entries, $msgid, $reference, $pot = '', $context = '') {
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
 * Detect a wrap_text-style array: ['msgid', ['context' => '...']]
 *
 * @param string $content file contents
 * @param int $offset byte offset of opening [
 * @return array|null entry with msgid, context, offset keys; null if not matching
 */
function wrap_extract_text_array($content, $offset) {
	$length = strlen($content);
	if ($offset >= $length OR $content[$offset] !== '[') return null;

	$pos = $offset + 1;
	if (preg_match('/\G\s*/', $content, $ws, 0, $pos))
		$pos += strlen($ws[0]);

	// first element must be a string literal
	$char = $content[$pos] ?? '';
	if ($char !== '\'' AND $char !== '"') return null;
	$msgid_offset = $pos;
	if ($char === '\'')
		$ok = preg_match('/\G\'(?:[^\'\\\\]|\\\\.)*\'/', $content, $m, 0, $pos);
	else
		$ok = preg_match('/\G"(?:[^"\\\\]|\\\\.)*"/', $content, $m, 0, $pos);
	if (!$ok) return null;
	$msgid = wrap_extract_code($m[0]);
	if ($msgid === null) return null;
	$pos += strlen($m[0]);

	// expect comma then nested array
	if (!preg_match('/\G\s*,\s*/', $content, $ws, 0, $pos)) return null;
	$pos += strlen($ws[0]);
	if ($pos >= $length OR $content[$pos] !== '[') return null;

	// look for 'context' => 'value' inside the nested array
	$end = wrap_extract_msg_skip_array_element($content, $pos);
	$inner = substr($content, $pos, $end - $pos + 1);
	if (!preg_match(
		"/['\"]context['\"]\\s*=>\\s*('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")/",
		$inner, $match
	)) return null;

	$context = wrap_extract_code($match[1]);
	if ($context === null) return null;

	return [
		'msgid' => $msgid,
		'context' => $context,
		'offset' => $msgid_offset,
	];
}

/**
 * Log an extraction warning (e.g. wrap_text() on a translatable key)
 *
 * Warnings are stored in wrap_static('zzwrap', 'extract_warnings') and can
 * be retrieved after extraction completes, e.g. for display in textupdate.
 *
 * @param string $file relative path within package
 * @param int $line line number
 * @param string $message warning description
 * @return void
 */
function wrap_extract_warning($file, $line, $message) {
	static $warnings = [];
	$warnings[] = [
		'file' => $file,
		'line' => $line,
		'message' => $message,
	];
	wrap_static('zzwrap', 'extract_warnings', $warnings);
}

/**
 * Retrieve extraction warnings logged during the current scan
 *
 * @return array list of warning arrays with file, line, message keys
 */
function wrap_extract_warnings() {
	return wrap_static('zzwrap', 'extract_warnings') ?: [];
}
