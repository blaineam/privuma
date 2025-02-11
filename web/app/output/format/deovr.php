<?php

namespace privuma\output\format;

session_start();

use privuma\privuma;
use privuma\helpers\cloudFS;
use privuma\helpers\tokenizer;

$DEOVR_MIRROR = privuma::getEnv('DEOVR_MIRROR') ?? privuma::getEnv('RCLONE_DESTINATION');
$ops = new cloudFS($DEOVR_MIRROR);
$opsNoEncode = new cloudFS($DEOVR_MIRROR, false);
$tokenizer = new tokenizer();
$FALLBACK_ENDPOINT = privuma::getEnv('FALLBACK_ENDPOINT');
$ENDPOINT = privuma::getEnv('ENDPOINT');
$AUTHTOKEN = privuma::getEnv('AUTHTOKEN');
$DEOVR_HOST = privuma::getEnv('DEOVR_HOST');
$DEOVR_LOGIN = privuma::getEnv('DEOVR_LOGIN');
$DEOVR_PASSWORD = privuma::getEnv('DEOVR_PASSWORD');
$DEOVR_DATA_DIRECTORY = privuma::getEnv('DEOVR_DATA_DIRECTORY');
$isDeoVR = isset($_GET['deovr']) && $_GET['deovr'] == 'true';
$ENDPOINT = ($isDeoVR && !is_null($DEOVR_HOST)) ? 'https://' . $DEOVR_HOST . '/' : $ENDPOINT;

function getProtectedUrlForMediaPath($path, $use_fallback = false, $useMediaFile = false, $isImage = false)
{
    global $ENDPOINT;
    global $FALLBACK_ENDPOINT;
    global $AUTHTOKEN;
    global $tokenizer;
    $mediaFile = $useMediaFile ? ($isImage ? 'media.jpg': 'media.mp4') : '';
    $uri = $mediaFile . '?token=' . $tokenizer->rollingTokens($AUTHTOKEN)[1] . '&deovr=1&&media=' . urlencode(base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', $path)));
    return $use_fallback ? $FALLBACK_ENDPOINT . $uri : $ENDPOINT . $uri;
}

function findMedia($path)
{
    global $ops;
    global $opsNoEncode;
    global $ENDPOINT;
    global $responseTypeJson;
    global $isDeoVR;
    global $DEOVR_DATA_DIRECTORY;
    $cachePath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'deovr-fs.json';
    $currentTime = time();
    $lastRan = filemtime($cachePath) ?? $currentTime - 90 * 24 * 60 * 60;
    $cacheStillRecent = $currentTime - $lastRan < 90 * 24 * 60 * 60;
    $scan = ['.', '..'];

    function _group_by($array, $key)
    {
        $return = array();
        foreach ($array as $val) {
            $return[$val[$key]][] = $val;
        }
        return $return;
    }

    function get_deovr_download($scan, $isHeresphere = false)
    {
        $videos = array_filter($scan, function ($item) { return $item['MimeType'] === 'video/mp4'; });
        usort($videos, function ($a, $b) {
            return ($a['ModTime'] === $b['ModTime']) ? 0 :  (($a['ModTime'] < $b['ModTime']) ? 1 : -1);
        });
        $structured = array_map(function ($item) use ($isHeresphere) {
            $prefix = !$isHeresphere ? privuma::getEnv('DEOVR_DOWNLOAD_MEDIA_PREFIX') : '';
            return [
              'scene' => dirname($item['Path']),
              'video_url' => $prefix . cloudFS::encode($item['Path']),
              'videoSrc' => $prefix . cloudFS::encode($item['Path']),
              'thumbnailUrl' => $prefix . cloudFS::encode(str_replace('.mp4', '.jpg', $item['Path'])),
              'thumbnailImage' => $prefix . cloudFS::encode(str_replace('.mp4', '.jpg', $item['Path'])),
              'title' => basename($item['Path'], '.mp4'),
              'skipIntro' => 0,
              ...get3dAttrs(basename($item['Path'], '.mp4')),
              'encodings' =>
                  [
                      [
                  'name' => 'h265',
                  'videoSources' => [
                      [
                      'resolution' => getResolution(basename($item['Path'], '.mp4')),
                      'url' => $prefix . cloudFS::encode($item['Path'])
                      ]
                  ]
                  ]
              ],
              'media' =>
                  [
                      [
                  'name' => 'h265',
                  'sources' => [
                      [
                      'resolution' => getResolution(basename($item['Path'], '.mp4')),
                      'url' => $prefix . cloudFS::encode($item['Path'])
                      ]
                  ]
                  ]
              ],
            ];
        }, $videos);
        return json_encode([
          (($isHeresphere) ? 'library' : 'scenes') => array_values(array_map(function ($item) {
              return [
                'name' => reset($item)['scene'],
                'list' => $item,
              ];
          }, _group_by($structured, 'scene'))),
          (($isHeresphere) ? 'access' : 'authorized') => 1
        ]
        );
    }

    if (is_file($cachePath) && $cacheStillRecent) {
        $json = file_get_contents($cachePath);
        $scan = json_decode($json, true);
        $target = $ops->encode($DEOVR_DATA_DIRECTORY) . DIRECTORY_SEPARATOR;
        $opsNoEncode->file_put_contents($target . 'deovr-fs.json', $json);
        $opsNoEncode->file_put_contents($target . 'deovr.json', get_deovr_download($scan));
        $opsNoEncode->file_put_contents($target . 'heresphere', get_deovr_download($scan, true));
        $viewerHTML = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'vr-viewer.html');
        $viewerHTML = str_replace(
            '{{DEOVR_DOWNLOAD_MEDIA_PREFIX}}',
            privuma::getEnv('DEOVR_DOWNLOAD_MEDIA_PREFIX'),
            $viewerHTML
        );
        $opsNoEncode->file_put_contents($target . 'index.html', $viewerHTML);
    } else {
        $scan = $ops->scandir($path, true, true);
        if ($scan === false) {
            $scan = [];
        } else {
            $json = json_encode($scan, JSON_INVALID_UTF8_IGNORE + JSON_THROW_ON_ERROR);
            file_put_contents($cachePath, $json);
            $target = $ops->encode($DEOVR_DATA_DIRECTORY) . DIRECTORY_SEPARATOR;
            $opsNoEncode->file_put_contents($target . 'deovr-fs.json', $json);
            $opsNoEncode->file_put_contents($target . 'deovr.json', get_deovr_download($scan));
            $opsNoEncode->file_put_contents($target . 'heresphere', get_deovr_download($scan, true));
            $viewerHTML = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'vr-viewer.html');

            $viewerHTML = str_replace(
                '{{DEOVR_DOWNLOAD_MEDIA_PREFIX}}',
                privuma::getEnv('DEOVR_DOWNLOAD_MEDIA_PREFIX'),
                $viewerHTML
            );
            $opsNoEncode->file_put_contents($target . 'index.html', $viewerHTML);
        }
    }

    $output = [];
    foreach ($scan as $id => $obj) {
        if ($obj['IsDir']) {
            /* $output = [...$output, findMedia($path .'/'.$obj['Name'])]; */
        } else {
            $ext = pathinfo($obj['Name'], PATHINFO_EXTENSION);
            if (in_array(strtolower($ext),  ['mp4'])) {
                $dir = dirname($obj['Path']);
                $filename = basename($obj['Name'], '.' . $ext);
                $thumbnailPath = $path . '/' . $filename . '.jpg';
                //if($ops->is_file($thumbnailPath)) {
                if (!is_array($output[$dir] ?? false)) {
                    $output[$dir] = [
                        'name' => $dir === '.' ? 'Privuma' : $dir,
                        'list' => [],
                    ];
                }

                $output[$dir]['list'][] = (!$isDeoVR && $responseTypeJson)
                    ? $ENDPOINT . ($responseTypeJson ? ($isDeoVR ? 'deovr' : 'heresphere') : 'vr') . '/?id=' . ($id + 1) . '&media=' . base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', $path . '/' . $dir . '/' . $filename . '.' . $ext))
                    : array_merge([
                        'video_url' => $ENDPOINT . ($responseTypeJson ? ($isDeoVR ? 'deovr' : 'heresphere') : 'vr') . '/?id=' . ($id + 1) . '&media=' . base64_encode(str_replace(DIRECTORY_SEPARATOR, '-----', $path . '/' . $dir . '/' . $filename . '.' . $ext)),
                        'thumbnailUrl' => getProtectedUrlForMediaPath($path . '/' . $dir . '/' . $filename . '.jpg'),
                        'thumbnailImage' => getProtectedUrlForMediaPath($path . '/' . $dir . '/' . $filename . '.jpg'),
                        'title' => $filename,
                        'videoSrc' => getProtectedUrlForMediaPath($path . '/' . $dir . '/' . $filename . '.' . $ext, false, true),
                    ],
                        get3dAttrs($filename),
                    );
                //}
            }
        }
    }

    return $output;
}

$unauthorizedJson = [
    ($isDeoVR ? 'scenes' : 'library') => [
        [
            'name' => 'Privuma',
            'list' => []
        ]
        ],
        ($isDeoVR ? 'authorized' : 'access') => -1,
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
						.fancybox-slide--iframe .fancybox-content {
    max-width  : 100%;
    max-height : 100%;
    margin: 0;
}


[data-tab-content] {
    display: none;
  }

  .active[data-tab-content] {
    display: block;
  }

  .tab-content {
    margin-top: 100px;
  }

  * {
    font-family: sans-serif;
  }


  .tabs {
    display: flex;
    justify-content: space-around;
    list-style-type: none;
    padding: 10px;
    top: 0;
    margin: 0;
    width: 100%;
    position: fixed;
    background-color: rgba(0,0,0,0.85);
  }

  .tab {
    cursor: pointer;
    padding: 10px 20px;
    border-radius: 30px;
    border: 1px solid #73859f;
    color: #ffffff;

  }

  .tab.active {
    background-color: #2B333F;
    border: 1px solid #2B333F;
  }

  .tab:hover {
    background-color: #73859f;
  }

        </style>
';

$loginForm = '<html>
    <head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
$responseTypeJson = false;
if (isset($_GET['json'])) {
    $responseTypeJson = true;
    header('HereSphere-JSON-Version: 1');
} else {
    echo '<!DOCTYPE html>';
}

if (!isset($_SESSION['deoAuthozied'])) {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $allowGetLogin = false;
    $username = $_POST['login'] ?? ($allowGetLogin ? $_GET['login'] : null) ?? $data['username'];
    $password = $_POST['password'] ?? ($allowGetLogin ? $_GET['password'] : null) ?? $data['password'];
    if (isset($username) && isset($password)) {
        if ($username === $DEOVR_LOGIN && $password === $DEOVR_PASSWORD) {
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
            $unauthorizedJson[($isDeoVR ? 'authorized' : 'access')] = 0;
            echo json_encode($unauthorizedJson);
            die();
        } else {
            echo $loginForm;
            die();
        }
    }
}

if ((isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 60 * 15)) || isset($_GET['logout'])) {
    // last request was more than 30 minutes ago
    session_unset();     // unset $_SESSION variable for the run-time
    session_destroy();   // destroy session data in storage
    header('Location: /' . ($responseTypeJson ? ($isDeoVR ? 'deovr' : 'heresphere') : 'vr'));
    die();
}
$_SESSION['LAST_ACTIVITY'] = time(); // update last activity time stamp

if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
} elseif (time() - $_SESSION['CREATED'] > 60 * 15) {
    // session started more than 30 minutes ago
    session_regenerate_id(true);    // change session ID for the current session and invalidate old session ID
    $_SESSION['CREATED'] = time();  // update creation time
}

function get3dAttrs($filename)
{
    $output = [];
    $upperFilename = strtoupper($filename);
    $filenameParts = array_map('trim', explode('_', $upperFilename));
    if (!(bool) array_intersect($filenameParts, [
        '180',
        '360',
        'FISHEYE',
        'FISHEYE190',
        'RF52',
        'MKX200',
        'VRCA220',
        'LR',
        '3DH',
        'SBS',
        'TB',
        '3DV',
        'OVERUNDER',
    ])) {
        return $output;
    }

    $output['is3d'] = !(bool) array_intersect($filenameParts, ['MONO']);
    $output['stereoMode'] = 'mono';
    $output['screenType'] = 'flat';
    if ((bool) array_intersect($filenameParts, [
        'LR',
        '3DH',
        'SBS',
    ])) {
        $output['stereoMode'] = 'sbs';
    }

    if ((bool) array_intersect($filenameParts, [
        'TB',
        '3DV',
        'OVERUNDER',
    ])) {
        $output['stereoMode'] = 'tb';
    }

    if ((bool) array_intersect($filenameParts, [
        '180',
        'FISHEYE',
        'FISHEYE190',
        'RF52',
        'MKX200',
        'VRCA220',
    ])) {
        $output['screenType'] = 'dome';
    }

    if ((bool) array_intersect($filenameParts, [
        '360',
    ])) {
        $output['screenType'] = 'sphere';
    }

    return $output;
}

function getResolution($filename)
{
    if (strpos($filename, '1080') !== false) {
        return 1080;
    } elseif (strpos($filename, '1920') !== false) {
        return 1920;
    } elseif (strpos($filename, '1440') !== false) {
        return 1440;
    } elseif (strpos($filename, '2160') !== false) {
        return 2160;
    } elseif (strpos($filename, '2880') !== false) {
        return 2880;
    } elseif (strpos($filename, '3360') !== false) {
        return 3360;
    } elseif (strpos($filename, '3840') !== false) {
        return 3840;
    } else {
        return 1440;
    }
}
$deovrJsonPath = privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'deovr.json';
$json = file_exists($deovrJsonPath) ? json_decode(file_get_contents($deovrJsonPath), true) ?? [] : [];
if (isset($_GET['media']) && isset($_GET['id'])) {
    if ($_GET['media'] === 'cached') {
        foreach ($json as $site => $search) {
            foreach ($search as $s => $scenes) {
                foreach ($scenes['list'] as $k => $scene) {
                    if ($_GET['id'] == $scenes['list'][$k]['id']) {
                        $originalUrl = $scenes['list'][$k]['encodings'][0]['videoSources'][0]['url'];
                        $scenes['list'][$k]['encodings'][0]['videoSources'][0]['url'] = getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode('?', $scenes['list'][$k]['encodings'][0]['videoSources'][0]['url'])[0]), false, true, false);
                        $thumbnailUrl = getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode('?', $scenes['list'][$k]['thumbnailUrl'])[0], '.jpg') . '_thumbnail.jpg', false, true, true);
                        if ($responseTypeJson) {
                            header('Content-Type: application/json');
                            echo json_encode(array_filter([
                            'encodings' => [
                                $scenes['list'][$k]['encodings'][0]
                            ],
                            'media' => [
                                [
                                    'name' => 'h265',
                                    'sources' => $scenes['list'][$k]['encodings'][0]['videoSources'],
                                ]
                            ],
                            'title' => $scenes['list'][$k]['title'],
                            'description' => $scenes['list'][$k]['description'],
                            'id' => $scenes['list'][$k]['id'],
                            'skipIntro' => 0,
                            'videoPreview' => getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode('?', $scenes['list'][$k]['videoPreview'])[0], '.mp4') . '_videoPreview.mp4', false, true, false),
                            'thumbnailUrl' => $thumbnailUrl,
                            'thumbnailImage' => $thumbnailUrl,
                            'is3d' => $scenes['list'][$k]['is3d'],
                            'viewAngle' => $scenes['list'][$k]['viewAngle'],
                            'stereomode' => $scenes['list'][$k]['stereomode'],
                            'projection' => $scenes['list'][$k]['projection'],
                            'projectID' => $scenes['list'][$k]['projectID'],
                            'screenType' => isset($scenes['list'][$k]['screenType']) ? $scenes['list'][$k]['screenType']: null,
                            ], function ($value) { return !is_null($value) && $value !== ''; }));
                            die();
                        } else {
                            echo '
                                <html>
                                    <head>
                                    <meta name="viewport" content="width=device-width, initial-scale=1">
                                    <meta charset="utf-8">
                                    ' . $htmlStyle . '
                                        <style>
                                        ' . file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '360player.css') . '
                                        </style>
                                    <head>
                                    <body>


  <div style="width:100%; height: calc( 100% - 25px ); display:block; position:absolute; margin:0; padding:0;top:0;left:0;"> <video poster="' . $thumbnailUrl . '" loop id="videojs-vr-player" class="video-js vjs-fill vjs-default-skin" playsinline controls>
                                            <source type="video/mp4" src="' . $scenes['list'][$k]['encodings'][0]['videoSources'][0]['url'] . '">
                                        </video>

    </div>
    <div style="position:absolute; height:25px; width:auto; display:block; bottom:0; left:0; padding:0; margin:0 auto; overflow:hidden;">
                                        <a target="_parent" href="' . $scenes['list'][$k]['encodings'][0]['videoSources'][0]['url'] . '">
                                            Download Video
                                        </a>
                                        <select id="actionMenu" onchange="selectAction(this)">
                                            <option value="">Select Projection</option>
                                            <option value="180">180</option>
                                            <option value="180_LR">180_LR</option>
                                            <option value="180_MONO">180_MONO</option>
                                            <option value="SBS">Side By Side</option>
                                            <option value="360">360</option>
                                            <option value="Cube">Cube</option>
                                            <option value="NONE">NONE</option>
                                            <option value="360_LR">360_LR</option>
                                            <option value="360_TB">360_TB</option>
                                            <option value="EAC">EAC</option>
                                            <option value="EAC_LR">EAC_LR</option>
                                        </select>
                                       </div> <script>
                                        ' . file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '360player.js') . '
                                        </script>
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
    $attrs = get3dAttrs($filename);

    if ($responseTypeJson) {
        header('Content-Type: application/json');
        echo json_encode(array_merge([
            'encodings' =>
                [
                    [
                'name' => 'h265',
                'videoSources' => [
                    [
                    'resolution' => getResolution($filename),
                    'url' => getProtectedUrlForMediaPath(dirname($mediaPath) . '/' . $filename . '.' . $ext, false, true, false)
                    ]
                ]
                ]
            ],
            'media' =>
                [
                    [
                'name' => 'h265',
                'sources' => [
                    [
                    'resolution' => getResolution($filename),
                    'url' => getProtectedUrlForMediaPath(dirname($mediaPath) . '/' . $filename . '.' . $ext, false, true, false)
                    ]
                ]
                ]
            ],
            'title' => $filename,
            'id' => $_GET['id'],
            'skipIntro' => 0,
            'is3d' => true,
            'thumbnailUrl' => getProtectedUrlForMediaPath(dirname($mediaPath) . '/' . $filename . '.jpg', false, true, true),
            'thumbnailImage' => getProtectedUrlForMediaPath(dirname($mediaPath) . '/' . $filename . '.jpg', false, true, true),

        ], (!$isDeoVR && $responseTypeJson) ? [] : $attrs));

        die();
    } else {
        echo '
            <html>
                <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                ' . $htmlStyle . '
                    <style>
                    ' . file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '360player.css') . '
                    </style>
                <head>
                <body>


  <div style="width:100%; height: calc( 100% - 25px ); display:block; position:absolute; margin:0; padding:0;top:0;left:0;">
                    <video poster="' . getProtectedUrlForMediaPath(dirname($mediaPath) . '/' . $filename . '.jpg') . '" loop id="videojs-vr-player" class="video-js vjs-fill vjs-default-skin" playsinline controls>
                        <source type="video/mp4" src="' . getProtectedUrlForMediaPath(dirname($mediaPath) . '/' . $filename . '.' . $ext, false, false) . '">
                    </video>
																						</div>
				  <div style="position:absolute; height:25px; width:auto; display:block; bottom:0; left:0; padding:0; margin:0 auto; overflow:hidden;">
                    <a target="_parent" href="' . getProtectedUrlForMediaPath(dirname($mediaPath) . '/' . $filename . '.' . $ext, false, true) . '">
                        Download Video
                    </a>

                    <select id="actionMenu" onchange="selectAction(this)">
                        <option value="">Select Projection</option>
                        <option value="180">180</option>
                        <option value="180_LR">180_LR</option>
                        <option value="180_MONO">180_MONO</option>
                        <option value="SBS">Side By Side</option>
                        <option value="360">360</option>
                        <option value="Cube">Cube</option>
                        <option value="NONE">NONE</option>
                        <option value="360_LR">360_LR</option>
                        <option value="360_TB">360_TB</option>
                        <option value="EAC">EAC</option>
                        <option value="EAC_LR">EAC_LR</option>
                    </select>
																																											</div>
                    <script>
                    ' . file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '360player.js') . '
                    </script>
                </body>
            </html>
        ';
        die();
    }
}

$media = findMedia($DEOVR_DATA_DIRECTORY);

$cached = [];
foreach ($json as $site => $search) {
    foreach ($search as $s => $scenes) {
        foreach ($scenes['list'] as $k => $scene) {
            $originalUrl = $scenes['list'][$k]['encodings'][0]['videoSources'][0]['url'];
            $scenes['list'][$k] = (!$isDeoVR && $responseTypeJson)
            ? $ENDPOINT . ($responseTypeJson ? ($isDeoVR ? 'deovr' : 'heresphere') : 'vr') . '/?id=' . $scenes['list'][$k]['id'] . '&media=cached'
            : [
                ...get3dAttrs($scenes['list'][$k]['title']),
                'video_url' => $ENDPOINT . ($responseTypeJson ? ($isDeoVR ? 'deovr' : 'heresphere') : 'vr') . '/?id=' . $scenes['list'][$k]['id'] . '&media=cached',
                'videoPreview' => getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode('?', $scenes['list'][$k]['videoPreview'])[0], '.mp4') . '_videoPreview.mp4'),
                'thumbnailUrl' => getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode('?', $scenes['list'][$k]['thumbnailUrl'])[0], '.jpg') . '_thumbnail.jpg'),
                'thumbnailImage' => getProtectedUrlForMediaPath($DEOVR_DATA_DIRECTORY . DIRECTORY_SEPARATOR . 'deovr' . DIRECTORY_SEPARATOR . basename(explode('?', $scenes['list'][$k]['thumbnailUrl'])[0], '.jpg') . '_thumbnail.jpg'),
                'title' => $scenes['list'][$k]['title'],
            ];
        }
        $cached[] = $scenes;
    }
}
if ($responseTypeJson) {
    $deoJSON = [
        ($isDeoVR ? 'scenes' : 'library') => [
            ...array_values($media),
            ...array_values($cached)
            ],
            ($isDeoVR ? 'authorized' : 'access') => 1,
            ];

    header('Content-Type: application/json');
    echo json_encode($deoJSON);
} else {
    echo '<html>
            <head>
                <meta name="viewport" content="width=device-width, initial-scale=1">
                ' . $htmlStyle . '
				<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.5.7/jquery.fancybox.min.css" integrity="sha512-H9jrZiiopUdsLpg94A333EfumgUBpO9MdbxStdeITo+KEIMaNfHNvwyjjDJb+ERPaRS6DpyRlKbvPUasNItRyw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
                <style>
                a[data-gallery-link="true"] {
                    width: 42vw;
                    height: 43vw;
                    margin: 2.5vw;
                    border-radius: 5vw;
                    display:inline-block;
                    overflow: hidden;
                }
                img {
                    object-fit: cover;
                    height: 100%;
                    width: 100%;
                    object-position: 0% 50%;
                }
            @media (min-width:801px)  {


                a[data-gallery-link="true"] {
                    width: 19vw;
                    height: 20vw;
                    margin: 2.5vw;
                    border-radius: 5vw;
                    display:inline-block;
                    overflow: hidden;
                }
            }
                </style>
            <head>
            <body>';
    $scenes = [
            ...array_values($media),
            ...array_values($cached)
            ];
    ?>

            <ul class="tabs">
                <?php

    foreach ($scenes as $index => $scene) {
        echo '<li data-tab-target="#' . urlencode($scene['name']) . '" class="' . ($index === 0 ? 'active' : '') . ' tab">' . $scene['name'] . '</li>';
    }
    ?>
    <li class="tab"><a style="width:100%;height:100%;" href="?logout=1"><svg fill="#ffffff" height="20px" width="20px" version="1.1" id="Capa_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
	 viewBox="0 0 384.971 384.971" xml:space="preserve">
<g>
	<g id="Sign_Out">
		<path d="M180.455,360.91H24.061V24.061h156.394c6.641,0,12.03-5.39,12.03-12.03s-5.39-12.03-12.03-12.03H12.03
			C5.39,0.001,0,5.39,0,12.031V372.94c0,6.641,5.39,12.03,12.03,12.03h168.424c6.641,0,12.03-5.39,12.03-12.03
			C192.485,366.299,187.095,360.91,180.455,360.91z"/>
		<path d="M381.481,184.088l-83.009-84.2c-4.704-4.752-12.319-4.74-17.011,0c-4.704,4.74-4.704,12.439,0,17.179l62.558,63.46H96.279
			c-6.641,0-12.03,5.438-12.03,12.151c0,6.713,5.39,12.151,12.03,12.151h247.74l-62.558,63.46c-4.704,4.752-4.704,12.439,0,17.179
			c4.704,4.752,12.319,4.752,17.011,0l82.997-84.2C386.113,196.588,386.161,188.756,381.481,184.088z"/>
	</g>
</g>
</svg></a></li>
</ul>

<div class="tab-content">
    <?php
    foreach ($scenes as $index => $scene) {
        echo '<div data-tab-content id="' . urlencode($scene['name']) . '" class="' . ($index === 0 ? 'active' : '') . '"><h2>' . $scene['name'] . '</h2>';
        foreach ($scene['list'] as $item) {
            $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
            if (isset($_GET['native']) && stripos($ua, 'x11') !== false) {
                echo ' <a data-gallery-link="true" href="' . $item['videoSrc'] . '"><img  loading="lazy" src="' . $item['thumbnailUrl'] . '" /></a> ';
            } else {
                $hash = 'NONE';
                if ($item['stereoMode'] === 'sbs' && $item['screenType'] === 'dome') {
                    $hash = '180_LR';
                }
                if ($item['stereoMode'] === 'tb') {
                    $hash = '360_TB';
                }
                if ($item['stereoMode'] === 'sbs' && $item['screenType'] === 'flat') {
                    $hash = 'SBS';
                }
                if ($item['stereoMode'] === 'sbs' && $item['screenType'] === 'dome' && $item['is3d'] === false) {
                    $hash = 'SBS';
                }
                if ($item['stereoMode'] === 'sbs' && $item['screenType'] === 'sphere') {
                    $hash = '360_LR';
                }
                if ($hash === 'NONE' && $item['screenType'] === 'sphere') {
                    $hash = '360';
                }
                echo ' <a data-gallery-link="true" data-fancybox="gallery"  data-type="iframe" href="#" data-src="' . $item['video_url'] . '&html=1#' . $hash . '"><img  loading="lazy" src="' . $item['thumbnailUrl'] . '" /></a> ';
            }

        }
        echo '</div>';
    }
    echo '</div>';
    echo "
    <script>


const tabs = document.querySelectorAll('[data-tab-target]')
const tabContents = document.querySelectorAll('[data-tab-content]')

tabs.forEach(tab => {
  tab.addEventListener('click', () => {
    const target = document.querySelector(tab.dataset.tabTarget)
    tabContents.forEach(tabContent => {
      tabContent.classList.remove('active')
    })
    tabs.forEach(tab => {
      tab.classList.remove('active')
    })
    tab.classList.add('active')
    target.classList.add('active')
  })
})
</script>";
    echo '
			<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
			<script src="https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.5.7/jquery.fancybox.min.js" integrity="sha512-uURl+ZXMBrF4AwGaWmEetzrd+J5/8NRkWAvJx5sbPSSuOb0bZLqf+tOzniObO00BjHa/dD7gub9oCGMLPQHtQA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
		</body>
        </html>';
}
