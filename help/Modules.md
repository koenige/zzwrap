<!--
# zzwrap
# about modules
#
# Part of »Zugzwang Project«
# https://www.zugzwang.org/modules/zzwrap
#
# @author Gustaf Mossakowski <gustaf@koenige.org>
# @copyright Copyright © 2024-2025 Gustaf Mossakowski
# @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
#
-->

# Modules

## Installation

Modules are installed inside the folder `_inc/modules`. Each module must
have a unique name. It is recommended to install them as submodule via
`git`. If downloaded from git then run: `git submodule update --init`

## Creation of a module

This is the recommended folder structure for a module called `example`:

- `example/behaviour`
- `example/configuration`
- `example/docs`
- `example/example` (i. e. the name of the module)
- `example/help`
- `example/languages`
- `example/layout`
- `example/libraries`
- `example/templates`
- `example/zzbrick_forms`
- `example/zzbrick_make`
- `example/zzbrick_page`
- `example/zzbrick_placeholder`
- `example/zzbrick_request`
- `example/zzbrick_request_get`
- `example/zzbrick_rights`
- `example/zzbrick_tables`
- `example/zzbrick_xhr`
- `example/zzform`

For a start, you normally don’t need all these folders.

### Files inside main folder

- `/config.inc.php` – local configuration, @deprecated
- `/README.md` – ReadMe
- `/LICENSE` – License

### Folder `/behaviour`

This folder is for your own JavaScript files. It is recommended to put
complete external JavaScript libraries inside the folder
`/www/_behaviour`. A file `/behaviour/test.js` can be accessed via this
URL: `example.org/_behaviour/example/test.js`. It is treated as a
zzbrick template, you can put %%% blocks inside it.

### Folder `/configuration`

- `/configuration/install.sql` SQL install script
- `/configuration/queries.sql` module SQL queries
- `/configuration/system.sql` system SQL queries
- `/configuration/update.sql` SQL update script, indexed with comment by date
and sequence, e. g. `/* 2020-09-10-1 */` followed by a tab
- `/configuration/settings.cfg` INI file with possible settings for this
module (recommended: use module name as prefix for settings)
- `/configuration/access.cfg` INI file with access settings
- `/configuration/modules.cfg` INI file with settings of other modules
changed here.
- further .cfg or .tsv files

### Folder `/docs`

- `/docs/changelog.tsv` Changelog for important changes. Fields: date +
index; type of changes; description of changes

### Folder `/example`

This folder has the same name as the module itself.

- `/example/_functions.inc.php` - Common functions which are always
included. It is recommended to use `mf_` plus the module name, i. e.
here `mf_example_`, as a prefix for each function.
- `/example/functions.inc.php` - Common functions which are only
included if the module was activated
- `/example/format.inc.php` – Formatting functions. Functions in this
file are automatically registered as brick formatting functions.
- `/example/search.inc.php` – Use to include search results from module.
- further functions: connecting functions for other modules should be
inside a file called `[module].inc.php`

### Folder `/help`

Help texts, either in Markdown formatting or text files.

### Folder `/languages`

This folder might contain language files:

- `/languages/example.pot` - PO template file
- `/languages/example-de.po` - German language PO file
- `/languages/example-de-informal.po` - German language PO file,
informal variant

### Folder `/layout`

This folder is for your own layout files, CSS and graphics. A file
`/layout/test.css` can be accessed via this URL:
`example.org/_layout/example/test.css`. It is treated as a zzbrick
template, you can put %%% blocks inside it.

CSS files with the same name as the module are included automatically if
`page packagecss` is used.

### Folder `/libraries`

Helper functions for libraries.

### Folder `/templates`

For webpage and mail templates for use with `zzbrick`

- `example-page.template.txt` complete HTML page (template name ending
with `-page`)
- `example1.template.txt` HTML fragment; template with equal name in
`/templates/` folder overwrites this template
- `example1-de.template.txt` HTML fragment; German version (if exists,
is preferred over `example1.template.txt`) – use with ISO codes and
language variants, e. g. `de-informal` for German with “Du”
- `feedback-mail.template.txt` Mail template, first lines can be mail
headers like `Subject: Test`, empty line separates mail header lines
from body

### Folder `/zzbrick_forms`

Form definitions, based on table definitions in folder
`/zzbrick_tables`, for use with `zzform` module.

- Filename: `table_name.php`
- Include in template via `%%% forms table_name %%%`

### Folder `/zzbrick_make`

Make functions. Similar to request functions below, these functions
change data via POST requests.

- Function names: start with `mod_example_make_`
- Filename for `mod_example_make_test()` function is `test.inc.php`
- Include in template via `%%% make test %%%`

### Folder `/zzbrick_page`

Page functions.

- Function names: start with `page_`
- Filename for `page_test()` function is `test.inc.php`
- Include in template via `%%% page test %%%`

### Folder `/zzbrick_placeholder`

Placeholder functions.

- `[placeholder].inc.php` – function that gets data for placeholder, 
e. g. `request timetable * *=event`, reading common fields from database
and setting breadcrumbs etc.

### Folder `/zzbrick_request`

Request functions.

- Function names: start with `mod_example_`
- Filename for `mod_example_test()` function is `test.inc.php`
- Include in template via `%%% request test %%%`

### Folder `/zzbrick_request_get`

Request/Get functions. If setting `brick_cms_input` is active, a
function in this folder is automatically used for request functions to
read data.

- Function names: start with `mod_example_get`

### Folder `/zzbrick_rights`

- `access_rights.inc.php` – define access rights with `brick_access_rights()`
- `usergroups.inc.php` – register usergroups

### Folder `/zzbrick_tables`

Definition files for single tables (or tables with subtables) for use
with `zzform` module.

- Filename: `table_name.php`
- Include in template via `%%% tables table_name %%%`

### Folder `/zzbrick_xhr`

XHR functions.

### Folder `/zzform`

- `batch.inc.php` – batch functions
- `definition.inc.php` – re-used table definitions, e. g. for detail
tables
- `editing.inc.php` – functions used in table definition scripts to
reformat values when validating input
- `hooks.inc.php` – Hook functions for zzform module, called
after/before upload, insert, update or delete

It is recommended to use `mf_` plus the module name, i. e. here
`mf_example_`, as a prefix for each function.
