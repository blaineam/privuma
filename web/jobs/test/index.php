<?php

require(__DIR__ . '/../../helpers/cloud-fs-operations.php'); 

$ops = new cloudFS\Operations(__DIR__);

if(!is_dir(__DIR__ . '/testing/potatoes')){
    mkdir(__DIR__ . '/testing/potatoes', 0777, true);
}

if(!is_dir(__DIR__ . '/testing/frank')){
    mkdir(__DIR__ . '/testing/frank', 0777, true);
}
file_put_contents(__DIR__ . '/testing/potatoes/example.txt', '');
file_put_contents(__DIR__ . '/testing/potatoes/sample.jpeg', '');
file_put_contents(__DIR__ . '/testing/frank/fred.mp4', '');

clearstatcache();



$ops->mkdir('/testing/potatoes');
$ops->mkdir('/testing/frank');
$ops->file_put_contents('/testing/potatoes/example.txt', '');
$ops->file_put_contents('/testing/potatoes/sample.jpeg', '');
$ops->file_put_contents('/testing/frank/fred.mp4', '');

$scandirTest = scandir(__DIR__ . '/testing');
sort($scandirTest);
$scandirTest2 = scandir(__DIR__ . '/testing/potatoes');
sort($scandirTest2);

function formatGlobForComparison($glob) {
    return array_map(function($path) {
        return basename(dirname($path)) . DIRECTORY_SEPARATOR . basename($path);
    }, $glob);
}

$fsTests = [
    $scandirTest,
    $scandirTest2,
    formatGlobForComparison(glob(__DIR__ . '/testing/**/exam*.*')),
    formatGlobForComparison(glob(__DIR__ . '/testing/potatoes/*.*')),
    is_dir(__DIR__ . '/testing'),
    is_dir(__DIR__ . '/testing/potatoes/example.txt'),
    file_exists(__DIR__ . '/testing/potatoes/example.txt'),
    is_file(__DIR__ . '/testing/potatoes/example.txt'),
    file_put_contents(__DIR__ . '/testing/potatoes/example.txt', 'hello'),
    file_get_contents(__DIR__ . '/testing/potatoes/example.txt'),
    md5_file(__DIR__ . '/testing/potatoes/example.txt'),
    clearstatcache(),
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


$scandirTest3 = $ops->scandir('/testing');
sort($scandirTest3);
$scandirTest4 = $ops->scandir('/testing/potatoes');
sort($scandirTest4);

$opsTests = [
    $scandirTest3,
    $scandirTest4,
    formatGlobForComparison($ops->glob('/testing/**/exam*.*')),
    formatGlobForComparison($ops->glob('/testing/potatoes/*.*')),
    $ops->is_dir('/testing'),
    $ops->is_dir('/testing/potatoes/example.txt'),
    $ops->file_exists('/testing/potatoes/example.txt'),
    $ops->is_file('/testing/potatoes/example.txt'),
    $ops->file_put_contents('/testing/potatoes/example.txt', 'hello'),
    $ops->file_get_contents('/testing/potatoes/example.txt'),
    $ops->md5_file('/testing/potatoes/example.txt'),
    clearstatcache(),
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

var_dump([$opsTests, $fsTests]);

$ops->file_put_contents('/testing/potatoes/example.txt', file_get_contents($ops->pull('/testing/potatoes/example.txt')) . ' Friends');
$encoded = $ops->encode('/testing/potatoes/example.chips.txt');
$opsEncodingTest = $encoded !== '/testing/potatoes/example.chips.txt' && $ops->decode($encoded) == '/testing/potatoes/example.chips.txt' && pathinfo($encoded, PATHINFO_EXTENSION) == 'txt';
$opsExclusiveTest = $ops->file_get_contents('/testing/potatoes/example.txt') == 'hello Friends';
$filemtimeTest = is_int($ops->filemtime('/testing/potatoes/example.txt')) && is_int(filemtime(__DIR__ . '/testing/potatoes/example.txt'));

var_dump([
    "file operations tests" => $opsTests == $fsTests,
    "operations exclusive functionality test" => $opsExclusiveTest,
    "file modification time test" => $filemtimeTest,
    "operations encoding test" => $opsEncodingTest
]);

echo 'Ops Tests Pass?: ' .( ($opsTests == $fsTests && $opsExclusiveTest && $filemtimeTest && $opsEncodingTest) ? 'Yes' : 'No');