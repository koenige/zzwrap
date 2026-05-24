<?php

/**
 * zzwrap
 * Keyed file cache (TTL + advisory lock + stamp file)
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * run at most once per request per $key: skip if cache fresh, else write under lock
 *
 * @param string $key prefix for wrap_setting($key.'_file'), $key.'_cache_seconds', tmp_dir/$key.flock
 * @param callable $callback invoked as $callback(); return non-empty string for wrap_filecache_put(), or false to skip
 * @return bool false if flock/open failed; true if skipped (fresh, already done, or write finished)
 */
function wrap_filecache($key, callable $callback) {
	static $done = [];
	if (!empty($done[$key])) return true;
	$done[$key] = true;

	if (wrap_filecache_fresh($key)) return true;
	return wrap_filecache_locked($key, $callback, true);
}

/**
 * run $callback while holding an exclusive lock on tmp_dir/$key.flock (created if missing)
 *
 * If wrap_filecache_put() replaces $key_file, touch(lock path) after unlock (cache mtime).
 *
 * @param string $key
 * @param callable $callback invoked as $callback(); return non-empty string or false
 * @param bool $non_blocking true: LOCK_NB (return false if already locked)
 * @return bool false if fopen or flock failed; true after callback finished
 */
function wrap_filecache_locked($key, callable $callback, $non_blocking = true) {
	$path = wrap_setting('tmp_dir').'/'.$key.'.flock';
	$file = wrap_setting($key.'_file');

	$handle = wrap_flock_acquire($path, $non_blocking);
	if (!$handle) return false;
	$touched = false;
	try {
		$new = $callback();
		$touched = $new ? wrap_filecache_put($file, $new) : false;
	} finally {
		wrap_flock_release($handle);
	}
	if ($touched === true)
		touch($path);
	return true;
}

/**
 * check if cached data for $key is fresh (data file + lock stamp + TTL)
 *
 * @param string $key
 * @return bool true: skip regenerate
 */
function wrap_filecache_fresh($key) {
	$data_file = wrap_setting($key.'_file');
	if (!file_exists($data_file)) return false;

	$flock_path = wrap_setting('tmp_dir').'/'.$key.'.flock';
	if (!file_exists($flock_path)) return false;

	$cache_seconds = wrap_setting($key.'_cache_seconds');
	return filemtime($flock_path) >= time() - (int) $cache_seconds;
}

/**
 * replace $file with $new atomically if content differs
 *
 * Writes to a temp file next to $file, then rename() over $file.
 *
 * @param string $file path to the target file
 * @param string $new full new file body
 * @return bool true if $file was replaced; false if unchanged, or write/rename failed
 */
function wrap_filecache_put($file, $new) {
	$old = file_exists($file) ? file_get_contents($file) : '';
	if ($new === $old) return false;

	wrap_mkdir(dirname($file));
	$tmp = $file.'.'.getmypid().'.tmp';
	if (file_put_contents($tmp, $new) === false) return false;
	if (!rename($tmp, $file)) {
		unlink($tmp);
		return false;
	}
	return true;
}

/**
 * open $path and acquire LOCK_EX; release with wrap_flock_release()
 *
 * The file is created if missing (mode 'c+'). Reads and writes through the
 * returned handle stay inside the locked critical section.
 *
 * Re-validates after flock that the inode held is still the file currently
 * at $path. Another caller may have unlinked the file (wrap_unlock with
 * 'delete') between our fopen and our flock; without re-validation we could
 * hold a lock on an orphaned inode while concurrent callers race a fresh
 * inode at the same path. On mismatch we release and retry on the current
 * inode; bounded by a small retry cap to prevent pathological live-lock.
 *
 * @param string $path absolute path to the lock file
 * @param bool $non_blocking true: LOCK_NB (return false if already locked)
 * @return resource|false handle on success, false on open or lock failure
 */
function wrap_flock_acquire($path, $non_blocking = false) {
	$operation = LOCK_EX;
	if ($non_blocking) $operation |= LOCK_NB;
	$retries = 0;
	while (true) {
		$handle = fopen($path, 'c+');
		if ($handle === false) return false;
		if (!flock($handle, $operation)) {
			fclose($handle);
			return false;
		}
		$stat_fd = fstat($handle);
		clearstatcache(true, $path);
		if (file_exists($path)) {
			$stat_path = stat($path);
			if ($stat_path !== false AND $stat_path['ino'] === $stat_fd['ino'])
				return $handle;
		}
		flock($handle, LOCK_UN);
		fclose($handle);
		if (++$retries > 5) return false;
	}
}

/**
 * release an exclusive lock taken with wrap_flock_acquire() and close the handle
 *
 * @param resource $handle
 * @return void
 */
function wrap_flock_release($handle) {
	flock($handle, LOCK_UN);
	fclose($handle);
}
