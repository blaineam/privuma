<?php
ini_set('memory_limit', '4G');
use privuma\privuma;
use privuma\helpers\tokenizer;
use privuma\helpers\cloudFS;

require_once __DIR__ .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  'app' .
  DIRECTORY_SEPARATOR .
  'privuma.php';

$privuma = privuma::getInstance();
$tokenizer = new tokenizer();
$downloadLocation = $privuma->getEnv('DOWNLOAD_LOCATION');
if (!$downloadLocation) {
    exit();
}

$deletionQueuePath = __DIR__."/deletion_queue.txt";
if (file_exists($deletionQueuePath)) {
  passthru("rclone --disable ListR -v --fast-list --checkers 32 --transfers 32 -P --retries 5 --multi-thread-streams 1 --include-from '$deletionQueuePath' delete $downloadLocation");
  unlink($deletionQueuePath);
  die("DONE!!!!");
}

$conn = $privuma->getPDO();
$ops = new cloudFS($downloadLocation . 'pr' . DIRECTORY_SEPARATOR, true, '/usr/bin/rclone', null, true);
$opsFavorites = new cloudFS($downloadLocation . 'fa' . DIRECTORY_SEPARATOR, true, '/usr/bin/rclone', null, true);
$opsUnfiltered = new cloudFS($downloadLocation . 'un' . DIRECTORY_SEPARATOR, true, '/usr/bin/rclone', null, true);

echo PHP_EOL . 'Building list of media to keep';
$stmt = $conn->prepare("select filename, hash
from media
where hash is not null
and hash != ''
and hash != 'compressed'
group by hash
 order by
	time DESC");
$stmt->execute();
$keepers = [];
foreach ($stmt->fetchAll() as $row) {
    $hash = $row['hash'];
    $ext = pathinfo($row['filename'], PATHINFO_EXTENSION);
    $keepers[] = $hash . '.' . $ext;
    if (in_array(strtolower($ext), ['mp4', 'webm', 'gif'])) {
        $keepers[] = $hash . '.jpg';
    }
}
echo PHP_EOL . 'Found ' . count($keepers) . ' Keepers!';
echo PHP_EOL . 'Checking Primary Ops for deletions';
$checkersOps = array_map(
    fn ($item) => trim($item, "\/"),
    array_column(
        $ops->scandir('', true, true, null, false, true, true, true),
        'Name'
    )
);

$deletionQueueOps = array_filter($checkersOps, function ($item) use ($keepers) {
    return !empty($item) && !in_array($item, ['.', '..', '/']) && !in_array($item, $keepers) && !in_array(pathinfo(strtolower($item), PATHINFO_EXTENSION), ['js', 'json', 'html']);
});
unset($checkersOps);

echo PHP_EOL . 'Found ' . count($deletionQueueOps) . ' Primary Deletions';

$deletions = [];
foreach ($deletionQueueOps as $deletion) {
    echo PHP_EOL . 'Deleting: ' . $deletion;
    $deletions[] = "pr/".ltrim($ops->encode($deletion, true), "./");
    //$ops->unlink($deletion);
}

echo PHP_EOL . 'Checking Favorite Ops for deletions';
$checkersOpsFavorites = array_map(
    fn ($item) => trim($item, "\/"),
    array_column(
        $opsFavorites->scandir('', true, true, null, false, true, true, true),
        'Name'
    )
);

$deletionQueueOpsFavorites = array_filter($checkersOpsFavorites, function ($item) use ($keepers) {
    return !empty($item) && !in_array($item, ['.', '..', '/']) && !in_array($item, $keepers) && !in_array(pathinfo(strtolower($item), PATHINFO_EXTENSION), ['js', 'json', 'html']);
});
unset($checkersOpsFavorites);

echo PHP_EOL . 'Found ' . count($deletionQueueOpsFavorites) . ' Favorite Deletions';

foreach ($deletionQueueOpsFavorites as $deletion) {
    echo PHP_EOL . 'Deleting: ' . $deletion;
    $deletions[] = "fa/".ltrim($ops->encode($deletion, true), "./");
    //$opsFavorites->unlink($deletion);
}

echo PHP_EOL . 'Checking Unfiltered Ops for deletions';
$checkersUnfiltered = array_map(
    fn ($item) => trim($item, "\/"),
    array_column(
        $opsUnfiltered->scandir('', true, true, null, false, true, true, true),
        'Name'
    )
);

$deletionQueueOpsUnfiltered = array_filter($checkersUnfiltered, function ($item) use ($keepers) {
    return !empty($item) && !in_array($item, ['.', '..', '/']) && !in_array($item, $keepers) && !in_array(pathinfo(strtolower($item), PATHINFO_EXTENSION), ['js', 'json', 'html']);
});
unset($checkersOps);

echo PHP_EOL . 'Found ' . count($deletionQueueOpsUnfiltered) . ' Unfiltered Deletions';

foreach ($deletionQueueOpsUnfiltered as $deletion) {
    echo PHP_EOL . 'Deleting: ' . $deletion;
    $deletions[] = "un/".ltrim($ops->encode($deletion, true), "./");
    //$opsUnfiltered->unlink($deletion);
}

unset($checkersUnfiltered);

file_put_contents($deletionQueuePath, implode(PHP_EOL, $deletions));

passthru("rclone --stats-one-line -P --retries 5 --multi-thread-streams 1 --include-from '$deletionQueuePath' --dry-run delete $downloadLocation");

echo PHP_EOL . 'DONE!';
