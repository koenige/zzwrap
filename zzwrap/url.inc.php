<?php

/**
 * zzwrap
 * url functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * check lang parameter from GET
 *
 * @return bool
 */
function wrap_url_language() {
	if (empty($_GET['lang'])) return true;
	
	if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $_GET['lang'])) return false;
	// @todo the following check is not a good solution since languages_2c is only
	// used on systems with languages with three letters
	if (in_array($_GET['lang'], array_keys(wrap_id('languages', '', 'list')))) {
		wrap_setting('lang', $_GET['lang']);
		return true;
	} elseif (in_array($_GET['lang'], array_keys(wrap_id('languages_2c', '', 'list')))) {
		wrap_setting('lang', $_GET['lang']);
		return true;
	}
	return false;
}
