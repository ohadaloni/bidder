<?php
/*------------------------------------------------------------*/
class Bidder extends Mcontroller {
	/*------------------------------------------------------------*
	a bidder endpoint
	resond to a single request, win, or tracking pixel
	/*------------------------------------------------------------*/
	// some utilities
	private $bidderUtils;
	private $memUtils;
	private $keyNames;
	private $logger;
	/*------------------------------*/
	// the control panel tells if the bidder is on, and what its daily budget is
	// as set in the bidder UI
	private $controlPanel;
	/*------------------------------*/
	// the request
	private $input;
	private $bidRequest;
	private $bidRequestId;
	private $placementId;
	private $bidRequestKind;
	private $bidRequestName;
	private $w;
	private $h;
	private $geo;
	private $domain;
	/*------------------------------------------------------------*/
	private $campaigns; // a selected list of matching campaigns
	private $pacedCampaigns; // which are currently ready for the next bid
	private $campaign; // of which there is one winning internal auction
	/*------------------------------*/
	private $bidId; // wins and tracking pixels carry a bidId
	private $bid; // which is cached, to be gotten when called for
	/*------------------------------------------------------------*/
	public function index() {
		// init
		$startTime = microtime(true);
		$this->keyNames = new KeyNames;
		$logsDir = "/var/www/vhosts/bidder.theora.com/logs/bidder";
		$today = date("Y-m-d");
		$logFileName = "bidder.$today.log";
		$logFile = "$logsDir/$logFileName";
		$this->logger = new Logger($logFile);
		$this->bidderUtils = new BidderUtils($logFile);
		$this->memUtils = new MemUtils($logFile);
		$this->campaigns = $this->pacedCampaigns = array();

		// thing other then a bid request
		$bidId = @$_REQUEST['bidId'];
		if ( $bidId ) {
			$this->others($bidId);
			return;
		}

		$this->input = file_get_contents("php://input");

		// for debugging, a campaign can be forced to bid
		$forceCampaignId = @$_REQUEST['forceCampaignId'];
		if ( $forceCampaignId ) {
			$this->parseRequest();
			$this->forceCampaignId($forceCampaignId);
			return;
		}
		// a regular bid request 
		$filters = $this->filters();
		foreach ( $filters as $func ) {
			if ( ! $this->$func() ) {
				$this->noBid();
				$this->logTime("noBid", $startTime, 1);
				return;
			}
		}
		$this->bid();
		$this->logTime("bid", $startTime, 4);
	}
	/*------------------------------------------------------------*/
	// the processing sequence to reach a valid bid
	// a false return value stops processing immediately with a noBid response
	private function filters() {
		$filters = array(
			'parseRequest', // also placementId & exchangeId, which are used by:
			'countRequest', // count the request. also per placement & exchange
			'onOff', // the bidder might be off, as dictated by the control panel UI
			'throttle', // quick pacer, last minute budget cap
			'bidderBlack', // is the request's domain globally black listed
			'bidderPacer', // daily and hourly pacers
			'match', // select a matching list of campaigns
			'campaignsPacer', // and drop those currently overbudget
			'setPrices', // for those left, set a price for each
			'selectCampaign', // internal auction the select the highest bidding campaign
		);
		return($filters);
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	// process requests that are not a regular bidRequest
	private function others($bidId) {
		$this->bidId = $bidId;
		$this->bid = $this->memUtils->bid($bidId);
		if ( ! $this->bid ) {
			$this->error("others:$bidId: bid not found", 5);
			return;
		}
		$bidRequestId = $this->bidRequestId = $this->bid['id'];
		$this->bidRequest = $this->memUtils->bidRequest($bidRequestId);
		if ( $this->bidRequest ) {
			$this->exchangeId = @$this->bidRequest['exchangeId'];
			$this->placementId = $this->bidderUtils->placementId($this->bidRequest);
		} else {
			$this->error("others:$bidId:$bidRequestId: bid request not found", 5);
			return;
		}

		$pathInfo = $_REQUEST['PATH_INFO'];
		if ( strstr($pathInfo, "win") )
			$this->win($bidId);
		elseif ( strstr($pathInfo, "view") )
			$this->view($bidId);
		elseif ( strstr($pathInfo, "click") )
			$this->click($bidId);
		elseif ( strstr($pathInfo, "cpa") )
			$this->cpa($bidId);
		else
			$this->error("others:$bidId: pathInfo=$pathInfo not understood", 10);
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	private function win($bidId) {
		$cost = @$_REQUEST['cost'];
		if ( ! $cost ) {
			$this->error("win: no cost (bidId: $bidId)", 100);
			return;
		}
		$realCost = $cost/1000;
		$memCost = $this->memUtils->memDouble2int($realCost);
		$campaignId = $this->campaignId($bidId);
		if ( ! $campaignId ) {
			$this->error("win: $bidId: no campaignId", 100);
			return;
		}
		$campaignName = $this->bidderUtils->campaignName($campaignId);
		$this->log("win: $campaignName:$bidId: $realCost", 10);

		$mkey = $this->keyNames->bidderCounter("wins", "thisMinute");
		$this->Mmemcache->increment($mkey, 300);
		$mkey = $this->keyNames->bidderCounter("cost", "thisMinute");
		$this->Mmemcache->incrementBy($mkey, $memCost, 300);

		$mkey = $this->keyNames->campaignCounter("wins", "thisMinute", $campaignId);
		$this->Mmemcache->increment($mkey, 300);
		$mkey = $this->keyNames->campaignCounter("cost", "thisMinute", $campaignId);
		$this->Mmemcache->incrementBy($mkey, $memCost, 300);

		$mkey = $this->keyNames->placementCounter("wins", "thisMinute", $this->placementId);
		$this->Mmemcache->increment($mkey, 300);
		$mkey = $this->keyNames->placementCounter("cost", "thisMinute", $this->placementId);
		$this->Mmemcache->incrementBy($mkey, $memCost, 300);

		$mkey = $this->keyNames->exchangeCounter("wins", "thisMinute", $this->exchangeId);
		$this->Mmemcache->increment($mkey, 300);
		$mkey = $this->keyNames->exchangeCounter("cost", "thisMinute", $this->exchangeId);
		$this->Mmemcache->incrementBy($mkey, $memCost, 300);

		$this->qPlacement();

		$winQname = $this->keyNames->winQname();
		$datetime = date("Y-m-d H:i:s");
		$date = substr($datetime, 0, 10);
		$hour = (int)substr($datetime, 11, 2);
		$minute = (int)substr($datetime, 14, 2);
		$row = array(
			'date' => $date,
			'hour' => $hour,
			'minute' => $minute,
			'datetime' => $datetime,
			'exchangeId' => $this->exchangeId,
			'campaignId' => $campaignId,
			'bidRequestId' => $this->bidRequestId,
			'bidId' => $this->bidId,
			'placementId' => $this->placementId,
			'cost' => $realCost,
		);
		$json = json_encode($row);
		$this->log("win: $json", 1);
		$this->Mmemcache->msgQadd($winQname, $row);
	}
	/*------------------------------------------------------------*/
	private function view($bidId) {
		$campaignId = $this->campaignId($bidId);
		if ( ! $campaignId ) {
			$this->error("view:$bidId: no campaignId", 100);
			return;
		}
		$this->log("view: $bidId", 1);
		$mkey = $this->keyNames->bidderCounter("views", "thisMinute");
		$this->Mmemcache->increment($mkey, 300);

		$mkey = $this->keyNames->campaignCounter("views", "thisMinute", $campaignId);
		$this->Mmemcache->increment($mkey, 300);

		$mkey = $this->keyNames->placementCounter("views", "thisMinute", $this->placementId);
		$this->Mmemcache->increment($mkey, 300);

		$mkey = $this->keyNames->exchangeCounter("views", "thisMinute", $this->exchangeId);
		$this->Mmemcache->increment($mkey, 300);

		$this->qPlacement();

		header("Content-type: image/gif");
		$onePixelPath = "../images/onePixel.gif";
		readfile($onePixelPath);
	}
	/*------------------------------------------------------------*/
	private function click($bidId) {
		$campaignId = $this->campaignId($bidId);
		if ( ! $campaignId ) {
			$this->error("click:$bidId: no campaignId", 100);
			return;
		}
		$campaign = $this->bidderUtils->campaign($campaignId);;
		$campaignName = @$campaign['name'];
		$this->log("click:$campaignName:$bidId", 10);

		$mkey = $this->keyNames->bidderCounter("clicks", "thisMinute");
		$this->Mmemcache->increment($mkey, 300);

		$mkey = $this->keyNames->campaignCounter("clicks", "thisMinute", $campaignId);
		$this->Mmemcache->increment($mkey, 300);

		$mkey = $this->keyNames->placementCounter("clicks", "thisMinute", $this->placementId);
		$this->Mmemcache->increment($mkey, 300);

		$mkey = $this->keyNames->exchangeCounter("clicks", "thisMinute", $this->exchangeId);
		$this->Mmemcache->increment($mkey, 300);

		$this->qPlacement();

		// todo
		// (CPC campaigns only): call $this->revenue() with campaign cpc

		$url = $campaign['landingPage'];
		header("Location: $url");
	}
	/*------------------------------------------------------------*/
	private function cpa($bidId) {
		$cpa = @$_REQUEST['cpa'];
		if ( ! $cpa ) {
			$this->error("cpa:$bidId: no cpa in url)", 100);
			return;
		}
		$this->revenue($bidId, $cpa);
	}
	/*------------------------------*/
	private function revenue($bidId, $revenue) {
		$campaignId = $this->campaignId($bidId);
		if ( ! $campaignId ) {
			$this->error("revenue:$bidId: no campaignId", 100);
			return;
		}
		$memRevenue = $this->memUtils->memDouble2int($revenue);

		$campaignName = $this->bidderUtils->campaignName($campaignId);
		$this->log("revenue:$campaignName:$bidId: $revenue", 5);

		$mkey = $this->keyNames->bidderCounter("revenue", "thisMinute");
		$this->Mmemcache->incrementBy($mkey, $memRevenue, 300);

		$mkey = $this->keyNames->campaignCounter("revenue", "thisMinute", $campaignId);
		$this->Mmemcache->incrementBy($mkey, $memRevenue, 300);

		$mkey = $this->keyNames->placementCounter("revenue", "thisMinute", $this->placementId);
		$this->Mmemcache->incrementBy($mkey, $memRevenue, 300);
		$this->log("revenue: incrementing $mkey by $memRevenue", 9);

		$mkey = $this->keyNames->exchangeCounter("revenue", "thisMinute", $this->exchangeId);
		$this->Mmemcache->incrementBy($mkey, $memRevenue, 300);

		$this->qPlacement();

		$revenueQname = $this->keyNames->revenueQname();
		$datetime = date("Y-m-d H:i:s");
		$date = substr($datetime, 0, 10);
		$hour = (int)substr($datetime, 11, 2);
		$minute = (int)substr($datetime, 14, 2);
		$row = array(
			'date' => $date,
			'hour' => $hour,
			'minute' => $minute,
			'datetime' => $datetime,
			'exchangeId' => $this->exchangeId,
			'campaignId' => $campaignId,
			'bidRequestId' => $this->bidRequestId,
			'bidId' => $this->bidId,
			'placementId' => $this->placementId,
			'revenue' => $revenue,
		);
		$json = json_encode($row);
		$this->log("revenue: $json", 1);
		$this->Mmemcache->msgQadd($revenueQname, $row);
	}
	/*------------------------------------------------------------*/
	// Queue the placement of this bid, for the optimizer crons to process
	private function qPlacement() {
		$placementIdsQname = $this->keyNames->placementIdsQname();
		$this->log("qPlacement: ".$this->placementId, 1);
		$this->Mmemcache->msgQadd($placementIdsQname, $this->placementId);
	}
	/*------------------------------------------------------------*/
	private function noBid() {
		$this->log("noBid", 0.1);
		http_response_code(204);
	}
	/*------------------------------------------------------------*/
	private function parseRequest() {
		$this->bidRequest = json_decode($this->input, true);
		$this->bidRequestId = @$this->bidRequest['id'];
		if ( ! $this->bidRequestId ) {
			$this->error("parseRequest: no id in request", 100);
			return(false);
		}
		$this->bidRequest['exchangeVhost'] = $this->bidderUtils->exchangeVhost();
		$this->exchangeId = $this->bidRequest['exchangeId'] = $this->bidderUtils->exchangeId();

		$this->bidRequestKind = $this->bidderUtils->bidRequestKind($this->bidRequest);
		$this->bidRequestName = $this->bidderUtils->bidRequestName($this->bidRequest);
		$site = @$this->bidRequest['site'];
		$app = @$this->bidRequest['app'];
		$domain = $app ? @$app['domain'] : @$site['domain'];
		$this->domain = strtolower($domain);
		$video = @$this->bidRequest['video'];
		$banner = @$this->bidRequest['imp'][0]['banner'];

		$this->w = @$banner['w'];
		$this->h = @$banner['h'];
		$code3 = @$this->bidRequest['device']['geo']['country'];
		$this->geo = $this->bidderUtils->geo322($code3);
		// README - this also filters out all requerst with no banner object, like video
		if ( ! $this->w || ! $this->h || ! $this->geo ) {
			$this->error("no w h or geo in request", 100);
			$printr = print_r($this->bidRequest, true);
			$this->error($printr, 100);
			return(false);
		}
		$placementId = $this->placementId = $this->bidderUtils->placementId($this->bidRequest);
		$this->log("parseRequest:placementId: $placementId", 2);

		$mkey = $this->keyNames->lastRequestId();
		$this->Mmemcache->set($mkey, $this->bidRequestId, 300);
		$mkey = $this->keyNames->bidRequest($this->bidRequestId);
		$this->Mmemcache->set($mkey, $this->bidRequest, 300);

		return(true);
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	private function logTime($label, $startTime, $r) {
		if ( rand(1, 100 * 1000) > $r * 1000 )
				return;
		$endTime = microtime(true);
		$secondsElapsed = $endTime - $startTime;
		$millisecondsElapsed = round($secondsElapsed * 1000, 2);
		$this->log("$label: $r/100: $millisecondsElapsed milliseconds");
	}
	/*------------------------------------------------------------*/
	private function campaignId($bidId) {
		$bid = $this->memUtils->bid($bidId);
		if ( ! $bid ) {
			$this->error("campaignId: $bidId: cannot find bid $bidId in memcache", 5);
			return(null);
		}
		$bid0 = @$bid['seatbid'][0]['bid'];
		if ( ! $bid0 ) {
			$this->error("campaignId: $bidId: cannot find bid0 in bid", 10);
			return(null);
		}
		$campaignId = @$bid0['cid'];
		if ( ! $campaignId ) {
			$this->error("campaignId: $bidId: cannot find cid in bid0", 10);
			return(null);
		}
		return($campaignId);
	}
	/*------------------------------------------------------------*/
	private function countRequest() {
		$mkey = $this->keyNames->bidderCounter("bidRequests", "thisMinute");
		$this->Mmemcache->increment($mkey, 300);
		$mkey = $this->keyNames->placementCounter("bidRequests", "thisMinute", $this->placementId);
		$this->Mmemcache->increment($mkey, 300);
		$mkey = $this->keyNames->exchangeCounter("bidRequests", "thisMinute", $this->exchangeId);
		$this->Mmemcache->increment($mkey, 300);
		$this->log("countRequest", 0.4);
		return(true);
	}
	/*------------------------------------------------------------*/
	private function onOff() {
		$this->controlPanel = $this->bidderUtils->controlPanel();
		$this->log("onOff", 0.2);
		return(@$this->controlPanel['onOff']);
	}
	/*------------------------------------------------------------*/
	private function throttle() {
		$maxQps = 5;
		$mkey = $this->keyNames->bidderCounter("bidRequests", "thisMinute");
		$cnt = $this->Mmemcache->rawGet($mkey);
		$elapsed = date("s");
		if ( $elapsed == 0 ) {
			// first second of the minute
			return($cnt < $maxQps);
		}
		$qps = $cnt / $elapsed;
		$isOK = $qps < $maxQps;
		if ( ! $isOK )
			$this->log("throttle: tossing traffic over $maxQps QPS", 3);
		return($isOK);
	}
	/*------------------------------------------------------------*/
	private function bidderHourPacer($hourlyBudget) {
		$secondsSoFar = time() % 3600;
		if ( ! $secondsSoFar ) {
			$this->log("bidderHourPacer: 1st second of the hour", 3);
			return(true);
		}
		$mkey = $this->keyNames->bidderCounter("cost", "thisHour");
		$spentSoFar = $this->Mmemcache->rawGet($mkey);
		if ( ! $spentSoFar ) {
			$this->log("bidderHourPacer: nothing yet spent thisHour", 10);
			return(true);
		}
		
		$hourPart = $secondsSoFar / 3600;
		$budgetSoFar = $hourlyBudget * $hourPart;
		$ok = $spentSoFar < $budgetSoFar;
		$this->log("bidderHourPacer: $spentSoFar/$budgetSoFar", 1);
		return($ok);
	}
	/*------------------------------*/
	private function bidderPacer() {
		$dailyBudget = $this->bidderUtils->dailyBudget();
		$secondsSoFar = time() % 86400;
		if ( ! $secondsSoFar ) {
			$this->log("bidderPacer: 1st second of the day", 3);
			return(true);
		}
		$mkey = $this->keyNames->bidderCounter("cost", "today");
		$spentSoFar = $this->Mmemcache->rawGet($mkey);
		if ( ! $spentSoFar ) {
			$this->log("bidderPacer: nothing yet spent today", 3);
			return(true);
		}
		
		$dayPart = $secondsSoFar / 86400;
		$budgetSoFar = $dailyBudget * $dayPart;
		$ok = $spentSoFar < $budgetSoFar;
		$this->log("bidderPacer: $spentSoFar/$budgetSoFar", 1);
		if ( ! $ok )
			return(false);
		$hourPacerOK = $this->bidderHourPacer($dailyBudget/24);
		return($hourPacerOK);
	}
	/*------------------------------------------------------------*/
	private function match() {
		$campaigns = $this->bidderUtils->onCampaigns();
		if ( ! $campaigns ) {
			$this->log("No active campaigns", 1);
			return(false);
		}
		foreach ( $campaigns as $campaign )
			if ( $this->campaignMatches($campaign) )
				$this->campaigns[] = $campaign;
		$this->log("match", 0.1);
		if ( $this->campaigns )
			return(true);
		else
			return(false);
	}
	/*------------------------------------------------------------*/
	// campaignBlack() is true if its blacklisted for $domain
	// campaignWhite() is true if the $domain is ok for this campaign
	// a campaign=0 is global for the bidder
	/*------------------------------*/
	private function campaignWhite($campaignId, $domain) {
		$whiteListsDomains = $this->bidderUtils->whiteListsDomains($campaignId);
		if ( ! $whiteListsDomains )
			return(true);
		if ( in_array($this->domain, $whiteListsDomains) ) {
			return(true);
		}
		$this->log("campaignWhite: $campaignId: $domain", 100);
		return(false);
	}
	/*------------------------------*/
	private function campaignBlack($campaignId, $domain) {
		$blackListsDomains = $this->bidderUtils->blackListsDomains($campaignId);
		if ( ! $blackListsDomains )
			return(false);
		if ( in_array($this->domain, $blackListsDomains) ) {
			$this->log("campaignBlack: $campaignId: $domain", 100);
			return(true);
		}
		return(false);
	}
	/*------------------------------*/
	private function bidderBlack() {
		return( ! $this->campaignBlack(0, $this->domain));
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	private function campaignMatches($campaign) {
		$kind = $this->bidRequestKind;
		$w = $this->w;
		$h = $this->h;
		$geo = $this->geo;
		$cw = $campaign['w'];
		$ch = $campaign['h'];
		$cgeo = $campaign['geo'];
		$ckind = $campaign['kind'];

		$basics = $kind == $ckind && $w == $cw && $h == $ch && $geo == $cgeo;
		if ( ! $basics )
			return(false);

		if ( $campaign['weekDays'] ) {
			$campaignWeekDays = $campaign['weekDays'];
			$thisDayOfWeek = date("w");
			if ( ! strstr(",$campaignWeekDays,", ",$thisDayOfWeek,") )
				return(false);
		}
		if ( $campaign['hours'] ) {
			$campaignHours = $campaign['hours'];
			$thisHour = date("G");
			if ( ! strstr(",$campaignHours,", ",$thisHour,") )
				return(false);
		}
		// there are no fields appName & siteName at this time
		/*	if ( @$campaign['appName'] || @$campaign['siteName'] ) {	*/
			/*	$name = $this->bidRequestName;	*/
			/*	$ok = $name &&	*/
				/*	( $name == @$campaign['appName']	*/
					/*	|| $name == @$campaign['siteName'] );	*/
			/*	if ( ! $ok )	*/
				/*	return(false);	*/
		/*	}	*/
		// there are no fields appName & siteName at this time


		if ( $this->campaignBlack($campaign['id'], $this->domain) )
			return(false);
		if ( ! $this->campaignWhite($campaign['id'], $this->domain) )
			return(false);
		return(true);
	}
	/*------------------------------------------------------------*/
	private function campaignHourPacer($campaign, $hourlyBudget) {
		$campaignId = $campaign['id'];
		$secondsSoFar = time() % 3600;
		if ( ! $secondsSoFar ) {
			$this->log("campaignHourPacer:$campaignId: 1st second of the hour", 3);
			return(true);
		}
		$mkey = $this->keyNames->campaignCounter("cost", "thisHour", $campaignId);
		$spentSoFar = $this->Mmemcache->rawGet($mkey);
		if ( ! $spentSoFar ) {
			$this->log("campaignHourPacer:$campaignId: nothing yet spent thisHour", 10);
			return(true);
		}
		
		$hourPart = $secondsSoFar / 3600;
		$budgetSoFar = $hourlyBudget * $hourPart;
		$ok = $spentSoFar < $budgetSoFar;
		$this->log("campaignHourPacer:$campaignId: $spentSoFar/$budgetSoFar", 4);
		return($ok);
	}
	/*------------------------------*/
	private function campaignPacer($campaign) {
		$dailyBudget = $campaign['dailyBudget'];
		$campaignId = $campaign['id'];

		$secondsSoFar = time() % 86400;
		if ( ! $secondsSoFar ) {
			$this->log("campaignPacer:$campaignId: 1st second of the day", 3);
			return(true);
		}
		$mkey = $this->keyNames->campaignCounter("cost", "today", $campaignId);
		$spentSoFar = $this->Mmemcache->rawGet($mkey);
		if ( ! $spentSoFar ) {
			$this->log("campaignPacer:$campaignId: nothing yet spent today", 3);
			return(true);
		}
		
		$dayPart = $secondsSoFar / 86400;
		$budgetSoFar = $dailyBudget * $dayPart;
		$ok = $spentSoFar < $budgetSoFar;
		$this->log("campaignPacer:$campaignId: $spentSoFar/$budgetSoFar", 4);
		if ( ! $ok )
			return(false);
		$hourPacerOK = $this->campaignHourPacer($campaign, $dailyBudget/24);
		return($hourPacerOK);
	}
	/*------------------------------*/
	private function campaignsPacer() {
		$this->log("campaignsPacer", 0.5);
		foreach ( $this->campaigns as $campaign ) {
			if ( $this->campaignPacer($campaign) )
				$this->pacedCampaigns[] = $campaign;
		}
		if ( $this->pacedCampaigns )
			return(true);
		else
			return(false);
	}
	/*------------------------------------------------------------*/
	private function selectCampaign() {
		$price = 0;
		$selected = array();
		foreach ( $this->pacedCampaigns as $key => $campaign ) {
			$bidPrice = $campaign['bidPrice'];
			if ( $bidPrice > $price ) {
				$selected = array($campaign,);
				$price = $bidPrice;
			} elseif ( $price == $bidPrice )
				$selected[] = $campaign;
		}
		$cnt = count($selected);
		if ( ! $cnt ) {
			$this->log("Eh: selectCampaign: none selected", 100);
			return(false);
		}
		if ( $cnt == 1 ) {
			$this->campaign = $campaign;
		} else {
			$this->campaign = $selected[rand(0, $cnt-1)];
		}
		$campaignName = $this->campaign['name'];
		$this->log("selectCampaign: $campaignName", 10);
		return(true);
	}
	/*------------------------------------------------------------*/
	private function setPrices() {
		$placementId = $this->placementId;
		$placementPpm = $this->memUtils->placementPPM($placementId);
		foreach ( $this->pacedCampaigns as $key => $campaign ) {
			if ( $placementPpm === null ) {
				$this->pacedCampaigns[$key]['bidPrice'] = $campaign['baseBid'];
			} else {
				if ( $placementPpm < 0 )
					return(false); // this placemnt is bad, deny it altogether.
				$maxBid = $campaign['maxBid'];
				$desiredProfitMargin = $campaign['desiredProfitMargin'];
				$plBidPrice = $placementPpm / ( 1 + $desiredProfitMargin / 100 );
				$bidPrice = $plBidPrice > $maxBid ? $maxBid : $plBidPrice;
				$this->pacedCampaigns[$key]['bidPrice'] = $bidPrice;
				$this->log("setPrices: setting $bidPrice for $placementId", 10);
			}
		}
		return(true);
	}
	/*------------------------------------------------------------*/
	private function forceCampaignId($campaignId) {
		$this->parseRequest(); // sets it in memcache. for click redirect to work
		$this->campaign = $this->bidderUtils->campaign($campaignId);
		$this->campaign['bidPrice'] = $this->campaign['baseBid'];
		$this->bid();
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	private function bid() {
		$this->bidId = $bidId = $this->newBidId();
		// Thu Jul 30 22:10:54 IDT 2020
		// too late if adm returns with an error!
		$adm = $this->adm();
		$winServer = WIN_SERVER;
		$nurl = "http://$winServer/win?bidId=$bidId&cost=".'${AUCTION_PRICE}';
		$bannerUrl = $this->bannerUrl();
		$iurl = $bannerUrl;
		$price = $this->campaign['bidPrice'];
		$cid = $this->campaign['id'];
		$bid = array(
			'id' => $this->bidRequestId,
			'bidid' => $bidId,
			'seatbid' => array(
				array(
					'seat' => "theora.com",
					'bid' => array(
						'id' => $bidId,
						'adid' => 1,
						'price' => $price,
						'nurl' => $nurl,
						'adm' => $adm,
						'adomain' => array('theora.com',),
						'cat' => array("IAB1",),
						'attr' => array(6,),
						'impid' => 1,
						'iurl' => $iurl,
						'cid' => $cid,
						// with forceCampaignId, this->w,h are not set
						'w' => $this->campaign['w'],
						'h' => $this->campaign['h'],
					),
				),
			),
		);

		$mkey = $this->keyNames->bidderCounter("bids", "thisMinute");
		$this->Mmemcache->increment($mkey, 300);

		$mkey = $this->keyNames->campaignCounter("bids", "thisMinute", $cid);
		$this->Mmemcache->increment($mkey, 300);

		$mkey = $this->keyNames->placementCounter("bids", "thisMinute", $this->placementId);
		$this->Mmemcache->increment($mkey, 300);

		$mkey = $this->keyNames->exchangeCounter("bids", "thisMinute", $this->exchangeId);
		$this->Mmemcache->increment($mkey, 300);

		$json = json_encode($bid);
		$bid['campaign'] = $this->campaign;
		$mkey = $this->keyNames->bid($bidId);
		$this->Mmemcache->set($mkey, $bid, 300);
		$mkey = $this->keyNames->lastBidId();
		$this->Mmemcache->set($mkey, $bidId, 300);
		$mkey = $this->keyNames->lastCampaignBidId($cid);
		$this->Mmemcache->set($mkey, $bidId, 300);
		$bidRequestId = $this->bidRequestId;
		$campaignName = $this->campaign['name'];
		$this->log("Bidding: $bidRequestId --> $bidId - $price - $campaignName", 1);
		header("Content-type: application/json");
		echo $json;
	}
	/*------------------------------------------------------------*/
	private function bannerUrl() {
		$bannerServer = BANNER_SERVER;
		$banner = $this->campaign['banner'];
		$bannerUrl = "http://$bannerServer/banners/$banner";
		return($bannerUrl);
	}
	/*------------------------------------------------------------*/
	private function adm() {
		$bidId = $this->bidId;
		$viewServer = VIEW_SERVER;
		$viewUrl = "http://$viewServer/view?bidId=$bidId";
		$viewPixel = "<img width=\"1\" height=\"1\" src=\"$viewUrl\" style=\"display:none;\" />";
		$clickServer = CLICK_SERVER;
		$clickUrl = "http://$clickServer/click?bidId=$bidId";

		if ( $this->campaign['adm'] ) {
			$inner = $this->campaign['adm'];
			$inner = str_replace("{$CLICK_URL}", $clickUrl, $inner);
		} elseif ( $this->campaign['banner'] ) {
			$bannerServer = BANNER_SERVER;
			$bannerUrl = $this->bannerUrl();
			$inner = "<a href=\"$clickUrl\"><img src=\"$bannerUrl\" /></a>";
		} else {
			$campaignId = $this->campaign['id'];
			$campaignName = $this->campaign['name'];
			$this->error("adm: No banner nor adm in campaign: $campaignId:$campaignName", 100);
			return(null);
		}
		$adm = "$viewPixel$inner";
		return($adm);
	}
	/*------------------------------------------------------------*/
	private function newBidId() {
		$microtime = microtime(true);
		$rand = rand(1000, 9999);
		$bidId = sprintf("%.6lf-$rand", $microtime);
		return($bidId);
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	private function error($msg, $r = 100) {
		$this->log("ERROR: $msg", $r);
		error_log($msg);
	}
	/*------------------------------------------------------------*/
	private function log($msg, $r = 100) {
		if ( rand(1, 100 * 1000) > $r * 1000 )
				return;
		if ( $r == 100 )
				$str = $msg;
		else
				$str = "$r/100: $msg";
		$this->logger->log($str);
	}
	/*------------------------------------------------------------*/
}
