<?php

namespace privuma\output\format;

session_start();


use privuma\helpers\dotenv;
use privuma\privuma;
use privuma\helpers\cloudFS;
use privuma\helpers\mediaFile;

$DEOVR_MIRROR = privuma::getEnv('DEOVR_MIRROR') ?? privuma::getEnv('RCLONE_DESTINATION');
$ops = new cloudFS($DEOVR_MIRROR);
$FALLBACK_ENDPOINT = privuma::getEnv('FALLBACK_ENDPOINT');
$ENDPOINT = privuma::getEnv('ENDPOINT');
$AUTHTOKEN = privuma::getEnv('AUTHTOKEN');
$DEOVR_LOGIN = privuma::getEnv('DEOVR_LOGIN');
$DEOVR_PASSWORD = privuma::getEnv('DEOVR_PASSWORD');
$DEOVR_DATA_DIRECTORY = privuma::getEnv('DEOVR_DATA_DIRECTORY');


function roundToNearestMinuteInterval(\DateTime $dateTime, $minuteInterval = 10)
{
    return $dateTime->setTime(
        $dateTime->format('H'),
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



  //die("-" . $_SERVER['HTTP_USER_AGENT'] . "-" . $_SERVER['REMOTE_ADDR'] );
function rollingTokens($seed) {
    $d1 = new \DateTime("now", new \DateTimeZone("America/Los_Angeles"));
    $d2 = new \DateTime("now", new \DateTimeZone("America/Los_Angeles"));
    $d3 = new \DateTime("now", new \DateTimeZone("America/Los_Angeles"));
    $d1->modify('-12 hours');
    $d3->modify('+12 hours');
    $d1 = roundToNearestMinuteInterval($d1, 60*12);
    $d2 = roundToNearestMinuteInterval($d2, 60*12);
    $d3 = roundToNearestMinuteInterval($d3, 60*12);
    return [
        sha1(md5($d1->format('Y-m-d H:i:s'))."-".$seed . "-" .
//          $_SERVER['HTTP_USER_AGENT'] . "-" .
          $_SERVER['REMOTE_ADDR'] ),
        sha1(md5($d2->format('Y-m-d H:i:s'))."-".$seed . "-" .
//          $_SERVER['HTTP_USER_AGENT'] . "-" .
          $_SERVER['REMOTE_ADDR'] ),
        sha1(md5($d3->format('Y-m-d H:i:s'))."-".$seed . "-" .
//          $_SERVER['HTTP_USER_AGENT'] . "-" .
 $_SERVER['REMOTE_ADDR'] ),
    ];
};


function getProtectedUrlForMediaPath($path, $use_fallback = false, $useMediaFile = false) {
    global $ENDPOINT;
    global $FALLBACK_ENDPOINT;
    global $AUTHTOKEN;
    $mediaFile = $useMediaFile ? 'media.mp4' : '';
    $uri = $mediaFile . "?token=" . rollingTokens($AUTHTOKEN)[1]  . "&deovr=1&&media=" . urlencode(base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', $path)));
    return $use_fallback ? $FALLBACK_ENDPOINT . $uri : $ENDPOINT . $uri;
}

function findMedia($path) {
    global $ops;
    global $ENDPOINT;
    $scan = $ops->scandir($path, true);
    if($scan === false) {
        return [];
    }

    $output = [];
    foreach($scan as $id => $obj) {
        if($obj['IsDir']) {
            /* $output = [...$output, findMedia($path .'/'.$obj['Name'])]; */
        } else {
            $ext = pathinfo($obj['Name'], PATHINFO_EXTENSION);
            if(in_array(strtolower($ext),  ["mp4"])){
                $filename = basename($obj['Name'], '.' . $ext);
                $thumbnailPath = $path .'/' . $filename . '.jpg';
                if($ops->is_file($thumbnailPath)) {
                    $output[] = [
                        "video_url" => $ENDPOINT . 'deovr/?id=' . ($id + 1) . '&media=' . base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', $path .'/'.$filename.'.'.$ext)),
                        "thumbnailUrl" => getProtectedUrlForMediaPath($path .'/' . $filename . '.jpg'),
                        "title" => $filename,
                    ];
                }
            }
        }
    }
    return $output;

}

$unauthorizedJson = [
    "scenes"=> [
        [
            "name" => "Privuma",
            "list" => []
        ]
        ],
        "authorized" => "-1"
        ];

if(!isset($_SESSION['deoAuthozied'])){
    if(isset($_POST['login']) && isset($_POST['password'])) {
        if($_POST['login'] === $DEOVR_LOGIN && $_POST['password'] === $DEOVR_PASSWORD) {
            $_SESSION['deoAuthozied'] = true;
        } else {
            header('Content-Type: application/json');
            echo json_encode($unauthorizedJson);
            die();
        }
    } else {
        header('Content-Type: application/json');
        $unauthorizedJson['authorized'] = "0";
        echo json_encode($unauthorizedJson);
        die();
    }
}


function get3dAttrs($filename) {
    $output = [];
    if(strpos(strtoupper($filename), "MONO_360") !== false ) {
        $output['is3d'] = false;
        $output['viewAngle'] = 360;
        $output['stereomode'] = "mono";
        $output['projection'] = "unset";
        $output['projectID'] = 1;
        return $output;
    } else if(strpos($filename, "180_180x180_3dh_LR") !== false || strpos($filename, "LR_180") !== false) {
        $output['is3d'] = true;
        $output['screenType'] = "dome";
        $output['stereoMode'] = "sbs";
    } else {
        $output['is3d'] = false;
        $output['screenType'] = "flat";
    }


    if (strpos(strtoupper($filename), "SBS") !== false || strpos(strtoupper($filename), "LR_180") !== false ) {
        $output["stereoMode"] = "sbs";
    } else if (strpos(strtoupper($filename), "TB") !== false) {
        $output["stereoMode"] = "tb";
    } else {
        $output["stereoMode"] = "off";
    }



    if (strpos(strtoupper($filename), "FLAT") !== false) {
        $output['is3d'] = false;
        $output['screenType'] = "flat";
    } else if (strpos(strtoupper($filename), "DOME") !== false) {
        $output['is3d'] = true;
        $output['screenType'] = "dome";
    } else if (strpos(strtoupper($filename), "SPHERE") !== false) {
        $output['is3d'] = true;
        $output['screenType'] = "sphere";
    } else if (strpos(strtoupper($filename), "FISHEYE") !== false) {
        $output['is3d'] = true;
        $output['screenType'] = "fisheye";
    } else if (strpos(strtoupper($filename), "MKX200") !== false) {
        $output['is3d'] = true;
        $output['screenType'] = "mkx200";
        $output["stereoMode"] = "sbs";
    }
    else if (strpos(strtoupper($filename), "360") !== false) {
        $output['is3d'] = true;
        $output['screenType'] = "sphere";
    }

    return $output;
}

function getResolution($filename) {

    if (strpos($filename, "1080") !== false) {
        return 1080;
    } else if (strpos($filename, "1920") !== false) {
        return 1920;
    } else if (strpos($filename, "1440") !== false) {
        return 1440;
    } else if (strpos($filename, "2160") !== false) {
        return 2160;
    } else if (strpos($filename, "2880") !== false) {
        return 2880;
    } else if (strpos($filename, "3360") !== false) {
        return 3360;
    } else if (strpos($filename, "3840") !== false) {
        return 3840;
    } else {
        return 1440;
    }
}

$json = json_decode(file_get_contents(privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'deovr.json'), true) ?? [];
if(isset($_GET['media']) && isset($_GET['id'])) {
    if($_GET['media'] === 'cached'){
        foreach($json as $site => $search){
            foreach($search as $s => $scenes) {
                foreach($scenes['list'] as $k => $scene) {
                    if($_GET['id'] == $scenes['list'][$k]['id']) {
                        $originalUrl = $scenes['list'][$k]["encodings"][0]["videoSources"][0]["url"];
                        $scenes['list'][$k]["encodings"][0]["videoSources"][0]["url"] = getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode("?",$scenes['list'][$k]["encodings"][0]["videoSources"][0]["url"])[0]));
                        header('Content-Type: application/json');
                        echo json_encode(array_filter([
                        "encodings" => [
                            $scenes['list'][$k]["encodings"][0]
                        ],
                        "title" => $scenes['list'][$k]["title"],
                        "description" => $scenes['list'][$k]["description"],
                        "id" => $scenes['list'][$k]["id"],
                        "skipIntro" => 0,
                        "videoPreview" => getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode("?",$scenes['list'][$k]['videoPreview'])[0], '.mp4') . "_videoPreview.mp4"),
                        "thumbnailUrl" => getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode("?",$scenes['list'][$k]['thumbnailUrl'])[0], '.jpg') . "_thumbnail.jpg"),
                        "is3d" => $scenes['list'][$k]['is3d'],
                        "viewAngle" => $scenes['list'][$k]['viewAngle'],
                        "stereomode" => $scenes['list'][$k]['stereomode'],
                        "projection" => $scenes['list'][$k]['projection'],
                        "projectID" => $scenes['list'][$k]['projectID'],
                        "screenType" => isset($scenes['list'][$k]['screenType']) ? $scenes['list'][$k]['screenType']: null,
                        ], function($value) { return !is_null($value) && $value !== ''; }));
                        die();
                    }
                }
            }
        }

    }


    $mediaPath = str_replace('/../', '/', str_replace('-----', DIRECTORY_SEPARATOR, base64_decode($_GET['media'])));

    $ext = pathinfo($mediaPath, PATHINFO_EXTENSION);
    $filename = basename($mediaPath, '.' . $ext);

    if($ops->is_file($mediaPath)) {
        $attrs = get3dAttrs($filename);

        header('Content-Type: application/json');
        echo json_encode(array_merge([
            "encodings" =>
              [
                  [
                "name" => "h265",
                "videoSources" => [
                  [
                    "resolution" => getResolution($filename),
                    "url" => getProtectedUrlForMediaPath(dirname($mediaPath) .'/' . $filename . '.' . $ext, false, false)
                  ]
                ]
              ]
            ],
            "title" => $filename,
            "id" => $_GET['id'],
            "skipIntro" => 0,
            "thumbnailUrl" => getProtectedUrlForMediaPath(dirname($mediaPath) .'/' . $filename . '.jpg'),

        ], $attrs));

        die();
    } else {
        http_response_code(404);
        die();
    }

}

$media = findMedia($DEOVR_DATA_DIRECTORY);


$cached = [];
foreach($json as $site => $search){
    foreach($search as $s => $scenes) {
        foreach($scenes['list'] as $k => $scene) {
            $originalUrl = $scenes['list'][$k]["encodings"][0]["videoSources"][0]["url"];
            $scenes['list'][$k] = [
                "video_url" => $ENDPOINT . 'deovr/?id=' . $scenes['list'][$k]['id'] . '&media=cached',
                "videoPreview" => getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode("?",$scenes['list'][$k]['videoPreview'])[0], '.mp4') . "_videoPreview.mp4"),
                "thumbnailUrl" => getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode("?",$scenes['list'][$k]['thumbnailUrl'])[0], '.jpg') . "_thumbnail.jpg"),
                "title" => $scenes['list'][$k]["title"],
            ];
        }
        $cached[] = $scenes;
    }
}

$deoJSON = [
    "scenes"=> [
        [
            "name" => "Privuma",
            "list" => $media
        ],
        ...array_values($cached)
        ],
    "authorized" => "1"
        ];

header('Content-Type: application/json');
echo json_encode($deoJSON);
