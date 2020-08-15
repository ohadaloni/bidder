<?php
class KeyNames {
	/*------------------------------------------------------------*/
	private function counter($metric, $timeSlot, $pfx, $id = null, $nextSlot) {
		if ( $nextSlot == 1 ) {
			$timeSlots = array(
				'allTime' => 'allTime',
				'thisYear' => date("Y") + 1,
				'thisMonth' => date("m") == 12 ? (date("Y")+1)."-01" : date("Y").sprintf("%02d", date("m")+1),
				'today' => date("Y-m-d", time() + 86400),
				'thisHour' => date("Y-m-d-H", time() + 3600),
				'thisMinute' => date("Y-m-d-H:i", time() + 60),
			);
		} elseif ( $nextSlot == -1 ) {
			$timeSlots = array(
				'allTime' => 'allTime',
				'thisYear' => date("Y") - 1,
				'thisMonth' => date("m") == 1 ? (date("Y")-1)."-12" : date("Y").sprintf("%02d", date("m")-1),
				'today' => date("Y-m-d", time() - 86400),
				'thisHour' => date("Y-m-d-H", time() - 3600),
				'thisMinute' => date("Y-m-d-H:i", time() - 60),
			);
		} else {
			$timeSlots = array(
				'allTime' => 'allTime',
				'thisYear' => date("Y"),
				'thisMonth' => date("Y-m"),
				'today' => date("Y-m-d"),
				'thisHour' => date("Y-m-d-H"),
				'thisMinute' => date("Y-m-d-H:i"),
			);
			
		}
		$timeStr = $timeSlots[$timeSlot];
		$idString = $id ? "-$id" : "";
		$key = "counter-$pfx$idString-$metric-$timeStr";
		return($key);
	}
	/*------------------------------*/
	public function bidderCounter($metric, $timeSlot, $nextSlot = 0) {
		$bidderCounter = $this->counter($metric, $timeSlot, "bidder", null, $nextSlot);
		return($bidderCounter);
	}
	/*------------------------------*/
	public function campaignCounter($metric, $timeSlot, $campaignId, $nextSlot = 0) {
		$campaignCounter = $this->counter($metric, $timeSlot, "campaign", $campaignId, $nextSlot);
		return($campaignCounter);
	}
	/*------------------------------*/
	public function placementCounter($metric, $timeSlot, $placementId, $nextSlot = 0) {
		$placementCounter = $this->counter($metric, $timeSlot, "placement", $placementId, $nextSlot);
		return($placementCounter);
	}
	/*------------------------------*/
	public function exchangeCounter($metric, $timeSlot, $exchangeId, $nextSlot = 0) {
		$exchangeCounter = $this->counter($metric, $timeSlot, "exchange", $exchangeId, $nextSlot);
		return($exchangeCounter);
	}
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	/*------------------------------------------------------------*/
	public function lastCampaignBidId($campaignId) {
		return("lastBidId-$campaignId");
	}
	/*------------------------------------------------------------*/
	public function lastBidId() {
		return("lastBidId");
	}
	/*------------------------------------------------------------*/
	public function winQname() {
		return("winQ");
	}
	/*------------------------------------------------------------*/
	public function revenueQname() {
		return("revenueQ");
	}
	/*------------------------------------------------------------*/
	public function placementIdsQname() {
		return("placementIds");
	}
	/*------------------------------------------------------------*/
	public function placementPPM($placementId) {
		return("placementPPM-$placementId");
	}
	/*------------------------------------------------------------*/
	public function lastRequestId() {
		return("lastRequestId");
	}
	/*------------------------------------------------------------*/
	public function bidRequest($bidRequestId) {
		return("bidRequest-$bidRequestId");
	}
	/*------------------------------------------------------------*/
	public function bid($bidId) {
		return("bid-$bidId");
	}
	/*------------------------------------------------------------*/
}
