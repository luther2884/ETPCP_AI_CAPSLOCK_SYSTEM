@echo off
setlocal
REM Lightweight wrapper to run Composer.phar with PHP on Windows

set "PHP_EXE=C:\xampp\php\php.exe"

if exist "%PHP_EXE%" (
  "%PHP_EXE%" "%~dp0Composer.phar" %*
) else (
  REM Fallback to php in PATH if available
  php "%~dp0Composer.phar" %*
)

endlocal

