# Deploying Database Administration

Target: a fresh RHEL-family server (RHEL/CentOS/Rocky/AlmaLinux) that
already has MariaDB installed, with the app running on the same box.

## 1. Install the web stack

    sudo dnf install -y nginx php-fpm php-pdo php-mysqlnd php-process git

No Composer, no Node — this app has no dependency toolchain by design.
`php-process` provides the POSIX functions used to look up group membership.

## 2. Install the PAM extension

Login uses the same username and password as SSH into the box, checked via
PAM, so the PECL `pam` extension is required:

    sudo dnf install -y php-devel gcc pam-devel php-pear
    sudo pecl install pam
    echo "extension=pam.so" | sudo tee /etc/php.d/40-pam.ini

PAM itself needs a service file telling it how to check a password. Reusing
the system's own login stack is enough:

    sudo tee /etc/pam.d/dbwebui <<'PAM'
    auth     include system-auth
    account  include system-auth
    PAM

`system_auth.php` opens the PAM session under the name `dbwebui`, matching
this file. Authenticating another user's password this way works from an
unprivileged process because `pam_unix.so` shells out to the setuid
`unix_chkpwd` helper — PHP-FPM does not need to run as root or read
`/etc/shadow` directly.

## 3. Create the admin and editor groups

Role is resolved from OS group membership, not stored in the app:

    sudo groupadd dbwebui-admin
    sudo groupadd dbwebui-editor
    sudo usermod -aG dbwebui-admin your_own_username

Anyone who can log into the box but is in neither group gets read-only
(viewer) access. Add or remove people with `usermod -aG` / `gpasswd -d`.

## 4. Create the MariaDB service account

    sudo mysql -u root -p <<'SQL'
    CREATE USER 'dbwebui_svc'@'localhost' IDENTIFIED BY 'CHANGE_ME';
    GRANT ALL PRIVILEGES ON dbwebui_app.* TO 'dbwebui_svc'@'localhost';
    GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX,
          REFERENCES ON `your_app_db`.* TO 'dbwebui_svc'@'localhost';
    -- Repeat the GRANT line for every database this tool should manage.
    -- Do NOT grant on *.* in production — scope it to the databases you
    -- actually want browsable/editable through this tool.
    FLUSH PRIVILEGES;
    SQL

This account is unrelated to human login — it is only how the app itself
talks to MariaDB. Not creating `dbwebui_app` here is deliberate:
`GRANT ... ON dbwebui_app.*` works whether or not that database exists yet,
and having granted it, `dbwebui_svc` is then able to create it itself via
`schema.sql`'s own `CREATE DATABASE IF NOT EXISTS`.

The service account needs full rights on `dbwebui_app` to maintain the
app's own tables, and the app keeps that schema out of the UI: the table
browser rejects it like a nonexistent database, the shared PDO connection
has no default database (so an unqualified console statement cannot land on
it), and the SQL console blocks the common ways a non-admin could name it
explicitly.

## 5. Deploy the application code

    sudo mkdir -p /var/www/dbwebui
    sudo git clone <your-repo-url> /var/www/dbwebui
    sudo useradd --system --no-create-home dbwebui
    sudo chown -R dbwebui:dbwebui /var/www/dbwebui

Create the PHP session directory. The pool runs as `dbwebui`, which cannot
write to RHEL's default `/var/lib/php/session` — without this step
`session_start()` cannot persist anything and every login silently bounces
back to the sign-in page. The matching `session.save_path` is already set
in `deploy/php-fpm-pool.conf.example`.

    sudo mkdir -p /var/lib/dbwebui/session
    sudo chown dbwebui:dbwebui /var/lib/dbwebui/session
    sudo chmod 700 /var/lib/dbwebui/session

SELinux is enforcing by default on a fresh RHEL-family box, and PHP-FPM runs
in the `httpd_t` domain, which cannot write the `var_lib_t` type a new
directory under `/var/lib` inherits. `restorecon` on its own only reapplies
the type already recorded for the path, so record a writable type first and
then relabel (`semanage` ships in `policycoreutils-python-utils`):

    sudo semanage fcontext -a -t httpd_var_run_t '/var/lib/dbwebui(/.*)?'
    sudo restorecon -R /var/lib/dbwebui

Also run `sudo restorecon -R /var/www/dbwebui` if you copied the code in
rather than cloning it in place. If you configure `DB_HOST` as a TCP host
instead of the local socket, also run
`sudo setsebool -P httpd_can_network_connect_db 1`.

## 6. Configure the app

The checked-in `config.php` is a template with placeholder credentials —
never edit it in place. The real one lives outside the served tree, at the
path `CONFIG_PATH` (`settings.php`) points to by default:

    sudo mkdir -p /var/www/private
    sudo -u dbwebui cp config.php /var/www/private/config.php
    sudo -u dbwebui vi /var/www/private/config.php
    # Set DB_HOST to 'localhost' (not '127.0.0.1') so PDO connects over the
    # Unix socket, matching the 'dbwebui_svc'@'localhost' account created in
    # step 4. Also set DB_USER and DB_PASS.
    sudo chmod 600 /var/www/private/config.php

If `/var/www/private` is not where you want it, point `settings.php` at it
instead by setting the `DBADMIN_CONFIG` environment variable in the PHP-FPM
pool config (`env[DBADMIN_CONFIG] = /path/to/config.php`) rather than
editing `settings.php` itself.

## 7. Apply the schema

    sudo -u dbwebui mariadb < schema.sql

`schema.sql` creates and selects `dbwebui_app` itself (that's what step 4's
`GRANT` was for) and is safe to run more than once — every statement,
including its own `CREATE DATABASE`, is `IF NOT EXISTS`. There is no
migration-tracking table and no runner: the checked-in file is meant to be
read and re-applied by hand when it changes, the same way you would with any
other plain `.sql` file.

## 8. Configure PHP-FPM and Nginx

    sudo cp deploy/php-fpm-pool.conf.example /etc/php-fpm.d/dbwebui.conf
    sudo cp deploy/nginx.conf.example /etc/nginx/conf.d/dbwebui.conf
    # Edit both to match your paths/hostname if you deviated from the defaults.

This app has no front controller — `index.php`, `table.php`, `sql.php` and
the rest are each requested directly, and the document root is the app's
own checkout. `deploy/nginx.conf.example` explicitly denies `tests/`,
`docs/` and any `.sql`/dotfile — none of those are meant to be web-facing,
even though nothing stops PHP-FPM from executing a `.php` file anywhere
under the root if a request reaches it.

## 9. TLS certificate

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

## 10. Firewall

    sudo firewall-cmd --permanent --add-service=https
    sudo firewall-cmd --permanent --add-service=http   # only needed for the 80->443 redirect
    sudo firewall-cmd --reload

MariaDB's port is not opened externally — the app connects over
localhost, and that's the only access path this deployment needs.

## 11. Start services

    sudo systemctl enable --now php-fpm nginx
    sudo systemctl restart php-fpm nginx

## 12. Smoke test

Visit `https://<server-hostname>/index.php` and log in with your own server
username and password (accept the self-signed cert warning if applicable).
Confirm you can see the database list, browse a real table, check
`/status.php`, run `SELECT 1` in the SQL console, and that the action shows
up on `/audit_log.php` if your account is in `dbwebui-admin`.
