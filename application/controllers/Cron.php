<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing cron controller class
 * Used for is alive checks
 * 
 * @package  Controller
 * @since    2.8
 */
class CronController extends Yaf_Controller_Abstract {

	protected $mailer;
	protected $smser;

	public function init() {
		Billrun_Factory::log("BillRun Cron is running", Zend_Log::INFO);
		$this->smser = Billrun_Factory::smser();
		$this->mailer = Billrun_Factory::mailer();
	}

	/**
	 * main action to do basic tests
	 * 
	 * @return void
	 */
	public function indexAction() {
		// do nothing
	}

	public function receiveAction() {
		Billrun_Factory::log("Check receive", Zend_Log::INFO);
		$alerts = $this->locate(('receive'));
		if (!empty($alerts)) {
			$this->sendAlerts('receive', $alerts);
		}
	}

	public function processAction() {
		Billrun_Factory::log("Check process", Zend_Log::INFO);
		$alerts = $this->locate(('process'));
		if (!empty($alerts)) {
			$this->sendAlerts('process', $alerts);
		}
	}

	protected function locate($process) {
		$logsModel = new LogModel();
		$empty_types = array();
		$filter_field = Billrun_Factory::config()->getConfigValue('cron.log.' . $process . '.field');
		$types = Billrun_Factory::config()->getConfigValue('cron.log.' . $process . '.types', array());
		foreach ($types as $type => $timediff) {
			$query = array(
				'source' => $type,
				$filter_field => array('$gt' => date('Y-m-d H:i:s', (time() - $timediff)))
			);
			$results = $logsModel->getData($query)->current();
			if ($results->isEmpty()) {
				$empty_types[] = $type;
			}
		}
		return $empty_types;
	}

	protected function sendAlerts($process, $empty_types) {
		if (empty($empty_types)) {
			return ;
		}
		$events_string = implode(', ', $empty_types);
		Billrun_Factory::log("Send alerts for " . $process, Zend_Log::INFO);
		Billrun_Factory::log("Events types: " . $events_string, Zend_Log::INFO);
		$actions = Billrun_Factory::config()->getConfigValue('cron.log.' . $process . '.actions', array());
		if (isset($actions['email'])) {
			//'GT BillRun - file did not %s: %s'
			if (isset($actions['email']['recipients'])) {
				$recipients = $actions['email']['recipients'];
			} else {
				$recipients = $this->getEmailsList();
			}
			$this->mailer->addTo($recipients);
			$this->mailer->setSubject($actions['email']['subject']);
			$message = sprintf($actions['email']['message'], $process, $events_string);
			$this->mailer->setBodyText($message);
			$this->mailer->send();
		}
		if (isset($actions['sms'])) {
			//'GT BillRun - file types did not %s: %s'
			$message = sprintf($actions['sms']['message'], $process, $events_string);
			if (isset($actions['sms']['recipients'])) {
				$recipients = $actions['sms']['recipients'];
			} else {
				$recipients = $this->getSmsList();
			}
			$this->smser->send($message, $recipients);
		}
	}

	public function autoRenewServicesAction() {
		$handler = new Billrun_Autorenew_Handler();
		$handler->autoRenewServices();
	}

	public function nonRecurringAction() {
		$this->cancelSlownessByEndedNonRecurringPlans();
	}

	/**
	 * @todo Not completed
	 */
	public function cancelSlownessByEndedNonRecurringPlans() {
		$balancesCollection = Billrun_Factory::db()->balancesCollection();
		$sort = array(
			'$sort' => array(
				'to' => -1,
			),
		);
		$group = array(
			'$group' => array(
				'_id' => '$sid',
				'to' => array(
					'$first' => '$to',
				),
				'charging_type' => array(
					'$first' => '$charging_type',
				)
			),
		);
		$match = array(
			'$match' => array(
				'charging_type' => 'prepaid',
				'to' => array('$lt' => new MongoDate()),
			),
		);
		$project = array(
			'$project' => array(
				'sid' => '$_id',
			),
		);
		$balances = $balancesCollection->aggregate($sort, $group, $match, $project);
		$sids = array_map(function($doc) {
			return $doc['sid'];
		}, iterator_to_array($balances));
	}

	/**
	 * method to add output to the stream and log
	 * 
	 * @param string $content the content to add
	 */
	public function addOutput($content) {
		Billrun_Log::getInstance()->log($content, Zend_Log::INFO);
	}

	protected function getEmailsList() {
		return Billrun_Factory::config()->getConfigValue('cron.log.mail_recipients', array());
	}

	protected function getSmsList() {
		return Billrun_Factory::config()->getConfigValue('cron.log.sms_recipients', array());
	}

}
