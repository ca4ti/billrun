<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing aggregator class for customers records
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Aggregator_Customer extends Billrun_Cycle_Aggregator {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = 'customer';

	/**
	 * 
	 * @var int
	 */
	protected $page = null;

	/**
	 * 
	 * @var int
	 */
	protected $size = 200;

	/**
	 *
	 * @var Mongodloid_Collection
	 */
	protected $plans = null;

	/**
	 *
	 * @var Mongodloid_Collection
	 */
	protected $lines = null;

	/**
	 *
	 * @var Mongodloid_Collection
	 */
	protected $billingCycle = null;

	/**
	 *
	 * @var Mongodloid_Collection
	 */
	protected $billrunCol = null;

	/**
	 *
	 * @var int invoice id to start from
	 */
	protected $min_invoice_id = 101;

	protected $rates;
	protected $testAcc = false;

	/**
	 * Memory limit in bytes, after which the aggregation is stopped. Set to -1 for no limit.
	 * @var int 
	 */
	protected $memory_limit = -1;

	/**
	 * Accounts to override
	 * @var array
	 */
	protected $forceAccountIds = array();

	/**
	 * the amount of account lines to preload from the db at a time.
	 * @param int $bulkAccountPreload
	 */
	protected $bulkAccountPreload = 10;

	/**
	 * the account ids that were successfully aggregated
	 * @var array
	 */
	protected $successfulAccounts = array();
	
	/**
	 *
	 * @var int flag to represent if recreate_invoices 
	 */
	
	protected $recreateInvoices = null;
	
	/**
	 * Manager for the aggregate subscriber logic.
	 * @var Billrun_Aggregator_Subscriber_Manager
	 */
	protected $subscriberAggregator;
	
	/**
	 *
	 * @var Billrun_DataTypes_Billrun
	 */
	protected $billrun;
	
	protected $accounts;
	
	/**
	 * True if Cycle process
	 * @var boolean
	 */
	protected $isCycle = false;
	
	/**
	 * If true then we can load the data.
	 * @var boolean
	 */
	protected $canLoad = false;
	
	/**
	 * If true need to override data in billrun collection, 
	 * @var boolean
	 */
	protected $overrideMode;
	/**
	 *  Is the run is fake (for example to get a current balance in the middle of the month)
	 */
	protected $fakeCycle = false;
	
	
	public function __construct($options = array()) {
		$this->isValid = false;
		parent::__construct($options);

		ini_set('mongo.native_long', 1); //Set mongo  to use  long int  for  all aggregated integer data.
		
		if (isset($options['aggregator']['recreate_invoices']) && $options['aggregator']['recreate_invoices']) {
			$this->recreateInvoices = $options['aggregator']['recreate_invoices'];
		}
		
		if (isset($options['aggregator']['page'])) {
			$this->page = (int)$options['aggregator']['page'];
		}
		
		$this->buildBillrun($options);
		
		if (isset($options['aggregator']['test_accounts'])) {
			$this->testAcc = $options['aggregator']['test_accounts'];
		}

		if (isset($options['aggregator']['memory_limit_in_mb'])) {
			if ($options['aggregator']['memory_limit_in_mb'] > -1) {
				$this->memory_limit = $options['aggregator']['memory_limit_in_mb'] * 1048576;
			} else {
				$this->memory_limit = $options['aggregator']['memory_limit_in_mb'];
			}
		}

		$this->size = (int) Billrun_Util::getFieldVal($options['aggregator']['size'],$this->size);
		//Override the configuration size settings with  the  size  provided in the arguments.
		$this->size = (int) Billrun_Util::getFieldVal($options['size'],$this->size);
		
		$this->bulkAccountPreload = (int) Billrun_Util::getFieldVal($options['aggregator']['bulk_account_preload'],$this->bulkAccountPreload);		
		$this->min_invoice_id = (int) Billrun_Util::getFieldVal($options['aggregator']['min_invoice_id'],$this->min_invoice_id);
		$this->forceAccountIds = Billrun_Util::getFieldVal($options['aggregator']['force_accounts'], $this->forceAccountIds);
		$this->fakeCycle = Billrun_Util::getFieldVal($options['aggregator']['fake_cycle'], Billrun_Util::getFieldVal($options['fake_cycle'], $this->fakeCycle));
		
		if (isset($options['action']) && $options['action'] == 'cycle') {
			$this->billingCycle = Billrun_Factory::db()->billing_cycleCollection();
			$this->isCycle = true;
		}
				
		if (!$this->shouldRunAggregate($options['stamp'])) {
			$this->_controller->addOutput("Can't run aggregate before end of billing cycle");
			return;
		}
				
		$this->plans = Billrun_Factory::db()->plansCollection();
		$this->lines = Billrun_Factory::db()->linesCollection();
		$this->billrunCol = Billrun_Factory::db()->billrunCollection();
		$this->overrideMode = Billrun_Factory::config()->getConfigValue('customer.aggregator.override_mode', true);

		if (!$this->recreateInvoices && $this->isCycle){
			$pageResult = $this->getPage();
			if ($pageResult === FALSE) {
				return;
			}
			$this->page = $pageResult;
		}

		$aggregateOptions = array(
			'passthrough_fields' => Billrun_Factory::config()->getConfigValue(static::$type . '.aggregator.passthrough_data', array())
		);
		// If the accounts should not be overriden, filter the existing ones before.
		if (!$this->overrideMode) {
			// Get the aid exclusion query
			$aggregateOptions['exclusion_query'] = $this->billrun->existingAccountsQuery();
		}
		//This class will define the account/subscriber/plans aggregation logic for the cycle
		$this->aggregationLogic = new Billrun_Cycle_AggregatePipeline($aggregateOptions);
		
		$this->isValid = true;
	}
	
	public function getCycle() {
		return new Billrun_DataTypes_CycleTime($this->getStamp());
	}
	
	public function getPlans() {
		if(empty($this->plansCache)) {
			$pipelines[] = $this->aggregationLogic->getCycleDateMatchPipeline($this->getCycle());
			$pipelines[] = $this->aggregationLogic->getPlansProjectPipeline();
			$coll = Billrun_Factory::db()->plansCollection();
			$res = $this->aggregatePipelines($pipelines,$coll);
			$this->plansCache =  $this->toKeyHashedArray($res,'plan');
		}

		return $this->plansCache;
	}
	
	public function getServices() {
		if(empty($this->servicesCache)) {
			$pipelines[] = $this->aggregationLogic->getCycleDateMatchPipeline($this->getCycle());
			$coll = Billrun_Factory::db()->servicesCollection();
			$res =  $this->aggregatePipelines($pipelines,$coll);
			$this->servicesCache = $this->toKeyHashedArray($res , 'name');
		}
		return $this->servicesCache;
	}
	
	public function getRates() {
		if(empty($this->ratesCache)) {
			$pipelines[] = $this->aggregationLogic->getCycleDateMatchPipeline($this->getCycle());
			$coll = Billrun_Factory::db()->ratesCollection();
			$res = $this->aggregatePipelines($pipelines,$coll);
			$this->ratesCache = $this->toKeyHashedArray($res, '_id');
		}
		return $this->ratesCache;
	}

	public static function removeBeforeAggregate($billrunKey, $aids = array()) {
		$linesColl = Billrun_Factory::db()->linesCollection();
		$billrunColl = Billrun_Factory::db()->billrunCollection();
		$billrunQuery = array('billrun_key' => $billrunKey, 'billed' => array('$ne' => 1));
		$notBilled = $billrunColl->query($billrunQuery)->cursor();
		$notBilledAids = array();
		foreach ($notBilled as $account) {
			$notBilledAids[] = $account['aid'];
		}
		if (empty($aids)) {
			$linesRemoveQuery = array('aid' => array('$in' => $notBilledAids), 'billrun' => $billrunKey, 'type' => array('$in' => array('service', 'flat')));
			$billrunRemoveQuery = $billrunQuery;
		} else {
			$aids =array_values(array_intersect($notBilledAids, $aids));
			$linesRemoveQuery = array(	'aid' => array('$in' => $aids),
										'billrun' => $billrunKey, 
										'$or' => array(
											array( 'type' => array('$in' => array('service', 'flat')) ),
											array( 'type'=>'credit','usaget'=>'discount' )
											));
			$billrunRemoveQuery = array('aid' => array('$in' => $aids), 'billrun_key' => $billrunKey, 'billed' => array('$ne' => 1));
		}
		$linesColl->remove($linesRemoveQuery);
		$billrunColl->remove($billrunRemoveQuery);
	}

	
	//--------------------------------------------------------------------
	
	protected function buildBillrun($options) {
		if (isset($options['stamp']) && $options['stamp']) {
			$this->stamp = $options['stamp'];  
			// TODO: Why is there a check for "isBillrunKey"??
		} else if (isset($options['aggregator']['stamp']) && (Billrun_Util::isBillrunKey($options['aggregator']['stamp']))) {
			$this->stamp = $options['aggregator']['stamp'];
		} else {
			$next_billrun_key = Billrun_Billrun::getBillrunKeyByTimestamp(time());
			$current_billrun_key = Billrun_Billrun::getPreviousBillrunKey($next_billrun_key);
  			$this->stamp = $current_billrun_key;
		}
		$this->billrun = new Billrun_DataTypes_Billrun($this->stamp);
	}
	
	protected function beforeLoad() {
		Billrun_Factory::log("Loading page " . $this->page . " of size " . $this->size, Zend_Log::INFO);
		$this->canLoad = true;
	}
	
	/**
	 * load the data to aggregate
	 */
	protected function loadData() {
		if(!$this->canLoad) {
			return;
		}
		$this->canLoad = false;

		$rawResults = $this->loadRawData($this->getCycle());
		$data = $rawResults['data'];
		
		$accounts = $this->parseToAccounts($data, $this);
		
		return $accounts;
	}
	
	protected function afterLoad($data) {
		if (!$this->recreateInvoices && $this->isCycle){			
			$this->handleInvoices($data);
		}

		Billrun_Factory::log("Acount entities loaded: " . count($data), Zend_Log::INFO);

		Billrun_Factory::dispatcher()->trigger('afterAggregatorLoadData', array('aggregator' => $this));
		
		if ($this->bulkAccountPreload) {
			$this->clearForAccountPreload($data);
		}
	}

	/**
	 * Map a regular array to an array the is  keyed by a given field in the array records
	 */
	protected function toKeyHashedArray($array, $indxKey) {
		$hashed = array();
		foreach ($array as $value) {
			$key = strval($value[$indxKey]);
			$translatedDates = Billrun_Utils_Mongo::convertRecordMongoDatetimeFields($value);
			$hashed[$key] = $translatedDates;
		}
		return $hashed;
	}

	/**
	 * Get the raw data
	 * @param Billrun_DataTypes_CycleTime $cycle
	 * @return array of raw data
	 */
	protected function loadRawData($cycle) {
		$mongoCycle = new Billrun_DataTypes_MongoCycleTime($cycle);
		
		$result = array();
		if (!$this->forceAccountIds) {
			$data = $this->aggregateMongo($mongoCycle, $this->page, $this->size);
			$result['data'] = $data;
			return $result;
		}
		
		$data = array();
		foreach ($this->forceAccountIds as $account_id) {
			if (Billrun_Bill_Invoice::isInvoiceConfirmed($account_id, $mongoCycle->key())) {
				continue;
			}
			$data = array_merge($data, $this->aggregateMongo($mongoCycle, 0, 1, $account_id));
		}
		$result['data'] = $data;
		return $result;
	}
	
	/**
	 * 
	 * @param type $outputArr
	 * @param Billrun_DataTypes_CycleTime $cycle
	 * @param array $plans
	 * @param array $rates
	 * @return \Billrun_Cycle_Account
	 */
	protected function parseToAccounts($outputArr) {
		$accounts = array();
		$billrunData = array(
			'billrun_key' => $this->getCycle()->key(),
			'autoload' => !empty($this->overrideMode)
			);
		
		foreach ($outputArr as $subscriberPlan) {
			$aid = (string)$subscriberPlan['id']['aid'];
			$type = $subscriberPlan['id']['type'];
			
			if ($type === 'account') {
				$accounts[$aid]['attributes'] = $this->constructAccountAttributes($subscriberPlan);
			} else if (($type === 'subscriber')) {
				$raw = $subscriberPlan['id'];
				$raw['plans'] = $subscriberPlan['plan_dates'];
				foreach($subscriberPlan['plan_dates'] as $dates) {
					$raw['from'] = min($dates['from']->sec,  Billrun_Util::getFieldVal($raw['from'],PHP_INT_MAX) );
					$raw['to'] = max($dates['to']->sec,Billrun_Util::getFieldVal($raw['to'],0) );
				}
				$accounts[$aid]['subscribers'][$raw['sid']][] = $raw;
			} else {
				Billrun_Factory::log('Recevied a record form cycle aggregate with unknown type.',Zend_Log::ERR);
			}
		}
		
		$accountsToRet = array();
		foreach($accounts as $aid => $accountData) {
			$accountToAdd = $this->getAccount($billrunData, $accountData, intval($aid));
			if($accountToAdd) {
				$accountsToRet[] = $accountToAdd;
			}
		}
				
		return $accountsToRet;
	}
	
	/**
	 * Returns a single cycle account instnace.
	 * If the account already exists in billrun, returns false..
	 * @param array $billrunData
	 * @param int $aid
	 * @param Billrun_DataTypes_CycleTime $cycle
	 * @param array $plans
	 * @param array $services
	 * @param array $rates
	 * @return Billrun_Cycle_Account | false 
	 */
	protected function getAccount($billrunData, $accountData, $aid) {
		// Handle no subscribers.
		if(!isset($accountData['subscribers'])) {
			$accountData['subscribers'] = array();
		}
		

		$billrunData['aid'] = $aid;
		$billrunData['attributes'] = $accountData['attributes'];
		$billrunData['override_mode'] = $this->overrideMode;
		$invoice = new Billrun_Cycle_Account_Invoice($billrunData);

		// Check if already exists.
		if(!$this->overrideMode && $invoice->exists()) {
			Billrun_Factory::log("Billrun " . $this->getCycle()->key() . " already exists for account " . $aid, Zend_Log::ALERT);
			return false;
		} 
		
		$accountData['invoice'] = $invoice;
		return new Billrun_Cycle_Account($accountData, $this);
	}
	
	/**
	 * This function constructs the account attributes for a billrun cycle account
	 * @param array $subscriberPlan - Current subscriber plan.
	 */
	protected function constructAccountAttributes($subscriberPlan) {
		$firstname = $subscriberPlan['id']['first_name'];
		$lastname = $subscriberPlan['id']['last_name'];
		$paymentDetails = 'No payment details';
		if (isset($subscriberPlan['card_token']) && !empty($token = $subscriberPlan['card_token'])) {
			$paymentDetails = Billrun_Util::getTokenToDisplay($token);
		}
		//Add basic account data
		$accountData = array(
			'firstname' => $firstname,
			'lastname' => $lastname,
			'fullname' => $firstname . ' ' . $lastname,
			'address' => $subscriberPlan['id']['address'],
			'email' => $subscriberPlan['id']['email'],
			'payment_details' => $paymentDetails,
		);
		
		foreach(Billrun_Factory::config()->getConfigValue(static::$type.'.aggregator.passthrough_data',array()) as  $invoiceField => $subscriberField) {
			if(isset($subscriberPlan['passthrough'][$subscriberField])) {
				$accountData[$invoiceField] = $subscriberPlan['passthrough'][$subscriberField];
			}
		}
		return  $accountData;
	}
	
	/**
	 * 
	 * @param type $data
	 */
	protected function handleInvoices($data) {
		$query = array('billrun_key' => $this->stamp, 'page_number' => (int)$this->page, 'page_size' => $this->size);
		$dataCount = count($data);
		$update = array('$set' => array('count' => $dataCount));
		$this->billingCycle->update($query, $update);
	}
	
	/**
	 * 
	 * @param type $data
	 * @return type
	 */
	protected function clearForAccountPreload($data) {
		Billrun_Factory::log('loading accounts that will be needed to be preloaded...', Zend_Log::INFO);
		$dataKeys = array_keys($data);
		//$existingAccounts = array();			
		foreach ($dataKeys as $key => $aid) {
			if (!$this->overrideMode && $this->billrun->exists($aid)) {
				unset($dataKeys[$key]);
			}
		}
		return $dataKeys;
	}
	
	protected function beforeAggregate($accounts) {
		if ($this->overrideMode && $accounts) {
			$aids = array();
			foreach ($accounts as $account) {
				$aids[] = $account->getInvoice()->getAid();
			}
			$billrunKey = $this->billrun->key();
			self::removeBeforeAggregate($billrunKey, $aids);
		}
	}

	
	protected function aggregatedEntity($aggregatedResults, $aggregatedEntity) {
			Billrun_Factory::dispatcher()->trigger('beforeAggregateAccount', array($aggregatedEntity));
			$aggregatedEntity->writeInvoice($this->min_invoice_id);
			if(!$this->fakeCycle) {
				Billrun_Factory::log('Writing the invoice data to DB for AID : '.$aggregatedEntity->getInvoice()->getAid());
				//Save Account services / plans
				$this->saveLines($aggregatedResults);
				//Save Account discounts.
				$this->saveLines($aggregatedEntity->getAppliedDiscounts());
				//Save the billrun document
				$aggregatedEntity->save();
			}
			Billrun_Factory::dispatcher()->trigger('afterAggregateAccount', array($aggregatedEntity, $aggregatedResults));
			return $aggregatedResults;
	}
	
	protected function afterAggregate($results) {
		
		
		$end_msg = "Finished iterating page {$this->page} of size {$this->size}. Memory usage is " . memory_get_usage() / 1048576 . " MB\n";
		$end_msg .="Processed " . (count($results)) . " accounts";
		Billrun_Factory::log($end_msg, Zend_Log::INFO);
		$this->sendEndMail($end_msg);

		if (!$this->recreateInvoices && $this->isCycle){
			$cycleQuery = array('billrun_key' => $this->stamp, 'page_number' => $this->page, 'page_size' => $this->size);
			$cycleUpdate = array('$set' => array('end_time' => new MongoDate()));
			$this->billingCycle->update($cycleQuery, $cycleUpdate);
		}
		if(Billrun_Billingcycle::hasCycleEnded($this->getCycle()->key(), $this->size)) {
			Billrun_Factory::dispatcher()->trigger('afterCycleDone', array($this->data, $this->getCycle(), &$this));
		}
		return $results;
	}
	
	protected function sendEndMail($msg) {
		$recipients = Billrun_Factory::config()->getConfigValue('log.email.writerParams.to');
		if ($recipients) {
			Billrun_Util::sendMail("BillRun customer aggregate page finished", $msg, $recipients);
		}
	}

	/**
	 * Aggregate mongo with a query
	 * @param Billrun_DataTypes_MongoCycleTime $cycle - Current cycle time
	 * @param int $page - page
	 * @param int $size - size
	 * @param int $aid - Account id, null by deafault
	 * @return array 
	 */
	protected function aggregateMongo($cycle, $page, $size, $aid = null) {
		$pipelines = $this->aggregationLogic->getCustomerAggregationForPage($cycle, $page, $size, $aid);
		$collection = Billrun_Factory::db()->subscribersCollection();
		return $this->aggregatePipelines($pipelines, $collection);
	}

	protected function aggregatePipelines(array $pipelines, Mongodloid_Collection $collection) {
		$cursor = $collection->aggregate($pipelines);
		$results = iterator_to_array($cursor);
		if (!is_array($results) || empty($results) ||
			(isset($results['success']) && ($results['success'] === FALSE))) {
			return array();
		} 	
		return $results;
	}

	protected function saveLines($results) {
		if(empty($results)) {
			Billrun_Factory::log("Empty aggregate customer results, skipping save");
			return;
		}
		$linesCol = Billrun_Factory::db()->linesCollection();
		try {	
			$linesCol->batchInsert($results);
		} catch (Exception $e) {
			Billrun_Factory::log($e->getMessage(), Zend_Log::ALERT);
			foreach ($results as $line) {
				try {
					$linesCol->insert($line);
				} catch (Exception $ex) {
					Billrun_Factory::log($ex->getMessage(), Zend_Log::ALERT);
				}
			}
		}
	}
	
	/**
	 * Finding which page is next in the biiling cycle
	 * @param the number of max tries to get the next page in the billing cycle
	 * @return number of the next page that should be taken
	 */
	protected function getPage($retries = 100) {	
		
		$zeroPages = Billrun_Factory::config()->getConfigValue('customer.aggregator.zero_pages_limit');
		if (Billrun_Billingcycle::isBillingCycleOver($this->billingCycle, $this->stamp, $this->size, $zeroPages) === TRUE){
			 return false;
		}
		$pagerConfiguration = array(
			'maxProcesses' => Billrun_Factory::config()->getConfigValue('customer.aggregator.processes_per_host_limit',10),
			'size' => $this->size,
			'identifingQuery' => array('billrun_key' => $this->stamp),
			
		);
		$pager = new Billrun_Cycle_Paging( $pagerConfiguration, $this->billingCycle );
		
		return $pager->getPage($zeroPages, $retries);
	}
	
	protected function shouldRunAggregate($stamp) {
		$allowPrematureRun = (int)Billrun_Factory::config()->getConfigValue('cycle.allow_premature_run', false);
		if (!$allowPrematureRun && time() < Billrun_Billingcycle::getEndTime($stamp)) {
			return false;
		}
		return true;
	}	
}
