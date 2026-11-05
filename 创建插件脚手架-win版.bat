@echo off
chcp 65001 >nul
REM ============================================================
REM  Atrman plugin frontend scaffold launcher (Windows)
REM  Encoding-safe ASCII version (works under zh-CN OEM 936)
REM
REM  Double-click : asks for a plugin name, then assembles its
REM                  frontend workspace via:
REM                  am_frontends/create-plugin.mjs
REM
REM  CLI arg:
REM    this-file.bat am_demo      skip the prompt
REM
REM  The plugin name must start with am_ and the backend skeleton
REM  must already have been created in the admin panel first.
REM ============================================================

setlocal
cd /d "%~dp0"

set "NAME=%~1"
if "%NAME%"=="" set /p "NAME=Plugin name (e.g. am_demo, must start with am_): "

if "%NAME%"=="" (
  echo [CANCELLED] No plugin name entered, aborted.
  endlocal
  pause
  exit /b 1
)

node "%~dp0am_frontends\create-plugin.mjs" %NAME%

echo.
echo Next step - open the frontend workspace to continue:
echo   cd am_frontends\%NAME%  ^&^&  pnpm install  ^&^&  pnpm dev
endlocal
pause
