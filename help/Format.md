<!--
# zzwrap
# about formatting
#
# Part of »Zugzwang Project«
# https://www.zugzwang.org/modules/zzwrap
#
# @author Gustaf Mossakowski <gustaf@koenige.org>
# @copyright Copyright © 2026 Gustaf Mossakowski
# @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
#
-->

# Formatting

Each module and the custom project may define a `format.inc.php`. zzwrap
includes `zzwrap/format.inc.php`; packages use `/[package]/format.inc.php`,
custom uses `/custom/format.inc.php`. Public functions from these files
are registered for use in zzbrick templates: the package prefix is
stripped and the rest becomes the format name.

    wrap_date($value)              → format=date           (zzwrap)
    mf_tournaments_result_format() → format=result_format  (tournaments)
    my_price_format()              → format=price_format   (custom)

Prefixes: `wrap_` (zzwrap), `mf_[module]_` (modules), `my_` (custom).

## Templates

Use formatting inside `%%% … %%%` placeholders with a `format=` setting:

    %%% item date_begin format=date %%%
    %%% item title format=html_escape %%%
    %%% item price format=money "%s €" %%%

Pass extra arguments to the formatter after a colon:

    %%% item count format=number:two-decimal-places %%%

Several formatters can be chained:

    format=[html_escape,markdown]

If the value is empty, formatting is skipped.

## Dates

`wrap_date($date, $format = false)` formats an ISO date (`YYYY-MM-DD`) or
a period (`YYYY-MM-DD/YYYY-MM-DD`). Without a format argument, the
`date_format` setting is used (typically `dates-de`).

Common format strings:

- `dates-de` — `12.03.2004`, ranges like `12.–14.03.2004`
- `dates-de-plain` — same, without the outer `<span class="date">`
- `dates-de-weekday` — short weekday before the date
- `rfc1123->datetime`, `timestamp->rfc1123` — convert between representations

    %%% item duration format=date %%%

`wrap_date_plain($date)` — like `wrap_date()`, but always plain text (uses
the `date_format` setting with `-plain` appended).

    %%% item duration format=date_plain %%%

`wrap_period($period)` — date/time period in ISO form, optionally with
`T` times (`2004-03-12T14:00/2004-03-14T18:00`). Weekdays are shown;
seconds are omitted.

    %%% item duration format=period %%%

### Weekdays

Weekday formatters take an **ISO date** (`YYYY-MM-DD`), not a day number.
Do not pass SQL `DAYOFWEEK` / `WEEKDAY` values or PHP `date('w')` /
`date('N')` numbers at call sites — pass the date string and let zzwrap
derive the weekday.

`wrap_weekday($date, $lang = null)` — abbreviated weekday (`Mon`, `Di`, …).

`wrap_weekday_long($date, $lang = null)` — full name (`Monday`, `Montag`, …).

Weekday names are loaded from `configuration/weekdays.tsv` via
`wrap_reference('weekdays', …)`.

Translations use gettext context `weekdays_short` for abbreviations (`Mon`,
`Di`, …) and `weekdays` for full names (`Monday`, `Montag`, …).

    %%% item date_begin format=weekday %%%
    %%% item date_begin format=weekday_long %%%

### Date with weekday

For a single date, prefer the combined formatters over two separate
modifiers:

`wrap_date_weekday($date, $lang = null)` — short weekday plus date,
separated by a space (`Mo 17.06.2026`).

`wrap_date_weekday_long($date, $lang = null)` — long weekday plus date,
separated by a comma and space (`Montag, 17.06.2026`).

    %%% item date_begin format=date_weekday %%%
    %%% item date_begin format=date_weekday_long %%%

### Date with weekday prefix

Pass `weekday` as part of a `dates-*` format string to prepend the short
weekday before the formatted date:

    wrap_date('2026-06-17', 'dates-de-weekday');

Output (German): `<span class="date"><span class="weekday">Mi</span> 17.06.2026</span>`.

Use `dates-de-plain-weekday` for the same layout without the outer
`<span class="date">` wrapper. Call from PHP or pass the format via
`format=date:dates-de-weekday`.

### Ranges

Combined weekday+date formatters apply to **one** date. For a date range
where begin and end weekdays differ, keep separate fields or modifiers:

    %%% item date_begin format=weekday %%%–%%% item date_end format=weekday %%%
    %%% item duration format=date %%%

## Times

`wrap_time($time, $format = false)` — format a time string; default output
format is `H:i`.

    %%% item time_begin format=time %%%

## Numbers and money

`wrap_number($number, $format = false)` — format a number. Default comes
from the `number_format` setting (`simple`). Other formats:

- `two-decimal-places`
- `simple-hidezero`
- `roman->arabic`, `arabic->roman`

    %%% item contact_count format=number %%%

`wrap_percent($number)` — multiply by 100, one decimal place, append `%`.

    %%% item share format=percent %%%

`wrap_money($number, $format = false)` — format money; default locale from
`lang` (`de`: `1.234,56`, `en`: `1,234.56`).

    %%% item price format=money %%%

`wrap_currency($currency)` — currency code to `<abbr>` with symbol
(from `currencies.tsv`).

    %%% item currency format=currency %%%

## Units

`wrap_bytes($bytes, $precision = 1)` — human-readable byte size (`KB`, `MB`, …).

`wrap_gram($gram, $precision = 1)` — mass (`mg`, `g`, `kg`, …).

`wrap_meters($meters, $precision = 1)` — length (`mm`, `m`, `km`, …).

    %%% item filesize format=bytes %%%

## Coordinates

`wrap_latitude($value, $format = 'dms')` — latitude.

`wrap_longitude($value, $format = 'dms')` — longitude.

`wrap_bearing($value, $precision = 1)` — compass bearing in degrees with
abbreviation and full name in `title`.

    %%% item lat format=latitude %%%
    %%% item lon format=longitude %%%

## Text and escaping

`wrap_html_escape($string)` — escape `<`, `>`, `&`, quotes for HTML text
and attributes.

`wrap_js_escape($string)` — escape for JavaScript string literals.

`wrap_js_nl2br($string)` — like `nl2br`, but safe inside JS.

`wrap_hyphenate($word)` — insert soft hyphens (`&shy;`) for long words.

`wrap_heading_id($text)` — slug suitable for HTML `id` attributes.

`wrap_typo_cleanup($text, $lang = '')` — typographic fixes (quotes, dashes,
non-breaking spaces).

`wrap_word_cut($str, $max_length = 0, $suffix = '…')` — truncate with suffix.

`wrap_cfg_quote($string)` — quote a string for use in config files.

`wrap_punycode_decode($string)` / `wrap_punycode_encode($string)` — IDNA
domain encoding.

    %%% item title format=html_escape %%%
    %%% item comment format=js_escape %%%

## Mail

`wrap_mail_format($mail)` — obfuscated `mailto:` link for an address.

    %%% item email format=mail_format %%%

`wrap_mailto($person, $mail, $attributes = false)` — link with display name;
call from PHP (needs two values, not a single template field).

`wrap_mailclean($string)` — strip unwanted characters from mail addresses.

## Duration and misc

`wrap_duration($duration, $unit = 'second', $format = '')` — human-readable
duration (`long`) or `H:i` for hours and minutes. Default from
`duration_format` setting.

    %%% item seconds format=duration %%%

`wrap_calc($value)` — evaluate simple arithmetic expressions (`86400*7`).

`wrap_filename($str, $spaceChar = '-', $replacements = [])` — safe filename
or URL segment.

`wrap_placeholder($placeholder)` — resolve a placeholder name.

`wrap_filepath($paths, $return = 'string')` — join path parts.

`wrap_profiles($data)` — format profile data for display.

`wrap_normalize($input)` — normalize Unicode strings.

`wrap_byte_to_int($value)` — parse abbreviated byte sizes (`128M`) to integer.

## PHP-only helpers

These functions in `format.inc.php` are not intended as template formatters:

- `wrap_convert_string()`, `wrap_convert_encoding()`, `wrap_detect_encoding()`,
  `wrap_set_encoding()` — character encoding
- `wrap_type_convert()`, `wrap_numeric()` — type coercion
- `wrap_print()`, `wrap_print_simple()` — debug output
