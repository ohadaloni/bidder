<?php
/*------------------------------------------------------------*/
/*
 * run by cron once a minute
 * 
 * at the beginning of the hour, the previous hour
 * is first finalized
 * then day uses the hour counter for the same
 * dashBoard number are also cached
 * including the allTime (cache only).
 * ttl is short, as this refreshes once a minute anyway.
 */
/*------------------------------------------------------------*/
class Aggregator extends Mcontroller {
	/*------------------------------------------------------------*/
	private $bidderUtils;
	private $memUtils;
	private $keyNames;
	private $cntMetrics;
	private $cCntMetrics;
	private $plCntMetrics;
	private $exCntMetrics;
	private $logger;
	/*------------------------------------------------------------*/
	public function __construct() {
		parent::__construct();
		$topDir = dirname(__DIR__);
		$logsDir = "$topDir/logs/aggregator";
		$today = date("Y-m-d");
		$logFileName = "aggregator.$today.log";
		$logFile = "$logsDir/$logFileName";
		$this->logger = new Logger($logFile);
		$this->bidderUtils = new BidderUtils($logFile);
		$this->memUtils = new MemUtils($logFile);
		$this->keyNames = new KeyNames;
		$this->exCntMetrics = $this->plCntMetrics = $this->cntMetrics = $this->bidderUtils->cntMetrics();
		$this->cCntMetrics = $this->bidderUtils->cntMetrics(false);
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
	private function bidder() {
		$thisMinute = date("i");
		$thisHour = date("G");
		$thisDay = date("j");
		$thisMonth = date("n");

		if ( $thisMinute == 0 )
			$this->cntHour(true);
		$this->cntHour();

		if ( $thisHour == 0 && $thisMinute == 0 )
			$this->cntDay(true);
		$this->cntDay();

		if ( $thisDay == 1 && $thisHour == 0 && $thisMinute == 0 )
			$this->cntMonth(true);
		$this->cntMonth();

		if ( $thisMonth == 1 && $thisDay == 1 && $thisHour == 0 && $thisMinute == 0 )
			$this->cntYear(true);
		$this->cntYear();

		$this->cntAllTime();
	}
	/*------------------------------------------------------------*/
	private function cntHour($prev = false) {
		$time = time();
		if ( $prev )
			$time -= 3600;
		$date = date("Y-m-d", $time);
		$hour = date("H", $time);
		$conds = "date = '$date' and hour = $hour";
		$row = array();
		foreach ( $this->cntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from cntMinute where $conds";
			$row[$cntMetric] = $this->Mmodel->getString($sql);
		}

		if ( $this->isEmpty($row) ) {
			$this->log("cntHour: empty row ignored", 100);
			return;
		}
		$sql = "select * from cntHour where $conds";
		$dbHourRow = $this->Mmodel->getRow($sql);
		$json = json_encode($row);
		$dbJson = json_encode($dbHourRow);
		$this->log("cntHour: prev='$prev', row=$json, dbRow=$dbJson");
		if ( $dbHourRow ) {
			$this->Mmodel->dbUpdate("cntHour", $dbHourRow['id'], $row);
		} else {
			$row['date'] = $date;
			$row['hour'] = $hour;
			$this->Mmodel->dbInsert("cntHour", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->cntMetrics as $cntMetric ) {
				$mkey = $this->keyNames->bidderCounter($cntMetric, "thisHour");
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function cntDay($prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600;
		$date = date("Y-m-d", $time);
		$conds = "date = '$date'";
		$row = array();

		foreach ( $this->cntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from cntHour where $conds";
			$row[$cntMetric] = $this->Mmodel->getString($sql);
		}
		if ( $this->isEmpty($row) ) {
			$this->log("cntDay: empty row ignored", 100);
			return;
		}

		$sql = "select * from cntDay where $conds";
		$dbDayRow = $this->Mmodel->getRow($sql);
		if ( $dbDayRow ) {
			$this->Mmodel->dbUpdate("cntDay", $dbDayRow['id'], $row);
		} else {
			$row['date'] = $date;
			$this->Mmodel->dbInsert("cntDay", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->cntMetrics as $cntMetric ) {
				$mkey = $this->keyNames->bidderCounter($cntMetric, "today");
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function cntMonth($prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600; // yesterday is the previous month in this case
		$month = date("Y-m", $time);
		$conds = "left(date, 7) = '$month'";
		$row = array();

		foreach ( $this->cntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from cntDay where $conds";
			$row[$cntMetric] = $this->Mmodel->getString($sql);
		}
		if ( $this->isEmpty($row) ) {
			$this->log("cntMonth: empty row ignored", 100);
			return;
		}

		$sql = "select * from cntMonth where month = '$month'";
		$dbMonthRow = $this->Mmodel->getRow($sql);
		if ( $dbMonthRow ) {
			$this->Mmodel->dbUpdate("cntMonth", $dbMonthRow['id'], $row);
		} else {
			$row['month'] = $month;
			$this->Mmodel->dbInsert("cntMonth", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->cntMetrics as $cntMetric ) {
				$mkey = $this->keyNames->bidderCounter($cntMetric, "thisMonth");
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function cntYear($prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600; // yesterday is the previous year in this case
		$year = date("Y", $time);
		$conds = "left(month, 4) = '$year'";
		$row = array();

		foreach ( $this->cntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from cntMonth where $conds";
			$row[$cntMetric] = $this->Mmodel->getString($sql);
		}
		if ( $this->isEmpty($row) ) {
			$this->log("cntYear: empty row ignored", 100);
			return;
		}

		$sql = "select * from cntYear where year = '$year'";
		$dbYearRow = $this->Mmodel->getRow($sql);
		if ( $dbYearRow ) {
			$this->Mmodel->dbUpdate("cntYear", $dbYearRow['id'], $row);
		} else {
			$row['year'] = $year;
			$this->Mmodel->dbInsert("cntYear", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->cntMetrics as $cntMetric ) {
				$mkey = $this->keyNames->bidderCounter($cntMetric, "thisYear");
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function cntAllTime() {
		foreach ( $this->cntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from cntYear";
			$sum = $this->Mmodel->getString($sql);
			$mkey = $this->keyNames->bidderCounter($cntMetric, "allTime");
			$this->Mmemcache->rawSet($mkey, $sum, 90);
		}
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	private function campaigns() {
		$orderBy = "order by id";
		$ago = date("Y-m-d H:i:s", time() - 300);
		$conds = "onSwitch = 1 or lastUpdated > '$ago'";
		$sql = "select id from campaigns where $conds $orderBy";
		$campaignIds = $this->Mmodel->getStrings($sql);
		foreach ( $campaignIds as $campaignId )
			$this->campaign($campaignId);
	}
	/*------------------------------------------------------------*/
	private function campaign($campaignId) {
		$thisMinute = date("i");
		$thisHour = date("G");
		$thisDay = date("j");
		$thisMonth = date("n");

		if ( $thisMinute == 0 )
			$this->cCntHour($campaignId, true);
		$this->cCntHour($campaignId);

		if ( $thisHour == 0 && $thisMinute == 0 )
			$this->cCntDay($campaignId, true);
		$this->cCntDay($campaignId);

		if ( $thisDay == 1 && $thisHour == 0 && $thisMinute == 0 )
			$this->cCntMonth($campaignId, true);
		$this->cCntMonth($campaignId);

		if ( $thisMonth == 1 && $thisDay == 1 && $thisHour == 0 && $thisMinute == 0 )
			$this->cCntYear($campaignId, true);
		$this->cCntYear($campaignId);

		$this->cCntAllTime($campaignId);
	}
	/*------------------------------------------------------------*/
	private function cCntHour($campaignId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 3600;
		$date = date("Y-m-d", $time);
		$hour = date("H", $time);
		$conds = "campaignId = $campaignId and date = '$date' and hour = $hour";
		$row = array();
		$row['campaignId'] = $campaignId;
		foreach ( $this->cCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from cCntMinute where $conds";
			$cnt = $this->Mmodel->getString($sql);
			if ( $cnt )
				$row[$cntMetric] = $cnt;
		}
		if ( $this->isEmpty($row) ) {
			$this->log("cCntHour: empty row ignored", 100);
			return;
		}
		$sql = "select * from cCntHour where $conds";
		$dbHourRow = $this->Mmodel->getRow($sql);
		if ( $dbHourRow ) {
			$this->Mmodel->dbUpdate("cCntHour", $dbHourRow['id'], $row);
		} else {
			$row['date'] = $date;
			$row['hour'] = $hour;
			$this->Mmodel->dbInsert("cCntHour", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->cCntMetrics as $cntMetric ) {
				if ( ! @$row[$cntMetric] )
					continue;
				$mkey = $this->keyNames->campaignCounter($cntMetric, "thisHour", $campaignId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function cCntDay($campaignId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600;
		$date = date("Y-m-d", $time);
		$conds = "campaignId = $campaignId and date = '$date'";
		$row = array();
		$row['campaignId'] = $campaignId;

		foreach ( $this->cCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from cCntHour where $conds";
			$cnt = $this->Mmodel->getString($sql);
			if ( $cnt )
				$row[$cntMetric] = $cnt;
		}
		if ( $this->isEmpty($row) ) {
			$this->log("cCntDay: empty row ignored", 100);
			return;
		}

		$sql = "select * from cCntDay where $conds";
		$dbDayRow = $this->Mmodel->getRow($sql);
		if ( $dbDayRow ) {
			$this->Mmodel->dbUpdate("cCntDay", $dbDayRow['id'], $row);
		} else {
			$row['date'] = $date;
			$this->Mmodel->dbInsert("cCntDay", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->cCntMetrics as $cntMetric ) {
				if ( ! @$row[$cntMetric] )
					continue;
				$mkey = $this->keyNames->campaignCounter($cntMetric, "today", $campaignId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function cCntMonth($campaignId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600; // yesterday is the previous month in this case
		$month = date("Y-m", $time);
		$conds = "campaignId = $campaignId and left(date, 7) = '$month'";
		$row = array();
		$row['campaignId'] = $campaignId;

		foreach ( $this->cCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from cCntDay where $conds";
			$row[$cntMetric] = $this->Mmodel->getString($sql);
		}
		if ( $this->isEmpty($row) ) {
			$this->log("cCntMonth: empty row ignored", 100);
			return;
		}

		$sql = "select * from cCntMonth where campaignId = $campaignId and month = '$month'";
		$dbMonthRow = $this->Mmodel->getRow($sql);
		if ( $dbMonthRow ) {
			$this->Mmodel->dbUpdate("cCntMonth", $dbMonthRow['id'], $row);
		} else {
			$row['month'] = $month;
			$this->Mmodel->dbInsert("cCntMonth", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->cCntMetrics as $cntMetric ) {
				if ( ! @$row[$cntMetric] )
					continue;
				$mkey = $this->keyNames->campaignCounter($cntMetric, "thisMonth", $campaignId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function cCntYear($campaignId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600; // yesterday is the previous year in this case
		$year = date("Y", $time);
		$conds = "campaignId = $campaignId and left(month, 4) = '$year'";
		$row = array();
		$row['campaignId'] = $campaignId;

		foreach ( $this->cCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from cCntMonth where $conds";
			$row[$cntMetric] = $this->Mmodel->getString($sql);
		}
		if ( $this->isEmpty($row) ) {
			$this->log("cCntYear: empty row ignored", 100);
			return;
		}

		$sql = "select * from cCntYear where campaignId = $campaignId and year = '$year'";
		$dbYearRow = $this->Mmodel->getRow($sql);
		if ( $dbYearRow ) {
			$this->Mmodel->dbUpdate("cCntYear", $dbYearRow['id'], $row);
		} else {
			$row['year'] = $year;
			$this->Mmodel->dbInsert("cCntYear", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->cCntMetrics as $cntMetric ) {
				if ( ! @$row[$cntMetric] )
					continue;
				$mkey = $this->keyNames->campaignCounter($cntMetric, "thisYear", $campaignId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function cCntAllTime($campaignId) {
		foreach ( $this->cCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from cCntYear where campaignId = $campaignId";
			$sum = $this->Mmodel->getString($sql);
			$mkey = $this->keyNames->campaignCounter($cntMetric, "allTime", $campaignId);
			$this->Mmemcache->rawSet($mkey, $sum, 90);
		}
	}
	/*------------------------------------------------------------*/
	private function agoConds($agoMinutes = 5) {
		$ago = time() - $agoMinutes*60;
		/*	$today = date("Y-m-d");	*/
		/*	$thisHour = date("G");	*/
		/*	$thisMinute = date("i");	*/
		$agoDay = date("Y-m-d", $ago);
		$agoHour = date("G", $ago);
		$agoMinute = date("i", $ago);
		$conds = array(
			"date >= '$agoDay'",
			"hour >= '$agoHour'",
			"minute >= '$agoMinute'",
			/*	"date <= '$today'",	*/
			/*	"hour <= '$thisHour'",	*/
			/*	"minute <= '$thisMinute'",	*/
		);
		$conds = implode(" and ", $conds);
		return($conds);
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	private function placements() {
		$conds = $this->agoConds();
		$sql = "select distinct placementId from plCntMinute where $conds";
		$placementIds = $this->Mmodel->getStrings($sql);
		foreach ( $placementIds as $placementId )
			$this->placement($placementId);
	}
	/*------------------------------------------------------------*/
	private function placement($placementId) {
		$thisMinute = date("i");
		$thisHour = date("G");
		$thisDay = date("j");
		$thisMonth = date("n");

		if ( $thisMinute == 0 )
			$this->plCntHour($placementId, true);
		$this->plCntHour($placementId);

		if ( $thisHour == 0 && $thisMinute == 0 )
			$this->plCntDay($placementId, true);
		$this->plCntDay($placementId);

		if ( $thisDay == 1 && $thisHour == 0 && $thisMinute == 0 )
			$this->plCntMonth($placementId, true);
		$this->plCntMonth($placementId);

		if ( $thisMonth == 1 && $thisDay == 1 && $thisHour == 0 && $thisMinute == 0 )
			$this->plCntYear($placementId, true);
		$this->plCntYear($placementId);

		$this->plCntAllTime($placementId);
	}
	/*------------------------------------------------------------*/
	private function plCntHour($placementId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 3600;
		$date = date("Y-m-d", $time);
		$hour = date("H", $time);
		$conds = "placementId = '$placementId' and date = '$date' and hour = $hour";
		$row = array();
		$row['placementId'] = $placementId;
		foreach ( $this->plCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from plCntMinute where $conds";
			$cnt = $this->Mmodel->getString($sql);
			if ( $cnt )
				$row[$cntMetric] = $cnt;
		}
		if ( $this->isEmpty($row) ) {
			$this->log("plCntHour: empty row ignored", 100);
			return;
		}
		$sql = "select * from plCntHour where $conds";
		$dbHourRow = $this->Mmodel->getRow($sql);
		if ( $dbHourRow ) {
			$this->Mmodel->dbUpdate("plCntHour", $dbHourRow['id'], $row);
		} else {
			$row['date'] = $date;
			$row['hour'] = $hour;
			$this->Mmodel->dbInsert("plCntHour", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->plCntMetrics as $cntMetric ) {
				if ( ! @$row[$cntMetric] )
					continue;
				$mkey = $this->keyNames->placementCounter($cntMetric, "thisHour", $placementId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function plCntDay($placementId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600;
		$date = date("Y-m-d", $time);
		$conds = "placementId = '$placementId' and date = '$date'";
		$row = array();
		$row['placementId'] = $placementId;

		foreach ( $this->plCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from plCntHour where $conds";
			$cnt = $this->Mmodel->getString($sql);
			if ( $cnt )
				$row[$cntMetric] = $cnt;
		}
		if ( $this->isEmpty($row) ) {
			$this->log("plCntDay: empty row ignored", 100);
			return;
		}

		$sql = "select * from plCntDay where $conds";
		$dbDayRow = $this->Mmodel->getRow($sql);
		if ( $dbDayRow ) {
			$this->Mmodel->dbUpdate("plCntDay", $dbDayRow['id'], $row);
		} else {
			$row['date'] = $date;
			$this->Mmodel->dbInsert("plCntDay", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->plCntMetrics as $cntMetric ) {
				if ( ! @$row[$cntMetric] )
					continue;
				$mkey = $this->keyNames->placementCounter($cntMetric, "today", $placementId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function plCntMonth($placementId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600; // yesterday is the previous month in this case
		$month = date("Y-m", $time);
		$conds = "placementId = '$placementId' and left(date, 7) = '$month'";
		$row = array();
		$row['placementId'] = $placementId;

		foreach ( $this->plCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from plCntDay where $conds";
			$cnt = $this->Mmodel->getString($sql);
			if ( $cnt )
				$row[$cntMetric] = $cnt;
		}
		if ( $this->isEmpty($row) ) {
			$this->log("plCntMonth: empty row ignored", 100);
			return;
		}

		$sql = "select * from plCntMonth where placementId = '$placementId' and month = '$month'";
		$dbMonthRow = $this->Mmodel->getRow($sql);
		if ( $dbMonthRow ) {
			$this->Mmodel->dbUpdate("plCntMonth", $dbMonthRow['id'], $row);
		} else {
			$row['month'] = $month;
			$this->Mmodel->dbInsert("plCntMonth", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->plCntMetrics as $cntMetric ) {
				if ( ! @$row[$cntMetric] )
					continue;
				$mkey = $this->keyNames->placementCounter($cntMetric, "thisMonth", $placementId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function plCntYear($placementId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600; // yesterday is the previous year in this case
		$year = date("Y", $time);
		$conds = "placementId = '$placementId' and left(month, 4) = '$year'";
		$row = array();
		$row['placementId'] = $placementId;

		foreach ( $this->plCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from plCntMonth where $conds";
			$cnt = $this->Mmodel->getString($sql);
			if ( $cnt )
				$row[$cntMetric] = $cnt;
		}
		if ( $this->isEmpty($row) ) {
			$this->log("plCntYear: empty row ignored", 100);
			return;
		}

		$sql = "select * from plCntYear where placementId = '$placementId' and year = '$year'";
		$dbYearRow = $this->Mmodel->getRow($sql);
		if ( $dbYearRow ) {
			$this->Mmodel->dbUpdate("plCntYear", $dbYearRow['id'], $row);
		} else {
			$row['year'] = $year;
			$this->Mmodel->dbInsert("plCntYear", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->plCntMetrics as $cntMetric ) {
				$mkey = $this->keyNames->placementCounter($cntMetric, "thisYear", $placementId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function plCntAllTime($placementId) {
		foreach ( $this->plCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from plCntYear where placementId = '$placementId'";
			$sum = $this->Mmodel->getString($sql);
			$mkey = $this->keyNames->placementCounter($cntMetric, "allTime", $placementId);
			$this->Mmemcache->rawSet($mkey, $sum, 90);
		}
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	private function exchanges() {
		$conds = $this->agoConds();
		$sql = "select distinct exchangeId from exCntMinute where $conds";
		$exchangeIds = $this->Mmodel->getStrings($sql);
		foreach ( $exchangeIds as $exchangeId )
			$this->exchange($exchangeId);
	}
	/*------------------------------------------------------------*/
	private function exchange($exchangeId) {
		$thisMinute = date("i");
		$thisHour = date("G");
		$thisDay = date("j");
		$thisMonth = date("n");

		if ( $thisMinute == 0 )
			$this->exCntHour($exchangeId, true);
		$this->exCntHour($exchangeId);

		if ( $thisHour == 0 && $thisMinute == 0 )
			$this->exCntDay($exchangeId, true);
		$this->exCntDay($exchangeId);

		if ( $thisDay == 1 && $thisHour == 0 && $thisMinute == 0 )
			$this->exCntMonth($exchangeId, true);
		$this->exCntMonth($exchangeId);

		if ( $thisMonth == 1 && $thisDay == 1 && $thisHour == 0 && $thisMinute == 0 )
			$this->exCntYear($exchangeId, true);
		$this->exCntYear($exchangeId);

		$this->exCntAllTime($exchangeId);
	}
	/*------------------------------------------------------------*/
	private function exCntHour($exchangeId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 3600;
		$date = date("Y-m-d", $time);
		$hour = date("H", $time);
		$conds = "exchangeId = $exchangeId and date = '$date' and hour = $hour";
		$row = array();
		$row['exchangeId'] = $exchangeId;
		foreach ( $this->exCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from exCntMinute where $conds";
			$row[$cntMetric] = $this->Mmodel->getString($sql);
		}
		if ( $this->isEmpty($row) ) {
			$this->log("exCntHour: empty row ignored", 100);
			return;
		}
		$sql = "select * from exCntHour where $conds";
		$dbHourRow = $this->Mmodel->getRow($sql);
		if ( $dbHourRow ) {
			$this->Mmodel->dbUpdate("exCntHour", $dbHourRow['id'], $row);
		} else {
			$row['date'] = $date;
			$row['hour'] = $hour;
			$this->Mmodel->dbInsert("exCntHour", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->exCntMetrics as $cntMetric ) {
				$mkey = $this->keyNames->exchangeCounter($cntMetric, "thisHour", $exchangeId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
				$this->log("exCntHour: setting $mkey to '{$row[$cntMetric]}'", 1);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function exCntDay($exchangeId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600;
		$date = date("Y-m-d", $time);
		$conds = "exchangeId = $exchangeId and date = '$date'";
		$row = array();
		$row['exchangeId'] = $exchangeId;

		foreach ( $this->exCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from exCntHour where $conds";
			$row[$cntMetric] = $this->Mmodel->getString($sql);
		}
		if ( $this->isEmpty($row) ) {
			$this->log("exCntDay: empty row ignored", 100);
			return;
		}

		$sql = "select * from exCntDay where $conds";
		$dbDayRow = $this->Mmodel->getRow($sql);
		if ( $dbDayRow ) {
			$this->Mmodel->dbUpdate("exCntDay", $dbDayRow['id'], $row);
		} else {
			$row['date'] = $date;
			$this->Mmodel->dbInsert("exCntDay", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->exCntMetrics as $cntMetric ) {
				$mkey = $this->keyNames->exchangeCounter($cntMetric, "today", $exchangeId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
				$this->log("exCntDay: setting $mkey to '{$row[$cntMetric]}'", 1);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function exCntMonth($exchangeId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600; // yesterday is the previous month in this case
		$month = date("Y-m", $time);
		$conds = "exchangeId = $exchangeId and left(date, 7) = '$month'";
		$row = array();
		$row['exchangeId'] = $exchangeId;

		foreach ( $this->exCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from exCntDay where $conds";
			$row[$cntMetric] = $this->Mmodel->getString($sql);
		}
		if ( $this->isEmpty($row) ) {
			$this->log("exCntMonth: empty row ignored", 100);
			return;
		}

		$sql = "select * from exCntMonth where exchangeId = $exchangeId and month = '$month'";
		$dbMonthRow = $this->Mmodel->getRow($sql);
		if ( $dbMonthRow ) {
			$this->Mmodel->dbUpdate("exCntMonth", $dbMonthRow['id'], $row);
		} else {
			$row['month'] = $month;
			$this->Mmodel->dbInsert("exCntMonth", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->exCntMetrics as $cntMetric ) {
				$mkey = $this->keyNames->exchangeCounter($cntMetric, "thisMonth", $exchangeId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
				$this->log("exCntMonth: setting $mkey to '{$row[$cntMetric]}'", 1);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function exCntYear($exchangeId, $prev = false) {
		$time = time();
		if ( $prev )
			$time -= 24*3600; // yesterday is the previous year in this case
		$year = date("Y", $time);
		$conds = "exchangeId = $exchangeId and left(month, 4) = '$year'";
		$row = array();
		$row['exchangeId'] = $exchangeId;

		foreach ( $this->exCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from exCntMonth where $conds";
			$row[$cntMetric] = $this->Mmodel->getString($sql);
		}
		if ( $this->isEmpty($row) ) {
			$this->log("exCntYear: empty row ignored", 100);
			return;
		}

		$sql = "select * from exCntYear where exchangeId = $exchangeId and year = '$year'";
		$dbYearRow = $this->Mmodel->getRow($sql);
		if ( $dbYearRow ) {
			$this->Mmodel->dbUpdate("exCntYear", $dbYearRow['id'], $row);
		} else {
			$row['year'] = $year;
			$this->Mmodel->dbInsert("exCntYear", $row);
		}
		if ( ! $prev ) {
			foreach ( $this->exCntMetrics as $cntMetric ) {
				$mkey = $this->keyNames->exchangeCounter($cntMetric, "thisYear", $exchangeId);
				$this->Mmemcache->rawSet($mkey, $row[$cntMetric], 90);
				$this->log("exCntYear: setting $mkey to '{$row[$cntMetric]}'", 1);
			}
		}
	}
	/*------------------------------------------------------------*/
	private function exCntAllTime($exchangeId) {
		foreach ( $this->exCntMetrics as $cntMetric ) {
			$sql = "select sum($cntMetric) from exCntYear where exchangeId = $exchangeId";
			$sum = $this->Mmodel->getString($sql);
			$mkey = $this->keyNames->exchangeCounter($cntMetric, "allTime", $exchangeId);
			$this->Mmemcache->rawSet($mkey, $sum, 90);
			$this->log("exCntAllTime: setting $mkey to '$sum'", 1);
		}
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	private function isEmpty($row) {
		foreach ( $this->cntMetrics as $metric )
			if ( @$row[$metric] )
				return(false);
		return(true);
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
