<?php

/**
 * Database Audit Log Migration Script
 *
 * This script creates the audit_log table for tracking all database modifications.
 * Run this once to enable database audit logging.
 */

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

echo "=== Database Audit Log Migration ===" . PHP_EOL . PHP_EOL;

$privuma = privuma::getInstance();
$pdo = $privuma->getPDO();

echo "Creating audit_log table..." . PHP_EOL;

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp TEXT NOT NULL,
            operation TEXT NOT NULL,
            table_name TEXT NOT NULL,
            record_id TEXT,
            calling_script TEXT NOT NULL,
            line_number INTEGER,
            before_data TEXT,
            after_data TEXT,
            sql_query TEXT,
            reverted INTEGER DEFAULT 0,
            reverted_at TEXT
        )
    ");

    echo "✓ Table created successfully" . PHP_EOL;

    echo "Creating indexes..." . PHP_EOL;

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_timestamp ON audit_log(timestamp)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_operation ON audit_log(operation)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_table ON audit_log(table_name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_script ON audit_log(calling_script)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_reverted ON audit_log(reverted)");

    echo "✓ Indexes created successfully" . PHP_EOL . PHP_EOL;

    // Check if table has any existing data
    $stmt = $pdo->query("SELECT COUNT(*) FROM audit_log");
    $count = $stmt->fetchColumn();

    echo "Current audit log entries: {$count}" . PHP_EOL . PHP_EOL;
    echo "=== Migration Complete ===" . PHP_EOL;
    echo "Database audit logging is now ready to use." . PHP_EOL;

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
