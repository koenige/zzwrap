<?php 

/**
 * zzwrap
 * .pot file infrastructure: building, parsing, merging, diffing, writing
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * gettext .pot header from pot.template.txt
 *
 * @param string $package
 * @param string $pot_suffix
 * @return string
 */
function wrap_pot_header($package, $pot_suffix = '', $creation_date = null) {
	return wrap_template('pot', wrap_pot_header_data($package, $pot_suffix, $creation_date));
}

/**
 * Data for pot.template.txt
 *
 * @param string $package
 * @param string $pot_suffix translate_pot suffix (empty = default .pot)
 * @param string|null $creation_date POT-Creation-Date value, or empty for preview builds
 * @return array
 */
function wrap_pot_header_data($package, $pot_suffix = '', $creation_date = null) {
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
 * @param array $entries wrap_extract() entries
 * @param string $creation_date POT-Creation-Date header value (empty for preview)
 * @return string
 */
function wrap_pot_build($package, $pot_suffix, array $entries, $creation_date = '') {
	$content = rtrim(wrap_pot_header(
		$package,
		$pot_suffix,
		$creation_date
	));
	$body = wrap_pot_format_chunks($entries);
	$content .= $body ? "\n\n".$body."\n" : "\n";
	return wrap_pot_normalize($content);
}

/**
 * Merge scanned entries with an existing .pot file
 *
 * Keeps old entries whose references have no line number. Scanned entries
 * replace line-less references in the same file when context and msgid match.
 *
 * @param array $scanned wrap_extract() entries for one .pot file
 * @param string $old_content existing .pot file contents
 * @return array merged entries
 */
function wrap_pot_merge_entries(array $scanned, $old_content) {
	$old_entries = wrap_pot_parse_entry_list($old_content);
	$old_by_key = [];
	$old_by_plural = [];
	foreach ($old_entries as $old) {
		$old_by_key[wrap_pot_entry_key($old)] = $old;
		if (!empty($old['msgid_plural']))
			$old_by_plural[wrap_pot_plural_lookup_key($old)] = $old;
	}

	$merged = [];
	foreach ($scanned as $entry) {
		$key = wrap_pot_entry_key($entry);
		if (isset($old_by_key[$key])) {
			$merged[$key] = $entry;
			wrap_pot_merge_entry_references($merged[$key], $old_by_key[$key]);
			wrap_pot_merge_entry_comments($merged[$key], $old_by_key[$key]);
			wrap_pot_merge_entry_plural($merged[$key], $old_by_key[$key]);
			continue;
		}
		$plural_key = wrap_pot_plural_lookup_key($entry);
		if (isset($old_by_plural[$plural_key])) {
			$old = $old_by_plural[$plural_key];
			$old_key = wrap_pot_entry_key($old);
			$merged[$old_key] = $old;
			$merged[$old_key]['references'] = $entry['references'];
			wrap_pot_merge_entry_references($merged[$old_key], $old);
			continue;
		}
		$merged[$key] = $entry;
	}

	foreach ($old_entries as $old) {
		$key = wrap_pot_entry_key($old);
		if (isset($merged[$key])) continue;
		if (!wrap_pot_entry_has_lineless_reference($old)) continue;
		$merged[$key] = $old;
	}

	return wrap_pot_sort_entries(array_values($merged));
}

/**
 * Sort .pot entries by first reference (file, line number) then msgid
 *
 * @param array $entries
 * @return array
 */
function wrap_pot_sort_entries(array $entries) {
	usort($entries, 'wrap_pot_compare_entries');
	return $entries;
}

/**
 * Keep line-less references from an old entry when merging with a scan match
 *
 * @param array $scan_entry merged entry from scan (by reference)
 * @param array $old_entry entry parsed from existing .pot file
 * @return void
 */
function wrap_pot_merge_entry_references(array &$scan_entry, array $old_entry) {
	$scan_files = [];
	foreach ($scan_entry['references'] as $reference)
		$scan_files[wrap_pot_reference_file($reference)] = true;

	foreach ($old_entry['references'] as $reference) {
		if (wrap_pot_reference_has_line($reference)) continue;
		$file = wrap_pot_reference_file($reference);
		if (!empty($scan_files[$file])) continue;
		if (!in_array($reference, $scan_entry['references'], true))
			$scan_entry['references'][] = $reference;
	}
	wrap_pot_sort_references($scan_entry['references']);
}

/**
 * Keep translator comments from an old entry when merging with a scan match
 *
 * @param array $scan_entry merged entry from scan (by reference)
 * @param array $old_entry entry parsed from existing .pot file
 * @return void
 */
function wrap_pot_merge_entry_comments(array &$scan_entry, array $old_entry) {
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
function wrap_pot_merge_entry_plural(array &$scan_entry, array $old_entry) {
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
function wrap_pot_entry_has_lineless_reference($entry) {
	foreach ($entry['references'] as $reference) {
		if (!wrap_pot_reference_has_line($reference)) return true;
	}
	return false;
}

/**
 * Whether a #: reference includes a line number suffix
 *
 * @param string $reference path with optional :line suffix
 * @return bool
 */
function wrap_pot_reference_has_line($reference) {
	return (bool) preg_match('/:\d+$/', $reference);
}

/**
 * File path from a #: reference, without the line number suffix
 *
 * @param string $reference path with optional :line suffix
 * @return string
 */
function wrap_pot_reference_file($reference) {
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
function wrap_pot_compare_references($left, $right) {
	$left_file = wrap_pot_reference_file($left);
	$right_file = wrap_pot_reference_file($right);
	$compare = wrap_pot_compare_reference_paths($left_file, $right_file);
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
function wrap_pot_compare_reference_paths($left, $right) {
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
function wrap_pot_sort_references(array &$references) {
	usort($references, 'wrap_pot_compare_references');
}

/**
 * Compare two .pot entries for sort order (first reference, then msgid)
 *
 * @param array $left
 * @param array $right
 * @return int -1, 0, or 1
 */
function wrap_pot_compare_entries($left, $right) {
	$compare = wrap_pot_compare_references(
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
function wrap_pot_items($package) {
	$items = [];
	$sources_by_pot = wrap_pot_extract($package);

	foreach (wrap_pot_suffixes($package) as $pot_suffix) {
		$scanned = $sources_by_pot[$pot_suffix] ?? [];
		$pot_file = wrap_text_pot_file($package, $pot_suffix);
		$old = file_exists($pot_file) ? file_get_contents($pot_file) : '';

		$entries = wrap_pot_merge_entries($scanned, $old);
		if (!$entries AND $old === '') continue;
		if (!$entries AND !wrap_pot_parse_entries($old)) continue;

		$items[] = [
			'pot_suffix' => $pot_suffix,
			'pot_file' => $pot_file,
			'filename' => basename($pot_file),
			'entries' => $entries,
			'old' => $old,
			'new' => wrap_pot_build($package, $pot_suffix, $entries),
		];
	}
	return $items;
}

/**
 * Source strings grouped by .pot file, sorted by first #: reference
 *
 * @param string $package
 * @return array keyed by translate_pot suffix (empty string key = default .pot)
 */
function wrap_pot_extract($package) {
	static $cache = [];
	if (array_key_exists($package, $cache)) return $cache[$package];

	wrap_include('extract', 'zzwrap');
	$by_pot = [];
	foreach (wrap_extract($package) as $entry) {
		$by_pot[$entry['pot']][] = $entry;
	}
	foreach ($by_pot as $pot_suffix => $entries) {
		usort($entries, 'wrap_pot_compare_entries');
		$by_pot[$pot_suffix] = $entries;
	}
	ksort($by_pot);
	$cache[$package] = $by_pot;
	return $by_pot;
}

/**
 * Write scanned .pot content to disk
 *
 * @param string $package
 * @return array ok (bool), message (string), written (string[] filenames)
 */
function wrap_pot_write($package) {
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
	foreach (wrap_pot_items($package) as $pot) {
		if (wrap_pot_normalize_for_diff($pot['old']) === wrap_pot_normalize_for_diff($pot['new']))
			continue;

		$new = wrap_pot_build(
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
		'message' => wrap_text('%d .pot files written.', ['values' => [count($written)]]),
		'written' => $written,
	];
}

/**
 * translate_pot suffixes from source scan and existing .pot files on disk
 *
 * @param string $package
 * @return array list of suffixes (empty string = default .pot)
 */
function wrap_pot_suffixes($package) {
	$suffixes = [];
	foreach (array_keys(wrap_pot_extract($package)) as $pot_suffix)
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
 * HTML diff of two complete .pot files (existing vs wrap_pot_build output)
 *
 * @param string $old_content existing .pot file contents, or empty string
 * @param string $new_content full .pot content from wrap_pot_build()
 * @return string HTML for .pot-diff container
 */
function wrap_pot_diff_html($old_content, $new_content) {
	wrap_include('diff', 'zzwrap');
	return wrap_diff(
		wrap_pot_normalize_for_diff($old_content),
		wrap_pot_normalize_for_diff($new_content)
	);
}

/**
 * Count added, deleted, and updated entries between old .pot and scan
 *
 * @param string $old_content
 * @param array $new_entries
 * @return array added, deleted, updated, unchanged (int)
 */
function wrap_pot_diff_stats($old_content, array $new_entries) {
	$stats = ['added' => 0, 'deleted' => 0, 'updated' => 0, 'unchanged' => 0];
	$old = wrap_pot_parse_entries($old_content);
	$new = [];
	foreach ($new_entries as $entry)
		$new[wrap_pot_entry_key($entry)] = wrap_pot_entry_signature($entry);

	foreach ($new_entries as $entry) {
		$key = wrap_pot_entry_key($entry);
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
 * Parse .pot entry bodies keyed by context + msgid
 *
 * @param string $content .pot file contents
 * @return array entry key => signature (context + msgid + sorted references)
 */
function wrap_pot_parse_entries($content) {
	$entries = [];
	foreach (wrap_pot_parse_entry_list($content) as $entry)
		$entries[wrap_pot_entry_key($entry)] = wrap_pot_entry_signature($entry);
	return $entries;
}

/**
 * Parse .pot entry bodies as a list
 *
 * @param string $content .pot file contents
 * @return array list of entries: msgid, context, references[], comments[], pot
 */
function wrap_pot_parse_entry_list($content) {
	$entries = [];
	foreach (wrap_pot_parse_chunks($content) as $chunk) {
		$entry = wrap_pot_parse_chunk($chunk);
		if (!$entry) continue;
		$entries[] = $entry;
	}
	return $entries;
}

/**
 * Format translation entries as gettext .pot chunks
 *
 * @param array $entries wrap_extract() entries
 * @return string
 */
function wrap_pot_format_chunks(array $entries) {
	$chunks = [];
	foreach ($entries as $entry) {
		$lines = [];
		foreach ($entry['comments'] ?? [] as $comment)
			$lines[] = $comment;
		foreach ($entry['references'] as $reference)
			$lines[] = '#: '.$reference;
		if (!empty($entry['context']))
			$lines[] = 'msgctxt "'.wrap_pot_escape($entry['context']).'"';
		$lines[] = 'msgid "'.wrap_pot_escape($entry['msgid']).'"';
		if (!empty($entry['msgid_plural'])) {
			$lines[] = 'msgid_plural "'.wrap_pot_escape($entry['msgid_plural']).'"';
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
function wrap_pot_escape($string) {
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
function wrap_pot_unescape($string) {
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
function wrap_pot_normalize($content) {
	if ($content === '') return '';
	$content = str_replace(["\r\n", "\r"], "\n", $content);
	$content = wrap_pot_strip_trailing_spaces($content);
	return rtrim($content, "\n")."\n";
}

/**
 * Normalize .pot content for diff and write comparison (ignores POT-Creation-Date)
 *
 * @param string $content
 * @return string
 */
function wrap_pot_normalize_for_diff($content) {
	$content = wrap_pot_normalize($content);
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
function wrap_pot_strip_trailing_spaces($content) {
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
function wrap_pot_parse_chunks($content) {
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
function wrap_pot_parse_chunk($chunk) {
	if (!preg_match('/^msgid "(.*)"$/m', $chunk, $match)) return null;
	if ($match[1] === '') return null;

	$context = '';
	$msgid_plural = '';
	$plural_style = null;
	$references = [];
	$comments = [];
	foreach (explode("\n", $chunk) as $line) {
		if (wrap_pot_is_translator_comment($line))
			$comments[] = $line;
		if (str_starts_with($line, '#: '))
			$references[] = substr($line, 3);
		if (preg_match('/^msgctxt "(.*)"$/', $line, $context_match))
			$context = wrap_pot_unescape($context_match[1]);
		if (preg_match('/^msgid_plural "(.*)"$/', $line, $plural_match))
			$msgid_plural = wrap_pot_unescape($plural_match[1]);
		if ($line === 'msgstr[] ""' AND $plural_style === null)
			$plural_style = 'brackets';
		if (preg_match('/^msgstr\[0\] /', $line))
			$plural_style = 'indexed';
	}
	wrap_pot_sort_references($references);
	$entry = [
		'msgid' => wrap_pot_unescape($match[1]),
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
function wrap_pot_is_translator_comment($line) {
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
function wrap_pot_entry_signature($entry) {
	$references = $entry['references'];
	wrap_pot_sort_references($references);
	return ($entry['context'] ?? '')."\0".$entry['msgid']."\0"
		.($entry['msgid_plural'] ?? '')."\0".implode("\0", $references);
}

/**
 * Unique key for a .pot entry (context + msgid)
 *
 * @param array $entry
 * @return string
 */
function wrap_pot_entry_key($entry) {
	return ($entry['context'] ?? '')."\0".$entry['msgid'];
}

/**
 * Lookup key for matching a scanned msgid to an existing msgid_plural
 *
 * @param array $entry
 * @return string
 */
function wrap_pot_plural_lookup_key($entry) {
	return ($entry['context'] ?? '')."\0".($entry['msgid_plural'] ?? $entry['msgid']);
}
