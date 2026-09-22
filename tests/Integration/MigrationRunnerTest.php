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
        $pdo->exec('DROP TABLE IF EXISTS schema_migrations');
        $pdo->exec('DROP TABLE IF EXISTS app_users');
        $pdo->exec('DROP TABLE IF EXISTS login_attempts');
        $pdo->exec('DROP TABLE IF EXISTS audit_log');
    }

    public function test_run_applies_all_migration_files(): void
    {
        $runner = new MigrationRunner(Database::connection(), dirname(__DIR__, 2) . '/migrations');

        $applied = $runner->run();

        $this->assertSame(
            ['001_create_app_users.sql', '002_create_login_attempts.sql', '003_create_audit_log.sql'],
            $applied
        );

        $tables = Database::connection()
            ->query('SHOW TABLES')
            ->fetchAll(\PDO::FETCH_COLUMN);
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
