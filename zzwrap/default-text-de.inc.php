<?php 

/**
 * zzwrap
 * Default translations to german language
 *
 * Part of »Zugzwang Project«
 * http://www.zugzwang.org/projects/zzwrap
 *
 * @author Gustaf Mossakowski <gustaf@koenige.org>
 * @copyright Copyright © 2008-2009 Gustaf Mossakowski
 * @license http://opensource.org/licenses/lgpl-3.0.html LGPL-3.0
 */


// auth.inc.php
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
	= 'Damit der Login funktioniert, m&uuml;ssen Sie nach &Uuml;bermittlung der Anmeldedaten einen Cookie akzeptieren. Nach %d Minuten Inaktivit&auml;t werden Sie aus Sicherheitsgr&uuml;nden automatisch wieder abgemeldet!';
$text['Password or username are empty. Please try again.'] = 'Pa&szlig;wort oder Benutzername leer. Bitte versuchen Sie es erneut.';
$text['Temporarily, a login is not possible.'] = 'Vor&uuml;bergehend ist keine Anmeldung m&ouml;glich.';

// HTTP Status Code 300
$text['Multiple Choices'] = 'Mehrfachauswahl';

// HTTP Status Code 301
$text['Moved Permanently'] = 'Dauerhaft verschoben';

// HTTP Status Code 302
$text['Found'] = 'Umleitung (unbestimmt)';

// HTTP Status Code 303
$text['See Other'] = 'Umleitung';

// HTTP Status Code 304
$text['Not Modified'] = 'Keine Veränderung';

// HTTP Status Code 305
$text['Use Proxy'] = 'Proxy benutzen';

// HTTP Status Code 307
$text['Temporary Redirect'] = 'Vorübergehende Umleitung';

// HTTP Status Code 400
$text['Bad Request'] = 'Fehlerhafte Anfrage';
$text['Your browser (or proxy) sent a request that this server could not understand.'] 
	= 'Ihr Browser (oder Proxy) hat eine ung&uuml;ltige Anfrage gesendet, die vom Server nicht beantwortet werden kann.';

// HTTP Status Code 401
$text['Unauthorized'] = 'Anmeldung fehlgeschlagen';
$text["This server could not verify that you are authorized to access this URL. You either supplied the wrong credentials (e.g., bad password), or your browser doesn't understand how to supply the credentials required."]
	= 'Der Server konnte nicht pr&uuml;fen, ob Sie autorisiert sind, auf diese URL zuzugreifen. Entweder wurden falsche Daten (z. B. ein falsches Passwort) angegeben oder Ihr Browser versteht nicht, wie die geforderten Daten zu &uuml;bermitteln sind.';

// HTTP Status Code 402
$text['Payment Required'] = 'Bezahlung erforderlich';

// HTTP Status Code 403
$text['Forbidden'] = 'Kein Zugriff';
$text["You don't have permission to access the requested object. It is either read-protected or not readable by the server."]
	= 'Der Zugriff auf den angeforderten Inhalt ist nicht m&ouml;glich. Entweder kann er vom Server nicht gelesen werden oder er ist zugriffsgesch&uuml;tzt.';

// HTTP Status Code 404
$text['Not Found'] = 'Inhalt nicht gefunden';
$text['The requested URL was not found on this server.'] = 'Der von Ihnen gew&uuml;nschte Inhalt konnte nicht gefunden werden.';

// HTTP Status Code 405
$text['Method Not Allowed'] = 'Methode nicht erlaubt';
$text['The %s-method is not allowed for the requested URL.'] = 'Die %s-Methode ist f&uuml;r die angeforderte URL nicht erlaubt.';

// HTTP Status Code 406
$text['Not Acceptable'] = 'Nicht verarbeitbar';

// HTTP Status Code 407
$text['Proxy Authentication Required'] = 'Proxyanmeldung erforderlich';

// HTTP Status Code 409
$text['Conflict'] = 'Konflikt';

// HTTP Status Code 410
$text['Gone'] = 'Inhalt nicht mehr verf&uuml;gbar';
$text['The requested URL is no longer available on this server and there is no forwarding address.'] = 'Der angeforderte Inhalt existiert auf dem Server nicht mehr und wurde dauerhaft entfernt. Eine Weiterleitungsadresse ist nicht verf&uuml;gbar.';

// HTTP Status Code 411
$text['Length Required'] = 'Content-Length-Angabe fehlerhaft';
$text['A request with the %s-method requires a valid <code>Content-Length</code> header.'] = 'Die Anfrage kann nicht beantwortet werden. Bei Verwendung der %s-Methode mu&szlig; ein korrekter <code>Content-Length</code>-Header angegeben werden.';

// HTTP Status Code 412	
$text['Precondition Failed'] = 'Vorbedingung verfehlt';
$text['The precondition on the request for the URL failed positive evaluation.'] = 'Die f&uuml;r den Abruf der angeforderten URL notwendige Vorbedingung wurde nicht erf&uuml;llt.';

// HTTP Status Code 413
$text['Request Entity Too Large'] = '&Uuml;bergebene Daten zu gro&szlig;';
$text['The %s-method does not allow the data transmitted, or the data volume exceeds the capacity limit.'] = 'Die bei der Anfrage &uuml;bermittelten Daten sind f&uuml;r die %s-Methode nicht erlaubt oder die Datenmenge hat das Maximum &uuml;berschritten.';

// HTTP Status Code 414	
$text['Request-URI Too Long'] = '&Uuml;bergebene URI zu lang';
$text['The length of the requested URL exceeds the capacity limit for this server. The request cannot be processed.'] = 'Die bei der Anfrage  &uuml;bermittelte URI &uuml;berschreitet die maximale L&auml;nge. Die Anfrage kann nicht ausgef&uuml;hrt werden.';

// HTTP Status Code 415
$text['Unsupported Media Type']	= 'Nicht unterst&uuml;tztes Format';
$text['The server does not support the media type transmitted in the request.'] = 'Das bei der Anfrage &uuml;bermittelte Format (Media Type) wird vom Server nicht unterst&uuml;tzt.';

// HTTP Status Code 416
$text['Requested Range Not Satisfiable'] = 'Abgefragtes Intervall (<code>range</code>) nicht erf&uuml;llbar';

// HTTP Status Code 417
$text['Expectation Failed'] = 'Erwartung (<code>Expect</code>) fehlgeschlagen';

// HTTP Status Code 500	
$text['Internal Server Error'] = 'Serverfehler';
$text['The server encountered an internal error and was unable to complete your request.'] = 'Die Anfrage kann nicht beantwortet werden, da im Server ein interner Fehler aufgetreten ist.';

// HTTP Status Code 501
$text['Not Implemented'] = 'Abfrage nicht unterst&uuml;tzt';
$text['The server does not support the action requested by the browser (%s).'] = 'Die vom Browser angeforderte Aktion wird vom Server nicht unterst&uuml;tzt (%s).';

// HTTP Status Code 502
$text['Bad Gateway'] = 'Fehlerhaftes Gateway';
$text['The proxy server received an invalid response from an upstream server.'] = 'Der Proxy-Server erhielt eine fehlerhafte Antwort eines &uuml;bergeordneten Servers oder Proxies.';

// HTTP Status Code 503
$text['Service Unavailable'] = 'Dienst steht nicht zur Verf&uuml;gung.';
$text['The server is temporarily unable to service your request due to maintenance downtime or capacity problems. Please try again later.'] 
	= 'Der Server kann zur Zeit Ihre Anfrage nicht bearbeiten. Grund kann eine &Uuml;berlastung oder eine Wartung des Servers sein. Bitte versuchen Sie es sp&auml;ter noch einmal.';

// HTTP Status Code 504
$text['Gateway Timeout'] = 'Zeitlimit am Netz&uuml;bergang (<code>gateway</code>) &uuml;berschritten';

// HTTP Status Code 505
$text['HTTP Version Not Supported'] = 'HTTP-Version nicht unterst&uuml;tzt';

$text['Please try to find the content you were looking for from our <a href="%s">main page</a>.'] = 'Bitte versuchen Sie, den Inhalt, den Sie gesucht haben, &uuml;ber <a href="%s">unsere Hauptseite</a> zu finden.';

$text['User'] = 'Nutzer';
$text["The URL\n\n <%s> was requested from\n\n <%s>\n\n with the IP address %s\n (Browser %s)\n\n, but could not be found on the server"] = "Die Seite\n\n <%s> wurde von\n\n <%s> mit der IP-Adresse %s\n (Browser %s)\n\n angefordert, konnte auf dem Server aber nicht gefunden werden!";

?>