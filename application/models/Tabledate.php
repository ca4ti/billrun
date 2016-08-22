<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Table Date model class to pull data from database for collections with from/to fields
 *
 * @package  Models
 * @subpackage Table
 * @since    0.5
 */
class TabledateModel extends TableModel {

	/**
	 * the date we filter the table
	 * 
	 * @var Zend_Date
	 */
	protected $date;

	/**
	 * constructor
	 * 
	 * @param array $params of parameters to preset the object
	 */
	public function __construct(array $params = array()) {
		parent::__construct($params);
		if (isset($params['date'])) {
			$this->date = $params['date'];
		}
		Billrun_Factory::config()->addConfig(APPLICATION_PATH . '/conf/validation.ini');
	}

	/**
	 * Get the data resource
	 * 
	 * @return Mongo Cursor
	 */
	public function getData($filter_query = array()) {
		$cursor = $this->collection->query($filter_query)->cursor();
		$this->_count = $cursor->count();
		$resource = $cursor->sort($this->sort)->skip($this->offset())->limit($this->size);
		return $resource;
	}

	public function getItem($id) {
		$entity = parent::getItem($id);
		return $entity;
	}

	/**
	 * Check if a new entity could replace it
	 * @param type $param
	 */
	public function isLast($entity) {
		$to_date = new MongoDate(strtotime($entity['to']));
		if (!$to_date) {
			return $this->setError("date error");
		}
		$result = $this->getLastItem($entity[$this->search_key]);
		return strval($result['_id']) == strval($entity['_id']);
	}

	public function endsInFuture($entity) {
		$to_date = strtotime($entity['to']);
		return $to_date > time();
	}
	
	public function getOverlappingDatesQuery($entity, $new = true) {
		$from_date = new MongoDate(strtotime($entity['from']));
		if (!$from_date) {
			return $this->setError("date error");
		}
		$to_date = new MongoDate(strtotime($entity['to']));
		if (!$to_date) {
			return $this->setError("date error");
		}
		$id = new MongoId(isset($entity['_id'])? $entity['_id'] : NULL);
		if (!$id) {
			return $this->setError("id error");
		}
		$ret = array(
			$this->search_key => $entity[$this->search_key],
			'$or' => array(
				array('from' => array(
					'$gte' => $from_date,
					'$lte' => $to_date,
				)),
				array('to' => array(
					'$gte' => $from_date,
					'$lte' => $to_date,
				))
			)
		);
		if (!$new) {
			$ret['_id'] = array('$ne' => $id);
		}
		return $ret;
	}
	
	public function hasEntityWithOverlappingDates($entity, $new = true) {
		$query = $this->getOverlappingDatesQuery($entity, $new);
		$result = $this->collection
			->query($query)
			->cursor()->count();
		return $result > 0;
	}

	public function startsInFuture($entity) {
		$from_date = strtotime($entity['from']);
		return $from_date > time();
	}

	public function getProtectedKeys($entity, $type) {
		$parent_protected = parent::getProtectedKeys($entity, $type);
		if ($type == 'update') {
			if ($this->isLast($entity) && $this->endsInFuture($entity)) {
				return array_merge($parent_protected, array("from", $this->search_key));
			}
			return array_merge($parent_protected, array("from", "to", $this->search_key));
		} else if ($type == 'close_and_new') {
			return array_merge($parent_protected, array($this->search_key));
		}
		return $parent_protected;
	}

	public function closeAndNew($params) {
		//			$from = new Zend_Date($data['from'], null, 'he-IL');
		$new_from = new Zend_Date($params['from'], null, 'he-IL');
		if ($new_from->getTimestamp() < time()) {
			$new_from = new Zend_Date(null, null, 'he-IL');
		}

		// close the old line
		$mongoCloseTime = new MongoDate($new_from->getTimestamp() - 1);
		$closed_data = $this->collection->findOne($params['_id'])->getRawData();
		$closed_data['to'] = $mongoCloseTime;
		$this->update($closed_data);

		// open new line
		$params[$this->search_key] = $closed_data[$this->search_key];
		unset($params['_id']);
		$params['from'] = new MongoDate($new_from->getTimestamp());
		$params['to'] = new MongoDate($new_from->add(125, Zend_Date::YEAR)->getTimestamp());
		return $this->update($params);
	}

	public function duplicate($params) {
		$from = new Zend_Date($params['from'], null, 'he-IL');
		$to = new Zend_Date($params['to'], null, 'he-IL');
		$params['from'] = new MongoDate($from->getTimestamp());
		$params['to'] = new MongoDate($to->getTimestamp());
		return parent::duplicate($params);
	}

	public function update($params) {
		if (isset($params['from']) && !$params['from'] instanceof MongoDate) {
			$params['from'] = new MongoDate(strtotime($params['from']));
		}
		if (isset($params['to']) && !$params['to'] instanceof MongoDate) {
			$to = new Zend_Date($params['to'], null, 'he-IL');
			$params['to'] = new MongoDate($to->getTimestamp());
		}
		return parent::update($params);
	}

	public function remove($params) {
		$entity = $this->collection->query($params)->cursor()->current();
		if (!$entity->isEmpty()) {
			$to = $entity['to'];
			$key_name = $entity[$this->search_key];
			$this->collection->removeEntity($entity);
			$last_item = $this->getLastItem($key_name);
			$this->collection->updateEntity($last_item, array('to' => $to));
		}
	}

	public function getLastItem($key_name) {
		$result = $this->collection
			->query($this->search_key, $key_name)
			->cursor()
			->sort(array('to' => -1))
			->limit(1)
			->current();
		$result->collection($this->collection);
		return $result;
	}

	public function getFilterFields() {
		$date = new Zend_Date(null, null, new Zend_Locale('he_IL'));
		$date->set('00:00:00', Zend_Date::TIMES);
		$filter_fields = array(
			'date' => array(
				'key' => 'date',
				'db_key' => array('from', 'to'),
				'input_type' => 'date',
				'comparison' => array('$lte', '$gte'),
				'display' => 'Date',
				'default' => $date->toString('YYYY-MM-dd HH:mm:ss'),
			),
		);
		return array_merge($filter_fields, parent::getFilterFields());
	}

	public function getFilterFieldsOrder() {
		$filter_field_order = array(
			array(
				'date' => array(
					'width' => 2,
				),
			),
		);
		return $filter_field_order;
	}

	public function getSortFields() {
		$sort_fields = array(
			'from' => 'From',
			'to' => 'To',
		);
		return $sort_fields;
	}
	
	public function validate($data, $type) {
		return $this->validationResponse(true);
	}
	
	protected function validationResponse($result, $errorMsg = '') {
		if (!$result) {
			Billrun_Factory::log('Validation errors: ' . $errorMsg, Zend_Log::INFO);
		}
		return array(
			'validate' => $result,
			'errorMsg' => $errorMsg,
		);
	}
	
	protected function validateMandatoryFields($data) {
		$fields = Billrun_Factory::config()->getConfigValue($this->collection_name . '.fields', array());
		$missingFields = array();
		
		foreach ($fields as $field) {
			if ($field['mandatory'] && 
				(!array_key_exists($field['field_name'], $data) ||
				(is_array($data[$field['field_name']]) && empty($data[$field['field_name']])) ||
				((is_string($data[$field['field_name']])) && empty(trim($data[$field['field_name']]))))) {
				$missingFields[] = $field['field_name'];
			}
		}
		
		if (!empty($missingFields)) {
			return "The following fields are missing: " . implode(', ', $missingFields);
		}
		return true;
	}
	
	protected function validateTypeOfFields($data) {
		$fields = Billrun_Factory::config()->getConfigValue($this->collection_name . '.fields', array());
		$typeFields = array();
		foreach ($fields as $field) {
			if (isset($field['type'])) {
				$typeFields[$field['field_name']] = $field['type'];
			}
		}
		return $this->validateTypes($data, $typeFields);
	}
	
	protected function validateTypes($data, $typeFields) {
		$wrongTypes = array();
		
		foreach ($typeFields as $fieldName => $fieldType) {
			$type = (!is_array($fieldType) ? $fieldType : $fieldType['type']);
			$params = (!is_array($fieldType) ? array() : $fieldType['params']);
			if (isset($data[$fieldName]) && !$this->validateType($data[$fieldName], $type, $params)) {
				$wrongTypes[$fieldName] = $fieldType;
			}
		}
		
		if (!empty($wrongTypes)) {
			$ret = array();
			foreach ($wrongTypes as $fieldName => $fieldType) {
				$ret[] = $this->getErrorMessage($fieldName, $data[$fieldName], $fieldType);
			}
			return implode(', ', $ret);
		}
		return true;
	}

	protected function getErrorMessage($fieldName, $fieldValue, $fieldType) {
		if (is_array($fieldType)) {
			return '"' . $fieldValue . '" is not a valid value for "' . $fieldType['type'] .'". Available values are: ' . implode(', ', $fieldType['params']);
		}
		return 'field "' . $fieldName . '" must be of type ' . $fieldType;
	}

	protected function validateType($value, $type, $params) {
		switch ($type) {
			case ('integer'):
				return Billrun_Util::IsIntegerValue($value);
			case ('float'):
				return Billrun_Util::IsFloatValue($value);
			case ('date'):
				return Billrun_Util::isDateValue($value);
			case ('boolean'):
				return is_bool($value);
			case ('in_array'):
				return in_array($value, $params);
		}
		
		return false;
	}

}
