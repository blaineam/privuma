#!/bin/bash
LOGPATH=../../logs/privuma-$1.txt
cd /volume1/docker/privuma/tools
./docker.sh exec php-cron php jobs/core/$1/index.php "${@:2}" 2>&1 > $LOGPATH
#./docker.sh exec php-cron php jobs/core/queue-worker-1/index.php 2>&1 >> $LOGPATH
./docker.sh exec php-cron php jobs/core/db-transformer/index.php 2>&1 >> $LOGPATH
./docker.sh exec php-cron php jobs/core/download/index.php 2>&1 >> $LOGPATH
#./docker.sh exec  php-cron php jobs/core/queue-worker-1/index.php 2>&1 >> $LOGPATH