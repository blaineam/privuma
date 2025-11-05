# Database Audit Log System

Complete audit logging for all database modifications with revert capabilities.

## Overview

The database audit log system automatically tracks all INSERT, UPDATE, and DELETE operations, recording:
- Timestamp of change
- Operation type (INSERT/UPDATE/DELETE)
- Table name and record ID
- Calling script/job and line number
- Before and after data (JSON)
- Original SQL query
- Revert status

## Quick Start

### 1. Run Migration

First, create the audit_log table:

```bash
cd /path/to/privuma/web/scripts
php migrate_audit_log.php
```

### 2. Enable Audit Logging

Audit logging is automatically enabled when using `privuma::getInstance()->getPDO()`. All database operations through the PDO instance will be logged.

## Viewing Audit Logs

### List Recent Entries

```bash
php scripts/revert_audit_log.php --list
```

### Filter by Keyword

```bash
php scripts/revert_audit_log.php --list --filter="media"
```

### View Specific ID Range

```bash
php scripts/revert_audit_log.php --list --range=100-200
```

### Show Detailed Entry

```bash
php scripts/revert_audit_log.php --show=123
```

This displays:
- Full timestamp and operation details
- Calling script and line number
- SQL query executed
- Before data (JSON)
- After data (JSON)
- Diff showing changes

## Reverting Changes

### Revert Single Entry

```bash
php scripts/revert_audit_log.php --id=123
```

### Revert Range

```bash
php scripts/revert_audit_log.php --range=100-200
```

Reverts entries in reverse order (newest first) and prompts for confirmation.

### Revert with Filter

```bash
php scripts/revert_audit_log.php --range=100-200 --filter="download-cleaner"
```

Only reverts entries matching the filter string (searches table name, script, and SQL query).

### Dry Run Mode

Preview what would be reverted without making changes:

```bash
php scripts/revert_audit_log.php --range=100-200 --filter="media" --dry-run
```

## How Reverts Work

### INSERT Operations
Reverts by **deleting** the inserted record using the record ID.

### UPDATE Operations
Reverts by **restoring** the previous values from `before_data`.

### DELETE Operations
Reverts by **re-inserting** the deleted record(s) from `before_data`.

## Examples

### Find what a job modified

```bash
# List all changes made by download-cleaner job
php scripts/revert_audit_log.php --list --filter="download-cleaner"

# Show details for specific entry
php scripts/revert_audit_log.php --show=456

# Revert all changes from that job
php scripts/revert_audit_log.php --range=450-500 --filter="download-cleaner"
```

### Recover from bulk deletion

```bash
# Find DELETE operations in recent entries
php scripts/revert_audit_log.php --list --filter="DELETE" --range=1000-1500

# Preview revert
php scripts/revert_audit_log.php --range=1000-1500 --filter="DELETE" --dry-run

# Actually revert
php scripts/revert_audit_log.php --range=1000-1500 --filter="DELETE"
```

### Undo specific table changes

```bash
# Find changes to media table
php scripts/revert_audit_log.php --list --filter="media" --range=2000-2100

# Revert only media table changes
php scripts/revert_audit_log.php --range=2000-2100 --filter="media"
```

## Implementation Details

### Files

- **`app/helpers/dbAuditLog.php`** - Core audit logging class
- **`app/helpers/AuditedPDO.php`** - PDO wrapper that intercepts operations
- **`scripts/migrate_audit_log.php`** - Database migration script
- **`scripts/revert_audit_log.php`** - Revert utility CLI tool

### Database Schema

```sql
CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp TEXT NOT NULL,
    operation TEXT NOT NULL,           -- INSERT/UPDATE/DELETE
    table_name TEXT NOT NULL,
    record_id TEXT,                    -- Affected record ID
    calling_script TEXT NOT NULL,      -- Script/job name
    line_number INTEGER,               -- Line number in script
    before_data TEXT,                  -- JSON before changes
    after_data TEXT,                   -- JSON after changes
    sql_query TEXT,                    -- Original SQL
    reverted INTEGER DEFAULT 0,        -- Revert status
    reverted_at TEXT                   -- Revert timestamp
);
```

### Performance Considerations

- Audit logging adds minimal overhead (~10-20ms per operation)
- `before_data` queries are optimized to fetch only affected records
- Indexes on timestamp, operation, table, script, and reverted status
- Recursive logging is prevented (audit operations don't log themselves)
- Transactions are used for safe reverts

### Limitations

- Only captures operations through the PDO wrapper
- Direct SQL exec() calls without parameters don't capture bound values
- Large batch operations may generate significant audit data
- SQLite AUTO INCREMENT may not preserve exact IDs on re-insert

## Maintenance

### Check Audit Log Size

```bash
# Count total entries
sqlite3 /path/to/db.sqlite3 "SELECT COUNT(*) FROM audit_log"

# Check unrevrted entries
sqlite3 /path/to/db.sqlite3 "SELECT COUNT(*) FROM audit_log WHERE reverted = 0"
```

### Archive Old Entries

```bash
# Export entries older than 90 days
sqlite3 /path/to/db.sqlite3 "SELECT * FROM audit_log WHERE timestamp < datetime('now', '-90 days')" > audit_archive.sql

# Delete old entries
sqlite3 /path/to/db.sqlite3 "DELETE FROM audit_log WHERE timestamp < datetime('now', '-90 days') AND reverted = 1"
```

## Troubleshooting

### "No before data available"

Some DELETE/UPDATE reverts may fail if the `before_data` wasn't captured. This can happen if:
- The WHERE clause was too complex to parse
- The operation was done outside the PDO wrapper
- The audit log table didn't exist at the time

### "Already reverted"

An entry can only be reverted once. Check `reverted_at` timestamp to see when it was reverted.

### Circular dependencies

When reverting foreign key constrained tables, you may need to temporarily disable foreign key checks or revert in specific order.

## Safety

- All reverts use transactions (atomic - either all succeed or all fail)
- Dry run mode available for testing
- Confirmation prompts for bulk operations
- Reverted entries are marked to prevent double-revert
- Original data is never deleted from audit log
