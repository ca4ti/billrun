<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */
/**
 * Billing customer calculator class for ilds records
 *
 * @package  calculator
 * @since    1.0
 */
require_once __DIR__ . '/../../../application/golan/' . 'subscriber.php';

class Billrun_Calculator_Customer extends Billrun_Calculator {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = "Customer";

	/**
	 * method to receive the lines the calculator should take care
	 * 
	 * @return Mongodloid_Cursor Mongo cursor for iteration
	 */
	protected function getLines() {
		$lines = Billrun_Factory::db()->linesCollection();

		return $lines->query(array(
				'source' => array('$in' => array('ilds', 'premium')),
				'unified_record_time' => array('$gt' => new MongoDate(strtotime('-7 month'))),
				'$or' => array(
					array('account_id' => array('$exists' => false)),
					array('subscriber_id' => array('$exists' => false))
				)
		));
	}

	/**
	 * @param int $subscriber_id the subscriber id to update
	 * @param Mongodloid_Entity $line the billing line to update
	 *
	 * @return boolean true on success else false
	 */
	protected function updateRow($row) {
		if ($row['source'] == 'api' && $row['type'] == 'refund') {
			$time = date("YmtHis", $row->get('unified_record_time')->sec);
			$phone_number = $row->get('NDC_SN');
		} else {
			$time = $row->get('call_start_dt');
			$phone_number = $row->get('caller_phone_no');
		}

		$format_time = date(Billrun_Base::base_dateformat, strtotime($time));
		$params = array(array('NDC_SN' => $phone_number, 'time' => $format_time, 'stamp' => $row->get('stamp'), 'EXTRAS' => 0, 'DATETIME' => $format_time)); //todo: modify this!
		// load subscriber
		$golan = new Subscriber_Golan();
//		$subscriber = golan_subscriber::get($phone_number, $time); the old way
		$list = $golan->requestList($params);
		$subscriber = $list[0];
		if (!$subscriber) {
			Billrun_Factory::log()->log("subscriber not found. phone:" . $phone_number . " time: " . $time, Zend_Log::INFO);
			return false;
		}

		$current = $row->getRawData();

		if (!isset($subscriber['subscriber_id']) || !isset($subscriber['account_id'])) {
			Billrun_Factory::log()->log("subscriber_id or account_id not found. phone:" . $phone_number . " time: " . $time, Zend_Log::WARN);
			return false;
		}

		Billrun_Factory::log()->log("update line: " . $row->get('stamp') . " subscriber_id: " . $subscriber['subscriber_id'] . ", account_id: " . $subscriber['account_id'], Zend_Log::INFO);
		$added_values = array('subscriber_id' => $subscriber['subscriber_id'], 'account_id' => $subscriber['account_id']);
		$newData = array_merge($current, $added_values);
		$row->setRawData($newData);
		return true;
	}

	/**
	 * Execute the calculation process
	 */
	public function calc() {
		foreach ($this->data as $item) {
			// update billing line with billrun stamp
			if (!$this->updateRow($item)) {
				Billrun_Factory::log()->log("phone number:" . $item->get('caller_phone_no') . " cannot update billing line", Zend_Log::INFO);
				continue;
			}
		}
	}

	/**
	 * Execute write down the calculation output
	 */
	public function write() {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorWriteData', array('data' => $this->data));
		$lines = Billrun_Factory::db()->linesCollection();
		foreach ($this->data as $item) {
			$item->save($lines);
		}
		Billrun_Factory::dispatcher()->trigger('afterCalculatorWriteData', array('data' => $this->data));
	}

}
