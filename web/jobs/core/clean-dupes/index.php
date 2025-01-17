<?php
use privuma\privuma;
use privuma\helpers\mediaFile;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = privuma::getInstance();
$ops = $privuma->getCloudFS();

if ($argc > 1) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

$db = array_filter($ops->scandir(privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER, true, true, null, false, true, true, true), function ($file) {
    return $file['Size'] <= 1000;
});

$dry = !isset($_GET['clean']);
$checked = [];
function clean($path)
{
    global $dry;
    global $ops;
    echo '🗑️  ';
    if ($dry) {
        return;
    }
    if (strlen($path) < 5) {
        return;
    }
    $ops->unlink(privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $path);
    echo '❌  ';
}
function check($path, $d = 0)
{
    global $ops;
    if ($d >= 10) {
        echo '🤿  ';
        return true;
    }
    global $checked;
    $checked[$path] = null;
    $output = $ops->file_get_contents(privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER . DIRECTORY_SEPARATOR . $path, false, null, 0, 1000);
    $ext = pathinfo($output, PATHINFO_EXTENSION);
    if ($output === false) {
        echo '🛟  ';
        return true;
    }
    if (strlen($output) === 0) {
        $checked[$path] = false;
        echo '👻  ';
        return false;
    }

    if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'gif', 'png', 'webm', 'mp4'])) {
        $checked[$path] = true;
        return true;
    }

    $testPath = str_replace(privuma::getDataFolder() . DIRECTORY_SEPARATOR . mediaFile::MEDIA_FOLDER, '', $output);

    if (array_key_exists($testPath, $checked)) {
        echo '🔍  ';
        $result = $checked[$testPath] ?? false;
        if ((is_null($result) || $result === false) && strlen($testPath) > 5) {
            $checked[$testPath] = false;
        }
        return $result;
    }

    return check($testPath, $d++);
}
array_multisort(array_column($db, 'Size'), $db);
$cleanCount = 0;
$keepCount = 0;
foreach ($db as $file) {
    if ($file['Size'] === 0 && strlen($file['Path']) > 2) {
        echo '🫙  ';
        clean($file['Path']);
        $cleanCount++;
        continue;
    }

    if (check($file['Path']) === false) {
        clean($file['Path']);
        $cleanCount++;
    } else {
        echo '🖼️  ';
        $keepCount++;
    }
}

echo PHP_EOL . (($dry) ? '[DRY RUN] ' : '') . "Done, Cleaned: {$cleanCount}, Kept: {$keepCount}";
