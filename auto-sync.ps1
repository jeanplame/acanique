param(
    [string]$RepoPath = "c:\wamp64\www\acadenique",
    [string]$LogFile = "c:\wamp64\www\acadenique\.git-sync.log"
)

function Write-Log {
    param([string]$Message)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$timestamp - $Message" | Add-Content -Path $LogFile
}

try {
    Push-Location $RepoPath
    
    # Vérifier s'il y a des modifications
    $status = git status --porcelain
    
    if ($status) {
        Write-Log "Modifications détectées. Synchronisation en cours..."
        
        # Ajouter tous les fichiers
        git add . 2>&1 | ForEach-Object { Write-Log $_ }
        
        # Créer un commit avec la date/heure
        $commitMsg = "Auto-sync: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
        git commit -m $commitMsg 2>&1 | ForEach-Object { Write-Log $_ }
        
        # Pousser vers GitHub
        git push origin main 2>&1 | ForEach-Object { Write-Log $_ }
        
        Write-Log "Synchronisation réussie!"
    } else {
        Write-Log "Pas de modifications détectées."
    }
}
catch {
    Write-Log "ERREUR: $_"
}
finally {
    Pop-Location
}
