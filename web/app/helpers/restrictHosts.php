<?php
namespace privuma\helpers;
use privuma\helpers\dotenv;
$env = new dotenv(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.env');
if (
    !is_null($_SERVER['SERVER_NAME'])
    && $_SERVER['SERVER_NAME'] === $env->get('DEOVR_HOST')
    && strpos($_SERVER['REQUEST_URI'], '/deovr') === false
    && strpos($_SERVER['REQUEST_URI'], '/media') === false
) {
    session_start();
    if(!isset($_SESSION['deoAuthozied'])) {
        http_response_code(400);
        die('Invalid Domain Requested');
    }
}

