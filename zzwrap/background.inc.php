<?php 

/**
 * zzwrap
 * background HTTP requests
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * call a job in the background, either with job manager or directly
 *
 * @param string $url
 * @param array $data (optional, further values for _jobqueue table)
 * @return bool
 */
function wrap_job($url, $data = []) {
	$path = wrap_path('jobmanager', '', false);
	if (!$path) $path = $url;
	$data['url'] = $url;
	// sequential: always use trigger
	if (!empty($data['sequential'])) $data['trigger'] = true;
	if (!empty($data['single'])) $data['trigger'] = true;
	if (!empty($data['trigger']))
		list($status, $headers, $response) = wrap_trigger_protected_url($path, false, true, $data);
	else
		list($status, $headers, $response) = wrap_get_protected_url($path, [], 'POST', $data);
	if ($status === 200) return true;
	wrap_error(sprintf('Job with URL %s failed. (Status: %d, Headers: %s)', $url, $status, json_encode($headers)));
	return false;
}

/**
 * add scheme and hostname to URL if missing
 * look for `admin_hostname`
 *
 * @param string $url
 * @return string
 */
function wrap_job_url_base($url) {
	if (!str_starts_with($url, '/')) return $url;
	if (!wrap_setting('admin_hostname')) return wrap_setting('host_base').$url;

	$hostname = wrap_setting('admin_hostname');
	$hostname = wrap_url_dev_add($hostname);
	return wrap_setting('protocol').'://'.$hostname.$url;
}

/**
 * check if a job can be started
 *
 * @param string $type
 * @return array
 */
function wrap_job_check($type) {
	if (!wrap_job_page($type)) return true;
	// check if jobmanager is present; since function is called regularly,
	// do not use wrap_path() which needs a database connection
	if (!wrap_setting('jobmanager_path')) return false;
	return mod_default_make_jobmanager_check();
}

/**
 * automatically finish a job
 *
 * @param array $job
 * @param string $type
 * @param array $content
 * @return void
 */
function wrap_job_finish($job, $type, $content) {
	if (!wrap_job_page($type)) return true;
	if (!wrap_setting('jobmanager_path')) return false;

	if (!$content)
		$content = [
			'status' => 404,
			'text' => wrap_text('not found')
		];
	
	if (!empty($content['content_type']) AND $content['content_type'] === 'json')
		$content['text'] = json_decode($content['text']);
	if (!empty($_POST['job_logfile_result'])) {
		wrap_include('file', 'zzwrap');
		wrap_file_log($_POST['job_logfile_result'], 'write', [time(), $content['extra']['job'] ?? 'job', json_encode($content['data'] ?? $content['text'])]);
	}

	$url_next = $_POST['job_url_next'] ?? $content['extra']['job_continue'] ?? '';
	if ($url_next) {
		if ($url_next === true) $url_next = $job['job_url'] ?? ''; // empty if job was stopped
		wrap_trigger_protected_url($url_next, wrap_username($job['username'] ?? '', false));
		// do not mark job as stopped if sequential mode
		if (!empty($job['postdata']['sequential'])) return true;
	}
	mod_default_make_jobmanager_finish($job, $content['status'] ?? 200, $content['text']);
}

/**
 * is page a job page?
 *
 * @param string $type
 * @return bool
 */
function wrap_job_page($type) {
	global $zz_page;
	if ($type !== 'make') return false;
	if (empty($zz_page['db']['parameters']['job'])) return false;
	
	$path = wrap_path('jobmanager', '', false);
	if (!$path) return false; // no job manager active

	wrap_include('zzbrick_make/jobmanager', 'default');
	return true;
}

/**
 * trigger next job, independent from current/last job
 * use new hash for that
 *
 * @param string $url
 * @return void
 */
function wrap_job_next($url) {
	wrap_trigger_protected_url($url, false, true, ['regenerate_hash' => 1]);
}

/**
 * call a website in the background via http
 * https is not supported
 *
 * @param string $url
 * @return array $page
 */
function wrap_trigger_url($url) {
	$port = 80;
	if (substr($url, 0, 1) === '/') {
		global $zz_page;
		$host = $zz_page['url']['full']['host'];
		$path = $url;
	} else {
		$parsed = parse_url($url);
		if ($parsed['scheme'] !== 'http') {
			$page['status'] = 503;
			$page['text'] = sprintf('Scheme %s not supported.', wrap_html_escape($parsed['scheme']));
			return $page;
		}
		if ($parsed['user'] OR $parsed['pass']) {
			$page['status'] = 503;
			$page['text'] = 'Authentication not supported.';
			return $page;
		}
		if ($parsed['port']) $port = $parsed['port'];
		$host = $parsed['host'];
		$path = $parsed['path'].($path['query'] ? '?'.$path['query'] : '');
	}
	$fp = fsockopen($host, $port);
	if ($fp === false) {
		$page['status'] = 503;
		$page['text'] = sprintf('Connection to server %s failed.', wrap_html_escape($host));
		return $page;
	}
	$out = "GET ".$path." HTTP/1.1\r\n";
	$out .= "Host: ".$host."\r\n";
	$out .= "Connection: Close\r\n\r\n";
	// @todo retry if 503 error in 10 seconds
	fwrite($fp, $out);
	// read at least one byte because some servers won't establish a connection
	// otherwise
	fread($fp, 1);
	fclose($fp);
	$page['text'] = 'Connection successful.';
	return $page;
}

/**
 * trigger a protected URL
 *
 * @param string $url
 * @param string $username (optional)
 * @param bool $send_lock defaults to true, send lock hash to child process
 * @param array $data (optional)
 * @return array from wrap_syndication_retrieve_via_http()
 */
function wrap_trigger_protected_url($url, $username = false, $send_lock = true, $data = []) {
	$username = wrap_username($username, false);
	if (wrap_setting('log_trigger')) {
		$logfile = wrap_setting('log_trigger') === true ? '' : wrap_setting('log_trigger');
		wrap_log(
			sprintf('trigger URL %s %s -> %s', date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']), wrap_setting('request_uri'), $url)
			, E_USER_NOTICE, $logfile
		);
	}
	$headers[] = 'X-Timeout-Ignore: 1';
	if (function_exists('wrap_lock_hash') AND $send_lock) {
		$headers[] = sprintf('X-Lock-Hash: %s', wrap_lock_hash($data['regnerate_hash'] ?? false));
	}
	return wrap_get_protected_url($url, $headers, 'POST', $data, $username);
}

/**
 * get a protected URL
 *
 * settings: login_key, login_key_validity_in_minutes must be set
 * @param string $url
 * @param array $headers
 * @param string $method
 * @param array $data
 * @param string $username (optional)
 * @return array from wrap_syndication_retrieve_via_http()
 */

function wrap_get_protected_url($url, $headers = [], $method = 'GET', $data = [], $username = false) {
	$username = wrap_username($username, false);
	$pwd = sprintf('%s:%s', $username, wrap_password_token($username));
	$headers[] = 'X-Request-WWW-Authentication: 1';
	// localhost: JSON
	if (wrap_get_protected_url_local($url) AND wrap_get_protected_url_html($url))
		$headers[] = 'Accept: application/json';
	$url = wrap_job_url_base($url);

	require_once __DIR__.'/syndication.inc.php';
	$result = wrap_syndication_retrieve_via_http($url, $headers, $method, $data, $pwd);
	return $result;
}

/**
 * is URL on a local or admin server?
 *
 * @param string $url
 * @return bool true: local or admin server, false: remote server
 */
function wrap_get_protected_url_local($url) {
	if (str_starts_with($url, '/')) return true;
	if (str_starts_with($url, wrap_setting('host_base'))) return true;
	if (!wrap_setting('admin_hostname')) return false;
	if (str_starts_with($url, wrap_setting('protocol').'://'.wrap_setting('admin_hostname').'/')) return true;
	if (str_starts_with($url, wrap_setting('protocol').'://dev.'.wrap_setting('admin_hostname').'/')) return true;
	if (str_starts_with($url, wrap_setting('protocol').'://'.wrap_setting('admin_hostname').'.local/')) return true;
	return false;		
}

/**
 * is URL most likely a HTML resource? check ending for that
 *
 * @param string $url
 * @return bool true: probably is HTML, false: no HTML
 */
function wrap_get_protected_url_html($url) {
	$path = parse_url($url, PHP_URL_PATH);
	if (!$path) return true; // homepage
	if (str_ends_with($path, '/')) return true;
	if (str_ends_with($path, '.html')) return true;
	if (str_ends_with($path, '.htm')) return true;
	$path = explode('/', $path);
	if (!strstr(end($path), '.')) return true;
	return false;
}
