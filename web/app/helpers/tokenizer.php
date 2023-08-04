<?php

namespace privuma\helpers;
use privuma\helpers\cloudFS;
use privuma\helpers\dotenv;

class tokenizer {
    private dotenv $env;

    function __construct() {
        $this->env = new dotenv();
    }

    public function mediaLink($path, $use_fallback = false, $noIp = false, $localOk = false) {
        if($localOk && is_string($this->env->get('CLOUDFS_HTTP_REMOTE')) && is_string($this->env->get('CLOUDFS_HTTP_ENDPOINT'))){
        	return "http://" . $this->env->get('CLOUDFS_HTTP_ENDPOINT') ."/" . ltrim(cloudFS::encode($path));
        }

        $FALLBACK_ENDPOINT = $this->env->get('FALLBACK_ENDPOINT');
        $ENDPOINT = $this->env->get('ENDPOINT');
        $AUTHTOKEN = $this->env->get('AUTHTOKEN');
        $STREAM_MEDIA_FROM_FALLBACK_ENDPOINT = $this->env->get('STREAM_MEDIA_FROM_FALLBACK_ENDPOINT');
        if ($STREAM_MEDIA_FROM_FALLBACK_ENDPOINT) {
            $use_fallback = true;
            $noIp = true;
        }
        $uri = "?token=" . $this->rollingTokens($AUTHTOKEN, $noIp)[1]  . "&media=" . urlencode(base64_encode(cloudFS::encode($path)));
        return $use_fallback ? $FALLBACK_ENDPOINT . $uri : $ENDPOINT . $uri;
    }

    public function rollingTokens($seed, $noIp = false) {
        date_default_timezone_set('America/Los_Angeles');
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            $_SERVER['REMOTE_ADDR'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
        }
        if (isset($_SERVER["HTTP_PVMA_IP"])) {
            $_SERVER['REMOTE_ADDR'] = $_SERVER["HTTP_PVMA_IP"];
        }
        if(!isset($_SERVER['REMOTE_ADDR'])) {
            $_SERVER['REMOTE_ADDR'] = file_get_contents("http://ipecho.net/plain");
        }

        $interval = 30;
        $count = 2;

        $interval = max(1, min($interval, 60));
        $count = max(1, $count);

        $tokens = [$seed];
        for($iteration=0;$iteration<=$count;$iteration++) {
            $modifiedMinutes = $iteration*$interval;
            $date = new \DateTime("-$modifiedMinutes minutes", new \DateTimeZone("America/Los_Angeles"));
            $date = $date->setTime($date->format('H'), round($date->format('i') / $interval) * $interval);
            $tokens[] = sha1(md5($date->format('Y-m-d H:i:s'))."-".$seed . "-" .
            ($noIp ? "" : $_SERVER['REMOTE_ADDR'] )) ;

        }

        for($iteration=1;$iteration<=$count;$iteration++) {
            $modifiedMinutes = $iteration*$interval;
            $date = new \DateTime("+$modifiedMinutes minutes", new \DateTimeZone("America/Los_Angeles"));
            $date = $date->setTime($date->format('H'), round($date->format('i') / $interval) * $interval);
            $tokens[] = sha1(md5($date->format('Y-m-d H:i:s'))."-".$seed . "-" .
            ($noIp ? "" : $_SERVER['REMOTE_ADDR'] )) ;
        }
        return $tokens;
    }

    public function checkToken($token, $seed) {
        return in_array($token, $this->rollingTokens($seed));
    }
}
