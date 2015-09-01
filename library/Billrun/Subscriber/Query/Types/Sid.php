<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing class for subscriber query by Sid.
 *
 * @package  Billing
 * @since    4
 */
class Billrun_Subscriber_Query_Types_Sid extends Billrun_Subscriber_Query_Base {
	
	/**
	 * get the field name in the parameters and the field name to set in the query.
	 * @return array - Key is the field name in the parameters and value is the field
	 * name in the query.
	 */
	protected function getKeyFields() {
		return array('sid' => 'sid');
	}
	
	/**
	 * Build the query by the parameters.
	 * @param array $params - Array of received parameters.
	 * @param array $fieldNames - Array of field names in the parameters and the query.
	 * @return array Query built from received parameters.
	 */
	protected function buildQuery($params, $fieldNames) {
		$query = parent::buildQuery($params, $fieldNames);
		
		// Add the extra query fields.
		$query['to']['$gte']   = new MongoDate();
		$query['from']['$lte'] = new MongoDate();
		
		return $query;
	}
}
