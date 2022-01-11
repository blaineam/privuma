<?php

session_start();

require_once(__DIR__.'/../helpers/dotenv.php');
loadEnv(__DIR__ . '/../config/.env');
require(__DIR__ . '/../helpers/cloud-fs-operations.php'); 

$ops = new cloudFS\Operations();
$FALLBACK_ENDPOINT = get_env('FALLBACK_ENDPOINT');
$ENDPOINT = get_env('ENDPOINT');
$AUTHTOKEN = get_env('AUTHTOKEN');
$DEOVR_LOGIN = get_env('DEOVR_LOGIN');
$DEOVR_PASSWORD = get_env('DEOVR_PASSWORD');
$DEOVR_DATA_DIRECTORY = get_env('DEOVR_DATA_DIRECTORY');



function rollingTokens($seed) {
    $d1 = new \DateTime("yesterday");
    $d2 = new \DateTime("today");
    $d3 = new \DateTime("tomorrow");
    return [
        sha1(md5($d1->format('Y-m-d'))."-".$seed),
        sha1(md5($d2->format('Y-m-d'))."-".$seed),
        sha1(md5($d3->format('Y-m-d'))."-".$seed),
    ];
};


function getProtectedUrlForMediaPath($path, $use_fallback = false, $useMediaFile = false) {
    global $ENDPOINT;
    global $FALLBACK_ENDPOINT;
    global $AUTHTOKEN;
    $mediaFile = $useMediaFile ? 'media.mp4' : '';
    $uri = $mediaFile . "?token=" . rollingTokens($AUTHTOKEN)[1]  . "&media=" . urlencode(base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', $path)));
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
            $output = [...$output, findMedia($path .'/'.$obj['Name'])];
        } else {
            $ext = pathinfo($obj['Name'], PATHINFO_EXTENSION);
            if(in_array(strtolower($ext),  ["mp4"])){
                $filename = basename($obj['Name'], '.' . $ext);
                $thumbnailPath = $path .'/' . $filename . '.jpg';
                if($ops->is_file($thumbnailPath)) {
                    $attrs = get3dAttrs($filename);
                    $mediaPath = $path .'/'.$obj['Name'];

                    $output[] = [
                        "video_url" => $ENDPOINT . 'deovr/?id=' . ($id + 1) . '&media=' . base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', $path .'/'.$filename.'.'.$ext)),
                        "thumbnailUrl" => getProtectedUrlForMediaPath($path .'/' . $filename . '.jpg'),
                        "title" => $filename,
                        "videoLength" => 3600
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
    if(strpos($filename, "180_180x180_3dh_LR") !== false || strpos($filename, "LR_180") !== false) {
        $output['is3d'] = true;
        $output['screenType'] = "dome";
        $output['stereoMode'] = "sbs";
    } else {
        $output['is3d'] = false;
        $output['screenType'] = "flat";
    }


    if (strpos(strtoupper($filename), "SBS") !== false) {
        $output["stereoMode"] = "sbs";
    } else if (strpos(strtoupper($filename), "TB") !== false) {
        $output["stereoMode"] = "tb";
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

if(isset($_GET['media']) && isset($_GET['id'])) {
    $mediaPath = str_replace('..', '', str_replace('-----', DIRECTORY_SEPARATOR, base64_decode($_GET['media'])));

    $ext = pathinfo($mediaPath, PATHINFO_EXTENSION);
    $filename = basename($mediaPath, '.' . $ext);

    if($ops->is_file($mediaPath)) {

        $attrs = get3dAttrs($filename);

        header('Content-Type: application/json');
        echo json_encode([
            "encodings" =>
              [
                  [
                "name" => "h265",
                "videoSources" => [
                  [
                    "resolution" => getResolution($filename),
                    "url" => getProtectedUrlForMediaPath(dirname($mediaPath) .'/' . $filename . '.' . $ext, false, true)
                  ]
                ]
              ]
            ],
            "title" => $filename,
            "id" => $_GET['id'],
            "videoLength" => 3600,
            "is3d" => $attrs['is3d'],
            "skipIntro" => 0,
            "screenType" => $attrs['screenType'],
            "stereoMode" => $attrs['stereoMode'],
            "thumbnailUrl" => getProtectedUrlForMediaPath(dirname($mediaPath) .'/' . $filename . '.jpg')
        ]);
        die();
    } else {
        http_response_code(404);
        die();
    }

}


$media = findMedia($DEOVR_DATA_DIRECTORY);

$deoJSON = [
    "scenes"=> [
        [
            "name" => "Privuma",
            "list" => $media
        ]
        ],
    "authorized" => "1"
        ];

header('Content-Type: application/json');
echo json_encode($deoJSON);