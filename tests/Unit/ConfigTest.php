<?php

namespace Tests\Unit;

use App\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_load_reads_key_value_pairs_from_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "DB_HOST=127.0.0.1\n# a comment\n\nDB_PORT=3306\n");

        Config::load($path);

        $this->assertSame('127.0.0.1', Config::get('DB_HOST'));
        $this->assertSame('3306', Config::get('DB_PORT'));

        unlink($path);
    }

    public function test_get_returns_default_when_key_missing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "DB_HOST=127.0.0.1\n");

        Config::load($path);

        $this->assertSame('fallback', Config::get('MISSING_KEY', 'fallback'));

        unlink($path);
    }

    public function test_load_throws_when_file_does_not_exist(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::load('/no/such/file.env');
    }
}
