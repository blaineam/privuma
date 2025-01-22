<?php

use privuma\privuma;

if ($argc > 1) parse_str(implode('&', array_slice($argv, 1)), $_GET);

$CLEANERS = ['clean-dupes', 'cleaner', 'fix-broken-dupes', 'fix-broken-links', 'fix-broken-media'];

$DEBUG = isset($_GET['debug']);
$CLEAN = isset($_GET['clean']);

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

$privuma = new privuma();

$phpPath = PHP_BINARY ?? exec('which php') ?? exec('whereis php') ?? '/usr/local/bin/php' ;

if (strpos($phpPath, 'not found') !== false || empty($phpPath)) {
	echo 'Unmet Dependencies, cannot find php binaries on system.';
	var_dump($phpPath);
	exit(1);
}

exec('find ' . sys_get_temp_dir() . DIRECTORY_SEPARATOR . " -maxdepth 1 -type f -mmin +30 -exec rm -f {} \;");


$coreJobsDir = __DIR__ . '/jobs/core';
$pluginsJobsDir = __DIR__ . '/jobs/plugins';
foreach (array_diff(array_merge(scandir($coreJobsDir), scandir($pluginsJobsDir)), ['.', '..']) as $job) {
	$jobDir = $pluginsJobsDir . '/' . $job . '/';
	if (!is_dir($jobDir)) {
		$jobDir = $coreJobsDir . '/' . $job . '/';
	}
	
	$job = basename($jobDir);
	$args = '';
	if (str_contains($job, 'queue-worker')) {
		continue;
	}
	
	if(in_array($job, $CLEANERS)) {
		if($CLEAN) {
			$args .= ' clean=1 ';
		} else {
			continue;
		}
	}
	
	$command = $jobDir . 'index.php';
	$cron = $jobDir . 'cron.json';
	$lock = $jobDir . 'job.lock';
	$disabled = $jobDir . '.disabled';
	$log = $privuma->getOutputDirectory() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . $job . '.txt';

	if (!is_file($command)) {
		if ($DEBUG) {
			echo PHP_EOL.'COMMAND NOT FOUND: ' . $command;
		}
		continue;
	}

	if (is_file($disabled)) {
		if ($DEBUG) {
			echo PHP_EOL.'Job is disabled: ' . $job;
		}
		continue;
	}

	$cmd = implode(' ', [
				// use php to script each cron job
				$phpPath,
				// path to normal cron job definition
				$command,
				$args,
			  ]);

	if ($DEBUG) {
		echo PHP_EOL.'STARTING COMMAND: ' . $command;
	}
	
	if ($DEBUG) {
		passthru($cmd);
	} else {
		exec($cmd);
	}

	if ($DEBUG) {
		echo PHP_EOL.'EXECUTED COMMAND: ' . $command;
	}
}

while(count(file("app/queue/queue.txt")) > 0){
	$cmd = implode(' ', [
		// use php to script each cron job
		$phpPath,
		// path to normal cron job definition
		"jobs/core/queue-worker-1/index.php",
	  ]);
	if ($DEBUG) {
		passthru($cmd);
	} else {
		exec($cmd);
	}
}

if ($DEBUG) {
	echo PHP_EOL.'SYNC COMPLETED';
}