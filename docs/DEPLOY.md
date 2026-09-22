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
