<!--
# zzwrap
# about database structure
#
# Part of »Zugzwang Project«
# https://www.zugzwang.org/modules/zzwrap
#
# @author Gustaf Mossakowski <gustaf@koenige.org>
# @copyright Copyright © 2025-2026 Gustaf Mossakowski
# @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
#
# Variables
# audience = programmer
-->

# Database structure

## Naming conventions

- Table names always English, plural form (e. g. `events`)
- Table names are a single word without underscores (e. g.
`bankaccounts`, not `bank_accounts`); the underscore is reserved for
m:n tables, so the type of a table can be read from its name
- M:n table names always with the main table in front, the related table
attached (e. g. `events_media`). If you have doubts which table name
comes first: for `events_media`, if you delete an event, you would have
the entry in `events_media` deleted, too. If you delete a medium, there
should be a block saying no. Therefore, it’s `events_media`, not
`media_events`. Key would be accordingly `event_medium_id`.
- The order of the tables in an m:n table name follows the main table
rule above, it is not alphabetical, e. g. `positions_worklogs` or
`vouchers_events`.
- PRIMARY KEY always singular (e. g. `event_id`); for m:n tables, both
table names in singular (e. g. `event_medium_id`)
- If possible, use a field that is the main field, naming it in singular
(e. g. `event` for `events` or `note` for `notes`)
- Foreign keys should end with the name of the primary key of the
foreign table. E. g. `contact_id` in m:n tables or `contact_category_id`
to define a foreign key further.
- If a table references the same foreign table more than once, prefix
each foreign key with its role, e. g. `vendor_contact_id` and
`customer_contact_id`.

## Which field names to use

- `identifier` VARCHAR(63-255) – use this field if a record will be
accessed publicly via a URL, use zzform field type `identifier` to
automatically generate a value for this field.
- `created` DATETIME - when record was first created
- `last_update` TIMESTAMP - automatic timestamp, should be last field
- `published` ENUM('yes','no') - for public visibility control
- `active` ENUM('yes','no') - for enable/disable flags
- `sequence` TINYINT UNSIGNED - for ordering/sorting, e. g. in m:n
tables
- `parameters` VARCHAR(255-750) - for additional configuration data,
zzform field type `parameters`
- `remarks` TEXT - for general notes and comments
- `abstract` TEXT - for short summaries
- `description` TEXT/MEDIUMTEXT - for detailed content

If a field contains a numeric value with a non-changeable unit, it is
good practice to add the unit, e. g. for height in pixels use
`height_px`, for a duration in minutes, use `duration_mins`.

Monetary values use DECIMAL(8,2) (or larger if needed) for sums,
accompanied by a currency field VARCHAR(3) with the ISO 4217 currency
code, e. g. `sum` and `currency`.

## Character encoding

- Per default, `utf8mb4` is used, as collation `utf8mb4_unicode_ci`.
Identifier fields, enum or set fields can use `latin1` if only ASCII
characters are allowed.

## System tables

System tables start with an underscore, e. g. `_settings`. They are
created by the default module.

- `_settings` - global configuration
- `_relations` - table relationship definitions
- `_logging` - query and action logging
- `_translationfields` - field translation definitions
- `_translations_*` - actual translation data
- `_cronjobs` - scheduled task definitions
- `_jobqueue` - task execution queue
