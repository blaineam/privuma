<?php
namespace privuma\webdav;

use PDO;
use privuma\privuma;
use privuma\helpers\cloudFS;

class WebDavFilesystem
{
    private $pdo;
    private string $cacheDir;
    private int $cacheTTL = 300; // 5 minutes

    private static array $videoExts = ['mp4', 'webm', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'swf'];
    private string $sizeCacheFile;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->cacheDir = __DIR__ . '/../output/cache/webdav';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        $this->sizeCacheFile = $this->cacheDir . '/sizes.json';
    }

    public function listRoot(): array
    {
        $root = [
            ['name' => 'Albums', 'type' => 'dir', 'mtime' => time()],
            ['name' => 'Favorites', 'type' => 'dir', 'mtime' => time()],
            ['name' => 'Unfiltered', 'type' => 'dir', 'mtime' => time()],
        ];

        $flashFile = __DIR__ . '/../output/cache/flash.json';
        if (file_exists($flashFile)) {
            $root[] = ['name' => 'Flash', 'type' => 'dir', 'mtime' => filemtime($flashFile)];
        }

        $vrFile = __DIR__ . '/../output/cache/deovr-fs.json';
        if (file_exists($vrFile)) {
            $root[] = ['name' => 'VR', 'type' => 'dir', 'mtime' => filemtime($vrFile)];
        }

        return $root;
    }

    // ---- Flash content (from flash.json cache) ----

    private function getFlashData(): ?array
    {
        $file = __DIR__ . '/../output/cache/flash.json';
        if (!file_exists($file)) {
            return null;
        }
        $cacheKey = 'flash_data';
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }
        $this->setCache($cacheKey, $data);
        return $data;
    }

    public function listFlashCategories(): array
    {
        $data = $this->getFlashData();
        if ($data === null) {
            return [];
        }
        $result = [];
        foreach ($data as $category => $items) {
            $result[] = [
                'name' => self::sanitizeName($category),
                'rawName' => $category,
                'type' => 'dir',
                'mtime' => time(),
                'count' => count($items),
            ];
        }
        return $result;
    }

    public function listFlashContents(string $category): array
    {
        $cacheKey = 'flash_contents_' . md5($category);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            if ($this->hasMissingSizes($cached)) {
                $this->fetchFlashFileSizes($cached);
                $this->setCache($cacheKey, $cached);
            }
            return $cached;
        }

        $data = $this->getFlashData();
        if ($data === null || !isset($data[$category])) {
            return [];
        }
        $result = [];
        $hasSwf = false;
        $seenNames = [];
        foreach ($data[$category] as $item) {
            $hash = md5($item['url'] ?? '');
            $title = self::buildFlashDisplayName($item, $hash, $seenNames);
            $baseName = pathinfo($title, PATHINFO_FILENAME);
            $result[] = [
                'name' => $title,
                'type' => 'file',
                'hash' => $hash,
                'ext' => 'swf',
                'mtime' => time(),
                'contentType' => 'application/x-shockwave-flash',
                'flashUrl' => $item['url'] ?? '',
            ];
            $hasSwf = true;

            // Add .html sidecar for SWF files (Ruffle player page)
            if (self::isEnvEnabled('WEBDAV_FLASH_HTML_SIDECAR')) {
                $htmlContent = self::generateRufflePlayerHtml($title);
                $result[] = [
                    'name' => $baseName . '.html',
                    'type' => 'file',
                    'hash' => md5($hash . '.html'),
                    'ext' => 'html',
                    'mtime' => time(),
                    'size' => strlen($htmlContent),
                    'contentType' => 'text/html',
                    'flashHtmlSidecar' => true,
                    'flashSwfName' => $title,
                ];
            }
        }

        // Fetch file sizes via parallel HEAD requests
        $this->fetchFlashFileSizes($result);

        // Add virtual assets directory for Ruffle if there are SWF files and sidecar is enabled
        if ($hasSwf && self::isEnvEnabled('WEBDAV_FLASH_HTML_SIDECAR')) {
            $result[] = [
                'name' => 'assets',
                'type' => 'dir',
                'mtime' => time(),
                'flashAssetsDir' => true,
            ];
        }

        $this->setCache($cacheKey, $result);
        return $result;
    }

    /**
     * Fetch flash file sizes via parallel HEAD requests to external URLs.
     */
    private function fetchFlashFileSizes(array &$items): void
    {
        $sizeCache = [];
        if (file_exists($this->sizeCacheFile)) {
            $raw = file_get_contents($this->sizeCacheFile);
            if ($raw !== false) {
                $sizeCache = json_decode($raw, true) ?: [];
            }
        }

        $needSize = [];
        foreach ($items as $idx => &$item) {
            if (empty($item['hash'])) {
                continue;
            }
            $cacheKey = 'flash_' . $item['hash'];
            if (isset($sizeCache[$cacheKey]) && $sizeCache[$cacheKey] > 0) {
                $item['size'] = $sizeCache[$cacheKey];
            } elseif (!empty($item['flashUrl'])) {
                $needSize[] = ['idx' => $idx, 'url' => $item['flashUrl'], 'cacheKey' => $cacheKey];
            }
        }
        unset($item);

        if (empty($needSize)) {
            return;
        }

        $mh = curl_multi_init();
        $batchSize = 30;
        $dirty = false;

        for ($batch = 0; $batch < count($needSize); $batch += $batchSize) {
            $slice = array_slice($needSize, $batch, $batchSize);
            $handles = [];

            foreach ($slice as $info) {
                $ch = curl_init($info['url']);
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => true,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[] = ['ch' => $ch, 'info' => $info];
            }

            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $h) {
                $ch = $h['ch'];
                $info = $h['info'];
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $contentLength = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

                if ($httpCode === 200 && $contentLength > 0) {
                    $items[$info['idx']]['size'] = $contentLength;
                    $sizeCache[$info['cacheKey']] = $contentLength;
                    $dirty = true;
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
        }

        curl_multi_close($mh);

        if ($dirty) {
            file_put_contents($this->sizeCacheFile, json_encode($sizeCache), LOCK_EX);
        }
    }

    public function resolveFlashFile(string $category, string $filename): ?array
    {
        $contents = $this->listFlashContents($category);
        foreach ($contents as $item) {
            if ($item['name'] === $filename) {
                return $item;
            }
        }
        return null;
    }

    // ---- VR content (from deovr-fs.json cache) ----

    private function getVrData(): ?array
    {
        $file = __DIR__ . '/../output/cache/deovr-fs.json';
        if (!file_exists($file)) {
            return null;
        }
        $cacheKey = 'vr_data';
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }
        $this->setCache($cacheKey, $data);
        return $data;
    }

    /**
     * List VR folder contents at a given path within the VR tree.
     * $folderPath is an array of path segments, e.g. ['Studio-A'].
     */
    public function listVrFolder(array $folderPath): array
    {
        $data = $this->getVrData();
        if ($data === null) {
            return ['dirs' => [], 'files' => []];
        }

        $prefix = empty($folderPath) ? '' : implode('/', $folderPath) . '/';
        $prefixLen = strlen($prefix);

        $subdirs = [];
        $files = [];

        foreach ($data as $item) {
            $itemPath = $item['Path'] ?? '';

            if ($prefix !== '' && strpos($itemPath, $prefix) !== 0) {
                continue;
            }

            $relative = substr($itemPath, $prefixLen);
            $parts = explode('/', $relative);

            if (count($parts) > 1) {
                // Subdirectory
                $dirName = self::sanitizeName($parts[0]);
                if (!isset($subdirs[$dirName])) {
                    $mtime = isset($item['ModTime']) ? strtotime($item['ModTime']) : time();
                    $subdirs[$dirName] = $mtime;
                } else {
                    $mtime = isset($item['ModTime']) ? strtotime($item['ModTime']) : time();
                    $subdirs[$dirName] = max($subdirs[$dirName], $mtime);
                }
            } elseif (count($parts) === 1 && $parts[0] !== '') {
                // File at this level
                $name = self::sanitizeName(pathinfo($parts[0], PATHINFO_FILENAME)) . '.' . strtolower(pathinfo($parts[0], PATHINFO_EXTENSION));
                $mtime = isset($item['ModTime']) ? strtotime($item['ModTime']) : time();
                $ext = strtolower(pathinfo($parts[0], PATHINFO_EXTENSION));
                $vrHash = self::computeVrHash($itemPath);
                $files[] = [
                    'name' => $name,
                    'type' => 'file',
                    'hash' => $vrHash,
                    'ext' => $ext,
                    'mtime' => $mtime,
                    'size' => !empty($item['Size']) ? (int) $item['Size'] : null,
                    'contentType' => $item['MimeType'] ?? self::getContentType($ext),
                    'vrPath' => $itemPath,
                ];

                // Add .json sidecar with metadata
                if (self::isEnvEnabled('WEBDAV_JSON_SIDECAR', true)) {
                    $vrBaseName = pathinfo($name, PATHINFO_FILENAME);
                    // Note: deovr-fs.json uses capitalized keys (Duration, Sound, Size, etc.)
                    $sidecarData = [
                        'hash' => $vrHash,
                        'path' => $itemPath,
                        'size' => !empty($item['Size']) ? (int) $item['Size'] : null,
                        'mimeType' => $item['MimeType'] ?? null,
                    ];
                    // Duration and Sound are only present on video entries in deovr-fs.json
                    if (isset($item['Duration'])) {
                        $sidecarData['duration'] = $item['Duration'];
                    }
                    if (isset($item['Sound'])) {
                        $sidecarData['sound'] = $item['Sound'];
                    }
                    if (isset($item['ID'])) {
                        $sidecarData['id'] = $item['ID'];
                    }
                    $sidecarJson = json_encode($sidecarData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $files[] = [
                        'name' => $vrBaseName . '.json',
                        'type' => 'file',
                        'hash' => md5($vrHash . '.json'),
                        'ext' => 'json',
                        'mtime' => $mtime,
                        'size' => strlen($sidecarJson),
                        'contentType' => 'application/json',
                        'vrJsonSidecar' => true,
                        'sidecar' => $sidecarData,
                    ];
                }

                // Add .html sidecar for video files (VR player page)
                if (in_array($ext, self::$videoExts) && self::isEnvEnabled('WEBDAV_VR_HTML_SIDECAR')) {
                    $vrBaseName = $vrBaseName ?? pathinfo($name, PATHINFO_FILENAME);
                    $projection = self::detectVrProjection($name);
                    $htmlContent = self::generateVrPlayerHtml($name, $projection);
                    $files[] = [
                        'name' => $vrBaseName . '.html',
                        'type' => 'file',
                        'hash' => md5($vrHash . '.html'),
                        'ext' => 'html',
                        'mtime' => $mtime,
                        'size' => strlen($htmlContent),
                        'contentType' => 'text/html',
                        'vrSidecar' => true,
                        'vrVideoName' => $name,
                        'vrProjection' => $projection,
                    ];
                }
            }
        }

        $dirs = [];
        foreach ($subdirs as $name => $mtime) {
            $dirs[] = ['name' => $name, 'type' => 'dir', 'mtime' => $mtime];
        }
        usort($dirs, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return ['dirs' => $dirs, 'files' => $files];
    }

    public function resolveVrFolder(array $folderPath): ?string
    {
        $result = $this->listVrFolder($folderPath);
        if (!empty($result['dirs']) || !empty($result['files'])) {
            return 'folder';
        }
        return null;
    }

    public function resolveVrFile(array $folderPath, string $filename): ?array
    {
        $result = $this->listVrFolder($folderPath);
        foreach ($result['files'] as $file) {
            if ($file['name'] === $filename) {
                return $file;
            }
        }
        return null;
    }

    // ---- VR/Flash Favorites ----

    private function getVrFavoriteHashes(): array
    {
        $file = __DIR__ . '/../output/cache/favorites_vr.json';
        if (!file_exists($file)) {
            return [];
        }
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function getFlashFavoriteHashes(): array
    {
        $file = __DIR__ . '/../output/cache/favorites_flash.json';
        if (!file_exists($file)) {
            return [];
        }
        return json_decode(file_get_contents($file), true) ?: [];
    }

    /**
     * List favorited VR items, organized by source folder.
     * Returns ['dirs' => [...], 'files' => [...]] for a given subpath within Favorites/VR/.
     */
    public function listFavoriteVr(array $folderPath): array
    {
        $cacheKey = 'fav_vr_' . md5(implode('/', $folderPath));
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $favHashes = array_flip($this->getVrFavoriteHashes());
        if (empty($favHashes)) {
            return ['dirs' => [], 'files' => []];
        }

        $data = $this->getVrData();
        if ($data === null) {
            return ['dirs' => [], 'files' => []];
        }

        // Build list of all VR items that are favorited
        $prefix = empty($folderPath) ? '' : implode('/', $folderPath) . '/';
        $prefixLen = strlen($prefix);
        $subdirs = [];
        $files = [];

        foreach ($data as $item) {
            $itemPath = $item['Path'] ?? '';
            $vrHash = self::computeVrHash($itemPath);

            if (!isset($favHashes[$vrHash])) {
                continue;
            }

            // Apply folder path filter
            $dirName = dirname($itemPath);
            $dirName = ($dirName === '.') ? '' : $dirName;

            if ($prefix !== '') {
                if (strpos($dirName . '/', $prefix) !== 0 && strpos($itemPath, $prefix) !== 0) {
                    continue;
                }
            }

            $relative = ($prefix !== '') ? substr($itemPath, $prefixLen) : $itemPath;
            $parts = explode('/', $relative);

            if (count($parts) > 1) {
                $subDir = self::sanitizeName($parts[0]);
                $mtime = isset($item['ModTime']) ? strtotime($item['ModTime']) : time();
                if (!isset($subdirs[$subDir])) {
                    $subdirs[$subDir] = $mtime;
                } else {
                    $subdirs[$subDir] = max($subdirs[$subDir], $mtime);
                }
            } elseif (count($parts) === 1 && $parts[0] !== '') {
                $name = self::sanitizeName(pathinfo($parts[0], PATHINFO_FILENAME)) . '.' . strtolower(pathinfo($parts[0], PATHINFO_EXTENSION));
                $ext = strtolower(pathinfo($parts[0], PATHINFO_EXTENSION));
                $mtime = isset($item['ModTime']) ? strtotime($item['ModTime']) : time();
                $files[] = [
                    'name' => $name,
                    'type' => 'file',
                    'hash' => $vrHash,
                    'ext' => $ext,
                    'mtime' => $mtime,
                    'size' => !empty($item['Size']) ? (int) $item['Size'] : null,
                    'contentType' => $item['MimeType'] ?? self::getContentType($ext),
                    'vrPath' => $itemPath,
                    'isFavVr' => true,
                ];

                // JSON sidecar
                if (self::isEnvEnabled('WEBDAV_JSON_SIDECAR', true)) {
                    $vrBaseName = pathinfo($name, PATHINFO_FILENAME);
                    $sidecarData = ['hash' => $vrHash, 'path' => $itemPath, 'size' => $item['Size'] ?? null];
                    if (isset($item['Duration'])) {
                        $sidecarData['duration'] = $item['Duration'];
                    }
                    if (isset($item['Sound'])) {
                        $sidecarData['sound'] = $item['Sound'];
                    }
                    $sidecarJson = json_encode($sidecarData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $files[] = [
                        'name' => $vrBaseName . '.json',
                        'type' => 'file',
                        'hash' => md5($vrHash . '.json'),
                        'ext' => 'json',
                        'mtime' => $mtime,
                        'size' => strlen($sidecarJson),
                        'contentType' => 'application/json',
                        'vrJsonSidecar' => true,
                        'sidecar' => $sidecarData,
                        'isFavVr' => true,
                    ];
                }

                // HTML sidecar for videos
                if (in_array($ext, self::$videoExts) && self::isEnvEnabled('WEBDAV_VR_HTML_SIDECAR')) {
                    $vrBaseName = $vrBaseName ?? pathinfo($name, PATHINFO_FILENAME);
                    $projection = self::detectVrProjection($name);
                    $htmlContent = self::generateVrPlayerHtml($name, $projection);
                    $files[] = [
                        'name' => $vrBaseName . '.html',
                        'type' => 'file',
                        'hash' => md5($vrHash . '.html'),
                        'ext' => 'html',
                        'mtime' => $mtime,
                        'size' => strlen($htmlContent),
                        'contentType' => 'text/html',
                        'vrSidecar' => true,
                        'vrVideoName' => $name,
                        'vrProjection' => $projection,
                        'isFavVr' => true,
                    ];
                }
            }
        }

        $dirs = [];
        foreach ($subdirs as $name => $mtime) {
            $dirs[] = ['name' => $name, 'type' => 'dir', 'mtime' => $mtime];
        }
        usort($dirs, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        $result = ['dirs' => $dirs, 'files' => $files];
        $this->setCache($cacheKey, $result);
        return $result;
    }

    /**
     * List favorited Flash items, organized by source category.
     */
    public function listFavoriteFlash(array $folderPath): array
    {
        $cacheKey = 'fav_flash_' . md5(implode('/', $folderPath));
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            if (isset($cached['files']) && $this->hasMissingSizes($cached['files'])) {
                $this->fetchFlashFileSizes($cached['files']);
                $this->setCache($cacheKey, $cached);
            }
            return $cached;
        }

        $favHashes = array_flip($this->getFlashFavoriteHashes());
        if (empty($favHashes)) {
            return ['dirs' => [], 'files' => []];
        }

        $data = $this->getFlashData();
        if ($data === null) {
            return ['dirs' => [], 'files' => []];
        }

        $targetCategory = !empty($folderPath) ? $folderPath[0] : null;
        $subdirs = [];
        $files = [];
        $seenNames = [];

        foreach ($data as $category => $items) {
            $catName = self::sanitizeName($category);

            foreach ($items as $item) {
                $hash = md5($item['url'] ?? '');
                if (!isset($favHashes[$hash])) {
                    continue;
                }

                if ($targetCategory !== null && $catName !== $targetCategory) {
                    continue;
                }

                if ($targetCategory === null) {
                    // Listing root of Favorites/Flash/ - collect subdirectories
                    if (!isset($subdirs[$catName])) {
                        $subdirs[$catName] = time();
                    }
                } else {
                    // Inside a category - list files
                    $title = self::buildFlashDisplayName($item, $hash, $seenNames);
                    $baseName = pathinfo($title, PATHINFO_FILENAME);
                    $files[] = [
                        'name' => $title,
                        'type' => 'file',
                        'hash' => $hash,
                        'ext' => 'swf',
                        'mtime' => time(),
                        'contentType' => 'application/x-shockwave-flash',
                        'flashUrl' => $item['url'] ?? '',
                        'isFavFlash' => true,
                        'flashCategory' => $category,
                    ];

                    // Add .html sidecar for SWF files (Ruffle player page)
                    if (self::isEnvEnabled('WEBDAV_FLASH_HTML_SIDECAR')) {
                        $htmlContent = self::generateRufflePlayerHtml($title);
                        $files[] = [
                            'name' => $baseName . '.html',
                            'type' => 'file',
                            'hash' => md5($hash . '.html'),
                            'ext' => 'html',
                            'mtime' => time(),
                            'size' => strlen($htmlContent),
                            'contentType' => 'text/html',
                            'flashHtmlSidecar' => true,
                            'flashSwfName' => $title,
                            'isFavFlash' => true,
                        ];
                    }
                }
            }
        }

        // Fetch sizes for flash files
        if (!empty($files)) {
            $this->fetchFlashFileSizes($files);
        }

        // Add virtual assets directory for Ruffle if there are SWF files and sidecar is enabled
        $hasSwf = !empty($files) && self::isEnvEnabled('WEBDAV_FLASH_HTML_SIDECAR');

        $dirs = [];
        foreach ($subdirs as $name => $mtime) {
            $dirs[] = ['name' => $name, 'type' => 'dir', 'mtime' => $mtime];
        }
        if ($hasSwf) {
            $dirs[] = ['name' => 'assets', 'type' => 'dir', 'mtime' => time(), 'flashAssetsDir' => true];
        }
        usort($dirs, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        $result = ['dirs' => $dirs, 'files' => $files];
        $this->setCache($cacheKey, $result);
        return $result;
    }

    /**
     * Check if Favorites has any VR or Flash favorites (for showing directories).
     */
    public function hasFavoriteVr(): bool
    {
        return !empty($this->getVrFavoriteHashes());
    }

    public function hasFavoriteFlash(): bool
    {
        return !empty($this->getFlashFavoriteHashes());
    }

    /**
     * Toggle a VR item's favorite status via its hash.
     */
    public function toggleVrFavorite(string $hash): bool
    {
        $file = __DIR__ . '/../output/cache/favorites_vr.json';
        $favorites = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        $index = array_search($hash, $favorites);
        if ($index !== false) {
            array_splice($favorites, $index, 1);
            file_put_contents($file, json_encode(array_values($favorites)));
            return false; // removed
        }
        $favorites[] = $hash;
        file_put_contents($file, json_encode(array_values($favorites)));
        return true; // added
    }

    /**
     * Toggle a Flash item's favorite status via its hash.
     */
    public function toggleFlashFavorite(string $hash): bool
    {
        $file = __DIR__ . '/../output/cache/favorites_flash.json';
        $favorites = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        $index = array_search($hash, $favorites);
        if ($index !== false) {
            array_splice($favorites, $index, 1);
            file_put_contents($file, json_encode(array_values($favorites)));
            return false; // removed
        }
        $favorites[] = $hash;
        file_put_contents($file, json_encode(array_values($favorites)));
        return true; // added
    }

    /**
     * Compute the VR hash matching the viewer's: md5("vr/" + encodePath(path))
     * encodePath base64-encodes each path segment (minus extension).
     */
    public static function computeVrHash(string $vrPath): string
    {
        $ext = strtolower(pathinfo($vrPath, PATHINFO_EXTENSION));
        $pathWithoutExt = $ext !== '' ? substr($vrPath, 0, -(strlen($ext) + 1)) : $vrPath;
        $parts = explode('/', $pathWithoutExt);
        $encodedParts = array_map('base64_encode', $parts);
        $encodedPath = implode('/', $encodedParts) . '.' . $ext;
        return md5('vr/' . $encodedPath);
    }

    public function getVrCloudUrl(string $vrPath): string
    {
        // VR content is served through the secondary endpoint (mirror-download),
        // same as how viewer/index.php serves non-pr paths
        $endpoint = privuma::getEnv('CLOUDFS_HTTP_SECONDARY_ENDPOINT');
        // Encode path the same way the viewer's encodePath() does:
        // base64-encode each path segment (without extension), rejoin with '/', add extension
        $ext = strtolower(pathinfo($vrPath, PATHINFO_EXTENSION));
        $pathWithoutExt = $ext !== '' ? substr($vrPath, 0, -(strlen($ext) + 1)) : $vrPath;
        $parts = explode('/', $pathWithoutExt);
        $encodedParts = array_map(function ($part) {
            return base64_encode($part);
        }, $parts);
        $encodedPath = implode('/', $encodedParts) . '.' . $ext;
        return 'http://' . $endpoint . '/vr/' . $encodedPath;
    }

    /**
     * Detect VR projection from filename using the same logic as viewer's get3dHash().
     */
    public static function detectVrProjection(string $filename): string
    {
        $upper = strtoupper(pathinfo($filename, PATHINFO_FILENAME));
        $parts = array_map('trim', explode('_', $upper));

        $stereoModes = ['LR', '3DH', 'SBS', 'TB', '3DV', 'OVERUNDER'];
        $screenTypes = ['180', '360', 'FISHEYE', 'FISHEYE190', 'RF52', 'MKX200', 'VRCA220'];

        $hasStereo = !empty(array_intersect($parts, $stereoModes));
        $hasScreen = !empty(array_intersect($parts, $screenTypes));

        if (!$hasStereo && !$hasScreen) {
            return '180'; // default
        }

        $is3d = !in_array('MONO', $parts);
        $stereoMode = 'mono';
        $screenType = 'flat';

        if (!empty(array_intersect($parts, ['LR', '3DH', 'SBS']))) {
            $stereoMode = 'sbs';
        }
        if (!empty(array_intersect($parts, ['TB', '3DV', 'OVERUNDER']))) {
            $stereoMode = 'tb';
        }
        if (!empty(array_intersect($parts, ['180', 'FISHEYE', 'FISHEYE190', 'RF52', 'MKX200', 'VRCA220']))) {
            $screenType = 'dome';
        }
        if (in_array('360', $parts)) {
            $screenType = 'sphere';
        }

        // Map to projection string (matching get3dHash logic)
        $hash = 'NONE';
        if ($stereoMode === 'sbs' && $screenType === 'dome') {
            $hash = '180_LR';
        }
        if ($stereoMode === 'tb') {
            $hash = '360_TB';
        }
        if ($stereoMode === 'sbs' && $screenType === 'flat') {
            $hash = 'SBS';
        }
        if ($stereoMode === 'sbs' && $screenType === 'dome' && !$is3d) {
            $hash = 'SBS';
        }
        if ($stereoMode === 'sbs' && $screenType === 'sphere') {
            $hash = '360_LR';
        }
        if ($hash === 'NONE' && $screenType === 'sphere') {
            $hash = '360';
        }

        return $hash;
    }

    /**
     * Check if an ENV variable is enabled (truthy).
     * Returns $default when the variable is not set.
     */
    private static function isEnvEnabled(string $key, bool $default = false): bool
    {
        $val = privuma::getEnv($key);
        if ($val === null || $val === '') {
            return $default;
        }
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    private static ?string $vrPlayerJsCache = null;
    private static ?string $vrPlayerCssCache = null;

    /**
     * Load and cache the inlined videojs + videojs-vr JS for VR player HTML.
     */
    private static function getVrPlayerJs(): string
    {
        if (self::$vrPlayerJsCache === null) {
            $assetsDir = __DIR__ . '/../../assets/js/';
            $videoJs = file_get_contents($assetsDir . 'videojs.min.js');
            $vrJs = file_get_contents($assetsDir . 'videojs-vr.min.js');
            self::$vrPlayerJsCache = $videoJs . "\n" . $vrJs;
        }
        return self::$vrPlayerJsCache;
    }

    /**
     * Load and cache the videojs base CSS for VR player HTML.
     */
    private static function getVrPlayerCss(): string
    {
        if (self::$vrPlayerCssCache === null) {
            $assetsDir = __DIR__ . '/../../assets/js/';
            self::$vrPlayerCssCache = file_get_contents($assetsDir . 'video-js.min.css') ?: '';
        }
        return self::$vrPlayerCssCache;
    }

    /**
     * Generate a self-contained VR player HTML page for a video file.
     * All JS dependencies are inlined so the file works with no external resources.
     */
    public static function generateVrPlayerHtml(string $videoFilename, string $projection): string
    {
        $videoUrl = './' . rawurlencode($videoFilename);
        $escapedFilename = htmlspecialchars($videoFilename, ENT_QUOTES);
        $escapedProjection = htmlspecialchars($projection, ENT_QUOTES);
        $inlinedJs = self::getVrPlayerJs();
        $inlinedCss = self::getVrPlayerCss();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VR Player - {$escapedFilename}</title>
<style>{$inlinedCss}</style>
<style>
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:100%;height:100%;overflow:hidden;background:#000}
.vr-container{width:100%;height:calc(100% - 40px);position:relative}
.controls{height:40px;background:rgba(20,20,40,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-top:1px solid rgba(255,255,255,0.08);display:flex;align-items:center;padding:0 10px;gap:10px}
.controls select,.controls a{color:#fff;background:rgba(30,30,60,0.7);border:1px solid rgba(255,255,255,0.12);padding:4px 8px;border-radius:4px;font-size:13px;text-decoration:none}
.controls select:hover,.controls a:hover{background:rgba(40,40,80,0.8);border-color:rgba(100,149,237,0.5)}
.controls .title{color:#aaa;font-size:12px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.video-js{width:100%!important;height:100%!important}
.video-js .vjs-control-bar{background:rgba(20,20,40,0.85)!important;backdrop-filter:blur(12px)!important;-webkit-backdrop-filter:blur(12px)!important;border-top:1px solid rgba(255,255,255,0.08)!important}
.video-js .vjs-big-play-button{background-color:rgba(20,20,40,0.7)!important;backdrop-filter:blur(12px)!important;-webkit-backdrop-filter:blur(12px)!important;border:2px solid rgba(255,255,255,0.12)!important;border-radius:50%!important;box-shadow:0 8px 32px rgba(0,0,0,0.3)!important;width:80px!important;height:80px!important;position:absolute!important;top:50%!important;left:50%!important;transform:translate(-50%,-50%)!important;margin:0!important;display:flex!important;align-items:center!important;justify-content:center!important}
.video-js .vjs-big-play-button:hover{background-color:rgba(30,30,60,0.8)!important;border-color:rgba(100,149,237,0.5)!important;box-shadow:0 0 20px rgba(100,149,237,0.3),0 8px 32px rgba(0,0,0,0.3)!important;transform:translate(-50%,-50%) scale(1.05)!important}
.video-js.vjs-has-started .vjs-big-play-button,.video-js.vjs-playing .vjs-big-play-button,
.video-js.vjs-has-started .vjs-big-vr-play-button,.video-js.vjs-playing .vjs-big-vr-play-button{display:none!important;visibility:hidden!important;opacity:0!important;pointer-events:none!important}
.video-js .vjs-big-vr-play-button{background-color:rgba(20,20,40,0.7)!important;backdrop-filter:blur(12px)!important;-webkit-backdrop-filter:blur(12px)!important;background-size:50%!important;background-position:center!important;background-repeat:no-repeat!important}
.video-js .vjs-big-vr-play-button .vjs-icon-placeholder{display:none!important}
.video-js .vjs-big-vr-play-button .vjs-icon-placeholder:before{display:none!important;content:''!important;font-size:0!important;width:0!important;height:0!important}
.video-js canvas{cursor:move}
</style>
</head>
<body>
<div class="vr-container">
<video id="vr-player" class="video-js vjs-fill vjs-default-skin" loop playsinline controls>
<source type="video/mp4" src="{$videoUrl}">
</video>
</div>
<div class="controls">
<span class="title">{$escapedFilename}</span>
<select id="projectionSelect">
<option value="180">180</option>
<option value="180_LR">180_LR</option>
<option value="180_MONO">180_MONO</option>
<option value="SBS">Side By Side</option>
<option value="360">360</option>
<option value="Cube">Cube</option>
<option value="NONE">Flat</option>
<option value="360_LR">360_LR</option>
<option value="360_TB">360_TB</option>
<option value="EAC">EAC</option>
<option value="EAC_LR">EAC_LR</option>
</select>
<a href="{$videoUrl}" download>Download</a>
</div>
<script>{$inlinedJs}</script>
<script>
(function(){
var defaultProjection="{$escapedProjection}";
var sel=document.getElementById("projectionSelect");
var stored=localStorage.getItem("vr-type");
var projection=stored||defaultProjection||"180";
sel.value=projection;
function initPlayer(){
if(typeof videojs==="undefined"){setTimeout(initPlayer,100);return}
var p=projection==="SBS"?"SBS_MONO":projection;
var player=videojs("vr-player");
player.mediainfo=player.mediainfo||{};
player.mediainfo.projection=p;
player.vr({projection:p,debug:false,forceCardboard:false});
sel.addEventListener("change",function(){
projection=sel.value;
localStorage.setItem("vr-type",projection);
var np=projection==="SBS"?"SBS_MONO":projection;
player.vr().setProjection(np);
});
}
initPlayer();
})();
</script>
</body>
</html>
HTML;
    }

    /**
     * Get the path to the Ruffle assets directory.
     */
    private static function getRuffleDir(): string
    {
        return __DIR__ . '/../../jobs/core/download/flash/ruffle/';
    }

    /**
     * List Ruffle asset files for inclusion as a virtual "assets" directory.
     * Returns file entries suitable for PROPFIND responses.
     */
    public static function listRuffleAssets(): array
    {
        $dir = self::getRuffleDir();
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $allowed = ['js', 'wasm'];
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . $entry;
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                continue;
            }
            $contentType = $ext === 'wasm' ? 'application/wasm' : 'application/javascript';
            $files[] = [
                'name' => $entry,
                'type' => 'file',
                'hash' => md5('ruffle_asset_' . $entry),
                'ext' => $ext,
                'mtime' => filemtime($path),
                'size' => filesize($path),
                'contentType' => $contentType,
                'ruffleAsset' => true,
                'ruffleAssetPath' => $path,
            ];
        }
        return $files;
    }

    /**
     * Resolve a single Ruffle asset file by name.
     */
    public static function resolveRuffleAsset(string $filename): ?array
    {
        $assets = self::listRuffleAssets();
        foreach ($assets as $asset) {
            if ($asset['name'] === $filename) {
                return $asset;
            }
        }
        return null;
    }

    /**
     * Generate a self-contained Ruffle player HTML page for a SWF file.
     * Loads Ruffle from ./assets/ruffle.js (relative to the HTML file).
     */
    public static function generateRufflePlayerHtml(string $swfFilename): string
    {
        $swfUrl = './' . rawurlencode($swfFilename);
        $escapedFilename = htmlspecialchars($swfFilename, ENT_QUOTES);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Flash Player - {$escapedFilename}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:100%;height:100%;overflow:hidden;background:#000}
.flash-container{width:100%;height:calc(100% - 40px);display:flex;align-items:center;justify-content:center;background:#000}
.flash-container ruffle-embed,
.flash-container ruffle-player{width:100%;height:100%}
.controls{height:40px;background:rgba(20,20,40,0.85);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-top:1px solid rgba(255,255,255,0.08);display:flex;align-items:center;padding:0 10px;gap:10px}
.controls a{color:#fff;background:rgba(30,30,60,0.7);border:1px solid rgba(255,255,255,0.12);padding:4px 8px;border-radius:4px;font-size:13px;text-decoration:none}
.controls a:hover{background:rgba(40,40,80,0.8);border-color:rgba(100,149,237,0.5)}
.controls .title{color:#aaa;font-size:12px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
</style>
</head>
<body>
<div class="flash-container">
<ruffle-embed src="{$swfUrl}" width="100%" height="100%"></ruffle-embed>
</div>
<div class="controls">
<span class="title">{$escapedFilename}</span>
<a href="{$swfUrl}" download>Download</a>
</div>
<script src="./assets/ruffle.js"></script>
</body>
</html>
HTML;
    }

    /**
     * Get all albums for a section as flat DB rows with rawName and segment arrays.
     */
    private function getAllAlbums(string $section): array
    {
        $cacheKey = 'allalbums_v2_' . $section;
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = [];

        if ($section === 'Favorites') {
            $stmt = $this->pdo->prepare(
                "SELECT filename, COUNT(*) as cnt, MAX(UNIX_TIMESTAMP(time)) as mtime
                 FROM media
                 WHERE album = 'Favorites' AND hash IS NOT NULL AND hash != '' AND hash != 'compressed'
                 GROUP BY SUBSTRING_INDEX(filename, '-----', 1)"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $parts = explode('-----', $row['filename']);
                $rawName = $parts[0] ?: 'Unknown';
                $segments = array_map([self::class, 'sanitizeName'], explode('---', $rawName));
                $result[] = [
                    'rawName' => $rawName,
                    'segments' => $segments,
                    'mtime' => (int) $row['mtime'],
                    'count' => (int) $row['cnt'],
                ];
            }
        } else {
            $blockedCondition = ($section === 'Albums') ? 'blocked = 0' : 'blocked = 1';
            $stmt = $this->pdo->prepare(
                "SELECT album, COUNT(*) as cnt, MAX(UNIX_TIMESTAMP(time)) as mtime
                 FROM media
                 WHERE $blockedCondition AND album != 'Favorites'
                   AND hash IS NOT NULL AND hash != '' AND hash != 'compressed'
                 GROUP BY album
                 ORDER BY album"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $segments = array_map([self::class, 'sanitizeName'], explode('---', $row['album']));
                $result[] = [
                    'rawName' => $row['album'],
                    'segments' => $segments,
                    'mtime' => (int) $row['mtime'],
                    'count' => (int) $row['cnt'],
                ];
            }
        }

        $this->setCache($cacheKey, $result);
        return $result;
    }

    /**
     * List children (subdirectories and/or files) at a given virtual folder path.
     * $folderPath is an array of sanitized segment names, e.g. ['Artists', 'Alice'].
     * Returns ['dirs' => [...], 'albums' => [...]] where albums are leaf albums that
     * have media at exactly this path depth.
     */
    public function listFolder(string $section, array $folderPath): array
    {
        $allAlbums = $this->getAllAlbums($section);
        $depth = count($folderPath);

        $subdirs = [];    // name => max mtime
        $leafAlbums = []; // albums whose segments exactly match folderPath

        foreach ($allAlbums as $album) {
            $segs = $album['segments'];

            // Check if this album's segments match the requested path prefix
            if (count($segs) < $depth) {
                continue;
            }
            $match = true;
            for ($i = 0; $i < $depth; $i++) {
                if ($segs[$i] !== $folderPath[$i]) {
                    $match = false;
                    break;
                }
            }
            if (!$match) {
                continue;
            }

            if (count($segs) === $depth) {
                // This album's path exactly matches folderPath - it's a leaf album here
                $leafAlbums[] = $album;
            } else {
                // This album extends deeper - the next segment is a subdirectory
                $childName = $segs[$depth];
                if (!isset($subdirs[$childName])) {
                    $subdirs[$childName] = 0;
                }
                $subdirs[$childName] = max($subdirs[$childName], $album['mtime']);
            }
        }

        $dirs = [];
        foreach ($subdirs as $name => $mtime) {
            $dirs[] = ['name' => $name, 'type' => 'dir', 'mtime' => $mtime];
        }
        usort($dirs, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return ['dirs' => $dirs, 'albums' => $leafAlbums];
    }

    /**
     * Resolve a virtual folder path to determine if it's valid (has children or is a leaf album).
     * Returns 'folder' if it has subdirs or leaf albums, null if not found.
     */
    public function resolveFolder(string $section, array $folderPath): ?string
    {
        $result = $this->listFolder($section, $folderPath);
        if (!empty($result['dirs']) || !empty($result['albums'])) {
            return 'folder';
        }
        return null;
    }

    /**
     * Resolve a folder path back to the raw DB album name(s) for leaf albums.
     */
    public function resolveLeafAlbums(string $section, array $folderPath): array
    {
        $result = $this->listFolder($section, $folderPath);
        return $result['albums'];
    }

    /**
     * List media contents for all leaf albums at a given folder path.
     */
    public function listAlbumContents(string $section, string $rawAlbumName): array
    {
        $cacheKey = 'contents_v3_' . $section . '_' . md5($rawAlbumName);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            // Re-fetch sizes for any items still missing them
            if ($this->hasMissingSizes($cached)) {
                $this->fetchFileSizes($cached, $section);
                $this->setCache($cacheKey, $cached);
            }
            return $cached;
        }

        $result = [];

        if ($section === 'Favorites') {
            $stmt = $this->pdo->prepare(
                "SELECT hash, filename, MAX(UNIX_TIMESTAMP(time)) as mtime,
                        MAX(metadata) as metadata, MAX(duration) as duration,
                        MAX(sound) as sound, MAX(score) as score
                 FROM media
                 WHERE album = 'Favorites' AND hash IS NOT NULL AND hash != '' AND hash != 'compressed'
                   AND SUBSTRING_INDEX(filename, '-----', 1) = ?
                 GROUP BY hash
                 ORDER BY MAX(time) DESC"
            );
            $stmt->execute([$rawAlbumName]);

            // Comics albums: if any pages are favorited, include ALL pages from the source album
            $lowerAlbum = strtolower($rawAlbumName);
            $isComicsAlbum = ($lowerAlbum === 'comics' || strpos($lowerAlbum, 'comics---') === 0);
            if ($isComicsAlbum) {
                $favRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($favRows)) {
                    // Fetch ALL pages from the source album (regardless of blocked status)
                    // Include MAX(blocked) to determine correct cloud prefix (pr/ vs un/)
                    $allStmt = $this->pdo->prepare(
                        "SELECT hash, filename, MAX(UNIX_TIMESTAMP(time)) as mtime,
                                MAX(metadata) as metadata, MAX(duration) as duration,
                                MAX(sound) as sound, MAX(score) as score,
                                MAX(blocked) as blocked
                         FROM media
                         WHERE album = ?
                           AND hash IS NOT NULL AND hash != '' AND hash != 'compressed'
                         GROUP BY hash
                         ORDER BY filename ASC"
                    );
                    $allStmt->execute([$rawAlbumName]);
                    $allRows = $allStmt->fetchAll(PDO::FETCH_ASSOC);

                    // Build a set of favorited hashes for quick lookup
                    $favHashSet = [];
                    foreach ($favRows as $favRow) {
                        $favHashSet[$favRow['hash']] = true;
                    }

                    // Use all pages from source album, probing cloud storage for actual location
                    $seenHashes = [];
                    $mergedRows = [];
                    foreach ($allRows as $row) {
                        if (!isset($seenHashes[$row['hash']])) {
                            if (!isset($favHashSet[$row['hash']])) {
                                // Non-favorited page — mark for multi-prefix probing
                                $row['_comicsProbe'] = true;
                                $row['_sourceSection'] = ((int) $row['blocked'] === 1) ? 'Unfiltered' : 'Albums';
                            }
                            // Favorited pages default to Favorites (fa/) prefix
                            $mergedRows[] = $row;
                            $seenHashes[$row['hash']] = true;
                        }
                    }
                    // Add any favorites not found in the source album
                    foreach ($favRows as $row) {
                        if (!isset($seenHashes[$row['hash']])) {
                            $mergedRows[] = $row;
                            $seenHashes[$row['hash']] = true;
                        }
                    }
                    $favRows = $mergedRows;
                }
                // Replace stmt result with merged rows
                $rows = $favRows;
            }
        } else {
            $blockedCondition = ($section === 'Albums') ? 'blocked = 0' : 'blocked = 1';
            $stmt = $this->pdo->prepare(
                "SELECT hash, filename, MAX(UNIX_TIMESTAMP(time)) as mtime,
                        MAX(metadata) as metadata, MAX(duration) as duration,
                        MAX(sound) as sound, MAX(score) as score
                 FROM media
                 WHERE album = ? AND $blockedCondition
                   AND hash IS NOT NULL AND hash != '' AND hash != 'compressed'
                 GROUP BY hash
                 ORDER BY MAX(time) DESC"
            );
            $stmt->execute([$rawAlbumName]);
        }

        $seenNames = [];
        $fetchedRows = isset($rows) ? $rows : $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($fetchedRows as $row) {
            $filename = $this->extractFilename($row['filename'], $section);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            $baseName = self::sanitizeName(pathinfo($filename, PATHINFO_FILENAME));

            $isVideo = in_array($ext, self::$videoExts);

            // Non-video images are stored as WebP — serve with .webp extension
            // so clients can properly parse animated WebP etc.
            $serveExt = (!$isVideo && $ext !== 'webp') ? 'webp' : $ext;
            $displayName = $baseName . '.' . $serveExt;

            // Handle filename collisions
            if (isset($seenNames[$displayName])) {
                $displayName = $baseName . '_' . substr($row['hash'], 0, 8) . '.' . $serveExt;
            }
            $seenNames[$displayName] = true;

            $entry = [
                'name' => $displayName,
                'type' => 'file',
                'hash' => $row['hash'],
                'ext' => $ext,
                'isVideo' => $isVideo,
                'mtime' => (int) $row['mtime'],
                'contentType' => self::getContentType($serveExt),
            ];
            // Comics pages sourced from Albums/Unfiltered need different cloud URL prefix
            if (isset($row['_sourceSection'])) {
                $entry['sourceSection'] = $row['_sourceSection'];
            }
            // Flag comics probe entries for multi-prefix HEAD probing in fetchFileSizes
            if (isset($row['_comicsProbe'])) {
                $entry['comicsProbe'] = true;
            }
            $result[] = $entry;

            // Add .json sidecar with metadata and DB fields
            if (self::isEnvEnabled('WEBDAV_JSON_SIDECAR', true)) {
                $sidecarData = [
                    'hash' => $row['hash'],
                    'duration' => $row['duration'] ?? null,
                    'sound' => $row['sound'] ?? null,
                    'score' => $row['score'] ?? null,
                ];
                if (!empty($row['metadata']) && trim($row['metadata']) !== '') {
                    $decoded = json_decode($row['metadata'], true);
                    $sidecarData['metadata'] = is_array($decoded) ? $decoded : $row['metadata'];
                }
                $sidecarJson = json_encode($sidecarData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $sidecarName = pathinfo($displayName, PATHINFO_FILENAME) . '.json';
                $result[] = [
                    'name' => $sidecarName,
                    'type' => 'file',
                    'hash' => $row['hash'],
                    'ext' => 'json',
                    'mtime' => (int) $row['mtime'],
                    'contentType' => 'application/json',
                    'sidecar' => $sidecarData,
                    'size' => strlen($sidecarJson),
                ];
            }
        }

        // Fetch file sizes via parallel HEAD requests
        $this->fetchFileSizes($result, $section);

        $this->setCache($cacheKey, $result);
        return $result;
    }

    /**
     * Get combined file listing for a folder path (may include files from multiple leaf albums).
     */
    public function listFolderFiles(string $section, array $folderPath): array
    {
        $leafAlbums = $this->resolveLeafAlbums($section, $folderPath);
        $allFiles = [];
        foreach ($leafAlbums as $album) {
            $contents = $this->listAlbumContents($section, $album['rawName']);
            $allFiles = array_merge($allFiles, $contents);
        }
        return $allFiles;
    }

    public function resolveFile(string $section, array $folderPath, string $filename): ?array
    {
        $files = $this->listFolderFiles($section, $folderPath);
        foreach ($files as $item) {
            if ($item['name'] === $filename && $item['type'] === 'file') {
                if (isset($item['sidecar'])) {
                    return [
                        'type' => 'sidecar',
                        'sidecar' => $item['sidecar'],
                        'mtime' => $item['mtime'],
                        'size' => $item['size'],
                    ];
                }
                return $item;
            }
        }
        return null;
    }

    public function getCloudUrl(string $hash, string $ext, string $section): string
    {
        $prefix = 'pr';
        if ($section === 'Favorites') {
            $prefix = 'fa';
        } elseif ($section === 'Unfiltered') {
            $prefix = 'un';
        }

        // For non-video images, storage may only have .webp version
        $isVideo = in_array($ext, self::$videoExts);
        $storageExt = (!$isVideo && $ext !== 'webp') ? 'webp' : $ext;

        // Build segmented encoded path matching download job pattern
        $encoded = cloudFS::encode($hash . '.' . $storageExt, true);
        // Safe prefix removal - encode() returns "./XX/file.ext" for bare filenames
        if (substr($encoded, 0, 2) === '.' . DIRECTORY_SEPARATOR) {
            $encoded = substr($encoded, 2);
        }
        $encoded = str_replace(DIRECTORY_SEPARATOR, '/', $encoded);
        $endpoint = privuma::getEnv('CLOUDFS_HTTP_SECONDARY_ENDPOINT');

        return 'http://' . $endpoint . '/' . $prefix . '/' . $encoded;
    }

    /**
     * Fetch file sizes via parallel HEAD requests to the rclone HTTP backend.
     * Uses a persistent size cache (keyed by hash+ext) so each file is only HEAD'd once.
     */
    private function fetchFileSizes(array &$items, string $section): void
    {
        // Load persistent size cache
        $sizeCache = [];
        if (file_exists($this->sizeCacheFile)) {
            $raw = file_get_contents($this->sizeCacheFile);
            if ($raw !== false) {
                $sizeCache = json_decode($raw, true) ?: [];
            }
        }

        // Collect items that need sizes (skip sidecars - they already have sizes)
        $needSize = [];
        // For comics probe items, also check persistent sourceSection cache
        $sourceSectionCacheFile = $this->cacheDir . '/source_sections.json';
        $sourceSectionCache = [];
        if (file_exists($sourceSectionCacheFile)) {
            $raw = file_get_contents($sourceSectionCacheFile);
            if ($raw !== false) {
                $sourceSectionCache = json_decode($raw, true) ?: [];
            }
        }

        foreach ($items as $idx => &$item) {
            if (isset($item['sidecar']) || $item['type'] !== 'file') {
                continue;
            }
            // For comics probe items, check if we already know the correct section
            if (!empty($item['comicsProbe']) && isset($sourceSectionCache[$item['hash']])) {
                $item['sourceSection'] = $sourceSectionCache[$item['hash']];
            }
            $cacheKey = $item['hash'] . '.' . $item['ext'];
            if (isset($sizeCache[$cacheKey]) && $sizeCache[$cacheKey] > 0) {
                $item['size'] = $sizeCache[$cacheKey];
            } else {
                $urlSection = $item['sourceSection'] ?? $section;
                $url = $this->getCloudUrl($item['hash'], $item['ext'], $urlSection);
                $entry = ['idx' => $idx, 'url' => $url, 'cacheKey' => $cacheKey];
                // Comics probe: try primary section first, then probe alternatives on failure
                if (!empty($item['comicsProbe']) && !isset($sourceSectionCache[$item['hash']])) {
                    $entry['comicsProbe'] = true;
                    $entry['hash'] = $item['hash'];
                    $entry['ext'] = $item['ext'];
                    $entry['triedSection'] = $urlSection;
                }
                $needSize[] = $entry;
            }
        }
        unset($item);

        if (empty($needSize)) {
            return;
        }

        $dirty = false;
        $sourceSectionDirty = false;

        // Batch HEAD requests using curl_multi
        $mh = curl_multi_init();
        $batchSize = 30;

        for ($batch = 0; $batch < count($needSize); $batch += $batchSize) {
            $slice = array_slice($needSize, $batch, $batchSize);
            $handles = [];

            foreach ($slice as $info) {
                $ch = curl_init($info['url']);
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => true,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[] = ['ch' => $ch, 'info' => $info];
            }

            // Execute all handles
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1);
                }
            } while ($running > 0 && $status === CURLM_OK);

            // Collect results — track failures for comics probe retry
            $retryEntries = [];
            foreach ($handles as $h) {
                $ch = $h['ch'];
                $info = $h['info'];
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $contentLength = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

                if ($httpCode === 200 && $contentLength > 0) {
                    $items[$info['idx']]['size'] = $contentLength;
                    $sizeCache[$info['cacheKey']] = $contentLength;
                    $dirty = true;
                    // Cache the successful section for comics probe items
                    if (!empty($info['comicsProbe'])) {
                        $sourceSectionCache[$info['hash']] = $info['triedSection'];
                        $sourceSectionDirty = true;
                    }
                } elseif (!empty($info['comicsProbe'])) {
                    // Failed — queue for retry with alternative prefixes
                    $retryEntries[] = $info;
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            // Retry failed comics probe items with alternative prefixes
            if (!empty($retryEntries)) {
                $altSections = ['Albums', 'Unfiltered', 'Favorites'];
                foreach ($retryEntries as &$retryInfo) {
                    $triedSections = [$retryInfo['triedSection']];
                    foreach ($altSections as $altSection) {
                        if (in_array($altSection, $triedSections)) {
                            continue;
                        }
                        $altUrl = $this->getCloudUrl($retryInfo['hash'], $retryInfo['ext'], $altSection);
                        $ch = curl_init($altUrl);
                        curl_setopt_array($ch, [
                            CURLOPT_NOBODY => true,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 10,
                            CURLOPT_CONNECTTIMEOUT => 5,
                            CURLOPT_FOLLOWLOCATION => true,
                        ]);
                        curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $contentLength = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
                        curl_close($ch);

                        if ($httpCode === 200 && $contentLength > 0) {
                            $items[$retryInfo['idx']]['size'] = $contentLength;
                            $items[$retryInfo['idx']]['sourceSection'] = $altSection;
                            $sizeCache[$retryInfo['cacheKey']] = $contentLength;
                            $sourceSectionCache[$retryInfo['hash']] = $altSection;
                            $dirty = true;
                            $sourceSectionDirty = true;
                            break;
                        }
                        $triedSections[] = $altSection;
                    }
                }
                unset($retryInfo);
            }
        }

        curl_multi_close($mh);

        // Save updated caches
        if ($dirty) {
            file_put_contents($this->sizeCacheFile, json_encode($sizeCache), LOCK_EX);
        }
        if ($sourceSectionDirty) {
            file_put_contents($sourceSectionCacheFile, json_encode($sourceSectionCache), LOCK_EX);
        }
    }

    private function extractFilename(string $filename, string $section): string
    {
        if ($section === 'Favorites') {
            $parts = explode('-----', $filename, 2);
            return $parts[1] ?? $parts[0];
        }
        return $filename;
    }

    /**
     * Sanitize a name for use as a filesystem path component.
     */
    /**
     * Build a display name for a Flash/SWF item.
     * Prefers the title from the data, falls back to URL basename.
     * Ensures the name ends in .swf and handles collisions with a hash prefix.
     */
    private static function buildFlashDisplayName(array $item, string $hash, array &$seenNames): string
    {
        $raw = $item['title'] ?? basename($item['url'] ?? 'unknown.swf');

        // Extract the base name (without .swf if present) and sanitize
        $base = $raw;
        if (strtolower(pathinfo($base, PATHINFO_EXTENSION)) === 'swf') {
            $base = pathinfo($base, PATHINFO_FILENAME);
        }
        $base = self::sanitizeName($base);

        // Truncate overly long tag-based titles
        if (strlen($base) > 120) {
            $base = substr($base, 0, 120);
            $base = rtrim($base, ' _-.');
        }

        $displayName = $base . '.swf';

        // Handle collisions
        if (isset($seenNames[$displayName])) {
            $displayName = $base . '_' . substr($hash, 0, 8) . '.swf';
        }
        $seenNames[$displayName] = true;

        return $displayName;
    }

    public static function sanitizeName(string $name): string
    {
        // Strip control characters (newlines, tabs, null bytes, etc.)
        $name = preg_replace('/[\x01-\x1F\x7F]/', '', $name);
        $name = str_replace("\0", '', $name);
        $name = str_replace(['/', '\\'], '_', $name);
        $name = str_replace(['<', '>', ':', '"', '|', '?', '*'], '_', $name);
        $name = rtrim($name, '. ');
        return $name !== '' ? $name : '_';
    }

    private static function getContentType(string $ext): string
    {
        $types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'swf' => 'application/x-shockwave-flash',
            'json' => 'application/json',
        ];
        return $types[$ext] ?? 'application/octet-stream';
    }

    /**
     * Check if any media file entries in a listing are missing sizes.
     */
    private function hasMissingSizes(array $items): bool
    {
        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'file') {
                continue;
            }
            if (isset($item['sidecar']) || !empty($item['vrJsonSidecar']) || !empty($item['vrSidecar']) || !empty($item['flashHtmlSidecar'])) {
                continue;
            }
            if (empty($item['size'])) {
                return true;
            }
        }
        return false;
    }

    private function getCache(string $key): ?array
    {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        if (!file_exists($file)) {
            return null;
        }
        if (time() - filemtime($file) > $this->cacheTTL) {
            unlink($file);
            return null;
        }
        $data = file_get_contents($file);
        if ($data === false) {
            return null;
        }
        return json_decode($data, true);
    }

    private function setCache(string $key, array $data): void
    {
        $file = $this->cacheDir . '/' . md5($key) . '.json';
        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    /**
     * Clear cached data related to Favorites so listings reflect changes immediately.
     */
    public function clearFavoritesCache(): void
    {
        // Clear all cache files — favorites changes can affect Albums counts too
        $files = glob($this->cacheDir . '/*.json');
        if ($files) {
            foreach ($files as $file) {
                if (basename($file) !== 'sizes.json' && basename($file) !== 'source_sections.json') {
                    unlink($file);
                }
            }
        }
    }
}
