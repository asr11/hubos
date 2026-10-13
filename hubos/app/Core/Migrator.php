<?php

declare(strict_types=1);

namespace HubOS\Core;

use PDO;
use RuntimeException;
use Throwable;

/** Applies numbered SQL files from database/migrations exactly once. */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    /** @return list<string> versions applied in this run */
    public function run(): array
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version    TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
            )"
        );

        $done = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $done, true)) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Cannot read migration {$version}");
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                DB::run($this->pdo, 'INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new RuntimeException("Migration {$version} failed: " . $e->getMessage(), 0, $e);
            }
            $applied[] = $version;
        }

        return $applied;
    }
}
