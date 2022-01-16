#!/bin/bash
killall -9 php
killall -9 rclone
killall -9 python3
find . -name job.lock -type f -delete
rm app/output/logs/*
rm -f /tmp/PVMA*
php tools/restart-cron.php
