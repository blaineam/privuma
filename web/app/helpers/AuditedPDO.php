<?php

namespace privuma\helpers;

use PDO;
use PDOStatement;

/**
 * Audited PDO wrapper that logs all database modifications
 */
class AuditedPDO extends PDO
{
    private dbAuditLog $auditLog;
    private PDO $wrappedPDO;

    public function __construct(PDO $pdo)
    {
        $this->wrappedPDO = $pdo;
        $this->auditLog = new dbAuditLog($pdo);
    }

    /**
     * Prepare statement - return audited statement wrapper
     */
    public function prepare($query, $options = []): PDOStatement|false
    {
        $stmt = $this->wrappedPDO->prepare($query, $options);
        if ($stmt === false) {
            return false;
        }
        return new AuditedPDOStatement($stmt, $query, $this->auditLog, $this->wrappedPDO);
    }

    /**
     * Execute query directly
     */
    public function exec($query): int|false
    {
        $result = $this->wrappedPDO->exec($query);

        if ($result !== false && !dbAuditLog::isInAuditOperation()) {
            // Determine operation type
            $operation = $this->getOperationType($query);
            if ($operation) {
                $this->auditLog->logOperation($operation, $query);
            }
        }

        return $result;
    }

    /**
     * Query and return statement
     */
    public function query($query, $fetchMode = null, ...$fetchModeArgs): PDOStatement|false
    {
        if ($fetchMode === null) {
            $stmt = $this->wrappedPDO->query($query);
        } else {
            $stmt = $this->wrappedPDO->query($query, $fetchMode, ...$fetchModeArgs);
        }

        if ($stmt !== false && !dbAuditLog::isInAuditOperation()) {
            $operation = $this->getOperationType($query);
            if ($operation) {
                $this->auditLog->logOperation($operation, $query);
            }
        }

        return $stmt;
    }

    /**
     * Get last insert ID
     */
    public function lastInsertId($name = null): string|false
    {
        return $this->wrappedPDO->lastInsertId($name);
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): bool
    {
        return $this->wrappedPDO->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        return $this->wrappedPDO->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollBack(): bool
    {
        return $this->wrappedPDO->rollBack();
    }

    /**
     * Check if in transaction
     */
    public function inTransaction(): bool
    {
        return $this->wrappedPDO->inTransaction();
    }

    /**
     * Get attribute
     */
    public function getAttribute($attribute): mixed
    {
        return $this->wrappedPDO->getAttribute($attribute);
    }

    /**
     * Set attribute
     */
    public function setAttribute($attribute, $value): bool
    {
        return $this->wrappedPDO->setAttribute($attribute, $value);
    }

    /**
     * Get error code
     */
    public function errorCode(): ?string
    {
        return $this->wrappedPDO->errorCode();
    }

    /**
     * Get error info
     */
    public function errorInfo(): array
    {
        return $this->wrappedPDO->errorInfo();
    }

    /**
     * Quote string
     */
    public function quote($string, $type = PDO::PARAM_STR): string|false
    {
        return $this->wrappedPDO->quote($string, $type);
    }

    /**
     * Determine operation type from SQL
     */
    private function getOperationType(string $sql): ?string
    {
        $sql = trim(strtoupper($sql));
        if (str_starts_with($sql, 'INSERT')) {
            return 'INSERT';
        }
        if (str_starts_with($sql, 'UPDATE')) {
            return 'UPDATE';
        }
        if (str_starts_with($sql, 'DELETE')) {
            return 'DELETE';
        }
        return null; // SELECT and other read operations are not logged
    }
}

/**
 * Audited PDOStatement wrapper
 */
class AuditedPDOStatement extends PDOStatement
{
    private PDOStatement $wrappedStmt;
    private string $query;
    private dbAuditLog $auditLog;
    private PDO $pdo;
    private array $boundParams = [];

    public function __construct(PDOStatement $stmt, string $query, dbAuditLog $auditLog, PDO $pdo)
    {
        $this->wrappedStmt = $stmt;
        $this->query = $query;
        $this->auditLog = $auditLog;
        $this->pdo = $pdo;
    }

    /**
     * Bind parameter
     */
    public function bindParam($param, &$var, $type = PDO::PARAM_STR, $maxLength = null, $driverOptions = null): bool
    {
        $this->boundParams[$param] = &$var;
        return $this->wrappedStmt->bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    /**
     * Bind value
     */
    public function bindValue($param, $value, $type = PDO::PARAM_STR): bool
    {
        $this->boundParams[$param] = $value;
        return $this->wrappedStmt->bindValue($param, $value, $type);
    }

    /**
     * Execute statement
     */
    public function execute($params = null): bool
    {
        $result = $this->wrappedStmt->execute($params);

        if ($result && !dbAuditLog::isInAuditOperation()) {
            $operation = $this->getOperationType($this->query);
            if ($operation) {
                $executeParams = $params ?? $this->boundParams;
                $lastInsertId = null;

                if ($operation === 'INSERT') {
                    $lastInsertId = $this->pdo->lastInsertId();
                }

                $this->auditLog->logOperation(
                    $operation,
                    $this->query,
                    $executeParams,
                    $lastInsertId
                );
            }
        }

        return $result;
    }

    /**
     * Fetch result
     */
    public function fetch($mode = PDO::FETCH_DEFAULT, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0): mixed
    {
        return $this->wrappedStmt->fetch($mode, $cursorOrientation, $cursorOffset);
    }

    /**
     * Fetch all results
     */
    public function fetchAll($mode = PDO::FETCH_DEFAULT, ...$args): array
    {
        return $this->wrappedStmt->fetchAll($mode, ...$args);
    }

    /**
     * Fetch column
     */
    public function fetchColumn($column = 0): mixed
    {
        return $this->wrappedStmt->fetchColumn($column);
    }

    /**
     * Row count
     */
    public function rowCount(): int
    {
        return $this->wrappedStmt->rowCount();
    }

    /**
     * Set fetch mode
     */
    public function setFetchMode($mode, ...$args): bool
    {
        return $this->wrappedStmt->setFetchMode($mode, ...$args);
    }

    /**
     * Get error code
     */
    public function errorCode(): ?string
    {
        return $this->wrappedStmt->errorCode();
    }

    /**
     * Get error info
     */
    public function errorInfo(): array
    {
        return $this->wrappedStmt->errorInfo();
    }

    /**
     * Determine operation type from SQL
     */
    private function getOperationType(string $sql): ?string
    {
        $sql = trim(strtoupper($sql));
        if (str_starts_with($sql, 'INSERT')) {
            return 'INSERT';
        }
        if (str_starts_with($sql, 'UPDATE')) {
            return 'UPDATE';
        }
        if (str_starts_with($sql, 'DELETE')) {
            return 'DELETE';
        }
        return null;
    }
}
