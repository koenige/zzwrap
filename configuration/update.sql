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
/* 2026-04-12-1 */	DELETE FROM _settings WHERE setting_key = 'login_url';
/* 2026-04-12-2 */	DELETE FROM _settings WHERE setting_key = 'logout_url';
/* 2026-04-12-3 */	INSERT INTO webpages (website_id, title, content, identifier, ending, sequence, mother_page_id, live, parameters, last_update) SELECT s.website_id, 'Home', ' ', IF(TRIM(s.setting_value) IN ('/', '') OR TRIM(s.setting_value) IS NULL, '/', IF(RIGHT(TRIM(s.setting_value), 1) = '/', LEFT(TRIM(s.setting_value), CHAR_LENGTH(TRIM(s.setting_value)) - 1), TRIM(s.setting_value))), IF(TRIM(s.setting_value) IN ('/', '') OR TRIM(s.setting_value) IS NULL, 'none', IF(RIGHT(TRIM(s.setting_value), 1) = '/', '/', 'none')), 1, NULL, 'yes', 'route=home', NOW() FROM _settings s WHERE s.setting_key = 'homepage_url' AND NOT EXISTS (SELECT 1 FROM webpages w WHERE w.website_id = s.website_id AND CONCAT(w.identifier, IF(w.ending = 'none', '', w.ending)) = CONCAT(IF(TRIM(s.setting_value) IN ('/', '') OR TRIM(s.setting_value) IS NULL, '/', IF(RIGHT(TRIM(s.setting_value), 1) = '/', LEFT(TRIM(s.setting_value), CHAR_LENGTH(TRIM(s.setting_value)) - 1), TRIM(s.setting_value))), IF(TRIM(s.setting_value) IN ('/', '') OR TRIM(s.setting_value) IS NULL, '', IF(RIGHT(TRIM(s.setting_value), 1) = '/', '/', ''))));
/* 2026-04-12-4 */	UPDATE webpages w INNER JOIN _settings s ON s.setting_key = 'homepage_url' AND s.website_id = w.website_id SET w.parameters = CONCAT(IFNULL(w.parameters, ''), '&route=home') WHERE CONCAT(w.identifier, IF(w.ending = 'none', '', w.ending)) = CONCAT(IF(TRIM(s.setting_value) IN ('/', '') OR TRIM(s.setting_value) IS NULL, '/', IF(RIGHT(TRIM(s.setting_value), 1) = '/', LEFT(TRIM(s.setting_value), CHAR_LENGTH(TRIM(s.setting_value)) - 1), TRIM(s.setting_value))), IF(TRIM(s.setting_value) IN ('/', '') OR TRIM(s.setting_value) IS NULL, '', IF(RIGHT(TRIM(s.setting_value), 1) = '/', '/', '')));
/* 2026-04-12-5 */	DELETE FROM _settings WHERE setting_key = 'homepage_url';
