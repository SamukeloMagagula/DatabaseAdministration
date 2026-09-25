# Setup

Target: a fresh RHEL-family server (RHEL/CentOS/Rocky/AlmaLinux) that already has MariaDB installed, with the app running on the same box behind Nginx and PHP-FPM.

## 1. Requirements

- PHP 8.1+ with the `pdo_mysql` extension.
- The PECL `pam` extension — login authenticates against the same accounts as SSH, via PAM, not a password this app stores itself.
- The `posix_getpwnam`/`posix_getgrall` functions (`php-process` on RHEL-family systems) — used to resolve a signed-in user's OS group membership.
- A MariaDB server reachable from this box.
- Nginx and PHP-FPM (or an equivalent front end that can run PHP behind TLS).

## 2. Install the web stack

```bash
sudo dnf install -y nginx php-fpm php-pdo php-mysqlnd php-process git
```

No Composer, no Node — this app has no dependency toolchain by design.

## 3. Install the PAM extension

```bash
sudo dnf install -y php-devel gcc pam-devel php-pear
sudo pecl install pam
echo "extension=pam.so" | sudo tee /etc/php.d/40-pam.ini
```

PAM needs a service file telling it how to check a password. Reusing the system's own login stack is enough:

```bash
sudo tee /etc/pam.d/dbwebui <<'PAM'
auth     include system-auth
account  include system-auth
PAM
```

Authenticating another user's password this way works from an unprivileged process because `pam_unix.so` shells out to the setuid `unix_chkpwd` helper — PHP-FPM does not need to run as root or read `/etc/shadow` directly.

## 4. Create the admin and editor groups

Role is resolved from OS group membership, not stored in the app:

```bash
sudo groupadd dbwebui-admin
sudo groupadd dbwebui-editor
sudo usermod -aG dbwebui-admin your_own_username
```

Anyone who can log into the box but is in neither group gets read-only (viewer) access. Add or remove people at any time with `usermod -aG` or `gpasswd -d`, the same way you'd manage access to anything else on the box.

## 5. Create the MariaDB service account

```sql
CREATE USER 'dbwebui_svc'@'localhost' IDENTIFIED BY 'CHANGE_ME';
GRANT ALL PRIVILEGES ON dbwebui_app.* TO 'dbwebui_svc'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX,
      REFERENCES ON `your_app_db`.* TO 'dbwebui_svc'@'localhost';
-- Repeat the GRANT line for every database this tool should manage.
-- Do NOT grant on *.* in production — scope it to the databases you
-- actually want browsable/editable through this tool.
FLUSH PRIVILEGES;
```

This account is unrelated to human login — it's only how the app itself talks to MariaDB. Not creating `dbwebui_app` here is deliberate: `GRANT ... ON dbwebui_app.*` works whether or not that database exists yet, and having granted it, `dbwebui_svc` is then able to create it itself via `schema.sql`'s own `CREATE DATABASE IF NOT EXISTS`.

## 6. Deploy the application code

```bash
sudo mkdir -p /var/www/dbwebui
sudo git clone <your-repo-url> /var/www/dbwebui
sudo useradd --system --no-create-home dbwebui
sudo chown -R dbwebui:dbwebui /var/www/dbwebui
```

Create the PHP session directory. The pool runs as `dbwebui`, which cannot write to RHEL's default `/var/lib/php/session` — without this step `session_start()` cannot persist anything and every login silently bounces back to the sign-in page.

```bash
sudo mkdir -p /var/lib/dbwebui/session
sudo chown dbwebui:dbwebui /var/lib/dbwebui/session
sudo chmod 700 /var/lib/dbwebui/session
```

SELinux is enforcing by default on a fresh RHEL-family box, and PHP-FPM runs in the `httpd_t` domain, which cannot write the `var_lib_t` type a new directory under `/var/lib` inherits. `restorecon` on its own only reapplies the type already recorded for the path, so record a writable type first and then relabel (`semanage` ships in `policycoreutils-python-utils`):

```bash
sudo semanage fcontext -a -t httpd_var_run_t '/var/lib/dbwebui(/.*)?'
sudo restorecon -R /var/lib/dbwebui
sudo restorecon -R /var/www/dbwebui
```

If you configure `DB_HOST` as a TCP host instead of the local socket, also run `sudo setsebool -P httpd_can_network_connect_db 1`.

## 7. Configure the app

```bash
sudo -u dbwebui cp /var/www/dbwebui/config.php.example /var/www/dbwebui/config.php
sudo -u dbwebui vi /var/www/dbwebui/config.php
# Set DB_HOST to 'localhost' (not '127.0.0.1') so PDO connects over the
# Unix socket, matching the 'dbwebui_svc'@'localhost' account from step 5.
# Also set DB_USER and DB_PASS.
sudo chmod 600 /var/www/dbwebui/config.php
```

`config.php` is gitignored, so this is the one file you edit directly on the server and it's never touched by a later `git pull`. Everything else on the deployed checkout is picked up automatically once this file has real values.

If you'd rather keep credentials outside the served tree entirely, set the `DBADMIN_CONFIG` environment variable in the PHP-FPM pool config instead (see the table in step 11) and place the real file there.

## 8. Apply the schema

```bash
sudo -u dbwebui mariadb < /var/www/dbwebui/schema.sql
```

`schema.sql` creates and selects `dbwebui_app` itself (that's what step 5's `GRANT` was for) and is safe to run more than once — every statement, including its own `CREATE DATABASE`, is `IF NOT EXISTS`. There's no migration-tracking table and no runner: the checked-in file is meant to be read and re-applied by hand when it changes, the same as any other plain `.sql` file.

## 9. Configure PHP-FPM and Nginx

Create `/etc/php-fpm.d/dbwebui.conf`:

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

; RHEL's default session directory (/var/lib/php/session) is root:apache
; 0770, which the dbwebui pool user cannot write to. Point the pool at its
; own directory instead (created in step 6).
php_admin_value[session.save_path] = /var/lib/dbwebui/session

; Never render PHP errors or exception traces to the browser; a PDO
; connection failure carries the DB password in its stack frame arguments.
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_flag[zend.exception_ignore_args] = on
```

Create `/etc/nginx/conf.d/dbwebui.conf`:

```nginx
server {
    listen 443 ssl;
    server_name db-admin.internal;

    ssl_certificate     /etc/pki/tls/certs/dbwebui.crt;
    ssl_certificate_key /etc/pki/tls/private/dbwebui.key;

    root /var/www/dbwebui;
    index index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/dbwebui.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        # Without this, PHP never learns the connection is TLS, so the
        # session cookie's Secure flag is never set — see using_https() in
        # settings.php.
        fastcgi_param HTTPS on;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) { deny all; }
}

server {
    listen 80;
    server_name db-admin.internal;
    return 301 https://$host$request_uri;
}
```

This app has no front controller — `index.php`, `table.php`, `sql.php` and the rest are each requested directly, and the document root is the app's own checkout.

## 10. TLS certificate

Default (no public domain yet) — a self-signed certificate:

```bash
sudo mkdir -p /etc/pki/tls/private
sudo openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
  -keyout /etc/pki/tls/private/dbwebui.key \
  -out /etc/pki/tls/certs/dbwebui.crt \
  -subj "/CN=db-admin.internal"
```

Browsers will warn once on first visit (expected for a self-signed cert on an internal tool). If a public domain is later pointed at this server, switch to Let's Encrypt instead:

```bash
sudo dnf install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-real-domain.example.com
```

## 11. Environment variables (all optional)

| Variable | Default | Meaning |
|---|---|---|
| `DBADMIN_CONFIG` | `config.php` next to `settings.php` | where the real `config.php` lives |
| `DBADMIN_APP_SCHEMA` | `dbwebui_app` | schema holding this app's own tables |
| `DBADMIN_LOGIN_MAX_ATTEMPTS` | `5` | consecutive failed sign-ins, per username or per IP, before a lockout |
| `DBADMIN_LOGIN_WINDOW_MINUTES` | `15` | how long a lockout lasts, and how far back "consecutive" looks |
| `DBADMIN_PAGE_SIZE` | `50` | data grid rows per page unless the visitor asks for a different amount |

## 12. Firewall

```bash
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --permanent --add-service=http   # only needed for the 80->443 redirect
sudo firewall-cmd --reload
```

MariaDB's port is not opened externally — the app connects over localhost, and that's the only access path this deployment needs.

## 13. Start services

```bash
sudo systemctl enable --now php-fpm nginx
sudo systemctl restart php-fpm nginx
```

## 14. Access model

- There's no signup page and no app-level user table. Anyone with a valid account on the server can log in via PAM, using the same username and password as SSH.
- What a signed-in account can do depends entirely on OS group membership, checked fresh on every request: `dbwebui-admin` gets full access including DDL and database copy, `dbwebui-editor` gets row inserts/edits/deletes plus `SELECT`/`INSERT`/`UPDATE`/`DELETE` in the SQL console, and everyone else gets read-only (viewer) access.
- Promoting or demoting someone is an OS-level change (`usermod -aG` / `gpasswd -d`), not something done inside the app.
- Every row change, SQL execution, login, export, and database copy is written to the audit log (`/audit_log.php`, visible to admins only).

## 15. Troubleshooting

- **Login always fails, even with correct credentials** — confirm the PAM extension is actually loaded (`php -m | grep pam`) and that `/etc/pam.d/dbwebui` exists and matches the service name `system_auth.php` opens (`dbwebui`).
- **Login succeeds but immediately bounces back to the sign-in page** — almost always the session directory from step 6: check `/var/lib/dbwebui/session` is owned by `dbwebui` and, on SELinux systems, correctly labeled.
- **A valid account can sign in but sees viewer-only access it shouldn't have** — confirm the account's group membership took effect (`groups your_username`); a user added to a group needs a new login session (or `newgrp`) for PHP's `posix_getgrall()` to see it, though this app calls that fresh on every request against the account's OS record, so a stale PHP-FPM worker isn't the cause here.
- **`curl -I https://your-server/config.php` returns 200 instead of executing as PHP** — the PHP-FPM `location ~ \.php$` block in step 9 isn't matching; config.php would still not leak credentials (it only declares constants and functions, never echoes anything), but this indicates the vhost isn't routing `.php` requests to PHP-FPM at all, which is a bigger problem than this one file.

## 16. Smoke test

Visit `https://<server-hostname>/index.php` and log in with your own server username and password (accept the self-signed cert warning if applicable). Confirm you can see the database list, browse a real table, check `/status.php`, run `SELECT 1` in the SQL console, and that the action shows up on `/audit_log.php` if your account is in `dbwebui-admin`.
