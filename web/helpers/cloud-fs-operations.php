<?php

namespace cloudFS;
use Exception;

class Operations {

    private string $rCloneBinaryPath;
    private string $rCloneConfigPath;
    private string $rCloneDestination;

    function __construct(string $rCloneDestination = 'privuma:', string $rCloneBinaryPath = __DIR__.'/../bin/rclone', string $rCloneConfigPath = __DIR__ . '/../config/rclone.conf') {
        $this->rCloneBinaryPath = $rCloneBinaryPath;
        $this->rCloneConfigPath = $rCloneConfigPath;
        $this->rCloneDestination = $rCloneDestination;
    }

    public function scandir(string $directory) {
        if(!$this->is_dir($directory)) {
            return false;
        }
        try {
            $response = array_column(json_decode($this->execute('lsjson', $directory), true), 'Name');
            return ['.','..', ...$response];
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    public function file_exists(string $file) : bool {
        try {
            $list = json_decode($this->execute('lsjson', dirname($file)), true);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        $key = array_search(basename($file), array_column($list, 'Name'));
        return $key !== false && !$list[$key]['IsDir'];
    }

    public function is_file(string $file) : bool {
        try {
            $list = json_decode($this->execute('lsjson', dirname($file)), true);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        $key = array_search(basename($file), array_column($list, 'Name'));
        return $key !== false && !$list[$key]['IsDir'];
    }

    public function filemtime(string $file) {
        try {
            $list = json_decode($this->execute('lsjson', dirname($file)), true);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        $key = array_search(basename($file), array_column($list, 'Name'));
        
        if ($key !== false && !$list[$key]['IsDir']) {
            return strtotime(explode('.', $list[$key]['ModTime'])[0]);
        }
        return false;
    }

    public function filesize(string $file) {
        try {
            $list = json_decode($this->execute('lsjson', dirname($file)), true);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        $key = array_search(basename($file), array_column($list, 'Name'));
        
        if ($key !== false && !$list[$key]['IsDir']) {
            return $list[$key]['Size'];
        }
        return false;
    }

    public function is_dir(string $directory) : bool {
        try {
            $list = json_decode($this->execute('lsjson', dirname($directory)), true);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        $key = array_search(basename($directory), array_column($list, 'Name'));
        return $key !== false && $list[$key]['IsDir'];
    }

    public function mkdir(string $directory) : bool {
        if(!$this->is_dir($directory)){
            try{
                $this->execute('mkdir', $directory);
            } catch(Exception $e) {
                error_log($e->getMessage());
                return false;
            }
            return true;
        }
        return false;
    }

    public function file_put_contents(string $path, string $contents) {
        $tmpfile = tempnam(sys_get_temp_dir(), 'PVMA');
        file_put_contents($tmpfile, $contents);
        try{
            $this->execute('copyto', $path, $tmpfile);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        unlink($tmpfile);
        return mb_strlen($contents, '8bit');
    }

    public function file_get_contents(string $path) {   
        if($this->file_exists($path)){
            return $this->execute('cat', $path);
        }  
        return false;
    }

    public function readfile(string $path) {   
        if($this->file_exists($path)){
            try {
                $this->execute('cat', $path,null,false,true,[],true);
            } catch(Exception $e) {
                error_log($e->getMessage());
                return false;
            }
        }  
        return false;
    }

    public function public_link(string $path) {   
        if($this->file_exists($path)){
            return array_pop(explode(PHP_EOL, $this->execute('link', $path, null, false, true, ['--expire', '1d'])));
        }  
        return false;
    }


    public function remove_public_link(string $path): bool {   
        if($this->file_exists($path)){
            try{
                $this->execute('link', $path, null, false, true, ['--unlink']);
                return true;
            } catch(Exception $e) {
                error_log($e->getMessage());
                return false;
            }
        }  
        return false;
    }


    public function unlink(string $path): bool {   
        if($this->file_exists($path)){
            try{
                $this->execute('delete', $path);
            } catch(Exception $e) {
                error_log($e->getMessage());
                return false;
            }
            return true;
        }
        return false;
    }

    public function rmdir(string $path, bool $recursive = false): bool {   
        if($this->is_dir($path)){
            try{
                $this->execute($recursive ? 'purge' : 'rmdir', $path);
            } catch(Exception $e) {
                error_log($e->getMessage());
                return false;
            }
            return true;
        }
        return false;
    }

    public function rename(string $oldname, string $newname, bool $remoteSource = true): bool {   
        try{
            $this->execute('moveto', $newname, $oldname, $remoteSource);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        return true;
    }

    public function copy(string $oldname, string $newname, bool $remoteSource = true, bool $remoteDestination = true): bool {
        try{   
            $this->execute('copyto', $newname, $oldname, $remoteSource, $remoteDestination);
        } catch(Exception $e) {
            error_log($e->getMessage());
            return false;
        }
        return true;
    }


    public function md5_file(string $path) {
        if ($this->file_exists($path)) {
            try {
                return explode(' ', $this->execute('md5sum', $path, null, false, true, ['--download']))[0];
            } catch (Exception $e) {
                error_log($e->getMessage());
                return false;
            }
        }
        return true;
    }

    public function pull(string $path) {   
        if($this->file_exists($path)){
            $tmpfile = tempnam(sys_get_temp_dir(), 'PVMA');
            try{
                $this->execute('copyto', $tmpfile, $path, true, false);
            } catch(Exception $e) {
                error_log($e->getMessage());
                return false;
            }
            return $tmpfile;
        }  
        return false;
    }

    private function execute(string $command, string $destination, ?string $source = null, bool $remoteSource = false, bool $remoteDestination = true, array $flags = [], bool $passthru = false) {
        $cmd = implode(
            ' ', 
            [
                $this->rCloneBinaryPath,
                '--config',
                escapeshellarg($this->rCloneConfigPath),
                '--auto-confirm',
                '--log-level ERROR',
                $command,
                ...$flags,
                !is_null($source) ? escapeshellarg(($remoteSource ? $this->rCloneDestination . $source : $source)) : '',
                $remoteDestination ? escapeshellarg($this->rCloneDestination . $destination) : $destination,
                '2>&1'
            ]
            );
        if($passthru) {
            passthru($cmd, $result_code);
        } else {
            exec(
                $cmd,
                $response,
                $result_code
            );
        }
        if($result_code !== 0){
            throw new Exception('RClone exited with an error code: '. PHP_EOL . implode(PHP_EOL, $response));
        }
        return implode(PHP_EOL, $response);
    }

}



?>