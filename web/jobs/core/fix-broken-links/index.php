<?php

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();

$conn = $privuma->getPDO();
$select_results = $conn->query("SELECT id, url FROM media where url is not null order by id asc");
$results = $select_results->fetchAll(PDO::FETCH_ASSOC);
foreach(array_chunk($results, 2000) as $key => $chunk) {
    foreach($chunk as $key => $row) {
        if(!is_null($row['url'])) {
            //echo PHP_EOL."Checking URL: " . $row['url'];
            $headers = get_headers($row['url'], TRUE);
            $head = array_change_key_case($headers);
            $protocol = parse_url($row['url'],  PHP_URL_SCHEME);
            $hostname = parse_url($row['url'],  PHP_URL_HOST);
            $hostHeaders = get_headers($protocol."://".$hostname, TRUE);
            if ( (strpos($hostHeaders[0], '200') !== FALSE || strpos($hostHeaders[0], '302') !== FALSE || strpos($hostHeaders[0], '301') !== FALSE ) && (strpos($headers[0], '200') === FALSE || (strpos($head['content-type'], 'image') === FALSE && strpos($head['content-type'], 'video') === FALSE) )) {
                $delete_stmt = $conn->prepare("delete FROM media WHERE id = ?");
                $delete_stmt->execute([$row['id']]);
                echo PHP_EOL.$delete_stmt->rowCount() . " - Deleted missing remote media url: " . $row['url'];
            }
        }
    }
}
