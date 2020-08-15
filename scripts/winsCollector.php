<?php
require_once("scriptsConfig.php");
require_once("WinsCollector.class.php");

$winsCollector = new WinsCollector;
$winsCollector->index();
