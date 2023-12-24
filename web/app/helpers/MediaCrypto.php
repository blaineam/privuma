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

    public static function encryptString(
        string $passphrase,
        string $data,
        bool $gzip = false,
    ) {
        $tmpfile = tempnam(sys_get_temp_dir(), 'PVMA');
        file_put_contents($tmpfile, $data);
        self::encrypt($passphrase, $tmpfile, false, null, true, $gzip);
        $result = file_get_contents($gzip ? ($tmpfile . "-gz") : $tmpfile);
        unlink($gzip ? ($tmpfile . "-gz") : $tmpfile);
        return $result;
    }

    public static function decryptString(
        string $passphrase,
        string $data,
        bool $gzip = false,
    ) {
        $tmpfile = tempnam(sys_get_temp_dir(), 'PVMA');
        file_put_contents($tmpfile, $data);
        self::decrypt($passphrase, $tmpfile, false, true, false, $gzip);
        $result = file_get_contents($gzip ? str_replace('-gz', '', $tmpfile) : $tmpfile);
        unlink($gzip ? str_replace('-gz', '', $tmpfile) : $tmpfile);
        return $result;
    }

    public static function encrypt(
        string $passphrase,
        string $path,
        bool $enableOutput = false,
        int $chunkSize = null,
        bool $force = false,
        bool $gzip = false,
    )
    {
        $memory_limit = (int)self::return_bytes(ini_get('memory_limit'));

        if ($memory_limit <= 0) {
            $memory_limit = 2 * 1024; // 2 GB as a sane default;
        }

        clearstatcache();
        if($force === false && strstr(MediaCrypto::getMime($path), 'image') == false
        && strstr(MediaCrypto::getMime($path), 'video') == false) {
            if ($enableOutput) {
                echo "Unexpected Filetype found, skipping encryption" . PHP_EOL;
            }
            return;
        }

        if ($enableOutput) {
            echo "Encrypting: {$path}" . PHP_EOL;
        }

        if (!is_null($chunkSize)) {
            $chunkSize = min($memory_limit / 8, $chunkSize);
        } else {
            $chunkSize = min($memory_limit / 8, 8192);
        }

        $tempName = tempnam(sys_get_temp_dir(), "MedCrypt_");

        if ($gzip) {
            $newPath = tempnam(sys_get_temp_dir(), "MedCrypt_");
            exec("gzip < " . escapeshellarg($path) . " > " . escapeshellarg($newPath));
        }

        $read = fopen($gzip? $newPath : $path, 'r');
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
        copy($tempName, $gzip ? ($path . "-gz") : $path);
        unlink($tempName);
        if ($gzip) {
            unlink($path);
            unlink($newPath);
        }
    }

    public static function decrypt(
        string $passphrase,
        string $path,
        bool $enableOutput = false,
        bool $force = false,
        bool $cleanup = false,
        bool $gzip = false,
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
        if(is_null($salts)) {
            echo PHP_EOL."removing corrupted encrypted file";
            unlink($tempName);
            unlink($path);
            return;
        }

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

        if ($gzip) {
            $newPath = tempnam(sys_get_temp_dir(), "MedCrypt_");
            exec("gzip -d < " . escapeshellarg($tempName) . " > " . escapeshellarg($newPath));
        }

        clearstatcache();
        $isMediaFile = strstr(MediaCrypto::getMime($gzip ? $newPath : $tempName), 'image') !== false
        || strstr(MediaCrypto::getMime($gzip ? $newPath : $tempName), 'video') !== false;
        if(
            $force === true
            || (
                $cleanup === false
                && $isMediaFile
            )
        ) {
            copy($gzip ? $newPath : $tempName, $gzip ? str_replace('-gz', '', $path) : $path);
        }else if (
            $cleanup === true
            && !$isMediaFile
        ) {
            unlink($path);
            if ($enableOutput) {
                echo "Cleaned Up corrupted encrypted file" . PHP_EOL;
            }
        } else if (
            $cleanup === false
            && $enableOutput
        ) {
            echo "Unexpected Decrypted Filetype found, skipping decryption result" . PHP_EOL;
        }else if (
            $cleanup === true
            && $enableOutput
        ) {
            echo "Decryption was successful, skipping cleanup" . PHP_EOL;
        }
        unlink($tempName);
        if ($gzip) {
            unlink($path);
            unlink($newPath);
        }
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
        return self::z85_encode(base64_decode(openssl_encrypt($value, 'aes-256-cbc', hex2bin($key), 0, hex2bin($iv))));
    }

    private static function decryptChunk(string $data, string $key, string $iv)
    {
        // Use this line to decrypt base64 encrypted files if you have them.
        //return openssl_decrypt($data, 'aes-256-cbc', hex2bin($key), 0, hex2bin($iv));

        return openssl_decrypt(base64_encode(self::z85_decode($data)), 'aes-256-cbc', hex2bin($key), 0, hex2bin($iv));
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

    // Implements http://rfc.zeromq.org/spec:32
    // Ported from https://github.com/msealand/z85.node/blob/master/index.js

    private static $encoder = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ.-:+=^!/*?&<>()[]{}@%$#";

    private static $decoder = array(
        0x00, 0x44, 0x00, 0x54, 0x53, 0x52, 0x48, 0x00,
        0x4B, 0x4C, 0x46, 0x41, 0x00, 0x3F, 0x3E, 0x45,
        0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07,
        0x08, 0x09, 0x40, 0x00, 0x49, 0x42, 0x4A, 0x47,
        0x51, 0x24, 0x25, 0x26, 0x27, 0x28, 0x29, 0x2A,
        0x2B, 0x2C, 0x2D, 0x2E, 0x2F, 0x30, 0x31, 0x32,
        0x33, 0x34, 0x35, 0x36, 0x37, 0x38, 0x39, 0x3A,
        0x3B, 0x3C, 0x3D, 0x4D, 0x00, 0x4E, 0x43, 0x00,
        0x00, 0x0A, 0x0B, 0x0C, 0x0D, 0x0E, 0x0F, 0x10,
        0x11, 0x12, 0x13, 0x14, 0x15, 0x16, 0x17, 0x18,
        0x19, 0x1A, 0x1B, 0x1C, 0x1D, 0x1E, 0x1F, 0x20,
        0x21, 0x22, 0x23, 0x4F, 0x00, 0x50, 0x00, 0x00
    );


    private static function z85_encode ($data) {
        $encoder = self::$encoder;
        $decoder = self::$decoder;
        if( !is_array($data) ) {
            $data = str_split($data);
        }
        if ((count($data) % 4) !== 0) {
            return null;
        }

        $str = "";
        $byte_nbr = 0;
        $size = count( $data );
        $value = 0;
        while ($byte_nbr < $size) {
            $characterCode = ord($data[$byte_nbr++]);
            $value = ($value * 256) + $characterCode;
            if (($byte_nbr % 4) === 0) {
                $divisor = 85 * 85 * 85 * 85;
                while ($divisor >= 1) {
                    $idx =  bcmod(floor($value / $divisor), 85);
                    $str = $str . $encoder[$idx];
                    $divisor /= 85;
                }
                $value = 0;
            }
        }

        return $str;
    }

    private static function z85_decode($string) {
        $encoder = self::$encoder;
        $decoder = self::$decoder;
        if ((strlen( $string ) % 5) !== 0) {
            return null;
        }

        $dest = array();
        $byte_nbr = 0;
        $char_nbr = 0;
        $string_len = strlen( $string );
        $value = 0;
        while ($char_nbr < $string_len) {
            $idx = ord($string[$char_nbr++]) - 32;
            if (($idx < 0) || ($idx >= count( $decoder ))) {
                return;
            }
            $value = ($value * 85) + $decoder[$idx];
            if (($char_nbr % 5) == 0) {
                $divisor = 256 * 256 * 256;
                while ($divisor >= 1) {
                    $dest[$byte_nbr++] = intval($value / $divisor) % 256;
                    $divisor /= 256;
                }
                $value = 0;
            }
        }

        return implode(array_map("chr", $dest));
    }

}
