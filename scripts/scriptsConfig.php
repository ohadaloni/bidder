<?php
/*------------------------------------------------------------*/
error_reporting(E_ALL | E_NOTICE | E_STRICT );
/*------------------------------------------------------------*/
date_default_timezone_set("UTC");
/*------------------------------------------------------------*/
$topDir = dirname(__DIR__);
require_once("$topDir/src/sharedConfig.php");
/*------------------------------------------------------------*/
require_once(M_DIR."/mfiles.php");
require_once("$topDir/src/BidderUtils.class.php");
require_once("$topDir/src/MemUtils.class.php");
require_once("$topDir/src/KeyNames.class.php");
/*------------------------------------------------------------*/
