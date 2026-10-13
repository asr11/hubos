<?php

declare(strict_types=1);

namespace HubOS\Core;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Tenant-scoped data access. Every read/write goes through here and is
 * forced to `tenant_id = <current tenant>`. Table and column names are
 * validated identifiers; values are always bound parameters.
 */
final class Tenant
{
    /** Tables that carry tenant_id and may be accessed through this scope. */
    private const TABLES = ['clients', 'users', 'events', 'tenant_modules', 'tenant_settings', 'user_permissions'];

    public function __construct(private PDO $pdo, private int $tenantId)
    {
        if ($tenantId < 1) {
            throw new InvalidArgumentException('Invalid tenant id');
        }
    }

    public function id(): int
    {
        return $this->tenantId;
    }

    /** @return array<string, mixed>|null */
    public function find(string $table, int $id): ?array
    {
        $sql = 'SELECT * FROM ' . $this->table($table) . ' WHERE tenant_id = :tenant_id AND id = :id';
        $row = DB::run($this->pdo, $sql, ['tenant_id' => $this->tenantId, 'id' => $id])->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, scalar|null> $where column => value (AND, equality)
     * @return list<array<string, mixed>>
     */
    public function all(string $table, array $where = [], string $orderBy = 'id', string $direction = 'DESC', int $limit = 200, int $offset = 0): array
    {
        [$clause, $params] = $this->where($where);
        $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $sql = 'SELECT * FROM ' . $this->table($table) . ' WHERE ' . $clause
            . ' ORDER BY ' . self::ident($orderBy) . ' ' . $dir
            . ' LIMIT ' . max(1, min($limit, 1000)) . ' OFFSET ' . max(0, $offset);
        return DB::run($this->pdo, $sql, $params)->fetchAll();
    }

    /** @param array<string, scalar|null> $where */
    public function count(string $table, array $where = []): int
    {
        [$clause, $params] = $this->where($where);
        $sql = 'SELECT COUNT(*) FROM ' . $this->table($table) . ' WHERE ' . $clause;
        return (int) DB::run($this->pdo, $sql, $params)->fetchColumn();
    }

    /** @param array<string, scalar|null> $data */
    public function insert(string $table, array $data): int
    {
        unset($data['tenant_id'], $data['id']);
        $data = ['tenant_id' => $this->tenantId] + $data;
        $cols = [];
        $marks = [];
        $params = [];
        foreach ($data as $col => $value) {
            $cols[] = self::ident((string) $col);
            $marks[] = '?';
            $params[] = $value;
        }
        $sql = 'INSERT INTO ' . $this->table($table) . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $marks) . ')';
        DB::run($this->pdo, $sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, scalar|null> $data
     * @return int affected rows (0 if the row belongs to another tenant)
     */
    public function update(string $table, int $id, array $data): int
    {
        unset($data['tenant_id'], $data['id']);
        if ($data === []) {
            return 0;
        }
        $sets = [];
        $params = [];
        foreach ($data as $col => $value) {
            $sets[] = self::ident((string) $col) . ' = ?';
            $params[] = $value;
        }
        $params[] = $this->tenantId;
        $params[] = $id;
        $sql = 'UPDATE ' . $this->table($table) . ' SET ' . implode(', ', $sets) . ' WHERE tenant_id = ? AND id = ?';
        return DB::run($this->pdo, $sql, $params)->rowCount();
    }

    /**
     * Scoped UPDATE by equality conditions (for tables without an id column).
     *
     * @param array<string, scalar|null> $data
     * @param array<string, scalar|null> $where
     */
    public function updateWhere(string $table, array $data, array $where): int
    {
        unset($data['tenant_id'], $data['id']);
        if ($data === [] || $where === []) {
            throw new InvalidArgumentException('updateWhere requires data and conditions');
        }
        $sets = [];
        $setParams = [];
        foreach ($data as $col => $value) {
            $sets[] = self::ident((string) $col) . ' = ?';
            $setParams[] = $value;
        }
        [$clause, $params] = $this->where($where);
        $sql = 'UPDATE ' . $this->table($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $clause;
        return DB::run($this->pdo, $sql, array_merge($setParams, $params))->rowCount();
    }

    /**
     * Scoped DELETE by equality conditions. At least one condition is required.
     *
     * @param array<string, scalar|null> $where
     */
    public function deleteWhere(string $table, array $where): int
    {
        if ($where === []) {
            throw new InvalidArgumentException('deleteWhere requires conditions');
        }
        [$clause, $params] = $this->where($where);
        return DB::run($this->pdo, 'DELETE FROM ' . $this->table($table) . ' WHERE ' . $clause, $params)->rowCount();
    }

    /**
     * Raw SELECT. The SQL MUST contain the :tenant_id placeholder, which is
     * always bound to the current tenant (callers cannot override it).
     *
     * @param array<string, scalar|null> $params named params (without tenant_id)
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->runScoped($sql, $params)->fetchAll();
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->runScoped($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, scalar|null> $params */
    private function runScoped(string $sql, array $params): \PDOStatement
    {
        if (preg_match('/:tenant_id\b/', $sql) !== 1) {
            throw new InvalidArgumentException('Tenant-scoped query must reference :tenant_id');
        }
        if (preg_match('/^\s*SELECT\b/i', $sql) !== 1) {
            throw new InvalidArgumentException('Only SELECT is allowed in Tenant::select');
        }
        foreach (array_keys($params) as $key) {
            if (!is_string($key) || ltrim($key, ':') === 'tenant_id') {
                throw new InvalidArgumentException('Params must be named and must not include tenant_id');
            }
        }
        $params['tenant_id'] = $this->tenantId;
        return DB::run($this->pdo, $sql, $params);
    }

    /**
     * @param array<string, scalar|null> $where
     * @return array{0:string, 1:list<scalar|null>}
     */
    private function where(array $where): array
    {
        unset($where['tenant_id']);
        $parts = ['tenant_id = ?'];
        $params = [$this->tenantId];
        foreach ($where as $col => $value) {
            if ($value === null) {
                $parts[] = self::ident((string) $col) . ' IS NULL';
                continue;
            }
            $parts[] = self::ident((string) $col) . ' = ?';
            $params[] = $value;
        }
        return [implode(' AND ', $parts), $params];
    }

    private function table(string $table): string
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException("Table is not tenant-scoped: {$table}");
        }
        return self::ident($table);
    }

    private static function ident(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid identifier');
        }
        return '"' . $name . '"';
    }
}
