<?php

/**
 * zzwrap
 * load compatibility functions for old PHP versions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024, 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


wrap_load_compatibility();

/**
 * Include version-specific compatibility polyfills for the running PHP version
 *
 * Loads files from ../compatibility/compatibility-pre-*.inc.php when the
 * current PHP version is below the threshold encoded in each filename.
 *
 * @return void
 */
function wrap_load_compatibility() {
	$directory = __DIR__ . '/../compatibility';
	if (!is_dir($directory)) return;

	$php_version_number = wrap_php_version_number(PHP_VERSION);
	$files = glob($directory . '/compatibility-pre-*.inc.php');
	if (!$files) return;

	sort($files, SORT_NATURAL);
	foreach ($files as $file) {
		if (!preg_match('/compatibility-pre-(\d+)\.inc\.php$/', basename($file), $matches)) continue;
		$threshold = wrap_compatibility_threshold_number($matches[1]);
		if ($php_version_number >= $threshold) continue;
		require_once $file;
	}
}

/**
 * Turn a PHP version string into a comparable integer (major * 100 + minor)
 *
 * @param string $version PHP version, e.g. PHP_VERSION ("8.4.1")
 * @return int e.g. 804 for 8.4.x; patch level is ignored
 */
function wrap_php_version_number($version) {
	$parts = explode('.', $version);
	return (int) $parts[0] * 100 + (int) ($parts[1] ?? 0);
}

/**
 * Parse the numeric threshold from a compatibility-pre-*.inc.php filename
 *
 * @param string $slug digits between "compatibility-pre-" and ".inc.php", e.g. "84"
 * @return int comparable version number, e.g. 804 for slug "84"
 */
function wrap_compatibility_threshold_number($slug) {
	if (strlen($slug) === 2) {
		return (int) $slug[0] * 100 + (int) $slug[1];
	}
	return (int) substr($slug, 0, -1) * 100 + (int) substr($slug, -1);
}
