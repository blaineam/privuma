#!/bin/bash
killall php && find . -name job.lock -type f -delete && php tools/restart-cron.php
