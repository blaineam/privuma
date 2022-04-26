#!/bin/bash
pkill -f privuma
find . -name job.lock -type f -delete
rm app/output/logs/*
rm -f /tmp/PVMA*
php tools/restart-cron.php
