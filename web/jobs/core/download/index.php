<?php
ini_set('memory_limit', '2G');
use privuma\privuma;
use privuma\helpers\mediaFile;
use privuma\helpers\tokenizer;
use privuma\helpers\cloudFS;

require_once __DIR__ .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  'app' .
  DIRECTORY_SEPARATOR .
  'privuma.php';

$privuma = privuma::getInstance();
$tokenizer = new tokenizer();
$downloadLocation = $privuma->getEnv('DOWNLOAD_LOCATION');
if (!$downloadLocation) {
    exit();
}
$downloadLocationUnencrypted = $privuma->getEnv(
    'DOWNLOAD_LOCATION_UNENCRYPTED'
);
if (!$downloadLocationUnencrypted) {
    exit();
}

$conn = $privuma->getPDO();

$ops = new cloudFS($downloadLocation, true, '/usr/bin/rclone', null, true);
$opsNoEncodeNoPrefix = new cloudFS($downloadLocation, false, '/usr/bin/rclone', null, false);
$opsPlain = new cloudFS(
    $downloadLocationUnencrypted,
    false,
    '/usr/bin/rclone',
    null,
    false
);

echo PHP_EOL . 'Building list of media to download';
$stmt = $conn->prepare("select filename, album, time, hash, url, thumbnail
from media
where hash is not null
and hash != ''
and hash != 'compressed'
and (album = 'Favorites' or blocked = 0)
and (dupe = 0 or album = 'Favorites')
group by hash
 order by
    time DESC");
$stmt->execute();
$dlData = $stmt->fetchAll();
echo PHP_EOL . 'Building web app payload of media to download';
$stmt = $conn->prepare(
    "SELECT filename, album, dupe, time, hash, REGEXP_REPLACE(metadata, 'www\.[a-zA-Z0-9\_\.\/\:\-\?\=\&]*|(http|https|ftp):\/\/[a-zA-Z0-9\_\.\/\:\-\?\=\&]*', 'Link Removed') as metadata FROM (SELECT * FROM media WHERE (album = 'Favorites' or blocked = 0) and hash is not null and hash != '' and hash != 'compressed') t1 ORDER BY time desc;"
);
$stmt->execute();
$data = str_replace('`', '', json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)));
function sanitizeLine($line)
{
    return trim(preg_replace('/[^A-Za-z0-9 ]/', '', $line), "\r\n");
}

function trimExtraNewLines($string)
{
    return trim(
        implode(
            PHP_EOL,
            array_map(function ($line) {
                return sanitizeLine($line);
            }, explode(PHP_EOL, $string))
        ),
        "\r\n"
    );
}

function parseMetaData($item)
{
    return [
      'title' => explode(PHP_EOL, explode('Title: ', $item)[1] ?? '')[0],
      'author' => explode(PHP_EOL, explode('Author: ', $item)[1] ?? '')[0],
      'date' => new DateTime(
          explode(PHP_EOL, explode('Date: ', $item)[1] ?? '')[0]
      ),
      'rating' => (int) explode(PHP_EOL, explode('Rating: ', $item)[1] ?? '')[0],
      'favorites' => (int) explode(
          PHP_EOL,
          explode('Favorites: ', $item)[1] ?? ''
      )[0],
      'description' => explode(
          'Tags:',
          explode('Description: ', $item)[1] ?? ''
      )[0],
      'tags' =>
        explode(', ', explode(PHP_EOL, explode('Tags: ', $item)[1] ?? '')[0]) ??
        [],
      'comments' => explode('Comments: ', $item)[1] ?? '',
    ];
}

function condenseMetaData($item)
{
    return str_replace(
        PHP_EOL,
        '\n',
        mb_convert_encoding(
            str_replace(
                PHP_EOL . PHP_EOL,
                PHP_EOL,
                implode(PHP_EOL, [
                  sanitizeLine(
                      $item['title'] ?:
                      substr(trimExtraNewLines($item['description']), 0, 256)
                  ),
                  sanitizeLine($item['favorites']),
                  sanitizeLine(implode(', ', array_slice($item['tags'], 0, 20))),
                  //substr(trimExtraNewLines($item['comments']), 0, 256),
                ])
            ),
            'UTF-8',
            'UTF-8'
        )
    );
}
$mobiledata = json_encode(
    mb_convert_encoding(
        array_map(function ($item) {
            $item['metadata'] = is_null($item['metadata']) ? '' : condenseMetaData(parseMetaData($item['metadata']));
            return $item;
        }, json_decode($data, true)),
        'UTF-8',
        'UTF-8'
    ),
    JSON_THROW_ON_ERROR
);

echo PHP_EOL . 'All Database Lookup Operations have been completed.';

$viewerHTML = <<<'HEREHTML'
<!doctype html>
<meta charset="utf8" />
<html>
    <head>
        <title>Privuma(Offline Web App)</title>
        <meta name="referrer" content="no-referrer" />
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0, shrink-to-fit=no"
        />
        <!-- prettier-ignore -->
        <style>
      /*!
      * Fancybox @3.5.7
      */
      body.compensate-for-scrollbar{overflow:hidden}.fancybox-active{height:auto}.fancybox-is-hidden{left:-9999px;margin:0;position:absolute!important;top:-9999px;visibility:hidden}.fancybox-container{-webkit-backface-visibility:hidden;height:100%;left:0;outline:none;position:fixed;-webkit-tap-highlight-color:transparent;top:0;-ms-touch-action:manipulation;touch-action:manipulation;transform:translateZ(0);width:100%;z-index:99992}.fancybox-container *{box-sizing:border-box}.fancybox-bg,.fancybox-inner,.fancybox-outer,.fancybox-stage{bottom:0;left:0;position:absolute;right:0;top:0}.fancybox-outer{-webkit-overflow-scrolling:touch;overflow-y:auto}.fancybox-bg{background:#1e1e1e;opacity:0;transition-duration:inherit;transition-property:opacity;transition-timing-function:cubic-bezier(.47,0,.74,.71)}.fancybox-is-open .fancybox-bg{opacity:.9;transition-timing-function:cubic-bezier(.22,.61,.36,1)}.fancybox-caption,.fancybox-infobar,.fancybox-navigation .fancybox-button,.fancybox-toolbar{direction:ltr;opacity:0;position:absolute;transition:opacity .25s ease,visibility 0s ease .25s;visibility:hidden;z-index:99997}.fancybox-show-caption .fancybox-caption,.fancybox-show-infobar .fancybox-infobar,.fancybox-show-nav .fancybox-navigation .fancybox-button,.fancybox-show-toolbar .fancybox-toolbar{opacity:1;transition:opacity .25s ease 0s,visibility 0s ease 0s;visibility:visible}.fancybox-infobar{color:#ccc;font-size:13px;-webkit-font-smoothing:subpixel-antialiased;height:44px;left:0;line-height:44px;min-width:44px;mix-blend-mode:difference;padding:0 10px;pointer-events:none;top:0;-webkit-touch-callout:none;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none}.fancybox-toolbar{right:0;top:0}.fancybox-stage{direction:ltr;overflow:visible;transform:translateZ(0);z-index:99994}.fancybox-is-open .fancybox-stage{overflow:hidden}.fancybox-slide{-webkit-backface-visibility:hidden;display:none;height:100%;left:0;outline:none;overflow:auto;-webkit-overflow-scrolling:touch;padding:44px;position:absolute;text-align:center;top:0;transition-property:transform,opacity;white-space:normal;width:100%;z-index:99994}.fancybox-slide:before{content:"";display:inline-block;font-size:0;height:100%;vertical-align:middle;width:0}.fancybox-is-sliding .fancybox-slide,.fancybox-slide--current,.fancybox-slide--next,.fancybox-slide--previous{display:block}.fancybox-slide--image{overflow:hidden;padding:44px 0}.fancybox-slide--image:before{display:none}.fancybox-slide--html{padding:6px}.fancybox-content{background:#fff;display:inline-block;margin:0;max-width:100%;overflow:auto;-webkit-overflow-scrolling:touch;padding:44px;position:relative;text-align:left;vertical-align:middle}.fancybox-slide--image .fancybox-content{animation-timing-function:cubic-bezier(.5,0,.14,1);-webkit-backface-visibility:hidden;background:transparent;background-repeat:no-repeat;background-size:100% 100%;left:0;max-width:none;overflow:visible;padding:0;position:absolute;top:0;transform-origin:top left;transition-property:transform,opacity;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;z-index:99995}.fancybox-can-zoomOut .fancybox-content{cursor:zoom-out}.fancybox-can-zoomIn .fancybox-content{cursor:zoom-in}.fancybox-can-pan .fancybox-content,.fancybox-can-swipe .fancybox-content{cursor:grab}.fancybox-is-grabbing .fancybox-content{cursor:grabbing}.fancybox-container [data-selectable=true]{cursor:text}.fancybox-image,.fancybox-spaceball{background:transparent;border:0;height:100%;left:0;margin:0;max-height:none;max-width:none;padding:0;position:absolute;top:0;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;width:100%}.fancybox-spaceball{z-index:1}.fancybox-slide--iframe .fancybox-content,.fancybox-slide--map .fancybox-content,.fancybox-slide--pdf .fancybox-content,.fancybox-slide--video .fancybox-content{height:100%;overflow:visible;padding:0;width:100%}.fancybox-slide--video .fancybox-content{background:#000}.fancybox-slide--map .fancybox-content{background:#e5e3df}.fancybox-slide--iframe .fancybox-content{background:#fff}.fancybox-iframe,.fancybox-video{background:transparent;border:0;display:block;height:100%;margin:0;overflow:hidden;padding:0;width:100%}.fancybox-iframe{left:0;position:absolute;top:0}.fancybox-error{background:#fff;cursor:default;max-width:400px;padding:40px;width:100%}.fancybox-error p{color:#444;font-size:16px;line-height:20px;margin:0;padding:0}.fancybox-button{background:rgba(30,30,30,.6);border:0;border-radius:0;box-shadow:none;cursor:pointer;display:inline-block;height:44px;margin:0;padding:10px;position:relative;transition:color .2s;vertical-align:top;visibility:inherit;width:44px}.fancybox-button,.fancybox-button:link,.fancybox-button:visited{color:#ccc}.fancybox-button:hover{color:#fff}.fancybox-button:focus{outline:none}.fancybox-button.fancybox-focus{outline:1px dotted}.fancybox-button[disabled],.fancybox-button[disabled]:hover{color:#888;cursor:default;outline:none}.fancybox-button div{height:100%}.fancybox-button svg{display:block;height:100%;overflow:visible;position:relative;width:100%}.fancybox-button svg path{fill:currentColor;stroke-width:0}.fancybox-button--fsenter svg:nth-child(2),.fancybox-button--fsexit svg:first-child,.fancybox-button--pause svg:first-child,.fancybox-button--play svg:nth-child(2){display:none}.fancybox-progress{background:#ff5268;height:2px;left:0;position:absolute;right:0;top:0;transform:scaleX(0);transform-origin:0;transition-property:transform;transition-timing-function:linear;z-index:99998}.fancybox-close-small{background:transparent;border:0;border-radius:0;color:#ccc;cursor:pointer;opacity:.8;padding:8px;position:absolute;right:-12px;top:-44px;z-index:401}.fancybox-close-small:hover{color:#fff;opacity:1}.fancybox-slide--html .fancybox-close-small{color:currentColor;padding:10px;right:0;top:0}.fancybox-slide--image.fancybox-is-scaling .fancybox-content{overflow:hidden}.fancybox-is-scaling .fancybox-close-small,.fancybox-is-zoomable.fancybox-can-pan .fancybox-close-small{display:none}.fancybox-navigation .fancybox-button{background-clip:content-box;height:100px;opacity:0;position:absolute;top:calc(50% - 50px);width:70px}.fancybox-navigation .fancybox-button div{padding:7px}.fancybox-navigation .fancybox-button--arrow_left{left:0;left:env(safe-area-inset-left);padding:31px 26px 31px 6px}.fancybox-navigation .fancybox-button--arrow_right{padding:31px 6px 31px 26px;right:0;right:env(safe-area-inset-right)}.fancybox-caption{background:linear-gradient(0deg,rgba(0,0,0,.85) 0,rgba(0,0,0,.3) 50%,rgba(0,0,0,.15) 65%,rgba(0,0,0,.075) 75.5%,rgba(0,0,0,.037) 82.85%,rgba(0,0,0,.019) 88%,transparent);bottom:0;color:#eee;font-size:14px;font-weight:400;left:0;line-height:1.5;padding:75px 44px 25px;pointer-events:none;right:0;text-align:center;z-index:99996}@supports (padding:max(0px)){.fancybox-caption{padding:75px max(44px,env(safe-area-inset-right)) max(25px,env(safe-area-inset-bottom)) max(44px,env(safe-area-inset-left))}}.fancybox-caption--separate{margin-top:-50px}.fancybox-caption__body{max-height:50vh;overflow:auto;pointer-events:all}.fancybox-caption a,.fancybox-caption a:link,.fancybox-caption a:visited{color:#ccc;text-decoration:none}.fancybox-caption a:hover{color:#fff;text-decoration:underline}.fancybox-loading{animation:a 1s linear infinite;background:transparent;border:4px solid #888;border-bottom-color:#fff;border-radius:50%;height:50px;left:50%;margin:-25px 0 0 -25px;opacity:.7;padding:0;position:absolute;top:50%;width:50px;z-index:99999}@keyframes a{to{transform:rotate(1turn)}}.fancybox-animated{transition-timing-function:cubic-bezier(0,0,.25,1)}.fancybox-fx-slide.fancybox-slide--previous{opacity:0;transform:translate3d(-100%,0,0)}.fancybox-fx-slide.fancybox-slide--next{opacity:0;transform:translate3d(100%,0,0)}.fancybox-fx-slide.fancybox-slide--current{opacity:1;transform:translateZ(0)}.fancybox-fx-fade.fancybox-slide--next,.fancybox-fx-fade.fancybox-slide--previous{opacity:0;transition-timing-function:cubic-bezier(.19,1,.22,1)}.fancybox-fx-fade.fancybox-slide--current{opacity:1}.fancybox-fx-zoom-in-out.fancybox-slide--previous{opacity:0;transform:scale3d(1.5,1.5,1.5)}.fancybox-fx-zoom-in-out.fancybox-slide--next{opacity:0;transform:scale3d(.5,.5,.5)}.fancybox-fx-zoom-in-out.fancybox-slide--current{opacity:1;transform:scaleX(1)}.fancybox-fx-rotate.fancybox-slide--previous{opacity:0;transform:rotate(-1turn)}.fancybox-fx-rotate.fancybox-slide--next{opacity:0;transform:rotate(1turn)}.fancybox-fx-rotate.fancybox-slide--current{opacity:1;transform:rotate(0deg)}.fancybox-fx-circular.fancybox-slide--previous{opacity:0;transform:scale3d(0,0,0) translate3d(-100%,0,0)}.fancybox-fx-circular.fancybox-slide--next{opacity:0;transform:scale3d(0,0,0) translate3d(100%,0,0)}.fancybox-fx-circular.fancybox-slide--current{opacity:1;transform:scaleX(1) translateZ(0)}.fancybox-fx-tube.fancybox-slide--previous{transform:translate3d(-100%,0,0) scale(.1) skew(-10deg)}.fancybox-fx-tube.fancybox-slide--next{transform:translate3d(100%,0,0) scale(.1) skew(10deg)}.fancybox-fx-tube.fancybox-slide--current{transform:translateZ(0) scale(1)}@media (max-height:576px){.fancybox-slide{padding-left:6px;padding-right:6px}.fancybox-slide--image{padding:6px 0}.fancybox-close-small{right:-6px}.fancybox-slide--image .fancybox-close-small{background:#4e4e4e;color:#f2f4f6;height:36px;opacity:1;padding:6px;right:0;top:0;width:36px}.fancybox-caption{padding-left:12px;padding-right:12px}@supports (padding:max(0px)){.fancybox-caption{padding-left:max(12px,env(safe-area-inset-left));padding-right:max(12px,env(safe-area-inset-right))}}}.fancybox-share{background:#f4f4f4;border-radius:3px;max-width:90%;padding:30px;text-align:center}.fancybox-share h1{color:#222;font-size:35px;font-weight:700;margin:0 0 20px}.fancybox-share p{margin:0;padding:0}.fancybox-share__button{border:0;border-radius:3px;display:inline-block;font-size:14px;font-weight:700;line-height:40px;margin:0 5px 10px;min-width:130px;padding:0 15px;text-decoration:none;transition:all .2s;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;white-space:nowrap}.fancybox-share__button:link,.fancybox-share__button:visited{color:#fff}.fancybox-share__button:hover{text-decoration:none}.fancybox-share__button--fb{background:#3b5998}.fancybox-share__button--fb:hover{background:#344e86}.fancybox-share__button--pt{background:#bd081d}.fancybox-share__button--pt:hover{background:#aa0719}.fancybox-share__button--tw{background:#1da1f2}.fancybox-share__button--tw:hover{background:#0d95e8}.fancybox-share__button svg{height:25px;margin-right:7px;position:relative;top:-1px;vertical-align:middle;width:25px}.fancybox-share__button svg path{fill:#fff}.fancybox-share__input{background:transparent;border:0;border-bottom:1px solid #d7d7d7;border-radius:0;color:#5d5b5b;font-size:14px;margin:10px 0 0;outline:none;padding:10px 15px;width:100%}.fancybox-thumbs{background:#ddd;bottom:0;display:none;margin:0;-webkit-overflow-scrolling:touch;-ms-overflow-style:-ms-autohiding-scrollbar;padding:2px 2px 4px;position:absolute;right:0;-webkit-tap-highlight-color:rgba(0,0,0,0);top:0;width:212px;z-index:99995}.fancybox-thumbs-x{overflow-x:auto;overflow-y:hidden}.fancybox-show-thumbs .fancybox-thumbs{display:block}.fancybox-show-thumbs .fancybox-inner{right:212px}.fancybox-thumbs__list{font-size:0;height:100%;list-style:none;margin:0;overflow-x:hidden;overflow-y:auto;padding:0;position:absolute;position:relative;white-space:nowrap;width:100%}.fancybox-thumbs-x .fancybox-thumbs__list{overflow:hidden}.fancybox-thumbs-y .fancybox-thumbs__list::-webkit-scrollbar{width:7px}.fancybox-thumbs-y .fancybox-thumbs__list::-webkit-scrollbar-track{background:#fff;border-radius:10px;box-shadow:inset 0 0 6px rgba(0,0,0,.3)}.fancybox-thumbs-y .fancybox-thumbs__list::-webkit-scrollbar-thumb{background:#2a2a2a;border-radius:10px}.fancybox-thumbs__list a{-webkit-backface-visibility:hidden;backface-visibility:hidden;background-color:rgba(0,0,0,.1);background-position:50%;background-repeat:no-repeat;background-size:cover;cursor:pointer;float:left;height:75px;margin:2px;max-height:calc(100% - 8px);max-width:calc(50% - 4px);outline:none;overflow:hidden;padding:0;position:relative;-webkit-tap-highlight-color:transparent;width:100px}.fancybox-thumbs__list a:before{border:6px solid #ff5268;bottom:0;content:"";left:0;opacity:0;position:absolute;right:0;top:0;transition:all .2s cubic-bezier(.25,.46,.45,.94);z-index:99991}.fancybox-thumbs__list a:focus:before{opacity:.5}.fancybox-thumbs__list a.fancybox-thumbs-active:before{opacity:1}@media (max-width:576px){.fancybox-thumbs{width:110px}.fancybox-show-thumbs .fancybox-inner{right:110px}.fancybox-thumbs__list a{max-width:calc(100% - 10px)}}

      /* Custom CSS */
      html, body{margin: 0;padding: 0;height: 100%;width: 100%;background-color: #000000;color:#ffffff;}.gallerypicture {width: calc(100%/4)!important;display: inline-block;height: 18.75vw!important;position: relative;}.gallerypicture img {object-fit: cover;cursor: pointer;object-position: 50% 50%;width: 100%;height: 100%;transition: transform .5s ease-in-out,opacity .5s ease-in-out;}.gallerypicture img:hover {transform: scale(1.2);}.gallerypicture .img-wrapper{overflow:hidden; width:100%; height:100%;display:inline-block;}.gallerypicture .album-menu{display: block;position: absolute;top:0px;width: 100%;background-color: rgba(0, 0, 0, 0.85);height: auto;line-height: 20px;font-size: 18px;padding: 4px 0px 4px 4px;color: white;}.logout{position: fixed;display: block;top:5px;right: 5px;width: auto;color: white;z-index: 5;background-color: #333333;}h1,h3{margin-top: 50px;}img {border: 0;}img:not([src]) {visibility: hidden;}@media screen and (max-width: 1200px) {.gallerypicture {width: calc(100%/3)!important;height: 25vw!important;}}@media screen and (max-width: 992px) {.gallerypicture {width: calc(100%/2)!important;height: 37.5vw!important;}}#searchBox {z-index:1;position: fixed;bottom: 5px;;left: 5px;padding:5px;width:auto;height:auto;background-color: rgba(0,0,0,0.7);border-radius:20px;}#searchInput {width:75%;min-width:225px;background-color: #000000;color:#ffffff;display:inline-block;} #backBtn{width:25%;min-width:75px;display:inline-block;vertical-align:baseline;}#downloadPassword {background-color: #000000;color:#ffffff;} #backBtn{width:25%;display:inline-block;vertical-align:baseline;} .collapsible { white-space: pre-wrap; text-align: left; margin-top:50px; background: #000000; padding:15px; } .toggle-collapsible { position:fixed; margin-top:-25px;left: 50%;transform: translateX(-50%); } .collapsed{ display:none;} .fancybox-content {background:#000000 !important;overflow-y:scroll !important; color: #ffffff !important;}.ff-loading-icon{background:none !important;}*::-webkit-scrollbar {display: none; }*{-ms-overflow-style: none;scrollbar-width: none;}.fancybox-content{padding:0px !important;}.ff-container.ff-loading-icon:before{background-image:none !important;}.openalbum {font-size: 18px;padding: 0px 2px;line-height: 14px;}#progress {position: fixed;z-index: 100000;top: 0;left: -6px;width: 1%;height: 3px;background-color: #ce0000;-moz-border-radius: 1px;-webkit-border-radius: 1px;border-radius: 1px;-moz-transition: width 600ms ease-out, opacity 500ms linear;-ms-transition: width 600ms ease-out, opacity 500ms linear;-o-transition: width 600ms ease-out, opacity 500ms linear;-webkit-transition: width 600ms ease-out, opacity 500ms linear;transition: width 1000ms ease-out, opacity 500ms linear;will-change: width, opacity;}#progress b,#progress i {position: absolute;top: 0;height: 3px;-moz-box-shadow: #777777 1px 0 6px 1px;-ms-box-shadow: #777777 1px 0 6px 1px;-webkit-box-shadow: #777777 1px 0 6px 1px;box-shadow: #777777 1px 0 6px 1px;-moz-border-radius: 100%;-webkit-border-radius: 100%;border-radius: 100%;}#progress b {clip: rect(-6px, 22px, 14px, 10px);opacity: 0.6;width: 20px;right: 0;}#progress i {clip: rect(-6px, 90px, 14px, -6px);opacity: 0.6;width: 180px;right: -80px;}.btn {border-radius: 15px;border: 0px solid transparent;text-decoration: none;appearance: none;padding: 12px;font-size: 16px;line-height: 16px;background-color:#222222 !important;color: #ffffff;}.form-control {border-radius: 15px;border: 0px solid transparent;text-decoration: none;appearance: none;padding: 10px;font-size: 16px;line-height: 16px;background-color:#222222 !important;color: #ffffff;}* { font-family: sans-serif; margin: 0;}.btn.min-width{min-width: 16px;box-sizing: content-box;}.dropdown {position: relative;display: inline-block;}.dropdown-content {display: none;position: absolute;background-color: #333333;min-width: 160px;box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);z-index: 1;border-radius: 15px;overflow:hidden;}.dropdown-content div {color: #eeeeee;padding: 12px 16px;font-size: 16px;line-height: 16px;text-decoration: none;display: flex;place-items: center;gap: 10px;}.dropdown-content div:hover,.dropdown-content div:focus {background-color: #454545;}.dropdown:hover .dropdown-content, .dropdown:focus .dropdown-content {display: block; z-index: 1000;}.dropdown:hover .btn, .dropdown:focus .btn {background-color: #454545;}#album-content .gallerypicture{float:left;}.t-wrap{z-index:999999}
    </style>
        <!-- prettier-ignore -->
        <script>
      /**
       * Javascript Libraries
       */

       /**
       * rclone-crypt-browser-tools.js
       */
       !function t(e,r,n){function o(i,c){if(!r[i]){if(!e[i]){var a="function"==typeof require&&require;if(!c&&a)return a(i,!0);if(s)return s(i,!0);var l=new Error("Cannot find module '"+i+"'");throw l.code="MODULE_NOT_FOUND",l}var u=r[i]={exports:{}};e[i][0].call(u.exports,(function(t){return o(e[i][1][t]||t)}),u,u.exports,t,e,r,n)}return r[i].exports}for(var s="function"==typeof require&&require,i=0;i<n.length;i++)o(n[i]);return o}({1:[function(t,e,r){(function(e){(function(){"use strict";var r=t("@fyears/rclone-crypt");e.window.rcloneCrypt={encrypt:async function(t,e="",n=""){const o=new r.Cipher("base32");await o.key(e,n);const s=await fetch(t),i=await s.arrayBuffer();return await o.encryptData(new Uint8Array(i))},encryptPath:async function(t,e="",n=""){const o=new r.Cipher("base32");return await o.key(e,n),await o.encryptFileName(t)},decrypt:async function(t,e="",n=""){const o=new r.Cipher("base32");await o.key(e,n);const s=await fetch(t),i=await s.arrayBuffer();return await o.decryptData(new Uint8Array(i))},decryptPath:async function(t,e="",n=""){const o=new r.Cipher("base32");return await o.key(e,n),await o.decryptFileName(t)},type:function(t){let e=t.split(".").pop(),r={pdf:"application/pdf",jpg:"image/jpg",jpeg:"image/jpeg",png:"image/png",gif:"image/gif",mp4:"video/mp4",webm:"video/webm"};return r[e]?r[e]:"application/octect-stream"},render:function(t,e="",r=!1,n=!1){let o=new Blob([t],{type:window.rcloneCrypt.type(e)}),s=e.split("/").pop();if(window.navigator.msSaveOrOpenBlob)window.navigator.msSaveOrOpenBlob(o,s);else{const t=document.createElement("a");document.body.appendChild(t);const e=window.URL.createObjectURL(o);if(!n)return e;t.href=e,r&&(t.download=s),t.click(),setTimeout((()=>{window.URL.revokeObjectURL(e),document.body.removeChild(t)}),0)}}}}).call(this)}).call(this,"undefined"!=typeof global?global:"undefined"!=typeof self?self:"undefined"!=typeof window?window:{})},{"@fyears/rclone-crypt":3}],2:[function(t,e,r){"use strict";var n=this&&this.__awaiter||function(t,e,r,n){return new(r||(r=Promise))((function(o,s){function i(t){try{a(n.next(t))}catch(t){s(t)}}function c(t){try{a(n.throw(t))}catch(t){s(t)}}function a(t){var e;t.done?o(t.value):(e=t.value,e instanceof r?e:new r((function(t){t(e)}))).then(i,c)}a((n=n.apply(t,e||[])).next())}))};Object.defineProperty(r,"__esModule",{value:!0}),r.AESCipherBlock=r.EMECipher=void 0;const o=t("@noble/ciphers/aes");function s(t,e){if(16!==e.length)throw Error("len must be 16");const r=new Uint8Array(16);r[0]=2*e[0],e[15]>=128&&(r[0]=135^r[0]);for(let t=1;t<16;t++)r[t]=2*e[t],e[t-1]>=128&&(r[t]=r[t]+1);t.set(r)}function i(t,e,r){if(e.length!==r.length)throw Error(`input1.length=${e.length} is not equal to input2.length=${r.length}`);for(let n=0;n<e.length;++n)t[n]=e[n]^r[n]}function c(t,e,r,o){return n(this,void 0,void 0,(function*(){r?yield o.encrypt(t,e):yield o.decrypt(t,e)}))}function a(t,e,r,o){return n(this,void 0,void 0,(function*(){const a=e,l=r;if(16!==t.blockSize())throw Error("Using a block size other than 16 is not implemented");if(16!==a.length)throw Error(`Tweak must be 16 bytes long, is ${a.length}`);if(l.length%16!=0)throw Error(`Data P must be a multiple of 16 long, is ${l.length}`);const u=l.length/16;if(0===u||u>128)throw Error(`EME operates on 1 to 128 block-cipher blocks, you passed ${u}`);const h=new Uint8Array(l.length),f=yield function(t,e){return n(this,void 0,void 0,(function*(){const r=new Uint8Array(16),n=new Uint8Array(16);yield t.encrypt(n,r);const o=new Array(e);for(let t=0;t<e;t++)s(n,n),o[t]=new Uint8Array(n);return o}))}(t,u),y=new Uint8Array(16);for(let e=0;e<u;e++){i(y,l.subarray(16*e,16*(e+1)),f[e]),yield c(h.subarray(16*e,16*(e+1)),y,o,t)}const d=new Uint8Array(16);i(d,h.subarray(0,16),a);for(let t=1;t<u;t++)i(d,d,h.subarray(16*t,16*(t+1)));const p=new Uint8Array(16);yield c(p,d,o,t);const g=new Uint8Array(16);i(g,d,p);const b=new Uint8Array(16);for(let t=1;t<u;t++)s(g,g),i(b,h.subarray(16*t,16*(t+1)),g),h.subarray(16*t,16*(t+1)).set(b);const w=new Uint8Array(16);i(w,p,a);for(let t=1;t<u;t++)i(w,w,h.subarray(16*t,16*(t+1)));h.subarray(0,16).set(w);for(let e=0;e<u;e++)yield c(h.subarray(16*e,16*(e+1)),h.subarray(16*e,16*(e+1)),o,t),i(h.subarray(16*e,16*(e+1)),h.subarray(16*e,16*(e+1)),f[e]);return h}))}r.EMECipher=class{constructor(t){this.bc=t}encrypt(t,e){return n(this,void 0,void 0,(function*(){return yield a(this.bc,t,e,!0)}))}decrypt(t,e){return n(this,void 0,void 0,(function*(){return yield a(this.bc,t,e,!1)}))}};r.AESCipherBlock=class{constructor(t){if(this.keyRaw=t,this.iv=new Uint8Array(16),16===t.length)this.algo="aes128";else if(24===t.length)this.algo="aes192";else{if(32!==t.length)throw Error(`invalid key length = ${t.length}`);this.algo="aes256"}}encrypt(t,e){return n(this,void 0,void 0,(function*(){const r=(0,o.ecb)(this.keyRaw,{disablePadding:!0});t.set([...r.encrypt(e)])}))}decrypt(t,e){return n(this,void 0,void 0,(function*(){const r=(0,o.ecb)(this.keyRaw,{disablePadding:!0});t.set([...r.decrypt(e)])}))}blockSize(){return 16}}},{"@noble/ciphers/aes":8}],3:[function(t,e,r){"use strict";var n=this&&this.__createBinding||(Object.create?function(t,e,r,n){void 0===n&&(n=r);var o=Object.getOwnPropertyDescriptor(e,r);o&&!("get"in o?!e.__esModule:o.writable||o.configurable)||(o={enumerable:!0,get:function(){return e[r]}}),Object.defineProperty(t,n,o)}:function(t,e,r,n){void 0===n&&(n=r),t[n]=e[r]}),o=this&&this.__setModuleDefault||(Object.create?function(t,e){Object.defineProperty(t,"default",{enumerable:!0,value:e})}:function(t,e){t.default=e}),s=this&&this.__importStar||function(t){if(t&&t.__esModule)return t;var e={};if(null!=t)for(var r in t)"default"!==r&&Object.prototype.hasOwnProperty.call(t,r)&&n(e,t,r);return o(e,t),e},i=this&&this.__awaiter||function(t,e,r,n){return new(r||(r=Promise))((function(o,s){function i(t){try{a(n.next(t))}catch(t){s(t)}}function c(t){try{a(n.throw(t))}catch(t){s(t)}}function a(t){var e;t.done?o(t.value):(e=t.value,e instanceof r?e:new r((function(t){t(e)}))).then(i,c)}a((n=n.apply(t,e||[])).next())}))};Object.defineProperty(r,"__esModule",{value:!0}),r.decryptedSize=r.encryptedSize=r.add=r.increment=r.carry=r.Cipher=r.msgErrorSuffixMissingDot=r.msgErrorBadSeek=r.msgErrorNotAnEncryptedFile=r.msgErrorFileClosed=r.msgErrorBadBase32Encoding=r.msgErrorEncryptedBadBlock=r.msgErrorEncryptedBadMagic=r.msgErrorEncryptedFileBadHeader=r.msgErrorEncryptedFileTooShort=r.msgErrorBadDecryptControlChar=r.msgErrorBadDecryptUTF8=void 0;const c=t("@noble/hashes/scrypt"),a=t("@noble/ciphers/salsa"),l=t("@noble/ciphers/webcrypto"),u=t("pkcs7-padding"),h=t("@fyears/eme"),f=t("rfc4648"),y=s(t("base32768")),d="RCLONE\0\0",p=(new TextEncoder).encode(d),g=a.xsalsa20poly1305.tagLength,b=65536,w=g+b,m=new Uint8Array([168,13,244,58,143,189,3,8,167,202,184,62,88,31,134,177]);r.msgErrorBadDecryptUTF8="bad decryption - utf-8 invalid",r.msgErrorBadDecryptControlChar="bad decryption - contains control chars",r.msgErrorEncryptedFileTooShort="file is too short to be encrypted",r.msgErrorEncryptedFileBadHeader="file has truncated block header",r.msgErrorEncryptedBadMagic="not an encrypted file - bad magic string",r.msgErrorEncryptedBadBlock="failed to authenticate decrypted block - bad password?",r.msgErrorBadBase32Encoding="bad base32 filename encoding",r.msgErrorFileClosed="file already closed",r.msgErrorNotAnEncryptedFile="not an encrypted file - does not match suffix",r.msgErrorBadSeek="Seek beyond end of file",r.msgErrorSuffixMissingDot="suffix config setting should include a '.'";function E(t,e){for(;t<e.length;t++){const r=e[t],n=r+1&255;if(e[t]=n,n>=r)break}}function v(t){return E(0,t)}function x(t){const e=Math.floor(t/b),r=t%b;let n=32+e*(g+b);return 0!==r&&(n+=g+r),n}function A(t){let e=t;if(e-=32,e<0)throw new Error(r.msgErrorEncryptedFileTooShort);const n=Math.floor(e/w);let o=e%w,s=n*b;if(0!==o&&(o-=g,o<=0))throw new Error(r.msgErrorEncryptedFileBadHeader);return s+=o,s}r.Cipher=class{constructor(t){this.dataKey=new Uint8Array(32),this.nameKey=new Uint8Array(32),this.nameTweak=new Uint8Array(16),this.dirNameEncrypt=!0,this.fileNameEnc=t}toString(){return`\ndataKey=${this.dataKey} \nnameKey=${this.nameKey}\nnameTweak=${this.nameTweak}\ndirNameEncrypt=${this.dirNameEncrypt}\nfileNameEnc=${this.fileNameEnc}\n`}encodeToString(t){if("base32"===this.fileNameEnc)return f.base32hex.stringify(t,{pad:!1}).toLowerCase();if("base64"===this.fileNameEnc)return f.base64url.stringify(t,{pad:!1});if("base32768"===this.fileNameEnc)return y.encode(t);throw Error(`unknown fileNameEnc=${this.fileNameEnc}`)}decodeString(t){if("base32"===this.fileNameEnc){if(t.endsWith("="))throw new Error(r.msgErrorBadBase32Encoding);return f.base32hex.parse(t.toUpperCase(),{loose:!0})}if("base64"===this.fileNameEnc)return f.base64url.parse(t,{loose:!0});if("base32768"===this.fileNameEnc)return y.decode(t);throw Error(`unknown fileNameEnc=${this.fileNameEnc}`)}key(t,e){return i(this,void 0,void 0,(function*(){const r=this.dataKey.length+this.nameKey.length+this.nameTweak.length;let n,o=m;return""!==e&&(o=(new TextEncoder).encode(e)),n=""===t?new Uint8Array(r):yield(0,c.scryptAsync)((new TextEncoder).encode(t),o,{N:16384,r:8,p:1,dkLen:r}),this.dataKey.set(n.slice(0,this.dataKey.length)),this.nameKey.set(n.slice(this.dataKey.length,this.dataKey.length+this.nameKey.length)),this.nameTweak.set(n.slice(this.dataKey.length+this.nameKey.length)),this}))}updateInternalKey(t,e,r){return this.dataKey=t,this.nameKey=e,this.nameTweak=r,this}getInternalKey(){return{dataKey:this.dataKey,nameKey:this.nameKey,nameTweak:this.nameTweak}}encryptSegment(t){return i(this,void 0,void 0,(function*(){if(""===t)return"";const e=(0,u.pad)((new TextEncoder).encode(t),16),r=new h.AESCipherBlock(this.nameKey),n=new h.EMECipher(r),o=yield n.encrypt(this.nameTweak,e);return this.encodeToString(o)}))}encryptFileName(t){return i(this,void 0,void 0,(function*(){const e=t.split("/");for(let t=0;t<e.length;++t)(this.dirNameEncrypt||t===e.length-1)&&(e[t]=yield this.encryptSegment(e[t]));return e.join("/")}))}decryptSegment(t){return i(this,void 0,void 0,(function*(){if(""===t)return"";const e=this.decodeString(t),r=new h.AESCipherBlock(this.nameKey),n=new h.EMECipher(r),o=yield n.decrypt(this.nameTweak,e),s=(0,u.unpad)(o);return(new TextDecoder).decode(s)}))}decryptFileName(t){return i(this,void 0,void 0,(function*(){const e=t.split("/");for(let t=0;t<e.length;++t)(this.dirNameEncrypt||t===e.length-1)&&(e[t]=yield this.decryptSegment(e[t]));return e.join("/")}))}encryptData(t,e){return i(this,void 0,void 0,(function*(){let r;r=void 0!==e?e:(0,l.randomBytes)(a.xsalsa20poly1305.nonceLength);const n=new Uint8Array(x(t.byteLength));n.set(p),n.set(r,8);for(let e=0,o=0;e<t.byteLength;e+=b,o+=1){const s=t.slice(e,e+b),i=(0,a.xsalsa20poly1305)(this.dataKey,r).encrypt(s);v(r),n.set(i,32+e+o*g)}return n}))}decryptData(t){return i(this,void 0,void 0,(function*(){if(t.byteLength<32)throw Error(r.msgErrorEncryptedFileTooShort);if(!function(t,e){if(t.length!==e.length)return!1;for(let r=0;r<t.length;++r)if(t[r]!==e[r])return!1;return!0}(t.slice(0,8),p))throw Error(r.msgErrorEncryptedBadMagic);const e=t.slice(8,32),n=new Uint8Array(A(t.byteLength));for(let o=32,s=0,i=0;o<t.byteLength;o+=w,s+=b,i+=1){const i=t.slice(o,o+w),c=(0,a.xsalsa20poly1305)(this.dataKey,e).decrypt(i);if(null===c)throw Error(r.msgErrorEncryptedBadBlock);v(e),n.set(c,s)}return n}))}},r.carry=E,r.increment=v,r.add=function(t,e){let r=BigInt(0);"bigint"==typeof t?r=BigInt.asUintN(64,t):"number"==typeof t&&(r=BigInt.asUintN(64,BigInt(t)));let n=BigInt.asUintN(16,BigInt(0));for(let t=0;t<8;t++){const o=e[t],s=r&BigInt(255);r>>=BigInt(8),n=n+BigInt(o)+BigInt(s),e[t]=Number(n),n>>=BigInt(8)}n!==BigInt(0)&&E(8,e)},r.encryptedSize=x,r.decryptedSize=A},{"@fyears/eme":2,"@noble/ciphers/salsa":10,"@noble/ciphers/webcrypto":12,"@noble/hashes/scrypt":18,base32768:21,"pkcs7-padding":22,rfc4648:23}],4:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.createCipher=r.rotl=r.sigma=void 0;const n=t("./_assert.js"),o=t("./utils.js"),s=t=>Uint8Array.from(t.split("").map((t=>t.charCodeAt(0)))),i=s("expand 16-byte k"),c=s("expand 32-byte k"),a=(0,o.u32)(i),l=(0,o.u32)(c);function u(t){return t.byteOffset%4==0}r.sigma=l.slice(),r.rotl=function(t,e){return t<<e|t>>>32-e};const h=64,f=16,y=2**32-1,d=new Uint32Array;r.createCipher=function(t,e){const{allowShortKeys:r,extendNonceFn:s,counterLength:i,counterRight:c,rounds:p}=(0,o.checkOpts)({allowShortKeys:!1,counterLength:8,counterRight:!1,rounds:20},e);if("function"!=typeof t)throw new Error("core must be a function");return(0,n.number)(i),(0,n.number)(p),(0,n.bool)(c),(0,n.bool)(r),(e,g,b,w,m=0)=>{(0,n.bytes)(e),(0,n.bytes)(g),(0,n.bytes)(b);const E=b.length;if(w||(w=new Uint8Array(E)),(0,n.bytes)(w),(0,n.number)(m),m<0||m>=y)throw new Error("arx: counter overflow");if(w.length<E)throw new Error(`arx: output (${w.length}) is shorter than data (${E})`);const v=[];let x,A,B=e.length;if(32===B)x=e.slice(),v.push(x),A=l;else{if(16!==B||!r)throw new Error(`arx: invalid 32-byte key, got length=${B}`);x=new Uint8Array(32),x.set(e),x.set(e,16),A=a,v.push(x)}u(g)||(g=g.slice(),v.push(g));const U=(0,o.u32)(x);if(s){if(24!==g.length)throw new Error("arx: extended nonce must be 24 bytes");s(A,U,(0,o.u32)(g.subarray(0,16)),U),g=g.subarray(16)}const k=16-i;if(k!==g.length)throw new Error(`arx: nonce must be ${k} or 16 bytes`);if(12!==k){const t=new Uint8Array(12);t.set(g,c?0:12-g.length),g=t,v.push(g)}const L=(0,o.u32)(g);for(!function(t,e,r,n,s,i,c,a){const l=s.length,p=new Uint8Array(h),g=(0,o.u32)(p),b=u(s)&&u(i),w=b?(0,o.u32)(s):d,m=b?(0,o.u32)(i):d;for(let o=0;o<l;c++){if(t(e,r,n,g,c,a),c>=y)throw new Error("arx: counter overflow");const u=Math.min(h,l-o);if(b&&u===h){const t=o/4;if(o%4!=0)throw new Error("arx: invalid block position");for(let e,r=0;r<f;r++)e=t+r,m[e]=w[e]^g[r];o+=h}else{for(let t,e=0;e<u;e++)t=o+e,i[t]=s[t]^p[e];o+=u}}}(t,A,U,L,b,w,m,p);v.length>0;)v.pop().fill(0);return w}}},{"./_assert.js":5,"./utils.js":11}],5:[function(t,e,r){"use strict";function n(t){if(!Number.isSafeInteger(t)||t<0)throw new Error(`positive integer expected, not ${t}`)}function o(t){if("boolean"!=typeof t)throw new Error(`boolean expected, not ${t}`)}function s(t){return t instanceof Uint8Array||null!=t&&"object"==typeof t&&"Uint8Array"===t.constructor.name}function i(t,...e){if(!s(t))throw new Error("Uint8Array expected");if(e.length>0&&!e.includes(t.length))throw new Error(`Uint8Array expected of length ${e}, not of length=${t.length}`)}function c(t){if("function"!=typeof t||"function"!=typeof t.create)throw new Error("hash must be wrapped by utils.wrapConstructor");n(t.outputLen),n(t.blockLen)}function a(t,e=!0){if(t.destroyed)throw new Error("Hash instance has been destroyed");if(e&&t.finished)throw new Error("Hash#digest() has already been called")}function l(t,e){i(t);const r=e.outputLen;if(t.length<r)throw new Error(`digestInto() expects output buffer of length at least ${r}`)}Object.defineProperty(r,"__esModule",{value:!0}),r.output=r.exists=r.hash=r.bytes=r.bool=r.number=r.isBytes=void 0,r.number=n,r.bool=o,r.isBytes=s,r.bytes=i,r.hash=c,r.exists=a,r.output=l;const u={number:n,bool:o,bytes:i,hash:c,exists:a,output:l};r.default=u},{}],6:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.poly1305=r.wrapConstructorWithKey=void 0;const n=t("./_assert.js"),o=t("./utils.js"),s=(t,e)=>255&t[e++]|(255&t[e++])<<8;class i{constructor(t){this.blockLen=16,this.outputLen=16,this.buffer=new Uint8Array(16),this.r=new Uint16Array(10),this.h=new Uint16Array(10),this.pad=new Uint16Array(8),this.pos=0,this.finished=!1,t=(0,o.toBytes)(t),(0,n.bytes)(t,32);const e=s(t,0),r=s(t,2),i=s(t,4),c=s(t,6),a=s(t,8),l=s(t,10),u=s(t,12),h=s(t,14);this.r[0]=8191&e,this.r[1]=8191&(e>>>13|r<<3),this.r[2]=7939&(r>>>10|i<<6),this.r[3]=8191&(i>>>7|c<<9),this.r[4]=255&(c>>>4|a<<12),this.r[5]=a>>>1&8190,this.r[6]=8191&(a>>>14|l<<2),this.r[7]=8065&(l>>>11|u<<5),this.r[8]=8191&(u>>>8|h<<8),this.r[9]=h>>>5&127;for(let e=0;e<8;e++)this.pad[e]=s(t,16+2*e)}process(t,e,r=!1){const n=r?0:2048,{h:o,r:i}=this,c=i[0],a=i[1],l=i[2],u=i[3],h=i[4],f=i[5],y=i[6],d=i[7],p=i[8],g=i[9],b=s(t,e+0),w=s(t,e+2),m=s(t,e+4),E=s(t,e+6),v=s(t,e+8),x=s(t,e+10),A=s(t,e+12),B=s(t,e+14);let U=o[0]+(8191&b),k=o[1]+(8191&(b>>>13|w<<3)),L=o[2]+(8191&(w>>>10|m<<6)),_=o[3]+(8191&(m>>>7|E<<9)),j=o[4]+(8191&(E>>>4|v<<12)),C=o[5]+(v>>>1&8191),S=o[6]+(8191&(v>>>14|x<<2)),T=o[7]+(8191&(x>>>11|A<<5)),O=o[8]+(8191&(A>>>8|B<<8)),M=o[9]+(B>>>5|n),I=0,K=I+U*c+k*(5*g)+L*(5*p)+_*(5*d)+j*(5*y);I=K>>>13,K&=8191,K+=C*(5*f)+S*(5*h)+T*(5*u)+O*(5*l)+M*(5*a),I+=K>>>13,K&=8191;let N=I+U*a+k*c+L*(5*g)+_*(5*p)+j*(5*d);I=N>>>13,N&=8191,N+=C*(5*y)+S*(5*f)+T*(5*h)+O*(5*u)+M*(5*l),I+=N>>>13,N&=8191;let H=I+U*l+k*a+L*c+_*(5*g)+j*(5*p);I=H>>>13,H&=8191,H+=C*(5*d)+S*(5*y)+T*(5*f)+O*(5*h)+M*(5*u),I+=H>>>13,H&=8191;let P=I+U*u+k*l+L*a+_*c+j*(5*g);I=P>>>13,P&=8191,P+=C*(5*p)+S*(5*d)+T*(5*y)+O*(5*f)+M*(5*h),I+=P>>>13,P&=8191;let D=I+U*h+k*u+L*l+_*a+j*c;I=D>>>13,D&=8191,D+=C*(5*g)+S*(5*p)+T*(5*d)+O*(5*y)+M*(5*f),I+=D>>>13,D&=8191;let F=I+U*f+k*h+L*u+_*l+j*a;I=F>>>13,F&=8191,F+=C*c+S*(5*g)+T*(5*p)+O*(5*d)+M*(5*y),I+=F>>>13,F&=8191;let $=I+U*y+k*f+L*h+_*u+j*l;I=$>>>13,$&=8191,$+=C*a+S*c+T*(5*g)+O*(5*p)+M*(5*d),I+=$>>>13,$&=8191;let R=I+U*d+k*y+L*f+_*h+j*u;I=R>>>13,R&=8191,R+=C*l+S*a+T*c+O*(5*g)+M*(5*p),I+=R>>>13,R&=8191;let V=I+U*p+k*d+L*y+_*f+j*h;I=V>>>13,V&=8191,V+=C*u+S*l+T*a+O*c+M*(5*g),I+=V>>>13,V&=8191;let z=I+U*g+k*p+L*d+_*y+j*f;I=z>>>13,z&=8191,z+=C*h+S*u+T*l+O*a+M*c,I+=z>>>13,z&=8191,I=(I<<2)+I|0,I=I+K|0,K=8191&I,I>>>=13,N+=I,o[0]=K,o[1]=N,o[2]=H,o[3]=P,o[4]=D,o[5]=F,o[6]=$,o[7]=R,o[8]=V,o[9]=z}finalize(){const{h:t,pad:e}=this,r=new Uint16Array(10);let n=t[1]>>>13;t[1]&=8191;for(let e=2;e<10;e++)t[e]+=n,n=t[e]>>>13,t[e]&=8191;t[0]+=5*n,n=t[0]>>>13,t[0]&=8191,t[1]+=n,n=t[1]>>>13,t[1]&=8191,t[2]+=n,r[0]=t[0]+5,n=r[0]>>>13,r[0]&=8191;for(let e=1;e<10;e++)r[e]=t[e]+n,n=r[e]>>>13,r[e]&=8191;r[9]-=8192;let o=(1^n)-1;for(let t=0;t<10;t++)r[t]&=o;o=~o;for(let e=0;e<10;e++)t[e]=t[e]&o|r[e];t[0]=65535&(t[0]|t[1]<<13),t[1]=65535&(t[1]>>>3|t[2]<<10),t[2]=65535&(t[2]>>>6|t[3]<<7),t[3]=65535&(t[3]>>>9|t[4]<<4),t[4]=65535&(t[4]>>>12|t[5]<<1|t[6]<<14),t[5]=65535&(t[6]>>>2|t[7]<<11),t[6]=65535&(t[7]>>>5|t[8]<<8),t[7]=65535&(t[8]>>>8|t[9]<<5);let s=t[0]+e[0];t[0]=65535&s;for(let r=1;r<8;r++)s=(t[r]+e[r]|0)+(s>>>16)|0,t[r]=65535&s}update(t){(0,n.exists)(this);const{buffer:e,blockLen:r}=this,s=(t=(0,o.toBytes)(t)).length;for(let n=0;n<s;){const o=Math.min(r-this.pos,s-n);if(o!==r)e.set(t.subarray(n,n+o),this.pos),this.pos+=o,n+=o,this.pos===r&&(this.process(e,0,!1),this.pos=0);else for(;r<=s-n;n+=r)this.process(t,n)}return this}destroy(){this.h.fill(0),this.r.fill(0),this.buffer.fill(0),this.pad.fill(0)}digestInto(t){(0,n.exists)(this),(0,n.output)(t,this),this.finished=!0;const{buffer:e,h:r}=this;let{pos:o}=this;if(o){for(e[o++]=1;o<16;o++)e[o]=0;this.process(e,0,!0)}this.finalize();let s=0;for(let e=0;e<8;e++)t[s++]=r[e]>>>0,t[s++]=r[e]>>>8;return t}digest(){const{buffer:t,outputLen:e}=this;this.digestInto(t);const r=t.slice(0,e);return this.destroy(),r}}function c(t){const e=(e,r)=>t(r).update((0,o.toBytes)(e)).digest(),r=t(new Uint8Array(32));return e.outputLen=r.outputLen,e.blockLen=r.blockLen,e.create=e=>t(e),e}r.wrapConstructorWithKey=c,r.poly1305=c((t=>new i(t)))},{"./_assert.js":5,"./utils.js":11}],7:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.polyval=r.ghash=r._toGHASHKey=void 0;const n=t("./utils.js"),o=t("./_assert.js"),s=16,i=new Uint8Array(16),c=(0,n.u32)(i),a=t=>(t>>>0&255)<<24|(t>>>8&255)<<16|(t>>>16&255)<<8|t>>>24&255|0;function l(t){t.reverse();const e=1&t[15];let r=0;for(let e=0;e<t.length;e++){const n=t[e];t[e]=n>>>1|r,r=(1&n)<<7}return t[0]^=225&-e,t}r._toGHASHKey=l;class u{constructor(t,e){this.blockLen=s,this.outputLen=s,this.s0=0,this.s1=0,this.s2=0,this.s3=0,this.finished=!1,t=(0,n.toBytes)(t),(0,o.bytes)(t,16);const r=(0,n.createView)(t);let i=r.getUint32(0,!1),c=r.getUint32(4,!1),l=r.getUint32(8,!1),u=r.getUint32(12,!1);const h=[];for(let t=0;t<128;t++)h.push({s0:a(i),s1:a(c),s2:a(l),s3:a(u)}),({s0:i,s1:c,s2:l,s3:u}={s3:(d=l)<<31|(p=u)>>>1,s2:(y=c)<<31|d>>>1,s1:(f=i)<<31|y>>>1,s0:f>>>1^225<<24&-(1&p)});var f,y,d,p;const g=(b=e||1024)>65536?8:b>1024?4:2;var b;if(![1,2,4,8].includes(g))throw new Error(`ghash: wrong window size=${g}, should be 2, 4 or 8`);this.W=g;const w=128/g,m=this.windowSize=2**g,E=[];for(let t=0;t<w;t++)for(let e=0;e<m;e++){let r=0,n=0,o=0,s=0;for(let i=0;i<g;i++){if(!(e>>>g-i-1&1))continue;const{s0:c,s1:a,s2:l,s3:u}=h[g*t+i];r^=c,n^=a,o^=l,s^=u}E.push({s0:r,s1:n,s2:o,s3:s})}this.t=E}_updateBlock(t,e,r,n){t^=this.s0,e^=this.s1,r^=this.s2,n^=this.s3;const{W:o,t:s,windowSize:i}=this;let c=0,a=0,l=0,u=0;const h=(1<<o)-1;let f=0;for(const y of[t,e,r,n])for(let t=0;t<4;t++){const e=y>>>8*t&255;for(let t=8/o-1;t>=0;t--){const r=e>>>o*t&h,{s0:n,s1:y,s2:d,s3:p}=s[f*i+r];c^=n,a^=y,l^=d,u^=p,f+=1}}this.s0=c,this.s1=a,this.s2=l,this.s3=u}update(t){t=(0,n.toBytes)(t),(0,o.exists)(this);const e=(0,n.u32)(t),r=Math.floor(t.length/s),a=t.length%s;for(let t=0;t<r;t++)this._updateBlock(e[4*t+0],e[4*t+1],e[4*t+2],e[4*t+3]);return a&&(i.set(t.subarray(r*s)),this._updateBlock(c[0],c[1],c[2],c[3]),c.fill(0)),this}destroy(){const{t:t}=this;for(const e of t)e.s0=0,e.s1=0,e.s2=0,e.s3=0}digestInto(t){(0,o.exists)(this),(0,o.output)(t,this),this.finished=!0;const{s0:e,s1:r,s2:s,s3:i}=this,c=(0,n.u32)(t);return c[0]=e,c[1]=r,c[2]=s,c[3]=i,t}digest(){const t=new Uint8Array(s);return this.digestInto(t),this.destroy(),t}}class h extends u{constructor(t,e){const r=l((t=(0,n.toBytes)(t)).slice());super(r,e),r.fill(0)}update(t){t=(0,n.toBytes)(t),(0,o.exists)(this);const e=(0,n.u32)(t),r=t.length%s,l=Math.floor(t.length/s);for(let t=0;t<l;t++)this._updateBlock(a(e[4*t+3]),a(e[4*t+2]),a(e[4*t+1]),a(e[4*t+0]));return r&&(i.set(t.subarray(l*s)),this._updateBlock(a(c[3]),a(c[2]),a(c[1]),a(c[0])),c.fill(0)),this}digestInto(t){(0,o.exists)(this),(0,o.output)(t,this),this.finished=!0;const{s0:e,s1:r,s2:s,s3:i}=this,c=(0,n.u32)(t);return c[0]=e,c[1]=r,c[2]=s,c[3]=i,t.reverse()}}function f(t){const e=(e,r)=>t(r,e.length).update((0,n.toBytes)(e)).digest(),r=t(new Uint8Array(16),0);return e.outputLen=r.outputLen,e.blockLen=r.blockLen,e.create=(e,r)=>t(e,r),e}r.ghash=f(((t,e)=>new u(t,e))),r.polyval=f(((t,e)=>new h(t,e)))},{"./_assert.js":5,"./utils.js":11}],8:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.unsafe=r.siv=r.gcm=r.cfb=r.cbc=r.ecb=r.ctr=r.expandKeyDecLE=r.expandKeyLE=void 0;const n=t("./utils.js"),o=t("./_polyval.js"),s=t("./_assert.js"),i=16,c=new Uint8Array(i),a=283;function l(t){return t<<1^a&-(t>>7)}function u(t,e){let r=0;for(;e>0;e>>=1)r^=t&-(1&e),t=l(t);return r}const h=(()=>{let t=new Uint8Array(256);for(let e=0,r=1;e<256;e++,r^=l(r))t[e]=r;const e=new Uint8Array(256);e[0]=99;for(let r=0;r<255;r++){let n=t[255-r];n|=n<<8,e[t[r]]=255&(n^n>>4^n>>5^n>>6^n>>7^99)}return e})(),f=h.map(((t,e)=>h.indexOf(e))),y=t=>t<<24|t>>>8,d=t=>t<<8|t>>>24;function p(t,e){if(256!==t.length)throw new Error("Wrong sbox length");const r=new Uint32Array(256).map(((r,n)=>e(t[n]))),n=r.map(d),o=n.map(d),s=o.map(d),i=new Uint32Array(65536),c=new Uint32Array(65536),a=new Uint16Array(65536);for(let e=0;e<256;e++)for(let l=0;l<256;l++){const u=256*e+l;i[u]=r[e]^n[l],c[u]=o[e]^s[l],a[u]=t[e]<<8|t[l]}return{sbox:t,sbox2:a,T0:r,T1:n,T2:o,T3:s,T01:i,T23:c}}const g=p(h,(t=>u(t,3)<<24|t<<16|t<<8|u(t,2))),b=p(f,(t=>u(t,11)<<24|u(t,13)<<16|u(t,9)<<8|u(t,14))),w=(()=>{const t=new Uint8Array(16);for(let e=0,r=1;e<16;e++,r=l(r))t[e]=r;return t})();function m(t){(0,s.bytes)(t);const e=t.length;if(![16,24,32].includes(e))throw new Error(`aes: wrong key size: should be 16, 24 or 32, got: ${e}`);const{sbox2:r}=g,o=(0,n.u32)(t),i=o.length,c=t=>x(r,t,t,t,t),a=new Uint32Array(e+28);a.set(o);for(let t=i;t<a.length;t++){let e=a[t-1];t%i==0?e=c(y(e))^w[t/i-1]:i>6&&t%i==4&&(e=c(e)),a[t]=a[t-i]^e}return a}function E(t){const e=m(t),r=e.slice(),n=e.length,{sbox2:o}=g,{T0:s,T1:i,T2:c,T3:a}=b;for(let t=0;t<n;t+=4)for(let o=0;o<4;o++)r[t+o]=e[n-t-4+o];e.fill(0);for(let t=4;t<n-4;t++){const e=r[t],n=x(o,e,e,e,e);r[t]=s[255&n]^i[n>>>8&255]^c[n>>>16&255]^a[n>>>24]}return r}function v(t,e,r,n,o,s){return t[r<<8&65280|n>>>8&255]^e[o>>>8&65280|s>>>24&255]}function x(t,e,r,n,o){return t[255&e|65280&r]|t[n>>>16&255|o>>>16&65280]<<16}function A(t,e,r,n,o){const{sbox2:s,T01:i,T23:c}=g;let a=0;e^=t[a++],r^=t[a++],n^=t[a++],o^=t[a++];const l=t.length/4-2;for(let s=0;s<l;s++){const s=t[a++]^v(i,c,e,r,n,o),l=t[a++]^v(i,c,r,n,o,e),u=t[a++]^v(i,c,n,o,e,r),h=t[a++]^v(i,c,o,e,r,n);e=s,r=l,n=u,o=h}return{s0:t[a++]^x(s,e,r,n,o),s1:t[a++]^x(s,r,n,o,e),s2:t[a++]^x(s,n,o,e,r),s3:t[a++]^x(s,o,e,r,n)}}function B(t,e,r,n,o){const{sbox2:s,T01:i,T23:c}=b;let a=0;e^=t[a++],r^=t[a++],n^=t[a++],o^=t[a++];const l=t.length/4-2;for(let s=0;s<l;s++){const s=t[a++]^v(i,c,e,o,n,r),l=t[a++]^v(i,c,r,e,o,n),u=t[a++]^v(i,c,n,r,e,o),h=t[a++]^v(i,c,o,n,r,e);e=s,r=l,n=u,o=h}return{s0:t[a++]^x(s,e,o,n,r),s1:t[a++]^x(s,r,e,o,n),s2:t[a++]^x(s,n,r,e,o),s3:t[a++]^x(s,o,n,r,e)}}function U(t,e){if(!e)return new Uint8Array(t);if((0,s.bytes)(e),e.length<t)throw new Error(`aes: wrong destination length, expected at least ${t}, got: ${e.length}`);return e}function k(t,e,r,o){(0,s.bytes)(e,i),(0,s.bytes)(r);const c=r.length;o=U(c,o);const a=e,l=(0,n.u32)(a);let{s0:u,s1:h,s2:f,s3:y}=A(t,l[0],l[1],l[2],l[3]);const d=(0,n.u32)(r),p=(0,n.u32)(o);for(let e=0;e+4<=d.length;e+=4){p[e+0]=d[e+0]^u,p[e+1]=d[e+1]^h,p[e+2]=d[e+2]^f,p[e+3]=d[e+3]^y;let r=1;for(let t=a.length-1;t>=0;t--)r=r+(255&a[t])|0,a[t]=255&r,r>>>=8;({s0:u,s1:h,s2:f,s3:y}=A(t,l[0],l[1],l[2],l[3]))}const g=i*Math.floor(d.length/4);if(g<c){const t=new Uint32Array([u,h,f,y]),e=(0,n.u8)(t);for(let t=g,n=0;t<c;t++,n++)o[t]=r[t]^e[n]}return o}function L(t,e,r,o,c){(0,s.bytes)(r,i),(0,s.bytes)(o),c=U(o.length,c);const a=r,l=(0,n.u32)(a),u=(0,n.createView)(a),h=(0,n.u32)(o),f=(0,n.u32)(c),y=e?0:12,d=o.length;let p=u.getUint32(y,e),{s0:g,s1:b,s2:w,s3:m}=A(t,l[0],l[1],l[2],l[3]);for(let r=0;r+4<=h.length;r+=4)f[r+0]=h[r+0]^g,f[r+1]=h[r+1]^b,f[r+2]=h[r+2]^w,f[r+3]=h[r+3]^m,p=p+1>>>0,u.setUint32(y,p,e),({s0:g,s1:b,s2:w,s3:m}=A(t,l[0],l[1],l[2],l[3]));const E=i*Math.floor(h.length/4);if(E<d){const t=new Uint32Array([g,b,w,m]),e=(0,n.u8)(t);for(let t=E,r=0;t<d;t++,r++)c[t]=o[t]^e[r]}return c}function _(t){if((0,s.bytes)(t),t.length%i!=0)throw new Error("aes/(cbc-ecb).decrypt ciphertext should consist of blocks with size 16")}function j(t,e,r){let o=t.length;const s=o%i;if(!e&&0!==s)throw new Error("aec/(cbc-ecb): unpadded plaintext with disabled padding");const c=(0,n.u32)(t);if(e){let t=i-s;t||(t=i),o+=t}const a=U(o,r);return{b:c,o:(0,n.u32)(a),out:a}}function C(t,e){if(!e)return t;const r=t.length;if(!r)throw new Error("aes/pcks5: empty ciphertext not allowed");const n=t[r-1];if(n<=0||n>16)throw new Error(`aes/pcks5: wrong padding byte: ${n}`);const o=t.subarray(0,-n);for(let e=0;e<n;e++)if(t[r-e-1]!==n)throw new Error("aes/pcks5: wrong padding");return o}function S(t){const e=new Uint8Array(16),r=(0,n.u32)(e);e.set(t);const o=i-t.length;for(let t=i-o;t<i;t++)e[t]=o;return r}function T(t,e,r,o,s){const i=t.create(r,o.length+(s?.length||0));s&&i.update(s),i.update(o);const c=new Uint8Array(16),a=(0,n.createView)(c);return s&&(0,n.setBigUint64)(a,0,BigInt(8*s.length),e),(0,n.setBigUint64)(a,8,BigInt(8*o.length),e),i.update(c),i.digest()}r.expandKeyLE=m,r.expandKeyDecLE=E,r.ctr=(0,n.wrapCipher)({blockSize:16,nonceLength:16},(function(t,e){function r(r,n){const o=m(t),s=e.slice(),i=k(o,s,r,n);return o.fill(0),s.fill(0),i}return(0,s.bytes)(t),(0,s.bytes)(e,i),{encrypt:(t,e)=>r(t,e),decrypt:(t,e)=>r(t,e)}})),r.ecb=(0,n.wrapCipher)({blockSize:16},(function(t,e={}){(0,s.bytes)(t);const r=!e.disablePadding;return{encrypt:(e,n)=>{(0,s.bytes)(e);const{b:o,o:i,out:c}=j(e,r,n),a=m(t);let l=0;for(;l+4<=o.length;){const{s0:t,s1:e,s2:r,s3:n}=A(a,o[l+0],o[l+1],o[l+2],o[l+3]);i[l++]=t,i[l++]=e,i[l++]=r,i[l++]=n}if(r){const t=S(e.subarray(4*l)),{s0:r,s1:n,s2:o,s3:s}=A(a,t[0],t[1],t[2],t[3]);i[l++]=r,i[l++]=n,i[l++]=o,i[l++]=s}return a.fill(0),c},decrypt:(e,o)=>{_(e);const s=E(t),i=U(e.length,o),c=(0,n.u32)(e),a=(0,n.u32)(i);for(let t=0;t+4<=c.length;){const{s0:e,s1:r,s2:n,s3:o}=B(s,c[t+0],c[t+1],c[t+2],c[t+3]);a[t++]=e,a[t++]=r,a[t++]=n,a[t++]=o}return s.fill(0),C(i,r)}}})),r.cbc=(0,n.wrapCipher)({blockSize:16,nonceLength:16},(function(t,e,r={}){(0,s.bytes)(t),(0,s.bytes)(e,16);const o=!r.disablePadding;return{encrypt:(r,s)=>{const i=m(t),{b:c,o:a,out:l}=j(r,o,s),u=(0,n.u32)(e);let h=u[0],f=u[1],y=u[2],d=u[3],p=0;for(;p+4<=c.length;)h^=c[p+0],f^=c[p+1],y^=c[p+2],d^=c[p+3],({s0:h,s1:f,s2:y,s3:d}=A(i,h,f,y,d)),a[p++]=h,a[p++]=f,a[p++]=y,a[p++]=d;if(o){const t=S(r.subarray(4*p));h^=t[0],f^=t[1],y^=t[2],d^=t[3],({s0:h,s1:f,s2:y,s3:d}=A(i,h,f,y,d)),a[p++]=h,a[p++]=f,a[p++]=y,a[p++]=d}return i.fill(0),l},decrypt:(r,s)=>{_(r);const i=E(t),c=(0,n.u32)(e),a=U(r.length,s),l=(0,n.u32)(r),u=(0,n.u32)(a);let h=c[0],f=c[1],y=c[2],d=c[3];for(let t=0;t+4<=l.length;){const e=h,r=f,n=y,o=d;h=l[t+0],f=l[t+1],y=l[t+2],d=l[t+3];const{s0:s,s1:c,s2:a,s3:p}=B(i,h,f,y,d);u[t++]=s^e,u[t++]=c^r,u[t++]=a^n,u[t++]=p^o}return i.fill(0),C(a,o)}}})),r.cfb=(0,n.wrapCipher)({blockSize:16,nonceLength:16},(function(t,e){function r(r,o,s){const c=m(t),a=r.length;s=U(a,s);const l=(0,n.u32)(r),u=(0,n.u32)(s),h=o?u:l,f=(0,n.u32)(e);let y=f[0],d=f[1],p=f[2],g=f[3];for(let t=0;t+4<=l.length;){const{s0:e,s1:r,s2:n,s3:o}=A(c,y,d,p,g);u[t+0]=l[t+0]^e,u[t+1]=l[t+1]^r,u[t+2]=l[t+2]^n,u[t+3]=l[t+3]^o,y=h[t++],d=h[t++],p=h[t++],g=h[t++]}const b=i*Math.floor(l.length/4);if(b<a){({s0:y,s1:d,s2:p,s3:g}=A(c,y,d,p,g));const t=(0,n.u8)(new Uint32Array([y,d,p,g]));for(let e=b,n=0;e<a;e++,n++)s[e]=r[e]^t[n];t.fill(0)}return c.fill(0),s}return(0,s.bytes)(t),(0,s.bytes)(e,16),{encrypt:(t,e)=>r(t,!0,e),decrypt:(t,e)=>r(t,!1,e)}})),r.gcm=(0,n.wrapCipher)({blockSize:16,nonceLength:12,tagLength:16},(function(t,e,r){if((0,s.bytes)(e),0===e.length)throw new Error("aes/gcm: empty nonce");const i=16;function a(t,e,n){const s=T(o.ghash,!1,t,n,r);for(let t=0;t<e.length;t++)s[t]^=e[t];return s}function l(){const r=m(t),s=c.slice(),i=c.slice();if(L(r,!1,i,i,s),12===e.length)i.set(e);else{const t=c.slice(),r=(0,n.createView)(t);(0,n.setBigUint64)(r,8,BigInt(8*e.length),!1),o.ghash.create(s).update(e).update(t).digestInto(i)}return{xk:r,authKey:s,counter:i,tagMask:L(r,!1,i,c)}}return{encrypt:t=>{(0,s.bytes)(t);const{xk:e,authKey:r,counter:n,tagMask:o}=l(),c=new Uint8Array(t.length+i);L(e,!1,n,t,c);const u=a(r,o,c.subarray(0,c.length-i));return c.set(u,t.length),e.fill(0),c},decrypt:t=>{if((0,s.bytes)(t),t.length<i)throw new Error("aes/gcm: ciphertext less than tagLen (16)");const{xk:e,authKey:r,counter:o,tagMask:c}=l(),u=t.subarray(0,-16),h=t.subarray(-16),f=a(r,c,u);if(!(0,n.equalBytes)(f,h))throw new Error("aes/gcm: invalid ghash tag");const y=L(e,!1,o,u);return r.fill(0),c.fill(0),e.fill(0),y}}}));const O=(t,e,r)=>n=>{if(!Number.isSafeInteger(n)||e>n||n>r)throw new Error(`${t}: invalid value=${n}, must be [${e}..${r}]`)};function M(t){return null!=t&&"object"==typeof t&&(t instanceof Uint32Array||"Uint32Array"===t.constructor.name)}r.siv=(0,n.wrapCipher)({blockSize:16,nonceLength:12,tagLength:16},(function(t,e,r){const i=O("AAD",0,2**36),c=O("plaintext",0,2**36),a=O("nonce",12,12),l=O("ciphertext",16,2**36+16);function u(){const r=t.length;if(16!==r&&24!==r&&32!==r)throw new Error(`key length must be 16, 24 or 32 bytes, got: ${r} bytes`);const o=m(t),s=new Uint8Array(r),i=new Uint8Array(16),c=(0,n.u32)(e);let a=0,l=c[0],u=c[1],h=c[2],f=0;for(const t of[i,s].map(n.u32)){const e=(0,n.u32)(t);for(let t=0;t<e.length;t+=2){const{s0:r,s1:n}=A(o,a,l,u,h);e[t+0]=r,e[t+1]=n,a=++f}}return o.fill(0),{authKey:i,encKey:m(s)}}function h(t,s,i){const c=T(o.polyval,!0,s,i,r);for(let t=0;t<12;t++)c[t]^=e[t];c[15]&=127;const a=(0,n.u32)(c);let l=a[0],u=a[1],h=a[2],f=a[3];return({s0:l,s1:u,s2:h,s3:f}=A(t,l,u,h,f)),a[0]=l,a[1]=u,a[2]=h,a[3]=f,c}function f(t,e,r){let n=e.slice();return n[15]|=128,L(t,!0,n,r)}return(0,s.bytes)(e),a(e.length),r&&((0,s.bytes)(r),i(r.length)),{encrypt:t=>{(0,s.bytes)(t),c(t.length);const{encKey:e,authKey:r}=u(),n=h(e,r,t),o=new Uint8Array(t.length+16);return o.set(n,t.length),o.set(f(e,n,t)),e.fill(0),r.fill(0),o},decrypt:t=>{(0,s.bytes)(t),l(t.length);const e=t.subarray(-16),{encKey:r,authKey:o}=u(),i=f(r,e,t.subarray(0,-16)),c=h(r,o,i);if(r.fill(0),o.fill(0),!(0,n.equalBytes)(e,c))throw new Error("invalid polyval tag");return i}}})),r.unsafe={expandKeyLE:m,expandKeyDecLE:E,encrypt:A,decrypt:B,encryptBlock:function(t,e){if((0,s.bytes)(e,16),!M(t))throw new Error("_encryptBlock accepts result of expandKeyLE");const r=(0,n.u32)(e);let{s0:o,s1:i,s2:c,s3:a}=A(t,r[0],r[1],r[2],r[3]);return r[0]=o,r[1]=i,r[2]=c,r[3]=a,e},decryptBlock:function(t,e){if((0,s.bytes)(e,16),!M(t))throw new Error("_decryptBlock accepts result of expandKeyLE");const r=(0,n.u32)(e);let{s0:o,s1:i,s2:c,s3:a}=B(t,r[0],r[1],r[2],r[3]);return r[0]=o,r[1]=i,r[2]=c,r[3]=a,e},ctrCounter:k,ctr32:L}},{"./_assert.js":5,"./_polyval.js":7,"./utils.js":11}],9:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.getWebcryptoSubtle=r.randomBytes=void 0;const n="object"==typeof globalThis&&"crypto"in globalThis?globalThis.crypto:void 0;r.randomBytes=function(t=32){if(n&&"function"==typeof n.getRandomValues)return n.getRandomValues(new Uint8Array(t));throw new Error("crypto.getRandomValues must be defined")},r.getWebcryptoSubtle=function(){if(n&&"object"==typeof n.subtle&&null!=n.subtle)return n.subtle;throw new Error("crypto.subtle must be defined")}},{}],10:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.secretbox=r.xsalsa20poly1305=r.xsalsa20=r.salsa20=r.hsalsa=void 0;const n=t("./_assert.js"),o=t("./_arx.js"),s=t("./_poly1305.js"),i=t("./utils.js");function c(t,e,r,n,s,i=20){let c=t[0],a=e[0],l=e[1],u=e[2],h=e[3],f=t[1],y=r[0],d=r[1],p=s,g=t[2],b=e[4],w=e[5],m=e[6],E=e[7],v=t[3],x=c,A=a,B=l,U=u,k=h,L=f,_=y,j=d,C=p,S=0,T=g,O=b,M=w,I=m,K=E,N=v;for(let t=0;t<i;t+=2)k^=(0,o.rotl)(x+M|0,7),C^=(0,o.rotl)(k+x|0,9),M^=(0,o.rotl)(C+k|0,13),x^=(0,o.rotl)(M+C|0,18),S^=(0,o.rotl)(L+A|0,7),I^=(0,o.rotl)(S+L|0,9),A^=(0,o.rotl)(I+S|0,13),L^=(0,o.rotl)(A+I|0,18),K^=(0,o.rotl)(T+_|0,7),B^=(0,o.rotl)(K+T|0,9),_^=(0,o.rotl)(B+K|0,13),T^=(0,o.rotl)(_+B|0,18),U^=(0,o.rotl)(N+O|0,7),j^=(0,o.rotl)(U+N|0,9),O^=(0,o.rotl)(j+U|0,13),N^=(0,o.rotl)(O+j|0,18),A^=(0,o.rotl)(x+U|0,7),B^=(0,o.rotl)(A+x|0,9),U^=(0,o.rotl)(B+A|0,13),x^=(0,o.rotl)(U+B|0,18),_^=(0,o.rotl)(L+k|0,7),j^=(0,o.rotl)(_+L|0,9),k^=(0,o.rotl)(j+_|0,13),L^=(0,o.rotl)(k+j|0,18),O^=(0,o.rotl)(T+S|0,7),C^=(0,o.rotl)(O+T|0,9),S^=(0,o.rotl)(C+O|0,13),T^=(0,o.rotl)(S+C|0,18),M^=(0,o.rotl)(N+K|0,7),I^=(0,o.rotl)(M+N|0,9),K^=(0,o.rotl)(I+M|0,13),N^=(0,o.rotl)(K+I|0,18);let H=0;n[H++]=c+x|0,n[H++]=a+A|0,n[H++]=l+B|0,n[H++]=u+U|0,n[H++]=h+k|0,n[H++]=f+L|0,n[H++]=y+_|0,n[H++]=d+j|0,n[H++]=p+C|0,n[H++]=0+S|0,n[H++]=g+T|0,n[H++]=b+O|0,n[H++]=w+M|0,n[H++]=m+I|0,n[H++]=E+K|0,n[H++]=v+N|0}function a(t,e,r,n){let s=t[0],i=e[0],c=e[1],a=e[2],l=e[3],u=t[1],h=r[0],f=r[1],y=r[2],d=r[3],p=t[2],g=e[4],b=e[5],w=e[6],m=e[7],E=t[3];for(let t=0;t<20;t+=2)l^=(0,o.rotl)(s+b|0,7),y^=(0,o.rotl)(l+s|0,9),b^=(0,o.rotl)(y+l|0,13),s^=(0,o.rotl)(b+y|0,18),d^=(0,o.rotl)(u+i|0,7),w^=(0,o.rotl)(d+u|0,9),i^=(0,o.rotl)(w+d|0,13),u^=(0,o.rotl)(i+w|0,18),m^=(0,o.rotl)(p+h|0,7),c^=(0,o.rotl)(m+p|0,9),h^=(0,o.rotl)(c+m|0,13),p^=(0,o.rotl)(h+c|0,18),a^=(0,o.rotl)(E+g|0,7),f^=(0,o.rotl)(a+E|0,9),g^=(0,o.rotl)(f+a|0,13),E^=(0,o.rotl)(g+f|0,18),i^=(0,o.rotl)(s+a|0,7),c^=(0,o.rotl)(i+s|0,9),a^=(0,o.rotl)(c+i|0,13),s^=(0,o.rotl)(a+c|0,18),h^=(0,o.rotl)(u+l|0,7),f^=(0,o.rotl)(h+u|0,9),l^=(0,o.rotl)(f+h|0,13),u^=(0,o.rotl)(l+f|0,18),g^=(0,o.rotl)(p+d|0,7),y^=(0,o.rotl)(g+p|0,9),d^=(0,o.rotl)(y+g|0,13),p^=(0,o.rotl)(d+y|0,18),b^=(0,o.rotl)(E+m|0,7),w^=(0,o.rotl)(b+E|0,9),m^=(0,o.rotl)(w+b|0,13),E^=(0,o.rotl)(m+w|0,18);let v=0;n[v++]=s,n[v++]=u,n[v++]=p,n[v++]=E,n[v++]=h,n[v++]=f,n[v++]=y,n[v++]=d}r.hsalsa=a,r.salsa20=(0,o.createCipher)(c,{allowShortKeys:!0,counterRight:!0}),r.xsalsa20=(0,o.createCipher)(c,{counterRight:!0,extendNonceFn:a}),r.xsalsa20poly1305=(0,i.wrapCipher)({blockSize:64,nonceLength:24,tagLength:16},((t,e)=>{const o=16;return(0,n.bytes)(t,32),(0,n.bytes)(e,24),{encrypt:(i,c)=>{(0,n.bytes)(i);const a=i.length+32;c?(0,n.bytes)(c,a):c=new Uint8Array(a),c.set(i,32),(0,r.xsalsa20)(t,e,c,c);const l=c.subarray(0,32),u=(0,s.poly1305)(c.subarray(32),l);return c.set(u,o),c.subarray(0,o).fill(0),c.subarray(o)},decrypt:c=>{(0,n.bytes)(c);const a=c.length;if(a<o)throw new Error("encrypted data should be at least 16 bytes");const l=new Uint8Array(a+o);l.set(c,o);const u=(0,r.xsalsa20)(t,e,new Uint8Array(32)),h=(0,s.poly1305)(l.subarray(32),u);if(!(0,i.equalBytes)(l.subarray(16,32),h))throw new Error("invalid tag");const f=(0,r.xsalsa20)(t,e,l);return f.subarray(0,32).fill(0),u.fill(0),f.subarray(32)}}})),r.secretbox=function(t,e){const n=(0,r.xsalsa20poly1305)(t,e);return{seal:n.encrypt,open:n.decrypt}}},{"./_arx.js":4,"./_assert.js":5,"./_poly1305.js":6,"./utils.js":11}],11:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.u64Lengths=r.setBigUint64=r.wrapCipher=r.Hash=r.equalBytes=r.checkOpts=r.concatBytes=r.toBytes=r.bytesToUtf8=r.utf8ToBytes=r.asyncLoop=r.nextTick=r.numberToBytesBE=r.bytesToNumberBE=r.hexToNumber=r.hexToBytes=r.bytesToHex=r.isLE=r.createView=r.u32=r.u16=r.u8=void 0;
       /*! noble-ciphers - MIT License (c) 2023 Paul Miller (paulmillr.com) */
       const n=t("./_assert.js");r.u8=t=>new Uint8Array(t.buffer,t.byteOffset,t.byteLength);r.u16=t=>new Uint16Array(t.buffer,t.byteOffset,Math.floor(t.byteLength/2));r.u32=t=>new Uint32Array(t.buffer,t.byteOffset,Math.floor(t.byteLength/4));if(r.createView=t=>new DataView(t.buffer,t.byteOffset,t.byteLength),r.isLE=68===new Uint8Array(new Uint32Array([287454020]).buffer)[0],!r.isLE)throw new Error("Non little-endian hardware is not supported");const o=Array.from({length:256},((t,e)=>e.toString(16).padStart(2,"0")));function s(t){(0,n.bytes)(t);let e="";for(let r=0;r<t.length;r++)e+=o[t[r]];return e}r.bytesToHex=s;const i={_0:48,_9:57,_A:65,_F:70,_a:97,_f:102};function c(t){return t>=i._0&&t<=i._9?t-i._0:t>=i._A&&t<=i._F?t-(i._A-10):t>=i._a&&t<=i._f?t-(i._a-10):void 0}function a(t){if("string"!=typeof t)throw new Error("hex string expected, got "+typeof t);const e=t.length,r=e/2;if(e%2)throw new Error("padded hex string expected, got unpadded hex of length "+e);const n=new Uint8Array(r);for(let e=0,o=0;e<r;e++,o+=2){const r=c(t.charCodeAt(o)),s=c(t.charCodeAt(o+1));if(void 0===r||void 0===s){const e=t[o]+t[o+1];throw new Error('hex string expected, got non-hex character "'+e+'" at index '+o)}n[e]=16*r+s}return n}function l(t){if("string"!=typeof t)throw new Error("hex string expected, got "+typeof t);return BigInt(""===t?"0":`0x${t}`)}r.hexToBytes=a,r.hexToNumber=l,r.bytesToNumberBE=function(t){return l(s(t))},r.numberToBytesBE=function(t,e){return a(t.toString(16).padStart(2*e,"0"))};function u(t){if("string"!=typeof t)throw new Error("string expected, got "+typeof t);return new Uint8Array((new TextEncoder).encode(t))}r.nextTick=async()=>{},r.asyncLoop=async function(t,e,n){let o=Date.now();for(let s=0;s<t;s++){n(s);const t=Date.now()-o;t>=0&&t<e||(await(0,r.nextTick)(),o+=t)}},r.utf8ToBytes=u,r.bytesToUtf8=function(t){return(new TextDecoder).decode(t)},r.toBytes=function(t){if("string"==typeof t)t=u(t);else{if(!(0,n.isBytes)(t))throw new Error("Uint8Array expected, got "+typeof t);t=t.slice()}return t},r.concatBytes=function(...t){let e=0;for(let r=0;r<t.length;r++){const o=t[r];(0,n.bytes)(o),e+=o.length}const r=new Uint8Array(e);for(let e=0,n=0;e<t.length;e++){const o=t[e];r.set(o,n),n+=o.length}return r},r.checkOpts=function(t,e){if(null==e||"object"!=typeof e)throw new Error("options must be defined");return Object.assign(t,e)},r.equalBytes=function(t,e){if(t.length!==e.length)return!1;let r=0;for(let n=0;n<t.length;n++)r|=t[n]^e[n];return 0===r};r.Hash=class{};function h(t,e,r,n){if("function"==typeof t.setBigUint64)return t.setBigUint64(e,r,n);const o=BigInt(32),s=BigInt(4294967295),i=Number(r>>o&s),c=Number(r&s),a=n?4:0,l=n?0:4;t.setUint32(e+a,i,n),t.setUint32(e+l,c,n)}r.wrapCipher=(t,e)=>(Object.assign(e,t),e),r.setBigUint64=h,r.u64Lengths=function(t,e){const n=new Uint8Array(16),o=(0,r.createView)(n);return h(o,0,BigInt(e?e.length:0),!0),h(o,8,BigInt(t.length),!0),n}},{"./_assert.js":5}],12:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.gcm=r.ctr=r.cbc=r.utils=r.managedNonce=r.getWebcryptoSubtle=r.randomBytes=void 0;const n=t("@noble/ciphers/crypto");Object.defineProperty(r,"randomBytes",{enumerable:!0,get:function(){return n.randomBytes}}),Object.defineProperty(r,"getWebcryptoSubtle",{enumerable:!0,get:function(){return n.getWebcryptoSubtle}});const o=t("./utils.js"),s=t("./_assert.js");r.managedNonce=function(t){return(0,s.number)(t.nonceLength),(e,...r)=>({encrypt:(s,...i)=>{const{nonceLength:c}=t,a=(0,n.randomBytes)(c),l=t(e,a,...r).encrypt(s,...i),u=(0,o.concatBytes)(a,l);return l.fill(0),u},decrypt:(n,...o)=>{const{nonceLength:s}=t,i=n.subarray(0,s),c=n.subarray(s);return t(e,i,...r).decrypt(c,...o)}})},r.utils={async encrypt(t,e,r,o){const s=(0,n.getWebcryptoSubtle)(),i=await s.importKey("raw",t,e,!0,["encrypt"]),c=await s.encrypt(r,i,o);return new Uint8Array(c)},async decrypt(t,e,r,o){const s=(0,n.getWebcryptoSubtle)(),i=await s.importKey("raw",t,e,!0,["decrypt"]),c=await s.decrypt(r,i,o);return new Uint8Array(c)}};const i={CBC:"AES-CBC",CTR:"AES-CTR",GCM:"AES-GCM"};function c(t){return(e,n,o)=>{(0,s.bytes)(e),(0,s.bytes)(n);const c={name:t,length:8*e.length},a=function(t,e,r){if(t===i.CBC)return{name:i.CBC,iv:e};if(t===i.CTR)return{name:i.CTR,counter:e,length:64};if(t===i.GCM)return r?{name:i.GCM,iv:e,additionalData:r}:{name:i.GCM,iv:e};throw new Error("unknown aes block mode")}(t,n,o);return{encrypt:t=>((0,s.bytes)(t),r.utils.encrypt(e,c,a,t)),decrypt:t=>((0,s.bytes)(t),r.utils.decrypt(e,c,a,t))}}}r.cbc=c(i.CBC),r.ctr=c(i.CTR),r.gcm=c(i.GCM)},{"./_assert.js":5,"./utils.js":11,"@noble/ciphers/crypto":9}],13:[function(t,e,r){"use strict";function n(t){if(!Number.isSafeInteger(t)||t<0)throw new Error(`positive integer expected, not ${t}`)}function o(t){if("boolean"!=typeof t)throw new Error(`boolean expected, not ${t}`)}function s(t){return t instanceof Uint8Array||null!=t&&"object"==typeof t&&"Uint8Array"===t.constructor.name}function i(t,...e){if(!s(t))throw new Error("Uint8Array expected");if(e.length>0&&!e.includes(t.length))throw new Error(`Uint8Array expected of length ${e}, not of length=${t.length}`)}function c(t){if("function"!=typeof t||"function"!=typeof t.create)throw new Error("Hash should be wrapped by utils.wrapConstructor");n(t.outputLen),n(t.blockLen)}function a(t,e=!0){if(t.destroyed)throw new Error("Hash instance has been destroyed");if(e&&t.finished)throw new Error("Hash#digest() has already been called")}function l(t,e){i(t);const r=e.outputLen;if(t.length<r)throw new Error(`digestInto() expects output buffer of length at least ${r}`)}Object.defineProperty(r,"__esModule",{value:!0}),r.output=r.exists=r.hash=r.bytes=r.bool=r.number=r.isBytes=void 0,r.number=n,r.bool=o,r.isBytes=s,r.bytes=i,r.hash=c,r.exists=a,r.output=l;const u={number:n,bool:o,bytes:i,hash:c,exists:a,output:l};r.default=u},{}],14:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.HashMD=r.Maj=r.Chi=void 0;const n=t("./_assert.js"),o=t("./utils.js");r.Chi=(t,e,r)=>t&e^~t&r;r.Maj=(t,e,r)=>t&e^t&r^e&r;class s extends o.Hash{constructor(t,e,r,n){super(),this.blockLen=t,this.outputLen=e,this.padOffset=r,this.isLE=n,this.finished=!1,this.length=0,this.pos=0,this.destroyed=!1,this.buffer=new Uint8Array(t),this.view=(0,o.createView)(this.buffer)}update(t){(0,n.exists)(this);const{view:e,buffer:r,blockLen:s}=this,i=(t=(0,o.toBytes)(t)).length;for(let n=0;n<i;){const c=Math.min(s-this.pos,i-n);if(c!==s)r.set(t.subarray(n,n+c),this.pos),this.pos+=c,n+=c,this.pos===s&&(this.process(e,0),this.pos=0);else{const e=(0,o.createView)(t);for(;s<=i-n;n+=s)this.process(e,n)}}return this.length+=t.length,this.roundClean(),this}digestInto(t){(0,n.exists)(this),(0,n.output)(t,this),this.finished=!0;const{buffer:e,view:r,blockLen:s,isLE:i}=this;let{pos:c}=this;e[c++]=128,this.buffer.subarray(c).fill(0),this.padOffset>s-c&&(this.process(r,0),c=0);for(let t=c;t<s;t++)e[t]=0;!function(t,e,r,n){if("function"==typeof t.setBigUint64)return t.setBigUint64(e,r,n);const o=BigInt(32),s=BigInt(4294967295),i=Number(r>>o&s),c=Number(r&s),a=n?4:0,l=n?0:4;t.setUint32(e+a,i,n),t.setUint32(e+l,c,n)}(r,s-8,BigInt(8*this.length),i),this.process(r,0);const a=(0,o.createView)(t),l=this.outputLen;if(l%4)throw new Error("_sha2: outputLen should be aligned to 32bit");const u=l/4,h=this.get();if(u>h.length)throw new Error("_sha2: outputLen bigger than state");for(let t=0;t<u;t++)a.setUint32(4*t,h[t],i)}digest(){const{buffer:t,outputLen:e}=this;this.digestInto(t);const r=t.slice(0,e);return this.destroy(),r}_cloneInto(t){t||(t=new this.constructor),t.set(...this.get());const{blockLen:e,buffer:r,length:n,finished:o,destroyed:s,pos:i}=this;return t.length=n,t.pos=i,t.finished=o,t.destroyed=s,n%e&&t.buffer.set(r),t}}r.HashMD=s},{"./_assert.js":13,"./utils.js":20}],15:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.crypto=void 0,r.crypto="object"==typeof globalThis&&"crypto"in globalThis?globalThis.crypto:void 0},{}],16:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.hmac=r.HMAC=void 0;const n=t("./_assert.js"),o=t("./utils.js");class s extends o.Hash{constructor(t,e){super(),this.finished=!1,this.destroyed=!1,(0,n.hash)(t);const r=(0,o.toBytes)(e);if(this.iHash=t.create(),"function"!=typeof this.iHash.update)throw new Error("Expected instance of class which extends utils.Hash");this.blockLen=this.iHash.blockLen,this.outputLen=this.iHash.outputLen;const s=this.blockLen,i=new Uint8Array(s);i.set(r.length>s?t.create().update(r).digest():r);for(let t=0;t<i.length;t++)i[t]^=54;this.iHash.update(i),this.oHash=t.create();for(let t=0;t<i.length;t++)i[t]^=106;this.oHash.update(i),i.fill(0)}update(t){return(0,n.exists)(this),this.iHash.update(t),this}digestInto(t){(0,n.exists)(this),(0,n.bytes)(t,this.outputLen),this.finished=!0,this.iHash.digestInto(t),this.oHash.update(t),this.oHash.digestInto(t),this.destroy()}digest(){const t=new Uint8Array(this.oHash.outputLen);return this.digestInto(t),t}_cloneInto(t){t||(t=Object.create(Object.getPrototypeOf(this),{}));const{oHash:e,iHash:r,finished:n,destroyed:o,blockLen:s,outputLen:i}=this;return t.finished=n,t.destroyed=o,t.blockLen=s,t.outputLen=i,t.oHash=e._cloneInto(t.oHash),t.iHash=r._cloneInto(t.iHash),t}destroy(){this.destroyed=!0,this.oHash.destroy(),this.iHash.destroy()}}r.HMAC=s;r.hmac=(t,e,r)=>new s(t,e).update(r).digest(),r.hmac.create=(t,e)=>new s(t,e)},{"./_assert.js":13,"./utils.js":20}],17:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.pbkdf2Async=r.pbkdf2=void 0;const n=t("./_assert.js"),o=t("./hmac.js"),s=t("./utils.js");function i(t,e,r,i){(0,n.hash)(t);const c=(0,s.checkOpts)({dkLen:32,asyncTick:10},i),{c:a,dkLen:l,asyncTick:u}=c;if((0,n.number)(a),(0,n.number)(l),(0,n.number)(u),a<1)throw new Error("PBKDF2: iterations (c) should be >= 1");const h=(0,s.toBytes)(e),f=(0,s.toBytes)(r),y=new Uint8Array(l),d=o.hmac.create(t,h),p=d._cloneInto().update(f);return{c:a,dkLen:l,asyncTick:u,DK:y,PRF:d,PRFSalt:p}}function c(t,e,r,n,o){return t.destroy(),e.destroy(),n&&n.destroy(),o.fill(0),r}r.pbkdf2=function(t,e,r,n){const{c:o,dkLen:a,DK:l,PRF:u,PRFSalt:h}=i(t,e,r,n);let f;const y=new Uint8Array(4),d=(0,s.createView)(y),p=new Uint8Array(u.outputLen);for(let t=1,e=0;e<a;t++,e+=u.outputLen){const r=l.subarray(e,e+u.outputLen);d.setInt32(0,t,!1),(f=h._cloneInto(f)).update(y).digestInto(p),r.set(p.subarray(0,r.length));for(let t=1;t<o;t++){u._cloneInto(f).update(p).digestInto(p);for(let t=0;t<r.length;t++)r[t]^=p[t]}}return c(u,h,l,f,p)},r.pbkdf2Async=async function(t,e,r,n){const{c:o,dkLen:a,asyncTick:l,DK:u,PRF:h,PRFSalt:f}=i(t,e,r,n);let y;const d=new Uint8Array(4),p=(0,s.createView)(d),g=new Uint8Array(h.outputLen);for(let t=1,e=0;e<a;t++,e+=h.outputLen){const r=u.subarray(e,e+h.outputLen);p.setInt32(0,t,!1),(y=f._cloneInto(y)).update(d).digestInto(g),r.set(g.subarray(0,r.length)),await(0,s.asyncLoop)(o-1,l,(()=>{h._cloneInto(y).update(g).digestInto(g);for(let t=0;t<r.length;t++)r[t]^=g[t]}))}return c(h,f,u,y,g)}},{"./_assert.js":13,"./hmac.js":16,"./utils.js":20}],18:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.scryptAsync=r.scrypt=void 0;const n=t("./_assert.js"),o=t("./sha256.js"),s=t("./pbkdf2.js"),i=t("./utils.js");function c(t,e,r,n,o,s){let c=t[e++]^r[n++],a=t[e++]^r[n++],l=t[e++]^r[n++],u=t[e++]^r[n++],h=t[e++]^r[n++],f=t[e++]^r[n++],y=t[e++]^r[n++],d=t[e++]^r[n++],p=t[e++]^r[n++],g=t[e++]^r[n++],b=t[e++]^r[n++],w=t[e++]^r[n++],m=t[e++]^r[n++],E=t[e++]^r[n++],v=t[e++]^r[n++],x=t[e++]^r[n++],A=c,B=a,U=l,k=u,L=h,_=f,j=y,C=d,S=p,T=g,O=b,M=w,I=m,K=E,N=v,H=x;for(let t=0;t<8;t+=2)L^=(0,i.rotl)(A+I|0,7),S^=(0,i.rotl)(L+A|0,9),I^=(0,i.rotl)(S+L|0,13),A^=(0,i.rotl)(I+S|0,18),T^=(0,i.rotl)(_+B|0,7),K^=(0,i.rotl)(T+_|0,9),B^=(0,i.rotl)(K+T|0,13),_^=(0,i.rotl)(B+K|0,18),N^=(0,i.rotl)(O+j|0,7),U^=(0,i.rotl)(N+O|0,9),j^=(0,i.rotl)(U+N|0,13),O^=(0,i.rotl)(j+U|0,18),k^=(0,i.rotl)(H+M|0,7),C^=(0,i.rotl)(k+H|0,9),M^=(0,i.rotl)(C+k|0,13),H^=(0,i.rotl)(M+C|0,18),B^=(0,i.rotl)(A+k|0,7),U^=(0,i.rotl)(B+A|0,9),k^=(0,i.rotl)(U+B|0,13),A^=(0,i.rotl)(k+U|0,18),j^=(0,i.rotl)(_+L|0,7),C^=(0,i.rotl)(j+_|0,9),L^=(0,i.rotl)(C+j|0,13),_^=(0,i.rotl)(L+C|0,18),M^=(0,i.rotl)(O+T|0,7),S^=(0,i.rotl)(M+O|0,9),T^=(0,i.rotl)(S+M|0,13),O^=(0,i.rotl)(T+S|0,18),I^=(0,i.rotl)(H+N|0,7),K^=(0,i.rotl)(I+H|0,9),N^=(0,i.rotl)(K+I|0,13),H^=(0,i.rotl)(N+K|0,18);o[s++]=c+A|0,o[s++]=a+B|0,o[s++]=l+U|0,o[s++]=u+k|0,o[s++]=h+L|0,o[s++]=f+_|0,o[s++]=y+j|0,o[s++]=d+C|0,o[s++]=p+S|0,o[s++]=g+T|0,o[s++]=b+O|0,o[s++]=w+M|0,o[s++]=m+I|0,o[s++]=E+K|0,o[s++]=v+N|0,o[s++]=x+H|0}function a(t,e,r,n,o){let s=n+0,i=n+16*o;for(let n=0;n<16;n++)r[i+n]=t[e+16*(2*o-1)+n];for(let n=0;n<o;n++,s+=16,e+=16)c(r,i,t,e,r,s),n>0&&(i+=16),c(r,s,t,e+=16,r,i)}function l(t,e,r){const c=(0,i.checkOpts)({dkLen:32,asyncTick:10,maxmem:1073742848},r),{N:a,r:l,p:u,dkLen:h,asyncTick:f,maxmem:y,onProgress:d}=c;if((0,n.number)(a),(0,n.number)(l),(0,n.number)(u),(0,n.number)(h),(0,n.number)(f),(0,n.number)(y),void 0!==d&&"function"!=typeof d)throw new Error("progressCb should be function");const p=128*l,g=p/4;if(a<=1||0!=(a&a-1)||a>=2**(p/8)||a>2**32)throw new Error("Scrypt: N must be larger than 1, a power of 2, less than 2^(128 * r / 8) and less than 2^32");if(u<0||u>137438953440/p)throw new Error("Scrypt: p must be a positive integer less than or equal to ((2^32 - 1) * 32) / (128 * r)");if(h<0||h>137438953440)throw new Error("Scrypt: dkLen should be positive integer less than or equal to (2^32 - 1) * 32");const b=p*(a+u);if(b>y)throw new Error(`Scrypt: parameters too large, ${b} (128 * r * (N + p)) > ${y} (maxmem)`);const w=(0,s.pbkdf2)(o.sha256,t,e,{c:1,dkLen:p*u}),m=(0,i.u32)(w),E=(0,i.u32)(new Uint8Array(p*a)),v=(0,i.u32)(new Uint8Array(p));let x=()=>{};if(d){const t=2*a*u,e=Math.max(Math.floor(t/1e4),1);let r=0;x=()=>{r++,!d||r%e&&r!==t||d(r/t)}}return{N:a,r:l,p:u,dkLen:h,blockSize32:g,V:E,B32:m,B:w,tmp:v,blockMixCb:x,asyncTick:f}}function u(t,e,r,n,i){const c=(0,s.pbkdf2)(o.sha256,t,r,{c:1,dkLen:e});return r.fill(0),n.fill(0),i.fill(0),c}r.scrypt=function(t,e,r){const{N:n,r:o,p:s,dkLen:c,blockSize32:h,V:f,B32:y,B:d,tmp:p,blockMixCb:g}=l(t,e,r);i.isLE||(0,i.byteSwap32)(y);for(let t=0;t<s;t++){const e=h*t;for(let t=0;t<h;t++)f[t]=y[e+t];for(let t=0,e=0;t<n-1;t++)a(f,e,f,e+=h,o),g();a(f,(n-1)*h,y,e,o),g();for(let t=0;t<n;t++){const t=y[e+h-16]%n;for(let r=0;r<h;r++)p[r]=y[e+r]^f[t*h+r];a(p,0,y,e,o),g()}}return i.isLE||(0,i.byteSwap32)(y),u(t,c,d,f,p)},r.scryptAsync=async function(t,e,r){const{N:n,r:o,p:s,dkLen:c,blockSize32:h,V:f,B32:y,B:d,tmp:p,blockMixCb:g,asyncTick:b}=l(t,e,r);i.isLE||(0,i.byteSwap32)(y);for(let t=0;t<s;t++){const e=h*t;for(let t=0;t<h;t++)f[t]=y[e+t];let r=0;await(0,i.asyncLoop)(n-1,b,(()=>{a(f,r,f,r+=h,o),g()})),a(f,(n-1)*h,y,e,o),g(),await(0,i.asyncLoop)(n,b,(()=>{const t=y[e+h-16]%n;for(let r=0;r<h;r++)p[r]=y[e+r]^f[t*h+r];a(p,0,y,e,o),g()}))}return i.isLE||(0,i.byteSwap32)(y),u(t,c,d,f,p)}},{"./_assert.js":13,"./pbkdf2.js":17,"./sha256.js":19,"./utils.js":20}],19:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.sha224=r.sha256=void 0;const n=t("./_md.js"),o=t("./utils.js"),s=new Uint32Array([1116352408,1899447441,3049323471,3921009573,961987163,1508970993,2453635748,2870763221,3624381080,310598401,607225278,1426881987,1925078388,2162078206,2614888103,3248222580,3835390401,4022224774,264347078,604807628,770255983,1249150122,1555081692,1996064986,2554220882,2821834349,2952996808,3210313671,3336571891,3584528711,113926993,338241895,666307205,773529912,1294757372,1396182291,1695183700,1986661051,2177026350,2456956037,2730485921,2820302411,3259730800,3345764771,3516065817,3600352804,4094571909,275423344,430227734,506948616,659060556,883997877,958139571,1322822218,1537002063,1747873779,1955562222,2024104815,2227730452,2361852424,2428436474,2756734187,3204031479,3329325298]),i=new Uint32Array([1779033703,3144134277,1013904242,2773480762,1359893119,2600822924,528734635,1541459225]),c=new Uint32Array(64);class a extends n.HashMD{constructor(){super(64,32,8,!1),this.A=0|i[0],this.B=0|i[1],this.C=0|i[2],this.D=0|i[3],this.E=0|i[4],this.F=0|i[5],this.G=0|i[6],this.H=0|i[7]}get(){const{A:t,B:e,C:r,D:n,E:o,F:s,G:i,H:c}=this;return[t,e,r,n,o,s,i,c]}set(t,e,r,n,o,s,i,c){this.A=0|t,this.B=0|e,this.C=0|r,this.D=0|n,this.E=0|o,this.F=0|s,this.G=0|i,this.H=0|c}process(t,e){for(let r=0;r<16;r++,e+=4)c[r]=t.getUint32(e,!1);for(let t=16;t<64;t++){const e=c[t-15],r=c[t-2],n=(0,o.rotr)(e,7)^(0,o.rotr)(e,18)^e>>>3,s=(0,o.rotr)(r,17)^(0,o.rotr)(r,19)^r>>>10;c[t]=s+c[t-7]+n+c[t-16]|0}let{A:r,B:i,C:a,D:l,E:u,F:h,G:f,H:y}=this;for(let t=0;t<64;t++){const e=y+((0,o.rotr)(u,6)^(0,o.rotr)(u,11)^(0,o.rotr)(u,25))+(0,n.Chi)(u,h,f)+s[t]+c[t]|0,d=((0,o.rotr)(r,2)^(0,o.rotr)(r,13)^(0,o.rotr)(r,22))+(0,n.Maj)(r,i,a)|0;y=f,f=h,h=u,u=l+e|0,l=a,a=i,i=r,r=e+d|0}r=r+this.A|0,i=i+this.B|0,a=a+this.C|0,l=l+this.D|0,u=u+this.E|0,h=h+this.F|0,f=f+this.G|0,y=y+this.H|0,this.set(r,i,a,l,u,h,f,y)}roundClean(){c.fill(0)}destroy(){this.set(0,0,0,0,0,0,0,0),this.buffer.fill(0)}}class l extends a{constructor(){super(),this.A=-1056596264,this.B=914150663,this.C=812702999,this.D=-150054599,this.E=-4191439,this.F=1750603025,this.G=1694076839,this.H=-1090891868,this.outputLen=28}}r.sha256=(0,o.wrapConstructor)((()=>new a)),r.sha224=(0,o.wrapConstructor)((()=>new l))},{"./_md.js":14,"./utils.js":20}],20:[function(t,e,r){"use strict";
       /*! noble-hashes - MIT License (c) 2022 Paul Miller (paulmillr.com) */Object.defineProperty(r,"__esModule",{value:!0}),r.randomBytes=r.wrapXOFConstructorWithOpts=r.wrapConstructorWithOpts=r.wrapConstructor=r.checkOpts=r.Hash=r.concatBytes=r.toBytes=r.utf8ToBytes=r.asyncLoop=r.nextTick=r.hexToBytes=r.bytesToHex=r.byteSwap32=r.byteSwapIfBE=r.byteSwap=r.isLE=r.rotl=r.rotr=r.createView=r.u32=r.u8=r.isBytes=void 0;const n=t("@noble/hashes/crypto"),o=t("./_assert.js");r.isBytes=function(t){return t instanceof Uint8Array||null!=t&&"object"==typeof t&&"Uint8Array"===t.constructor.name};r.u8=t=>new Uint8Array(t.buffer,t.byteOffset,t.byteLength);r.u32=t=>new Uint32Array(t.buffer,t.byteOffset,Math.floor(t.byteLength/4));r.createView=t=>new DataView(t.buffer,t.byteOffset,t.byteLength);r.rotr=(t,e)=>t<<32-e|t>>>e;r.rotl=(t,e)=>t<<e|t>>>32-e>>>0,r.isLE=68===new Uint8Array(new Uint32Array([287454020]).buffer)[0];r.byteSwap=t=>t<<24&4278190080|t<<8&16711680|t>>>8&65280|t>>>24&255,r.byteSwapIfBE=r.isLE?t=>t:t=>(0,r.byteSwap)(t),r.byteSwap32=function(t){for(let e=0;e<t.length;e++)t[e]=(0,r.byteSwap)(t[e])};const s=Array.from({length:256},((t,e)=>e.toString(16).padStart(2,"0")));r.bytesToHex=function(t){(0,o.bytes)(t);let e="";for(let r=0;r<t.length;r++)e+=s[t[r]];return e};const i={_0:48,_9:57,_A:65,_F:70,_a:97,_f:102};function c(t){return t>=i._0&&t<=i._9?t-i._0:t>=i._A&&t<=i._F?t-(i._A-10):t>=i._a&&t<=i._f?t-(i._a-10):void 0}r.hexToBytes=function(t){if("string"!=typeof t)throw new Error("hex string expected, got "+typeof t);const e=t.length,r=e/2;if(e%2)throw new Error("padded hex string expected, got unpadded hex of length "+e);const n=new Uint8Array(r);for(let e=0,o=0;e<r;e++,o+=2){const r=c(t.charCodeAt(o)),s=c(t.charCodeAt(o+1));if(void 0===r||void 0===s){const e=t[o]+t[o+1];throw new Error('hex string expected, got non-hex character "'+e+'" at index '+o)}n[e]=16*r+s}return n};function a(t){if("string"!=typeof t)throw new Error("utf8ToBytes expected string, got "+typeof t);return new Uint8Array((new TextEncoder).encode(t))}function l(t){return"string"==typeof t&&(t=a(t)),(0,o.bytes)(t),t}r.nextTick=async()=>{},r.asyncLoop=async function(t,e,n){let o=Date.now();for(let s=0;s<t;s++){n(s);const t=Date.now()-o;t>=0&&t<e||(await(0,r.nextTick)(),o+=t)}},r.utf8ToBytes=a,r.toBytes=l,r.concatBytes=function(...t){let e=0;for(let r=0;r<t.length;r++){const n=t[r];(0,o.bytes)(n),e+=n.length}const r=new Uint8Array(e);for(let e=0,n=0;e<t.length;e++){const o=t[e];r.set(o,n),n+=o.length}return r};r.Hash=class{clone(){return this._cloneInto()}};const u={}.toString;r.checkOpts=function(t,e){if(void 0!==e&&"[object Object]"!==u.call(e))throw new Error("Options should be object or undefined");return Object.assign(t,e)},r.wrapConstructor=function(t){const e=e=>t().update(l(e)).digest(),r=t();return e.outputLen=r.outputLen,e.blockLen=r.blockLen,e.create=()=>t(),e},r.wrapConstructorWithOpts=function(t){const e=(e,r)=>t(r).update(l(e)).digest(),r=t({});return e.outputLen=r.outputLen,e.blockLen=r.blockLen,e.create=e=>t(e),e},r.wrapXOFConstructorWithOpts=function(t){const e=(e,r)=>t(r).update(l(e)).digest(),r=t({});return e.outputLen=r.outputLen,e.blockLen=r.blockLen,e.create=e=>t(e),e},r.randomBytes=function(t=32){if(n.crypto&&"function"==typeof n.crypto.getRandomValues)return n.crypto.getRandomValues(new Uint8Array(t));throw new Error("crypto.getRandomValues must be defined")}},{"./_assert.js":13,"@noble/hashes/crypto":15}],21:[function(t,e,r){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.encode=r.decode=void 0;const n={},o={};["ҠҿԀԟڀڿݠޟ߀ߟကဟႠႿᄀᅟᆀᆟᇠሿበቿዠዿጠጿᎠᏟᐠᙟᚠᛟកសᠠᡟᣀᣟᦀᦟ᧠᧿ᨠᨿᯀᯟᰀᰟᴀᴟ⇠⇿⋀⋟⍀⏟␀␟─❟➀➿⠀⥿⦠⦿⨠⩟⪀⪿⫠⭟ⰀⰟⲀⳟⴀⴟⵀⵟ⺠⻟㇀㇟㐀䶟䷀龿ꀀꑿ꒠꒿ꔀꗿꙀꙟꚠꛟ꜀ꝟꞀꞟꡀꡟ","ƀƟɀʟ"].forEach(((t,e)=>{const r=[];t.match(/../gu).forEach((t=>{const e=t.codePointAt(0),n=t.codePointAt(1);for(let t=e;t<=n;t++)r.push(String.fromCodePoint(t))}));const s=15-8*e;n[s]=r,r.forEach(((t,e)=>{o[t]=[s,e]}))}));r.encode=t=>{const e=t.length;let r="",o=0,s=0;for(let i=0;i<e;i++){const e=t[i];for(let t=7;t>=0;t--){o=(o<<1)+(e>>t&1),s++,15===s&&(r+=n[s][o],o=0,s=0)}}if(0!==s){for(;!(s in n);)o=1+(o<<1),s++;r+=n[s][o]}return r};r.decode=t=>{const e=t.length,r=new Uint8Array(Math.floor(15*e/8));let n=0,s=0,i=0;for(let c=0;c<e;c++){const a=t.charAt(c);if(!(a in o))throw new Error(`Unrecognised Base32768 character: ${a}`);const[l,u]=o[a];if(15!==l&&c!==e-1)throw new Error("Secondary character found before end of input at position "+String(c));for(let t=l-1;t>=0;t--){s=(s<<1)+(u>>t&1),i++,8===i&&(r[n]=s,n++,s=0,i=0)}}if(s!==(1<<i)-1)throw new Error("Padding mismatch");return new Uint8Array(r.buffer,0,n)}},{}],22:[function(t,e,r){e.exports={pad:function(t,e){var r=t;if("number"!=typeof e)e=16;else{if(e>255)throw new RangeError("pad(): PKCS#7 padding cannot be longer than 255 bytes");if(e<0)throw new RangeError("pad(): PKCS#7 padding size must be positive")}if("string"==typeof t){var n=e-t.length%e;isNaN(n)&&(n=0);for(var o=String.fromCharCode(n),s=0;s<n;s++)r+=o}else{if(!(t instanceof Uint8Array||t instanceof Uint8ClampedArray))throw new TypeError("pad(): data could not be padded");var i=t.byteLength;n=e-i%e,isNaN(n)&&(n=0);var c=i+n;for((r=new t.constructor(c)).set(t),s=i;s<c;s++)r[s]=n}return r},unpad:function(t){var e=t;if("string"==typeof t&&t.length>0){var r=t.charCodeAt(t.length-1);if(r>t.length)throw new Error("unpad(): cannot remove "+r+" bytes from a "+t.length+"-byte(s) string");for(var n=t.length-2,o=t.length-r;n>=o;n--)if(t.charCodeAt(n)!==r)throw new Error("unpad(): found a padding byte of "+t.charCodeAt(n)+" instead of "+r+" at position "+n);e=t.substring(0,o)}else if(t instanceof Uint8Array||t instanceof Uint8ClampedArray){var s=t.byteLength,i=s-(r=t[s-1]);if(i<0)throw new Error("unpad(): cannot remove "+r+" bytes from a "+s+"-byte(s) string");for(n=s-2;n>=i;n--)if(t[n]!==r)throw new Error("unpad(): found a padding byte of "+t[n]+" instead of "+r+" at position "+n);e=t.slice(0,i)}return e}}},{}],23:[function(t,e,r){"use strict";function n(t,e,r){var n;if(void 0===r&&(r={}),!e.codes){e.codes={};for(var o=0;o<e.chars.length;++o)e.codes[e.chars[o]]=o}if(!r.loose&&t.length*e.bits&7)throw new SyntaxError("Invalid padding");for(var s=t.length;"="===t[s-1];)if(--s,!(r.loose||(t.length-s)*e.bits&7))throw new SyntaxError("Invalid padding");for(var i=new(null!=(n=r.out)?n:Uint8Array)(s*e.bits/8|0),c=0,a=0,l=0,u=0;u<s;++u){var h=e.codes[t[u]];if(void 0===h)throw new SyntaxError("Invalid character "+t[u]);a=a<<e.bits|h,(c+=e.bits)>=8&&(c-=8,i[l++]=255&a>>c)}if(c>=e.bits||255&a<<8-c)throw new SyntaxError("Unexpected end of data");return i}function o(t,e,r){void 0===r&&(r={});for(var n=r.pad,o=void 0===n||n,s=(1<<e.bits)-1,i="",c=0,a=0,l=0;l<t.length;++l)for(a=a<<8|255&t[l],c+=8;c>e.bits;)c-=e.bits,i+=e.chars[s&a>>c];if(c&&(i+=e.chars[s&a<<e.bits-c]),o)for(;i.length*e.bits&7;)i+="=";return i}Object.defineProperty(r,"__esModule",{value:!0}),r.codec=r.base64url=r.base64=r.base32hex=r.base32=r.base16=void 0;var s={chars:"0123456789ABCDEF",bits:4},i={chars:"ABCDEFGHIJKLMNOPQRSTUVWXYZ234567",bits:5},c={chars:"0123456789ABCDEFGHIJKLMNOPQRSTUV",bits:5},a={chars:"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/",bits:6},l={chars:"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_",bits:6};r.base16={parse:function(t,e){return n(t.toUpperCase(),s,e)},stringify:function(t,e){return o(t,s,e)}},r.base32={parse:function(t,e){return void 0===e&&(e={}),n(e.loose?t.toUpperCase().replace(/0/g,"O").replace(/1/g,"L").replace(/8/g,"B"):t,i,e)},stringify:function(t,e){return o(t,i,e)}},r.base32hex={parse:function(t,e){return n(t,c,e)},stringify:function(t,e){return o(t,c,e)}},r.base64={parse:function(t,e){return n(t,a,e)},stringify:function(t,e){return o(t,a,e)}},r.base64url={parse:function(t,e){return n(t,l,e)},stringify:function(t,e){return o(t,l,e)}},r.codec={parse:n,stringify:o}},{}]},{},[1]);

      // GIF Duration Utility
      !function(t){var n={};function i(e){if(n[e])return n[e].exports;var r=n[e]={i:e,l:!1,exports:{}};return t[e].call(r.exports,r,r.exports,i),r.l=!0,r.exports}i.m=t,i.c=n,i.d=function(e,r,t){i.o(e,r)||Object.defineProperty(e,r,{enumerable:!0,get:t})},i.r=function(e){"undefined"!=typeof Symbol&&Symbol.toStringTag&&Object.defineProperty(e,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(e,"__esModule",{value:!0})},i.t=function(r,e){if(1&e&&(r=i(r)),8&e)return r;if(4&e&&"object"==typeof r&&r&&r.__esModule)return r;var t=Object.create(null);if(i.r(t),Object.defineProperty(t,"default",{enumerable:!0,value:r}),2&e&&"string"!=typeof r)for(var n in r)i.d(t,n,function(e){return r[e]}.bind(null,n));return t},i.n=function(e){var r=e&&e.__esModule?function(){return e.default}:function(){return e};return i.d(r,"a",r),r},i.o=function(e,r){return Object.prototype.hasOwnProperty.call(e,r)},i.p="",i(i.s=0)}([function(e,r,t){"use strict";t.r(r);var n=t(1);window.getGifDuration=async function(e){e=await(await fetch(e)).arrayBuffer(),e=Object(n.parseGIF)(e);return Object(n.decompressFrames)(e,!0).map(e=>e.delay).reduce((e,r)=>e+r,0)}},function(e,r,t){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.decompressFrames=r.decompressFrame=r.parseGIF=void 0;var n,i=(n=t(2))&&n.__esModule?n:{default:n},o=t(3),a=t(4),d=t(5),u=t(6);r.parseGIF=function(e){e=new Uint8Array(e);return(0,o.parse)((0,a.buildStream)(e),i.default)};function c(e,r,t){if(e.image){var n=e.image,i=n.descriptor.width*n.descriptor.height,i=(0,u.lzw)(n.data.minCodeSize,n.data.blocks,i);n.descriptor.lct.interlaced&&(i=(0,d.deinterlace)(i,n.descriptor.width));i={pixels:i,dims:{top:e.image.descriptor.top,left:e.image.descriptor.left,width:e.image.descriptor.width,height:e.image.descriptor.height}};return n.descriptor.lct&&n.descriptor.lct.exists?i.colorTable=n.lct:i.colorTable=r,e.gce&&(i.delay=10*(e.gce.delay||10),i.disposalType=e.gce.extras.disposal,e.gce.extras.transparentColorGiven&&(i.transparentIndex=e.gce.transparentColorIndex)),t&&(i.patch=function(e){for(var r=e.pixels.length,t=new Uint8ClampedArray(4*r),n=0;n<r;n++){var i=4*n,o=e.pixels[n],a=e.colorTable[o]||[0,0,0];t[i]=a[0],t[1+i]=a[1],t[2+i]=a[2],t[3+i]=o!==e.transparentIndex?255:0}return t}(i)),i}console.warn("gif frame does not have associated image.")}r.decompressFrame=c;r.decompressFrames=function(r,t){return r.frames.filter(function(e){return e.image}).map(function(e){return c(e,r.gct,t)})}},function(e,r,t){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.default=void 0;var n=t(3),c=t(4),i={blocks:function(e){for(var r=[],t=e.data.length,n=0,i=(0,c.readByte)()(e);0!==i&&i;i=(0,c.readByte)()(e)){if(e.pos+i>=t){var o=t-e.pos;r.push((0,c.readBytes)(o)(e)),n+=o;break}r.push((0,c.readBytes)(i)(e)),n+=i}for(var a=new Uint8Array(n),d=0,u=0;u<r.length;u++)a.set(r[u],d),d+=r[u].length;return a}},o=(0,n.conditional)({gce:[{codes:(0,c.readBytes)(2)},{byteSize:(0,c.readByte)()},{extras:(0,c.readBits)({future:{index:0,length:3},disposal:{index:3,length:3},userInput:{index:6},transparentColorGiven:{index:7}})},{delay:(0,c.readUnsigned)(!0)},{transparentColorIndex:(0,c.readByte)()},{terminator:(0,c.readByte)()}]},function(e){e=(0,c.peekBytes)(2)(e);return 33===e[0]&&249===e[1]}),a=(0,n.conditional)({image:[{code:(0,c.readByte)()},{descriptor:[{left:(0,c.readUnsigned)(!0)},{top:(0,c.readUnsigned)(!0)},{width:(0,c.readUnsigned)(!0)},{height:(0,c.readUnsigned)(!0)},{lct:(0,c.readBits)({exists:{index:0},interlaced:{index:1},sort:{index:2},future:{index:3,length:2},size:{index:5,length:3}})}]},(0,n.conditional)({lct:(0,c.readArray)(3,function(e,r,t){return Math.pow(2,t.descriptor.lct.size+1)})},function(e,r,t){return t.descriptor.lct.exists}),{data:[{minCodeSize:(0,c.readByte)()},i]}]},function(e){return 44===(0,c.peekByte)()(e)}),d=(0,n.conditional)({text:[{codes:(0,c.readBytes)(2)},{blockSize:(0,c.readByte)()},{preData:function(e,r,t){return(0,c.readBytes)(t.text.blockSize)(e)}},i]},function(e){e=(0,c.peekBytes)(2)(e);return 33===e[0]&&1===e[1]}),t=(0,n.conditional)({application:[{codes:(0,c.readBytes)(2)},{blockSize:(0,c.readByte)()},{id:function(e,r,t){return(0,c.readString)(t.blockSize)(e)}},i]},function(e){e=(0,c.peekBytes)(2)(e);return 33===e[0]&&255===e[1]}),i=(0,n.conditional)({comment:[{codes:(0,c.readBytes)(2)},i]},function(e){e=(0,c.peekBytes)(2)(e);return 33===e[0]&&254===e[1]}),d=[{header:[{signature:(0,c.readString)(3)},{version:(0,c.readString)(3)}]},{lsd:[{width:(0,c.readUnsigned)(!0)},{height:(0,c.readUnsigned)(!0)},{gct:(0,c.readBits)({exists:{index:0},resolution:{index:1,length:3},sort:{index:4},size:{index:5,length:3}})},{backgroundColorIndex:(0,c.readByte)()},{pixelAspectRatio:(0,c.readByte)()}]},(0,n.conditional)({gct:(0,c.readArray)(3,function(e,r){return Math.pow(2,r.lsd.gct.size+1)})},function(e,r){return r.lsd.gct.exists}),{frames:(0,n.loop)([o,t,i,a,d],function(e){e=(0,c.peekByte)()(e);return 33===e||44===e})}];r.default=d},function(e,r,t){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.loop=r.conditional=r.parse=void 0;function o(r,e){var t,n=2<arguments.length&&void 0!==arguments[2]?arguments[2]:{},i=3<arguments.length&&void 0!==arguments[3]?arguments[3]:n;return Array.isArray(e)?e.forEach(function(e){return o(r,e,n,i)}):"function"==typeof e?e(r,n,i,o):(t=Object.keys(e)[0],Array.isArray(e[t])?(i[t]={},o(r,e[t],n,i[t])):i[t]=e[t](r,n,i,o)),n}r.parse=o;r.conditional=function(i,o){return function(e,r,t,n){o(e,r,t)&&n(e,i,r,t)}};r.loop=function(d,u){return function(e,r,t,n){for(var i=[],o=e.pos;u(e,r,t);){var a={};if(n(e,d,r,a),e.pos===o)break;o=e.pos,i.push(a)}return i}}},function(e,r,t){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.readBits=r.readArray=r.readUnsigned=r.readString=r.peekBytes=r.readBytes=r.peekByte=r.readByte=r.buildStream=void 0;r.buildStream=function(e){return{data:e,pos:0}};function o(){return function(e){return e.data[e.pos++]}}r.readByte=o;r.peekByte=function(){var r=0<arguments.length&&void 0!==arguments[0]?arguments[0]:0;return function(e){return e.data[e.pos+r]}};function c(r){return function(e){return e.data.subarray(e.pos,e.pos+=r)}}r.readBytes=c;r.peekBytes=function(r){return function(e){return e.data.subarray(e.pos,e.pos+r)}};r.readString=function(r){return function(e){return Array.from(c(r)(e)).map(function(e){return String.fromCharCode(e)}).join("")}};r.readUnsigned=function(r){return function(e){e=c(2)(e);return r?(e[1]<<8)+e[0]:(e[0]<<8)+e[1]}};r.readArray=function(d,u){return function(e,r,t){for(var n="function"==typeof u?u(e,r,t):u,i=c(d),o=new Array(n),a=0;a<n;a++)o[a]=i(e);return o}};r.readBits=function(i){return function(e){for(var r=o()(e),n=new Array(8),t=0;t<8;t++)n[7-t]=!!(r&1<<t);return Object.keys(i).reduce(function(e,r){var t=i[r];return t.length?e[r]=function(e,r,t){for(var n=0,i=0;i<t;i++)n+=e[r+i]&&Math.pow(2,t-i-1);return n}(n,t.index,t.length):e[r]=n[t.index],e},{})}}},function(e,r,t){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.deinterlace=void 0;r.deinterlace=function(e,r){for(var t,n,i=new Array(e.length),o=e.length/r,a=[0,4,2,1],d=[8,8,4,2],u=0,c=0;c<4;c++)for(var s=a[c];s<o;s+=d[c])t=s,n=u,n=e.slice(n*r,(n+1)*r),i.splice.apply(i,[t*r,r].concat(n)),u++;return i}},function(e,r,t){"use strict";Object.defineProperty(r,"__esModule",{value:!0}),r.lzw=void 0;r.lzw=function(e,r,t){for(var n,i,o,a,d,u,c,s,f=4096,l=t,p=new Array(t),y=new Array(f),g=new Array(f),v=new Array(4097),h=e,m=1<<h,b=1+m,x=2+m,B=-1,w=h+1,k=(1<<w)-1,S=0;S<m;S++)y[S]=0,g[S]=S;for(i=o=a=d=u=c=s=0;i<l;){if(0===u){if(a<w){o+=r[s]<<a,a+=8,s++;continue}if(S=o&k,o>>=w,a-=w,x<S||S==b)break;if(S==m){k=(1<<(w=h+1))-1,x=2+m,B=-1;continue}if(-1==B){v[u++]=g[S],d=B=S;continue}for((n=S)==x&&(v[u++]=d,S=B);m<S;)v[u++]=g[S],S=y[S];d=255&g[S],v[u++]=d,x<f&&(y[x]=B,g[x]=d,0==(++x&k)&&x<f&&(w++,k+=x)),B=n}u--,p[c++]=v[u],i++}for(i=c;i<l;i++)p[i]=0;return p}}]);

      /*! jQuery v3.7.1 | (c) OpenJS Foundation and other contributors | jquery.org/license */
      !function(e,t){"use strict";"object"==typeof module&&"object"==typeof module.exports?module.exports=e.document?t(e,!0):function(e){if(!e.document)throw new Error("jQuery requires a window with a document");return t(e)}:t(e)}("undefined"!=typeof window?window:this,function(ie,e){"use strict";var oe=[],r=Object.getPrototypeOf,ae=oe.slice,g=oe.flat?function(e){return oe.flat.call(e)}:function(e){return oe.concat.apply([],e)},s=oe.push,se=oe.indexOf,n={},i=n.toString,ue=n.hasOwnProperty,o=ue.toString,a=o.call(Object),le={},v=function(e){return"function"==typeof e&&"number"!=typeof e.nodeType&&"function"!=typeof e.item},y=function(e){return null!=e&&e===e.window},C=ie.document,u={type:!0,src:!0,nonce:!0,noModule:!0};function m(e,t,n){var r,i,o=(n=n||C).createElement("script");if(o.text=e,t)for(r in u)(i=t[r]||t.getAttribute&&t.getAttribute(r))&&o.setAttribute(r,i);n.head.appendChild(o).parentNode.removeChild(o)}function x(e){return null==e?e+"":"object"==typeof e||"function"==typeof e?n[i.call(e)]||"object":typeof e}var t="3.7.1",l=/HTML$/i,ce=function(e,t){return new ce.fn.init(e,t)};function c(e){var t=!!e&&"length"in e&&e.length,n=x(e);return!v(e)&&!y(e)&&("array"===n||0===t||"number"==typeof t&&0<t&&t-1 in e)}function fe(e,t){return e.nodeName&&e.nodeName.toLowerCase()===t.toLowerCase()}ce.fn=ce.prototype={jquery:t,constructor:ce,length:0,toArray:function(){return ae.call(this)},get:function(e){return null==e?ae.call(this):e<0?this[e+this.length]:this[e]},pushStack:function(e){var t=ce.merge(this.constructor(),e);return t.prevObject=this,t},each:function(e){return ce.each(this,e)},map:function(n){return this.pushStack(ce.map(this,function(e,t){return n.call(e,t,e)}))},slice:function(){return this.pushStack(ae.apply(this,arguments))},first:function(){return this.eq(0)},last:function(){return this.eq(-1)},even:function(){return this.pushStack(ce.grep(this,function(e,t){return(t+1)%2}))},odd:function(){return this.pushStack(ce.grep(this,function(e,t){return t%2}))},eq:function(e){var t=this.length,n=+e+(e<0?t:0);return this.pushStack(0<=n&&n<t?[this[n]]:[])},end:function(){return this.prevObject||this.constructor()},push:s,sort:oe.sort,splice:oe.splice},ce.extend=ce.fn.extend=function(){var e,t,n,r,i,o,a=arguments[0]||{},s=1,u=arguments.length,l=!1;for("boolean"==typeof a&&(l=a,a=arguments[s]||{},s++),"object"==typeof a||v(a)||(a={}),s===u&&(a=this,s--);s<u;s++)if(null!=(e=arguments[s]))for(t in e)r=e[t],"__proto__"!==t&&a!==r&&(l&&r&&(ce.isPlainObject(r)||(i=Array.isArray(r)))?(n=a[t],o=i&&!Array.isArray(n)?[]:i||ce.isPlainObject(n)?n:{},i=!1,a[t]=ce.extend(l,o,r)):void 0!==r&&(a[t]=r));return a},ce.extend({expando:"jQuery"+(t+Math.random()).replace(/\D/g,""),isReady:!0,error:function(e){throw new Error(e)},noop:function(){},isPlainObject:function(e){var t,n;return!(!e||"[object Object]"!==i.call(e))&&(!(t=r(e))||"function"==typeof(n=ue.call(t,"constructor")&&t.constructor)&&o.call(n)===a)},isEmptyObject:function(e){var t;for(t in e)return!1;return!0},globalEval:function(e,t,n){m(e,{nonce:t&&t.nonce},n)},each:function(e,t){var n,r=0;if(c(e)){for(n=e.length;r<n;r++)if(!1===t.call(e[r],r,e[r]))break}else for(r in e)if(!1===t.call(e[r],r,e[r]))break;return e},text:function(e){var t,n="",r=0,i=e.nodeType;if(!i)while(t=e[r++])n+=ce.text(t);return 1===i||11===i?e.textContent:9===i?e.documentElement.textContent:3===i||4===i?e.nodeValue:n},makeArray:function(e,t){var n=t||[];return null!=e&&(c(Object(e))?ce.merge(n,"string"==typeof e?[e]:e):s.call(n,e)),n},inArray:function(e,t,n){return null==t?-1:se.call(t,e,n)},isXMLDoc:function(e){var t=e&&e.namespaceURI,n=e&&(e.ownerDocument||e).documentElement;return!l.test(t||n&&n.nodeName||"HTML")},merge:function(e,t){for(var n=+t.length,r=0,i=e.length;r<n;r++)e[i++]=t[r];return e.length=i,e},grep:function(e,t,n){for(var r=[],i=0,o=e.length,a=!n;i<o;i++)!t(e[i],i)!==a&&r.push(e[i]);return r},map:function(e,t,n){var r,i,o=0,a=[];if(c(e))for(r=e.length;o<r;o++)null!=(i=t(e[o],o,n))&&a.push(i);else for(o in e)null!=(i=t(e[o],o,n))&&a.push(i);return g(a)},guid:1,support:le}),"function"==typeof Symbol&&(ce.fn[Symbol.iterator]=oe[Symbol.iterator]),ce.each("Boolean Number String Function Array Date RegExp Object Error Symbol".split(" "),function(e,t){n["[object "+t+"]"]=t.toLowerCase()});var pe=oe.pop,de=oe.sort,he=oe.splice,ge="[\\x20\\t\\r\\n\\f]",ve=new RegExp("^"+ge+"+|((?:^|[^\\\\])(?:\\\\.)*)"+ge+"+$","g");ce.contains=function(e,t){var n=t&&t.parentNode;return e===n||!(!n||1!==n.nodeType||!(e.contains?e.contains(n):e.compareDocumentPosition&&16&e.compareDocumentPosition(n)))};var f=/([\0-\x1f\x7f]|^-?\d)|^-$|[^\x80-\uFFFF\w-]/g;function p(e,t){return t?"\0"===e?"\ufffd":e.slice(0,-1)+"\\"+e.charCodeAt(e.length-1).toString(16)+" ":"\\"+e}ce.escapeSelector=function(e){return(e+"").replace(f,p)};var ye=C,me=s;!function(){var e,b,w,o,a,T,r,C,d,i,k=me,S=ce.expando,E=0,n=0,s=W(),c=W(),u=W(),h=W(),l=function(e,t){return e===t&&(a=!0),0},f="checked|selected|async|autofocus|autoplay|controls|defer|disabled|hidden|ismap|loop|multiple|open|readonly|required|scoped",t="(?:\\\\[\\da-fA-F]{1,6}"+ge+"?|\\\\[^\\r\\n\\f]|[\\w-]|[^\0-\\x7f])+",p="\\["+ge+"*("+t+")(?:"+ge+"*([*^$|!~]?=)"+ge+"*(?:'((?:\\\\.|[^\\\\'])*)'|\"((?:\\\\.|[^\\\\\"])*)\"|("+t+"))|)"+ge+"*\\]",g=":("+t+")(?:\\((('((?:\\\\.|[^\\\\'])*)'|\"((?:\\\\.|[^\\\\\"])*)\")|((?:\\\\.|[^\\\\()[\\]]|"+p+")*)|.*)\\)|)",v=new RegExp(ge+"+","g"),y=new RegExp("^"+ge+"*,"+ge+"*"),m=new RegExp("^"+ge+"*([>+~]|"+ge+")"+ge+"*"),x=new RegExp(ge+"|>"),j=new RegExp(g),A=new RegExp("^"+t+"$"),D={ID:new RegExp("^#("+t+")"),CLASS:new RegExp("^\\.("+t+")"),TAG:new RegExp("^("+t+"|[*])"),ATTR:new RegExp("^"+p),PSEUDO:new RegExp("^"+g),CHILD:new RegExp("^:(only|first|last|nth|nth-last)-(child|of-type)(?:\\("+ge+"*(even|odd|(([+-]|)(\\d*)n|)"+ge+"*(?:([+-]|)"+ge+"*(\\d+)|))"+ge+"*\\)|)","i"),bool:new RegExp("^(?:"+f+")$","i"),needsContext:new RegExp("^"+ge+"*[>+~]|:(even|odd|eq|gt|lt|nth|first|last)(?:\\("+ge+"*((?:-\\d)?\\d*)"+ge+"*\\)|)(?=[^-]|$)","i")},N=/^(?:input|select|textarea|button)$/i,q=/^h\d$/i,L=/^(?:#([\w-]+)|(\w+)|\.([\w-]+))$/,H=/[+~]/,O=new RegExp("\\\\[\\da-fA-F]{1,6}"+ge+"?|\\\\([^\\r\\n\\f])","g"),P=function(e,t){var n="0x"+e.slice(1)-65536;return t||(n<0?String.fromCharCode(n+65536):String.fromCharCode(n>>10|55296,1023&n|56320))},M=function(){V()},R=J(function(e){return!0===e.disabled&&fe(e,"fieldset")},{dir:"parentNode",next:"legend"});try{k.apply(oe=ae.call(ye.childNodes),ye.childNodes),oe[ye.childNodes.length].nodeType}catch(e){k={apply:function(e,t){me.apply(e,ae.call(t))},call:function(e){me.apply(e,ae.call(arguments,1))}}}function I(t,e,n,r){var i,o,a,s,u,l,c,f=e&&e.ownerDocument,p=e?e.nodeType:9;if(n=n||[],"string"!=typeof t||!t||1!==p&&9!==p&&11!==p)return n;if(!r&&(V(e),e=e||T,C)){if(11!==p&&(u=L.exec(t)))if(i=u[1]){if(9===p){if(!(a=e.getElementById(i)))return n;if(a.id===i)return k.call(n,a),n}else if(f&&(a=f.getElementById(i))&&I.contains(e,a)&&a.id===i)return k.call(n,a),n}else{if(u[2])return k.apply(n,e.getElementsByTagName(t)),n;if((i=u[3])&&e.getElementsByClassName)return k.apply(n,e.getElementsByClassName(i)),n}if(!(h[t+" "]||d&&d.test(t))){if(c=t,f=e,1===p&&(x.test(t)||m.test(t))){(f=H.test(t)&&U(e.parentNode)||e)==e&&le.scope||((s=e.getAttribute("id"))?s=ce.escapeSelector(s):e.setAttribute("id",s=S)),o=(l=Y(t)).length;while(o--)l[o]=(s?"#"+s:":scope")+" "+Q(l[o]);c=l.join(",")}try{return k.apply(n,f.querySelectorAll(c)),n}catch(e){h(t,!0)}finally{s===S&&e.removeAttribute("id")}}}return re(t.replace(ve,"$1"),e,n,r)}function W(){var r=[];return function e(t,n){return r.push(t+" ")>b.cacheLength&&delete e[r.shift()],e[t+" "]=n}}function F(e){return e[S]=!0,e}function $(e){var t=T.createElement("fieldset");try{return!!e(t)}catch(e){return!1}finally{t.parentNode&&t.parentNode.removeChild(t),t=null}}function B(t){return function(e){return fe(e,"input")&&e.type===t}}function _(t){return function(e){return(fe(e,"input")||fe(e,"button"))&&e.type===t}}function z(t){return function(e){return"form"in e?e.parentNode&&!1===e.disabled?"label"in e?"label"in e.parentNode?e.parentNode.disabled===t:e.disabled===t:e.isDisabled===t||e.isDisabled!==!t&&R(e)===t:e.disabled===t:"label"in e&&e.disabled===t}}function X(a){return F(function(o){return o=+o,F(function(e,t){var n,r=a([],e.length,o),i=r.length;while(i--)e[n=r[i]]&&(e[n]=!(t[n]=e[n]))})})}function U(e){return e&&"undefined"!=typeof e.getElementsByTagName&&e}function V(e){var t,n=e?e.ownerDocument||e:ye;return n!=T&&9===n.nodeType&&n.documentElement&&(r=(T=n).documentElement,C=!ce.isXMLDoc(T),i=r.matches||r.webkitMatchesSelector||r.msMatchesSelector,r.msMatchesSelector&&ye!=T&&(t=T.defaultView)&&t.top!==t&&t.addEventListener("unload",M),le.getById=$(function(e){return r.appendChild(e).id=ce.expando,!T.getElementsByName||!T.getElementsByName(ce.expando).length}),le.disconnectedMatch=$(function(e){return i.call(e,"*")}),le.scope=$(function(){return T.querySelectorAll(":scope")}),le.cssHas=$(function(){try{return T.querySelector(":has(*,:jqfake)"),!1}catch(e){return!0}}),le.getById?(b.filter.ID=function(e){var t=e.replace(O,P);return function(e){return e.getAttribute("id")===t}},b.find.ID=function(e,t){if("undefined"!=typeof t.getElementById&&C){var n=t.getElementById(e);return n?[n]:[]}}):(b.filter.ID=function(e){var n=e.replace(O,P);return function(e){var t="undefined"!=typeof e.getAttributeNode&&e.getAttributeNode("id");return t&&t.value===n}},b.find.ID=function(e,t){if("undefined"!=typeof t.getElementById&&C){var n,r,i,o=t.getElementById(e);if(o){if((n=o.getAttributeNode("id"))&&n.value===e)return[o];i=t.getElementsByName(e),r=0;while(o=i[r++])if((n=o.getAttributeNode("id"))&&n.value===e)return[o]}return[]}}),b.find.TAG=function(e,t){return"undefined"!=typeof t.getElementsByTagName?t.getElementsByTagName(e):t.querySelectorAll(e)},b.find.CLASS=function(e,t){if("undefined"!=typeof t.getElementsByClassName&&C)return t.getElementsByClassName(e)},d=[],$(function(e){var t;r.appendChild(e).innerHTML="<a id='"+S+"' href='' disabled='disabled'></a><select id='"+S+"-\r\\' disabled='disabled'><option selected=''></option></select>",e.querySelectorAll("[selected]").length||d.push("\\["+ge+"*(?:value|"+f+")"),e.querySelectorAll("[id~="+S+"-]").length||d.push("~="),e.querySelectorAll("a#"+S+"+*").length||d.push(".#.+[+~]"),e.querySelectorAll(":checked").length||d.push(":checked"),(t=T.createElement("input")).setAttribute("type","hidden"),e.appendChild(t).setAttribute("name","D"),r.appendChild(e).disabled=!0,2!==e.querySelectorAll(":disabled").length&&d.push(":enabled",":disabled"),(t=T.createElement("input")).setAttribute("name",""),e.appendChild(t),e.querySelectorAll("[name='']").length||d.push("\\["+ge+"*name"+ge+"*="+ge+"*(?:''|\"\")")}),le.cssHas||d.push(":has"),d=d.length&&new RegExp(d.join("|")),l=function(e,t){if(e===t)return a=!0,0;var n=!e.compareDocumentPosition-!t.compareDocumentPosition;return n||(1&(n=(e.ownerDocument||e)==(t.ownerDocument||t)?e.compareDocumentPosition(t):1)||!le.sortDetached&&t.compareDocumentPosition(e)===n?e===T||e.ownerDocument==ye&&I.contains(ye,e)?-1:t===T||t.ownerDocument==ye&&I.contains(ye,t)?1:o?se.call(o,e)-se.call(o,t):0:4&n?-1:1)}),T}for(e in I.matches=function(e,t){return I(e,null,null,t)},I.matchesSelector=function(e,t){if(V(e),C&&!h[t+" "]&&(!d||!d.test(t)))try{var n=i.call(e,t);if(n||le.disconnectedMatch||e.document&&11!==e.document.nodeType)return n}catch(e){h(t,!0)}return 0<I(t,T,null,[e]).length},I.contains=function(e,t){return(e.ownerDocument||e)!=T&&V(e),ce.contains(e,t)},I.attr=function(e,t){(e.ownerDocument||e)!=T&&V(e);var n=b.attrHandle[t.toLowerCase()],r=n&&ue.call(b.attrHandle,t.toLowerCase())?n(e,t,!C):void 0;return void 0!==r?r:e.getAttribute(t)},I.error=function(e){throw new Error("Syntax error, unrecognized expression: "+e)},ce.uniqueSort=function(e){var t,n=[],r=0,i=0;if(a=!le.sortStable,o=!le.sortStable&&ae.call(e,0),de.call(e,l),a){while(t=e[i++])t===e[i]&&(r=n.push(i));while(r--)he.call(e,n[r],1)}return o=null,e},ce.fn.uniqueSort=function(){return this.pushStack(ce.uniqueSort(ae.apply(this)))},(b=ce.expr={cacheLength:50,createPseudo:F,match:D,attrHandle:{},find:{},relative:{">":{dir:"parentNode",first:!0}," ":{dir:"parentNode"},"+":{dir:"previousSibling",first:!0},"~":{dir:"previousSibling"}},preFilter:{ATTR:function(e){return e[1]=e[1].replace(O,P),e[3]=(e[3]||e[4]||e[5]||"").replace(O,P),"~="===e[2]&&(e[3]=" "+e[3]+" "),e.slice(0,4)},CHILD:function(e){return e[1]=e[1].toLowerCase(),"nth"===e[1].slice(0,3)?(e[3]||I.error(e[0]),e[4]=+(e[4]?e[5]+(e[6]||1):2*("even"===e[3]||"odd"===e[3])),e[5]=+(e[7]+e[8]||"odd"===e[3])):e[3]&&I.error(e[0]),e},PSEUDO:function(e){var t,n=!e[6]&&e[2];return D.CHILD.test(e[0])?null:(e[3]?e[2]=e[4]||e[5]||"":n&&j.test(n)&&(t=Y(n,!0))&&(t=n.indexOf(")",n.length-t)-n.length)&&(e[0]=e[0].slice(0,t),e[2]=n.slice(0,t)),e.slice(0,3))}},filter:{TAG:function(e){var t=e.replace(O,P).toLowerCase();return"*"===e?function(){return!0}:function(e){return fe(e,t)}},CLASS:function(e){var t=s[e+" "];return t||(t=new RegExp("(^|"+ge+")"+e+"("+ge+"|$)"))&&s(e,function(e){return t.test("string"==typeof e.className&&e.className||"undefined"!=typeof e.getAttribute&&e.getAttribute("class")||"")})},ATTR:function(n,r,i){return function(e){var t=I.attr(e,n);return null==t?"!="===r:!r||(t+="","="===r?t===i:"!="===r?t!==i:"^="===r?i&&0===t.indexOf(i):"*="===r?i&&-1<t.indexOf(i):"$="===r?i&&t.slice(-i.length)===i:"~="===r?-1<(" "+t.replace(v," ")+" ").indexOf(i):"|="===r&&(t===i||t.slice(0,i.length+1)===i+"-"))}},CHILD:function(d,e,t,h,g){var v="nth"!==d.slice(0,3),y="last"!==d.slice(-4),m="of-type"===e;return 1===h&&0===g?function(e){return!!e.parentNode}:function(e,t,n){var r,i,o,a,s,u=v!==y?"nextSibling":"previousSibling",l=e.parentNode,c=m&&e.nodeName.toLowerCase(),f=!n&&!m,p=!1;if(l){if(v){while(u){o=e;while(o=o[u])if(m?fe(o,c):1===o.nodeType)return!1;s=u="only"===d&&!s&&"nextSibling"}return!0}if(s=[y?l.firstChild:l.lastChild],y&&f){p=(a=(r=(i=l[S]||(l[S]={}))[d]||[])[0]===E&&r[1])&&r[2],o=a&&l.childNodes[a];while(o=++a&&o&&o[u]||(p=a=0)||s.pop())if(1===o.nodeType&&++p&&o===e){i[d]=[E,a,p];break}}else if(f&&(p=a=(r=(i=e[S]||(e[S]={}))[d]||[])[0]===E&&r[1]),!1===p)while(o=++a&&o&&o[u]||(p=a=0)||s.pop())if((m?fe(o,c):1===o.nodeType)&&++p&&(f&&((i=o[S]||(o[S]={}))[d]=[E,p]),o===e))break;return(p-=g)===h||p%h==0&&0<=p/h}}},PSEUDO:function(e,o){var t,a=b.pseudos[e]||b.setFilters[e.toLowerCase()]||I.error("unsupported pseudo: "+e);return a[S]?a(o):1<a.length?(t=[e,e,"",o],b.setFilters.hasOwnProperty(e.toLowerCase())?F(function(e,t){var n,r=a(e,o),i=r.length;while(i--)e[n=se.call(e,r[i])]=!(t[n]=r[i])}):function(e){return a(e,0,t)}):a}},pseudos:{not:F(function(e){var r=[],i=[],s=ne(e.replace(ve,"$1"));return s[S]?F(function(e,t,n,r){var i,o=s(e,null,r,[]),a=e.length;while(a--)(i=o[a])&&(e[a]=!(t[a]=i))}):function(e,t,n){return r[0]=e,s(r,null,n,i),r[0]=null,!i.pop()}}),has:F(function(t){return function(e){return 0<I(t,e).length}}),contains:F(function(t){return t=t.replace(O,P),function(e){return-1<(e.textContent||ce.text(e)).indexOf(t)}}),lang:F(function(n){return A.test(n||"")||I.error("unsupported lang: "+n),n=n.replace(O,P).toLowerCase(),function(e){var t;do{if(t=C?e.lang:e.getAttribute("xml:lang")||e.getAttribute("lang"))return(t=t.toLowerCase())===n||0===t.indexOf(n+"-")}while((e=e.parentNode)&&1===e.nodeType);return!1}}),target:function(e){var t=ie.location&&ie.location.hash;return t&&t.slice(1)===e.id},root:function(e){return e===r},focus:function(e){return e===function(){try{return T.activeElement}catch(e){}}()&&T.hasFocus()&&!!(e.type||e.href||~e.tabIndex)},enabled:z(!1),disabled:z(!0),checked:function(e){return fe(e,"input")&&!!e.checked||fe(e,"option")&&!!e.selected},selected:function(e){return e.parentNode&&e.parentNode.selectedIndex,!0===e.selected},empty:function(e){for(e=e.firstChild;e;e=e.nextSibling)if(e.nodeType<6)return!1;return!0},parent:function(e){return!b.pseudos.empty(e)},header:function(e){return q.test(e.nodeName)},input:function(e){return N.test(e.nodeName)},button:function(e){return fe(e,"input")&&"button"===e.type||fe(e,"button")},text:function(e){var t;return fe(e,"input")&&"text"===e.type&&(null==(t=e.getAttribute("type"))||"text"===t.toLowerCase())},first:X(function(){return[0]}),last:X(function(e,t){return[t-1]}),eq:X(function(e,t,n){return[n<0?n+t:n]}),even:X(function(e,t){for(var n=0;n<t;n+=2)e.push(n);return e}),odd:X(function(e,t){for(var n=1;n<t;n+=2)e.push(n);return e}),lt:X(function(e,t,n){var r;for(r=n<0?n+t:t<n?t:n;0<=--r;)e.push(r);return e}),gt:X(function(e,t,n){for(var r=n<0?n+t:n;++r<t;)e.push(r);return e})}}).pseudos.nth=b.pseudos.eq,{radio:!0,checkbox:!0,file:!0,password:!0,image:!0})b.pseudos[e]=B(e);for(e in{submit:!0,reset:!0})b.pseudos[e]=_(e);function G(){}function Y(e,t){var n,r,i,o,a,s,u,l=c[e+" "];if(l)return t?0:l.slice(0);a=e,s=[],u=b.preFilter;while(a){for(o in n&&!(r=y.exec(a))||(r&&(a=a.slice(r[0].length)||a),s.push(i=[])),n=!1,(r=m.exec(a))&&(n=r.shift(),i.push({value:n,type:r[0].replace(ve," ")}),a=a.slice(n.length)),b.filter)!(r=D[o].exec(a))||u[o]&&!(r=u[o](r))||(n=r.shift(),i.push({value:n,type:o,matches:r}),a=a.slice(n.length));if(!n)break}return t?a.length:a?I.error(e):c(e,s).slice(0)}function Q(e){for(var t=0,n=e.length,r="";t<n;t++)r+=e[t].value;return r}function J(a,e,t){var s=e.dir,u=e.next,l=u||s,c=t&&"parentNode"===l,f=n++;return e.first?function(e,t,n){while(e=e[s])if(1===e.nodeType||c)return a(e,t,n);return!1}:function(e,t,n){var r,i,o=[E,f];if(n){while(e=e[s])if((1===e.nodeType||c)&&a(e,t,n))return!0}else while(e=e[s])if(1===e.nodeType||c)if(i=e[S]||(e[S]={}),u&&fe(e,u))e=e[s]||e;else{if((r=i[l])&&r[0]===E&&r[1]===f)return o[2]=r[2];if((i[l]=o)[2]=a(e,t,n))return!0}return!1}}function K(i){return 1<i.length?function(e,t,n){var r=i.length;while(r--)if(!i[r](e,t,n))return!1;return!0}:i[0]}function Z(e,t,n,r,i){for(var o,a=[],s=0,u=e.length,l=null!=t;s<u;s++)(o=e[s])&&(n&&!n(o,r,i)||(a.push(o),l&&t.push(s)));return a}function ee(d,h,g,v,y,e){return v&&!v[S]&&(v=ee(v)),y&&!y[S]&&(y=ee(y,e)),F(function(e,t,n,r){var i,o,a,s,u=[],l=[],c=t.length,f=e||function(e,t,n){for(var r=0,i=t.length;r<i;r++)I(e,t[r],n);return n}(h||"*",n.nodeType?[n]:n,[]),p=!d||!e&&h?f:Z(f,u,d,n,r);if(g?g(p,s=y||(e?d:c||v)?[]:t,n,r):s=p,v){i=Z(s,l),v(i,[],n,r),o=i.length;while(o--)(a=i[o])&&(s[l[o]]=!(p[l[o]]=a))}if(e){if(y||d){if(y){i=[],o=s.length;while(o--)(a=s[o])&&i.push(p[o]=a);y(null,s=[],i,r)}o=s.length;while(o--)(a=s[o])&&-1<(i=y?se.call(e,a):u[o])&&(e[i]=!(t[i]=a))}}else s=Z(s===t?s.splice(c,s.length):s),y?y(null,t,s,r):k.apply(t,s)})}function te(e){for(var i,t,n,r=e.length,o=b.relative[e[0].type],a=o||b.relative[" "],s=o?1:0,u=J(function(e){return e===i},a,!0),l=J(function(e){return-1<se.call(i,e)},a,!0),c=[function(e,t,n){var r=!o&&(n||t!=w)||((i=t).nodeType?u(e,t,n):l(e,t,n));return i=null,r}];s<r;s++)if(t=b.relative[e[s].type])c=[J(K(c),t)];else{if((t=b.filter[e[s].type].apply(null,e[s].matches))[S]){for(n=++s;n<r;n++)if(b.relative[e[n].type])break;return ee(1<s&&K(c),1<s&&Q(e.slice(0,s-1).concat({value:" "===e[s-2].type?"*":""})).replace(ve,"$1"),t,s<n&&te(e.slice(s,n)),n<r&&te(e=e.slice(n)),n<r&&Q(e))}c.push(t)}return K(c)}function ne(e,t){var n,v,y,m,x,r,i=[],o=[],a=u[e+" "];if(!a){t||(t=Y(e)),n=t.length;while(n--)(a=te(t[n]))[S]?i.push(a):o.push(a);(a=u(e,(v=o,m=0<(y=i).length,x=0<v.length,r=function(e,t,n,r,i){var o,a,s,u=0,l="0",c=e&&[],f=[],p=w,d=e||x&&b.find.TAG("*",i),h=E+=null==p?1:Math.random()||.1,g=d.length;for(i&&(w=t==T||t||i);l!==g&&null!=(o=d[l]);l++){if(x&&o){a=0,t||o.ownerDocument==T||(V(o),n=!C);while(s=v[a++])if(s(o,t||T,n)){k.call(r,o);break}i&&(E=h)}m&&((o=!s&&o)&&u--,e&&c.push(o))}if(u+=l,m&&l!==u){a=0;while(s=y[a++])s(c,f,t,n);if(e){if(0<u)while(l--)c[l]||f[l]||(f[l]=pe.call(r));f=Z(f)}k.apply(r,f),i&&!e&&0<f.length&&1<u+y.length&&ce.uniqueSort(r)}return i&&(E=h,w=p),c},m?F(r):r))).selector=e}return a}function re(e,t,n,r){var i,o,a,s,u,l="function"==typeof e&&e,c=!r&&Y(e=l.selector||e);if(n=n||[],1===c.length){if(2<(o=c[0]=c[0].slice(0)).length&&"ID"===(a=o[0]).type&&9===t.nodeType&&C&&b.relative[o[1].type]){if(!(t=(b.find.ID(a.matches[0].replace(O,P),t)||[])[0]))return n;l&&(t=t.parentNode),e=e.slice(o.shift().value.length)}i=D.needsContext.test(e)?0:o.length;while(i--){if(a=o[i],b.relative[s=a.type])break;if((u=b.find[s])&&(r=u(a.matches[0].replace(O,P),H.test(o[0].type)&&U(t.parentNode)||t))){if(o.splice(i,1),!(e=r.length&&Q(o)))return k.apply(n,r),n;break}}}return(l||ne(e,c))(r,t,!C,n,!t||H.test(e)&&U(t.parentNode)||t),n}G.prototype=b.filters=b.pseudos,b.setFilters=new G,le.sortStable=S.split("").sort(l).join("")===S,V(),le.sortDetached=$(function(e){return 1&e.compareDocumentPosition(T.createElement("fieldset"))}),ce.find=I,ce.expr[":"]=ce.expr.pseudos,ce.unique=ce.uniqueSort,I.compile=ne,I.select=re,I.setDocument=V,I.tokenize=Y,I.escape=ce.escapeSelector,I.getText=ce.text,I.isXML=ce.isXMLDoc,I.selectors=ce.expr,I.support=ce.support,I.uniqueSort=ce.uniqueSort}();var d=function(e,t,n){var r=[],i=void 0!==n;while((e=e[t])&&9!==e.nodeType)if(1===e.nodeType){if(i&&ce(e).is(n))break;r.push(e)}return r},h=function(e,t){for(var n=[];e;e=e.nextSibling)1===e.nodeType&&e!==t&&n.push(e);return n},b=ce.expr.match.needsContext,w=/^<([a-z][^\/\0>:\x20\t\r\n\f]*)[\x20\t\r\n\f]*\/?>(?:<\/\1>|)$/i;function T(e,n,r){return v(n)?ce.grep(e,function(e,t){return!!n.call(e,t,e)!==r}):n.nodeType?ce.grep(e,function(e){return e===n!==r}):"string"!=typeof n?ce.grep(e,function(e){return-1<se.call(n,e)!==r}):ce.filter(n,e,r)}ce.filter=function(e,t,n){var r=t[0];return n&&(e=":not("+e+")"),1===t.length&&1===r.nodeType?ce.find.matchesSelector(r,e)?[r]:[]:ce.find.matches(e,ce.grep(t,function(e){return 1===e.nodeType}))},ce.fn.extend({find:function(e){var t,n,r=this.length,i=this;if("string"!=typeof e)return this.pushStack(ce(e).filter(function(){for(t=0;t<r;t++)if(ce.contains(i[t],this))return!0}));for(n=this.pushStack([]),t=0;t<r;t++)ce.find(e,i[t],n);return 1<r?ce.uniqueSort(n):n},filter:function(e){return this.pushStack(T(this,e||[],!1))},not:function(e){return this.pushStack(T(this,e||[],!0))},is:function(e){return!!T(this,"string"==typeof e&&b.test(e)?ce(e):e||[],!1).length}});var k,S=/^(?:\s*(<[\w\W]+>)[^>]*|#([\w-]+))$/;(ce.fn.init=function(e,t,n){var r,i;if(!e)return this;if(n=n||k,"string"==typeof e){if(!(r="<"===e[0]&&">"===e[e.length-1]&&3<=e.length?[null,e,null]:S.exec(e))||!r[1]&&t)return!t||t.jquery?(t||n).find(e):this.constructor(t).find(e);if(r[1]){if(t=t instanceof ce?t[0]:t,ce.merge(this,ce.parseHTML(r[1],t&&t.nodeType?t.ownerDocument||t:C,!0)),w.test(r[1])&&ce.isPlainObject(t))for(r in t)v(this[r])?this[r](t[r]):this.attr(r,t[r]);return this}return(i=C.getElementById(r[2]))&&(this[0]=i,this.length=1),this}return e.nodeType?(this[0]=e,this.length=1,this):v(e)?void 0!==n.ready?n.ready(e):e(ce):ce.makeArray(e,this)}).prototype=ce.fn,k=ce(C);var E=/^(?:parents|prev(?:Until|All))/,j={children:!0,contents:!0,next:!0,prev:!0};function A(e,t){while((e=e[t])&&1!==e.nodeType);return e}ce.fn.extend({has:function(e){var t=ce(e,this),n=t.length;return this.filter(function(){for(var e=0;e<n;e++)if(ce.contains(this,t[e]))return!0})},closest:function(e,t){var n,r=0,i=this.length,o=[],a="string"!=typeof e&&ce(e);if(!b.test(e))for(;r<i;r++)for(n=this[r];n&&n!==t;n=n.parentNode)if(n.nodeType<11&&(a?-1<a.index(n):1===n.nodeType&&ce.find.matchesSelector(n,e))){o.push(n);break}return this.pushStack(1<o.length?ce.uniqueSort(o):o)},index:function(e){return e?"string"==typeof e?se.call(ce(e),this[0]):se.call(this,e.jquery?e[0]:e):this[0]&&this[0].parentNode?this.first().prevAll().length:-1},add:function(e,t){return this.pushStack(ce.uniqueSort(ce.merge(this.get(),ce(e,t))))},addBack:function(e){return this.add(null==e?this.prevObject:this.prevObject.filter(e))}}),ce.each({parent:function(e){var t=e.parentNode;return t&&11!==t.nodeType?t:null},parents:function(e){return d(e,"parentNode")},parentsUntil:function(e,t,n){return d(e,"parentNode",n)},next:function(e){return A(e,"nextSibling")},prev:function(e){return A(e,"previousSibling")},nextAll:function(e){return d(e,"nextSibling")},prevAll:function(e){return d(e,"previousSibling")},nextUntil:function(e,t,n){return d(e,"nextSibling",n)},prevUntil:function(e,t,n){return d(e,"previousSibling",n)},siblings:function(e){return h((e.parentNode||{}).firstChild,e)},children:function(e){return h(e.firstChild)},contents:function(e){return null!=e.contentDocument&&r(e.contentDocument)?e.contentDocument:(fe(e,"template")&&(e=e.content||e),ce.merge([],e.childNodes))}},function(r,i){ce.fn[r]=function(e,t){var n=ce.map(this,i,e);return"Until"!==r.slice(-5)&&(t=e),t&&"string"==typeof t&&(n=ce.filter(t,n)),1<this.length&&(j[r]||ce.uniqueSort(n),E.test(r)&&n.reverse()),this.pushStack(n)}});var D=/[^\x20\t\r\n\f]+/g;function N(e){return e}function q(e){throw e}function L(e,t,n,r){var i;try{e&&v(i=e.promise)?i.call(e).done(t).fail(n):e&&v(i=e.then)?i.call(e,t,n):t.apply(void 0,[e].slice(r))}catch(e){n.apply(void 0,[e])}}ce.Callbacks=function(r){var e,n;r="string"==typeof r?(e=r,n={},ce.each(e.match(D)||[],function(e,t){n[t]=!0}),n):ce.extend({},r);var i,t,o,a,s=[],u=[],l=-1,c=function(){for(a=a||r.once,o=i=!0;u.length;l=-1){t=u.shift();while(++l<s.length)!1===s[l].apply(t[0],t[1])&&r.stopOnFalse&&(l=s.length,t=!1)}r.memory||(t=!1),i=!1,a&&(s=t?[]:"")},f={add:function(){return s&&(t&&!i&&(l=s.length-1,u.push(t)),function n(e){ce.each(e,function(e,t){v(t)?r.unique&&f.has(t)||s.push(t):t&&t.length&&"string"!==x(t)&&n(t)})}(arguments),t&&!i&&c()),this},remove:function(){return ce.each(arguments,function(e,t){var n;while(-1<(n=ce.inArray(t,s,n)))s.splice(n,1),n<=l&&l--}),this},has:function(e){return e?-1<ce.inArray(e,s):0<s.length},empty:function(){return s&&(s=[]),this},disable:function(){return a=u=[],s=t="",this},disabled:function(){return!s},lock:function(){return a=u=[],t||i||(s=t=""),this},locked:function(){return!!a},fireWith:function(e,t){return a||(t=[e,(t=t||[]).slice?t.slice():t],u.push(t),i||c()),this},fire:function(){return f.fireWith(this,arguments),this},fired:function(){return!!o}};return f},ce.extend({Deferred:function(e){var o=[["notify","progress",ce.Callbacks("memory"),ce.Callbacks("memory"),2],["resolve","done",ce.Callbacks("once memory"),ce.Callbacks("once memory"),0,"resolved"],["reject","fail",ce.Callbacks("once memory"),ce.Callbacks("once memory"),1,"rejected"]],i="pending",a={state:function(){return i},always:function(){return s.done(arguments).fail(arguments),this},"catch":function(e){return a.then(null,e)},pipe:function(){var i=arguments;return ce.Deferred(function(r){ce.each(o,function(e,t){var n=v(i[t[4]])&&i[t[4]];s[t[1]](function(){var e=n&&n.apply(this,arguments);e&&v(e.promise)?e.promise().progress(r.notify).done(r.resolve).fail(r.reject):r[t[0]+"With"](this,n?[e]:arguments)})}),i=null}).promise()},then:function(t,n,r){var u=0;function l(i,o,a,s){return function(){var n=this,r=arguments,e=function(){var e,t;if(!(i<u)){if((e=a.apply(n,r))===o.promise())throw new TypeError("Thenable self-resolution");t=e&&("object"==typeof e||"function"==typeof e)&&e.then,v(t)?s?t.call(e,l(u,o,N,s),l(u,o,q,s)):(u++,t.call(e,l(u,o,N,s),l(u,o,q,s),l(u,o,N,o.notifyWith))):(a!==N&&(n=void 0,r=[e]),(s||o.resolveWith)(n,r))}},t=s?e:function(){try{e()}catch(e){ce.Deferred.exceptionHook&&ce.Deferred.exceptionHook(e,t.error),u<=i+1&&(a!==q&&(n=void 0,r=[e]),o.rejectWith(n,r))}};i?t():(ce.Deferred.getErrorHook?t.error=ce.Deferred.getErrorHook():ce.Deferred.getStackHook&&(t.error=ce.Deferred.getStackHook()),ie.setTimeout(t))}}return ce.Deferred(function(e){o[0][3].add(l(0,e,v(r)?r:N,e.notifyWith)),o[1][3].add(l(0,e,v(t)?t:N)),o[2][3].add(l(0,e,v(n)?n:q))}).promise()},promise:function(e){return null!=e?ce.extend(e,a):a}},s={};return ce.each(o,function(e,t){var n=t[2],r=t[5];a[t[1]]=n.add,r&&n.add(function(){i=r},o[3-e][2].disable,o[3-e][3].disable,o[0][2].lock,o[0][3].lock),n.add(t[3].fire),s[t[0]]=function(){return s[t[0]+"With"](this===s?void 0:this,arguments),this},s[t[0]+"With"]=n.fireWith}),a.promise(s),e&&e.call(s,s),s},when:function(e){var n=arguments.length,t=n,r=Array(t),i=ae.call(arguments),o=ce.Deferred(),a=function(t){return function(e){r[t]=this,i[t]=1<arguments.length?ae.call(arguments):e,--n||o.resolveWith(r,i)}};if(n<=1&&(L(e,o.done(a(t)).resolve,o.reject,!n),"pending"===o.state()||v(i[t]&&i[t].then)))return o.then();while(t--)L(i[t],a(t),o.reject);return o.promise()}});var H=/^(Eval|Internal|Range|Reference|Syntax|Type|URI)Error$/;ce.Deferred.exceptionHook=function(e,t){ie.console&&ie.console.warn&&e&&H.test(e.name)&&ie.console.warn("jQuery.Deferred exception: "+e.message,e.stack,t)},ce.readyException=function(e){ie.setTimeout(function(){throw e})};var O=ce.Deferred();function P(){C.removeEventListener("DOMContentLoaded",P),ie.removeEventListener("load",P),ce.ready()}ce.fn.ready=function(e){return O.then(e)["catch"](function(e){ce.readyException(e)}),this},ce.extend({isReady:!1,readyWait:1,ready:function(e){(!0===e?--ce.readyWait:ce.isReady)||(ce.isReady=!0)!==e&&0<--ce.readyWait||O.resolveWith(C,[ce])}}),ce.ready.then=O.then,"complete"===C.readyState||"loading"!==C.readyState&&!C.documentElement.doScroll?ie.setTimeout(ce.ready):(C.addEventListener("DOMContentLoaded",P),ie.addEventListener("load",P));var M=function(e,t,n,r,i,o,a){var s=0,u=e.length,l=null==n;if("object"===x(n))for(s in i=!0,n)M(e,t,s,n[s],!0,o,a);else if(void 0!==r&&(i=!0,v(r)||(a=!0),l&&(a?(t.call(e,r),t=null):(l=t,t=function(e,t,n){return l.call(ce(e),n)})),t))for(;s<u;s++)t(e[s],n,a?r:r.call(e[s],s,t(e[s],n)));return i?e:l?t.call(e):u?t(e[0],n):o},R=/^-ms-/,I=/-([a-z])/g;function W(e,t){return t.toUpperCase()}function F(e){return e.replace(R,"ms-").replace(I,W)}var $=function(e){return 1===e.nodeType||9===e.nodeType||!+e.nodeType};function B(){this.expando=ce.expando+B.uid++}B.uid=1,B.prototype={cache:function(e){var t=e[this.expando];return t||(t={},$(e)&&(e.nodeType?e[this.expando]=t:Object.defineProperty(e,this.expando,{value:t,configurable:!0}))),t},set:function(e,t,n){var r,i=this.cache(e);if("string"==typeof t)i[F(t)]=n;else for(r in t)i[F(r)]=t[r];return i},get:function(e,t){return void 0===t?this.cache(e):e[this.expando]&&e[this.expando][F(t)]},access:function(e,t,n){return void 0===t||t&&"string"==typeof t&&void 0===n?this.get(e,t):(this.set(e,t,n),void 0!==n?n:t)},remove:function(e,t){var n,r=e[this.expando];if(void 0!==r){if(void 0!==t){n=(t=Array.isArray(t)?t.map(F):(t=F(t))in r?[t]:t.match(D)||[]).length;while(n--)delete r[t[n]]}(void 0===t||ce.isEmptyObject(r))&&(e.nodeType?e[this.expando]=void 0:delete e[this.expando])}},hasData:function(e){var t=e[this.expando];return void 0!==t&&!ce.isEmptyObject(t)}};var _=new B,z=new B,X=/^(?:\{[\w\W]*\}|\[[\w\W]*\])$/,U=/[A-Z]/g;function V(e,t,n){var r,i;if(void 0===n&&1===e.nodeType)if(r="data-"+t.replace(U,"-$&").toLowerCase(),"string"==typeof(n=e.getAttribute(r))){try{n="true"===(i=n)||"false"!==i&&("null"===i?null:i===+i+""?+i:X.test(i)?JSON.parse(i):i)}catch(e){}z.set(e,t,n)}else n=void 0;return n}ce.extend({hasData:function(e){return z.hasData(e)||_.hasData(e)},data:function(e,t,n){return z.access(e,t,n)},removeData:function(e,t){z.remove(e,t)},_data:function(e,t,n){return _.access(e,t,n)},_removeData:function(e,t){_.remove(e,t)}}),ce.fn.extend({data:function(n,e){var t,r,i,o=this[0],a=o&&o.attributes;if(void 0===n){if(this.length&&(i=z.get(o),1===o.nodeType&&!_.get(o,"hasDataAttrs"))){t=a.length;while(t--)a[t]&&0===(r=a[t].name).indexOf("data-")&&(r=F(r.slice(5)),V(o,r,i[r]));_.set(o,"hasDataAttrs",!0)}return i}return"object"==typeof n?this.each(function(){z.set(this,n)}):M(this,function(e){var t;if(o&&void 0===e)return void 0!==(t=z.get(o,n))?t:void 0!==(t=V(o,n))?t:void 0;this.each(function(){z.set(this,n,e)})},null,e,1<arguments.length,null,!0)},removeData:function(e){return this.each(function(){z.remove(this,e)})}}),ce.extend({queue:function(e,t,n){var r;if(e)return t=(t||"fx")+"queue",r=_.get(e,t),n&&(!r||Array.isArray(n)?r=_.access(e,t,ce.makeArray(n)):r.push(n)),r||[]},dequeue:function(e,t){t=t||"fx";var n=ce.queue(e,t),r=n.length,i=n.shift(),o=ce._queueHooks(e,t);"inprogress"===i&&(i=n.shift(),r--),i&&("fx"===t&&n.unshift("inprogress"),delete o.stop,i.call(e,function(){ce.dequeue(e,t)},o)),!r&&o&&o.empty.fire()},_queueHooks:function(e,t){var n=t+"queueHooks";return _.get(e,n)||_.access(e,n,{empty:ce.Callbacks("once memory").add(function(){_.remove(e,[t+"queue",n])})})}}),ce.fn.extend({queue:function(t,n){var e=2;return"string"!=typeof t&&(n=t,t="fx",e--),arguments.length<e?ce.queue(this[0],t):void 0===n?this:this.each(function(){var e=ce.queue(this,t,n);ce._queueHooks(this,t),"fx"===t&&"inprogress"!==e[0]&&ce.dequeue(this,t)})},dequeue:function(e){return this.each(function(){ce.dequeue(this,e)})},clearQueue:function(e){return this.queue(e||"fx",[])},promise:function(e,t){var n,r=1,i=ce.Deferred(),o=this,a=this.length,s=function(){--r||i.resolveWith(o,[o])};"string"!=typeof e&&(t=e,e=void 0),e=e||"fx";while(a--)(n=_.get(o[a],e+"queueHooks"))&&n.empty&&(r++,n.empty.add(s));return s(),i.promise(t)}});var G=/[+-]?(?:\d*\.|)\d+(?:[eE][+-]?\d+|)/.source,Y=new RegExp("^(?:([+-])=|)("+G+")([a-z%]*)$","i"),Q=["Top","Right","Bottom","Left"],J=C.documentElement,K=function(e){return ce.contains(e.ownerDocument,e)},Z={composed:!0};J.getRootNode&&(K=function(e){return ce.contains(e.ownerDocument,e)||e.getRootNode(Z)===e.ownerDocument});var ee=function(e,t){return"none"===(e=t||e).style.display||""===e.style.display&&K(e)&&"none"===ce.css(e,"display")};function te(e,t,n,r){var i,o,a=20,s=r?function(){return r.cur()}:function(){return ce.css(e,t,"")},u=s(),l=n&&n[3]||(ce.cssNumber[t]?"":"px"),c=e.nodeType&&(ce.cssNumber[t]||"px"!==l&&+u)&&Y.exec(ce.css(e,t));if(c&&c[3]!==l){u/=2,l=l||c[3],c=+u||1;while(a--)ce.style(e,t,c+l),(1-o)*(1-(o=s()/u||.5))<=0&&(a=0),c/=o;c*=2,ce.style(e,t,c+l),n=n||[]}return n&&(c=+c||+u||0,i=n[1]?c+(n[1]+1)*n[2]:+n[2],r&&(r.unit=l,r.start=c,r.end=i)),i}var ne={};function re(e,t){for(var n,r,i,o,a,s,u,l=[],c=0,f=e.length;c<f;c++)(r=e[c]).style&&(n=r.style.display,t?("none"===n&&(l[c]=_.get(r,"display")||null,l[c]||(r.style.display="")),""===r.style.display&&ee(r)&&(l[c]=(u=a=o=void 0,a=(i=r).ownerDocument,s=i.nodeName,(u=ne[s])||(o=a.body.appendChild(a.createElement(s)),u=ce.css(o,"display"),o.parentNode.removeChild(o),"none"===u&&(u="block"),ne[s]=u)))):"none"!==n&&(l[c]="none",_.set(r,"display",n)));for(c=0;c<f;c++)null!=l[c]&&(e[c].style.display=l[c]);return e}ce.fn.extend({show:function(){return re(this,!0)},hide:function(){return re(this)},toggle:function(e){return"boolean"==typeof e?e?this.show():this.hide():this.each(function(){ee(this)?ce(this).show():ce(this).hide()})}});var xe,be,we=/^(?:checkbox|radio)$/i,Te=/<([a-z][^\/\0>\x20\t\r\n\f]*)/i,Ce=/^$|^module$|\/(?:java|ecma)script/i;xe=C.createDocumentFragment().appendChild(C.createElement("div")),(be=C.createElement("input")).setAttribute("type","radio"),be.setAttribute("checked","checked"),be.setAttribute("name","t"),xe.appendChild(be),le.checkClone=xe.cloneNode(!0).cloneNode(!0).lastChild.checked,xe.innerHTML="<textarea>x</textarea>",le.noCloneChecked=!!xe.cloneNode(!0).lastChild.defaultValue,xe.innerHTML="<option></option>",le.option=!!xe.lastChild;var ke={thead:[1,"<table>","</table>"],col:[2,"<table><colgroup>","</colgroup></table>"],tr:[2,"<table><tbody>","</tbody></table>"],td:[3,"<table><tbody><tr>","</tr></tbody></table>"],_default:[0,"",""]};function Se(e,t){var n;return n="undefined"!=typeof e.getElementsByTagName?e.getElementsByTagName(t||"*"):"undefined"!=typeof e.querySelectorAll?e.querySelectorAll(t||"*"):[],void 0===t||t&&fe(e,t)?ce.merge([e],n):n}function Ee(e,t){for(var n=0,r=e.length;n<r;n++)_.set(e[n],"globalEval",!t||_.get(t[n],"globalEval"))}ke.tbody=ke.tfoot=ke.colgroup=ke.caption=ke.thead,ke.th=ke.td,le.option||(ke.optgroup=ke.option=[1,"<select multiple='multiple'>","</select>"]);var je=/<|&#?\w+;/;function Ae(e,t,n,r,i){for(var o,a,s,u,l,c,f=t.createDocumentFragment(),p=[],d=0,h=e.length;d<h;d++)if((o=e[d])||0===o)if("object"===x(o))ce.merge(p,o.nodeType?[o]:o);else if(je.test(o)){a=a||f.appendChild(t.createElement("div")),s=(Te.exec(o)||["",""])[1].toLowerCase(),u=ke[s]||ke._default,a.innerHTML=u[1]+ce.htmlPrefilter(o)+u[2],c=u[0];while(c--)a=a.lastChild;ce.merge(p,a.childNodes),(a=f.firstChild).textContent=""}else p.push(t.createTextNode(o));f.textContent="",d=0;while(o=p[d++])if(r&&-1<ce.inArray(o,r))i&&i.push(o);else if(l=K(o),a=Se(f.appendChild(o),"script"),l&&Ee(a),n){c=0;while(o=a[c++])Ce.test(o.type||"")&&n.push(o)}return f}var De=/^([^.]*)(?:\.(.+)|)/;function Ne(){return!0}function qe(){return!1}function Le(e,t,n,r,i,o){var a,s;if("object"==typeof t){for(s in"string"!=typeof n&&(r=r||n,n=void 0),t)Le(e,s,n,r,t[s],o);return e}if(null==r&&null==i?(i=n,r=n=void 0):null==i&&("string"==typeof n?(i=r,r=void 0):(i=r,r=n,n=void 0)),!1===i)i=qe;else if(!i)return e;return 1===o&&(a=i,(i=function(e){return ce().off(e),a.apply(this,arguments)}).guid=a.guid||(a.guid=ce.guid++)),e.each(function(){ce.event.add(this,t,i,r,n)})}function He(e,r,t){t?(_.set(e,r,!1),ce.event.add(e,r,{namespace:!1,handler:function(e){var t,n=_.get(this,r);if(1&e.isTrigger&&this[r]){if(n)(ce.event.special[r]||{}).delegateType&&e.stopPropagation();else if(n=ae.call(arguments),_.set(this,r,n),this[r](),t=_.get(this,r),_.set(this,r,!1),n!==t)return e.stopImmediatePropagation(),e.preventDefault(),t}else n&&(_.set(this,r,ce.event.trigger(n[0],n.slice(1),this)),e.stopPropagation(),e.isImmediatePropagationStopped=Ne)}})):void 0===_.get(e,r)&&ce.event.add(e,r,Ne)}ce.event={global:{},add:function(t,e,n,r,i){var o,a,s,u,l,c,f,p,d,h,g,v=_.get(t);if($(t)){n.handler&&(n=(o=n).handler,i=o.selector),i&&ce.find.matchesSelector(J,i),n.guid||(n.guid=ce.guid++),(u=v.events)||(u=v.events=Object.create(null)),(a=v.handle)||(a=v.handle=function(e){return"undefined"!=typeof ce&&ce.event.triggered!==e.type?ce.event.dispatch.apply(t,arguments):void 0}),l=(e=(e||"").match(D)||[""]).length;while(l--)d=g=(s=De.exec(e[l])||[])[1],h=(s[2]||"").split(".").sort(),d&&(f=ce.event.special[d]||{},d=(i?f.delegateType:f.bindType)||d,f=ce.event.special[d]||{},c=ce.extend({type:d,origType:g,data:r,handler:n,guid:n.guid,selector:i,needsContext:i&&ce.expr.match.needsContext.test(i),namespace:h.join(".")},o),(p=u[d])||((p=u[d]=[]).delegateCount=0,f.setup&&!1!==f.setup.call(t,r,h,a)||t.addEventListener&&t.addEventListener(d,a)),f.add&&(f.add.call(t,c),c.handler.guid||(c.handler.guid=n.guid)),i?p.splice(p.delegateCount++,0,c):p.push(c),ce.event.global[d]=!0)}},remove:function(e,t,n,r,i){var o,a,s,u,l,c,f,p,d,h,g,v=_.hasData(e)&&_.get(e);if(v&&(u=v.events)){l=(t=(t||"").match(D)||[""]).length;while(l--)if(d=g=(s=De.exec(t[l])||[])[1],h=(s[2]||"").split(".").sort(),d){f=ce.event.special[d]||{},p=u[d=(r?f.delegateType:f.bindType)||d]||[],s=s[2]&&new RegExp("(^|\\.)"+h.join("\\.(?:.*\\.|)")+"(\\.|$)"),a=o=p.length;while(o--)c=p[o],!i&&g!==c.origType||n&&n.guid!==c.guid||s&&!s.test(c.namespace)||r&&r!==c.selector&&("**"!==r||!c.selector)||(p.splice(o,1),c.selector&&p.delegateCount--,f.remove&&f.remove.call(e,c));a&&!p.length&&(f.teardown&&!1!==f.teardown.call(e,h,v.handle)||ce.removeEvent(e,d,v.handle),delete u[d])}else for(d in u)ce.event.remove(e,d+t[l],n,r,!0);ce.isEmptyObject(u)&&_.remove(e,"handle events")}},dispatch:function(e){var t,n,r,i,o,a,s=new Array(arguments.length),u=ce.event.fix(e),l=(_.get(this,"events")||Object.create(null))[u.type]||[],c=ce.event.special[u.type]||{};for(s[0]=u,t=1;t<arguments.length;t++)s[t]=arguments[t];if(u.delegateTarget=this,!c.preDispatch||!1!==c.preDispatch.call(this,u)){a=ce.event.handlers.call(this,u,l),t=0;while((i=a[t++])&&!u.isPropagationStopped()){u.currentTarget=i.elem,n=0;while((o=i.handlers[n++])&&!u.isImmediatePropagationStopped())u.rnamespace&&!1!==o.namespace&&!u.rnamespace.test(o.namespace)||(u.handleObj=o,u.data=o.data,void 0!==(r=((ce.event.special[o.origType]||{}).handle||o.handler).apply(i.elem,s))&&!1===(u.result=r)&&(u.preventDefault(),u.stopPropagation()))}return c.postDispatch&&c.postDispatch.call(this,u),u.result}},handlers:function(e,t){var n,r,i,o,a,s=[],u=t.delegateCount,l=e.target;if(u&&l.nodeType&&!("click"===e.type&&1<=e.button))for(;l!==this;l=l.parentNode||this)if(1===l.nodeType&&("click"!==e.type||!0!==l.disabled)){for(o=[],a={},n=0;n<u;n++)void 0===a[i=(r=t[n]).selector+" "]&&(a[i]=r.needsContext?-1<ce(i,this).index(l):ce.find(i,this,null,[l]).length),a[i]&&o.push(r);o.length&&s.push({elem:l,handlers:o})}return l=this,u<t.length&&s.push({elem:l,handlers:t.slice(u)}),s},addProp:function(t,e){Object.defineProperty(ce.Event.prototype,t,{enumerable:!0,configurable:!0,get:v(e)?function(){if(this.originalEvent)return e(this.originalEvent)}:function(){if(this.originalEvent)return this.originalEvent[t]},set:function(e){Object.defineProperty(this,t,{enumerable:!0,configurable:!0,writable:!0,value:e})}})},fix:function(e){return e[ce.expando]?e:new ce.Event(e)},special:{load:{noBubble:!0},click:{setup:function(e){var t=this||e;return we.test(t.type)&&t.click&&fe(t,"input")&&He(t,"click",!0),!1},trigger:function(e){var t=this||e;return we.test(t.type)&&t.click&&fe(t,"input")&&He(t,"click"),!0},_default:function(e){var t=e.target;return we.test(t.type)&&t.click&&fe(t,"input")&&_.get(t,"click")||fe(t,"a")}},beforeunload:{postDispatch:function(e){void 0!==e.result&&e.originalEvent&&(e.originalEvent.returnValue=e.result)}}}},ce.removeEvent=function(e,t,n){e.removeEventListener&&e.removeEventListener(t,n)},ce.Event=function(e,t){if(!(this instanceof ce.Event))return new ce.Event(e,t);e&&e.type?(this.originalEvent=e,this.type=e.type,this.isDefaultPrevented=e.defaultPrevented||void 0===e.defaultPrevented&&!1===e.returnValue?Ne:qe,this.target=e.target&&3===e.target.nodeType?e.target.parentNode:e.target,this.currentTarget=e.currentTarget,this.relatedTarget=e.relatedTarget):this.type=e,t&&ce.extend(this,t),this.timeStamp=e&&e.timeStamp||Date.now(),this[ce.expando]=!0},ce.Event.prototype={constructor:ce.Event,isDefaultPrevented:qe,isPropagationStopped:qe,isImmediatePropagationStopped:qe,isSimulated:!1,preventDefault:function(){var e=this.originalEvent;this.isDefaultPrevented=Ne,e&&!this.isSimulated&&e.preventDefault()},stopPropagation:function(){var e=this.originalEvent;this.isPropagationStopped=Ne,e&&!this.isSimulated&&e.stopPropagation()},stopImmediatePropagation:function(){var e=this.originalEvent;this.isImmediatePropagationStopped=Ne,e&&!this.isSimulated&&e.stopImmediatePropagation(),this.stopPropagation()}},ce.each({altKey:!0,bubbles:!0,cancelable:!0,changedTouches:!0,ctrlKey:!0,detail:!0,eventPhase:!0,metaKey:!0,pageX:!0,pageY:!0,shiftKey:!0,view:!0,"char":!0,code:!0,charCode:!0,key:!0,keyCode:!0,button:!0,buttons:!0,clientX:!0,clientY:!0,offsetX:!0,offsetY:!0,pointerId:!0,pointerType:!0,screenX:!0,screenY:!0,targetTouches:!0,toElement:!0,touches:!0,which:!0},ce.event.addProp),ce.each({focus:"focusin",blur:"focusout"},function(r,i){function o(e){if(C.documentMode){var t=_.get(this,"handle"),n=ce.event.fix(e);n.type="focusin"===e.type?"focus":"blur",n.isSimulated=!0,t(e),n.target===n.currentTarget&&t(n)}else ce.event.simulate(i,e.target,ce.event.fix(e))}ce.event.special[r]={setup:function(){var e;if(He(this,r,!0),!C.documentMode)return!1;(e=_.get(this,i))||this.addEventListener(i,o),_.set(this,i,(e||0)+1)},trigger:function(){return He(this,r),!0},teardown:function(){var e;if(!C.documentMode)return!1;(e=_.get(this,i)-1)?_.set(this,i,e):(this.removeEventListener(i,o),_.remove(this,i))},_default:function(e){return _.get(e.target,r)},delegateType:i},ce.event.special[i]={setup:function(){var e=this.ownerDocument||this.document||this,t=C.documentMode?this:e,n=_.get(t,i);n||(C.documentMode?this.addEventListener(i,o):e.addEventListener(r,o,!0)),_.set(t,i,(n||0)+1)},teardown:function(){var e=this.ownerDocument||this.document||this,t=C.documentMode?this:e,n=_.get(t,i)-1;n?_.set(t,i,n):(C.documentMode?this.removeEventListener(i,o):e.removeEventListener(r,o,!0),_.remove(t,i))}}}),ce.each({mouseenter:"mouseover",mouseleave:"mouseout",pointerenter:"pointerover",pointerleave:"pointerout"},function(e,i){ce.event.special[e]={delegateType:i,bindType:i,handle:function(e){var t,n=e.relatedTarget,r=e.handleObj;return n&&(n===this||ce.contains(this,n))||(e.type=r.origType,t=r.handler.apply(this,arguments),e.type=i),t}}}),ce.fn.extend({on:function(e,t,n,r){return Le(this,e,t,n,r)},one:function(e,t,n,r){return Le(this,e,t,n,r,1)},off:function(e,t,n){var r,i;if(e&&e.preventDefault&&e.handleObj)return r=e.handleObj,ce(e.delegateTarget).off(r.namespace?r.origType+"."+r.namespace:r.origType,r.selector,r.handler),this;if("object"==typeof e){for(i in e)this.off(i,t,e[i]);return this}return!1!==t&&"function"!=typeof t||(n=t,t=void 0),!1===n&&(n=qe),this.each(function(){ce.event.remove(this,e,n,t)})}});var Oe=/<script|<style|<link/i,Pe=/checked\s*(?:[^=]|=\s*.checked.)/i,Me=/^\s*<!\[CDATA\[|\]\]>\s*$/g;function Re(e,t){return fe(e,"table")&&fe(11!==t.nodeType?t:t.firstChild,"tr")&&ce(e).children("tbody")[0]||e}function Ie(e){return e.type=(null!==e.getAttribute("type"))+"/"+e.type,e}function We(e){return"true/"===(e.type||"").slice(0,5)?e.type=e.type.slice(5):e.removeAttribute("type"),e}function Fe(e,t){var n,r,i,o,a,s;if(1===t.nodeType){if(_.hasData(e)&&(s=_.get(e).events))for(i in _.remove(t,"handle events"),s)for(n=0,r=s[i].length;n<r;n++)ce.event.add(t,i,s[i][n]);z.hasData(e)&&(o=z.access(e),a=ce.extend({},o),z.set(t,a))}}function $e(n,r,i,o){r=g(r);var e,t,a,s,u,l,c=0,f=n.length,p=f-1,d=r[0],h=v(d);if(h||1<f&&"string"==typeof d&&!le.checkClone&&Pe.test(d))return n.each(function(e){var t=n.eq(e);h&&(r[0]=d.call(this,e,t.html())),$e(t,r,i,o)});if(f&&(t=(e=Ae(r,n[0].ownerDocument,!1,n,o)).firstChild,1===e.childNodes.length&&(e=t),t||o)){for(s=(a=ce.map(Se(e,"script"),Ie)).length;c<f;c++)u=e,c!==p&&(u=ce.clone(u,!0,!0),s&&ce.merge(a,Se(u,"script"))),i.call(n[c],u,c);if(s)for(l=a[a.length-1].ownerDocument,ce.map(a,We),c=0;c<s;c++)u=a[c],Ce.test(u.type||"")&&!_.access(u,"globalEval")&&ce.contains(l,u)&&(u.src&&"module"!==(u.type||"").toLowerCase()?ce._evalUrl&&!u.noModule&&ce._evalUrl(u.src,{nonce:u.nonce||u.getAttribute("nonce")},l):m(u.textContent.replace(Me,""),u,l))}return n}function Be(e,t,n){for(var r,i=t?ce.filter(t,e):e,o=0;null!=(r=i[o]);o++)n||1!==r.nodeType||ce.cleanData(Se(r)),r.parentNode&&(n&&K(r)&&Ee(Se(r,"script")),r.parentNode.removeChild(r));return e}ce.extend({htmlPrefilter:function(e){return e},clone:function(e,t,n){var r,i,o,a,s,u,l,c=e.cloneNode(!0),f=K(e);if(!(le.noCloneChecked||1!==e.nodeType&&11!==e.nodeType||ce.isXMLDoc(e)))for(a=Se(c),r=0,i=(o=Se(e)).length;r<i;r++)s=o[r],u=a[r],void 0,"input"===(l=u.nodeName.toLowerCase())&&we.test(s.type)?u.checked=s.checked:"input"!==l&&"textarea"!==l||(u.defaultValue=s.defaultValue);if(t)if(n)for(o=o||Se(e),a=a||Se(c),r=0,i=o.length;r<i;r++)Fe(o[r],a[r]);else Fe(e,c);return 0<(a=Se(c,"script")).length&&Ee(a,!f&&Se(e,"script")),c},cleanData:function(e){for(var t,n,r,i=ce.event.special,o=0;void 0!==(n=e[o]);o++)if($(n)){if(t=n[_.expando]){if(t.events)for(r in t.events)i[r]?ce.event.remove(n,r):ce.removeEvent(n,r,t.handle);n[_.expando]=void 0}n[z.expando]&&(n[z.expando]=void 0)}}}),ce.fn.extend({detach:function(e){return Be(this,e,!0)},remove:function(e){return Be(this,e)},text:function(e){return M(this,function(e){return void 0===e?ce.text(this):this.empty().each(function(){1!==this.nodeType&&11!==this.nodeType&&9!==this.nodeType||(this.textContent=e)})},null,e,arguments.length)},append:function(){return $e(this,arguments,function(e){1!==this.nodeType&&11!==this.nodeType&&9!==this.nodeType||Re(this,e).appendChild(e)})},prepend:function(){return $e(this,arguments,function(e){if(1===this.nodeType||11===this.nodeType||9===this.nodeType){var t=Re(this,e);t.insertBefore(e,t.firstChild)}})},before:function(){return $e(this,arguments,function(e){this.parentNode&&this.parentNode.insertBefore(e,this)})},after:function(){return $e(this,arguments,function(e){this.parentNode&&this.parentNode.insertBefore(e,this.nextSibling)})},empty:function(){for(var e,t=0;null!=(e=this[t]);t++)1===e.nodeType&&(ce.cleanData(Se(e,!1)),e.textContent="");return this},clone:function(e,t){return e=null!=e&&e,t=null==t?e:t,this.map(function(){return ce.clone(this,e,t)})},html:function(e){return M(this,function(e){var t=this[0]||{},n=0,r=this.length;if(void 0===e&&1===t.nodeType)return t.innerHTML;if("string"==typeof e&&!Oe.test(e)&&!ke[(Te.exec(e)||["",""])[1].toLowerCase()]){e=ce.htmlPrefilter(e);try{for(;n<r;n++)1===(t=this[n]||{}).nodeType&&(ce.cleanData(Se(t,!1)),t.innerHTML=e);t=0}catch(e){}}t&&this.empty().append(e)},null,e,arguments.length)},replaceWith:function(){var n=[];return $e(this,arguments,function(e){var t=this.parentNode;ce.inArray(this,n)<0&&(ce.cleanData(Se(this)),t&&t.replaceChild(e,this))},n)}}),ce.each({appendTo:"append",prependTo:"prepend",insertBefore:"before",insertAfter:"after",replaceAll:"replaceWith"},function(e,a){ce.fn[e]=function(e){for(var t,n=[],r=ce(e),i=r.length-1,o=0;o<=i;o++)t=o===i?this:this.clone(!0),ce(r[o])[a](t),s.apply(n,t.get());return this.pushStack(n)}});var _e=new RegExp("^("+G+")(?!px)[a-z%]+$","i"),ze=/^--/,Xe=function(e){var t=e.ownerDocument.defaultView;return t&&t.opener||(t=ie),t.getComputedStyle(e)},Ue=function(e,t,n){var r,i,o={};for(i in t)o[i]=e.style[i],e.style[i]=t[i];for(i in r=n.call(e),t)e.style[i]=o[i];return r},Ve=new RegExp(Q.join("|"),"i");function Ge(e,t,n){var r,i,o,a,s=ze.test(t),u=e.style;return(n=n||Xe(e))&&(a=n.getPropertyValue(t)||n[t],s&&a&&(a=a.replace(ve,"$1")||void 0),""!==a||K(e)||(a=ce.style(e,t)),!le.pixelBoxStyles()&&_e.test(a)&&Ve.test(t)&&(r=u.width,i=u.minWidth,o=u.maxWidth,u.minWidth=u.maxWidth=u.width=a,a=n.width,u.width=r,u.minWidth=i,u.maxWidth=o)),void 0!==a?a+"":a}function Ye(e,t){return{get:function(){if(!e())return(this.get=t).apply(this,arguments);delete this.get}}}!function(){function e(){if(l){u.style.cssText="position:absolute;left:-11111px;width:60px;margin-top:1px;padding:0;border:0",l.style.cssText="position:relative;display:block;box-sizing:border-box;overflow:scroll;margin:auto;border:1px;padding:1px;width:60%;top:1%",J.appendChild(u).appendChild(l);var e=ie.getComputedStyle(l);n="1%"!==e.top,s=12===t(e.marginLeft),l.style.right="60%",o=36===t(e.right),r=36===t(e.width),l.style.position="absolute",i=12===t(l.offsetWidth/3),J.removeChild(u),l=null}}function t(e){return Math.round(parseFloat(e))}var n,r,i,o,a,s,u=C.createElement("div"),l=C.createElement("div");l.style&&(l.style.backgroundClip="content-box",l.cloneNode(!0).style.backgroundClip="",le.clearCloneStyle="content-box"===l.style.backgroundClip,ce.extend(le,{boxSizingReliable:function(){return e(),r},pixelBoxStyles:function(){return e(),o},pixelPosition:function(){return e(),n},reliableMarginLeft:function(){return e(),s},scrollboxSize:function(){return e(),i},reliableTrDimensions:function(){var e,t,n,r;return null==a&&(e=C.createElement("table"),t=C.createElement("tr"),n=C.createElement("div"),e.style.cssText="position:absolute;left:-11111px;border-collapse:separate",t.style.cssText="box-sizing:content-box;border:1px solid",t.style.height="1px",n.style.height="9px",n.style.display="block",J.appendChild(e).appendChild(t).appendChild(n),r=ie.getComputedStyle(t),a=parseInt(r.height,10)+parseInt(r.borderTopWidth,10)+parseInt(r.borderBottomWidth,10)===t.offsetHeight,J.removeChild(e)),a}}))}();var Qe=["Webkit","Moz","ms"],Je=C.createElement("div").style,Ke={};function Ze(e){var t=ce.cssProps[e]||Ke[e];return t||(e in Je?e:Ke[e]=function(e){var t=e[0].toUpperCase()+e.slice(1),n=Qe.length;while(n--)if((e=Qe[n]+t)in Je)return e}(e)||e)}var et=/^(none|table(?!-c[ea]).+)/,tt={position:"absolute",visibility:"hidden",display:"block"},nt={letterSpacing:"0",fontWeight:"400"};function rt(e,t,n){var r=Y.exec(t);return r?Math.max(0,r[2]-(n||0))+(r[3]||"px"):t}function it(e,t,n,r,i,o){var a="width"===t?1:0,s=0,u=0,l=0;if(n===(r?"border":"content"))return 0;for(;a<4;a+=2)"margin"===n&&(l+=ce.css(e,n+Q[a],!0,i)),r?("content"===n&&(u-=ce.css(e,"padding"+Q[a],!0,i)),"margin"!==n&&(u-=ce.css(e,"border"+Q[a]+"Width",!0,i))):(u+=ce.css(e,"padding"+Q[a],!0,i),"padding"!==n?u+=ce.css(e,"border"+Q[a]+"Width",!0,i):s+=ce.css(e,"border"+Q[a]+"Width",!0,i));return!r&&0<=o&&(u+=Math.max(0,Math.ceil(e["offset"+t[0].toUpperCase()+t.slice(1)]-o-u-s-.5))||0),u+l}function ot(e,t,n){var r=Xe(e),i=(!le.boxSizingReliable()||n)&&"border-box"===ce.css(e,"boxSizing",!1,r),o=i,a=Ge(e,t,r),s="offset"+t[0].toUpperCase()+t.slice(1);if(_e.test(a)){if(!n)return a;a="auto"}return(!le.boxSizingReliable()&&i||!le.reliableTrDimensions()&&fe(e,"tr")||"auto"===a||!parseFloat(a)&&"inline"===ce.css(e,"display",!1,r))&&e.getClientRects().length&&(i="border-box"===ce.css(e,"boxSizing",!1,r),(o=s in e)&&(a=e[s])),(a=parseFloat(a)||0)+it(e,t,n||(i?"border":"content"),o,r,a)+"px"}function at(e,t,n,r,i){return new at.prototype.init(e,t,n,r,i)}ce.extend({cssHooks:{opacity:{get:function(e,t){if(t){var n=Ge(e,"opacity");return""===n?"1":n}}}},cssNumber:{animationIterationCount:!0,aspectRatio:!0,borderImageSlice:!0,columnCount:!0,flexGrow:!0,flexShrink:!0,fontWeight:!0,gridArea:!0,gridColumn:!0,gridColumnEnd:!0,gridColumnStart:!0,gridRow:!0,gridRowEnd:!0,gridRowStart:!0,lineHeight:!0,opacity:!0,order:!0,orphans:!0,scale:!0,widows:!0,zIndex:!0,zoom:!0,fillOpacity:!0,floodOpacity:!0,stopOpacity:!0,strokeMiterlimit:!0,strokeOpacity:!0},cssProps:{},style:function(e,t,n,r){if(e&&3!==e.nodeType&&8!==e.nodeType&&e.style){var i,o,a,s=F(t),u=ze.test(t),l=e.style;if(u||(t=Ze(s)),a=ce.cssHooks[t]||ce.cssHooks[s],void 0===n)return a&&"get"in a&&void 0!==(i=a.get(e,!1,r))?i:l[t];"string"===(o=typeof n)&&(i=Y.exec(n))&&i[1]&&(n=te(e,t,i),o="number"),null!=n&&n==n&&("number"!==o||u||(n+=i&&i[3]||(ce.cssNumber[s]?"":"px")),le.clearCloneStyle||""!==n||0!==t.indexOf("background")||(l[t]="inherit"),a&&"set"in a&&void 0===(n=a.set(e,n,r))||(u?l.setProperty(t,n):l[t]=n))}},css:function(e,t,n,r){var i,o,a,s=F(t);return ze.test(t)||(t=Ze(s)),(a=ce.cssHooks[t]||ce.cssHooks[s])&&"get"in a&&(i=a.get(e,!0,n)),void 0===i&&(i=Ge(e,t,r)),"normal"===i&&t in nt&&(i=nt[t]),""===n||n?(o=parseFloat(i),!0===n||isFinite(o)?o||0:i):i}}),ce.each(["height","width"],function(e,u){ce.cssHooks[u]={get:function(e,t,n){if(t)return!et.test(ce.css(e,"display"))||e.getClientRects().length&&e.getBoundingClientRect().width?ot(e,u,n):Ue(e,tt,function(){return ot(e,u,n)})},set:function(e,t,n){var r,i=Xe(e),o=!le.scrollboxSize()&&"absolute"===i.position,a=(o||n)&&"border-box"===ce.css(e,"boxSizing",!1,i),s=n?it(e,u,n,a,i):0;return a&&o&&(s-=Math.ceil(e["offset"+u[0].toUpperCase()+u.slice(1)]-parseFloat(i[u])-it(e,u,"border",!1,i)-.5)),s&&(r=Y.exec(t))&&"px"!==(r[3]||"px")&&(e.style[u]=t,t=ce.css(e,u)),rt(0,t,s)}}}),ce.cssHooks.marginLeft=Ye(le.reliableMarginLeft,function(e,t){if(t)return(parseFloat(Ge(e,"marginLeft"))||e.getBoundingClientRect().left-Ue(e,{marginLeft:0},function(){return e.getBoundingClientRect().left}))+"px"}),ce.each({margin:"",padding:"",border:"Width"},function(i,o){ce.cssHooks[i+o]={expand:function(e){for(var t=0,n={},r="string"==typeof e?e.split(" "):[e];t<4;t++)n[i+Q[t]+o]=r[t]||r[t-2]||r[0];return n}},"margin"!==i&&(ce.cssHooks[i+o].set=rt)}),ce.fn.extend({css:function(e,t){return M(this,function(e,t,n){var r,i,o={},a=0;if(Array.isArray(t)){for(r=Xe(e),i=t.length;a<i;a++)o[t[a]]=ce.css(e,t[a],!1,r);return o}return void 0!==n?ce.style(e,t,n):ce.css(e,t)},e,t,1<arguments.length)}}),((ce.Tween=at).prototype={constructor:at,init:function(e,t,n,r,i,o){this.elem=e,this.prop=n,this.easing=i||ce.easing._default,this.options=t,this.start=this.now=this.cur(),this.end=r,this.unit=o||(ce.cssNumber[n]?"":"px")},cur:function(){var e=at.propHooks[this.prop];return e&&e.get?e.get(this):at.propHooks._default.get(this)},run:function(e){var t,n=at.propHooks[this.prop];return this.options.duration?this.pos=t=ce.easing[this.easing](e,this.options.duration*e,0,1,this.options.duration):this.pos=t=e,this.now=(this.end-this.start)*t+this.start,this.options.step&&this.options.step.call(this.elem,this.now,this),n&&n.set?n.set(this):at.propHooks._default.set(this),this}}).init.prototype=at.prototype,(at.propHooks={_default:{get:function(e){var t;return 1!==e.elem.nodeType||null!=e.elem[e.prop]&&null==e.elem.style[e.prop]?e.elem[e.prop]:(t=ce.css(e.elem,e.prop,""))&&"auto"!==t?t:0},set:function(e){ce.fx.step[e.prop]?ce.fx.step[e.prop](e):1!==e.elem.nodeType||!ce.cssHooks[e.prop]&&null==e.elem.style[Ze(e.prop)]?e.elem[e.prop]=e.now:ce.style(e.elem,e.prop,e.now+e.unit)}}}).scrollTop=at.propHooks.scrollLeft={set:function(e){e.elem.nodeType&&e.elem.parentNode&&(e.elem[e.prop]=e.now)}},ce.easing={linear:function(e){return e},swing:function(e){return.5-Math.cos(e*Math.PI)/2},_default:"swing"},ce.fx=at.prototype.init,ce.fx.step={};var st,ut,lt,ct,ft=/^(?:toggle|show|hide)$/,pt=/queueHooks$/;function dt(){ut&&(!1===C.hidden&&ie.requestAnimationFrame?ie.requestAnimationFrame(dt):ie.setTimeout(dt,ce.fx.interval),ce.fx.tick())}function ht(){return ie.setTimeout(function(){st=void 0}),st=Date.now()}function gt(e,t){var n,r=0,i={height:e};for(t=t?1:0;r<4;r+=2-t)i["margin"+(n=Q[r])]=i["padding"+n]=e;return t&&(i.opacity=i.width=e),i}function vt(e,t,n){for(var r,i=(yt.tweeners[t]||[]).concat(yt.tweeners["*"]),o=0,a=i.length;o<a;o++)if(r=i[o].call(n,t,e))return r}function yt(o,e,t){var n,a,r=0,i=yt.prefilters.length,s=ce.Deferred().always(function(){delete u.elem}),u=function(){if(a)return!1;for(var e=st||ht(),t=Math.max(0,l.startTime+l.duration-e),n=1-(t/l.duration||0),r=0,i=l.tweens.length;r<i;r++)l.tweens[r].run(n);return s.notifyWith(o,[l,n,t]),n<1&&i?t:(i||s.notifyWith(o,[l,1,0]),s.resolveWith(o,[l]),!1)},l=s.promise({elem:o,props:ce.extend({},e),opts:ce.extend(!0,{specialEasing:{},easing:ce.easing._default},t),originalProperties:e,originalOptions:t,startTime:st||ht(),duration:t.duration,tweens:[],createTween:function(e,t){var n=ce.Tween(o,l.opts,e,t,l.opts.specialEasing[e]||l.opts.easing);return l.tweens.push(n),n},stop:function(e){var t=0,n=e?l.tweens.length:0;if(a)return this;for(a=!0;t<n;t++)l.tweens[t].run(1);return e?(s.notifyWith(o,[l,1,0]),s.resolveWith(o,[l,e])):s.rejectWith(o,[l,e]),this}}),c=l.props;for(!function(e,t){var n,r,i,o,a;for(n in e)if(i=t[r=F(n)],o=e[n],Array.isArray(o)&&(i=o[1],o=e[n]=o[0]),n!==r&&(e[r]=o,delete e[n]),(a=ce.cssHooks[r])&&"expand"in a)for(n in o=a.expand(o),delete e[r],o)n in e||(e[n]=o[n],t[n]=i);else t[r]=i}(c,l.opts.specialEasing);r<i;r++)if(n=yt.prefilters[r].call(l,o,c,l.opts))return v(n.stop)&&(ce._queueHooks(l.elem,l.opts.queue).stop=n.stop.bind(n)),n;return ce.map(c,vt,l),v(l.opts.start)&&l.opts.start.call(o,l),l.progress(l.opts.progress).done(l.opts.done,l.opts.complete).fail(l.opts.fail).always(l.opts.always),ce.fx.timer(ce.extend(u,{elem:o,anim:l,queue:l.opts.queue})),l}ce.Animation=ce.extend(yt,{tweeners:{"*":[function(e,t){var n=this.createTween(e,t);return te(n.elem,e,Y.exec(t),n),n}]},tweener:function(e,t){v(e)?(t=e,e=["*"]):e=e.match(D);for(var n,r=0,i=e.length;r<i;r++)n=e[r],yt.tweeners[n]=yt.tweeners[n]||[],yt.tweeners[n].unshift(t)},prefilters:[function(e,t,n){var r,i,o,a,s,u,l,c,f="width"in t||"height"in t,p=this,d={},h=e.style,g=e.nodeType&&ee(e),v=_.get(e,"fxshow");for(r in n.queue||(null==(a=ce._queueHooks(e,"fx")).unqueued&&(a.unqueued=0,s=a.empty.fire,a.empty.fire=function(){a.unqueued||s()}),a.unqueued++,p.always(function(){p.always(function(){a.unqueued--,ce.queue(e,"fx").length||a.empty.fire()})})),t)if(i=t[r],ft.test(i)){if(delete t[r],o=o||"toggle"===i,i===(g?"hide":"show")){if("show"!==i||!v||void 0===v[r])continue;g=!0}d[r]=v&&v[r]||ce.style(e,r)}if((u=!ce.isEmptyObject(t))||!ce.isEmptyObject(d))for(r in f&&1===e.nodeType&&(n.overflow=[h.overflow,h.overflowX,h.overflowY],null==(l=v&&v.display)&&(l=_.get(e,"display")),"none"===(c=ce.css(e,"display"))&&(l?c=l:(re([e],!0),l=e.style.display||l,c=ce.css(e,"display"),re([e]))),("inline"===c||"inline-block"===c&&null!=l)&&"none"===ce.css(e,"float")&&(u||(p.done(function(){h.display=l}),null==l&&(c=h.display,l="none"===c?"":c)),h.display="inline-block")),n.overflow&&(h.overflow="hidden",p.always(function(){h.overflow=n.overflow[0],h.overflowX=n.overflow[1],h.overflowY=n.overflow[2]})),u=!1,d)u||(v?"hidden"in v&&(g=v.hidden):v=_.access(e,"fxshow",{display:l}),o&&(v.hidden=!g),g&&re([e],!0),p.done(function(){for(r in g||re([e]),_.remove(e,"fxshow"),d)ce.style(e,r,d[r])})),u=vt(g?v[r]:0,r,p),r in v||(v[r]=u.start,g&&(u.end=u.start,u.start=0))}],prefilter:function(e,t){t?yt.prefilters.unshift(e):yt.prefilters.push(e)}}),ce.speed=function(e,t,n){var r=e&&"object"==typeof e?ce.extend({},e):{complete:n||!n&&t||v(e)&&e,duration:e,easing:n&&t||t&&!v(t)&&t};return ce.fx.off?r.duration=0:"number"!=typeof r.duration&&(r.duration in ce.fx.speeds?r.duration=ce.fx.speeds[r.duration]:r.duration=ce.fx.speeds._default),null!=r.queue&&!0!==r.queue||(r.queue="fx"),r.old=r.complete,r.complete=function(){v(r.old)&&r.old.call(this),r.queue&&ce.dequeue(this,r.queue)},r},ce.fn.extend({fadeTo:function(e,t,n,r){return this.filter(ee).css("opacity",0).show().end().animate({opacity:t},e,n,r)},animate:function(t,e,n,r){var i=ce.isEmptyObject(t),o=ce.speed(e,n,r),a=function(){var e=yt(this,ce.extend({},t),o);(i||_.get(this,"finish"))&&e.stop(!0)};return a.finish=a,i||!1===o.queue?this.each(a):this.queue(o.queue,a)},stop:function(i,e,o){var a=function(e){var t=e.stop;delete e.stop,t(o)};return"string"!=typeof i&&(o=e,e=i,i=void 0),e&&this.queue(i||"fx",[]),this.each(function(){var e=!0,t=null!=i&&i+"queueHooks",n=ce.timers,r=_.get(this);if(t)r[t]&&r[t].stop&&a(r[t]);else for(t in r)r[t]&&r[t].stop&&pt.test(t)&&a(r[t]);for(t=n.length;t--;)n[t].elem!==this||null!=i&&n[t].queue!==i||(n[t].anim.stop(o),e=!1,n.splice(t,1));!e&&o||ce.dequeue(this,i)})},finish:function(a){return!1!==a&&(a=a||"fx"),this.each(function(){var e,t=_.get(this),n=t[a+"queue"],r=t[a+"queueHooks"],i=ce.timers,o=n?n.length:0;for(t.finish=!0,ce.queue(this,a,[]),r&&r.stop&&r.stop.call(this,!0),e=i.length;e--;)i[e].elem===this&&i[e].queue===a&&(i[e].anim.stop(!0),i.splice(e,1));for(e=0;e<o;e++)n[e]&&n[e].finish&&n[e].finish.call(this);delete t.finish})}}),ce.each(["toggle","show","hide"],function(e,r){var i=ce.fn[r];ce.fn[r]=function(e,t,n){return null==e||"boolean"==typeof e?i.apply(this,arguments):this.animate(gt(r,!0),e,t,n)}}),ce.each({slideDown:gt("show"),slideUp:gt("hide"),slideToggle:gt("toggle"),fadeIn:{opacity:"show"},fadeOut:{opacity:"hide"},fadeToggle:{opacity:"toggle"}},function(e,r){ce.fn[e]=function(e,t,n){return this.animate(r,e,t,n)}}),ce.timers=[],ce.fx.tick=function(){var e,t=0,n=ce.timers;for(st=Date.now();t<n.length;t++)(e=n[t])()||n[t]!==e||n.splice(t--,1);n.length||ce.fx.stop(),st=void 0},ce.fx.timer=function(e){ce.timers.push(e),ce.fx.start()},ce.fx.interval=13,ce.fx.start=function(){ut||(ut=!0,dt())},ce.fx.stop=function(){ut=null},ce.fx.speeds={slow:600,fast:200,_default:400},ce.fn.delay=function(r,e){return r=ce.fx&&ce.fx.speeds[r]||r,e=e||"fx",this.queue(e,function(e,t){var n=ie.setTimeout(e,r);t.stop=function(){ie.clearTimeout(n)}})},lt=C.createElement("input"),ct=C.createElement("select").appendChild(C.createElement("option")),lt.type="checkbox",le.checkOn=""!==lt.value,le.optSelected=ct.selected,(lt=C.createElement("input")).value="t",lt.type="radio",le.radioValue="t"===lt.value;var mt,xt=ce.expr.attrHandle;ce.fn.extend({attr:function(e,t){return M(this,ce.attr,e,t,1<arguments.length)},removeAttr:function(e){return this.each(function(){ce.removeAttr(this,e)})}}),ce.extend({attr:function(e,t,n){var r,i,o=e.nodeType;if(3!==o&&8!==o&&2!==o)return"undefined"==typeof e.getAttribute?ce.prop(e,t,n):(1===o&&ce.isXMLDoc(e)||(i=ce.attrHooks[t.toLowerCase()]||(ce.expr.match.bool.test(t)?mt:void 0)),void 0!==n?null===n?void ce.removeAttr(e,t):i&&"set"in i&&void 0!==(r=i.set(e,n,t))?r:(e.setAttribute(t,n+""),n):i&&"get"in i&&null!==(r=i.get(e,t))?r:null==(r=ce.find.attr(e,t))?void 0:r)},attrHooks:{type:{set:function(e,t){if(!le.radioValue&&"radio"===t&&fe(e,"input")){var n=e.value;return e.setAttribute("type",t),n&&(e.value=n),t}}}},removeAttr:function(e,t){var n,r=0,i=t&&t.match(D);if(i&&1===e.nodeType)while(n=i[r++])e.removeAttribute(n)}}),mt={set:function(e,t,n){return!1===t?ce.removeAttr(e,n):e.setAttribute(n,n),n}},ce.each(ce.expr.match.bool.source.match(/\w+/g),function(e,t){var a=xt[t]||ce.find.attr;xt[t]=function(e,t,n){var r,i,o=t.toLowerCase();return n||(i=xt[o],xt[o]=r,r=null!=a(e,t,n)?o:null,xt[o]=i),r}});var bt=/^(?:input|select|textarea|button)$/i,wt=/^(?:a|area)$/i;function Tt(e){return(e.match(D)||[]).join(" ")}function Ct(e){return e.getAttribute&&e.getAttribute("class")||""}function kt(e){return Array.isArray(e)?e:"string"==typeof e&&e.match(D)||[]}ce.fn.extend({prop:function(e,t){return M(this,ce.prop,e,t,1<arguments.length)},removeProp:function(e){return this.each(function(){delete this[ce.propFix[e]||e]})}}),ce.extend({prop:function(e,t,n){var r,i,o=e.nodeType;if(3!==o&&8!==o&&2!==o)return 1===o&&ce.isXMLDoc(e)||(t=ce.propFix[t]||t,i=ce.propHooks[t]),void 0!==n?i&&"set"in i&&void 0!==(r=i.set(e,n,t))?r:e[t]=n:i&&"get"in i&&null!==(r=i.get(e,t))?r:e[t]},propHooks:{tabIndex:{get:function(e){var t=ce.find.attr(e,"tabindex");return t?parseInt(t,10):bt.test(e.nodeName)||wt.test(e.nodeName)&&e.href?0:-1}}},propFix:{"for":"htmlFor","class":"className"}}),le.optSelected||(ce.propHooks.selected={get:function(e){var t=e.parentNode;return t&&t.parentNode&&t.parentNode.selectedIndex,null},set:function(e){var t=e.parentNode;t&&(t.selectedIndex,t.parentNode&&t.parentNode.selectedIndex)}}),ce.each(["tabIndex","readOnly","maxLength","cellSpacing","cellPadding","rowSpan","colSpan","useMap","frameBorder","contentEditable"],function(){ce.propFix[this.toLowerCase()]=this}),ce.fn.extend({addClass:function(t){var e,n,r,i,o,a;return v(t)?this.each(function(e){ce(this).addClass(t.call(this,e,Ct(this)))}):(e=kt(t)).length?this.each(function(){if(r=Ct(this),n=1===this.nodeType&&" "+Tt(r)+" "){for(o=0;o<e.length;o++)i=e[o],n.indexOf(" "+i+" ")<0&&(n+=i+" ");a=Tt(n),r!==a&&this.setAttribute("class",a)}}):this},removeClass:function(t){var e,n,r,i,o,a;return v(t)?this.each(function(e){ce(this).removeClass(t.call(this,e,Ct(this)))}):arguments.length?(e=kt(t)).length?this.each(function(){if(r=Ct(this),n=1===this.nodeType&&" "+Tt(r)+" "){for(o=0;o<e.length;o++){i=e[o];while(-1<n.indexOf(" "+i+" "))n=n.replace(" "+i+" "," ")}a=Tt(n),r!==a&&this.setAttribute("class",a)}}):this:this.attr("class","")},toggleClass:function(t,n){var e,r,i,o,a=typeof t,s="string"===a||Array.isArray(t);return v(t)?this.each(function(e){ce(this).toggleClass(t.call(this,e,Ct(this),n),n)}):"boolean"==typeof n&&s?n?this.addClass(t):this.removeClass(t):(e=kt(t),this.each(function(){if(s)for(o=ce(this),i=0;i<e.length;i++)r=e[i],o.hasClass(r)?o.removeClass(r):o.addClass(r);else void 0!==t&&"boolean"!==a||((r=Ct(this))&&_.set(this,"__className__",r),this.setAttribute&&this.setAttribute("class",r||!1===t?"":_.get(this,"__className__")||""))}))},hasClass:function(e){var t,n,r=0;t=" "+e+" ";while(n=this[r++])if(1===n.nodeType&&-1<(" "+Tt(Ct(n))+" ").indexOf(t))return!0;return!1}});var St=/\r/g;ce.fn.extend({val:function(n){var r,e,i,t=this[0];return arguments.length?(i=v(n),this.each(function(e){var t;1===this.nodeType&&(null==(t=i?n.call(this,e,ce(this).val()):n)?t="":"number"==typeof t?t+="":Array.isArray(t)&&(t=ce.map(t,function(e){return null==e?"":e+""})),(r=ce.valHooks[this.type]||ce.valHooks[this.nodeName.toLowerCase()])&&"set"in r&&void 0!==r.set(this,t,"value")||(this.value=t))})):t?(r=ce.valHooks[t.type]||ce.valHooks[t.nodeName.toLowerCase()])&&"get"in r&&void 0!==(e=r.get(t,"value"))?e:"string"==typeof(e=t.value)?e.replace(St,""):null==e?"":e:void 0}}),ce.extend({valHooks:{option:{get:function(e){var t=ce.find.attr(e,"value");return null!=t?t:Tt(ce.text(e))}},select:{get:function(e){var t,n,r,i=e.options,o=e.selectedIndex,a="select-one"===e.type,s=a?null:[],u=a?o+1:i.length;for(r=o<0?u:a?o:0;r<u;r++)if(((n=i[r]).selected||r===o)&&!n.disabled&&(!n.parentNode.disabled||!fe(n.parentNode,"optgroup"))){if(t=ce(n).val(),a)return t;s.push(t)}return s},set:function(e,t){var n,r,i=e.options,o=ce.makeArray(t),a=i.length;while(a--)((r=i[a]).selected=-1<ce.inArray(ce.valHooks.option.get(r),o))&&(n=!0);return n||(e.selectedIndex=-1),o}}}}),ce.each(["radio","checkbox"],function(){ce.valHooks[this]={set:function(e,t){if(Array.isArray(t))return e.checked=-1<ce.inArray(ce(e).val(),t)}},le.checkOn||(ce.valHooks[this].get=function(e){return null===e.getAttribute("value")?"on":e.value})});var Et=ie.location,jt={guid:Date.now()},At=/\?/;ce.parseXML=function(e){var t,n;if(!e||"string"!=typeof e)return null;try{t=(new ie.DOMParser).parseFromString(e,"text/xml")}catch(e){}return n=t&&t.getElementsByTagName("parsererror")[0],t&&!n||ce.error("Invalid XML: "+(n?ce.map(n.childNodes,function(e){return e.textContent}).join("\n"):e)),t};var Dt=/^(?:focusinfocus|focusoutblur)$/,Nt=function(e){e.stopPropagation()};ce.extend(ce.event,{trigger:function(e,t,n,r){var i,o,a,s,u,l,c,f,p=[n||C],d=ue.call(e,"type")?e.type:e,h=ue.call(e,"namespace")?e.namespace.split("."):[];if(o=f=a=n=n||C,3!==n.nodeType&&8!==n.nodeType&&!Dt.test(d+ce.event.triggered)&&(-1<d.indexOf(".")&&(d=(h=d.split(".")).shift(),h.sort()),u=d.indexOf(":")<0&&"on"+d,(e=e[ce.expando]?e:new ce.Event(d,"object"==typeof e&&e)).isTrigger=r?2:3,e.namespace=h.join("."),e.rnamespace=e.namespace?new RegExp("(^|\\.)"+h.join("\\.(?:.*\\.|)")+"(\\.|$)"):null,e.result=void 0,e.target||(e.target=n),t=null==t?[e]:ce.makeArray(t,[e]),c=ce.event.special[d]||{},r||!c.trigger||!1!==c.trigger.apply(n,t))){if(!r&&!c.noBubble&&!y(n)){for(s=c.delegateType||d,Dt.test(s+d)||(o=o.parentNode);o;o=o.parentNode)p.push(o),a=o;a===(n.ownerDocument||C)&&p.push(a.defaultView||a.parentWindow||ie)}i=0;while((o=p[i++])&&!e.isPropagationStopped())f=o,e.type=1<i?s:c.bindType||d,(l=(_.get(o,"events")||Object.create(null))[e.type]&&_.get(o,"handle"))&&l.apply(o,t),(l=u&&o[u])&&l.apply&&$(o)&&(e.result=l.apply(o,t),!1===e.result&&e.preventDefault());return e.type=d,r||e.isDefaultPrevented()||c._default&&!1!==c._default.apply(p.pop(),t)||!$(n)||u&&v(n[d])&&!y(n)&&((a=n[u])&&(n[u]=null),ce.event.triggered=d,e.isPropagationStopped()&&f.addEventListener(d,Nt),n[d](),e.isPropagationStopped()&&f.removeEventListener(d,Nt),ce.event.triggered=void 0,a&&(n[u]=a)),e.result}},simulate:function(e,t,n){var r=ce.extend(new ce.Event,n,{type:e,isSimulated:!0});ce.event.trigger(r,null,t)}}),ce.fn.extend({trigger:function(e,t){return this.each(function(){ce.event.trigger(e,t,this)})},triggerHandler:function(e,t){var n=this[0];if(n)return ce.event.trigger(e,t,n,!0)}});var qt=/\[\]$/,Lt=/\r?\n/g,Ht=/^(?:submit|button|image|reset|file)$/i,Ot=/^(?:input|select|textarea|keygen)/i;function Pt(n,e,r,i){var t;if(Array.isArray(e))ce.each(e,function(e,t){r||qt.test(n)?i(n,t):Pt(n+"["+("object"==typeof t&&null!=t?e:"")+"]",t,r,i)});else if(r||"object"!==x(e))i(n,e);else for(t in e)Pt(n+"["+t+"]",e[t],r,i)}ce.param=function(e,t){var n,r=[],i=function(e,t){var n=v(t)?t():t;r[r.length]=encodeURIComponent(e)+"="+encodeURIComponent(null==n?"":n)};if(null==e)return"";if(Array.isArray(e)||e.jquery&&!ce.isPlainObject(e))ce.each(e,function(){i(this.name,this.value)});else for(n in e)Pt(n,e[n],t,i);return r.join("&")},ce.fn.extend({serialize:function(){return ce.param(this.serializeArray())},serializeArray:function(){return this.map(function(){var e=ce.prop(this,"elements");return e?ce.makeArray(e):this}).filter(function(){var e=this.type;return this.name&&!ce(this).is(":disabled")&&Ot.test(this.nodeName)&&!Ht.test(e)&&(this.checked||!we.test(e))}).map(function(e,t){var n=ce(this).val();return null==n?null:Array.isArray(n)?ce.map(n,function(e){return{name:t.name,value:e.replace(Lt,"\r\n")}}):{name:t.name,value:n.replace(Lt,"\r\n")}}).get()}});var Mt=/%20/g,Rt=/#.*$/,It=/([?&])_=[^&]*/,Wt=/^(.*?):[ \t]*([^\r\n]*)$/gm,Ft=/^(?:GET|HEAD)$/,$t=/^\/\//,Bt={},_t={},zt="*/".concat("*"),Xt=C.createElement("a");function Ut(o){return function(e,t){"string"!=typeof e&&(t=e,e="*");var n,r=0,i=e.toLowerCase().match(D)||[];if(v(t))while(n=i[r++])"+"===n[0]?(n=n.slice(1)||"*",(o[n]=o[n]||[]).unshift(t)):(o[n]=o[n]||[]).push(t)}}function Vt(t,i,o,a){var s={},u=t===_t;function l(e){var r;return s[e]=!0,ce.each(t[e]||[],function(e,t){var n=t(i,o,a);return"string"!=typeof n||u||s[n]?u?!(r=n):void 0:(i.dataTypes.unshift(n),l(n),!1)}),r}return l(i.dataTypes[0])||!s["*"]&&l("*")}function Gt(e,t){var n,r,i=ce.ajaxSettings.flatOptions||{};for(n in t)void 0!==t[n]&&((i[n]?e:r||(r={}))[n]=t[n]);return r&&ce.extend(!0,e,r),e}Xt.href=Et.href,ce.extend({active:0,lastModified:{},etag:{},ajaxSettings:{url:Et.href,type:"GET",isLocal:/^(?:about|app|app-storage|.+-extension|file|res|widget):$/.test(Et.protocol),global:!0,processData:!0,async:!0,contentType:"application/x-www-form-urlencoded; charset=UTF-8",accepts:{"*":zt,text:"text/plain",html:"text/html",xml:"application/xml, text/xml",json:"application/json, text/javascript"},contents:{xml:/\bxml\b/,html:/\bhtml/,json:/\bjson\b/},responseFields:{xml:"responseXML",text:"responseText",json:"responseJSON"},converters:{"* text":String,"text html":!0,"text json":JSON.parse,"text xml":ce.parseXML},flatOptions:{url:!0,context:!0}},ajaxSetup:function(e,t){return t?Gt(Gt(e,ce.ajaxSettings),t):Gt(ce.ajaxSettings,e)},ajaxPrefilter:Ut(Bt),ajaxTransport:Ut(_t),ajax:function(e,t){"object"==typeof e&&(t=e,e=void 0),t=t||{};var c,f,p,n,d,r,h,g,i,o,v=ce.ajaxSetup({},t),y=v.context||v,m=v.context&&(y.nodeType||y.jquery)?ce(y):ce.event,x=ce.Deferred(),b=ce.Callbacks("once memory"),w=v.statusCode||{},a={},s={},u="canceled",T={readyState:0,getResponseHeader:function(e){var t;if(h){if(!n){n={};while(t=Wt.exec(p))n[t[1].toLowerCase()+" "]=(n[t[1].toLowerCase()+" "]||[]).concat(t[2])}t=n[e.toLowerCase()+" "]}return null==t?null:t.join(", ")},getAllResponseHeaders:function(){return h?p:null},setRequestHeader:function(e,t){return null==h&&(e=s[e.toLowerCase()]=s[e.toLowerCase()]||e,a[e]=t),this},overrideMimeType:function(e){return null==h&&(v.mimeType=e),this},statusCode:function(e){var t;if(e)if(h)T.always(e[T.status]);else for(t in e)w[t]=[w[t],e[t]];return this},abort:function(e){var t=e||u;return c&&c.abort(t),l(0,t),this}};if(x.promise(T),v.url=((e||v.url||Et.href)+"").replace($t,Et.protocol+"//"),v.type=t.method||t.type||v.method||v.type,v.dataTypes=(v.dataType||"*").toLowerCase().match(D)||[""],null==v.crossDomain){r=C.createElement("a");try{r.href=v.url,r.href=r.href,v.crossDomain=Xt.protocol+"//"+Xt.host!=r.protocol+"//"+r.host}catch(e){v.crossDomain=!0}}if(v.data&&v.processData&&"string"!=typeof v.data&&(v.data=ce.param(v.data,v.traditional)),Vt(Bt,v,t,T),h)return T;for(i in(g=ce.event&&v.global)&&0==ce.active++&&ce.event.trigger("ajaxStart"),v.type=v.type.toUpperCase(),v.hasContent=!Ft.test(v.type),f=v.url.replace(Rt,""),v.hasContent?v.data&&v.processData&&0===(v.contentType||"").indexOf("application/x-www-form-urlencoded")&&(v.data=v.data.replace(Mt,"+")):(o=v.url.slice(f.length),v.data&&(v.processData||"string"==typeof v.data)&&(f+=(At.test(f)?"&":"?")+v.data,delete v.data),!1===v.cache&&(f=f.replace(It,"$1"),o=(At.test(f)?"&":"?")+"_="+jt.guid+++o),v.url=f+o),v.ifModified&&(ce.lastModified[f]&&T.setRequestHeader("If-Modified-Since",ce.lastModified[f]),ce.etag[f]&&T.setRequestHeader("If-None-Match",ce.etag[f])),(v.data&&v.hasContent&&!1!==v.contentType||t.contentType)&&T.setRequestHeader("Content-Type",v.contentType),T.setRequestHeader("Accept",v.dataTypes[0]&&v.accepts[v.dataTypes[0]]?v.accepts[v.dataTypes[0]]+("*"!==v.dataTypes[0]?", "+zt+"; q=0.01":""):v.accepts["*"]),v.headers)T.setRequestHeader(i,v.headers[i]);if(v.beforeSend&&(!1===v.beforeSend.call(y,T,v)||h))return T.abort();if(u="abort",b.add(v.complete),T.done(v.success),T.fail(v.error),c=Vt(_t,v,t,T)){if(T.readyState=1,g&&m.trigger("ajaxSend",[T,v]),h)return T;v.async&&0<v.timeout&&(d=ie.setTimeout(function(){T.abort("timeout")},v.timeout));try{h=!1,c.send(a,l)}catch(e){if(h)throw e;l(-1,e)}}else l(-1,"No Transport");function l(e,t,n,r){var i,o,a,s,u,l=t;h||(h=!0,d&&ie.clearTimeout(d),c=void 0,p=r||"",T.readyState=0<e?4:0,i=200<=e&&e<300||304===e,n&&(s=function(e,t,n){var r,i,o,a,s=e.contents,u=e.dataTypes;while("*"===u[0])u.shift(),void 0===r&&(r=e.mimeType||t.getResponseHeader("Content-Type"));if(r)for(i in s)if(s[i]&&s[i].test(r)){u.unshift(i);break}if(u[0]in n)o=u[0];else{for(i in n){if(!u[0]||e.converters[i+" "+u[0]]){o=i;break}a||(a=i)}o=o||a}if(o)return o!==u[0]&&u.unshift(o),n[o]}(v,T,n)),!i&&-1<ce.inArray("script",v.dataTypes)&&ce.inArray("json",v.dataTypes)<0&&(v.converters["text script"]=function(){}),s=function(e,t,n,r){var i,o,a,s,u,l={},c=e.dataTypes.slice();if(c[1])for(a in e.converters)l[a.toLowerCase()]=e.converters[a];o=c.shift();while(o)if(e.responseFields[o]&&(n[e.responseFields[o]]=t),!u&&r&&e.dataFilter&&(t=e.dataFilter(t,e.dataType)),u=o,o=c.shift())if("*"===o)o=u;else if("*"!==u&&u!==o){if(!(a=l[u+" "+o]||l["* "+o]))for(i in l)if((s=i.split(" "))[1]===o&&(a=l[u+" "+s[0]]||l["* "+s[0]])){!0===a?a=l[i]:!0!==l[i]&&(o=s[0],c.unshift(s[1]));break}if(!0!==a)if(a&&e["throws"])t=a(t);else try{t=a(t)}catch(e){return{state:"parsererror",error:a?e:"No conversion from "+u+" to "+o}}}return{state:"success",data:t}}(v,s,T,i),i?(v.ifModified&&((u=T.getResponseHeader("Last-Modified"))&&(ce.lastModified[f]=u),(u=T.getResponseHeader("etag"))&&(ce.etag[f]=u)),204===e||"HEAD"===v.type?l="nocontent":304===e?l="notmodified":(l=s.state,o=s.data,i=!(a=s.error))):(a=l,!e&&l||(l="error",e<0&&(e=0))),T.status=e,T.statusText=(t||l)+"",i?x.resolveWith(y,[o,l,T]):x.rejectWith(y,[T,l,a]),T.statusCode(w),w=void 0,g&&m.trigger(i?"ajaxSuccess":"ajaxError",[T,v,i?o:a]),b.fireWith(y,[T,l]),g&&(m.trigger("ajaxComplete",[T,v]),--ce.active||ce.event.trigger("ajaxStop")))}return T},getJSON:function(e,t,n){return ce.get(e,t,n,"json")},getScript:function(e,t){return ce.get(e,void 0,t,"script")}}),ce.each(["get","post"],function(e,i){ce[i]=function(e,t,n,r){return v(t)&&(r=r||n,n=t,t=void 0),ce.ajax(ce.extend({url:e,type:i,dataType:r,data:t,success:n},ce.isPlainObject(e)&&e))}}),ce.ajaxPrefilter(function(e){var t;for(t in e.headers)"content-type"===t.toLowerCase()&&(e.contentType=e.headers[t]||"")}),ce._evalUrl=function(e,t,n){return ce.ajax({url:e,type:"GET",dataType:"script",cache:!0,async:!1,global:!1,converters:{"text script":function(){}},dataFilter:function(e){ce.globalEval(e,t,n)}})},ce.fn.extend({wrapAll:function(e){var t;return this[0]&&(v(e)&&(e=e.call(this[0])),t=ce(e,this[0].ownerDocument).eq(0).clone(!0),this[0].parentNode&&t.insertBefore(this[0]),t.map(function(){var e=this;while(e.firstElementChild)e=e.firstElementChild;return e}).append(this)),this},wrapInner:function(n){return v(n)?this.each(function(e){ce(this).wrapInner(n.call(this,e))}):this.each(function(){var e=ce(this),t=e.contents();t.length?t.wrapAll(n):e.append(n)})},wrap:function(t){var n=v(t);return this.each(function(e){ce(this).wrapAll(n?t.call(this,e):t)})},unwrap:function(e){return this.parent(e).not("body").each(function(){ce(this).replaceWith(this.childNodes)}),this}}),ce.expr.pseudos.hidden=function(e){return!ce.expr.pseudos.visible(e)},ce.expr.pseudos.visible=function(e){return!!(e.offsetWidth||e.offsetHeight||e.getClientRects().length)},ce.ajaxSettings.xhr=function(){try{return new ie.XMLHttpRequest}catch(e){}};var Yt={0:200,1223:204},Qt=ce.ajaxSettings.xhr();le.cors=!!Qt&&"withCredentials"in Qt,le.ajax=Qt=!!Qt,ce.ajaxTransport(function(i){var o,a;if(le.cors||Qt&&!i.crossDomain)return{send:function(e,t){var n,r=i.xhr();if(r.open(i.type,i.url,i.async,i.username,i.password),i.xhrFields)for(n in i.xhrFields)r[n]=i.xhrFields[n];for(n in i.mimeType&&r.overrideMimeType&&r.overrideMimeType(i.mimeType),i.crossDomain||e["X-Requested-With"]||(e["X-Requested-With"]="XMLHttpRequest"),e)r.setRequestHeader(n,e[n]);o=function(e){return function(){o&&(o=a=r.onload=r.onerror=r.onabort=r.ontimeout=r.onreadystatechange=null,"abort"===e?r.abort():"error"===e?"number"!=typeof r.status?t(0,"error"):t(r.status,r.statusText):t(Yt[r.status]||r.status,r.statusText,"text"!==(r.responseType||"text")||"string"!=typeof r.responseText?{binary:r.response}:{text:r.responseText},r.getAllResponseHeaders()))}},r.onload=o(),a=r.onerror=r.ontimeout=o("error"),void 0!==r.onabort?r.onabort=a:r.onreadystatechange=function(){4===r.readyState&&ie.setTimeout(function(){o&&a()})},o=o("abort");try{r.send(i.hasContent&&i.data||null)}catch(e){if(o)throw e}},abort:function(){o&&o()}}}),ce.ajaxPrefilter(function(e){e.crossDomain&&(e.contents.script=!1)}),ce.ajaxSetup({accepts:{script:"text/javascript, application/javascript, application/ecmascript, application/x-ecmascript"},contents:{script:/\b(?:java|ecma)script\b/},converters:{"text script":function(e){return ce.globalEval(e),e}}}),ce.ajaxPrefilter("script",function(e){void 0===e.cache&&(e.cache=!1),e.crossDomain&&(e.type="GET")}),ce.ajaxTransport("script",function(n){var r,i;if(n.crossDomain||n.scriptAttrs)return{send:function(e,t){r=ce("<script>").attr(n.scriptAttrs||{}).prop({charset:n.scriptCharset,src:n.url}).on("load error",i=function(e){r.remove(),i=null,e&&t("error"===e.type?404:200,e.type)}),C.head.appendChild(r[0])},abort:function(){i&&i()}}});var Jt,Kt=[],Zt=/(=)\?(?=&|$)|\?\?/;ce.ajaxSetup({jsonp:"callback",jsonpCallback:function(){var e=Kt.pop()||ce.expando+"_"+jt.guid++;return this[e]=!0,e}}),ce.ajaxPrefilter("json jsonp",function(e,t,n){var r,i,o,a=!1!==e.jsonp&&(Zt.test(e.url)?"url":"string"==typeof e.data&&0===(e.contentType||"").indexOf("application/x-www-form-urlencoded")&&Zt.test(e.data)&&"data");if(a||"jsonp"===e.dataTypes[0])return r=e.jsonpCallback=v(e.jsonpCallback)?e.jsonpCallback():e.jsonpCallback,a?e[a]=e[a].replace(Zt,"$1"+r):!1!==e.jsonp&&(e.url+=(At.test(e.url)?"&":"?")+e.jsonp+"="+r),e.converters["script json"]=function(){return o||ce.error(r+" was not called"),o[0]},e.dataTypes[0]="json",i=ie[r],ie[r]=function(){o=arguments},n.always(function(){void 0===i?ce(ie).removeProp(r):ie[r]=i,e[r]&&(e.jsonpCallback=t.jsonpCallback,Kt.push(r)),o&&v(i)&&i(o[0]),o=i=void 0}),"script"}),le.createHTMLDocument=((Jt=C.implementation.createHTMLDocument("").body).innerHTML="<form></form><form></form>",2===Jt.childNodes.length),ce.parseHTML=function(e,t,n){return"string"!=typeof e?[]:("boolean"==typeof t&&(n=t,t=!1),t||(le.createHTMLDocument?((r=(t=C.implementation.createHTMLDocument("")).createElement("base")).href=C.location.href,t.head.appendChild(r)):t=C),o=!n&&[],(i=w.exec(e))?[t.createElement(i[1])]:(i=Ae([e],t,o),o&&o.length&&ce(o).remove(),ce.merge([],i.childNodes)));var r,i,o},ce.fn.load=function(e,t,n){var r,i,o,a=this,s=e.indexOf(" ");return-1<s&&(r=Tt(e.slice(s)),e=e.slice(0,s)),v(t)?(n=t,t=void 0):t&&"object"==typeof t&&(i="POST"),0<a.length&&ce.ajax({url:e,type:i||"GET",dataType:"html",data:t}).done(function(e){o=arguments,a.html(r?ce("<div>").append(ce.parseHTML(e)).find(r):e)}).always(n&&function(e,t){a.each(function(){n.apply(this,o||[e.responseText,t,e])})}),this},ce.expr.pseudos.animated=function(t){return ce.grep(ce.timers,function(e){return t===e.elem}).length},ce.offset={setOffset:function(e,t,n){var r,i,o,a,s,u,l=ce.css(e,"position"),c=ce(e),f={};"static"===l&&(e.style.position="relative"),s=c.offset(),o=ce.css(e,"top"),u=ce.css(e,"left"),("absolute"===l||"fixed"===l)&&-1<(o+u).indexOf("auto")?(a=(r=c.position()).top,i=r.left):(a=parseFloat(o)||0,i=parseFloat(u)||0),v(t)&&(t=t.call(e,n,ce.extend({},s))),null!=t.top&&(f.top=t.top-s.top+a),null!=t.left&&(f.left=t.left-s.left+i),"using"in t?t.using.call(e,f):c.css(f)}},ce.fn.extend({offset:function(t){if(arguments.length)return void 0===t?this:this.each(function(e){ce.offset.setOffset(this,t,e)});var e,n,r=this[0];return r?r.getClientRects().length?(e=r.getBoundingClientRect(),n=r.ownerDocument.defaultView,{top:e.top+n.pageYOffset,left:e.left+n.pageXOffset}):{top:0,left:0}:void 0},position:function(){if(this[0]){var e,t,n,r=this[0],i={top:0,left:0};if("fixed"===ce.css(r,"position"))t=r.getBoundingClientRect();else{t=this.offset(),n=r.ownerDocument,e=r.offsetParent||n.documentElement;while(e&&(e===n.body||e===n.documentElement)&&"static"===ce.css(e,"position"))e=e.parentNode;e&&e!==r&&1===e.nodeType&&((i=ce(e).offset()).top+=ce.css(e,"borderTopWidth",!0),i.left+=ce.css(e,"borderLeftWidth",!0))}return{top:t.top-i.top-ce.css(r,"marginTop",!0),left:t.left-i.left-ce.css(r,"marginLeft",!0)}}},offsetParent:function(){return this.map(function(){var e=this.offsetParent;while(e&&"static"===ce.css(e,"position"))e=e.offsetParent;return e||J})}}),ce.each({scrollLeft:"pageXOffset",scrollTop:"pageYOffset"},function(t,i){var o="pageYOffset"===i;ce.fn[t]=function(e){return M(this,function(e,t,n){var r;if(y(e)?r=e:9===e.nodeType&&(r=e.defaultView),void 0===n)return r?r[i]:e[t];r?r.scrollTo(o?r.pageXOffset:n,o?n:r.pageYOffset):e[t]=n},t,e,arguments.length)}}),ce.each(["top","left"],function(e,n){ce.cssHooks[n]=Ye(le.pixelPosition,function(e,t){if(t)return t=Ge(e,n),_e.test(t)?ce(e).position()[n]+"px":t})}),ce.each({Height:"height",Width:"width"},function(a,s){ce.each({padding:"inner"+a,content:s,"":"outer"+a},function(r,o){ce.fn[o]=function(e,t){var n=arguments.length&&(r||"boolean"!=typeof e),i=r||(!0===e||!0===t?"margin":"border");return M(this,function(e,t,n){var r;return y(e)?0===o.indexOf("outer")?e["inner"+a]:e.document.documentElement["client"+a]:9===e.nodeType?(r=e.documentElement,Math.max(e.body["scroll"+a],r["scroll"+a],e.body["offset"+a],r["offset"+a],r["client"+a])):void 0===n?ce.css(e,t,i):ce.style(e,t,n,i)},s,n?e:void 0,n)}})}),ce.each(["ajaxStart","ajaxStop","ajaxComplete","ajaxError","ajaxSuccess","ajaxSend"],function(e,t){ce.fn[t]=function(e){return this.on(t,e)}}),ce.fn.extend({bind:function(e,t,n){return this.on(e,null,t,n)},unbind:function(e,t){return this.off(e,null,t)},delegate:function(e,t,n,r){return this.on(t,e,n,r)},undelegate:function(e,t,n){return 1===arguments.length?this.off(e,"**"):this.off(t,e||"**",n)},hover:function(e,t){return this.on("mouseenter",e).on("mouseleave",t||e)}}),ce.each("blur focus focusin focusout resize scroll click dblclick mousedown mouseup mousemove mouseover mouseout mouseenter mouseleave change select submit keydown keypress keyup contextmenu".split(" "),function(e,n){ce.fn[n]=function(e,t){return 0<arguments.length?this.on(n,null,e,t):this.trigger(n)}});var en=/^[\s\uFEFF\xA0]+|([^\s\uFEFF\xA0])[\s\uFEFF\xA0]+$/g;ce.proxy=function(e,t){var n,r,i;if("string"==typeof t&&(n=e[t],t=e,e=n),v(e))return r=ae.call(arguments,2),(i=function(){return e.apply(t||this,r.concat(ae.call(arguments)))}).guid=e.guid=e.guid||ce.guid++,i},ce.holdReady=function(e){e?ce.readyWait++:ce.ready(!0)},ce.isArray=Array.isArray,ce.parseJSON=JSON.parse,ce.nodeName=fe,ce.isFunction=v,ce.isWindow=y,ce.camelCase=F,ce.type=x,ce.now=Date.now,ce.isNumeric=function(e){var t=ce.type(e);return("number"===t||"string"===t)&&!isNaN(e-parseFloat(e))},ce.trim=function(e){return null==e?"":(e+"").replace(en,"$1")},"function"==typeof define&&define.amd&&define("jquery",[],function(){return ce});var tn=ie.jQuery,nn=ie.$;return ce.noConflict=function(e){return ie.$===ce&&(ie.$=nn),e&&ie.jQuery===ce&&(ie.jQuery=tn),ce},"undefined"==typeof e&&(ie.jQuery=ie.$=ce),ce});

      /*! tiny.toast 2014-11-04 */
      !function(e,t){function o(e){return e?"object"==typeof Node?e instanceof Node:e&&"object"==typeof e&&"number"==typeof e.nodeType&&"string"==typeof e.nodeName:!1}function a(e){return e===t.body?!1:t.body.contains(e)}function n(e,t){for(var o=0,a=e.length;a>o;o++)t(e[o],o,e)}function i(e){function t(){a(e)&&e.parentElement.removeChild(e)}h?(e.addEventListener("webkitAnimationEnd",t,!1),e.addEventListener("animationend",t,!1),e.className+=" t-exit"):t(e)}function r(e){e=[].slice.call(e);var t;return n(e,function(e){t||"object"!=typeof e||(t=e)}),t=t||{},t.msg=t.msg||t.message,t.group=t.action?!1:void 0!==t.group?!!t.group:!!y.group,"string"==typeof e[0]&&(t.msg=e[0]),"number"==typeof e[1]&&(t.timeout=e[1]),t}function c(e,a){if(e instanceof Array)return a=a||t.createElement("span"),n(e,function(e){a.appendChild(c(e,a))}),a;if(e.dom&&o(e.dom))return e.dom;if(e.name&&e.onclick){var i=t.createElement("span"),r=t.createTextNode(e.name);return i.onclick=e.onclick,i.appendChild(r),i.className="t-action",i}return t.createTextNode("")}function m(e){var o={},a=function(a){var n=this;n.count=0,n.dom=t.createElement("div"),n.textNode=t.createTextNode(""),n.autoRemove=void 0,n.remove=function(){clearTimeout(n.autoRemove),delete o[a],i(n.dom)},n.dom.appendChild(n.textNode),n.dom.removeToast=n.remove,n.dom.className=e+" t-toast",g.appendChild(n.dom),!!y.group&&(o[a]=n)};return function(){var e=r(arguments),t=e.msg||"",n=e.dismissible,i="function"==typeof e.onclick?e.onclick:!1,m=n===!1?-1:e.timeout,d=e.group&&o[t]?o[t]:new a(t),s=d.dom,u=d&&++d.count>1?t+" (x"+d.count+")":t;return d.textNode.nodeValue=u,e.action&&"object"==typeof e.action&&s.appendChild(c(e.action)),-1!==m&&(clearTimeout(d.autoRemove),d.autoRemove=setTimeout(function(){d.remove()},+m||+y.timeout||5e3)),s.className+=n!==!1||i?" t-click":"",s.onclick=function(){i&&i({message:t,count:d.count}),n!==!1&&d.remove()},d.remove}}function d(){n(g.children,function(e){e.removeToast?e.removeToast():i(e)})}var s=t.head||t.getElementsByTagName("head")[0],u=t.createElement("style");u.type="text/css";var l=".t-wrap{position:fixed;bottom:0;text-align:center;font-family:sans-serif;width:100%}@media (min-width:0){.t-wrap{width:auto;display:inline-block;left:50%;-webkit-transform:translate(-50%,0);-ms-transform:translate(-50%,0);transform:translate(-50%,0);transform:translate3d(-50%,0,0);transform-style:preserve-3d}}.t-toast{width:16em;margin:.6em auto;padding:.5em .3em;border-radius:2em;box-shadow:0 4px 0 -1px rgba(0,0,0,.2);color:#eee;cursor:default;overflow-y:hidden;will-change:opacity,height,margin;-webkit-animation:t-enter 500ms ease-out;animation:t-enter 500ms ease-out;transform-style:preserve-3d}.t-toast.t-gray{background:#777;background:rgba(119,119,119,.9)}.t-toast.t-red{background:#D85955;background:rgba(216,89,85,.9)}.t-toast.t-blue{background:#4374AD;background:rgba(67,116,173,.9)}.t-toast.t-green{background:#75AD44;background:rgba(117,173,68,.9)}.t-toast.t-orange{background:#D89B55;background:rgba(216,133,73,.9)}.t-toast.t-white{background:#FAFAFA;background:rgba(255,255,255,.9);color:#777}.t-action,.t-click{cursor:pointer}.t-action{font-weight:700;text-decoration:underline;margin-left:.5em;display:inline-block}.t-toast.t-exit{-webkit-animation:t-exit 500ms ease-in;animation:t-exit 500ms ease-in}@-webkit-keyframes t-enter{from{opacity:0;max-height:0}to{opacity:1;max-height:2em}}@keyframes t-enter{from{opacity:0;max-height:0}to{opacity:1;max-height:2em}}@-webkit-keyframes t-exit{from{opacity:1;max-height:2em}to{opacity:0;max-height:0}}@keyframes t-exit{from{opacity:1;max-height:2em}to{opacity:0;max-height:0}}@media screen and (max-width:17em){.t-toast{width:90%}}";u.styleSheet?u.styleSheet.cssText=l:u.appendChild(t.createTextNode(l)),s.appendChild(u);var g=t.createElement("div");g.className="t-wrap";var f=function(){t.body.appendChild(g)},p=function(){"complete"===t.readyState&&f()};t.addEventListener?t.addEventListener("readystatechange",p,!1):t.attachEvent("onreadystatechange",p);var h=function(e){return"animation"in e||"-webkit-animation"in e}(g.style),y=e.toastr=e.toast={timeout:4e3,group:!0,clear:d,log:m("t-gray"),alert:m("t-white"),error:m("t-red"),info:m("t-blue"),success:m("t-green"),warning:m("t-orange")}}(window,document);

      // fancyBox v3.5.7
      !function(t,e,n,o){"use strict";function i(t,e){var o,i,a,s=[],r=0;t&&t.isDefaultPrevented()||(t.preventDefault(),e=e||{},t&&t.data&&(e=h(t.data.options,e)),o=e.$target||n(t.currentTarget).trigger("blur"),(a=n.fancybox.getInstance())&&a.$trigger&&a.$trigger.is(o)||(e.selector?s=n(e.selector):(i=o.attr("data-fancybox")||"",i?(s=t.data?t.data.items:[],s=s.length?s.filter('[data-fancybox="'+i+'"]'):n('[data-fancybox="'+i+'"]')):s=[o]),r=n(s).index(o),r<0&&(r=0),a=n.fancybox.open(s,e,r),a.$trigger=o))}if(t.console=t.console||{info:function(t){}},n){if(n.fn.fancybox)return void console.info("fancyBox already initialized");var a={closeExisting:!1,loop:!1,gutter:50,keyboard:!0,preventCaptionOverlap:!0,arrows:!0,infobar:!0,smallBtn:"auto",toolbar:"auto",buttons:["zoom","slideShow","thumbs","close"],idleTime:3,protect:!1,modal:!1,image:{preload:!1},ajax:{settings:{data:{fancybox:!0}}},iframe:{tpl:'<iframe id="fancybox-frame{rnd}" name="fancybox-frame{rnd}" class="fancybox-iframe" allowfullscreen="allowfullscreen" allow="autoplay; fullscreen" src=""></iframe>',preload:!0,css:{},attr:{scrolling:"auto"}},video:{tpl:'<video class="fancybox-video" playsinline controls controlsList="nodownload" poster="{{poster}}"><source src="{{src}}" type="{{format}}" />Sorry, your browser doesn\'t support embedded videos, <a href="{{src}}">download</a> and watch with your favorite video player!</video>',format:"",autoStart:!0},defaultType:"image",animationEffect:"zoom",animationDuration:366,zoomOpacity:"auto",transitionEffect:"fade",transitionDuration:366,slideClass:"",baseClass:"",baseTpl:'<div class="fancybox-container" role="dialog" tabindex="-1"><div class="fancybox-bg"></div><div class="fancybox-inner"><div class="fancybox-infobar"><span data-fancybox-index></span>&nbsp;/&nbsp;<span data-fancybox-count></span></div><div class="fancybox-toolbar">{{buttons}}</div><div class="fancybox-navigation">{{arrows}}</div><div class="fancybox-stage"></div><div class="fancybox-caption"><div class="fancybox-caption__body"></div></div></div></div>',spinnerTpl:'<div class="fancybox-loading"></div>',errorTpl:'<div class="fancybox-error"><p>{{ERROR}}</p></div>',btnTpl:{download:'<a download data-fancybox-download class="fancybox-button fancybox-button--download" title="{{DOWNLOAD}}" href="javascript:;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M18.62 17.09V19H5.38v-1.91zm-2.97-6.96L17 11.45l-5 4.87-5-4.87 1.36-1.32 2.68 2.64V5h1.92v7.77z"/></svg></a>',zoom:'<button data-fancybox-zoom class="fancybox-button fancybox-button--zoom" title="{{ZOOM}}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M18.7 17.3l-3-3a5.9 5.9 0 0 0-.6-7.6 5.9 5.9 0 0 0-8.4 0 5.9 5.9 0 0 0 0 8.4 5.9 5.9 0 0 0 7.7.7l3 3a1 1 0 0 0 1.3 0c.4-.5.4-1 0-1.5zM8.1 13.8a4 4 0 0 1 0-5.7 4 4 0 0 1 5.7 0 4 4 0 0 1 0 5.7 4 4 0 0 1-5.7 0z"/></svg></button>',close:'<button data-fancybox-close class="fancybox-button fancybox-button--close" title="{{CLOSE}}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 10.6L6.6 5.2 5.2 6.6l5.4 5.4-5.4 5.4 1.4 1.4 5.4-5.4 5.4 5.4 1.4-1.4-5.4-5.4 5.4-5.4-1.4-1.4-5.4 5.4z"/></svg></button>',arrowLeft:'<button data-fancybox-prev class="fancybox-button fancybox-button--arrow_left" title="{{PREV}}"><div><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M11.28 15.7l-1.34 1.37L5 12l4.94-5.07 1.34 1.38-2.68 2.72H19v1.94H8.6z"/></svg></div></button>',arrowRight:'<button data-fancybox-next class="fancybox-button fancybox-button--arrow_right" title="{{NEXT}}"><div><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M15.4 12.97l-2.68 2.72 1.34 1.38L19 12l-4.94-5.07-1.34 1.38 2.68 2.72H5v1.94z"/></svg></div></button>',smallBtn:'<button type="button" data-fancybox-close class="fancybox-button fancybox-close-small" title="{{CLOSE}}"><svg xmlns="http://www.w3.org/2000/svg" version="1" viewBox="0 0 24 24"><path d="M13 12l5-5-1-1-5 5-5-5-1 1 5 5-5 5 1 1 5-5 5 5 1-1z"/></svg></button>'},parentEl:"body",hideScrollbar:!0,autoFocus:!0,backFocus:!0,trapFocus:!0,fullScreen:{autoStart:!1},touch:{vertical:!0,momentum:!0},hash:null,media:{},slideShow:{autoStart:!1,speed:3e3},thumbs:{autoStart:!1,hideOnClose:!0,parentEl:".fancybox-container",axis:"y"},wheel:"auto",onInit:n.noop,beforeLoad:n.noop,afterLoad:n.noop,beforeShow:n.noop,afterShow:n.noop,beforeClose:n.noop,afterClose:n.noop,onActivate:n.noop,onDeactivate:n.noop,clickContent:function(t,e){return"image"===t.type&&"zoom"},clickSlide:"close",clickOutside:"close",dblclickContent:!1,dblclickSlide:!1,dblclickOutside:!1,mobile:{preventCaptionOverlap:!1,idleTime:!1,clickContent:function(t,e){return"image"===t.type&&"toggleControls"},clickSlide:function(t,e){return"image"===t.type?"toggleControls":"close"},dblclickContent:function(t,e){return"image"===t.type&&"zoom"},dblclickSlide:function(t,e){return"image"===t.type&&"zoom"}},lang:"en",i18n:{en:{CLOSE:"Close",NEXT:"Next",PREV:"Previous",ERROR:"The requested content cannot be loaded. <br/> Please try again later.",PLAY_START:"Start slideshow",PLAY_STOP:"Pause slideshow",FULL_SCREEN:"Full screen",THUMBS:"Thumbnails",DOWNLOAD:"Download",SHARE:"Share",ZOOM:"Zoom"},de:{CLOSE:"Schlie&szlig;en",NEXT:"Weiter",PREV:"Zur&uuml;ck",ERROR:"Die angeforderten Daten konnten nicht geladen werden. <br/> Bitte versuchen Sie es sp&auml;ter nochmal.",PLAY_START:"Diaschau starten",PLAY_STOP:"Diaschau beenden",FULL_SCREEN:"Vollbild",THUMBS:"Vorschaubilder",DOWNLOAD:"Herunterladen",SHARE:"Teilen",ZOOM:"Vergr&ouml;&szlig;ern"}}},s=n(t),r=n(e),c=0,l=function(t){return t&&t.hasOwnProperty&&t instanceof n},d=function(){return t.requestAnimationFrame||t.webkitRequestAnimationFrame||t.mozRequestAnimationFrame||t.oRequestAnimationFrame||function(e){return t.setTimeout(e,1e3/60)}}(),u=function(){return t.cancelAnimationFrame||t.webkitCancelAnimationFrame||t.mozCancelAnimationFrame||t.oCancelAnimationFrame||function(e){t.clearTimeout(e)}}(),f=function(){var t,n=e.createElement("fakeelement"),o={transition:"transitionend",OTransition:"oTransitionEnd",MozTransition:"transitionend",WebkitTransition:"webkitTransitionEnd"};for(t in o)if(void 0!==n.style[t])return o[t];return"transitionend"}(),p=function(t){return t&&t.length&&t[0].offsetHeight},h=function(t,e){var o=n.extend(!0,{},t,e);return n.each(e,function(t,e){n.isArray(e)&&(o[t]=e)}),o},g=function(t){var o,i;return!(!t||t.ownerDocument!==e)&&(n(".fancybox-container").css("pointer-events","none"),o={x:t.getBoundingClientRect().left+t.offsetWidth/2,y:t.getBoundingClientRect().top+t.offsetHeight/2},i=e.elementFromPoint(o.x,o.y)===t,n(".fancybox-container").css("pointer-events",""),i)},b=function(t,e,o){var i=this;i.opts=h({index:o},n.fancybox.defaults),n.isPlainObject(e)&&(i.opts=h(i.opts,e)),n.fancybox.isMobile&&(i.opts=h(i.opts,i.opts.mobile)),i.id=i.opts.id||++c,i.currIndex=parseInt(i.opts.index,10)||0,i.prevIndex=null,i.prevPos=null,i.currPos=0,i.firstRun=!0,i.group=[],i.slides={},i.addContent(t),i.group.length&&i.init()};n.extend(b.prototype,{init:function(){var o,i,a=this,s=a.group[a.currIndex],r=s.opts;r.closeExisting&&n.fancybox.close(!0),n("body").addClass("fancybox-active"),!n.fancybox.getInstance()&&!1!==r.hideScrollbar&&!n.fancybox.isMobile&&e.body.scrollHeight>t.innerHeight&&(n("head").append('<style id="fancybox-style-noscroll" type="text/css">.compensate-for-scrollbar{margin-right:'+(t.innerWidth-e.documentElement.clientWidth)+"px;}</style>"),n("body").addClass("compensate-for-scrollbar")),i="",n.each(r.buttons,function(t,e){i+=r.btnTpl[e]||""}),o=n(a.translate(a,r.baseTpl.replace("{{buttons}}",i).replace("{{arrows}}",r.btnTpl.arrowLeft+r.btnTpl.arrowRight))).attr("id","fancybox-container-"+a.id).addClass(r.baseClass).data("FancyBox",a).appendTo(r.parentEl),a.$refs={container:o},["bg","inner","infobar","toolbar","stage","caption","navigation"].forEach(function(t){a.$refs[t]=o.find(".fancybox-"+t)}),a.trigger("onInit"),a.activate(),a.jumpTo(a.currIndex)},translate:function(t,e){var n=t.opts.i18n[t.opts.lang]||t.opts.i18n.en;return e.replace(/\{\{(\w+)\}\}/g,function(t,e){return void 0===n[e]?t:n[e]})},addContent:function(t){var e,o=this,i=n.makeArray(t);n.each(i,function(t,e){var i,a,s,r,c,l={},d={};n.isPlainObject(e)?(l=e,d=e.opts||e):"object"===n.type(e)&&n(e).length?(i=n(e),d=i.data()||{},d=n.extend(!0,{},d,d.options),d.$orig=i,l.src=o.opts.src||d.src||i.attr("href"),l.type||l.src||(l.type="inline",l.src=e)):l={type:"html",src:e+""},l.opts=n.extend(!0,{},o.opts,d),n.isArray(d.buttons)&&(l.opts.buttons=d.buttons),n.fancybox.isMobile&&l.opts.mobile&&(l.opts=h(l.opts,l.opts.mobile)),a=l.type||l.opts.type,r=l.src||"",!a&&r&&((s=r.match(/\.(mp4|mov|ogv|webm)((\?|#).*)?$/i))?(a="video",l.opts.video.format||(l.opts.video.format="video/"+("ogv"===s[1]?"ogg":s[1]))):r.match(/(^data:image\/[a-z0-9+\/=]*,)|(\.(jp(e|g|eg)|gif|png|bmp|webp|svg|ico)((\?|#).*)?$)/i)?a="image":r.match(/\.(pdf)((\?|#).*)?$/i)?(a="iframe",l=n.extend(!0,l,{contentType:"pdf",opts:{iframe:{preload:!1}}})):"#"===r.charAt(0)&&(a="inline")),a?l.type=a:o.trigger("objectNeedsType",l),l.contentType||(l.contentType=n.inArray(l.type,["html","inline","ajax"])>-1?"html":l.type),l.index=o.group.length,"auto"==l.opts.smallBtn&&(l.opts.smallBtn=n.inArray(l.type,["html","inline","ajax"])>-1),"auto"===l.opts.toolbar&&(l.opts.toolbar=!l.opts.smallBtn),l.$thumb=l.opts.$thumb||null,l.opts.$trigger&&l.index===o.opts.index&&(l.$thumb=l.opts.$trigger.find("img:first"),l.$thumb.length&&(l.opts.$orig=l.opts.$trigger)),l.$thumb&&l.$thumb.length||!l.opts.$orig||(l.$thumb=l.opts.$orig.find("img:first")),l.$thumb&&!l.$thumb.length&&(l.$thumb=null),l.thumb=l.opts.thumb||(l.$thumb?l.$thumb[0].src:null),"function"===n.type(l.opts.caption)&&(l.opts.caption=l.opts.caption.apply(e,[o,l])),"function"===n.type(o.opts.caption)&&(l.opts.caption=o.opts.caption.apply(e,[o,l])),l.opts.caption instanceof n||(l.opts.caption=void 0===l.opts.caption?"":l.opts.caption+""),"ajax"===l.type&&(c=r.split(/\s+/,2),c.length>1&&(l.src=c.shift(),l.opts.filter=c.shift())),l.opts.modal&&(l.opts=n.extend(!0,l.opts,{trapFocus:!0,infobar:0,toolbar:0,smallBtn:0,keyboard:0,slideShow:0,fullScreen:0,thumbs:0,touch:0,clickContent:!1,clickSlide:!1,clickOutside:!1,dblclickContent:!1,dblclickSlide:!1,dblclickOutside:!1})),o.group.push(l)}),Object.keys(o.slides).length&&(o.updateControls(),(e=o.Thumbs)&&e.isActive&&(e.create(),e.focus()))},addEvents:function(){var e=this;e.removeEvents(),e.$refs.container.on("click.fb-close","[data-fancybox-close]",function(t){t.stopPropagation(),t.preventDefault(),e.close(t)}).on("touchstart.fb-prev click.fb-prev","[data-fancybox-prev]",function(t){t.stopPropagation(),t.preventDefault(),e.previous()}).on("touchstart.fb-next click.fb-next","[data-fancybox-next]",function(t){t.stopPropagation(),t.preventDefault(),e.next()}).on("click.fb","[data-fancybox-zoom]",function(t){e[e.isScaledDown()?"scaleToActual":"scaleToFit"]()}),s.on("orientationchange.fb resize.fb",function(t){t&&t.originalEvent&&"resize"===t.originalEvent.type?(e.requestId&&u(e.requestId),e.requestId=d(function(){e.update(t)})):(e.current&&"iframe"===e.current.type&&e.$refs.stage.hide(),setTimeout(function(){e.$refs.stage.show(),e.update(t)},n.fancybox.isMobile?600:250))}),r.on("keydown.fb",function(t){var o=n.fancybox?n.fancybox.getInstance():null,i=o.current,a=t.keyCode||t.which;if(9==a)return void(i.opts.trapFocus&&e.focus(t));if(!(!i.opts.keyboard||t.ctrlKey||t.altKey||t.shiftKey||n(t.target).is("input,textarea,video,audio,select")))return 8===a||27===a?(t.preventDefault(),void e.close(t)):37===a||38===a?(t.preventDefault(),void e.previous()):39===a||40===a?(t.preventDefault(),void e.next()):void e.trigger("afterKeydown",t,a)}),e.group[e.currIndex].opts.idleTime&&(e.idleSecondsCounter=0,r.on("mousemove.fb-idle mouseleave.fb-idle mousedown.fb-idle touchstart.fb-idle touchmove.fb-idle scroll.fb-idle keydown.fb-idle",function(t){e.idleSecondsCounter=0,e.isIdle&&e.showControls(),e.isIdle=!1}),e.idleInterval=t.setInterval(function(){++e.idleSecondsCounter>=e.group[e.currIndex].opts.idleTime&&!e.isDragging&&(e.isIdle=!0,e.idleSecondsCounter=0,e.hideControls())},1e3))},removeEvents:function(){var e=this;s.off("orientationchange.fb resize.fb"),r.off("keydown.fb .fb-idle"),this.$refs.container.off(".fb-close .fb-prev .fb-next"),e.idleInterval&&(t.clearInterval(e.idleInterval),e.idleInterval=null)},previous:function(t){return this.jumpTo(this.currPos-1,t)},next:function(t){return this.jumpTo(this.currPos+1,t)},jumpTo:function(t,e){var o,i,a,s,r,c,l,d,u,f=this,h=f.group.length;if(!(f.isDragging||f.isClosing||f.isAnimating&&f.firstRun)){if(t=parseInt(t,10),!(a=f.current?f.current.opts.loop:f.opts.loop)&&(t<0||t>=h))return!1;if(o=f.firstRun=!Object.keys(f.slides).length,r=f.current,f.prevIndex=f.currIndex,f.prevPos=f.currPos,s=f.createSlide(t),h>1&&((a||s.index<h-1)&&f.createSlide(t+1),(a||s.index>0)&&f.createSlide(t-1)),f.current=s,f.currIndex=s.index,f.currPos=s.pos,f.trigger("beforeShow",o),f.updateControls(),s.forcedDuration=void 0,n.isNumeric(e)?s.forcedDuration=e:e=s.opts[o?"animationDuration":"transitionDuration"],e=parseInt(e,10),i=f.isMoved(s),s.$slide.addClass("fancybox-slide--current"),o)return s.opts.animationEffect&&e&&f.$refs.container.css("transition-duration",e+"ms"),f.$refs.container.addClass("fancybox-is-open").trigger("focus"),f.loadSlide(s),void f.preload("image");c=n.fancybox.getTranslate(r.$slide),l=n.fancybox.getTranslate(f.$refs.stage),n.each(f.slides,function(t,e){n.fancybox.stop(e.$slide,!0)}),r.pos!==s.pos&&(r.isComplete=!1),r.$slide.removeClass("fancybox-slide--complete fancybox-slide--current"),i?(u=c.left-(r.pos*c.width+r.pos*r.opts.gutter),n.each(f.slides,function(t,o){o.$slide.removeClass("fancybox-animated").removeClass(function(t,e){return(e.match(/(^|\s)fancybox-fx-\S+/g)||[]).join(" ")});var i=o.pos*c.width+o.pos*o.opts.gutter;n.fancybox.setTranslate(o.$slide,{top:0,left:i-l.left+u}),o.pos!==s.pos&&o.$slide.addClass("fancybox-slide--"+(o.pos>s.pos?"next":"previous")),p(o.$slide),n.fancybox.animate(o.$slide,{top:0,left:(o.pos-s.pos)*c.width+(o.pos-s.pos)*o.opts.gutter},e,function(){o.$slide.css({transform:"",opacity:""}).removeClass("fancybox-slide--next fancybox-slide--previous"),o.pos===f.currPos&&f.complete()})})):e&&s.opts.transitionEffect&&(d="fancybox-animated fancybox-fx-"+s.opts.transitionEffect,r.$slide.addClass("fancybox-slide--"+(r.pos>s.pos?"next":"previous")),n.fancybox.animate(r.$slide,d,e,function(){r.$slide.removeClass(d).removeClass("fancybox-slide--next fancybox-slide--previous")},!1)),s.isLoaded?f.revealContent(s):f.loadSlide(s),f.preload("image")}},createSlide:function(t){var e,o,i=this;return o=t%i.group.length,o=o<0?i.group.length+o:o,!i.slides[t]&&i.group[o]&&(e=n('<div class="fancybox-slide"></div>').appendTo(i.$refs.stage),i.slides[t]=n.extend(!0,{},i.group[o],{pos:t,$slide:e,isLoaded:!1}),i.updateSlide(i.slides[t])),i.slides[t]},scaleToActual:function(t,e,o){var i,a,s,r,c,l=this,d=l.current,u=d.$content,f=n.fancybox.getTranslate(d.$slide).width,p=n.fancybox.getTranslate(d.$slide).height,h=d.width,g=d.height;l.isAnimating||l.isMoved()||!u||"image"!=d.type||!d.isLoaded||d.hasError||(l.isAnimating=!0,n.fancybox.stop(u),t=void 0===t?.5*f:t,e=void 0===e?.5*p:e,i=n.fancybox.getTranslate(u),i.top-=n.fancybox.getTranslate(d.$slide).top,i.left-=n.fancybox.getTranslate(d.$slide).left,r=h/i.width,c=g/i.height,a=.5*f-.5*h,s=.5*p-.5*g,h>f&&(a=i.left*r-(t*r-t),a>0&&(a=0),a<f-h&&(a=f-h)),g>p&&(s=i.top*c-(e*c-e),s>0&&(s=0),s<p-g&&(s=p-g)),l.updateCursor(h,g),n.fancybox.animate(u,{top:s,left:a,scaleX:r,scaleY:c},o||366,function(){l.isAnimating=!1}),l.SlideShow&&l.SlideShow.isActive&&l.SlideShow.stop())},scaleToFit:function(t){var e,o=this,i=o.current,a=i.$content;o.isAnimating||o.isMoved()||!a||"image"!=i.type||!i.isLoaded||i.hasError||(o.isAnimating=!0,n.fancybox.stop(a),e=o.getFitPos(i),o.updateCursor(e.width,e.height),n.fancybox.animate(a,{top:e.top,left:e.left,scaleX:e.width/a.width(),scaleY:e.height/a.height()},t||366,function(){o.isAnimating=!1}))},getFitPos:function(t){var e,o,i,a,s=this,r=t.$content,c=t.$slide,l=t.width||t.opts.width,d=t.height||t.opts.height,u={};return!!(t.isLoaded&&r&&r.length)&&(e=n.fancybox.getTranslate(s.$refs.stage).width,o=n.fancybox.getTranslate(s.$refs.stage).height,e-=parseFloat(c.css("paddingLeft"))+parseFloat(c.css("paddingRight"))+parseFloat(r.css("marginLeft"))+parseFloat(r.css("marginRight")),o-=parseFloat(c.css("paddingTop"))+parseFloat(c.css("paddingBottom"))+parseFloat(r.css("marginTop"))+parseFloat(r.css("marginBottom")),l&&d||(l=e,d=o),i=Math.min(1,e/l,o/d),l*=i,d*=i,l>e-.5&&(l=e),d>o-.5&&(d=o),"image"===t.type?(u.top=Math.floor(.5*(o-d))+parseFloat(c.css("paddingTop")),u.left=Math.floor(.5*(e-l))+parseFloat(c.css("paddingLeft"))):"video"===t.contentType&&(a=t.opts.width&&t.opts.height?l/d:t.opts.ratio||16/9,d>l/a?d=l/a:l>d*a&&(l=d*a)),u.width=l,u.height=d,u)},update:function(t){var e=this;n.each(e.slides,function(n,o){e.updateSlide(o,t)})},updateSlide:function(t,e){var o=this,i=t&&t.$content,a=t.width||t.opts.width,s=t.height||t.opts.height,r=t.$slide;o.adjustCaption(t),i&&(a||s||"video"===t.contentType)&&!t.hasError&&(n.fancybox.stop(i),n.fancybox.setTranslate(i,o.getFitPos(t)),t.pos===o.currPos&&(o.isAnimating=!1,o.updateCursor())),o.adjustLayout(t),r.length&&(r.trigger("refresh"),t.pos===o.currPos&&o.$refs.toolbar.add(o.$refs.navigation.find(".fancybox-button--arrow_right")).toggleClass("compensate-for-scrollbar",r.get(0).scrollHeight>r.get(0).clientHeight)),o.trigger("onUpdate",t,e)},centerSlide:function(t){var e=this,o=e.current,i=o.$slide;!e.isClosing&&o&&(i.siblings().css({transform:"",opacity:""}),i.parent().children().removeClass("fancybox-slide--previous fancybox-slide--next"),n.fancybox.animate(i,{top:0,left:0,opacity:1},void 0===t?0:t,function(){i.css({transform:"",opacity:""}),o.isComplete||e.complete()},!1))},isMoved:function(t){var e,o,i=t||this.current;return!!i&&(o=n.fancybox.getTranslate(this.$refs.stage),e=n.fancybox.getTranslate(i.$slide),!i.$slide.hasClass("fancybox-animated")&&(Math.abs(e.top-o.top)>.5||Math.abs(e.left-o.left)>.5))},updateCursor:function(t,e){var o,i,a=this,s=a.current,r=a.$refs.container;s&&!a.isClosing&&a.Guestures&&(r.removeClass("fancybox-is-zoomable fancybox-can-zoomIn fancybox-can-zoomOut fancybox-can-swipe fancybox-can-pan"),o=a.canPan(t,e),i=!!o||a.isZoomable(),r.toggleClass("fancybox-is-zoomable",i),n("[data-fancybox-zoom]").prop("disabled",!i),o?r.addClass("fancybox-can-pan"):i&&("zoom"===s.opts.clickContent||n.isFunction(s.opts.clickContent)&&"zoom"==s.opts.clickContent(s))?r.addClass("fancybox-can-zoomIn"):s.opts.touch&&(s.opts.touch.vertical||a.group.length>1)&&"video"!==s.contentType&&r.addClass("fancybox-can-swipe"))},isZoomable:function(){var t,e=this,n=e.current;if(n&&!e.isClosing&&"image"===n.type&&!n.hasError){if(!n.isLoaded)return!0;if((t=e.getFitPos(n))&&(n.width>t.width||n.height>t.height))return!0}return!1},isScaledDown:function(t,e){var o=this,i=!1,a=o.current,s=a.$content;return void 0!==t&&void 0!==e?i=t<a.width&&e<a.height:s&&(i=n.fancybox.getTranslate(s),i=i.width<a.width&&i.height<a.height),i},canPan:function(t,e){var o=this,i=o.current,a=null,s=!1;return"image"===i.type&&(i.isComplete||t&&e)&&!i.hasError&&(s=o.getFitPos(i),void 0!==t&&void 0!==e?a={width:t,height:e}:i.isComplete&&(a=n.fancybox.getTranslate(i.$content)),a&&s&&(s=Math.abs(a.width-s.width)>1.5||Math.abs(a.height-s.height)>1.5)),s},loadSlide:function(t){var e,o,i,a=this;if(!t.isLoading&&!t.isLoaded){if(t.isLoading=!0,!1===a.trigger("beforeLoad",t))return t.isLoading=!1,!1;switch(e=t.type,o=t.$slide,o.off("refresh").trigger("onReset").addClass(t.opts.slideClass),e){case"image":a.setImage(t);break;case"iframe":a.setIframe(t);break;case"html":a.setContent(t,t.src||t.content);break;case"video":a.setContent(t,t.opts.video.tpl.replace(/\{\{src\}\}/gi,t.src).replace("{{format}}",t.opts.videoFormat||t.opts.video.format||"").replace("{{poster}}",t.thumb||""));break;case"inline":n(t.src).length?a.setContent(t,n(t.src)):a.setError(t);break;case"ajax":a.showLoading(t),i=n.ajax(n.extend({},t.opts.ajax.settings,{url:t.src,success:function(e,n){"success"===n&&a.setContent(t,e)},error:function(e,n){e&&"abort"!==n&&a.setError(t)}})),o.one("onReset",function(){i.abort()});break;default:a.setError(t)}return!0}},setImage:function(t){var o,i=this;setTimeout(function(){var e=t.$image;i.isClosing||!t.isLoading||e&&e.length&&e[0].complete||t.hasError||i.showLoading(t)},50),i.checkSrcset(t),t.$content=n('<div class="fancybox-content"></div>').addClass("fancybox-is-hidden").appendTo(t.$slide.addClass("fancybox-slide--image")),!1!==t.opts.preload&&t.opts.width&&t.opts.height&&t.thumb&&(t.width=t.opts.width,t.height=t.opts.height,o=e.createElement("img"),o.onerror=function(){n(this).remove(),t.$ghost=null},o.onload=function(){i.afterLoad(t)},t.$ghost=n(o).addClass("fancybox-image").appendTo(t.$content).attr("src",t.thumb)),i.setBigImage(t)},checkSrcset:function(e){var n,o,i,a,s=e.opts.srcset||e.opts.image.srcset;if(s){i=t.devicePixelRatio||1,a=t.innerWidth*i,o=s.split(",").map(function(t){var e={};return t.trim().split(/\s+/).forEach(function(t,n){var o=parseInt(t.substring(0,t.length-1),10);if(0===n)return e.url=t;o&&(e.value=o,e.postfix=t[t.length-1])}),e}),o.sort(function(t,e){return t.value-e.value});for(var r=0;r<o.length;r++){var c=o[r];if("w"===c.postfix&&c.value>=a||"x"===c.postfix&&c.value>=i){n=c;break}}!n&&o.length&&(n=o[o.length-1]),n&&(e.src=n.url,e.width&&e.height&&"w"==n.postfix&&(e.height=e.width/e.height*n.value,e.width=n.value),e.opts.srcset=s)}},setBigImage:function(t){var o=this,i=e.createElement("img"),a=n(i);t.$image=a.one("error",function(){o.setError(t)}).one("load",function(){var e;t.$ghost||(o.resolveImageSlideSize(t,this.naturalWidth,this.naturalHeight),o.afterLoad(t)),o.isClosing||(t.opts.srcset&&(e=t.opts.sizes,e&&"auto"!==e||(e=(t.width/t.height>1&&s.width()/s.height()>1?"100":Math.round(t.width/t.height*100))+"vw"),a.attr("sizes",e).attr("srcset",t.opts.srcset)),t.$ghost&&setTimeout(function(){t.$ghost&&!o.isClosing&&t.$ghost.hide()},Math.min(300,Math.max(1e3,t.height/1600))),o.hideLoading(t))}).addClass("fancybox-image").attr("src",t.src).appendTo(t.$content),(i.complete||"complete"==i.readyState)&&a.naturalWidth&&a.naturalHeight?a.trigger("load"):i.error&&a.trigger("error")},resolveImageSlideSize:function(t,e,n){var o=parseInt(t.opts.width,10),i=parseInt(t.opts.height,10);t.width=e,t.height=n,o>0&&(t.width=o,t.height=Math.floor(o*n/e)),i>0&&(t.width=Math.floor(i*e/n),t.height=i)},setIframe:function(t){var e,o=this,i=t.opts.iframe,a=t.$slide;t.$content=n('<div class="fancybox-content'+(i.preload?" fancybox-is-hidden":"")+'"></div>').css(i.css).appendTo(a),a.addClass("fancybox-slide--"+t.contentType),t.$iframe=e=n(i.tpl.replace(/\{rnd\}/g,(new Date).getTime())).attr(i.attr).appendTo(t.$content),i.preload?(o.showLoading(t),e.on("load.fb error.fb",function(e){this.isReady=1,t.$slide.trigger("refresh"),o.afterLoad(t)}),a.on("refresh.fb",function(){var n,o,s=t.$content,r=i.css.width,c=i.css.height;if(1===e[0].isReady){try{n=e.contents(),o=n.find("body")}catch(t){}o&&o.length&&o.children().length&&(a.css("overflow","visible"),s.css({width:"100%","max-width":"100%",height:"9999px"}),void 0===r&&(r=Math.ceil(Math.max(o[0].clientWidth,o.outerWidth(!0)))),s.css("width",r||"").css("max-width",""),void 0===c&&(c=Math.ceil(Math.max(o[0].clientHeight,o.outerHeight(!0)))),s.css("height",c||""),a.css("overflow","auto")),s.removeClass("fancybox-is-hidden")}})):o.afterLoad(t),e.attr("src",t.src),a.one("onReset",function(){try{n(this).find("iframe").hide().unbind().attr("src","//about:blank")}catch(t){}n(this).off("refresh.fb").empty(),t.isLoaded=!1,t.isRevealed=!1})},setContent:function(t,e){var o=this;o.isClosing||(o.hideLoading(t),t.$content&&n.fancybox.stop(t.$content),t.$slide.empty(),l(e)&&e.parent().length?((e.hasClass("fancybox-content")||e.parent().hasClass("fancybox-content"))&&e.parents(".fancybox-slide").trigger("onReset"),t.$placeholder=n("<div>").hide().insertAfter(e),e.css("display","inline-block")):t.hasError||("string"===n.type(e)&&(e=n("<div>").append(n.trim(e)).contents()),t.opts.filter&&(e=n("<div>").html(e).find(t.opts.filter))),t.$slide.one("onReset",function(){n(this).find("video,audio").trigger("pause"),t.$placeholder&&(t.$placeholder.after(e.removeClass("fancybox-content").hide()).remove(),t.$placeholder=null),t.$smallBtn&&(t.$smallBtn.remove(),t.$smallBtn=null),t.hasError||(n(this).empty(),t.isLoaded=!1,t.isRevealed=!1)}),n(e).appendTo(t.$slide),n(e).is("video,audio")&&(n(e).addClass("fancybox-video"),n(e).wrap("<div></div>"),t.contentType="video",t.opts.width=t.opts.width||n(e).attr("width"),t.opts.height=t.opts.height||n(e).attr("height")),t.$content=t.$slide.children().filter("div,form,main,video,audio,article,.fancybox-content").first(),t.$content.siblings().hide(),t.$content.length||(t.$content=t.$slide.wrapInner("<div></div>").children().first()),t.$content.addClass("fancybox-content"),t.$slide.addClass("fancybox-slide--"+t.contentType),o.afterLoad(t))},setError:function(t){t.hasError=!0,t.$slide.trigger("onReset").removeClass("fancybox-slide--"+t.contentType).addClass("fancybox-slide--error"),t.contentType="html",this.setContent(t,this.translate(t,t.opts.errorTpl)),t.pos===this.currPos&&(this.isAnimating=!1)},showLoading:function(t){var e=this;(t=t||e.current)&&!t.$spinner&&(t.$spinner=n(e.translate(e,e.opts.spinnerTpl)).appendTo(t.$slide).hide().fadeIn("fast"))},hideLoading:function(t){var e=this;(t=t||e.current)&&t.$spinner&&(t.$spinner.stop().remove(),delete t.$spinner)},afterLoad:function(t){var e=this;e.isClosing||(t.isLoading=!1,t.isLoaded=!0,e.trigger("afterLoad",t),e.hideLoading(t),!t.opts.smallBtn||t.$smallBtn&&t.$smallBtn.length||(t.$smallBtn=n(e.translate(t,t.opts.btnTpl.smallBtn)).appendTo(t.$content)),t.opts.protect&&t.$content&&!t.hasError&&(t.$content.on("contextmenu.fb",function(t){return 2==t.button&&t.preventDefault(),!0}),"image"===t.type&&n('<div class="fancybox-spaceball"></div>').appendTo(t.$content)),e.adjustCaption(t),e.adjustLayout(t),t.pos===e.currPos&&e.updateCursor(),e.revealContent(t))},adjustCaption:function(t){var e,n=this,o=t||n.current,i=o.opts.caption,a=o.opts.preventCaptionOverlap,s=n.$refs.caption,r=!1;s.toggleClass("fancybox-caption--separate",a),a&&i&&i.length&&(o.pos!==n.currPos?(e=s.clone().appendTo(s.parent()),e.children().eq(0).empty().html(i),r=e.outerHeight(!0),e.empty().remove()):n.$caption&&(r=n.$caption.outerHeight(!0)),o.$slide.css("padding-bottom",r||""))},adjustLayout:function(t){var e,n,o,i,a=this,s=t||a.current;s.isLoaded&&!0!==s.opts.disableLayoutFix&&(s.$content.css("margin-bottom",""),s.$content.outerHeight()>s.$slide.height()+.5&&(o=s.$slide[0].style["padding-bottom"],i=s.$slide.css("padding-bottom"),parseFloat(i)>0&&(e=s.$slide[0].scrollHeight,s.$slide.css("padding-bottom",0),Math.abs(e-s.$slide[0].scrollHeight)<1&&(n=i),s.$slide.css("padding-bottom",o))),s.$content.css("margin-bottom",n))},revealContent:function(t){var e,o,i,a,s=this,r=t.$slide,c=!1,l=!1,d=s.isMoved(t),u=t.isRevealed;return t.isRevealed=!0,e=t.opts[s.firstRun?"animationEffect":"transitionEffect"],i=t.opts[s.firstRun?"animationDuration":"transitionDuration"],i=parseInt(void 0===t.forcedDuration?i:t.forcedDuration,10),!d&&t.pos===s.currPos&&i||(e=!1),"zoom"===e&&(t.pos===s.currPos&&i&&"image"===t.type&&!t.hasError&&(l=s.getThumbPos(t))?c=s.getFitPos(t):e="fade"),"zoom"===e?(s.isAnimating=!0,c.scaleX=c.width/l.width,c.scaleY=c.height/l.height,a=t.opts.zoomOpacity,"auto"==a&&(a=Math.abs(t.width/t.height-l.width/l.height)>.1),a&&(l.opacity=.1,c.opacity=1),n.fancybox.setTranslate(t.$content.removeClass("fancybox-is-hidden"),l),p(t.$content),void n.fancybox.animate(t.$content,c,i,function(){s.isAnimating=!1,s.complete()})):(s.updateSlide(t),e?(n.fancybox.stop(r),o="fancybox-slide--"+(t.pos>=s.prevPos?"next":"previous")+" fancybox-animated fancybox-fx-"+e,r.addClass(o).removeClass("fancybox-slide--current"),t.$content.removeClass("fancybox-is-hidden"),p(r),"image"!==t.type&&t.$content.hide().show(0),void n.fancybox.animate(r,"fancybox-slide--current",i,function(){r.removeClass(o).css({transform:"",opacity:""}),t.pos===s.currPos&&s.complete()},!0)):(t.$content.removeClass("fancybox-is-hidden"),u||!d||"image"!==t.type||t.hasError||t.$content.hide().fadeIn("fast"),void(t.pos===s.currPos&&s.complete())))},getThumbPos:function(t){var e,o,i,a,s,r=!1,c=t.$thumb;return!(!c||!g(c[0]))&&(e=n.fancybox.getTranslate(c),o=parseFloat(c.css("border-top-width")||0),i=parseFloat(c.css("border-right-width")||0),a=parseFloat(c.css("border-bottom-width")||0),s=parseFloat(c.css("border-left-width")||0),r={top:e.top+o,left:e.left+s,width:e.width-i-s,height:e.height-o-a,scaleX:1,scaleY:1},e.width>0&&e.height>0&&r)},complete:function(){var t,e=this,o=e.current,i={};!e.isMoved()&&o.isLoaded&&(o.isComplete||(o.isComplete=!0,o.$slide.siblings().trigger("onReset"),e.preload("inline"),p(o.$slide),o.$slide.addClass("fancybox-slide--complete"),n.each(e.slides,function(t,o){o.pos>=e.currPos-1&&o.pos<=e.currPos+1?i[o.pos]=o:o&&(n.fancybox.stop(o.$slide),o.$slide.off().remove())}),e.slides=i),e.isAnimating=!1,e.updateCursor(),e.trigger("afterShow"),o.opts.video.autoStart&&o.$slide.find("video,audio").filter(":visible:first").trigger("play").one("ended",function(){Document.exitFullscreen?Document.exitFullscreen():this.webkitExitFullscreen&&this.webkitExitFullscreen(),e.next()}),o.opts.autoFocus&&"html"===o.contentType&&(t=o.$content.find("input[autofocus]:enabled:visible:first"),t.length?t.trigger("focus"):e.focus(null,!0)),o.$slide.scrollTop(0).scrollLeft(0))},preload:function(t){var e,n,o=this;o.group.length<2||(n=o.slides[o.currPos+1],e=o.slides[o.currPos-1],e&&e.type===t&&o.loadSlide(e),n&&n.type===t&&o.loadSlide(n))},focus:function(t,o){var i,a,s=this,r=["a[href]","area[href]",'input:not([disabled]):not([type="hidden"]):not([aria-hidden])',"select:not([disabled]):not([aria-hidden])","textarea:not([disabled]):not([aria-hidden])","button:not([disabled]):not([aria-hidden])","iframe","object","embed","video","audio","[contenteditable]",'[tabindex]:not([tabindex^="-"])'].join(",");s.isClosing||(i=!t&&s.current&&s.current.isComplete?s.current.$slide.find("*:visible"+(o?":not(.fancybox-close-small)":"")):s.$refs.container.find("*:visible"),i=i.filter(r).filter(function(){return"hidden"!==n(this).css("visibility")&&!n(this).hasClass("disabled")}),i.length?(a=i.index(e.activeElement),t&&t.shiftKey?(a<0||0==a)&&(t.preventDefault(),i.eq(i.length-1).trigger("focus")):(a<0||a==i.length-1)&&(t&&t.preventDefault(),i.eq(0).trigger("focus"))):s.$refs.container.trigger("focus"))},activate:function(){var t=this;n(".fancybox-container").each(function(){var e=n(this).data("FancyBox");e&&e.id!==t.id&&!e.isClosing&&(e.trigger("onDeactivate"),e.removeEvents(),e.isVisible=!1)}),t.isVisible=!0,(t.current||t.isIdle)&&(t.update(),t.updateControls()),t.trigger("onActivate"),t.addEvents()},close:function(t,e){var o,i,a,s,r,c,l,u=this,f=u.current,h=function(){u.cleanUp(t)};return!u.isClosing&&(u.isClosing=!0,!1===u.trigger("beforeClose",t)?(u.isClosing=!1,d(function(){u.update()}),!1):(u.removeEvents(),a=f.$content,o=f.opts.animationEffect,i=n.isNumeric(e)?e:o?f.opts.animationDuration:0,f.$slide.removeClass("fancybox-slide--complete fancybox-slide--next fancybox-slide--previous fancybox-animated"),!0!==t?n.fancybox.stop(f.$slide):o=!1,f.$slide.siblings().trigger("onReset").remove(),i&&u.$refs.container.removeClass("fancybox-is-open").addClass("fancybox-is-closing").css("transition-duration",i+"ms"),u.hideLoading(f),u.hideControls(!0),u.updateCursor(),"zoom"!==o||a&&i&&"image"===f.type&&!u.isMoved()&&!f.hasError&&(l=u.getThumbPos(f))||(o="fade"),"zoom"===o?(n.fancybox.stop(a),s=n.fancybox.getTranslate(a),c={top:s.top,left:s.left,scaleX:s.width/l.width,scaleY:s.height/l.height,width:l.width,height:l.height},r=f.opts.zoomOpacity,"auto"==r&&(r=Math.abs(f.width/f.height-l.width/l.height)>.1),r&&(l.opacity=0),n.fancybox.setTranslate(a,c),p(a),n.fancybox.animate(a,l,i,h),!0):(o&&i?n.fancybox.animate(f.$slide.addClass("fancybox-slide--previous").removeClass("fancybox-slide--current"),"fancybox-animated fancybox-fx-"+o,i,h):!0===t?setTimeout(h,i):h(),!0)))},cleanUp:function(e){var o,i,a,s=this,r=s.current.opts.$orig;s.current.$slide.trigger("onReset"),s.$refs.container.empty().remove(),s.trigger("afterClose",e),s.current.opts.backFocus&&(r&&r.length&&r.is(":visible")||(r=s.$trigger),r&&r.length&&(i=t.scrollX,a=t.scrollY,r.trigger("focus"),n("html, body").scrollTop(a).scrollLeft(i))),s.current=null,o=n.fancybox.getInstance(),o?o.activate():(n("body").removeClass("fancybox-active compensate-for-scrollbar"),n("#fancybox-style-noscroll").remove())},trigger:function(t,e){var o,i=Array.prototype.slice.call(arguments,1),a=this,s=e&&e.opts?e:a.current;if(s?i.unshift(s):s=a,i.unshift(a),n.isFunction(s.opts[t])&&(o=s.opts[t].apply(s,i)),!1===o)return o;"afterClose"!==t&&a.$refs?a.$refs.container.trigger(t+".fb",i):r.trigger(t+".fb",i)},updateControls:function(){var t=this,o=t.current,i=o.index,a=t.$refs.container,s=t.$refs.caption,r=o.opts.caption;o.$slide.trigger("refresh"),r&&r.length?(t.$caption=s,s.children().eq(0).html(r)):t.$caption=null,t.hasHiddenControls||t.isIdle||t.showControls(),a.find("[data-fancybox-count]").html(t.group.length),a.find("[data-fancybox-index]").html(i+1),a.find("[data-fancybox-prev]").prop("disabled",!o.opts.loop&&i<=0),a.find("[data-fancybox-next]").prop("disabled",!o.opts.loop&&i>=t.group.length-1),"image"===o.type?a.find("[data-fancybox-zoom]").show().end().find("[data-fancybox-download]").attr("href",o.opts.image.src||o.src).show():o.opts.toolbar&&a.find("[data-fancybox-download],[data-fancybox-zoom]").hide(),n(e.activeElement).is(":hidden,[disabled]")&&t.$refs.container.trigger("focus")},hideControls:function(t){var e=this,n=["infobar","toolbar","nav"];!t&&e.current.opts.preventCaptionOverlap||n.push("caption"),this.$refs.container.removeClass(n.map(function(t){return"fancybox-show-"+t}).join(" ")),this.hasHiddenControls=!0},showControls:function(){var t=this,e=t.current?t.current.opts:t.opts,n=t.$refs.container;t.hasHiddenControls=!1,t.idleSecondsCounter=0,n.toggleClass("fancybox-show-toolbar",!(!e.toolbar||!e.buttons)).toggleClass("fancybox-show-infobar",!!(e.infobar&&t.group.length>1)).toggleClass("fancybox-show-caption",!!t.$caption).toggleClass("fancybox-show-nav",!!(e.arrows&&t.group.length>1)).toggleClass("fancybox-is-modal",!!e.modal)},toggleControls:function(){this.hasHiddenControls?this.showControls():this.hideControls()}}),n.fancybox={version:"3.5.7",defaults:a,getInstance:function(t){var e=n('.fancybox-container:not(".fancybox-is-closing"):last').data("FancyBox"),o=Array.prototype.slice.call(arguments,1);return e instanceof b&&("string"===n.type(t)?e[t].apply(e,o):"function"===n.type(t)&&t.apply(e,o),e)},open:function(t,e,n){return new b(t,e,n)},close:function(t){var e=this.getInstance();e&&(e.close(),!0===t&&this.close(t))},destroy:function(){this.close(!0),r.add("body").off("click.fb-start","**")},isMobile:/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent),use3d:function(){var n=e.createElement("div");return t.getComputedStyle&&t.getComputedStyle(n)&&t.getComputedStyle(n).getPropertyValue("transform")&&!(e.documentMode&&e.documentMode<11)}(),getTranslate:function(t){var e;return!(!t||!t.length)&&(e=t[0].getBoundingClientRect(),{top:e.top||0,left:e.left||0,width:e.width,height:e.height,opacity:parseFloat(t.css("opacity"))})},setTranslate:function(t,e){var n="",o={};if(t&&e)return void 0===e.left&&void 0===e.top||(n=(void 0===e.left?t.position().left:e.left)+"px, "+(void 0===e.top?t.position().top:e.top)+"px",n=this.use3d?"translate3d("+n+", 0px)":"translate("+n+")"),void 0!==e.scaleX&&void 0!==e.scaleY?n+=" scale("+e.scaleX+", "+e.scaleY+")":void 0!==e.scaleX&&(n+=" scaleX("+e.scaleX+")"),n.length&&(o.transform=n),void 0!==e.opacity&&(o.opacity=e.opacity),void 0!==e.width&&(o.width=e.width),void 0!==e.height&&(o.height=e.height),t.css(o)},animate:function(t,e,o,i,a){var s,r=this;n.isFunction(o)&&(i=o,o=null),r.stop(t),s=r.getTranslate(t),t.on(f,function(c){(!c||!c.originalEvent||t.is(c.originalEvent.target)&&"z-index"!=c.originalEvent.propertyName)&&(r.stop(t),n.isNumeric(o)&&t.css("transition-duration",""),n.isPlainObject(e)?void 0!==e.scaleX&&void 0!==e.scaleY&&r.setTranslate(t,{top:e.top,left:e.left,width:s.width*e.scaleX,height:s.height*e.scaleY,scaleX:1,scaleY:1}):!0!==a&&t.removeClass(e),n.isFunction(i)&&i(c))}),n.isNumeric(o)&&t.css("transition-duration",o+"ms"),n.isPlainObject(e)?(void 0!==e.scaleX&&void 0!==e.scaleY&&(delete e.width,delete e.height,t.parent().hasClass("fancybox-slide--image")&&t.parent().addClass("fancybox-is-scaling")),n.fancybox.setTranslate(t,e)):t.addClass(e),t.data("timer",setTimeout(function(){t.trigger(f)},o+33))},stop:function(t,e){t&&t.length&&(clearTimeout(t.data("timer")),e&&t.trigger(f),t.off(f).css("transition-duration",""),t.parent().removeClass("fancybox-is-scaling"))}},n.fn.fancybox=function(t){var e;return t=t||{},e=t.selector||!1,e?n("body").off("click.fb-start",e).on("click.fb-start",e,{options:t},i):this.off("click.fb-start").on("click.fb-start",{items:this,options:t},i),this},r.on("click.fb-start","[data-fancybox]",i),r.on("click.fb-start","[data-fancybox-trigger]",function(t){n('[data-fancybox="'+n(this).attr("data-fancybox-trigger")+'"]').eq(n(this).attr("data-fancybox-index")||0).trigger("click.fb-start",{$trigger:n(this)})}),function(){var t=null;r.on("mousedown mouseup focus blur",".fancybox-button",function(e){switch(e.type){case"mousedown":t=n(this);break;case"mouseup":t=null;break;case"focusin":n(".fancybox-button").removeClass("fancybox-focus"),n(this).is(t)||n(this).is("[disabled]")||n(this).addClass("fancybox-focus");break;case"focusout":n(".fancybox-button").removeClass("fancybox-focus")}})}()}}(window,document,jQuery),function(t){"use strict";var e={youtube:{matcher:/(youtube\.com|youtu\.be|youtube\-nocookie\.com)\/(watch\?(.*&)?v=|v\/|u\/|embed\/?)?(videoseries\?list=(.*)|[\w-]{11}|\?listType=(.*)&list=(.*))(.*)/i,params:{autoplay:1,autohide:1,fs:1,rel:0,hd:1,wmode:"transparent",enablejsapi:1,html5:1},paramPlace:8,type:"iframe",url:"https://www.youtube-nocookie.com/embed/$4",thumb:"https://img.youtube.com/vi/$4/hqdefault.jpg"},vimeo:{matcher:/^.+vimeo.com\/(.*\/)?([\d]+)(.*)?/,params:{autoplay:1,hd:1,show_title:1,show_byline:1,show_portrait:0,fullscreen:1},paramPlace:3,type:"iframe",url:"//player.vimeo.com/video/$2"},instagram:{matcher:/(instagr\.am|instagram\.com)\/p\/([a-zA-Z0-9_\-]+)\/?/i,type:"image",url:"//$1/p/$2/media/?size=l"},gmap_place:{matcher:/(maps\.)?google\.([a-z]{2,3}(\.[a-z]{2})?)\/(((maps\/(place\/(.*)\/)?\@(.*),(\d+.?\d+?)z))|(\?ll=))(.*)?/i,type:"iframe",url:function(t){return"//maps.google."+t[2]+"/?ll="+(t[9]?t[9]+"&z="+Math.floor(t[10])+(t[12]?t[12].replace(/^\//,"&"):""):t[12]+"").replace(/\?/,"&")+"&output="+(t[12]&&t[12].indexOf("layer=c")>0?"svembed":"embed")}},gmap_search:{matcher:/(maps\.)?google\.([a-z]{2,3}(\.[a-z]{2})?)\/(maps\/search\/)(.*)/i,type:"iframe",url:function(t){return"//maps.google."+t[2]+"/maps?q="+t[5].replace("query=","q=").replace("api=1","")+"&output=embed"}}},n=function(e,n,o){if(e)return o=o||"","object"===t.type(o)&&(o=t.param(o,!0)),t.each(n,function(t,n){e=e.replace("$"+t,n||"")}),o.length&&(e+=(e.indexOf("?")>0?"&":"?")+o),e};t(document).on("objectNeedsType.fb",function(o,i,a){var s,r,c,l,d,u,f,p=a.src||"",h=!1;s=t.extend(!0,{},e,a.opts.media),t.each(s,function(e,o){if(c=p.match(o.matcher)){if(h=o.type,f=e,u={},o.paramPlace&&c[o.paramPlace]){d=c[o.paramPlace],"?"==d[0]&&(d=d.substring(1)),d=d.split("&");for(var i=0;i<d.length;++i){var s=d[i].split("=",2);2==s.length&&(u[s[0]]=decodeURIComponent(s[1].replace(/\+/g," ")))}}return l=t.extend(!0,{},o.params,a.opts[e],u),p="function"===t.type(o.url)?o.url.call(this,c,l,a):n(o.url,c,l),r="function"===t.type(o.thumb)?o.thumb.call(this,c,l,a):n(o.thumb,c),"youtube"===e?p=p.replace(/&t=((\d+)m)?(\d+)s/,function(t,e,n,o){return"&start="+((n?60*parseInt(n,10):0)+parseInt(o,10))}):"vimeo"===e&&(p=p.replace("&%23","#")),!1}}),h?(a.opts.thumb||a.opts.$thumb&&a.opts.$thumb.length||(a.opts.thumb=r),"iframe"===h&&(a.opts=t.extend(!0,a.opts,{iframe:{preload:!1,attr:{scrolling:"no"}}})),t.extend(a,{type:h,src:p,origSrc:a.src,contentSource:f,contentType:"image"===h?"image":"gmap_place"==f||"gmap_search"==f?"map":"video"})):p&&(a.type=a.opts.defaultType)});var o={youtube:{src:"https://www.youtube.com/iframe_api",class:"YT",loading:!1,loaded:!1},vimeo:{src:"https://player.vimeo.com/api/player.js",class:"Vimeo",loading:!1,loaded:!1},load:function(t){var e,n=this;if(this[t].loaded)return void setTimeout(function(){n.done(t)});this[t].loading||(this[t].loading=!0,e=document.createElement("script"),e.type="text/javascript",e.src=this[t].src,"youtube"===t?window.onYouTubeIframeAPIReady=function(){n[t].loaded=!0,n.done(t)}:e.onload=function(){n[t].loaded=!0,n.done(t)},document.body.appendChild(e))},done:function(e){var n,o,i;"youtube"===e&&delete window.onYouTubeIframeAPIReady,(n=t.fancybox.getInstance())&&(o=n.current.$content.find("iframe"),"youtube"===e&&void 0!==YT&&YT?i=new YT.Player(o.attr("id"),{events:{onStateChange:function(t){0==t.data&&n.next()}}}):"vimeo"===e&&void 0!==Vimeo&&Vimeo&&(i=new Vimeo.Player(o),i.on("ended",function(){n.next()})))}};t(document).on({"afterShow.fb":function(t,e,n){e.group.length>1&&("youtube"===n.contentSource||"vimeo"===n.contentSource)&&o.load(n.contentSource)}})}(jQuery),function(t,e,n){"use strict";var o=function(){return t.requestAnimationFrame||t.webkitRequestAnimationFrame||t.mozRequestAnimationFrame||t.oRequestAnimationFrame||function(e){return t.setTimeout(e,1e3/60)}}(),i=function(){return t.cancelAnimationFrame||t.webkitCancelAnimationFrame||t.mozCancelAnimationFrame||t.oCancelAnimationFrame||function(e){t.clearTimeout(e)}}(),a=function(e){var n=[];e=e.originalEvent||e||t.e,e=e.touches&&e.touches.length?e.touches:e.changedTouches&&e.changedTouches.length?e.changedTouches:[e];for(var o in e)e[o].pageX?n.push({x:e[o].pageX,y:e[o].pageY}):e[o].clientX&&n.push({x:e[o].clientX,y:e[o].clientY});return n},s=function(t,e,n){return e&&t?"x"===n?t.x-e.x:"y"===n?t.y-e.y:Math.sqrt(Math.pow(t.x-e.x,2)+Math.pow(t.y-e.y,2)):0},r=function(t){if(t.is('a,area,button,[role="button"],input,label,select,summary,textarea,video,audio,iframe')||n.isFunction(t.get(0).onclick)||t.data("selectable"))return!0;for(var e=0,o=t[0].attributes,i=o.length;e<i;e++)if("data-fancybox-"===o[e].nodeName.substr(0,14))return!0;return!1},c=function(e){var n=t.getComputedStyle(e)["overflow-y"],o=t.getComputedStyle(e)["overflow-x"],i=("scroll"===n||"auto"===n)&&e.scrollHeight>e.clientHeight,a=("scroll"===o||"auto"===o)&&e.scrollWidth>e.clientWidth;return i||a},l=function(t){for(var e=!1;;){if(e=c(t.get(0)))break;if(t=t.parent(),!t.length||t.hasClass("fancybox-stage")||t.is("body"))break}return e},d=function(t){var e=this;e.instance=t,e.$bg=t.$refs.bg,e.$stage=t.$refs.stage,e.$container=t.$refs.container,e.destroy(),e.$container.on("touchstart.fb.touch mousedown.fb.touch",n.proxy(e,"ontouchstart"))};d.prototype.destroy=function(){var t=this;t.$container.off(".fb.touch"),n(e).off(".fb.touch"),t.requestId&&(i(t.requestId),t.requestId=null),t.tapped&&(clearTimeout(t.tapped),t.tapped=null)},d.prototype.ontouchstart=function(o){var i=this,c=n(o.target),d=i.instance,u=d.current,f=u.$slide,p=u.$content,h="touchstart"==o.type;if(h&&i.$container.off("mousedown.fb.touch"),(!o.originalEvent||2!=o.originalEvent.button)&&f.length&&c.length&&!r(c)&&!r(c.parent())&&(c.is("img")||!(o.originalEvent.clientX>c[0].clientWidth+c.offset().left))){if(!u||d.isAnimating||u.$slide.hasClass("fancybox-animated"))return o.stopPropagation(),void o.preventDefault();i.realPoints=i.startPoints=a(o),i.startPoints.length&&(u.touch&&o.stopPropagation(),i.startEvent=o,i.canTap=!0,i.$target=c,i.$content=p,i.opts=u.opts.touch,i.isPanning=!1,i.isSwiping=!1,i.isZooming=!1,i.isScrolling=!1,i.canPan=d.canPan(),i.startTime=(new Date).getTime(),i.distanceX=i.distanceY=i.distance=0,i.canvasWidth=Math.round(f[0].clientWidth),i.canvasHeight=Math.round(f[0].clientHeight),i.contentLastPos=null,i.contentStartPos=n.fancybox.getTranslate(i.$content)||{top:0,left:0},i.sliderStartPos=n.fancybox.getTranslate(f),i.stagePos=n.fancybox.getTranslate(d.$refs.stage),i.sliderStartPos.top-=i.stagePos.top,i.sliderStartPos.left-=i.stagePos.left,i.contentStartPos.top-=i.stagePos.top,i.contentStartPos.left-=i.stagePos.left,n(e).off(".fb.touch").on(h?"touchend.fb.touch touchcancel.fb.touch":"mouseup.fb.touch mouseleave.fb.touch",n.proxy(i,"ontouchend")).on(h?"touchmove.fb.touch":"mousemove.fb.touch",n.proxy(i,"ontouchmove")),n.fancybox.isMobile&&e.addEventListener("scroll",i.onscroll,!0),((i.opts||i.canPan)&&(c.is(i.$stage)||i.$stage.find(c).length)||(c.is(".fancybox-image")&&o.preventDefault(),n.fancybox.isMobile&&c.parents(".fancybox-caption").length))&&(i.isScrollable=l(c)||l(c.parent()),n.fancybox.isMobile&&i.isScrollable||o.preventDefault(),(1===i.startPoints.length||u.hasError)&&(i.canPan?(n.fancybox.stop(i.$content),i.isPanning=!0):i.isSwiping=!0,i.$container.addClass("fancybox-is-grabbing")),2===i.startPoints.length&&"image"===u.type&&(u.isLoaded||u.$ghost)&&(i.canTap=!1,i.isSwiping=!1,i.isPanning=!1,i.isZooming=!0,n.fancybox.stop(i.$content),i.centerPointStartX=.5*(i.startPoints[0].x+i.startPoints[1].x)-n(t).scrollLeft(),i.centerPointStartY=.5*(i.startPoints[0].y+i.startPoints[1].y)-n(t).scrollTop(),i.percentageOfImageAtPinchPointX=(i.centerPointStartX-i.contentStartPos.left)/i.contentStartPos.width,i.percentageOfImageAtPinchPointY=(i.centerPointStartY-i.contentStartPos.top)/i.contentStartPos.height,i.startDistanceBetweenFingers=s(i.startPoints[0],i.startPoints[1]))))}},d.prototype.onscroll=function(t){var n=this;n.isScrolling=!0,e.removeEventListener("scroll",n.onscroll,!0)},d.prototype.ontouchmove=function(t){var e=this;return void 0!==t.originalEvent.buttons&&0===t.originalEvent.buttons?void e.ontouchend(t):e.isScrolling?void(e.canTap=!1):(e.newPoints=a(t),void((e.opts||e.canPan)&&e.newPoints.length&&e.newPoints.length&&(e.isSwiping&&!0===e.isSwiping||t.preventDefault(),e.distanceX=s(e.newPoints[0],e.startPoints[0],"x"),e.distanceY=s(e.newPoints[0],e.startPoints[0],"y"),e.distance=s(e.newPoints[0],e.startPoints[0]),e.distance>0&&(e.isSwiping?e.onSwipe(t):e.isPanning?e.onPan():e.isZooming&&e.onZoom()))))},d.prototype.onSwipe=function(e){var a,s=this,r=s.instance,c=s.isSwiping,l=s.sliderStartPos.left||0;if(!0!==c)"x"==c&&(s.distanceX>0&&(s.instance.group.length<2||0===s.instance.current.index&&!s.instance.current.opts.loop)?l+=Math.pow(s.distanceX,.8):s.distanceX<0&&(s.instance.group.length<2||s.instance.current.index===s.instance.group.length-1&&!s.instance.current.opts.loop)?l-=Math.pow(-s.distanceX,.8):l+=s.distanceX),s.sliderLastPos={top:"x"==c?0:s.sliderStartPos.top+s.distanceY,left:l},s.requestId&&(i(s.requestId),s.requestId=null),s.requestId=o(function(){s.sliderLastPos&&(n.each(s.instance.slides,function(t,e){var o=e.pos-s.instance.currPos;n.fancybox.setTranslate(e.$slide,{top:s.sliderLastPos.top,left:s.sliderLastPos.left+o*s.canvasWidth+o*e.opts.gutter})}),s.$container.addClass("fancybox-is-sliding"))});else if(Math.abs(s.distance)>10){if(s.canTap=!1,r.group.length<2&&s.opts.vertical?s.isSwiping="y":r.isDragging||!1===s.opts.vertical||"auto"===s.opts.vertical&&n(t).width()>800?s.isSwiping="x":(a=Math.abs(180*Math.atan2(s.distanceY,s.distanceX)/Math.PI),s.isSwiping=a>45&&a<135?"y":"x"),"y"===s.isSwiping&&n.fancybox.isMobile&&s.isScrollable)return void(s.isScrolling=!0);r.isDragging=s.isSwiping,s.startPoints=s.newPoints,n.each(r.slides,function(t,e){var o,i;n.fancybox.stop(e.$slide),o=n.fancybox.getTranslate(e.$slide),i=n.fancybox.getTranslate(r.$refs.stage),e.$slide.css({transform:"",opacity:"","transition-duration":""}).removeClass("fancybox-animated").removeClass(function(t,e){return(e.match(/(^|\s)fancybox-fx-\S+/g)||[]).join(" ")}),e.pos===r.current.pos&&(s.sliderStartPos.top=o.top-i.top,s.sliderStartPos.left=o.left-i.left),n.fancybox.setTranslate(e.$slide,{top:o.top-i.top,left:o.left-i.left})}),r.SlideShow&&r.SlideShow.isActive&&r.SlideShow.stop()}},d.prototype.onPan=function(){var t=this;if(s(t.newPoints[0],t.realPoints[0])<(n.fancybox.isMobile?10:5))return void(t.startPoints=t.newPoints);t.canTap=!1,t.contentLastPos=t.limitMovement(),t.requestId&&i(t.requestId),t.requestId=o(function(){n.fancybox.setTranslate(t.$content,t.contentLastPos)})},d.prototype.limitMovement=function(){var t,e,n,o,i,a,s=this,r=s.canvasWidth,c=s.canvasHeight,l=s.distanceX,d=s.distanceY,u=s.contentStartPos,f=u.left,p=u.top,h=u.width,g=u.height;return i=h>r?f+l:f,a=p+d,t=Math.max(0,.5*r-.5*h),e=Math.max(0,.5*c-.5*g),n=Math.min(r-h,.5*r-.5*h),o=Math.min(c-g,.5*c-.5*g),l>0&&i>t&&(i=t-1+Math.pow(-t+f+l,.8)||0),l<0&&i<n&&(i=n+1-Math.pow(n-f-l,.8)||0),d>0&&a>e&&(a=e-1+Math.pow(-e+p+d,.8)||0),d<0&&a<o&&(a=o+1-Math.pow(o-p-d,.8)||0),{top:a,left:i}},d.prototype.limitPosition=function(t,e,n,o){var i=this,a=i.canvasWidth,s=i.canvasHeight;return n>a?(t=t>0?0:t,t=t<a-n?a-n:t):t=Math.max(0,a/2-n/2),o>s?(e=e>0?0:e,e=e<s-o?s-o:e):e=Math.max(0,s/2-o/2),{top:e,left:t}},d.prototype.onZoom=function(){var e=this,a=e.contentStartPos,r=a.width,c=a.height,l=a.left,d=a.top,u=s(e.newPoints[0],e.newPoints[1]),f=u/e.startDistanceBetweenFingers,p=Math.floor(r*f),h=Math.floor(c*f),g=(r-p)*e.percentageOfImageAtPinchPointX,b=(c-h)*e.percentageOfImageAtPinchPointY,m=(e.newPoints[0].x+e.newPoints[1].x)/2-n(t).scrollLeft(),v=(e.newPoints[0].y+e.newPoints[1].y)/2-n(t).scrollTop(),y=m-e.centerPointStartX,x=v-e.centerPointStartY,w=l+(g+y),$=d+(b+x),S={top:$,left:w,scaleX:f,scaleY:f};e.canTap=!1,e.newWidth=p,e.newHeight=h,e.contentLastPos=S,e.requestId&&i(e.requestId),e.requestId=o(function(){n.fancybox.setTranslate(e.$content,e.contentLastPos)})},d.prototype.ontouchend=function(t){var o=this,s=o.isSwiping,r=o.isPanning,c=o.isZooming,l=o.isScrolling;if(o.endPoints=a(t),o.dMs=Math.max((new Date).getTime()-o.startTime,1),o.$container.removeClass("fancybox-is-grabbing"),n(e).off(".fb.touch"),e.removeEventListener("scroll",o.onscroll,!0),o.requestId&&(i(o.requestId),o.requestId=null),o.isSwiping=!1,o.isPanning=!1,o.isZooming=!1,o.isScrolling=!1,o.instance.isDragging=!1,o.canTap)return o.onTap(t);o.speed=100,o.velocityX=o.distanceX/o.dMs*.5,o.velocityY=o.distanceY/o.dMs*.5,r?o.endPanning():c?o.endZooming():o.endSwiping(s,l)},d.prototype.endSwiping=function(t,e){var o=this,i=!1,a=o.instance.group.length,s=Math.abs(o.distanceX),r="x"==t&&a>1&&(o.dMs>130&&s>10||s>50);o.sliderLastPos=null,"y"==t&&!e&&Math.abs(o.distanceY)>50?(n.fancybox.animate(o.instance.current.$slide,{top:o.sliderStartPos.top+o.distanceY+150*o.velocityY,opacity:0},200),i=o.instance.close(!0,250)):r&&o.distanceX>0?i=o.instance.previous(300):r&&o.distanceX<0&&(i=o.instance.next(300)),!1!==i||"x"!=t&&"y"!=t||o.instance.centerSlide(200),o.$container.removeClass("fancybox-is-sliding")},d.prototype.endPanning=function(){var t,e,o,i=this;i.contentLastPos&&(!1===i.opts.momentum||i.dMs>350?(t=i.contentLastPos.left,e=i.contentLastPos.top):(t=i.contentLastPos.left+500*i.velocityX,e=i.contentLastPos.top+500*i.velocityY),o=i.limitPosition(t,e,i.contentStartPos.width,i.contentStartPos.height),o.width=i.contentStartPos.width,o.height=i.contentStartPos.height,n.fancybox.animate(i.$content,o,366))},d.prototype.endZooming=function(){var t,e,o,i,a=this,s=a.instance.current,r=a.newWidth,c=a.newHeight;a.contentLastPos&&(t=a.contentLastPos.left,e=a.contentLastPos.top,i={top:e,left:t,width:r,height:c,scaleX:1,scaleY:1},n.fancybox.setTranslate(a.$content,i),r<a.canvasWidth&&c<a.canvasHeight?a.instance.scaleToFit(150):r>s.width||c>s.height?a.instance.scaleToActual(a.centerPointStartX,a.centerPointStartY,150):(o=a.limitPosition(t,e,r,c),n.fancybox.animate(a.$content,o,150)))},d.prototype.onTap=function(e){var o,i=this,s=n(e.target),r=i.instance,c=r.current,l=e&&a(e)||i.startPoints,d=l[0]?l[0].x-n(t).scrollLeft()-i.stagePos.left:0,u=l[0]?l[0].y-n(t).scrollTop()-i.stagePos.top:0,f=function(t){var o=c.opts[t];if(n.isFunction(o)&&(o=o.apply(r,[c,e])),o)switch(o){case"close":r.close(i.startEvent);break;case"toggleControls":r.toggleControls();break;case"next":r.next();break;case"nextOrClose":r.group.length>1?r.next():r.close(i.startEvent);break;case"zoom":"image"==c.type&&(c.isLoaded||c.$ghost)&&(r.canPan()?r.scaleToFit():r.isScaledDown()?r.scaleToActual(d,u):r.group.length<2&&r.close(i.startEvent))}};if((!e.originalEvent||2!=e.originalEvent.button)&&(s.is("img")||!(d>s[0].clientWidth+s.offset().left))){if(s.is(".fancybox-bg,.fancybox-inner,.fancybox-outer,.fancybox-container"))o="Outside";else if(s.is(".fancybox-slide"))o="Slide";else{if(!r.current.$content||!r.current.$content.find(s).addBack().filter(s).length)return;o="Content"}if(i.tapped){if(clearTimeout(i.tapped),i.tapped=null,Math.abs(d-i.tapX)>50||Math.abs(u-i.tapY)>50)return this;f("dblclick"+o)}else i.tapX=d,i.tapY=u,c.opts["dblclick"+o]&&c.opts["dblclick"+o]!==c.opts["click"+o]?i.tapped=setTimeout(function(){i.tapped=null,r.isAnimating||f("click"+o)},500):f("click"+o);return this}},n(e).on("onActivate.fb",function(t,e){e&&!e.Guestures&&(e.Guestures=new d(e))}).on("beforeClose.fb",function(t,e){e&&e.Guestures&&e.Guestures.destroy()})}(window,document,jQuery),function(t,e){"use strict";e.extend(!0,e.fancybox.defaults,{btnTpl:{slideShow:'<button data-fancybox-play class="fancybox-button fancybox-button--play" title="{{PLAY_START}}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M6.5 5.4v13.2l11-6.6z"/></svg><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M8.33 5.75h2.2v12.5h-2.2V5.75zm5.15 0h2.2v12.5h-2.2V5.75z"/></svg></button>'},slideShow:{autoStart:!1,speed:3e3,progress:!0}});var n=function(t){this.instance=t,this.init()};e.extend(n.prototype,{timer:null,isActive:!1,$button:null,init:function(){var t=this,n=t.instance,o=n.group[n.currIndex].opts.slideShow;t.$button=n.$refs.toolbar.find("[data-fancybox-play]").on("click",function(){t.toggle()}),n.group.length<2||!o?t.$button.hide():o.progress&&(t.$progress=e('<div class="fancybox-progress"></div>').appendTo(n.$refs.inner))},set:function(t){var n=this,o=n.instance,i=o.current;i&&(!0===t||i.opts.loop||o.currIndex<o.group.length-1)?n.isActive&&"video"!==i.contentType&&(n.$progress&&e.fancybox.animate(n.$progress.show(),{scaleX:1},i.opts.slideShow.speed),n.timer=setTimeout(function(){o.current.opts.loop||o.current.index!=o.group.length-1?o.next():o.jumpTo(0)},i.opts.slideShow.speed)):(n.stop(),o.idleSecondsCounter=0,o.showControls())},clear:function(){var t=this;clearTimeout(t.timer),t.timer=null,t.$progress&&t.$progress.removeAttr("style").hide()},start:function(){var t=this,e=t.instance.current;e&&(t.$button.attr("title",(e.opts.i18n[e.opts.lang]||e.opts.i18n.en).PLAY_STOP).removeClass("fancybox-button--play").addClass("fancybox-button--pause"),t.isActive=!0,e.isComplete&&t.set(!0),t.instance.trigger("onSlideShowChange",!0))},stop:function(){var t=this,e=t.instance.current;t.clear(),t.$button.attr("title",(e.opts.i18n[e.opts.lang]||e.opts.i18n.en).PLAY_START).removeClass("fancybox-button--pause").addClass("fancybox-button--play"),t.isActive=!1,t.instance.trigger("onSlideShowChange",!1),t.$progress&&t.$progress.removeAttr("style").hide()},toggle:function(){var t=this;t.isActive?t.stop():t.start()}}),e(t).on({"onInit.fb":function(t,e){e&&!e.SlideShow&&(e.SlideShow=new n(e))},"beforeShow.fb":function(t,e,n,o){var i=e&&e.SlideShow;o?i&&n.opts.slideShow.autoStart&&i.start():i&&i.isActive&&i.clear()},"afterShow.fb":function(t,e,n){var o=e&&e.SlideShow;o&&o.isActive&&o.set()},"afterKeydown.fb":function(n,o,i,a,s){var r=o&&o.SlideShow;!r||!i.opts.slideShow||80!==s&&32!==s||e(t.activeElement).is("button,a,input")||(a.preventDefault(),r.toggle())},"beforeClose.fb onDeactivate.fb":function(t,e){var n=e&&e.SlideShow;n&&n.stop()}}),e(t).on("visibilitychange",function(){var n=e.fancybox.getInstance(),o=n&&n.SlideShow;o&&o.isActive&&(t.hidden?o.clear():o.set())})}(document,jQuery),function(t,e){"use strict";var n=function(){for(var e=[["requestFullscreen","exitFullscreen","fullscreenElement","fullscreenEnabled","fullscreenchange","fullscreenerror"],["webkitRequestFullscreen","webkitExitFullscreen","webkitFullscreenElement","webkitFullscreenEnabled","webkitfullscreenchange","webkitfullscreenerror"],["webkitRequestFullScreen","webkitCancelFullScreen","webkitCurrentFullScreenElement","webkitCancelFullScreen","webkitfullscreenchange","webkitfullscreenerror"],["mozRequestFullScreen","mozCancelFullScreen","mozFullScreenElement","mozFullScreenEnabled","mozfullscreenchange","mozfullscreenerror"],["msRequestFullscreen","msExitFullscreen","msFullscreenElement","msFullscreenEnabled","MSFullscreenChange","MSFullscreenError"]],n={},o=0;o<e.length;o++){var i=e[o];if(i&&i[1]in t){for(var a=0;a<i.length;a++)n[e[0][a]]=i[a];return n}}return!1}();if(n){var o={request:function(e){e=e||t.documentElement,e[n.requestFullscreen](e.ALLOW_KEYBOARD_INPUT)},exit:function(){t[n.exitFullscreen]()},toggle:function(e){e=e||t.documentElement,this.isFullscreen()?this.exit():this.request(e)},isFullscreen:function(){return Boolean(t[n.fullscreenElement])},enabled:function(){return Boolean(t[n.fullscreenEnabled])}};e.extend(!0,e.fancybox.defaults,{btnTpl:{fullScreen:'<button data-fancybox-fullscreen class="fancybox-button fancybox-button--fsenter" title="{{FULL_SCREEN}}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M5 16h3v3h2v-5H5zm3-8H5v2h5V5H8zm6 11h2v-3h3v-2h-5zm2-11V5h-2v5h5V8z"/></svg></button>'},fullScreen:{autoStart:!1}}),e(t).on(n.fullscreenchange,function(){var t=o.isFullscreen(),n=e.fancybox.getInstance();n&&(n.current&&"image"===n.current.type&&n.isAnimating&&(n.isAnimating=!1,n.update(!0,!0,0),n.isComplete||n.complete()),n.trigger("onFullscreenChange",t),n.$refs.container.toggleClass("fancybox-is-fullscreen",t),n.$refs.toolbar.find("[data-fancybox-fullscreen]").toggleClass("fancybox-button--fsenter",!t).toggleClass("fancybox-button--fsexit",t))})}e(t).on({"onInit.fb":function(t,e){var i;if(!n)return void e.$refs.toolbar.find("[data-fancybox-fullscreen]").remove();e&&e.group[e.currIndex].opts.fullScreen?(i=e.$refs.container,i.on("click.fb-fullscreen","[data-fancybox-fullscreen]",function(t){t.stopPropagation(),t.preventDefault(),o.toggle()}),e.opts.fullScreen&&!0===e.opts.fullScreen.autoStart&&o.request(),e.FullScreen=o):e&&e.$refs.toolbar.find("[data-fancybox-fullscreen]").hide()},"afterKeydown.fb":function(t,e,n,o,i){e&&e.FullScreen&&70===i&&(o.preventDefault(),e.FullScreen.toggle())},"beforeClose.fb":function(t,e){e&&e.FullScreen&&e.$refs.container.hasClass("fancybox-is-fullscreen")&&o.exit()}})}(document,jQuery),function(t,e){"use strict";var n="fancybox-thumbs";e.fancybox.defaults=e.extend(!0,{btnTpl:{thumbs:'<button data-fancybox-thumbs class="fancybox-button fancybox-button--thumbs" title="{{THUMBS}}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M14.59 14.59h3.76v3.76h-3.76v-3.76zm-4.47 0h3.76v3.76h-3.76v-3.76zm-4.47 0h3.76v3.76H5.65v-3.76zm8.94-4.47h3.76v3.76h-3.76v-3.76zm-4.47 0h3.76v3.76h-3.76v-3.76zm-4.47 0h3.76v3.76H5.65v-3.76zm8.94-4.47h3.76v3.76h-3.76V5.65zm-4.47 0h3.76v3.76h-3.76V5.65zm-4.47 0h3.76v3.76H5.65V5.65z"/></svg></button>'},thumbs:{autoStart:!1,hideOnClose:!0,parentEl:".fancybox-container",axis:"y"}},e.fancybox.defaults);var o=function(t){this.init(t)};e.extend(o.prototype,{$button:null,$grid:null,$list:null,isVisible:!1,isActive:!1,init:function(t){var e=this,n=t.group,o=0;e.instance=t,e.opts=n[t.currIndex].opts.thumbs,t.Thumbs=e,e.$button=t.$refs.toolbar.find("[data-fancybox-thumbs]");for(var i=0,a=n.length;i<a&&(n[i].thumb&&o++,!(o>1));i++);o>1&&e.opts?(e.$button.removeAttr("style").on("click",function(){e.toggle()}),e.isActive=!0):e.$button.hide()},create:function(){var t,o=this,i=o.instance,a=o.opts.parentEl,s=[];o.$grid||(o.$grid=e('<div class="'+n+" "+n+"-"+o.opts.axis+'"></div>').appendTo(i.$refs.container.find(a).addBack().filter(a)),o.$grid.on("click","a",function(){i.jumpTo(e(this).attr("data-index"))})),o.$list||(o.$list=e('<div class="'+n+'__list">').appendTo(o.$grid)),e.each(i.group,function(e,n){t=n.thumb,t||"image"!==n.type||(t=n.src),s.push('<a href="javascript:;" tabindex="0" data-index="'+e+'"'+(t&&t.length?' style="background-image:url('+t+')"':'class="fancybox-thumbs-missing"')+"></a>")}),o.$list[0].innerHTML=s.join(""),"x"===o.opts.axis&&o.$list.width(parseInt(o.$grid.css("padding-right"),10)+i.group.length*o.$list.children().eq(0).outerWidth(!0))},focus:function(t){var e,n,o=this,i=o.$list,a=o.$grid;o.instance.current&&(e=i.children().removeClass("fancybox-thumbs-active").filter('[data-index="'+o.instance.current.index+'"]').addClass("fancybox-thumbs-active"),n=e.position(),"y"===o.opts.axis&&(n.top<0||n.top>i.height()-e.outerHeight())?i.stop().animate({scrollTop:i.scrollTop()+n.top},t):"x"===o.opts.axis&&(n.left<a.scrollLeft()||n.left>a.scrollLeft()+(a.width()-e.outerWidth()))&&i.parent().stop().animate({scrollLeft:n.left},t))},update:function(){var t=this;t.instance.$refs.container.toggleClass("fancybox-show-thumbs",this.isVisible),t.isVisible?(t.$grid||t.create(),t.instance.trigger("onThumbsShow"),t.focus(0)):t.$grid&&t.instance.trigger("onThumbsHide"),t.instance.update()},hide:function(){this.isVisible=!1,this.update()},show:function(){this.isVisible=!0,this.update()},toggle:function(){this.isVisible=!this.isVisible,this.update()}}),e(t).on({"onInit.fb":function(t,e){var n;e&&!e.Thumbs&&(n=new o(e),n.isActive&&!0===n.opts.autoStart&&n.show())},"beforeShow.fb":function(t,e,n,o){var i=e&&e.Thumbs;i&&i.isVisible&&i.focus(o?0:250)},"afterKeydown.fb":function(t,e,n,o,i){var a=e&&e.Thumbs;a&&a.isActive&&71===i&&(o.preventDefault(),a.toggle())},"beforeClose.fb":function(t,e){var n=e&&e.Thumbs;n&&n.isVisible&&!1!==n.opts.hideOnClose&&n.$grid.hide()}})}(document,jQuery),function(t,e){"use strict";function n(t){var e={"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;","/":"&#x2F;","`":"&#x60;","=":"&#x3D;"};return String(t).replace(/[&<>"'`=\/]/g,function(t){return e[t]})}e.extend(!0,e.fancybox.defaults,{btnTpl:{share:'<button data-fancybox-share class="fancybox-button fancybox-button--share" title="{{SHARE}}"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M2.55 19c1.4-8.4 9.1-9.8 11.9-9.8V5l7 7-7 6.3v-3.5c-2.8 0-10.5 2.1-11.9 4.2z"/></svg></button>'},share:{url:function(t,e){return!t.currentHash&&"inline"!==e.type&&"html"!==e.type&&(e.origSrc||e.src)||window.location},tpl:'<div class="fancybox-share"><h1>{{SHARE}}</h1><p><a class="fancybox-share__button fancybox-share__button--fb" href="https://www.facebook.com/sharer/sharer.php?u={{url}}"><svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="m287 456v-299c0-21 6-35 35-35h38v-63c-7-1-29-3-55-3-54 0-91 33-91 94v306m143-254h-205v72h196" /></svg><span>Facebook</span></a><a class="fancybox-share__button fancybox-share__button--tw" href="https://twitter.com/intent/tweet?url={{url}}&text={{descr}}"><svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="m456 133c-14 7-31 11-47 13 17-10 30-27 37-46-15 10-34 16-52 20-61-62-157-7-141 75-68-3-129-35-169-85-22 37-11 86 26 109-13 0-26-4-37-9 0 39 28 72 65 80-12 3-25 4-37 2 10 33 41 57 77 57-42 30-77 38-122 34 170 111 378-32 359-208 16-11 30-25 41-42z" /></svg><span>Twitter</span></a><a class="fancybox-share__button fancybox-share__button--pt" href="https://www.pinterest.com/pin/create/button/?url={{url}}&description={{descr}}&media={{media}}"><svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path d="m265 56c-109 0-164 78-164 144 0 39 15 74 47 87 5 2 10 0 12-5l4-19c2-6 1-8-3-13-9-11-15-25-15-45 0-58 43-110 113-110 62 0 96 38 96 88 0 67-30 122-73 122-24 0-42-19-36-44 6-29 20-60 20-81 0-19-10-35-31-35-25 0-44 26-44 60 0 21 7 36 7 36l-30 125c-8 37-1 83 0 87 0 3 4 4 5 2 2-3 32-39 42-75l16-64c8 16 31 29 56 29 74 0 124-67 124-157 0-69-58-132-146-132z" fill="#fff"/></svg><span>Pinterest</span></a></p><p><input class="fancybox-share__input" type="text" value="{{url_raw}}" onclick="select()" /></p></div>'}}),e(t).on("click","[data-fancybox-share]",function(){var t,o,i=e.fancybox.getInstance(),a=i.current||null;a&&("function"===e.type(a.opts.share.url)&&(t=a.opts.share.url.apply(a,[i,a])),o=a.opts.share.tpl.replace(/\{\{media\}\}/g,"image"===a.type?encodeURIComponent(a.src):"").replace(/\{\{url\}\}/g,encodeURIComponent(t)).replace(/\{\{url_raw\}\}/g,n(t)).replace(/\{\{descr\}\}/g,i.$caption?encodeURIComponent(i.$caption.text()):""),e.fancybox.open({src:i.translate(i,o),type:"html",opts:{touch:!1,animationEffect:!1,afterLoad:function(t,e){i.$refs.container.one("beforeClose.fb",function(){t.close(null,0)}),e.$content.find(".fancybox-share__button").click(function(){return window.open(this.href,"Share","width=550, height=450"),!1})},mobile:{autoFocus:!1}}}))})}(document,jQuery),function(t,e,n){"use strict";function o(){var e=t.location.hash.substr(1),n=e.split("-"),o=n.length>1&&/^\+?\d+$/.test(n[n.length-1])?parseInt(n.pop(-1),10)||1:1,i=n.join("-");return{hash:e,index:o<1?1:o,gallery:i}}function i(t){""!==t.gallery&&n("[data-fancybox='"+n.escapeSelector(t.gallery)+"']").eq(t.index-1).focus().trigger("click.fb-start")}function a(t){var e,n;return!!t&&(e=t.current?t.current.opts:t.opts,""!==(n=e.hash||(e.$orig?e.$orig.data("fancybox")||e.$orig.data("fancybox-trigger"):""))&&n)}n.escapeSelector||(n.escapeSelector=function(t){return(t+"").replace(/([\0-\x1f\x7f]|^-?\d)|^-$|[^\x80-\uFFFF\w-]/g,function(t,e){return e?"\0"===t?"�":t.slice(0,-1)+"\\"+t.charCodeAt(t.length-1).toString(16)+" ":"\\"+t})}),n(function(){!1!==n.fancybox.defaults.hash&&(n(e).on({"onInit.fb":function(t,e){var n,i;!1!==e.group[e.currIndex].opts.hash&&(n=o(),(i=a(e))&&n.gallery&&i==n.gallery&&(e.currIndex=n.index-1))},"beforeShow.fb":function(n,o,i,s){var r;i&&!1!==i.opts.hash&&(r=a(o))&&(o.currentHash=r+(o.group.length>1?"-"+(i.index+1):""),t.location.hash!=="#"+o.currentHash&&(s&&!o.origHash&&(o.origHash=t.location.hash),o.hashTimer&&clearTimeout(o.hashTimer),o.hashTimer=setTimeout(function(){"replaceState"in t.history?(t.history[s?"pushState":"replaceState"]({},e.title,t.location.pathname+t.location.search+"#"+o.currentHash),s&&(o.hasCreatedHistory=!0)):t.location.hash=o.currentHash,o.hashTimer=null},300)))},"beforeClose.fb":function(n,o,i){i&&!1!==i.opts.hash&&(clearTimeout(o.hashTimer),o.currentHash&&o.hasCreatedHistory?t.history.back():o.currentHash&&("replaceState"in t.history?t.history.replaceState({},e.title,t.location.pathname+t.location.search+(o.origHash||"")):t.location.hash=o.origHash),o.currentHash=null)}}),n(t).on("hashchange.fb",function(){var t=o(),e=null;n.each(n(".fancybox-container").get().reverse(),function(t,o){var i=n(o).data("FancyBox");if(i&&i.currentHash)return e=i,!1}),e?e.currentHash===t.gallery+"-"+t.index||1===t.index&&e.currentHash==t.gallery||(e.currentHash=null,e.close()):""!==t.gallery&&i(t)}),setTimeout(function(){n.fancybox.getInstance()||i(o())},50))})}(window,document,jQuery),function(t,e){"use strict";var n=(new Date).getTime();e(t).on({"onInit.fb":function(t,e,o){}})}(document,jQuery);
    </script>
        <script>
            /**
             * Custom Application Javascript
             */

            function sleep(ms) {
                return new Promise((resolve) => setTimeout(resolve, ms));
            }

            let medcrypt = {
                loadedCache: {},
                transfers: {
                    loaded: 0,
                    size: 0,
                },
                displayProgress: function () {
                    if ($("#progress").length === 0) {
                        $("body").append(
                            $("<div><b></b><i></i></div>").attr(
                                "id",
                                "progress",
                            ),
                        );
                    }
                    var progress = Math.round(
                        (medcrypt.transfers.loaded / medcrypt.transfers.size) *
                            100,
                    );
                    $("#progress")
                        .width(progress + "%")
                        .delay(800);
                    if (progress >= 100) {
                        $("#progress").fadeOut(1000, function () {
                            $(this).remove();
                        });
                    }
                },
                finishProgress: function () {
                    medcrypt.transfers.loaded = 0;
                    medcrypt.transfers.size = 0;
                    $("#progress").fadeOut(1000, function () {
                        $(this).remove();
                    });
                },
                extToMimes: {
                    jpg: "image/jpeg",
                    jpeg: "image/jpeg",
                    gif: "image/gif",
                    png: "image/png",
                    mp4: "video/mp4",
                    webm: "video/webm",
                    js: "application/javascript",
                    txt: "text/plain",
                },
                getMime: function (resource) {
                    var re = /(?:\.([^.]+))?$/;
                    var ext = re.exec(resource)[1];
                    if (medcrypt.extToMimes.hasOwnProperty(ext)) {
                        return medcrypt.extToMimes[ext];
                    }
                    return "application/octect-stream";
                },
                isImage: function (resource) {
                    var re = /(?:\.([^.]+))?$/;
                    var ext = re.exec(resource)[1];
                    return ["jpg", "jpeg", "png"].includes(ext);
                },
                isVideo: function (resource) {
                    var re = /(?:\.([^.]+))?$/;
                    var ext = re.exec(resource)[1];
                    return ["mp4", "webm", "gif"].includes(ext);
                },
                requestQueue: [],
                dirCache: {},
                startQueue: async () => {
                    var chunkSize = 8;
                    while (medcrypt.requestQueue.length) {
                        if (medcrypt.requestQueue.length >= chunkSize) {
                            await Promise.allSettled(
                                Array(chunkSize)
                                    .fill()
                                    .map((v, i) => {
										const item = medcrypt.requestQueue.shift();
										if (typeof item == 'function') {
											return item();
										}
										return item;
                                    }

                                    ),
                            );
                        } else {
                            const item = medcrypt.requestQueue.shift();
							if (typeof item == 'function') {
								await item();
							}
                        }
                    }
                },
                flushQueue: () => {
                    medcrypt.requestQueue = [];
                },
                processingQueue: false,
                maxTries: 2,
                getSrc: function (
                    resource,
                    action = () => {},
                    currentTries = 0,
					) {
                    medcrypt.requestQueue.push(async () => {
                        return new Promise(async (resolve, reject) => {
                            if (medcrypt.loadedCache[resource]) {
                                return resolve(medcrypt.loadedCache[resource]);
                            }

                            if (resource.includes("blob:")) {
                                return resolve(resource);
                            }


                            if (usingRapiServe.length) {
								let targetSrc = usingRapiServe + "/" + resource;
								if (resource.startsWith(usingRapiServe + "/")) {
									targetSrc = resource;
								}
								return fetch(targetSrc, { headers: { 'range': 'bytes=0-1'}}).then(function(response) {
								    if (!response.ok) {
								        throw new Error("HTTP status " + response.status);
								    }
									return true
								}).then(() => {
									resolve(targetSrc);
								}).catch((e) => reject(e));
                            }

                            try {
                                return resolve(
                                    window.rcloneCrypt.render(
                                        await window.rcloneCrypt.decrypt(
                                            "crypt/" +
                                                (await window.rcloneCrypt.encryptPath(
                                                    resource,
                                                    passphrase,
                                                )),
                                            passphrase,
                                        ),
                                        "",
                                        false,
                                        false,
                                    ),
                                );
                            } catch (e) {
                                reject(e);
                            }
                        })
                            .then((success) => {
                                return Promise.resolve(success);
                            })
                            .then(action)
                            .catch((err) => {
                                medcrypt.finishProgress();
                                if (currentTries < medcrypt.maxTries) {
									let delay = currentTries + 1 * 1000;
									setTimeout(() => medcrypt.getSrc(
                                        resource,
                                        action,
                                        currentTries + 1,
                                    ), delay);

                                } else {
                                    action(resource);
                                }
                            });
						});
					medcrypt.processingQueue = medcrypt.startQueue();
                    return medcrypt.processingQueue;
                },
            };

            function setWithExpiry(key, value, ttl) {
                const now = new Date();
                const item = {
                    value: value,
                    expiry: now.getTime() + ttl,
                };
                localStorage.setItem(key, JSON.stringify(item));
            }

            function getWithExpiry(key) {
                const itemStr = localStorage.getItem(key);
                if (!itemStr) {
                    return null;
                }
                const item = JSON.parse(itemStr);
                const now = new Date();
                if (now.getTime() > item.expiry) {
                    localStorage.removeItem(key);
                    return null;
                }
                return item.value;
            }

            window.idleTimer = setInterval(() => {
                if (
                    typeof passphrase !== "undefined" &&
                    passphrase.length < 512
                ) {
                    setWithExpiry(
                        "offline-viewer-pass",
                        passphrase,
                        5 * 60 * 1000,
                    );
                }
            }, 30 * 1000);
            window.idleTimer = setInterval(() => {
                for (const [dir, cache] of Object.entries(medcrypt.dirCache)) {
                    setWithExpiry(
                        "offline-viewer-dirs-" + dir,
                        cache,
                        7 * 24 * 60 * 60 * 1000,
                    );
                }
            }, 5 * 1000);

            function loadScript(url, callback) {
                var head = document.head;
                var script = document.createElement("script");
                script.type = "text/javascript";
                script.src = url;
                script.onload = callback;
                head.appendChild(script);
            }

            window.mobileCheck = function () {
                if (window.location.hash.includes("mobile")) {
                    return true;
                }
                let check = false;
                (function (a) {
                    if (
                        /(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i.test(
                            a,
                        ) ||
                        /1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(
                            a.substr(0, 4),
                        )
                    )
                        check = true;
                })(navigator.userAgent || navigator.vendor || window.opera);
                return check;
            };

            function imgError(img) {
                if (jQuery(img).parent().data("type") !== "video") {
                    jQuery(img).parent().remove();
                }
            }

            function imgLoad(img) {
                jQuery(img).parent().show();
                var source = jQuery(img).attr("src");
                window.getGifDuration(source).then((duration) => {
                    jQuery(`img[src="${source}"]`).attr(
                        "data-duration",
                        duration,
                    );
                });
            }

            const md5 = inputString => {
               const hc = '0123456789abcdef';
               const rh = n => {let j,s='';for(j=0;j<=3;j++) s+=hc.charAt((n>>(j*8+4))&0x0F)+hc.charAt((n>>(j*8))&0x0F);return s;}
               const ad = (x,y) => {let l=(x&0xFFFF)+(y&0xFFFF);let m=(x>>16)+(y>>16)+(l>>16);return (m<<16)|(l&0xFFFF);}
               const rl = (n,c) => (n<<c)|(n>>>(32-c));
               const cm = (q,a,b,x,s,t) => ad(rl(ad(ad(a,q),ad(x,t)),s),b);
               const ff = (a,b,c,d,x,s,t) => cm((b&c)|((~b)&d),a,b,x,s,t);
               const gg = (a,b,c,d,x,s,t) => cm((b&d)|(c&(~d)),a,b,x,s,t);
               const hh = (a,b,c,d,x,s,t) => cm(b^c^d,a,b,x,s,t);
               const ii = (a,b,c,d,x,s,t) => cm(c^(b|(~d)),a,b,x,s,t);
               const sb = x => {
                  let i;const nblk=((x.length+8)>>6)+1;const blks=[];for(i=0;i<nblk*16;i++) { blks[i]=0 };
                  for(i=0;i<x.length;i++) {blks[i>>2]|=x.charCodeAt(i)<<((i%4)*8);}
                  blks[i>>2]|=0x80<<((i%4)*8);blks[nblk*16-2]=x.length*8;return blks;
               }
               let i,x=sb(inputString),a=1732584193,b=-271733879,c=-1732584194,d=271733878,olda,oldb,oldc,oldd;
               for(i=0;i<x.length;i+=16) {olda=a;oldb=b;oldc=c;oldd=d;
                  a=ff(a,b,c,d,x[i+ 0], 7, -680876936);d=ff(d,a,b,c,x[i+ 1],12, -389564586);c=ff(c,d,a,b,x[i+ 2],17,  606105819);
                  b=ff(b,c,d,a,x[i+ 3],22,-1044525330);a=ff(a,b,c,d,x[i+ 4], 7, -176418897);d=ff(d,a,b,c,x[i+ 5],12, 1200080426);
                  c=ff(c,d,a,b,x[i+ 6],17,-1473231341);b=ff(b,c,d,a,x[i+ 7],22,  -45705983);a=ff(a,b,c,d,x[i+ 8], 7, 1770035416);
                  d=ff(d,a,b,c,x[i+ 9],12,-1958414417);c=ff(c,d,a,b,x[i+10],17,     -42063);b=ff(b,c,d,a,x[i+11],22,-1990404162);
                  a=ff(a,b,c,d,x[i+12], 7, 1804603682);d=ff(d,a,b,c,x[i+13],12,  -40341101);c=ff(c,d,a,b,x[i+14],17,-1502002290);
                  b=ff(b,c,d,a,x[i+15],22, 1236535329);a=gg(a,b,c,d,x[i+ 1], 5, -165796510);d=gg(d,a,b,c,x[i+ 6], 9,-1069501632);
                  c=gg(c,d,a,b,x[i+11],14,  643717713);b=gg(b,c,d,a,x[i+ 0],20, -373897302);a=gg(a,b,c,d,x[i+ 5], 5, -701558691);
                  d=gg(d,a,b,c,x[i+10], 9,   38016083);c=gg(c,d,a,b,x[i+15],14, -660478335);b=gg(b,c,d,a,x[i+ 4],20, -405537848);
                  a=gg(a,b,c,d,x[i+ 9], 5,  568446438);d=gg(d,a,b,c,x[i+14], 9,-1019803690);c=gg(c,d,a,b,x[i+ 3],14, -187363961);
                  b=gg(b,c,d,a,x[i+ 8],20, 1163531501);a=gg(a,b,c,d,x[i+13], 5,-1444681467);d=gg(d,a,b,c,x[i+ 2], 9,  -51403784);
                  c=gg(c,d,a,b,x[i+ 7],14, 1735328473);b=gg(b,c,d,a,x[i+12],20,-1926607734);a=hh(a,b,c,d,x[i+ 5], 4,    -378558);
                  d=hh(d,a,b,c,x[i+ 8],11,-2022574463);c=hh(c,d,a,b,x[i+11],16, 1839030562);b=hh(b,c,d,a,x[i+14],23,  -35309556);
                  a=hh(a,b,c,d,x[i+ 1], 4,-1530992060);d=hh(d,a,b,c,x[i+ 4],11, 1272893353);c=hh(c,d,a,b,x[i+ 7],16, -155497632);
                  b=hh(b,c,d,a,x[i+10],23,-1094730640);a=hh(a,b,c,d,x[i+13], 4,  681279174);d=hh(d,a,b,c,x[i+ 0],11, -358537222);
                  c=hh(c,d,a,b,x[i+ 3],16, -722521979);b=hh(b,c,d,a,x[i+ 6],23,   76029189);a=hh(a,b,c,d,x[i+ 9], 4, -640364487);
                  d=hh(d,a,b,c,x[i+12],11, -421815835);c=hh(c,d,a,b,x[i+15],16,  530742520);b=hh(b,c,d,a,x[i+ 2],23, -995338651);
                  a=ii(a,b,c,d,x[i+ 0], 6, -198630844);d=ii(d,a,b,c,x[i+ 7],10, 1126891415);c=ii(c,d,a,b,x[i+14],15,-1416354905);
                  b=ii(b,c,d,a,x[i+ 5],21,  -57434055);a=ii(a,b,c,d,x[i+12], 6, 1700485571);d=ii(d,a,b,c,x[i+ 3],10,-1894986606);
                  c=ii(c,d,a,b,x[i+10],15,   -1051523);b=ii(b,c,d,a,x[i+ 1],21,-2054922799);a=ii(a,b,c,d,x[i+ 8], 6, 1873313359);
                  d=ii(d,a,b,c,x[i+15],10,  -30611744);c=ii(c,d,a,b,x[i+ 6],15,-1560198380);b=ii(b,c,d,a,x[i+13],21, 1309151649);
                  a=ii(a,b,c,d,x[i+ 4], 6, -145523070);d=ii(d,a,b,c,x[i+11],10,-1120210379);c=ii(c,d,a,b,x[i+ 2],15,  718787259);
                  b=ii(b,c,d,a,x[i+ 9],21, -343485551);a=ad(a,olda);b=ad(b,oldb);c=ad(c,oldc);d=ad(d,oldd);
               }
               return rh(a)+rh(b)+rh(c)+rh(d);
            }

            function saveAlbumPosition(position, album) {
                let cache = window.localStorage.getItem("PDLAP");
                let store = {};
                if (cache !== null) {
                    try {
                        let decoded = atob(cache);
                        store = JSON.parse(decoded);
                    } catch (e) {

                    }
                }
                store[md5(album)] = position;
                                window.currentAlbumPosition = position;
                window.localStorage.setItem("PDLAP",
                btoa(JSON.stringify(store)));
            }

            function loadAlbumPosition(album) {
              let cache = window.localStorage.getItem("PDLAP");
              let store = {};
              if (cache !== null) {
                  try {
                      let decoded = atob(cache);
                      store = JSON.parse(decoded);
                  } catch (e) {

                  }
              }
              if (store[md5(album)]) {
                  return store[md5(album)];
              }
              return {src: false, currentTime: 0}
            }

            function updatePlaybackTimestamp(src, album, video) {
              let currentTime = video.currentTime;
              if (video.currentTime >= video.duration * 0.99) {
                  currentTime = 0;
              }
              saveAlbumPosition({src, currentTime}, album);
            }

            function searchifica(items, query, keys) {
            if (keys.length == 0 && items.length > 0) {
                keys = Object.keys(items[0]);
            }

            function determineOperation(part) {
                let potentialOperator = part.substring(0, 1);
                if (potentialOperator === "~") {
                targetOperation = "anys";
                } else if (potentialOperator === "!" || potentialOperator === "-") {
                targetOperation = "excludes";
                } else {
                targetOperation = "alls";
                }

                return targetOperation;
            }

            let query_parts = query
                .toLowerCase()
                .split(" ")
                .reduce(
                (acc, part) => {
                    if (part.includes('"') && !acc.quoteOpen) {
                    acc.quoteOpen = true;
                    acc.quotePartials = [];
                    acc.targetOperator = determineOperation(part);
                    acc.quotePartials.push(
                        acc.targetOperator === "alls"
                        ? part.substring(1, part.length)
                        : part.substring(2, part.length)
                    );
                    } else if (acc.quoteOpen && !part.includes('"')) {
                    acc.quotePartials.push(part);
                    } else if (part.includes('"') && acc.quoteOpen) {
                    acc.quotePartials.push(part.substring(0, part.length - 1));
                    acc[acc.targetOperator].push(acc.quotePartials.join(" "));
                    acc.quotePartials = [];
                    acc.quoteOpen = false;
                    } else {
                    acc.targetOperator = determineOperation(part);
                    acc[acc.targetOperator].push(
                        acc.targetOperator === "alls"
                        ? part
                        : part.substring(1, part.length)
                    );
                    }
                    return acc;
                },
                { alls: [], anys: [], excludes: [] }
                );

            // console.log({ msg: "performing search", query_parts });
            var keyMatches = [];
            function itsamatch(value) {
                value = value.toLowerCase().split(" ");
                for (let exclude of query_parts.excludes) {
                if (
                    (exclude.includes(" ") && value.join(" ").includes(exclude)) ||
                    value.filter((v) => v.includes(exclude)).length > 0
                ) {
                    return {score: -1, matches: []};
                }
                }
                let matches = [];
                let orMatch = false;
                for (let any of query_parts.anys) {
                if (
                    (any.includes(" ") && value.join(" ").includes(any)) ||
                    value.filter((v) => v.includes(any)).length > 0
                ) {
                    orMatch = true;
                    matches.push(value.join(" ").indexOf(any));
                }
                }
                if (!orMatch && query_parts.anys.length > 0) {
                return {score: 0, matches: []};
                }

                let missimgAndMatch = false;
                for (let all of query_parts.alls) {
                if (
                    (all.includes(" ") && !value.join(" ").includes(all)) ||
                    value.filter((v) => v.includes(all)).length == 0
                ) {
                    missimgAndMatch = true;
                } else {
                    matches.push(value.join(" ").indexOf(all));
                }
                }

                if (missimgAndMatch && query_parts.alls.length > 0) {
                return {score: 0, matches: []};
                }
                return {score: 1, matches};
            }
            var filtered = items.reduce((acc, item) => {
                let subject = keys.map((key) => {
                if (!Object.keys(item).includes(key) || item[key] == null) {
                    return {key, subject: '', length: 0};
                }
                return {key, subject: item[key], length: item[key].length};
                });
                let combinedSubject = subject.map((i) => i.subject).join(' ');
                let match = itsamatch(combinedSubject);
                if (match["score"] > 0) {
                match.matches.sort();
                let matchedKeys = {};
                let offset = 0;
                for(let i = 0; i < subject.length;i++) {
                    match.matches.forEach((pos) => {
                    if(pos >= offset && pos < subject[i].length + offset) {
                        matchedKeys[subject[i].key] = 1;
                    }
                    })
                    offset += subject[i].length + 1;
                }
                acc.push({item, matchedKeys: Object.keys(matchedKeys)});
                }
                return acc;
            }, []);
            filtered.sort(
                (a, b) => keys.indexOf(a.matchedKeys[0]) - keys.indexOf(b.matchedKeys[0])
            );
            filtered = filtered.map((item) => item.item);
            return filtered;
            }
        </script>
    </head>
    <body>
        <div id="content">
            <input
                type="password"
                class="form-control"
                id="downloadPassword"
                placeholder="Password"
            />
            <button class="btn btn-primary" id="unlockBtn">Unlock</button>
        </div>
        <div id="searchBox">
            <button class="btn btn-secondary" id="backBtn">Back</button
            ><input
                type="search"
                class="form-control"
                id="searchInput"
                placeholder="Search"
            />
        </div>
        <script src="index.php?RapiServe=1"></script>
        <script src="../index.php?RapiServe=1"></script>
        <script>
            var usingRapiServe =
                typeof canrapiserve !== "undefined"
                    ? canrapiserve === true
                        ? "index.php"
                        : canrapiserve
                    : window.location.hash.includes("rapiserve")
                      ? "index.php"
                      : "";

            function init() {
                medcrypt.getSrc(
                    window.mobileCheck()
                        ? "ZW/ZW5jcnlwdGVkX21vYmlsZV9kYXRh.js"
                        : "ZW/ZW5jcnlwdGVkX2RhdGE=.js",
                    (encrypted_data_file) => {
                        loadScript(encrypted_data_file, runApp);
                    },
                );
            }

            var unlock = function (setPassword = true) {
                if (setPassword) {
                    passphrase = $("#downloadPassword").val();
                }
                if (usingRapiServe.length) {
                    var formData = new FormData();
                    formData.append("key", btoa(passphrase));
										fetch(usingRapiServe, {
                        method: "POST",
                        body: formData,
                        headers: {
                            X_AUTH_KEY: btoa(passphrase),
                        },
                    }).then(() => {
                        $("#content").empty();
                        init();
                    });
                } else {
                    $("#content").empty();
                    init();
                }
            };
            $("#downloadPassword").on("keyup", function (e) {
                if (e.key === "Enter" || e.keyCode === 13) {
                    unlock();
                }
            });
            $("#unlockBtn").on("click", unlock);

            var passphrase = getWithExpiry("offline-viewer-pass") ?? "";
            if (passphrase.length > 0) {
                unlock(false);
            }

            !function(n,t){"object"==typeof exports&&"undefined"!=typeof module?module.exports=t():"function"==typeof define&&define.amd?define(t):(n=n||self).sha1=t()}(this,(function(){"use strict";var n,t=String.fromCharCode;function r(n){return n=function(n,t,r){if(!t&&!r&&n instanceof Uint8Array&&!n.copy)return n;t>>>=0,null==r&&(r=n.byteLength-t);return new Uint8Array(n.buffer,n.byteOffset+t,r)}(n),t.apply(String,n)}function e(n,t){if("string"==typeof n)return n;if(n=r(n),!1!==t&&(e=n,!c.test(e)))if(t)n=s(n);else if(null==t)try{n=s(n)}catch(n){}var e;return n}function i(n,t){n=String(n),null==t&&(t=function(n){var t=a.exec(n);return!!t&&t[1]}(n)),t&&(n=function(n){return unescape(encodeURI(n))}(n));for(var r=n.length,e=new Uint8Array(r);r--;)e[r]=n.charCodeAt(r);return e}function f(n){switch(n){case!1:case"binary":return r(this);case"hex":return i=(t=this).BYTES_PER_ELEMENT<<1,t.reduce((function(n,t){return n+(t>>>0).toString(16).padStart(i,"0")}),"");case"base64":return btoa(r(this));case"utf8":n=!0}var t,i;return e(this,n)}function u(){return void 0!==n||(n=!!new Uint8Array(new Uint16Array([1]).buffer)[0],u=function(){return n}),n}function o(n){return(255&n)<<24|(65280&n)<<8|n>>8&65280|n>>24&255}var a=/([^\x00-\xFF])/,c=/^[\x00-\x7F]*$/;function s(n){return decodeURIComponent(escape(n))}return function(n,t){var r=n&&n.BYTES_PER_ELEMENT?n:i(n,t);return(r=function(n){var t,r,e,i,f,a,c=n.byteLength,s=0,y=Uint32Array.from([t=1732584193,r=4023233417,~t,~r,3285377520]),d=new Uint32Array(80),h=c/4+2|15,p=new Uint32Array(h+1);for(p[h]=8*c,p[c>>2]|=128<<(~c<<3);c--;)p[c>>2]|=n[c]<<(~c<<3);for(t=y.slice();s<h;s+=16,t.set(y)){for(c=0;c<80;t[0]=(f=((n=t[0])<<5|n>>>27)+t[4]+(d[c]=c<16?p[s+c]:f<<1|f>>>31)+1518500249,r=t[1],e=t[2],i=t[3],f+((a=c/5>>2)?2!=a?(r^e^i)+(2&a?1876969533:341275144):882459459+(r&e|r&i|e&i):r&e|~r&i)),t[1]=n,t[2]=r<<30|r>>>2,t[3]=e,t[4]=i,++c)f=d[c-3]^d[c-8]^d[c-14]^d[c-16];for(c=5;c;)y[--c]=y[c]+t[c]}return u()&&(y=y.map(o)),new Uint8Array(y.buffer,y.byteOffset,y.byteLength)}(r)).toString=f,r}}));


             function rollingTokens(seed) {
               let interval = 30;
               let count = 2;

               interval = Math.max(1, Math.min(interval, 60));
               count = Math.max(1, count);

               let tokens = [];

               let utc = (new Date()).getTime();
               for (let iteration = 0; iteration <= count; iteration++) {
                   let modifiedMinutes = iteration * interval;
                   let date = new Date(utc - (modifiedMinutes * 60 * 1000));
                   date.setMinutes(Math.round(date.getMinutes() / interval) * interval, 0, 0);
                   let seconds = Math.round(date.getTime() / 1000);
                   tokens.push(sha1(md5(seconds.toString()) + "-" + seed + "-").toString('hex'));
               }

               for (let iteration = 1; iteration <= count; iteration++) {
                    let modifiedMinutes = iteration * interval;
                    let date = new Date(utc + (modifiedMinutes * 60 * 1000));
                    date.setMinutes(Math.round(date.getMinutes() / interval) * interval, 0, 0);
                    let seconds = Math.round(date.getTime() / 1000);
                    tokens.push(sha1(md5(seconds.toString()) + "-" + seed + "-").toString('hex'));
               }

               return tokens;
             }
             $.fancybox.defaults.hash = false;
             $.fancybox.defaults.btnTpl.favorite = '<button data-fancybox-favorite class="fancybox-button fancybox-button--favorite">&hearts;</button>';
             $('body').on('click', '[data-fancybox-favorite]', function(e) {
                  let hash = $.fancybox.getInstance().current.$thumb.data('src').split('/').reverse()[0].split(".")[0];
                  let tokens = rollingTokens(passphrase);
                  let token = tokens[Math.floor(tokens.length / 2)];
                  let call = `{{ENDPOINT}}?favorite=${hash}&token=${token}`;
                  $.ajax(call).then((data) => {
                      toast.info(data);
                  }).catch(() => {
                      toast.error("Something went wrong");
                  });
             });


            window.groupByKey = (list, key) =>
                list.reduce(
                    (hash, obj) => ({
                        ...hash,
                        [obj[key]]: (hash[obj[key]] || []).concat(obj),
                    }),
                    {},
                );

            let audio = document.createElement("audio");
            let globalWakeLock = null;
            function preventSleep() {
                try {
                    navigator.wakeLock
                        .request("screen")
                        .then((wakeLock) => {
                            globalWakeLock = wakeLock;
                        })
                        .catch((err) => {
                            throw err;
                        });
                } catch (err) {
                    try {
                        let ctx = new AudioContext();
                        let bufferSize = 2 * ctx.sampleRate,
                            emptyBuffer = ctx.createBuffer(
                                1,
                                bufferSize,
                                ctx.sampleRate,
                            ),
                            output = emptyBuffer.getChannelData(0);
                        for (let i = 0; i < bufferSize; i++) output[i] = 0;

                        let source = ctx.createBufferSource();
                        source.buffer = emptyBuffer;
                        source.loop = true;
                        let node = ctx.createMediaStreamDestination();
                        source.connect(node);
                        audio.style.display = "none";
                        document.body.appendChild(audio);
                        audio.srcObject = node.stream;
                        audio.play();
                    } catch (ierr) {}
                }
            }

            function allowSleep() {
                if (globalWakeLock != null) {
                    globalWakeLock.release();
                }
                try {
                    audio.pause();
                    document.body.removeChild(audio);
                } catch (err) {}
            }

            function runApp() {
                var delim = "1--57--2";
                var alldata = window.mobileCheck()
                    ? JSON.parse(encrypted_data)
                    : encrypted_data;

                if (window.location.hash.includes("mobile")) {
                    window.location.hash = "";
                }
                var allAlbums = window.groupByKey(alldata, "album");
                allAlbums = Object.values(allAlbums).map((album) => album[0]);
                allAlbums = allAlbums.sort((a, b) => a.time - b.time);
                function findSearch(searchText, res) {
                    return searchifica(res, searchText, ['album', 'filename', 'metadata'])
                }

                jQuery.fancybox.defaults = {
                    ...jQuery.fancybox.defaults,
                    hash: false,
                    loop: true,
                    buttons: [
                        "zoom",
                        "slideShow",
                        "fullScreen",
                        "download",
                        "close",
                        "favorite"
                    ],
                    wheel: false,
                    clickContent: function (current, event) {
                        return false;
                    },
                    caption: function (instance, item) {
                        let el = $('.gallerypicture[href="' + item.src + '"]');
                        let metadata = el.find("script").text().trim();
                        if (metadata.length > 0 && metadata != '0') {
                            return (
                                '<a class="btn btn-secondary toggle-collapsible">Expand Details</a><div class="collapsible collapsed">' +
                                metadata +
                                "</div>"
                            );
                        }
                    },
                    touch: true,
                    video: {
                        ...jQuery.fancybox.defaults.video,
                        format: "video/mp4",
                        autoStart: false,
                    },
                };

                $(document).on(
                    "click",
                    ".toggle-collapsible",
                    function (event) {
                        let el = $(this).parent().find(".collapsible");
                        if (el.hasClass("collapsed")) {
                            el.removeClass("collapsed");
                            $(this).text("Collapse Details");
                        } else {
                            el.addClass("collapsed");
                            $(this).text("Expand Details");
                        }
                    },
                );

                function getFolderContent(results, desiredPath, search = "") {
                    if (search.length > 3) {
                        return Object.values(findSearch(search, alldata).reverse()
                        .reduce((acc, item) => {
                            acc[item.album] = item;
                            return acc;
                        }, {})).reverse()
                            .map((item) => {
                                return {
                                    ...item,
                                    displayName: getItemDisplayName(
                                        item.album,
                                        desiredPath,
                                        search,
                                    ),
                                };
                            });
                    }

                    if (!desiredPath) {
                        return results
                            .filter((value, index, self) => {
                                return (
                                    self
                                        .map(
                                            ({ album }) =>
                                                album.split("---")[0],
                                        )
                                        .indexOf(
                                            value.album.split("---")[0],
                                        ) === index
                                );
                            })
                            .map((item) => {
                                return {
                                    ...item,
                                    displayName: getItemDisplayName(
                                        item.album,
                                        desiredPath,
                                        search,
                                    ),
                                };
                            });
                    }
                    return results
                        .filter((value, index, self) => {
                            if (!value.album.startsWith(desiredPath)) {
                                return false;
                            }
                            return (
                                self
                                    .map(
                                        ({ album }) =>
                                            album
                                                .replace(desiredPath, "")
                                                .split("---")
                                                .filter((n) => n)[0],
                                    )
                                    .indexOf(
                                        value.album
                                            .replace(desiredPath, "")
                                            .split("---")
                                            .filter((n) => n)[0],
                                    ) === index
                            );
                        })
                        .map((item) => {
                            return {
                                ...item,
                                displayName: getItemDisplayName(
                                    item.album,
                                    desiredPath,
                                    search,
                                ),
                            };
                        });
                }

                function getPathIndex(desiredPath) {
                    if (!desiredPath) {
                        return 0;
                    }
                    return desiredPath.split("---").length;
                }

                function getItemDisplayName(album, desiredPath, search = "") {
                    if (search) {
                        return album.split("---").pop();
                    }
                    let displayName =
                        album.split("---")[getPathIndex(desiredPath)];
                    if (!displayName) {
                        displayName = desiredPath
                            .split("---")
                            .filter((n) => n)
                            .pop();
                    }
                    return displayName;
                }

                function shouldOpenAlbum(results, desiredPath) {
                    let displayNames = getFolderContent(
                        results,
                        desiredPath,
                    ).map((item) =>
                        getItemDisplayName(item.album, desiredPath),
                    );
                    if (displayNames.length == 0) {
                        let target = desiredPath.split("---").filter((n) => n);
                        target.pop();
                        return target.join("---");
                    }

                    if (displayNames.length == 1) {
                        return desiredPath.split("---").pop() !==
                            displayNames[0]
                            ? console.log([
                                  desiredPath.split("---").pop(),
                                  displayNames[0],
                              ]) && !1
                            : desiredPath;
                    }
                    return false;
                }

                (async () => {
                    function showAlbum(album, kind) {
                        window.currentAlbum = album;
                        window.currentAlbumPosition = loadAlbumPosition(album);
                        res = alldata.filter((media) => media.album === album);
                        let testRes = findSearch(search, res);
                        if (testRes.length > 0) {
                            res = testRes;
                        }
                        if (!res) {
                            alert(
                                "This album contains no content please add content to this album via the privuma web service",
                            );
                            window.history.back();
                        }

                        res.sort((a, b) => {
                            let aext = a["filename"].split(".").pop();
                            let bext = b["filename"].split(".").pop();
                            let atime = a["time"];
                            let btime = b["time"];
                            if (album.includes("comic")) {
                                return a["filename"].localeCompare(
                                    b["filename"],
                                    undefined,
                                    {
                                        numeric: true,
                                        sensitivity: "base",
                                    },
                                );
                            }

                            if (aext == "gif" && bext != "gif") {
                                return -1;
                            }

                            if (bext == "gif" && aext != "gif") {
                                return 1;
                            }

                            if (
                                ["webm", "mp4"].includes(aext) &&
                                !["webm", "mp4"].includes(bext)
                            ) {
                                return -1;
                            }

                            if (
                                ["webm", "mp4"].includes(bext) &&
                                !["webm", "mp4"].includes(aext)
                            ) {
                                return 1;
                            }

                            return b["time"] - a["time"];
                        });

                        preventSleep();
                        Promise.allSettled(
                            res.map(function (item) {
                                let filename = item.filename ?? "";
                                let dbAlbum = item.album;
                                let filenameParts = filename.split(".");
                                let extension = filenameParts.pop();
                                let isVideo = [
                                    ".mpg",
                                    ".mod",
                                    ".mmv",
                                    ".tod",
                                    ".wmv",
                                    ".asf",
                                    ".avi",
                                    ".divx",
                                    ".mov",
                                    ".mp4",
                                    ".m4v",
                                    ".3gp",
                                    ".3g2",
                                    ".mp4",
                                    ".m2t",
                                    ".m2ts",
                                    ".mts",
                                    ".mkv",
                                    ".webm",
                                    ".gif",
                                ].includes("." + extension.toLowerCase());
                                if (
                                    ("video" == kind || "all" == kind) &&
                                    isVideo
                                ) {
                                    let thumbnailFilename =
                                        filenameParts.join(".") + ".jpg";
                                    let video =
                                        btoa(item.hash) +
                                        "." +
                                        (extension.toLowerCase() == "gif"
                                            ? "gif"
                                            : "mp4");
                                    let thumbnail = btoa(item.hash) + ".jpg";
                                    let resource = `${thumbnail.substring(0, 2)}/${thumbnail}`;
                                    let videoResource = `${video.substring(0, 2)}/${video}`;
                                    return Promise.resolve([
                                        resource,
                                        true,
                                        filename,
                                        video,
                                        item.metadata,
                                        extension.toLowerCase() == "gif"
                                            ? "image"
                                            : "video",
                                        videoResource,
                                        item.hash,
                                    ]);
                                }
                                if (
                                    ("photo" == kind || "all" == kind) &&
                                    !isVideo
                                ) {
                                    let photo =
                                        btoa(item.hash) + "." + extension;
                                    let resource = `${photo.substring(0, 2)}/${photo}`;
                                    return Promise.resolve([
                                        resource,
                                        false,
                                        filename,
                                        "",
                                        item.metadata,
                                        "",
                                        "",
                                        item.hash,
                                    ]);
                                }
                            }),
                        )
                            .then((results) => {
                                results
                                    .filter(
                                        (result) =>
                                            typeof result.value !== "undefined",
                                    )
                                    .map((result) => result.value)
                                    .forEach(
                                        ([
                                            uri,
                                            isVideo,
                                            filename,
                                            video,
                                            meta,
                                            datatype,
                                            videoResource,
                                            hash,
                                        ]) =>
                                            isVideo
                                                ? jQuery("#content").append(
                                                      `<a class="gallerypicture" title="${filename}" data-type="${datatype}" data-fancybox="gallery"  data-filehash="${hash}"  href="${videoResource}">
                    <div class="img-wrapper"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="lazy" data-src="${uri}" loading="lazy" alt=""></div><script type="text/json">${meta}<\/script>` +
                                                          (datatype === "video"
                                                              ? `<svg style="position: absolute;z-index: 1;right: 15px;bottom: 15px;width: 30px;height: 30px;" width="30px" height="30px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <rect x="0" fill="none" width="24" height="24"/>
                            <g>
                              <path fill="#ffffff" d="M8 4h8v1.997h2V4c1.105 0 2 .896 2 2v12c0 1.104-.895 2-2 2v-2.003h-2V20H8v-2.003H6V20c-1.105 0-2-.895-2-2V6c0-1.105.895-2 2-2v1.997h2V4zm2 11l4.5-3L10 9v6zm8 .997v-3h-2v3h2zm0-5v-3h-2v3h2zm-10 5v-3H6v3h2zm0-5v-3H6v3h2z"/>
                            </g>
                          </svg>`
                                                              : ``) +
                                                          `</a>`,
                                                  )
                                                : jQuery("#content").append(
                                                      `<a class="gallerypicture" data-width="1920" href="${uri}" title="${filename}"  data-filehash="${hash}"  data-fancybox="gallery">
                    <div class="img-wrapper"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="lazy" data-src="${uri}" loading="lazy" alt="" onError="imgError(this)" onLoad="imgLoad(this)"></div><script type="text/json">${meta}<\/script></a>`,
                                                  ),
                                    );
                            })
                            .finally(() => {
                                processLazyLoad();
                                if (window.currentAlbumPosition.src) {
                                    let mediaLink = $('a[data-filehash="' + window.currentAlbumPosition.src + '"');
                                    mediaLink.get(0).scrollIntoView();
                                    mediaLink.click();
                                }
                                jQuery("#content").append(
                                    `<div style="height: 63px"></div>`,
                                );
                            });
                    }

                    let currentFolder = "";
                    let search = "";

                    function showAllAlbums() {
                        allowSleep();
                        res = allAlbums;
                        if (!res) {
                            alert(
                                "This album contains no content please add content to this album via the privuma web service",
                            );
                            window.history.back();
                        }

                        Promise.allSettled(
                            getFolderContent(res, currentFolder, search).map(
                                (item) => {
                                    let filename = item.filename ?? "";
                                    let displayName = item["displayName"];
                                    let album = displayName;
                                    if (currentFolder) {
                                        album = currentFolder + "---" + album;
                                    }

                                    if (search) {
                                        album =
                                            item["album"] +
                                            "---" +
                                            item["album"].split("---").pop();
                                    }

                                    let filenameParts = filename.split(".");
                                    let extension = filenameParts.pop();
                                    let photo = item["filename"];
                                    let isVideo = [
                                        ".mpg",
                                        ".mod",
                                        ".mmv",
                                        ".tod",
                                        ".wmv",
                                        ".asf",
                                        ".avi",
                                        ".divx",
                                        ".mov",
                                        ".mp4",
                                        ".m4v",
                                        ".3gp",
                                        ".3g2",
                                        ".mp4",
                                        ".m2t",
                                        ".m2ts",
                                        ".mts",
                                        ".mkv",
                                        ".webm",
                                        ".gif",
                                    ].includes("." + extension.toLowerCase());
                                    let image =
                                        btoa(item.hash) +
                                        "." +
                                        (isVideo ? "jpg" : extension);
                                    let resource = `${image.substring(0, 2)}/${image}`;
                                    return Promise.resolve([
                                        resource,
                                        displayName,
                                        album,
                                    ]);
                                },
                            ),
                        )
                            .then((results) => {
                                results
                                    .filter(
                                        (result) =>
                                            typeof result.value !== "undefined",
                                    )
                                    .map((result) => result.value)
                                    .forEach(([photo, displayName, album]) =>
                                        jQuery("#content")
                                            .append(`<div class="gallerypicture">
                          <div class="img-wrapper">
                            <img data-src="${photo}" class="openalbum lazy" alt="" data-hash="${
                                btoa(album) + delim + "all"
                            }">
                          </div>
                        <div class="album-menu">
                          <span class="dropdown">
                            <button class="btn min-width">⋮</button>
                            <div class="dropdown-content">
                              <div class="openalbum" data-hash="${
                                  btoa(album) + delim + "photo"
                              }">
                                <svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
                                                width="20px" height="20px" viewBox="0 0 100 100" enable-background="new 0 0 100 100" xml:space="preserve">
                                    <g>
                                        <path fill="#ffffff" d="M93.194,18c0-2.47-2.002-4.472-4.472-4.472c-0.228,0-0.447,0.034-0.667,0.067V13.5H11.25v0.028
                                            c-2.47,0-4.472,2.002-4.472,4.472l0,0.001v63.998l0,0.001l0,0.001V82.5h0.05c0.252,2.231,2.123,3.972,4.421,3.972V86.5h76.805
                                            v-0.095c0.219,0.033,0.438,0.067,0.667,0.067c2.299,0,4.17-1.74,4.422-3.972h0.078V18H93.194z M83.265,76.543H72.404
                                            c-0.038-0.155-0.092-0.304-0.166-0.442l0.018-0.01l-22.719-39.35l-0.009,0.005c-0.5-1.027-1.544-1.74-2.764-1.74
                                            c-1.251,0-2.324,0.749-2.807,1.821L28.838,63.013l-3.702-6.411l-0.005,0.003c-0.264-0.542-0.814-0.918-1.457-0.918
                                            c-0.659,0-1.224,0.395-1.479,0.958l-5.46,9.457V23.485h66.53V76.543z"/>
                                        <circle fill="#ffffff" cx="68.122" cy="38.584" r="10.1"/>
                                    </g>
                                </svg>
                                <span>Photos</span>
                              </div>
                              <div class="openalbum" data-hash="${
                                  btoa(album) + delim + "video"
                              }">
                                <svg width="20px" height="20px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                  <rect x="0" fill="none" width="24" height="24"/>
                                  <g>
                                    <path fill="#ffffff" d="M8 4h8v1.997h2V4c1.105 0 2 .896 2 2v12c0 1.104-.895 2-2 2v-2.003h-2V20H8v-2.003H6V20c-1.105 0-2-.895-2-2V6c0-1.105.895-2 2-2v1.997h2V4zm2 11l4.5-3L10 9v6zm8 .997v-3h-2v3h2zm0-5v-3h-2v3h2zm-10 5v-3H6v3h2zm0-5v-3H6v3h2z"/>
                                  </g>
                                </svg>
                                <span>Videos</span>
                              </div>
                              <div class="openalbum" data-hash="${
                                  btoa(album) + delim + "all"
                              }">
                                <svg width="20px" height="20px"  fill="#ffffff" viewBox="0 0 640 512" xmlns="http://www.w3.org/2000/svg"><path d="M608 0H160a32 32 0 0 0-32 32v96h160V64h192v320h128a32 32 0 0 0 32-32V32a32 32 0 0 0-32-32zM232 103a9 9 0 0 1-9 9h-30a9 9 0 0 1-9-9V73a9 9 0 0 1 9-9h30a9 9 0 0 1 9 9zm352 208a9 9 0 0 1-9 9h-30a9 9 0 0 1-9-9v-30a9 9 0 0 1 9-9h30a9 9 0 0 1 9 9zm0-104a9 9 0 0 1-9 9h-30a9 9 0 0 1-9-9v-30a9 9 0 0 1 9-9h30a9 9 0 0 1 9 9zm0-104a9 9 0 0 1-9 9h-30a9 9 0 0 1-9-9V73a9 9 0 0 1 9-9h30a9 9 0 0 1 9 9zm-168 57H32a32 32 0 0 0-32 32v288a32 32 0 0 0 32 32h384a32 32 0 0 0 32-32V192a32 32 0 0 0-32-32zM96 224a32 32 0 1 1-32 32 32 32 0 0 1 32-32zm288 224H64v-32l64-64 32 32 128-128 96 96z"/></svg>
                                <span>All</span>
                              </div>
                            </div>
                          </span>
                        <span class="openalbum" data-hash="${
                            btoa(album) + delim + "all"
                        }">
                          ${displayName}
                        </span>
                      </div>
                    </div>`),
                                    );
                            })
                            .finally(() => {
                                processLazyLoad();
                                jQuery("#content").append(
                                    `<div style="height: 63px"></div>`,
                                );
                                console.log("rendered");
                            });
                    }

                    const getQueryParameter = (param) =>
                        new URLSearchParams(
                            document.location.search.substring(1),
                        ).get(param);

                    function run() {
                        medcrypt.flushQueue();
                        jQuery("#content").empty();
                        var hash = window.location.hash;
                        if (hash.length > 1) {
                            var parts = hash.substr(1).split(delim);
                            var album = decodeURI(atob(parts[0]));
                            var kind = parts[1];
                            var photoskind =
                                kind == "all" || kind == "photo" ? "1" : "0";
                            var videoskind =
                                kind == "all" || kind == "video" ? "1" : "0";
                            let targetAlbum = shouldOpenAlbum(allAlbums, album);
                            if (targetAlbum) {
                                var id = allAlbums.find(
                                    (item) => item.album == targetAlbum,
                                )["id"];
                                if (
                                    localStorage.getItem(
                                        "LastRequestedAlbum",
                                    ) === id
                                ) {
                                    const backHash = targetAlbum.split("---");
                                    backHash.pop();
                                    window.location.hash =
                                        btoa(backHash) + delim + "all";
                                    return;
                                }
                                search = jQuery("#searchInput").val() || "";
                                showAlbum(targetAlbum, kind);
                                return;
                            }
                        }
                        search = jQuery("#searchInput").val() || "";
                        currentFolder = album;
                        showAllAlbums();
                        $(document).ready(function () {
                            $('[data-fancybox="gallery"]').fancybox({});
                        });
                    }

                    $(document).on("click", ".openalbum", function (e) {
                        window.location.hash = $(this).data("hash");
                    });

                    $(document).on("blur", "#searchInput", function (e) {
                        run();
                    });

                    $("#searchInput").on("keyup", function (e) {
                        if (e.key === "Enter" || e.keyCode === 13) {
                            $("#searchInput").blur();
                        }
                    });

                    let slideshowTimer;
                    let slideshowStarted = false;

                    $(document).on("beforeClose.fb", function (e, instance) {
                        slideshowStarted = false;
                        $("video")
                            .trigger("pause")
                            .find("Source:first")
                            .removeAttr("src")
                            .parent()
                            .trigger("load");
                    });

                    $(document).on(
                        "beforeLoad.fb",
                        function (e, instance, slide) {
                            if (slide.type === "image") {

                            } else {
                            }
                        },
                    );
                    $(document).on(
                        "afterShow.fb",
                        function (e, instance, slide) {
                            let originalLink = $(
                                '.gallerypicture[href="' + slide.src + '"]'
								);

                                let element = $(
                                    '.gallerypicture[href="' + slide.src + '"]',
                                );
                                let targetsrc =
                                    slide.src.length > 0
                                        ? slide.src
                                        : element.data("src");
							if ($("video").length == 0) {
                                saveAlbumPosition({src: originalLink.data('filehash'), currentTime: 0}, window.currentAlbum);

								medcrypt.getSrc(targetsrc, (uri) => {
                                    element.attr("href", uri);
                                    slide.src = uri;
                                    slide.original = targetsrc;
                                    if (slide.hasError) {
                                        $(
                                            ".fancybox-content.fancybox-error",
                                        ).remove();
                                        $.fancybox
                                            .getInstance()
                                            .current.$slide.trigger("onReset");
                                        slide.hasError = false;
                                        slide.isLoading = false;
                                        slide.isLoaded = false;
                                        instance.loadSlide(slide);
                                    }
                                });
                                if (
                                    $.fancybox.getInstance().SlideShow.isActive
                                ) {
                                    $.fancybox.getInstance().SlideShow.stop();
                                    slideshowStarted = true;
                                }

                                if (!slideshowStarted) {
                                    return;
                                }

                                if (slideshowTimer) {
                                    clearTimeout(slideshowTimer);
                                }

                                window
                                    .getGifDuration(slide.src)
                                    .then((duration) => {
                                        jQuery(`img[src="${slide.src}"]`).attr(
                                            "data-duration",
                                            duration,
                                        );
                                        let defaultDuration = 5000;
                                        let gifduration = duration;
                                        gifduration =
                                            !gifduration ||
                                            typeof gifduration ===
                                                "undefined" ||
                                            gifduration == 0
                                                ? defaultDuration
                                                : gifduration;
                                        if (
                                            slide.$content &&
                                            jQuery(slide.$content).find("video")
                                                .length > 0
                                        ) {
                                            jQuery(slide.$content)
                                                .find("video")
                                                .trigger("play");
                                            jQuery(slide.$content)
                                                .find("video")
                                                .on("ended", function () {
                                                    $.fancybox
                                                        .getInstance()
                                                        .next();
                                                });
                                            return;
                                        }

                                        let targetDuration = gifduration;
                                        if (gifduration < defaultDuration) {
                                            let gifDivisible = Math.ceil(
                                                defaultDuration / gifduration,
                                            );
                                            targetDuration =
                                                gifduration * gifDivisible;
                                        }

                                        slideshowTimer = setTimeout(
                                            function () {
                                                $.fancybox.getInstance().next();
                                            },
                                            targetDuration,
                                        );
                                    })
                                    .catch(() => {
                                        let defaultDuration = 5000;
                                        let gifduration = jQuery(
                                            slide.$thumb,
                                        ).data("duration");
                                        gifduration =
                                            !gifduration ||
                                            typeof gifduration ===
                                                "undefined" ||
                                            gifduration == 0
                                                ? defaultDuration
                                                : gifduration;
                                        if (
                                            slide.$content &&
                                            jQuery(slide.$content).find("video")
                                                .length > 0
                                        ) {
                                            jQuery(slide.$content)
                                                .find("video")
                                                .trigger("play");
                                            jQuery(slide.$content)
                                                .find("video")
                                                .on("ended", function () {
                                                    $.fancybox
                                                        .getInstance()
                                                        .next();
                                                });
                                            return;
                                        }

                                        let targetDuration = gifduration;
                                        if (gifduration < defaultDuration) {
                                            let gifDivisible = Math.ceil(
                                                defaultDuration / gifduration,
                                            );
                                            targetDuration =
                                                gifduration * gifDivisible;
                                        }

                                        slideshowTimer = setTimeout(
                                            function () {
                                                $.fancybox.getInstance().next();
                                            },
                                            targetDuration,
                                        );
                                    });
                                return;
                            }

							try {
	                            $("video").trigger("pause");
	                            var videoDuration = $("video").attr("duration");

	                            var updateProgressBar = function () {
	                                if ($("video").attr("readyState")) {
	                                    var buffered = $("video")
	                                        .attr("buffered")
	                                        .end(0);
	                                    var percent =
	                                        (100 * buffered) / videoDuration;

	                                    medcrypt.transfers.loaded = percent;
	                                    medcrypt.transfers.size = 100;
	                                    medcrypt.displayProgress();
	                                    if (percent > 10) {
	                                        $("video").trigger("play");
	                                    }
	                                    if (buffered >= videoDuration) {
	                                        clearInterval(this.watchBuffer);
	                                        medcrypt.finishProgress();
	                                    }
	                                }
	                            };
	                            var watchBuffer = setInterval(
	                                updateProgressBar,
	                                500,
	                            );
							} catch (e) {
								console.error(e);
							}

                            medcrypt.getSrc(slide.src, (uri) => {
								element.attr("href", uri);
                                slide.src = uri;
                                slide.original = targetsrc;
                                if (slide.hasError) {
                                    $(
                                        ".fancybox-content.fancybox-error",
                                    ).remove();
                                    $.fancybox
                                        .getInstance()
                                        .current.$slide.trigger("onReset");
                                    slide.hasError = false;
                                    slide.isLoading = false;
                                    slide.isLoaded = false;
                                    instance.loadSlide(slide);
                                }

                                // $(
                                //     '.gallerypicture[href="' + slide.src + '"]',
                                // ).attr("href", uri);
                                $("video")
                                    .find("Source:first")
                                    .attr("src", uri)
                                    .parent()
                                    .trigger("load")
                                    .trigger("play");
                                let videoElement = document.getElementsByTagName('video')[0];
                                videoElement.currentTime = window.currentAlbumPosition.currentTime ?? 0;
                                videoElement.addEventListener('timeupdate', (e) => {
                                    updatePlaybackTimestamp(
                                        originalLink.data('filehash'), window.currentAlbum, videoElement);
                                });


                            });
                            $("video").removeAttr("controls");
                            $("video").click(function toggleControls() {
                                if (this.hasAttribute("controls")) {
                                    this.removeAttribute("controls");
                                } else {
                                    this.setAttribute("controls", "controls");
                                }
                            });


                            saveAlbumPosition({src: originalLink.data('filehash'), currentTime: window.currentAlbumPosition.src === originalLink.data('filehash') ? window.currentAlbumPosition.currentTime: 0}, window.currentAlbum);

                            if ($.fancybox.getInstance().SlideShow.isActive) {
                                $.fancybox.getInstance().SlideShow.stop();
                                slideshowStarted = true;
                            }

                            if (slideshowStarted) {

	                            if (slideshowTimer) {
	                                clearTimeout(slideshowTimer);
	                            }
	                            window
	                                .getGifDuration(slide.src)
	                                .then((duration) => {
	                                    jQuery(`img[src="${slide.src}"]`).attr(
	                                        "data-duration",
	                                        duration,
	                                    );
	                                    let defaultDuration = 5000;
	                                    let gifduration = duration;
	                                    gifduration =
	                                        !gifduration ||
	                                        typeof gifduration === "undefined" ||
	                                        gifduration == 0
	                                            ? defaultDuration
	                                            : gifduration;
	                                    if (
	                                        slide.$content &&
	                                        jQuery(slide.$content).find("video")
	                                            .length > 0
	                                    ) {
	                                        jQuery(slide.$content)
	                                            .find("video")
	                                            .trigger("play");
	                                        jQuery(slide.$content)
	                                            .find("video")
	                                            .on("ended", function () {
	                                                $.fancybox.getInstance().next();
	                                            });
	                                        return;
	                                    }

	                                    let targetDuration = gifduration;
	                                    if (gifduration < defaultDuration) {
	                                        let gifDivisible = Math.ceil(
	                                            defaultDuration / gifduration,
	                                        );
	                                        targetDuration =
	                                            gifduration * gifDivisible;
	                                    }

	                                    slideshowTimer = setTimeout(function () {
	                                        $.fancybox.getInstance().next();
	                                    }, targetDuration);
	                                })
	                                .catch(() => {
	                                    let defaultDuration = 5000;
	                                    let gifduration = jQuery(slide.$thumb).data(
	                                        "duration",
	                                    );
	                                    gifduration =
	                                        !gifduration ||
	                                        typeof gifduration === "undefined" ||
	                                        gifduration == 0
	                                            ? defaultDuration
	                                            : gifduration;
	                                    if (
	                                        slide.$content &&
	                                        jQuery(slide.$content).find("video")
	                                            .length > 0
	                                    ) {
	                                        jQuery(slide.$content)
	                                            .find("video")
	                                            .trigger("play");
	                                        jQuery(slide.$content)
	                                            .find("video")
	                                            .on("ended", function () {
	                                                $.fancybox.getInstance().next();
	                                            });
	                                        return;
	                                    }

	                                    let targetDuration = gifduration;
	                                    if (gifduration < defaultDuration) {
	                                        let gifDivisible = Math.ceil(
	                                            defaultDuration / gifduration,
	                                        );
	                                        targetDuration =
	                                            gifduration * gifDivisible;
	                                    }

	                                    slideshowTimer = setTimeout(function () {
	                                        $.fancybox.getInstance().next();
	                                    }, targetDuration);
	                                });

								}
	                        },
	                );

                    let scrollMemory = {};
                    $("#backBtn").click(function () {
                        let hash = window.location.hash.slice(1);
                        if (hash) {
                            let hashParts = hash.split(delim);
                            let path = atob(hashParts[0]);
                            if (path.split("---").length > 1) {
                                pathParts = path.split("---");
                                window.location.hash =
                                    "#" +
                                    [
                                        btoa(
                                            pathParts
                                                .slice(0, pathParts.length - 1)
                                                .join("---"),
                                        ),
                                        ...hashParts.slice(1),
                                    ].join(delim);
                            } else {
                                window.location.hash = "";
                            }
                        }

                    });

                    let lastHash = window.location.hash;
                    if (!lastHash) {
                        lastHash = "none";
                    }

                    $(window).on("hashchange", function (e) {
                        medcrypt.flushQueue();
                        let newhash = window.location.hash;
                        if (!newhash) {
                            newhash = "none";
                        }
                        let scrollTop =
                            window.pageYOffset ||
                            document.documentElement.scrollTop;
                        scrollMemory[lastHash] = scrollTop;
                        document.documentElement.scrollTop =
                            document.body.scrollTop =
                                scrollMemory[newhash] ?? 0;
                        lastHash = newhash;
                        run();
                    });
                    run();
                })();
            }

            function processLazyLoad() {
                var lazyImages = [].slice.call(
                    document.querySelectorAll("img.lazy"),
                );
                if ("IntersectionObserver" in window) {
                    let lazyImageObserver = new IntersectionObserver(function (
                        entries,
                        observer,
                    ) {
                        entries.forEach(function (entry) {
                            let lazyImage = entry.target;
                            if (
                                entry.isIntersecting &&
                                lazyImage.dataset.src != ""
                            ) {
                                medcrypt.getSrc(
                                    lazyImage.dataset.src,
                                    (uri) => {
                                        lazyImage.src = uri;
                                    },
                                );
                                lazyImage.classList.remove("lazy-waiting");
                                lazyImage.classList.add("lazy-loaded");
                            } else if (
                                lazyImage.classList.contains("lazy-loaded") &&
                                lazyImage.src !==
                                    "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                            ) {
                                lazyImage.dataset.src = lazyImage.src;
                                lazyImage.src =
                                    "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";
                                lazyImage.classList.remove("lazy-loaded");
                                lazyImage.classList.add("lazy-waiting");
                            }
                        });
                    });
                    lazyImages.forEach(function (lazyImage) {
                        lazyImageObserver.observe(lazyImage);
                    });
                }
            }
        </script>
    </body>
</html>
HEREHTML;

echo PHP_EOL . 'Downloading encrypted database offline website payload';
$mobiledata = 'const encrypted_data = `' . $mobiledata . '`;';
$ops->file_put_contents('encrypted_mobile_data.js', $mobiledata);
unset($mobiledata);

$data = 'const encrypted_data = ' . $data . ';';
$ops->file_put_contents('encrypted_data.js', $data);
unset($data);

$viewerHTML = str_replace(
    '{{ENDPOINT}}',
    privuma::getEnv('ENDPOINT'),
    $viewerHTML
);

echo PHP_EOL . 'Downloading Offline Web App Viewer HTML File';
$opsPlain->file_put_contents('index.html', $viewerHTML);
$opsNoEncodeNoPrefix->file_put_contents('index.html', $viewerHTML);
unset($viewerHTML);

echo PHP_EOL . 'Database Downloads have been completed';
echo PHP_EOL .
  'Checking ' .
  count($dlData) .
  ' media items have been downloaded';

$previouslyDownloadedMedia = array_flip(
    array_map(
        fn ($item) => trim($item, "\/"),
        array_column(
            $ops->scandir('', true, true, null, false, true, true, true),
            'Name'
        )
    )
);

echo PHP_EOL .
  'Filtering ' .
  count($previouslyDownloadedMedia) .
  ' media items already downloaded';

$dlData = array_filter($dlData, function ($item) use (
    $previouslyDownloadedMedia
) {
    $filename = str_replace(
        [
          '.mpg',
          '.mod',
          '.mmv',
          '.tod',
          '.wmv',
          '.asf',
          '.avi',
          '.divx',
          '.mov',
          '.m4v',
          '.3gp',
          '.3g2',
          '.mp4',
          '.m2t',
          '.m2ts',
          '.mts',
          '.mkv',
          '.webm',
        ],
        '.mp4',
        $item['filename']
    );
    $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $thumbnailPreserve = $item['hash'] . '.jpg';
    return !array_key_exists($preserve, $previouslyDownloadedMedia) &&
      !array_key_exists($thumbnailPreserve, $previouslyDownloadedMedia);
});

echo PHP_EOL . 'Found ' . count($dlData) . ' new media items to be downloaded';

$progress = 0;
$total = count($dlData);
$lastProgress = 0;
$newDlCount = 0;
foreach ($dlData as $item) {
    $progress++;
    $percentage = round(($progress / $total) * 100, 2);
    if ($percentage > $lastProgress + 5) {
        echo PHP_EOL . "Overall Progress: {$percentage}% ";
        $lastProgress = $percentage;
    }
    $album = $item['album'];
    $filename = str_replace(
        [
          '.mpg',
          '.mod',
          '.mmv',
          '.tod',
          '.wmv',
          '.asf',
          '.avi',
          '.divx',
          '.mov',
          '.m4v',
          '.3gp',
          '.3g2',
          '.mp4',
          '.m2t',
          '.m2ts',
          '.mts',
          '.mkv',
          '.webm',
        ],
        '.mp4',
        $item['filename']
    );

    $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $thumbnailPreserve = $item['hash'] . '.jpg';
    $path =
      privuma::getDataFolder() .
      DIRECTORY_SEPARATOR .
      (new mediaFile($item['filename'], $item['album']))->path();
    $thumbnailPath = str_replace('.mp4', '.jpg', $path);
    if (!$ops->is_file($preserve)) {
        if (!isset($item['url'])) {
            if (
                $item['url'] =
                  $privuma->getCloudFS()->public_link($path) ?:
                  $tokenizer->mediaLink($path, false, false, true)
            ) {
                if (strpos($filename, '.mp4') !== false) {
                    $item['thumbnail'] =
                      $privuma->getCloudFS()->public_link($thumbnailPath) ?:
                      $tokenizer->mediaLink($thumbnailPath, false, false, true);
                }
            } else {
                echo PHP_EOL . "Skipping unavailable media: $path";
                continue;
            }
        }
        echo PHP_EOL .
          'Queue Downloading of media file: ' .
          $preserve .
          ' from album: ' .
          $item['album'] .
          ' with potential thumbnail: ' .
          ($item['thumbnail'] ?? 'No thumbnail');
        $privuma->getQueueManager()->enqueue(
            json_encode([
              'type' => 'processMedia',
              'data' => [
                'album' => $album,
                'filename' => $filename,
                'url' => $item['url'],
                'thumbnail' => $item['thumbnail'],
                'download' => $downloadLocation,
                'hash' => $item['hash'],
              ],
            ])
        );
        $newDlCount++;
    } elseif (
        strpos($filename, '.mp4') !== false &&
        is_null($item['thumbnail']) &&
        !$ops->is_file($thumbnailPreserve) &&
        ($item['thumbnail'] =
          $privuma->getCloudFS()->public_link($thumbnailPath) ?:
          $tokenizer->mediaLink($thumbnailPath, false, false, true))
    ) {
        echo PHP_EOL .
          'Queue Downloading of media file: ' .
          $thumbnailPreserve .
          ' from album: ' .
          $item['album'];
        $privuma->getQueueManager()->enqueue(
            json_encode([
              'type' => 'processMedia',
              'data' => [
                'album' => $album,
                'filename' => str_replace('.mp4', '.jpg', $filename),
                'url' => $item['thumbnail'],
                'download' => $downloadLocation,
                'hash' => $item['hash'],
              ],
            ])
        );
    }
}
echo PHP_EOL . 'Done queing ' . $newDlCount . ' Media to be downloaded';
