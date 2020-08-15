<?php
$fp = fopen("countries_codes_and_coordinates.csv", 'r');
while ( ($row = fgetcsv($fp)) != false ) {
	$code = $row['1'];
	$code3 = $row['2'];
	if ( strlen($code) != 2 || strlen($code3) != 3 )
		continue;
	echo "update countries set code3 = '$code3' where code = '$code';\n";
}
