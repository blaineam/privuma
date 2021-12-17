<?php
    $DEBUG = false;

    $phpPath = PHP_BINARY ?? exec("which php") ?? exec("whereis php") ?? "/usr/local/bin/php" ;

    if ($DEBUG) {
        var_dump($phpPath);
    }

    if(strpos($phpPath, 'not found') !== false || empty($phpPath)) {
        echo "Unmet Dependencies, cannot find php binaries on system.";
        var_dump($phpPath);
        exit(1);
    }

    $currentTime = time();
    if ($DEBUG) {
        var_dump(scandir(__DIR__ . "/jobs"));
        var_dump($currentTime);
    }

    exec("find " . sys_get_temp_dir() . DIRECTORY_SEPARATOR . " -maxdepth 1  -type f-mmin +30 -exec rm {} \;");

    foreach(array_diff(scandir(__DIR__ . "/jobs"), ['.', '..']) as $job) {
        $jobDir = __DIR__ . "/jobs/" . $job . "/";
        $command = $jobDir . "index.php";
        $cron = $jobDir   . "cron.json";
        $lock = $jobDir   . "job.lock";
        $log = __DIR__ . "/logs/" . $job . "-out.txt";

        if(!is_file($command)) {
            if ($DEBUG) {
                var_dump("COMMAND NOT FOUND: " . $command);
            }
            continue;
        }

        $cronConfig = json_decode(file_get_contents($cron), true) ?? [ "interval" => 24 * 60 * 60];

        $lastRan = filemtime($cron) ?? $currentTime - 24 * 60 * 60;

        unset($cronConfig['lastRan']);

        if ($currentTime - $lastRan < $cronConfig["interval"]) {
            if ($DEBUG) {
                var_dump([
                    "CRON RAN TOO RECENTLY",
                    $currentTime,
                    $lastRan,
                    $cronConfig["interval"]
                ]);
            }
            continue;
        }

        if (!touch($cron, $currentTime)) {
            echo PHP_EOL. "Cron file's modification date cannot be set, please check the cron.json permissions";
            continue;
        };

        file_put_contents($cron, json_encode($cronConfig, JSON_PRETTY_PRINT));

        exec(implode(' ', [
            // path to flock in container
            '/usr/bin/flock',
            // flag to exit if lock already exists
            '-n',
            // path to job lock file
            $lock,
            // use php to script each cron job
            $phpPath,
            // path to normal cron job definition
            $command,
            // overrite the log file with new log entries
            '>',
            // logs go to the same logs folder for easy tailing with multiple -f flags
            $log,
            // redirect any errors to the log file
            '2>&1',
            // background the task so that it can continue to run to its completion
            '&'
        ]));

        if ($DEBUG) {
            var_dump("EXECUTED COMMAND: " . $command);
        }

    }

    if ($DEBUG) {
        var_dump("CRON COMPLETED");
    }