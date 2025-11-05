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
            INDEX idx_audit_timestamp (timestamp),
            INDEX idx_audit_operation (operation),
            INDEX idx_audit_table (table_name),
            INDEX idx_audit_script (calling_script(255)),
            INDEX idx_audit_reverted (reverted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    echo "✓ Table and indexes created successfully" . PHP_EOL . PHP_EOL;

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
