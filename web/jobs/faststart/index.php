<?php

$dest = realpath(__DIR__ . '/../../data/privuma');
exec("find " . $dest . " -type f -name *.mp4 ! -name *---dupe.mp4 -exec sh -c \"/usr/bin/ffmpeg -threads 1 -hide_banner -loglevel error -y -i '{}' -c copy -map 0 -movflags +faststart '{}-fast.mp4' && rm '{}' && mv '{}-fast.mp4' '{}'\" \;");