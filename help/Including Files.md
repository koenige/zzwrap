<!--
# zzwrap
# Including files
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

# Including files

Use `wrap_include()` to load PHP files from modules, themes, or the
custom folder. By default, `.inc.php` is appended to the file name; you
can pass another extension explicitly (e.g. `zzform.php`). The function
returns metadata about included packages and the functions defined in
each file.

## Path resolution

For a module or theme package, files are resolved as:

    {modules_dir}/{package}/{path}/{name}.inc.php

If `$filename` contains a `.`, that file name is used as given (e.g.
`zzform.php` → `…/zzform/zzform.php`, not `zzform.php.inc.php`).

- **`$filename`** — file name; omit `.inc.php` unless you need another
  extension. May contain a subpath (e.g. `data/contacts`,
  `zzbrick_request/objects`).
- **`$path`** — folder inside the package. If `$filename` has no `/`, the
  inner module folder is used: `$path = $package` (e.g. `estate/estate/…`).
- If `$filename` includes a path, that path is used instead of the inner
  module folder.

### Examples

| Call | Resolves to |
|------|-------------|
| `wrap_include('objectfilter', 'estate')` | `_inc/modules/estate/estate/objectfilter.inc.php` |
| `wrap_include('objects', 'estate')` | `_inc/modules/estate/estate/objects.inc.php` |
| `wrap_include('_functions', 'estate')` | `_inc/modules/estate/estate/_functions.inc.php` |
| `wrap_include('data/contacts', 'contacts')` | `_inc/modules/contacts/data/contacts.inc.php` |
| `wrap_include('zzbrick_request/objects', 'estate')` | `_inc/modules/estate/zzbrick_request/objects.inc.php` |
| `wrap_include('zzform.php', 'zzform')` | `_inc/modules/zzform/zzform/zzform.php` |

## Second argument: where to search

The second argument (`$paths`) controls which packages are searched. It
defaults to `'custom/modules'`.

| Value | Behaviour |
|-------|-----------|
| Single package name (e.g. `'contacts'`) | That module or theme only |
| `'custom'` | `_inc/custom/custom/{name}.inc.php` (or `_inc/custom/{path}/{name}.inc.php` if `$filename` contains a path) |
| `'modules'` | All installed modules (order follows module list) |
| `'custom/modules'` | Custom folder first, then all modules (default) |
| `'custom/modules/themes'` | Custom, modules, and themes — used at bootstrap for `_functions.inc.php` |

Search terms can be combined with `/`, e.g. `'custom/modules/themes'`.
Order matters: the first matching location wins when a single package is
requested; combined searches follow the sequence of terms.

## Function prefixes

Functions registered from an included file are mapped to a package prefix
via `wrap_function_prefix()`:

| Package | Prefix |
|---------|--------|
| `custom` | `my_` |
| `zzwrap` | `wrap_` |
| `zzform` | `zz_` |
| other modules (e.g. `estate`, `contacts`) | `mf_{package}_` |

Use these prefixes for functions in module code (see [Modules](modules)).

## Return value

`wrap_include()` returns an array (empty if no file was found):

    [
        'packages' => [ 'contacts' => '/path/to/contacts.inc.php', … ],
        'functions' => [ … ],
    ]

### `packages`

Maps each package name to the absolute path of the included file.

### `functions`

Lists user functions **newly defined** by the include (not already loaded
before). Each entry may contain:

| Key | Meaning |
|-----|---------|
| `function` | Full PHP function name (e.g. `mf_contacts_edit_contact_name`) |
| `package` | Package that defined the function |
| `private` | Set if the name starts with `_` (file-local helper) |
| `short` | Function name **without** the package prefix — only set when the name starts with that package’s prefix (see above) |
| `prefix` | Package prefix without trailing `_` (e.g. `mf_contacts` for `mf_contacts_…`) |

The **`short`** name is what you use to refer to a function without
hard-coding the package prefix. Example: `mf_contacts_edit_contact_name`
→ `short` = `edit_contact_name`, `prefix` = `mf_contacts`.

Functions without the expected prefix (or with a leading `_`) are still
listed under `function`, but have no `short` name.

### Finding functions: `wrap_functions()`

Pass the return value of `wrap_include()` to `wrap_functions()` to look up
functions by **`short`** name:

    $files = wrap_include('data/contacts', 'contacts');
    foreach (wrap_functions($files, 'contacts') as $fn) {
        $data = $fn['function']($data, $ids);
    }

- Exact match: `wrap_functions($files, 'edit_contact_name')`
- Prefix match: `wrap_functions($files, 'logging*')` — matches all short
  names starting with `logging`; optional `suffix` key holds the rest
  after the next `_`

Private functions (`_…`) are skipped. This pattern is used e.g. for
`data/*.inc.php` collectors, `format.inc.php` registration, and zzform
hooks (`{filename}_init`, `{filename}_finish`).

## Always loaded vs on demand

**Always loaded at startup**

- `_functions.inc.php` from every active package — via
  `wrap_include('_functions', 'custom/modules/themes')` in the zzwrap
  bootstrap (`zzwrap.php`).

Put shared helpers here that many requests may need without an explicit
include.

**Loaded when a module is activated**

- `functions.inc.php` of that module — via
  `wrap_include('functions', $package)` in `wrap_package_activate()`.

Activation happens when the module is used, e.g. when a template from
that package is rendered. The first activated module becomes
`active_module`. Put module-specific helpers here that should be
available for the rest of the request once the module is active.

**Loaded on demand**

- Any other file — call `wrap_include('name', 'module')` (or another
  `$paths` value) only where needed.

Examples: `format.inc.php`, `data/*.inc.php`, brick scripts under
`zzbrick_*`, zzform helpers under `zzform/`.
