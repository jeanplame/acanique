@echo off
REM Script d'auto-sync avec sauvegarde MySQL pour Acadenique
cd /d c:\wamp64\www\acadenique

REM Charger git et PHP dans le PATH
set PATH=%PATH%;C:\Program Files\Git\cmd;C:\wamp64\bin\php\php8.4.15

REM ===== SAUVEGARDE DATABASE =====
echo [%date% %time%] Sauvegarde de la base de donnees en cours... >> .git-sync.log
php.exe backup-db-php.php >> .git-sync.log 2>&1

REM ===== NETTOYAGE ANCIENS BACKUPS =====
echo [%date% %time%] Nettoyage des anciens backups (garder 5 derniers)... >> .git-sync.log
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "& '.\cleanup-old-backups.ps1'" >> .git-sync.log 2>&1

REM ===== SYNCHRONISATION GIT =====
echo [%date% %time%] Debut synchronisation Git... >> .git-sync.log
REM Vérifier les modifications
git status --porcelain > nul 2>&1
if %ERRORLEVEL% EQU 0 (
    git add .
    git commit -m "Auto-sync: %date% %time% (avec sauvegarde DB)"
    git push origin main
    
    REM Log le succès
    echo [%date% %time%] Sync complete avec sauvegarde >> .git-sync.log
) else (
    echo [%date% %time%] Pas de modifications >> .git-sync.log
)
