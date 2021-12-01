<?php
include(__DIR__.'/../../helpers/dotenv.php');
loadEnv(__DIR__ . '/../../config/.env');
$RCLONE_DESTINATION = get_env('RCLONE_DESTINATION');
exec(__DIR__ . '/../../bin/rclone --config ' . __DIR__ . '/../../config/rclone.conf --exclude "#recycle/**" --exclude "@eaDir/**" --exclude "@eaDir/"  --exclude "jobs/**/scratch/" --track-renames --ignore-existing --size-only --transfers 2 --checkers 2  --s3-chunk-size 64M -v --log-file=' . realpath(__DIR__ . '/../../logs/mirror-sync-out.txt') .' sync ' . realpath(__DIR__ . '/../../') . ' ' . $RCLONE_DESTINATION);