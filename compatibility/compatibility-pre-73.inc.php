<?php

/**
 * zzwrap
 * compatibility functions for PHP < 7.3
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024, 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


if( !function_exists('apache_request_headers')) {
	function apache_request_headers() {
		$arh = [];
		$rx_http = '/\AHTTP_/';
		foreach ($_SERVER as $key => $val) {
			if (!preg_match($rx_http, $key)) continue;
			$arh_key = preg_replace($rx_http, '', $key);
			$rx_matches = [];
			// restore original case, as good as possible
			$arh_key = strtolower($arh_key);
			$rx_matches = explode('_', $arh_key);
			if( count($rx_matches) > 0 and strlen($arh_key) > 2 ) {
				foreach($rx_matches as $ak_key => $ak_val)
					$rx_matches[$ak_key] = ucfirst($ak_val);
				$arh_key = implode('-', $rx_matches);
			}
			$arh[$arh_key] = $val;
		}
		return ($arh);
	}
}

if (!function_exists('array_key_last')) {
    function array_key_last($array) {
        if (!is_array($array) || empty($array)) {
            return NULL;
        }
        return array_keys($array)[count($array)-1];
    }
}
