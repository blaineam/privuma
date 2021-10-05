<?php
include(__DIR__.'/../../helpers/dotenv.php');
loadEnv(__DIR__ . '/../../config/.env');
$host = get_env('MYSQL_HOST');
$hostExternal = get_env('MYSQL_HOST_EXTERNAL');
$db   = get_env('MYSQL_DATABASE');
$user = get_env('MYSQL_USER');
$pass =  get_env('MYSQL_PASSWORD');
exec('/usr/bin/pt-table-sync --verbose --execute --no-check-slave h=' . $host . ',D=' . $db . ',t=media,p=' . $pass . ',u=' . $user . ' h=' . $hostExternal . ',D=' . $db . ',t=media,p=' . $pass . ',u=' . $user);