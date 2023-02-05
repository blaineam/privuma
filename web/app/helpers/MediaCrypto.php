<?php

namespace MediaCrypto;

use finfo;

class MediaCrypto
{
    public static function getMime($filePath)
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($filePath);
    }

    public static function encrypt(
        string $passphrase,
        string $path,
        bool $enableOutput = false,
        int $chunkSize = null,
    )
    {
        $memory_limit = self::return_bytes(ini_get('memory_limit'));

        if ($enableOutput) {
            echo "Encrypting: {$path}" . PHP_EOL;
        }

        if (!is_null($chunkSize)) {
            $chunkSize = min($memory_limit / 8, $chunkSize);
        } else {
            $chunkSize = min($memory_limit / 8, 8146);
        }

        $tempName = tempnam(sys_get_temp_dir(), "MedCrypt_");
        $read = fopen($path, 'r');
        $write = fopen($tempName, 'w');
        $total = filesize($path);
        $progress = 0;
        $loggedProgress = 0;
        $keys = self::getSaltAndKeyAndIv($passphrase);
        fwrite($write, json_encode(["salt" => $keys["salt"], "iv" => $keys["iv"]]) . "\n");
        while (!feof($read)) {
            $chunk = self::encryptChunk(fread($read, $chunkSize), $keys["key"], $keys["iv"]);
            fwrite($write, "{" . $chunk . "}\n");
            $progress += $chunkSize;
            if ($enableOutput) {
                $output = min(round(($progress / $total) * 10, 3), 10);
                if ($loggedProgress + 1 < $output) {
                    echo ($output * 10) . "% ";
                    $loggedProgress = floor($output);
                }
            }
        }

        if ($enableOutput) {
            echo "100.0% " . PHP_EOL;
        }

        fclose($read);
        fclose($write);
        copy($tempName, $path);
        unlink($tempName);
    }

    public static function decrypt(
        string $passphrase,
        string $path,
        bool $enableOutput = false
    )
    {

        if ($enableOutput) {
            echo "Decrypting: {$path}" . PHP_EOL;
        }

        $tempName = tempnam(sys_get_temp_dir(), "MedCrypt_");
        $read = fopen($path, 'r');
        $write = fopen($tempName, 'w');
        $total = filesize($path);
        $progress = 0;
        $loggedProgress = 0;

        $salts = json_decode(rtrim(fgets($read), "\r\n"), true);
        $keys = self::getDecryptionKeyAndIv($passphrase, $salts["salt"], $salts["iv"]);
        while (!feof($read)) {
            $line = rtrim(fgets($read), "\r\n");
            $line = preg_replace('/^{/', '', $line);
            $line = preg_replace('/}$/', '', $line);
            if (strlen($line) > 0) {
                $decrypted = self::decryptChunk($line, $keys["key"], $keys["iv"]);
                $chunk = $decrypted;
                fwrite($write, $chunk);

                if ($enableOutput) {
                    $progress += strlen($line);
                    $output = min(round(($progress / $total) * 10, 3), 10);
                    if ($loggedProgress + 1 < $output) {
                        echo ($output * 10) . "% ";
                        $loggedProgress = floor($output);
                    }
                }
            }
        }

        if ($enableOutput) {
            echo "100.0% " . PHP_EOL;
        }

        fclose($read);
        fclose($write);
        copy($tempName, $path);
        unlink($tempName);
    }

    private static function getSaltAndKeyAndIv(string $passphrase)
    {
        $salt = openssl_random_pseudo_bytes(8);
        $salted = '';
        $dx = '';
        while (strlen($salted) < 48) {
            $dx = md5($dx . $passphrase . $salt, true);
            $salted .= $dx;
        }
        $key = substr($salted, 0, 32);
        $iv = substr($salted, 32, 16);
        return ["salt" => bin2hex($salt), "key" => bin2hex($key), "iv" => bin2hex($iv)];
    }

    private static function getDecryptionKeyAndIv(string $passphrase, string $salt, string $iv)
    {
        $concatedPassphrase = $passphrase . hex2bin($salt);
        $md5 = [];
        $md5[0] = md5($concatedPassphrase, true);
        $result = $md5[0];
        for ($i = 1; $i < 3; $i++) {
            $md5[$i] = md5($md5[$i - 1] . $concatedPassphrase, true);
            $result .= $md5[$i];
        }
        $key = substr($result, 0, 32);
        return ["key" => bin2hex($key), "iv" => $iv];
    }

    private static function encryptChunk($value, string $key, string $iv)
    {
        return openssl_encrypt($value, 'aes-256-cbc', hex2bin($key), 0, hex2bin($iv));
    }

    private static function decryptChunk(string $data, string $key, string $iv)
    {
        return openssl_decrypt($data, 'aes-256-cbc', hex2bin($key), 0, hex2bin($iv));
    }

    private static function return_bytes($val)
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = substr($val, 0, -1);
        switch ($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        return $val;
    }

    /**
     * yEncodes a string and returns it.
     */
    public static function yencode($string)
    {
        $encoded = "";
        // Encode each character of the string one at a time.
        for ($i = 0; $i < strlen($string); $i++) {
            $value = (ord($string[$i]) + 42) % 256;

            // Escape NULL, TAB, LF, CR, space, . and = characters.
            if ($value == 0 || $value == 10 || $value == 13 || $value == 61)
                $encoded .= "=" . chr(($value + 64) % 256);
            else
                $encoded .= chr($value);
        }

        return $encoded;
    }

    /**
     * yDecodes an encoded string and either writes the result to a file
     * or returns it as a string.
     */
    public static function ydecode($encoded)
    {
        $decoded = '';
        for ($i = 0; $i < strlen($encoded); $i++) {
            if ($encoded[$i] == "=") {
                $i++;
                $decoded .= chr((ord($encoded[$i]) - 64) - 42);
            } else {
                $decoded .= chr(ord($encoded[$i]) - 42);
            }
        }

        return $decoded;
    }
}