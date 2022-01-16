<?php

namespace privuma\output\format;

session_start();


use privuma\helpers\dotenv;
use privuma\privuma;
use privuma\helpers\cloudFS;
use privuma\helpers\mediaFile;

$ops = privuma::getCloudFS();
$FALLBACK_ENDPOINT = privuma::getEnv('FALLBACK_ENDPOINT');
$ENDPOINT = privuma::getEnv('ENDPOINT');
$AUTHTOKEN = privuma::getEnv('AUTHTOKEN');
$DEOVR_LOGIN = privuma::getEnv('DEOVR_LOGIN');
$DEOVR_PASSWORD = privuma::getEnv('DEOVR_PASSWORD');
$DEOVR_DATA_DIRECTORY = privuma::getEnv('DEOVR_DATA_DIRECTORY');



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
            //$output = [...$output, findMedia($path .'/'.$obj['Name'])];
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
                        //"videoLength" => 3600
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

if(isset($_GET['media']) && isset($_GET['id'])) {
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
                    "url" => getProtectedUrlForMediaPath(dirname($mediaPath) .'/' . $filename . '.' . $ext, false, true)
                  ]
                ]
              ]
            ],
            "title" => $filename,
            "id" => $_GET['id'],
            "skipIntro" => 0,
            "thumbnailUrl" => getProtectedUrlForMediaPath(dirname($mediaPath) .'/' . $filename . '.jpg'),
            
        ], $attrs));

        //echo '{"id":10715,"authorized":1,"title":"Mirror Squirting and Anal Fingering","projectID":1,"description":" ","fps":60,"viewAngle":360,"is3d":false,"paysite":{"id":85,"name":"perVRt","is3rdParty":true},"isPremium":true,"quantity":{"comments":8,"favorites":269,"purchases":7,"ja":{"favorites":0}},"positions":{"laying":false,"sitting":true,"leaning":false,"standing":false},"projection":"unset","stereomode":"mono","isFavorite":false,"thumbnailUrl":"https:\/\/cdn-vr.sexlikereal.com\/images\/10715\/67772_app_256.jpg","rating_avg":4,"skipIntro":0,"fullVideoReady":true,"videoLength":120,"fullVideoLength":840,"fullAccess":false,"videoThumbnail":"https:\/\/cdn-vr.sexlikereal.com\/preview\/500f_trailer\/10715_200p.mp4","videoPreview":"https:\/\/cdn-vr.sexlikereal.com\/preview\/14x1\/10715_300p.mp4","actors":[{"id":977,"name":"Francesca DiCaprio"},{"id":1407,"name":"Giorgia Roma"}],"categories":[{"niche":{"id":16,"name":"Brunette"},"tag":{"id":172,"name":"brunette"}},{"niche":{"id":48,"name":"Lesbian"},"tag":{"id":23,"name":"lesbian"}},{"niche":{"id":49,"name":"Masturbation"},"tag":{"id":684,"name":"anal masturbation"}},{"niche":{"id":209,"name":"No Male"},"tag":{"id":741,"name":"no male"}},{"niche":{"id":55,"name":"Nylons"},"tag":{"id":707,"name":"fishnet"}},{"niche":{"id":55,"name":"Nylons"},"tag":{"id":627,"name":"garter belt"}},{"niche":{"id":55,"name":"Nylons"},"tag":{"id":110,"name":"stockings"}},{"niche":{"id":65,"name":"POV"},"tag":{"id":711,"name":"Non POV"}},{"niche":{"id":191,"name":"Shaved Pussy"},"tag":{"id":662,"name":"shaved pussy"}},{"niche":{"id":163,"name":"Squirting"},"tag":{"id":42,"name":"squirting"}},{"niche":{"id":83,"name":"Toys"},"tag":{"id":677,"name":"magic wand"}},{"niche":{"id":83,"name":"Toys"},"tag":{"id":190,"name":"toys \/ dildos"}},{"niche":{"id":251,"name":"FPS"},"tag":{"id":800,"name":"60 FPS"}},{"niche":{"id":252,"name":"Resolution"},"tag":{"id":794,"name":"4K"}},{"niche":{"id":252,"name":"Resolution"},"tag":{"id":795,"name":"5K"}},{"niche":{"id":252,"name":"Resolution"},"tag":{"id":796,"name":"6K"}},{"niche":{"id":253,"name":"FOV"},"tag":{"id":799,"name":"360\u00b0"}},{"niche":{"id":29,"name":"Other European"},"tag":{"id":309,"name":"italian"}}],"encodings":[{"name":"h265","videoSources":[{"resolution":480,"height":480,"width":960,"size":8015317,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h265\/10715_480p.mp4"},{"resolution":720,"height":720,"width":1440,"size":18282757,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h265\/10715_720p.mp4"},{"resolution":1080,"height":1080,"width":2160,"size":49361562,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h265\/10715_1080p.mp4"},{"resolution":1440,"height":1440,"width":2880,"size":108054616,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h265\/10715_1440p.mp4"},{"resolution":1920,"height":1920,"width":3840,"size":148752054,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h265\/10715_1920p.mp4"},{"resolution":2160,"height":2160,"width":4320,"size":178975468,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h265\/10715_2160p.mp4"},{"resolution":2880,"height":2880,"width":5760,"size":265735403,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h265\/10715_2880p.mp4"}]},{"name":"h264","videoSources":[{"resolution":480,"height":480,"width":960,"size":9290549,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h264\/10715_480p.mp4"},{"resolution":720,"height":720,"width":1440,"size":17749567,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h264\/10715_720p.mp4"},{"resolution":1080,"height":1080,"width":2160,"size":41623948,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h264\/10715_1080p.mp4"},{"resolution":1440,"height":1440,"width":2880,"size":91173768,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h264\/10715_1440p.mp4"},{"resolution":1920,"height":1920,"width":3840,"size":148257874,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h264\/10715_1920p.mp4"},{"resolution":2160,"height":2160,"width":4320,"size":178367901,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h264\/10715_2160p.mp4"},{"resolution":2880,"height":2880,"width":5760,"size":265378166,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h264\/10715_2880p.mp4"}]},{"name":"h264_30","videoSources":[{"resolution":1080,"height":1080,"width":2160,"size":36844629,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h264_30\/10715_1080p.mp4"}]},{"name":"clearvr","videoSources":[{"codec":"h265","resolution":2688,"height":2688,"width":5376,"fps":59.97,"url":"https:\/\/cdn-vr.sexlikereal.com\/videos_app\/h265_clearvr_v5\/10715_2688\/manifest.json"}]}]}';

        die();
    } else {
        die("didn't make it");
        http_response_code(404);
        die();
    }

}

function getSLRVideoURL($url) {
    global $ops;
    global $DEOVR_DATA_DIRECTORY;
    $filename = explode('?', basename($url))[0];
    $mediaPath = $DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'slr' . DIRECTORY_SEPARATOR . $filename;
    if($ops->is_file($mediaPath)) {
        return getProtectedUrlForMediaPath($mediaPath);
    }
    return $url;
}


$media = findMedia($DEOVR_DATA_DIRECTORY);

$search = ["squirt"];

$json = json_decode(file_get_contents(privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'deovr.json'), true) ?? [];

$cached = [];
foreach($json as $site => $search){
    foreach($search as $s => $scene) {
        $cached[] = $scene;
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