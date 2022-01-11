<?php

namespace privuma\helpers;
use DateTime;
use privuma\helpers\cloudFS;
use privuma\privuma;

class mediaFile {
    const MEDIA_FOLDER = 'privuma';
    private ?int $id;
    private ?string $hash;
    private string $album;
    private string $filename;
    private string $extension;
    private DateTime $date;
    private bool $dupe;
    private cloudFS $cloudFS;
    private string $sanitizedFilesPath;

    function __construct(string $filename, string $album, ?int $id = null, ?string $hash = null, ?DateTime $date = null, ?bool $dupe = null)
    {
        $this->id = $id;
        $this->hash = $hash ?? $this->hash();
        $this->album = $album;
        $this->filename = $filename;
        $this->extension = pathinfo($filename, PATHINFO_EXTENSION);
        $this->date = $date ?? new DateTime();
        $this->dupe = $dupe ?? strpos($filename, '---dupe') !== false ? 1 : 0;;
        $this->cloudFS = privuma::getCloudFS();
        $this->sanitizedFilesPath = privuma::getConfigDirectory() . DIRECTORY_SEPARATOR . 'sanitizedFiles.json';
    }

    public function realPath() {
        $filePath = $this->album . DIRECTORY_SEPARATOR . $this->filename;

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        $filename = basename($filePath, "." . $ext);
        $album = $this->sanitize(basename(dirname($filePath)));

        $filePath = self::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename . "." . $ext;
        $compressedFile = self::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---compressed." . $ext;

        $dupe = self::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---dupe." . $ext;
                    
        $files = $this->cloudFS->glob(self::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . explode('---', $filename)[0]. "*.*");
        if($files === false) {
            $files = [];
        }
        if ($this->cloudFS->is_file($filePath)) {
            return $filePath;
        } else if ($this->cloudFS->is_file($compressedFile)) {
            return $compressedFile;
        } else if ($this->cloudFS->is_file($dupe)) {
            return $dupe;
        } else if (count($files) > 0) {
            if (strtolower($ext) == "mp4" || strtolower($ext) == "webm") {
                foreach ($files as $file) {
                    $iext = pathinfo($file, PATHINFO_EXTENSION);
                    if (strtolower($ext) == strtolower($iext)) {
                        return $file;
                    }
                }
            } else {
                foreach ($files as $file) {
                    $iext = pathinfo($file, PATHINFO_EXTENSION);
                    if (strtolower($iext) !== "mp4" && strtolower($iext) !== "webm") {
                        return $file;
                    }
                }
            }
        }

        return false;
    }

    public function path() {
        return $this->album . DIRECTORY_SEPARATOR . $this->filename;
    }

    public function hash() {
        $fileParts = explode('---', $this->filename);
        if (count($fileParts) > 1 && $fileParts[1] !== "compressed" && !empty($fileParts[1])) {
            return $fileParts[1];
        }

        return $this->cloudFS->md5_file($this->realPath());
    }

    public function save() {
        $fileParts = explode('---', $this->filename);
        $stmt = privuma::getPDO()->prepare('SELECT * FROM media WHERE hash = ? AND album = ? AND filename LIKE "' . trim($fileParts[0],'-') . '%"  ORDER BY time ASC');
        $stmt->execute([$this->hash, $this->album]);
        $test = $stmt->fetch();

        if ($test === false) {
            $date = date('Y-m-d H:i:s');
            $stmt = privuma::getPDO()->prepare('INSERT INTO media (dupe, album, hash, filename, time)
            VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$$this->dupe, $this->album, $this->hash, $this->filename, $date]);
        }
    }

    public function delete(?string $hash = null) {
        if(!is_null($hash)){
            $stmt = privuma::getPDO()->prepare('DELETE FROM media WHERE hash = ?');
            $stmt->execute([$hash]);
            return;
        }

        if(!is_null($this->id)){
            $stmt = privuma::getPDO()->prepare('DELETE FROM media WHERE id = ?');
            $stmt->execute([$this->id]);
            $this->cloudFS->unlink($this->path());
            return;
        }

        $fileParts = explode('---', $this->filename);
        $stmt = privuma::getPDO()->prepare('DELETE FROM media WHERE album = ? AND filename LIKE "' . trim($fileParts[0],'-') . '%"');
        $stmt->execute([$this->album]);

        $this->cloudFS->unlink($this->path());
    }

    public function titleCase(string $name): string {
        $result = "";
        $pattern = '/([;:,-.\/ X])/';
        $array = preg_split($pattern, $name, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    
        foreach ($array as $v) $result .= ucwords(strtolower($v));
    
        return $result;
    }

    public static function sanitize(string $name): string {
        //remove accents
        $str = strtr(utf8_decode($name), utf8_decode('àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ'), 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');
        //replace directory symbols
        $str = preg_replace('/[\/\\\\]+/', '-', $str);
        //replace symbols;
        $str = preg_replace('/[\:]+/', '_', $str);
        //replace foreign characters
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-\s\(\)~]+/', '', $str);

        if($sanitized !== $name) {
            $sanitizedFiles = json_decode(file_get_contents(self::$sanitizedFilesPath), true) ?? [];
            $sanitizedFiles[$sanitized] = $name;
            file_put_contents(self::$sanitizedFilesPath, json_encode($sanitizedFiles, JSON_PRETTY_PRINT));
        }

        return $sanitized;

    }

    public static function desanitize(string $name) {
        $sanitizedFiles = json_decode(file_get_contents(self::$sanitizedFilesPath), true) ?? [];
        if (isset($satiizedFiles[$name])) {
            return $sanitizedFiles[$name];
        }
        return $name;
    }
}