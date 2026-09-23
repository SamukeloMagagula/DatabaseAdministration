# Database Administration

A lightweight, plain-PHP web admin tool for MariaDB — browse databases and tables, edit data through a grid, run ad-hoc SQL, and manage who's allowed to do what, all from a browser. No framework, no dependency toolchain.

## Features (Phase 1)

- **Login & roles** — session-based auth with three roles: `admin`, `editor`, `viewer`.
- **Database & table browser** — list databases and tables, inspect column structure.
- **Data grid** — paginated, sortable, filterable row listing with insert/edit/delete (editor and admin only).
- **SQL console** — run ad-hoc SQL, gated by role (viewer: `SELECT` only; editor: `SELECT`/`INSERT`/`UPDATE`/`DELETE`; admin: anything, including DDL).
- **Audit log** — every row mutation, SQL execution, and login attempt is recorded (admin-only view).
- **User management** — admins can create/manage app login accounts and roles.
- **CLI bootstrap** — a script to create the first admin account.

## Tech stack

Plain PHP 8.1+, functions instead of classes, no framework, no Composer, no PHPUnit — this app has no dependency toolchain by design, so it runs on a server exactly as checked out. PDO for MariaDB access. Deploys behind Nginx + PHP-FPM.

## Project layout

Every page a browser requests directly is its own file at the repository root — there is no front controller or router. Shared logic lives in small, single-purpose library files, also at the root, each named for what it does.

```
index.php, database.php, table.php,   Pages — request these directly.
sql.php, manage_users.php,            Each guards itself (require_login()/
audit_log.php                         require_role()) and renders a view.

auth/auth.php                         POST-only login/logout endpoint.
auth/guard.php                        require_login(), require_role(), current_user().

config.php                            DB credentials + connect(): PDO. Template only —
                                       see "Configuration" below.
settings.php                          Every other constant, env-overridable.
errors.php, view.php, csrf.php,       Small single-purpose libraries. Each is used by
roles.php, sql_classifier.php,        more than one page; none has its own page.
audit.php, ratelimit.php,
db_browser.php, grid.php,
sql_console.php, users.php

views/                                Plain PHP templates, rendered by view.php.
assets/                                Static files (stylesheet).

cli/bootstrap_admin.php               Create the first admin account.
schema.sql                            This app's own tables. Plain SQL, no migration
                                       runner — see "Database setup" below.

deploy/                               Example Nginx / PHP-FPM configs.
docs/                                 Design spec, implementation plan, deployment runbook.
tests/                                Custom test runner + test_*.php files.
```

## Requirements

- PHP 8.1+ with the `pdo_mysql` extension
- A MariaDB server (the app connects to it; it doesn't bundle one)

Nothing else — no Composer, no build step, no Node.

## Configuration

The checked-in `config.php` is a template with placeholder credentials, not something you edit in place. Copy it somewhere else and point `settings.php`'s `CONFIG_PATH` at it — either by editing the default in `settings.php`, or by setting the `DBADMIN_CONFIG` environment variable, which takes priority. In production this file lives outside the web-servable tree; see [`docs/DEPLOY.md`](docs/DEPLOY.md).

`connect()` (in `config.php`) deliberately opens with **no default database**. The SQL console lets an editor or viewer run SQL of their own choosing over that same connection — if it defaulted to this app's own schema, an unqualified `UPDATE app_users …` would silently land on it. Every query this app makes against its own tables names the schema explicitly, via `app_table()`.

## Database setup

Apply the schema file directly with the `mariadb` client — no database argument, since the file creates and selects its own:

```bash
mariadb < schema.sql
```

`schema.sql` creates its own database (`dbwebui_app` by default — if you changed `DB_APP_SCHEMA` in `settings.php`, edit the `CREATE DATABASE`/`USE` lines at the top of `schema.sql` to match) and then the app's own tables, all as `CREATE ... IF NOT EXISTS`, safe to re-run. There is no migration-tracking table and no runner — this app has no dependency toolchain, so schema changes are read and re-applied by hand, the same as any other plain `.sql` file.

Then create the first admin account:

```bash
php cli/bootstrap_admin.php
```

Every account after that is created from the Manage Users page by an admin who already exists.

## Running the app locally

```bash
php -S 127.0.0.1:8080
```

Log in at `http://127.0.0.1:8080/index.php`.

## Running the tests

```bash
cp tests/config.testing.php.example tests/config.testing.php
# edit tests/config.testing.php with a disposable MariaDB server's credentials
php tests/run.php
```

No Composer, no PHPUnit — `tests/run.php` is a ~100-line runner with `test()`/`same()`/`check()`. It creates and owns the `dbwebui_app_test` schema itself by applying `schema.sql`, and several test files also create/drop a throwaway fixture database (`wbtest_fixture`) — the account in `tests/config.testing.php` needs rights to do both.

Pages that guard themselves with `require_login()`/`require_role()` (everything under "Pages" above, plus `auth/auth.php`) are not covered by the automated suite: those functions `exit()` on failure, which the test runner has no way to intercept. What's tested instead is everything those pages call into — the SQL console's role and schema guards, the data grid's SQL, rate limiting, audit logging, user management — which is where a mistake would actually matter. This is a deliberate trade-off, not an oversight; treat any change to `auth/auth.php`, `table.php`, `sql.php`, or `manage_users.php` as needing a manual smoke test in a browser in addition to the suite.

## Deployment

See [`docs/DEPLOY.md`](docs/DEPLOY.md) for a full RHEL/Nginx/PHP-FPM deployment runbook, including TLS setup and firewall configuration.

## Security notes

- Roles are enforced in application code against a single shared MariaDB service account — there is no per-role database-level access control.
- The SQL console's guard against non-admins reaching the app's own tables is a heuristic (pattern-based), not a hard guarantee. For stronger isolation, use a separate, more restricted MariaDB account for the console/grid than the one used for the app's own login/audit tables.
- Report security concerns before opening a public issue if this repository is ever made public.
