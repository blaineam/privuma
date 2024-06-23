<?php

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
$conn = $privuma->getPDO();

$blocklist = array_map('strtoupper', json_decode(file_get_contents($privuma->getConfigDirectory() . DIRECTORY_SEPARATOR . 'global-blocklist.json'), true) ?? []);
if (count($blocklist) > 0 ) {
    $blockedComicAlbums = array_column(
        $conn->query(
            "
                select album
                from media
                where
                    album like 'Comics---%'
                    and upper(
                        concat(
                            'Album: ',
                            album,
                            '\nFilename: ',
                            filename,
                            '\n',
                            substring_index(
                                coalesce(
                                    metadata,
                                    ''
                                ),
                                '\nComments: ',
                                1
                            )
                        )
                    ) regexp '" . implode('|', $blocklist) . "'
                group by album;
            "
        )->fetchAll(),
        "album"
    );
    echo PHP_EOL
        . "Set Blocked column for: "
        . $conn->query(
            "
                update media
                set blocked = case
                    when upper(
                        concat(
                            'Album: ',
                            album,
                            '\nFilename: ',
                            filename,
                            '\n',
                            substring_index(
                                coalesce(
                                    metadata,
                                    ''
                                ),
                                '\nComments: ',
                                1
                            )
                        )
                    ) regexp '" . implode('|', $blocklist) . "'
                    then 1
                    when album in ('" . implode("', '", $blockedComicAlbums) . "')
                    then 1
                    else 0
                end;
            "
        )->rowCount()
        . " rows";
}
