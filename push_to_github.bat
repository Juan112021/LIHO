@echo off
chcp 65001 >nul
echo ========================================================
echo   LIHO - Sincronizacion Inicial con GitHub
echo ========================================================
set "PATH=%LOCALAPPDATA%\Programs\Git\cmd;%LOCALAPPDATA%\Programs\Git Credential Manager;%PATH%"

echo Conectando con https://github.com/Juan112021/LIHO.git...
git.exe push -u origin main --tags

echo.
if %ERRORLEVEL% EQU 0 (
    echo [EXITO] Proyecto LIHO subido correctamente a GitHub.
) else (
    echo [AVISO] Revisa si se abrio la ventana del navegador para autorizar GitHub.
)
echo ========================================================
pause
