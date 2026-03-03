param(
    [int]$KeepCount = 5,
    [string]$BackupDir = "c:\wamp64\www\acadenique\database\backups"
)

# Garder seulement les 5 derniers backups
$backups = Get-ChildItem -Path $BackupDir -Filter "lmd_db-*.sql" | Sort-Object LastWriteTime -Descending

if ($backups.Count -gt $KeepCount) {
    $toDelete = $backups | Select-Object -Skip $KeepCount
    
    foreach ($file in $toDelete) {
        Remove-Item -Path $file.FullName -Force
        Write-Host "Supprimé: $($file.Name)"
    }
    
    Write-Host "Nettoyage: $($toDelete.Count) anciens backups supprimés. $($KeepCount) conservés."
} else {
    Write-Host "OK: $($backups.Count) backups (limite: $KeepCount)"
}
