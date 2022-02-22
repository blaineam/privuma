<?php

use privuma\helpers\mediaFile;
use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = new privuma();

$deovrSites = json_decode(file_get_contents($privuma->getConfigDirectory() . DIRECTORY_SEPARATOR . 'deovr-cacher.json'), true) ?? [];

foreach($deovrSites as $url => $config) {

    echo PHP_EOL. "Checking DeoVR site for new media to cache: " . $url;
    if($search = $config['search']) {
        $json = json_decode(getUrlWithAuth($url, $config['login'], $config['password']), true);
        $LibaryKey = array_search("Library", array_column($json['scenes'], 'name'));
        $output = [];
        $changed = false;
        foreach($search as $s){
            $output[$s] = [
                "name" => $config['name'] . " - " . $s,
                "list" => []
            ];
        }
        foreach($json['scenes'][$LibaryKey]['list'] as $video) {
            foreach($search as $s) {
                if(strpos(strtolower($video['title']), strtolower($s)) !== false) {
                    $vjson = json_decode(getUrlWithAuth($video['video_url'], $config['login'], $config['password']), true);
                    $vjson['authorized'] = 1;
                    $encodingKey = array_search('h265', array_column($vjson['encodings'], 'name'));

                    $vs = end($vjson['encodings'][$encodingKey]['videoSources']);
                    $videoUrl = $vs['url'];
                    $vjson['encodings'] = [$vjson['encodings'][$encodingKey]];
                    $vjson['encodings'][0]['videoSources'] = [$vs];

                    $filename = explode('?', basename($videoUrl))[0];
                    $preserve = mediaFile::sanitize($privuma->getEnv("DEOVR_DATA_DIRECTORY") . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . str_replace('.mp4', '', $filename)) . ".mp4";
                    if(!$privuma->getCloudFS()->is_file($preserve)) {
                        echo PHP_EOL."Queueing deovr download to: " . $preserve;
                        $privuma->getQueueManager()->enqueue(json_encode([
                            'type' => 'processMedia',
                            'data' => [
                                'preserve' => $preserve,
                                'url' => $videoUrl
                            ],
                        ]));
                        $changed = true;
                    }
                    $filename = explode('?', basename($vjson['videoPreview']))[0];
                    $preserve = mediaFile::sanitize($privuma->getEnv("DEOVR_DATA_DIRECTORY") . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . str_replace('.mp4', '', $filename)) . "_videoPreview.mp4";
                    if(!$privuma->getCloudFS()->is_file($preserve)) {
                        echo PHP_EOL."Queueing deovr download to: " . $preserve;
                        $privuma->getQueueManager()->enqueue(json_encode([
                            'type' => 'processMedia',
                            'data' => [
                                'preserve' => $preserve,
                                'url' => $vjson['videoPreview'],
                            ],
                        ]));
                    }
                    $filename = explode('?', basename($vjson['thumbnailUrl']))[0];
                    $preserve = mediaFile::sanitize($privuma->getEnv("DEOVR_DATA_DIRECTORY") . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . str_replace('.jpg', '', $filename)) . "_thumbnail.jpg";
                    if(!$privuma->getCloudFS()->is_file($preserve)) {
                        echo PHP_EOL."Queueing deovr download to: " . $preserve;
                        $privuma->getQueueManager()->enqueue(json_encode([
                            'type' => 'processMedia',
                            'data' => [
                                'preserve' => $preserve,
                                'url' => $vjson['thumbnailUrl'],
                            ],
                        ]));
                    }

                    $output[$s]['list'][] = $vjson;
                }
            }
        }
        if($changed) {
            echo PHP_EOL. "Saving DeoVR Cache";
            $privuma->getQueueManager()->enqueue(json_encode([
                'type' => 'cachePath',
                'data' => [
                    'cacheName' => 'deovr',
                    'key' => $url,
                    'value' => $output,
                ],
            ]));
        }

        echo PHP_EOL. "Done with search: " . json_encode($search);

        continue;
    }
}

function getUrlWithAuth($url, $login, $password) {
    $ch = curl_init( $url );
    curl_setopt( $ch, CURLOPT_POST, 1);

    if(isset($login) && isset($password)) {
        $myvars = 'login=' . $login . '&password=' . $password;
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $myvars);
    }

    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt( $ch, CURLOPT_HEADER, 0);
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);

    return curl_exec( $ch );
}
