<?php

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
$conn = $privuma->getPDO();

$blocklist = array_map('strtoupper', json_decode(file_get_contents($privuma->getConfigDirectory() . DIRECTORY_SEPARATOR . 'global-blocklist.json'), true) ?? []);
if (count($blocklist) > 0) {
    $comicQuery = "
                select album
                from media
                where
                    album like 'comics---%'
                    and album not in (
                        select album
                        from media
                        where hash in (
                            select hash
                            from media
                            where album = 'Favorites'
                        )
                    )
                    and upper(
                        concat(
                            album,
                            filename,
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
            ";
    $blockedComicAlbums = array_column(
        $conn->query(
            $comicQuery
        )->fetchAll(),
        'album'
    );
    $blockingQuery = "
                update media
                set blocked = case
                    when hash not in (select hash from media where album = 'Favorites')
                        and
                        (
                            album in ('" . implode("', '", $blockedComicAlbums) . "')
                            or upper(
                                concat(
                                    album,
                                    filename,
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
                        )
                    then 1
                    else 0
                end;
            ";
    $blockingQuery2 = '
                update media set blocked = case
                    when hash in (select hash from media where blocked = 1) then 1
                    else 0
                end;
            ';
    echo PHP_EOL
        . 'Set Blocked column for: '
        . $conn->query(
            $blockingQuery
        )->rowCount() + $conn->query(
            $blockingQuery2
        )->rowCount()
        . ' rows';
}

 echo PHP_EOL
. 'Set Score column for: '
. $conn->query(
    "UPDATE media SET score = COALESCE(CAST(NULLIF(SUBSTRING(REGEXP_SUBSTR(metadata, 'Rating: [0-9]+'), 9), '') AS SIGNED), null) WHERE metadata like '%Rating:%' and score is null;"
)->rowCount() 
. ' rows';
