<?php

use privuma\privuma;

function adminer_object()
{
    // required to run any plugin
    include_once './plugins/plugin.php';

    // autoloader
    foreach (glob('plugins/*.php') as $filename) {
        include_once "./$filename";
    }

    // enable extra drivers just by including them
    //~ include "./plugins/drivers/simpledb.php";

    require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

    $plugins = array(
        // specify enabled plugins here
new AdminerLoginPasswordLess((privuma::getInstance())->getEnv('MYSQL_PASSWORD'))
    );

    /* It is possible to combine customization and plugins:
    class AdminerCustomization extends AdminerPlugin {
    }
    return new AdminerCustomization($plugins);
    */

    return new AdminerPlugin($plugins);
}

// include original Adminer or Adminer Editor
include './adminer.php';
