/**
 * zzwrap
 * SQL updates
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2023 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/* 2023-08-04-1 */	UPDATE _settings SET setting_key = 'change_password_path' WHERE setting_key = 'change_password_url';
/* 2023-08-04-2 */	UPDATE _settings SET setting_key = 'login_entry_path' WHERE setting_key = 'login_entryurl';
