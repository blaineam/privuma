<?php
namespace privuma\helpers;

$env = new dotenv(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.env');

$unrestrictedHostnames = array_map('trim', explode(',', $env->get('UNRESTRICTED_HOSTNAMES') ?? ''));
$unrestrictedPaths = array_map('trim', explode(',', $env->get('UNRESTRICTED_PATHS') ?? ''));
$unrestrictedSessionVariables = array_map('trim', explode(',', $env->get('UNRESTRICTED_SESSION_VARIABLES') ?? ''));

if (
    isset($_SERVER['SERVER_NAME'])
    && !is_null($_SERVER['SERVER_NAME'])
    && $_SERVER['SERVER_NAME'] !== '_'
    && !in_array($_SERVER['SERVER_NAME'], $unrestrictedHostnames)
    && empty(array_filter($unrestrictedPaths, function ($path) {
        return strpos($_SERVER['REQUEST_URI'], $path) !== false;
    }))
) {
    session_start();
    $found = false;
    foreach($unrestrictedSessionVariables as $variableName) {
        if(isset($_SESSION[$variableName])) {
            $found = true;
        }
    }
    if (!$found) {
        http_response_code(400);
        die('Invalid Domain Requested');
    }
}
