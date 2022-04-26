<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing calculator class for SMS records received in realltime
 *
 * @package  calculator
 * @since 4.0
 */
class Billrun_Calculator_Rate_Smsrt extends Billrun_Calculator_Rate_Callrt {

	static protected $type = 'smsrt';

	/**
	 * Check if a given line should be rated.
	 * @param type $row
	 * @return type
	 */
	protected function shouldLineBeRated($row) {
		return true;
	}

	/**
	 * @see Billrun_Calculator::isLineLegitimate
	 */
	public function isLineLegitimate($line) {
		return $line['type'] == 'smsrt';
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
//		$called_number = $this->get_called_number($row);
//		$line_time = $row->get('urt');
//		$usage_type = $row->get('usaget');
		$this->setRowDataForQuery($row);
//		$matchedRate = $this->getRateByParams($called_number, $usage_type, $line_time);
		$matchedRate = $this->getRateByParams($row);

		return $matchedRate;
	}
	
	protected function getAggregateId() {
		return array(
			"_id" => '$_id',
			"pref" => '$params.prefix',
			"msc" => '$params.msc'
		);
	}
	
	protected function getRatesExistsQuery() {
		return array(
			'$exists' => true,
			'$ne' => array(),
		);
	}

}
