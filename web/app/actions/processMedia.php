<?php

namespace privuma\actions;

use privuma\helpers\mediaFile;
use privuma\helpers\cloudFS;
use privuma\queue\QueueManager;
use privuma\privuma;

class processMedia
{
    public function __construct(array $data)
    {
        $qm = new QueueManager();
        $ext = pathinfo($data['filename'], PATHINFO_EXTENSION);
        $correctedFilename =
            basename($data['filename'], '.' . $ext) . '.' . strtolower($ext);
        if (isset($data['album']) && isset($data['filename'])) {
            if (isset($data['url'])) {
                $mediaFile = new mediaFile($correctedFilename, $data['album']);

                if (isset($data['metadata'])) {
                    $mediaFile->setMetadata($data['metadata']);
                }

                if (!isset($data['cache']) || isset($data['download'])) {
                    $mediaFile = new mediaFile(
                        $correctedFilename,
                        $data['album'],
                        null,
                        null,
                        null,
                        null,
                        $data['url'],
                        isset($data['thumbnail']) ? $data['thumbnail'] : null
                    );
                }
                $existingFile = $mediaFile->source();
                echo PHP_EOL . 'Loaded MediaFile: ' . $mediaFile->path();
                if ($existingFile === false || isset($data['download'])) {
                    if (isset($data['download'])) {
                        $downloadOps = new cloudFS(
                            $data['download'],
                            true,
                            '/usr/bin/rclone',
                            null,
                            true
                        );
                        $mediaPreservationPath = str_replace(
                            [
                                '.mpg',
                                '.mod',
                                '.mmv',
                                '.tod',
                                '.wmv',
                                '.asf',
                                '.avi',
                                '.divx',
                                '.mov',
                                '.m4v',
                                '.3gp',
                                '.3g2',
                                '.mp4',
                                '.m2t',
                                '.m2ts',
                                '.mts',
                                '.mkv',
                                '.webm',
                            ],
                            '.mp4',
                            $data['hash'] .
                                '.' .
                                pathinfo($mediaFile->path(), PATHINFO_EXTENSION)
                        );
                        $dlExt = pathinfo(
                            $mediaPreservationPath,
                            PATHINFO_EXTENSION
                        );
                        $isGif = strtoupper($dlExt) === 'GIF';
                        $gifThumbnailPath = str_replace(
                            ['.gif', '.GIF'],
                            '.jpg',
                            $mediaPreservationPath
                        );
                        $gifThumbnailExists = $downloadOps->is_file(
                            $gifThumbnailPath
                        );
                        if (
                            $isGif &&
                            $gifThumbnailExists &&
                            $downloadOps->is_file($mediaPreservationPath)
                        ) {
                            echo PHP_EOL .
                                "Skip Existing Media already downloaded to: $mediaPreservationPath";
                            return;
                        }
                        if (
                            !$isGif &&
                            $downloadOps->is_file($mediaPreservationPath)
                        ) {
                            echo PHP_EOL .
                                "Skip Existing Media already downloaded to: $mediaPreservationPath";
                            return;
                        }
                    }

                    if (!isset($data['cache']) && !isset($data['download'])) {
                        $mediaFile->save();
                        if (isset($data['metadata'])) {
                            $mediaFile->setMetadata($data['metadata']);
                        }
                        return;
                    } elseif (
                        isset($data['download']) &&
                        ($mediaPath = $this->downloadUrl($data['url']))
                    ) {
                        if (
                            in_array(md5_file($mediaPath), [
                                // Image Not Found
                                'a6433af4191d95f6191c2b90fc9117af',
                                // Empty File
                                'd41d8cd98f00b204e9800998ecf8427e',
                            ]) ||
                            !in_array(
                                explode(
                                    '/',
                                    strtolower(mime_content_type($mediaPath))
                                )[0],
                                ['image', 'video']
                            )
                        ) {
                            echo PHP_EOL .
                                'Invalid file found: ' .
                                $data['url'];
                            return;
                        }
                        $dlExt = !empty(
                            pathinfo($mediaPath, PATHINFO_EXTENSION)
                        )
                            ? pathinfo($mediaPath, PATHINFO_EXTENSION)
                            : pathinfo(
                                $mediaPreservationPath,
                                PATHINFO_EXTENSION
                            );
                        if (strtoupper($dlExt) === 'GIF') {
                            echo PHP_EOL . 'generating thumbnail for gif image';
                            $dlThumbDest =
                                str_replace('.gif', '', $mediaPath) . '.jpg';
                            $dlThumbPreservationPath =
                                str_replace(
                                    '.gif',
                                    '',
                                    $mediaPreservationPath
                                ) . '.jpg';
							$cmediapath = escapeshellarg($mediaPath);
                            exec(
                                "nice convert '{$cmediapath}[0]' -monitor -sampling-factor 4:2:0 -strip -interlace JPEG -colorspace sRGB -resize 1000 -compress JPEG -quality 70 '$dlThumbDest'"
                            );
                            echo PHP_EOL .
                                "Downloading media thumbnail to: $dlThumbPreservationPath";
                            $downloadOps->rename(
                                $dlThumbDest,
                                $dlThumbPreservationPath,
                                false
                            );
                        }

                        echo PHP_EOL .
                            "Attempting to Compress Media: $mediaPath | " .
                            $data['url'];
                        if (
                            (new preserveMedia([], $downloadOps))->compress(
                                $mediaPath,
                                $mediaPreservationPath,
                            )
                        ) {
                            is_file($mediaPath) && unlink($mediaPath);
                            echo PHP_EOL .
                                "Downloaded media to: $mediaPreservationPath";
                        } else {
                            echo PHP_EOL . 'Compression failed';
                            if (is_file($mediaPath)) {
                                echo PHP_EOL .
                                    "Downloading media to: $mediaPreservationPath";
                                $downloadOps->rename(
                                    $mediaPath,
                                    $mediaPreservationPath,
                                    false
                                );
                            } else {
                                echo PHP_EOL .
                                    'Download failed: ' .
                                    $data['url'];
                            }
                        }

                        if (
                            isset($data['thumbnail']) &&
                            ($thumbnailPath = $this->downloadUrl(
                                $data['thumbnail']
                            ))
                        ) {
                            $thumbnailPreservationPath = str_replace(
                                '.mp4',
                                '.jpg',
                                $mediaPreservationPath
                            );
                            if (
                                (new preserveMedia([], $downloadOps))->compress(
                                    $thumbnailPath,
                                    $thumbnailPreservationPath,
                                )
                            ) {
                                is_file($thumbnailPath) &&
                                    unlink($thumbnailPath);
                                echo PHP_EOL .
                                    "Downloaded media to: $thumbnailPreservationPath";
                            } else {
                                echo PHP_EOL . 'Compression failed';
                                if (is_file($thumbnailPath)) {
                                    echo PHP_EOL .
                                        "Downloading media to: $thumbnailPreservationPath";
                                    $downloadOps->rename(
                                        $thumbnailPath,
                                        $thumbnailPreservationPath,
                                        false
                                    );
                                } else {
                                    echo PHP_EOL . 'Download failed';
                                }
                            }
                        }
                        return;
                    } elseif ($tempPath = $this->downloadUrl($data['url'])) {
                        echo PHP_EOL . 'Downloaded Media File to: ' . $tempPath;
                        $qm->enqueue(
                            json_encode([
                                'type' => 'preserveMedia',
                                'data' => [
                                    'path' => $tempPath,
                                    'album' => $data['album'],
                                    'filename' => $data['filename'],
                                ],
                            ])
                        );
                    }
                } else {
                    if (!$mediaFile->record()) {
                        if (!$mediaFile->save()) {
                            echo PHP_EOL . 'Preservation Failed for: ' . privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path();
                        } else {
                            echo PHP_EOL . 'Preservation succcessful for missing database entry: ' . privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path();
                        }
                    }
                    echo PHP_EOL .
                        'Existing MediaFile located at: ' .
                        $existingFile .
                        ' For: ' .
                        $data['url'];
                }
                return;
            }

            $mediaFile = new mediaFile($correctedFilename, $data['album'], null, isset($data['path']) ? md5_file($data['path']) : null );
            $existingFile = $mediaFile->realPath();
            echo PHP_EOL . 'Loaded MediaFile: ' . $mediaFile->path();
            if (isset($data['path'])) {
                if ($existingFile === false) {
                    if (
                        $tempPath = $this->loadPath(
                            $data['path'],
                            isset($data['local']) ? true : false
                        )
                    ) {
                        echo PHP_EOL . 'Pulled Media File to: ' . $tempPath;
                        $qm->enqueue(
                            json_encode([
                                'type' => 'preserveMedia',
                                'data' => [
                                    'path' => $tempPath,
                                    'album' => $data['album'],
                                    'filename' => $data['filename'],
                                ],
                            ])
                        );
                    } else {
                        echo PHP_EOL .
                            'Failed to obtain media file from path: ' .
                            $data['path'];
                    }
                } else {
                    if (!$mediaFile->record()) {
                        if (!$mediaFile->save()) {
                            echo PHP_EOL . 'Preservation Failed for: ' . privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path();
                        } else {
                            echo PHP_EOL . 'Preservation succcessful for missing database entry: ' . privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path();
                        }
                    }
                    
                    unlink($data['path']);
                    echo PHP_EOL .
                        'Existing MediaFile located at: ' .
                        $existingFile .
                        ' For: ' .
                        $data['path'];
                }
            }
            return;
        }
        if (
            (isset($data['url']) || isset($data['path'])) &&
            isset($data['preserve']) &&
            !privuma::getCloudFS()->is_file($data['preserve'])
        ) {
            if (
                $this->getDirectorySize(sys_get_temp_dir()) >=
                1024 * 1024 * 1024 * 25
            ) {
                echo PHP_EOL . 'Temp Directory full, cleaning temp director';
                foreach (
                    glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . '*') as $file
                ) {
                    if (time() - filectime($file) > 60 * 60 * 2) {
                        unlink($file);
                    }
                }
                echo PHP_EOL . 'Requeue Message';
                $qm->enqueue(
                    json_encode(['type' => 'processMedia', 'data' => $data])
                );
                return;
            }

            if (isset($data['url'])) {
                if (isset($data['download'])) {
                    $downloadOps = new cloudFS(
                        $data['download'],
                        true,
                        '/usr/bin/rclone',
                        null,
                        true
                    );
                    if ($downloadOps->is_file($data['preserve'])) {
                        echo PHP_EOL .
                            'Skip Existing Media already downloaded to: ' .
                            $data['preserve'];
                        return;
                    } elseif ($tempPath = $this->downloadUrl($data['url'])) {
                        echo PHP_EOL .
                            'Uploading Media to: ' .
                            $data['preserve'];
                        $downloadOps->rename(
                            $tempPath,
                            $data['preserve'],
                            false
                        );
                        return;
                    }
                }
                if ($tempPath = $this->downloadUrl($data['url'])) {
                    echo PHP_EOL .
                        'Downloaded Preservation File to: ' .
                        $tempPath;
                    $qm->enqueue(
                        json_encode([
                            'type' => 'preserveMedia',
                            'data' => [
                                'preserve' => $data['preserve'],
                                'skipThumbnail' => $data['skipThumbnail'],
                                'path' => $tempPath,
                            ],
                        ])
                    );
                } else {
                    echo PHP_EOL .
                        'Failed to obtain preserve file from url: ' .
                        $data['url'];
                }
            } elseif (isset($data['path'])) {
                if (
                    $tempPath = $this->loadPath(
                        $data['path'],
                        isset($data['local']) ? true : false
                    )
                ) {
                    echo PHP_EOL . 'Using Preservation File at: ' . $tempPath;
                    $qm->enqueue(
                        json_encode([
                            'type' => 'preserveMedia',
                            'data' => [
                                'preserve' => $data['preserve'],
                                'skipThumbnail' => $data['skipThumbnail'],
                                'path' => $tempPath,
                            ],
                        ])
                    );
                } else {
                    echo PHP_EOL .
                        'Failed to obtain preserve file from filesystem path: ' .
                        $data['path'];
                }
            }
        } else {
            if (!$mediaFile->record()) {
                if (!$mediaFile->save()) {
                    echo PHP_EOL . 'Preservation Failed for: ' . privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path();
                } else {
                    echo PHP_EOL . 'Preservation succcessful for missing database entry: ' . privuma::getDataFolder() . DIRECTORY_SEPARATOR . $mediaFile->path();
                }
            }
            echo PHP_EOL .
                'Existing preserve file located at: ' .
                $data['preserve'];
        }
    }

    private function downloadUrl(string $url): ?string
    {
        return privuma::getContent($url, [], null, privuma::getConfigDirectory() . DIRECTORY_SEPARATOR . 'cookies' . DIRECTORY_SEPARATOR . 'curl.txt', true);
    }

    private function loadPath(string $path, bool $directPath = false): ?string
    {
        if (is_file($path)) {
            clearstatcache();
            if (!filesize($path)) {
                return null;
            }

            if ($directPath) {
                return $path;
            }
            $tmpfile = tempnam(sys_get_temp_dir(), 'PVMA');
            copy($path, $tmpfile);
            rename(
                $tmpfile,
                $tmpfile . '.' . pathinfo($path, PATHINFO_EXTENSION)
            );

            return $tmpfile . '.' . pathinfo($path, PATHINFO_EXTENSION);
        }

        return privuma::getCloudFS()->pull($path);
    }

    private function getDirectorySize($path)
    {
        if (!is_dir($path)) {
            return 0;
        }

        $path = strval($path);
        $io = popen(
            "ls -ltrR {$path} 2>/dev/null |awk '{print \$5}'|awk 'BEGIN{sum=0} {sum=sum+\$1} END {print sum}'",
            'r'
        );
        $size = intval(fgets($io, 80));
        pclose($io);

        return $size;
    }
}
