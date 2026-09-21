# MariaDB Web Admin Tool — Phase 1 (MVP) Design

## Context

The user has a MariaDB instance on a Linux (RHEL-family) server and wants a
custom web UI to administer it — browsing/editing data, managing schema,
managing MariaDB users/privileges, and import/export/backups — for
themselves plus a few trusted admins.

This is a multi-subsystem project. Per the project decomposition, it is
split into phases, each with its own design → plan → build cycle:

- **Phase 1 (this document)**: App auth & roles, database/table browser,
  data grid CRUD, SQL console, audit log.
- **Phase 2** (future): Schema management — create/alter/drop tables,
  columns, indexes, foreign keys.
- **Phase 3** (future): MariaDB user & privilege management (CREATE
  USER/GRANT/REVOKE), import/export, backups.

Existing tools (phpMyAdmin, Adminer) were considered and explicitly
declined — the user wants a custom-built tool.


## Goals (Phase 1)

- Multiple trusted admins can log in to a web UI, with different
  permission levels, to view and edit data on the existing MariaDB
  instance and run ad-hoc SQL.
- Every SQL execution and data mutation is attributable to a user via an
  audit trail.
- Deployable on a fresh RHEL-family server that currently has only
  MariaDB installed.

## Non-goals (Phase 1)

- Schema editing (create/alter/drop tables/columns/indexes) — Phase 2.
- MariaDB user/privilege management via the UI — Phase 3.
- Import/export and backups — Phase 3.
- Public internet exposure hardening beyond basic TLS (this deployment
  targets access by a small trusted admin group, not the general
  public).

## Architecture

A single PHP application (plain PHP, no framework) running under
PHP-FPM behind Nginx, on the same server as MariaDB, connecting over
localhost/socket.

```
Admin's browser
      |  HTTPS
      v
   Nginx  ---->  PHP-FPM  ---->  plain PHP app  ---->  PDO (MariaDB driver)
                                  (public/ front                |
                                   controller, router,          v
                                   auth, RBAC)              MariaDB
                                                        (dbwebui_app schema
                                                         + user's real DBs)
```

- **App's own storage**: a dedicated MariaDB schema, `dbwebui_app`, holds
  the application's own tables:
  - `app_users` — id, username, password_hash, role, is_active,
    created_at.
  - `audit_log` — id, app_user_id, action_type, target_db, target_table,
    statement_or_diff, created_at.
- **DB service account**: one MariaDB user, e.g. `dbwebui_svc`, that the
  PHP app always connects as. Scoped to the databases it is meant to
  manage; excludes `mysql`, `information_schema`, `performance_schema`,
  `sys` from the browsable database list (the SQL console does not
  technically block querying them, since Admin is a trusted role, but
  the UI won't surface them by default). Because Admin-role users can
  run arbitrary SQL including DDL through the console, the service
  account needs broad DML/DDL rights on the managed databases (not
  `GRANT OPTION`, and not access to unrelated schemas on the same
  server).
- **App roles**, enforced in PHP application logic (not via MariaDB
  GRANTs — the app always connects as the single service account):
  - **Admin** — full access: browse/edit any table, full SQL console
    (including DDL), manage app_users, view audit log.
  - **Editor** — browse/edit data via the grid (INSERT/UPDATE/DELETE),
    SQL console restricted to SELECT/INSERT/UPDATE/DELETE (no DDL), no
    access to app_user management or audit log.
  - **Viewer** — read-only: browse data, SQL console restricted to
    SELECT only.
- **Request flow**: server-rendered HTML pages (plain PHP templates),
  with vanilla JS for the data grid (pagination/sort/inline edit) and
  the SQL console editor. No SPA framework, no build step required to
  run the app.

## Authentication & session security

- **Login**: username/password form; passwords hashed with PHP's
  `password_hash()` (bcrypt/argon2i default).
- **Sessions**: native PHP sessions; `session_regenerate_id()` on
  successful login; cookies set `secure`, `httponly`, `samesite=Strict`
  (the `secure` flag requires the HTTPS setup below to be in place).
- **CSRF protection**: a per-session CSRF token embedded in every
  state-changing form (login, grid edits, SQL console submission, user
  management) and validated server-side on every POST.
- **Brute-force mitigation**: login attempts are rate-limited; an
  account/IP is temporarily locked out for 15 minutes after 5
  consecutive failures. Lockout events are written to `audit_log`.
- **Bootstrapping**: a CLI script (run once, directly on the server)
  creates the first Admin `app_user` interactively, since there is no
  UI yet to create the first account. After that, Admins manage further
  accounts via the in-app "Manage users" screen (see below).

## Core features

### Manage app users (Admin only)
Screen to create/edit/disable `app_users` accounts and assign roles
(Admin/Editor/Viewer). Complements the CLI bootstrap script used for the
very first account.

### Database/table browser
Sidebar or list of databases visible to the service account (system
schemas excluded from the default view). Drilling into a database lists
its tables; drilling into a table shows a read-only structure view
(columns, types, keys, indexes). Actual schema editing is Phase 2.

### Data grid
Paginated view of table rows (default 50 rows/page, configurable),
column sorting,
simple per-column filtering. Row actions:
- **Edit** — inline form respecting column types/constraints.
- **Delete** — requires confirmation.
- **Insert** — form generated from column metadata.

All mutations use parameterized/prepared statements (never string-built
SQL) and are recorded in `audit_log` (who, when, table, and a
before/after diff or the executed statement).

### SQL console
A textarea for writing one SQL statement plus an execute action.
- SELECT results render as a table.
- Other statements show the affected-row count.
- The statement's type is checked against the caller's role *before*
  execution: Viewer → must be SELECT; Editor → SELECT/INSERT/
  UPDATE/DELETE; Admin → unrestricted (including DDL).
- Every executed statement is recorded in `audit_log` regardless of
  role, including statements that error out.

### Audit log / Activity page (Admin only)
Lists recent `audit_log` entries — user, timestamp, action type,
statement or row diff, target database/table — filterable by user and
date range.

## Security considerations

- All SQL touching user-supplied values (grid CRUD, filters, pagination
  bounds) uses PDO prepared statements — no manual string
  concatenation into SQL.
- The SQL console is inherently a "run arbitrary SQL" feature by design;
  its safety comes from auth + role gating + CSRF protection + audit
  logging, not from trying to sanitize SQL text.
- Config/secrets (DB credentials, session secret) live in a file outside
  the web root, not committed to version control.
- Nginx serves only the `public/` front-controller directory; PHP
  application/library code sits outside the web root and is not
  directly reachable over HTTP.
- PHP-FPM pool runs as a dedicated non-root system user.
- MariaDB's network port is not exposed beyond localhost; the app
  connects over the local socket/localhost only.

## Deployment

- **OS**: RHEL-family (RHEL/CentOS/Rocky/AlmaLinux), fresh box with only
  MariaDB currently installed.
- **Stack to install**: Nginx, PHP-FPM + `php-mysqlnd` (via `dnf`).
- **TLS**: defaults to a self-signed certificate, suitable for access by
  a small trusted-admin group over VPN/LAN; documented separately how to
  swap in Let's Encrypt/Certbot later if a public domain is pointed at
  the server.
- **App layout**: deployed under `/var/www/dbwebui`, with Nginx pointed
  only at `public/` (front-controller pattern).
- **Firewall**: `firewalld` opens only the port(s) admins need
  (443, optionally 80 for redirect); MariaDB's port stays closed to
  external access.

## Testing approach

- **Unit tests** (PHPUnit), no live DB required: role/permission checks
  (which statement types each role may execute), CSRF token validation,
  input validation helpers.
- **Integration tests** (PHPUnit against a real test MariaDB schema):
  login/logout/lockout flow, data grid CRUD round-trips, SQL console
  execution per role (including rejecting out-of-role statement types),
  audit log entries being written correctly for each action type.
- **Manual smoke test** on the actual server post-deployment: log in as
  each role, browse a real table, run a query, confirm audit entries
  appear — since full server deployment isn't verifiable from a local
  dev environment.

## Open items carried to later phases

- Schema management UI (Phase 2).
- MariaDB user/privilege management UI (Phase 3).
- Import/export and backups (Phase 3).
- Optional future hardening (not in this phase): safety rails like
  confirm-to-proceed prompts for destructive statements, WHERE-less
  UPDATE/DELETE warnings, affected-row-count previews (deferred per the
  "Approach 2" decision — audit log now, extra friction only if it
  proves necessary later).
