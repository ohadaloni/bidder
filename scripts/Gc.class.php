<?php
/*------------------------------------------------------------*/
// run by cron once a minute
/*------------------------------------------------------------*/
class Gc extends Mcontroller {
	/*------------------------------------------------------------*/
	public function __construct() {
		parent::__construct();
		$topDir = dirname(__DIR__);
		$logsDir = "$topDir/logs/gc";
		$today = date("Y-m-d");
		$logFileName = "gc.$today.log";
		$logFile = "$logsDir/$logFileName";
		$this->logger = new Logger($logFile);
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	public function index() {
		$pid = getmypid();
		/*	$this->log("$pid: starting...");	*/
		$keepMonths = 3; // inhibit detailed minute reports b4 this date
		$ago = date("Y-m-01", time() - $keepMonths * 30 * 24 * 3600);
		$perRun = 100 * 1000;
		$tables = array(
			'cntMinute',
			'cCntMinute',
			'exCntMinute',
			'plCntMinute',
		);
		foreach ( $tables as $table ) {
			$sql = "select min(date) from $table";
			$minDateB4 = $this->Mmodel->getString($sql);
			$done = strcmp($ago, $minDateB4) <= 0;
			if ( $done ) {
				$this->log("$pid: $table: minDate $minDateB4, done");
			} else {
				$start = time();
				$delSql = "delete from $table where date < '$ago' order by id limit $perRun";
				$this->Mmodel->sql($delSql);
				$end = time();
				$elapsed = $end - $start;
				$minDateAfter = $this->Mmodel->getString($sql);
				$this->log("$pid: $table: deleted $perRun rows in $elapsed seconds. minDate was: $minDateB4, now: $minDateAfter");
			}
		}

	}
	/*------------------------------------------------------------*/
	private function error($msg, $r = 100) {
		$this->log("ERROR: $msg", $r);
	}
	/*------------------------------------------------------------*/
	private function warning($msg, $r = 100) {
		$this->log("WARNING: $msg", $r);
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
