<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
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
		$empty_types = array();
		$settings = array(
			'log' => array(
				'filter_field_type' => 'source',
				'filter_time' => array('$gt' => ''),
			),
			'lines' => array(
				'filter_field_type' => 'type',
				'filter_time' => array('$gt' => ''),
			),
		);
		foreach ($settings as $c => $c_settings) {
			$modelName = $c . 'Model';
			$model = new $modelName();
			$filter_field = Billrun_Factory::config()->getConfigValue('cron.' . $c . '.' . $process . '.field');
			$types = Billrun_Factory::config()->getConfigValue('cron.' . $c . '.' . $process . '.types', array());
			if (empty($filter_field) || empty($types)) {
				continue;
			}
			foreach ($types as $type => $timediff) {
				if ($c == 'log') {
					$c_settings['filter_time']['$gt'] = date('Y-m-d H:i:s', (time() - $timediff));
				} else {
					$c_settings['filter_time']['$gt'] = new MongoDate(time() - $timediff);
				}
				
				$query = array(
					$c_settings['filter_field_type'] => $type,
					$filter_field => $c_settings['filter_time']
				);
				
				$results = $model->setSize(1)->getData($query)->current();
				
				if ($results->isEmpty()) {
					$empty_types[$c] = $type;
				}
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
