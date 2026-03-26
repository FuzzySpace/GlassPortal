@echo off
:: Wait for MariaDB to become ready (used during installation)
set INSTALL_DIR=%~dp0..
set /a TRIES=0
:RETRY
timeout /t 2 /nobreak >nul
"%INSTALL_DIR%\mariadb\bin\mysqladmin.exe" --defaults-file="%INSTALL_DIR%\config\my.ini" -u root ping >nul 2>&1
if %ERRORLEVEL% EQU 0 goto READY
set /a TRIES+=1
if %TRIES% LSS 15 goto RETRY
echo WARNING: Database did not respond after 30 seconds.
exit /b 1
:READY
exit /b 0
