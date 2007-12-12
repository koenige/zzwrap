<?php 

### menues einlesen

// Layouts
// 1 = Linien, 2 = Metall
$layout = 2;
if (substr($_SERVER['SERVER_NAME'], 0, 4) != 'www2') $layout = 1;

require $_SERVER['DOCUMENT_ROOT'].'/www/_scripts/zzform/local/config.inc.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/www/_scripts/zzform/local/db.inc.php';

if (empty($projekt)) $projekt = '';
if (empty($seite)) $seite = '';

$sql = 'SELECT project_menus.* 
	, project_seiten.kennung
	FROM project_menus
	LEFT JOIN project_seiten ON project_menus.seite_id = project_seiten.seite_id
	ORDER BY reihenfolge';
$result = mysql_query($sql);
if ($result) if (mysql_num_rows($result))
	while ($line = mysql_fetch_assoc($result))
		$menus[] = $line;

if (!empty($menus)) foreach ($menus as $menu_entry)
	eval('$menu[str_replace("//", "/", $menu_entry[\'kennung\']."/")] = $menu_entry[\'titel\'];');

### seitenaufbau

header('Content-Type: text/html;charset=utf-8');

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
        "http://www.w3.org/TR/1999/REC-html401-19991224/loose.dtd">
<html lang="de">
<head>
<?php if (!empty($page['meta_description'])) { ?>
	<meta name="description" content="<?php echo $page['meta_description']; ?>">
<?php } ?>
	<link rel="stylesheet" href="/_layout/example.css" type="text/css" media="all" title="Linien">
	<!--[if lt IE 6]><link rel="stylesheet" href="/_layout/example-ie5.css" type="text/css" media="all"><![endif]-->
	<link rel="stylesheet" href="/_layout/example-print.css" type="text/css" media="print">
	<link rel="icon" href="/favicon.ico" type="image/x-ico">
	<title><?php echo $page['pagetitle']; ?></title>
</head>
<body>
<table id="middle"><tr><td>
<div id="canvas">
<p id="logo"><?php if ($_SERVER['REQUEST_URI'] != "/") echo '<a href="/">'?>
<img src="/_layout/<?php 
		if ($layout == 2) echo '2/';
	?>logo.gif" alt="example"><?php if ($_SERVER['REQUEST_URI'] != "/") echo '</a>'?></p>
<div id="topmenu">
