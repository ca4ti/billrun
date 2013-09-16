<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing calculator for  pricing  billing lines with customer price.
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Calculator_CustomerPricing extends Billrun_Calculator {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = "pricing";

	/**
	 * the field will be add as the calculated field
	 * @var string
	 */
	protected $pricingField = "price_customer";

	/**
	 * should we apply vat to the row price by default
	 * 
	 * @var boolean
	 */
	protected $vatable = true;

	/**
	 * the plans available for the process
	 * @var type 
	 */
	protected $plans = array();

	/**
	 * don't include old lines than this value
	 * 
	 * @var int timestamp
	 */
	protected $billrun_lower_bound_timestamp;

	/**
	 * constuctor to build the base customer pricing object
	 * 
	 * @param array $options
	 */
	public function __construct($options = array()) {
		$options['autoload'] = false;
		parent::__construct($options);

		if (isset($options['calculator']['limit'])) {
			$this->limit = $options['calculator']['limit'];
		}
		if (isset($options['calculator']['vatable'])) {
			$this->vatable = $options['calculator']['vatable'];
		}
		if (isset($options['calculator']['months_limit'])) {
			$this->months_limit = $options['calculator']['months_limit'];
		}
		$this->billrun_lower_bound_timestamp = is_null($this->months_limit) ? 0 : strtotime($this->months_limit . " months ago");
		// set months limit
		$this->load();
		$this->loadRates();
		$this->loadPlans();
	}

	protected function getLines() {
		$query = array();
		$query['type'] = array('$in' => array('ggsn', 'smpp', 'smsc', 'nsn', 'tap3', 'credit'));
		return $this->getQueuedLines($query);
	}

	/**
	 * execute the calculation process
	 * @TODO this function mighh  be a duplicate of  @see Billrun_Calculator::calc() do we really  need the diffrence  between Rate/Pricing?
	 */
	public function calc() {
		Billrun_Factory::dispatcher()->trigger('beforePricingData', array('data' => $this->data));
		$lines_coll = Billrun_Factory::db()->linesCollection();

		$lines = $this->pullLines($this->lines);
		foreach ($lines as $key => $line) {
			if ($line) {
				Billrun_Factory::dispatcher()->trigger('beforePricingDataRow', array('data' => &$line));
				//Billrun_Factory::log()->log("Calcuating row : ".print_r($item,1),  Zend_Log::DEBUG);
				$line->collection($lines_coll);
				if ($this->isLineLegitimate($line)) {
					if (!$this->updateRow($line)) {
						continue;
					}
				}
				$this->data[] = $line;
				//$this->updateLinePrice($item); //@TODO  this here to prevent divergance  between the priced lines and the subscriber's balance/billrun if the process fails in the middle.
				Billrun_Factory::dispatcher()->trigger('afterPricingDataRow', array('data' => &$line));
			}
		}
		Billrun_Factory::dispatcher()->trigger('afterPricingData', array('data' => $this->data));
	}

	protected function updateRow($row) {
		$rate = $this->getRowRate($row);

		$billrun_key = Billrun_Util::getBillrunKey($row['unified_record_time']->sec);

		//TODO  change this to be configurable.
		$pricingData = array();
		$billrun_info = array();

		$usage_type = $row['usaget'];
		$volume = $row['usagev'];
		if ($row['type'] == 'tap3') {
			$usage_class_prefix = "intl_roam_";
		} else {
			$usage_class_prefix = "";
		}

		if (isset($volume)) {
			if ($row['type'] == 'credit') {
				$accessPrice = isset($rate['rates'][$usage_type]['access']) ? $rate['rates'][$usage_type]['access'] : 0;
				$pricingData = array($this->pricingField => $accessPrice + $this->getPriceByRates($rate['rates'][$usage_type]['rate'], $volume));
			} else {
				$pricingData = $this->updateSubscriberBalance(array($usage_class_prefix . $usage_type => $volume), $row, $billrun_key, $usage_type, $rate, $volume);
			}
			if (!$pricingData) { // balance wasn't found
				return false;
			}
			$vatable = (!(isset($rate['vatable']) && !$rate['vatable']) || (!isset($rate['vatable']) && !$this->vatable));
			if (!$billrun = Billrun_Billrun::updateBillrun($billrun_key, array($usage_type => $volume), $pricingData, $row, $vatable)) {
				return false;
			} else {
				$billrun_info['billrun_key'] = $billrun['billrun_key'];
				$billrun_info['billrun_ref'] = $billrun->createRef(Billrun_Factory::db()->billrunCollection());
			}
		} else {
			Billrun_Factory::log()->log("Line with stamp " . $row['stamp'] . " is missing volume information", Zend_Log::ALERT);
			return false;
		}

		$row->setRawData(array_merge($row->getRawData(), $pricingData));
		return true;
	}

	/**
	 * Get pricing data for a given rate / subcriber.
	 * @param int $volume The usage volume (seconds of call, count of SMS, bytes  of data)
	 * @param string $usageType The type  of the usage (call/sms/data)
	 * @param mixed $rate The rate of associated with the usage.
	 * @param mixed $subr the  subscriber that generated the usage.
	 * @return Array the 
	 */
	protected function getLinePricingData($volumeToPrice, $usageType, $rate, $sub_balance) {
		$typedRates = $rate['rates'][$usageType];
		$accessPrice = isset($typedRates['access']) ? $typedRates['access'] : 0;
		$subscriber_current_plan = $this->getBalancePlan($sub_balance);
		$plan = Billrun_Factory::plan(array('data' => $subscriber_current_plan));

		if ($plan->isRateInSubPlan($rate, $sub_balance, $usageType)) {
			$volumeToPrice = $volumeToPrice - $plan->usageLeftInPlan($sub_balance['balance'], $usageType);

			if ($volumeToPrice < 0) {
				$volumeToPrice = 0;
				//@TODO  check  if that actually the action we want once all the usage is in the plan...
				$accessPrice = 0;
			} else if ($volumeToPrice > 0) {
				$ret['over_plan'] = $volumeToPrice;
			}
		} else {
			$ret['out_plan'] = $volumeToPrice;
		}

		$price = $accessPrice + $this->getPriceByRates($typedRates['rate'], $volumeToPrice);
		//Billrun_Factory::log()->log("Rate : ".print_r($typedRates,1),  Zend_Log::DEBUG);
		$ret[$this->pricingField] = $price;


		return $ret;
	}

	/**
	 * Calculates the price for the given volume (w/o access price)
	 * @param array $rates_arr the "rate" array of a rate entry
	 * @param int $volume The usage volume (seconds of call, count of SMS, bytes  of data)
	 * @return int the calculated price
	 */
	protected function getPriceByRates($rates_arr, $volume) {
		$price = 0;
		foreach ($rates_arr as $currRate) {
			if (0 == $volume) { // volume could be negative if it's a refund amount
				break;
			}//break if no volume left to price.
			$volumeToPriceCurrentRating = ($volume - $currRate['to'] < 0) ? $volume : $currRate['to']; // get the volume that needed to be priced for the current rating
			if (isset($currRate['ceil'])) {
				$ceil = $currRate['ceil'];
			} else {
				$ceil = false;
			}
			if ($ceil) {
				$price += floatval(ceil($volumeToPriceCurrentRating / $currRate['interval']) * $currRate['price']); // actually price the usage volume by the current 	
			} else {
				$price += floatval($volumeToPriceCurrentRating / $currRate['interval'] * $currRate['price']); // actually price the usage volume by the current 
			}
			$volume = $volume - $volumeToPriceCurrentRating; //decressed the volume that was priced
		}
		return $price;
	}

	/**
	 * Update the subscriber balance for a given usage.
	 * @param array $counters the counters to update
	 * @param Mongodloid_Entity $row the input line
	 * @param string $billrun_key the billrun key at the row time
	 * @param string $usageType The type  of the usage (call/sms/data)
	 * @param mixed $rate The rate of associated with the usage.
	 * @param int $volume The usage volume (seconds of call, count of SMS, bytes  of data)
	 * @return mixed array with the pricing data on success, false otherwise
	 */
	protected function updateSubscriberBalance($counters, $row, $billrun_key, $usage_type, $rate, $volume) {
		$balance_unique_key = array('subscriber_id' => $row['subscriber_id'], 'billrun_key' => $billrun_key);
		$subscriber_balance = Billrun_Factory::balance($balance_unique_key);
		if (!$subscriber_balance || !$subscriber_balance->isValid()) {
			Billrun_Factory::log()->log("couldn't get balance for : " . print_r(array(
					'subscriber_id' => $row['subscriber_id'],
					'billrun_month' => $billrun_key
					), 1), Zend_Log::ALERT);
			return false;
		}

		$balances = Billrun_Factory::db()->balancesCollection();
		$subRaw = $subscriber_balance->getRawData();
		$stamp = strval($row['stamp']);
		if (isset($subRaw['tx']) && array_key_exists($stamp, $subRaw['tx'])) { // we're after a crash
			$pricingData = $subRaw['tx'][$stamp]; // restore the pricingData from before the crash
			return $pricingData;
		}
		$pricingData = $this->getLinePricingData($volume, $usage_type, $rate, $subscriber_balance);
		$balance_unique_key['billrun_month'] = $balance_unique_key['billrun_key'];
		unset($balance_unique_key['billrun_key']);
		$query = $balance_unique_key;
		$update = array();
		$update['$set']['tx'][$stamp] = $pricingData;
		foreach ($counters as $key => $value) {
			$old_usage = $subRaw['balance']['totals'][$key]['usagev'];
			$query['balance.totals.' . $key . '.usagev'] = $old_usage;
			$update['$set']['balance.totals.' . $key . '.usagev'] = $old_usage + $value;
		}
		$update['$set']['balance.cost'] = $subRaw['balance']['cost'] + $pricingData[$this->pricingField];
		$options = array('w' => 1);
		$ret = $balances->update($query, $update, $options);
		if (!($ret['ok'] && $ret['updatedExisting'])) { // failed because of different totals (could be that another server with another line raised the totals). Need to calculate pricingData from the beginning
			$pricingData = $this->updateSubscriberBalance($counters, $row, $billrun_key, $usage_type, $rate, $volume);
		}
		return $pricingData;
	}

	/**
	 * removes the transactions from the subscriber's balance to save space.
	 * @param type $row
	 */
	protected function removeBalanceTx($row) {
		$balances_coll = Billrun_Factory::db()->balancesCollection();
		$subscriber_id = $row['subscriber_id'];
		$billrun_key = Billrun_Util::getBillrunKey($row['unified_record_time']->sec);
		$query = array(
			'billrun_month' => $billrun_key,
			'subscriber_id' => $subscriber_id,
		);
		$values = array(
			'$unset' => array(
				'tx.' . $row['stamp'] => 1
			)
		);
		$balances_coll->update($query, $values);
	}

	/**
	 * @see Billrun_Calculator::getCalculatorQueueType
	 */
	static protected function getCalculatorQueueType() {
		return self::$type;
	}

	/**
	 * @see Billrun_Calculator::isLineLegitimate
	 */
	protected function isLineLegitimate($line) {
		return isset($line['customer_rate']) && $line['customer_rate'] !== false &&
			isset($line['subscriber_id']) && $line['subscriber_id'] !== false &&
			!isset($line['price_customer']) && $line['unified_record_time']->sec >= $this->billrun_lower_bound_timestamp;
	}

	/**
	 * 
	 */
	protected function setCalculatorTag() {
		parent::setCalculatorTag();
		foreach ($this->data as $item) {
			$this->removeBalanceTx($item); // we can safely remove the transactions after the lines have left the current queue
		}
	}

	protected function loadRates() {
		$rates_coll = Billrun_Factory::db()->ratesCollection();
		$rates = $rates_coll->query()->cursor();
		foreach ($rates as $rate) {
			$rate->collection($rates_coll);
			$this->rates[strval($rate->getId())] = $rate;
		}
	}

	protected function loadPlans() {
		$plans_coll = Billrun_Factory::db()->plansCollection();
		$plans = $plans_coll->query()->cursor();
		foreach ($plans as $plan) {
			$plan->collection($plans_coll);
			$this->plans[strval($plan->getId())] = $plan;
		}
	}

	/**
	 * gets an array which represents a db ref (includes '$ref' & '$id' keys)
	 * @param type $db_ref
	 */
	protected function getRowRate($row) {
		$raw_rate = $row->get('customer_rate', true);
		$id_str = strval($raw_rate['$id']);
		if (isset($this->rates[$id_str])) {
			return $this->rates[$id_str];
		} else {
			return $row->get('customer_rate', false);
		}
	}

	/**
	 * gets an array which represents a db ref (includes '$ref' & '$id' keys)
	 * @param type $db_ref
	 */
	protected function getBalancePlan($sub_balance) {
		$raw_plan = $sub_balance->get('current_plan', true);
		$id_str = strval($raw_plan['$id']);
		if (isset($this->plans[$id_str])) {
			return $this->plans[$id_str];
		} else {
			return $sub_balance->get('customer_rate', false);
		}
	}

}

