<?php
/*------------------------------------------------------------*/
// run by cron once a minute
/*------------------------------------------------------------*/
class MinuteSaver extends Mcontroller {
	/*------------------------------------------------------------*/
	private $bidderUtils;
	private $memUtils;
	private $keyNames;
	/*------------------------------------------------------------*/
	public function __construct() {
		parent::__construct();
		$topDir = dirname(__DIR__);
		$logsDir = "$topDir/logs/minuteSaver";
		$today = date("Y-m-d");
		$logFileName = "minuteSaver.$today.log";
		$logFile = "$logsDir/$logFileName";
		$this->logger = new Logger($logFile);
		$this->bidderUtils = new BidderUtils($logFile);
		$this->memUtils = new MemUtils($logFile);
		$this->keyNames = new KeyNames;
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	public function index() {
		$this->bidder();
		$this->campaigns();
		$this->placements();
		$this->exchanges();
	}
	/*------------------------------------------------------------*/
	public function bidder() {
		$time = time() - 60;
		$date = date("Y-m-d", $time);
		$hour = date("H", $time);
		$minute = date("i", $time);
		$cntMetrics = $this->bidderUtils->cntMetrics();
		$floatMetrics = $this->bidderUtils->floatMetrics();
		$row = array(
			'date' => $date,
			'hour' => $hour,
			'minute' => $minute,
		);
		$allZeros = true;
		foreach ( $cntMetrics as $cntMetric ) {
			$mkey = $this->keyNames->bidderCounter($cntMetric, "thisMinute", -1);
			$value = $this->Mmemcache->rawGet($mkey);
			if ( $value ) {
				if ( in_array($cntMetric, $floatMetrics) )
					$value = $this->memUtils->memInt2double($value);
				$row[$cntMetric] = $value;
				$allZeros = false;
			}
		}
		if ( $allZeros ) {
			$this->log("bidder: All Zeros: ignoring", 100);
			return;
		}
		$json = json_encode($row);
		$conds = "date = '$date' and hour = $hour and minute = $minute";
		$sql = "select * from cntMinute where $conds";
		$dbRow = $this->Mmodel->getRow($sql);
		if ( $dbRow ) {
			$updated = $this->Mmodel->dbUpdate("cntMinute", $dbRow['id'], $row);
			// $updated should always be zero
			// as this updates what happened in the previous minute
			if ( $updated )
				$this->log("bidder: Eh? updated $json", 100);
		} else {
			$newId = $this->Mmodel->dbInsert("cntMinute", $row);
			if ( $newId ) {
				$this->log("bidder: new row $json", 100);
			} else {
				$error = $this->Mmodel->lastError();
				$this->error("Failed to save where $conds");
			}
		}
	}
	/*------------------------------------------------------------*/
	public function campaigns() {
		$orderBy = "order by id";
		$ago = date("Y-m-d H:i:s", time() - 300);
		$conds = "onSwitch = 1 or lastUpdated > '$ago'";
		$sql = "select id from campaigns where $conds $orderBy";
		$campaignIds = $this->Mmodel->getStrings($sql);
		foreach ( $campaignIds as $campaignId )
			$this->campaign($campaignId);
	}
	/*------------------------------*/
	public function campaign($campaignId) {
		$time = time() - 60;
		$date = date("Y-m-d", $time);
		$hour = date("H", $time);
		$minute = date("i", $time);
		$cntMetrics = $this->bidderUtils->cntMetrics(false);
		$floatMetrics = $this->bidderUtils->floatMetrics();
		$row = array(
			'campaignId' => $campaignId,
			'date' => $date,
			'hour' => $hour,
			'minute' => $minute,
		);
		$allZeros = true;
		foreach ( $cntMetrics as $cntMetric ) {
			$mkey = $this->keyNames->campaignCounter($cntMetric, "thisMinute", $campaignId, -1);
			$value = $this->Mmemcache->rawGet($mkey);
			if ( $value ) {
				$this->log("campaign:$campaignId: $mkey: $value", 1);
				if ( in_array($cntMetric, $floatMetrics) )
					$value = $this->memUtils->memInt2double($value);
				$row[$cntMetric] = $value;
				$allZeros = false;
			}
		}
		if ( $allZeros ) {
			$this->log("campaign:$campaignId: All Zeros: ignoring", 1);
			return;
		}
		$conds = "campaignId = $campaignId and date = '$date' and hour = $hour and minute = $minute";
		$sql = "select * from cCntMinute where $conds";
		$dbRow = $this->Mmodel->getRow($sql);
		if ( $dbRow ) {
			$updated = $this->Mmodel->dbUpdate("cCntMinute", $dbRow['id'], $row);
			$this->log("campaign:$campaignId: updated where $conds", 1);
		} else {
			$newId = $this->Mmodel->dbInsert("cCntMinute", $row);
			if ( $newId ) {
				$this->log("campaign:$campaignId: new row for $conds", 1);
			} else {
				$error = $this->Mmodel->lastError();
				$this->error("campaign:$campaignId: Failed to save where $conds", 1);
			}
		}
	}
	/*------------------------------------------------------------*/
	public function placements() {
		$placementIds = array();
		$qName = $this->keyNames->placementIdsQname();
		$qLength = $this->Mmemcache->msgQlength($qName);
		for($i=0;$i<$qLength;$i++) {
			$placementId = $this->Mmemcache->msgQnext($qName);
			if ( $placementId ) {
				$placementIds[] = $placementId;
				$this->log("placements:$placementId", 1);
			} else {
				$this->log("placements: null placement id", 1);
			}
		}
		$placementIds = array_unique($placementIds);
		foreach ( $placementIds as $placementId )
			$this->placement($placementId);
	}
	/*------------------------------*/
	public function placement($placementId) {
		$this->log("placement:$placementId", 1);
		$time = time() - 60;
		$date = date("Y-m-d", $time);
		$hour = date("H", $time);
		$minute = date("i", $time);
		$cntMetrics = $this->bidderUtils->cntMetrics();
		$floatMetrics = $this->bidderUtils->floatMetrics();
		$row = array(
			'placementId' => $placementId,
			'date' => $date,
			'hour' => $hour,
			'minute' => $minute,
		);
		$allZeros = true;
		foreach ( $cntMetrics as $cntMetric ) {
			$mkey = $this->keyNames->placementCounter($cntMetric, "thisMinute", $placementId, -1);
			$value = $this->Mmemcache->rawGet($mkey);
			if ( $value != 0 )
				$allZeros = false;
			$this->log("placement:$placementId: $mkey: $value", 1);
			if ( in_array($cntMetric, $floatMetrics) )
				$value = $this->memUtils->memInt2double($value);
			$row[$cntMetric] = $value;
		}
		if ( $allZeros ) {
			$this->log("placement:$placementId: All Zeros: ignoring", 1);
			return;
		}
		$conds = "placementId = '$placementId' and date = '$date' and hour = $hour and minute = $minute";
		$sql = "select * from plCntMinute where $conds";
		$dbRow = $this->Mmodel->getRow($sql);
		if ( $dbRow ) {
			$updated = $this->Mmodel->dbUpdate("plCntMinute", $dbRow['id'], $row);
			$this->log("placement:$placementId: updated where $conds", 1);
		} else {
			$newId = $this->Mmodel->dbInsert("plCntMinute", $row);
			if ( $newId ) {
				$json = json_encode($row);
				$this->log("placement:$placementId: new row (id=$newId) for $conds ($json)", 1);
			} else {
				$error = $this->Mmodel->lastError();
				$this->error("placement:$placementId: Failed to save where $conds ($error)");
			}
		}
	}
	/*------------------------------------------------------------*/
	public function exchanges() {
		$exchanges = $this->bidderUtils->exchanges();
		foreach ( $exchanges as $exchange )
			$this->exchange($exchange['id']);
	}
	/*------------------------------*/
	public function exchange($exchangeId) {
		$this->log("exchange:$exchangeId", 1);
		$time = time() - 60;
		$date = date("Y-m-d", $time);
		$hour = date("H", $time);
		$minute = date("i", $time);
		$cntMetrics = $this->bidderUtils->cntMetrics();
		$floatMetrics = $this->bidderUtils->floatMetrics();
		$row = array(
			'exchangeId' => $exchangeId,
			'date' => $date,
			'hour' => $hour,
			'minute' => $minute,
		);
		$allZeros = true;
		foreach ( $cntMetrics as $cntMetric ) {
			$mkey = $this->keyNames->exchangeCounter($cntMetric, "thisMinute", $exchangeId, -1);
			$value = $this->Mmemcache->rawGet($mkey);
			if ( $value != 0 )
				$allZeros = false;
			$this->log("exchange:$exchangeId: $mkey: $value", 1);
			if ( in_array($cntMetric, $floatMetrics) )
				$value = $this->memUtils->memInt2double($value);
			$row[$cntMetric] = $value;
		}
		if ( $allZeros ) {
			$this->log("exchange:$exchangeId: All Zeros: ignoring", 1);
			return;
		}
		$conds = "exchangeId = $exchangeId and date = '$date' and hour = $hour and minute = $minute";
		$sql = "select * from exCntMinute where $conds";
		$dbRow = $this->Mmodel->getRow($sql);
		if ( $dbRow ) {
			$updated = $this->Mmodel->dbUpdate("exCntMinute", $dbRow['id'], $row);
			$this->log("exchange:$exchangeId: updated where $conds", 1);
		} else {
			$newId = $this->Mmodel->dbInsert("exCntMinute", $row);
			if ( $newId ) {
				$this->log("exchange:$exchangeId: new row for $conds", 1);
			} else {
				$error = $this->Mmodel->lastError();
				$this->error("exchange:$exchangeId: Failed to save where $conds");
			}
		}
	}
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
