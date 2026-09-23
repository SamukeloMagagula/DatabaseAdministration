# Database Administration

A lightweight, plain-PHP web admin tool for MariaDB — browse databases and tables, edit data through a grid, run ad-hoc SQL, and manage who's allowed to do what, all from a browser. No framework, no build step.

## Features (Phase 1)

- **Login & roles** — session-based auth with three roles: `admin`, `editor`, `viewer`.
- **Database & table browser** — list databases and tables, inspect column structure.
- **Data grid** — paginated, sortable, filterable row listing with insert/edit/delete (editor and admin only).
- **SQL console** — run ad-hoc SQL, gated by role (viewer: `SELECT` only; editor: `SELECT`/`INSERT`/`UPDATE`/`DELETE`; admin: anything, including DDL).
- **Audit log** — every row mutation, SQL execution, and login attempt is recorded (admin-only view).
- **User management** — admins can create/manage app login accounts and roles.
- **CLI bootstrap** — a script to create the first admin account.

## Tech stack

Plain PHP 8.1+ (no framework), PDO for MariaDB access, PHPUnit for tests, Composer for autoloading only. Deploys behind Nginx + PHP-FPM.

## Project layout

```
app/                  Application classes (Auth, Roles, Csrf, Database, controllers, ...)
bin/                  CLI scripts (run migrations, bootstrap the first admin)
deploy/               Example Nginx / PHP-FPM configs
docs/                 Design spec, implementation plan, deployment runbook
migrations/           SQL schema migrations for the app's own tables
public/               Web root (front controller + static assets)
resources/views/      PHP templates
tests/                PHPUnit unit and integration tests
```

## Requirements

- PHP 8.1+ with the `pdo_mysql` extension
- Composer
- A MariaDB server (the app connects to it; it doesn't bundle one)

## Local setup

```bash
composer install
cp .env.example .env
cp .env.testing.example .env.testing
```

Edit `.env` (and `.env.testing`) with your database connection details. The app expects its own schema (default name `dbwebui_app`) to already exist, plus a MariaDB user with privileges on it and on whatever databases you want to manage. See [`docs/DEPLOY.md`](docs/DEPLOY.md) for the exact `CREATE USER`/`GRANT` statements.

Run migrations to create the app's own tables (`app_users`, `login_attempts`, `audit_log`):

```bash
php bin/migrate.php
```

Create the first admin account:

```bash
php bin/bootstrap_admin.php
```

Serve the app locally with PHP's built-in server:

```bash
php -S 127.0.0.1:8080 -t public
```

## Running the tests

```bash
composer install
vendor/bin/phpunit
```

Integration tests need a real MariaDB connection (configured via `.env.testing`) and a database user with enough privileges to create/drop a disposable fixture schema — see the "Prerequisites" section of [`docs/superpowers/plans/2026-09-22-mariadb-web-admin-phase1.md`](docs/superpowers/plans/2026-09-22-mariadb-web-admin-phase1.md) for the exact setup SQL.

## Deployment

See [`docs/DEPLOY.md`](docs/DEPLOY.md) for a full RHEL/Nginx/PHP-FPM deployment runbook, including TLS setup and firewall configuration.

## Security notes

- Roles are enforced in application code against a single shared MariaDB service account — there is no per-role database-level access control.
- The SQL console's guard against non-admins reaching the app's own tables is a heuristic (pattern-based), not a hard guarantee. For stronger isolation, use a separate, more restricted MariaDB account for the console/grid than the one used for the app's own login/audit tables.
- Report security concerns before opening a public issue if this repository is ever made public.
