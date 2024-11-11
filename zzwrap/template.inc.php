<?php 

/**
 * zzwrap
 * template functions
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Puts data from request into template and returns full page
 *
 * @param string $template Name of template that will be filled
 * @param array $data Data which will be used to fill the template
 * @param string $mode
 *		'ignore position': ignores position, returns a string instead of an array
 *		'error': returns simple template, with placeholders
 * @return mixed $text (string or array indexed by positions)
 */
function wrap_template($template, $data = [], $mode = false) {
	wrap_page_format_files();

	if (strstr($template, "\n")) {
		wrap_setting('current_template', '(from variable)');
		wrap_setting('current_template_file', '');
		$template = explode("\n", $template);
		// add newline that explode removed to each line
		foreach (array_keys($template) as $no) {
			$template[$no] .= "\n";
		}
	} elseif (str_starts_with($template, '/') AND file_exists($template)) {
		$tpl_file = $template;
		wrap_setting('current_template', $template);
		wrap_setting('current_template_file', $tpl_file);
		$template = file($tpl_file);
	} else {
		$tpl_file = wrap_template_file($template);
		if (!$tpl_file) return false;
		wrap_setting('current_template', $template);
		wrap_setting('current_template_file', $tpl_file);
		$template = file($tpl_file);
	}
	// remove comments and next empty line from the start
	foreach ($template as $index => $line) {
		if (substr($line, 0, 1) === '#') unset($template[$index]); // comments
		elseif (!trim($line)) unset($template[$index]); // empty lines
		else break;
	}
	$template = implode("", $template);
	if (!trim($template)) return '';
	// now we have the template as string, in case of error, return
	if ($mode === 'error') return $template;

	// replace placeholders in template
	// save old setting regarding text formatting
	$old_brick_fulltextformat = wrap_setting('brick_fulltextformat');
	// apply new text formatting
	wrap_setting('brick_fulltextformat', 'brick_textformat_html');
	$page = brick_format($template, $data);
	// restore old setting regarding text formatting
	wrap_setting('brick_fulltextformat', $old_brick_fulltextformat);

	// get rid of if / else text that will be put to hidden
	if (is_array($page['text']) AND count($page['text']) === 2 
		AND in_array('_hidden_', array_keys($page['text']))
		AND in_array(wrap_setting('brick_default_position'), array_keys($page['text']))) {
		unset($page['text']['_hidden_']);
		$page['text'] = end($page['text']);
	}
	if ($mode === 'ignore positions' AND is_array($page['text']) AND count($page['text']) === 1) {
		$page['text'] = current($page['text']);
	}
	// check if errors occured while filling in the template
	wrap_page_check_if_error($page);
	return $page['text'];
}

/**
 * Gets template filename for any given template
 *
 * @param string $template
 * @param bool $show_error return with error 503 if not found or not
 * @return string $filename
 */
function wrap_template_file($template, $show_error = true) {
	if (wrap_setting('active_theme')) {
		$tpl_file = wrap_template_file_per_folder($template, wrap_setting('themes_dir').'/'.wrap_setting('active_theme').'/templates');
		if ($tpl_file) return $tpl_file;
	}
	
	$tpl_file = wrap_template_file_per_folder($template, wrap_setting('custom').'/templates');
	if ($tpl_file) return $tpl_file;

	// check if there's a module template
	if (strstr($template, '/')) {
		// is it a full file name coming from 'tpl_file'?
		// we cannot do anything with this here
		if (substr($template, 0, 1) === '/') return false;
		$template_parts = explode('/', $template);
		$my_module = array_shift($template_parts);
		$template = implode('/', $template_parts);
	} else {
		$my_module = '';
	}
	$found = [];
	$packages = array_merge(wrap_setting('modules'), wrap_setting('themes'));
	foreach ($packages as $package) {
		if ($my_module AND $package !== $my_module) continue;
		$dir = in_array($package, wrap_setting('modules'))
			? wrap_setting('modules_dir') : wrap_setting('themes_dir');
		$pathinfo = pathinfo($template);
		if (!empty($pathinfo['dirname'])
			AND in_array($pathinfo['dirname'], ['layout', 'behaviour'])
			AND !empty($pathinfo['extension'])
		) {
			// has path and extension = separate file, other folder
			$tpl_file = sprintf('%s/%s/%s', $dir, $package, $template);
		} else {
			$tpl_file = wrap_template_file_per_folder($template, $dir.'/'.$package.'/templates');
		}
		if ($tpl_file) $found[$package] = $tpl_file;
	}
	// ignore default template if there’s another template from a module
	$found = wrap_template_file_decide($found);
	
	if (count($found) !== 1) {
		if (!$show_error) return false;
		global $zz_page;
		if (!$found) {
			$error_msg = wrap_text('Template <code>%s</code> does not exist.', ['values' => wrap_html_escape($template)]);
		} else {
			$error_msg = wrap_text('More than one template with the name <code>%s</code> exists.', ['values' => wrap_html_escape($template)]);
		}
		if (!empty($zz_page['error_code'])) {
			echo $error_msg;
			return false;
		} else {
			wrap_quit(503, $error_msg);
		}
	} else {
		$package = key($found);
		if (in_array($package, wrap_setting('modules')))
			wrap_package_activate($package);
		else
			wrap_package_activate($package, 'theme');
		$tpl_file = reset($found);
	}
	return $tpl_file;
}

/**
 * decide if more than one template was found which one to use
 *
 * @param array $founde
 * @return array
 */
function wrap_template_file_decide($found) {
	if (count($found) <= 1) return $found;
	
	// remove inactive themes
	foreach ($found as $package => $path) {
		if (!str_starts_with($path, wrap_setting('themes_dir'))) continue;
		if ($package === wrap_setting('active_theme')) continue;
		unset($found[$package]);
	}
	if (count($found) <= 1) return $found;
	
	// two templates found? always overwrite default module
	if (count($found) === 2 AND array_key_exists('default', $found))
		unset($found['default']);

	return $found;
}

/**
 * Checks per folder (custom/templates, modules/templates) if there's a template
 * in that folder; checks first for language variations, then for languages
 * and at last for templates without language information
 *
 * @param string $template
 * @param string $folder
 * @return string $filename
 */
function wrap_template_file_per_folder($template, $folder) {
	if (wrap_setting('lang')) {
		if (wrap_setting('language_variation')) {
			$tpl_file = $folder.'/'.$template.'-'.wrap_setting('lang').'-'.wrap_setting('language_variation').'.template.txt';
			if (file_exists($tpl_file)) return $tpl_file;
		}
		$tpl_file = $folder.'/'.$template.'-'.wrap_setting('lang').'.template.txt';
		if (file_exists($tpl_file)) return $tpl_file;
	}
	$tpl_file = $folder.'/'.$template.'.template.txt';
	if (file_exists($tpl_file)) return $tpl_file;
	return '';
}