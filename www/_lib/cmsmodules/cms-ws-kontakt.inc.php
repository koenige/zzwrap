<?php

// Zugzwang Project
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2008
// Feedback-Formulare


function cms_kritikformular($variablen) {
	global $zz_conf;
	global $zz_setting;

	$fehlernachricht = false;
	$ws_text = cms_textbausteine('Formular Kritik');

	// 1. Formulardaten wurden gesendet?
	$felder = array('feedback', 'kontakt', 'absender');
	foreach ($felder as $feld) {
		$$feld = (!empty($_POST[$feld]) ? $_POST[$feld] : '');
	}

	if (!empty($_POST)) {	
	// 1.1. Formulardaten vollständig?: Mail an werkstatt-stadt.de versenden, Dank formulieren
		if ($absender AND $kontakt AND $feedback) {
			$subject = "Feedback IProS-Seiten";
			$body = "Feedback von:"
				."\n\nName: ".$absender
				."\n\nErreichbar unter: ".$kontakt
				."\n\nFeedback: ".$feedback;
			mail($zz_setting['mailto'], $subject, $body, $zz_setting['mail_headers']);
			$page['text'] = '<h2>'.$ws_text['Dank für Anregung'].'</h2>';
			$page['replace_db_text'] = true;
			return $page;

		} else {
	// 1.2. Formulardaten unvollständig: Formular vorausfüllen, weiter bei 2.
			$fehlernachricht = '<div class="fehler"><p>'.$ws_text['Angaben unvollständig'].'</p><p>'.$ws_text['Felder ergänzen'].'</p></div>';
		}
	}

	// 2. Formular ausgeben

	$page['text'] = '
<form method="POST" action="'.htmlentities($_SERVER['REQUEST_URI']).'">
<h2><label for="feedback">'.$ws_text['Was möchten Sie uns mitteilen'].'</label></h2>
'.$fehlernachricht.'
<textarea class="eingabefeld-schmal" name="feedback" rows="8" cols="40" id="feedback">'.$feedback.'</textarea>

<h2>'.$ws_text['Wie können wir Sie erreichen'].'</h2>

<p><label for="kontakt">'.$ws_text['E-Mail-Adresse oder Telefon'].'<br>
<input class="eingabefeld-schmal" type="text" name="kontakt" size="40" id="kontakt" value="'.$kontakt.'">
</label></p>

<p><label for="absender">'.$ws_text['Ihr Name'].'<br>
<input class="eingabefeld-schmal" type="text" name="absender" size="40" id="absender" value="'.$absender.'">
</label></p>

<p><input type="submit" name="submit" value="'.$ws_text['Formular abschicken'].'"></p>
</form>';

	return $page;		
}


?>