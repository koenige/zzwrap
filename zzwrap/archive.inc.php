<?php 

/**
 * zzwrap
 * Archive functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * gzip a file, remove existing file on success
 *
 * @param string $path
 * @return bool
 */
function wrap_gzip($path) {
	$gzip_path = $path.'.gz';
	if (!strstr(ini_get('disable_functions'), 'exec')) {
		// gzip preserves timestamp
		$command = sprintf('gzip -N -9 %s %s', $path, $gzip_path);
		exec($command);
		return file_exists($gzip_path) ? true : false;
	}

	$time = filemtime($path);
	copy($path, 'compress.zlib://'.$gzip_path);
	if (!file_exists($gzip_path)) return false;
	touch($gzip_path, $time);
	unlink($path);
	return true;
}
