<?php 

// zzwrap (Project Zugzwang)
// (c) Gustaf Mossakowski, <gustaf@koenige.org> 2008-2009
// Default translations to german language


// login.inc.php
$text['You have been logged out.'] = 'Sie haben sich abgemeldet.';
$text['Please allow us to set a cookie!'] = 'Bitte erlauben Sie uns, einen Cookie zu setzen!';
$text['Sign in'] = 'Anmelden';
$text['Login'] = 'Login';
$text['Password or username incorrect. Please try again.'] = 'Passwort oder Benutzername falsch. Bitte versuchen Sie es erneut.';
$text['Username:'] = 'Benutzername:';
$text['Password:'] = 'Passwort:';
$text['To access the internal area, a registration is required. Please enter below your username and password.']
	= 'F&uuml;r den Zugang zum internen Bereich ist eine Anmeldung erforderlich. Bitte geben Sie unten den von uns erhaltenen Benutzernamen mit Pa&szlig;wort ein.';
$text['Please allow cookies after sending your login credentials. For security reasons, after %d minutes of inactivity you will be logged out automatically.'] 
	= 'Damit der Login funktioniert, müssen Sie nach &Uuml;bermittlung der Anmeldedaten einen Cookie akzeptieren. Nach %d Minuten Inaktivit&auml;t werden Sie aus Sicherheitsgr&uuml;nden automatisch wieder abgemeldet!';
$text['Password or username are empty. Please try again.'] = 'Pa%szlig;wort oder Benutzername leer. Bitte versuchen Sie es erneut.';

// errors.php 400
$text['Bad Request'] = 'Fehlerhafte Anfrage';
$text['Your browser (or proxy) sent a request that this server could not understand.'] 
	= 'Ihr Browser (oder Proxy) hat eine ung&uuml;ltige Anfrage gesendet, die vom Server nicht beantwortet werden kann.';

// errors.php 401
$text['Unauthorized'] = 'Anmeldung fehlgeschlagen';
$text["This server could not verify that you are authorized to access this URL. You either supplied the wrong credentials (e.g., bad password), or your browser doesn't understand how to supply the credentials required."]
	= 'Der Server konnte nicht pr&uuml;fen, ob Sie autorisiert sind, auf diese URL zuzugreifen. Entweder wurden falsche Daten (z. B. ein falsches Passwort) angegeben oder Ihr Browser versteht nicht, wie die geforderten Daten zu &uuml;bermitteln sind.';

// errors.php 402
$text['Payment Required'] = 'Bezahlung erforderlich';

// errors.php 403
$text['Forbidden'] = 'Kein Zugriff';
$text["You don't have permission to access the requested object. It is either read-protected or not readable by the server."]
	= 'Der Zugriff auf den angeforderten Inhalt ist nicht m&ouml;glich. Entweder kann es vom Server nicht gelesen werden oder es ist zugriffsgesch&uuml;tzt.';

// errors.php 404
$text['Not Found'] = 'Inhalt nicht gefunden';
$text['The requested URL was not found on this server.'] = 'Der von Ihnen gew&uuml;nschte Inhalt konnte nicht gefunden werden.';

// errors.php 405
$text['Method Not Allowed'] = 'Methode nicht erlaubt';
$text['The %s-method is not allowed for the requested URL.'] = 'Die %s-Methode ist f&uuml;r die angeforderte URL nicht erlaubt.';

// errors.php 406
$text['Not Acceptable'] = 'Nicht verarbeitbar';

// errors.php 407
$text['Proxy Authentication Required'] = 'Proxyanmeldung erforderlich';

// errors.php 409
$text['Conflict'] = 'Konflikt';

// errors.php 410
$text['Gone'] = 'Inhalt nicht mehr verf&uuml;gbar';
$text['The requested URL is no longer available on this server and there is no forwarding address.'] = 'Der angeforderte Inhalt existiert auf dem Server nicht mehr und wurde dauerhaft entfernt. Eine Weiterleitungsadresse ist nicht verf&uuml;gbar.';

// errors.php 411
$text['Length Required'] = 'Content-Length-Angabe fehlerhaft';
$text['A request with the %s-method requires a valid <code>Content-Length</code> header.'] = 'Die Anfrage kann nicht beantwortet werden. Bei Verwendung der %s-Methode mu&szlig; ein korrekter <code>Content-Length</code>-Header angegeben werden.';

// errors.php 412	
$text['Precondition Failed'] = 'Vorbedingung verfehlt';
$text['The precondition on the request for the URL failed positive evaluation.'] = 'Die f&uuml;r den Abruf der angeforderten URL notwendige Vorbedingung wurde nicht erf&uuml;llt.';

// errors.php 413
$text['Request Entity Too Large'] = '&Uuml;bergebene Daten zu gro&szlig;';
$text['The %s-method does not allow the data transmitted, or the data volume exceeds the capacity limit.'] = 'Die bei der Anfrage &uuml;bermittelten Daten sind f&uuml;r die %s-Methode nicht erlaubt oder die Datenmenge hat das Maximum &uuml;berschritten.';

// errors.php 414	
$text['Request-URI Too Long'] = '&Uuml;bergebene URI zu lang';
$text['The length of the requested URL exceeds the capacity limit for this server. The request cannot be processed.'] = 'Die bei der Anfrage  &uuml;bermittelte URI &uuml;berschreitet die maximale L&auml;nge. Die Anfrage kann nicht ausgef&uuml;hrt werden.';

// errors.php 415
$text['Unsupported Media Type']	= 'Nicht unterst&uuml;tztes Format';
$text['The server does not support the media type transmitted in the request.'] = 'Das bei der Anfrage &uuml;bermittelte Format (Media Type) wird vom Server nicht unterst&uuml;tzt.';

// errors.php 416
$text['Requested Range Not Satisfiable'] = 'Abgefragtes Intervall (<code>range</code>) nicht erfüllbar';

// errors.php 417
$text['Expectation Failed'] = 'Erwartung (<code>Expect</code>) fehlgeschlagen';

// errors.php 500	
$text['Internal Server Error'] = 'Serverfehler';
$text['The server encountered an internal error and was unable to complete your request.'] = 'Die Anfrage kann nicht beantwortet werden, da im Server ein interner Fehler aufgetreten ist.';

// errors.php 501
$text['Not Implemented'] = 'Abfrage nicht unterstützt';
$text['The server does not support the action requested by the browser.'] = 'Die vom Browser angeforderte Aktion wird vom Server nicht unterst&uuml;tzt.';

// errors.php 502
$text['Bad Gateway'] = 'Fehlerhaftes Gateway';
$text['The proxy server received an invalid response from an upstream server.'] = 'Der Proxy-Server erhielt eine fehlerhafte Antwort eines &uuml;bergeordneten Servers oder Proxies.';

// errors.php 503
$text['Service Unavailable'] = 'Dienst steht nicht zur Verf&uuml;gung.';
$text['The server is temporarily unable to service your request due to maintenance downtime or capacity problems. Please try again later.'] 
	= 'Der Server kann zur Zeit Ihre Anfrage nicht bearbeiten. Grund kann eine &Uuml;berlastung oder eine Wartung des Servers sein. Bitte versuchen Sie es sp&auml;ter noch einmal.';

// errors.php 504
$text['Gateway Timeout'] = 'Zeitlimit am Netzübergang (<code>gateway</code>) überschritten';

// errors.php 505
$text['HTTP Version Not Supported'] = 'HTTP-Version nicht unterstützt';

$text['Please try to find the content you were looking for from our <a href="%s">main page</a>.'] = 'Bitte versuchen Sie, den Inhalt, den Sie gesucht haben, &uuml;ber <a href="%s">unsere Hauptseite</a> zu finden.';

$text['User'] = 'Nutzer';
$text["The URL\n\n <%s> was requested from\n\n <%s>\n\n with the IP address %s\n (Browser %s)\n\n, but could not be found on the server"] = "Die Seite\n\n <%s> wurde von\n\n <%s> mit der IP-Adresse %s\n (Browser %s)\n\n angefordert, konnte auf dem Server aber nicht gefunden werden!";

?>