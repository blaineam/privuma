<?php

use privuma\privuma;

use privuma\helpers\mediaFile;
use privuma\helpers\cloudFS;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
$conn = $privuma->getPDO();

if (!is_file(__DIR__ . DIRECTORY_SEPARATOR . 'rename.txt')) {
    echo PHP_EOL . 'Nothing to Rename';
    return;
}
$data = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'rename.txt');

if (empty($data)) {
    echo PHP_EOL . 'Nothing to clean';
    return;
}

function remove_utf8_bom($text)
{
    $bom = pack('H*', 'EFBBBF');
    $text = preg_replace("/^$bom/", '', $text);
    return $text;
}

$items = explode(PHP_EOL, $data);

$ops = $privuma->getCloudFS();

$downloadLocation = privuma::getEnv('DOWNLOAD_LOCATION') . 'pr' . DIRECTORY_SEPARATOR;
$dlOps = new cloudFS($downloadLocation, true, '/usr/bin/rclone', null, true);

$albumFilenameDelimiter = '-----';

foreach ($items as $item) {
    $parts = explode('||', $item);
    $src = remove_utf8_bom($parts[0]);
    $dst = remove_utf8_bom($parts[1]);
    $isFile = false;
    if (str_contains($src, DIRECTORY_SEPARATOR) && str_contains($dst, DIRECTORY_SEPARATOR)) {
        $isFile = true;
        $srcPaths = explode($albumFilenameDelimiter, $src);
        $srcAlbum = array_shift($srcPaths);
        $srcFilename = implode($albumFilenameDelimiter, $srcPaths);
        $dstPaths = explode($albumFilenameDelimiter, $dst);
        $dstAlbum = array_shift($dstPaths);
        $dstFilename = implode($albumFilenameDelimiter, $dstPaths);
    }
    echo PHP_EOL . "Renaming: $src to $dst";
    $fsMove = $ops->rename(privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $src, privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $dst);
    if ($isFile) {
        $srcExt = pathinfo($srcFilename, PATHINFO_EXTENSION);
        $dstExt = pathinfo($dstFilename, PATHINFO_EXTENSION);
        if ($srcExt !== $dstExt) {
            $hash = (new mediaFile($srcFilename, $srcAlbum))->record()['hash'];
            $dlMove = $dlOps->rename($hash . '.' . $srcExt, $hash . '.' . $dstExt);
        }
        $stmt = $conn->prepare('UPDATE media SET album = ?, filename = ? WHERE album = ? AND filename = ?');
        $stmt->execute([
           $dstAlbum,
           $dstFilename,
           $srcAlbum,
           $srcFilename
        ]);
    } else {
        $stmt = $conn->prepare('UPDATE media SET album = ? WHERE album = ?');
        $stmt->execute([
        $dst,
        $src
        ]);
    }
    echo ' Renamed';
}

unlink(__DIR__ . DIRECTORY_SEPARATOR . 'rename.txt');
echo PHP_EOL . 'All Renames Completed';
