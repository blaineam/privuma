<?php

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
$ops = $privuma->getCloudFS();
$conn = $privuma->getPDO();

echo PHP_EOL . 'fixing dupe mismatches';
$select_results = $conn->query('select min(id) as id, hash from media where hash not in (select hash from media where dupe = 0) group by hash');
$results = $select_results->fetchAll(PDO::FETCH_ASSOC);
echo PHP_EOL . 'Checking ' . count($results) . ' database records';
foreach (array_chunk($results, 2000) as $key => $chunk) {
    foreach ($chunk as $key => $row) {
        $id = $row['id'];
        echo PHP_EOL . 'fixing dupe for id: ' . $id;
        $stmt = $conn->prepare('UPDATE media SET dupe = 0 WHERE id = ?');
        $stmt->execute([$id]);
    }
}
echo PHP_EOL . 'done';
