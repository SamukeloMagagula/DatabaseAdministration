<?php

namespace Tests\Integration;

use App\Database;
use App\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        // The shared connection has no default database, so the app's own
        // tables must be named with their schema here too.
        Database::connection()->exec('TRUNCATE TABLE ' . Database::appTable('login_attempts'));
    }

    public function test_not_locked_out_before_five_failures(): void
    {
        $limiter = new RateLimiter(Database::connection());

        for ($i = 0; $i < 4; $i++) {
            $limiter->recordAttempt('alice', false);
        }

        $this->assertFalse($limiter->isLockedOut('alice'));
    }

    public function test_locked_out_after_five_failures(): void
    {
        $limiter = new RateLimiter(Database::connection());

        for ($i = 0; $i < 5; $i++) {
            $limiter->recordAttempt('alice', false);
        }

        $this->assertTrue($limiter->isLockedOut('alice'));
    }

    public function test_lockout_is_per_identifier(): void
    {
        $limiter = new RateLimiter(Database::connection());

        for ($i = 0; $i < 5; $i++) {
            $limiter->recordAttempt('alice', false);
        }

        $this->assertFalse($limiter->isLockedOut('bob'));
    }
}
