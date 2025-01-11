<?php

namespace privuma\helpers;

use privuma\privuma;

class dotenv
{
    public function __construct(?string $path = null)
    {

        $path = $path ?? privuma::getConfigDirectory() . DIRECTORY_SEPARATOR . '.env';

        if (!is_readable($path)) {
            throw new \RuntimeException(sprintf('%s file is not readable', $path));
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    public function get($key = null)
    {
        $output = [];
        foreach ($_ENV as $name => $value) {
            if (is_scalar($value)) {
                if (is_numeric($value)) {
                    $value = $value + 0;
                }

                if (strtolower($value) == 'true') {
                    $value = true;
                }

                if (strtolower($value) == 'false') {
                    $value = false;
                }
            }

            $output[$name] = $value;
        }

        foreach ($_SERVER as $name => $value) {
            if (is_scalar($value)) {
                if (is_numeric($value)) {
                    $value = $value + 0;
                }

                if (strtolower($value) == 'true') {
                    $value = true;
                }

                if (strtolower($value) == 'false') {
                    $value = false;
                }
            }

            $output[$name] = $value;
        }

        return $key ? (array_key_exists($key, $output) ? $output[$key] : null) : $output;
    }
}
