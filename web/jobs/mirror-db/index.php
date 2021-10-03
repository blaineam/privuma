<?php
include(__DIR__.'/../../helpers/dotenv.php');
loadEnv(__DIR__ . '/../../config/.env');
$host = get_env('MYSQL_HOST');
$hostExternal = get_env('MYSQL_HOST_EXTERNAL');
$db   = get_env('MYSQL_DATABASE');
$user = get_env('MYSQL_USER');
$pass =  get_env('MYSQL_PASSWORD');
exec(__DIR__ . '/../../bin/db-sync --user ' . $user . ' --password ' . $pass . ' --hash sha1 -e --delete ' . $host . ' ' . $hostExternal . ' ' . $db . '.media');