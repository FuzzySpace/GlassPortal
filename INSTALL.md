# Glasshouse Provisioning Portal — Installation Guide

## Table of Contents

1. [Overview](#1-overview)
2. [Requirements](#2-requirements)
3. [Live Server Installation (Linux)](#3-live-server-installation-linuxapache--nginx)
4. [Local Windows — XAMPP / WAMP](#4-local-windows--xampp--wamp)
5. [Windows Standalone Installer (.exe)](#5-windows-standalone-installer-exe)
6. [Database Setup](#6-database-setup)
7. [Creating the First Admin User](#7-creating-the-first-admin-user)
8. [File & Directory Structure](#8-file--directory-structure)
9. [User Roles](#9-user-roles)
10. [Securing the Installation](#10-securing-the-installation)
11. [Changing the Database Password](#11-changing-the-database-password)
12. [Updating the Portal](#12-updating-the-portal)
13. [Troubleshooting](#13-troubleshooting)

---

## 1. Overview

The **Glasshouse Provisioning Portal** is an internal NOC-level web application for managing:

- Data centres, racks, customers, and nodes
- Server provisioning workflows with CIS Benchmark Level 1 & 2 hardening checklists
- Ansible automation scripting with multi-target execution and real-time log streaming
- Audit logging, role-based access control, and security review dashboards

There are three deployment paths:

| Path | Best for |
|---|---|
| **Live Linux server** (Apache or Nginx + PHP-FPM) | Production / staging |
| **Local Windows** (XAMPP or WAMP) | Development / testing |
| **Windows standalone installer** (`.exe`) | Self-contained Windows deployments |

---

## 2. Requirements

### Live Linux Server

| Component | Minimum version |
|---|---|
| PHP | 8.1+ |
| PHP extensions | `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `sodium`, `fileinfo` |
| MySQL | 8.0+ |
| MariaDB (alternative) | 10.6+ |
| Apache | 2.4+ with `mod_rewrite` enabled |
| Nginx (alternative) | 1.18+ |
| RAM | 512 MB minimum |
| Disk | 1 GB minimum |

### Local Windows (XAMPP / WAMP)

| Component | Minimum version |
|---|---|
| XAMPP | 8.3+ |
| WampServer (alternative) | 3.3+ |
| Windows | 10 or 11, 64-bit |

### Windows Standalone Installer

| Component | Requirement |
|---|---|
| Windows | 10 or 11, 64-bit |
| Administrator rights | Required |
| Disk space | 500 MB |
| Port | 8080 (must be free) |

---

## 3. Live Server Installation (Linux/Apache & Nginx)

### Step 1 — Get the code

```bash
# Clone the repository
git clone https://github.com/GlassMineCraft/Glasshouse-Provisioning-Portal-Development.git /var/www/glasshouse

# Or download and extract a release archive
unzip GlasshousePortal-x.x.x.zip -d /var/www/glasshouse
```

### Step 2 — Set file permissions

```bash
cd /var/www/glasshouse

# Directories need execute; files need read
find "provisioning portal" -type d -exec chmod 755 {} \;
find "provisioning portal" -type f -exec chmod 644 {} \;

# Restrict sensitive directories from direct web access
chmod 750 "provisioning portal/auth"
chmod 750 "provisioning portal/database"
chmod 750 "provisioning portal/worker"

# Web server user must own the files
chown -R www-data:www-data "provisioning portal"
```

### Step 3 — Import the database schema

```bash
mysql -u root -p < "provisioning portal/database/schema.sql"
```

This single command:
- Creates the `provisioning_portal` database
- Creates the `portal_user` MySQL account
- Creates all tables
- Seeds the 35-step CIS hardening checklist

### Step 4 — Edit database credentials

```bash
nano "provisioning portal/database/config.php"
```

Change the password to match what you set (or what `schema.sql` created):

```php
'username' => 'portal_user',
'password' => 'YourNewStrongPassword',
```

### Step 5 — Apache virtual host

Create `/etc/apache2/sites-available/glasshouse.conf`:

```apache
<VirtualHost *:80>
    ServerName portal.yourdomain.com
    DocumentRoot /var/www/glasshouse/provisioning portal

    <Directory "/var/www/glasshouse/provisioning portal">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Block direct access to sensitive directories
    <DirectoryMatch "/(auth|database|worker)/">
        Require all denied
    </DirectoryMatch>

    ErrorLog  ${APACHE_LOG_DIR}/glasshouse_error.log
    CustomLog ${APACHE_LOG_DIR}/glasshouse_access.log combined
</VirtualHost>
```

```bash
a2ensite glasshouse
a2enmod rewrite
systemctl reload apache2
```

### Step 5 (alternative) — Nginx server block

Create `/etc/nginx/sites-available/glasshouse`:

```nginx
server {
    listen 80;
    server_name portal.yourdomain.com;
    root "/var/www/glasshouse/provisioning portal";
    index login.php;

    # Block direct access to sensitive directories
    location ~ ^/(auth|database|worker)/ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /login.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }
}
```

```bash
ln -s /etc/nginx/sites-available/glasshouse /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

### Step 6 — Create the first admin user

See [Section 7](#7-creating-the-first-admin-user).

### Step 7 — Test

Browse to `http://portal.yourdomain.com/login.php` and sign in.

---

## 4. Local Windows — XAMPP / WAMP

### Step 1 — Install XAMPP

Download from [apachefriends.org](https://www.apachefriends.org/) (`xampp-windows-x64-8.3.x-installer.exe`) and install with default settings.

### Step 2 — Start services

1. Open the **XAMPP Control Panel**
2. Click **Start** next to **Apache**
3. Click **Start** next to **MySQL**

Both status indicators should turn green.

### Step 3 — Copy the project

Copy the project folder into the XAMPP web root:

```
C:\xampp\htdocs\glasshouse\
```

Your structure should look like:

```
C:\xampp\htdocs\glasshouse\
└── provisioning portal\
    ├── login.php
    ├── dashboard.php
    ├── database\
    │   ├── schema.sql
    │   └── config.php
    └── ...
```

### Step 4 — Import the database schema

1. Open **phpMyAdmin**: [http://localhost/phpmyadmin](http://localhost/phpmyadmin)
2. Click **Import** in the top navigation bar
3. Click **Choose File** and select:
   `C:\xampp\htdocs\glasshouse\provisioning portal\database\schema.sql`
4. Leave all settings at default
5. Click **Go**

You should see a success message with the tables listed.

### Step 5 — Update database credentials for XAMPP

Open `C:\xampp\htdocs\glasshouse\provisioning portal\database\config.php` in a text editor and change:

```php
'username' => 'root',
'password' => '',
```

XAMPP's MySQL uses `root` with no password by default.

### Step 6 — Browse to the portal

[http://localhost/glasshouse/provisioning portal/login.php](http://localhost/glasshouse/provisioning%20portal/login.php)

### Step 7 — Create the first admin user

See [Section 7](#7-creating-the-first-admin-user).

---

## 5. Windows Standalone Installer (.exe)

The standalone installer bundles PHP, MariaDB, and Apache into a single self-contained package — no separate installation of XAMPP or WAMP is needed.

For build instructions, see: `windows-installer/README-INSTALLER.txt`

### End-user installation

1. Right-click `GlasshousePortal-Setup-x.x.x.exe` and select **Run as administrator**
2. Follow the setup wizard:
   - Choose installation directory (default: `C:\Program Files\GlasshousePortal`)
   - Enter admin email address
   - Set admin password
   - Set HTTP port (default: `8080`)
3. Click **Install** — the wizard will:
   - Install PHP, MariaDB, and Apache
   - Import the database schema automatically
   - Create your admin user
   - Register Windows services for auto-start
4. When complete, click **Open Portal** or browse to [http://localhost:8080](http://localhost:8080)

The portal will start automatically on Windows startup via registered services (`GlasshousePortalDB` and `GlasshousePortalHTTP`).

---

## 6. Database Setup

### What `schema.sql` does

The schema file is fully self-contained. Importing it once:

- Creates the `provisioning_portal` database (safe to run again — uses `IF NOT EXISTS`)
- Creates the `portal_user` MySQL account with least-privilege grants
- Creates all tables with correct indexes and foreign keys
- Seeds the 35-step CIS Benchmark hardening checklist into `provisioning_tasks`

All statements use `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE`, so re-importing is non-destructive.

### Import via phpMyAdmin (GUI)

1. Open [http://localhost/phpmyadmin](http://localhost/phpmyadmin)
2. Click **Import** in the top menu
3. Choose file: `provisioning portal/database/schema.sql`
4. Click **Go**

### Import via MySQL CLI

```bash
# Linux / macOS
mysql -u root -p < "provisioning portal/database/schema.sql"

# Windows (XAMPP)
C:\xampp\mysql\bin\mysql.exe -u root < "C:\xampp\htdocs\glasshouse\provisioning portal\database\schema.sql"
```

### Changing the default database password

After import, `portal_user` has the default password `PortalStrongPass!ChangeMe`.

**Step 1 — Change the password in MySQL:**

```sql
ALTER USER 'portal_user'@'localhost' IDENTIFIED BY 'YourNewStrongPassword';
FLUSH PRIVILEGES;
```

**Step 2 — Update `config.php` to match:**

```php
'password' => 'YourNewStrongPassword',
```

---

## 7. Creating the First Admin User

### Method 1 — CLI (recommended for live servers)

SSH into the server and run:

```bash
cd /var/www/glasshouse
php "provisioning portal/init-db.php"
```

You will be prompted for:
- Admin email address
- Admin password (must be at least 8 characters)

The script creates the user with the `owner` role.

### Method 2 — SQL via phpMyAdmin

Generate a bcrypt hash for your password first:

```php
// Run this once in any PHP context to get your hash:
echo password_hash('YourPassword', PASSWORD_BCRYPT);
```

Then run in phpMyAdmin **SQL** tab:

```sql
INSERT INTO provisioning_portal.users (email, password_hash, role, is_active)
VALUES ('admin@yourdomain.com', '$2y$12$YOUR_BCRYPT_HASH_HERE', 'owner', 1);
```

### Method 3 — Portal Settings page

If a user already exists and is logged in:

1. Navigate to **Settings** → **User Management**
2. Click **Add User**
3. Fill in email, password, and assign the `owner` or `admin` role

---

## 8. File & Directory Structure

```
provisioning portal/
├── database/
│   ├── schema.sql          ← Import this first (creates DB, tables, seeds data)
│   ├── config.php          ← Edit DB host, username, password here
│   └── connection.php      ← PDO initialisation (do not edit)
│
├── auth/                   ← Authentication logic — do not expose via URL
│   ├── bootstrap.php       ← Session config, CSRF helpers
│   ├── guard.php           ← Route protection (require auth)
│   └── login_handler.php   ← Processes login POST
│
├── worker/                 ← Background automation daemon
│   ├── automation_worker.php   ← Long-running worker process
│   └── worker_config.php       ← Worker paths and tuning
│
├── assets/
│   ├── css/styles.css      ← Full portal stylesheet
│   ├── js/                 ← Frontend scripts
│   └── images/
│       └── glasshouse-logo.svg ← Portal logo (white SVG, orange glow via CSS)
│
├── components/             ← Shared UI partials
│   ├── header.php          ← Top navigation bar
│   ├── sidebar.php         ← Left navigation
│   └── footer.php          ← Page footer
│
├── login.php               ← Sign-in page
├── dashboard.php           ← Main dashboard
├── datacenters.php         ← Data centre management
├── hardware.php            ← Rack / node inventory
├── provision.php           ← Server provisioning workflows
├── automations.php         ← Ansible automation management
├── audit.php               ← Audit log viewer
├── users.php               ← User management
├── settings.php            ← Portal settings
├── search.php              ← Global search
├── logout.php              ← Sign-out handler
└── init-db.php             ← CLI first-run setup script
```

---

## 9. User Roles

| Role | Description | Capabilities |
|---|---|---|
| `owner` | Portal owner | Full access including user management and settings |
| `admin` | Administrator | All operations except changing owner settings |
| `operator` | NOC operator | Can run provisioning, view hardware, run automations |
| `security` | Security reviewer | Read-only access to audit logs and security reports |

Roles are assigned when creating a user and can be changed in **Settings → User Management** by an `owner` or `admin`.

---

## 10. Securing the Installation

### Block direct URL access to sensitive directories

**Apache** (add to vhost or `.htaccess`):

```apache
<DirectoryMatch "/(auth|database|worker)/">
    Require all denied
</DirectoryMatch>
```

**Nginx** (add to server block):

```nginx
location ~ ^/(auth|database|worker)/ {
    deny all;
    return 403;
}
```

### Enable HTTPS

Obtain a certificate (Let's Encrypt is free):

```bash
apt install certbot python3-certbot-apache
certbot --apache -d portal.yourdomain.com
```

Once HTTPS is live, enable secure cookies in `provisioning portal/auth/bootstrap.php` by uncommenting:

```php
// 'cookie_secure'   => true,
```

### Change the default database password

See [Section 11](#11-changing-the-database-password).

### PHP production settings

In `/etc/php/8.1/apache2/php.ini` (adjust path for your PHP version):

```ini
display_errors = Off
log_errors     = On
error_log      = /var/log/php_errors.log
```

### Checklist

- [ ] Schema imported and default DB password changed
- [ ] `auth/`, `database/`, `worker/` directories blocked via web server config
- [ ] HTTPS enabled and `cookie_secure` uncommented
- [ ] `display_errors = Off` in `php.ini`
- [ ] File ownership set to `www-data` (Linux)
- [ ] First admin user created with a strong password

---

## 11. Changing the Database Password

### Change via MySQL CLI

```bash
mysql -u root -p -e "ALTER USER 'portal_user'@'localhost' IDENTIFIED BY 'NewStrongPassword'; FLUSH PRIVILEGES;"
```

### Change via phpMyAdmin

1. Open phpMyAdmin → **User accounts**
2. Click **Edit privileges** next to `portal_user`
3. Click **Change password**
4. Enter and confirm the new password
5. Click **Go**

### Update `config.php` to match

```php
// provisioning portal/database/config.php
'password' => 'NewStrongPassword',
```

The portal will use the new password on next page load.

---

## 12. Updating the Portal

```bash
cd /var/www/glasshouse

# Pull latest code
git pull origin main

# Re-import schema (safe — all statements use IF NOT EXISTS / INSERT IGNORE)
mysql -u root -p < "provisioning portal/database/schema.sql"

# Reload web server
systemctl reload apache2   # or: systemctl reload nginx
```

No data is lost on re-import. New tables and seed rows are added; existing data is untouched.

---

## 13. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `Table 'provisioning_portal.X' doesn't exist` | Schema not imported | Import `schema.sql` (see [Section 6](#6-database-setup)) |
| `Access denied for user 'portal_user'` | User not created | Import `schema.sql` as root (it creates the user) |
| `Connection refused` / MySQL not running | Database server stopped | Start MySQL: `systemctl start mysql` or XAMPP Control Panel |
| White/blank page | PHP fatal error hidden | Check `/var/log/apache2/glasshouse_error.log` or enable `display_errors = On` temporarily |
| `403 Forbidden` on `login.php` | File permissions too restrictive | `chmod 644 "provisioning portal/"*.php` |
| `CSRF token mismatch` | Session not saving | Check `session.save_path` is writable: `chmod 777 /var/lib/php/sessions` |
| `500 Internal Server Error` | PHP extension missing | Verify: `php -m \| grep -E 'pdo_mysql\|mbstring\|openssl\|sodium'` |
| Logo not showing | Wrong web root path | Ensure `DocumentRoot` points to `provisioning portal/` (not the parent) |
| Automations not running | Worker daemon stopped | Run: `php "provisioning portal/worker/automation_worker.php" &` |
| Can't log in after password change | Config not updated | Update `database/config.php` password to match MySQL |
| phpMyAdmin import fails on large schema | `max_allowed_packet` too low | In MySQL: `SET GLOBAL max_allowed_packet=64*1024*1024;` |

### Checking PHP extensions

```bash
php -m | grep -E 'pdo|pdo_mysql|mbstring|openssl|sodium|fileinfo'
```

All six must appear. Install any missing ones (Debian/Ubuntu example):

```bash
apt install php8.1-mysql php8.1-mbstring php8.1-sodium
systemctl restart apache2
```

### Enabling debug mode temporarily

In `provisioning portal/database/connection.php`, the styled error page shows the raw PDO error message. For deeper PHP debugging, temporarily add to the top of `login.php`:

```php
ini_set('display_errors', '1');
error_reporting(E_ALL);
```

Remove before going back to production.

---

*Glasshouse Provisioning Portal — Internal NOC Platform*
