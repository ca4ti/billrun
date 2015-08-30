<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Refund action class
 *
 * @package  Action
 * @since    1.0
 */
class VfdaysAction extends Action_Base {

	/**
	 * method to execute the refund
	 * it's called automatically by the api main controller
	 * on vadofone
	 */
	public function execute() {
		Billrun_Factory::log()->log("Execute ird days API call", Zend_Log::INFO);
		$request = $this->getRequest();
		$sid = intval($request->get("sid"));
		$year = intval($request->get("year"));
		if (is_null($year) || empty($year)) {
			$year = date("Y");
		}
		$max_datetime = $request->get("max_datetime");

		$results = $this->count_days($sid, $year, $max_datetime);
		$tap3_count = $this->count_days_tap3($sid, $year, $max_datetime);
		if (isset($results[0]["count"])) {
			$days = $results[0]["count"];
		} else {
			$days = 0;
		}
		$max_days = ($tap3_count > $days) ? $tap3_count : $days;
		$this->getController()->setOutput(array(
			'status' => 1,
			'desc' => 'success',
			'input' => $request,
			'details' => array(
				'days' => $max_days,
				'min_day' => 45,
				'max_day' => 45,
			)
		));
	}

	/**
	 * for subscriber with LARGE_PREIUM (?KOSHER) counts the number of days he used he's phone abroad
	 * in the current year based on fraud lines 
	 * @param type $sid
	 * @return number of days 
	 */
	public function count_days($sid, $year = null, $max_datetime = null) {

		$ggsn_fields = Billrun_Factory::config()->getConfigValue('ggsn.fraud.groups.vodafone15');
		$sender = Billrun_Factory::config()->getConfigValue('nrtrde.fraud.groups.vodafone15');
		$plans = Billrun_Factory::config()->getConfigValue('nrtrde.fraud.events.NRTRDE1_B.target_plans');

		$match1 = array(
			'$match' => array(
				'subscriber_id' => $sid,
			),
		);
		$match2 = array(
			'$match' => array(
				'plan' => array('$in' => $plans),
				'$or' => array(
					array_merge(
						array(
						'type' => "ggsn",
						'record_opening_time' => new MongoRegex("/^$year/"),
						), $ggsn_fields
					),
					array(
						'type' => "nrtrde",
						'callEventStartTimeStamp' => new MongoRegex("/^$year/"),
						'sender' => array('$in' => $sender),
						'$or' => array(
							array(
								'record_type' => "MTC",
								'callEventDurationRound' => array('$gt' => 0), // duration greater then 0 => call and not sms
							),
							array(
								'record_type' => "MOC",
								'connectedNumber' => new MongoRegex('/^972/')
							),
						),
					),
				),
			),
		);

		if (!empty($max_datetime)) {
			$match2['$match']['unified_record_time'] = array('$lte' => new MongoDate(strtotime($max_datetime)));
		}

		$group = array(
			'$group' => array(
				'_id' => array('$substr' =>
					array(
						array('$ifNull' => array('$record_opening_time', '$callEventStartTimeStamp')),
						4,
						4
					)
				),
				'count' => array('$sum' => 1),
			),
		);

		$group2 = array(
			'$group' => array(
				'_id' => null,
				'count' => array('$sum' => 1),
			),
		);

		$res = Billrun_Factory::db()->linesCollection()->aggregate($match1, $match2, $group, $group2);
		return $res;
	}

	public function count_days_tap3($sid, $year = null) {
		$from = date('YmdHis', strtotime($year . '-01-01' . ' 00:00:00'));
		$to = date('YmdHis', strtotime($year . '-12-31' . ' 23:59:59'));
		$plans = Billrun_Factory::config()->getConfigValue('nrtrde.fraud.events.NRTRDE1_B.target_plans');
		print($year);
		print_r($plans);
		print_r($to);
		print("<br>");
		print_r($from);
		$match = array(
			'$match' => array(
				'sid' => $sid,
				'type' => 'tap3',
				'plan' => array('$in' => $plans),
				'basicCallInformation.CallEventStartTimeStamp.localTimeStamp' => array(
					'$gte' => $from,
					'$lte' => $to,
				),
				'arategroup' => "VF",
				'in_group' => array(
					'$gt' => 0,
				),
				'billrun' => array(
					'$exists' => true,
				),
			),
		);
		$group = array(
			'$group' => array(
				'_id' => array(
					'day_key' => array(
						'$substr' => array('$basicCallInformation.CallEventStartTimeStamp.localTimeStamp', 0, 8),
					),
				),
			),
		);
		$group2 = array(
			'$group' => array(
				'_id' => 'null',
				'day_sum' => array(
					'$sum' => 1,
				),
			),
		);
		$billing_connection = Billrun_Factory::db(Billrun_Factory::config()->getConfigValue('billing.db'))->linesCollection();
		$results = $billing_connection->aggregate($match, $group);
		print_r($results);
		die();
		return isset($results) ? $results[0]['day_sum'] : 0;
	}

}
