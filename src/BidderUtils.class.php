<?php
class BidderUtils extends Mcontroller {
	/*------------------------------------------------------------*/
	private $logger;
	private $memUtils;
	/*------------------------------------------------------------*/
	public function __construct($logFile = null) {
		parent::__construct();
		$this->memUtils = new memUtils;
		if ( $logFile )
			$this->logger = new Logger($logFile);
	}
	/*------------------------------------------------------------*/
	public function dailyBudget() {
		$controlPanel = $this->controlPanel();
		$dailyBudget = $controlPanel['dailyBudget'];
		return($dailyBudget);
	}
	/*------------------------------------------------------------*/
	public function controlPanel($noCache = false) {
		$controlPanelTtl = $noCache ? null : 15;
		$sql = "select * from controlPanel order by id desc limit 1";
		$controlPanel = $this->Mmodel->getRow($sql, $controlPanelTtl);
		return($controlPanel);
	}
	/*------------------------------------------------------------*/
	// used by both placement summary & the optimizer
	// return all placements that are familiar, with their metrics
	// a cron loads the ppm to mem
	// from this
	/*------------------------------*/
	public function placements() {
		$agoDays = PLACEMENT_OPT_WINDOW;
		$minWins = PLACEMENT_MIN_WINS;
		$fields = array(
			"placementId",
			"sum(bids) as bids",
			"sum(wins) as wins",
			"sum(cost) as cost",
			"sum(views) as views",
			"sum(clicks) as clicks",
			"sum(revenue) as revenue",
		);
		$fields = implode(", ", $fields);
		$groupBy = "group by placementId";
		$having = "having sum(wins) >= $minWins";
		$ago = date("Y-m-d", time() - $agoDays*24*3600);
		$conds = "date > '$ago'";
		$sql = "select $fields from plCntDay where $conds $groupBy $having";
		$rows = $this->Mmodel->getRows($sql);
		foreach ( $rows as $key => $row )
			$this->setRates($rows[$key]);
		return($rows);
	}
	/*------------------------------*/
	private function setRates(&$row) {
			$row['profit'] = $row['revenue'] - $row['cost'];
			$row['ppm'] = $this->ppm($row['profit'], $row['wins']);
			$row['winRate'] = $this->winRate($row['wins'], $row['bids']);
			$row['cpm'] = $this->cpm($row['cost'], $row['wins']);
			$row['viewRate'] = $this->viewRate($row['views'], $row['wins']);
			$row['cpv'] = $this->cpv($row['cost'], $row['views']);
			$row['clickRate'] = $this->clickRate($row['clicks'], $row['wins']);
			$row['cpc'] = $this->cpc($row['cost'], $row['clicks']);
			$row['rpm'] = $this->rpm($row['revenue'], $row['wins']);
	}
	/*------------------------------------------------------------*/
	public function onCampaigns() {
		$sql = "select * from campaigns where onSwitch = 1";
		$onCampaigns = $this->Mmodel->getRows($sql, 300);
		return($onCampaigns);
	}
	/*------------------------------------------------------------*/
	public function campaign($campaignId, $fromCache = true) {
		if ( ! $campaignId )
			return(null);
		$sql = "select * from campaigns where id = $campaignId";
		$ttl = $fromCache ? 300 : null;
		$campaign = $this->Mmodel->getRow($sql, $ttl);
		return($campaign);
	}
	/*------------------------------------------------------------*/
	public function campaignName($campaignId) {
		if ( ! $campaignId )
			return("no-id");
		$sql = "select name from campaigns where id = $campaignId";
		$name = $this->Mmodel->getString($sql, 300);
		return($name);
	}
	/*------------------------------------------------------------*/
	public function _bidDescription($bid) {
		$bidId = @$bid['bidid'];
		$parts = explode(".", $bidId);
		$time = $parts[0];
		$datetime = date("Y-m-d G:i:s", $time);
		$bid0 = @$bid['seatbid'][0]['bid'];
		$price = $bid0['price'];
		$campaignId = $bid0['cid'];
		$campaignName = $this->campaignName($campaignId);
		$_bidDescription = "$datetime: $campaignName: \$$price";
		return($_bidDescription);
	}
	/*------------------------------*/
	public function bidDescription($bid) {
		if ( ! $bid )
			return("No Bid");
		$bidRequestId = $bid['id'];
		$bidRequest = $this->memUtils->bidRequest($bidRequestId);
		if ( $bidRequest )
			$requestDescription = $this->requestDescription($bidRequest);
		else
			$requestDescription = "bidRequest $bidRequestId not found";
		$bidDescription = $this->_bidDescription($bid);
		$description = "bid: $bidDescription, request: $requestDescription";
		return($description);
	}
	/*------------------------------------------------------------*/
	public function requestDescription($bidRequest) {
		return($this->placementId($bidRequest));
	}
	/*------------------------------------------------------------*/
	public function floatMetrics() {
		$metrics = array(
			'cost',
			'revenue',
		);
		return($metrics);
	}
	/*------------------------------------------------------------*/
	// for bidder,placment - withBidRequests = true
	// campaigns - request counter not relevant
	/*------------------------------*/
	public function cntMetrics($withBidRequests = true) {
		$metrics = array(
			'bidRequests',
			'bids',
			'wins',
			'cost',
			'views',
			'clicks',
			'revenue', // always put revenue here, refardless of model (cpa, cpc, cpm)
		);
		if ( ! $withBidRequests )
			array_shift($metrics);
		return($metrics);
	}
	/*------------------------------*/
	public function rateMetrics($withBidRate = true) {
		$metrics = array(
			'bidRate',
			'winRate',
			'cpm', // cost per 1000 wins (see cpv)
			'viewRate',
			'cpv', // cost per 1000 views, (my terminolgy for the usual cpm)
			'clickRate', // per wins
			'cpc', // cost per 1000 click
			'rpm', // revenue per 1000 wins
		);
		if ( ! $withBidRate )
			array_shift($metrics);
		return($metrics);
	}
	/*------------------------------------------------------------*/
	public function metrics() {
		$metrics = array(
			'bidRequests',
			'bids',
			'bidRate',
			'wins',
			'winRate',
			'cost',
			'cpm', // cost per 1000 wins (see cpv)
			'views',
			'viewRate',
			'cpv', // cost per 1000 views, (my terminolgy for the usual cpm)
			'clicks',
			'clickRate', // per wins
			'cpc', // cost per 1000 click
			'revenue',
			'rpm', // revenue per 1000 wins
		);
		return($metrics);
	}
	/*------------------------------------------------------------*/
	public function timeSlots() {
		$timeSlots = array(
			'thisMinute',
			'thisHour',
			'today',
			'thisMonth',
			'thisYear',
			'allTime',
		);
		return($timeSlots);
	}
	/*------------------------------------------------------------*/
	public function exchanges() {
		$sql = "select * from exchanges order by id";
		$exchanges = $this->Mmodel->getRows($sql, 24*3600);
		return($exchanges);
	}
	/*------------------------------------------------------------*/
	public function exchangeName($exchangeId) {
		$exchanges = $this->exchanges();
		$exchanges = Mutils::reIndexBy($exchanges, "id");
		$exchangeName = @$exchanges[$exchangeId]['name'];
		return($exchangeName);
	}
	/*------------------------------------------------------------*/
	// Sun Jan 19 09:56:33 IST 2020
	// adx vs adxperience
	// this always returned adx, also for adxperience
	// so adding '.bidder' to the string
	public function exchangeId() {
		$exchanges = $this->exchanges();
		foreach ( $exchanges as $exchange ) {
			$vhost = $exchange['vhost'];
			if ( strstr(__DIR__, "$vhost.bidder") )
				return($exchange['id']);
		}
		return(null);
	}
	/*------------------------------------------------------------*/
	public function exchangeVhosts() {
		$exchanges = $this->exchanges();
		$exchangeVhosts = Mutils::arrayColumn($exchanges, "vhost");
		return($exchangeVhosts);
	}
	/*------------------------------------------------------------*/
	public function exchangeVhost() {
		$dirParts = explode("/", __DIR__);
		$numParts = count($dirParts);
		$vhostFolderName = $dirParts[$numParts-2];
		$parts = explode(".", $vhostFolderName);
		$exchangeVhost = $parts[0];
		return($exchangeVhost);
	}
	/*------------------------------------------------------------*/
	public function rate($part, $whole) {
		$rate = $whole ? 100.0 * $part / $whole : 0.0;
		return($rate);
	}
	/*------------------------------------------------------------*/
	public function pm($amount, $howMany) {
		if ( ! $amount )
			return(0.0);
		if ( ! $howMany ) {
			$this->error("pm: amount=$amount with no howMany");
			return(0.0);
		}
		$per = $amount / $howMany;
		$pm = $per*1000;
		return($pm);
	}
	/*------------------------------------------------------------*/
	public function cpm($cost, $wins) {
		return($this->pm($cost, $wins));
	}
	/*------------------------------------------------------------*/
	public function cpv($cost, $views) {
		return($this->pm($cost, $views));
	}
	/*------------------------------------------------------------*/
	public function ppm($profit, $wins) {
		return($this->pm($profit, $wins));
	}
	/*------------------------------------------------------------*/
	public function rpm($revenue, $wins) {
		return($this->pm($revenue, $wins));
	}
	/*------------------------------------------------------------*/
	public function cpc($cost, $clicks) {
		return($this->pm($cost, $clicks));
	}
	/*------------------------------------------------------------*/
	public function bidRate($bids, $bidRequests) {
		return($this->rate($bids, $bidRequests));
	}
	/*------------------------------------------------------------*/
	public function clickRate($clicks, $wins) {
		return($this->rate($clicks, $wins));
	}
	/*------------------------------------------------------------*/
	public function viewRate($views, $wins) {
		return($this->rate($views, $wins));
	}
	/*------------------------------------------------------------*/
	public function winRate($wins, $bids) {
		return($this->rate($wins, $bids));
	}
	/*------------------------------------------------------------*/
	public function hourGroup($time) {
		$hour = date("G", $time);
		$hourGroups = $this->hourGroups();
		foreach ( $hourGroups as $hourGroup ) {
			$parts = explode("-", $hourGroup);
			if ( $hour >= $parts[0] && $hour < $parts[1] )
				return($hourGroup);
		}
		$this->error("hourGroup: Eh?");
		return(null);
	}
	/*------------------------------*/
	public function hourGroups() {
		$hourGroups = array(
			"0-6",
			"6-9",
			"9-17",
			"17-21",
			"21-24",
		);
		return($hourGroups);
	}
	/*------------------------------------------------------------*/
	public function ageGroup($bidRequest) {
		$yob = @$bidRequest['user']['yob'];
		if ( ! $yob )
			return("unk");
		$thisYear = date("Y");
		$age = $thisYear - $yob;
		if ( $age < 12 )
			return("lt12");
		elseif ( $age <= 18 )
			return("12-18");
		elseif ( $age <= 29 )
			return("19-29");
		elseif ( $age <= 40 )
			return("30-40");
		else
			return("gt40");
	}
	/*------------------------------*/
	public function ageGroups() {
		$ageGroups = array(
			"unk",
			"lt12",
			"12-18",
			"19-29",
			"30-40",
			"gt40",
		);
		return($ageGroups);
	}
	/*------------------------------------------------------------*/
	public function gender($bidRequest) {
		$gender = @$bidRequest['user']['gender'];
		if ( ! $gender )
			$gender = "unk";
		return($gender);
	}
	/*------------------------------------------------------------*/
	public function genders() {
		$genders = array(
			"unk",
			"F",
			"M",
			"O", // known to be other, see rtb specs
		);
		return($genders);
	}
	/*------------------------------------------------------------*/
	public function kinds() {
		$kinds = array(
			"app",
			"desktop",
		);
		return($kinds);
	}
	/*------------------------------------------------------------*/
	public function countries() {
		$sql = "select * from countries where code3 is not null order by name";
		$countries = $this->Mmodel->getRows($sql, 24*3600);
		return($countries);
	}
	/*------------------------------------------------------------*/
	public function geo322($code3) {
		$sql = "select code from countries where code3 = '$code3'";
		$code3 = $this->Mmodel->getString($sql, 24*3600);
		return($code3);
	}
	/*------------------------------*/
	public function geo($bidRequest) {
		$code3 = @$bidRequest['device']['geo']['country'];
		$geo = $this->geo322($code3);
		return($geo);
	}
	/*------------------------------------------------------------*/
	public function w($bidRequest) {
		$banner = @$bidRequest['imp'][0]['banner'];
		$w = @$banner['w'];
		return($w);
	}
	/*------------------------------------------------------------*/
	public function h($bidRequest) {
		$banner = @$bidRequest['imp'][0]['banner'];
		$h = @$banner['h'];
		return($h);
	}
	/*------------------------------------------------------------*/
	public function placementId($bidRequest) {
		$exchangeVhost = $bidRequest['exchangeVhost'];
		$kind = $this->bidRequestKind($bidRequest);
		$name = $this->bidRequestName($bidRequest);
		$w = $this->w($bidRequest);
		$h = $this->h($bidRequest);
		$geo = $this->geo($bidRequest);
		/*	$hourGroup = $this->hourGroup(time());	*/
		$ageGroup = $this->ageGroup($bidRequest);
		$gender = $this->gender($bidRequest);
		/*	$placementId = "$exchangeVhost-$kind-[$name]-{$w}x$h-$geo-[$hourGroup]-[$ageGroup]-$gender";	*/
		$placementId = "$exchangeVhost-$kind-[$name]-{$w}x$h-$geo";
		return($placementId);
	}
	/*------------------------------------------------------------*/
	public function bidRequestKind($bidRequest) {
		$app = @$bidRequest['app'];
		$kind = $app ? "app" : "desktop";
		return($kind);
	}
	/*------------------------------------------------------------*/
	public function bidRequestName($bidRequest) {
		$app = @$bidRequest['app'];
		$site = @$bidRequest['site'];
		$name = $app ? @$app['name'] : @$site['name'];
		return($name);
	}
	/*------------------------------------------------------------*/
	// 0 campaignId means bidder==global
	// ttl is hardwired to each code so performance can be controlled separately
	// whitelists are small, and are more like comapgin config (300)
	// blacklists are long, and may require better performance (900 now)
	/*------------------------------*/
	public function whiteListsDomains($campaignId) {
		$sql = "select whiteListId from campaignWhiteLists where campaignId = $campaignId";
		$ttl = 300;
		$whiteListIds = $this->Mmodel->getStrings($sql, $ttl);
		if ( ! $whiteListIds )
			return(null);
		$whiteListIdsList = implode(", ", $whiteListIds);
		$sql = "select lower(domain) from whiteListItems where whiteListId in ( $whiteListIdsList )";
		$whiteListsDomains = $this->Mmodel->getStrings($sql, $ttl);
		return($whiteListsDomains);
	}
	/*------------------------------*/
	public function blackListsDomains($campaignId) {
		$sql = "select blackListId from campaignBlackLists where campaignId = $campaignId";
		$ttl = 900;
		$blackListIds = $this->Mmodel->getStrings($sql, $ttl);
		if ( ! $blackListIds )
			return(null);
		$blackListIdsList = implode(", ", $blackListIds);
		$sql = "select lower(domain) from blackListItems where blackListId in ( $blackListIdsList )";
		$blackListsDomains = $this->Mmodel->getStrings($sql, $ttl);
		return($blackListsDomains);
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
			$str = "$now: $msg";
		else
			$str = "$now: $r/100: $msg";
		if ( $this->logger )
			$this->logger->log($str, false);
		else
			error_log($str);

	}
	/*------------------------------------------------------------*/
}
/*------------------------------------------------------------*/
