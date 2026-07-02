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
 * Scans template text blocks, wrap_text() string literals, and translatable
 * configuration fields (see configuration/cfg.cfg). Each entry includes file
 * references with line numbers where the scanner could determine them.
 *
 * @param string $package package folder name, or custom
 * @return array list of entries: msgid, references[], pot (translate_pot suffix)
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
		return strcmp($left['msgid'], $right['msgid']);
	});
	foreach ($sources as $index => $source) {
		sort($source['references']);
		$sources[$index] = $source;
	}
	return $sources;
}

/**
 * Scan package files and collect translatable strings into $entries
 *
 * Handles .template.txt, .php, and .cfg files (cfg only when listed in
 * wrap_cfg_translate_fields()).
 *
 * @param string $package_dir absolute path to package folder
 * @param array $entries collected entries, keyed by pot + msgid (by reference)
 * @return void
 */
function wrap_text_sources_scan($package_dir, &$entries) {
	$handlers = [
		'template' => [
			'pattern' => '/%%% text (.+?) %%%/',
			'parse' => 'wrap_text_sources_template',
		],
		'code' => [
			'pattern' => '/wrap_text\s*\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(?=[\s,\)])/',
			'parse' => 'wrap_text_sources_code',
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
		} elseif (str_ends_with($relative_path, '.php')) {
			$handler = $handlers['code'];
		} else {
			continue;
		}

		$lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
		if (!$lines) continue;

		foreach ($lines as $line_number => $line) {
			$reference = sprintf('%s:%d', $relative_path, $line_number + 1);
			if (array_key_exists('pattern', $handler)) {
				$found = [];
				if (preg_match_all($handler['pattern'], $line, $matches)) {
					foreach ($matches[1] as $chunk) {
						$msgid = $handler['parse']($chunk);
						if ($msgid === null) continue;
						$found[] = ['msgid' => $msgid, 'pot' => ''];
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
 * Add or merge a source entry (dedupe by pot + msgid, merge references)
 *
 * @param array $entries
 * @param string $msgid
 * @param string $reference file path with line number
 * @param string $pot translate_pot suffix (empty string = default .pot)
 * @return void
 */
function wrap_text_sources_add(&$entries, $msgid, $reference, $pot = '') {
	if ($msgid === '') return;

	$key = $pot."\0".$msgid;
	if (!isset($entries[$key])) {
		$entries[$key] = [
			'msgid' => $msgid,
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
		$indexed = [];
		foreach ($entries as $entry) {
			$key = ($entry['references'][0] ?? '')."\0".$entry['msgid'];
			$indexed[$key] = $entry;
		}
		ksort($indexed);
		$by_pot[$pot_suffix] = array_values($indexed);
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
 * @param string|null $creation_date POT-Creation-Date value, or null for now (UTC)
 * @return array
 */
function wrap_text_pot_header_data($package, $pot_suffix = '', $creation_date = null) {
	$data = [
		'package' => $package,
		'pot_suffix' => $pot_suffix,
		'package_type' => '',
		'package_label' => $package,
		'creation_date' => $creation_date ?? gmdate('Y-m-d H:i').'+0000',
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
 * @param string $old_content existing .pot file contents, for preserving POT-Creation-Date
 * @return string
 */
function wrap_text_pot_build($package, $pot_suffix, array $entries, $old_content = '') {
	$content = rtrim(wrap_text_pot_header(
		$package,
		$pot_suffix,
		wrap_text_pot_creation_date($old_content)
	));
	$body = wrap_text_format_pot_chunks($entries);
	$content .= $body ? "\n\n".$body."\n" : "\n";
	return wrap_text_pot_normalize($content);
}

/**
 * POT-Creation-Date from an existing .pot header, if set
 *
 * @param string $content .pot file contents
 * @return string|null
 */
function wrap_text_pot_creation_date($content) {
	if ($content === '') return null;
	if (!preg_match('/"POT-Creation-Date: (.*?)\\\\n"/', $content, $match)) return null;
	if ($match[1] === '') return null;
	return $match[1];
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
		$entries = $sources_by_pot[$pot_suffix] ?? [];
		$pot_file = wrap_text_log_pot_file($package, $pot_suffix);
		$old = file_exists($pot_file) ? file_get_contents($pot_file) : '';

		if (!$entries AND $old === '') continue;
		if (!$entries AND !wrap_text_pot_parse_entries($old)) continue;

		$items[] = [
			'pot_suffix' => $pot_suffix,
			'pot_file' => $pot_file,
			'filename' => basename($pot_file),
			'entries' => $entries,
			'old' => $old,
			'new' => wrap_text_pot_build($package, $pot_suffix, $entries, $old),
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
		if (wrap_text_pot_normalize($pot['old']) === $pot['new']) continue;

		if (file_put_contents($pot['pot_file'], $pot['new']) === false) {
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
		wrap_text_pot_normalize($old_content),
		wrap_text_pot_normalize($new_content)
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
		$new[wrap_text_pot_entry_signature($entry)] = true;

	foreach ($new_entries as $entry) {
		$msgid = $entry['msgid'];
		if (!isset($old[$msgid]))
			$stats['added']++;
		elseif ($old[$msgid] !== wrap_text_pot_entry_signature($entry))
			$stats['updated']++;
		else
			$stats['unchanged']++;
	}
	foreach ($old as $msgid => $signature) {
		$found = false;
		foreach ($new_entries as $entry) {
			if ($entry['msgid'] !== $msgid) continue;
			$found = true;
			break;
		}
		if (!$found) $stats['deleted']++;
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
		if (array_key_exists($entry['msgid'], wrap_text_pot_parse_entries(file_exists($pot_file) ? file_get_contents($pot_file) : '')))
			continue;
		$new[$entry['pot']][] = $entry;
	}
	return $new;
}

/**
 * Parse .pot entry bodies keyed by msgid
 *
 * @param string $content .pot file contents
 * @return array msgid => signature (msgid + sorted references)
 */
function wrap_text_pot_parse_entries($content) {
	$entries = [];
	foreach (wrap_text_pot_parse_chunks($content) as $chunk) {
		$entry = wrap_text_pot_parse_chunk($chunk);
		if (!$entry) continue;
		$entries[$entry['msgid']] = wrap_text_pot_entry_signature($entry);
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
		foreach ($entry['references'] as $reference)
			$lines[] = '#: '.$reference;
		$lines[] = 'msgid "'.wrap_text_pot_escape($entry['msgid']).'"';
		$lines[] = 'msgstr ""';
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
 * @return array|null msgid, references[], pot
 */
function wrap_text_pot_parse_chunk($chunk) {
	if (!preg_match('/^msgid "(.*)"$/m', $chunk, $match)) return null;
	if ($match[1] === '') return null;

	$references = [];
	foreach (explode("\n", $chunk) as $line) {
		if (str_starts_with($line, '#: '))
			$references[] = substr($line, 3);
	}
	sort($references);
	return [
		'msgid' => wrap_text_pot_unescape($match[1]),
		'references' => $references,
		'pot' => '',
	];
}

/**
 * Compare key for a .pot entry (msgid + sorted references)
 *
 * @param array $entry
 * @return string
 */
function wrap_text_pot_entry_signature($entry) {
	$references = $entry['references'];
	sort($references);
	return $entry['msgid']."\0".implode("\0", $references);
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
