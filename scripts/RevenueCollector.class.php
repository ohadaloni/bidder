<?php
class RevenueCollector extends Mcontroller {
	/*------------------------------------------------------------*/
	private $bidderUtils;
	private $memUtils;
	private $keyNames;
	/*------------------------------------------------------------*/
	public function __construct() {
		parent::__construct();
		$topDir = dirname(__DIR__);
		$logsDir = "$topDir/logs/revenueCollector";
		$today = date("Y-m-d");
		$logFileName = "revenueCollector.$today.log";
		$logFile = "$logsDir/$logFileName";
		$this->logger = new Logger($logFile);
		$this->bidderUtils = new BidderUtils($logFile);
		$this->memUtils = new MemUtils($logFile);
		$this->keyNames = new KeyNames;
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	public function index() {
		$maxBulk = 10000;
		$revenueQname = $this->keyNames->revenueQname();
		$qLength = $this->Mmemcache->msgQlength($revenueQname);
		if ( ! $qLength ) {
			$this->log("Q empty. quitting.", 100);
			return;
		}
		$rows = array();
		/*	$this->log("index: qLength=$qLength");	*/
		$bulkSize = floor($qLength / 2 );
		if ( $bulkSize > $maxBulk )
			$bulkSize = $maxBulk;
		if ( ! $bulkSize )
			$bulkSize = $qLength;
		for($i=0;$i<$bulkSize;$i++)
			$rows[] = $this->Mmemcache->msgQnext($revenueQname);
		$inserted = $this->Mmodel->bulkInsert("revenue", $rows);
		$s = $inserted == 1 ? "" : "s";
		$this->log("index: $inserted new revenue row$s inserted", 100);
	}
	/*------------------------------------------------------------*/
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


