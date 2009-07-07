<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007
// CMS-Kernfunktionen


// Local modifications to SQL queries
require_once $zz_setting['modules'].'/local-sql-core.inc.php';

/** Test, ob URL secret key enthält, der korrekt ist und so Vorschau möglich ist
 * 
 * @param $secret_key(string) shared secret key
 * @return $cms_page_preview true|false
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function cms_test_secret_key($secret_key) {
	$cms_page_preview = false;
	if (!empty($_GET['tle']) && !empty($_GET['tld']) && !empty($_GET['tlh']))
		if (time() > $_GET['tle'] && time() < $_GET['tld'] && 
			$_GET['tlh'] == md5($_GET['tle'].'&'.$_GET['tld'].'&'.$secret_key)) {
			session_start();
			$_SESSION['cms_page_preview'] = true;
			$cms_page_preview = true;
		}
	return $cms_page_preview;
}

/** Prüft, ob URL in Datenbank steht (oder Teil davon mit *)
 * 
 * @param $zz_conf(array) zz configuration variables
 * @param $zz_access(array) zz access rights
 * @return $page
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function cms_look_for_page(&$zz_conf, &$zz_access) {
	global $zz_sql;
	$page = false;
	$parameter = false;
	// URL für Datenbank aufbereiten
	$url = (!empty($_GET['cmsurl']) ? addslashes($_GET['cmsurl']) : false);
	// Für Abfrage letzten / oder .html entfernen, nicht jedoch bei Root
	if (substr($url, -1) == '/' && strlen($url) > 1) 
		$url = substr($url, 0, -1);
	elseif (substr($url, -5) == '.html' && strlen($url) > 1) 
		$url = substr($url, 0, -5);

	while (!$page) {
		$sql = sprintf($zz_sql['pages'], $url);
		if (!$zz_access['cms_page_preview']) $sql.= ' AND freigabe = "ja"';
		$result = mysql_query($sql);
		if ($zz_conf['debug'] && mysql_error()) {
			echo mysql_error();
			echo '<br>'.$sql;
		}
		if ($result) if (mysql_num_rows($result)) // == 1
			$page = mysql_fetch_assoc($result);
		if (empty($page) && strstr($url, '/')) { // nur, wenn nicht gefunden, dann weiter URL dekonstruieren
			if ($parameter) {
				$parameter = '/'.$parameter; // / als Trenner der Variablen
				$url = substr($url, 0, -1); // * entfernen
			}
			$parameter = substr($url, strrpos($url, '/')+1).$parameter;
			$url = substr($url, 0, strrpos($url, '/')).'*';
		}
		else break;
	}
	if (!$page) return false;

	$page['parameter'] = $parameter;
	$page['url'] = $url;
	return $page;
}

// Seite bestimmen
// falls Seite vorhanden, ggf. trailing slash / anfügen
function cms_check_canonical($page, $endung) {
	$request_uri = parse_url('http://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI']);
	
	// Korrekte Endung
	switch ($endung) {
	case '/':
		if (substr($request_uri['path'], -5) == '.html') {
			header("Location: http://".$_SERVER['SERVER_NAME'].substr($request_uri['path'], 0, -5).'/');
			exit;
		} elseif (substr($request_uri['path'], -1) != '/') {
			header("Location: http://".$_SERVER['SERVER_NAME'].$request_uri['path'].'/');
			exit;
		}
	break;
	case '.html':
		if (substr($request_uri['path'], -1) == '/') {
			header("Location: http://".$_SERVER['SERVER_NAME'].substr($request_uri['path'], 0, -1).'.html');
			exit;
		} elseif (substr($request_uri['path'], -5) != '.html') {
			header("Location: http://".$_SERVER['SERVER_NAME'].$request_uri['path'].'.html');
			exit;
		}
	break;
	case 'keine':
		if (substr($request_uri['path'], -5) == '.html') {
			header("Location: http://".$_SERVER['SERVER_NAME'].substr($request_uri['path'], 0, -5));
			exit;
		} elseif (substr($request_uri['path'], -1) == '/') {
			header("Location: http://".$_SERVER['SERVER_NAME'].substr($request_uri['path'], 0, -1));
			exit;
		}
	break;
	}
	// todo:  AND substr($request_uri['path'], 0, 8) != '/kunden/' einbinden
	// falls gezielt einige bereiche ausgeblendet werden sollen
	// todo:  abhängig von cms-Funktion unterschiedliche Endung erlauben
	
}

/** Baut URL aus REQUEST zusammen
 * 
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function read_url() {
	$url['full'] = parse_url($_SERVER['REQUEST_URI']); 
	// besser als via mod_rewrite, da dort fehlerhaft mit dem '&' umgegangen wird.
	$url['db'] = $url['full']['path'];
	$url['suffix_length'] = (!empty($_GET['lang']) ? strlen($_GET['lang']) + 6 : 5);
	// '/' am Beginn und ggf. am Ende abschneiden
	if (substr($url['db'], 0, 1) == '/') $url['db'] = substr($url['db'], 1);
	if (substr($url['db'], -1) == '/') $url['db'] = substr($url['db'], 0, -1);
	if (substr($url['db'], -5) == '.html') $url['db'] = substr($url['db'], 0, -5);
	if (!empty($_GET['lang']))
		if (substr($url['db'], -$url['suffix_length']) == '.html.'.$_GET['lang']) 
			$url['db'] = substr($url['db'], 0, -$url['suffix_length']);
	return $url;
}

/** Beendet Ausführung, Check für evtl. Umleitungen auf andere Seiten
 * 
 * Die Ausführung des CMS wird beendet und es wird getestet, ob evtl.
 * ein Eintrag in der Umleitungstablle steht und die Seite umgeleitet werden soll.
 * Falls ja, wird mit 301 oder 302 umgeleitet oder mit 410 darauf verwiesen, daß
 * keine Umleitung vorhanden ist. Sonst wird ein undefinierter Fehler 404 
 * ausgegeben, mit entsprechender 404-Seite
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
 function quit_cms($errorcode = 404) {
	global $zz_conf;
	global $zz_setting;
	$redir = false;

	// check for redirects, if there's a corresponding table.
	if (!empty($zz_setting['check_redirects'])) {
		$url = read_url();
		$url['db'] = mysql_real_escape_string($url['db']);
		$sql = 'SELECT * FROM '.$zz_conf['prefix'].'umleitungen
			WHERE alt = "/'.$url['db'].'/"
			OR alt = "/'.$url['db'].'.html"
			OR alt = "/'.$url['db'].'"';
		if (!empty($_GET['lang'])) {
			$sql.= ' OR alt = "/'.$url['db'].'.html.'.mysql_real_escape_string($_GET['lang']).'"';
		}
		$result = mysql_query($sql);
		if ($result) if (mysql_num_rows($result) == 1)
			$redir = mysql_fetch_assoc($result);

		// Prüfen, ob falls bisher keine Umleitung gefunden wurde, vielleicht eine Umleitung
		// mit * am Ende darüber vorhanden ist.
		$parameter = false;
		$found = false;
		$break_next = false;
		if (!$redir) {
			while (!$found) {
				$sql = 'SELECT * FROM '.$zz_conf['prefix'].'umleitungen
					WHERE alt = "/'.$url['db'].'*"';
				$result = mysql_query($sql);
				if ($result) if (mysql_num_rows($result) == 1)
					$redir = mysql_fetch_assoc($result);
				if ($redir) break; // Ergebnis, also raus hier!
				if (strrpos($url['db'], '/'))
					$parameter = '/'.substr($url['db'], strrpos($url['db'], '/')+1).$parameter;
				$url['db'] = substr($url['db'], 0, strrpos($url['db'], '/'));
				if ($break_next) break; // letzte Runde
				if (!strstr($url['db'], '/')) $break_next = true;
			}
			if ($redir) {
				// Falls * am Ende der Umleitung, wird der abgeschnittene Pfad
				// hinten angefügt
				if (substr($redir['neu'], -1) == '*')
					$redir['neu'] = substr($redir['neu'], 0, -1).$parameter;
			}
		}
	}
	if (!$redir) $redir['code'] = $errorcode;

	// Set protocol
	$protocol = $_SERVER['SERVER_PROTOCOL'];
	if (!$protocol) $protocol = 'HTTP/1.0'; // default value

	// Check redirection code	
	switch ($redir['code']) {
	case 301:
		header($protocol." 301 Moved Permanently");
	case 302:
		// header wird automatisch 302 gesendet bei Location
		$neu = parse_url($redir['neu']);
		if (!empty($neu['scheme'])) {
			$neu = $redir['neu'];
		} else {
			$neu = 'http://'.$_SERVER['SERVER_NAME'].$redir['neu'];
		}
		header("Location: ".$neu);
		break;
	case 403:
		header($protocol." 403 Forbidden");
		include_once $zz_setting['http_errors'].'/403.php';
		break;
	case 410:
		header($protocol." 410 Gone");
		include_once $zz_setting['http_errors'].'/410.php';
		break;
	case 503:
		header($protocol." 503 Service Unavailable");
		include_once $zz_setting['http_errors'].'/503.php';
		break;
	case 404:
	default: // nicht definiert, sollte nicht in der Datenbank auftreten
		header($protocol." 404 Not Found");
		include_once $zz_setting['http_errors'].'/404.php';
	}
	exit;
}


/** Format database content from CMS
 * 
 * @param $text(string) = text field read from database
 * @param $parameters(array) = parameters, via URL or via function
 * @return $page array
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function cms_format($text, $parameters) {
	// standardize line breaks
	$content = str_replace(array("\r\n", "\r"), "\n", $text);
	// cut content and query blocks
	$content = explode('%%%', $content); 

	// initialize variables
	$page['text'] = false;				// textbody
	$page['title'] = false;				// page title and h1 element
	$page['breadcrumbs_extra'] = false;	// additional breadcrumbs
	$page['authors'] = array();			// authors of page
	$page['media'] = array();			// media linked from page
	$page['language_link'] = false;		// language links to other language(s)

	// positions (if more than one)
	global $zz_setting;
	if (empty($zz_setting['default_position'])) 
		$zz_setting['default_position'] = 'none';

	$forbidden_access = false;			// access is allowed
	$position = 'none';
	$page['text'][$position] = false;
	$cut_next_paragraph = false;		// to get markdown formatted text inline
	$replace_db_text[$position] = false;

	foreach ($content as $index => $text) {
		if ($index & 1) {	// gerade index-werte: normaler text, 
							// ungerade index-werte: bedarf der nachbearbeitung
			$cut_next_paragraph = false;
			
			$part = trim($text);
			$variables = explode("\n", $part); // entweder durch zeilen begrenzt
			if (count($variables) == 1)
				$variables = explode(" ", $part); // oder durch leerzeichen

			// put variables with spaces, but enclosed in "" back together
			$paste = false;
			foreach ($variables as $index => $var) {
				if ($paste) {
					$variables[$index] = $paste.' '.$var;
				} elseif (substr($var, 0, 1) == '"'
					AND substr($var, -1) == '"') {
					$var = substr($var, 1, -1);
					$variables[$index] = $var;
				}
				if (substr($var, 0, 1) == '"') {
					$paste = substr($var, 1);
					unset($variables[$index]);
				} elseif (substr($var, -1) == '"') {
					$paste = false;
					$variables[$index] = substr($variables[$index], 0, -1);
				}
			}
			// get correct keys in order
			$new_variables = false;
			foreach ($variables as $var) {
				$new_variables[] = $var;
			}
			$variables = $new_variables;

			switch ($variables[0]) {
			case 'request':
			case 'abfrage':
				array_shift($variables); // 'abfrage' or 'request', won't be needed anymore
			case 'image':
			case 'bild':
				if ($variables[0] == 'bild' OR $variables[0] == 'image')
					$variables[] = '*'; // to transport additional variables which are needed
					// so %%% image 23 %%% may be as small as possible
				$request = 'cms_'.strtolower($variables[0]); // name of function to be called
				$funktionsname = $variables[0];
				array_shift($variables); // won't be needed anymore
				$parameters_for_function = cms_variables_to_function($variables, $parameters);
				if (function_exists($request)) {
					$mypage = $request($parameters_for_function);
					if (empty($mypage)) return false;
					// check if there's some </p>text<p>, remove it for inline results of function
					if (!is_array($mypage['text'])) if (substr($mypage['text'], 0, 1) != '<' 
						AND substr($mypage['text'], -1) != '>') {
						///echo substr(trim($page['text'][$position]), -4);
						if (substr(trim($page['text'][$position]), -4) == '</p>') {
							$page['text'][$position] = substr(trim($page['text'][$position]), 0, -4).' ';
							$cut_next_paragraph = true;
						}
					}
					if (!empty($mypage['replace_db_text'])) {
						$replace_db_text[$position] = true;
						$page['text'][$position] = '';
					}
					if (is_array($mypage['text'])) {
						foreach ($mypage['text'] AS $myposition => $content) {
							if (!empty($page['text'][$myposition])) 
								$page['text'][$myposition] .= $mypage['text'][$myposition];
							else
								$page['text'][$myposition] = $mypage['text'][$myposition];
						}
					} else
						$page['text'][$position] .= $mypage['text'];
					if (!empty($mypage['title']))
						$page['title'] = $mypage['title'];
					if (!empty($mypage['authors']) AND is_array($mypage['authors']))
						$page['authors'] = array_merge($page['authors'], $mypage['authors']);
					if (!empty($mypage['media']) AND is_array($mypage['media']))
						$page['media'] = array_merge($page['media'], $mypage['media']);
					if (!empty($mypage['breadcrumbs']))
						$page['breadcrumbs_extra'] = $mypage['breadcrumbs'];
					if (!empty($mypage['dont_show_h1']))
						$page['dont_show_h1'] = $mypage['dont_show_h1'];
					if (!empty($mypage['language_link']))
						$page['language_link'] = $mypage['language_link'];
					if (!empty($mypage['extra']))	// for all individual needs, not standardized
						$page['extra'] = $mypage['extra'];
					if (!empty($mypage['no_page_head']))
						$page['no_page_head'] = $mypage['no_page_head'];
					if (!empty($mypage['no_page_foot']))
						$page['no_page_foot'] = $mypage['no_page_foot'];
					if (!empty($mypage['last_update']))
						$page['last_update'] = $mypage['last_update'];
					if (!empty($mypage['head_addition']))
						$page['head_addition'] = $mypage['head_addition'];
					if (!empty($mypage['style']))
						$page['style'] = $mypage['style'];
				} else
					$page['text'][$position] .= '<p><strong class="error">The function &#187;'
						.$funktionsname.'&#171; is not supported by the CMS.</strong></p>';
				break;
			case 'position':
				$position = $variables[1];
				if (!empty($variables[2])) $position .= $variables[2]; // in case someone put a space between row and column
				if (empty($page['text'][$position])) {
					$page['text'][$position] = false; // initialisieren
					$replace_db_text[$position] = false;
				}
				break;
			case 'tables':
			case 'tabellen':
			case 'verwaltung':
			case 'forms':
				if ($variables[0] == 'verwaltung') $desc_dir = 'verwaltung';
				elseif ($variables[0] == 'forms') $desc_dir = 'forms';
				elseif ($variables[0] == 'publicforms') $desc_dir = 'publicforms';
				else $desc_dir = 'db';
				if (empty($position))
					$position = $zz_setting['default_position'];
				$scriptpath = $parameters;
				if ($variables[0] == 'forms' OR !$parameters) {
					$scriptpath = $variables[1];
				} elseif (substr($parameters, -1) == '*') {
					$scriptpath = substr($parameters, 0, -1);
				}
				array_shift($variables); // 'verwaltung', wird nicht mehr gebraucht
				global $zz_conf;
				global $zz_setting;
				global $zz_access;
				if (file_exists($tabellen = $zz_setting['inc'].'/'.$desc_dir.'/'.$scriptpath.'.php')) {
					// check whether script shall be made accessible from public
					if (empty($variables[1]) OR $variables[1] != 'public') {
						require_once $zz_setting['core'].'/auth.inc.php';
						if (!empty($_SESSION)) $zz_conf['user'] = $_SESSION['username'];
					}
					require_once $zz_conf['dir'].'/inc/edit.inc.php';
					require_once $tabellen;
					$zz_conf['show_output'] = false;
					zzform();
					$page['text'][$position] = $zz['output'];
					$page['title'] = ((!empty($zz_conf['title'])) ? $zz_conf['title'] : cms_text('Error'));
					$page['breadcrumbs_extra'][] = $page['title'];
					$page['dont_show_h1'] = true;
					if ($zz['mode'] == 'export') {
						foreach ($zz['headers'] as $index) {
							foreach ($index as $bool => $header) {
								header($header, $bool);
							}
						}
						echo $zz['output'];			// Output der Funktion ausgeben
						exit;
					}
				} else {
					return false;
				}
				break;
			case 'rights':
			case 'rechte':
				array_shift($variables); // 'rights', will not be used
				if (!cms_access_rights($variables)) {
					$position = 'hidden';
					$page['text']['hidden'] = false;
					// ok, something is forbidden, will not be shown
					// mark it as forbidden, so if nothing will be shown, we can
					// answer with 403 forbidden
					$forbidden_access = true; 
				}
				break;
			case 'comment':
			case 'kommentar':
				// Kommentare werden nicht angezeigt, nur für interne Zwecke.
				break;
			case 'redirect':
				array_shift($variables); // 'redirect', wird nicht mehr gebraucht
				global $zz_conf;
				global $zz_setting;
				require_once $zz_conf['dir'].'/inc/validate.inc.php';
				if (zz_check_url($variables[0])) {
					if (substr($variables[0], 0, 1) == '/')
						$variables[0] = $zz_setting['host_base'].$variables[0];
					header('Location: '.$variables[0]);
					exit;
				}
			default:
				$page['text'][$position].= '<p><strong class="error">Error 
					in the CMS: '.$variables[0].' is not a valid parameter.</strong></p>';
			}
		} elseif ($text AND empty($replace_db_text[$position])) {
			// behind %%% -- %%% blocks, an additional newline will appear
			// remove it, because we do not want it
			if ($index) $text = trim($text)."\n"; 
			// format text block (default mode)
			$text_to_add = cms_textformat($text, 'pieces');
			// check if there's some </p>text<p>, remove it for inline results of function
			if ($cut_next_paragraph && substr(trim($text_to_add), 0, 3) == '<p>') {
				$text_to_add = ' '.substr(trim($text_to_add), 3);
				$cut_next_paragraph = false;
			}
			$page['text'][$position] .= $text_to_add; // if wichtig, 
				// sonst macht markdown auch aus leerer variable etwas
		}
	}
	if (!empty($page['text']['hidden'])) {
		unset($page['text']['hidden']);
		$forbidden_access = true;
	}
	if (!$page['text']['none']) unset($page['text']['none']);
	// if position is not wanted, remove unneccessary complexity in array
	if (count($page['text']) == 1 AND !empty($page['text']['none'])) {
		if ($zz_setting['default_position'] == 'none') {
			$page['text'] = cms_textformat($page['text']['none'], 'full');
		} else {
			$page['text'][$zz_setting['default_position']] = cms_textformat($page['text']['none'], 'full');
			unset ($page['text']['none']);
		}
	} elseif (!count($page['text']) AND $forbidden_access) {
		quit_cms(403);
	} else {
	// new
		foreach ($page['text'] AS $pos => $text) {
			$page['text'][$pos] = cms_textformat($text, 'full');
		}
	}
	return $page;
}

function cms_textformat($string, $type) {
	global $zz_setting;
	
	if (!empty($zz_setting['fulltextformat'])
		AND function_exists($zz_setting['fulltextformat'])) {
		if ($type == 'pieces') {
			return $string;
		} elseif ($type == 'full') {
			return $zz_setting['fulltextformat']($string);
		}
	} else {
		// Standard formatting, each piece will be treated seperately, for backwards 
		// compatibility
		if ($type == 'pieces') {
			return markdown($string);
		} elseif ($type == 'full') {
			return $string;
		}
	}
}

function cms_variables_to_function($variables, $parameters) {
	$parameters_for_function = false;
	$var_safe = false;
	foreach ($variables as $var) {
		if ($var == '*') {
			$url_parameters = explode('/', $parameters);
			if ($parameters_for_function)
				$parameters_for_function = array_merge($parameters_for_function, 
				$url_parameters); // parameters transmitted via URL
			// Attention: if there are more than one *-variables
			// parameters will be inserted several times
			else
				$parameters_for_function = $url_parameters;
		} else {
			if (substr($var, 0, 1) == '"' && substr($var, -1) == '"')
				$parameters_for_function[] = substr($var, 1, -1);
			elseif (substr($var, 0, 1) == '"')
				$var_safe[] = substr($var, 1);
			elseif ($var_safe && substr($var, -1) != '"') 
				$var_safe[] = $var;
			elseif ($var_safe && substr($var, -1) == '"') {
				$var_safe[] = substr($var, 0, -1);
				$parameters_for_function[] = implode(" ", $var_safe);
				$var_safe = false;
			} else 
				$parameters_for_function[] = $var; // Parameter wie uebergeben, nur neu indiziert
		}
	}
	return $parameters_for_function;
}

?>