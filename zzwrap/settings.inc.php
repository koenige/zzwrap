<?php 

/**
 * zzwrap
 * handling settings
 *
 * – wrap_setting() and helper functions
 * - wrap_cfg_files() and helper functions
 * - wrap_path() and helper functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * read or write settings
 *
 * @param string $key
 * @param mixed $value (if set, assign value, if not read value)
 * @param int $login_id (optional, get setting for user)
 * @return mixed
 * @todo support writing of setting per login ID (necessary?)
 * @todo support array structure like setting[subsetting] for $key
 */
function wrap_setting($key, $value = NULL, $login_id = NULL) {
	global $zz_setting;
	// write?
	if (isset($value) AND !isset($login_id)) {
		$value = wrap_setting_value($value);
		if (strstr($key, '['))
			$zz_setting = wrap_setting_key($key, $value, $zz_setting);
		else
			$zz_setting[$key] = $value;
	}
	// read
	return wrap_get_setting($key, $login_id, $value);
}

/**
 * add setting to a list
 *
 * @param string $key
 * @param mixed $value
 */
function wrap_setting_add($key, $value) {
	if (!is_array(wrap_setting($key)))
		wrap_error(sprintf('Unable to add value %s to key %s, it is not an array.', $key, json_encode($value)), E_USER_WARNING);

	$existing = wrap_setting($key);
	if (is_array($value))
		$existing = array_merge($existing, $value);
	else
		$existing[] = $value;
	wrap_setting($key, $existing);
}

/**
 * delete setting
 *
 * @param string $key
 */
function wrap_setting_delete($key) {
	global $zz_setting;
	unset($zz_setting[$key]);
}

/**
 * gets setting from configuration (default: zz_setting)
 *
 * @param string $key
 * @param int $login_id (optional)
 * @param string $set (optional) new value to be set, important for local keys
 * @return mixed $setting (if not found, returns NULL)
 */
function wrap_get_setting($key, $login_id = 0, $set = NULL) {
	global $zz_setting;

	$cfg = wrap_cfg_files('settings');
	if (is_null($set) AND !$login_id AND $value = wrap_setting_local($key, $cfg)) {
		if (!is_null($value)) return $value;
	}

	// check if in settings.cfg
	wrap_setting_log_missing($key, $cfg);

	// setting is set in $zz_setting (from _settings-table or directly)
	// if it is an array, check if all keys are there later
	if (isset($zz_setting[$key]) AND !$login_id
		AND (!is_array($zz_setting[$key]) OR is_numeric(key($zz_setting[$key])))
	) {
		$zz_setting[$key] = wrap_get_setting_prepare($zz_setting[$key], $key, $cfg);
		return $zz_setting[$key];
	}

	// shorthand notation with []?
	$shorthand = wrap_setting_key_read($zz_setting, $key);
	if (isset($shorthand))
		return wrap_get_setting_prepare($shorthand, $key, $cfg);

	// read setting from database
	if (!wrap_db_connection() AND $login_id) {
		$values = wrap_setting_read($key, $login_id);
		if (array_key_exists($key, $values)) {
			return wrap_get_setting_prepare($values[$key], $key, $cfg);
		}
	}

	// default value set in one of the current settings.cfg files?
	if (array_key_exists($key, $cfg) AND !isset($zz_setting[$key])) {
		$default = wrap_get_setting_default($key, $cfg[$key]);
		return wrap_get_setting_prepare($default, $key, $cfg);
	} elseif ($pos = strpos($key, '[') AND array_key_exists(substr($key, 0, $pos), $cfg)) {
		$default = wrap_get_setting_default($key, $cfg[substr($key, 0, $pos)]);
		$sub_key = substr($key, $pos +1, -1);
		if (is_array($default) AND array_key_exists($sub_key, $default)) {
			$default = $default[$sub_key];
			return wrap_get_setting_prepare($default, $key, $cfg);
		}
		// @todo add support for key[sub_key][sub_sub_key] notation
	}

	// check for keys that are arrays
	$my_keys = [];
	foreach ($cfg as $cfg_key => $cfg_value) {
		if (!str_starts_with($cfg_key, $key.'[')) continue;
		$my_keys[] = $cfg_key;
	}

	$return = [];
	foreach ($my_keys as $my_key) {
		$return = array_merge_recursive($return, wrap_setting_key($my_key, wrap_get_setting_default($my_key, $cfg[$my_key])));
	}
	if (!empty($return[$key])) {
		// check if some of the keys have already been set, overwrite these
		if (isset($zz_setting[$key])) {
			$return[$key] = array_merge($return[$key], $zz_setting[$key]);
		}
		return $return[$key];
	} else {
		// no defaults, so return existing settings unchanged
		if (isset($zz_setting[$key])) return $zz_setting[$key];
	}
	return NULL;
}

/**
 * check if a local setting is available
 * ending with `_local`, with `local = 1` in settings.cfg
 *
 * @param string $key
 * @param array $cfg
 * @return mixed
 */
function wrap_setting_local($key, $cfg) {
	global $zz_setting;
	if (empty($zz_setting['local_access'])) return NULL;

	static $keys = [];
	if (in_array($key, $keys)) return NULL; // already tried
	$keys[] = $key;

	$parts = explode('[', $key);
	if (str_ends_with($parts[0], '_local')) return NULL; // is already local
	if (empty($cfg[$parts[0]]['local'])) return NULL;

	$parts[0] .= '_local';
	$new_key = implode('[', $parts);
	$value = wrap_get_setting($new_key);
	if (is_null($value)) return NULL; // value is not set
	wrap_setting($key, $value); // write local value back to normal value
	return $value;
}

/**
 * gets default value from .cfg file
 *
 * @param string $key
 * @param array $params = $cfg[$key]
 * @return mixed $setting (if not found, returns NULL)
 */
function wrap_get_setting_default($key, $params) {
	global $zz_conf;

	if (!empty($params['default_from_php_ini']) AND ini_get($params['default_from_php_ini'])) {
		return ini_get($params['default_from_php_ini']);
	} elseif (!empty($params['default_empty_string'])) {
		return '';
	} elseif (!empty($params['default'])) {
		return wrap_setting_value($params['default']);
	} elseif (!empty($params['default_from_setting'])) {
		if (str_starts_with($params['default_from_setting'], 'zzform_')) {
			$default_setting_key = substr($params['default_from_setting'], 7);
			if (is_array($zz_conf) AND array_key_exists($default_setting_key, $zz_conf))
				return $zz_conf[$default_setting_key];
			else
				return wrap_setting($params['default_from_setting']);
		} elseif (!is_null(wrap_setting($params['default_from_setting']))) {
			return wrap_setting($params['default_from_setting']);
		}
	}
	if (!empty($params['default_from_function']) AND function_exists($params['default_from_function'])) {
		return $params['default_from_function']();
	}
	if (!wrap_db_connection() AND !empty($params['brick'])) {
		$path = wrap_setting_path($key, $params['brick']);
		if ($path) return wrap_setting($key);
	}
	return NULL;
}

/**
 * prepare setting before returning, according to settings.cfg
 *
 * @param mixed $setting
 * @param string $key
 * @param array $cfg
 * @return mixed
 */
function wrap_get_setting_prepare($setting, $key, $cfg) {
	if (!array_key_exists($key, $cfg)) return $setting;

	// depending on type
	if (!empty($cfg[$key]['type']))
		switch ($cfg[$key]['type']) {
			case 'bytes': $setting = wrap_byte_to_int($setting); break;
			case 'folder': $setting = wrap_filepath($setting); break;
		}
	
	// list = 1 means values need to be array!
	if (!empty($cfg[$key]['list']) AND !is_array($setting)) {
		if (!empty($cfg[$key]['levels'])) {
			$levels = wrap_setting_value($cfg[$key]['levels']);
			$return = [];
			foreach ($levels as $level) {
				$return[] = $level;
				if ($level === $setting) break;
			}
			$setting = $return;
		} elseif ($setting) {
			$setting = [$setting];
		} else {
			$setting = [];
		}
	}
	if (!empty($cfg[$key]['languages']) AND is_array($setting))
		if (array_key_exists(wrap_setting('lang'), $setting))
			return $setting[wrap_setting('lang')];
		else
			return NULL;
	return $setting;
}

/**
 * log missing keys in settings.cfg
 *
 * @param string $key
 * @param array $cfg
 * @return void
 */
function wrap_setting_log_missing($key, $cfg) {
	global $zz_setting;
	if (empty($zz_setting['debug'])) return;

	$base_key = $key;
	if ($pos = strpos($base_key, '[')) $base_key = substr($base_key, 0, $pos);
	if (array_key_exists($base_key, $cfg)) return;
	$log_dir = $zz_setting['log_dir'] ?? false;
	if (!$log_dir AND !empty($cfg['log_dir']['default']))
		$log_dir = wrap_setting_value_placeholder($cfg['log_dir']['default']);
	if (!$log_dir) return;
	error_log(sprintf("%s: Setting %s not found in settings.cfg [%s].\n", date('Y-m-d H:i:s'), $base_key, $_SESSION['username'] ?? wrap_setting('remote_ip')), 3, $log_dir.'/settings.log');
}

/**
 * write settings to database
 *
 * @param string $key
 * @param string $value
 * @param int $login_id (optional)
 * @param array $settings (optional)
 * @return bool
 */
function wrap_setting_write($key, $value, $login_id = 0, $settings = []) {
	$existing_setting = wrap_setting_read($key, $login_id);
	if ($existing_setting) {
		// support for keys that are arrays
		$new_setting = wrap_setting_key($key, wrap_setting_value($value));
		if ($existing_setting === $new_setting) return false;
		$sql = 'UPDATE /*_PREFIX_*/%s_settings SET setting_value = "%%s" WHERE setting_key = "%%s"';
		if (wrap_setting('multiple_websites') AND !$login_id)
			$sql .= ' AND website_id IN (1, /*_SETTING website_id _*/)';
		$sql = sprintf($sql, $login_id ? 'logins' : '');
		$sql = sprintf($sql, wrap_db_escape($value), wrap_db_escape($key));
		$sql .= wrap_setting_login_id($login_id);
	} elseif ($login_id) {
		$sql = 'INSERT INTO /*_PREFIX_*/logins_settings (setting_value, setting_key, login_id) VALUES ("%s", "%s", %s)';
		$sql = sprintf($sql, wrap_db_escape($value), wrap_db_escape($key), $login_id);
	} else {
		$cfg = wrap_cfg_files('settings');
		$explanation = (in_array($key, array_keys($cfg)) AND !empty($cfg[$key]['description']))
			? sprintf('"%s"', $cfg[$key]['description'])  : 'NULL';
		if (wrap_setting('multiple_websites'))
			$sql = 'INSERT INTO /*_PREFIX_*/_settings (setting_value, setting_key, explanation, website_id) VALUES ("%s", "%s", %s, /*_SETTING website_id _*/)';
		else
			$sql = 'INSERT INTO /*_PREFIX_*/_settings (setting_value, setting_key, explanation) VALUES ("%s", "%s", %s)';
		$sql = sprintf($sql, wrap_db_escape($value), wrap_db_escape($key), $explanation);
	}
	$result = wrap_db_query($sql);
	if ($result) {
		if (wrap_include('database', 'zzform') AND empty($settings['no_logging'])) {
			wrap_setting('log_username_default', 'Servant Robot 247');
			zz_db_log($sql, '', $result['id'] ?? false);
			wrap_setting_delete('log_username_default');
		}
		// activate setting
		if (!$login_id) wrap_setting($key, $value);
		return true;
	}

	wrap_error(sprintf(
		wrap_text('Could not change setting. Key: %s, value: %s, login: %s'),
		wrap_html_escape($key), wrap_html_escape($value), $login_id
	));	
	return false;
}

/**
 * read settings from database
 *
 * @param string $key (* at the end used as wildcard)
 * @param int $login_id (optional)
 * @return array
 */
function wrap_setting_read($key, $login_id = 0) {
	static $setting_table = '';
	static $login_setting_table = '';
	static $settings = [];
	if (array_key_exists($login_id, $settings))
		if (array_key_exists($key, $settings[$login_id]))
			return $settings[$login_id][$key];

	if (!$login_id AND !$setting_table) {
		$setting_table = wrap_database_table_check('_settings');
		if (!$setting_table) return [];
	} elseif ($login_id AND !$login_setting_table) {
		$login_setting_table = wrap_database_table_check('logins_settings');
		if (!$login_setting_table) return [];
	}
	$sql = 'SELECT setting_key, setting_value
		FROM /*_PREFIX_*/%s_settings
		WHERE setting_key %%s "%%s"';
	if (wrap_setting('multiple_websites') AND !$login_setting_table)
		$sql .= ' AND website_id IN (1, /*_SETTING website_id _*/)';
	$sql = sprintf($sql, $login_id ? 'logins' : '');
	if (substr($key, -1) === '*') {
		$sql = sprintf($sql, 'LIKE', substr($key, 0, -1).'%');
	} else {
		$sql = sprintf($sql, '=', $key);
	}
	$sql .= wrap_setting_login_id($login_id);
	$settings_raw = wrap_db_fetch($sql, 'setting_key', 'key/value');
	$settings[$login_id][$key] = [];
	foreach ($settings_raw as $skey => $value) {
		$settings[$login_id][$key]
			= array_merge_recursive($settings[$login_id][$key], wrap_setting_key($skey, wrap_setting_value($value)));
	}
	return $settings[$login_id][$key];
}

/**
 * add login_id or not to setting query
 *
 * @param int $login_id (optional)
 * @return string WHERE query part
 */
function wrap_setting_login_id($login_id = 0) {
	if (!$login_id) return '';
	return sprintf(' AND login_id = %d', $login_id);
}

/**
 * sets key/value pairs, key may be array in form of
 * key[subkey], value may be array in form (1, 2, 3)
 *
 * @param string $key
 * @param string $value
 * @param array $settings (existing settings or no settings)
 * @return array
 */
function wrap_setting_key($key, $value, $settings = []) {
	if (!strstr($key, '[')) {
		$settings[$key] = $value;
		return $settings;
	}

	$keys = wrap_setting_key_array($key);
	switch (count($keys)) {
		case 2:
			$settings[$keys[0]][$keys[1]] = $value;
			break;
		case 3:
			$settings[$keys[0]][$keys[1]][$keys[2]] = $value;
			break;
		case 4:
			$settings[$keys[0]][$keys[1]][$keys[2]][$keys[3]] = $value;
			break;
		default:
			wrap_error(sprintf('Too many arrays in %s, not implemented.', $key), E_USER_ERROR);
	}
	return $settings;
}

/**
 * reads key/value pairs, key may be array in form of key[subkey]
 *
 * @param array $source
 * @param string $key
 * @return mixed
 */
function wrap_setting_key_read($source, $key) {
	if (!strstr($key, '['))
		return $source[$key] ?? NULL;

	$keys = wrap_setting_key_array($key);
	switch (count($keys)) {
		case 2:
			return $source[$keys[0]][$keys[1]] ?? NULL;
		case 3:
			return $source[$keys[0]][$keys[1]][$keys[2]] ?? NULL;
		case 4:
			return $source[$keys[0]][$keys[1]][$keys[2]][$keys[3]] ?? NULL;
		default:
			wrap_error(sprintf('Too many arrays in %s, not implemented.', $key), E_USER_ERROR);
	}
}

/**
 * change key[subkey][subsubkey] into array key, subkey, subsubkey
 *
 * @param string $key
 * @return array
 */
function wrap_setting_key_array($key) {
	$keys = explode('[', $key);
	foreach ($keys as $index => $key)
		$keys[$index] = rtrim($key, ']');
	return $keys;
}

/**
 * unset a key from an array where the given key is a string that looks like
 * key[value][value2] etc., done via reference
 *
 * example: unset($setting['key[value][value2]']); does, of course, not work
 * so this works like unset($setting['key']['value']['value2']);
 *
 * @param string $key
 * @param array $settings
 * @return array
 */
function wrap_setting_key_unset($key, $settings) {
	$old = wrap_setting_key_array($key);
	if (!count($old)) return $settings;

	$last_key = array_pop($old);
	$temp = &$settings; // reset reference
	foreach ($old as $subkey) {
		// does not exist, nothing to remove?
		if (!isset($temp[$subkey])) return $settings;
		$temp = &$temp[$subkey];
	}
	unset($temp[$last_key]); 
	return $settings;
}

/**
 * allows settings from db to be in the format [1, 2, 3]; first \ will be
 * removed and allows settings starting with [
 *
 * list items inside [] are allowed to be enclosed in "" to preserve spaces
 * @param string $setting
 * @return mixed
 */
function wrap_setting_value($setting) {
	if (is_array($setting)) {
		foreach ($setting as $index => $value)
			$setting[$index] = wrap_setting_value_placeholder($value);
		return $setting;
	}
	$setting = wrap_setting_value_placeholder($setting);

	// if setting value is a constant, convert it to its numerical value	
	if (preg_match('/^[A-Z_]+$/', $setting) AND defined($setting))
		return constant($setting);

	switch (substr($setting, 0, 1)) {
	case '\\':
		return substr($setting, 1);
	case '[':
		if (!substr($setting, -1) === ']') break;
		$setting = substr($setting, 1, -1);
		$settings = explode(',', $setting);
		foreach ($settings as $index => $setting) {
			$settings[$index] = trim($setting);
			if (str_starts_with($settings[$index], '"') AND str_ends_with($settings[$index], '"'))
				$settings[$index] = trim($settings[$index], '"');
		}
		return $settings;
	case '?':
	case '&':
		$setting = substr($setting, 1);
		parse_str($setting, $settings);
		return $settings;
	}
	return $setting;
}

/**
 * check if setting has a setting placeholder in it
 *
 * @param string $string
 * @return string
 */
function wrap_setting_value_placeholder($string) {
	if (!is_string($string)) return $string;
	if (!strstr($string, '%%% setting')) return $string;
	$parts = explode('%%%', $string);
	foreach ($parts as $index => $part) {
		if ($index & 1) {
			$part = trim($part);
			// no setting: ignore and pass through
			if (!str_starts_with($part, 'setting ')) {
				$parts[$index] = '%%% '.$part.' %%%';
				continue;
			}
			$setting = substr($part, 8);
			if (is_null(wrap_setting($setting))) {
				$parts[$index] = '%%% '.$part.' %%%';
				continue;
			}
			$parts[$index] = wrap_setting($setting);
		}
	}
	$string = implode('', $parts);
	return $string;
}

/**
 * write settings from array to $zz_setting or $zz_conf
 *
 * @param array $config
 * @return void
 */
function wrap_setting_register($config) {
	global $zz_setting;
	global $zz_conf;
	
	$zzform_cfg = wrap_cfg_files('settings', ['package' => 'zzform']);

	foreach ($config as $skey => $value) {
		if (wrap_setting_zzconf($zzform_cfg, $skey)) {
			$skey = substr($skey, 7);
			$var = 'zz_conf';
		} else {
			$var = 'zz_setting';
		}
		if (is_array($value) AND reset($value) === '__defaults__') {
			$$var[$skey] = wrap_setting($skey);
			array_shift($value);
		}
		$keys_values = wrap_setting_key($skey, wrap_setting_value($value));
		foreach ($keys_values as $key => $value) {
			if (!is_array($value)) {
				if (!$value OR $value === 'false') $value = false;
				elseif ($value === 'true') $value = true;
			}
			if (empty($$var[$key]) OR !is_array($$var[$key]))
				$$var[$key] = $value;
			else
				$$var[$key] = array_merge_recursive($$var[$key], $value);
		}
	}
}

/**
 * for zzform, check if key belongs to $zz_conf or to wrap_setting()
 *
 * @param array $cfg
 * @param string $key
 * @return bool
 */
function wrap_setting_zzconf($cfg, $key) {
	if (!str_starts_with($key, 'zzform_')) return false;
	$key_short = ($pos = strpos($key, '[')) ? substr($key, 0, $pos) : '';
	if ($key_short AND empty($cfg[$key_short]['zz_conf'])) return false;
	if (empty($cfg[$key]['zz_conf'])) return false;
	return true;
}

/**
 * add settings from website if backend is on a different website
 *
 * @return void
 */
function wrap_setting_backend() {
	if (!$backend_website_id = wrap_setting('backend_website_id')) return;
	if ($backend_website_id === wrap_setting('website_id')) return;

	$cfg = wrap_cfg_files('settings');
	foreach ($cfg as $index => $parameters) {
		if (!empty($parameters['backend_for_website'])) continue;
		unset($cfg[$index]);
	}
	if (!$cfg) return;

	$sql = 'SELECT setting_key, setting_value
		FROM /*_PREFIX_*/_settings
		WHERE website_id = /*_SETTING backend_website_id _*/
		AND setting_key IN ("%s")';
	$sql = sprintf($sql, implode('","', array_keys($cfg)));
	$extra_settings = wrap_db_fetch($sql, 'setting_key', 'key/value');
	wrap_setting_register($extra_settings);
}

/**
 * read default settings from .cfg files
 *
 * @param string $type (settings, access, etc.)
 * @param array $settings (optional)
 * @return array
 */
function wrap_cfg_files($type, $settings = []) {
	static $cfg = [];
	static $single_cfg = [];
	static $translated = false;

	// check if wrap_cfg_files() was called without database connection
	// then translate all config variables read so far

	if (!$translated AND wrap_db_connection() AND $cfg AND !empty($settings['translate'])) {
		foreach (array_keys($cfg) as $this_type) {
			wrap_cfg_translate($cfg[$this_type], $this_type);
		}
		foreach ($single_cfg as $this_type => $this_config) {
			foreach (array_keys($this_config) as $this_module) {
				wrap_cfg_translate($single_cfg[$this_type][$this_module], $this_type);
			}
		}
		$translated = true;
	}
	
	// read data
	if (empty($cfg[$type]))
		list($cfg[$type], $single_cfg[$type]) = wrap_cfg_files_parse($type);
	if (empty($cfg[$type]))
		return [];

	// return existing values
	if (!empty($settings['package'])) {
		if (!array_key_exists($settings['package'], $single_cfg[$type])) return [];
		return $single_cfg[$type][$settings['package']];
	} elseif (!empty($settings['scope'])) {
		// restrict array to a certain scope, 'website' being implicit default scope
		$cfg_return = $cfg[$type];
		foreach ($cfg_return as $key => $config) {
			if (empty($config['scope'])) {
				if ($settings['scope'] === 'website') continue;
				unset($cfg_return[$key]);
				continue;
			}
			$scope = is_array($config['scope']) ? $config['scope'] : wrap_setting_value($config['scope']);
			if (in_array($settings['scope'], $scope)) continue;
			unset($cfg_return[$key]);
		}
		return $cfg_return;
	}
	return $cfg[$type];
}

/**
 * parse .cfg files per type
 *
 * @param string $type
 * @return array
 *		array $cfg configuration data indexed by package
 *		array $single_cfg configuration data, all keys in one array
 */
function wrap_cfg_files_parse($type) {
	$files = wrap_collect_files('configuration/'.$type.'.cfg', 'modules/themes/custom');
	if (!$files) return [[], []];

	$cfg = [];
	foreach ($files as $package => $cfg_file) {
		$single_cfg[$package] = parse_ini_file($cfg_file, true);
		foreach ($single_cfg[$package] as $index => $configuration) {
			$single_cfg[$package][$index]['package'] = $package;
			// local keys?
			if (!empty($configuration['local'])) {
				$index_local = $index.'_local';
				$single_cfg[$package][$index_local] = $single_cfg[$package][$index];
				unset($single_cfg[$package][$index_local]['local']);
				unset($single_cfg[$package][$index_local]['default']);
			}
		}
		
		// might be called before database connection exists
		if (wrap_db_connection()) {
			wrap_cfg_translate($single_cfg[$package], $cfg_file);
			$translated = true;
		}
		// no merging, let custom cfg overwrite single settings from modules
		foreach ($single_cfg[$package] as $key => $line) {
			if (array_key_exists($key, $cfg))
				foreach ($line as $subkey => $value) {
					if ($subkey === 'package') continue;
					$cfg[$key][$subkey] = $value;
				}
			else
				$cfg[$key] = $line;
		}
	}
	return [$cfg, $single_cfg];
}

/**
 * translate description per config
 *
 * @param array $cfg
 * @param string $filename
 * @return array
 */
function wrap_cfg_translate(&$cfg, $filename) {
	$my_filename = $filename;
	foreach ($cfg as $index => $config) {
		if (empty($config['description'])) continue;
		if (is_array($config['description'])) continue;
		if (!str_starts_with($filename, '/'))
			$my_filename = sprintf('%s/%s/configuration/%s.cfg', wrap_setting('modules_dir'), $config['package'], $filename);
		$cfg[$index]['description'] = wrap_text($config['description'], ['source' => $my_filename]);
	}
	return $cfg;
}

/**
 * get URL path of a page depending on a brick, write as setting
 *
 * @param string $setting_key
 * @param string $brick (optional, read from settings.cfg)
 * @param array $params (optional)
 * @return bool
 */
function wrap_setting_path($setting_key, $brick = '', $params = []) {
	static $tries = [];
	if (in_array($setting_key, $tries)) return false; // do not try more than once per request
	$tries[] = $setting_key;
	
	if (!$brick) {
		// get it from settings.cfg
		$cfg = wrap_cfg_files('settings');
		if (empty($cfg[$setting_key]['brick'])) return false;
		$brick = $cfg[$setting_key]['brick'];
		if (!$params)
			$params = $cfg[$setting_key]['brick_local_settings'] ?? [];
	}
	
	$sql = 'SELECT CONCAT(identifier, IF(ending = "none", "", ending)) AS path, content
		FROM /*_PREFIX_*/webpages
		WHERE content LIKE "%\%\%\% '.$brick.'% \%\%\%%"';
	if (wrap_setting('website_id'))
		$sql .= ' AND website_id = /*_SETTING website_id _*/';
	$paths = wrap_db_fetch($sql, '_dummy_', 'numeric');
	
	// build parameters
	$no_params = [];
	foreach ($params as $key => $value) {
		if ($value) continue;
		$no_params[] = $key;
		unset($params[$key]);
	}
	$params = $params ? http_build_query($params) : '';
	$params = explode('&', $params);
	foreach ($params as $param) {
		if (!$param) continue;
		// if parameter: only leave pages having this parameter
		foreach ($paths as $index => $path) {
			if (strstr($path['content'], $param)) continue;
			unset($paths[$index]);
		}
	}
	foreach ($no_params as $param) {
		// if parameter=0: only leave pages without this parameter
		$param .= '=';
		foreach ($paths as $index => $path) {
			if (!strstr($path['content'], $param)) continue;
			unset($paths[$index]);
		}
	}

	if (count($paths) !== 1 AND !str_ends_with($setting_key, '*')) {
		// check if one ends with asterisk
		foreach ($paths as $index => $path) {
			if (strstr($path['content'], $brick.' *')) unset($paths[$index]);
		}
	}
	if (count($paths) !== 1) {
		$brick = explode(' ', $brick);
		if (count($brick) !== 2) return false;
		if ($brick[0] !== 'tables') return false;
		$path = wrap_path('default_tables', $brick[1]);
		if (!$path) return false;
	} else {
		$path = reset($paths);
		$path = $path['path'];
	}
	$path = str_replace('*', '/%s', $path);
	$path = str_replace('//', '/', $path);
	wrap_setting_write($setting_key, $path);
	return true;
}

/**
 * get a path based on a setting, check for access
 *
 * e. g. for 'default_masquerade' get path from 'default_masquerade_path'
 * for 'activities_profile[usergroup]' use 'activities_profile_path[usergroup]'
 * @param string $area
 * @param mixed $value (optional)
 * @param mixed $check_rights (optional) false: no check; array: use as details
 * @param bool $testing (optional) true: checks if path exists, regardless of values
 * @param array $settings (optional)
 * @return string
 */
function wrap_path($area, $value = [], $check_rights = true, $testing = false, $settings = []) {
	// check rights
	$detail = is_bool($check_rights) ? '' : $check_rights;
	if ($check_rights AND !wrap_access($area, $detail)) return NULL;

	// add _path to setting, check if it exists
	$check = false;
	if (strstr($area, '[')) {
		$keys = explode('[', $area);
		$keys[0] = sprintf('%s_path', $keys[0]);
		$setting = implode('[', $keys);
	} else {
		$setting = sprintf('%s_path', $area);
	}
	if (!wrap_setting($setting)) $check = true;

	if ($check) {
		$success = wrap_setting_path($setting);
		if (!$success) return NULL;
	}
	$this_setting = wrap_setting($setting);
	if (!$this_setting) return '';
	// if you address e. g. news_article and it is in fact news_article[publication_path]:
	if (is_array($this_setting)) return '';
	// replace page placeholders with %s
	$this_setting = wrap_path_placeholder($this_setting);
	$required_count = substr_count($this_setting, '%');
	if (!is_array($value)) $value = [$value];
	if (count($value) < $required_count) {
		if (wrap_setting('backend_path'))
			array_unshift($value, wrap_setting('backend_path'));
		if (count($value) < $required_count AND wrap_setting('path_placeholder_function')) {
			$new_value = wrap_setting('path_placeholder_function')();
			if ($new_value) array_unshift($value, $new_value);
		}
		if (count($value) < $required_count) {
			if (!$testing) return '';
			while (count($value) < $required_count)
				$value[] = 'testing';
		}
	}
	$base = !empty($settings['no_base']) ? '' : wrap_setting('base');
	$path = vsprintf($base.$this_setting, $value);
	if (str_ends_with($path, '#')) $path = substr($path, 0, -1);
	if ($website_id = wrap_setting('backend_website_id')
		AND $website_id !== wrap_setting('website_id')) {
		$cfg = wrap_cfg_files('settings');
		if (!empty($cfg[$setting]['backend_for_website']))
			$path = wrap_host_base($website_id).$path;
	}
	return $path;
}

/**
 * replace URL placeholders in path (e. g. %year%) with %s
 *
 * @param string $path
 * @param string $char whith what to replace
 * @return string
 */
function wrap_path_placeholder($path, $char = '%s') {
	global $zz_page;
	if (empty($zz_page['url_placeholders'])) return $path;
	foreach (array_keys($zz_page['url_placeholders']) as $placeholder) {
		$placeholder = sprintf($char === '*' ? '/%%%s%%' : '%%%s%%', $placeholder);
		if (!strstr($path, $placeholder)) continue;
		$path = str_replace($placeholder, $char, $path);
	}
	// remove duplicate *
	while (strstr($path, $char.'/'.$char))
		$path = str_replace($char.'/'.$char, $char, $path);
	while (strstr($path, $char.$char))
		$path = str_replace($char.$char, $char, $path);
	return $path;
}

/**
 * get a path for a certain help text
 *
 * @param string $helptext
 * @return string
 */
function wrap_path_helptext($help) {
	$identifier = str_replace('_', '-', $help);
	wrap_include('zzbrick_request_get/helptexts', 'default');
	$files = mf_default_helptexts_files();
	if (!array_key_exists($identifier, $files)) return '';
	return wrap_path('default_helptext', $help);
}
