<?php

namespace privuma\actions;

use privuma\helpers\mediaFile;
use privuma\queue\QueueManager;
use privuma\helpers\cloudFS;

use privuma\privuma;

class processMedia {
    function __construct(array $data) {
        $qm = new QueueManager();
        if(isset($data['album']) && isset($data['filename'])) {
            if(isset($data['url'])) {
                $mediaFile = new mediaFile($data['filename'], $data['album']);
                if(!isset($data['cache']) || isset($data['download'])) {
                    $mediaFile = new mediaFile($data['filename'], $data['album'], null, null, null, null, $data['url'], isset($data['thumbnail']) ? $data['thumbnail'] : null );
                }
                $existingFile = $mediaFile->source();
                echo PHP_EOL."Loaded MediaFile: " . $mediaFile->path();
                if($existingFile === false || isset($data['download'])) {
                    if (isset($data['download'])) {
                        $downloadOps = new cloudFS($data['download'], true, '/usr/bin/rclone', null, true);
                        $mediaPreservationPath = str_replace([".mpg", ".mod", ".mmv", ".tod", ".wmv", ".asf", ".avi", ".divx", ".mov", ".m4v", ".3gp", ".3g2", ".mp4", ".m2t", ".m2ts", ".mts", ".mkv", ".webm"], '.mp4', $data['hash'] . "." . pathinfo($mediaFile->path(), PATHINFO_EXTENSION));
                        if($downloadOps->is_file($mediaPreservationPath)) {
                            echo PHP_EOL."Skip Existing Media already downloaded to: $mediaPreservationPath";
                            return;
                        }
                    }

                    if(!isset($data['cache']) && !isset($data['download'])) {
                        $mediaFile->save();
                        return;
                    }else if(
                        isset($data['download'])
                        && $mediaPath = $this->downloadUrl($data['url'])
                    ) {
                        if (in_array(md5_file($mediaPath), [
                            // Image Not Found
                            'a6433af4191d95f6191c2b90fc9117af',
                            // Empty File
                            'd41d8cd98f00b204e9800998ecf8427e',
                        ]) || !in_array(explode('/', strtolower(mime_content_type($mediaPath)))[0], ['image', 'video'])) {
                            echo PHP_EOL."Removing broken url from database";
                            $mediaFile->delete($data['hash']);
                            return;
                        }

                        echo PHP_EOL."Attempting to Compress Media: $mediaPath";
                        if(
                            (new preserveMedia([], $downloadOps))->compress($mediaPath, $mediaPreservationPath) 
                        ) {
                            is_file($mediaPath) && unlink($mediaPath);
                            echo PHP_EOL."Downloaded media to: $mediaPreservationPath";
                        } else {
                            echo PHP_EOL."Compression failed";
                            echo PHP_EOL."Downloading media to: $mediaPreservationPath";
                            $result1 = $downloadOps->rename($mediaPath, $mediaPreservationPath, false);
                        }
                       
                        if (
                            isset($data['thumbnail'])
                            && $thumbnailPath = $this->downloadUrl($data['thumbnail'])
                        ) {
                            $thumbnailPreservationPath = str_replace('.mp4', '.jpg', $mediaPreservationPath);
                            if(
                                (new preserveMedia([], $downloadOps))->compress($thumbnailPath, $thumbnailPreservationPath) 
                            ) {
                                is_file($thumbnailPath) && unlink($thumbnailPath);
                                echo PHP_EOL."Downloaded media to: $thumbnailPreservationPath";
                            } else {
                                echo PHP_EOL."Compression failed";
                                echo PHP_EOL."Downloading media to: $thumbnailPreservationPath";
                                $result2 = $downloadOps->rename($thumbnailPath, $thumbnailPreservationPath, false);
                            }
                        }
                        // if(!$mediaFile->dupe()){
                        //     //$mediaFile->save();
                        // }
                        return;

                    }else if($tempPath = $this->downloadUrl($data['url'])) {
                        echo PHP_EOL."Downloaded Media File to: " . $tempPath;
                        $qm->enqueue(json_encode(['type'=> 'preserveMedia', 'data' => ['path' => $tempPath, 'album' => $data['album'], 'filename' => $data['filename']]]));
                    } else {
                        echo PHP_EOL."Failed to obtain media file from url: " . $data['url'];

                        echo PHP_EOL."Removing broken url from database";
                        $mediaFile->delete($data['hash'] ?? null);
                    }
                } else {
                    echo PHP_EOL."Existing MediaFile located at: " . $existingFile . " For: " . $data['url'];
                }
                return;
            }

            $mediaFile = new mediaFile($data['filename'], $data['album']);
            $existingFile = $mediaFile->realPath();
            echo PHP_EOL."Loaded MediaFile: " . $mediaFile->path();
            if(isset($data['path'])) {
                if( $existingFile === false ) {
                    if($tempPath = $this->loadPath($data['path'], (isset($data['local']) ? true : false))) {
                        echo PHP_EOL."Pulled Media File to: " . $tempPath;
                        $qm->enqueue(json_encode(['type'=> 'preserveMedia', 'data' => ['path' => $tempPath, 'album' => $data['album'], 'filename' => $data['filename']]]));
                    } else {
                        echo PHP_EOL."Failed to obtain media file from path: " . $data['path'];
                    }
                } else {
                    unlink($data['path']);
                    echo PHP_EOL."Existing MediaFile located at: " . $existingFile . " For: " . $data['path'];
                }
            }
            return;
        }
        if((isset($data['url']) || isset($data['path'])) && isset($data['preserve']) && !privuma::getCloudFS()->is_file($data['preserve'])) {
            if($this->getDirectorySize(sys_get_temp_dir()) >= 1024 * 1024 * 1024 * 25) {
                echo PHP_EOL."Temp Directory full, cleaning temp director";
                foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR."*") as $file) {
                    if(time() - filectime($file) > 60 * 60 * 2){
                        unlink($file);
                    }
                }
                echo PHP_EOL."Requeue Message";
                $qm->enqueue(json_encode(['type'=> 'processMedia', 'data' => $data]));
                return;
            }

            if(isset($data['url'])) {
		if(isset($data['download'])) {
			$downloadOps = new cloudFS($data['download'], true, '/usr/bin/rclone', null, true);
			if($downloadOps->is_file($data['preserve'])) {
			    echo PHP_EOL."Skip Existing Media already downloaded to: " . $data['preserve'];
			    return;
			} else if($tempPath = $this->downloadUrl($data['url'])) {
			    echo PHP_EOL."Uploading Media to: " . $data['preserve'];
                                $downloadOps->rename($tempPath, $data['preserve'], false);
				return;
			}
		}
                if($tempPath = $this->downloadUrl($data['url'])) {
                    echo PHP_EOL."Downloaded Preservation File to: " . $tempPath;
                    $qm->enqueue(json_encode(['type'=> 'preserveMedia', 'data' => ['preserve' => $data['preserve'], 'skipThumbnail' => $data['skipThumbnail'], 'path' => $tempPath]]));
                } else {
                    echo PHP_EOL."Failed to obtain preserve file from url: " . $data['url'];
                }
            } else if(isset($data['path'])) {

                if($tempPath = $this->loadPath($data['path'], (isset($data['local']) ? true : false))) {
                    echo PHP_EOL."Using Preservation File at: " . $tempPath;
                    $qm->enqueue(json_encode(['type'=> 'preserveMedia', 'data' => ['preserve' => $data['preserve'], 'skipThumbnail' => $data['skipThumbnail'], 'path' => $tempPath]]));
                } else {
                    echo PHP_EOL."Failed to obtain preserve file from filesystem path: " . $data['path'];
                }
            }

        } else {
            echo PHP_EOL."Existing preserve file located at: " . $data['preserve'];
        }

    }

    private function downloadUrl(string $url): ?string {
        return (new curlDL($url))->getResult();
    }

    private function loadPath(string $path, bool $directPath = false): ?string {

        if(is_file($path)) {
            clearstatcache();
            if(!filesize($path)) {
                return null;
            }

            if($directPath) {
                return $path;
            }
            $tmpfile = tempnam(sys_get_temp_dir(), 'PVMA');
            copy($path, $tmpfile);
            rename( $tmpfile, $tmpfile . "." . pathinfo($path, PATHINFO_EXTENSION) );

            return $tmpfile . "." . pathinfo($path, PATHINFO_EXTENSION);
        }

        return privuma::getCloudFS()->pull($path);
    }

    private function getDirectorySize( $path )
    {
        if( !is_dir( $path ) ) {
            return 0;
        }

        $path   = strval( $path );
        $io     = popen( "ls -ltrR {$path} 2>/dev/null |awk '{print \$5}'|awk 'BEGIN{sum=0} {sum=sum+\$1} END {print sum}'", 'r' );
        $size   = intval( fgets( $io, 80 ) );
        pclose( $io );

        return $size;
    }

}

class curlDL{
    public $result;

    private string $cookiePath;

    function __construct($url){
        $this->cookiePath = privuma::getConfigDirectory() . DIRECTORY_SEPARATOR . 'cookies';
        $this->curl_rev_fgc($url);
    }

    function __toString(){
        return $this->result;
    }

    function getResult() {
        return $this->result;
    }

    private function get_cookies() {
        $return = null;

        foreach(glob($this->cookiePath . DIRECTORY_SEPARATOR . "*.txt") as $file) {
            $return .= file_get_contents($file).';';
        }
        return $return;
    }

    private function save_cookies($http_response_header) {
        foreach($http_response_header as $header) {
            if(substr($header, 0, 10) == 'Set-Cookie'){
                if(preg_match('@Set-Cookie: (([^=]+)=[^;]+)@i', $header, $matches)) {
                    $fp = fopen($this->cookiePath . DIRECTORY_SEPARATOR .$matches[2].'.txt', 'w');
                    fwrite($fp, $matches[1]);
                    fclose($fp);
                }
            }
        }
    }

    private function curl_rev_fgc($url){
        if(!file_exists($this->cookiePath )){
            mkdir($this->cookiePath . DIRECTORY_SEPARATOR , 0755, true);
        }

        $usragent = 'Mozilla/5.0 (compatible; privumabot/0.1; +https://privuma/bot.html)';


        $this->result = tempnam(sys_get_temp_dir(), 'PVMA-');
        $this->result .= '.' . pathinfo(explode('?', $url)[0], PATHINFO_EXTENSION);

        $fp = fopen($this->result, 'w');

        if($fp === false) {
            echo PHP_EOL. "Could not open temp file at path: " . $this->result;
            $this->result = null;
            return;
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_FILE, $fp);
        curl_setopt($curl, CURLOPT_USERAGENT, $usragent);

        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip,deflate');
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        if(!file_exists($this->cookiePath . DIRECTORY_SEPARATOR . 'curl.txt')){
            file_put_contents($this->cookiePath . DIRECTORY_SEPARATOR  . 'curl.txt',null);
        }
        curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookiePath . DIRECTORY_SEPARATOR . 'curl.txt');
        curl_setopt($curl, CURLOPT_COOKIEJAR,  $this->cookiePath . DIRECTORY_SEPARATOR  . 'curl.txt');

        $result = curl_exec($curl);
        if(empty($result) || !in_array(explode('/', mime_content_type($this->result))[0], ['image','video'])){
            echo PHP_EOL . 'Error fetching: '.htmlentities($url) . curl_error($curl);
            $this->result = null;
        }
        curl_close($curl);

        fclose($fp);

        return;
    }
}
