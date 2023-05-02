<?php

namespace privuma\helpers;
use privuma\helpers\dotenv;

class tokenizer {
    private dotenv $env;

    function __construct() {
        $this->env = new dotenv();
    }

    public function mediaLink($path, $use_fallback = false, $noIp = false) {
        $FALLBACK_ENDPOINT = $this->env->get('FALLBACK_ENDPOINT');
        $ENDPOINT = $this->env->get('ENDPOINT');
        $AUTHTOKEN = $this->env->get('AUTHTOKEN');
        $uri = "?token=" . $this->rollingTokens($AUTHTOKEN, $noIp)[1]  . "&media=" . urlencode(base64_encode($path));
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

        $d1 = new \DateTime("now", new \DateTimeZone("America/Los_Angeles"));
        $d2 = new \DateTime("now", new \DateTimeZone("America/Los_Angeles"));
        $d3 = new \DateTime("now", new \DateTimeZone("America/Los_Angeles"));
        $d1->modify('-4 hours');
        $d3->modify('+4 hours');
        $d1 = $this->roundToNearestMinuteInterval($d1, 60*4);
        $d2 = $this->roundToNearestMinuteInterval($d2, 60*4);
        $d3 = $this->roundToNearestMinuteInterval($d3, 60*4);
        return [
            sha1(md5($d1->format('Y-m-d H:i:s'))."-".$seed . "-" .
    //          $_SERVER['HTTP_USER_AGENT'] . "-" .
            ($noIp ? "" : $_SERVER['REMOTE_ADDR'] ) ),
            sha1(md5($d2->format('Y-m-d H:i:s'))."-".$seed . "-" .
    //          $_SERVER['HTTP_USER_AGENT'] . "-" .
            ($noIp ? "" : $_SERVER['REMOTE_ADDR'] ) ),
            sha1(md5($d3->format('Y-m-d H:i:s'))."-".$seed . "-" .
    //          $_SERVER['HTTP_USER_AGENT'] . "-" .
            ($noIp ? "" : $_SERVER['REMOTE_ADDR'] ) ),
        ];
    }

    public function checkToken($token, $seed) {
        return in_array($token, $this->rollingTokens($seed));
    }

    private function roundToNearestMinuteInterval(\DateTime $dateTime, $minuteInterval = 10)
    {
        $hourInterval = 1;
        if($minuteInterval > 60) {
            $hourInterval = floor($minuteInterval/60);
            $minuteInterval = $minuteInterval - ($hourInterval * 60);
            if ($minuteInterval == 0) {
                $minuteInterval = 60;
            }
        }
        return $dateTime->setTime(
            round($dateTime->format('H') / $hourInterval) * $hourInterval,
            round($dateTime->format('i') / $minuteInterval) * $minuteInterval,
            0
        );

    }

}
