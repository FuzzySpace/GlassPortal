============================================================
 Glasshouse NOC Provisioning Portal — Windows Installer
 Build & Deployment Guide
============================================================

REQUIREMENTS
------------
To BUILD the installer you need:
  - Windows 10/11 x64
  - Inno Setup 6.3+     https://jrsoftware.org/isinfo.php
  - The three vendor archives (see STEP 1 below)

To RUN the installer the target machine needs:
  - Windows 10/11 x64
  - Administrator privileges during installation
  - ~500 MB free disk space
  - No existing service on port 8080 (or choose another port)


STEP 1 — DOWNLOAD VENDOR BINARIES
-----------------------------------
Before building, download and extract the following into
the windows-installer\vendor\ directory:

  A) PHP 8.3 Thread-Safe (TS) x64 zip
     URL: https://windows.php.net/download/
     Extract to: windows-installer\vendor\php\
     Verify:     windows-installer\vendor\php\php.exe exists

  B) MariaDB 10.11 LTS x64 ZIP (no-installer package)
     URL: https://mariadb.org/download/
     Extract to: windows-installer\vendor\mariadb\
     Verify:     windows-installer\vendor\mariadb\bin\mysqld.exe exists

  C) Apache 2.4 Win64 VS17 zip
     URL: https://www.apachelounge.com/download/
     Extract to: windows-installer\vendor\apache\
     Verify:     windows-installer\vendor\apache\bin\httpd.exe exists

NOTE: vendor\ is in .gitignore — these binaries are not committed
to the repository. Each developer/build machine downloads them
once and builds locally.


STEP 2 — BUILD THE INSTALLER
------------------------------
  1. Double-click windows-installer\build.bat
     (or open setup.iss in Inno Setup IDE and press F9)

  2. The compiled installer will be output to:
     windows-installer\dist\GlasshousePortal-Setup-1.0.0.exe


STEP 3 — DISTRIBUTE AND INSTALL
---------------------------------
  1. Copy GlasshousePortal-Setup-1.0.0.exe to the target machine

  2. Right-click → "Run as administrator"

  3. Follow the wizard:
     - Choose install directory (default: C:\GlasshousePortal\)
     - Enter admin email and password for the portal account
     - Choose web server port (default: 8080)

  4. The installer will:
     - Extract PHP, MariaDB, Apache
     - Configure all services
     - Create the database and import the schema
     - Create your admin account
     - Register GlasshousePortalDB and GlasshousePortalHTTP
       as auto-start Windows services
     - Create Start Menu and optional Desktop shortcuts

  5. On completion, click "Open the portal in my browser now"
     or navigate to: http://localhost:8080/


MANUAL SERVICE MANAGEMENT
--------------------------
  Start/stop from the Start Menu:
    Glasshouse NOC Portal → Start Portal Services
    Glasshouse NOC Portal → Stop Portal Services

  Or use the scripts directly:
    C:\GlasshousePortal\launcher\start-portal.bat
    C:\GlasshousePortal\launcher\stop-portal.bat
    C:\GlasshousePortal\launcher\open-portal.bat

  Or via Windows Services panel (services.msc):
    GlasshousePortalDB   — MariaDB database
    GlasshousePortalHTTP — Apache web server


UNINSTALL
---------
  Control Panel → Programs → Uninstall a Program
  → Glasshouse NOC Portal → Uninstall

  This will:
    - Stop and remove both Windows services
    - Remove all installed files (data directory preserved by default)

  To also remove database data, delete C:\GlasshousePortal\data\
  after uninstalling.


DEVELOPMENT / MANUAL START (No Services)
-----------------------------------------
  If you just want to run the portal without installing services
  (e.g., on a development machine):

    1. Ensure PHP and MariaDB are running
    2. Double-click: C:\GlasshousePortal\launcher\start-portal.bat

  This starts PHP's built-in web server on port 8080.
  NOT recommended for production use.


UPDATING THE PORTAL
-------------------
  To deploy a new version of the portal code:

    1. Stop the Apache service (net stop GlasshousePortalHTTP)
    2. Copy new PHP files to C:\GlasshousePortal\htdocs\
    3. If schema changes: run php htdocs\init-db.php (non-destructive)
    4. Start Apache service (net start GlasshousePortalHTTP)

  Alternatively, run the new setup.exe — it will update files
  while preserving your database data directory.


TROUBLESHOOTING
---------------
  Logs are written to: C:\GlasshousePortal\logs\
    - apache-access.log
    - apache-error.log
    - mariadb-error.log
    - php-errors.log

  Port conflict:
    Run: netstat -ano | findstr :8080
    to find what is using port 8080. Change port in setup wizard
    or edit config\portal.cfg and restart services.

  Database connection failed:
    Verify MariaDB service is running in services.msc
    Check logs\mariadb-error.log for errors
    Re-run: php htdocs\init-db.php --force

============================================================
