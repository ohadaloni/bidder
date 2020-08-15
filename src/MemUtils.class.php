<?php
class MemUtils {
	/*------------------------------------------------------------*/
	private $Mmemcache;
	private $keyNames;
	/*------------------------------------------------------------*/
	public function __construct($logFile = null) {
		$this->Mmemcache = new Mmemcache;
		$this->keyNames = new KeyNames;
		if ( $logFile )
			$this->logger = new Logger($logFile);
	}
	/*------------------------------------------------------------*/
	public function bidRequest($bidRequestId) {
		$mkey = $this->keyNames->bidRequest($bidRequestId);
		$bidRequest = $this->Mmemcache->get($mkey);
		return($bidRequest);
	}
	/*------------------------------------------------------------*/
	public function bid($bidId) {
		$mkey = $this->keyNames->bid($bidId);
		$bid = $this->Mmemcache->get($mkey);
		return($bid);
	}
	/*------------------------------------------------------------*/
	public function lastRequest() {
		$mkey = $this->keyNames->lastRequestId();
		$lastRequestId = $this->Mmemcache->get($mkey);
		if ( ! $lastRequestId )
			return(null);
		$request = $this->bidRequest($lastRequestId);
		return($request);
	}
	/*------------------------------------------------------------*/
	public function placementPPM($placementId) {
		$mkey = $this->keyNames->placementPPM($placementId);
		$intPPM = $this->Mmemcache->rawGet($mkey);
		if ( $intPPM === false )
			return(null);
		$placementPPM = $this->memInt2double($intPPM);
		return($placementPPM);
	}
	/*------------------------------------------------------------*/
	// money numbers are stored with their real (1/1000) value
	// so very small floats
	/*------------------------------*/
	private $memFactor = 1000000;
	/*------------------------------*/
	public function memDouble2int($d) {
		return(round($d * $this->memFactor));
	}
	/*------------------------------*/
	public function memInt2double($i) {
		return($i / $this->memFactor);
	}
	/*------------------------------------------------------------*/
	public function lastCampaignBid($campaignId) {
		$mkey = $this->keyNames->lastCampaignBidId($campaignId);
		$lastCampaignBidId = $this->Mmemcache->get($mkey);
		if ( ! $lastCampaignBidId )
			return(null);
		$bid = $this->bid($lastCampaignBidId);
		return($bid);
	}
	/*------------------------------------------------------------*/
	public function lastBid() {
		$mkey = $this->keyNames->lastBidId();
		$lastBidId = $this->Mmemcache->get($mkey);
		if ( ! $lastBidId )
			return(null);
		$bid = $this->bid($lastBidId);
		return($bid);
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	private function error($msg, $r = 100) {
		$this->log("ERROR: $msg", $r);
	}
	/*------------------------------------------------------------*/
	private function log($msg, $r = 100 ) {
		if ( rand(1, 100 * 1000) > $r * 1000 )
			return;
		$now = date("Y-m-d G:i:s");
		if ( $r == 100 )
			$str = "$now: MemUtils: $msg";
		else
			$str = "$now: MemUtils: $r/100: $msg";
		if ( $this->logger )
			$this->logger->log($str, false);
		else
			error_log($str);
	}
	/*------------------------------------------------------------*/
}
/*------------------------------------------------------------*/
