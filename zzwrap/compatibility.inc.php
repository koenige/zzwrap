<?php 

/**
 * zzwrap
 * compatibility functions for old PHP versions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


// ------------------------------------------------------------------ //
// PHP < 8.0
if (version_compare(PHP_VERSION, '8.0.0', '>')) return;
// ------------------------------------------------------------------ //

// source: Laravel Framework
// https://github.com/laravel/framework/blob/8.x/src/Illuminate/Support/Str.php
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle !== '' && substr($haystack, -strlen($needle)) === (string)$needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

// ------------------------------------------------------------------ //
// PHP < 7.4
if (version_compare(PHP_VERSION, '7.4.0', '>')) return;
// ------------------------------------------------------------------ //

if (!function_exists('mb_str_split')) {
    function mb_str_split($string = '', $split_length = 1 , $encoding = null) {
        if (empty($string)) return $string;
        if (!$encoding) $encoding = mb_internal_encoding();
        if ($split_length < 1) return NULL;
        if ($split_length === 1)
        	return preg_split("//u", $string, -1, PREG_SPLIT_NO_EMPTY);
		$split = [];
		$string_length = mb_strlen($string, $encoding);
		for ($i = 0; $i < $string_length; $i += $split_length) {
			$substr = mb_substr($string, $i, $split_length, $encoding);
			if (empty($substr)) continue;
			$split[] = $substr;
		}
        return $split;
    }
}

// ------------------------------------------------------------------ //
// PHP < 7.3
if (version_compare(PHP_VERSION, '7.3.0', '>')) return;
// ------------------------------------------------------------------ //

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
