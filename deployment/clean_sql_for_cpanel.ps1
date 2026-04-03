param(
    [Parameter(Mandatory = $true)]
    [string]$InputFile,

    [Parameter(Mandatory = $false)]
    [string]$OutputFile
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $InputFile)) {
    Write-Error "Fichier introuvable: $InputFile"
    exit 1
}

if (-not $OutputFile) {
    $directory = Split-Path -Parent $InputFile
    $filename = [System.IO.Path]::GetFileNameWithoutExtension($InputFile)
    $extension = [System.IO.Path]::GetExtension($InputFile)
    $OutputFile = Join-Path $directory ($filename + '_cpanel' + $extension)
}

$content = [System.IO.File]::ReadAllText($InputFile)

# Supprime tout le bloc de procedure initial (incluant DELIMITER et commentaires)
# qui provoque souvent des erreurs sur cPanel/phpMyAdmin.
$content = [System.Text.RegularExpressions.Regex]::Replace(
    $content,
    '(?is)DELIMITER\s*\$\$\s*--\s*Proc[ée]dures\s*--.*?DROP\s+PROCEDURE\s+IF\s+EXISTS\s+`fix_charset_all`\s*\$\$.*?DELIMITER\s*;',''
)

# Fallback: au cas ou le bloc precedent n'existe pas exactement sous cette forme.
$content = [System.Text.RegularExpressions.Regex]::Replace(
    $content,
    '(?is)DROP\s+PROCEDURE\s+IF\s+EXISTS\s+`fix_charset_all`\s*\$\$.*?DELIMITER\s*;',''
)

# Supprime un DELIMITER $$ orphelin qui pourrait rester apres nettoyage.
$content = [System.Text.RegularExpressions.Regex]::Replace(
    $content,
    '(?im)^DELIMITER\s*\$\$\s*$\r?\n',
    ''
)

# Supprime tous les DEFINER=
$content = [System.Text.RegularExpressions.Regex]::Replace(
    $content,
    '(?i)\s+DEFINER=`[^`]+`@`[^`]+`',
    ''
)

# Evite les blocages de privilege sur certaines plateformes partagees.
$content = [System.Text.RegularExpressions.Regex]::Replace(
    $content,
    '(?i)SQL\s+SECURITY\s+DEFINER',
    'SQL SECURITY INVOKER'
)

# Nettoyage leger de lignes vides excessives.
$content = [System.Text.RegularExpressions.Regex]::Replace(
    $content,
    '(\r?\n){3,}',
    "`r`n`r`n"
)

[System.IO.File]::WriteAllText($OutputFile, $content, (New-Object System.Text.UTF8Encoding($false)))

Write-Host "Fichier nettoye cree:" -ForegroundColor Green
Write-Host $OutputFile
