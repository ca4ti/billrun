<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Factory used to get a plan charge
 *
 * @package  Plans
 * @since    5.2
 */
class Billrun_Plans_Charge {
	
	/**
	 *
	 * @var Billrun_DataTypes_CycleTime
	 */
	protected $cycle;
	
	/**
	 * Get the charges
	 * @return array.
	 */
	public function charge($plan, Billrun_DataTypes_CycleTime $cycle) {
		$results = array();
		
		$chargeObj = $this->getChargeObject($plan);
		if(!$chargeObj) {
			return $results;
		}
		
		// Get the charge.
		$charge = $chargeObj->getPrice();
		
		if($charge !== null) {
			$results['charge'] = $charge;
		}
		
		// Check if has refund
		if($chargeObj instanceof Billrun_Plans_Charge_Upfront) {
			$refund = $chargeObj->getRefund($cycle);
			if($refund !== null) {
				$results['refund'] = $refund;
			}
		}
		
		return $results;
	}
	
	/**
	 * 
	 * @param type $plan
	 * @return Billrun_Plans_Charge_Base
	 */
	protected function getChargeObject($plan) {
		$object = __CLASS__;
		
		//Should  the  charge be  upfornt or  arrears
		$object .= !empty($plan['upfront']) ? '_Upfront' : '_Arrears';
		//Should the charge  be unprorated?
		$object .= !isset($plan['prorated']) || !empty($plan['prorated']) ? '' : '_Notprorated';		 
		//Should we  use  a diffrent peroid  then monthly charge?
		$object .= isset($plan['recurrence']['periodicity']) ? '_'. ucfirst($plan['recurrence']['periodicity']) 
															 : '_Month';
		// Check if exists
		if(!class_exists($object)) {
			Billrun_Factory::log("Could not find class: " . print_r($object,1));
			return null;
		}
		
		return new $object($plan);
	}
}
