# Database Administration

A lightweight, plain PHP web admin tool for MariaDB. Browse databases and tables, edit data through a grid, run ad hoc SQL, check server status, export data, and copy whole databases, all from a browser. No framework, no dependency toolchain.

## Features

1. **Login** using the same username and password people already use to access the server (checked via PAM, the same mechanism SSH uses). There is no separate app password to manage.
2. **Roles from server groups.** Membership in the `dbwebui-admin` or `dbwebui-editor` OS group decides what an account can do. Everyone else who can log into the box gets read only (viewer) access.
3. **Database and table browser** to list databases and tables and inspect column structure.
4. **Data grid**, paginated, sortable, and filterable, with insert, edit, and delete for editors and admins.
5. **SQL console** for arbitrary SQL, gated by role: viewers get `SELECT` only, editors add `INSERT`/`UPDATE`/`DELETE`, and admins can run anything, including DDL.
6. **Server status page** showing MariaDB's version, uptime, connection count, and the size of each managed database.
7. **Export** a table as CSV, export a whole database as a SQL dump, or open a print friendly view of a table to save as PDF from the browser's own print dialog.
8. **Copy a database**, admins only, creating a new database with the same tables and data under a new name.
9. **Audit log** recording every row change, SQL execution, login, and export, visible to admins.

## Tech stack

Plain PHP 8.1+, functions instead of classes, no framework, no Composer, no PHPUnit. This app has no dependency toolchain by design, so it runs on a server exactly as checked out. PDO handles MariaDB access and the PECL PAM extension handles login. Deploys behind Nginx and PHP-FPM.

## Project layout

Every page a browser requests directly is its own file at the repository root; there is no front controller or router. Shared logic lives in small files, also at the root, each named for what it does.

```
index.php, database.php, table.php,   Pages, request these directly.
sql.php, status.php, audit_log.php    Each starts with bootstrap.php, then guards
                                       itself (require_login()/require_role()).

bootstrap.php                         Session, error handler, and auth helpers shared
                                       by every page above.
auth/auth.php                         POST only login/logout endpoint.
auth/guard.php                        require_login(), require_role(), current_user().

config.php.example                    Copy to config.php and fill in real values,
                                       see "Configuration" below.
settings.php                          Every other constant, overridable by environment,
                                       plus load_app_config() and using_https().
system_auth.php                       PAM login and OS group role lookup.
errors.php, view.php, csrf.php,       Small single purpose libraries, each used by
roles.php, sql_classifier.php,        more than one page.
audit.php, ratelimit.php,
db_browser.php, grid.php,
sql_console.php, export.php

views/                                Plain PHP templates, rendered by view.php.
assets/                               Static files (stylesheet).

schema.sql                            This app's own tables. Plain SQL, no migration
                                       runner, see "Database setup" below.
```

## Requirements

1. PHP 8.1+ with the `pdo_mysql` and PECL `pam` extensions
2. The `posix_getpwnam`/`posix_getgrall` functions (`php-process` on RHEL family systems)
3. A MariaDB server (the app connects to it; it does not bundle one)
4. The `dbwebui-admin` and `dbwebui-editor` OS groups, created once and populated with the accounts that should have those roles

Nothing else. No Composer, no build step, no Node.

## Configuration

Copy `config.php.example` to `config.php` in the same directory and fill in real values there:

```bash
cp config.php.example config.php
vi config.php
```

`config.php` is gitignored, so it is never committed and a later `git pull` never touches it. Everything else on the deployed files is picked up automatically; this is the only file you need to edit per server.

If you would rather keep credentials outside the web servable tree entirely, point `settings.php`'s `CONFIG_PATH` at a different location instead, either by editing the default there or by setting the `DBADMIN_CONFIG` environment variable, which takes priority.

`connect()` (in `config.php`) deliberately opens with no default database. The SQL console lets an editor or viewer run SQL of their own choosing over that same connection, so an unqualified statement must never be able to land on this app's own tables by accident. Every query this app makes against its own tables names the schema explicitly, via `app_table()`.

## Database setup

Apply the schema file directly with the `mariadb` client, no database argument needed since the file creates and selects its own:

```bash
mariadb < schema.sql
```

`schema.sql` creates its own database (`dbwebui_app` by default; if you changed `DB_APP_SCHEMA` in `settings.php`, edit the `CREATE DATABASE`/`USE` lines at the top of `schema.sql` to match) and then the app's own tables, all as `CREATE ... IF NOT EXISTS`, safe to re run. There is no migration tracking table and no runner; schema changes are read and re applied by hand, the same as any other plain `.sql` file.

## Setting up logins

There is no signup page and no user table. Anyone with a valid account on the server can log in; what they can do depends on group membership:

```bash
sudo groupadd dbwebui-admin
sudo groupadd dbwebui-editor
sudo usermod -aG dbwebui-admin your_username
```

Add or remove access at any time with `usermod -aG` or `gpasswd -d`, the same way you would manage access to anything else on the box.

## Running the app locally

```bash
php -S 127.0.0.1:8080
```

Log in at `http://127.0.0.1:8080/index.php` with your local machine's own username and password.

## Security notes

1. Roles are enforced in application code against a single shared MariaDB service account; there is no per role database level access control.
2. The SQL console's guard against non admins reaching the app's own tables is a heuristic (pattern based), not a hard guarantee. For stronger isolation, use a separate, more restricted MariaDB account for the console and grid than the one used for the app's own audit tables.
3. "Export as PDF" is a print friendly page meant for the browser's own print to PDF option, not server generated PDF, since a real PDF library would add a dependency this project deliberately avoids.
4. Report security concerns before opening a public issue if this repository is ever made public.
