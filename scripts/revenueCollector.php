<?php
require_once("scriptsConfig.php");
require_once("RevenueCollector.class.php");

$revenueCollector = new RevenueCollector;
$revenueCollector->index();
