<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing calculator class for SMSc records
 *
 * @package  calculator
 */
class Billrun_Calculator_Rate_Smpp extends Billrun_Calculator_Rate_Sms {

	static protected $type = 'smpp';
	protected $legitimateValues = array(
//		'cause_of_terminition' => "100",
		'record_type' => '1',
	);

	public function __construct($options = array()) {
		parent::__construct($options);
		if (isset($options['calculator']['legitimate_values']) && $options['calculator']['legitimate_values']) {
			$this->legitimateValues = $options['calculator']['legitimate_values'];
		}
	}

	/**
	 * Check if a given line should be rated.
	 * @param type $row
	 * @return type
	 */
	protected function shouldLineBeRated($row) {
		foreach ($this->legitimateValues as $key => $value) {
			if (!(is_array($value) && in_array($row[$key], $value) || $row[$key] == $value )) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @see Billrun_Calculator::isLineLegitimate
	 */
	public function isLineLegitimate($line) {
		return $line['type'] == 'smpp';
	}

	/**
	 * Get the associate rate object for a given CDR line.
	 * @param $row the CDR line to get the for.
	 * @param $usage_type the CDR line  usage type (SMS/Call/etc..)
	 * @param $type CDR type
	 * @param $tariffCategory rate category
	 * @param $filters array of filters used to find the rate
	 * @return the Rate object that was loaded  from the DB  or false if the line shouldn't be rated.
	 */
	protected function getLineRate($row, $usaget, $type, $tariffCategory, $filters) {
		$matchedRate = false;
		if ($this->shouldLineBeRated($row)) {
			$called_number = $this->extractNumber($row);
			$line_time = $row['urt'];
			if (isset($this->rates[$called_number])) {
				foreach ($this->rates[$called_number] as $rate) {
					if (isset($rate['rates'][$row['usaget']])) {
						if ($rate['from'] <= $line_time && $rate['to'] >= $line_time) {
							$matchedRate = $rate;
							break;
						}
					}
				}
			}
		}
		return $matchedRate;
	}

}
