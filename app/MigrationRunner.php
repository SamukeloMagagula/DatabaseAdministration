<?php

namespace App;

use PDO;

final class MigrationRunner
{
    public function __construct(private PDO $pdo, private string $migrationsDir)
    {
    }

    public function run(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->appliedMigrations();

        $appliedNow = [];
        foreach ($this->migrationFiles() as $file) {
            if (in_array($file, $applied, true)) {
                continue;
            }
            $sql = file_get_contents($this->migrationsDir . '/' . $file);
            $this->pdo->exec($sql);

            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (:f)');
            $stmt->execute(['f' => $file]);

            $appliedNow[] = $file;
        }

        return $appliedNow;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(191) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function appliedMigrations(): array
    {
        return $this->pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function migrationFiles(): array
    {
        $files = glob($this->migrationsDir . '/*.sql');
        sort($files);
        return array_map('basename', $files);
    }
}
