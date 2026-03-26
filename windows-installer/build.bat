@echo off
setlocal

:: ============================================================
::  Glasshouse NOC Portal — Windows Installer Build Script
::  Requires: Inno Setup 6+ installed
::            vendor\php\, vendor\mariadb\, vendor\apache\ present
:: ============================================================

set ISCC="C:\Program Files (x86)\Inno Setup 6\ISCC.exe"
if not exist %ISCC% set ISCC="C:\Program Files\Inno Setup 6\ISCC.exe"

if not exist %ISCC% (
    echo ERROR: Inno Setup 6 not found.
    echo Download from: https://jrsoftware.org/isinfo.php
    pause
    exit /b 1
)

echo Checking vendor directories...

if not exist "vendor\php\php.exe" (
    echo ERROR: PHP not found at vendor\php\php.exe
    echo Download PHP 8.3 TS x64 zip from: https://windows.php.net/download/
    echo Extract it into: windows-installer\vendor\php\
    pause
    exit /b 1
)

if not exist "vendor\mariadb\bin\mysqld.exe" (
    echo ERROR: MariaDB not found at vendor\mariadb\bin\mysqld.exe
    echo Download MariaDB 10.11 x64 ZIP from: https://mariadb.org/download/
    echo Extract it into: windows-installer\vendor\mariadb\
    pause
    exit /b 1
)

if not exist "vendor\apache\bin\httpd.exe" (
    echo ERROR: Apache not found at vendor\apache\bin\httpd.exe
    echo Download Apache 2.4 Win64 from: https://www.apachelounge.com/download/
    echo Extract it into: windows-installer\vendor\apache\
    pause
    exit /b 1
)

if not exist "dist" mkdir dist

echo Building installer...
%ISCC% setup.iss

if %ERRORLEVEL% EQU 0 (
    echo.
    echo BUILD SUCCESSFUL
    echo Installer is in: windows-installer\dist\
    dir /b dist\*.exe
) else (
    echo.
    echo BUILD FAILED — check errors above
)

pause
