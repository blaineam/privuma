<?php

namespace privuma\helpers;

use PDO;

class databaseBuilder
{
    /**
     * Sanitize a line by removing invalid characters
     */
    public static function sanitizeLine($line)
    {
        return trim(preg_replace('/[^A-Za-z0-9 \\-\\_\\~\\+\\(\\)\\.\\,\\/]/', '', $line), "\r\n");
    }

    /**
     * Trim extra newlines from a string
     */
    public static function trimExtraNewLines($string)
    {
        return trim(
            implode(
                PHP_EOL,
                array_map(function ($line) {
                    return self::sanitizeLine($line);
                }, explode(PHP_EOL, $string))
            ),
            "\r\n"
        );
    }

    /**
     * Parse metadata from a media item
     */
    public static function parseMetaData($item)
    {
        $dateValue = explode(PHP_EOL, explode('Date: ', $item)[1] ?? '')[0];
        $intval = filter_var($dateValue, FILTER_VALIDATE_INT);
        if ($intval) {
            $dateValue = '@' . substr($dateValue, 0, 10);
        }
        return [
            'title' => explode(PHP_EOL, explode('Title: ', $item)[1] ?? '')[0],
            'author' => explode(PHP_EOL, explode('Author: ', $item)[1] ?? '')[0],
            'date' => new \DateTime($dateValue),
            'rating' => (int) explode(PHP_EOL, explode('Rating: ', $item)[1] ?? '')[0],
            'favorites' => (int) explode(
                PHP_EOL,
                explode('Favorites: ', $item)[1] ?? ''
            )[0],
            'description' => explode(
                'Tags:',
                explode('Description: ', $item)[1] ?? ''
            )[0],
            'tags' =>
                explode(', ', explode(PHP_EOL, explode('Tags: ', $item)[1] ?? '')[0]) ??
                [],
            'comments' => explode('Comments: ', $item)[1] ?? '',
        ];
    }

    /**
     * Condense metadata into a compact format
     */
    public static function condenseMetaData($item)
    {
        return str_replace(
            PHP_EOL,
            '\n',
            mb_convert_encoding(
                str_replace(
                    PHP_EOL . PHP_EOL,
                    PHP_EOL,
                    implode(PHP_EOL, [
                        self::sanitizeLine(
                            $item['title'] ?:
                            substr(self::trimExtraNewLines($item['description']), 0, 150)
                        ),
                        self::sanitizeLine($item['favorites']),
                        self::sanitizeLine(implode(', ', array_slice($item['tags'], 0, 20))),
                    ])
                ),
                'UTF-8',
                'UTF-8'
            )
        );
    }

    /**
     * Filter array by excluding certain keys
     */
    public static function filterArrayByKeys($originalArray, $blacklistedKeys)
    {
        $newArray = array();
        foreach ($originalArray as $key => $value) {
            if (!in_array($key, $blacklistedKeys)) {
                $newArray[$key] = $value;
            }
        }
        return $newArray;
    }

    /**
     * Get first element matching criteria
     */
    public static function getFirst($array, $key, $value = null, $negate = false)
    {
        foreach ($array as $element) {
            if (
                isset($element[$key])
                && (
                    is_null($value)
                    || (
                        (
                            !$negate
                            && $element[$key] === $value
                        )
                        ||
                        (
                            $negate
                            && $element[$key] !== $value
                        )
                    )
                )
            ) {
                return $element;
            }
        }
        return null;
    }

    /**
     * Build database array from dataset
     *
     * @param array $dataset The raw dataset from database
     * @param array &$metaDataFiles Reference to metadata files array
     * @param int $tagLimit Maximum number of tags to include (default: 60)
     * @param int $filenameLimit Maximum filename length (default: 20)
     * @return array The processed array grouped by hash
     */
    public static function buildDatabaseArray($dataset, &$metaDataFiles = null, $tagLimit = 60, $filenameLimit = 20)
    {
        $array = [];

        foreach ($dataset as $item) {
            // Handle metadata storage
            if (!is_null($metaDataFiles) && !is_null($item['metadata']) && strlen($item['metadata']) > 3) {
                $targetMetaDataPrefix = substr(base64_encode($item['hash']), 0, 2);
                if (!array_key_exists($targetMetaDataPrefix, $metaDataFiles)) {
                    $metaDataFiles[$targetMetaDataPrefix] = [];
                }
                $metaDataFiles[$targetMetaDataPrefix][$item['hash']] = $item['metadata'];
            }

            // Extract and condense tags
            $tags = substr(
                self::sanitizeLine(
                    implode(', ', array_slice(
                        explode(', ', explode(PHP_EOL, explode('Tags: ', $item['metadata'])[1] ?? '')[0]) ?? [],
                        0,
                        $tagLimit
                    ))
                ),
                0,
                500
            );
            $item['metadata'] = is_null($item['metadata']) ? '' : (strlen($tags) < 1 ? 'Using MetaData Store...' : $tags);

            // Build or update array entry
            if (!array_key_exists($item['hash'], $array)) {
                $filenameParts = explode('-----', $item['filename']);
                $array[$item['hash']] = [
                    'albums' => [self::sanitizeLine($item['album'])],
                    'filename' => self::sanitizeLine(substr(end($filenameParts), 0, $filenameLimit)) . '.' . pathinfo($item['filename'], PATHINFO_EXTENSION),
                    'hash' => $item['hash'],
                    'times' => [$item['time']],
                    'metadata' => $item['metadata'],
                    'duration' => $item['duration'],
                    'sound' => $item['sound'],
                    'score' => $item['score']
                ];
            } else {
                $array[$item['hash']]['albums'][] = self::sanitizeLine($item['album']);
                $array[$item['hash']]['times'][] = $item['time'];
            }
        }

        return $array;
    }

    /**
     * Build albums index with metadata and hash for on-demand loading
     * Combines album list and index into single file
     *
     * @param array $fullData The full dataset array
     * @return array Albums with name, count, time, hash, and cover info for loading
     */
    public static function buildAlbumsIndex($fullData)
    {
        $albumsMap = [];
        $videoExts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'wmv', 'm4v', '3gp'];
        $gifExt = ['gif'];

        foreach ($fullData as $item) {
            $itemTime = $item['times'][0] ?? null;
            $itemHash = $item['hash'] ?? null;
            $itemFilename = $item['filename'] ?? '';
            $ext = strtolower(pathinfo($itemFilename, PATHINFO_EXTENSION));

            // Determine cover extension: videos/gifs use .jpg thumbnail, images use their own extension
            $needsThumbnail = in_array($ext, $videoExts) || in_array($ext, $gifExt);
            $coverExt = $needsThumbnail ? 'jpg' : $ext;

            foreach ($item['albums'] as $albumIndex => $album) {
                $albumTime = $item['times'][$albumIndex] ?? $itemTime;

                if (!isset($albumsMap[$album])) {
                    $albumsMap[$album] = [
                        'album' => $album,
                        'hash' => substr(md5($album), 0, 8),
                        'count' => 0,
                        'time' => $albumTime,
                        'coverHash' => $itemHash,
                        'coverExt' => $coverExt,
                    ];
                }
                $albumsMap[$album]['count']++;

                // Update cover to most recent item
                if (!is_null($albumTime)) {
                    if (is_null($albumsMap[$album]['time']) || $albumTime > $albumsMap[$album]['time']) {
                        $albumsMap[$album]['time'] = $albumTime;
                        $albumsMap[$album]['coverHash'] = $itemHash;
                        $albumsMap[$album]['coverExt'] = $coverExt;
                    }
                }
            }
        }

        // Sort by time (most recent first)
        uasort($albumsMap, function ($a, $b) {
            return ($a['time'] ?? '') < ($b['time'] ?? '') ? 1 : (($b['time'] ?? '') < ($a['time'] ?? '') ? -1 : 0);
        });

        return array_values($albumsMap);
    }

    /**
     * Split data by albums into separate arrays
     *
     * @param array $fullData The full dataset array
     * @return array Associative array of album name => items in that album
     */
    public static function splitByAlbums($fullData)
    {
        $albumData = [];

        foreach ($fullData as $item) {
            foreach ($item['albums'] as $album) {
                if (!isset($albumData[$album])) {
                    $albumData[$album] = [];
                }
                $albumData[$album][] = $item;
            }
        }

        return $albumData;
    }

    /**
     * Encode data for JavaScript output
     *
     * @param mixed $data Data to encode
     * @return string JSON encoded and sanitized for JavaScript
     */
    public static function encodeForJS($data)
    {
        return str_replace(
            '$',
            'USD',
            str_replace(
                "'",
                '-',
                str_replace(
                    '`',
                    '-',
                    json_encode($data, JSON_THROW_ON_ERROR)
                )
            )
        );
    }
}
