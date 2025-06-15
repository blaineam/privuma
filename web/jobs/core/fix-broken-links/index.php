<?php

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();

if ($argc > 1) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

$conn = $privuma->getPDO();

$album = '';
if (isset($_GET['albums'])) {
    $albums = implode(', ', array_map(function ($albumItem) use ($conn) {
        return $conn->quote($albumItem);
    }, explode(',', $_GET['albums'])));
    echo PHP_EOL . "checking broken links in album: {$albums}";
    $album = " and album in ({$albums}) ";
}

$select_results = $conn->query("SELECT id, url, thumbnail FROM media where url is not null {$album} and album != 'Favorites' order by id asc");
$results = $select_results->fetchAll(PDO::FETCH_ASSOC);
$checkCount = count($results);
echo PHP_EOL . 'Checking ' . $checkCount . ' database records';
$delections = 0;
foreach (array_chunk($results, 2000) as $key => $chunk) {
    foreach ($chunk as $key => $row) {
        if (!is_null($row['url']) && strlen($row['url']) > 0) {
            // echo PHP_EOL."Checking URL: " . $row['url'];
            $headers = get_headers($row['url'], true);
            if (!is_array($headers)) {
                continue;
            }
            $responseCode = substr($headers[0], 9, 3);
            $head = array_change_key_case($headers);
            $protocol = parse_url($row['url'],  PHP_URL_SCHEME);
            $hostname = parse_url($row['url'],  PHP_URL_HOST);
            $hostHeaders = get_headers($protocol . '://' . $hostname, true);
            $hostResponseCode = substr($hostHeaders[0], 9, 3);
            $hostOk = (
                str_starts_with($hostResponseCode, '2') ||
                str_starts_with($hostResponseCode, '3') ||
                str_starts_with($hostResponseCode, '4')
            );
            $fileMissing = (
                (!str_starts_with($responseCode, '2') && !str_starts_with($responseCode, '3'))
                || (
                    strpos($head['content-type'], 'image') === false
                    && strpos($head['content-type'], 'video') === false
                )
            );
            if (
                $fileMissing && $hostOk
            ) {
                $delections++;
                if (!isset($_GET['clean'])) {
                    echo PHP_EOL . ' - [DRY RUN] Skipping Deletion of missing remote media url: ' . $row['url'];
                    continue;
                }
                $delete_stmt = $conn->prepare('delete FROM media WHERE id = ?');
                $delete_stmt->execute([$row['id']]);
                echo PHP_EOL . $delete_stmt->rowCount() . ' - Deleted missing remote media url: ' . $row['url'];
            } elseif (strpos($head['content-type'], 'video') !== false) {
                if (!is_null($row['thumbnail']) && strlen($row['thumbnail']) > 0) {
                    //echo PHP_EOL."Checking thumbnail: " . $row['thumbnail'];
                    $headers = get_headers($row['thumbnail'], true);
                    if (!is_array($headers)) {
                        continue;
                    }
                    $responseCode = substr($headers[0], 9, 3);
                    $head = array_change_key_case($headers);
                    $protocol = parse_url($row['thumbnail'],  PHP_URL_SCHEME);
                    $hostname = parse_url($row['thumbnail'],  PHP_URL_HOST);
                    $hostHeaders = get_headers($protocol . '://' . $hostname, true);

                    $fileMissing = (
                        (!str_starts_with($responseCode, '2') && !str_starts_with($responseCode, '3'))
                        || (
                            strpos($head['content-type'], 'image') === false
                            && strpos($head['content-type'], 'video') === false
                        )
                    );
                    if (
                        $fileMissing && $hostOk
                    ) {
                        if (!isset($_GET['clean'])) {
                            echo PHP_EOL . ' - [DRY RUN] Skipping Deletion of missing remote media thumbnail: ' . $row['thumbnail'];
                            continue;
                        }
                        $delete_stmt = $conn->prepare('delete FROM media WHERE id = ?');
                        $delete_stmt->execute([$row['id']]);
                        echo PHP_EOL . $delete_stmt->rowCount() . ' - Deleted missing remote media thumbnail: ' . $row['thumbnail'];

                    }
                }
            }
        }
    }
}

echo PHP_EOL . "Total Deletions: {$delections} / {$checkCount}";
