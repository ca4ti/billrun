<?php

/**
 * @package	Billing
 * @copyright	Copyright (C) 2012-2017 BillRun Technologies Ltd. All rights reserved.
 * @license	GNU Affero General Public License Version 4; see LICENSE.txt
 */

Yaf_Loader::getInstance(APPLICATION_PATH . '/application/modules/Billapi')->registerLocalNamespace("Models");

/**
 * Abstract helper class for an auto renew record
 *
 */
abstract class Billrun_Autorenew_Record {
	
	protected $record = null;
	
	public function __construct($record) {
		$this->record = $record;
	}

	/**
	 * Get the next renew date for this recurring plan.
	 * @return MongoDate Next update date.
	 */
	protected abstract function getNextRenewDate();
	
	public function autoRenew() {
		Billrun_Factory::log('running auto renew for auto renew record: ' . $this->getRecordId(), Billrun_Log::DEBUG);
		if ($this->updateBalance()) {
			$ret = $this->updateAutoRenewRecord();
			if (!$ret || !$ret['ok'] || !$ret['nModified']) {
				Billrun_Factory::log('cannot update auto renew record after balance update: ' . $this->getRecordId(), Billrun_Log::ERR);
			}
		}
		Billrun_Factory::log('finish running auto renew for auto renew record: ' . $this->getRecordId(), Billrun_Log::DEBUG);
	}
	
	/**
	 * gets the record Mongo ID
	 * 
	 * @return MongoId
	 */
	protected function getRecordId() {
		return $this->record->getId()->getMongoID();
	}
	
	protected function getUpdateRequestQuery() {
		$query = array(
			'charging_plan' => $this->record['bucket_group'],
		);
		if (isset($this->record['sid'])) {
			$query['sid'] = $this->record['sid'];
		}
		if (isset($this->record['aid'])) {
			$query['aid'] = $this->record['aid'];
		}
		return $query;
	}
	
	protected function getUpdateRequestParams() {
		return array(
			'request' => array(
				'action' => 'update',
				'query' => json_encode($this->getUpdateRequestQuery()),
			),
			'collection' => 'balances',
		);
	}
	
	protected function updateBalance() {
		$params = $this->getUpdateRequestParams();
		$model = new Models_Balances($params);
		return $model->update();
	}
	
	protected function updateAutoRenewRecord() {
		$query = array(
			'_id' => $this->getRecordId(),
		);
		$update = array(
			'$set' => array(
				'next_renew' => $this->getNextRenewDate(),
				'last_renew' => new MongoDate(),
			),
			'$inc' => array(
				'cycles_remaining' => - 1,
			),
		);
		return Billrun_Factory::db()->autorenewCollection()->update($query, $update);
	}
}
