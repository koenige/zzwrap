<?php
if (empty($oberseite_url)) $oberseite_url = '';

$lists = array('menu');
foreach ($lists as $list) {
	echo '<ul id="'.$list.'">'."\n";
	$i = 0;
	foreach ($$list as $m_url => $entry) {
		echo '<li'
			.($i ? ($i == count($$list)-1 ? ' class="last-child"': '') : ' class="first-child"').'>'
			.($m_url != $seite.'/' ? '<a href="'.$m_url.'"'
			.(($m_url == $oberseite_url.'/' && !empty($subpages)) ? ' class="aktiv"': '')
			.'>' : '<strong>')
			.$entry
			.($m_url != $seite.'/' ? '</a>' : '</strong>');
		if ($m_url == $oberseite_url.'/' && !empty($subpages)) {
			echo '<ul id="submenu">'."\n";
			$j = 0;
			foreach($subpages as $subpage) {
				if (strstr($subpage['titel'], '–'))
					$subpage['titel'] = substr($subpage['titel'], strrpos($subpage['titel'], '–') +3);
				if ($subpage['reihenfolge'] == 100) $subpage['kennung'] = substr($subpage['kennung'], strrpos($subpage['kennung'], '/'));
				echo '<li'.($j == (count($subpages)-1) ? ' class="last-child"': '')
					.($subpage['reihenfolge'] == 100 ? ' id="extramenu"' : '')
					.'>'
					.($subpage['kennung'].'/' != $_SERVER['REQUEST_URI'] ? '<a href="'.$subpage['kennung'].'/"'
					.(($subpage['kennung'] == $seite && !empty($projekte)) ? ' class="aktiv"': '')
					.'>' : '<strong>')
					.$subpage['titel']
					.($subpage['kennung'].'/' != $_SERVER['REQUEST_URI'] ? '</a>' : '</strong>');
				if ($subpage['kennung'] == $seite) {
					if (!empty($projekte)) {
						echo '<ul class="projekte'.count($projekte).'">';
						$i = 1;
						foreach ($projekte as $einzelprojekt) {
							echo '<li>'.($einzelprojekt['kennung'] == $projekt ? '<strong>' : '<a href="'.$seite.'/'.$einzelprojekt['kennung'].'/" 
								title="'.$einzelprojekt['projekt'].'">').
								$i.($einzelprojekt['kennung'] == $projekt ? '</strong>' : '</a>').'</li>'."\n";
							$i++;
						}
						echo '</ul>'."\n";
					}
				}	
				echo '</li>'."\n";
				$j++;
			}
			echo '</ul>'."\n";
		}
		echo '</li>'."\n";
		$i++;
	}

	echo '</ul>';
}

?>

<?php 

if (!empty($_SESSION['logged_in']))
	echo '<div id="logout"><a href="/login/?logout">Logout</a></div>';

echo '<div id="menufuss"></div><div id="fuss"></div>';

?></div></td></tr></table>
</body>

</html>