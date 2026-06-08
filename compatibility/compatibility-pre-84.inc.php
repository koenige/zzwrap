<?php

/**
 * zzwrap
 * compatibility functions for PHP < 8.4
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024, 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


if (!function_exists('http_get_last_response_headers')) {
	function http_get_last_response_headers() {
		if (!isset($http_response_header)) return null;
		return $http_response_header;
	}
}
