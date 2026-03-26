@echo off
setlocal

:: ============================================================
::  Glasshouse NOC Portal — Start Services
::  Use this for manual start or if Windows services are not
::  installed. For production, use install-services.bat instead.
:: ============================================================

set INSTALL_DIR=%~dp0..
set PHP=%INSTALL_DIR%\php\php.exe
set MARIADB=%INSTALL_DIR%\mariadb\bin\mysqld.exe
set MARIADB_CFG=%INSTALL_DIR%\config\my.ini
set HTDOCS=%INSTALL_DIR%\htdocs
set PORT=8080

:: Read port from config if available
if exist "%INSTALL_DIR%\config\portal.cfg" (
    for /f "tokens=2 delims==" %%A in ('findstr /i "port" "%INSTALL_DIR%\config\portal.cfg"') do set PORT=%%A
)

echo Starting Glasshouse NOC Portal...

:: Start MariaDB
echo [1/2] Starting MariaDB...
start /min "" "%MARIADB%" --defaults-file="%MARIADB_CFG%"

:: Wait a moment for MariaDB to be ready
timeout /t 3 /nobreak >nul

:: Start PHP built-in web server
echo [2/2] Starting PHP web server on port %PORT%...
start /min "Glasshouse Portal PHP" "%PHP%" -S 0.0.0.0:%PORT% -t "%HTDOCS%"

timeout /t 2 /nobreak >nul

echo.
echo Portal is starting at http://localhost:%PORT%/dashboard.php
echo.
echo To stop the portal, run stop-portal.bat
echo To open in browser, run open-portal.bat
echo.
pause
