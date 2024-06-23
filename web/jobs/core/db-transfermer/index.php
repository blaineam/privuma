<?php

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
$conn = $privuma->getPDO();

$blocklist = array_map('strtoupper', json_decode(file_get_contents($privuma->getConfigDirectory() . DIRECTORY_SEPARATOR . 'download-blocklist.json'), true) ?? []);
if (count($blocklist) > 0 ) {
    echo PHP_EOL. "Set Blocked column for: " . $conn->query("update media set blocked = case when upper(concat('Album: ', album, '\nFilename: ', filename, '\n', COALESCE(metadata, ''))) REGEXP '(^|\n)(TAGS|TITLE|DESCRIPTION|FILENAME|ALBUM):[^:]*(" . implode('|', $blocklist) . ")[^:]*' then 1 else 0 end;")->rowCount() . " rows";
}
