<?php 

// Zugzwang CMS
// SQL Queries

global $zz_conf;

$zz_sql['pages'] = 'SELECT seiten.*
	, seiten.titel AS title
	FROM '.$zz_conf['prefix'].'seiten seiten
	WHERE seiten.kennung = "%s"';

$zz_sql['is_public'] = 'freigabe ="ja"';

$zz_sql['redirects'] = 'SELECT * FROM '.$zz_conf['prefix'].'umleitungen
	WHERE alt = "%s/"
	OR alt = "%s.html"
	OR alt = "%s"';

$zz_sql['redirects_old_fieldname'] = 'alt';
$zz_sql['redirects_new_fieldname'] = 'neu';

$zz_sql['redirects_*'] = 'SELECT * FROM '.$zz_conf['prefix'].'umleitungen
	WHERE alt = "%s*"';

$zz_field_page_id			= 'seite_id';
$zz_field_content			= 'inhalt';
$zz_field_title				= 'titel';
$zz_field_ending			= 'endung';
$zz_field_identifier		= 'kennung';
$zz_field_lastupdate		= 'letzte_aenderung';
$zz_field_author_id			= 'autor_person_id';

?>