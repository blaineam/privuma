<?php

function loadEnv($path) :void
    {
        if (!is_readable($path)) {
            //throw new \RuntimeException(sprintf('%s file is not readable', $path));
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

    function get_env($key = null) {
        $output = [];
        foreach($_ENV as $name => $value) {

            if(is_scalar($value)) {
                if(is_numeric($value)) {
                    $value = $value + 0;
                }
    
                if(strtolower($value) == 'true') {
                    $value = true;
                }
    
                if(strtolower($value) == 'false') {
                    $value = false;
                }
            }

            $output[$name] = $value;
        }

        return $key ? $output[$key] : $output;
    }