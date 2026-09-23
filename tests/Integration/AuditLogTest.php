<?php

namespace Tests\Integration;

use App\AuditLog;
use App\Database;
use PHPUnit\Framework\TestCase;

final class AuditLogTest extends TestCase
{
    protected function setUp(): void
    {
        // The shared connection has no default database, so the app's own
        // tables must be named with their schema here too.
        Database::connection()->exec('TRUNCATE TABLE ' . Database::appTable('audit_log'));
    }

    public function test_record_and_recent_round_trip(): void
    {
        $log = new AuditLog(Database::connection());

        $log->record(1, 'alice', 'SQL_EXEC', null, null, 'SELECT 1');
        $log->record(2, 'bob', 'ROW_DELETE', 'shop', 'orders', '{"pk":5}');

        $entries = $log->recent();

        $this->assertCount(2, $entries);
        $this->assertSame('bob', $entries[0]['username']);
        $this->assertSame('alice', $entries[1]['username']);
    }

    public function test_recent_filters_by_username(): void
    {
        $log = new AuditLog(Database::connection());
        $log->record(1, 'alice', 'SQL_EXEC', null, null, 'SELECT 1');
        $log->record(2, 'bob', 'SQL_EXEC', null, null, 'SELECT 2');

        $entries = $log->recent(50, 0, 'bob');

        $this->assertCount(1, $entries);
        $this->assertSame('bob', $entries[0]['username']);
    }

    public function test_recent_respects_limit_and_offset(): void
    {
        $log = new AuditLog(Database::connection());
        for ($i = 0; $i < 3; $i++) {
            $log->record(1, 'alice', 'SQL_EXEC', null, null, "SELECT {$i}");
        }

        $page = $log->recent(1, 1);

        $this->assertCount(1, $page);
    }
}
