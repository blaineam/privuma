<?php

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();

if ($argc > 1) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

$conn = $privuma->getPDO();

$album = '';
if (isset($_GET['album'])) {
    $album = $conn->quote($_GET['album']);
    echo PHP_EOL . "checking broken links in album: {$album}";
    $album = " and album = {$album} ";
}

$select_results = $conn->query("SELECT id, url, thumbnail FROM media where url is not null {$album} order by id asc");
$results = $select_results->fetchAll(PDO::FETCH_ASSOC);
echo PHP_EOL . 'Checking ' . count($results) . ' database records';
foreach (array_chunk($results, 2000) as $key => $chunk) {
    foreach ($chunk as $key => $row) {
        if (!is_null($row['url'])) {
            //echo PHP_EOL."Checking URL: " . $row['url'];
            $headers = get_headers($row['url'], true);
            if (!is_array($headers)) {
                continue;
            }
            $head = array_change_key_case($headers);
            $protocol = parse_url($row['url'],  PHP_URL_SCHEME);
            $hostname = parse_url($row['url'],  PHP_URL_HOST);
            $hostHeaders = get_headers($protocol . '://' . $hostname, true);
            $hostOk = (
                strpos($hostHeaders[0], '200') !== false
                || strpos($hostHeaders[0], '302') !== false
                || strpos($hostHeaders[0], '301') !== false
                || strpos($hostHeaders[0], '403') !== false
            );
            $fileMissing = (
                strpos($headers[0], '200') === false
                || (
                    strpos($head['content-type'], 'image') === false
                    && strpos($head['content-type'], 'video') === false
                )
            );
            if (
                $fileMissing && $hostOk
            ) {
                var_dump([$headers,
                    $fileMissing, $hostOk, $hostHeaders]);
                continue;
                $delete_stmt = $conn->prepare('delete FROM media WHERE id = ?');
                $delete_stmt->execute([$row['id']]);
                echo PHP_EOL . $delete_stmt->rowCount() . ' - Deleted missing remote media url: ' . $row['url'];
            } elseif (strpos($head['content-type'], 'video') !== false) {
                if (!is_null($row['thumbnail'])) {
                    //echo PHP_EOL."Checking thumbnail: " . $row['thumbnail'];
                    $headers = get_headers($row['thumbnail'], true);
                    $head = array_change_key_case($headers);
                    $protocol = parse_url($row['thumbnail'],  PHP_URL_SCHEME);
                    $hostname = parse_url($row['thumbnail'],  PHP_URL_HOST);
                    $hostHeaders = get_headers($protocol . '://' . $hostname, true);

                    if ((strpos($hostHeaders[0], '200') !== false || strpos($hostHeaders[0], '302') !== false || strpos($hostHeaders[0], '301') !== false) && (strpos($headers[0], '200') === false || (strpos($head['content-type'], 'image') === false && strpos($head['content-type'], 'video') === false))) {
                        var_dump([$headers,
                                $fileMissing, $hostOk, $hostHeaders]);
                        continue;
                        $delete_stmt = $conn->prepare('delete FROM media WHERE id = ?');
                        $delete_stmt->execute([$row['id']]);
                        echo PHP_EOL . $delete_stmt->rowCount() . ' - Deleted missing remote media thumbnail: ' . $row['thumbnail'];

                    }
                }
            }
        }
    }
}
