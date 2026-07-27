<!--
# zzwrap
# about translating text
#
# Part of »Zugzwang Project«
# https://www.zugzwang.org/modules/zzwrap
#
# @author Gustaf Mossakowski <gustaf@koenige.org>
# @copyright Copyright © 2026 Gustaf Mossakowski
# @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
#
# Variables
# audience = programmer
-->

# Translating Text

`wrap_text()` looks up a message ID (msgid) in the active language (`.po` files,
optional database) and returns the translation.

## Simple text

```php
wrap_text('The early bird catches the worm.');
```

The English string is the msgid. If no translation exists, the msgid is shown.
If the request language is set to German and the German translation exists, the
user would see

    Der frühe Vogel fängt den Wurm.

## One placeholder

Dynamic parts use `values` for `sprintf()`:

```php
wrap_text('We found %d items.', ['values' => [$count]]);
```

The same can be written as array shorthand (msgid and params in one array):

```php
wrap_text(['We found %d items.', ['values' => [$count]]]);
```

If the translation defines plural forms in .po (msgid_plural), the first numeric
value in values selects the form — e.g. “We found 1 item.” vs “We found 5
items.”

## Several placeholders

Values are applied in order:

```php
wrap_text(
	'From %s to %s',
	['values' => [$date_begin, $date_end]]
);
```

## Context and other parameters

Use `context` when the same English word needs different translations
(gettext msgctxt):

```php
wrap_text('Open', ['context' => 'button']);
wrap_text('Open', ['context' => 'shop']);
```

Other `$params` keys include:

- `lang` — translate into a specific language, not the current language
- `translate_pot` — when a translation is missing, log to `{package}-{value}.pot`
- `prefix` / `suffix` — markup or layout around the translated string (not
  translated themselves; see below)

## Prefix and suffix

Keep HTML out of msgids. Build dynamic attributes in PHP:

```php
wrap_text('Some linktext', [
	'prefix' => '<a href="' . wrap_html_escape($url) . '">',
	'suffix' => '</a>',
]);
```

For several sentences, use a sentence list (each item is `[msgid]` or
`[msgid, $params]`). Per-sentence `prefix` / `suffix` wrap that part only;
list-level `prefix` / `suffix` on the outer call wrap the assembled result:

```php
wrap_text([
	['Transfer failed. Probably you sent a file that was too large.', ['suffix' => '<br>']],
	['Maximum allowed filesize is %s.', ['values' => [wrap_bytes($max)]]],
	['– You sent: %s data.', ['values' => [wrap_bytes($sent)]]],
]);
```

## zzbrick templates

In templates, the `text` brick calls `wrap_text()` for you. Example:

```
%%% text Good things come to those who wait. %%%
```

See [Text brick](zzbrick/Text.md) in the zzbrick module for syntax and
placeholders.
