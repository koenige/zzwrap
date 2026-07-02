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
 * Source strings not yet present in the corresponding .pot file(s)
 *
 * @param string $package
 * @return array keyed by translate_pot suffix (empty string key = default .pot)
 */
function wrap_text_sources_new($package) {
	$new = [];
	foreach (wrap_text_sources($package) as $entry) {
		$pot_file = wrap_text_log_pot_file($package, $entry['pot']);
		if (in_array($entry['msgid'], wrap_text_pot_msgids($pot_file), true)) continue;
		$new[$entry['pot']][] = $entry;
	}
	return $new;
}

/**
 * msgid values already present in a .pot file
 *
 * @param string $pot_file
 * @return array
 */
function wrap_text_pot_msgids($pot_file) {
	if (!file_exists($pot_file)) return [];

	$msgids = [];
	$lines = file($pot_file, FILE_IGNORE_NEW_LINES);
	if (!$lines) return [];

	foreach ($lines as $line) {
		if (!str_starts_with($line, 'msgid ')) continue;
		if (!preg_match('/^msgid "(.*)"$/', $line, $match)) continue;
		if ($match[1] === '') continue;
		$msgids[] = wrap_text_pot_unescape($match[1]);
	}
	return $msgids;
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
