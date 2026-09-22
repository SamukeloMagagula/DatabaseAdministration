# MariaDB Web Admin Tool — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a plain-PHP web admin tool for MariaDB — login/roles, database & table browser, data grid CRUD, SQL console, and an audit log — deployable on a fresh RHEL-family server that currently only has MariaDB installed.

**Architecture:** A single plain-PHP application (no framework) served by Nginx + PHP-FPM, connecting to MariaDB over localhost via PDO. The app has its own `dbwebui_app` schema (app_users, login_attempts, audit_log, schema_migrations) and always connects as one service account; roles (admin/editor/viewer) are enforced entirely in PHP. Pages are server-rendered PHP templates; navigation, sorting, filtering, and pagination are done via plain links/forms (no client-side JS is required for functionality — this is a deliberate simplification of the spec's "light JS" wording, since forms/links deliver the same behavior with less code and no build step).

**Tech Stack:** PHP 8.1+, PDO (mysql driver), Composer (autoload + dev tooling only, no framework), PHPUnit 10, Nginx, PHP-FPM, MariaDB.

**Spec:** `docs/superpowers/specs/2026-09-21-mariadb-web-admin-phase1-design.md`

## Global Constraints

- Plain PHP, no framework. Composer is used only for PSR-4 autoloading and PHPUnit.
- Every SQL identifier (database, table, or column name) that comes from user input MUST be validated against `information_schema` before being interpolated into a SQL string. Every value MUST be bound via a PDO prepared statement placeholder — never concatenated.
- Every state-changing POST request MUST validate a CSRF token before doing anything else.
- Every SQL console execution and every row mutation (insert/update/delete) is recorded to `audit_log`, including statements that error out.
- Roles are `admin`, `editor`, `viewer` (see `App\Roles`), enforced only in PHP — the app always connects to MariaDB as one service account.
- Passwords are hashed with PHP's `password_hash()` (never stored or logged in plaintext). Sessions are regenerated on login. Session cookies are `httponly`, `secure`, `samesite=Strict`.
- Login lockout: after 5 consecutive failed attempts for an identifier, that identifier is locked out for 15 minutes.
- Data grid default page size is 50 rows, configurable via query string, capped at 500.
- The database browser excludes `mysql`, `information_schema`, `performance_schema`, `sys`, and the app's own `dbwebui_app` schema from the visible list.
- Guard methods (`Auth::requireLogin()`, `Auth::requireRole()`) return `null` to mean "proceed" and a non-null string to mean "stop and return this instead." Callers MUST check with `!== null`, never plain truthiness — `Http::redirect()` returns an empty string, which is falsy in PHP and would silently defeat a truthy check.

## Prerequisites (one-time, before Task 1)

You need PHP 8.1+, Composer, and access to a MariaDB server (local or remote) from your dev machine, even though the final deployment target is the Linux server. On the MariaDB instance you'll use for development/testing, run:

```sql
CREATE DATABASE dbwebui_app;
CREATE DATABASE dbwebui_app_test;
CREATE USER 'dbwebui_svc'@'localhost' IDENTIFIED BY 'changeme';
GRANT ALL PRIVILEGES ON dbwebui_app.* TO 'dbwebui_svc'@'localhost';
GRANT ALL PRIVILEGES ON dbwebui_app_test.* TO 'dbwebui_svc'@'localhost';
GRANT CREATE, DROP, ALTER, INSERT, UPDATE, DELETE, SELECT, INDEX, REFERENCES ON *.* TO 'dbwebui_svc'@'localhost';
FLUSH PRIVILEGES;
```

The broad `*.*` grant is so integration tests can create/drop a disposable fixture schema (`wbtest_fixture`) and browse arbitrary databases — this is for your local dev/test MariaDB only. Task 16 documents a more narrowly-scoped grant for the production server.

---

### Task 1: Project scaffolding & core helpers (Config, View, Http)

**Files:**
- Create: `composer.json`
- Create: `.gitignore`
- Create: `.env.example`
- Create: `.env.testing.example`
- Create: `phpunit.xml`
- Create: `app/Config.php`
- Create: `app/View.php`
- Create: `app/Http.php`
- Create: `tests/bootstrap.php`
- Create: `tests/Unit/ConfigTest.php`
- Create: `tests/Unit/ViewTest.php`
- Create: `tests/Unit/HttpTest.php`
- Create: `tests/Fixtures/views/hello.php`

**Interfaces:**
- Produces: `App\Config::load(string $envFile): void`, `App\Config::get(string $key, ?string $default = null): ?string`
- Produces: `App\View::render(string $view, array $data = [], ?string $layout = 'layout'): string`, `App\View::e(?string $value): string`, `App\View::setViewsPath(string $path): void`
- Produces: `App\Http::redirect(string $path): string`

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "acme/dbwebui",
    "description": "Custom web admin UI for MariaDB",
    "type": "project",
    "require": {
        "php": ">=8.1",
        "ext-pdo": "*",
        "ext-pdo_mysql": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Install dependencies**

Run: `composer install`
Expected: `vendor/` is created, `composer.lock` is generated.

- [ ] **Step 3: Create .gitignore**

```
/vendor/
/.env
/.env.testing
/composer.lock
.phpunit.cache/
```

- [ ] **Step 4: Create .env.example and .env.testing.example**

`.env.example`:
```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=dbwebui_svc
DB_PASS=changeme
DB_APP_SCHEMA=dbwebui_app
```

`.env.testing.example`:
```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=dbwebui_svc
DB_PASS=changeme
DB_APP_SCHEMA=dbwebui_app_test
```

- [ ] **Step 5: Copy the testing env file for local use**

Run: `cp .env.testing.example .env.testing` (adjust credentials if yours differ from the Prerequisites section)

- [ ] **Step 6: Write the failing test for Config**

Create `tests/Unit/ConfigTest.php`:

```php
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
```

Also create `tests/bootstrap.php` (needed for `phpunit.xml` below, and by every later test — it loads autoload + `.env.testing`):

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config;

$envFile = __DIR__ . '/../.env.testing';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Missing .env.testing — copy .env.testing.example and point it at a disposable test schema.\n");
    exit(1);
}
Config::load($envFile);
```

And `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 7: Run the test to see it fail**

Run: `vendor/bin/phpunit tests/Unit/ConfigTest.php`
Expected: FAIL — `Class "App\Config" not found`.

- [ ] **Step 8: Implement Config**

Create `app/Config.php`:

```php
<?php

namespace App;

final class Config
{
    private static array $values = [];

    public static function load(string $envFile): void
    {
        if (!file_exists($envFile)) {
            throw new \RuntimeException("Config file not found: {$envFile}");
        }

        self::$values = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            self::$values[trim($key)] = trim($value);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? $default;
    }
}
```

- [ ] **Step 9: Run the test to see it pass**

Run: `vendor/bin/phpunit tests/Unit/ConfigTest.php`
Expected: PASS (3 tests).

- [ ] **Step 10: Write the failing tests for View**

Create `tests/Fixtures/views/hello.php`:

```php
Hello, <?= $name ?>!
```

Create `tests/Unit/ViewTest.php`:

```php
<?php

namespace Tests\Unit;

use App\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    protected function tearDown(): void
    {
        View::setViewsPath(dirname(__DIR__, 2) . '/resources/views');
    }

    public function test_render_injects_data_into_template_with_no_layout(): void
    {
        View::setViewsPath(__DIR__ . '/../Fixtures/views');

        $html = View::render('hello', ['name' => 'World'], null);

        $this->assertSame('Hello, World!', trim($html));
    }

    public function test_e_escapes_html_special_characters(): void
    {
        $this->assertSame('&lt;script&gt;', View::e('<script>'));
    }

    public function test_e_handles_null(): void
    {
        $this->assertSame('', View::e(null));
    }
}
```

- [ ] **Step 11: Run the tests to see them fail**

Run: `vendor/bin/phpunit tests/Unit/ViewTest.php`
Expected: FAIL — `Class "App\View" not found`.

- [ ] **Step 12: Implement View**

Create `app/View.php`:

```php
<?php

namespace App;

final class View
{
    private static ?string $viewsPath = null;

    public static function setViewsPath(string $path): void
    {
        self::$viewsPath = $path;
    }

    public static function render(string $view, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::renderTemplate($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::renderTemplate($layout, $data + ['content' => $content]);
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private static function renderTemplate(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require self::viewsPath() . "/{$view}.php";
        return (string) ob_get_clean();
    }

    private static function viewsPath(): string
    {
        return self::$viewsPath ?? dirname(__DIR__) . '/resources/views';
    }
}
```

- [ ] **Step 13: Run the tests to see them pass**

Run: `vendor/bin/phpunit tests/Unit/ViewTest.php`
Expected: PASS (3 tests).

- [ ] **Step 14: Write the failing test for Http**

Create `tests/Unit/HttpTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Http;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function test_redirect_returns_empty_body(): void
    {
        $this->assertSame('', Http::redirect('/somewhere'));
    }
}
```

- [ ] **Step 15: Run it to see it fail, then implement Http**

Run: `vendor/bin/phpunit tests/Unit/HttpTest.php` — expect FAIL (`Class "App\Http" not found`).

Create `app/Http.php`:

```php
<?php

namespace App;

final class Http
{
    public static function redirect(string $path): string
    {
        header("Location: {$path}");
        return '';
    }
}
```

- [ ] **Step 16: Run all Unit tests to confirm everything passes**

Run: `vendor/bin/phpunit tests/Unit`
Expected: PASS (all tests green).

- [ ] **Step 17: Commit**

```bash
git add composer.json composer.lock .gitignore .env.example .env.testing.example phpunit.xml app/Config.php app/View.php app/Http.php tests/bootstrap.php tests/Unit/ConfigTest.php tests/Unit/ViewTest.php tests/Unit/HttpTest.php tests/Fixtures/views/hello.php
git commit -m "Add project scaffolding and Config/View/Http helpers"
```

---

### Task 2: Database connection, migrations, and migration runner

**Files:**
- Create: `app/Database.php`
- Create: `app/MigrationRunner.php`
- Create: `migrations/001_create_app_users.sql`
- Create: `migrations/002_create_login_attempts.sql`
- Create: `migrations/003_create_audit_log.sql`
- Create: `bin/migrate.php`
- Create: `tests/Integration/MigrationRunnerTest.php`
- Modify: `tests/bootstrap.php` (run migrations automatically before the suite)

**Interfaces:**
- Consumes: `App\Config::get()` (Task 1)
- Produces: `App\Database::connection(): \PDO`, `App\Database::reset(): void`
- Produces: `App\MigrationRunner::__construct(\PDO $pdo, string $migrationsDir)`, `App\MigrationRunner::run(): array`

- [ ] **Step 1: Implement Database (no test needed — thin PDO factory, exercised by every integration test that follows)**

Create `app/Database.php`:

```php
<?php

namespace App;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', '127.0.0.1'),
                Config::get('DB_PORT', '3306'),
                Config::get('DB_APP_SCHEMA', 'dbwebui_app')
            );
            self::$connection = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$connection;
    }

    public static function reset(): void
    {
        self::$connection = null;
    }
}
```

- [ ] **Step 2: Write the failing test for MigrationRunner**

Create `tests/Integration/MigrationRunnerTest.php`:

```php
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
```

- [ ] **Step 3: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/MigrationRunnerTest.php`
Expected: FAIL — `Class "App\MigrationRunner" not found`.

- [ ] **Step 4: Create the migration SQL files**

`migrations/001_create_app_users.sql`:

```sql
CREATE TABLE IF NOT EXISTS app_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(16) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`migrations/002_create_login_attempts.sql`:

```sql
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(191) NOT NULL,
    succeeded TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_created (identifier, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`migrations/003_create_audit_log.sql`:

```sql
CREATE TABLE IF NOT EXISTS audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_user_id INT UNSIGNED NULL,
    username VARCHAR(64) NOT NULL,
    action_type VARCHAR(32) NOT NULL,
    target_db VARCHAR(64) NULL,
    target_table VARCHAR(64) NULL,
    detail TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 5: Implement MigrationRunner**

Create `app/MigrationRunner.php`:

```php
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
```

- [ ] **Step 6: Run the test to see it pass**

Run: `vendor/bin/phpunit tests/Integration/MigrationRunnerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Create the CLI migration script**

Create `bin/migrate.php`:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\MigrationRunner;

$envFile = $argv[1] ?? __DIR__ . '/../.env';
Config::load($envFile);

$runner = new MigrationRunner(Database::connection(), __DIR__ . '/../migrations');
$applied = $runner->run();

if (!$applied) {
    echo "No new migrations to apply.\n";
} else {
    foreach ($applied as $file) {
        echo "Applied: {$file}\n";
    }
}
```

- [ ] **Step 8: Wire migrations into the test bootstrap so every later test starts with schema present**

Modify `tests/bootstrap.php` — replace its full contents with:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\MigrationRunner;

$envFile = __DIR__ . '/../.env.testing';
if (!file_exists($envFile)) {
    fwrite(STDERR, "Missing .env.testing — copy .env.testing.example and point it at a disposable test schema.\n");
    exit(1);
}
Config::load($envFile);

$runner = new MigrationRunner(Database::connection(), __DIR__ . '/../migrations');
$runner->run();
```

- [ ] **Step 9: Run the full suite to confirm nothing broke**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests green — MigrationRunnerTest's own DROP TABLE setUp still exercises the runner correctly on top of the bootstrap's initial run).

- [ ] **Step 10: Commit**

```bash
git add app/Database.php app/MigrationRunner.php migrations/ bin/migrate.php tests/Integration/MigrationRunnerTest.php tests/bootstrap.php
git commit -m "Add MariaDB connection wrapper, migrations, and migration runner"
```

---

### Task 3: Roles and SqlStatementClassifier

**Files:**
- Create: `app/Roles.php`
- Create: `app/SqlStatementClassifier.php`
- Create: `tests/Unit/RolesTest.php`
- Create: `tests/Unit/SqlStatementClassifierTest.php`

**Interfaces:**
- Produces: `App\Roles::ADMIN`, `App\Roles::EDITOR`, `App\Roles::VIEWER`, `App\Roles::ALL`, `App\Roles::isValid(string $role): bool`, `App\Roles::canRunStatementType(string $role, string $statementType): bool`
- Produces: `App\SqlStatementClassifier::classify(string $sql): string` (returns one of `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `DDL`, `OTHER`)

- [ ] **Step 1: Write the failing tests for SqlStatementClassifier**

Create `tests/Unit/SqlStatementClassifierTest.php`:

```php
<?php

namespace Tests\Unit;

use App\SqlStatementClassifier;
use PHPUnit\Framework\TestCase;

final class SqlStatementClassifierTest extends TestCase
{
    /** @dataProvider statements */
    public function test_classify(string $sql, string $expected): void
    {
        $this->assertSame($expected, SqlStatementClassifier::classify($sql));
    }

    public static function statements(): array
    {
        return [
            ['SELECT * FROM widgets', 'SELECT'],
            ['  insert into widgets (name) values ("a")', 'INSERT'],
            ["UPDATE widgets SET name = 'b'", 'UPDATE'],
            ['DELETE FROM widgets WHERE id = 1', 'DELETE'],
            ['DROP TABLE widgets', 'DDL'],
            ['CREATE TABLE widgets (id INT)', 'DDL'],
            ['ALTER TABLE widgets ADD COLUMN qty INT', 'DDL'],
            ['TRUNCATE TABLE widgets', 'DDL'],
            ['SHOW TABLES', 'OTHER'],
            ["-- a comment\nSELECT 1", 'SELECT'],
        ];
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/SqlStatementClassifierTest.php`
Expected: FAIL — `Class "App\SqlStatementClassifier" not found`.

- [ ] **Step 3: Implement SqlStatementClassifier**

Create `app/SqlStatementClassifier.php`:

```php
<?php

namespace App;

final class SqlStatementClassifier
{
    private const DML = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];
    private const DDL_KEYWORDS = ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];

    public static function classify(string $sql): string
    {
        $sql = trim($sql);
        $sql = preg_replace('#^/\*.*?\*/\s*#s', '', $sql);
        $sql = preg_replace('/^(--.*(\r?\n|$))+/', '', $sql);
        $sql = ltrim($sql);

        $firstWord = strtoupper((string) strtok($sql, " \t\r\n("));

        if (in_array($firstWord, self::DML, true)) {
            return $firstWord;
        }
        if (in_array($firstWord, self::DDL_KEYWORDS, true)) {
            return 'DDL';
        }
        return 'OTHER';
    }
}
```

- [ ] **Step 4: Run it to see it pass**

Run: `vendor/bin/phpunit tests/Unit/SqlStatementClassifierTest.php`
Expected: PASS (10 cases).

- [ ] **Step 5: Write the failing tests for Roles**

Create `tests/Unit/RolesTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Roles;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    public function test_viewer_can_only_select(): void
    {
        $this->assertTrue(Roles::canRunStatementType(Roles::VIEWER, 'SELECT'));
        $this->assertFalse(Roles::canRunStatementType(Roles::VIEWER, 'INSERT'));
        $this->assertFalse(Roles::canRunStatementType(Roles::VIEWER, 'DDL'));
    }

    public function test_editor_can_run_dml_but_not_ddl(): void
    {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $type) {
            $this->assertTrue(Roles::canRunStatementType(Roles::EDITOR, $type));
        }
        $this->assertFalse(Roles::canRunStatementType(Roles::EDITOR, 'DDL'));
    }

    public function test_admin_can_run_anything(): void
    {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DDL'] as $type) {
            $this->assertTrue(Roles::canRunStatementType(Roles::ADMIN, $type));
        }
    }

    public function test_other_statement_type_is_always_allowed(): void
    {
        $this->assertTrue(Roles::canRunStatementType(Roles::VIEWER, 'OTHER'));
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(Roles::isValid('admin'));
        $this->assertFalse(Roles::isValid('superuser'));
    }
}
```

- [ ] **Step 6: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/RolesTest.php`
Expected: FAIL — `Class "App\Roles" not found`.

- [ ] **Step 7: Implement Roles**

Create `app/Roles.php`:

```php
<?php

namespace App;

final class Roles
{
    public const ADMIN = 'admin';
    public const EDITOR = 'editor';
    public const VIEWER = 'viewer';

    public const ALL = [self::ADMIN, self::EDITOR, self::VIEWER];

    private const STATEMENT_PERMISSIONS = [
        self::VIEWER => ['SELECT'],
        self::EDITOR => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
        self::ADMIN => ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DDL'],
    ];

    public static function isValid(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }

    public static function canRunStatementType(string $role, string $statementType): bool
    {
        if ($statementType === 'OTHER') {
            return true;
        }
        return in_array($statementType, self::STATEMENT_PERMISSIONS[$role] ?? [], true);
    }
}
```

- [ ] **Step 8: Run it to see it pass**

Run: `vendor/bin/phpunit tests/Unit/RolesTest.php`
Expected: PASS (5 tests).

- [ ] **Step 9: Commit**

```bash
git add app/Roles.php app/SqlStatementClassifier.php tests/Unit/RolesTest.php tests/Unit/SqlStatementClassifierTest.php
git commit -m "Add Roles permission matrix and SqlStatementClassifier"
```

---

### Task 4: CSRF helper

**Files:**
- Create: `app/Csrf.php`
- Create: `tests/Unit/CsrfTest.php`

**Interfaces:**
- Produces: `App\Csrf::token(): string`, `App\Csrf::validate(?string $submitted): bool`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/CsrfTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function test_token_is_stable_across_calls_in_the_same_session(): void
    {
        $first = Csrf::token();
        $second = Csrf::token();

        $this->assertSame($first, $second);
        $this->assertNotEmpty($first);
    }

    public function test_validate_accepts_the_current_token(): void
    {
        $token = Csrf::token();

        $this->assertTrue(Csrf::validate($token));
    }

    public function test_validate_rejects_a_wrong_token(): void
    {
        Csrf::token();

        $this->assertFalse(Csrf::validate('not-the-token'));
    }

    public function test_validate_rejects_null(): void
    {
        Csrf::token();

        $this->assertFalse(Csrf::validate(null));
    }

    public function test_validate_rejects_when_no_token_was_ever_issued(): void
    {
        $this->assertFalse(Csrf::validate('anything'));
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/CsrfTest.php`
Expected: FAIL — `Class "App\Csrf" not found`.

- [ ] **Step 3: Implement Csrf**

Create `app/Csrf.php`:

```php
<?php

namespace App;

final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $submitted): bool
    {
        return is_string($submitted)
            && !empty($_SESSION[self::SESSION_KEY])
            && hash_equals($_SESSION[self::SESSION_KEY], $submitted);
    }
}
```

- [ ] **Step 4: Run it to see it pass**

Run: `vendor/bin/phpunit tests/Unit/CsrfTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Csrf.php tests/Unit/CsrfTest.php
git commit -m "Add CSRF token helper"
```

---

### Task 5: Login rate limiter

**Files:**
- Create: `app/RateLimiter.php`
- Create: `tests/Integration/RateLimiterTest.php`

**Interfaces:**
- Consumes: `App\Database::connection()` (Task 2), `login_attempts` table (Task 2)
- Produces: `App\RateLimiter::__construct(\PDO $pdo)`, `App\RateLimiter::isLockedOut(string $identifier): bool`, `App\RateLimiter::recordAttempt(string $identifier, bool $succeeded): void`

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/RateLimiterTest.php`:

```php
<?php

namespace Tests\Integration;

use App\Database;
use App\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        Database::connection()->exec('TRUNCATE TABLE login_attempts');
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
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/RateLimiterTest.php`
Expected: FAIL — `Class "App\RateLimiter" not found`.

- [ ] **Step 3: Implement RateLimiter**

Create `app/RateLimiter.php`:

```php
<?php

namespace App;

use PDO;

final class RateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function __construct(private PDO $pdo)
    {
    }

    public function isLockedOut(string $identifier): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE identifier = :id AND succeeded = 0
               AND created_at > (NOW() - INTERVAL ' . self::LOCKOUT_MINUTES . ' MINUTE)'
        );
        $stmt->execute(['id' => $identifier]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function recordAttempt(string $identifier, bool $succeeded): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO login_attempts (identifier, succeeded) VALUES (:id, :s)');
        $stmt->execute(['id' => $identifier, 's' => $succeeded ? 1 : 0]);
    }
}
```

- [ ] **Step 4: Run it to see it pass**

Run: `vendor/bin/phpunit tests/Integration/RateLimiterTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/RateLimiter.php tests/Integration/RateLimiterTest.php
git commit -m "Add login rate limiter with 15-minute lockout"
```

---

### Task 6: Audit log

**Files:**
- Create: `app/AuditLog.php`
- Create: `tests/Integration/AuditLogTest.php`

**Interfaces:**
- Consumes: `App\Database::connection()` (Task 2), `audit_log` table (Task 2)
- Produces: `App\AuditLog::__construct(\PDO $pdo)`, `App\AuditLog::record(?int $userId, string $username, string $actionType, ?string $targetDb, ?string $targetTable, string $detail): void`, `App\AuditLog::recent(int $limit = 50, int $offset = 0, ?string $username = null, ?string $fromDate = null, ?string $toDate = null): array`

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/AuditLogTest.php`:

```php
<?php

namespace Tests\Integration;

use App\AuditLog;
use App\Database;
use PHPUnit\Framework\TestCase;

final class AuditLogTest extends TestCase
{
    protected function setUp(): void
    {
        Database::connection()->exec('TRUNCATE TABLE audit_log');
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
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/AuditLogTest.php`
Expected: FAIL — `Class "App\AuditLog" not found`.

- [ ] **Step 3: Implement AuditLog**

Create `app/AuditLog.php`:

```php
<?php

namespace App;

use PDO;

final class AuditLog
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(
        ?int $userId,
        string $username,
        string $actionType,
        ?string $targetDb,
        ?string $targetTable,
        string $detail
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (app_user_id, username, action_type, target_db, target_table, detail)
             VALUES (:uid, :username, :action, :db, :table, :detail)'
        );
        $stmt->execute([
            'uid' => $userId,
            'username' => $username,
            'action' => $actionType,
            'db' => $targetDb,
            'table' => $targetTable,
            'detail' => $detail,
        ]);
    }

    public function recent(
        int $limit = 50,
        int $offset = 0,
        ?string $username = null,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        $where = [];
        $params = [];
        if ($username) {
            $where[] = 'username = :username';
            $params['username'] = $username;
        }
        if ($fromDate) {
            $where[] = 'created_at >= :from_date';
            $params['from_date'] = $fromDate . ' 00:00:00';
        }
        if ($toDate) {
            $where[] = 'created_at <= :to_date';
            $params['to_date'] = $toDate . ' 23:59:59';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $this->pdo->prepare(
            "SELECT * FROM audit_log {$whereSql} ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
```

- [ ] **Step 4: Run it to see it pass**

Run: `vendor/bin/phpunit tests/Integration/AuditLogTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/AuditLog.php tests/Integration/AuditLogTest.php
git commit -m "Add audit log recording and querying"
```

---

### Task 7: Router

**Files:**
- Create: `app/Router.php`
- Create: `tests/Unit/RouterTest.php`

**Interfaces:**
- Produces: `App\Router::add(string $method, string $pattern, callable $handler): void`, `App\Router::match(string $method, string $path): ?array` (returns `['handler' => callable, 'params' => array]` or `null`)

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/RouterTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function test_matches_a_static_route(): void
    {
        $router = new Router();
        $router->add('GET', '/hello', fn($p) => 'hi');

        $match = $router->match('GET', '/hello');

        $this->assertNotNull($match);
        $this->assertSame('hi', ($match['handler'])($match['params']));
    }

    public function test_matches_a_route_with_params(): void
    {
        $router = new Router();
        $router->add('GET', '/db/{db}/table/{table}', fn($p) => $p['db'] . ':' . $p['table']);

        $match = $router->match('GET', '/db/shop/table/orders');

        $this->assertSame(['db' => 'shop', 'table' => 'orders'], $match['params']);
        $this->assertSame('shop:orders', ($match['handler'])($match['params']));
    }

    public function test_returns_null_when_no_route_matches(): void
    {
        $router = new Router();
        $router->add('GET', '/hello', fn($p) => 'hi');

        $this->assertNull($router->match('GET', '/missing'));
    }

    public function test_method_must_also_match(): void
    {
        $router = new Router();
        $router->add('GET', '/hello', fn($p) => 'hi');

        $this->assertNull($router->match('POST', '/hello'));
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/RouterTest.php`
Expected: FAIL — `Class "App\Router" not found`.

- [ ] **Step 3: Implement Router**

Create `app/Router.php`:

```php
<?php

namespace App;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => $method, 'pattern' => $pattern, 'handler' => $handler];
    }

    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $regex = $this->compile($route['pattern']);
            if (preg_match($regex, $path, $matches)) {
                $params = array_filter($matches, fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
                return ['handler' => $route['handler'], 'params' => $params];
            }
        }
        return null;
    }

    private function compile(string $pattern): string
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }
}
```

- [ ] **Step 4: Run it to see it pass**

Run: `vendor/bin/phpunit tests/Unit/RouterTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Router.php tests/Unit/RouterTest.php
git commit -m "Add pure Router for path/method matching"
```

---

### Task 8: Auth, login flow, and front controller

**Files:**
- Create: `app/Auth.php`
- Create: `app/Controllers/AuthController.php`
- Create: `public/index.php`
- Create: `public/assets/style.css`
- Create: `resources/views/layout.php`
- Create: `resources/views/login.php`
- Create: `resources/views/error_403.php`
- Create: `resources/views/error_404.php`
- Create: `tests/Integration/AuthFlowTest.php`

**Interfaces:**
- Consumes: `App\RateLimiter` (Task 5), `App\Csrf` (Task 4), `App\View`, `App\Http` (Task 1), `App\Router` (Task 7), `App\Roles` (Task 3)
- Produces: `App\Auth::__construct(\PDO $pdo, RateLimiter $rateLimiter)`, `App\Auth::attemptLogin(string $username, string $password): array{success: bool, error?: string}`, `App\Auth::currentUser(): ?array{id: int, username: string, role: string}`, `App\Auth::logout(): void`, `App\Auth::requireLogin(): ?string`, `App\Auth::requireRole(string ...$roles): ?string`
- Produces: `App\Controllers\AuthController::showLogin(): string`, `::handleLogin(): string`, `::handleLogout(): string`

Every later controller guards its actions with:
```php
if (($guard = Auth::requireLogin()) !== null) {
    return $guard;
}
```
or, for admin-only actions:
```php
if (($guard = Auth::requireRole(Roles::ADMIN)) !== null) {
    return $guard;
}
```
**Do not** use `if ($guard = Auth::requireLogin())` — `Http::redirect()` returns `''`, which is falsy in PHP and would silently skip the guard.

- [ ] **Step 1: Write the failing tests for Auth**

Create `tests/Integration/AuthFlowTest.php`:

```php
<?php

namespace Tests\Integration;

use App\Auth;
use App\Database;
use App\RateLimiter;
use PHPUnit\Framework\TestCase;

final class AuthFlowTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('TRUNCATE TABLE login_attempts');
        $this->pdo->exec('TRUNCATE TABLE app_users');

        $stmt = $this->pdo->prepare(
            'INSERT INTO app_users (username, password_hash, role, is_active) VALUES (:u, :p, :r, 1)'
        );
        $stmt->execute(['u' => 'alice', 'p' => password_hash('correct-password', PASSWORD_DEFAULT), 'r' => 'admin']);

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_successful_login_sets_session(): void
    {
        $auth = new Auth($this->pdo, new RateLimiter($this->pdo));

        $result = $auth->attemptLogin('alice', 'correct-password');

        $this->assertTrue($result['success']);
        $this->assertSame('alice', $_SESSION['username']);
        $this->assertSame('admin', $_SESSION['role']);
    }

    public function test_failed_login_does_not_set_session(): void
    {
        $auth = new Auth($this->pdo, new RateLimiter($this->pdo));

        $result = $auth->attemptLogin('alice', 'wrong-password');

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_credentials', $result['error']);
        $this->assertArrayNotHasKey('username', $_SESSION);
    }

    public function test_lockout_after_five_failed_attempts(): void
    {
        $auth = new Auth($this->pdo, new RateLimiter($this->pdo));
        for ($i = 0; $i < 5; $i++) {
            $auth->attemptLogin('alice', 'wrong-password');
        }

        $result = $auth->attemptLogin('alice', 'correct-password');

        $this->assertFalse($result['success']);
        $this->assertSame('locked_out', $result['error']);
    }

    public function test_current_user_reads_from_session(): void
    {
        $_SESSION = ['user_id' => 7, 'username' => 'bob', 'role' => 'viewer'];

        $this->assertSame(['id' => 7, 'username' => 'bob', 'role' => 'viewer'], Auth::currentUser());
    }

    public function test_current_user_is_null_when_logged_out(): void
    {
        $_SESSION = [];

        $this->assertNull(Auth::currentUser());
    }

    public function test_require_login_returns_non_null_when_logged_out(): void
    {
        $_SESSION = [];

        $this->assertNotNull(Auth::requireLogin());
    }

    public function test_require_login_returns_null_when_logged_in(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'bob', 'role' => 'viewer'];

        $this->assertNull(Auth::requireLogin());
    }

    public function test_require_role_blocks_wrong_role(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'bob', 'role' => 'viewer'];

        $this->assertNotNull(Auth::requireRole('admin'));
    }

    public function test_require_role_allows_matching_role(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'admin'];

        $this->assertNull(Auth::requireRole('admin'));
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/AuthFlowTest.php`
Expected: FAIL — `Class "App\Auth" not found`.

- [ ] **Step 3: Implement Auth**

Create `app/Auth.php`:

```php
<?php

namespace App;

use PDO;

final class Auth
{
    public function __construct(private PDO $pdo, private RateLimiter $rateLimiter)
    {
    }

    public function attemptLogin(string $username, string $password): array
    {
        if ($this->rateLimiter->isLockedOut($username)) {
            return ['success' => false, 'error' => 'locked_out'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role, is_active FROM app_users WHERE username = :u'
        );
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        $valid = $user && (bool) $user['is_active'] && password_verify($password, $user['password_hash']);
        $this->rateLimiter->recordAttempt($username, $valid);

        if (!$valid) {
            return ['success' => false, 'error' => 'invalid_credentials'];
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        return ['success' => true];
    }

    public static function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id' => (int) $_SESSION['user_id'],
            'username' => (string) $_SESSION['username'],
            'role' => (string) $_SESSION['role'],
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function requireLogin(): ?string
    {
        if (self::currentUser() === null) {
            return Http::redirect('/login');
        }
        return null;
    }

    public static function requireRole(string ...$roles): ?string
    {
        $user = self::currentUser();
        if ($user === null) {
            return Http::redirect('/login');
        }
        if (!in_array($user['role'], $roles, true)) {
            http_response_code(403);
            return View::render('error_403', ['user' => $user]);
        }
        return null;
    }
}
```

- [ ] **Step 4: Run it to see it pass**

Run: `vendor/bin/phpunit tests/Integration/AuthFlowTest.php`
Expected: PASS (9 tests). Note `test_require_role_blocks_wrong_role` will only pass once `resources/views/error_403.php` exists — created in Step 6 below; if you run tests before that, this one case fails with a missing-file error, which is expected mid-task.

- [ ] **Step 5: Create the layout and page views**

Create `resources/views/layout.php`:

```php
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>DB Web Admin</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="topnav">
    <a href="/">Databases</a>
    <a href="/sql">SQL Console</a>
    <?php if (($user['role'] ?? null) === \App\Roles::ADMIN): ?>
        <a href="/users">Users</a>
        <a href="/audit">Audit Log</a>
    <?php endif; ?>
    <span class="topnav-user">
        <?= \App\View::e($user['username'] ?? '') ?> (<?= \App\View::e($user['role'] ?? '') ?>)
        <form method="post" action="/logout" style="display:inline">
            <button type="submit">Logout</button>
        </form>
    </span>
</nav>
<main>
<?= $content ?>
</main>
</body>
</html>
```

Create `resources/views/login.php`:

```php
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Log in</title><link rel="stylesheet" href="/assets/style.css"></head>
<body>
<main class="login-page">
<h1>DB Web Admin</h1>
<?php if ($error): ?><p class="error"><?= \App\View::e($error) ?></p><?php endif; ?>
<form method="post" action="/login">
    <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
    <label>Username <input type="text" name="username" required autofocus></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Log in</button>
</form>
</main>
</body>
</html>
```

Create `resources/views/error_403.php`:

```php
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Forbidden</title><link rel="stylesheet" href="/assets/style.css"></head>
<body>
<main class="login-page">
<h1>403 — Forbidden</h1>
<p>Your role does not have access to this page.</p>
<p><a href="/">Back to Databases</a></p>
</main>
</body>
</html>
```

Create `resources/views/error_404.php`:

```php
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Not found</title><link rel="stylesheet" href="/assets/style.css"></head>
<body>
<main class="login-page">
<h1>404 — Not Found</h1>
<p><a href="/">Back to Databases</a></p>
</main>
</body>
</html>
```

Create `public/assets/style.css`:

```css
body { font-family: system-ui, sans-serif; margin: 0; color: #1a1a1a; }
.topnav { background: #222; padding: 0.75rem 1rem; display: flex; gap: 1rem; align-items: center; }
.topnav a { color: #fff; text-decoration: none; }
.topnav-user { margin-left: auto; color: #ccc; }
main { padding: 1.5rem; }
table.grid { border-collapse: collapse; width: 100%; margin: 1rem 0; }
table.grid th, table.grid td { border: 1px solid #ccc; padding: 0.4rem 0.6rem; text-align: left; }
.error { color: #b00020; }
.login-page { max-width: 320px; margin: 4rem auto; text-align: center; }
form label { display: block; margin: 0.5rem 0; }
```

- [ ] **Step 6: Run the Auth tests again to confirm all pass now**

Run: `vendor/bin/phpunit tests/Integration/AuthFlowTest.php`
Expected: PASS (9 tests).

- [ ] **Step 7: Implement AuthController**

Create `app/Controllers/AuthController.php`:

```php
<?php

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Database;
use App\Http;
use App\RateLimiter;
use App\View;

final class AuthController
{
    private Auth $auth;

    public function __construct()
    {
        $pdo = Database::connection();
        $this->auth = new Auth($pdo, new RateLimiter($pdo));
    }

    public function showLogin(): string
    {
        if (Auth::currentUser() !== null) {
            return Http::redirect('/');
        }
        return View::render('login', ['csrfToken' => Csrf::token(), 'error' => null], null);
    }

    public function handleLogin(): string
    {
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return View::render('login', [
                'csrfToken' => Csrf::token(),
                'error' => 'Invalid form submission, please try again.',
            ], null);
        }

        $result = $this->auth->attemptLogin((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if (!$result['success']) {
            $message = $result['error'] === 'locked_out'
                ? 'Too many failed attempts. Try again in a few minutes.'
                : 'Invalid username or password.';
            return View::render('login', ['csrfToken' => Csrf::token(), 'error' => $message], null);
        }

        return Http::redirect('/');
    }

    public function handleLogout(): string
    {
        Auth::logout();
        return Http::redirect('/login');
    }
}
```

- [ ] **Step 8: Create the front controller**

Create `public/index.php`:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Controllers\AuthController;
use App\Router;
use App\View;

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'cookie_secure' => true,
]);

Config::load(__DIR__ . '/../.env');

$router = new Router();
$auth = new AuthController();

$router->add('GET', '/login', fn($p) => $auth->showLogin());
$router->add('POST', '/login', fn($p) => $auth->handleLogin());
$router->add('POST', '/logout', fn($p) => $auth->handleLogout());
$router->add('GET', '/', fn($p) => \App\Auth::requireLogin() ?? 'Logged in.');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$match = $router->match($_SERVER['REQUEST_METHOD'], $path);

if ($match === null) {
    http_response_code(404);
    echo View::render('error_404', [], null);
    exit;
}

echo ($match['handler'])($match['params']);
```

The `GET /` route is a placeholder here — Task 9 replaces it with the real dashboard route.

- [ ] **Step 9: Sanity-check the front controller with PHP's built-in server**

Run: `php -S 127.0.0.1:8080 -t public` (in one terminal), then in another: `curl -i http://127.0.0.1:8080/login`
Expected: HTTP 200 with the login form HTML. Stop the server with Ctrl+C when done.

- [ ] **Step 10: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests green).

- [ ] **Step 11: Commit**

```bash
git add app/Auth.php app/Controllers/AuthController.php public/index.php public/assets/style.css resources/views/layout.php resources/views/login.php resources/views/error_403.php resources/views/error_404.php tests/Integration/AuthFlowTest.php
git commit -m "Add login/logout flow, session auth, and front controller"
```

---

### Task 9: Database & table browser

**Files:**
- Create: `app/Controllers/DashboardController.php`
- Create: `app/Controllers/TableController.php`
- Create: `resources/views/dashboard.php`
- Create: `resources/views/tables.php`
- Create: `resources/views/table_structure.php`
- Create: `tests/Integration/TableBrowserTest.php`
- Modify: `public/index.php` (replace the placeholder `/` route, add browser routes)

**Interfaces:**
- Consumes: `App\Auth::requireLogin()` (Task 8), `App\Database::connection()` (Task 2), `App\View` (Task 1)
- Produces: `App\Controllers\TableController::listTables(string $db): array`, `::columns(string $db, string $table): array`, `::primaryKeyColumn(string $db, string $table): ?string`, `::listForDatabase(string $db): string`, `::structure(string $db, string $table): string`
- Produces: `App\Controllers\DashboardController::index(): string`

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/TableBrowserTest.php`:

```php
<?php

namespace Tests\Integration;

use App\Controllers\DashboardController;
use App\Controllers\TableController;
use App\Database;
use PHPUnit\Framework\TestCase;

final class TableBrowserTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $this->pdo->exec('CREATE DATABASE wbtest_fixture');
        $this->pdo->exec(
            'CREATE TABLE wbtest_fixture.widgets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                quantity INT NOT NULL DEFAULT 0
            )'
        );
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'admin'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $_SESSION = [];
    }

    public function test_list_tables_returns_known_table(): void
    {
        $controller = new TableController();

        $this->assertSame(['widgets'], $controller->listTables('wbtest_fixture'));
    }

    public function test_list_tables_rejects_unknown_database(): void
    {
        $controller = new TableController();

        $this->expectException(\InvalidArgumentException::class);
        $controller->listTables('no_such_database');
    }

    public function test_columns_returns_column_metadata_in_order(): void
    {
        $controller = new TableController();

        $columns = $controller->columns('wbtest_fixture', 'widgets');

        $this->assertSame(['id', 'name', 'quantity'], array_column($columns, 'COLUMN_NAME'));
    }

    public function test_primary_key_column_is_detected(): void
    {
        $controller = new TableController();

        $this->assertSame('id', $controller->primaryKeyColumn('wbtest_fixture', 'widgets'));
    }

    public function test_dashboard_excludes_system_and_app_schemas(): void
    {
        $controller = new DashboardController();

        $html = $controller->index();

        $this->assertStringNotContainsString('>mysql<', $html);
        $this->assertStringNotContainsString('>information_schema<', $html);
        $this->assertStringContainsString('wbtest_fixture', $html);
    }

    public function test_list_for_database_renders_table_names(): void
    {
        $controller = new TableController();

        $html = $controller->listForDatabase('wbtest_fixture');

        $this->assertStringContainsString('widgets', $html);
    }

    public function test_structure_renders_column_names(): void
    {
        $controller = new TableController();

        $html = $controller->structure('wbtest_fixture', 'widgets');

        $this->assertStringContainsString('quantity', $html);
    }

    public function test_browser_actions_redirect_when_logged_out(): void
    {
        $_SESSION = [];
        $controller = new TableController();

        $this->assertSame('', $controller->listForDatabase('wbtest_fixture'));
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/TableBrowserTest.php`
Expected: FAIL — `Class "App\Controllers\TableController" not found`.

- [ ] **Step 3: Implement TableController**

Create `app/Controllers/TableController.php`:

```php
<?php

namespace App\Controllers;

use App\Auth;
use App\AuditLog;
use App\Database;
use App\View;
use InvalidArgumentException;
use PDO;

final class TableController
{
    protected PDO $pdo;
    protected AuditLog $auditLog;

    public function __construct()
    {
        $this->pdo = Database::connection();
        $this->auditLog = new AuditLog($this->pdo);
    }

    public function listForDatabase(string $db): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }
        $this->assertValidDatabase($db);

        return View::render('tables', [
            'user' => Auth::currentUser(),
            'db' => $db,
            'tables' => $this->listTables($db),
        ]);
    }

    public function structure(string $db, string $table): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }
        $this->assertValidTable($db, $table);

        return View::render('table_structure', [
            'user' => Auth::currentUser(),
            'db' => $db,
            'table' => $table,
            'columns' => $this->columns($db, $table),
        ]);
    }

    public function listTables(string $db): array
    {
        $this->assertValidDatabase($db);

        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME'
        );
        $stmt->execute(['db' => $db]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function columns(string $db, string $table): array
    {
        $this->assertValidTable($db, $table);

        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['db' => $db, 't' => $table]);

        return $stmt->fetchAll();
    }

    public function primaryKeyColumn(string $db, string $table): ?string
    {
        foreach ($this->columns($db, $table) as $col) {
            if ($col['COLUMN_KEY'] === 'PRI') {
                return $col['COLUMN_NAME'];
            }
        }
        return null;
    }

    protected function assertValidDatabase(string $db): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db');
        $stmt->execute(['db' => $db]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Unknown database');
        }
    }

    protected function assertValidTable(string $db, string $table): void
    {
        $this->assertValidDatabase($db);
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t'
        );
        $stmt->execute(['db' => $db, 't' => $table]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Unknown table');
        }
    }
}
```

- [ ] **Step 4: Implement DashboardController**

Create `app/Controllers/DashboardController.php`:

```php
<?php

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Database;
use App\View;
use PDO;

final class DashboardController
{
    private const SYSTEM_SCHEMAS = ['mysql', 'information_schema', 'performance_schema', 'sys'];

    public function index(): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }

        $appSchema = Config::get('DB_APP_SCHEMA', 'dbwebui_app');
        $all = Database::connection()->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        $databases = array_values(array_diff($all, self::SYSTEM_SCHEMAS, [$appSchema]));

        return View::render('dashboard', [
            'user' => Auth::currentUser(),
            'databases' => $databases,
        ]);
    }
}
```

- [ ] **Step 5: Create the views**

Create `resources/views/dashboard.php`:

```php
<h1>Databases</h1>
<ul class="db-list">
<?php foreach ($databases as $db): ?>
    <li><a href="/db/<?= \App\View::e($db) ?>/tables"><?= \App\View::e($db) ?></a></li>
<?php endforeach; ?>
</ul>
```

Create `resources/views/tables.php`:

```php
<h1>Tables in <?= \App\View::e($db) ?></h1>
<ul class="table-list">
<?php foreach ($tables as $table): ?>
    <li>
        <a href="/db/<?= \App\View::e($db) ?>/table/<?= \App\View::e($table) ?>"><?= \App\View::e($table) ?></a>
        (<a href="/db/<?= \App\View::e($db) ?>/table/<?= \App\View::e($table) ?>/structure">structure</a>)
    </li>
<?php endforeach; ?>
</ul>
```

Create `resources/views/table_structure.php`:

```php
<h1><?= \App\View::e($db) ?>.<?= \App\View::e($table) ?> — structure</h1>
<table class="grid">
<thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>
<tbody>
<?php foreach ($columns as $col): ?>
<tr>
    <td><?= \App\View::e($col['COLUMN_NAME']) ?></td>
    <td><?= \App\View::e($col['DATA_TYPE']) ?></td>
    <td><?= \App\View::e($col['IS_NULLABLE']) ?></td>
    <td><?= \App\View::e($col['COLUMN_KEY']) ?></td>
    <td><?= \App\View::e((string) $col['COLUMN_DEFAULT']) ?></td>
    <td><?= \App\View::e($col['EXTRA']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p><a href="/db/<?= \App\View::e($db) ?>/table/<?= \App\View::e($table) ?>">View data</a></p>
```

- [ ] **Step 6: Run the tests to see them pass**

Run: `vendor/bin/phpunit tests/Integration/TableBrowserTest.php`
Expected: PASS (8 tests).

- [ ] **Step 7: Wire the browser routes into the front controller**

Modify `public/index.php` — replace:

```php
use App\Config;
use App\Controllers\AuthController;
use App\Router;
use App\View;
```

with:

```php
use App\Config;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\TableController;
use App\Router;
use App\View;
```

and replace:

```php
$router = new Router();
$auth = new AuthController();

$router->add('GET', '/login', fn($p) => $auth->showLogin());
$router->add('POST', '/login', fn($p) => $auth->handleLogin());
$router->add('POST', '/logout', fn($p) => $auth->handleLogout());
$router->add('GET', '/', fn($p) => \App\Auth::requireLogin() ?? 'Logged in.');
```

with:

```php
$router = new Router();
$auth = new AuthController();
$dashboard = new DashboardController();
$tables = new TableController();

$router->add('GET', '/login', fn($p) => $auth->showLogin());
$router->add('POST', '/login', fn($p) => $auth->handleLogin());
$router->add('POST', '/logout', fn($p) => $auth->handleLogout());
$router->add('GET', '/', fn($p) => $dashboard->index());
$router->add('GET', '/db/{db}/tables', fn($p) => $tables->listForDatabase($p['db']));
$router->add('GET', '/db/{db}/table/{table}/structure', fn($p) => $tables->structure($p['db'], $p['table']));
```

- [ ] **Step 8: Manual smoke test**

Run: `php -S 127.0.0.1:8080 -t public`, log in via the browser at `http://127.0.0.1:8080/login`, and confirm you land on the database list, can click into a database's tables, and can view a table's structure.

- [ ] **Step 9: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests green).

- [ ] **Step 10: Commit**

```bash
git add app/Controllers/DashboardController.php app/Controllers/TableController.php resources/views/dashboard.php resources/views/tables.php resources/views/table_structure.php tests/Integration/TableBrowserTest.php public/index.php
git commit -m "Add database and table browser"
```

---

### Task 10: Data grid — listing (pagination, sort, filter)

**Files:**
- Modify: `app/Controllers/TableController.php` (add `listRows`, `data`)
- Create: `resources/views/table_data.php`
- Create: `tests/Integration/TableDataListingTest.php`
- Modify: `public/index.php` (add the table-data route)

**Interfaces:**
- Consumes: `TableController::columns()`, `::assertValidTable()` (Task 9)
- Produces: `App\Controllers\TableController::listRows(string $db, string $table, int $page, int $pageSize, ?string $sortColumn, string $sortDir, array $filters): array` (returns `['rows' => array, 'total' => int, 'page' => int, 'pageSize' => int]`), `::data(string $db, string $table): string`

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/TableDataListingTest.php`:

```php
<?php

namespace Tests\Integration;

use App\Controllers\TableController;
use App\Database;
use PHPUnit\Framework\TestCase;

final class TableDataListingTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $this->pdo->exec('CREATE DATABASE wbtest_fixture');
        $this->pdo->exec(
            'CREATE TABLE wbtest_fixture.widgets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                quantity INT NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->exec(
            "INSERT INTO wbtest_fixture.widgets (name, quantity) VALUES
             ('bolt', 10), ('nail', 5), ('screw', 20)"
        );
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'viewer'];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $_SESSION = [];
        $_GET = [];
    }

    public function test_list_rows_returns_all_rows_and_total(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 1, 50, null, 'ASC', []);

        $this->assertSame(3, $result['total']);
        $this->assertCount(3, $result['rows']);
    }

    public function test_list_rows_sorts_by_column(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 1, 50, 'quantity', 'ASC', []);

        $this->assertSame(['nail', 'bolt', 'screw'], array_column($result['rows'], 'name'));
    }

    public function test_list_rows_sorts_descending(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 1, 50, 'quantity', 'DESC', []);

        $this->assertSame(['screw', 'bolt', 'nail'], array_column($result['rows'], 'name'));
    }

    public function test_list_rows_filters_by_column_value(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 1, 50, null, 'ASC', ['name' => 'ol']);

        $this->assertSame(['bolt'], array_column($result['rows'], 'name'));
    }

    public function test_list_rows_ignores_unknown_sort_column(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 1, 50, 'not_a_column', 'ASC', []);

        $this->assertCount(3, $result['rows']);
    }

    public function test_list_rows_paginates(): void
    {
        $controller = new TableController();

        $result = $controller->listRows('wbtest_fixture', 'widgets', 2, 2, 'id', 'ASC', []);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['page']);
    }

    public function test_data_action_renders_rows_for_logged_in_viewer(): void
    {
        $controller = new TableController();

        $html = $controller->data('wbtest_fixture', 'widgets');

        $this->assertStringContainsString('bolt', $html);
        $this->assertStringContainsString('nail', $html);
        $this->assertStringContainsString('screw', $html);
    }

    public function test_data_action_respects_query_string_paging(): void
    {
        $_GET = ['page' => '1', 'page_size' => '1', 'sort' => 'quantity', 'dir' => 'ASC'];
        $controller = new TableController();

        $html = $controller->data('wbtest_fixture', 'widgets');

        $this->assertStringContainsString('nail', $html);
        $this->assertStringNotContainsString('screw', $html);
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/TableDataListingTest.php`
Expected: FAIL — `Call to undefined method App\Controllers\TableController::listRows()`.

- [ ] **Step 3: Add listRows and data to TableController**

Modify `app/Controllers/TableController.php` — add these two public methods (e.g. after `structure()`):

```php
    public function data(string $db, string $table): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }
        $this->assertValidTable($db, $table);

        $columns = $this->columns($db, $table);
        $validColumns = array_column($columns, 'COLUMN_NAME');

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = (int) ($_GET['page_size'] ?? 50);
        $sortColumn = $_GET['sort'] ?? null;
        $sortDir = $_GET['dir'] ?? 'ASC';
        $filters = array_intersect_key($_GET['filter'] ?? [], array_flip($validColumns));

        $result = $this->listRows($db, $table, $page, $pageSize, $sortColumn, $sortDir, $filters);

        return View::render('table_data', [
            'user' => Auth::currentUser(),
            'db' => $db,
            'table' => $table,
            'columns' => $columns,
            'primaryKey' => $this->primaryKeyColumn($db, $table),
            'result' => $result,
            'sortColumn' => $sortColumn,
            'sortDir' => $sortDir,
            'filters' => $filters,
            'csrfToken' => \App\Csrf::token(),
        ]);
    }

    public function listRows(
        string $db,
        string $table,
        int $page,
        int $pageSize,
        ?string $sortColumn,
        string $sortDir,
        array $filters
    ): array {
        $this->assertValidTable($db, $table);
        $validColumns = array_column($this->columns($db, $table), 'COLUMN_NAME');

        $where = [];
        $params = [];
        foreach ($filters as $col => $value) {
            if (!in_array($col, $validColumns, true) || $value === '') {
                continue;
            }
            $where[] = sprintf('`%s` LIKE :filter_%s', $col, $col);
            $params['filter_' . $col] = '%' . $value . '%';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $orderSql = '';
        if ($sortColumn !== null && in_array($sortColumn, $validColumns, true)) {
            $dir = strtoupper($sortDir) === 'DESC' ? 'DESC' : 'ASC';
            $orderSql = sprintf('ORDER BY `%s` %s', $sortColumn, $dir);
        }

        $pageSize = max(1, min($pageSize, 500));
        $offset = max(0, ($page - 1) * $pageSize);

        $sql = sprintf('SELECT * FROM `%s`.`%s` %s %s LIMIT :limit OFFSET :offset', $db, $table, $whereSql, $orderSql);
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $countSql = sprintf('SELECT COUNT(*) FROM `%s`.`%s` %s', $db, $table, $whereSql);
        $countStmt = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize];
    }
```

- [ ] **Step 4: Create the table_data view**

Create `resources/views/table_data.php`:

```php
<h1><?= \App\View::e($db) ?>.<?= \App\View::e($table) ?></h1>
<p>
    <a href="/db/<?= \App\View::e($db) ?>/table/<?= \App\View::e($table) ?>/structure">View structure</a>
    <?php if (in_array($user['role'], [\App\Roles::EDITOR, \App\Roles::ADMIN], true)): ?>
        | <a href="/db/<?= \App\View::e($db) ?>/table/<?= \App\View::e($table) ?>/new">Insert row</a>
    <?php endif; ?>
</p>

<form method="get" class="filter-form">
<?php foreach ($columns as $col): $name = $col['COLUMN_NAME']; ?>
    <label><?= \App\View::e($name) ?>
        <input type="text" name="filter[<?= \App\View::e($name) ?>]" value="<?= \App\View::e($filters[$name] ?? '') ?>">
    </label>
<?php endforeach; ?>
<button type="submit">Filter</button>
</form>

<table class="grid">
<thead><tr>
<?php foreach ($columns as $col): $name = $col['COLUMN_NAME']; $nextDir = ($sortColumn === $name && $sortDir === 'ASC') ? 'DESC' : 'ASC'; ?>
    <th><a href="?sort=<?= \App\View::e($name) ?>&dir=<?= $nextDir ?>"><?= \App\View::e($name) ?></a></th>
<?php endforeach; ?>
<th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($result['rows'] as $row): ?>
<tr>
<?php foreach ($columns as $col): ?>
    <td><?= \App\View::e((string) ($row[$col['COLUMN_NAME']] ?? '')) ?></td>
<?php endforeach; ?>
<td>
<?php if ($primaryKey !== null && in_array($user['role'], [\App\Roles::EDITOR, \App\Roles::ADMIN], true)): ?>
    <a href="/db/<?= \App\View::e($db) ?>/table/<?= \App\View::e($table) ?>/row/<?= \App\View::e((string) $row[$primaryKey]) ?>/edit">Edit</a>
    <form method="post" action="/db/<?= \App\View::e($db) ?>/table/<?= \App\View::e($table) ?>/row/<?= \App\View::e((string) $row[$primaryKey]) ?>/delete" style="display:inline" onsubmit="return confirm('Delete this row?');">
        <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
        <button type="submit">Delete</button>
    </form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<div class="pagination">
<?php $totalPages = max(1, (int) ceil($result['total'] / $result['pageSize'])); ?>
Page <?= $result['page'] ?> of <?= $totalPages ?>
<?php if ($result['page'] > 1): ?> <a href="?page=<?= $result['page'] - 1 ?>">Previous</a><?php endif; ?>
<?php if ($result['page'] < $totalPages): ?> <a href="?page=<?= $result['page'] + 1 ?>">Next</a><?php endif; ?>
</div>
```

- [ ] **Step 5: Run the tests to see them pass**

Run: `vendor/bin/phpunit tests/Integration/TableDataListingTest.php`
Expected: PASS (8 tests).

- [ ] **Step 6: Wire the data route into the front controller**

Modify `public/index.php` — after the `structure` route line, add:

```php
$router->add('GET', '/db/{db}/table/{table}', fn($p) => $tables->data($p['db'], $p['table']));
```

- [ ] **Step 7: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests green).

- [ ] **Step 8: Commit**

```bash
git add app/Controllers/TableController.php resources/views/table_data.php tests/Integration/TableDataListingTest.php public/index.php
git commit -m "Add paginated, sortable, filterable data grid listing"
```

---

### Task 11: Data grid — insert/update/delete rows

**Files:**
- Modify: `app/Controllers/TableController.php` (add data-access + HTTP action methods)
- Create: `resources/views/row_form.php`
- Create: `tests/Integration/TableDataMutationTest.php`
- Modify: `public/index.php` (add mutation routes)

**Interfaces:**
- Consumes: `TableController::columns()`, `::primaryKeyColumn()`, `::assertValidTable()` (Task 9); `App\Csrf` (Task 4); `App\Roles` (Task 3)
- Produces (data-access, tested directly): `TableController::findRow(string $db, string $table, string $pkColumn, $pkValue): ?array`, `::insertRow(string $db, string $table, array $data, int $userId, string $username): void`, `::updateRowData(string $db, string $table, string $pkColumn, $pkValue, array $data, int $userId, string $username): void`, `::deleteRowData(string $db, string $table, string $pkColumn, $pkValue, int $userId, string $username): void`
- Produces (HTTP actions): `::newRowForm(string $db, string $table): string`, `::createRow(string $db, string $table): string`, `::editRowForm(string $db, string $table, string $pk): string`, `::updateRow(string $db, string $table, string $pk): string`, `::deleteRow(string $db, string $table, string $pk): string`

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/TableDataMutationTest.php`:

```php
<?php

namespace Tests\Integration;

use App\Controllers\TableController;
use App\AuditLog;
use App\Csrf;
use App\Database;
use PHPUnit\Framework\TestCase;

final class TableDataMutationTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $this->pdo->exec('CREATE DATABASE wbtest_fixture');
        $this->pdo->exec(
            'CREATE TABLE wbtest_fixture.widgets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                quantity INT NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->exec("INSERT INTO wbtest_fixture.widgets (name, quantity) VALUES ('bolt', 10)");
        $this->pdo->exec('TRUNCATE TABLE audit_log');

        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $_SESSION = [];
        $_POST = [];
    }

    public function test_insert_row_writes_row_and_audit_entry(): void
    {
        $controller = new TableController();

        $controller->insertRow('wbtest_fixture', 'widgets', ['name' => 'nail', 'quantity' => '5'], 1, 'alice');

        $row = $controller->findRow('wbtest_fixture', 'widgets', 'id', 2);
        $this->assertSame('nail', $row['name']);

        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('ROW_INSERT', $entries[0]['action_type']);
    }

    public function test_insert_row_ignores_unknown_columns(): void
    {
        $controller = new TableController();

        $controller->insertRow('wbtest_fixture', 'widgets', ['name' => 'nail', 'not_a_column' => 'x'], 1, 'alice');

        $row = $controller->findRow('wbtest_fixture', 'widgets', 'id', 2);
        $this->assertSame('nail', $row['name']);
    }

    public function test_update_row_data_changes_fields_and_logs(): void
    {
        $controller = new TableController();

        $controller->updateRowData('wbtest_fixture', 'widgets', 'id', 1, ['quantity' => '99'], 1, 'alice');

        $row = $controller->findRow('wbtest_fixture', 'widgets', 'id', 1);
        $this->assertSame('99', (string) $row['quantity']);

        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('ROW_UPDATE', $entries[0]['action_type']);
    }

    public function test_update_row_data_cannot_change_the_primary_key(): void
    {
        $controller = new TableController();

        $controller->updateRowData('wbtest_fixture', 'widgets', 'id', 1, ['id' => '999', 'quantity' => '1'], 1, 'alice');

        $this->assertNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 999));
        $this->assertNotNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 1));
    }

    public function test_delete_row_data_removes_row_and_logs(): void
    {
        $controller = new TableController();

        $controller->deleteRowData('wbtest_fixture', 'widgets', 'id', 1, 1, 'alice');

        $this->assertNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 1));

        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('ROW_DELETE', $entries[0]['action_type']);
    }

    public function test_create_row_action_requires_editor_or_admin_role(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'vic', 'role' => 'viewer'];
        $_POST = ['csrf_token' => Csrf::token(), 'fields' => ['name' => 'nail', 'quantity' => '1']];
        $controller = new TableController();

        $controller->createRow('wbtest_fixture', 'widgets');

        $this->assertNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 2));
    }

    public function test_create_row_action_rejects_bad_csrf_token(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'ed', 'role' => 'editor'];
        Csrf::token();
        $_POST = ['csrf_token' => 'wrong', 'fields' => ['name' => 'nail', 'quantity' => '1']];
        $controller = new TableController();

        $controller->createRow('wbtest_fixture', 'widgets');

        $this->assertNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 2));
    }

    public function test_create_row_action_succeeds_for_editor_with_valid_csrf(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'ed', 'role' => 'editor'];
        $_POST = ['csrf_token' => Csrf::token(), 'fields' => ['name' => 'nail', 'quantity' => '1']];
        $controller = new TableController();

        $controller->createRow('wbtest_fixture', 'widgets');

        $this->assertNotNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 2));
    }

    public function test_delete_row_action_succeeds_for_admin(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'admin1', 'role' => 'admin'];
        $_POST = ['csrf_token' => Csrf::token()];
        $controller = new TableController();

        $controller->deleteRow('wbtest_fixture', 'widgets', '1');

        $this->assertNull($controller->findRow('wbtest_fixture', 'widgets', 'id', 1));
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/TableDataMutationTest.php`
Expected: FAIL — `Call to undefined method App\Controllers\TableController::findRow()`.

- [ ] **Step 3: Add data-access mutation methods to TableController**

Modify `app/Controllers/TableController.php` — add these methods (e.g. after `listRows()`):

```php
    public function findRow(string $db, string $table, string $pkColumn, $pkValue): ?array
    {
        $this->assertValidTable($db, $table);
        $sql = sprintf('SELECT * FROM `%s`.`%s` WHERE `%s` = :pk', $db, $table, $pkColumn);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['pk' => $pkValue]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function insertRow(string $db, string $table, array $data, int $userId, string $username): void
    {
        $this->assertValidTable($db, $table);
        $validColumns = array_column($this->columns($db, $table), 'COLUMN_NAME');
        $data = array_intersect_key($data, array_flip($validColumns));
        if (!$data) {
            throw new InvalidArgumentException('No valid columns supplied');
        }

        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s`.`%s` (%s) VALUES (%s)',
            $db,
            $table,
            implode(', ', array_map(fn($c) => "`$c`", $columns)),
            implode(', ', array_map(fn($c) => ":$c", $columns))
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        $this->auditLog->record($userId, $username, 'ROW_INSERT', $db, $table, json_encode($data));
    }

    public function updateRowData(
        string $db,
        string $table,
        string $pkColumn,
        $pkValue,
        array $data,
        int $userId,
        string $username
    ): void {
        $this->assertValidTable($db, $table);
        $validColumns = array_column($this->columns($db, $table), 'COLUMN_NAME');
        $data = array_intersect_key($data, array_flip($validColumns));
        unset($data[$pkColumn]);
        if (!$data) {
            throw new InvalidArgumentException('No valid columns supplied');
        }
        if (!in_array($pkColumn, $validColumns, true)) {
            throw new InvalidArgumentException('Invalid primary key column');
        }

        $setSql = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($data)));
        $sql = sprintf('UPDATE `%s`.`%s` SET %s WHERE `%s` = :pk_value', $db, $table, $setSql, $pkColumn);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...$data, 'pk_value' => $pkValue]);

        $this->auditLog->record($userId, $username, 'ROW_UPDATE', $db, $table, json_encode(['pk' => $pkValue, 'set' => $data]));
    }

    public function deleteRowData(string $db, string $table, string $pkColumn, $pkValue, int $userId, string $username): void
    {
        $this->assertValidTable($db, $table);
        $validColumns = array_column($this->columns($db, $table), 'COLUMN_NAME');
        if (!in_array($pkColumn, $validColumns, true)) {
            throw new InvalidArgumentException('Invalid primary key column');
        }

        $sql = sprintf('DELETE FROM `%s`.`%s` WHERE `%s` = :pk_value', $db, $table, $pkColumn);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['pk_value' => $pkValue]);

        $this->auditLog->record($userId, $username, 'ROW_DELETE', $db, $table, json_encode(['pk' => $pkValue]));
    }
```

- [ ] **Step 4: Add the HTTP action methods to TableController**

Modify `app/Controllers/TableController.php` — add these methods (e.g. after `data()`), and add `use App\Csrf;`, `use App\Http;`, `use App\Roles;` to the top `use` block:

```php
    public function newRowForm(string $db, string $table): string
    {
        if (($guard = Auth::requireRole(Roles::EDITOR, Roles::ADMIN)) !== null) {
            return $guard;
        }
        $this->assertValidTable($db, $table);

        return View::render('row_form', [
            'user' => Auth::currentUser(),
            'db' => $db,
            'table' => $table,
            'columns' => $this->columns($db, $table),
            'row' => null,
            'primaryKey' => $this->primaryKeyColumn($db, $table),
            'pkValue' => null,
            'csrfToken' => Csrf::token(),
        ]);
    }

    public function createRow(string $db, string $table): string
    {
        if (($guard = Auth::requireRole(Roles::EDITOR, Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }
        $this->assertValidTable($db, $table);

        $user = Auth::currentUser();
        $this->insertRow($db, $table, $_POST['fields'] ?? [], $user['id'], $user['username']);

        return Http::redirect("/db/{$db}/table/{$table}");
    }

    public function editRowForm(string $db, string $table, string $pk): string
    {
        if (($guard = Auth::requireRole(Roles::EDITOR, Roles::ADMIN)) !== null) {
            return $guard;
        }
        $this->assertValidTable($db, $table);
        $pkColumn = $this->primaryKeyColumn($db, $table);
        if ($pkColumn === null) {
            http_response_code(400);
            return 'Table has no primary key; editing is not supported.';
        }

        return View::render('row_form', [
            'user' => Auth::currentUser(),
            'db' => $db,
            'table' => $table,
            'columns' => $this->columns($db, $table),
            'row' => $this->findRow($db, $table, $pkColumn, $pk),
            'primaryKey' => $pkColumn,
            'pkValue' => $pk,
            'csrfToken' => Csrf::token(),
        ]);
    }

    public function updateRow(string $db, string $table, string $pk): string
    {
        if (($guard = Auth::requireRole(Roles::EDITOR, Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }
        $this->assertValidTable($db, $table);
        $pkColumn = $this->primaryKeyColumn($db, $table);

        $user = Auth::currentUser();
        $this->updateRowData($db, $table, $pkColumn, $pk, $_POST['fields'] ?? [], $user['id'], $user['username']);

        return Http::redirect("/db/{$db}/table/{$table}");
    }

    public function deleteRow(string $db, string $table, string $pk): string
    {
        if (($guard = Auth::requireRole(Roles::EDITOR, Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }
        $this->assertValidTable($db, $table);
        $pkColumn = $this->primaryKeyColumn($db, $table);

        $user = Auth::currentUser();
        $this->deleteRowData($db, $table, $pkColumn, $pk, $user['id'], $user['username']);

        return Http::redirect("/db/{$db}/table/{$table}");
    }
```

- [ ] **Step 5: Create the row_form view**

Create `resources/views/row_form.php`:

```php
<h1><?= $row === null ? 'Insert row into' : 'Edit row in' ?> <?= \App\View::e($db) ?>.<?= \App\View::e($table) ?></h1>
<form method="post" action="<?= $row === null
    ? '/db/' . \App\View::e($db) . '/table/' . \App\View::e($table) . '/rows'
    : '/db/' . \App\View::e($db) . '/table/' . \App\View::e($table) . '/row/' . \App\View::e((string) $pkValue) ?>">
    <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
    <?php foreach ($columns as $col): $name = $col['COLUMN_NAME']; ?>
        <?php if ($row === null || $name !== $primaryKey): ?>
        <label><?= \App\View::e($name) ?> (<?= \App\View::e($col['DATA_TYPE']) ?>)
            <input type="text" name="fields[<?= \App\View::e($name) ?>]" value="<?= \App\View::e((string) ($row[$name] ?? $col['COLUMN_DEFAULT'] ?? '')) ?>">
        </label>
        <?php else: ?>
        <p><?= \App\View::e($name) ?>: <?= \App\View::e((string) $row[$name]) ?> (primary key, not editable)</p>
        <?php endif; ?>
    <?php endforeach; ?>
    <button type="submit"><?= $row === null ? 'Insert' : 'Save' ?></button>
</form>
```

- [ ] **Step 6: Run the tests to see them pass**

Run: `vendor/bin/phpunit tests/Integration/TableDataMutationTest.php`
Expected: PASS (9 tests).

- [ ] **Step 7: Wire the mutation routes into the front controller**

Modify `public/index.php` — after the table-data route, add:

```php
$router->add('GET', '/db/{db}/table/{table}/new', fn($p) => $tables->newRowForm($p['db'], $p['table']));
$router->add('POST', '/db/{db}/table/{table}/rows', fn($p) => $tables->createRow($p['db'], $p['table']));
$router->add('GET', '/db/{db}/table/{table}/row/{pk}/edit', fn($p) => $tables->editRowForm($p['db'], $p['table'], $p['pk']));
$router->add('POST', '/db/{db}/table/{table}/row/{pk}', fn($p) => $tables->updateRow($p['db'], $p['table'], $p['pk']));
$router->add('POST', '/db/{db}/table/{table}/row/{pk}/delete', fn($p) => $tables->deleteRow($p['db'], $p['table'], $p['pk']));
```

- [ ] **Step 8: Manual smoke test**

Run: `php -S 127.0.0.1:8080 -t public`, log in as an editor/admin, insert a row via the UI, edit it, then delete it, confirming each action redirects back to the table view and the row list updates correctly.

- [ ] **Step 9: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests green).

- [ ] **Step 10: Commit**

```bash
git add app/Controllers/TableController.php resources/views/row_form.php tests/Integration/TableDataMutationTest.php public/index.php
git commit -m "Add insert/edit/delete row actions with audit logging"
```

---

### Task 12: SQL console

**Files:**
- Create: `app/Controllers/SqlConsoleController.php`
- Create: `resources/views/sql_console.php`
- Create: `tests/Integration/SqlConsoleTest.php`
- Modify: `public/index.php` (add SQL console routes)

**Interfaces:**
- Consumes: `App\SqlStatementClassifier` (Task 3), `App\Roles` (Task 3), `App\AuditLog` (Task 6), `App\Csrf` (Task 4), `App\Auth` (Task 8)
- Produces: `App\Controllers\SqlConsoleController::show(): string`, `::execute(): string`, `::runStatement(string $sql, string $role, int $userId, string $username): array` (returns `['ok' => bool, 'rows' => ?array, 'rowCount' => int]` or `['ok' => false, 'error' => string]`)

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/SqlConsoleTest.php`:

```php
<?php

namespace Tests\Integration;

use App\AuditLog;
use App\Controllers\SqlConsoleController;
use App\Database;
use App\Roles;
use PHPUnit\Framework\TestCase;

final class SqlConsoleTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
        $this->pdo->exec('CREATE DATABASE wbtest_fixture');
        $this->pdo->exec('CREATE TABLE wbtest_fixture.widgets (id INT PRIMARY KEY, name VARCHAR(50))');
        $this->pdo->exec("INSERT INTO wbtest_fixture.widgets VALUES (1, 'bolt')");
        $this->pdo->exec('TRUNCATE TABLE audit_log');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP DATABASE IF EXISTS wbtest_fixture');
    }

    public function test_viewer_can_run_select(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement('SELECT * FROM wbtest_fixture.widgets', Roles::VIEWER, 1, 'vic');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['rowCount']);
    }

    public function test_viewer_cannot_run_insert(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement("INSERT INTO wbtest_fixture.widgets VALUES (2, 'nail')", Roles::VIEWER, 1, 'vic');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('INSERT', $result['error']);
    }

    public function test_editor_can_run_insert_but_not_ddl(): void
    {
        $console = new SqlConsoleController();

        $insert = $console->runStatement("INSERT INTO wbtest_fixture.widgets VALUES (2, 'nail')", Roles::EDITOR, 1, 'ed');
        $this->assertTrue($insert['ok']);

        $ddl = $console->runStatement('DROP TABLE wbtest_fixture.widgets', Roles::EDITOR, 1, 'ed');
        $this->assertFalse($ddl['ok']);
    }

    public function test_admin_can_run_ddl(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement('ALTER TABLE wbtest_fixture.widgets ADD COLUMN qty INT', Roles::ADMIN, 1, 'admin1');

        $this->assertTrue($result['ok']);
    }

    public function test_every_execution_is_audited_including_rejections(): void
    {
        $console = new SqlConsoleController();
        $console->runStatement('SELECT * FROM wbtest_fixture.widgets', Roles::VIEWER, 1, 'vic');
        $console->runStatement('DELETE FROM wbtest_fixture.widgets', Roles::VIEWER, 1, 'vic');

        $entries = (new AuditLog($this->pdo))->recent();

        $this->assertCount(2, $entries);
        $this->assertSame('SQL_REJECTED', $entries[0]['action_type']);
        $this->assertSame('SQL_EXEC', $entries[1]['action_type']);
    }

    public function test_sql_errors_are_caught_and_audited(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement('SELECT * FROM wbtest_fixture.no_such_table', Roles::ADMIN, 1, 'admin1');

        $this->assertFalse($result['ok']);
        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('SQL_ERROR', $entries[0]['action_type']);
    }

    public function test_empty_statement_is_rejected_without_touching_the_database(): void
    {
        $console = new SqlConsoleController();

        $result = $console->runStatement('', Roles::ADMIN, 1, 'admin1');

        $this->assertFalse($result['ok']);
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/SqlConsoleTest.php`
Expected: FAIL — `Class "App\Controllers\SqlConsoleController" not found`.

- [ ] **Step 3: Implement SqlConsoleController**

Create `app/Controllers/SqlConsoleController.php`:

```php
<?php

namespace App\Controllers;

use App\AuditLog;
use App\Auth;
use App\Csrf;
use App\Database;
use App\Roles;
use App\SqlStatementClassifier;
use App\View;
use PDO;
use PDOException;

final class SqlConsoleController
{
    private PDO $pdo;
    private AuditLog $auditLog;

    public function __construct()
    {
        $this->pdo = Database::connection();
        $this->auditLog = new AuditLog($this->pdo);
    }

    public function show(): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }
        return View::render('sql_console', [
            'user' => Auth::currentUser(),
            'csrfToken' => Csrf::token(),
            'result' => null,
            'sql' => '',
        ]);
    }

    public function execute(): string
    {
        if (($guard = Auth::requireLogin()) !== null) {
            return $guard;
        }
        $user = Auth::currentUser();

        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return View::render('sql_console', [
                'user' => $user,
                'csrfToken' => Csrf::token(),
                'result' => ['ok' => false, 'error' => 'Invalid form submission, please try again.'],
                'sql' => (string) ($_POST['sql'] ?? ''),
            ]);
        }

        $sql = trim((string) ($_POST['sql'] ?? ''));
        $result = $this->runStatement($sql, $user['role'], $user['id'], $user['username']);

        return View::render('sql_console', [
            'user' => $user,
            'csrfToken' => Csrf::token(),
            'result' => $result,
            'sql' => $sql,
        ]);
    }

    public function runStatement(string $sql, string $role, int $userId, string $username): array
    {
        if ($sql === '') {
            return ['ok' => false, 'error' => 'Enter a SQL statement to run.'];
        }

        $type = SqlStatementClassifier::classify($sql);
        if (!Roles::canRunStatementType($role, $type)) {
            $this->auditLog->record($userId, $username, 'SQL_REJECTED', null, null, $sql);
            return ['ok' => false, 'error' => "Your role is not permitted to run {$type} statements."];
        }

        try {
            if ($type === 'SELECT') {
                $stmt = $this->pdo->query($sql);
                $rows = $stmt->fetchAll();
                $this->auditLog->record($userId, $username, 'SQL_EXEC', null, null, $sql);
                return ['ok' => true, 'rows' => $rows, 'rowCount' => count($rows)];
            }

            $affected = $this->pdo->exec($sql);
            $this->auditLog->record($userId, $username, 'SQL_EXEC', null, null, $sql);
            return ['ok' => true, 'rows' => null, 'rowCount' => $affected];
        } catch (PDOException $e) {
            $this->auditLog->record($userId, $username, 'SQL_ERROR', null, null, $sql . ' -- ERROR: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
```

- [ ] **Step 4: Create the sql_console view**

Create `resources/views/sql_console.php`:

```php
<h1>SQL Console</h1>
<form method="post" action="/sql">
    <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
    <textarea name="sql" rows="6" cols="80"><?= \App\View::e($sql) ?></textarea><br>
    <button type="submit">Run</button>
</form>

<?php if ($result !== null): ?>
    <?php if (!$result['ok']): ?>
        <p class="error"><?= \App\View::e($result['error']) ?></p>
    <?php elseif ($result['rows'] !== null): ?>
        <p><?= (int) $result['rowCount'] ?> row(s) returned.</p>
        <?php if ($result['rows']): ?>
        <table class="grid">
        <thead><tr><?php foreach (array_keys($result['rows'][0]) as $col): ?><th><?= \App\View::e($col) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $row): ?>
            <tr><?php foreach ($row as $value): ?><td><?= \App\View::e((string) $value) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        <?php endif; ?>
    <?php else: ?>
        <p><?= (int) $result['rowCount'] ?> row(s) affected.</p>
    <?php endif; ?>
<?php endif; ?>
```

- [ ] **Step 5: Run the tests to see them pass**

Run: `vendor/bin/phpunit tests/Integration/SqlConsoleTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Wire the SQL console routes into the front controller**

Modify `public/index.php` — add the import and routes:

```php
use App\Controllers\SqlConsoleController;
```

```php
$sqlConsole = new SqlConsoleController();

$router->add('GET', '/sql', fn($p) => $sqlConsole->show());
$router->add('POST', '/sql', fn($p) => $sqlConsole->execute());
```

- [ ] **Step 7: Manual smoke test**

Run: `php -S 127.0.0.1:8080 -t public`, log in, visit `/sql`, run `SELECT 1`, confirm the result table renders; log in as a viewer and confirm an `INSERT` is rejected with a clear error.

- [ ] **Step 8: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests green).

- [ ] **Step 9: Commit**

```bash
git add app/Controllers/SqlConsoleController.php resources/views/sql_console.php tests/Integration/SqlConsoleTest.php public/index.php
git commit -m "Add role-gated SQL console with audit logging"
```

---

### Task 13: Audit log page

**Files:**
- Create: `app/Controllers/AuditController.php`
- Create: `resources/views/audit_log.php`
- Create: `tests/Integration/AuditPageTest.php`
- Modify: `public/index.php` (add the audit route)

**Interfaces:**
- Consumes: `App\AuditLog::recent()` (Task 6), `App\Auth::requireRole()` (Task 8)
- Produces: `App\Controllers\AuditController::index(): string`

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/AuditPageTest.php`:

```php
<?php

namespace Tests\Integration;

use App\AuditLog;
use App\Controllers\AuditController;
use App\Database;
use PHPUnit\Framework\TestCase;

final class AuditPageTest extends TestCase
{
    protected function setUp(): void
    {
        Database::connection()->exec('TRUNCATE TABLE audit_log');
        (new AuditLog(Database::connection()))->record(1, 'alice', 'SQL_EXEC', null, null, 'SELECT 1');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = [];
    }

    public function test_admin_sees_the_audit_page(): void
    {
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'admin'];
        $controller = new AuditController();

        $html = $controller->index();

        $this->assertStringContainsString('SQL_EXEC', $html);
    }

    public function test_non_admin_is_blocked(): void
    {
        $_SESSION = ['user_id' => 2, 'username' => 'vic', 'role' => 'viewer'];
        $controller = new AuditController();

        $html = $controller->index();

        $this->assertStringNotContainsString('SQL_EXEC', $html);
    }

    public function test_username_filter_is_applied(): void
    {
        (new AuditLog(Database::connection()))->record(2, 'bob', 'SQL_EXEC', null, null, 'SELECT 2');
        $_SESSION = ['user_id' => 1, 'username' => 'alice', 'role' => 'admin'];
        $_GET = ['username' => 'bob'];
        $controller = new AuditController();

        $html = $controller->index();

        $this->assertStringContainsString('bob', $html);
        $this->assertStringNotContainsString('>alice<', $html);
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/AuditPageTest.php`
Expected: FAIL — `Class "App\Controllers\AuditController" not found`.

- [ ] **Step 3: Implement AuditController**

Create `app/Controllers/AuditController.php`:

```php
<?php

namespace App\Controllers;

use App\AuditLog;
use App\Auth;
use App\Database;
use App\Roles;
use App\View;

final class AuditController
{
    public function index(): string
    {
        if (($guard = Auth::requireRole(Roles::ADMIN)) !== null) {
            return $guard;
        }

        $auditLog = new AuditLog(Database::connection());
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 50;
        $username = $_GET['username'] ?? null;
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;

        $entries = $auditLog->recent($pageSize, ($page - 1) * $pageSize, $username ?: null, $from ?: null, $to ?: null);

        return View::render('audit_log', [
            'user' => Auth::currentUser(),
            'entries' => $entries,
            'page' => $page,
            'filters' => ['username' => $username, 'from' => $from, 'to' => $to],
        ]);
    }
}
```

- [ ] **Step 4: Create the audit_log view**

Create `resources/views/audit_log.php`:

```php
<h1>Audit Log</h1>
<form method="get" class="filter-form">
    <label>Username <input type="text" name="username" value="<?= \App\View::e($filters['username'] ?? '') ?>"></label>
    <label>From <input type="date" name="from" value="<?= \App\View::e($filters['from'] ?? '') ?>"></label>
    <label>To <input type="date" name="to" value="<?= \App\View::e($filters['to'] ?? '') ?>"></label>
    <button type="submit">Filter</button>
</form>
<table class="grid">
<thead><tr><th>When</th><th>User</th><th>Action</th><th>DB</th><th>Table</th><th>Detail</th></tr></thead>
<tbody>
<?php foreach ($entries as $entry): ?>
<tr>
    <td><?= \App\View::e($entry['created_at']) ?></td>
    <td><?= \App\View::e($entry['username']) ?></td>
    <td><?= \App\View::e($entry['action_type']) ?></td>
    <td><?= \App\View::e((string) $entry['target_db']) ?></td>
    <td><?= \App\View::e((string) $entry['target_table']) ?></td>
    <td><code><?= \App\View::e($entry['detail']) ?></code></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p>Page <?= $page ?></p>
```

- [ ] **Step 5: Run the tests to see them pass**

Run: `vendor/bin/phpunit tests/Integration/AuditPageTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Wire the audit route into the front controller**

Modify `public/index.php` — add the import and route:

```php
use App\Controllers\AuditController;
```

```php
$audit = new AuditController();

$router->add('GET', '/audit', fn($p) => $audit->index());
```

- [ ] **Step 7: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests green).

- [ ] **Step 8: Commit**

```bash
git add app/Controllers/AuditController.php resources/views/audit_log.php tests/Integration/AuditPageTest.php public/index.php
git commit -m "Add admin-only audit log page with filtering"
```

---

### Task 14: Manage app users (Admin only)

**Files:**
- Create: `app/Controllers/UserController.php`
- Create: `resources/views/users.php`
- Create: `tests/Integration/UserManagementTest.php`
- Modify: `public/index.php` (add user management routes)

**Interfaces:**
- Consumes: `App\Auth::requireRole()` (Task 8), `App\Csrf` (Task 4), `App\Roles` (Task 3), `App\AuditLog` (Task 6)
- Produces: `App\Controllers\UserController::index(): string`, `::create(): string`, `::updateRole(int $userId): string`, `::setActive(int $userId): string`, `::all(): array`, `::createUser(string $username, string $password, string $role, int $actorId, string $actorUsername): void`, `::setRole(int $userId, string $role, int $actorId, string $actorUsername): void`, `::setActiveState(int $userId, bool $active, int $actorId, string $actorUsername): void`

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/UserManagementTest.php`:

```php
<?php

namespace Tests\Integration;

use App\AuditLog;
use App\Controllers\UserController;
use App\Csrf;
use App\Database;
use PHPUnit\Framework\TestCase;

final class UserManagementTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('TRUNCATE TABLE app_users');
        $this->pdo->exec('TRUNCATE TABLE audit_log');
        $_SESSION = ['user_id' => 1, 'username' => 'admin1', 'role' => 'admin'];
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
    }

    public function test_create_user_hashes_password_and_logs(): void
    {
        $controller = new UserController();

        $controller->createUser('bob', 'secret123', 'editor', 1, 'admin1');

        $row = $this->pdo->query("SELECT * FROM app_users WHERE username = 'bob'")->fetch();
        $this->assertNotFalse($row);
        $this->assertTrue(password_verify('secret123', $row['password_hash']));
        $this->assertSame('editor', $row['role']);

        $entries = (new AuditLog($this->pdo))->recent();
        $this->assertSame('USER_CREATE', $entries[0]['action_type']);
    }

    public function test_set_role_changes_role(): void
    {
        $controller = new UserController();
        $controller->createUser('bob', 'secret123', 'viewer', 1, 'admin1');
        $userId = (int) $this->pdo->query("SELECT id FROM app_users WHERE username = 'bob'")->fetchColumn();

        $controller->setRole($userId, 'admin', 1, 'admin1');

        $role = $this->pdo->query("SELECT role FROM app_users WHERE id = {$userId}")->fetchColumn();
        $this->assertSame('admin', $role);
    }

    public function test_set_active_state_disables_a_user(): void
    {
        $controller = new UserController();
        $controller->createUser('bob', 'secret123', 'viewer', 1, 'admin1');
        $userId = (int) $this->pdo->query("SELECT id FROM app_users WHERE username = 'bob'")->fetchColumn();

        $controller->setActiveState($userId, false, 1, 'admin1');

        $active = (int) $this->pdo->query("SELECT is_active FROM app_users WHERE id = {$userId}")->fetchColumn();
        $this->assertSame(0, $active);
    }

    public function test_create_action_rejects_duplicate_username(): void
    {
        $controller = new UserController();
        $controller->createUser('bob', 'secret123', 'viewer', 1, 'admin1');
        $_POST = ['csrf_token' => Csrf::token(), 'username' => 'bob', 'password' => 'other-pass', 'role' => 'viewer'];

        $controller->create();

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM app_users WHERE username = 'bob'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_create_action_blocked_for_non_admin(): void
    {
        $_SESSION = ['user_id' => 2, 'username' => 'ed', 'role' => 'editor'];
        $_POST = ['csrf_token' => Csrf::token(), 'username' => 'carol', 'password' => 'secret123', 'role' => 'viewer'];
        $controller = new UserController();

        $controller->create();

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM app_users WHERE username = 'carol'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function test_index_lists_users(): void
    {
        $controller = new UserController();
        $controller->createUser('bob', 'secret123', 'viewer', 1, 'admin1');

        $html = $controller->index();

        $this->assertStringContainsString('bob', $html);
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/UserManagementTest.php`
Expected: FAIL — `Class "App\Controllers\UserController" not found`.

- [ ] **Step 3: Implement UserController**

Create `app/Controllers/UserController.php`:

```php
<?php

namespace App\Controllers;

use App\AuditLog;
use App\Auth;
use App\Csrf;
use App\Database;
use App\Http;
use App\Roles;
use App\View;
use PDO;

final class UserController
{
    private PDO $pdo;
    private AuditLog $auditLog;

    public function __construct()
    {
        $this->pdo = Database::connection();
        $this->auditLog = new AuditLog($this->pdo);
    }

    public function index(): string
    {
        if (($guard = Auth::requireRole(Roles::ADMIN)) !== null) {
            return $guard;
        }
        return View::render('users', [
            'user' => Auth::currentUser(),
            'users' => $this->all(),
            'roles' => Roles::ALL,
            'csrfToken' => Csrf::token(),
        ]);
    }

    public function create(): string
    {
        if (($guard = Auth::requireRole(Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? '');

        if ($username === '' || $password === '' || !Roles::isValid($role)) {
            http_response_code(422);
            return 'Username, password, and a valid role are required.';
        }

        $exists = $this->pdo->prepare('SELECT 1 FROM app_users WHERE username = :u');
        $exists->execute(['u' => $username]);
        if ($exists->fetchColumn()) {
            http_response_code(409);
            return 'That username already exists.';
        }

        $actor = Auth::currentUser();
        $this->createUser($username, $password, $role, $actor['id'], $actor['username']);

        return Http::redirect('/users');
    }

    public function updateRole(int $userId): string
    {
        if (($guard = Auth::requireRole(Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }

        $role = (string) ($_POST['role'] ?? '');
        if (!Roles::isValid($role)) {
            http_response_code(422);
            return 'Invalid role.';
        }

        $actor = Auth::currentUser();
        $this->setRole($userId, $role, $actor['id'], $actor['username']);

        return Http::redirect('/users');
    }

    public function setActive(int $userId): string
    {
        if (($guard = Auth::requireRole(Roles::ADMIN)) !== null) {
            return $guard;
        }
        if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            return 'Invalid form submission.';
        }

        $active = ((string) ($_POST['active'] ?? '0')) === '1';
        $actor = Auth::currentUser();
        $this->setActiveState($userId, $active, $actor['id'], $actor['username']);

        return Http::redirect('/users');
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT id, username, role, is_active, created_at FROM app_users ORDER BY username')->fetchAll();
    }

    public function createUser(string $username, string $password, string $role, int $actorId, string $actorUsername): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_users (username, password_hash, role, is_active) VALUES (:u, :p, :r, 1)'
        );
        $stmt->execute([
            'u' => $username,
            'p' => password_hash($password, PASSWORD_DEFAULT),
            'r' => $role,
        ]);
        $this->auditLog->record($actorId, $actorUsername, 'USER_CREATE', null, null, "created user '{$username}' with role '{$role}'");
    }

    public function setRole(int $userId, string $role, int $actorId, string $actorUsername): void
    {
        $stmt = $this->pdo->prepare('UPDATE app_users SET role = :r WHERE id = :id');
        $stmt->execute(['r' => $role, 'id' => $userId]);
        $this->auditLog->record($actorId, $actorUsername, 'USER_UPDATE', null, null, "set user #{$userId} role to '{$role}'");
    }

    public function setActiveState(int $userId, bool $active, int $actorId, string $actorUsername): void
    {
        $stmt = $this->pdo->prepare('UPDATE app_users SET is_active = :a WHERE id = :id');
        $stmt->execute(['a' => $active ? 1 : 0, 'id' => $userId]);
        $this->auditLog->record($actorId, $actorUsername, 'USER_UPDATE', null, null, 'set user #' . $userId . ' active=' . ($active ? '1' : '0'));
    }
}
```

- [ ] **Step 4: Create the users view**

Create `resources/views/users.php`:

```php
<h1>Manage Users</h1>
<table class="grid">
<thead><tr><th>Username</th><th>Role</th><th>Active</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($users as $u): ?>
<tr>
    <td><?= \App\View::e($u['username']) ?></td>
    <td>
        <form method="post" action="/users/<?= (int) $u['id'] ?>/role" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
            <select name="role" onchange="this.form.submit()">
            <?php foreach ($roles as $r): ?>
                <option value="<?= \App\View::e($r) ?>" <?= $r === $u['role'] ? 'selected' : '' ?>><?= \App\View::e($r) ?></option>
            <?php endforeach; ?>
            </select>
        </form>
    </td>
    <td><?= $u['is_active'] ? 'Yes' : 'No' ?></td>
    <td>
        <form method="post" action="/users/<?= (int) $u['id'] ?>/active" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
            <input type="hidden" name="active" value="<?= $u['is_active'] ? '0' : '1' ?>">
            <button type="submit"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h2>Add User</h2>
<form method="post" action="/users">
    <input type="hidden" name="csrf_token" value="<?= \App\View::e($csrfToken) ?>">
    <label>Username <input type="text" name="username" required></label>
    <label>Password <input type="password" name="password" required></label>
    <label>Role
        <select name="role">
        <?php foreach ($roles as $r): ?><option value="<?= \App\View::e($r) ?>"><?= \App\View::e($r) ?></option><?php endforeach; ?>
        </select>
    </label>
    <button type="submit">Create</button>
</form>
```

- [ ] **Step 5: Run the tests to see them pass**

Run: `vendor/bin/phpunit tests/Integration/UserManagementTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Wire the user management routes into the front controller**

Modify `public/index.php` — add the import and routes:

```php
use App\Controllers\UserController;
```

```php
$users = new UserController();

$router->add('GET', '/users', fn($p) => $users->index());
$router->add('POST', '/users', fn($p) => $users->create());
$router->add('POST', '/users/{id}/role', fn($p) => $users->updateRole((int) $p['id']));
$router->add('POST', '/users/{id}/active', fn($p) => $users->setActive((int) $p['id']));
```

- [ ] **Step 7: Manual smoke test**

Run: `php -S 127.0.0.1:8080 -t public`, log in as admin, visit `/users`, create a new editor account, change its role, disable it, then confirm a non-admin who visits `/users` gets a 403.

- [ ] **Step 8: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests green).

- [ ] **Step 9: Commit**

```bash
git add app/Controllers/UserController.php resources/views/users.php tests/Integration/UserManagementTest.php public/index.php
git commit -m "Add admin-only app user management"
```

---

### Task 15: CLI bootstrap script for the first admin

**Files:**
- Create: `app/AdminBootstrapper.php`
- Create: `bin/bootstrap_admin.php`
- Create: `tests/Integration/AdminBootstrapperTest.php`

**Interfaces:**
- Consumes: `App\Roles::ADMIN` (Task 3), `App\Database::connection()` (Task 2)
- Produces: `App\AdminBootstrapper::__construct(\PDO $pdo)`, `::createFirstAdmin(string $username, string $password): void`

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/AdminBootstrapperTest.php`:

```php
<?php

namespace Tests\Integration;

use App\AdminBootstrapper;
use App\Database;
use PHPUnit\Framework\TestCase;

final class AdminBootstrapperTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('TRUNCATE TABLE app_users');
    }

    public function test_creates_an_admin_user(): void
    {
        $bootstrapper = new AdminBootstrapper($this->pdo);

        $bootstrapper->createFirstAdmin('root', 'super-secret');

        $row = $this->pdo->query("SELECT * FROM app_users WHERE username = 'root'")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('admin', $row['role']);
        $this->assertTrue(password_verify('super-secret', $row['password_hash']));
    }

    public function test_refuses_to_create_a_duplicate_username(): void
    {
        $bootstrapper = new AdminBootstrapper($this->pdo);
        $bootstrapper->createFirstAdmin('root', 'super-secret');

        $this->expectException(\RuntimeException::class);
        $bootstrapper->createFirstAdmin('root', 'another-password');
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Integration/AdminBootstrapperTest.php`
Expected: FAIL — `Class "App\AdminBootstrapper" not found`.

- [ ] **Step 3: Implement AdminBootstrapper**

Create `app/AdminBootstrapper.php`:

```php
<?php

namespace App;

use PDO;

final class AdminBootstrapper
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createFirstAdmin(string $username, string $password): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM app_users WHERE username = :u');
        $stmt->execute(['u' => $username]);
        if ($stmt->fetchColumn()) {
            throw new \RuntimeException("User '{$username}' already exists.");
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO app_users (username, password_hash, role, is_active) VALUES (:u, :p, :r, 1)'
        );
        $insert->execute([
            'u' => $username,
            'p' => password_hash($password, PASSWORD_DEFAULT),
            'r' => Roles::ADMIN,
        ]);
    }
}
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `vendor/bin/phpunit tests/Integration/AdminBootstrapperTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Create the CLI script**

Create `bin/bootstrap_admin.php`:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use App\AdminBootstrapper;
use App\Config;
use App\Database;

$envFile = $argv[1] ?? __DIR__ . '/../.env';
Config::load($envFile);

echo "Admin username: ";
$username = trim((string) fgets(STDIN));

echo "Admin password: ";
system('stty -echo');
$password = trim((string) fgets(STDIN));
system('stty echo');
echo "\n";

if ($username === '' || $password === '') {
    fwrite(STDERR, "Username and password are required.\n");
    exit(1);
}

$bootstrapper = new AdminBootstrapper(Database::connection());

try {
    $bootstrapper->createFirstAdmin($username, $password);
    echo "Admin user '{$username}' created.\n";
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
```

This script is meant to run on the Linux server (the `stty` calls that hide password input assume a POSIX terminal).

- [ ] **Step 6: Manual check**

Run: `php bin/bootstrap_admin.php .env.testing`, enter a throwaway username/password, and confirm it prints `Admin user '<name>' created.` and that a corresponding row exists in `app_users`. Then delete that test row: `DELETE FROM app_users WHERE username = '<name>';` via a MariaDB client.

- [ ] **Step 7: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all tests green).

- [ ] **Step 8: Commit**

```bash
git add app/AdminBootstrapper.php bin/bootstrap_admin.php tests/Integration/AdminBootstrapperTest.php
git commit -m "Add CLI bootstrap script for the first admin account"
```

---

### Task 16: Deployment (Nginx, PHP-FPM, RHEL, TLS, firewall)

**Files:**
- Create: `deploy/nginx.conf.example`
- Create: `deploy/php-fpm-pool.conf.example`
- Create: `docs/DEPLOY.md`

This task has no automated test — it produces the deployment artifacts and a runbook, verified by manual review and (once you have server access) a real deployment.

- [ ] **Step 1: Create the Nginx site config**

Create `deploy/nginx.conf.example`:

```nginx
server {
    listen 443 ssl;
    server_name db-admin.internal;

    ssl_certificate     /etc/pki/tls/certs/dbwebui.crt;
    ssl_certificate_key /etc/pki/tls/private/dbwebui.key;

    root /var/www/dbwebui/public;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/dbwebui.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}

server {
    listen 80;
    server_name db-admin.internal;
    return 301 https://$host$request_uri;
}
```

Note only `public/` is exposed as the document root — `app/`, `resources/`, `migrations/`, `bin/`, and config files are not reachable over HTTP.

- [ ] **Step 2: Create the PHP-FPM pool config**

Create `deploy/php-fpm-pool.conf.example`:

```ini
[dbwebui]
user = dbwebui
group = dbwebui
listen = /run/php-fpm/dbwebui.sock
listen.owner = nginx
listen.group = nginx
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
```

- [ ] **Step 3: Write the deployment runbook**

Create `docs/DEPLOY.md`:

```markdown
# Deploying the MariaDB Web Admin Tool (Phase 1)

Target: a fresh RHEL-family server (RHEL/CentOS/Rocky/AlmaLinux) that
already has MariaDB installed, with the app running on the same box.

## 1. Install the web stack

    sudo dnf install -y nginx php-fpm php-pdo php-mysqlnd composer git

## 2. Create the MariaDB service account and app schema

    sudo mysql -u root -p <<'SQL'
    CREATE DATABASE dbwebui_app;
    CREATE USER 'dbwebui_svc'@'localhost' IDENTIFIED BY 'CHANGE_ME';
    GRANT ALL PRIVILEGES ON dbwebui_app.* TO 'dbwebui_svc'@'localhost';
    GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX,
          REFERENCES, TRUNCATE ON `your_app_db`.* TO 'dbwebui_svc'@'localhost';
    -- Repeat the GRANT line for every database this tool should manage.
    -- Do NOT grant on *.* in production — scope it to the databases you
    -- actually want browsable/editable through this tool.
    FLUSH PRIVILEGES;
    SQL

## 3. Deploy the application code

    sudo mkdir -p /var/www/dbwebui
    sudo git clone <your-repo-url> /var/www/dbwebui
    cd /var/www/dbwebui
    composer install --no-dev --optimize-autoloader
    sudo useradd --system --no-create-home dbwebui
    sudo chown -R dbwebui:dbwebui /var/www/dbwebui

## 4. Configure the app

    sudo -u dbwebui cp .env.example .env
    sudo -u dbwebui vi .env   # set DB_HOST, DB_USER, DB_PASS, DB_APP_SCHEMA

## 5. Run migrations and create the first admin

    sudo -u dbwebui php bin/migrate.php
    sudo -u dbwebui php bin/bootstrap_admin.php

## 6. Configure PHP-FPM and Nginx

    sudo cp deploy/php-fpm-pool.conf.example /etc/php-fpm.d/dbwebui.conf
    sudo cp deploy/nginx.conf.example /etc/nginx/conf.d/dbwebui.conf
    # Edit both to match your paths/hostname if you deviated from the defaults.

## 7. TLS certificate

Default (no public domain yet) — a self-signed certificate:

    sudo mkdir -p /etc/pki/tls/private
    sudo openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
      -keyout /etc/pki/tls/private/dbwebui.key \
      -out /etc/pki/tls/certs/dbwebui.crt \
      -subj "/CN=db-admin.internal"

Browsers will warn once on first visit (expected for a self-signed cert on
an internal tool). If a public domain is later pointed at this server,
switch to Let's Encrypt instead:

    sudo dnf install -y certbot python3-certbot-nginx
    sudo certbot --nginx -d your-real-domain.example.com

## 8. Firewall

    sudo firewall-cmd --permanent --add-service=https
    sudo firewall-cmd --permanent --add-service=http   # only needed for the 80->443 redirect
    sudo firewall-cmd --reload

MariaDB's port is not opened externally — the app connects over
localhost, and that's the only access path this deployment needs.

## 9. Start services

    sudo systemctl enable --now php-fpm nginx
    sudo systemctl restart php-fpm nginx

## 10. Smoke test

Visit `https://<server-hostname>/login`, accept the self-signed cert
warning (if applicable), and log in with the admin account created in
step 5. Confirm you can see the database list, browse a real table, run
`SELECT 1` in the SQL console, and that the action shows up on `/audit`.
```

- [ ] **Step 4: Review the runbook against the spec's Deployment section**

Re-read `docs/superpowers/specs/2026-09-21-mariadb-web-admin-phase1-design.md`'s "Deployment" section and confirm every point there (RHEL-family, Nginx+PHP-FPM, self-signed default with Let's Encrypt noted as an alternative, front-controller-only document root, firewalld scoped to the app's own port, MariaDB port not exposed) is reflected in `docs/DEPLOY.md`. Fix any gaps inline.

- [ ] **Step 5: Commit**

```bash
git add deploy/nginx.conf.example deploy/php-fpm-pool.conf.example docs/DEPLOY.md
git commit -m "Add Nginx/PHP-FPM deployment configs and RHEL deployment runbook"
```

---

## Plan self-review notes

- **Spec coverage:** app auth/roles → Task 8; database/table browser → Task 9; data grid (view/insert/update/delete) → Tasks 10–11; SQL console → Task 12; audit log → Tasks 6 & 13; manage app users → Task 14; bootstrap → Task 15; deployment → Task 16. No spec section is without a task.
- **Guard-pattern correctness:** `Auth::requireLogin()`/`requireRole()` return `null` to mean "proceed" — every call site in this plan uses `!== null`, not truthiness, because `Http::redirect()` returns `''` (falsy).
- **Deliberate deviation from the spec's wording:** the spec says "vanilla JS sprinkles" for sorting/pagination/inline edit; this plan implements those via plain links and forms instead (progressive enhancement, works without JS, less code, no build step). The resulting behavior matches the spec's functional intent; flagged here rather than silently diverging.
- **Naming consistency check:** `TableController`'s data-access methods (`listTables`, `columns`, `primaryKeyColumn`, `listRows`, `findRow`, `insertRow`, `updateRowData`, `deleteRowData`) and HTTP action methods (`listForDatabase`, `structure`, `data`, `newRowForm`, `createRow`, `editRowForm`, `updateRow`, `deleteRow`) use distinct names precisely so `updateRow` (HTTP action) and `updateRowData` (data access) never collide — verified consistent across Tasks 9–11.

