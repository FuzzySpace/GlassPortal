@echo off
:: Run as Administrator
net session >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo This script must be run as Administrator.
    echo Right-click and select "Run as administrator"
    pause
    exit /b 1
)

set INSTALL_DIR=%~dp0..
set PORT=8080
if exist "%INSTALL_DIR%\config\portal.cfg" (
    for /f "tokens=2 delims==" %%A in ('findstr /i "port" "%INSTALL_DIR%\config\portal.cfg"') do set PORT=%%A
)

echo Installing Glasshouse NOC Portal services...

:: ---- MariaDB Windows Service ----
echo [1/2] Registering MariaDB service (GlasshousePortalDB)...
"%INSTALL_DIR%\mariadb\bin\mysqld.exe" --install GlasshousePortalDB --defaults-file="%INSTALL_DIR%\config\my.ini"
sc config GlasshousePortalDB start= auto >nul
sc description GlasshousePortalDB "Glasshouse NOC Portal - Database (MariaDB)" >nul
net start GlasshousePortalDB
if %ERRORLEVEL% EQU 0 (echo [OK] MariaDB service started) else (echo [WARN] MariaDB service failed to start — check my.ini)

:: ---- Apache Windows Service ----
echo [2/2] Registering Apache service (GlasshousePortalHTTP)...
"%INSTALL_DIR%\apache\bin\httpd.exe" -k install -n GlasshousePortalHTTP
sc config GlasshousePortalHTTP start= auto >nul
sc description GlasshousePortalHTTP "Glasshouse NOC Portal - Web Server (Apache)" >nul
net start GlasshousePortalHTTP
if %ERRORLEVEL% EQU 0 (echo [OK] Apache service started) else (echo [WARN] Apache service failed to start — check httpd.conf)

echo.
echo Services installed. Portal available at http://localhost:%PORT%/
echo.
pause
