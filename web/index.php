<?php

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();

require_once($privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'format' . DIRECTORY_SEPARATOR . 'photos.php');
