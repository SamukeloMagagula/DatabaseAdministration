<?php

namespace Tests\Integration;

use App\Database;
use App\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = Database::connection();
        // The shared connection has no default database, so the app's own
        // tables must be named with their schema here too.
        foreach (['schema_migrations', 'app_users', 'login_attempts', 'audit_log'] as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . Database::appTable($table));
        }
    }

    public function test_run_applies_all_migration_files(): void
    {
        $runner = new MigrationRunner(Database::connection(), dirname(__DIR__, 2) . '/migrations');

        $applied = $runner->run();

        $this->assertSame(
            ['001_create_app_users.sql', '002_create_login_attempts.sql', '003_create_audit_log.sql'],
            $applied
        );

        $stmt = Database::connection()->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema'
        );
        $stmt->execute(['schema' => Database::appSchemaName()]);
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('app_users', $tables);
        $this->assertContains('login_attempts', $tables);
        $this->assertContains('audit_log', $tables);
    }

    public function test_run_is_idempotent(): void
    {
        $runner = new MigrationRunner(Database::connection(), dirname(__DIR__, 2) . '/migrations');
        $runner->run();

        $second = $runner->run();

        $this->assertSame([], $second);
    }
}
