@echo off
REM ---------------------------------------------------------------------------
REM run.cmd — Windows entry point for the Presence Platform run suite.
REM run.cmd — punto de entrada de Windows para la suite de Presence Platform.
REM
REM Thin delegator (ADR-017): finds Git Bash (Git for Windows) and forwards
REM every argument to the SAME ./run dispatcher used on Linux/macOS. Command
REM routing stays single-source: the bash `run` script + scripts/_lib/common.sh.
REM
REM Usage:  run.cmd ^<command^> [args]     e.g.  run.cmd setup   then   run.cmd serve
REM         In Git Bash / En Git Bash:  ./run setup   ^&^&   ./run serve
REM
REM Override: set B2B_BASH=C:\path\to\bash.exe to force a specific Git Bash.
REM ---------------------------------------------------------------------------

setlocal
set "ROOT=%~dp0"

rem --- 1. explicit override ---------------------------------------------------
if defined B2B_BASH (
    if exist "%B2B_BASH%" (
        "%B2B_BASH%" "%ROOT%run" %*
        exit /b %ERRORLEVEL%
    )
)

rem --- 2. well-known Git for Windows locations (checked in order) -------------
set "BASH="
if exist "%ProgramFiles%\Git\bin\bash.exe" set "BASH=%ProgramFiles%\Git\bin\bash.exe"
if not defined BASH if exist "%ProgramFiles(x86)%\Git\bin\bash.exe" set "BASH=%ProgramFiles(x86)%\Git\bin\bash.exe"
if not defined BASH if exist "%LOCALAPPDATA%\Programs\Git\bin\bash.exe" set "BASH=%LOCALAPPDATA%\Programs\Git\bin\bash.exe"
if not defined BASH if exist "%USERPROFILE%\scoop\apps\git\current\bin\bash.exe" set "BASH=%USERPROFILE%\scoop\apps\git\current\bin\bash.exe"

rem --- 3. bash.exe anywhere on PATH --------------------------------------------
if not defined BASH (
    for /f "delims=" %%I in ('where bash.exe 2^>nul') do (
        if not defined BASH set "BASH=%%I"
    )
)

if defined BASH (
    "%BASH%" "%ROOT%run" %*
    exit /b %ERRORLEVEL%
)

echo fatal: Git Bash not found - install Git for Windows first / no se encontro Git Bash - instala Git for Windows:
echo   https://git-scm.com/download/win
echo   then open a NEW terminal and retry / luego abre una terminal NUEVA y reintenta
echo.
echo   (The run suite is a bash suite; Git for Windows provides bash on Windows.)
echo   (La suite ./run es bash; Git for Windows provee bash en Windows.)
exit /b 1
