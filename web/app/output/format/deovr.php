<?php

namespace privuma\output\format;

session_start();

use privuma\helpers\dotenv;
use privuma\privuma;
use privuma\helpers\cloudFS;
use privuma\helpers\tokenizer;
use privuma\helpers\mediaFile;

$DEOVR_MIRROR = privuma::getEnv('DEOVR_MIRROR') ?? privuma::getEnv('RCLONE_DESTINATION');
$ops = new cloudFS($DEOVR_MIRROR);
$tokenizer = new tokenizer();
$FALLBACK_ENDPOINT = privuma::getEnv('FALLBACK_ENDPOINT');
$ENDPOINT = privuma::getEnv('ENDPOINT');
$AUTHTOKEN = privuma::getEnv('AUTHTOKEN');
$DEOVR_LOGIN = privuma::getEnv('DEOVR_LOGIN');
$DEOVR_PASSWORD = privuma::getEnv('DEOVR_PASSWORD');
$DEOVR_DATA_DIRECTORY = privuma::getEnv('DEOVR_DATA_DIRECTORY');

function getProtectedUrlForMediaPath($path, $use_fallback = false, $useMediaFile = false) {
    global $ENDPOINT;
    global $FALLBACK_ENDPOINT;
    global $AUTHTOKEN;
    global $tokenizer;
    $mediaFile = $useMediaFile ? 'media.mp4' : '';
    $uri = $mediaFile . "?token=" . $tokenizer->rollingTokens($AUTHTOKEN)[1]  . "&deovr=1&&media=" . urlencode(base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', $path)));
    return $use_fallback ? $FALLBACK_ENDPOINT . $uri : $ENDPOINT . $uri;
}

function findMedia($path) {
    global $ops;
    global $ENDPOINT;
    $cachePath = __DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."cache".DIRECTORY_SEPARATOR."deovr-fs.json";
    $currentTime = time();
    $lastRan = filemtime($cachePath) ?? $currentTime - 90 * 24 * 60 * 60;
    $cacheStillRecent = $currentTime - $lastRan < 90 * 24 * 60 * 60;
    $scan = ['.','..'];

    if(is_file($cachePath) && $cacheStillRecent) {
        $scan = json_decode(file_get_contents($cachePath), true);
    } else {
        $scan = $ops->scandir($path, true, true);
        if($scan === false) {
            $scan = [];
        } else {
            file_put_contents($cachePath, json_encode($scan, JSON_INVALID_UTF8_IGNORE+JSON_THROW_ON_ERROR));
        }
    }

    $output = [];
    foreach($scan as $id => $obj) {
        if($obj['IsDir']) {
            /* $output = [...$output, findMedia($path .'/'.$obj['Name'])]; */
        } else {
            $ext = pathinfo($obj['Name'], PATHINFO_EXTENSION);
            if(in_array(strtolower($ext),  ["mp4"])){
                $dir = dirname($obj['Path']);
                $filename = basename($obj['Name'], '.' . $ext);
                $thumbnailPath = $path .'/' . $filename . '.jpg';
                //if($ops->is_file($thumbnailPath)) {
                    if (!is_array($output[$dir])) {
                        $output[$dir] = [
                            "name" => $dir === '.' ? 'Privuma' : $dir,
                            "list" => [],
                        ];
                    }

                    $output[$dir]['list'][] = [
                        "video_url" => $ENDPOINT . 'deovr/?id=' . ($id + 1) . '&media=' . base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', $path .'/' . $dir . '/' .$filename.'.'.$ext)),
                        "thumbnailUrl" => getProtectedUrlForMediaPath($path .'/' . $dir . '/' . $filename . '.jpg'),
                        "title" => $filename,
                    ];
                //}
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

$htmlStyle = '
        <style>
            /* Box sizing rules */
            *,
            *::before,
            *::after {
            box-sizing: border-box;
            }

            /* Prevent font size inflation */
            html {
            -moz-text-size-adjust: none;
            -webkit-text-size-adjust: none;
            text-size-adjust: none;
            }

            /* Remove default margin in favour of better control in authored CSS */
            body, h1, h2, h3, h4, p,
            figure, blockquote, dl, dd {
            margin-block-end: 0;
            }

            /* Remove list styles on ul, ol elements with a list role, which suggests default styling will be removed */
            ul[role=\'list\'],
            ol[role=\'list\'] {
            list-style: none;
            }

            /* Set core body defaults */
            body {
            min-height: 100vh;
            line-height: 1.5;
            }

            /* Set shorter line heights on headings and interactive elements */
            h1, h2, h3, h4,
            button, input, label {
            line-height: 1.1;
            }

            /* Balance text wrapping on headings */
            h1, h2,
            h3, h4 {
            text-wrap: balance;
            }

            /* A elements that don\'t have a class get default styles */
            a:not([class]) {
            text-decoration-skip-ink: auto;
            color: currentColor;
            }

            /* Make images easier to work with */
            img,
            picture {
            display: block;
            }

            /* Inherit fonts for inputs and buttons */
            input, button,
            textarea, select {
            font: inherit;
            }

            /* Make sure textareas without a rows attribute are not tiny */
            textarea:not([rows]) {
            min-height: 10em;
            }

            /* Anything that has been anchored to should have extra scroll margin */
            :target {
            scroll-margin-block: 5ex;
            }

            body {
                background: black;
                color: white;
            }
            a {
                width: 43vw;
                height: 43vw;
                margin: 2.5vw;
                border-radius: 5vw;
                display:inline-block;
                overflow: hidden;
            }
            img {
                object-fit: cover;
                min-height: 100%;
                min-width: 100%;
                width: auto;
                height: auto;
            }
            video {
                width:100%;
                height: 100%;
            }
            input {
                display: block;
                width: 90%;
                margin: 5% auto;
                border: none;
                border: solid 0px transparent;
                border-radius: 10px;
                overflow:hidden;
                color: white;
                background: dimgray;
                padding: 2px 5px;
            }
            input[type="submit"] {
                background: CornflowerBlue;
            }
        </style>
';

$loginForm = '<html>
    <head>
' . $htmlStyle . '
    <head>
    <body>
        <form method="post">
            <input type="text" name="login" placeholder="login">
            <input type="password" name="password" placeholder="password">
            <input type="submit">
        </form>
    </body>
</html>';
$responseTypeJson = true;
if (isset($_GET['html'])) {
    $responseTypeJson = false;
    echo '<!DOCTYPE html>';
}

if(!isset($_SESSION['deoAuthozied'])){
    if(isset($_POST['login']) && isset($_POST['password'])) {
        if($_POST['login'] === $DEOVR_LOGIN && $_POST['password'] === $DEOVR_PASSWORD) {
            $_SESSION['deoAuthozied'] = true;
        } else {
            if ($responseTypeJson) {
                header('Content-Type: application/json');
                echo json_encode($unauthorizedJson);
                die();
            } else {
                echo $loginForm;
                die();
            }
        }
    } else {
        if ($responseTypeJson) {
            header('Content-Type: application/json');
            $unauthorizedJson['authorized'] = "0";
            echo json_encode($unauthorizedJson);
            die();
        } else {
            echo $loginForm;
            die();
        }
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
        $output['is3d'] = true;
        $output['screenType'] = "flat";
        $output['stereoMode'] = "sbs";
    }

    if (strpos(strtoupper($filename), "SBS") !== false || strpos(strtoupper($filename), "LR_180") !== false ) {
        $output["stereoMode"] = "sbs";
    } else if (strpos(strtoupper($filename), "TB") !== false) {
        $output["stereoMode"] = "tb";
    } else if(!isset($output['stereoMode'])) {
        $output["stereoMode"] = "off";
    }

    if (strpos(strtoupper($filename), "FLAT") !== false) {
        $output['is3d'] = false;
        $output['screenType'] = "flat";
        $output['stereoMode'] = "off";
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
                        $thumbnailUrl = getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode("?",$scenes['list'][$k]['thumbnailUrl'])[0], '.jpg') . "_thumbnail.jpg");
                        if ($responseTypeJson) {
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
                            "thumbnailUrl" => $thumbnailUrl,
                            "is3d" => $scenes['list'][$k]['is3d'],
                            "viewAngle" => $scenes['list'][$k]['viewAngle'],
                            "stereomode" => $scenes['list'][$k]['stereomode'],
                            "projection" => $scenes['list'][$k]['projection'],
                            "projectID" => $scenes['list'][$k]['projectID'],
                            "screenType" => isset($scenes['list'][$k]['screenType']) ? $scenes['list'][$k]['screenType']: null,
                            ], function($value) { return !is_null($value) && $value !== ''; }));
                            die();
                        } else {
                            echo '
                                <html>
    <head>
' . $htmlStyle . '
    <head>
                                    <body>
                                        <video autoplay muted controls src="' . $scenes['list'][$k]["encodings"][0]["videoSources"][0]["url"] . '" poster="' . $thumbnailUrl . '" playsinline>
                                    </body>
                                </html>
                            ';
                            die();
                        }
                    }
                }
            }
        }
    }

    $mediaPath = str_replace('/../', '/', str_replace('-----', DIRECTORY_SEPARATOR, base64_decode($_GET['media'])));
    $ext = pathinfo($mediaPath, PATHINFO_EXTENSION);
    $filename = basename($mediaPath, '.' . $ext);
    $attrs = []; //get3dAttrs($filename);

    if($responseTypeJson) {
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
            "is3d" => true,
            "thumbnailUrl" => getProtectedUrlForMediaPath(dirname($mediaPath) .'/' . $filename . '.jpg'),

        ], $attrs));

        die();
    } else {
        echo '
            <html>
    <head>
' . $htmlStyle . '
    <head>
                <body>
                    <video autoplay controls muted src="' . getProtectedUrlForMediaPath(dirname($mediaPath) .'/' . $filename . '.' . $ext, false, false) . '" poster="' . getProtectedUrlForMediaPath(dirname($mediaPath) .'/' . $filename . '.jpg') . '" playsinline>
                </body>
            </html>
        ';
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
if($responseTypeJson) {
    $deoJSON = [
        "scenes"=> [
            ...array_values($media),
            ...array_values($cached)
            ],
        "authorized" => "1"
            ];

    header('Content-Type: application/json');
    echo json_encode($deoJSON);
} else {
    echo '<html>
    <head>
' . $htmlStyle . '
    <head>
        <body>';
    $scenes = [
            ...array_values($media),
            ...array_values($cached)
            ];
    foreach( $scenes as $scene) {
        echo '<h2>' . $scene['name'] . '</h2>';
        foreach($scene['list'] as $item) {
            echo ' <a href="' . $item['video_url'] . '&html=1"><img  loading="lazy" src="' . $item['thumbnailUrl'] . '" /></a> ';
        }
    }
    echo ' </body>
        </html>';
}
