<?php

namespace privuma\actions;

use privuma\privuma;

class generateThumbnail
{
    public function __construct($data)
    {
        $ffmpegThreadCount = PHP_OS_FAMILY == 'Darwin' ? 4 : 1;
        $ffmpegPath = PHP_OS_FAMILY == 'Darwin' ? '/usr/local/bin/ffmpeg' : '/usr/bin/ffmpeg';

        if (isset($data['path']) && (strpos($data['path'], '.webm') !== false || strpos($data['path'], '.mp4') !== false)) {
            $tempFile = privuma::getCloudFS()->pull($data['path']);
            rename($tempFile, $tempFile . '.mp4');
            $tempFile = $tempFile . '.mp4';
            $newThumbTemp = tempnam(sys_get_temp_dir(), 'PVMA');
            rename($newThumbTemp, $newThumbTemp . '.jpg');
            $newThumbTemp = $newThumbTemp . '.jpg';
            $targetThumbnailPath = str_replace('.mp4', '.jpg', $data['path']);
            $time = shell_exec("$ffmpegPath -threads $ffmpegThreadCount -y -i " . escapeshellarg($tempFile) . " 2>&1 | grep Duration | awk '{print $2}' | tr -d , | awk -F ':' '{print ($3+$2*60+$1*3600)/2}'");
            if (is_null($time) || $time === false || strlen($time) === 0) {
                $time = '00:00:01.00';
            }
            exec('nice cpulimit -f -l ' . privuma::getEnv('MAX_CPU_PERCENTAGE') . " -- $ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y  -ss $time  -i " . escapeshellarg($tempFile) . ' -vcodec mjpeg -vframes 1 -an -f rawvideo ' . escapeshellarg($newThumbTemp) . ' > /dev/null', $void, $response);
            if ($response !== 0) {
                unset($response);
                unset($void);
                exec('nice cpulimit -f -l ' . privuma::getEnv('MAX_CPU_PERCENTAGE') . " --  $ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y -ss 00:00:01.00 -i " . escapeshellarg($tempFile) . ' -vcodec mjpeg -vframes 1 -an -f rawvideo ' . escapeshellarg($newThumbTemp) . ' > /dev/null', $void, $response);
            }

            if ($response == 0) {
                privuma::getCloudFS()->rename($newThumbTemp, $targetThumbnailPath, false);
                echo PHP_EOL . 'Succcessfully generated thumbnail: ' . $targetThumbnailPath;
            } else {
                echo PHP_EOL . 'Failed to generate thumbnail: ' . $targetThumbnailPath . ' From: ' . $data['path'];
            }
            is_file($tempFile) && unlink($tempFile);
            return;
        }
    }
}
