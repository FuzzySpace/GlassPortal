@echo off
set PORT=8080
set INSTALL_DIR=%~dp0..

:: Read port from config if available
if exist "%INSTALL_DIR%\config\portal.cfg" (
    for /f "tokens=2 delims==" %%A in ('findstr /i "port" "%INSTALL_DIR%\config\portal.cfg"') do set PORT=%%A
)

start "" "http://localhost:%PORT%/dashboard.php"
