<?php

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = new privuma();
if(!$privuma->getEnv('MIRROR_DB')) {
    exit();
}
$host = $privuma->getEnv('MYSQL_HOST');
$hostExternal = $privuma->getEnv('MYSQL_HOST_EXTERNAL');
$db = $privuma->getEnv('MYSQL_DATABASE');
$user = $privuma->getEnv('MYSQL_USER');
$pass = $privuma->getEnv('MYSQL_PASSWORD');
$RESET = $privuma->getEnv('MYSQL_RESET_EXTERNAL') ?? false;
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$source = new PDO('mysql:host=' . $host . ';dbname=' . $db, $user, $pass, $options);
$target = new PDO('mysql:host=' . $hostExternal . ';dbname=' . $db, $user, $pass, $options);

if($RESET) {
    $truncate_stmt = $target->prepare('TRUNCATE TABLE ' . $db . '.media');
    $truncate_stmt->execute();
}

$select_results = $source->query('SELECT id FROM media order by id asc');
$ids = array_column($select_results->fetchAll(PDO::FETCH_ASSOC), 'id');

$existing_media = $target->query('SELECT id FROM media order by id asc');
$existing_ids = array_column($existing_media->fetchAll(PDO::FETCH_ASSOC), 'id');

$missing_ids = array_diff($existing_ids, $ids);
foreach(array_chunk($missing_ids, 2000) as $key => $chunk_ids) {
    $mParams = str_repeat('?,', count($chunk_ids) - 1) . '?';
    $min_id = $chunk_ids[0];
    $max_id = $chunk_ids[count($chunk_ids) - 1];
    $delete_stmt = $target->prepare("DELETE FROM media WHERE id >= $min_id AND id <= $max_id AND id IN ($mParams)");
    $delete_stmt->execute($chunk_ids);
    echo PHP_EOL . $delete_stmt->rowCount() . ' deleted missing media in chunk ' . $key . '/' . ceil(count($missing_ids) / 2000) . ' from mirrored database';
}

$new_ids = array_diff($ids, $existing_ids);
foreach(array_chunk($new_ids, 2000) as $key => $chunk_ids) {
    $mParams = str_repeat('?,', count($chunk_ids) - 1) . '?';
    $min_id = $chunk_ids[0];
    $max_id = $chunk_ids[count($chunk_ids) - 1];
    $select_results = $source->prepare("SELECT * FROM media WHERE id >= $min_id AND id <= $max_id AND id IN ($mParams)");
    $select_results->execute($chunk_ids);

    $counter = 0;
    $insert_stmt = $target->prepare('INSERT INTO media (id, dupe, hash, album, filename, time) VALUES (:id, :dupe, :hash, :album, :filename, :time) ON DUPLICATE KEY UPDATE id=id');
    while ($row = $select_results->fetch(PDO::FETCH_ASSOC)) {
        $counter++;
        $insert_stmt->execute($row);
    }

    echo PHP_EOL . $counter . ' inserted new media in chunk ' . $key . '/' . ceil(count($new_ids) / 2000) . ' to mirrored database';
}
