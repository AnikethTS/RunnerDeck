@echo off
rem The RunnerDeck command line. The installer puts this folder on PATH.
rem   runnerdeck              start the dashboard
rem   runnerdeck run [--port N]
rem   runnerdeck <command>    list, start, stop, restart, add, delete, logs, doctor, version
setlocal
set "APP=%~dp0..\RunnerDeck.exe"
if "%~1"=="" goto dashboard
if /i "%~1"=="run" goto dashboard
"%APP%" cli %*
exit /b %errorlevel%

:dashboard
set "REST="
if /i "%~1"=="run" shift
:collect
if "%~1"=="" goto launch
if /i "%~1"=="--open" (
    shift
    goto collect
)
set "REST=%REST% %1"
shift
goto collect

:launch
start "" "%APP%"%REST%
exit /b 0
