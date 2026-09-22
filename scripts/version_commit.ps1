<#
.SYNOPSIS
    Script automatizado de versionado y publicación en GitHub para LIHO.
.DESCRIPTION
    Este script facilita registrar una versión, realizar el commit con mensaje semántico,
    crear la etiqueta (tag) de Git y enviar los cambios a GitHub automáticamente.
.EXAMPLE
    .\scripts\version_commit.ps1 -Version "1.1.0" -Message "feat(medicos): agregar nuevo filtro de especialidad"
#>
param (
    [Parameter(Mandatory=$false)]
    [string]$Version,

    [Parameter(Mandatory=$true)]
    [string]$Message
)

$gitExe = "$env:LOCALAPPDATA\Programs\Git\cmd\git.exe"
if (-not (Test-Path $gitExe)) {
    $gitExe = (Get-Command git -ErrorAction SilentlyContinue).Source
}

if (-not $gitExe) {
    Write-Error "No se encontró el ejecutable de Git."
    exit 1
}

Write-Host ">>> Preparando cambios en Git..." -ForegroundColor Cyan
& $gitExe add .

Write-Host ">>> Creando commit..." -ForegroundColor Cyan
& $gitExe commit -m $Message

if ($Version) {
    $tagName = "v$Version"
    Write-Host ">>> Creando etiqueta de versión $tagName..." -ForegroundColor Cyan
    & $gitExe tag -a $tagName -m "Versión $Version: $Message"
}

Write-Host ">>> Verificando repositorios remotos..." -ForegroundColor Cyan
$remotes = & $gitExe remote
if ($remotes -contains "origin") {
    Write-Host ">>> Sincronizando con GitHub (origin main)..." -ForegroundColor Green
    & $gitExe push origin main
    if ($Version) {
        & $gitExe push origin --tags
    }
    Write-Host ">>> ¡Versión subida exitosamente a GitHub!" -ForegroundColor Green
} else {
    Write-Warning "No hay un repositorio remoto 'origin' configurado todavía."
    Write-Host "Para vincular tu repositorio remoto de GitHub ejecuta:"
    Write-Host "  git remote add origin https://github.com/TU-USUARIO/LIHO.git"
    Write-Host "  git push -u origin main"
}
