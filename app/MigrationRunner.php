<?php

namespace App;

use PDO;

final class MigrationRunner
{
    /**
     * Token migration files use in place of the app schema name, expanded to the
     * backtick-quoted value of DB_APP_SCHEMA before the file is executed. The
     * shared connection has no default database (see Database::connection()),
     * so every migration must name its schema explicitly.
     */
    public const SCHEMA_TOKEN = '{{app_schema}}';

    public function __construct(private PDO $pdo, private string $migrationsDir)
    {
    }

    public function run(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->appliedMigrations();
        $migrations = Database::appTable('schema_migrations');

        $appliedNow = [];
        foreach ($this->migrationFiles() as $file) {
            if (in_array($file, $applied, true)) {
                continue;
            }
            $sql = (string) file_get_contents($this->migrationsDir . '/' . $file);
            $this->pdo->exec(str_replace(self::SCHEMA_TOKEN, Database::appSchema(), $sql));

            $stmt = $this->pdo->prepare("INSERT INTO {$migrations} (filename) VALUES (:f)");
            $stmt->execute(['f' => $file]);

            $appliedNow[] = $file;
        }

        return $appliedNow;
    }

    private function ensureMigrationsTable(): void
    {
        $migrations = Database::appTable('schema_migrations');
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$migrations} (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(191) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function appliedMigrations(): array
    {
        $migrations = Database::appTable('schema_migrations');

        return $this->pdo->query("SELECT filename FROM {$migrations}")->fetchAll(PDO::FETCH_COLUMN);
    }

    private function migrationFiles(): array
    {
        $files = glob($this->migrationsDir . '/*.sql');
        sort($files);
        return array_map('basename', $files);
    }
}
