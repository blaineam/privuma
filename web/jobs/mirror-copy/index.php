<?php
require_once(__DIR__.'/../../helpers/dotenv.php');
loadEnv(__DIR__ . '/../../config/.env');
$RCLONE_MIRROR = get_env('RCLONE_MIRROR');
$RCLONE_DESTINATION = get_env('RCLONE_DESTINATION');
if (!get_env('MIRROR_FILES')){
    exit();
}
require(__DIR__ . '/../../helpers/cloud-fs-operations.php'); 
$opsDest = new cloudFS\Operations($RCLONE_DESTINATION, false);
function syncEncodedPath($path) {
    global $opsDest;
    global $RCLONE_DESTINATION;
    global $RCLONE_MIRROR;

    foreach($opsDest->scandir($path, true) as $child) {
        $childPath = $child['Name'];
        $target = str_replace(DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $path . DIRECTORY_SEPARATOR . $childPath);
        if(!$child['IsDir']) {
            if(strpos($opsDest->decode($target), '---compressed.') !== false || strpos($opsDest->decode($target), '.pdf') !== false){
                if($opsDest->sync($RCLONE_DESTINATION . $target, $RCLONE_MIRROR . dirname($target), false, false, false,  ['--track-renames --ignore-existing --size-only --transfers 2 --checkers 2  --s3-chunk-size 64M '])){
                    echo PHP_EOL . "synced: " . $target;
                }
            }
        }else if($child['IsDir']) {
            if(!in_array(basename($opsDest->decode($target)), ['@eaDir'])){
                echo PHP_EOL.$target;
                syncEncodedPath($target);
            }
        }
    }
}
syncEncodedPath(DIRECTORY_SEPARATOR.'ZGF0YQ==');

$opsDest = new cloudFS\Operations($RCLONE_DESTINATION, false);
function syncNoEncodePath($path) {
    global $opsDest;
    global $RCLONE_DESTINATION;
    global $RCLONE_MIRROR;

    foreach($opsDest->scandir($path, true) as $child) {
        $childPath = $child['Name'];
        $target = str_replace(DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $path . DIRECTORY_SEPARATOR . $childPath);
        if(!$child['IsDir']) {
            if(strpos($opsDest->decode($target), '.lock') === false || strpos($opsDest->decode($target), '.txt') === false){
                if($opsDest->sync($RCLONE_DESTINATION . $target, $RCLONE_MIRROR . dirname($target), false, false, false,  ['--track-renames --ignore-existing --size-only --transfers 2 --checkers 2  --s3-chunk-size 64M '])){
                    echo PHP_EOL . "synced: " . $target;
               }
            }
        }else if($child['IsDir']) {
            if(!in_array(basename($opsDest->decode($target)), ['ZGF0YQ==', 'data', '#recycle', '@eaDir']) && !(strpos($opsDest->decode($target), 'jobs') !== false && in_array(basename($opsDest->decode($target)), ['scratch'])) ){
                echo PHP_EOL.$target;
                syncNoEncodePath($target);
            }
        }
    }
}
syncNoEncodePath(DIRECTORY_SEPARATOR);
//exec(__DIR__ . '/../../bin/rclone --config ' . __DIR__ . '/../../config/rclone/rclone.conf --exclude "#recycle/**" --exclude "@eaDir/**" --exclude "@eaDir/"  --exclude "jobs/**/scratch/" --track-renames --ignore-existing --size-only --transfers 2 --checkers 2  --s3-chunk-size 64M -v --log-file=' . realpath(__DIR__ . '/../../logs/mirror-copy-out.txt') .' copy ' . $RCLONE_DESTINATION . '/ZGF0YQ==/ ' . $RCLONE_MIRROR . '/ZGF0YQ==/');