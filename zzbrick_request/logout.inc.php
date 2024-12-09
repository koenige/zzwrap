<?php 

/**
 * zzwrap module
 * Logout from restricted area
 *
 * Part of »Zugzwang Project«
 * https://www.zugzwang.org/modules/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2007-2012, 2014-2024 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


/**
 * Logout from restricted area
 *
 * should be used via %%% request logout %%%
 * @param array $params -
 * @return - (redirect to main page)
 */
function mod_zzwrap_logout($params) {
	// Stop the session, delete all session data
	wrap_session_stop();

	$host_base = str_starts_with(wrap_setting('login_url'), '/') ? wrap_setting('host_base') : '';
	wrap_redirect($host_base.wrap_setting('login_url').'?logout', 307, false);
	exit;
}
