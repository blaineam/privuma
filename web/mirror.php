<?php

$dataDirectory = "/data";

function get_mime_by_filename($filename) {
    if (!is_file(__DIR__ . DIRECTORY_SEPARATOR .'mimes.json')) {
       $db = json_decode(file_get_contents('https://cdn.jsdelivr.net/gh/jshttp/mime-db@master/db.json'), true);
       $mime_types = [];
       foreach ($db as $mime => $data) {
           if (!isset($data['extensions'])) {
               continue;
           }
           foreach ($data['extensions'] as $extension) {
               $mime_types[$extension] = $mime;
           }
       }

       file_put_contents(__DIR__ . DIRECTORY_SEPARATOR .'mimes.json', json_encode($mime_types, JSON_PRETTY_PRINT));
    }

    $mime_types = json_decode(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR .'mimes.json'), true);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (array_key_exists($ext, $mime_types)) {
        return $mime_types[$ext];
    }
    else {
        return 'application/octet-stream';
    }
}

function streamFile($file) {
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    if (!in_array(strtolower($ext), ['pdf', 'mp4', 'jpg', 'jpeg', 'gif', 'png', 'webm', 'mov'])) {
        return false;
    }

	header('Content-Type: ' . get_mime_by_filename($file));
    header('X-Accel-Redirect: ' . $file);
    die();
}

function roundToNearestMinuteInterval(\DateTime $dateTime, $minuteInterval = 60)
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

if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
}
if (isset($_SERVER["HTTP_PVMA_IP"])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER["HTTP_PVMA_IP"];
}

if(!isset($_SERVER['AUTHTOKEN']) && is_file($dataDirectory.'/AUTHTOKEN.txt')) {
	$_SERVER['AUTHTOKEN'] = file_get_contents($dataDirectory.'/AUTHTOKEN.txt');
}

function rollingTokens($seed, $noIp = true) {
    $d1 = new \DateTime("now", new \DateTimeZone("America/Los_Angeles"));
    $d2 = new \DateTime("now", new \DateTimeZone("America/Los_Angeles"));
    $d3 = new \DateTime("now", new \DateTimeZone("America/Los_Angeles"));
    $d1->modify('-4 hours');
    $d3->modify('+4 hours');
    $d1 = roundToNearestMinuteInterval($d1, 60*4);
    $d2 = roundToNearestMinuteInterval($d2, 60*4);
    $d3 = roundToNearestMinuteInterval($d3, 60*4);
    return [
        sha1(md5($d1->format('Y-m-d H:i:s'))."-".$seed . "-" .
         ($noIp ? "" : $_SERVER['REMOTE_ADDR'] )),
        sha1(md5($d2->format('Y-m-d H:i:s'))."-".$seed . "-" .
          ($noIp ? "" : $_SERVER['REMOTE_ADDR'] ) ),
        sha1(md5($d3->format('Y-m-d H:i:s'))."-".$seed . "-" .
 ($noIp ? "" : $_SERVER['REMOTE_ADDR'] ) ),
		$seed,
    ];
};

function checkToken($token, $seed) {
    return in_array($token, rollingTokens($seed));
}

if(isset($_GET['token']) && checkToken($_GET['token'], $_SERVER['AUTHTOKEN']) && isset($_GET['media'])) {
    streamFile($dataDirectory.base64_decode($_GET['media']));
}

http_response_code(404);

