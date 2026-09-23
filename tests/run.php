<?php

declare(strict_types=1);

/**
 * Test runner.
 *
 *   php tests/run.php
 *
 * No Composer, no PHPUnit — this app has no dependency toolchain by design,
 * and a suite that needed one installed on the server would not get run.
 * Exits non-zero if anything fails, so it can gate a deploy.
 *
 * Unlike a framework runner, nothing here isolates one test's state from the
 * next automatically: a test that touches the database truncates what it
 * needs in its own body, the same way every test file here already does.
 * That is the price of having no framework, and it is worth paying — the
 * alternative is a mocking layer standing between the tests and the exact SQL
 * this app actually sends, which is precisely what a regression in the SQL
 * console's schema guard needs to run against for real to be caught at all.
 *
 * Needs a disposable MariaDB schema to run against — see
 * tests/config.testing.php.example.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run the tests from the command line.\n");
    exit(1);
}

putenv('DBADMIN_CONFIG=' . __DIR__ . '/config.testing.php');
putenv('DBADMIN_APP_SCHEMA=dbwebui_app_test');

require_once __DIR__ . '/../settings.php';

if (!is_readable(CONFIG_PATH)) {
    fwrite(STDERR, "Missing " . CONFIG_PATH . " — copy tests/config.testing.php.example there"
        . " and point it at a disposable test server.\n");
    exit(1);
}
require CONFIG_PATH;

require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../sql_classifier.php';
require_once __DIR__ . '/../csrf.php';
require_once __DIR__ . '/../view.php';
require_once __DIR__ . '/../db_browser.php';
require_once __DIR__ . '/../grid.php';
require_once __DIR__ . '/../sql_console.php';
require_once __DIR__ . '/../users.php';
require_once __DIR__ . '/../ratelimit.php';

/** Shared PDO connection for tests that need the database. */
function test_pdo(): PDO
{
    static $pdo = null;
    return $pdo ??= connect();
}

/**
 * The schema itself is the one thing outside schema.sql's own remit — in
 * production an administrator creates it before applying the file. Each
 * CREATE TABLE is executed on its own: connect() disables multi-statement
 * queries so that a stacked statement smuggled through the SQL console can
 * never run, and that restriction applies here too.
 */
function apply_test_schema(): void
{
    test_pdo()->exec('CREATE DATABASE IF NOT EXISTS ' . app_schema());

    $statements = array_filter(array_map('trim', explode(';', (string) file_get_contents(__DIR__ . '/../schema.sql'))));
    foreach ($statements as $statement) {
        test_pdo()->exec(str_replace('CREATE TABLE IF NOT EXISTS ', 'CREATE TABLE IF NOT EXISTS ' . app_schema() . '.', $statement));
    }
}
apply_test_schema();

final class Results
{
    public static int $passed = 0;
    /** @var string[] */
    public static array $failed = [];
}

function test(string $name, callable $body): void
{
    try {
        $body();
        Results::$passed++;
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        Results::$failed[] = $name;
        printf("  FAIL %s\n         %s\n", $name, $e->getMessage());
    }
}

/** Fail the current test unless $condition holds. */
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** Fail the current test unless the two values are identical. */
function same($expected, $actual, string $label = 'value'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $label, var_export($expected, true), var_export($actual, true)
        ));
    }
}

$files = glob(__DIR__ . '/test_*.php') ?: [];
sort($files);

if (!$files) {
    fwrite(STDERR, "No test files found in " . __DIR__ . "\n");
    exit(1);
}

foreach ($files as $file) {
    echo basename($file) . "\n";
    require $file;
    echo "\n";
}

printf("%d passed, %d failed\n", Results::$passed, count(Results::$failed));
foreach (Results::$failed as $name) {
    echo "  failed: {$name}\n";
}

exit(Results::$failed ? 1 : 0);
