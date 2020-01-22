<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

require_once APPLICATION_PATH . '/application/controllers/Action/Api.php';

/**
 * CreditInstallments action class
 *
 * @package  Action
 * 
 * @since    5.11
 */
class CreditInstallmentsAction extends ApiAction {
	use Billrun_Traits_Api_UserPermissions;
	
	/**
	 * method to execute the credit installments requested action
	 * it's called automatically by the api main controller
	 */
	public function execute() {
		$this->allowed();
		$request = $this->getRequest();
		try {
			switch ($request->get('action')) {
				case 'prepone' :
					$response = $this->preponeCreditInstallments($request);
					break;
			}

			if ($response !== FALSE) {
				$this->getController()->setOutput(array(array(
						'status' => 1,
						'desc' => 'success',
						'input' => $request->getPost(),
						'details' => $response,
				)));
			}
		} catch (Exception $ex) {
			$this->setError($ex->getMessage(), $request->getPost());
			return;
		}
	}

	protected function getPermissionLevel() {
		return Billrun_Traits_Api_IUserPermissions::PERMISSION_READ;
	}
	
	protected function preponeCreditInstallments($request){
		$sid = $request->get('sid');
		$aid = $request->get('aid');
		if(!is_numeric($sid) || !is_numeric($aid)){
			$this->setError('Illegal sid/aid', $request->getPost());
			return FALSE;
		}
		$accountArray = [$aid => [$sid]];
		Billrun_Aggregator_Customer::preponeInstallments($accountArray);
	}
}
