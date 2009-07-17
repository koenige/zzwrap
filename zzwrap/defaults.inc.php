<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2008
// Default variables


// -------------------------------------------------------------------------
// Hostname, Access via HTTPS or not
// -------------------------------------------------------------------------

if (empty($zz_setting['hostname'])) // SERVER_NAME, htmlentities against XSS
	$zz_setting['hostname']		= htmlentities($_SERVER['SERVER_NAME']);
if (empty($zz_setting['local_access'])) // check if it's a local server
	$zz_setting['local_access'] = (substr($zz_setting['hostname'], -6) == '.local' ? true : false);
if (empty($zz_setting['no_https'])) 
	// check if https is wanted or not
	// local connections are never made via https
	$zz_setting['no_https']		= ($zz_setting['local_access'] ? true : false);
$zz_setting['https'] = (empty($_SERVER['HTTPS']) ? false : !$zz_setting['no_https']);
if (empty($zz_setting['protocol']))
	$zz_setting['protocol'] 	= 'http'.($zz_setting['https'] ? 's' : '');
if (empty($zz_setting['host_base']))
	$zz_setting['host_base'] 	= $zz_setting['protocol'].'://'.$zz_setting['hostname'];

// -------------------------------------------------------------------------
// URLs
// -------------------------------------------------------------------------

// Base URL
$zz_page['url']['full'] = parse_url($zz_setting['host_base'].$_SERVER['REQUEST_URI']);

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

if (empty($zz_setting['base_url'])) 
	$zz_setting['base_url'] = '';

// -------------------------------------------------------------------------
// Paths
// -------------------------------------------------------------------------

// server root
if (empty($zz_conf['root']))
	$zz_conf['root']			= $_SERVER['DOCUMENT_ROOT'];

// scripts
if (empty($zz_setting['inc']))
	$zz_setting['inc']			= $zz_conf['root'].'/_inc';
	
// localized includes
if (empty($zz_setting['inc_local']))	
	$zz_setting['inc_local'] 	= $zz_setting['inc'].'/local';

// page head
if (empty($zz_page['head']))			
	$zz_page['head']			= $zz_setting['inc_local'].'/html-head.inc.php';

// page foot
if (empty($zz_page['foot']))			
	$zz_page['foot']			= $zz_setting['inc_local'].'/html-foot.inc.php';

// database connection
if (empty($zz_setting['db_inc']))
	$zz_setting['db_inc']		= $zz_setting['inc_local'].'/db.inc.php';

// cms core
if (empty($zz_setting['core']))
	$zz_setting['core']			= $zz_setting['inc'].'/cmscore';

// http errors
if (empty($zz_setting['http_errors']))
	$zz_setting['http_errors']	= $zz_conf['root'].'/_scripts/errors';

// zzform path
if (empty($zz_conf['dir']))
	$zz_conf['dir']				= $zz_setting['inc'].'/zzform';

// zzform db scripts
if (empty($zz_conf['form_scripts']))
	$zz_conf['form_scripts']	= $zz_setting['inc'].'/db';


// -------------------------------------------------------------------------
// Page
// -------------------------------------------------------------------------

// page language, html lang attribute
if (!isset($zz_page['lang']))
	if (!empty($zz_conf['language']))
		$zz_page['lang']		= $zz_conf['language'];
	else
		$zz_page['lang']		= false;

// page base
if (empty($zz_page['base']))
	$zz_page['base']			= '/';

// breadcrumbs
if (!isset($zz_page['breadcrumbs_separator']))
	$zz_page['breadcrumbs_separator'] = '&gt;';

// -------------------------------------------------------------------------
// Debugging
// -------------------------------------------------------------------------

if (!isset($zz_conf['debug']))
	$zz_conf['debug']			= false;


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


?>