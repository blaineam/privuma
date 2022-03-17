<?php

namespace privuma\actions;

use privuma\privuma;
use privuma\helpers\cloudFS;
use privuma\helpers\mediaFile;
use privuma\queue\QueueManager;

class preserveMedia {

    private cloudFS $ops;

    function __construct(array $data) {
        $qm = new QueueManager();
        $this->ops = privuma::getCloudFS();
        if(isset($data['album']) && isset($data['filename'])) {
            echo PHP_EOL."creating media file with: " . json_encode([
                "filename" => $data['filename'],
                "album" => $data['album'],
                "path" => $data['path'],
                "hash" => md5_file($data['path'])
            ]);
            $mediaFile = new mediaFile(str_replace('.webm', '.mp4', $data['filename']), $data['album'], null, md5_file($data['path']));
            echo PHP_EOL."New mediaFile: " . $mediaFile->path();
            if($mediaFile->hashConflict()) {
                echo PHP_EOL."There was a hash conflict";
                unlink($data['path']);
                return;
            } else {
                echo PHP_EOL."Hash is new for album";
            }

            if($mediaFile->dupe()) {
                privuma::getCloudFS()->file_put_contents(privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path(), privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->original());
                if(!$mediaFile->save()) {
                    privuma::getCloudFS()->unlink(privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path());
                    echo PHP_EOL."Preservation Failed for: " . privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path();
                } else{
                    echo PHP_EOL."Preservation succcessful for: " . privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path();
                }
                unlink($data['path']);
                return;
            }else {
                echo PHP_EOL."Media File is an Original";
            }

            echo PHP_EOL."Compressing: " . $data['path'] . " To: ". privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path();
            if($this->compress($data['path'], privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path())) {
                if(!$mediaFile->save()) {
                    privuma::getCloudFS()->unlink(privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path());
                    echo PHP_EOL."Preservation Failed for: " . privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path();
                } else{
                    echo PHP_EOL."Preservation succcessful for: " . privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path();
                }
                $qm->enqueue(json_encode(['type'=> 'generateThumbnail', 'data' => ['path' => privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path()]]));
            } else {
                unlink($data['path']);
                echo PHP_EOL."Compression Failed for: " . $data['path'];
            }

            return;
        }

        if(isset($data['preserve']) && $this->ops->rename($data['path'], $data['preserve'], false) !== false) {
            $scan = $this->ops->scandir(dirname($data['preserve'], 2), true, false, null, true);
            if($scan !== false) {
                $value = $scan[array_search(basename(dirname($data['preserve'])), array_column($scan, 'Name'))];
                $value['Path'] = substr(ltrim(dirname($data['preserve']), DIRECTORY_SEPARATOR), strlen(privuma::getDataFolder() . DIRECTORY_SEPARATOR ));
                $qm->enqueue(json_encode(['type'=> 'cachePath', 'data' => [
                    'cacheName' => 'mediadirs',
                    'key' => dirname($data['preserve']),
                    'value' => $value,
                ]]));
            }
            $qm->enqueue(json_encode(['type'=> 'generateThumbnail', 'data' => ['path' => $data['preserve']]]));
        }
    }

    public static function compress(string $file, string $preserve): bool {
        $allowedPhotos = ["BMP", "GIF", "HEIC", "ICO", "JPG", "JPEG", "PNG", "TIFF", "WEBP"];
        $allowedVideos = ["MPG", "MOD", "MMV", "TOD", "WMV", "ASF", "AVI", "DIVX", "MOV", "M4V", "3GP", "3G2", "MP4", "M2T", "M2TS", "MTS", "MKV", "WEBM"];

        $ext = pathinfo($file, PATHINFO_EXTENSION);

        if(in_array(strtoupper($ext), $allowedPhotos)) {
            return self::compressPhoto($file, $preserve);
        } else if(in_array(strtoupper($ext), $allowedVideos)) {
            return self::compressVideo($file, $preserve);
        }else{
            echo PHP_EOL."Unsupported File Extension: " . $ext;
        }

        return false;

    }

    private static function compressVideo(string $file, string $preserve): bool {
        $ffmpegThreadCount = PHP_OS_FAMILY == 'Darwin' ? 4 : 1;
        $ffmpegVideoCodec = PHP_OS_FAMILY == 'Darwin' ? "h264_videotoolbox" : "h264";
        $ffmpegPath =  PHP_OS_FAMILY == 'Darwin' ? "/usr/local/bin/ffmpeg" : "/usr/bin/ffmpeg";
        $newFileTemp = tempnam(sys_get_temp_dir(), 'PVMA-');
        rename($newFileTemp, $newFileTemp . '.mp4');
        $newFileTemp = $newFileTemp . '.mp4';
        $cmd = "$ffmpegPath -threads $ffmpegThreadCount -hide_banner -loglevel error -y -fflags +genpts -i '" . $file . "' -c:v " . $ffmpegVideoCodec . " -r 24 -crf 24 -c:a aac -movflags +faststart -profile:v baseline -level 3.0 -pix_fmt yuv420p -vf \"scale='min(1920,iw+mod(iw,2))':'min(1080,ih+mod(ih,2)):flags=neighbor'\" '" . $newFileTemp . "'";
        //echo PHP_EOL."Runnning command: " . $cmd;
        exec($cmd, $void, $response);

        if ($response == 0) {
            echo PHP_EOL."ffmpeg was successful";
            $result = privuma::getCloudFS()->rename($newFileTemp, $preserve, false);
            is_file($newFileTemp) && unlink($newFileTemp);
            return $result;
        }else {
            echo PHP_EOL.implode(PHP_EOL,$void);
            unset($void);
        }

        is_file($newFileTemp) && unlink($newFileTemp);
        unlink($file);
        return false;

    }


    private static function compressPhoto($tempFile, $filePath): bool{
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        echo PHP_EOL."Compressing image: ".$filePath;

        $newFileTemp = tempnam(sys_get_temp_dir(), 'PVMA-');
        rename($newFileTemp, $newFileTemp . '.' . $ext);
        $newFileTemp = $newFileTemp . '.' . $ext;

        if (strtolower($ext) === "gif") {
            exec("/usr/bin/gifsicle -O3 --careful --conserve-memory --colors=100 --no-ignore-errors --no-warnings --crop-transparency --no-comments --no-extensions --no-names --resize-fit 1920x1920 '" . $tempFile . "' -o '" . $newFileTemp . "'", $void, $response);

            if($response == 0 ) {
                echo PHP_EOL."gifsicle was successful";
                $output =  privuma::getCloudFS()->rename($newFileTemp, $filePath, false);
            }else{
                echo PHP_EOL.implode(PHP_EOL,$void);
                unset($void);
                $output =  false;
            }
        } else {
            $path = '/usr/local/bin/mogrify';
            exec($path . ' -help 2>&1', $test, $binNotFound);
            if($binNotFound !== 0){
                $path = '/usr/bin/mogrify';
            }
            exec($path . " -resize 1920x1920 -quality 60 -fuzz 7% '".$ext.':'.$tempFile."'", $void, $response);
            $is = getimagesize($tempFile);
            if($response == 0 ) {
                echo PHP_EOL."mogrify was successful";
                $output =  privuma::getCloudFS()->rename($tempFile, $filePath, false);
            }elseif((exif_imagetype($tempFile) || $is !== false) && filesize($tempFile) < 1024*1024*30) {
                echo PHP_EOL."mogrify failed but this is a reasonably sized image (<30MB), lets save it anyways";
                $output =  privuma::getCloudFS()->rename($tempFile, $filePath, false);
            }else{
                echo PHP_EOL.implode(PHP_EOL,$void);
                unset($void);
                $output =  false;
            }
        }
        is_file($tempFile) && unlink($tempFile);
        is_file($newFileTemp) && unlink($newFileTemp);
        return $output;
    }


}
