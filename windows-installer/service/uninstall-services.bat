@echo off
net session >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo This script must be run as Administrator.
    pause
    exit /b 1
)

set INSTALL_DIR=%~dp0..

echo Removing Glasshouse NOC Portal services...

echo [1/2] Stopping and removing Apache service...
net stop GlasshousePortalHTTP >nul 2>&1
"%INSTALL_DIR%\apache\bin\httpd.exe" -k uninstall -n GlasshousePortalHTTP >nul 2>&1
echo [OK] Apache service removed.

echo [2/2] Stopping and removing MariaDB service...
net stop GlasshousePortalDB >nul 2>&1
"%INSTALL_DIR%\mariadb\bin\mysqld.exe" --remove GlasshousePortalDB >nul 2>&1
echo [OK] MariaDB service removed.

echo.
echo Services removed successfully.
pause
