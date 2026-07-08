@echo off
REM =============================================================================
REM build.bat — Split Payment Gateway build & package script (Windows)
REM =============================================================================
REM Requirements:
REM   - PHP + Composer in PATH
REM   - Node.js + npm in PATH
REM   - 7-Zip installed at the default location (or adjust 7ZIP_PATH below)
REM =============================================================================

setlocal EnableDelayedExpansion

set PLUGIN_SLUG=split-payment-plugin
set ZIP_NAME=%PLUGIN_SLUG%-READY.zip
set BUILD_DIR=%~dp0build\%PLUGIN_SLUG%
set 7ZIP_PATH=C:\Program Files\7-Zip\7z.exe

REM ── clean ─────────────────────────────────────────────────────────────────
echo Cleaning previous build artefacts...
if exist "%~dp0%ZIP_NAME%"        del /f /q "%~dp0%ZIP_NAME%"
if exist "%~dp0%ZIP_NAME%.sha256" del /f /q "%~dp0%ZIP_NAME%.sha256"
if exist "%~dp0build"             rmdir /s /q "%~dp0build"

if "%1"=="--clean" (
    echo Clean complete.
    goto :EOF
)

REM ── verify tools ──────────────────────────────────────────────────────────
where composer >nul 2>&1
if errorlevel 1 ( echo ERROR: composer not found. Install from https://getcomposer.org & exit /b 1 )

where node >nul 2>&1
if errorlevel 1 ( echo ERROR: node not found. Install from https://nodejs.org & exit /b 1 )

where npm >nul 2>&1
if errorlevel 1 ( echo ERROR: npm not found. Install from https://nodejs.org & exit /b 1 )

if not exist "%7ZIP_PATH%" (
    echo ERROR: 7-Zip not found at "%7ZIP_PATH%". Install from https://www.7-zip.org
    exit /b 1
)

REM ── PHP dependencies ──────────────────────────────────────────────────────
echo Installing PHP dependencies (composer)...
cd /d "%~dp0"
call composer install --no-dev --optimize-autoloader --no-progress
if errorlevel 1 ( echo ERROR: composer install failed & exit /b 1 )

REM ── Node.js dependencies & build ──────────────────────────────────────────
echo Installing Node.js dependencies (npm)...
call npm install --silent
if errorlevel 1 ( echo ERROR: npm install failed & exit /b 1 )

echo Compiling assets (webpack)...
call npm run build
if errorlevel 1 ( echo ERROR: npm run build failed & exit /b 1 )

REM ── stage files ───────────────────────────────────────────────────────────
echo Staging files for packaging...
mkdir "%BUILD_DIR%"

for %%D in (includes admin assets vendor) do (
    if exist "%~dp0%%D" xcopy "%~dp0%%D" "%BUILD_DIR%\%%D" /E /I /Y /Q
)

for %%F in (split-payment-plugin.php uninstall.php) do (
    if exist "%~dp0%%F" copy /Y "%~dp0%%F" "%BUILD_DIR%\%%F" >nul
)

REM ── remove vendor .git if present ────────────────────────────────────────
if exist "%BUILD_DIR%\vendor\.git" rmdir /s /q "%BUILD_DIR%\vendor\.git"

REM ── create ZIP ───────────────────────────────────────────────────────────
echo Creating distributable ZIP...
cd /d "%~dp0build"
"%7ZIP_PATH%" a -r "..\%ZIP_NAME%" "%PLUGIN_SLUG%\" >nul
if errorlevel 1 ( echo ERROR: ZIP creation failed & exit /b 1 )

REM ── checksum ─────────────────────────────────────────────────────────────
echo Generating checksum...
cd /d "%~dp0"
for /f "skip=1 tokens=*" %%H in ('certutil -hashfile "%ZIP_NAME%" SHA256') do (
    if not defined HASH set HASH=%%H
)
REM Normalise to lowercase and write sha256sum-compatible format (hash *file)
echo %HASH% *%ZIP_NAME%> "%ZIP_NAME%.sha256"

REM ── cleanup ───────────────────────────────────────────────────────────────
rmdir /s /q "%~dp0build"

REM ── summary ───────────────────────────────────────────────────────────────
echo.
echo Build complete!
echo   File  : %ZIP_NAME%
echo   SHA256: 
type "%ZIP_NAME%.sha256"
echo.
echo Upload %ZIP_NAME% to WordPress Admin -^> Plugins -^> Upload Plugin
pause
