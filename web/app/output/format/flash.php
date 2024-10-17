<?php

namespace privuma\output\format;

session_start();

use privuma\privuma;
use privuma\helpers\cloudFS;
use privuma\helpers\tokenizer;


$DEOVR_MIRROR = privuma::getEnv('RCLONE_DESTINATION');
$ops = new cloudFS($DEOVR_MIRROR);
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
    $uri = 'media.swf?token=' . $tokenizer->rollingTokens($AUTHTOKEN)[1] . '&deovr=1&media=' . urlencode(base64_encode($path));
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
    $username = $_POST['login'] ?? ($allowGetLogin ? $_GET['login'] : null) ?? $data['username'] ?? "";
    $password = $_POST['password'] ?? ($allowGetLogin ? $_GET['password'] : null) ?? $data['password'] ?? "";
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












$flashJsonPath = privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'flash.json';
$json = file_exists($flashJsonPath) ? json_decode(file_get_contents($flashJsonPath), true) ?? [] : [];

if (isset($_GET['media']) && isset($_GET['id'])) {
    if ($_GET['media'] === 'cached') {
			foreach ($json as $search => $posts) {
                foreach ($posts as $k => $post) {
                    if ($_GET['id'] == $post['id']) {
                        $originalUrl = $post['url'];
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


  <div style="width:100%; height: calc( 100% - 25px ); display:block; position:absolute; margin:0; padding:0;top:0;left:0;"> <object>
                                            <embed src="' . $proxiedUrl . '" width="100%" height="100%">
                                      </object>

    </div>
																																								<script src="/flash/ruffle/ruffle.js">
                                        
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
                a[data-gallery-link="true"] {
                    width: 42vw;
                    height: 43vw;
                    margin: 2.5vw;
                    border-radius: 5vw;
                    display:inline-block;
                    overflow: hidden;
					border: 1px solid white;
					padding: 2.5vw;
					font-family: sans-serif;
					font-size: 2em;
					line-height: 1.0;
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
   
    ?>

            <ul class="tabs">
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
            $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
            if (stripos($ua, 'x11') !== false) {
                echo '<a data-gallery-link="true" href="?media=cached&id=' . $post['id'] . '">' . $post['title'] . '</a> ';
            } else {
							
                echo ' <a data-gallery-link="true" data-fancybox="gallery"  data-type="iframe" href="#" data-src="?media=cached&id=' . $post['id'] . '">' . $post['title'] . '</a> ';
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
echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
			<script src="https://cdnjs.cloudflare.com/ajax/libs/fancybox/3.5.7/jquery.fancybox.min.js" integrity="sha512-uURl+ZXMBrF4AwGaWmEetzrd+J5/8NRkWAvJx5sbPSSuOb0bZLqf+tOzniObO00BjHa/dD7gub9oCGMLPQHtQA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
		</body>
        </html>';



