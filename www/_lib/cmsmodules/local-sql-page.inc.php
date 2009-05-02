<?php 

// Zugzwang CMS
// SQL Queries

global $zz_conf;

$zz_sql['breadcrumbs'] = 'SELECT seite_id, 
		kurztitel title, 
		CONCAT(IF(STRCMP(kennung, "/"), kennung, ""), IF(STRCMP(endung, "keine"), endung, "")) AS identifier, 
		ober_seite_id mother_page_id
	FROM '.$zz_conf['prefix'].'seiten';

?>