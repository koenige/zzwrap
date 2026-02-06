<?php 

/**
 * zzwrap
 * Tests
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2025-2026 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Run tests on functions
 *
 * @param array $params
 *		[0]: package name
 *		[1]: file name
 *		[2]: function name
 * @return array
 */
function mod_zzwrap_test($params, $settings = []) {
	if (count($params) < 2) return brick_format('%%% request tests * %%%');
	if (count($params) > 2) return false;
	list($data['package'], $data['function']) = $params;

	wrap_include('tests', 'zzwrap');
	$functions = mf_zzwrap_test_functions($data['package'], $data['function']);
	if (!array_key_exists($data['function'], $functions)) return false;
	
	$json = json_decode(file_get_contents($functions[$data['function']]['file']), true);
	if (!$json) {
		wrap_error(sprintf('JSON file %s seems to be malformed.', $functions[$data['function']]['file']), E_USER_ERROR);
	}
	$data += $json;

	if (!empty($data['file'])) {
		$files = wrap_include($data['file'].'.php', $data['package']);
		$data['function_found'] = mf_zzwrap_test_function_exists($data['function'], $data['package'], $files);
	} else {
		// already included
		$data['function_found'] = function_exists($data['function']);
	}
	if (!$data['function_found']) return false;

	$data['lines'] = [];
	foreach ($data['data'] as $index => $value) {
		$output_pre = $value;
		if (!empty($data['pre_functions'])) {
			foreach ($data['pre_functions'] as $function)
				$output_pre = mf_zzwrap_test_function($function, $output_pre);
			// just take first key, second might be error message
			if (is_array($output_pre)) $output_pre = reset($output_pre);
		}
		
		// Handle functions with multiple parameters via variables
		if (!empty($data['variables']) && is_array($value)) {
			if (array_key_exists('settings', $value))
				mod_zzwrap_test_settings($value['settings']);
			if (array_key_exists('server', $value))
				mod_zzwrap_test_server($value['server']);
			if (array_key_exists('post', $value))
				mod_zzwrap_test_post($value['post']);
			$args = [];
			foreach ($data['variables'] as $var) {
				if (!array_key_exists($var, $value)) {
					wrap_error(sprintf('Variable %s not found in test data.', $var), E_USER_ERROR);
					continue;
				}
				$args[] = $value[$var];
			}
			$output = call_user_func_array($data['function'], $args);
			// Check expected result if present
			if (isset($value['expected']))
				$expected = $value['expected'];
		} else {
			$output = $output_pre;
			$output = $data['function']($output, $data['parameters'] ?? NULL);
			$expected = NULL;
		}
		if (!is_null($expected))
			$expected = $output === $expected ? 'success' : 'fail';
		
		if (!empty($data['post_functions'])) {
			foreach ($data['post_functions'] as $function)
				$output = mf_zzwrap_test_function($function, $output);
		}
		$input = $value;
		if (is_array($input)) {
			$input = [];
			foreach ($value as $key => $sub_value) {
				$input[] = [
					'key' => $key,
					'value' => is_array($sub_value) ? json_encode($sub_value) : $sub_value
				];
			}
			$data['lines'][] = [
				'inputs' => $input,
				'output' => mod_zzwrap_test_array($output),
				'output_pre' => $output_pre !== $value ? $output_pre : NULL,
				'legend' => $data['legends'][$index] ?? NULL,
				'expected' => $expected
			];
		} else {
			$data['lines'][] = [
				'input' => $input,
				'output' => mod_zzwrap_test_array($output),
				'output_pre' => $output_pre !== $value ? $output_pre : NULL,
				'legend' => $data['legends'][$index] ?? NULL,
				'expected' => $expected
			];
		}
		// Cleanup: restore all modified values to their original state
		mod_zzwrap_test_cleanup();
	}

	$page['text'] = wrap_template('test', $data);
	$page['breadcrumbs'][] = ['url_path' => '../', 'title' => $data['package']];
	$page['breadcrumbs'][] = ['title' => $data['function']];
	$page['extra']['css'][] = 'zzwrap/tests.css';
	$page['title'] = wrap_text('Tests for %s()', ['values' => [$data['function']]]);
	return $page;
}

/**
 * add settings via key `settings` in data
 *
 * @param array $values
 * @return void
 */
function mod_zzwrap_test_settings($values) {
	static $original_values = [];
	if (!$values) return;
	foreach ($values as $key => $value) {
		// Store original value before changing it
		if (!array_key_exists($key, $original_values)) {
			$original_values[$key] = wrap_setting($key);
		}
		wrap_setting($key, $value);
	}
}

/**
 * overwrite SERVER values via key `server` in data
 *
 * @param array $values
 * @return void
 */
function mod_zzwrap_test_server($values) {
	static $original_values = [];
	if (!$values) return;
	foreach ($values as $key => $value) {
		// Store original value before changing it
		if (!array_key_exists($key, $original_values)) {
			$original_values[$key] = $_SERVER[$key] ?? null;
		}
		$_SERVER[$key] = $value;
		if ($key === 'REQUEST_URI') {
			wrap_setting('request_uri', $value);
			wrap_url_encode();
			wrap_url_forwarded();
		}
	}
}

/**
 * overwrite POST values via key `post` in data
 *
 * @param array $values
 * @return void
 */
function mod_zzwrap_test_post($values) {
	static $original_values = [];
	if (!$values) return;
	// Store original POST state before first change
	if (empty($original_values)) {
		$original_values = $_POST;
	}
	$_POST += $values;
}

/**
 * restore all settings, SERVER and POST values to their original state
 *
 * @return void
 */
function mod_zzwrap_test_cleanup() {
	// Restore settings
	mod_zzwrap_test_settings_restore();
	// Restore SERVER variables
	mod_zzwrap_test_server_restore();
	// Restore POST variables
	mod_zzwrap_test_post_restore();
}

/**
 * restore settings to their original values
 *
 * @return void
 */
function mod_zzwrap_test_settings_restore() {
	// Get static variable from mod_zzwrap_test_settings
	$reflection = new ReflectionFunction('mod_zzwrap_test_settings');
	$statics = $reflection->getStaticVariables();
	if (empty($statics['original_values'])) return;
	
	foreach ($statics['original_values'] as $key => $value) {
		wrap_setting($key, $value);
	}
}

/**
 * restore SERVER values to their original state
 *
 * @return void
 */
function mod_zzwrap_test_server_restore() {
	// Get static variable from mod_zzwrap_test_server
	$reflection = new ReflectionFunction('mod_zzwrap_test_server');
	$statics = $reflection->getStaticVariables();
	if (empty($statics['original_values'])) return;
	
	foreach ($statics['original_values'] as $key => $value) {
		if (is_null($value)) {
			unset($_SERVER[$key]);
		} else {
			$_SERVER[$key] = $value;
		}
		if ($key === 'REQUEST_URI') {
			wrap_setting('request_uri', $value);
			wrap_url_encode();
			wrap_url_forwarded();
		}
	}
}

/**
 * restore POST values to their original state
 *
 * @return void
 */
function mod_zzwrap_test_post_restore() {
	// Get static variable from mod_zzwrap_test_post
	$reflection = new ReflectionFunction('mod_zzwrap_test_post');
	$statics = $reflection->getStaticVariables();
	if (empty($statics['original_values'])) return;
	
	$_POST = $statics['original_values'];
}

/**
 * return string represenation of array if value is an array
 *
 * @param mixed $value
 * @return string
 */
function mod_zzwrap_test_array($value) {
	if (!is_array($value)) return $value;
	return wrap_print($value);
}
