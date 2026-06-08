<?php

/**
 * zzwrap
 * compatibility functions for PHP < 7.4
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2024, 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


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
