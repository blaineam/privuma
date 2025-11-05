<?php

namespace privuma\helpers;

use DateTime;
use privuma\privuma;

class auditLog
{
    private static string $logPath;

    /**
     * Initialize audit log path
     */
    private static function init(): void
    {
        if (!isset(self::$logPath)) {
            self::$logPath = privuma::getOutputDirectory() . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'rclone_audit.log';
        }
    }

    /**
     * Get the calling script/job name from backtrace
     */
    private static function getCallingScript(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        // Look for the first non-cloudFS/auditLog file in the trace
        foreach ($trace as $frame) {
            if (isset($frame['file']) &&
                !str_contains($frame['file'], 'cloudFS.php') &&
                !str_contains($frame['file'], 'auditLog.php')) {

                $file = basename($frame['file']);
                $line = $frame['line'] ?? 0;

                // If it's a job, extract job name
                if (str_contains($frame['file'], '/jobs/')) {
                    preg_match('/\/jobs\/(core|plugins)\/([^\/]+)\//', $frame['file'], $matches);
                    if (isset($matches[2])) {
                        return "JOB:{$matches[2]} ({$file}:{$line})";
                    }
                }

                // If it's a cron job
                if (str_contains($frame['file'], 'cron.php')) {
                    return "CRON ({$file}:{$line})";
                }

                // Otherwise return the file and line
                return "{$file}:{$line}";
            }
        }

        return 'UNKNOWN';
    }

    /**
     * Log a file system mutation
     *
     * @param string $operation Operation type: mkdir, write, delete, rename, copy, move
     * @param string $path Primary path affected
     * @param string|null $secondaryPath Secondary path (for rename/copy/move operations)
     * @param bool $success Whether the operation succeeded
     * @param string|null $reason Optional reason/context for the mutation
     */
    public static function logMutation(
        string $operation,
        string $path,
        ?string $secondaryPath = null,
        bool $success = true,
        ?string $reason = null
    ): void {
        self::init();

        $timestamp = (new DateTime())->format('Y-m-d H:i:s');
        $caller = self::getCallingScript();
        $status = $success ? 'SUCCESS' : 'FAILED';

        $logEntry = sprintf(
            "[%s] %s | %s | %s | Path: %s%s%s\n",
            $timestamp,
            $status,
            $operation,
            $caller,
            $path,
            $secondaryPath ? " -> {$secondaryPath}" : '',
            $reason ? " | Reason: {$reason}" : ''
        );

        // Append to audit log file
        file_put_contents(self::$logPath, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log a directory creation
     */
    public static function logMkdir(string $path, bool $success, ?string $reason = null): void
    {
        self::logMutation('MKDIR', $path, null, $success, $reason);
    }

    /**
     * Log a file write/update
     */
    public static function logWrite(string $path, bool $success, ?string $reason = null): void
    {
        self::logMutation('WRITE', $path, null, $success, $reason);
    }

    /**
     * Log a file deletion
     */
    public static function logDelete(string $path, bool $success, ?string $reason = null): void
    {
        self::logMutation('DELETE', $path, null, $success, $reason);
    }

    /**
     * Log a file rename/move
     */
    public static function logRename(string $oldPath, string $newPath, bool $success, ?string $reason = null): void
    {
        self::logMutation('RENAME', $oldPath, $newPath, $success, $reason);
    }

    /**
     * Log a file copy
     */
    public static function logCopy(string $sourcePath, string $destPath, bool $success, ?string $reason = null): void
    {
        self::logMutation('COPY', $sourcePath, $destPath, $success, $reason);
    }

    /**
     * Log a sync/move operation
     */
    public static function logMove(string $sourcePath, string $destPath, bool $success, ?string $reason = null): void
    {
        self::logMutation('MOVE', $sourcePath, $destPath, $success, $reason);
    }
}
