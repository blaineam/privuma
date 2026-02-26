<?php
use privuma\privuma;
use privuma\webdav\WebDavServer;

require_once __DIR__ . '/../app/privuma.php';

$privuma = privuma::getInstance();
$server = new WebDavServer($privuma);
$server->handle();
