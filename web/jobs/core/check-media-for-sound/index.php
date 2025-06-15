<?php

use privuma\privuma;
use privuma\helpers\mediaFile;
use privuma\helpers\cloudFS;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
$ops = $privuma->getCloudFS();

if ($argc > 1) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

$conn = $privuma->getPDO();

$album = '';
if (isset($_GET['albums'])) {
    $albums = implode(', ', array_map(function ($albumItem) use ($conn) {
        return $conn->quote($albumItem);
    }, explode(',', $_GET['albums'])));
    echo PHP_EOL . "checking sound in album: {$albums}";
    $album = " and album in ({$albums}) ";
}

$exts = ['mp4', 'mov', 'webm'];
if (isset($_GET['exts'])) {
    $exts = explode(',', $_GET['exts']);
}

$refresh = '';
if (isset($_GET['refresh'])) {
    $refresh = ' or sound = -91 ';
}

$extQuery = implode(' or ', array_map(function ($ext) {
    return " filename like '%.$ext' or url like '%.$ext%' ";
}, $exts));

$query = "SELECT hash, album, filename, url FROM media where (sound is null {$refresh}) and ({$extQuery}) and album != 'Favorites' {$album} AND (`metadata` NOT LIKE '%no_sound%') group by hash order by id desc";
$select_results = $conn->query($query);
$results = $select_results->fetchAll(PDO::FETCH_ASSOC);
$total = count($results);
echo PHP_EOL . 'Checking ' . $total . ' database records';

function getPos($innerKey, $outerKey, $total)
{
    $pos = ($outerKey * 2000) + $innerKey;
    $percent = round($pos / $total, 5) * 100;
    return "$pos / $total ($percent%)  ";
}

foreach (array_chunk($results, 2000) as $key => $chunk) {
    foreach ($chunk as $ikey => $row) {
        $album = $row['album'];
        $filename = $row['filename'];
        $url = $row['url'];
        if (!is_null($album) && !is_null($filename)) {
            if (is_null($url) || empty($url)) {
                $preserve = privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename;
                $url = 'http://' . privuma::getEnv('CLOUDFS_HTTP_ENDPOINT') . '/' . cloudFS::encode($preserve);
            }
            $volume = shell_exec('ffmpeg -hide_banner -i "' . $url . '" -af volumedetect -vn -f null - 2>&1 | grep mean_volume');
            if ($volume === false || is_null($volume) || empty($volume)) {
                echo PHP_EOL . getPos($ikey, $key, $total) . 'Failed to determine media Volume: ' . $url;
                $sound_stmt = $conn->prepare('update media set sound = ? WHERE hash = ? AND (sound is null OR sound = -91)');
                if ($sound_stmt !== false) {
                    try {
                        $sound_stmt->execute([-91, $row['hash']]);
                    } catch (Exception $e) {

                    }
                }
                continue;
            }
            $parts = explode(':', $volume);
            $volume = end($parts);
            $volume = floatval(explode(' ', trim($volume))[0]);
            echo PHP_EOL . getPos($ikey, $key, $total) . 'Volume Determined: ' . $volume;
            $sound_stmt = $conn->prepare('update media set sound = ? WHERE hash = ? AND (sound is null OR sound = -91)');
            $sound_stmt->execute([$volume, $row['hash']]);
        }
    }
}
