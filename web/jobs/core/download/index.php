<?php
ini_set('memory_limit', '4G');
use privuma\privuma;
use privuma\helpers\mediaFile;
use privuma\helpers\tokenizer;
use privuma\helpers\cloudFS;
use privuma\helpers\databaseBuilder;

require_once __DIR__ .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  '..' .
  DIRECTORY_SEPARATOR .
  'app' .
  DIRECTORY_SEPARATOR .
  'privuma.php';

// Parse CLI arguments
$options = getopt('', ['mode:', 'skip-download::', 'split-albums::']);
$mode = $options['mode'] ?? 'both'; // 'filtered', 'unfiltered', or 'both'
$skipDownload = isset($options['skip-download']);
$splitAlbums = isset($options['split-albums']) || privuma::getEnv('DOWNLOAD_SPLIT_ALBUMS');

echo PHP_EOL . "Running in mode: $mode";
if ($skipDownload) {
    echo PHP_EOL . 'Skipping media download queue';
}
if ($splitAlbums) {
    echo PHP_EOL . 'Splitting albums into separate JSON files';
}

// If mode is 'both', run filtered and unfiltered sequentially
if ($mode === 'both') {
    echo PHP_EOL . PHP_EOL . '=== Running FILTERED mode ===';
    $filteredArgs = [];
    if ($skipDownload) {
        $filteredArgs[] = '--skip-download';
    }
    if ($splitAlbums) {
        $filteredArgs[] = '--split-albums';
    }
    $filteredArgs[] = '--mode=filtered';

    $cmd = 'php ' . __FILE__ . ' ' . implode(' ', $filteredArgs);
    echo PHP_EOL . "Executing: $cmd";
    passthru($cmd, $filteredResult);

    echo PHP_EOL . PHP_EOL . '=== Running UNFILTERED mode ===';
    $unfilteredArgs = [];
    if ($skipDownload) {
        $unfilteredArgs[] = '--skip-download';
    }
    if ($splitAlbums) {
        $unfilteredArgs[] = '--split-albums';
    }
    $unfilteredArgs[] = '--mode=unfiltered';

    $cmd = 'php ' . __FILE__ . ' ' . implode(' ', $unfilteredArgs);
    echo PHP_EOL . "Executing: $cmd";
    passthru($cmd, $unfilteredResult);

    echo PHP_EOL . PHP_EOL . '=== Both modes completed ===';
    echo PHP_EOL . "Filtered result code: $filteredResult";
    echo PHP_EOL . "Unfiltered result code: $unfilteredResult";
    exit(max($filteredResult, $unfilteredResult));
}

$privuma = privuma::getInstance();
$tokenizer = new tokenizer();

// Determine which environment variables to use based on mode
if ($mode === 'unfiltered') {
    $downloadLocation = $privuma->getEnv('UNFILTERED_DOWNLOAD_LOCATION');
    $downloadLocationUnencrypted = $privuma->getEnv('UNFILTERED_DOWNLOAD_LOCATION_UNENCRYPTED');
    $prefix = 'un';
    $blockedCondition = 'blocked = 1';
} else {
    $downloadLocation = $privuma->getEnv('DOWNLOAD_LOCATION');
    $downloadLocationUnencrypted = $privuma->getEnv('DOWNLOAD_LOCATION_UNENCRYPTED');
    $prefix = 'pr';
    $blockedCondition = 'blocked = 0';
}

if (!$downloadLocation || !$downloadLocationUnencrypted) {
    echo PHP_EOL . "Missing required environment variables for mode: $mode";
    exit();
}

$conn = $privuma->getPDO();

$ops = new cloudFS($downloadLocation . $prefix . DIRECTORY_SEPARATOR, true, '/usr/bin/rclone', null, true);
$opsFavorites = new cloudFS($downloadLocation . 'fa' . DIRECTORY_SEPARATOR, true, '/usr/bin/rclone', null, true);
$opsNoEncodeNoPrefix = new cloudFS($downloadLocation, false, '/usr/bin/rclone', null, false);
$opsPlain = new cloudFS(
    $downloadLocationUnencrypted,
    false,
    '/usr/bin/rclone',
    null,
    false
);

echo PHP_EOL . 'Building list of media to download';
$stmt = $conn->prepare("SELECT filename, album, time, hash, url, thumbnail, duration, sound, score
FROM media
WHERE hash IS NOT NULL
AND hash != ''
AND hash != 'compressed'
AND (album = 'Favorites' OR $blockedCondition)
AND (dupe = 0 OR album = 'Favorites')
GROUP BY hash
ORDER BY time DESC");
$stmt->execute();
$dlData = $stmt->fetchAll();

echo PHP_EOL . 'Building web app payload of media';
// Get data for current mode
$stmt = $conn->prepare(
    "SELECT filename, album, dupe, time, hash, duration, sound, score, REGEXP_REPLACE(metadata, 'www\.[a-zA-Z0-9\_\.\/\:\-\?\=\&]*|(http|https|ftp):\/\/[a-zA-Z0-9\_\.\/\:\-\?\=\&]*', 'Link Removed') as metadata FROM (SELECT * FROM media WHERE (album = 'Favorites' OR $blockedCondition) AND hash IS NOT NULL AND hash != '' AND hash != 'compressed') t1 ORDER BY time DESC;"
);
$stmt->execute();
$data = str_replace('`', '', json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)));

// Get opposite mode data for favorites sync
$oppositeCondition = ($mode === 'filtered') ? 'blocked = 1' : 'blocked = 0';
$oppositestmt = $conn->prepare(
    "SELECT filename, album, dupe, time, hash, duration, sound, score, REGEXP_REPLACE(metadata, 'www\.[a-zA-Z0-9\_\.\/\:\-\?\=\&]*|(http|https|ftp):\/\/[a-zA-Z0-9\_\.\/\:\-\?\=\&]*', 'Link Removed') as metadata FROM (SELECT * FROM media WHERE (album = 'Favorites' OR $oppositeCondition) AND hash IS NOT NULL AND hash != '' AND hash != 'compressed') t1 ORDER BY time DESC;"
);
$oppositestmt->execute();
$oppositedata = str_replace('`', '', json_encode($oppositestmt->fetchAll(PDO::FETCH_ASSOC)));

$previouslyDownloadedMedia = array_flip(
    array_map(
        function ($item) { return trim($item, "\/"); },
        array_column(
            $ops->scandir('', true, true, null, false, true, true, true),
            'Name'
        )
    )
);

clearstatcache();
if (!file_exists(__DIR__ . '/restore_point.txt')) {
    $metaDataFiles = [];
    $dataset = json_decode($data, true);
    $array = databaseBuilder::buildDatabaseArray($dataset, $metaDataFiles);

    $oppositedataset = json_decode($oppositedata, true);
    $oppositearray = databaseBuilder::buildDatabaseArray($oppositedataset, $metaDataFiles);

    // Filter non-favorites for mobile data
    $mobiledata = databaseBuilder::encodeForJS(
        array_values(array_filter($array, function ($item) {
            return !in_array('Favorites', $item['albums']);
        }))
    );

    // Build favorites with complete comic albums support
    echo PHP_EOL . 'Building favorites with complete comic albums';
    $mergedArray = array_merge(array_values($array), array_values($oppositearray));

    // Get items that are actually favorited
    $favoritedItems = array_filter($mergedArray, function ($item) {
        return in_array('Favorites', $item['albums']);
    });

    // Get ALL albums for each favorited hash (the current query may miss some albums)
    echo PHP_EOL . 'Fetching all albums for favorited items';
    $favoritedHashes = array_column($favoritedItems, 'hash');
    if (count($favoritedHashes) > 0) {
        $placeholders = implode(',', array_fill(0, count($favoritedHashes), '?'));
        $allAlbumsStmt = $conn->prepare(
            "SELECT hash, album FROM media WHERE hash IN ($placeholders) AND hash IS NOT NULL AND hash != '' GROUP BY hash, album"
        );
        $allAlbumsStmt->execute($favoritedHashes);
        $allAlbumsData = $allAlbumsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build hash -> albums mapping
        $hashToAllAlbums = [];
        foreach ($allAlbumsData as $row) {
            if (!isset($hashToAllAlbums[$row['hash']])) {
                $hashToAllAlbums[$row['hash']] = [];
            }
            $hashToAllAlbums[$row['hash']][] = databaseBuilder::sanitizeLine($row['album']);
        }

        // Merge all albums into favorited items
        foreach ($favoritedItems as &$item) {
            if (isset($hashToAllAlbums[$item['hash']])) {
                $item['albums'] = array_values(array_unique(array_merge($item['albums'], $hashToAllAlbums[$item['hash']])));
                // Also update times array to match albums count
                while (count($item['times']) < count($item['albums'])) {
                    $item['times'][] = $item['times'][0] ?? date('Y-m-d H:i:s');
                }
            }
        }
        unset($item); // Break reference

        echo PHP_EOL . 'Updated albums for ' . count($favoritedItems) . ' favorited items';
    }

    // Find all comic albums that have at least one favorited page
    $favoritedComicAlbums = [];
    foreach ($favoritedItems as $item) {
        foreach ($item['albums'] as $album) {
            if (strpos($album, 'Comics---') === 0 || strpos($album, 'comics---') === 0) {
                $favoritedComicAlbums[$album] = true;
            }
        }
    }

    echo PHP_EOL . 'Found ' . count($favoritedComicAlbums) . ' comic albums with favorited pages';

    // Get ALL pages from favorited comic albums
    $completeComicPages = [];
    if (count($favoritedComicAlbums) > 0) {
        foreach (array_keys($favoritedComicAlbums) as $comicAlbum) {
            echo PHP_EOL . 'Including all pages from: ' . $comicAlbum;

            // Find all items from this comic album
            foreach ($mergedArray as $item) {
                if (in_array($comicAlbum, $item['albums'])) {
                    // Add to complete comic pages if not already favorited
                    if (!in_array('Favorites', $item['albums'])) {
                        $completeComicPages[$item['hash']] = $item;
                    }
                }
            }
        }
    }

    echo PHP_EOL . 'Added ' . count($completeComicPages) . ' additional comic pages for complete albums';

    // Combine favorited items with complete comic pages for fa folder
    $favoritesForDownload = array_merge(
        array_values($favoritedItems),
        array_values($completeComicPages)
    );

    // Remove duplicates by hash
    $favoritesForDownload = array_values(array_reduce($favoritesForDownload, function ($carry, $item) {
        $carry[$item['hash']] = $item;
        return $carry;
    }, []));

    echo PHP_EOL . 'Total items in favorites.json: ' . count($favoritesForDownload);
    echo PHP_EOL . '  - Actually favorited: ' . count($favoritedItems);
    echo PHP_EOL . '  - Comic pages (complete albums): ' . count($completeComicPages);

    $favorites = databaseBuilder::encodeForJS($favoritesForDownload);

    // Save full data for album splitting before unsetting (includes favorites)
    $fullDataForAlbums = $splitAlbums ? array_values($array) : null;

    unset($array);
    unset($oppositearray);
    unset($oppositedata);
    unset($mergedArray);
    unset($favoritedItems);
    unset($completeComicPages);

    echo PHP_EOL . 'All Database Lookup Operations have been completed.';

    // Download viewer HTML
    $viewerHTML = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'viewer' . DIRECTORY_SEPARATOR . 'index.html');
    $viewerHTML = str_replace('{{ENDPOINT}}', privuma::getEnv('ENDPOINT'), $viewerHTML);

    echo PHP_EOL . 'Downloading Offline Web App Viewer HTML File';
    $opsPlain->file_put_contents('index.html', $viewerHTML);
    $opsNoEncodeNoPrefix->file_put_contents('index.html', $viewerHTML);
    $opsNoEncodeNoPrefix->file_put_contents('fa/index.html', $viewerHTML);
    if ($mode === 'unfiltered') {
        $opsNoEncodeNoPrefix->file_put_contents('un/index.html', $viewerHTML);
    }
    unset($viewerHTML);

    echo PHP_EOL . 'Downloading encrypted database offline website payload';

    if ($splitAlbums && $fullDataForAlbums) {
        echo PHP_EOL . 'Splitting database into per-album files';

        // Create local temp directory for batch writing (much faster than individual cloud uploads)
        $tempDir = sys_get_temp_dir() . '/album_split_' . $prefix . '_' . getmypid();
        $tempAlbumsDir = $tempDir . '/albums';
        if (!file_exists($tempAlbumsDir)) {
            mkdir($tempAlbumsDir, 0755, true);
        }

        // Generate combined albums index (replaces separate albums_list.json and albums_index.json)
        $albumsIndex = databaseBuilder::buildAlbumsIndex($fullDataForAlbums);
        $albumsJSON = databaseBuilder::encodeForJS($albumsIndex);

        echo PHP_EOL . 'Writing albums.json (' . count($albumsIndex) . ' albums) locally';
        file_put_contents($tempDir . '/albums.json', $albumsJSON);

        // Split into individual album files - write to local temp dir (fast)
        $albumData = databaseBuilder::splitByAlbums($fullDataForAlbums);
        echo PHP_EOL . 'Writing ' . count($albumData) . ' individual album files locally';

        foreach ($albumData as $albumName => $items) {
            $albumHash = substr(md5($albumName), 0, 8);
            $albumJSON = databaseBuilder::encodeForJS($items);
            file_put_contents($tempAlbumsDir . '/album_' . $albumHash . '.json', $albumJSON);
        }

        echo PHP_EOL . 'Local write complete, uploading to cloud storage at ' . $prefix . '/';

        // Upload albums.json (single index file)
        echo PHP_EOL . 'Uploading albums.json';
        $opsNoEncodeNoPrefix->file_put_contents($prefix . '/albums.json', $albumsJSON);

        // Sync albums directory with parallel transfers (removes orphan album files)
        echo PHP_EOL . 'Syncing ' . count($albumData) . ' album files to ' . $prefix . '/albums/';
        $syncResult = $opsNoEncodeNoPrefix->syncDir($tempAlbumsDir, $prefix . '/albums', 16, 16, true);

        if (!$syncResult) {
            echo PHP_EOL . 'WARNING: syncDir returned failure';
        }

        // Cleanup temp directory
        echo PHP_EOL . 'Cleaning up temp directory';
        array_map('unlink', glob($tempAlbumsDir . '/*.json'));
        @rmdir($tempAlbumsDir);
        @rmdir($tempDir);

        echo PHP_EOL . 'Album split complete';
        unset($fullDataForAlbums);
        unset($albumData);
    } else {
        echo PHP_EOL . 'Downloading Mobile Dataset';
        $mobiledata = "const encrypted_data = '" . $mobiledata . "';";
        $ops->file_put_contents('encrypted_mobile_data.js', $mobiledata);
    }

    unset($mobiledata);

    // Save favorites
    $opsNoEncodeNoPrefix->file_put_contents('fa/favorites.json', $favorites);

    // Sync favorites between filtered/unfiltered
    echo PHP_EOL . 'Scanning favorites';
    $favoritesJson = json_decode($favorites, true);
    $existingFavoritesPaths = array_flip(
        array_map(
            function ($item) { return trim($item, "\/"); },
            array_column(
                $opsFavorites->scandir('', true, true, null, false, true, true, true),
                'Path'
            )
        )
    );
    $existingFavorites = array_map(function ($item) {
        return basename($item);
    }, $existingFavoritesPaths);

    $newFavorites = array_filter($favoritesJson, function ($item) use (
        $existingFavorites,
        $previouslyDownloadedMedia
    ) {
        $filename = str_replace(
            ['.mpg', '.mod', '.mmv', '.tod', '.wmv', '.asf', '.avi', '.divx', '.mov', '.m4v', '.3gp', '.3g2', '.m2t', '.m2ts', '.mts', '.mkv'],
            '.mp4',
            $item['filename']
        );
        $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
        $thumbnailPreserve = $item['hash'] . '.jpg';
        $isAnimated = (str_contains($preserve, '.webm') || str_contains($preserve, '.mp4') || str_contains($preserve, '.gif'));
        $newFavorite = !array_key_exists($preserve, $existingFavorites) || ($isAnimated && !array_key_exists($thumbnailPreserve, $existingFavorites));
        $fileExists = array_key_exists($preserve, $previouslyDownloadedMedia) && (!$isAnimated || array_key_exists($thumbnailPreserve, $previouslyDownloadedMedia));
        return $newFavorite && $fileExists;
    });

    echo PHP_EOL . 'Found ' . count($newFavorites) . ' New Favorites';

    $favoritesNames = array_map(function ($item) {
        $filename = str_replace(
            ['.mpg', '.mod', '.mmv', '.tod', '.wmv', '.asf', '.avi', '.divx', '.mov', '.m4v', '.3gp', '.3g2', '.m2t', '.m2ts', '.mts', '.mkv'],
            '.mp4',
            $item['filename']
        );
        $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
        $thumbnailPreserve = $item['hash'] . '.jpg';
        return ['name' => $preserve, 'thumbnail' => $thumbnailPreserve];
    }, $favoritesJson);

    $removedFavorites = array_filter($existingFavoritesPaths, function ($name) use ($favoritesNames) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        return !array_key_exists(basename($name), array_column($favoritesNames, 'name'))
            && !array_key_exists(basename($name), array_column($favoritesNames, 'thumbnail'))
            && count(explode(DIRECTORY_SEPARATOR, $name)) > 1
            && in_array(strtolower($ext), ['webm', 'mp4', 'gif', 'jpg', 'png']);
    });

    echo PHP_EOL . 'Found ' . count($removedFavorites) . ' Removed Favorites';

    echo PHP_EOL . 'Syncing favorites';
    foreach ($newFavorites as $favorite) {
        $ext = pathinfo($favorite['filename'], PATHINFO_EXTENSION);
        $src = cloudFS::canonicalize($prefix . DIRECTORY_SEPARATOR . cloudFS::encode($favorite['hash'] . '.' . $ext, true));
        $dst = cloudFS::canonicalize('fa' . DIRECTORY_SEPARATOR . cloudFS::encode($favorite['hash'] . '.' . $ext, true));
        echo PHP_EOL . 'Moving favorite: ' . $src . '  to: ' . $dst;
        $opsNoEncodeNoPrefix->rename($src, $dst, true);

        if (in_array(strtolower($ext), ['webm', 'mp4', 'gif'])) {
            $src = cloudFS::canonicalize($prefix . DIRECTORY_SEPARATOR . cloudFS::encode($favorite['hash'] . '.jpg', true));
            $dst = cloudFS::canonicalize('fa' . DIRECTORY_SEPARATOR . cloudFS::encode($favorite['hash'] . '.jpg', true));
            echo PHP_EOL . 'Moving favorite thumbnail: ' . $src . '  to: ' . $dst;
            $opsNoEncodeNoPrefix->rename($src, $dst, true);
        }
    }

    foreach ($removedFavorites as $name) {
        $src = cloudFS::canonicalize('fa' . DIRECTORY_SEPARATOR . $name);
        $dst = cloudFS::canonicalize($prefix . DIRECTORY_SEPARATOR . $name);
        echo PHP_EOL . 'Moving de-favorited media: ' . $src . '  to: ' . $dst;
        $opsNoEncodeNoPrefix->rename($src, $dst, true);
    }

    echo PHP_EOL . 'Downloading Mobile MetaData Stores (' . count($metaDataFiles) . ' files)';

    // Write meta files to local temp directory first (fast)
    $metaTempDir = sys_get_temp_dir() . '/meta_split_' . getmypid();
    $metaTempDirPrefix = $metaTempDir . '/' . $prefix . '/meta';
    $metaTempDirFa = $metaTempDir . '/fa/meta';

    if (!file_exists($metaTempDirPrefix)) {
        mkdir($metaTempDirPrefix, 0755, true);
    }
    if (!file_exists($metaTempDirFa)) {
        mkdir($metaTempDirFa, 0755, true);
    }

    foreach ($metaDataFiles as $metaPrefix => $item) {
        file_put_contents($metaTempDirPrefix . '/' . $metaPrefix . '.json', json_encode($item));
        file_put_contents($metaTempDirFa . '/' . $metaPrefix . '.json', json_encode($item));
    }

    // Sync meta directories with parallel transfers
    echo PHP_EOL . 'Syncing meta files to ' . $prefix . '/meta/';
    $opsNoEncodeNoPrefix->syncDir($metaTempDirPrefix, $prefix . '/meta', 16, 16, true);

    echo PHP_EOL . 'Syncing meta files to fa/meta/';
    $opsNoEncodeNoPrefix->syncDir($metaTempDirFa, 'fa/meta', 16, 16, true);

    // Cleanup temp directory
    echo PHP_EOL . 'Cleaning up meta temp directory';
    array_map('unlink', glob($metaTempDirPrefix . '/*.json'));
    array_map('unlink', glob($metaTempDirFa . '/*.json'));
    @rmdir($metaTempDirPrefix);
    @rmdir($metaTempDirFa);
    @rmdir($metaTempDir . '/' . $prefix);
    @rmdir($metaTempDir . '/fa');
    @rmdir($metaTempDir);

    // Sync VR/Flash favorites from cache to cloud storage
    echo PHP_EOL . 'Syncing VR/Flash favorites';
    $cacheDir = privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'cache';

    // VR favorites - sync JSON and media files
    $vrFavoritesFile = $cacheDir . DIRECTORY_SEPARATOR . 'favorites_vr.json';
    if (file_exists($vrFavoritesFile)) {
        $vrFavoriteHashes = json_decode(file_get_contents($vrFavoritesFile), true) ?? [];
        echo PHP_EOL . 'Processing VR favorites: ' . count($vrFavoriteHashes) . ' hashes';

        // Helper function to encode path segments with base64 (matches JS encodePath)
        $encodePathForHash = function ($path) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $pathWithoutExt = substr($path, 0, -strlen($ext) - 1);
            $parts = explode('/', $pathWithoutExt);
            $encodedParts = array_map(function ($part) {
                return base64_encode($part);
            }, $parts);
            return implode('/', $encodedParts) . '.' . $ext;
        };

        // Get list of VR files and build hash-to-full-object mapping
        $vrFiles = $opsNoEncodeNoPrefix->scandir('vr', true, true, null, false, true, true, true);
        $vrHashToData = [];
        if ($vrFiles !== false) {
            foreach ($vrFiles as $vrFile) {
                if (isset($vrFile['MimeType']) && $vrFile['MimeType'] === 'video/mp4') {
                    // Hash is computed as md5("vr/" + encodedPath) where encodedPath uses base64 segments
                    $encodedPath = $encodePathForHash($vrFile['Path']);
                    $hash = md5('vr/' . $encodedPath);
                    $dirname = dirname($vrFile['Path']);
                    $vrHashToData[$hash] = [
                        'hash' => $hash,
                        'filename' => 'vr/' . $encodedPath,
                        'album' => 'VR---' . ($dirname === '.' ? 'Root' : $dirname),
                        'time' => isset($vrFile['ModTime']) ? explode('.', str_replace('T', ' ', $vrFile['ModTime']))[0] : date('Y-m-d H:i:s'),
                        'vr' => 1,
                        'flash' => 0,
                        'metadata' => '',
                        'duration' => $vrFile['Duration'] ?? 0,
                        'sound' => $vrFile['Sound'] ?? -91,
                        'score' => $vrFile['score'] ?? 0,
                        'path' => $vrFile['Path']
                    ];
                }
            }
        }

        // Build full media objects for favorited VR items
        $vrFavoriteHashesFlipped = array_flip($vrFavoriteHashes);
        $vrFavoritesFullData = [];
        foreach ($vrHashToData as $hash => $data) {
            if (isset($vrFavoriteHashesFlipped[$hash])) {
                $vrFavoritesFullData[] = $data;

                // Copy to fa/vr/
                $src = 'vr/' . $data['path'];
                $dst = 'fa/vr/' . $data['path'];
                echo PHP_EOL . 'Copying VR favorite: ' . $src . ' to ' . $dst;
                $opsNoEncodeNoPrefix->copy($src, $dst, true, true);
            }
        }

        // Save full VR favorites data (not just hashes)
        $vrFavoritesFullJson = json_encode($vrFavoritesFullData);
        $opsNoEncodeNoPrefix->file_put_contents('fa/favorites_vr.json', $vrFavoritesFullJson);
        $opsNoEncodeNoPrefix->file_put_contents($prefix . '/favorites_vr.json', $vrFavoritesFullJson);
        echo PHP_EOL . 'Synced VR favorites JSON with full data: ' . count($vrFavoritesFullData) . ' items';

        // Clean up removed VR favorites from fa/vr/ (keep originals in vr/ intact)
        echo PHP_EOL . 'Checking for removed VR favorites to clean up from fa/vr/';
        $faVrFiles = $opsNoEncodeNoPrefix->scandir('fa/vr', true, true, null, false, true, true, true);
        if ($faVrFiles !== false) {
            foreach ($faVrFiles as $faVrFile) {
                if (isset($faVrFile['MimeType']) && $faVrFile['MimeType'] === 'video/mp4') {
                    // Compute hash the same way as originals (base64 encoded path segments)
                    $encodedPath = $encodePathForHash($faVrFile['Path']);
                    $hash = md5('vr/' . $encodedPath);

                    // If not in current favorites, delete from fa/vr/ only
                    if (!isset($vrFavoriteHashesFlipped[$hash])) {
                        $fileToDelete = 'fa/vr/' . $faVrFile['Path'];
                        echo PHP_EOL . 'Removing de-favorited VR file: ' . $fileToDelete;
                        $opsNoEncodeNoPrefix->unlink($fileToDelete);
                    }
                }
            }
        }
    }

    // Flash favorites - sync JSON and media files
    $flashFavoritesFile = $cacheDir . DIRECTORY_SEPARATOR . 'favorites_flash.json';
    if (file_exists($flashFavoritesFile)) {
        $flashFavoriteHashes = json_decode(file_get_contents($flashFavoritesFile), true) ?? [];
        echo PHP_EOL . 'Processing Flash favorites: ' . count($flashFavoriteHashes) . ' hashes';

        // Get flash index to map hashes to full objects
        $flashIndexPath = $cacheDir . DIRECTORY_SEPARATOR . 'flash_index.json';
        if (!file_exists($flashIndexPath)) {
            // Try to fetch from remote
            $flashIndex = $opsNoEncodeNoPrefix->file_get_contents('flash/index.json');
            if ($flashIndex) {
                $flashIndex = base64_decode($flashIndex);
            }
        } else {
            $flashIndex = file_get_contents($flashIndexPath);
        }

        // Build hash-to-full-object mapping for flash files
        $flashHashToData = [];
        $count = 0;
        if ($flashIndex) {
            $flashData = json_decode($flashIndex, true) ?? [];
            foreach ($flashData as $album => $items) {
                $count++;
                foreach ($items as $item) {
                    if (isset($item['hash'])) {
                        $flashHashToData[$item['hash']] = [
                            'hash' => $item['hash'],
                            'filename' => 'flash/' . base64_encode($album) . '/' . $item['url'],
                            'album' => 'Flash---' . $album,
                            'time' => date('Y-m-d H:i:s', strtotime("-{$count} days")),
                            'vr' => 0,
                            'flash' => 1,
                            'metadata' => 'Title: ' . ($item['title'] ?? '') . '\n\nTags: ' . implode(',', $item['tags']['general'] ?? []),
                            'title' => $item['title'] ?? '',
                            'path' => 'flash/' . base64_encode($album) . '/' . $item['url'],
                            'thumb' => 'flash/' . base64_encode($album) . '/' . str_replace('.swf', '.jpg', $item['url'])
                        ];
                    }
                }
            }
        }

        // Build full media objects for favorited Flash items
        $flashFavoriteHashesFlipped = array_flip($flashFavoriteHashes);
        $flashFavoritesFullData = [];
        foreach ($flashHashToData as $hash => $data) {
            if (isset($flashFavoriteHashesFlipped[$hash])) {
                $flashFavoritesFullData[] = $data;

                // Copy to fa/flash/
                $src = $data['path'];
                $dst = str_replace('flash/', 'fa/flash/', $data['path']);
                echo PHP_EOL . 'Copying Flash favorite: ' . $src . ' to ' . $dst;
                $opsNoEncodeNoPrefix->copy($src, $dst, true, true);

                // Also copy thumbnail if exists
                $thumbSrc = $data['thumb'];
                $thumbDst = str_replace('flash/', 'fa/flash/', $data['thumb']);
                $opsNoEncodeNoPrefix->copy($thumbSrc, $thumbDst, true, true);
            }
        }

        // Save full Flash favorites data (not just hashes)
        $flashFavoritesFullJson = json_encode($flashFavoritesFullData);
        $opsNoEncodeNoPrefix->file_put_contents('fa/favorites_flash.json', $flashFavoritesFullJson);
        $opsNoEncodeNoPrefix->file_put_contents($prefix . '/favorites_flash.json', $flashFavoritesFullJson);
        echo PHP_EOL . 'Synced Flash favorites JSON with full data: ' . count($flashFavoritesFullData) . ' items';

        // Clean up removed Flash favorites from fa/flash/ (keep originals in flash/ intact)
        echo PHP_EOL . 'Checking for removed Flash favorites to clean up from fa/flash/';
        $faFlashFiles = $opsNoEncodeNoPrefix->scandir('fa/flash', true, true, null, false, true, true, true);
        if ($faFlashFiles !== false) {
            foreach ($faFlashFiles as $faFlashFile) {
                // Skip ruffle directory
                if (strpos($faFlashFile['Path'], 'ruffle') !== false) {
                    continue;
                }

                $ext = strtolower(pathinfo($faFlashFile['Path'], PATHINFO_EXTENSION));
                if ($ext === 'swf' || $ext === 'jpg') {
                    // Find hash for this file by checking against flashHashToData
                    $faPath = 'fa/flash/' . $faFlashFile['Path'];
                    $originalPath = 'flash/' . $faFlashFile['Path'];
                    $foundHash = null;

                    foreach ($flashHashToData as $hash => $data) {
                        if ($data['path'] === $originalPath || $data['thumb'] === $originalPath) {
                            $foundHash = $hash;
                            break;
                        }
                    }

                    // If hash found and not in current favorites, delete from fa/flash/ only
                    if ($foundHash && !isset($flashFavoriteHashesFlipped[$foundHash])) {
                        echo PHP_EOL . 'Removing de-favorited Flash file: ' . $faPath;
                        $opsNoEncodeNoPrefix->unlink($faPath);
                    }
                }
            }
        }

        // Copy ruffle player to fa/flash/ruffle/ for favorites-only playback
        echo PHP_EOL . 'Copying ruffle player to fa/flash/ruffle/';
        $opsNoEncodeNoPrefix->syncDir('flash/ruffle', 'fa/flash/ruffle', 4, 4, false);
    }
}

echo PHP_EOL . 'Database Downloads have been completed';

if ($skipDownload) {
    echo PHP_EOL . 'Skipping media download queue as requested';
    exit(0);
}

echo PHP_EOL . 'Checking ' . count($dlData) . ' media items to be downloaded';
echo PHP_EOL . 'Filtering ' . count($previouslyDownloadedMedia) . ' media items already downloaded';

$dlData = array_filter($dlData, function ($item) use ($previouslyDownloadedMedia) {
    $filename = str_replace(
        ['.mpg', '.mod', '.mmv', '.tod', '.wmv', '.asf', '.avi', '.divx', '.mov', '.m4v', '.3gp', '.3g2', '.m2t', '.m2ts', '.mts', '.mkv'],
        '.mp4',
        $item['filename']
    );
    $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $thumbnailPreserve = $item['hash'] . '.jpg';
    return !array_key_exists($preserve, $previouslyDownloadedMedia)
        || ((str_contains($preserve, '.webm') || str_contains($preserve, '.mp4'))
            && !array_key_exists($thumbnailPreserve, $previouslyDownloadedMedia));
});

$existingFavorites = array_flip(
    array_map(
        function ($item) { return trim($item, "\/"); },
        array_column(
            $opsFavorites->scandir('', true, true, null, false, true, true, true),
            'Name'
        )
    )
);

echo PHP_EOL . 'Filtering ' . count($existingFavorites) . ' Favorited media items already downloaded';

$dlData = array_filter($dlData, function ($item) use ($existingFavorites) {
    $filename = str_replace(
        ['.mpg', '.mod', '.mmv', '.tod', '.wmv', '.asf', '.avi', '.divx', '.mov', '.m4v', '.3gp', '.3g2', '.m2t', '.m2ts', '.mts', '.mkv'],
        '.mp4',
        $item['filename']
    );
    $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $thumbnailPreserve = $item['hash'] . '.jpg';
    return !array_key_exists($preserve, $existingFavorites)
        || ((str_contains($preserve, '.webm') || str_contains($preserve, '.mp4'))
            && !array_key_exists($thumbnailPreserve, $existingFavorites));
});

echo PHP_EOL . 'Found ' . count($dlData) . ' new media items to be downloaded';

$progress = 0;
$total = count($dlData);
$lastProgress = 0;
$newDlCount = 0;
$lastDlTime = file_exists(__DIR__ . '/restore_point.txt') ? file_get_contents(__DIR__ . '/restore_point.txt') : false;

foreach ($dlData as $item) {
    $progress++;
    $percentage = round(($progress / $total) * 100, 2);
    if ($percentage > $lastProgress + 5) {
        echo PHP_EOL . "Overall Progress: {$percentage}% ";
        $lastProgress = $percentage;
    }

    if ($lastDlTime !== false && $item['time'] > $lastDlTime) {
        continue;
    }

    $album = $item['album'];
    $filename = str_replace(
        ['.mpg', '.mod', '.mmv', '.tod', '.wmv', '.asf', '.avi', '.divx', '.mov', '.m4v', '.3gp', '.3g2', '.m2t', '.m2ts', '.mts', '.mkv'],
        '.mp4',
        $item['filename']
    );

    $preserve = $item['hash'] . '.' . pathinfo($filename, PATHINFO_EXTENSION);
    $thumbnailPreserve = $item['hash'] . '.jpg';
    $path = privuma::getDataFolder() . DIRECTORY_SEPARATOR . (new mediaFile($item['filename'], $item['album']))->path();
    $path = $privuma->getOriginalPath($path) ?: $path;
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $thumbnailPath = dirname($path) . DIRECTORY_SEPARATOR . basename($path, '.' . $ext) . '.jpg';

    if (!$ops->is_file($preserve)) {
        if (!isset($item['url'])) {
            if (
                $item['url'] = $privuma->getCloudFS()->public_link($path) ?: $tokenizer->mediaLink($path, false, false, true)
            ) {
                if (strpos($filename, '.webm') !== false || strpos($filename, '.mp4') !== false) {
                    $item['thumbnail'] = $privuma->getCloudFS()->public_link($thumbnailPath) ?: $tokenizer->mediaLink($thumbnailPath, false, false, true);
                }
            } else {
                echo PHP_EOL . "Skipping unavailable media: $path";
                continue;
            }
        }

        echo PHP_EOL . 'Queue Downloading of media file: ' . $preserve . ' from album: ' . $item['album'] . ' with potential thumbnail: ' . ($item['thumbnail'] ?? 'No thumbnail');
        $privuma->getQueueManager()->enqueue(
            json_encode([
                'type' => 'processMedia',
                'data' => [
                    'album' => $album,
                    'filename' => $filename,
                    'url' => $item['url'],
                    'thumbnail' => $item['thumbnail'],
                    'download' => $downloadLocation . $prefix . DIRECTORY_SEPARATOR,
                    'hash' => $item['hash'],
                ],
            ])
        );
        $newDlCount++;
    } elseif (
        (strpos($filename, '.webm') !== false || strpos($filename, '.mp4') !== false)
        && !is_null($item['thumbnail'])
        && !$ops->is_file($thumbnailPreserve)
    ) {
        echo PHP_EOL . 'Queue Downloading of thumbnail: ' . $thumbnailPreserve . ' from album: ' . $item['album'];
        $privuma->getQueueManager()->enqueue(
            json_encode([
                'type' => 'processMedia',
                'data' => [
                    'album' => $album,
                    'filename' => str_replace('.webm', '.jpg', str_replace('.mp4', '.jpg', $filename)),
                    'url' => $item['thumbnail'],
                    'download' => $downloadLocation . $prefix . DIRECTORY_SEPARATOR,
                    'hash' => $item['hash'],
                ],
            ])
        );
    } elseif (
        (strpos($filename, '.webm') !== false || strpos($filename, '.mp4') !== false)
        && is_null($item['thumbnail'])
        && !$ops->is_file($thumbnailPreserve)
        && ($item['thumbnail'] = $privuma->getCloudFS()->public_link($thumbnailPath) ?: $tokenizer->mediaLink($thumbnailPath, false, false, true))
    ) {
        echo PHP_EOL . 'Queue Downloading of generated thumbnail: ' . $thumbnailPreserve . ' from album: ' . $item['album'];
        $privuma->getQueueManager()->enqueue(
            json_encode([
                'type' => 'processMedia',
                'data' => [
                    'album' => $album,
                    'filename' => str_replace('.webm', '.jpg', str_replace('.mp4', '.jpg', $filename)),
                    'url' => $item['thumbnail'],
                    'download' => $downloadLocation . $prefix . DIRECTORY_SEPARATOR,
                    'hash' => $item['hash'],
                ],
            ])
        );
    }
    file_put_contents(__DIR__ . '/restore_point.txt', $item['time']);
}

if (file_exists(__DIR__ . '/restore_point.txt')) {
    unlink(__DIR__ . '/restore_point.txt');
}

echo PHP_EOL . 'Done queuing ' . $newDlCount . ' Media to be downloaded';
