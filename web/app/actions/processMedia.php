<?php

namespace privuma\actions;

use privuma\helpers\mediaFile;
use privuma\queue\QueueManager;

use privuma\privuma;

class processMedia {
    function __construct(array $data) {
        $qm = new QueueManager();
        if(isset($data['album']) && isset($data['filename'])) {
            $mediaFile = new mediaFile($data['filename'], $data['album']);
            $existingFile = $mediaFile->realPath();
            echo PHP_EOL."Loaded MediaFile: " . $mediaFile->path();
            if(isset($data['url'])) {
                if($existingFile === false) {
                    if($tempPath = $this->downloadUrl($data['url'])) {
                        echo PHP_EOL."Downloaded Media File to: " . $tempPath;
                        $qm->enqueue(json_encode(['type'=> 'preserveMedia', 'data' => ['path' => $tempPath, 'album' => $data['album'], 'filename' => $data['filename']]]));
                    } else {
                        echo PHP_EOL."Failed to obtain media file from url: " . $data['url'];
                    }
                } else {
                    echo PHP_EOL."Existing MediaFile located at: " . $existingFile . " For: " . $data['path']; 
                }
                return;
            }

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
            if($this->getDirectorySize(sys_get_temp_dir()) >= 1024 * 1024 * 1024 * 10) {
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
                if($tempPath = $this->downloadUrl($data['url'])) {
                    echo PHP_EOL."Downloaded Media File to: " . $tempPath;
                    $qm->enqueue(json_encode(['type'=> 'preserveMedia', 'data' => ['preserve' => $data['preserve'], 'path' => $tempPath]]));
                } else {
                    echo PHP_EOL."Failed to obtain preserve file from url: " . $data['url'];
                }
            } else if(isset($data['path'])) {

                if($tempPath = $this->loadPath($data['path'], (isset($data['local']) ? true : false))) {
                    echo PHP_EOL."Using Media File at: " . $tempPath;
                    $qm->enqueue(json_encode(['type'=> 'preserveMedia', 'data' => ['preserve' => $data['preserve'], 'path' => $tempPath]]));
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
        $io     = popen( "ls -ltrR {$path} |awk '{print \$5}'|awk 'BEGIN{sum=0} {sum=sum+\$1} END {print sum}'", 'r' );
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
        if(empty($result)){
            echo PHP_EOL . 'Error fetching: '.htmlentities($url) . curl_error($curl);
            $this->result = null;
        }
        curl_close($curl);

        fclose($fp);

        return;
    }
}