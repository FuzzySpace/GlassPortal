@echo off
echo Stopping Glasshouse NOC Portal...

:: Stop PHP server
taskkill /f /im php.exe /t >nul 2>&1
if %ERRORLEVEL% EQU 0 (echo [OK] PHP web server stopped) else (echo [ -] PHP server was not running)

:: Stop MariaDB gracefully (mysqladmin shutdown)
set INSTALL_DIR=%~dp0..
"%INSTALL_DIR%\mariadb\bin\mysqladmin.exe" --defaults-file="%INSTALL_DIR%\config\my.ini" -u root shutdown >nul 2>&1
if %ERRORLEVEL% EQU 0 (echo [OK] MariaDB stopped) else (echo [ -] MariaDB was not running)

echo.
echo Portal stopped.
pause
