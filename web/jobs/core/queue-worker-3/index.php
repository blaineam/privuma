<?php
use privuma\privuma;

use privuma\queue\worker;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = new privuma();

if ($argc > 1) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}
new worker('queue', isset($_GET['search']) ? $_GET['search'] : null);
