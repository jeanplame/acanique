@echo off
REM Script de sauvegarde automatique pour Acadenique
REM À exécuter via le planificateur de tâches Windows

echo ========================================
echo Sauvegarde Automatique Acadenique
echo Date: %date% %time%
echo ========================================

REM Changement du répertoire de travail
cd /d "C:\wamp64\www\acadenique"

REM Exécution de la sauvegarde avec PHP
"C:\wamp64\bin\php\php8.1.0\php.exe" backup_system_v2.php

REM Vérification du code de retour
if %errorlevel% equ 0 (
    echo.
    echo ========================================
    echo SAUVEGARDE TERMINEE AVEC SUCCES
    echo ========================================
) else (
    echo.
    echo ========================================
    echo ERREUR LORS DE LA SAUVEGARDE
    echo Code d'erreur: %errorlevel%
    echo ========================================
)

REM Pause si exécuté manuellement (optionnel)
REM pause