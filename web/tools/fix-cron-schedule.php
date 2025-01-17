<?php

//exec('pkill -f privuma');

$dir_iterator = new RecursiveDirectoryIterator(__DIR__ . '/../jobs');
$iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);
foreach ($iterator as $file) {
    if ($file->getBasename() === 'cron.json') {
        touch($file->getRealPath(), strtotime('PST 1:00AM yesterday', time()), strtotime('PST 1:00AM yesterday', time()));
    }
}

echo 'Done';
