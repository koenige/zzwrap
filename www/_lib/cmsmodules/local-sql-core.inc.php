<?php 

// Zugzwang CMS
// SQL Queries

global $zz_conf;

$zz_sql['pages'] = 'SELECT seite_id
	, titel'.$zz_conf['lang_suffix'].' AS titel
	, kurztitel'.$zz_conf['lang_suffix'].' AS kurztitel
	, inhalt'.$zz_conf['lang_suffix'].' AS inhalt
	, kennung'.$zz_conf['lang_suffix'].' AS kennung
	, kennung'.$zz_conf['alt_lang_suffix'].' AS alt_kennung
	, kennung_de AS bildurl
	, reihenfolge
	, ober_seite_id
	, freigabe
	, endung
	, erstelldatum
	, autor_login_id AS autor_person_id
	, menu
	, letzte_aenderung
	FROM '.$zz_conf['prefix'].'seiten seiten
	WHERE seiten.kennung'.$zz_conf['lang_suffix'].' = "%s"';

?>