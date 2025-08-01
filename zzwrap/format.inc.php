<?php 

/**
 * zzwrap
 * Formatting functions for strings and other data
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
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
 * @copyright Copyright © 2007-2025 Gustaf Mossakowski
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
			reset($data);
			$first = key($data);
			if (substr($data[$first], 0, 3) === "\xEF\xBB\xBF") { // UTF8_BOM
				$data[$first] = substr($data[$first], 3);
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
	$encoding = mb_detect_encoding($test_string, mb_detect_order(), true);
	// html_entity_decode() and htmlspecialchars() do not work with ASCII
	if ($encoding === 'ASCII') return 'ISO-8859-1';
	return $encoding;
}

/**
 * set internal character encoding for mulitbyte functions
 *
 * @param string $character_encoding (setting 'character_set')
 * @return bool
 */
function wrap_set_encoding($character_encoding) {
	// note: mb_-functions obiously cannot tell Latin1 from other Latin encodings!
	$iso = str_starts_with($character_encoding, 'iso-8859-') ? strtoupper($character_encoding) : 'ISO-8859-1';
	mb_detect_order(['ASCII', 'UTF-8', $iso]);
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
 * convert array to different encoding
 *
 * @param array $data
 * @param string $encoding
 * @return array
 */
function wrap_convert_encoding($data, $encoding) {
	foreach ($data as $key => &$value) {
		if (is_array($value))
			$value = wrap_convert_encoding($value, $encoding);
		else
			$value = mb_convert_encoding($value, 'UTF-8');
	}
	return $data;
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
	static $characters = [];
	if (!$characters)
		$characters = wrap_tsv_parse('transliteration-characters');
	
	if (is_array($str)) {
		wrap_error('wrap_filename() only accepts strings: '.json_encode($str));
		$str = 'unknown';
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
	$_str = rtrim($_str, $spaceChar);
	// require at least one character
	if (!$_str) $_str = wrap_setting('format_filename_empty') ?? $spaceChar;

	wrap_set_encoding(wrap_setting('character_set'));
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
	if (!$mail) return '';
	$mailto = str_replace('@', '&#64;', urlencode('<'.$mail.'>'));
	$mail = str_replace('@', '&#64;', $mail);
	$output = '<a href="&#109;&#x61;&#105;&#x6c;t&#111;&#x3a;%22'.str_replace(' ', '%20', $person)
		.'%22%20'.$mailto.'"'.$attributes
		.'>'.$mail.'</a>';
	return $output;
}

/**
 * Format an e-mail link, without name
 *
 * @param string $mail
 * @return string
 */
function wrap_mail_format($mail) {
	$mailto = str_replace('@', '&#64;', urlencode($mail));
	$mail = str_replace('@', '&#64;', $mail);
	$output = sprintf('<a href="&#109;&#x61;&#105;&#x6c;t&#111;&#x3a;%s">%s</a>', $mailto, $mail);
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
	if (!$date) return '';

	if (!$format) {
		$format = wrap_setting('date_format');
		if (!$format) {
			wrap_error('Please set at least a default format for wrap_date().
				via setting "date_format" = "dates-de" or so');
			return $date;
		}
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
		$p = ['lang' => $lang, 'context' => 'months'];
		$set['months_long'] = [
			1 => wrap_text('January', $p), 2 => wrap_text('February', $p),
			3 => wrap_text('March', $p), 4 => wrap_text('April', $p),
			5 => wrap_text('May', $p), 6 => wrap_text('June', $p),
			7 => wrap_text('July', $p), 8 => wrap_text('August', $p),
			9 => wrap_text('September', $p), 10 => wrap_text('October', $p),
			11 => wrap_text('November', $p), 12 => wrap_text('December', $p)
		];
		$output_format = 'dates';
		switch ($lang) {
			case 'de':		$set['sep'] = '.'; $set['order'] = 'DMY';
				$set['months_if_no_day'] = $set['months_long'];
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
				break; // dd/mm/yyyy
			case 'pl':		$set['sep'] = '.'; $set['order'] = 'DMY';
				$set['months_if_no_day'] = $set['months_long'];
				if ($type === 'long') $set['sep'] = ['. ', ' '];
				break;
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
			$output = wrap_date_format('0000'.substr($begin, 4), $set, $type).$bis
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
			.'; position: relative; z-index: 10; white-space: pre-wrap;" class="fullarray">';
	} else {
		$out = '';
	}
	ob_start();
	print_r($array);
	$code = ob_get_clean();
	if (str_starts_with($code, 'Array')) {
		$code = ltrim($code, "Array\n(");
		$code = rtrim($code);
		$code = rtrim($code, ")");
	}
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
	if (!$number AND $number !== '0' AND $number !== 0) return '';

	if (!$format) $format = wrap_setting('number_format');
	
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
				wrap_error(wrap_text(
					'Sorry, <strong>%s</strong> appears not to be a valid roman number.'
				, ['values' => wrap_html_escape($input)]), E_USER_NOTICE);
				return '';
			}
		}
		return $output;
	case 'two-decimal-places':
		$output = number_format($number, 2, wrap_setting('decimal_point'), wrap_setting('thousands_separator'));
		return $output;
	case 'simple':
	case 'simple-hidezero':
		if (strstr($number, '.')) {
			if ($format === 'simple-hidezero' AND (str_ends_with($number, '.0') OR str_ends_with($number, '.00') OR str_ends_with($number, '.000'))) {
				$output = number_format($number, 0, wrap_setting('decimal_point'), wrap_setting('thousands_separator'));
			} else {
				$output = number_format($number, 1, wrap_setting('decimal_point'), wrap_setting('thousands_separator'));
			}
		} else {
			$output = number_format($number, 0, wrap_setting('decimal_point'), wrap_setting('thousands_separator'));
		}
		return $output;
	default:
		wrap_error(wrap_text('Sorry, the number format <strong>%s</strong> is not supported.',
			['values' => wrap_html_escape($format)]), E_USER_NOTICE);
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
	$number *= 100;
	$number = number_format($number, 1, wrap_setting('decimal_point'), wrap_setting('thousands_separator'));
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
	if (!$format) $format = wrap_setting('lang');
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
 * return a currency symbol for a currency
 *
 * @param string $currency
 * @return string
 * @todo merge with wrap_money to get correct no of minor units
 * @todo some currencies have different symbols for singular and plural
 */
function wrap_currency($currency) {
	static $currencies = [];
	if (!$currencies)
		$currencies = wrap_tsv_parse('currencies', 'default/custom');

	if (!array_key_exists($currency, $currencies)) return $currency;
	$text = sprintf('<abbr title="%s (%s)">%s</abbr>'
		, $currencies[$currency]['Currency']
		, $currencies[$currency]['Alphabetic Code']
		, $currencies[$currency]['Symbol'] ?? $currencies[$currency]['Alphabetic Code']		
	);
	return $text;
}

/**
 * Escapes unvalidated strings for HTML values (< > & " ')
 *
 * @param string $string
 * @return string
 */
function wrap_html_escape($string) {
	if (!$string) return $string;
	if (is_array($string)) {
		wrap_error(sprintf('wrap_html_escape() only handles strings (%s)', json_encode($string)));
		return '';
	}
	// overwrite default character set UTF-8 because htmlspecialchars will
	// return NULL if character set is unknown
	switch (wrap_setting('character_set')) {
		case 'iso-8859-2': $character_set = 'ISO-8859-1'; break;
		default: $character_set = wrap_setting('character_set'); break;
	}
	$new_string = @htmlspecialchars($string, ENT_QUOTES, $character_set);
	if (!$new_string) $new_string = htmlspecialchars($string, ENT_QUOTES, 'ISO-8859-1');
	return $new_string;
}

/**
 * Escapes strings for JavaScript, replaces new lines with \n plus other special
 * characters
 *
 * backslash, Horizontal Tabulator, Vertical Tabulator, Form Feed, New Line,
 * Carriage Return, Nul char are replaced, Backspace \b not supported,
 * @param string $string
 * @return string
 */
function wrap_js_escape($string) {
	$string = addcslashes($string, "\\\v\t\f\n\r\0'\"");
	return $string;
}

/**
 * Escapes strings for JavaScript, replaces new lines with <br>\n plus other 
 * special characters
 *
 * @param string $string
 * @return string
 */
function wrap_js_nl2br($string) {
	$string = nl2br($string, false);
	$string = wrap_js_escape($string);
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
    if (!is_numeric($value)) return $value;
    $value = max($value, 0);
    $pow = floor(($value ? log($value) : 0) / log($factor)); 
    $pow = min($pow, count($units) - 1); 
	// does unit for this exist?
	while (!isset($units[$pow])) $pow--;
	$value /= pow($factor, $pow);

    $text = round($value, $precision) . html_entity_decode('&nbsp;') . $units[$pow]; 
    if (wrap_setting('decimal_point') !== '.')
    	$text = str_replace('.', wrap_setting('decimal_point'), $text);
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
    if (!wrap_is_int($bytes)) $bytes = wrap_byte_to_int($bytes);
	return wrap_unit_format($bytes, $precision, $units, 1024);
}

/**
 * format abbreviated byte sizes into integer values
 *
 * @param string $value
 * @return int
 */
function wrap_byte_to_int($value) {
	switch (substr($value, -1)) {
		case 'K': return substr($value, 0, -1) * 1024;
		case 'M': return substr($value, 0, -1) * pow(1024, 2);
		case 'G': return substr($value, 0, -1) * pow(1024, 3);
		case 'T': return substr($value, 0, -1) * pow(1024, 4);
		case 'P': return substr($value, 0, -1) * pow(1024, 5);
		case 'E': return substr($value, 0, -1) * pow(1024, 6);
		case 'Z': return substr($value, 0, -1) * pow(1024, 7);
		case 'Y': return substr($value, 0, -1) * pow(1024, 8);
		case 'R': return substr($value, 0, -1) * pow(1024, 9);
		case 'Q': return substr($value, 0, -1) * pow(1024, 10);
	}
	return $value;
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
	if (strstr($value, '/')) {
		$value = explode('/', $value);
		$value = $value[0]/$value[1];
	}
	if ($value < 0) $value = 360 - $value;
	$text = round($value, $precision).'° ';
    if (wrap_setting('decimal_point') !== '.')
    	$text = str_replace('.', wrap_setting('decimal_point'), $text);
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
 * @param string $format
 * @return string
 */
function wrap_latitude($value, $format = 'dms') {
	return wrap_coordinate($value, 'lat', $format);
}

/**
 * format a geographical coordinate (longitude)
 * 
 * @param double $value
 * @param string $format
 * @return string
 */
function wrap_longitude($value, $format = 'dms') {
	return wrap_coordinate($value, 'lon', $format);
}

/**
 * output of a geographical coordinate (latitude or longitude)
 * 19°41'59"N 98°50'38"W / 19.6996°N 98.8440°W / 19.6996; -98.8440
 *
 * @param double $value value of coordinate, e. g. 69.34829922
 * @param string $orientation ('lat' or 'lon')
 * @param string $format output
 *		'dms' = degree + minute + second; 'deg' = degree, 'dm' = degree + minute,
 *		'dec' = decimal value; all may be appended by =other, e. g. dms=deg
 * @return string
 */
function wrap_coordinate($value, $orientation, $format = 'dms') {
	if ($value === NULL) return '';
	if ($value === false) return '';
	
	$hemisphere = ($value >= 0) ? '+' : '-';
	if ($value < 0) $value = substr($value, 1); // get rid of - sign)
	switch ($orientation) {
		case 'latitude':
		case 'lat':
			$hemisphere_text = $hemisphere === '+' ? 'North' : 'South';
			break;
		case 'longitude':
		case 'lon':
			$hemisphere_text = $hemisphere === '+' ? 'East' : 'West';
			break;
		default:
			return '';
	}
	$hemisphere_text = wrap_text(substr($hemisphere_text, 0, 1), ['context' => $hemisphere_text]);
/*
	@todo allow HTML abbreviation, but this would rather be in zzwrap/format.inc.php
	$hemisphere_text = sprintf('<abbr title="%s">%s</abbr>'
		, wrap_text($hemisphere_text), wrap_text(substr($hemisphere_text, 0, 1), ['context' => $hemisphere_text])
	);
*/

	// Output in desired format
	$coord = [];
	$formats = explode('=', $format);
	foreach ($formats as $format) {
		switch ($format) {
		case 'o':
			$coord[] = $hemisphere_text;
			break;
		case 'deg':	// 98.8440°W
			$coord[] = wrap_coordinate_decimal($value).'&#176;'.wrap_setting('geo_spacer').$hemisphere_text;
			break;
		case 'dec':	// -98.8440
			$coord[] = $hemisphere.wrap_coordinate_decimal($value);
			break;
		case 'dm':	// 98°50.6333'W
			$min = wrap_coordinate_decimal(round(($value - floor($value)) * 60, wrap_setting('geo_rounding')));
			$coord[] = floor($value).'&#176;'.wrap_setting('geo_spacer').($min ? $min.'&#8242;'.wrap_setting('geo_spacer') : '').$hemisphere_text;
			break;
		case 'dms':	// 98°50'38"W
		default:
			if (!is_numeric($value)) return $value;
			// transform decimal value to seconds and round first!
			$deg = intval($value);
			$sec = round(($value - $deg) * 3600, wrap_setting('geo_rounding'));
			$min = intval($sec) - (intval($sec) % 60); // min in seconds
			// sec can now have up to 14 decimal places because of float operations, round last two to get sensible value
			$sec = round($sec - $min, 12);
			$min /= 60;
			$coord[] = $deg.'&#176;'.wrap_setting('geo_spacer')
				.(($min OR $sec) ? $min.'&#8242;'.wrap_setting('geo_spacer') : '')
				.($sec ? wrap_coordinate_decimal($sec).'&#8243;'.wrap_setting('geo_spacer') : '')
				.$hemisphere_text;
			break;
		}
	}
	if (!$coord) return false;
	if (count($coord) == 1)
		$coord = $coord[0];
	else
		$coord = '('.$coord[0].' = '.$coord[1].')';
	return $coord;
}

/**
 * formats a number depending on language with . or ,
 *
 * @param string $number
 * @return string $number
 */
function wrap_coordinate_decimal($number) {
	// replace . with , where appropriate
	$number = str_replace('.', wrap_setting('decimal_point'), $number);
	return $number;
}

/**
 * normalizes input to NFC
 * Canonical normalization
 *
 * @param string $input
 * @return string
 */
function wrap_normalize($input) {
	static $replacements = [];

	if (wrap_setting('character_set') !== 'utf-8') return $input;
	if (!$input) return $input;
	if (is_numeric($input)) return $input;
	if (!is_string($input)) return $input; // e. g. subrecords

	if (class_exists("Normalizer", $autoload = false)) {
		$output = normalizer_normalize($input, Normalizer::FORM_C);
		if (!$output) return $input;
		return $output;
	}
	
	if (!$replacements) {
		$normalization = wrap_tsv_parse('unicode-normalization');
		foreach ($normalization as $line) {
			if ($line[0] === '-') continue;
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
	if (!function_exists('idn_to_ascii')) {
		wrap_error(sprintf(
			'Need function `idn_to_ascii` to check value %s, but it does not exist.'
			, $string
		));
		return $string;
	}
	return idn_to_ascii($string);
}

/**
 * convert punycode to UTF-8 string
 *
 * @param string $string
 * @return string
 */
function wrap_punycode_decode($string) {
	$host = parse_url($string, PHP_URL_HOST);
	if (!$host) return $string;
	$subdomains = explode('.', $host);
	foreach ($subdomains as $index => $part) {
		if (substr($part, 0, 4) !== 'xn--') continue;
		if (!function_exists('idn_to_utf8')) {
			wrap_error('missing function `idn_to_utf8`', E_USER_NOTICE);
			continue;
		}
		$subdomains[$index] = idn_to_utf8($part);
	}
	$host_new = implode('.', $subdomains);
	if ($host_new === $host) return $string;
	$string = str_replace($host, $host_new, $string);
	return $string;
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
	if (!$format) $format = wrap_setting('duration_format');

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
			else $out[] = wrap_text('%d '.$type.'s', ['values' => $count]);
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
 * @param string $lang (optional, uses setting 'lang' as default)
 * @return string
 */
function wrap_weekday($day, $lang = '') {
	switch ($day) {
		case 1: $short = 'Sun'; break;
		case 2: $short = 'Mon'; break;
		case 3: $short = 'Tue'; break;
		case 4: $short = 'Wed'; break;
		case 5: $short = 'Thu'; break;
		case 6: $short = 'Fri'; break;
		case 7: $short = 'Sat'; break;
	}
	if (isset($short))
		return wrap_text($short, ['lang' => $lang, 'context' => 'weekdays']);
	return $day;
}

/**
 * replace weekday abbreviations for a data list and certain field names
 *
 * @param array $data data indexed by ID
 * @param array $fields list with name of fields
 * @param string $lang (optional, uses setting 'lang' as default)
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
	if (!trim($text)) return $text;
	$new_text = '';
	$skip = 0;
	$html_open = false;
	$placeholder_open = false;
	$quotation_marks_open_single = false;
	$quotation_marks_open_double = false;
	$is_url = false;

	if (!$lang) {
		if (wrap_setting('default_source_language'))
			$lang = wrap_setting('default_source_language');
		else
			$lang = wrap_setting('lang');
	}
	$quotation_marks_format = wrap_setting('quotation_marks['.$lang.']') ?? $lang;

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
		if ($letter === '<' AND mb_substr($text, $i, 7) === '<script') {
			$skip = mb_strpos($text, '</script>', $i) - $i + mb_strlen('</script>');
			$new_text .= mb_substr($text, $i, $skip);
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
		case 'x':
			if (preg_match('/^ x $/', mb_substr($text, $i -1, 3))) {
				$letter = '×';
			}
			break;
		default:
			break;
		}
		$new_text .= $letter;
	}

	// non-breaking spaces
	$nbsp = ' '; // this is not a space but a non-breaking space
	$cfg = wrap_cfg_files('nbsp-'.$lang);
	if ($cfg) {
		if (!empty($cfg['abbreviations'])) {
			foreach ($cfg['abbreviations'] as $abbr => $explanation) {
				if (!stristr($new_text, $abbr)) continue;
				// @todo: \W*…\W*
				// @todo: support ucfirst() for abbreviations at beginning of sentence
				$pattern = sprintf('/%s/', str_replace('.', '\.', $abbr)); 
				$replace = str_replace(" ", $nbsp, $abbr);
				$new_text = preg_replace($pattern, $replace, $new_text);
			}
		}
		// @todo add missing spaces in abbreviations
		// @todo support units

		// @todo somewhere else, allow to replace these abbreviations with abbr title="" for output
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
	switch ($placeholder) {
	case 'mysql_date_format':
		switch (wrap_setting('lang')) {
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

/**
 * add quotes as necessary for .cfg files
 *
 * @param string $string
 * @return string
 */
function wrap_cfg_quote($string) {
	$quote = false;
	$quoted_strings = [
		'(', ')', "\n", "\r", "'", "no", "yes", " ", "none"
	];
	$string = trim($string);
	foreach ($quoted_strings as $quoted) {
		if (!stristr($string, $quoted)) continue;
		return sprintf('"%s"', $string);
	}
	return $string;
}

/**
 * get profile links from profiles (setting or configuration)
 *
 * reads data from profiles.cfg, will be overwritten with general setting `profiles`
 * via settings table, modules.json or wrap_setting()
 *
 * format:
 * [unique_internal_key]
 * url = https://example.org/%s
 * title = profile for club on example.org
 * scope[] = contact/club
 * active = 1
 * fields[] = identifiers[identifier]
 * fields_scope = identifiers/code
 * @param array $data
 *		requires fields category_id, identifier, further fields in fields[] definition
 * @return array
 */
function wrap_profiles($data) {
	$profiles = wrap_cfg_files('profiles');
	$profiles = wrap_array_merge($profiles, wrap_setting('profiles'));

	$my_profiles = [];
	foreach ($profiles as $profile) {
		if (!is_array($profile)) $profile = ['url' => $profile];
		if (isset($profile['active']) AND !$profile['active']) continue;
		if (isset($profile['scope'])) {
			$scope_found = false;
			foreach ($profile['scope'] as $scope) {
				if ($data['category_id'] !== wrap_category_id($scope)) continue;
				$scope_found = true;
			}
			if (!$scope_found) continue;
		}
		$title = isset($profile['title']) ? wrap_profiles_lang($profile['title']) : NULL;
		$url = wrap_profiles_lang($profile['url']);
		$fields = $profile['fields'] ?? ['identifier'];
		$values = [];
		foreach ($fields as $field) {
			if (strstr($field, '[')) {
				$field = explode('[', $field);
				if (!array_key_exists($field[0], $data)) continue 2;
				$field[1] = rtrim($field[1], ']');
				foreach ($data[$field[0]] as $line) {
					if (!isset($line[$field[1]])) continue 3;
					if (!isset($profile['fields_scope'])) {
						$values[] = $line[$field[1]];
						continue 3;
					}
					if ($line['category_id'] !== wrap_category_id($profile['fields_scope'])) continue;
					$values[] = $line[$field[1]];
				}
			} else {
				if (!array_key_exists($field, $data)) continue 2;
				$values[] = $data[$field];
			}
		}
		if (!$values) continue;
		$url = vsprintf($url, $values);
		$my_profiles[] = ['title' => $title, 'url' => $url];
	}
	return $my_profiles;
}

/**
 * get correct key per language
 *
 * @param mixed $value
 * @return string
 */
function wrap_profiles_lang($value) {
	if (!is_array($value)) return $value;
	// language?
	if (array_key_exists(wrap_setting('lang'), $value)) return $value[wrap_setting('lang')];
	// language not found, return first element
	return reset($value);
}

/**
 * hyphenate some very long strings (add &shy)
 * (if hyphens: auto is not wanted or hyphens: manual is used)
 *
 * @param string $word
 * @return string
 */
function wrap_hyphenate($word) {
	foreach (wrap_setting('hyphenate_before') as $string) {
		if (!strstr($word, $string)) continue;
		$word = str_replace($string, '&shy;'.$string, $word);
	}
	foreach (wrap_setting('hyphenate_after') as $string) {
		if (!strstr($word, $string)) continue;
		$word = str_replace($string, $string.'&shy;', $word);
	}
	return $word;
}

/**
 * adds id to headings
 *
 * @param string $text
 * @return string
 */
function wrap_heading_id($text) {
	if (!$text) return $text;
	$text = preg_replace_callback('~###(.*)~', 'wrap_heading_id_set', $text);
	$text = markdown($text);
	return $text;
}

function wrap_heading_id_set($string) {
	return trim($string[0]).' {#'.strtolower(wrap_filename($string[1])).'}';
}

/**
 * normalize file path, i. e. get rid of .. and .
 *
 * @param mixed $paths
 * @param string $return return as string or array, defaults to string
 * @return mixed
 */
function wrap_filepath($paths, $return = 'string') {
	if (!$paths) return $paths;
	if (!is_array($paths))
		$paths = [$paths];
	else
		$return = 'multiarray';

	foreach ($paths as $index => $path) {
		$start_slash = str_starts_with($path, '/') ? '/' : '';
		$parts = array_filter(explode('/', $path), 'strlen');
		// get rid of .. and .
		$folders = [];
		foreach ($parts as $part) {
			if ($part === '.') continue;
			if ($part === '..')
				array_pop($folders);
			else
				$folders[] = $part;
		}
		$new_paths[$index] = $folders;
		$new_paths_combined[$index] = $start_slash.implode('/', $folders);
	}
	switch ($return) {
		case 'array': return reset($new_paths);
		case 'multiarray': return $new_paths_combined;
		default: return reset($new_paths_combined);
	}
}

/**
 * clean a string for e-mail
 *
 * @param string $string
 * @return string
 */
function wrap_mailclean($string) {
	if (strstr($string, '.')) $string = str_replace('.', ' ', $string);
	return $string;
}
