<?php
namespace privuma\helpers;

use Exception;
use privuma\privuma;

class cloudFS
{

    private string $rCloneBinaryPath;
    private string $rCloneConfigPath;
    private string $rCloneDestination;
    private bool $encoded;
    private bool $segmented;
    private dotenv $env;

    public function __construct(
        string $rCloneDestination = 'privuma:',
        bool $encoded = true,
        string $rCloneBinaryPath = '/usr/bin/rclone',
        ?string $rCloneConfigPath = null,
        bool $segmented = false
    ) {
        $this->env = new dotenv();
        exec('cpulimit -f -l ' . privuma::getEnv('MAX_CPU_PERCENTAGE') . ' -- ' . $rCloneBinaryPath . ' version 2>&1 > /dev/null', $void, $code);
        if($code !== 0) {
            $rCloneBinaryPath = '/usr/local/bin/rclone';
            exec('cpulimit -f -l ' . privuma::getEnv('MAX_CPU_PERCENTAGE') . ' -- ' . $rCloneBinaryPath . ' version 2>&1 > /dev/null', $void, $code);
            if($code !== 0) {
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
        if(!$this->is_dir($directory) && $directory !== DIRECTORY_SEPARATOR) {
            error_log('not a dir');
            return false;
        }
        try {
            $filter = null;
            if(is_array($filters)) {
                $filter = '';
                foreach($filters as $internal_filter) {
                    $filterType = substr($internal_filter, 0, 1) === '-' ? "--filter '- ": "--filter '+ ";
                    $filter .= ' ' . $filterType . ($this->encoded ? $this->encode(ltrim($internal_filter, '+- ')) : ltrim($internal_filter, '+- ')) . "'";
                }
            }
            $files = json_decode($this->execute('lsjson', $directory, null, false, true, [ ($noMimeType ? '--no-mimetype' : ''), ($noModTime ? '--no-modtime' : ''), ($dirsOnly ? '--dirs-only' : ''), ($filesOnly ? '--files-only' : ''), ($recursive !== false) ? '--recursive': '', (!is_null($filter)) ? $filter : '']), true);
            usort($files, function ($a, $b) {
                return strtotime(explode('.', $b['ModTime'])[0]) <=> strtotime(explode('.', $a['ModTime'])[0]);
            });
            $response = array_map(function ($object) {
                $object['Name'] = ($this->encoded ? $this->decode($object['Name'], $this->segmented) : $object['Name']);
                $object['Path'] = ($this->encoded ? $this->decode($object['Path'], $this->segmented) : $object['Path']);
                return $object;
            }, $files);

            $response = $objects ? $response : ['.', '..', ...array_column($response, 'Name')];
            return  $response;
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    public function glob($pattern): array
    {
        $recursiveParts = explode('**', $pattern);
        $wildcards = explode('*', str_replace('**', '', $pattern));
        if(count($recursiveParts) > 1) {
            $wildcardParent = substr($recursiveParts[0], -1) === DIRECTORY_SEPARATOR ? $recursiveParts[0] : dirname($recursiveParts[0]) . DIRECTORY_SEPARATOR;
            $scan = $this->scandir($wildcardParent, true, true);
            if($scan === false) {
                return [];
            }
            $paths = array_column($scan, 'Path');
        } else {
            $wildcardParent = substr($wildcards[0], -1) === DIRECTORY_SEPARATOR ? $wildcards[0] : dirname($wildcards[0]) . DIRECTORY_SEPARATOR;
            $scan = $this->scandir($wildcardParent, true);
            if($scan === false) {
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
        try {
            $list = json_decode($this->execute('lsjson', $path, null, false, true, [
                '--stat',
                $modTime ? '' : '--no-modtime',
                $mimetype ? '' : '--no-mimetype',
                $onlyDirs ? '--dirs-only' : '',
                $onlyFiles ? '--files-only' : '',
                $showMD5 ? '--hash --hash-type md5' : '',
            ]), true);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        return is_null($list) ? false : $list;
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
        if ($info !== false) {
            return strtotime(explode('.', $info['ModTime'])[0]);
        }
        return false;
    }

    public function touch(string $file, ?int $time = null, ?int $atime = null): bool
    {
        if(is_null($time)) {
            $time = time();
        }
        if(is_null($atime)) {
            $atime = $time;
        }
        try {
            $this->execute('touch', $file, null, false, true, ['--timestamp', date("Y-m-d\TH:i:s", $time) ]);
        } catch(Exception $e) {
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
        try {
            $data = json_decode($this->execute('size', $file, null, false, true, [
                '--json'
            ], false, true, 5.0), true);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        return is_null($data) ? false : $data['bytes'];
    }

    public function is_dir(string $directory): bool
    {
        $info = $this->getPathInfo($directory, false, false, true, false, false);
        return $info !== false;
    }

    public function mkdir(string $directory): bool
    {
        if(!$this->is_dir($directory)) {
            try {
                $this->execute('mkdir', $directory);
            } catch(Exception $e) {
                error_log($e->getMessage());
                return false;
            }
            return true;
        }
        return false;
    }

    public function file_put_contents(string $path, string $contents)
    {
        $tmpfile = tempnam(sys_get_temp_dir(), 'PVMA');
        file_put_contents($tmpfile, $contents);
        try {
            $this->execute('copyto', $path, $tmpfile, false, true, [], false, false);
        } catch(Exception $e) {
            error_log($e->getMessage());
            unlink($tmpfile);
            return false;
        }
        unlink($tmpfile);
        return mb_strlen($contents, '8bit');
    }

    public function file_get_contents(string $path)
    {
        if($this->is_file($path)) {
            return $this->execute('cat', $path);
        }
        return false;
    }

    public function readfile(string $path)
    {
        if($this->is_file($path)) {
            try {
                $this->execute('cat', $path, null, false, true, [], true);
            } catch(Exception $e) {
                error_log($e->getMessage());
                return false;
            }
        }
        return false;
    }

    public function public_link(string $path, string $expire = '1d')
    {
        if(!is_string($this->env->get('CLOUDFS_HTTP_REMOTE')) || !is_string($this->env->get('CLOUDFS_HTTP_ENDPOINT'))) {
            try {
                $flags = ['--expire', $expire];
                $link = $this->execute('link', $path, null, false, true, $flags, false, true, 5.0);
                $lines = explode(PHP_EOL, $link);
                return array_pop($lines);
            } catch(Exception $e) {
                error_log($e->getMessage());
                return false;
            }
        }
        return false;
    }

    public function remove_public_link(string $path): bool
    {
        if($this->is_file($path)) {
            try {
                $this->execute('link', $path, null, false, true, ['--unlink']);
                return true;
            } catch(Exception $e) {
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
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        return true;
    }

    public function rmdir(string $path, bool $recursive = false): bool
    {
        if($this->is_dir($path)) {
            try {
                $this->execute($recursive ? 'purge' : 'rmdir', $path);
            } catch(Exception $e) {
                error_log($e->getMessage());
                return false;
            }
            return true;
        }
        return false;
    }

    public function rename(string $oldname, string $newname, bool $remoteSource = true): bool
    {
        try {
            $this->execute('moveto', $newname, $oldname, $remoteSource);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        return true;
    }

    public function copy(string $oldname, string $newname, bool $remoteSource = true, bool $remoteDestination = true): bool
    {
        try {
            $this->execute('copyto', $newname, $oldname, $remoteSource, $remoteDestination);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        return true;
    }

    public function md5_file(string $path)
    {
        if ($this->is_file($path)) {
            try {
                return explode(' ', $this->execute('md5sum', $path, null, false, true, [
                    '--sftp-path-override',
                    $this->env->get('RCLONE_SFTP_PREFIX')
                    . DIRECTORY_SEPARATOR
                    . ltrim(
                        end(
                            explode(
                                ':',
                                $this->formatPath(dirname($path))
                            )
                        ),
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
        if($this->is_file($path)) {
            $tmpfile = tempnam(sys_get_temp_dir(), 'PVMA');
            try {
                $this->execute('copyto', $tmpfile, $path, true, false);
            } catch(Exception $e) {
                unlink($tmpfile);
                error_log($e->getMessage());
                return false;
            }
            return $tmpfile;
        }
        return false;
    }

    public static function encode(string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return implode(DIRECTORY_SEPARATOR, array_map(function ($part) use ($ext) {
            return implode('*', array_map(function ($p) use ($ext) {
                if(strpos($p, '.') !== 0) {
                    return base64_encode(basename($p, '.' . $ext));
                }
                return '';
            }, explode('*', $part)));
        }, explode(DIRECTORY_SEPARATOR, $path))) . (empty($ext) ? '' :  '.' . $ext);
    }

    public static function decode(string $path, bool $segmented = false): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $path = $segmented ? (dirname($path, 2) . DIRECTORY_SEPARATOR . basename($path)) : $path;
        return implode(DIRECTORY_SEPARATOR, array_map(function ($part) use ($ext) {
            return implode('*', array_map(function ($p) use ($ext) {
                if(strpos($p, '.') !== 0) {
                    return base64_decode(basename($p, '.' . $ext));
                }
                return '';
            }, explode('*', $part)));
        }, explode(DIRECTORY_SEPARATOR, $path))) . (empty($ext) ? '' :  '.' . $ext);
    }

    public function moveSync(string $source, string $destination, bool $encodeDestination = true, bool $decodeSource = false, bool $preserveBucketName = true, array $flags = []): bool
    {
        try {
            $destinationParts = explode(':', $destination);
            $sourceParts = explode(':', $source);
            $target = $destination;
            if($encodeDestination && count($destinationParts) > 1) {
                $target = array_shift($destinationParts)
                . ':';
                if($preserveBucketName) {
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
            } elseif($encodeDestination) {
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
        } catch(Exception $e) {
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
            if($encodeDestination && count($destinationParts) > 1) {
                $target = array_shift($destinationParts)
                . ':';
                if($preserveBucketName) {
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
            } elseif($encodeDestination) {
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
        } catch(Exception $e) {
            var_dump($e->getMessage());
            return false;
        }
        return true;
    }

    private function formatPath(string $path, bool $remote = true): string
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
                '--s3-no-check-bucket',
                '--s3-no-head',
                '--ignore-checksum',
                '--size-only',
                '--retries 3',
                '--checkers 1',
                '--transfers 1',
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
        /* echo PHP_EOL.$cmd; */
        if($passthru) {
            passthru($cmd, $result_code);
        } else {
            exec(
                $cmd,
                $response,
                $result_code
            );
        }
        if($result_code !== 0) {
            throw new Exception(PHP_EOL . 'RClone cmd: "' . $cmd . '" exited with an error code: ' . (!empty($response) ? PHP_EOL . implode(PHP_EOL, $response) : 'No Response'));
        }
        return implode(PHP_EOL, $response ?? []);
    }

}
