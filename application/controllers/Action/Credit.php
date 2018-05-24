<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */
require_once APPLICATION_PATH . '/application/controllers/Action/Api.php';

/**
 * Credit action class
 *
 * @package  Action
 * @since    0.5
 */
class CreditAction extends ApiAction {
	use Billrun_Traits_Api_UserPermissions;
	
	protected $request = null;
	protected $event = null;
	protected $status = 1;
	protected $desc = 'success';
	
	/**
	 * method to execute the refund
	 * it's called automatically by the api main controller
	 */
	public function execute() {
		$this->allowed();
		
		Billrun_Factory::log("Execute credit", Zend_Log::INFO);
		$this->request = $this->getRequest()->getRequest(); // supports GET / POST requests;
		$this->setEventData();
		$this->process();
		return $this->response();
	}
	
	protected function setEventData() {
		$this->event = $this->parse($this->request);
		$this->event['source'] = 'credit';
		$this->event['rand'] = rand(1, 1000000);
		$this->event['stamp'] = Billrun_Util::generateArrayStamp($this->event);
	}
	
	/**
	 * Runs Billrun process
	 * 
	 * @return type Data generated by process
	 */
	protected function process() {
		Billrun_Factory::log("Process of credit starting", Zend_Log::INFO);
		$options = array(
			'type' => 'Credit',
			'parser' => 'none',
		);
		$processor = Billrun_Processor::getInstance($options);
		$processor->addDataRow($this->event);
		if ($processor->process() === false) {
			$this->status = 0;
			$this->desc = 'Processor error';
		}
		Billrun_Factory::log("Process of credit ended", Zend_Log::INFO);
//		return current($processor->getAllLines());
	}
	
	protected function parse($credit_row) {
		$ret = $this->validateFields($credit_row);
		$ret['skip_calc'] = $this->getSkipCalcs($ret);
		$ret['process_time'] = new MongoDate();
		$ret['usaget'] = $this->getCreditUsaget($ret);
		$rate = Billrun_Rates_Util::getRateByName($credit_row['rate']);
		if ($rate->isEmpty()) {
			throw new Exception("Rate doesn't exist");
		}
		$ret['credit'] = array(
			'usagev' => $ret['usagev'],
			'credit_by' => 'rate',
			'rate' => $ret['rate'],
			'usaget' => $this->getUsageTypeFromRate($rate)
		);
		if ($this->isCreditByPrice($ret)) {
			$this->parseCreditByPrice($ret);
		} else {
			$this->parseCreditByUsagev($ret);
		}
		return $ret;
	}
	
	protected function parseCreditByPrice(&$row) {
		$row['credit']['aprice'] = $row['aprice'];
		$row['aprice'] = $row['aprice'] * $row['usagev'];
		$row['prepriced'] = true;
	}
	
	protected function parseCreditByUsagev(&$row) {
		$row['usagev'] = 1;
		$row['prepriced'] = false;
	}
	
	protected function isCreditByPrice($row) {
		return isset($row['aprice']);
	}
	
	protected function getCreditUsaget($row) {
		if (!isset($row['aprice'])) {
			return 'refund';
		}
		return ($row['aprice'] >= 0 ? 'charge' : 'refund');
	}
	
	protected function getSkipCalcs($row) {
		$skipArray = array('unify');
		if(!empty($row['aid']) && $row['sid'] == '0') { // TODO: this is a hack for credit on account level, needs to be fixed in customer calculator
            $skipArray[] = 'customer';
		}
		return $skipArray;
	}
	
	protected function validateFields($credit_row) {
		$fields = Billrun_Factory::config()->getConfigValue('credit.fields', array());
		$ret = array();
		
		foreach ($fields as $fieldName => $field) {
			if (isset($field['mandatory']) && $field['mandatory']) {
				if (isset($credit_row[$fieldName])) {
					$ret[$fieldName] = $credit_row[$fieldName];
				} else if (isset($field['alternative_fields']) && is_array($field['alternative_fields'])) {
					foreach ($field['alternative_fields'] as $alternativeFieldName) {
						if (isset($credit_row[$alternativeFieldName])) {
							$ret[$fieldName] = $credit_row[$alternativeFieldName];
							break;
						}
						$this->setError('Following field/s are missing: one of: (' . implode(', ', array_merge(array($fieldName), $field['alternative_fields']))) . ')';
					}
				} else {
					$this->setError('Following field/s are missing: ' . $fieldName);
				}
			} else if (isset($credit_row[$fieldName])) { // not mandatory field
				$ret[$fieldName] = $credit_row[$fieldName];
			} else {
				continue;
			}
			
			if (!empty($field['validator'])) {
				$validator = Billrun_TypeValidator_Manager::getValidator($field['validator']);
				if (!$validator) {
					Billrun_Factory::log('Cannot get validator for field ' .  $fieldName . '. Details: ' . print_r($field, 1));
					$this->setError('General error');
				}
				$params = isset($field['validator_params']) ? $field['validator_params'] : array();
				if (!$validator->validate($ret[$fieldName], $params)) {
					$this->setError('Field ' . $fieldName . ' should be of type ' . ucfirst($field['validator']));
				}
			}
			
			if (!empty($field['conversionMethod'])) {
				$ret[$fieldName] = call_user_func($field['conversionMethod'], $ret[$fieldName]);
			}
		}
		
		return $ret;
	}
	
	protected function proccess() {
		
	}
	
	protected function response() {
		$this->getController()->setOutput(array(
			array(
				'status' => $this->status,
				'desc' => $this->desc,
				'stamp' => $this->event['stamp'],
				'input' => $this->request,
			)
		));
		Billrun_Factory::log("done credit line " . $this->event['stamp'], Zend_Log::INFO);
		return true;
	}

	protected function getPermissionLevel() {
		return Billrun_Traits_Api_IUserPermissions::PERMISSION_WRITE;
	}

	protected function getUsageTypeFromRate($rate) {
		return key($rate['rates']);
	}

}
