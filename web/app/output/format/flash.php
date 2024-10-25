<?php

namespace privuma\output\format;

session_start();

use privuma\privuma;
use privuma\helpers\cloudFS;
use privuma\helpers\tokenizer;

$FLASH_MIRROR = privuma::getEnv('FLASH_RCLONE_DESTINATION');
$ops = new cloudFS($FLASH_MIRROR);
$tokenizer = new tokenizer();
$ENDPOINT = privuma::getEnv('ENDPOINT');
$AUTHTOKEN = privuma::getEnv('AUTHTOKEN');
$DEOVR_LOGIN = privuma::getEnv('DEOVR_LOGIN');
$DEOVR_PASSWORD = privuma::getEnv('DEOVR_PASSWORD');

function getProtectedUrlForMediaPath($path)
{
    global $ENDPOINT;
    global $AUTHTOKEN;
    global $tokenizer;
    $uri = 'media.swf?token=' . $tokenizer->rollingTokens($AUTHTOKEN)[1] . '&flash=1&media=' . urlencode(base64_encode($path));
    return $ENDPOINT . $uri;
}

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

if (!isset($_SESSION['flashAuthozied'])) {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $allowGetLogin = false;
    $username = $_POST['login'] ?? ($allowGetLogin ? $_GET['login'] : null) ?? $data['username'] ?? '';
    $password = $_POST['password'] ?? ($allowGetLogin ? $_GET['password'] : null) ?? $data['password'] ?? '';
    if (isset($username) && isset($password)) {
        if ($username === $DEOVR_LOGIN && $password === $DEOVR_PASSWORD) {
            $_SESSION['flashAuthozied'] = true;
        } else {
            echo $loginForm;
            die();
        }
    } else {

        echo $loginForm;
        die();

    }
}

if ((isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > 60 * 15)) || isset($_GET['logout'])) {
    // last request was more than 30 minutes ago
    session_unset();     // unset $_SESSION variable for the run-time
    session_destroy();   // destroy session data in storage
    header('Location: /flash');
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

$RCLONE_DESTINATION_PATH =
    $privuma->getEnv('FLASH_DATA_DIRECTORY') . DIRECTORY_SEPARATOR;

function getOpsPathPrefixForLink($link, $search)
{
    global $RCLONE_DESTINATION_PATH;
    $filenameBase = basename(strtolower($link), '.swf');
    return implode('/', [$RCLONE_DESTINATION_PATH, $search, $filenameBase]);
}

function streamThumbnail($path)
{
    global $ops;
    //var_dump([$path, $ops->getPathInfo($path)]);
    //die();
    header('Content-Type: image/png');
    $ops->readfile($path, true);
    die();
}

function processLink($link, $search)
{
    global $ops;
    global $RCLONE_DESTINATION_PATH;
    $filenameBase = basename(strtolower($link), '.swf');
    $thumbnail = getTempNameWithExtension('png');
    $thumbnailTarget = implode('/', [$RCLONE_DESTINATION_PATH, $search, $filenameBase . '.png']);
    $flashTarget = implode('/', [$RCLONE_DESTINATION_PATH, $search, $filenameBase . '.swf']);
    $flash = getTempNameWithExtension('swf');
    if ($ops->file_exists($flashTarget) && $ops->file_exists($thumbnailTarget)) {
        return;
    }

    file_put_contents($flash, file_get_contents($link));
    exec("/usr/local/ruffle/target/release/exporter --skip-unsupported -p low --silent $flash $thumbnail 2>&1");
    if (!$ops->is_dir($RCLONE_DESTINATION_PATH)) {
        $ops->mkdir($RCLONE_DESTINATION_PATH);
    }

    $ops->rename($flash, $flashTarget, false);
    $ops->rename($thumbnail, $thumbnailTarget, false);
}

if (isset($_GET['thumbnail']) && isset($_GET['search'])) {
    $link = urldecode(base64_decode($_GET['thumbnail']));
    $search = urldecode(base64_decode($_GET['search']));
    $path = getOpsPathPrefixForLink($link, $search) . '.png';
    //var_dump($path);
    //die();

    if (!$ops->is_file($path)) {
        processLink($link, $search);
    }

    header('Location: ' . getProtectedUrlForMediaPath($path));
    die();

    streamThumbnail($path);
    die();
}

$flashJsonPath = privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'flash.json';
$json = file_exists($flashJsonPath) ? json_decode(file_get_contents($flashJsonPath), true) ?? [] : [];

if (isset($_GET['access'])) {
    header('Location: ' . getProtectedUrlForMediaPath(urldecode(base64_decode($_GET['access']))));
    return;
}

function getTempNameWithExtension($ext)
{
    $tmpname = tempnam(sys_get_temp_dir(), 'PVMA-');
    $newtmpname = $tmpname . '.' . $ext;
    rename($tmpname, $newtmpname);
    return $newtmpname;
}

if (isset($_GET['media']) && isset($_GET['id'])) {
    if ($_GET['media'] === 'cached') {
        foreach ($json as $search => $posts) {
            foreach ($posts as $k => $post) {
                if ($_GET['id'] == $post['id']) {
                    $originalUrl = $post['url'];
                    $width = $post['file']['width'] ?? '100%';

                    $height = $post['file']['height'] ?? '100%';

                    if ($width != '100%') {
                        $height = (($height / $width) * 100) . 'vw';
                        $width = '100vw';
                    }
                    $proxiedUrl = getProtectedUrlForMediaPath($originalUrl);
                    echo '<!DOCTYPE html>';
                    echo '
                                <html>
                                    <head>
                                    <meta name="viewport" content="width=device-width, initial-scale=1">
                                    <meta charset="utf-8">
                                    ' . $htmlStyle . '
																			
                                    <head>
                                    <body>

																			<object style="width:' . $width . ';height:' . $height . ';">
                                            <embed src="' . $proxiedUrl . '" width="100%" height="100%">
                                      </object>
																						
                                        
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
echo '<!DOCTYPE html>';
echo '<html>
            <head>
                <meta name="viewport" content="width=device-width, initial-scale=1">
                ' . $htmlStyle . '
				<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.5.7/jquery.fancybox.min.css" integrity="sha512-H9jrZiiopUdsLpg94A333EfumgUBpO9MdbxStdeITo+KEIMaNfHNvwyjjDJb+ERPaRS6DpyRlKbvPUasNItRyw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
                <style>
                a[data-fancybox] {
                    width: 42vw;
                    height: auto;
                    margin: 2.5vw;
                    border-radius: 5vw;
                    display:inline-block;
                    overflow: hidden;
					border: 1px solid white;
					padding: 2.5vw;
					font-family: sans-serif;
					font-size: 1.2em;
					line-height: 1.0;
                }
                
								a[data-fancybox] img {
									object-fit:cover;
										float:left;
										width:100px;
										height:100px;
										margin-right: 10px;
									}
            @media (max-width:801px)  {


                a[data-fancybox] {
                    width: 84vw;
                    height: auto;
                    margin: 2.5vw;
                    border-radius: 5vw;
                    display:inline-block;
                    overflow: hidden;
                }
            }
                </style>
				<script src="/flash/ruffle/ruffle.js"></script>
            </head>
            <body>';

?>
            <ul class="tabs" style="overflow-x: scroll">
                <?php

foreach ($json as $search => $results) {
    echo '<li data-tab-target="#' . urlencode($search) . '" class="' . ($search === array_key_first($json) ? 'active' : '') . ' tab">' . $search . '</li>';
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
foreach ($json as $search => $posts) {
    echo '<div data-tab-content id="' . urlencode($search) . '" class="' . ($search === array_key_first($json) ? 'active' : '') . '"><h2>' . $search . '</h2>';
    foreach ($posts as $post) {
        echo '<a data-fancybox href="javascript:;" data-src="#inline-player" data-url="?access=' . urlencode(base64_encode($post['url'])) . '"><img loading="lazy" class="lazy" data-hash="' . md5(basename($post['url'])) . '" data-baksrc="?search=' . urlencode(base64_encode($search)) . '&thumbnail=' . urlencode(base64_encode($post['url'])) . '" />
' . $post['title'] . '<p style="display:none;">' . implode(', ', $post['tags']['general'] ?? []) . '</p></a> '; //

    }
    echo '</div>';
}
echo '</div>';
echo '<div id="searchBox" style="position:fixed; left: 10px; bottom: 10px; width:100%; max-width: 320px; padding:5px; border-radius:7.5px; background-color: rgba(0,0,0,0.85);"><input style="border-radius:5px; padding:3px; font-size:16px; width:100%;" type="search" placeholder="search" name="search" id="search" /></div>';
echo '
	<div id="inline-player" style="display:none;width:100%;height:100%;background-color:#000000;padding:0px;margin:0px;">
	</div>';
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
		processLazyLoad();
  })
})
</script>";
echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
			<script src="https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.5.7/jquery.fancybox.min.js" integrity="sha512-uURl+ZXMBrF4AwGaWmEetzrd+J5/8NRkWAvJx5sbPSSuOb0bZLqf+tOzniObO00BjHa/dD7gub9oCGMLPQHtQA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
			<script>
			
			$.fancybox.defaults.touch = false;
			$.fancybox.defaults.keyboard = false;
			
				const ruffle = window.RufflePlayer.newest();
				
				function md5ToImage(hash) {
       const canvas = document.createElement("canvas");
       const ctx = canvas.getContext("2d");
       canvas.width = 8;
       canvas.height = 8;

       const bytes = [];
       for (let i = 0; i < hash.length; i += 2) {
           bytes.push(parseInt(hash.substr(i, 2), 16));
       }

       const imageData = ctx.createImageData(8, 8);
       for (let i = 0; i < 64; i++) {
           imageData.data[i * 4] = bytes[i % 16];
           imageData.data[i * 4 + 1] = bytes[(i + 4) % 16];
           imageData.data[i * 4 + 2] = bytes[(i + 8) % 16];
           imageData.data[i * 4 + 3] = 255;
       }

       ctx.putImageData(imageData, 0, 0);
       return canvas.toDataURL();
   }

  	$("a[data-fancybox] img").each(function(el) {
			$(this).attr(`retro`, md5ToImage($(this).data(`hash`)));
		});

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
																lazyImage.onerror = function() {
																	lazyImage.src = md5ToImage(lazyImage.dataset.hash);	
																};
																lazyImage.src = lazyImage.dataset.src;
                                lazyImage.classList.remove("lazy-waiting");
                                lazyImage.classList.add("lazy-loaded");
                            } else if (
                                lazyImage.classList.contains("lazy-loaded") &&
                                lazyImage.src !== lazyImage.dataset.retro
                            ) {
                                lazyImage.dataset.src = lazyImage.src;
                                lazyImage.src = lazyImage.dataset.retro;
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
						
						processLazyLoad();


    
			$(document).on("beforeShow.fb", function( e, instance, slide ) {
				const player = ruffle.createPlayer();
    $("#inline-player").empty().html(player);
    player.load({
        "url": slide.opts.$orig.data("url"),
        "scale": "showAll",
        "openUrlMode": "deny",
        "allowNetworking": "none",
        "letterbox": "on",
      });
	player.style.width = "100%"; player.style.height = "100%";
});

$("#search").keypress((event) => {
	if (event.which === 13) {
		if ($("#search").val().length <= 2) {
			$(`a[data-fancybox]`).show();
			return;
		}
		$(`a[data-fancybox]`).each(function(el){ 
			var match = true;
			var area = $(this).text();
			($("#search").val()).toLowerCase().split(" ").forEach((word) => {
				console.log(area);
			 	if(!area.includes(word)) {
				 	match = false;
			 	}
		 	});
	
		
			 if (match) {
				 $(this).show();
			 } else {
				 $(this).hide();
			 }
		});
	}
});
				</script>
		</body>
        </html>';
