<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2008
// Default variables


// -------------------------------------------------------------------------
// Hostname, Access via HTTPS or not
// -------------------------------------------------------------------------

if (empty($zz_setting['hostname'])) // SERVER_NAME, htmlentities against XSS
	$zz_setting['hostname']		= htmlentities($_SERVER['SERVER_NAME']);
if (empty($zz_setting['local_access'])) // check if it's a local server
	$zz_setting['local_access'] = (substr($zz_setting['hostname'], -6) == '.local' ? true : false);

if (empty($zz_setting['base_url'])) 
	$zz_setting['base_url'] = '';

if (empty($zz_setting['https'])) $zz_setting['https'] = false;
// HTTPS; zzwrap authentification will always be https
if (!empty($zz_setting['https_urls'])) {
	foreach ($zz_setting['https_urls'] AS $url) {
		if ($zz_setting['base_url'].$url == substr($_SERVER['REQUEST_URI'], 0, strlen($zz_setting['base_url'].$url)))
			$zz_setting['https'] = true;
	}
}
// local connections are never made via https
if (!empty($zz_setting['local_access'])) {
	$zz_setting['https'] = false;
	$zz_setting['no_https'] = true;
}
// explicitly do not want https even for authentification (not recommended)
if (!empty($zz_setting['no_https'])) $zz_setting['https'] = false;
else $zz_setting['no_https'] = false;

// allow to choose manually whether one uses https or not
if (!isset($zz_setting['ignore_scheme'])) $zz_setting['ignore_scheme'] = false;
if ($zz_setting['ignore_scheme']) 
	$zz_setting['https'] = (empty($_SERVER['HTTPS']) ? false : true);

if (empty($zz_setting['protocol']))
	$zz_setting['protocol'] 	= 'http'.($zz_setting['https'] ? 's' : '');
if (empty($zz_setting['host_base']))
	$zz_setting['host_base'] 	= $zz_setting['protocol'].'://'.$zz_setting['hostname'];

// -------------------------------------------------------------------------
// URLs
// -------------------------------------------------------------------------

// Base URL, allow it to be set manually (handle with care!)
// e. g. for Content Management Systems without mod_rewrite or websites in subdirectories
if (empty($zz_page['url']['full'])) {
	$zz_page['url']['full'] = parse_url($zz_setting['host_base'].$_SERVER['REQUEST_URI']);
}

// More URLs
if (empty($zz_setting['homepage_url']))
	$zz_setting['homepage_url']	= '/';

// Relative linking
if (empty($zz_page['deep']))
	if (!empty($zz_page['url']['full']['path']))
		$zz_page['deep'] = str_repeat('../', (substr_count('/'.$zz_page['url']['full']['path'], '/') -2));
	else
		$zz_page['deep'] = '/';
		
if (empty($zz_setting['login_entryurl']))
	$zz_setting['login_entryurl'] = '/';

// -------------------------------------------------------------------------
// Paths
// -------------------------------------------------------------------------

// server root
if (empty($zz_conf['root']))
	$zz_conf['root']			= $_SERVER['DOCUMENT_ROOT'];

// scripts
if (empty($zz_setting['inc']))
	$zz_setting['inc']			= $zz_conf['root'].'/_inc';

// library
if (empty($zz_setting['lib']))
	$zz_setting['lib']			= $zz_setting['inc'].'/library';
	
// localized includes
if (empty($zz_setting['custom']))	
	$zz_setting['custom'] 	= $zz_setting['inc'].'/custom';

// customized cms includes
if (empty($zz_setting['custom_wrap_dir']))	
	$zz_setting['custom_wrap_dir'] = $zz_setting['custom'].'/zzwrap';

// customized sql queries, db connection
if (empty($zz_setting['custom_wrap_sql_dir']))	
	$zz_setting['custom_wrap_sql_dir'] = $zz_setting['custom'].'/zzwrap_sql';

// customized sql queries, db connection
if (empty($zz_setting['custom_wrap_template_dir']))	
	$zz_setting['custom_wrap_template_dir'] = $zz_setting['custom'].'/zzwrap_templates';

// database connection
if (empty($zz_setting['db_inc']))
	$zz_setting['db_inc']		= $zz_setting['custom_wrap_sql_dir'].'/db.inc.php';

// cms core
if (empty($zz_setting['core']))
	$zz_setting['core']			= $zz_setting['lib'].'/zzwrap';

// http errors
if (empty($zz_setting['http_error_script']))
	$zz_setting['http_error_script']	= $zz_conf['root'].'/_scripts/errors.php';

// zzform path
if (empty($zz_conf['dir']))
	$zz_conf['dir']				= $zz_setting['lib'].'/zzform';
if (empty($zz_conf['dir_custom']))
	$zz_conf['dir_custom']		= $zz_setting['custom'].'/zzform';
if (empty($zz_conf['dir_ext']))
	$zz_conf['dir_ext']			= $zz_setting['lib'];
if (empty($zz_conf['dir_inc']))
	$zz_conf['dir_inc']			= $zz_conf['dir'];

// zzform db scripts
if (empty($zz_conf['form_scripts']))
	$zz_conf['form_scripts']	= $zz_setting['custom'].'/zzbrick_tables';

// local pwd
if (empty($zz_setting['local_pwd']))
	$zz_setting['local_pwd'] = "/Users/pwd.inc";

// -------------------------------------------------------------------------
// Page
// -------------------------------------------------------------------------

// allow %%% page ... %%%-syntax
if (empty($zz_setting['brick_page_templates']))
	$zz_setting['brick_page_templates'] = false;

// page language, html lang attribute
if (!isset($zz_setting['lang']))
	if (!empty($zz_conf['language']))
		$zz_setting['lang']		= $zz_conf['language'];
	else
		$zz_setting['lang']		= false;

// translations
if (!isset($zz_conf['translations_of_fields']))
	$zz_conf['translations_of_fields'] = false;

// page base
if (empty($zz_page['base']))
	$zz_page['base']			= '/';

// breadcrumbs
if (!isset($zz_page['breadcrumbs_separator']))
	$zz_page['breadcrumbs_separator'] = '&gt;';


// -------------------------------------------------------------------------
// Page paths
// -------------------------------------------------------------------------

if (empty($zz_page['http_error_template']))
	$zz_page['http_error_template']	= $zz_setting['core'].'/default-http-error.template.txt';

if (!$zz_setting['brick_page_templates']) {
	// page head
	if (empty($zz_page['head']))
		$zz_page['head']		= $zz_setting['custom_wrap_dir'].'/html-head.inc.php';
	// page foot
	if (empty($zz_page['foot']))			
		$zz_page['foot']		= $zz_setting['custom_wrap_dir'].'/html-foot.inc.php';
} else {
	// page
	if (empty($zz_page['brick_template']))
		$zz_page['brick_template']	= $zz_setting['custom_wrap_template_dir'].'/page.template.txt';
}

// -------------------------------------------------------------------------
// Debugging
// -------------------------------------------------------------------------

if (!isset($zz_conf['debug']))
	$zz_conf['debug']			= false;

// -------------------------------------------------------------------------
// Error Logging
// -------------------------------------------------------------------------

if (!isset($zz_conf['error_log']['error']))
	$zz_conf['error_log']['error']	= ini_get('error_log');

if (!isset($zz_conf['error_log']['warning']))
	$zz_conf['error_log']['warning']	= ini_get('error_log');

if (!isset($zz_conf['error_log']['notice']))
	$zz_conf['error_log']['notice']	= ini_get('error_log');

if (!isset($zz_conf['log_errors']))
	$zz_conf['log_errors'] 			= ini_get('log_errors');

if (!isset($zz_conf['log_errors_max_len']))
	$zz_conf['log_errors_max_len'] 	= ini_get('log_errors_max_len');

if (!isset($zz_conf['error_mail_level']))
	$zz_conf['error_mail_level']	= array('warning', 'error');


// -------------------------------------------------------------------------
// Database structure
// -------------------------------------------------------------------------

$zz_field_page_id		= 'page_id';
$zz_field_content		= 'content';
$zz_field_title			= 'title';
$zz_field_ending		= 'ending';
$zz_field_identifier	= 'identifier';
$zz_field_lastupdate	= 'last_update';
$zz_field_author_id		= 'author_person_id';

if (!isset($zz_conf['prefix']))
	$zz_conf['prefix'] = ''; // prefix for all database tables


// -------------------------------------------------------------------------
// Authentification
// -------------------------------------------------------------------------

if (!isset($zz_setting['authentification_possible']))
	$zz_setting['authentification_possible'] = true;

if (!isset($zz_setting['logout_inactive_after']))
	$zz_setting['logout_inactive_after'] = 30; // time in minutes


// -------------------------------------------------------------------------
// Encryption
// -------------------------------------------------------------------------

if (empty($zz_conf['password_encryption'])) 
	$zz_conf['password_encryption'] = 'md5';

?>