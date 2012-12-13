<?php

/**
 * @package			Billing
 * @copyright		Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license			GNU General Public License version 2 or later; see LICENSE.txt
 */
// initiate libs
// @todo make auto load
define('LIBS_PATH', __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR);
require_once LIBS_PATH . 'parser.php';
require_once LIBS_PATH . 'processor.php';
require_once LIBS_PATH . 'calculator' . DIRECTORY_SEPARATOR . 'basic.php';
define('MONGODLOID_PATH', LIBS_PATH . DIRECTORY_SEPARATOR . 'Mongodloid' . DIRECTORY_SEPARATOR);
require_once MONGODLOID_PATH . 'Connection.php';
require_once MONGODLOID_PATH . 'Exception.php';

// load mongodb instance
$conn = Mongodloid_Connection::getInstance();
$db = $conn->getDB('billing');

if (isset($argv[1]))
{
	$type = $argv[1];
}
else
{
	$type = 'ilds';
}


$options = array(
	'type' => $type,
	'db' => $db,
);

$calculator = calculator_basic::getInstance($options);

$calculator->load();

$calculator->calc();

$calculator->write();