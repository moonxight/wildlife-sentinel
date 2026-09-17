@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0demo\run.ps1"
if errorlevel 1 pause
