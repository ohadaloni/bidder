<?php
/*------------------------------------------------------------*/
if ( strstr(@$_REQUEST['PATH_INFO'], 'healthcheck') ) {
	echo "I'm OK\n";
	exit;
}
/*------------------------------------------------------------*/
require_once("bidderConfig.php");
require_once(M_DIR."/mfiles.php");
require_once("bidderFiles.php");
/*------------------------------------------------------------*/
global $Mview;
global $Mmodel;
$Mview = new Mview;
$Mmodel = new Mmodel;
/*------------------------------------------------------------*/
$bidder = new Bidder;
$bidder->index();
/*------------------------------------------------------------*/
/*------------------------------------------------------------*/
