/**
 * zzwrap
 * SQL updates
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2023, 2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/* 2023-08-04-1 */	UPDATE _settings SET setting_key = 'change_password_path' WHERE setting_key = 'change_password_url';
/* 2023-08-04-2 */	UPDATE _settings SET setting_key = 'login_entry_path' WHERE setting_key = 'login_entryurl';
/* 2026-03-10-1 */	DELETE FROM _settings WHERE setting_key = 'change_password_path';
/* 2026-03-10-2 */	DELETE FROM _settings WHERE setting_key = 'jobmanager_path';
/* 2026-03-14-1 */	UPDATE webpages SET parameters = CONCAT(IFNULL(parameters, ''), '&route=login_entry') WHERE CONCAT(identifier, IF(ending = 'none', '', ending)) = '/*_SETTING login_entry_path _*/';
/* 2026-03-14-2 */	DELETE FROM _settings WHERE setting_key = 'login_entry_path';
