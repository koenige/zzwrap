<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2011
// Main script


function zzwrap() {
	global $zz_setting;		// settings for zzwrap and zzbrick
	global $zz_conf;		// settings for zzform
	global $zz_page;		// page variables
	
	// include required files
	if (empty($zz_setting['lib']))
		$zz_setting['lib']			= $zz_setting['inc'].'/library';
	if (empty($zz_setting['core']))
		$zz_setting['core'] = $zz_setting['lib'].'/zzwrap';
	require_once $zz_setting['core'].'/defaults.inc.php';	// set default variables
	require_once $zz_setting['core'].'/errorhandling.inc.php';	// CMS errorhandling
	require_once $zz_setting['db_inc']; // Establish database connection
	require_once $zz_setting['core'].'/core.inc.php';	// CMS core scripts
	require_once $zz_setting['core'].'/page.inc.php';	// CMS page scripts
	if (!empty($zz_conf['error_503'])) wrap_error($zz_conf['error_503'], E_USER_ERROR);	// exit for maintenance reasons
	
	if (file_exists($zz_setting['custom_wrap_dir'].'/_functions.inc.php'))
		require_once $zz_setting['custom_wrap_dir'].'/_functions.inc.php';

	// do some checks
	wrap_check_http_request_method();
	wrap_remove_query_strings();
	wrap_check_db_connection();

	// Secret Key für Vorschaufunktion, damit auch noch nicht zur
	// Veröffentlichung freigegebene Seiten angeschaut werden können.
	if (!empty($zz_setting['secret_key']) AND !wrap_rights('preview'))
		wrap_rights('preview', 'set', wrap_test_secret_key($zz_setting['secret_key']));

	// Sprachcode etc. steht evtl. in URL
	if ($zz_conf['translations_of_fields']) {
		$zz_page['url']['full'] = wrap_prepare_url($zz_page['url']['full']);
	}

	$zz_page['db'] = wrap_look_for_page($zz_page);
	wrap_check_https($zz_page, $zz_setting);

	// Functions which may be needed for login
	if (file_exists($zz_setting['custom_wrap_dir'].'/start.inc.php'))
		require_once $zz_setting['custom_wrap_dir'].'/start.inc.php';
	
	// modules may change page language ($page['lang']), so include language functions here
	require_once $zz_setting['core'].'/language.inc.php';	// CMS language
	if ($zz_setting['authentification_possible']) {
		require_once $zz_setting['core'].'/auth.inc.php';
		wrap_auth();
	}

	if (!empty($zz_setting['cache']) AND !empty($zz_setting['cache_age'])
		AND empty($_SESSION) AND empty($_POST)) {
		// TODO: check if we can start this earlier
		wrap_send_cache($zz_setting['cache_age']);
	}

	// include standard functions (e. g. markup languages)
	// Standardfunktionen einbinden (z. B. Markup-Sprachen)
	if (!empty($zz_setting['standard_extensions']))	{
		foreach ($zz_setting['standard_extensions'] as $function) {
			if (file_exists($zz_setting['lib'].'/'.$function.'.php')) 
				require_once $zz_setting['lib'].'/'.$function.'.php';
			elseif (file_exists($zz_setting['lib'].'/'.$function.'/'.$function.'.php'))
				require_once $zz_setting['lib'].'/'.$function.'/'.$function.'.php';
			else
				wrap_error(sprintf(wrap_text('Required library %s does not exist.'), '`'.$function.'`'), E_USER_ERROR);
		}
	}
	require_once $zz_conf['dir_inc'].'/numbers.inc.php';

	if (file_exists($zz_setting['custom_wrap_dir'].'/_settings_post_login.inc.php'))
		require_once $zz_setting['custom_wrap_dir'].'/_settings_post_login.inc.php';

	// on error exit, after all files are included
	// Falls kein Eintrag in Datenbank, Umleitungen pruefen, ggf. 404 Fehler ausgeben.
	if (!$zz_page['db']) wrap_quit();
	
	wrap_translate_page();
	$page = wrap_get_page();
	
	// output of content if not already sent by wrap_get_page()
	if ($zz_setting['brick_page_templates'] == true) {
		wrap_htmlout_page($page);
	} else {
		wrap_htmlout_page_without_templates($page);
	}
	exit;
}

?>