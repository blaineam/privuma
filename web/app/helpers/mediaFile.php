<?php

namespace privuma\helpers;
use DateTime;
use PDO;
use privuma\helpers\cloudFS;
use privuma\privuma;

class mediaFile {
    public const MEDIA_FOLDER = 'privuma';
    private ?int $id;
    private ?string $hash;
    private ?string $url;
    private ?string $thumbnail;
    private string $album;
    private string $filename;
    private string $extension;
    private DateTime $date;
    private bool $dupe;
    private cloudFS $cloudFS;
    private PDO $pdo;
    public const SANITIZED_PATH = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'sanitizedFiles.json';

    function __construct(string $filename, string $album, ?int $id = null, ?string $hash = null, ?DateTime $date = null, ?bool $dupe = null, ?string $url = null, ?string $thumbnail = null)
    {
        $this->id = $id;
        $this->hash = $hash;
        $this->url = $url;
        $this->thumbnail = $thumbnail;
        $this->album = $album;
        $this->filename = $filename;
        $this->extension = pathinfo($filename, PATHINFO_EXTENSION);
        $this->date = $date ?? new DateTime();
        $this->dupe = $dupe ?? strpos($filename, '---dupe') !== false ? 1 : 0;;
        $this->cloudFS = privuma::getCloudFS();
        $this->sanitizedFilesPath = privuma::getConfigDirectory() . DIRECTORY_SEPARATOR . 'sanitizedFiles.json';
        $privuma = privuma::getInstance();
        $this->pdo = $privuma->getPDO();
    }

    public static function getAlbumPath(string $path): string {
        return basename(dirname($path)) . DIRECTORY_SEPARATOR . basename($path);
    }

    public function realPath() {
        $filePath = $this->album . DIRECTORY_SEPARATOR . $this->filename;

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        $filename = basename($filePath, "." . $ext);
        $album = $this->sanitize(basename(dirname($filePath)));

        $filePath = privuma::getDataFolder() . DIRECTORY_SEPARATOR . self::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . $filename . "." . $ext;

        $compressedFile = privuma::getDataFolder() . DIRECTORY_SEPARATOR . self::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---compressed." . $ext;

        $dupe = privuma::getDataFolder() . DIRECTORY_SEPARATOR . self::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album  . DIRECTORY_SEPARATOR . $filename . "---dupe." . $ext;

        $files = $this->cloudFS->glob(privuma::getDataFolder() . DIRECTORY_SEPARATOR . self::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $album . DIRECTORY_SEPARATOR . explode('---', $filename)[0]. "*.*");
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
        return self::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $this->album . DIRECTORY_SEPARATOR . $this->filename;
    }

    public function hash() {
        $fileParts = explode('---', $this->filename);
        if (count($fileParts) > 1 && $fileParts[1] !== "compressed" && !empty($fileParts[1])) {
            return $fileParts[1];
        }
        if(!is_null($this->url)) {
            return md5($this->url);
        }

        return $this->cloudFS->md5_file($this->realPath());
    }

    public function original() {
        if(is_null($this->hash) && $hash = $this->hash()) {
            $this->hash = $hash;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE hash = ? AND dupe = 0 AND album != ? limit 1');
        $stmt->execute([$this->hash, $this->album]);
        $test = $stmt->fetch();

        if ($test === false) {
            return false;
        }

        return self::MEDIA_FOLDER . DIRECTORY_SEPARATOR .$test['album'] . DIRECTORY_SEPARATOR . $test['filename'];
    }

    public function source() {
        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE ((filename = ? AND album = ?) OR hash = ?) limit 1');
        $stmt->execute([$this->filename, $this->album, $this->hash]);
        $test = $stmt->fetch();

        if ($test === false) {
            return false;
        }

        return $test['url'] ?? false;
    }


    public function hashConflict() {
        if(is_null($this->hash) && $hash = $this->hash()) {
            $this->hash = $hash;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE hash = ? AND album = ? limit 1');
        $stmt->execute([$this->hash, $this->album]);
        $test = $stmt->fetch();

        if ($test === false) {
            return false;
        }

        return true;
    }

    public function dupe() : bool {
        return $this->original() === false ? false : true;
    }

    public function save(): bool {
        if(is_null($this->hash) && $hash = $this->hash()) {
            $this->hash = $hash;
        }

        $fileParts = explode('---', $this->filename);
        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE hash = ? AND album = ? AND filename LIKE "' . trim($fileParts[0],'-') . '%"  ORDER BY time ASC');
        $stmt->execute([$this->hash, $this->album]);
        $test = $stmt->fetch();

        if ($test === false) {
            $date = date('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare('INSERT INTO media (dupe, album, hash, filename, url, thumbnail, time)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
            return $stmt->execute([
                $this->dupe() ? 1 : 0,
                $this->album,
                $this->hash,
                $this->filename,
                $this->url,
                $this->thumbnail,
                $date
            ]) !== false;
        }
        return false;
    }

    public function getFieldValuesForAlbum($field): array {
        $stmt = $this->pdo->prepare("select `{$field}`
        from media
        where album = ?
        group by filename");
        $stmt->execute([$this->album]);
        $data = $stmt->fetchAll();
        return empty($data) ? [] : array_column($data, $field);
    }

    public function delete(?string $hash = null) {
        if(!is_null($hash)){
            $stmt = $this->pdo->prepare('DELETE FROM media WHERE hash = ?');
            $stmt->execute([$hash]);
            return;
        }

        if(!is_null($this->id)){
            $stmt = $this->pdo->prepare('DELETE FROM media WHERE id = ?');
            $stmt->execute([$this->id]);
            $this->cloudFS->unlink($this->path());
            return;
        }

        $fileParts = explode('---', $this->filename);
        $stmt = $this->pdo->prepare('DELETE FROM media WHERE album = ? AND filename LIKE "' . trim($fileParts[0],'-') . '%"');
        $stmt->execute([$this->album]);

        $this->cloudFS->unlink($this->path());
    }

    public static function titleCase(string $name): string {
        $result = "";
        $pattern = '/([;:,-.\/ X])/';
        $array = preg_split($pattern, $name, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        foreach ($array as $v) $result .= ucwords(strtolower($v));

        return $result;
    }

    public static function sanitize(string $name, ?string $storedValue = null): string {
        //remove accents
        $str = strtr(utf8_decode($name), utf8_decode('àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ'), 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');
        //replace directory symbols
        $str = preg_replace('/[\/\\\\]+/', '-', $str);
        //replace symbols;
        $str = preg_replace('/[\:]+/', '_', $str);
        //replace foreign characters
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-\s\(\)~]+/', '', $str);

        $parts = explode(DIRECTORY_SEPARATOR, $name);
        if(count($parts) > 1) {
            $sanitized =  implode(DIRECTORY_SEPARATOR, array_map('self::sanitize', $parts));
        }

        if($sanitized !== $name || !is_null($storedValue)) {
            $sanitizedFiles = json_decode(file_get_contents(self::SANITIZED_PATH), true) ?? [];
            $sanitizedFiles[$sanitized] = $storedValue ?? $name;
            file_put_contents(self::SANITIZED_PATH, json_encode($sanitizedFiles, JSON_PRETTY_PRINT));
        }

        return $sanitized;

    }

    public static function desanitize(string $name) {
        $sanitizedFiles = json_decode(file_get_contents(self::SANITIZED_PATH), true) ?? [];
        if (!empty($sanitizedFiles[$name])) {
            return $sanitizedFiles[$name];
        }
        return $name;
    }
}
