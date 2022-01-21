<?php

use privuma\privuma;
use privuma\helpers\mediaFile;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = new privuma();
$ops = $privuma->getCloudFS();

$configs = json_decode(file_get_contents($privuma->getConfigDirectory() . DIRECTORY_SEPARATOR . 'sync-dirs.json'), true) ?? [];
foreach($configs as $sync) {

    if(substr($sync['path'], 0, 1) !== DIRECTORY_SEPARATOR) {
        $sync['path'] = $privuma->canonicalizePath(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . $sync['path']);
        echo PHP_EOL."Using abolsute path of the web folder relative path: " . $sync['path'];
    }

    if(!is_dir($sync['path'])) {
        echo PHP_EOL."Cannot find sync path: " . $sync['path'];
        continue;
    } 

    processDir($sync['path'], $sync);
}

function processDir($dir, $sync) {
    global $ops;
    global $privuma;
    $files = scandir($dir);
    foreach ($files as $value) {
        $path = $dir . DIRECTORY_SEPARATOR . $value;
        if (!is_dir($path)) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if(in_array($ext, ['DS_Store'])) {
                continue;
            }
            if(!$sync['preserve'] && in_array(strtolower($ext), ['mp4','jpg','jpeg','gif','png','heif'])) {
                $album = str_replace(DIRECTORY_SEPARATOR, '---', str_replace($sync['path'], 'Syncs', dirname($path)));
                $filename = mediaFile::sanitize(basename($path, "." . $ext)) . "." . (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg','jpeg','gif','png','heif']) ? 'mp4' : pathinfo($path, PATHINFO_EXTENSION));
                $preserve = privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename;
                if(!$ops->is_dir(dirname($preserve))) {
                    $ops->mkdir(dirname($preserve));
                }
                if(!$ops->file_exists($preserve)) {
                    echo PHP_EOL."Queue Processing of media file: " . $preserve;
                    $privuma->getQueueManager()->enqueue(json_encode([
                        'type' => 'processMedia',
                        'data' => [
                            'album' => $album,
                            'filename' => mediaFile::sanitize(basename($path, "." . $ext)) . "." . $ext,
                            'path' => $path,
                            'local' => $sync['removeFromSource']
                        ],
                    ]));
                }
            }else if($sync['preserve']){
                $preserve = privuma::getDataFolder() . DIRECTORY_SEPARATOR . 'SCRATCH' . DIRECTORY_SEPARATOR .'Syncs' . DIRECTORY_SEPARATOR . basename(dirname($path)) . DIRECTORY_SEPARATOR . mediaFile::sanitize(basename($path, "." . pathinfo($path, PATHINFO_EXTENSION))) . "." . pathinfo($path, PATHINFO_EXTENSION);
                if(!$ops->file_exists($preserve)) {
                    echo PHP_EOL."Queue Processing of preservation file: " . $preserve;
                    $privuma->getQueueManager()->enqueue(json_encode([
                        'type' => 'processMedia',
                        'data' => [
                            'preserve' => $preserve,
                            'path' => $path,
                            'local' => $sync['removeFromSource']
                        ],
                    ]));
                }
            }
        } else if ($value != "." && $value != ".." && $value !== "@eaDir") {
            processDir($path, $sync);
        }
    }
}

echo PHP_EOL."Done";