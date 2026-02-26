<?php
namespace privuma\webdav;

use privuma\privuma;
use privuma\helpers\mediaFile;

class WebDavServer
{
    private privuma $privuma;
    private WebDavFilesystem $fs;
    private string $basePath = '/access';

    private static array $mediaSections = ['Albums', 'Favorites', 'Unfiltered'];

    public function __construct(privuma $privuma)
    {
        $this->privuma = $privuma;
        $this->fs = new WebDavFilesystem($privuma->getPDO());
    }

    public function handle(): void
    {
        if (!$this->authenticate()) {
            header('WWW-Authenticate: Basic realm="Privuma WebDAV"');
            http_response_code(401);
            echo 'Authentication required';
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'];
        $path = $_SERVER['PATH_INFO'] ?? '/';
        $path = '/' . trim($path, '/');

        switch ($method) {
            case 'OPTIONS':
                $this->handleOptions();
                break;
            case 'PROPFIND':
                $this->handlePropfind($path);
                break;
            case 'GET':
                $this->serveFile($path, true);
                break;
            case 'HEAD':
                $this->serveFile($path, false);
                break;
            case 'COPY':
                $this->handleCopy($path);
                break;
            case 'DELETE':
                $this->handleDelete($path);
                break;
            default:
                http_response_code(405);
                header('Allow: OPTIONS, PROPFIND, GET, HEAD, COPY, DELETE');
                echo 'Method not allowed';
                break;
        }
    }

    private function authenticate(): bool
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? '';
        $password = $_SERVER['PHP_AUTH_PW'] ?? '';

        if ($username === '' && $password === '') {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/^Basic\s+(.+)$/i', $authHeader, $matches)) {
                $decoded = base64_decode($matches[1]);
                if ($decoded !== false && strpos($decoded, ':') !== false) {
                    list($username, $password) = explode(':', $decoded, 2);
                }
            }
        }

        $expectedUser = privuma::getEnv('WEBDAV_USERNAME');
        $expectedPass = privuma::getEnv('WEBDAV_PASSWORD');

        return $username === $expectedUser && $password === $expectedPass;
    }

    private function handleOptions(): void
    {
        header('DAV: 1');
        header('Allow: OPTIONS, PROPFIND, GET, HEAD, COPY, DELETE');
        header('MS-Author-Via: DAV');
        http_response_code(200);
    }

    private function handlePropfind(string $path): void
    {
        $depth = $_SERVER['HTTP_DEPTH'] ?? '1';

        if ($depth === 'infinity') {
            http_response_code(403);
            echo 'Depth: infinity not supported';
            return;
        }

        $parsed = $this->parsePath($path);
        $responses = $this->buildPropfindResponses($parsed, $depth);

        if ($responses === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        http_response_code(207);
        header('Content-Type: application/xml; charset=utf-8');
        echo WebDavXml::multiStatus($responses);
    }

    private function buildPropfindResponses(array $parsed, string $depth): ?array
    {
        $responses = [];

        switch ($parsed['level']) {
            case 'root':
                $responses[] = WebDavXml::directoryResponse($this->basePath . '/', '', time());
                if ($depth !== '0') {
                    foreach ($this->fs->listRoot() as $dir) {
                        $responses[] = WebDavXml::directoryResponse(
                            $this->basePath . '/' . $dir['name'] . '/',
                            $dir['name'],
                            $dir['mtime']
                        );
                    }
                }
                return $responses;

            case 'folder':
                return $this->propfindMediaFolder($parsed, $depth);

            case 'file':
                return $this->propfindMediaFile($parsed);

            case 'flash_root':
                return $this->propfindFlashRoot($depth);

            case 'flash_category':
                return $this->propfindFlashCategory($parsed, $depth);

            case 'flash_file':
                return $this->propfindFlashFile($parsed);

            case 'flash_assets_dir':
                return $this->propfindFlashAssetsDir($parsed, $depth);

            case 'vr_folder':
                return $this->propfindVrFolder($parsed, $depth);

            case 'vr_file':
                return $this->propfindVrFile($parsed);

            case 'fav_vr_folder':
                return $this->propfindFavVrFolder($parsed, $depth);

            case 'fav_vr_file':
                return $this->propfindFavVrFile($parsed);

            case 'fav_flash_folder':
                return $this->propfindFavFlashFolder($parsed, $depth);

            case 'fav_flash_file':
                return $this->propfindFavFlashFile($parsed);

            default:
                return null;
        }
    }

    // ---- Media PROPFIND ----

    private function propfindMediaFolder(array $parsed, string $depth): ?array
    {
        $section = $parsed['section'];
        $folderPath = $parsed['folderPath'];
        $hrefBase = $this->buildHref($section, $folderPath);

        if (!empty($folderPath) && $this->fs->resolveFolder($section, $folderPath) === null) {
            return null;
        }

        $responses = [];
        $responses[] = WebDavXml::directoryResponse(
            $hrefBase . '/',
            empty($folderPath) ? $section : end($folderPath),
            time()
        );

        if ($depth !== '0') {
            $listing = $this->fs->listFolder($section, $folderPath);

            foreach ($listing['dirs'] as $dir) {
                $responses[] = WebDavXml::directoryResponse(
                    $hrefBase . '/' . $dir['name'] . '/',
                    $dir['name'],
                    $dir['mtime']
                );
            }

            // Inject VR/Flash favorite directories at Favorites root
            if ($section === 'Favorites' && empty($folderPath)) {
                if ($this->fs->hasFavoriteVr()) {
                    $responses[] = WebDavXml::directoryResponse(
                        $hrefBase . '/VR/',
                        'VR',
                        time()
                    );
                }
                if ($this->fs->hasFavoriteFlash()) {
                    $responses[] = WebDavXml::directoryResponse(
                        $hrefBase . '/Flash/',
                        'Flash',
                        time()
                    );
                }
            }

            $files = $this->fs->listFolderFiles($section, $folderPath);
            foreach ($files as $item) {
                $responses[] = WebDavXml::fileResponse(
                    $hrefBase . '/' . $item['name'],
                    $item['name'],
                    $item['mtime'],
                    $item['contentType'],
                    $item['hash'],
                    $item['size'] ?? null
                );
            }
        }

        return $responses;
    }

    private function propfindMediaFile(array $parsed): ?array
    {
        $file = $this->fs->resolveFile($parsed['section'], $parsed['folderPath'], $parsed['filename']);
        if ($file === null) {
            return null;
        }

        $href = $this->buildHref($parsed['section'], $parsed['folderPath']) . '/' . $parsed['filename'];

        if (isset($file['type']) && $file['type'] === 'sidecar') {
            $json = json_encode($file['sidecar'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return [WebDavXml::fileResponse($href, $parsed['filename'], $file['mtime'], 'application/json', md5($json), strlen($json))];
        }
        return [WebDavXml::fileResponse($href, $parsed['filename'], $file['mtime'], $file['contentType'], $file['hash'], $file['size'] ?? null)];
    }

    // ---- Flash PROPFIND ----

    private function propfindFlashRoot(string $depth): array
    {
        $responses = [];
        $responses[] = WebDavXml::directoryResponse($this->basePath . '/Flash/', 'Flash', time());
        if ($depth !== '0') {
            foreach ($this->fs->listFlashCategories() as $cat) {
                $responses[] = WebDavXml::directoryResponse(
                    $this->basePath . '/Flash/' . $cat['name'] . '/',
                    $cat['name'],
                    $cat['mtime']
                );
            }
        }
        return $responses;
    }

    private function propfindFlashCategory(array $parsed, string $depth): ?array
    {
        $category = $parsed['category'];
        $rawCategory = $parsed['rawCategory'];
        $responses = [];
        $responses[] = WebDavXml::directoryResponse(
            $this->basePath . '/Flash/' . $category . '/',
            $category,
            time()
        );
        if ($depth !== '0') {
            foreach ($this->fs->listFlashContents($rawCategory) as $item) {
                if ($item['type'] === 'dir') {
                    $responses[] = WebDavXml::directoryResponse(
                        $this->basePath . '/Flash/' . $category . '/' . $item['name'] . '/',
                        $item['name'],
                        $item['mtime']
                    );
                } else {
                    $responses[] = WebDavXml::fileResponse(
                        $this->basePath . '/Flash/' . $category . '/' . $item['name'],
                        $item['name'],
                        $item['mtime'],
                        $item['contentType'],
                        $item['hash'],
                        $item['size'] ?? null
                    );
                }
            }
        }
        return $responses;
    }

    private function propfindFlashFile(array $parsed): ?array
    {
        // Asset file within virtual assets/ directory
        if (!empty($parsed['flashAsset'])) {
            $asset = WebDavFilesystem::resolveRuffleAsset($parsed['flashAsset']);
            if ($asset === null) {
                return null;
            }
            $href = $this->basePath . '/Flash/' . $parsed['category'] . '/assets/' . $asset['name'];
            return [WebDavXml::fileResponse($href, $asset['name'], $asset['mtime'], $asset['contentType'], $asset['hash'], $asset['size'])];
        }

        $file = $this->fs->resolveFlashFile($parsed['rawCategory'], $parsed['filename']);
        if ($file === null) {
            return null;
        }
        $href = $this->basePath . '/Flash/' . $parsed['category'] . '/' . $parsed['filename'];
        return [WebDavXml::fileResponse($href, $parsed['filename'], $file['mtime'], $file['contentType'], $file['hash'], $file['size'] ?? null)];
    }

    private function propfindFlashAssetsDir(array $parsed, string $depth): array
    {
        // Determine base path (Flash or Favorites/Flash)
        if (isset($parsed['rawCategory'])) {
            $hrefBase = $this->basePath . '/Flash/' . $parsed['category'] . '/assets';
        } else {
            $hrefBase = $this->basePath . '/Favorites/Flash/' . implode('/', $parsed['flashPath']) . '/assets';
        }

        $responses = [];
        $responses[] = WebDavXml::directoryResponse($hrefBase . '/', 'assets', time());

        if ($depth !== '0') {
            foreach (WebDavFilesystem::listRuffleAssets() as $asset) {
                $responses[] = WebDavXml::fileResponse(
                    $hrefBase . '/' . $asset['name'],
                    $asset['name'],
                    $asset['mtime'],
                    $asset['contentType'],
                    $asset['hash'],
                    $asset['size']
                );
            }
        }

        return $responses;
    }

    // ---- VR PROPFIND ----

    private function propfindVrFolder(array $parsed, string $depth): ?array
    {
        $folderPath = $parsed['vrPath'];
        $hrefBase = $this->basePath . '/VR' . (empty($folderPath) ? '' : '/' . implode('/', $folderPath));

        if (!empty($folderPath) && $this->fs->resolveVrFolder($folderPath) === null) {
            return null;
        }

        $responses = [];
        $responses[] = WebDavXml::directoryResponse(
            $hrefBase . '/',
            empty($folderPath) ? 'VR' : end($folderPath),
            time()
        );

        if ($depth !== '0') {
            $listing = $this->fs->listVrFolder($folderPath);
            foreach ($listing['dirs'] as $dir) {
                $responses[] = WebDavXml::directoryResponse(
                    $hrefBase . '/' . $dir['name'] . '/',
                    $dir['name'],
                    $dir['mtime']
                );
            }
            foreach ($listing['files'] as $file) {
                $responses[] = WebDavXml::fileResponse(
                    $hrefBase . '/' . $file['name'],
                    $file['name'],
                    $file['mtime'],
                    $file['contentType'],
                    $file['hash'],
                    $file['size'] ?? null
                );
            }
        }

        return $responses;
    }

    private function propfindVrFile(array $parsed): ?array
    {
        $file = $this->fs->resolveVrFile($parsed['vrPath'], $parsed['filename']);
        if ($file === null) {
            return null;
        }
        $hrefBase = $this->basePath . '/VR' . (empty($parsed['vrPath']) ? '' : '/' . implode('/', $parsed['vrPath']));
        $href = $hrefBase . '/' . $parsed['filename'];
        return [WebDavXml::fileResponse($href, $parsed['filename'], $file['mtime'], $file['contentType'], $file['hash'], $file['size'] ?? null)];
    }

    // ---- Favorite VR/Flash PROPFIND ----

    private function propfindFavVrFolder(array $parsed, string $depth): ?array
    {
        $folderPath = $parsed['vrPath'];
        $hrefBase = $this->basePath . '/Favorites/VR' . (empty($folderPath) ? '' : '/' . implode('/', $folderPath));

        $listing = $this->fs->listFavoriteVr($folderPath);
        if (empty($listing['dirs']) && empty($listing['files']) && !empty($folderPath)) {
            return null;
        }

        $responses = [];
        $responses[] = WebDavXml::directoryResponse(
            $hrefBase . '/',
            empty($folderPath) ? 'VR' : end($folderPath),
            time()
        );

        if ($depth !== '0') {
            foreach ($listing['dirs'] as $dir) {
                $responses[] = WebDavXml::directoryResponse(
                    $hrefBase . '/' . $dir['name'] . '/',
                    $dir['name'],
                    $dir['mtime']
                );
            }
            foreach ($listing['files'] as $file) {
                $responses[] = WebDavXml::fileResponse(
                    $hrefBase . '/' . $file['name'],
                    $file['name'],
                    $file['mtime'],
                    $file['contentType'],
                    $file['hash'],
                    $file['size'] ?? null
                );
            }
        }

        return $responses;
    }

    private function propfindFavVrFile(array $parsed): ?array
    {
        $file = $this->resolveFavVrFile($parsed);
        if ($file === null) {
            return null;
        }
        $hrefBase = $this->basePath . '/Favorites/VR' . (empty($parsed['vrPath']) ? '' : '/' . implode('/', $parsed['vrPath']));
        $href = $hrefBase . '/' . $parsed['filename'];
        return [WebDavXml::fileResponse($href, $parsed['filename'], $file['mtime'], $file['contentType'], $file['hash'], $file['size'] ?? null)];
    }

    private function propfindFavFlashFolder(array $parsed, string $depth): ?array
    {
        $folderPath = $parsed['flashPath'];
        $hrefBase = $this->basePath . '/Favorites/Flash' . (empty($folderPath) ? '' : '/' . implode('/', $folderPath));

        $listing = $this->fs->listFavoriteFlash($folderPath);
        if (empty($listing['dirs']) && empty($listing['files']) && !empty($folderPath)) {
            return null;
        }

        $responses = [];
        $responses[] = WebDavXml::directoryResponse(
            $hrefBase . '/',
            empty($folderPath) ? 'Flash' : end($folderPath),
            time()
        );

        if ($depth !== '0') {
            foreach ($listing['dirs'] as $dir) {
                $responses[] = WebDavXml::directoryResponse(
                    $hrefBase . '/' . $dir['name'] . '/',
                    $dir['name'],
                    $dir['mtime']
                );
            }
            foreach ($listing['files'] as $file) {
                $responses[] = WebDavXml::fileResponse(
                    $hrefBase . '/' . $file['name'],
                    $file['name'],
                    $file['mtime'],
                    $file['contentType'],
                    $file['hash'],
                    $file['size'] ?? null
                );
            }
        }

        return $responses;
    }

    private function propfindFavFlashFile(array $parsed): ?array
    {
        // Asset file within virtual assets/ directory
        if (!empty($parsed['flashAsset'])) {
            $asset = WebDavFilesystem::resolveRuffleAsset($parsed['flashAsset']);
            if ($asset === null) {
                return null;
            }
            $hrefBase = $this->basePath . '/Favorites/Flash/' . implode('/', $parsed['flashPath']) . '/assets';
            $href = $hrefBase . '/' . $asset['name'];
            return [WebDavXml::fileResponse($href, $asset['name'], $asset['mtime'], $asset['contentType'], $asset['hash'], $asset['size'])];
        }

        $file = $this->resolveFavFlashFile($parsed);
        if ($file === null) {
            return null;
        }
        $hrefBase = $this->basePath . '/Favorites/Flash' . (empty($parsed['flashPath']) ? '' : '/' . implode('/', $parsed['flashPath']));
        $href = $hrefBase . '/' . $parsed['filename'];
        return [WebDavXml::fileResponse($href, $parsed['filename'], $file['mtime'], $file['contentType'], $file['hash'], $file['size'] ?? null)];
    }

    private function resolveFavVrFile(array $parsed): ?array
    {
        $listing = $this->fs->listFavoriteVr($parsed['vrPath']);
        foreach ($listing['files'] as $file) {
            if ($file['name'] === $parsed['filename']) {
                return $file;
            }
        }
        return null;
    }

    private function resolveFavFlashFile(array $parsed): ?array
    {
        $listing = $this->fs->listFavoriteFlash($parsed['flashPath']);
        foreach ($listing['files'] as $file) {
            if ($file['name'] === $parsed['filename']) {
                return $file;
            }
        }
        return null;
    }

    // ---- File serving ----

    private function serveFile(string $path, bool $includeBody): void
    {
        $parsed = $this->parsePath($path);

        // Directory GET - simple HTML
        if (in_array($parsed['level'], ['root', 'folder', 'flash_root', 'flash_category', 'flash_assets_dir', 'vr_folder', 'fav_vr_folder', 'fav_flash_folder'])) {
            header('Content-Type: text/html; charset=utf-8');
            http_response_code(200);
            if ($includeBody) {
                echo '<html><body><h1>Privuma WebDAV</h1><p>Use a WebDAV client to browse.</p></body></html>';
            }
            return;
        }

        if ($parsed['level'] === 'file') {
            $this->serveMediaFile($parsed, $includeBody);
        } elseif ($parsed['level'] === 'flash_file' || $parsed['level'] === 'fav_flash_file') {
            $this->serveFlashFile($parsed, $includeBody);
        } elseif ($parsed['level'] === 'vr_file' || $parsed['level'] === 'fav_vr_file') {
            $this->serveVrFile($parsed, $includeBody);
        } else {
            http_response_code(404);
            echo 'Not found';
        }
    }

    private function serveMediaFile(array $parsed, bool $includeBody): void
    {
        $file = $this->fs->resolveFile($parsed['section'], $parsed['folderPath'], $parsed['filename']);
        if ($file === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        // JSON sidecar
        if (isset($file['type']) && $file['type'] === 'sidecar') {
            $json = json_encode($file['sidecar'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Length: ' . strlen($json));
            header('ETag: "' . md5($json) . '"');
            if ($file['mtime']) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $file['mtime']) . ' GMT');
            }
            http_response_code(200);
            if ($includeBody) {
                echo $json;
            }
            return;
        }

        // Media file - proxy via X-Accel-Redirect
        $urlSection = $file['sourceSection'] ?? $parsed['section'];
        $url = $this->fs->getCloudUrl($file['hash'], $file['ext'], $urlSection);
        header('Content-Type: ' . $file['contentType']);
        header('ETag: "' . $file['hash'] . '"');
        if ($file['mtime']) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $file['mtime']) . ' GMT');
        }
        if (!empty($file['size'])) {
            header('Content-Length: ' . $file['size']);
        }
        privuma::accel($url);
    }

    private function serveFlashFile(array $parsed, bool $includeBody): void
    {
        // Serve Ruffle assets from virtual assets/ directory
        if (!empty($parsed['flashAsset'])) {
            $asset = WebDavFilesystem::resolveRuffleAsset($parsed['flashAsset']);
            if ($asset === null) {
                http_response_code(404);
                echo 'Not found';
                return;
            }
            header('Content-Type: ' . $asset['contentType']);
            header('Content-Length: ' . $asset['size']);
            header('ETag: "' . $asset['hash'] . '"');
            header('Cache-Control: public, max-age=31536000, immutable');
            http_response_code(200);
            if ($includeBody) {
                readfile($asset['ruffleAssetPath']);
            }
            return;
        }

        if ($parsed['level'] === 'fav_flash_file') {
            $file = $this->resolveFavFlashFile($parsed);
        } else {
            $file = $this->fs->resolveFlashFile($parsed['rawCategory'], $parsed['filename']);
        }
        if ($file === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        // Flash HTML sidecar (Ruffle player page)
        if (!empty($file['flashHtmlSidecar'])) {
            $html = WebDavFilesystem::generateRufflePlayerHtml($file['flashSwfName']);
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Length: ' . strlen($html));
            header('ETag: "' . $file['hash'] . '"');
            http_response_code(200);
            if ($includeBody) {
                echo $html;
            }
            return;
        }

        if (empty($file['flashUrl'])) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        header('Content-Type: ' . $file['contentType']);
        header('ETag: "' . $file['hash'] . '"');
        if (isset($file['size'])) {
            header('Content-Length: ' . $file['size']);
        }
        privuma::accel($file['flashUrl']);
    }

    private function serveVrFile(array $parsed, bool $includeBody): void
    {
        if ($parsed['level'] === 'fav_vr_file') {
            $file = $this->resolveFavVrFile($parsed);
        } else {
            $file = $this->fs->resolveVrFile($parsed['vrPath'], $parsed['filename']);
        }
        if ($file === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        // VR JSON sidecar (metadata)
        if (!empty($file['vrJsonSidecar'])) {
            $json = json_encode($file['sidecar'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Length: ' . strlen($json));
            header('ETag: "' . $file['hash'] . '"');
            if ($file['mtime']) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $file['mtime']) . ' GMT');
            }
            http_response_code(200);
            if ($includeBody) {
                echo $json;
            }
            return;
        }

        // VR HTML sidecar (player page)
        if (!empty($file['vrSidecar'])) {
            $html = WebDavFilesystem::generateVrPlayerHtml($file['vrVideoName'], $file['vrProjection']);
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Length: ' . strlen($html));
            header('ETag: "' . $file['hash'] . '"');
            http_response_code(200);
            if ($includeBody) {
                echo $html;
            }
            return;
        }

        if (empty($file['vrPath'])) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $url = $this->fs->getVrCloudUrl($file['vrPath']);
        header('Content-Type: ' . $file['contentType']);
        header('ETag: "' . $file['hash'] . '"');
        if ($file['mtime']) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $file['mtime']) . ' GMT');
        }
        if (isset($file['size'])) {
            header('Content-Length: ' . $file['size']);
        }
        privuma::accel($url);
    }

    // ---- Favorites: COPY (add) and DELETE (remove) ----

    /**
     * COPY a file to Favorites/ to favorite it.
     * Source: the request URI (a file in Albums/ or Unfiltered/).
     * Destination header: must target Favorites/ (ignored beyond validation).
     */
    private function handleCopy(string $path): void
    {
        $destination = $_SERVER['HTTP_DESTINATION'] ?? '';
        if ($destination === '') {
            http_response_code(400);
            echo 'Missing Destination header';
            return;
        }

        // Parse destination to verify it targets Favorites
        $destPath = parse_url($destination, PHP_URL_PATH);
        if ($destPath === false || $destPath === null) {
            http_response_code(400);
            echo 'Invalid Destination header';
            return;
        }
        // Strip basePath prefix from destination
        if (strpos($destPath, $this->basePath) === 0) {
            $destPath = substr($destPath, strlen($this->basePath));
        }
        $destPath = '/' . trim($destPath, '/');
        $destParts = array_values(array_filter(explode('/', $destPath), fn ($p) => $p !== ''));
        $destParts = array_map('urldecode', $destParts);

        if (empty($destParts) || $destParts[0] !== 'Favorites') {
            http_response_code(403);
            echo 'COPY is only supported with Favorites/ as the destination';
            return;
        }

        // Resolve the source file
        $parsed = $this->parsePath($path);

        // VR file → toggle VR favorite
        if ($parsed['level'] === 'vr_file') {
            $file = $this->fs->resolveVrFile($parsed['vrPath'], $parsed['filename']);
            if ($file === null || !isset($file['hash'])) {
                http_response_code(404);
                echo 'VR file not found';
                return;
            }
            $this->fs->toggleVrFavorite($file['hash']);
            $this->fs->clearFavoritesCache();
            http_response_code(201);
            echo 'Added to VR Favorites';
            return;
        }

        // Flash file → toggle Flash favorite
        if ($parsed['level'] === 'flash_file' && empty($parsed['flashAsset'])) {
            $file = $this->fs->resolveFlashFile($parsed['rawCategory'], $parsed['filename']);
            if ($file === null || !isset($file['hash']) || !empty($file['flashHtmlSidecar'])) {
                http_response_code(404);
                echo 'Flash file not found';
                return;
            }
            $this->fs->toggleFlashFavorite($file['hash']);
            $this->fs->clearFavoritesCache();
            http_response_code(201);
            echo 'Added to Flash Favorites';
            return;
        }

        // Regular media file
        if ($parsed['level'] !== 'file') {
            http_response_code(400);
            echo 'Source must be a file';
            return;
        }

        $file = $this->fs->resolveFile($parsed['section'], $parsed['folderPath'], $parsed['filename']);
        if ($file === null || !isset($file['hash'])) {
            http_response_code(404);
            echo 'Source file not found';
            return;
        }

        $media = mediaFile::load($file['hash']);
        if ($media === null) {
            http_response_code(404);
            echo 'Media not found in database';
            return;
        }

        if ($media->favorited()) {
            http_response_code(204);
            return;
        }

        $media->favorite();
        $this->fs->clearFavoritesCache();
        http_response_code(201);
        echo 'Added to Favorites';
    }

    /**
     * DELETE a file from Favorites/ to unfavorite it.
     * Only works on files within the Favorites section.
     */
    private function handleDelete(string $path): void
    {
        $parsed = $this->parsePath($path);

        // Delete from VR Favorites
        if ($parsed['level'] === 'fav_vr_file') {
            $file = $this->resolveFavVrFile($parsed);
            if ($file === null || !isset($file['hash'])) {
                http_response_code(404);
                echo 'File not found';
                return;
            }
            if (!empty($file['vrJsonSidecar']) || !empty($file['vrSidecar'])) {
                http_response_code(403);
                echo 'Cannot delete sidecar files';
                return;
            }
            $this->fs->toggleVrFavorite($file['hash']);
            $this->fs->clearFavoritesCache();
            http_response_code(204);
            return;
        }

        // Delete from Flash Favorites
        if ($parsed['level'] === 'fav_flash_file' && empty($parsed['flashAsset'])) {
            $file = $this->resolveFavFlashFile($parsed);
            if ($file === null || !isset($file['hash']) || !empty($file['flashHtmlSidecar'])) {
                http_response_code(404);
                echo 'File not found';
                return;
            }
            $this->fs->toggleFlashFavorite($file['hash']);
            $this->fs->clearFavoritesCache();
            http_response_code(204);
            return;
        }

        // Regular favorites
        if ($parsed['level'] !== 'file' || ($parsed['section'] ?? '') !== 'Favorites') {
            http_response_code(403);
            echo 'DELETE is only supported on files within Favorites/';
            return;
        }

        $file = $this->fs->resolveFile('Favorites', $parsed['folderPath'], $parsed['filename']);
        if ($file === null || !isset($file['hash'])) {
            http_response_code(404);
            echo 'File not found';
            return;
        }

        // Skip sidecar files
        if (isset($file['type']) && $file['type'] === 'sidecar') {
            http_response_code(403);
            echo 'Cannot delete sidecar files';
            return;
        }

        $media = mediaFile::load($file['hash']);
        if ($media === null) {
            http_response_code(404);
            echo 'Media not found in database';
            return;
        }

        if (!$media->favorited()) {
            // Not favorited - already removed
            http_response_code(204);
            return;
        }

        $media->favorite(); // toggles off
        $this->fs->clearFavoritesCache();
        http_response_code(204);
    }

    // ---- Path parsing ----

    private function parsePath(string $path): array
    {
        $path = '/' . trim($path, '/');
        $parts = array_values(array_filter(explode('/', $path), function ($p) {
            return $p !== '';
        }));
        $parts = array_map('urldecode', $parts);

        if (count($parts) === 0) {
            return ['level' => 'root'];
        }

        $section = $parts[0];

        // Flash section
        if ($section === 'Flash') {
            return $this->parseFlashPath(array_slice($parts, 1));
        }

        // VR section
        if ($section === 'VR') {
            return $this->parseVrPath(array_slice($parts, 1));
        }

        // Media sections (Albums, Favorites, Unfiltered)
        if (!in_array($section, self::$mediaSections)) {
            return ['level' => 'unknown'];
        }

        if (count($parts) === 1) {
            return ['level' => 'folder', 'section' => $section, 'folderPath' => []];
        }

        $subParts = array_slice($parts, 1);

        // Favorites/VR/... and Favorites/Flash/... are special subsections
        if ($section === 'Favorites' && count($subParts) >= 1) {
            if ($subParts[0] === 'VR' && $this->fs->hasFavoriteVr()) {
                return $this->parseFavVrPath(array_slice($subParts, 1));
            }
            if ($subParts[0] === 'Flash' && $this->fs->hasFavoriteFlash()) {
                return $this->parseFavFlashPath(array_slice($subParts, 1));
            }
        }

        // Try treating all subParts as a folder path
        if ($this->fs->resolveFolder($section, $subParts) !== null) {
            return ['level' => 'folder', 'section' => $section, 'folderPath' => $subParts];
        }

        // Otherwise, last part is filename
        if (count($subParts) >= 2) {
            $folderPath = array_slice($subParts, 0, -1);
            $filename = end($subParts);
            return ['level' => 'file', 'section' => $section, 'folderPath' => $folderPath, 'filename' => $filename];
        }

        return ['level' => 'file', 'section' => $section, 'folderPath' => [], 'filename' => $subParts[0]];
    }

    private function parseFlashPath(array $parts): array
    {
        if (count($parts) === 0) {
            return ['level' => 'flash_root'];
        }

        // Resolve category name
        $categoryName = $parts[0];
        $categories = $this->fs->listFlashCategories();
        $rawCategory = null;
        foreach ($categories as $cat) {
            if ($cat['name'] === $categoryName) {
                $rawCategory = $cat['rawName'];
                break;
            }
        }
        if ($rawCategory === null) {
            return ['level' => 'unknown'];
        }

        if (count($parts) === 1) {
            return ['level' => 'flash_category', 'category' => $categoryName, 'rawCategory' => $rawCategory];
        }

        // Virtual assets/ directory for Ruffle
        if ($parts[1] === 'assets') {
            if (count($parts) === 2) {
                return ['level' => 'flash_assets_dir', 'category' => $categoryName, 'rawCategory' => $rawCategory];
            }
            return ['level' => 'flash_file', 'category' => $categoryName, 'rawCategory' => $rawCategory, 'filename' => $parts[2], 'flashAsset' => $parts[2]];
        }

        $filename = $parts[1];
        return ['level' => 'flash_file', 'category' => $categoryName, 'rawCategory' => $rawCategory, 'filename' => $filename];
    }

    private function parseVrPath(array $parts): array
    {
        if (count($parts) === 0) {
            return ['level' => 'vr_folder', 'vrPath' => []];
        }

        // Try all parts as folder
        if ($this->fs->resolveVrFolder($parts) !== null) {
            return ['level' => 'vr_folder', 'vrPath' => $parts];
        }

        // Otherwise last part is filename
        if (count($parts) >= 2) {
            $folderPath = array_slice($parts, 0, -1);
            $filename = end($parts);
            return ['level' => 'vr_file', 'vrPath' => $folderPath, 'filename' => $filename];
        }

        return ['level' => 'vr_file', 'vrPath' => [], 'filename' => $parts[0]];
    }

    private function parseFavVrPath(array $parts): array
    {
        if (count($parts) === 0) {
            return ['level' => 'fav_vr_folder', 'vrPath' => []];
        }

        // Check if all parts form a folder with favorited VR content
        $listing = $this->fs->listFavoriteVr($parts);
        if (!empty($listing['dirs']) || !empty($listing['files'])) {
            return ['level' => 'fav_vr_folder', 'vrPath' => $parts];
        }

        // Otherwise last part is filename
        if (count($parts) >= 2) {
            $folderPath = array_slice($parts, 0, -1);
            $filename = end($parts);
            return ['level' => 'fav_vr_file', 'vrPath' => $folderPath, 'filename' => $filename];
        }

        return ['level' => 'fav_vr_file', 'vrPath' => [], 'filename' => $parts[0]];
    }

    private function parseFavFlashPath(array $parts): array
    {
        if (count($parts) === 0) {
            return ['level' => 'fav_flash_folder', 'flashPath' => []];
        }

        // Virtual assets/ directory for Ruffle within a category
        if (count($parts) >= 2 && end($parts) === 'assets') {
            $folderPath = array_slice($parts, 0, -1);
            return ['level' => 'flash_assets_dir', 'flashPath' => $folderPath];
        }
        if (count($parts) >= 3 && $parts[count($parts) - 2] === 'assets') {
            $folderPath = array_slice($parts, 0, -2);
            $assetName = end($parts);
            return ['level' => 'fav_flash_file', 'flashPath' => $folderPath, 'filename' => $assetName, 'flashAsset' => $assetName];
        }

        // Check if this is a category folder
        $listing = $this->fs->listFavoriteFlash($parts);
        if (!empty($listing['dirs']) || !empty($listing['files'])) {
            return ['level' => 'fav_flash_folder', 'flashPath' => $parts];
        }

        // Otherwise last part is filename
        if (count($parts) >= 2) {
            $folderPath = array_slice($parts, 0, -1);
            $filename = end($parts);
            return ['level' => 'fav_flash_file', 'flashPath' => $folderPath, 'filename' => $filename];
        }

        return ['level' => 'fav_flash_file', 'flashPath' => [], 'filename' => $parts[0]];
    }

    private function buildHref(string $section, array $folderPath): string
    {
        $href = $this->basePath . '/' . $section;
        foreach ($folderPath as $seg) {
            $href .= '/' . $seg;
        }
        return $href;
    }
}
