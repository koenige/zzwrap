# 
# Tabellenstruktur für Tabelle `umleitungen`
# 

CREATE TABLE `umleitungen` (
  `umleitung_id` int(10) unsigned NOT NULL auto_increment,
  `alt` varchar(127) collate latin1_german2_ci NOT NULL default '',
  `neu` varchar(127) collate latin1_german2_ci NOT NULL default '',
  `code` smallint(5) unsigned NOT NULL default '301',
  `r_match` enum('ja','nein') collate latin1_german2_ci NOT NULL default 'nein',
  PRIMARY KEY  (`umleitung_id`),
  UNIQUE KEY `alt` (`alt`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german2_ci AUTO_INCREMENT=17 ;
