<?php 

/**
 * zzwrap
 * Tests overview
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2025 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * overview of tests for functions
 *
 * @param array $params
 * @return array
 */
function mod_zzwrap_tests($params) {
	wrap_include('tests', 'zzwrap');

	$data = [];
	$page['extra']['css'][] = 'zzwrap/tests.css';
	switch (count($params)) {
	case 1:
		list($data['package']) = $params;
		$files = mf_zzwrap_test_functions($data['package']);
		if (!$files) return false;
		$data['files'] = array_values($files);
		$page['breadcrumbs'][] = ['title' => $data['package']];
		$page['title'] = wrap_text('Tests for %s', ['values' => [$data['package']]]);
		break;
	case 0:
		$files = mf_zzwrap_test_functions();
		$packages = array_unique(array_column($files, 'package'));
		$data['packages'] = [];
		foreach ($packages as $package)
			$data['packages'][]['package'] = $package;
		$page['breadcrumbs'][] = ['title' => wrap_text('Tests')];
		$page['title'] = wrap_text('Tests');
		break;
	}
	$page['text'] = wrap_template('tests', $data);
	return $page;
}
