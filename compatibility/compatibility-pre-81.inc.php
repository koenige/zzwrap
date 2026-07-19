<?php

/**
 * zzwrap
 * compatibility functions for PHP < 8.1
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


if (!function_exists('array_is_list')) {
	function array_is_list(array $array) {
		if ($array === []) {
			return true;
		}
		return array_keys($array) === range(0, count($array) - 1);
	}
}
