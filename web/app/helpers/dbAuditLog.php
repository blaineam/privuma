<?php

namespace privuma\helpers;

use PDO;
use PDOStatement;
use DateTime;

class dbAuditLog
{
    private PDO $pdo;
    private static bool $loggingEnabled = true;
    private static bool $inAuditOperation = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureAuditTableExists();
    }

    /**
     * Create audit_log table if it doesn't exist
     */
    private function ensureAuditTableExists(): void
    {
        self::$inAuditOperation = true;
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS audit_log (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                timestamp DATETIME NOT NULL,
                operation VARCHAR(20) NOT NULL,
                table_name VARCHAR(255) NOT NULL,
                record_id VARCHAR(255),
                calling_script VARCHAR(500) NOT NULL,
                line_number INT,
                before_data LONGTEXT,
                after_data LONGTEXT,
                sql_query LONGTEXT,
                reverted TINYINT DEFAULT 0,
                reverted_at DATETIME,
                INDEX idx_timestamp (timestamp),
                INDEX idx_operation (operation),
                INDEX idx_table (table_name),
                INDEX idx_script (calling_script(255)),
                INDEX idx_reverted (reverted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$inAuditOperation = false;
    }

    /**
     * Get calling script and line number from backtrace
     */
    private static function getCallingContext(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        // Look for first non-audit, non-PDO file
        foreach ($trace as $frame) {
            if (isset($frame['file']) &&
                !str_contains($frame['file'], 'dbAuditLog.php') &&
                !str_contains($frame['file'], 'AuditedPDO.php')) {

                $file = $frame['file'];
                $line = $frame['line'] ?? 0;

                // If it's a job, extract job name
                if (str_contains($file, '/jobs/')) {
                    preg_match('/\/jobs\/(core|plugins)\/([^\/]+)\//', $file, $matches);
                    if (isset($matches[2])) {
                        return [
                            'script' => "JOB:{$matches[2]} (" . basename($file) . ")",
                            'line' => $line
                        ];
                    }
                }

                // If it's a cron job
                if (str_contains($file, 'cron.php')) {
                    return [
                        'script' => 'CRON (' . basename($file) . ')',
                        'line' => $line
                    ];
                }

                // Otherwise return the file and line
                return [
                    'script' => basename($file),
                    'line' => $line
                ];
            }
        }

        return ['script' => 'UNKNOWN', 'line' => 0];
    }

    /**
     * Parse table name from SQL query
     */
    private static function parseTableName(string $sql): string
    {
        // Remove extra whitespace and normalize
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // INSERT INTO table
        if (preg_match('/INSERT\s+INTO\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }

        // UPDATE table
        if (preg_match('/UPDATE\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }

        // DELETE FROM table
        if (preg_match('/DELETE\s+FROM\s+`?(\w+)`?/i', $sql, $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }

    /**
     * Extract record ID from WHERE clause or insert data
     */
    private static function extractRecordId(string $sql, array $params, string $operation): ?string
    {
        // For UPDATE/DELETE, try to get ID from WHERE clause
        if ($operation === 'UPDATE' || $operation === 'DELETE') {
            if (preg_match('/WHERE\s+id\s*=\s*\?/i', $sql) && !empty($params)) {
                return (string) $params[0];
            }
            if (preg_match('/WHERE\s+id\s*=\s*(\d+)/i', $sql, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Get current record data before modification
     */
    private function getRecordData(string $table, ?string $recordId, string $sql, array $params): ?array
    {
        if (self::$inAuditOperation) {
            return null;
        }

        self::$inAuditOperation = true;

        try {
            // For UPDATE/DELETE with WHERE clause, try to fetch current data
            if (preg_match('/WHERE\s+(.+?)(?:ORDER|LIMIT|$)/i', $sql, $matches)) {
                $whereClause = trim($matches[1]);

                // Build SELECT query
                $selectSql = "SELECT * FROM {$table} WHERE {$whereClause}";
                $stmt = $this->pdo->prepare($selectSql);
                $stmt->execute($params);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

                self::$inAuditOperation = false;
                return $data ?: null;
            }
        } catch (\Exception $e) {
            error_log("Error fetching before data: " . $e->getMessage());
        }

        self::$inAuditOperation = false;
        return null;
    }

    /**
     * Log a database operation
     */
    public function logOperation(
        string $operation,
        string $sql,
        array $params = [],
        ?int $lastInsertId = null
    ): void {
        if (!self::$loggingEnabled || self::$inAuditOperation) {
            return;
        }

        self::$inAuditOperation = true;

        try {
            $context = self::getCallingContext();
            $table = self::parseTableName($sql);
            $recordId = self::extractRecordId($sql, $params, $operation);

            // Get before data for UPDATE/DELETE
            $beforeData = null;
            if ($operation === 'UPDATE' || $operation === 'DELETE') {
                $beforeData = $this->getRecordData($table, $recordId, $sql, $params);
            }

            // For INSERT, record ID is the last insert ID
            if ($operation === 'INSERT' && $lastInsertId) {
                $recordId = (string) $lastInsertId;
            }

            // Build after data for INSERT/UPDATE
            $afterData = null;
            if ($operation === 'INSERT' || $operation === 'UPDATE') {
                // For INSERT, try to fetch the newly inserted record
                if ($operation === 'INSERT' && $recordId) {
                    $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
                    $stmt->execute([$recordId]);
                    $afterData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } elseif ($operation === 'UPDATE') {
                    // Re-fetch after update
                    $afterData = $this->getRecordData($table, $recordId, $sql, $params);
                }
            }

            // Insert audit log entry
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_log (
                    timestamp, operation, table_name, record_id,
                    calling_script, line_number, before_data, after_data, sql_query
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                (new DateTime())->format('Y-m-d H:i:s'),
                $operation,
                $table,
                $recordId,
                $context['script'],
                $context['line'],
                $beforeData ? json_encode($beforeData, JSON_PRETTY_PRINT) : null,
                $afterData ? json_encode($afterData, JSON_PRETTY_PRINT) : null,
                $sql
            ]);
        } catch (\Exception $e) {
            error_log("Audit logging error: " . $e->getMessage());
        }

        self::$inAuditOperation = false;
    }

    /**
     * Enable/disable audit logging
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$loggingEnabled = $enabled;
    }

    /**
     * Check if currently in audit operation (prevent recursion)
     */
    public static function isInAuditOperation(): bool
    {
        return self::$inAuditOperation;
    }
}
