<?php

namespace privuma\output\format;

session_start();

use privuma\privuma;
use privuma\helpers\tokenizer;

$tokenizer = new tokenizer();
$AUTHTOKEN = privuma::getEnv('AUTHTOKEN');

parse_str(parse_url($_SERVER['HTTP_X_ORIGINAL_URI'], PHP_URL_QUERY), $query);
if ($tokenizer->checkToken($query['key'], $AUTHTOKEN)) {
    http_response_code(200);
    die();
}

http_response_code(401);
die();
