<?php
namespace privuma\helpers;

use Exception;
use resource;
use privuma\privuma;

class cloudFS
{

    private string $rCloneBinaryPath;
    private string $rCloneConfigPath;
    private string $rCloneDestination;
    private bool $encoded;
    private bool $segmented;
    private dotenv $env;

    // Performance caches
    private static array $pathInfoCache = [];
    private static array $pathInfoCacheTime = [];
    private static array $scandirCache = [];
    private static array $scandirCacheTime = [];
    private static array $encodingCache = [];
    private static int $cacheHits = 0;
    private static int $cacheMisses = 0;
    private static int $maxCacheSize = 1000;
    private static int $cacheTTL = 300; // 5 minutes

    public function __construct(
        string $rCloneDestination = 'privuma:',
        bool $encoded = true,
        string $rCloneBinaryPath = '/usr/bin/rclone',
        ?string $rCloneConfigPath = null,
        bool $segmented = false
    ) {
        $this->env = new dotenv();
        exec('cpulimit -f -l ' . privuma::getEnv('MAX_CPU_PERCENTAGE') . ' -- ' . $rCloneBinaryPath . ' version 2>&1 > /dev/null', $void, $code);
        if ($code !== 0) {
            $rCloneBinaryPath = '/usr/local/bin/rclone';
            exec('cpulimit -f -l ' . privuma::getEnv('MAX_CPU_PERCENTAGE') . ' -- ' . $rCloneBinaryPath . ' version 2>&1 > /dev/null', $void, $code);
            if ($code !== 0) {
                $rCloneBinaryPath = __DIR__ . '/../bin/rclone';
            }
        }

        $this->rCloneBinaryPath = $rCloneBinaryPath;
        $this->rCloneConfigPath = $rCloneConfigPath ?? privuma::getConfigDirectory() . DIRECTORY_SEPARATOR . 'rclone' . DIRECTORY_SEPARATOR . 'rclone.conf';
        $this->rCloneDestination = ($rCloneDestination !== 'privuma:') ? $rCloneDestination : ($this->env->get('RCLONE_DESTINATION') ?? $rCloneDestination);
        $this->encoded = $encoded;
        $this->segmented = $segmented;
    }

    public function scandir(string $directory, bool $objects = false, bool $recursive = false, ?array $filters = null, $dirsOnly = false, $filesOnly = false, $noModTime = false, $noMimeType = false)
    {
        // // Create cache key for this specific scandir request
        // $cacheKey = 'cloudfs:' . md5($this->rCloneDestination) . ':scandir:' . md5($directory . (int) $objects . (int) $recursive . serialize($filters) . (int) $dirsOnly . (int) $filesOnly . (int) $noModTime . (int) $noMimeType);
        //
        // // Try Redis cache first (persistent across requests)
        // $cached = redisCache::get($cacheKey);
        // if ($cached !== null) {
        //     self::$cacheHits++;
        //     return $cached;
        // }
        //
        // // Check local cache second
        // $localCacheKey = md5($directory . (int) $objects . (int) $recursive . serialize($filters) . (int) $dirsOnly . (int) $filesOnly . (int) $noModTime . (int) $noMimeType);
        // if (isset(self::$scandirCache[$localCacheKey])) {
        //     $cacheTime = self::$scandirCacheTime[$localCacheKey];
        //     if (time() - $cacheTime < self::$cacheTTL) {
        //         self::$cacheHits++;
        //         return self::$scandirCache[$localCacheKey];
        //     }
        //     // Expired cache entry
        //     unset(self::$scandirCache[$localCacheKey], self::$scandirCacheTime[$localCacheKey]);
        // }

        if (!$this->is_dir($directory) && $directory !== DIRECTORY_SEPARATOR) {
            error_log('not a dir');
            return false;
        }

        //self::$cacheMisses++;

        try {
            $filter = null;
            if (is_array($filters)) {
                $filter = '';
                foreach ($filters as $internal_filter) {
                    $filterType = substr($internal_filter, 0, 1) === '-' ? "--filter '- ": "--filter '+ ";
                    $filter .= ' ' . $filterType . ($this->encoded ? $this->encode(ltrim($internal_filter, '+- ')) : ltrim($internal_filter, '+- ')) . "'";
                }
            }

            $files = json_decode($this->execute('lsjson', $directory, null, false, true, [
                '--min-size 1B',
                ($noMimeType ? '--no-mimetype' : ''),
                ($noModTime ? '--no-modtime' : ''),
                ($dirsOnly ? '--dirs-only' : ''),
                ($filesOnly ? '--files-only' : ''),
                ($recursive !== false) ? '--recursive': '',
                (!is_null($filter)) ? $filter : ''
            ]), true);

            // Optimize sorting - only sort if ModTime is available
            if (!$noModTime && is_array($files)) {
                usort($files, function ($a, $b) {
                    $aTime = isset($a['ModTime']) ? strtotime(explode('.', $a['ModTime'])[0]) : 0;
                    $bTime = isset($b['ModTime']) ? strtotime(explode('.', $b['ModTime'])[0]) : 0;
                    return $bTime <=> $aTime;
                });
            }

            // Optimize decoding - batch process and cache encoding results
            $response = [];
            if (is_array($files)) {
                foreach ($files as $object) {
                    if ($this->encoded) {
                        $nameKey = 'name_' . $object['Name'];
                        $pathKey = 'path_' . $object['Path'];

                        if (!isset(self::$encodingCache[$nameKey])) {
                            self::$encodingCache[$nameKey] = $this->decode($object['Name'], $this->segmented);
                        }
                        if (!isset(self::$encodingCache[$pathKey])) {
                            self::$encodingCache[$pathKey] = $this->decode($object['Path'], $this->segmented);
                        }

                        $object['Name'] = self::$encodingCache[$nameKey];
                        $object['Path'] = self::$encodingCache[$pathKey];

                        // Limit encoding cache size
                        if (count(self::$encodingCache) > 2000) {
                            self::$encodingCache = array_slice(self::$encodingCache, -1000, 1000, true);
                        }
                    }
                    $response[] = $object;
                }
            }

            $result = $objects ? $response : ['.', '..', ...array_column($response, 'Name')];

            // Cache the result with size management
            // if (count(self::$scandirCache) >= self::$maxCacheSize) {
            //     // Remove oldest 25% of entries
            //     $removeCount = intval(self::$maxCacheSize * 0.25);
            //     asort(self::$scandirCacheTime);
            //     $keysToRemove = array_slice(array_keys(self::$scandirCacheTime), 0, $removeCount);
            //
            //     foreach ($keysToRemove as $key) {
            //         unset(self::$scandirCache[$key], self::$scandirCacheTime[$key]);
            //     }
            // }

            // // Cache in Redis with 5 minute TTL
            // redisCache::set($cacheKey, $result, 300);

            // // Also cache locally
            // self::$scandirCache[$localCacheKey] = $result;
            // self::$scandirCacheTime[$localCacheKey] = time();

            return $result;
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    public function glob($pattern): array
    {
        $recursiveParts = explode('**', $pattern);
        $wildcards = explode('*', str_replace('**', '', $pattern));
        if (count($recursiveParts) > 1) {
            $wildcardParent = substr($recursiveParts[0], -1) === DIRECTORY_SEPARATOR ? $recursiveParts[0] : dirname($recursiveParts[0]) . DIRECTORY_SEPARATOR;
            $scan = $this->scandir($wildcardParent, true, true);
            if ($scan === false) {
                return [];
            }
            $paths = array_column($scan, 'Path');
        } else {
            $wildcardParent = substr($wildcards[0], -1) === DIRECTORY_SEPARATOR ? $wildcards[0] : dirname($wildcards[0]) . DIRECTORY_SEPARATOR;
            $scan = $this->scandir($wildcardParent, true);
            if ($scan === false) {
                return [];
            }
            $paths = array_column($scan, 'Path');
        }

        return array_values(array_filter(array_map(function ($path) use ($pattern, $wildcardParent) {
            return fnmatch($pattern, $wildcardParent . $path) ? DIRECTORY_SEPARATOR . trim($wildcardParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : null;
        }, $paths)));
    }

    public function getPathInfo(string $path, bool $modTime = true, bool $mimetype = true, bool $onlyDirs = false, bool $onlyFiles = false, bool $showMD5 = false)
    {
        // Create cache key based on path and parameters
        $cacheKey = 'cloudfs:pathinfo:' . md5($path . (int) $modTime . (int) $mimetype . (int) $onlyDirs . (int) $onlyFiles . (int) $showMD5);

        // Try Redis cache first (persistent across requests)
        $cached = redisCache::get($cacheKey);
        if ($cached !== null) {
            self::$cacheHits++;
            return $cached;
        }

        // Check local cache second (faster but request-specific)
        $localCacheKey = md5($path . (int) $modTime . (int) $mimetype . (int) $onlyDirs . (int) $onlyFiles . (int) $showMD5);
        if (isset(self::$pathInfoCache[$localCacheKey])) {
            $cacheTime = self::$pathInfoCacheTime[$localCacheKey];
            if (time() - $cacheTime < self::$cacheTTL) {
                self::$cacheHits++;
                return self::$pathInfoCache[$localCacheKey];
            }
            // Expired cache entry
            unset(self::$pathInfoCache[$localCacheKey], self::$pathInfoCacheTime[$localCacheKey]);
        }

        self::$cacheMisses++;

        try {
            $list = json_decode($this->execute('lsjson', $path, null, false, true, [
                '--min-size 1B',
                '--stat',
                $modTime ? '' : '--no-modtime',
                $mimetype ? '' : '--no-mimetype',
                $onlyDirs ? '--dirs-only' : '',
                $onlyFiles ? '--files-only' : '',
                $showMD5 ? '--hash --hash-type md5' : '',
            ]), true);
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }

        $result = is_null($list) ? false : $list;

        // Cache the result in Redis (persistent across requests) with 10 minute TTL
        redisCache::set($cacheKey, $result, 600);

        // Also cache locally for this request
        if (count(self::$pathInfoCache) >= self::$maxCacheSize) {
            // Remove oldest 25% of entries
            $removeCount = intval(self::$maxCacheSize * 0.25);
            $sortedKeys = array_keys(self::$pathInfoCacheTime);
            asort(self::$pathInfoCacheTime);
            $keysToRemove = array_slice(array_keys(self::$pathInfoCacheTime), 0, $removeCount);

            foreach ($keysToRemove as $key) {
                unset(self::$pathInfoCache[$key], self::$pathInfoCacheTime[$key]);
            }
        }

        self::$pathInfoCache[$localCacheKey] = $result;
        self::$pathInfoCacheTime[$localCacheKey] = time();

        return $result;
    }

    public function file_exists(string $file): bool
    {
        $info = $this->getPathInfo($file, false, false, false, false, false);
        return $info !== false;
    }

    public function is_file(string $file): bool
    {
        $info = $this->getPathInfo($file, false, false, false, true, false);
        return $info !== false;
    }

    public function filemtime(string $file)
    {
        $info = $this->getPathInfo($file, true, false, false, true, false);
        if ($info !== false && isset($info['ModTime'])) {
            // Cache the strtotime conversion to avoid repeated calls
            static $timeCache = [];
            $timeKey = $info['ModTime'];
            if (!isset($timeCache[$timeKey])) {
                $timeCache[$timeKey] = strtotime(explode('.', $info['ModTime'])[0]);
                // Limit time cache size
                if (count($timeCache) > 500) {
                    $timeCache = array_slice($timeCache, -250, 250, true);
                }
            }
            return $timeCache[$timeKey];
        }
        return false;
    }

    public function touch(string $file, ?int $time = null, ?int $atime = null): bool
    {
        if (is_null($time)) {
            $time = time();
        }
        if (is_null($atime)) {
            $atime = $time;
        }
        try {
            $this->execute('touch', $file, null, false, true, ['--timestamp', date("Y-m-d\TH:i:s", $time) ]);
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        return true;
    }

    public function mime_content_type(string $file)
    {
        $info = $this->getPathInfo($file, false, true, false, true, false);
        if ($info !== false) {
            return $info['MimeType'];
        }
        return false;
    }

    public function filesize(string $file)
    {
        // Try to get size from pathInfo cache first (if available)
        $cacheKey = md5($file . '00000'); // Use same pattern as getPathInfo but for filesize
        if (isset(self::$pathInfoCache[$cacheKey])) {
            $cached = self::$pathInfoCache[$cacheKey];
            if (is_array($cached) && isset($cached['Size'])) {
                self::$cacheHits++;
                return $cached['Size'];
            }
        }

        self::$cacheMisses++;

        try {
            $data = json_decode($this->execute('size', $file, null, false, true, [
                '--json'
            ], false, true, 5.0), true);
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }

        $result = is_null($data) ? false : $data['bytes'];

        // Cache the filesize result
        if ($result !== false && count(self::$pathInfoCache) < self::$maxCacheSize) {
            self::$pathInfoCache[$cacheKey] = ['Size' => $result];
            self::$pathInfoCacheTime[$cacheKey] = time();
        }

        return $result;
    }

    public function is_dir(string $directory): bool
    {
        $info = $this->getPathInfo($directory, false, false, true, false, false);
        return $info !== false;
    }

    public function mkdir(string $directory): bool
    {
        if (!$this->is_dir($directory)) {
            try {
                $this->execute('mkdir', $directory);
            } catch (Exception $e) {
                error_log($e->getMessage());
                return false;
            }
            return true;
        }
        return false;
    }

    public function file_put_contents(string $path, string $contents)
    {
        if (empty($contents)) {
            error_log('Not storing empty file: ' . $path);
            return false;
        }
        $tmpfile = tempnam(sys_get_temp_dir(), 'PVMA');
        file_put_contents($tmpfile, $contents);
        try {
            $this->execute('copyto', $path, $tmpfile, false, true, [], false, false);
        } catch (Exception $e) {
            error_log($e->getMessage());
            unlink($tmpfile);
            return false;
        }
        unlink($tmpfile);
        return mb_strlen($contents, '8bit');
    }

    public function file_get_contents(string $path, bool $use_include_path = false, ?resource $context = null, int $offset = 0, ?int $length = null)
    {
        if ($use_include_path !== false) {
            throw new Exception('NOT IMPLEMENTED');
        }

        if (!is_null($context)) {
            throw new Exception('NOT IMPLEMENTED');
        }

        if ($this->is_file($path)) {
            return $this->execute('cat', $path, null, false, true, [
                (($offset === 0) ? '' : ('--offset ' . $offset)),
                (is_null($length) ? '' : ('--count ' . $length)),
            ]);
        }
        return false;
    }

    public function readfile(string $path, bool $unsafe = false)
    {
        if ($unsafe) {
            $this->execute('cat', $path, null, false, true, [], true);
            return;
        }
        if ($this->is_file($path)) {
            try {
                $this->execute('cat', $path, null, false, true, [], true);
            } catch (Exception $e) {
                var_dump($e);
                error_log($e->getMessage());
                return false;
            }
        }
        return false;
    }

    public function public_link(string $path, string $expire = '1d')
    {
        if (!is_string($this->env->get('CLOUDFS_HTTP_REMOTE')) || !is_string($this->env->get('CLOUDFS_HTTP_ENDPOINT'))) {
            try {
                $flags = ['--expire', $expire];
                $link = $this->execute('link', $path, null, false, true, $flags, false, true, 5.0);
                $lines = explode(PHP_EOL, $link);
                return array_pop($lines);
            } catch (Exception $e) {
                error_log($e->getMessage());
                return false;
            }
        }
        return false;
    }

    public function remove_public_link(string $path): bool
    {
        if ($this->is_file($path)) {
            try {
                $this->execute('link', $path, null, false, true, ['--unlink']);
                return true;
            } catch (Exception $e) {
                error_log($e->getMessage());
                return false;
            }
        }
        return false;
    }

    public function unlink(string $path): bool
    {
        try {
            $this->execute('delete', $path);
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        return true;
    }

    public function rmdir(string $path, bool $recursive = false): bool
    {
        if ($this->is_dir($path)) {
            try {
                $this->execute($recursive ? 'purge' : 'rmdir', $path);
            } catch (Exception $e) {
                error_log($e->getMessage());
                return false;
            }
            return true;
        }
        return false;
    }

    public function rename(string $oldname, string $newname, bool $remoteSource = true): bool
    {
        clearstatcache(true, $oldname);
        if ((!$remoteSource && filesize($oldname) == 0) || $remoteSource && $this->filesize($oldname) == 0) {
            error_log('Not moving empty file: ' . $oldname);
            return false;
        }
        try {
            $this->execute('moveto', $newname, $oldname, $remoteSource);
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        return true;
    }

    public function copy(string $oldname, string $newname, bool $remoteSource = true, bool $remoteDestination = true): bool
    {
        clearstatcache(true, $oldname);
        if ((!$remoteSource && filesize($oldname) == 0) || $remoteSource && $this->filesize($oldname) == 0) {
            error_log('Not moving empty file: ' . $oldname);
            return false;
        }
        try {
            $this->execute('copyto', $newname, $oldname, $remoteSource, $remoteDestination);
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        return true;
    }

    public function md5_file(string $path)
    {
        if ($this->is_file($path)) {
            try {
                $dir = dirname($path);
                $fpath = $this->formatPath($dir);
                $parts = explode(
                    ':',
                    $fpath
                );
                $lpart = end(
                    $parts
                );
                return explode(' ', $this->execute('md5sum', $path, null, false, true, [
                    '--sftp-path-override',
                    $this->env->get('RCLONE_SFTP_PREFIX')
                    . DIRECTORY_SEPARATOR
                    . ltrim($lpart,
                        DIRECTORY_SEPARATOR
                    )
                ]))[0];
            } catch (Exception $e) {
                try {
                    return explode(' ', $this->execute('md5sum', $path, null, false, true, ['--download']))[0];
                } catch (Exception $e) {
                    error_log($e->getMessage());
                    return false;
                }
            }
        }
        return false;
    }

    public function pull(string $path)
    {
        if ($this->is_file($path)) {
            $tmpfile = tempnam(sys_get_temp_dir(), 'PVMA');
            try {
                $this->execute('copyto', $tmpfile, $path, true, false);
            } catch (Exception $e) {
                unlink($tmpfile);
                error_log($e->getMessage());
                return false;
            }
            return $tmpfile;
        }
        return false;
    }

    public static function canonicalize($path)
    {
        $path = explode('/', $path);
        $keys = array_keys($path, '..');

        foreach ($keys as $keypos => $key) {
            array_splice($path, $key - ($keypos * 2 + 1), 2);
        }

        $path = implode('/', $path);
        $path = str_replace('./', '', $path);
        return $path;
    }

    public static function encode(string $path, bool $segmented = false): string
    {
        // Check encoding cache first
        $cacheKey = 'encode_' . $path . '_' . (int) $segmented;
        if (isset(self::$encodingCache[$cacheKey])) {
            return self::$encodingCache[$cacheKey];
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $encoded = implode(DIRECTORY_SEPARATOR, array_map(function ($part) use ($ext) {
            return implode('*', array_map(function ($p) use ($ext) {
                if (strpos($p, '.') !== 0) {
                    return base64_encode(basename($p, '.' . $ext));
                }
                return '';
            }, explode('*', $part)));
        }, explode(DIRECTORY_SEPARATOR, $path))) . (empty($ext) ? '' :  '.' . $ext);

        $result = $segmented
        ? dirname($encoded) . DIRECTORY_SEPARATOR . substr(basename($encoded), 0, 2) . DIRECTORY_SEPARATOR . $encoded
        : $encoded;

        // Cache result with size management
        if (count(self::$encodingCache) > 2000) {
            self::$encodingCache = array_slice(self::$encodingCache, -1000, 1000, true);
        }
        self::$encodingCache[$cacheKey] = $result;

        return $result;
    }

    public static function decode(string $path, bool $segmented = false): string
    {
        // Check encoding cache first
        $cacheKey = 'decode_' . $path . '_' . (int) $segmented;
        if (isset(self::$encodingCache[$cacheKey])) {
            return self::$encodingCache[$cacheKey];
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $processPath = $segmented ? (dirname($path, 2) . DIRECTORY_SEPARATOR . basename($path)) : $path;

        $result = implode(DIRECTORY_SEPARATOR, array_map(function ($part) use ($ext) {
            return implode('*', array_map(function ($p) use ($ext) {
                if (strpos($p, '.') !== 0) {
                    return base64_decode(basename($p, '.' . $ext));
                }
                return '';
            }, explode('*', $part)));
        }, explode(DIRECTORY_SEPARATOR, $processPath))) . (empty($ext) ? '' :  '.' . $ext);

        // Cache result with size management
        if (count(self::$encodingCache) > 2000) {
            self::$encodingCache = array_slice(self::$encodingCache, -1000, 1000, true);
        }
        self::$encodingCache[$cacheKey] = $result;

        return $result;
    }

    // Add method to get cache statistics for monitoring performance
    public static function getCacheStats(): array
    {
        return [
            'cache_hits' => self::$cacheHits,
            'cache_misses' => self::$cacheMisses,
            'hit_ratio' => self::$cacheMisses > 0 ? round((self::$cacheHits / (self::$cacheHits + self::$cacheMisses)) * 100, 2) : 0,
            'pathinfo_cache_size' => count(self::$pathInfoCache),
            'scandir_cache_size' => count(self::$scandirCache),
            'encoding_cache_size' => count(self::$encodingCache),
            'total_cache_entries' => count(self::$pathInfoCache) + count(self::$scandirCache) + count(self::$encodingCache)
        ];
    }

    // Add method to clear caches if needed
    public static function clearCaches(): void
    {
        self::$pathInfoCache = [];
        self::$pathInfoCacheTime = [];
        self::$scandirCache = [];
        self::$scandirCacheTime = [];
        self::$encodingCache = [];
        self::$cacheHits = 0;
        self::$cacheMisses = 0;
    }

    public function moveSync(string $source, string $destination, bool $encodeDestination = true, bool $decodeSource = false, bool $preserveBucketName = true, array $flags = []): bool
    {
        try {
            $destinationParts = explode(':', $destination);
            $sourceParts = explode(':', $source);
            $target = $destination;
            if ($encodeDestination && count($destinationParts) > 1) {
                $target = array_shift($destinationParts)
                . ':';
                if ($preserveBucketName) {
                    $parts = array_filter(explode(DIRECTORY_SEPARATOR, implode(
                        ':', $destinationParts)));
                    $bucket = array_shift($parts);
                    $target .= $bucket . DIRECTORY_SEPARATOR . $this->encode(implode(DIRECTORY_SEPARATOR, $parts));
                } else {
                    $target .= $this->encode(
                        implode(
                            ':',
                            $destinationParts
                        )
                    ) ;
                }
            } elseif ($encodeDestination) {
                $target = $this->rCloneDestination . $this->encode(str_replace($this->rCloneDestination, '', $destination));
            }

            $this->execute(
                'moveto',
                $target,
                (
                    $decodeSource
                    ? array_shift($sourceParts)
                    . ':'
                    . $this->decode(
                        implode(
                            ':',
                            $sourceParts
                        ),
                        $this->segmented
                    )
                    : $source
                ),
                false,
                false,
                $flags
            );
        } catch (Exception $e) {
            var_dump($e->getMessage());
            return false;
        }
        return true;
    }

    public function sync(string $source, string $destination, bool $encodeDestination = true, bool $decodeSource = false, bool $preserveBucketName = true, array $flags = []): bool
    {
        try {
            $destinationParts = explode(':', $destination);
            $sourceParts = explode(':', $source);
            $target = $destination;
            if ($encodeDestination && count($destinationParts) > 1) {
                $target = array_shift($destinationParts)
                . ':';
                if ($preserveBucketName) {
                    $parts = array_filter(explode(DIRECTORY_SEPARATOR, implode(
                        ':', $destinationParts)));
                    $bucket = array_shift($parts);
                    $target .= $bucket . DIRECTORY_SEPARATOR . $this->encode(implode(DIRECTORY_SEPARATOR, $parts));
                } else {
                    $target .= $this->encode(
                        implode(
                            ':',
                            $destinationParts
                        )
                    ) ;
                }
            } elseif ($encodeDestination) {
                $target = $this->rCloneDestination . $this->encode(str_replace($this->rCloneDestination, '', $destination));
            }

            $this->execute(
                'sync',
                $target,
                (
                    $decodeSource
                    ? array_shift($sourceParts)
                    . ':'
                    . $this->decode(
                        implode(
                            ':',
                            $sourceParts
                        ),
                        $this->segmented
                    )
                    : $source
                ),
                false,
                false,
                [...$flags, '--ignore-existing']
            );
        } catch (Exception $e) {
            var_dump($e->getMessage());
            return false;
        }
        return true;
    }

    public function formatPath(string $path, bool $remote = true): string
    {
        return str_replace(
            DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR,
            $remote
            ? rtrim($this->rCloneDestination, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . ltrim(
                $this->encoded
                ? (
                    $this->segmented
                    ? dirname(
                        $this->encode($path)
                    )
                    . DIRECTORY_SEPARATOR
                    . substr(
                        basename(
                            $this->encode(
                                $path
                            )
                        ),
                        0, 2
                    )
                    . DIRECTORY_SEPARATOR
                    . $this->encode($path)
                    : $this->encode($path)
                )
                : $path, DIRECTORY_SEPARATOR)
            : $path
        );
    }

    private function execute(
        string $command,
        string $destination,
        ?string $source = null,
        bool $remoteSource = false,
        bool $remoteDestination = true,
        array $flags = [],
        bool $passthru = false,
        bool $encodeSource = true,
        ?float $timeout = null
    ) {
        $source = is_null($source)
            ? null
            : (
                strpos(
                    explode(
                        DIRECTORY_SEPARATOR,
                        $source
                    )[0],
                    ':'
                ) === false
                ? DIRECTORY_SEPARATOR . ltrim($source, DIRECTORY_SEPARATOR)
                : $source
            );
        $destination = strpos(
            explode(
                DIRECTORY_SEPARATOR,
                $destination)[0],
            ':'
        ) === false
            ? DIRECTORY_SEPARATOR . ltrim($destination, DIRECTORY_SEPARATOR)
            : $destination;
        $cmd = implode(
            ' ',
            [
                'export GOGC=20;',
                'nice',
                is_null($timeout) ? '': 'timeout ' . $timeout . ' ',
                $this->rCloneBinaryPath,
                '--config',
                escapeshellarg($this->rCloneConfigPath),
                '--auto-confirm',
                '--log-level ERROR',
                                '--multi-thread-streams=1',
                '--s3-no-check-bucket',
                '--s3-no-head',
                // '--s3-no-head-object', -- Cannot be used for single file operations
                '--ignore-checksum',
                '--size-only',
                '--retries 3',
                '--checkers 1',
                '--transfers 1',
                '--fast-list',
                '--use-mmap',
                '--buffer-size 0M',
                $command,
                ...$flags,
                !is_null($source)
                ? escapeshellarg(
                    str_replace(
                        DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
                        DIRECTORY_SEPARATOR,
                        (
                            $remoteSource
                            ? $this->rCloneDestination . (
                                $this->encoded && $encodeSource
                                ? (
                                    $this->segmented
                                    ? dirname(
                                        $this->encode($source)
                                    )
                                    . DIRECTORY_SEPARATOR
                                    . substr(
                                        basename(
                                            $this->encode(
                                                $source
                                            )
                                        ),
                                        0, 2
                                    )
                                    . DIRECTORY_SEPARATOR
                                    . $this->encode($source)
                                    : $this->encode($source)
                                )
                                : $source
                            )
                            : $source
                        )
                    )
                )
                : '',
                escapeshellarg(
                    str_replace(
                        DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
                        DIRECTORY_SEPARATOR,
                        $remoteDestination
                        ? $this->rCloneDestination . (
                            $this->encoded
                            ? (
                                $this->segmented
                                ? dirname(
                                    $this->encode($destination)
                                )
                                . DIRECTORY_SEPARATOR
                                . substr(
                                    basename(
                                        $this->encode(
                                            $destination
                                        )
                                    ),
                                    0, 2
                                )
                                . DIRECTORY_SEPARATOR
                                . $this->encode($destination)
                                : $this->encode($destination)
                            )
                            : $destination
                        )
                        : $destination
                    )
                ),
                '2>&1',
            ]
        );
        // echo PHP_EOL.$cmd;
        if ($passthru) {
            passthru($cmd, $result_code);
        } else {
            exec(
                $cmd,
                $response,
                $result_code
            );
        }
        if ($result_code !== 0) {
            throw new Exception(PHP_EOL . 'RClone cmd: "' . $cmd . '" exited with an error code: ' . (!empty($response) ? PHP_EOL . implode(PHP_EOL, $response) : 'No Response'));
        }
        return implode(PHP_EOL, $response ?? []);
    }

}
