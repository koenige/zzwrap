<?php

// Zugzwang Project
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2008
// Abfragen zu Google Maps

function cms_karte($variablen) {
	global $zz_conf;
	global $zz_setting;

	$page['text'] = '<div id="map" style="width: 600px; height: 600px"></div>
';
	$page['extra']['headers'] = '
    <script src="http://maps.google.com/maps?file=api&amp;v=2&amp;key='.$zz_setting['api_key'].'&amp;hl=de"
      type="text/javascript"></script>
    <script type="text/javascript" src="/_behaviour/markers.js.php?lang='.$zz_conf['language'].'"></script>';
	$page['extra']['body_attributes'] = ' onload="initialize()" onunload="GUnload()"';
	return $page;
}


?>