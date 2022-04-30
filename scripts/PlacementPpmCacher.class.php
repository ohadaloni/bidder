<?php
/*------------------------------------------------------------*/
class PlacementPpmCacher extends Mcontroller {
	/*------------------------------------------------------------*/
	private $bidderUtils;
	private $memUtils;
	private $keyNames;
	private $logger;
	private $ttl;
	/*------------------------------------------------------------*/
	public function __construct() {
		parent::__construct();
		$topDir = dirname(__DIR__);
		$logsDir = "$topDir/logs/placementPpmCacher";
		$today = date("Y-m-d");
		$logFileName = "placementPpmCacher.$today.log";
		$logFile = "$logsDir/$logFileName";
		$this->logger = new Logger($logFile);
		$this->bidderUtils = new BidderUtils($logFile);
		$this->memUtils = new MemUtils($logFile);
		$this->keyNames = new KeyNames;
		$this->ttl = 300; // once a minute overwrite - so this is plenty
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	public function index() {
		$placements = $this->bidderUtils->placements();
		if ( ! $placements ) {
			$this->error("index: no placements");
			return;
		}
		foreach ( $placements as $placement ) {
			$placementId = $placement['placementId'];
			$ppm = $placement['ppm'];
			$mkey = $this->keyNames->placementPPM($placementId);
			$intPPM = $this->memUtils->memDouble2int($ppm);
			$this->Mmemcache->rawSet($mkey, $intPPM, $this->ttl);
			$this->log("setting $intPPM ($ppm) for $placementId", 0.4);
		}
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	private function error($msg, $r = 100) {
		$this->log("ERROR: $msg", $r);
	}
	/*------------------------------------------------------------*/
	private function log($msg, $r = 100) {
		if ( rand(1, 100 * 1000) > $r * 1000 )
				return;
		if ( $r == 100 )
				$str = $msg;
		else
				$str = "$r/100: $msg";
		echo "$str\n";
		$this->logger->log($str);
	}
	/*------------------------------------------------------------*/
}
/*------------------------------------------------------------*/
