<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = new privuma();

require_once($privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'format' . DIRECTORY_SEPARATOR . 'flash.php');
