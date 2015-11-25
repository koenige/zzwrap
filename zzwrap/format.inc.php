<?php 

/**
 * zzwrap
 * Formatting functions for strings
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 *	wrap_mailto()
 *	wrap_date()
 *	wrap_print()
 *  wrap_number()
 *  wrap_money()
 *  wrap_html_escape()
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2015 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Format an e-mail link, nice way with name encoded
 * small protection against spammers
 *
 * @param string $person name of the person
 * @param string $mail e-mail address
 * @param string $attributes (optional attributes for the anchor)
 * @return string HTML anchor with mailto-Link
 */
function wrap_mailto($person, $mail, $attributes = false) {
	$mailto = str_replace('@', '&#64;', urlencode('<'.$mail.'>'));
	$mail = str_replace('@', '&#64;', $mail);
	$output = '<a href="mailto:'.str_replace(' ', '%20', $person)
		.'%20'.$mailto.'"'.$attributes
		.'>'.$mail.'</a>';
	return $output;
}

/**
 * Format a date
 *
 * @param string $date
 *		date in ISO format, e. g. "2004-03-12" or period "2004-03-12/2004-03-20"
 *		other date set in first part of format
 * @param string $format format which should be used:
 *		dates-de: 12.03.2004, 12.-14.03.2004, 12.04.-13.05.2004, 
 *			31.12.2004-06.01.2005
 *		rfc1123->datetime,
 *		rfc1123->timestamp,
 *		timestamp->rfc1123
 *		timestamp->datetime
 * @return string
 */
function wrap_date($date, $format = false) {
	global $zz_conf;
	global $zz_setting;
	if (!$date) return '';

	if (!$format AND isset($zz_setting['date_format']))
		$format = $zz_setting['date_format'];
	if (!$format) {
		wrap_error('Please set at least a default format for wrap_date().
			via $zz_setting["date_format"] = "dates-de" or so');
		return $date;
	}
	
	if (strstr($format, '->')) {
		// reformat all inputs to timestamps
		$format = explode('->', $format);
		$input_format = $format[0];
		$output_format = $format[1];
	} else {
		$input_format = 'iso8601';
		$output_format = $format;
	}

	switch ($input_format) {
	case 'iso8601':
		if (strstr($date, '/')) {
			$dates = explode('/', $date);
		} else {
			$dates = array($date);
		}
		foreach ($dates as $index => $mydate) {
			if (!$mydate) continue;
			if (preg_match("/^([0-9]{4}-[0-9]{2}-[0-9]{2}) [0-2][0-9]:[0-5][0-9]:[0-5][0-9]$/", $mydate, $match)) {
				// DATETIME YYYY-MM-DD HH:ii:ss
				$dates[$index] = $match[1]; // ignore time, it's a date function
			} elseif (!preg_match("/^[0-9]{1,4}-[0-9]{2}-[0-9]{2}$/", $mydate)) {
				wrap_error(sprintf(
					'Date %s is currently either not supported as ISO date or no ISO date at all.', $date
				));
				return $date;
			}
		}
		$begin = $dates[0];
		$end = (!empty($dates[1]) AND $dates[1] !== $begin) ? $dates[1] : '';
		break;
	case 'rfc1123':
		// input = Sun, 06 Nov 1994 08:49:37 GMT
		$time = strtotime($date);
		// @todo: what happens with dates outside the timestamp scope?
		break;
	case 'timestamp':
		// input = 784108177
		$time = $date;
		break;
	default:
		wrap_error(sprintf('Unknown input format %s', $input_format));
		break;
	}

	$type = '';
	if (substr($output_format, -6) == '-short') {
		$output_format = substr($output_format, 0, -6);
		$type = 'short';
	}
	if (substr($output_format, 0, 6) == 'dates-') {
		$lang = substr($output_format, 6);
		$output_format = 'dates';
		switch ($lang) {
			case 'de':		$set['sep'] = '.'; $set['order'] = 'DMY'; break; // dd.mm.yyyy
			case 'nl':		$set['sep'] = '-'; $set['order'] = 'DMY'; break; // dd-mm-yyyy
			case 'en-GB':	$set['sep'] = ' '; $set['order'] = 'DMY'; 
				$set['months'] = array(
					1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
					7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'
				);
				break; // dd/mm/yyyy
			default:
				wrap_error(sprintf('Language %s currently not supported', $lang));
				break;
		}
	}
	switch ($output_format) {
	case 'dates':
		if (!$end) {
			// 12.03.2004 or 03.2004 or 2004
			$output = wrap_date_format($begin, $set, $type);
		} elseif (substr($begin, 7) === substr($end, 7)
			AND substr($begin, 0, 4) === substr($end, 0, 4)
			AND substr($begin, 7) === '-00'
			AND substr($begin, 4) !== '-00-00') {
			// 2004-03-00 2004-04-00 = 03-04.2004
			$output = substr($begin, 5, 2).'&#8211;'
				.wrap_date_format($end, $set, $type);
		} elseif (substr($begin, 0, 7) === substr($end, 0, 7)
			AND substr($begin, 7) !== '-00') {
			// 12.-14.03.2004
			$output = substr($begin, 8).'.&#8211;'
				.wrap_date_format($end, $set, $type);
		} elseif (substr($begin, 0, 4) === substr($end, 0, 4)
			AND substr($begin, 7) !== '-00') {
			// 12.04.-13.05.2004
			$output = wrap_date_format($begin, $set, 'noyear')
				.'&#8203;&#8211;'.wrap_date_format($end, $set, $type);
		} else {
			// 2004-03-00 2005-04-00 = 03.2004-04.2005
			// 2004-00-00 2005-00-00 = 2004-2005
			// 31.12.2004-06.01.2005
			$output = wrap_date_format($begin, $set, $type)
				.'&#8203;&#8211;'.wrap_date_format($end, $set, $type);
		}
		return $output;
	case 'datetime':
		// output 1994-11-06 08:49:37
		return date('Y-m-d H:i:s', $time);
	case 'timestamp':
		// output = 784108177
		return $time;
	case 'rfc1123':
		// output Sun, 06 Nov 1994 08:49:37 GMT
		return gmdate('D, d M Y H:i:s', $time). ' GMT';
	}
	wrap_error(sprintf('Unknown output format %s', $output_format));
	return '';
}

/**
 * reformats an ISO 8601 date
 * 
 * @param string $date e. g. 2004-05-31
 * @param array $set settings:
 * 	string 'sep' separator
 * 	string 'order' 'DMY', 'YMD', 'MDY'
 *  array 'months'
 * @param string $type 'standard', 'short', 'noyear'
 */
function wrap_date_format($date, $set, $type = 'standard') {
	if (!$date) return '';
	list($year, $month, $day) = explode('-', $date);
	while (substr($year, 0, 1) === '0') {
		// 0800 = 800 etc.
		$year = substr($year, 1);
	}
	switch ($type) {
		case 'short': $year = substr($year, -2); break;
		case 'noyear': $year = ''; $break;		
	}
	if ($day === '00' AND $month === '00') {
		return $year;
	}
	if (!empty($set['months'])) {
		$month = $set['months'][intval($month)];
	}
	switch ($set['order']) {
	case 'DMY':
		if ($set['sep'] === '.') {
			// let date without year end with dot
			$date = ($day === '00' ? '' : $day.$set['sep']).$month.$set['sep'].$year;
		} else {
			$date = ($day === '00' ? '' : $day.$set['sep']).$month.($year !== '' ? $set['sep'].$year : '');
		}
		break;
	case 'YMD':
		$date = ($year !== '' ? $year.$set['sep'] : '').$month.($day === '00' ? '' : $set['sep'].$day);
		break;
	case 'MDY':
		$date = $month.($day === '00' ? '' : $set['sep'].$day).($year !== '' ? $set['sep'].$year : '');
		break;
	}
	return $date;
}

/**
 * debug: print_r included in text so we do not get problems with headers, zip
 * etc.
 *
 * @param array $array
 * @param string $color (optional)
 * @param bool $html (optional)
 * @return string
 */
function wrap_print($array, $color = 'FFF', $html = true) {
	if ($html) {
		$out = '<pre style="text-align: left; background-color: #'.$color
			.'; position: relative; z-index: 10;" class="fullarray">';
	} else {
		$out = '';
	}
	ob_start();
	print_r($array);
	$code = ob_get_clean();
	if ($html) {
		$codeout = wrap_html_escape($code);
		if ($code AND !$codeout)
			$codeout = htmlspecialchars($code, ENT_QUOTES, 'iso-8859-1');
		$out .= $codeout.'</pre>';
	} else {
		$out .= $code;
	}
	return $out;
}

/**
 * Format a number
 *
 * @param string $number
 * @param string $format format which should be used:
 *		roman->arabic
 *		arabic->roman
 * @return string
 */
function wrap_number($number, $format = false) {
	global $zz_setting;
	if (!$number) return '';

	if (!$format AND isset($zz_setting['number_format']))
		$format = $zz_setting['number_format'];
	if (!$format) {
		wrap_error('Please set at least a default format for wrap_number().
			via $zz_setting["number_format"] = "roman->arabic" or so');
		return $number;
	}
	
	switch ($format) {
	case 'roman->arabic':
	case 'arabic->roman':
		$roman_letters = array(
			1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C',
			90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 
			4 => 'IV', 1 => 'I'
		);
		if (is_numeric($number)) {
			// arabic/roman
			if ($number > 3999 OR $number < 1) {
				wrap_error(wrap_text(
					'Sorry, we can only convert numbers between 1 and 3999 to roman numbers.'
				), E_USER_NOTICE);
				return '';
			}
			$output = '';
			foreach ($roman_letters as $arabic => $letter) {
				while ($number >= $arabic) {
					$output .= $letter;
					$number -= $arabic;
				}
			}
		} else {
			// roman/arabic
			$output = 0;
			$input = $number;
			$error = false;
			foreach ($roman_letters as $value => $key) {
				$count = 0;
				while (strpos($number, $key) === 0) {
					$output += $value;
					$number = substr($number, strlen($key));
					$count++;
				}
				// validity check: combined letters and letters representing
				// half of 10^n might be repeated once; other letters four times
				if (strlen($key) === 2 AND $count > 1) $error = true;
				elseif (substr($value, 0, 1) == '5' AND $count > 1) $error = true;
				elseif ($count > 4) $error = true;
			}
			// if it's a valid number, no character may remain
			if ($number) $error = true;
			if ($error) {
				wrap_error(sprintf(wrap_text(
					'Sorry, <strong>%s</strong> appears not to be a valid roman number.'
				), wrap_html_escape($input)), E_USER_NOTICE);
				return '';
			}
		}
		return $output;
	default:
		wrap_error(sprintf(wrap_text('Sorry, the number format <strong>%s</strong> is not supported.'),
			wrap_html_escape($format)), E_USER_NOTICE);
		return '';
	}
}

/**
 * returns own money_format
 *
 * @param double $number
 * @param string $format (optional)
 * @todo read default format from settings, as in wrap_number()
 */
function wrap_money($number, $format = false) {
	return wrap_money_format($number, $format);
}

function wrap_money_format($number, $format = false) {
	if (!$format) {
		global $zz_setting;
		$format = $zz_setting['lang'];
	}
	if (!is_numeric($number)) return $number;
	switch ($format) {
	case 'de':
		return number_format($number, 2, ',', '.');
	case 'en':
		return number_format($number, 2, '.', ',');
	default:
		return $number;
	}
}

/**
 * Escapes unvalidated strings for HTML values (< > & " ')
 *
 * @param string $string
 * @return string $string
 * @global array $zz_conf
 * @see zz_html_escape()
 */
function wrap_html_escape($string) {
	global $zz_conf;
	// overwrite default character set UTF-8 because htmlspecialchars will
	// return NULL if character set is unknown
	switch ($zz_conf['character_set']) {
		case 'iso-8859-2': $character_set = 'ISO-8859-1'; break;
		default: $character_set = $zz_conf['character_set']; break;
	}
	$string = htmlspecialchars($string, ENT_QUOTES, $character_set);
	return $string;
}

/**
 * formats a numeric value into a readable representation using different units
 *
 * @param int $value value to format
 * @param int $precision
 * @param array $units units that can be used, indexed by power
 * @param int $factor factor between different units, defaults to 1000
 * @param int $precision
 * @return string
 */
function wrap_unit_format($value, $precision, $units, $factor = 1000) {
	global $zz_conf;
    $value = max($value, 0);
    $pow = floor(($value ? log($value) : 0) / log($factor)); 
    $pow = min($pow, count($units) - 1); 
	// does unit for this exist?
	while (!isset($units[$pow])) $pow--;
	$value /= pow($factor, $pow);

    $text = round($value, $precision) . '&nbsp;' . $units[$pow]; 
    if ($zz_conf['decimal_point'] !== '.')
    	$text = str_replace('.', $zz_conf['decimal_point'], $text);
    return $text;
}

/**
 * formats an integer into a readable byte representation
 *
 * @param int $bytes
 * @param int $precision
 * @return string
 * @see zz_byte_format
 */
function wrap_bytes($bytes, $precision = 1) { 
    $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB'); 
	return wrap_unit_format($bytes, $precision, $units, 1024);
}

/**
 * formats a numeric value into a readable gram representation
 *
 * @param int $gram
 * @param int $precision
 * @return string
 */
function wrap_gram($gram, $precision = 1) {
	$units = array(
		-3 => 'ng', -2 => 'µg', -1 => 'mg', 0 => 'g', 1 => 'kg',
		2 => 't', 3 => 'kt', 4 => 'Mt', 5 => 'Gt'
	);
	return wrap_unit_format($gram, $precision, $units);
}

/**
 * formats a numeric value into a readable meter representation
 *
 * @param int $meters
 * @param int $precision
 * @return string
 */
function wrap_meters($meters, $precision = 1) {
	$units = array(
		-9 => 'nm', -6 => 'µm', -3 => 'mm', -2 => 'cm', 0 => 'm', 3 => 'km'
	);
	return wrap_unit_format($meters, $precision, $units, 10);
}
