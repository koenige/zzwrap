<?php 

/**
 * zzwrap
 * Formatting functions for strings
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * wrap_convert_string()
 *	wrap_mailto()
 *	wrap_date()
 *		wrap_date_format()
 *	wrap_print()
 *  wrap_number()
 *  wrap_money()
 *		wrap_money_format()
 *  wrap_html_escape()
 *	wrap_unit_format()
 *		wrap_bytes()
 *		wrap_gram()
 *		wrap_meters()
 *	wrap_bearing()
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2021 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * convert a string into a different character encoding if necessary
 *
 * @param mixed $data
 * @param string $encoding (optional, if not set, internal encoding is used)
 * @return mixed
 */
function wrap_convert_string($data, $encoding = false) {
	if (!$encoding) $encoding = mb_internal_encoding();
	$detected_encoding = wrap_detect_encoding($data);
	if ($detected_encoding === $encoding) return $data;
	if (substr($detected_encoding, 0, 9) === 'ISO-8859-' AND 
		substr($encoding, 0, 9) === 'ISO-8859-') {
		// all ISO character encodings will be seen as ISO-8859-1
		// @see http://www.php.net/manual/en/function.mb-detect-order.php
		return $data;
	}
	if (!is_array($data)) {
		if ($detected_encoding === 'UTF-8') {
			if (substr($data, 0, 3) === "\xEF\xBB\xBF") { // UTF8_BOM
				$data = substr($data, 3);
			}
		}
		$data = mb_convert_encoding($data, $encoding, $detected_encoding);
	} else {
		if ($detected_encoding === 'UTF-8') {
			if (substr($data[0], 0, 3) === "\xEF\xBB\xBF") { // UTF8_BOM
				$data[0] = substr($data[0], 3);
			}
		}
		foreach ($data as $index => $line) {
			$data[$index] = mb_convert_encoding($line, $encoding, $detected_encoding);
		}
	}
	return $data;
}

/**
 * detect character encoding
 *
 * @param mixed $data
 * @return string
 */
function wrap_detect_encoding($data) {
	if (is_array($data)) {
		// just support for one dimensional arrays
		$test_string = implode('', $data);
	} else {
		$test_string = $data;
	}
	if (substr($test_string, -1) === chr(241)) {
		$test_string .= 'a'; // PHP bug? Latin1 string ending with n tilde returns UTF-8
	}
	// strict mode (last parameter) set to true because function is probably
	// useless without (see http://php.net/mb_detect_encoding)
	return mb_detect_encoding($test_string, mb_detect_order(), true);
}

/**
 * set internal character encoding for mulitbyte functions
 *
 * @param string $character_encoding ($zz_conf['character_set'])
 * @return bool
 */
function wrap_set_encoding($character_encoding) {
	// note: mb_-functions obiously cannot tell Latin1 from other Latin encodings!
	$iso = wrap_substr($character_encoding, 'iso-8859-') ? strtoupper($character_encoding) : 'ISO-8859-1';
	mb_detect_order('UTF-8, '.$iso.', UTF-7, EUC-JP, SJIS, eucJP-win, SJIS-win, JIS, ISO-2022-JP');
	switch ($character_encoding) {
	case 'utf-8':
		mb_internal_encoding('UTF-8');
		break;
	case 'iso-8859-1':
		mb_internal_encoding('ISO-8859-1');
		break;
	case 'iso-8859-2':
		mb_internal_encoding('ISO-8859-2');
		break;
	}
	return true;
}

/**
 * convert a string to a filename (just ASCII characters)
 *
 * @param string $str input string
 * @param string $spaceChar character to use for spaces
 * @param array $replacements
 * @return string
 */
function wrap_filename($str, $spaceChar = '-', $replacements = []) {
	global $zz_setting;
	global $zz_conf;
	static $characters;

	if (!$characters) {
		$data = file($zz_setting['core'].'/transliteration-characters.tsv');
		foreach ($data as $line) {
			if (!trim($line)) continue;
			if (substr($line, 0, 1) === '#') continue;
			$line = explode("\t", $line);
			$characters[trim($line[0])] = trim($line[1]);
		}
	}
	$str = wrap_convert_string($str, 'UTF-8');
	wrap_set_encoding('utf-8');
	$str = trim($str);

	// get rid of html entities
	$str = html_entity_decode($str);
	if (strstr($str, '&#')) {
		if (strstr($str, '&#x')) {
			$str = preg_replace('~&#x([0-9a-f]+);~i', '', $str);
		}
		$str = preg_replace('~&#([0-9]+);~', '', $str);
	}

	$_str = '';
	$i_max = mb_strlen($str);
	for ($i = 0; $i < $i_max; $i++) {
		$ch = mb_substr($str, $i, 1);
		if (in_array($ch, array_keys($replacements))) {
			$_str .= $replacements[$ch];
			continue;
		}
		if (array_key_exists(strval($ch), $characters)) {
			$_str .= $characters[$ch];
		} elseif ($ch === ' ') {
			$_str .= $spaceChar;
		} elseif (preg_match('/[A-Za-z0-9]/u', $ch)) {
			$_str .= $ch;
		} else {
			continue;
		}
	}

	$_str = str_replace("{$spaceChar}{$spaceChar}", "{$spaceChar}",	$_str);
	$_str = str_replace("{$spaceChar}-", '-',	$_str);
	$_str = str_replace("-{$spaceChar}", '-',	$_str);
	if (substr($_str, -1) === $spaceChar) {
		$_str = substr($_str, 0, -1);
	}
	// require at least one character
	if (!$_str) $_str = $spaceChar;

	wrap_set_encoding($zz_conf['character_set']);
	return $_str;
}

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
	$output = '<a href="&#109;&#x61;&#105;&#x6c;t&#111;&#x3a;%22'.str_replace(' ', '%20', $person)
		.'%22%20'.$mailto.'"'.$attributes
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
			$dates = [$date];
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
	} elseif (substr($output_format, -5) == '-long') {
		$output_format = substr($output_format, 0, -5);
		$type = 'long';
	}
	if (substr($output_format, 0, 6) == 'dates-') {
		$lang = substr($output_format, 6);
		$output_format = 'dates';
		switch ($lang) {
			case 'de':		$set['sep'] = '.'; $set['order'] = 'DMY';
				$set['months_if_no_day'] = $set['months_long'] = [
					1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
					5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
					9 => 'September', 10 => 'Oktober', 11 => 'November',
					12 => 'Dezember'
				];
				if ($type === 'long') $set['sep'] = ['. ', ' '];
				break; // dd.mm.yyyy
			case 'nl':		$set['sep'] = '-'; $set['order'] = 'DMY';
				break; // dd-mm-yyyy
			case 'en-GB':	$set['sep'] = ' '; $set['order'] = 'DMY'; 
				$set['months'] = [
					1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May',
					6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct',
					11 => 'Nov', 12 => 'Dec'
				];
				$set['months_long'] = [
					1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
					5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
					9 => 'September', 10 => 'October', 11 => 'November',
					12 => 'December'
				];
				break; // dd/mm/yyyy
			default:
				wrap_error(sprintf('Language %s currently not supported', $lang));
				break;
		}
	}
	if (!empty($set)) {
		if (!is_array($set['sep']) OR count($set['sep']) !== 2) {
			$set['sep'][0] = $set['sep'][1] = $set['sep'];
		}
	}
	// decode HTML entities as this function can be used for mails as well
	$bis = html_entity_decode('&#8239;–&#8239;');
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
			$output = substr($begin, 5, 2).$bis
				.wrap_date_format($end, $set, $type);
		} elseif (substr($begin, 0, 7) === substr($end, 0, 7)
			AND substr($begin, 7) !== '-00') {
			// 12.-14.03.2004 -- trim to remove space if '. ' is separator
			$output = substr($begin, 8)
				.(strlen($set['sep'][0]) > 1 ? trim($set['sep'][0]) : $set['sep'][0])
				.$bis.wrap_date_format($end, $set, $type);
		} elseif (substr($begin, 0, 4) === substr($end, 0, 4)
			AND substr($begin, 7) !== '-00') {
			// 12.04.-13.05.2004
			$output = wrap_date_format($begin, $set, 'noyear'.($type === 'long' ? '-long' : ''))
				.$bis.wrap_date_format($end, $set, $type);
		} else {
			// 2004-03-00 2005-04-00 = 03.2004-04.2005
			// 2004-00-00 2005-00-00 = 2004-2005
			// 31.12.2004-06.01.2005
			$output = wrap_date_format($begin, $set, $type)
				.$bis.wrap_date_format($end, $set, $type);
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
 * 	array 'sep' separator
 * 	string 'order' 'DMY', 'YMD', 'MDY'
 *  array 'months'
 * @param string $type 'standard', 'long', 'short', 'noyear', 'noyear-long'
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
		case 'noyear': case 'noyear-long': $year = ''; break;
	}
	if ($day === '00' AND $month === '00') {
		return $year;
	}
	if (in_array($type, ['long', 'noyear-long']) AND !empty($set['months_long'])) {
		$month = $set['months_long'][intval($month)];
	} else {
		if (!empty($set['months'])) {
			$month = $set['months'][intval($month)];
		}
		if ($day === '00' AND !empty($set['months_if_no_day'])) {
			return $set['months_if_no_day'][intval($month)].' '.$year;
		}
	}
	switch ($set['order']) {
	case 'DMY':
		if ($set['sep'][0] === '.' AND $set['sep'][1] === '.') {
			// let date without year end with dot
			$date = ($day === '00' ? '' : $day.$set['sep'][0]).$month.$set['sep'][1].$year;
		} else {
			$date = ($day === '00' ? '' : $day.$set['sep'][0]).$month.($year !== '' ? $set['sep'][1].$year : '');
		}
		break;
	case 'YMD':
		$date = ($year !== '' ? $year.$set['sep'][0] : '').$month.($day === '00' ? '' : $set['sep'][1].$day);
		break;
	case 'MDY':
		$date = $month.($day === '00' ? '' : $set['sep'][0].$day).($year !== '' ? $set['sep'][1].$year : '');
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
 *		simple (default)
 * @return string
 */
function wrap_number($number, $format = false) {
	global $zz_setting;
	global $zz_conf;
	if (!$number AND $number !== '0' AND $number !== 0) return '';

	if (!$format AND isset($zz_setting['number_format']))
		$format = $zz_setting['number_format'];
	if (!$format)
		$format = 'simple';
	
	switch ($format) {
	case 'roman->arabic':
	case 'arabic->roman':
		$roman_letters = [
			1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C',
			90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 
			4 => 'IV', 1 => 'I'
		];
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
	case 'two-decimal-places':
		$output = number_format($number, 2, $zz_conf['decimal_point'], $zz_conf['thousands_separator']);
		return $output;
	case 'simple':
	case 'simple-hidezero':
		if (strstr($number, '.')) {
			if ($format === 'simple-hidezero' AND (substr($number, -3) === '.00' OR substr($number, -4) === '.000')) {
				$output = number_format($number, 0, $zz_conf['decimal_point'], $zz_conf['thousands_separator']);
			} else {
				$output = number_format($number, 1, $zz_conf['decimal_point'], $zz_conf['thousands_separator']);
			}
		} else {
			$output = number_format($number, 0, $zz_conf['decimal_point'], $zz_conf['thousands_separator']);
		}
		return $output;
	default:
		wrap_error(sprintf(wrap_text('Sorry, the number format <strong>%s</strong> is not supported.'),
			wrap_html_escape($format)), E_USER_NOTICE);
		return '';
	}
}

/**
 * returns percentage with one decimal place
 *
 * @param double $number
 * @return string
 */
function wrap_percent($number) {
	global $zz_conf;
	$number *= 100;
	$number = number_format($number, 1, $zz_conf['decimal_point'], $zz_conf['thousands_separator']);
	$number .= html_entity_decode('&nbsp;%');
	return $number;
}

/**
 * returns own money_format
 *
 * @param double $number
 * @param string $format (optional)
 * @return string
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
 */
function wrap_html_escape($string) {
	global $zz_conf;
	// overwrite default character set UTF-8 because htmlspecialchars will
	// return NULL if character set is unknown
	switch ($zz_conf['character_set']) {
		case 'iso-8859-2': $character_set = 'ISO-8859-1'; break;
		default: $character_set = $zz_conf['character_set']; break;
	}
	$new_string = @htmlspecialchars($string, ENT_QUOTES, $character_set);
	if (!$new_string) $new_string = htmlspecialchars($string, ENT_QUOTES, 'ISO-8859-1');
	return $new_string;
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

    $text = round($value, $precision) . html_entity_decode('&nbsp;') . $units[$pow]; 
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
 */
function wrap_bytes($bytes, $precision = 1) { 
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']; 
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
	$units = [
		-3 => 'ng', -2 => 'µg', -1 => 'mg', 0 => 'g', 1 => 'kg',
		2 => 't', 3 => 'kt', 4 => 'Mt', 5 => 'Gt'
	];
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
	$units = [
		-9 => 'nm', -6 => 'µm', -3 => 'mm', -2 => 'cm', 0 => 'm', 3 => 'km'
	];
	return wrap_unit_format($meters, $precision, $units, 10);
}

/**
 * formats a numeric value into a direction with N E S W
 *
 * @param int $value
 * @param int $precision
 * @return string
 */
function wrap_bearing($value, $precision = 1) {
	global $zz_conf;
	if (strstr($value, '/')) {
		$value = explode('/', $value);
		$value = $value[0]/$value[1];
	}
	if ($value < 0) $value = 360 - $value;
	$text = round($value, $precision).'° ';
    if ($zz_conf['decimal_point'] !== '.')
    	$text = str_replace('.', $zz_conf['decimal_point'], $text);
    $units = [
    	  0 => 'N North', '22.5' => 'NNE North-northeast',
    	 45 => 'NE Northeast', '67.5' => 'ENE East-northeast',
    	 90 => 'E East', '112.5' => 'ESE East-southeast',
    	135 => 'SE Southeast', '157.5' => 'SSE South-southeast',
    	180 => 'S South', '202.5' => 'SSW South-southwest',
    	225 => 'SW Southwest', '247.5' => 'WSW West-southwest',
    	270 => 'W West', '292.5' => 'WNW West-northwest',
    	315 => 'NW Northwest', '337.5' => 'NNW North-northwest'
    ];
    $check = $value + 11.25;
    if ($check >= 360) $check -= 360;
    foreach ($units as $deg => $direction) {
    	if ($value == $deg) {
			$title = $direction;
    		break;
    	}
    	if ($check >= $deg) {
    		$last_direction = $direction;
    		continue;
		}
    }
    if (empty($title)) $title = $last_direction;
    $title = wrap_text($title);
    $title = explode(' ', $title);
    $abbr = array_shift($title);
    $title = implode(' ', $title);
    $text .= sprintf('<abbr title="%s">%s</abbr>', $title, $abbr);
	return $text;
}

/**
 * format a geographical coordinate (latitude)
 * 
 * @param double $value
 * @return string
 */
function wrap_latitude($value) {
	global $zz_conf;
	if (!file_exists($zz_conf['dir'].'/geo.inc.php')) return $value; // @todo
	require_once $zz_conf['dir'].'/geo.inc.php';
	return zz_geo_coord_out($value, 'lat', 'dms');
}

/**
 * format a geographical coordinate (longitude)
 * 
 * @param double $value
 * @return string
 */
function wrap_longitude($value) {
	global $zz_conf;
	if (!file_exists($zz_conf['dir'].'/geo.inc.php')) return $value; // @todo
	require_once $zz_conf['dir'].'/geo.inc.php';
	return zz_geo_coord_out($value, 'lon', 'dms');
}

/**
 * normalizes input to NFC
 * Canonical normalization
 *
 * @param string $input
 * @return string
 */
function wrap_normalize($input) {
	global $zz_conf;
	static $replacements;

	if ($zz_conf['character_set'] !== 'utf-8') return $input;
	if (!$input) return $input;
	if (is_numeric($input)) return $input;
	if (!is_string($input)) return $input; // e. g. subrecords

	if (class_exists("Normalizer", $autoload = false)) {
		$output = normalizer_normalize($input, Normalizer::FORM_C);
		if (!$output) return $input;
		return $output;
	}
	
	if (!$replacements) {
		$replacements = [];
		$file = __DIR__.'/unicode-normalization.tsv';
		$handle = fopen($file, 'r');
		while (!feof($handle)) {
			$line = fgetcsv($handle, 256, "\t");
			if (!$line[0]) continue;
			if (substr($line[0], 0, 1) === '#') continue;
			$replacements[wrap_hex2chars($line[0])] = wrap_hex2chars($line[3]);
		}
	}
	foreach ($replacements as $search => $replace) {
		if (!strstr($input, $search)) continue;
		$input = str_replace($search, $replace, $input);
	}
	return $input;
}

/**
 * reformat hexadecimal codes to characters
 * on a byte basis, so resulting characters might be unicode as well
 * e. g. "c3 84" to "Ä"
 *
 * @param string $string hexadecimal codepoints separated by space
 * @return string
 */
function wrap_hex2chars($string) {
	$codes = explode(' ', $string);
	$string = '';
	foreach ($codes as $code) {
		$string .= chr(hexdec($code));
	}
	return $string;
}

/**
 * convert string to punycode
 *
 * @param string $string
 * @return string
 */
function wrap_punycode_encode($string) {
	return wrap_punycode($string, 'encode');
}

/**
 * convert punycode to UTF-8 string
 *
 * @param string $string
 * @return string
 */
function wrap_punycode_decode($string) {
	$url = parse_url($string);
	if (empty($url['host'])) return $string;
	$host = explode('.', $url['host']);
	foreach ($host as $index => $part) {
		if (substr($part, 0, 4) !== 'xn--') continue;
		$host[$index] = wrap_punycode($part, 'decode');
	}
	$url['host_new'] = implode('.', $host);
	if ($url['host_new'] === $url['host']) return $string;
	$string = str_replace($url['host'], $url['host_new'], $string);
	return $string;
}

/**
 * encode / decode punycode
 *
 * @param string $string
 * @param string $action encode, decode
 * @return string
 */
function wrap_punycode($string, $action) {
	global $zz_setting;
	global $zz_conf;

	$punycode_lib = $zz_setting['lib'].'/idnaconvert/src/IdnaConvert.php';
	if (!file_exists($punycode_lib)) return $string;

	require_once $punycode_lib;
	require_once $zz_setting['lib'].'/idnaconvert/src/PunycodeInterface.php';
	require_once $zz_setting['lib'].'/idnaconvert/src/Punycode.php';
	require_once $zz_setting['lib'].'/idnaconvert/src/NamePrepDataInterface.php';
	require_once $zz_setting['lib'].'/idnaconvert/src/NamePrepData.php';
	require_once $zz_setting['lib'].'/idnaconvert/src/UnicodeTranscoderInterface.php';
	require_once $zz_setting['lib'].'/idnaconvert/src/UnicodeTranscoder.php';
	
	$IDN = new Algo26\IdnaConvert\IdnaConvert();
	if ($action === 'encode') {
		if ($zz_conf['character_set'] !== 'utf-8') {
			$string = iconv($zz_conf['character_set'], 'utf-8', $string);
		}
		return $IDN->encode($string);
	}
	return $IDN->decode($string);
}

/**
 * format a time
 *
 * @param string $time
 * @param string $format
 * @return string
 */
function wrap_time($time, $format = false) {
	if (empty($format)) $format = 'H:i';
	return date($format, strtotime($time));
}

/**
 * format a duration
 *
 * @param int $duration duration
 * @param string $unit (optional) unit of duration, defaults to 'second'
 * @param string $format
 * @return string
 */
function wrap_duration($duration, $unit = 'second', $format = '') {
	global $zz_setting;
	if (!$format AND isset($zz_setting['duration_format']))
		$format = $zz_setting['duration_format'];
	else
		$format = 'long';

	$data = [
		'year' => 0, 'week' => 0, 'day' => 0,
		'hour' => 0, 'minute' => 0, 'second' => 0
	];
	$seconds = [
		'year' => 86400*365, 'week' => 86400*7, 'day' => 86400,
		'hour' => 3600, 'minute' => 60, 'second' => 0
	];
	if ($unit !== 'second') {
		if (!in_array($unit, array_keys($seconds))) {
			wrap_error('Unit %s not recognized for calculating duration.');
			return $duration;
		}
		$duration *= $seconds[$unit];
	}
	switch (true) {
		case $duration >= $seconds['year']:
			if ($format !== 'H:i') {
				$data['year'] = intval(floor($duration / $seconds['year']));
				$duration -= $data['year'] * $seconds['year'];
			}
		case $duration >= $seconds['week']:
			if ($format !== 'H:i') {
				$data['week'] = intval(floor($duration / $seconds['week']));
				$duration -= $data['week'] * $seconds['week'];
			}
		case $duration >= $seconds['day']:
			if ($format !== 'H:i') {
				$data['day'] = intval(floor($duration / $seconds['day']));
				$duration -= $data['day'] * $seconds['day'];
			}
		case $duration >= $seconds['hour']:
			$data['hour'] = intval(floor($duration / $seconds['hour']));
			$duration -= $data['hour'] * $seconds['hour'];
		case $duration >= $seconds['minute']:
			$data['minute'] = intval(floor($duration / $seconds['minute']));
			$duration -= $data['minute'] * $seconds['minute'];
		default:
			$data['second'] = $duration;
			break;
	}

	$out = [];
	switch ($format) {
	case 'long':
		foreach ($data as $type => $count) {
			if (!$count) continue;
			if ($count === 1) $out[] = wrap_text('1 '.$type);
			else $out[] = sprintf(wrap_text('%d '.$type.'s'), $count);
		}
		return implode(', ', $out);
	case 'H:i':
		return sprintf('%d:%02d', $data['hour'], $data['minute']);
	}
}

/**
 * return weekday abbreviation for a given day of the week starting with Sunday = 1
 *
 * @param string $day
 * @param string $lang (optional, uses $zz_setting['lang'] as default)
 * @return string
 */
function wrap_weekday($day, $lang = '') {
	switch ($day) {
		case 1: return wrap_text('Sun', $lang);
		case 2: return wrap_text('Mon', $lang);
		case 3: return wrap_text('Tue', $lang);
		case 4: return wrap_text('Wed', $lang);
		case 5: return wrap_text('Thu', $lang);
		case 6: return wrap_text('Fri', $lang);
		case 7: return wrap_text('Sat', $lang);
	}
	return $day;
}

/**
 * replace weekday abbreviations for a data list and certain field names
 *
 * @param array $data data indexed by ID
 * @param array $fields list with name of fields
 * @param string $lang (optional, uses $zz_setting['lang'] as default)
 * @return array
 */
function wrap_weekdays($data, $fields, $lang) {
	foreach ($data as $id => $line) {
		foreach ($fields as $field) {
			if (!array_key_exists($field, $data[$id])) continue;
			$data[$id][$field] = wrap_weekday($line[$field], $lang);
		}
	}
	return $data;
}

/**
 * do some automatic replacement for a better typography
 *
 * @param string $text
 * @param string $lang ISO 2 letter code
 * @return string $text
 */
function wrap_typo_cleanup($text, $lang = '') {
	global $zz_setting;

	if (!trim($text)) return $text;
	$new_text = '';
	$skip = 0;
	$html_open = false;
	$placeholder_open = false;
	$quotation_marks_open_single = false;
	$quotation_marks_open_double = false;
	$is_url = false;

	if (!$lang) {
		if (!empty($zz_setting['default_source_language']))
			$lang = $zz_setting['default_source_language'];
		else
			$lang = $zz_setting['lang'];
	}
	if (empty($zz_setting['quotation_marks'][$lang])) {
		$quotation_marks_format = $lang;
	} else {
		$quotation_marks_format = $zz_setting['quotation_marks'][$lang];
	}

	switch ($quotation_marks_format) {
	case 'de':
		$qm_double_open = '„';
		$qm_double_close = '“';
		$qm_single_open = '‚';
		$qm_single_close = '‘';
		break;
	case 'de-guillemets':
		$qm_double_open = '»';
		$qm_double_close = '«';
		$qm_single_open = '›';
		$qm_single_close = '‹';
		break;
	case 'ch':
	case 'fr':
		$qm_double_open = '«';
		$qm_double_close = '»';
		$qm_single_open = '‹';
		$qm_single_close = '›';
		break;
	default:
	case 'en':
		$qm_double_open = '“';
		$qm_double_close = '”';
		$qm_single_open = '‘';
		$qm_single_close = '’';
		break;
	}

	for ($i = 0; $i < mb_strlen($text); $i++) {
		$letter = mb_substr($text, $i, 1);
		if ($skip) {
			$skip--;
			continue;
		}
		if ($html_open AND $letter !== '>') {
			$new_text .= $letter;
			continue;
		}
		if ($placeholder_open) {
			if (mb_substr($text, $i, 6) === '%%%%%%') {
				$new_text .= '%%%%%%';
				$skip = 5;
			} elseif (mb_substr($text, $i, 3) === '%%%') {
				$new_text .= '%%%';
				$skip = 2;
				$placeholder_open = false;
			} else {
				$new_text .= $letter;
			}
			continue;
		}
		if ($is_url) {
			if ($letter === "\n") $is_url = false;
			$new_text .= $letter;
			continue;
		}
		switch ($letter) {
		case '<':
			if (!in_array(mb_substr($text, $i + 1, 1), ['>', ' '])) $html_open = true;
			break;
		case '>':
			if ($html_open) $html_open = false;
			break;
		case '%':
			if (mb_substr($text, $i, 6) !== '%%%%%%' AND mb_substr($text, $i, 3) === '%%%')
				$placeholder_open = !$placeholder_open;
			break;
		case '"':
			if ($quotation_marks_open_double) {
				$letter = $qm_double_close;
				$quotation_marks_open_double = false;
			} else {
				$letter = $qm_double_open;
				$quotation_marks_open_double = true;
			}
			break;
		case "'":
			if (preg_match("/^\S'\S$/", mb_substr($text, $i - 1, 3))) {
				// inside a word: apostrophe, not quotation mark
				$letter = '’';
			} elseif (!$quotation_marks_open_single AND preg_match("/^\S' $/", mb_substr($text, $i - 1, 3))) {
				// at the end of a word without opening quotation mark: apostrophe, not quotation mark
				$letter = '’';
			} else {
				if ($quotation_marks_open_single) {
					$letter = $qm_single_close;
					$quotation_marks_open_single = false;
				} else {
					$letter = $qm_single_open;
					$quotation_marks_open_single = true;
				}
			}
			break;
			break;
		case '´':
			$letter = '’';
			break;
		case '`':
			if (preg_match('/^\S`\S$/', mb_substr($text, $i - 1, 3))) {
				$letter = '’';
			}
			break;
		case '-':
			if (preg_match('/^\S - \S$/', mb_substr($text, $i - 2, 5))) {
				$letter = '–';
			} elseif (preg_match('/^\d-\d$/', mb_substr($text, $i - 1, 3))) {
				$letter = '–';
			}
			break;
		case '.':
			if (preg_match('/^\.\.\.$/', mb_substr($text, $i, 3))) {
				$letter = '…';
				$skip = 2;
			}
			break;
		case ':':
			if (preg_match('/^]: $/', mb_substr($text, $i -1, 3))) {
				$is_url = true;
			}
			break;
		case '!':
			if (preg_match('/^!\[.+?]\(.+?\)/', mb_substr($text, $i), $matches)) {
				if (!empty($matches[0])) {
					$skip = mb_strlen($matches[0]) -1;
					$letter = $matches[0];
					break;
				}
			}
			$letter = '!';
			break;
		case '[':
			if (preg_match('/^\[.+?]\(.+?\)/', mb_substr($text, $i), $matches)) {
				if (!empty($matches[0])) {
					$skip = mb_strlen($matches[0]) -1;
					$letter = $matches[0];
					break;
				}
			}
			$letter = '[';
			break;
		default:
			break;
		}
		$new_text .= $letter;
	}
	return $new_text;
}

/**
 * return placeholder for formatting
 *
 * @param string $placeholder
 * @return string
 */
function wrap_placeholder($placeholder) {
	global $zz_setting;

	switch ($placeholder) {
	case 'mysql_date_format':
		switch ($zz_setting['lang']) {
			case 'de': return '%d.%m.%Y';
			case 'en': return '%d/%m/%Y';
			default: return '%Y-%m-%d';
		}
		break;
	default:
		wrap_error(sprintf('Placeholder %s not found.', wrap_html_escape($placeholder)));	
		return '';
	}
}
