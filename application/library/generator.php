<?php

/**
 * @package			Billing
 * @copyright		Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license			GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing abstract generator class
 *
 * @package  Billing
 * @since    1.0
 */
abstract class generator extends base {

	/**
	 * the directory where the generator store files
	 * @var string
	 */
	protected $export_directory;
	protected $csvContent = '';
	protected $csvPath;

	public function __construct($options) {

		parent::__construct($options);
		if (isset($options['export_directory'])) {
			$this->export_directory = $options['export_directory'];
		} else {
			$this->export_directory = $this->config->ilds->export;//__DIR__ . '/../files/';
		}

		$this->csvPath = $this->export_directory . '/' . $this->getStamp() . '.csv';
		$this->loadCsv();
	}

	protected function loadCsv() {
		if (file_exists($this->csvPath)) {
			$this->csvContent = file_get_contents($this->csvPath);
		}
	}

	protected function csv($row) {
		return file_put_contents($this->csvPath, $row, FILE_APPEND);
	}

	/**
	 * load the container the need to be generate
	 */
	abstract public function load($initData = true);

	/**
	 * execute the generate action
	 */
	abstract public function generate();
}