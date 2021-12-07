<?php

require(__DIR__ . '/../../helpers/cloud-fs-operations.php'); 

$ops = new cloudFS\Operations(__DIR__);
file_put_contents(__DIR__ . '/testing/potatoes/example.txt', 'hello');
clearstatcache();
$fsTests = [
    scandir(__DIR__ . '/testing'),
    scandir(__DIR__ . '/testing/potatoes'),
    is_dir(__DIR__ . '/testing'),
    is_dir(__DIR__ . '/testing/potatoes/example.txt'),
    file_exists(__DIR__ . '/testing/potatoes/example.txt'),
    is_file(__DIR__ . '/testing/potatoes/example.txt'),
    file_put_contents(__DIR__ . '/testing/potatoes/example.txt', 'hello'),
    file_get_contents(__DIR__ . '/testing/potatoes/example.txt'),
    md5_file(__DIR__ . '/testing/potatoes/example.txt'),
    filesize(__DIR__ . '/testing/potatoes/example.txt'),
    file_get_contents(__DIR__ . '/testing/potatoes/example.txt'),
    mkdir(__DIR__ . '/testing/potatoes2'),
    copy(__DIR__ . '/testing/potatoes/example.txt',__DIR__ . '/testing/potatoes2/example.txt'),
    copy(__DIR__ . '/testing/potatoes/sample.jpeg',__DIR__ . '/testing/potatoes2/sample.jpeg'),
    rename(__DIR__ . '/testing/potatoes2/example.txt',__DIR__ . '/testing/potatoes2/example2.txt'),
    unlink(__DIR__ . '/testing/potatoes2/example2.txt'),
    rmdir(__DIR__ . '/testing/potatoes2'),
    unlink(__DIR__ . '/testing/potatoes2/sample.jpeg'),
    rmdir(__DIR__ . '/testing/potatoes2'),
];
$opsTests = [
    $ops->scandir('/testing'),
    $ops->scandir('/testing/potatoes'),
    $ops->is_dir('/testing'),
    $ops->is_dir('/testing/potatoes/example.txt'),
    $ops->file_exists('/testing/potatoes/example.txt'),
    $ops->is_file('/testing/potatoes/example.txt'),
    $ops->file_put_contents('/testing/potatoes/example.txt', 'hello'),
    $ops->file_get_contents('/testing/potatoes/example.txt'),
    $ops->md5_file('/testing/potatoes/example.txt'),
    $ops->filesize('/testing/potatoes/example.txt'),
    $ops->file_get_contents('/testing/potatoes/example.txt'),
    $ops->mkdir('/testing/potatoes2'),
    $ops->copy('/testing/potatoes/example.txt','/testing/potatoes2/example.txt'),
    $ops->copy('/testing/potatoes/sample.jpeg','/testing/potatoes2/sample.jpeg'),
    $ops->rename('/testing/potatoes2/example.txt','/testing/potatoes2/example2.txt'),
    $ops->unlink('/testing/potatoes2/example2.txt'),
    $ops->rmdir('/testing/potatoes2'),
    $ops->unlink('/testing/potatoes2/sample.jpeg'),
    $ops->rmdir('/testing/potatoes2'),
];

$ops->file_put_contents('/testing/potatoes/example.txt', file_get_contents($ops->pull('/testing/potatoes/example.txt')) . ' Friends');
$opsExclusiveTest = $ops->file_get_contents('/testing/potatoes/example.txt') == 'hello Friends';
$filemtimeTest = is_int($ops->filemtime('/testing/potatoes/example.txt')) && is_int(filemtime(__DIR__ . '/testing/potatoes/example.txt'));
echo 'Ops Tests Pass?: ' .( ($opsTests == $fsTests && $opsExclusiveTest && $filemtimeTest) ? 'Yes' : 'No');