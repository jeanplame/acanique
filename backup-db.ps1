# Script de sauvegarde automatique MySQL - Acadenique
# Localisation: c:\wamp64\www\acadenique\backup-db.ps1

param(
    [string]$DbHost = "localhost",
    [string]$DbName = "lmd_db",
    [string]$DbUser = "root",
    [string]$DbPassword = "mysarnye",
    [string]$BackupDir = "c:\wamp64\www\acadenique\database\backups",
    [string]$MySQLPath = "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe"
)

# Créer le dossier de sauvegardes s'il n'existe pas
if (-not (Test-Path $BackupDir)) {
    New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null
}

# Générer le nom du fichier avec la date/heure
$timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
$backupFile = "$BackupDir\$DbName-$timestamp.sql"
$compressedFile = "$backupFile.gz"

try {
    Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Sauvegarde de $DbName en cours..."
    
    # Vérifier que mysqldump existe
    if (-not (Test-Path $MySQLPath)) {
        # Chercher mysqldump dans le PATH
        $mysqldump = Get-Command mysqldump.exe -ErrorAction SilentlyContinue
        if ($mysqldump) {
            $MySQLPath = $mysqldump.Source
        } else {
            throw "mysqldump.exe non trouvé. Assurez-vous que MySQL est installé."
        }
    }
    
    # Créer la sauvegarde
    $env:MYSQL_PWD = $DbPassword
    & $MySQLPath -h $DbHost -u $DbUser --single-transaction --quick $DbName | Out-File -FilePath $backupFile -Encoding UTF8
    
    if ($LASTEXITCODE -eq 0) {
        $fileSize = (Get-Item $backupFile).Length / 1MB
        Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] OK - Sauvegarde reussie : $backupFile ($($fileSize | ForEach-Object { [Math]::Round($_, 2) }) MB)"
        
        # Nettoyer les anciennes sauvegardes (garder seulement les 7 dernières)
        Get-ChildItem -Path $BackupDir -Filter "$DbName*.sql" -File | 
            Sort-Object LastWriteTime -Descending | 
            Select-Object -Skip 7 | 
            ForEach-Object { 
                Remove-Item -Path $_.FullName -Force
                Write-Host "Suppression de l'ancienne sauvegarde : $($_.Name)"
            }
        
        return $true
    } else {
        throw "Erreur lors de la sauvegarde : Code $LASTEXITCODE"
    }
}
catch {
    Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] ERREUR : $_" -ForegroundColor Red
    return $false
}
finally {
    # Nettoyer la variable de mot de passe
    Remove-Item -Path Env:\MYSQL_PWD -ErrorAction SilentlyContinue
}
