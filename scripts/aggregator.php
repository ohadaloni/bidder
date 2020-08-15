<?php
require_once("scriptsConfig.php");
require_once("Aggregator.class.php");

$aggregator = new Aggregator;
$aggregator->index();
