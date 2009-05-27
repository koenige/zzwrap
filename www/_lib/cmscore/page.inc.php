<?php 

// Zugzwang CMS
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2007-2008
// Standard-Funktionen für Seite


// Autoren erfassen
function cms_lese_autoren($page_autoren, $autor_id) {
	$autor = '';
	// Autor der Seite im CMS zu den Autoren aus Abfragen hinzufuegen
	if ($autor_id) $page_autoren[] = $autor_id;
	$sql = 'SELECT vorname, nachname, kennung
		FROM fdr_personen WHERE person_id IN ('.implode(', ', $page_autoren).')';
	$result = mysql_query($sql);
	if ($result) if (mysql_num_rows($result))
		while ($line = mysql_fetch_assoc($result)) {
			$autorteile = explode(' ', strtolower($line['vorname'].' '.$line['nachname']));
			$autor_kuerzel = '';
			foreach ($autorteile as $initial) {
				$autor_kuerzel .= substr($initial, 0, 1);
				$autor[] = $autor_kuerzel;
				//TODO: $autor[] = '<a href="'.$my['baseurl'].$my['pfad_personen'].$line['Kennung'].'" title="zur Seite von '.$line['Vorname'].' '.$line['Nachname'].'">'.$autor_kuerzel.'</a>';
			}
		}
	return $autor;
}

// Brotkrumen, Basis
function cms_lese_brotkrumen($seite_id, $seitenkennung, $extra_krumen) {
	global $zz_conf;
	$brotkrumen = false;
	if (empty($zz_conf['breadcrumb_separator']))
		$zz_conf['breadcrumb_separator'] = '&gt;';
	
	// 1. Schritt: alle Seiten erfassen
	$sql = 'SELECT seite_id, kurztitel, kennung, ober_seite_id
		FROM '.$zz_conf['prefix'].'seiten';
	$result = mysql_query($sql);
	if ($result) if (mysql_num_rows($result))
		while ($line = mysql_fetch_assoc($result))
			$pages[$line['seite_id']] = $line;

	// 2. Schritt: Brotkrumen zusammenbauen
	$arr_brotkrumen = cms_zeige_brotkrumen($seite_id, $pages);
	if (!empty($extra_krumen)) unset($arr_brotkrumen[0]);
	krsort($arr_brotkrumen);
	foreach ($arr_brotkrumen as $broesel)
		$brotkrumen[] = 
			($broesel['kennung'] == $seitenkennung ? '<strong>' : '<a href="'
			.($broesel['kennung'] == '/' ? false : $broesel['kennung']).'/">')
			.$broesel['kurztitel'].($broesel['kennung'] == $seitenkennung ? '</strong>' : '</a>');
	if (!$brotkrumen) return false;
	
	$brotkrumen = implode(' '.$zz_conf['breadcrumb_separator'].' ', $brotkrumen);
	if (!empty($extra_krumen)) 
		$brotkrumen.= ' '.$zz_conf['breadcrumb_separator']
		.' '.implode(' '.$zz_conf['breadcrumb_separator'].' ', $extra_krumen);
	return $brotkrumen;
}

/** Gibt Array für Brotkrumennavigation zu aktueller Seite zurück
 * 
 * @param $seite_id(int) ID der aktuellen Seite in Datenbank (cms_seiten)
 * @param $pages(array) Array mit allen Seiten aus Datenbank (cms_seiten), indiziert nach seite_id
 * @return array kurztitel = Seitenkurztitel; kennung = Seitenkennung; mutter_seite_id = Seiten_ID der übergeordneten Seite
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 */
function cms_zeige_brotkrumen($seite_id, &$pages) {
	$krumen[] = array(
		'kurztitel' => $pages[$seite_id]['kurztitel'],
		'kennung' => $pages[$seite_id]['kennung'],
		'ober_seite_id' => $pages[$seite_id]['ober_seite_id']);
	if ($pages[$seite_id]['ober_seite_id'] 
		&& !empty($pages[$pages[$seite_id]['ober_seite_id']]))
		$krumen = array_merge($krumen, cms_zeige_brotkrumen($pages[$seite_id]['ober_seite_id'], $pages));
	return $krumen;
}	

?>