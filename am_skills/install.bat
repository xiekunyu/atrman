@echo off
rem ===================================================================
rem Atrman Agent Skills installer for Windows (double-click safe)
rem ASCII-only on purpose: non-ASCII bytes break cmd under CP936.
rem
rem Usage:
rem   install.bat            Same as "project"
rem   install.bat project    Install into ..\.claude\skills (this repo)
rem   install.bat user       Install into %USERPROFILE%\.claude\skills
rem   install.bat ^<dir^>     Install into any agent skills directory
rem   install.bat list       List available skills
rem ===================================================================

setlocal
rem Skills sit directly in this directory: a subfolder with SKILL.md is a skill.
set "SRC=%~dp0"

if "%~1"=="" goto :project
if /i "%~1"=="project" goto :project
if /i "%~1"=="user" goto :user
if /i "%~1"=="list" goto :list
if /i "%~1"=="-h" goto :help
if /i "%~1"=="--help" goto :help
if /i "%~1"=="help" goto :help

set "DEST=%~1"
goto :install

:project
set "DEST=%~dp0..\.claude\skills"
goto :install

:user
set "DEST=%USERPROFILE%\.claude\skills"
goto :install

:list
echo Available skills in %SRC%:
for /d %%D in ("%SRC%*") do (
    if exist "%%~fD\SKILL.md" (
        echo   - %%~nD
    ) else (
        echo   (skip %%~nD: SKILL.md missing)
    )
)
goto :eof

:help
findstr /b "rem " "%~f0" | findstr /v "findstr"
goto :eof

:install
if not exist "%SRC%" (
    echo [ERROR] skills source not found: %SRC%
    exit /b 1
)
if not exist "%DEST%" mkdir "%DEST%"

echo Installing skills into: %DEST%
for /d %%D in ("%SRC%*") do (
    if exist "%%~fD\SKILL.md" (
        echo   + %%~nD
        robocopy "%%~fD" "%DEST%\%%~nD" /MIR /NFL /NDL /NJH /NJS /NP >nul
    ) else (
        echo   skip %%~nD: SKILL.md missing
    )
)
echo Done. Restart / reload your agent so it picks up the new skills.
exit /b 0
