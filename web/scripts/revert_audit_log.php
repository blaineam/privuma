<?php

/**
 * Database Audit Log Revert Utility
 *
 * Usage:
 *   php revert_audit_log.php --help
 *   php revert_audit_log.php --id=123
 *   php revert_audit_log.php --range=100-200
 *   php revert_audit_log.php --range=100-200 --filter="media"
 *   php revert_audit_log.php --list [--filter="keyword"] [--range=100-200]
 *   php revert_audit_log.php --range=100-200 --filter="media" --dry-run
 */

use privuma\privuma;

require_once(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'privuma.php');

class AuditLogReverter
{
    private $pdo;
    private $dryRun = false;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    /**
     * List audit log entries with optional filters
     */
    public function listEntries(?string $filter = null, ?array $range = null): void
    {
        $sql = "SELECT id, timestamp, operation, table_name, record_id, calling_script, line_number, reverted
                FROM audit_log WHERE 1=1";
        $params = [];

        if ($range) {
            $sql .= " AND id BETWEEN ? AND ?";
            $params[] = $range[0];
            $params[] = $range[1];
        }

        if ($filter) {
            $sql .= " AND (table_name LIKE ? OR calling_script LIKE ? OR sql_query LIKE ?)";
            $filterParam = "%{$filter}%";
            $params[] = $filterParam;
            $params[] = $filterParam;
            $params[] = $filterParam;
        }

        $sql .= " ORDER BY id DESC LIMIT 100";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($entries)) {
            echo "No audit log entries found matching criteria." . PHP_EOL;
            return;
        }

        echo PHP_EOL . "=== Audit Log Entries ===" . PHP_EOL . PHP_EOL;
        printf("%-6s %-20s %-10s %-15s %-10s %-30s %-6s %-10s\n",
            "ID", "Timestamp", "Operation", "Table", "Record ID", "Script", "Line", "Reverted");
        echo str_repeat("-", 120) . PHP_EOL;

        foreach ($entries as $entry) {
            printf("%-6d %-20s %-10s %-15s %-10s %-30s %-6s %-10s\n",
                $entry['id'],
                substr($entry['timestamp'], 0, 19),
                $entry['operation'],
                substr($entry['table_name'], 0, 15),
                substr($entry['record_id'] ?? 'N/A', 0, 10),
                substr($entry['calling_script'], 0, 30),
                $entry['line_number'] ?? 'N/A',
                $entry['reverted'] ? 'YES' : 'NO'
            );
        }

        echo PHP_EOL . "Total entries: " . count($entries) . PHP_EOL;
        if (count($entries) == 100) {
            echo "(Limited to 100 entries - use --range to narrow results)" . PHP_EOL;
        }
    }

    /**
     * Show detailed information for a single entry
     */
    public function showEntry(int $id): void
    {
        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE id = ?");
        $stmt->execute([$id]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entry) {
            echo "Audit log entry #{$id} not found." . PHP_EOL;
            return;
        }

        echo PHP_EOL . "=== Audit Log Entry #{$id} ===" . PHP_EOL . PHP_EOL;
        echo "Timestamp:      {$entry['timestamp']}" . PHP_EOL;
        echo "Operation:      {$entry['operation']}" . PHP_EOL;
        echo "Table:          {$entry['table_name']}" . PHP_EOL;
        echo "Record ID:      " . ($entry['record_id'] ?? 'N/A') . PHP_EOL;
        echo "Calling Script: {$entry['calling_script']}:{$entry['line_number']}" . PHP_EOL;
        echo "Reverted:       " . ($entry['reverted'] ? 'YES (at ' . $entry['reverted_at'] . ')' : 'NO') . PHP_EOL;
        echo PHP_EOL;

        echo "SQL Query:" . PHP_EOL;
        echo $entry['sql_query'] . PHP_EOL . PHP_EOL;

        if ($entry['before_data']) {
            echo "BEFORE DATA:" . PHP_EOL;
            $this->printJson($entry['before_data']);
            echo PHP_EOL;
        }

        if ($entry['after_data']) {
            echo "AFTER DATA:" . PHP_EOL;
            $this->printJson($entry['after_data']);
            echo PHP_EOL;
        }

        if ($entry['before_data'] && $entry['after_data']) {
            echo "DIFF:" . PHP_EOL;
            $this->showDiff($entry['before_data'], $entry['after_data']);
        }
    }

    /**
     * Revert a single entry
     */
    public function revertEntry(int $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE id = ?");
        $stmt->execute([$id]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entry) {
            echo "✗ Audit log entry #{$id} not found." . PHP_EOL;
            return false;
        }

        if ($entry['reverted']) {
            echo "✗ Entry #{$id} was already reverted at {$entry['reverted_at']}" . PHP_EOL;
            return false;
        }

        echo "Reverting entry #{$id}: {$entry['operation']} on {$entry['table_name']}" . PHP_EOL;

        $success = false;

        try {
            $this->pdo->beginTransaction();

            switch ($entry['operation']) {
                case 'INSERT':
                    $success = $this->revertInsert($entry);
                    break;
                case 'UPDATE':
                    $success = $this->revertUpdate($entry);
                    break;
                case 'DELETE':
                    $success = $this->revertDelete($entry);
                    break;
                default:
                    echo "✗ Unknown operation: {$entry['operation']}" . PHP_EOL;
                    $this->pdo->rollBack();
                    return false;
            }

            if ($success) {
                if ($this->dryRun) {
                    echo "✓ [DRY RUN] Would revert entry #{$id}" . PHP_EOL;
                    $this->pdo->rollBack();
                } else {
                    // Mark as reverted
                    $stmt = $this->pdo->prepare("UPDATE audit_log SET reverted = 1, reverted_at = ? WHERE id = ?");
                    $stmt->execute([date('Y-m-d H:i:s'), $id]);
                    $this->pdo->commit();
                    echo "✓ Successfully reverted entry #{$id}" . PHP_EOL;
                }
                return true;
            } else {
                $this->pdo->rollBack();
                echo "✗ Failed to revert entry #{$id}" . PHP_EOL;
                return false;
            }
        } catch (Exception $e) {
            $this->pdo->rollBack();
            echo "✗ Error reverting entry #{$id}: " . $e->getMessage() . PHP_EOL;
            return false;
        }
    }

    /**
     * Revert INSERT operation (delete the inserted record)
     */
    private function revertInsert(array $entry): bool
    {
        if (!$entry['record_id']) {
            echo "  ✗ No record ID to delete" . PHP_EOL;
            return false;
        }

        $sql = "DELETE FROM {$entry['table_name']} WHERE id = ?";
        echo "  Executing: {$sql} [id={$entry['record_id']}]" . PHP_EOL;

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$entry['record_id']]);
    }

    /**
     * Revert UPDATE operation (restore previous values)
     */
    private function revertUpdate(array $entry): bool
    {
        if (!$entry['before_data']) {
            echo "  ✗ No before data available" . PHP_EOL;
            return false;
        }

        $beforeData = json_decode($entry['before_data'], true);
        if (!$beforeData || !is_array($beforeData)) {
            echo "  ✗ Invalid before data" . PHP_EOL;
            return false;
        }

        // Take first record (in case multiple were updated)
        $record = $beforeData[0];
        if (!isset($record['id'])) {
            echo "  ✗ No ID in before data" . PHP_EOL;
            return false;
        }

        // Build UPDATE statement
        $sets = [];
        $params = [];
        foreach ($record as $column => $value) {
            if ($column !== 'id') {
                $sets[] = "{$column} = ?";
                $params[] = $value;
            }
        }

        $params[] = $record['id'];
        $sql = "UPDATE {$entry['table_name']} SET " . implode(', ', $sets) . " WHERE id = ?";

        echo "  Restoring " . count($sets) . " columns to previous values" . PHP_EOL;

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Revert DELETE operation (re-insert the deleted record)
     */
    private function revertDelete(array $entry): bool
    {
        if (!$entry['before_data']) {
            echo "  ✗ No before data available to restore" . PHP_EOL;
            return false;
        }

        $beforeData = json_decode($entry['before_data'], true);
        if (!$beforeData || !is_array($beforeData)) {
            echo "  ✗ Invalid before data" . PHP_EOL;
            return false;
        }

        // Restore each deleted record
        $restored = 0;
        foreach ($beforeData as $record) {
            $columns = array_keys($record);
            $placeholders = array_fill(0, count($columns), '?');

            $sql = "INSERT INTO {$entry['table_name']} (" . implode(', ', $columns) . ")
                    VALUES (" . implode(', ', $placeholders) . ")";

            $stmt = $this->pdo->prepare($sql);
            if ($stmt->execute(array_values($record))) {
                $restored++;
            }
        }

        echo "  Restored {$restored} deleted record(s)" . PHP_EOL;
        return $restored > 0;
    }

    /**
     * Revert multiple entries based on range and filter
     */
    public function revertRange(array $range, ?string $filter = null): void
    {
        $sql = "SELECT id FROM audit_log WHERE id BETWEEN ? AND ? AND reverted = 0";
        $params = [$range[0], $range[1]];

        if ($filter) {
            $sql .= " AND (table_name LIKE ? OR calling_script LIKE ? OR sql_query LIKE ?)";
            $filterParam = "%{$filter}%";
            $params[] = $filterParam;
            $params[] = $filterParam;
            $params[] = $filterParam;
        }

        $sql .= " ORDER BY id DESC"; // Revert in reverse order (newest first)

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            echo "No entries found matching criteria." . PHP_EOL;
            return;
        }

        echo PHP_EOL . "Found " . count($ids) . " entries to revert." . PHP_EOL;

        if ($this->dryRun) {
            echo "[DRY RUN MODE - No changes will be made]" . PHP_EOL;
        }

        echo "Proceed? (yes/no): ";
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));

        if (strtolower($line) !== 'yes') {
            echo "Cancelled." . PHP_EOL;
            return;
        }

        $success = 0;
        $failed = 0;

        foreach ($ids as $id) {
            if ($this->revertEntry($id)) {
                $success++;
            } else {
                $failed++;
            }
        }

        echo PHP_EOL . "=== Revert Summary ===" . PHP_EOL;
        echo "Successful: {$success}" . PHP_EOL;
        echo "Failed:     {$failed}" . PHP_EOL;
    }

    /**
     * Pretty print JSON data
     */
    private function printJson(string $json): void
    {
        $data = json_decode($json, true);
        if ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            echo $json . PHP_EOL;
        }
    }

    /**
     * Show diff between before and after
     */
    private function showDiff(string $beforeJson, string $afterJson): void
    {
        $before = json_decode($beforeJson, true);
        $after = json_decode($afterJson, true);

        if (!$before || !$after) {
            echo "Could not parse data for diff" . PHP_EOL;
            return;
        }

        $beforeRecord = $before[0] ?? [];
        $afterRecord = $after[0] ?? [];

        foreach ($beforeRecord as $key => $beforeValue) {
            $afterValue = $afterRecord[$key] ?? null;
            if ($beforeValue !== $afterValue) {
                echo "  {$key}: ";
                echo json_encode($beforeValue) . " → " . json_encode($afterValue) . PHP_EOL;
            }
        }
    }
}

// ===== Main Script =====

$privuma = privuma::getInstance();
$pdo = $privuma->getPDO();
$reverter = new AuditLogReverter($pdo);

// Parse command line arguments
$options = getopt('', [
    'help',
    'list',
    'id:',
    'range:',
    'filter:',
    'dry-run',
    'show:'
]);

if (isset($options['help']) || empty($options)) {
    echo <<<HELP

Database Audit Log Revert Utility
==================================

Usage:
  php revert_audit_log.php [OPTIONS]

Options:
  --help              Show this help message
  --list              List audit log entries
  --show=ID           Show detailed information for entry ID
  --id=ID             Revert single entry by ID
  --range=START-END   Revert entries in ID range (e.g., --range=100-200)
  --filter=KEYWORD    Filter entries by table name, script, or SQL content
  --dry-run           Preview changes without executing them

Examples:
  # List all recent entries
  php revert_audit_log.php --list

  # List entries with filter
  php revert_audit_log.php --list --filter="media"

  # Show details for entry #123
  php revert_audit_log.php --show=123

  # Revert single entry
  php revert_audit_log.php --id=123

  # Revert range of entries
  php revert_audit_log.php --range=100-200

  # Revert range with filter (dry run)
  php revert_audit_log.php --range=100-200 --filter="media" --dry-run

  # Revert filtered entries after confirming
  php revert_audit_log.php --range=100-200 --filter="download-cleaner"


HELP;
    exit(0);
}

// Handle dry-run flag
if (isset($options['dry-run'])) {
    $reverter->setDryRun(true);
    echo "[DRY RUN MODE - No changes will be made]" . PHP_EOL . PHP_EOL;
}

// Handle --list
if (isset($options['list'])) {
    $filter = $options['filter'] ?? null;
    $range = null;

    if (isset($options['range'])) {
        $parts = explode('-', $options['range']);
        if (count($parts) === 2) {
            $range = [(int)$parts[0], (int)$parts[1]];
        }
    }

    $reverter->listEntries($filter, $range);
    exit(0);
}

// Handle --show
if (isset($options['show'])) {
    $reverter->showEntry((int)$options['show']);
    exit(0);
}

// Handle --id (single revert)
if (isset($options['id'])) {
    $reverter->revertEntry((int)$options['id']);
    exit(0);
}

// Handle --range (bulk revert)
if (isset($options['range'])) {
    $parts = explode('-', $options['range']);
    if (count($parts) !== 2) {
        echo "Error: Invalid range format. Use --range=START-END (e.g., --range=100-200)" . PHP_EOL;
        exit(1);
    }

    $range = [(int)$parts[0], (int)$parts[1]];
    $filter = $options['filter'] ?? null;

    $reverter->revertRange($range, $filter);
    exit(0);
}

echo "No action specified. Use --help for usage information." . PHP_EOL;
exit(1);

HELP;
