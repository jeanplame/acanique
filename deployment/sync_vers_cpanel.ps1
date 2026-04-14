<#
.SYNOPSIS
    Synchronise la base de données locale (WAMP) vers le serveur cPanel
    pour alimenter la page de consultation des résultats en ligne.

.DESCRIPTION
    1. Exporte les tables nécessaires depuis MySQL local (mysqldump)
    2. Uploade le fichier SQL via FTP sur le serveur cPanel
    3. Déclenche l'import via import_sync.php (endpoint sécurisé)
    4. Supprime le fichier SQL temporaire local

.NOTES
    ⚠  Gardez ce fichier PRIVÉ — il contient des mots de passe.
       Ne le committez pas dans un dépôt Git public.

    À exécuter depuis PowerShell :
        .\deployment\sync_vers_cpanel.ps1
#>

# ═══════════════════════════════════════════════════════════════════
#  CONFIGURATION — REMPLIR AVANT PREMIÈRE UTILISATION
# ═══════════════════════════════════════════════════════════════════

# ── Base de données LOCALE (WAMP) ──────────────────────────────────
$LOCAL_DB_HOST = "localhost"
$LOCAL_DB_NAME = "lmd_db"
$LOCAL_DB_USER = "root"
$LOCAL_DB_PASS = "mysarnye"

# ── Serveur cPanel (FTP) ───────────────────────────────────────────
# Trouvez ces infos dans cPanel > Comptes FTP
$FTP_HOST = "ftp.unilo.cd"                # ex: ftp.unilo.cd
$FTP_USER = "ftp.unilo@unilo.cd"          # ex: admin@unilo.cd
$FTP_PASS = "@Jean2022"
$FTP_PATH = "/public_html/solution.unilo.cd/_sync/"  # Dossier _sync/ sur le serveur
                                           # (créez-le dans cPanel > Gestionnaire de fichiers)

# ── Import en ligne ────────────────────────────────────────────────
# URL complète vers import_sync.php sur le serveur
$IMPORT_URL   = "https://solution.unilo.cd/_sync/import_sync.php"
# Token secret — doit être identique dans import_sync.php
$IMPORT_TOKEN = "UNILO_SYNC_2026_9aK3pL8qR5mN2x"

# ═══════════════════════════════════════════════════════════════════
#  NE PAS MODIFIER CI-DESSOUS SAUF SI NÉCESSAIRE
# ═══════════════════════════════════════════════════════════════════

$ErrorActionPreference = "Stop"
$DUMP_FILE = "$env:TEMP\acadenique_sync_$(Get-Date -Format 'yyyyMMdd_HHmmss').sql"

# Tables à synchroniser (toutes celles utilisées par consulter_resultat.php)
$TABLES = @(
    "t_configuration"
    "t_anne_academique"
    "t_etudiant"
    "t_inscription"
    "t_filiere"
    "t_domaine"
    "t_mention"
    "t_promotion"
    "t_cote"
    "t_unite_enseignement"
    "t_element_constitutif"
    "t_semestre"
    "t_mention_ue"
    "t_mention_ue_ec"
)

# Display helpers (ASCII-safe)
function Write-Step  { param($msg) Write-Host "  > $msg" -ForegroundColor Cyan }
function Write-OK    { param($msg) Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Fail  { param($msg) Write-Host "  [ERR] $msg" -ForegroundColor Red }
function Write-Warn  { param($msg) Write-Host "  [WARN] $msg" -ForegroundColor Yellow }

Write-Host ""
Write-Host "========================================" -ForegroundColor DarkCyan
Write-Host "  SYNC ACADENIQUE -> CPANEL" -ForegroundColor White
Write-Host "  $(Get-Date -Format 'dd/MM/yyyy HH:mm:ss')" -ForegroundColor DarkGray
Write-Host "========================================" -ForegroundColor DarkCyan
Write-Host ""

# ── Vérifier la configuration ──────────────────────────────────────
if ($FTP_HOST -eq "ftp.votre-domaine.com" -or $IMPORT_TOKEN -eq "CHANGEZ_CE_TOKEN_SECRET_32_CHARS") {
    Write-Fail "Configuration non remplie !"
    Write-Warn "Éditez les variables de configuration dans ce script avant de continuer."
    exit 1
}

# ── Étape 1 : Trouver mysqldump ────────────────────────────────────
Write-Step "Recherche de mysqldump..."

$mysqldump = $null

# Chercher dans les installations WAMP
$wampMysql = Get-ChildItem "C:\wamp64\bin\mysql" -ErrorAction SilentlyContinue |
    Where-Object { $_.PSIsContainer } |
    Sort-Object Name -Descending |
    Select-Object -First 1

if ($wampMysql) {
    $candidate = Join-Path $wampMysql.FullName "bin\mysqldump.exe"
    if (Test-Path $candidate) { $mysqldump = $candidate }
}

# Chercher dans PATH si non trouvé
if (-not $mysqldump) {
    $found = Get-Command mysqldump -ErrorAction SilentlyContinue
    if ($found) { $mysqldump = $found.Source }
}

if (-not $mysqldump) {
    Write-Fail "mysqldump.exe introuvable dans WAMP. Vérifiez que WAMP est installé."
    exit 1
}
Write-OK "mysqldump trouvé : $mysqldump"

# ── Étape 2 : Créer le dump SQL ────────────────────────────────────
Write-Step "Création du dump SQL ($(($TABLES).Count) tables)..."

$tableList = $TABLES -join " "
$mysqlArgs = @(
    "--host=$LOCAL_DB_HOST"
    "--user=$LOCAL_DB_USER"
    "--password=$LOCAL_DB_PASS"
    "--no-create-info"       # données uniquement (structure déjà sur serveur)
    "--skip-comments"        # retire les en-têtes/commentaires mysqldump
    "--replace"              # REPLACE INTO au lieu de INSERT INTO (idempotent)
    "--skip-extended-insert" # une ligne par enregistrement (plus lisible)
    "--set-charset"
    "--default-character-set=utf8"
    $LOCAL_DB_NAME
) + $TABLES

try {
    # ── Stratégie d'encodage ──────────────────────────────────────────────
    # On NE capture PAS la sortie de mysqldump dans des variables PowerShell.
    # Capturer via  & $mysqldump  ferait passer les octets UTF-8 par l'encodage
    # de la console Windows (CP850/CP1252) et corromprait tous les accents.
    # Solution : mysqldump écrit directement dans un fichier via --result-file,
    # puis PowerShell lit ce fichier en UTF-8 pour filtrer les lignes indésirables
    # et le réécrit proprement en UTF-8-sans-BOM avec fins de ligne LF (Unix).
    # ─────────────────────────────────────────────────────────────────────────

    $utf8NoBom  = New-Object System.Text.UTF8Encoding($false)
    $DUMP_RAW   = $DUMP_FILE + ".raw"

    # mysqldump écrit les données brutes UTF-8 sans passer par la console
    $mysqlArgsWithFile = $mysqlArgs + "--result-file=$DUMP_RAW"
    # $ErrorActionPreference = "Stop" transforme les messages stderr de mysqldump
    # (dont le warning "password on command line") en exceptions fatales.
    # On suspend temporairement ce comportement pour cet appel uniquement.
    $prevEAP = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    & $mysqldump @mysqlArgsWithFile 2>$null
    $dumpExitCode = $LASTEXITCODE
    $ErrorActionPreference = $prevEAP
    if ($dumpExitCode -ne 0 -or -not (Test-Path $DUMP_RAW) -or (Get-Item $DUMP_RAW).Length -eq 0) {
        throw "mysqldump a échoué (code $dumpExitCode). Vérifiez les identifiants DB."
    }

    # Lire le fichier brut en UTF-8, filtrer les lignes dangereuses,
    # réécrire en UTF-8-sans-BOM avec LF uniquement.
    $rawLines = [System.IO.File]::ReadAllLines($DUMP_RAW, [System.Text.Encoding]::UTF8)

    $filteredLines = $rawLines | Where-Object {
        # LOCK/UNLOCK TABLES → commit implicite MySQL → brise la transaction PHP
        $_ -notmatch '^\s*LOCK\s+TABLES\s' -and
        $_ -notmatch '^\s*UNLOCK\s+TABLES' -and
        $_ -notmatch '^\s*SET\s+autocommit'
    }

    $sqlContent = ($filteredLines -join "`n") + "`n"
    [System.IO.File]::WriteAllText($DUMP_FILE, $sqlContent, $utf8NoBom)
    Remove-Item $DUMP_RAW -ErrorAction SilentlyContinue

    $dumpSize = (Get-Item $DUMP_FILE).Length
    Write-OK "Dump créé : $([Math]::Round($dumpSize / 1KB, 1)) Ko → $DUMP_FILE"
} catch {
    Write-Fail "Erreur lors du dump : $_"
    exit 1
}

# ── Étape 3a : Déployer la dernière version de import_sync.php ────
# Assure que le serveur utilise toujours la version locale à jour.
Write-Step "Deploiement de import_sync.php sur le serveur FTP..."

$importSyncLocal = Join-Path $PSScriptRoot "import_sync.php"
if (Test-Path $importSyncLocal) {
    try {
        $ftpUri = "ftp://$FTP_HOST$FTP_PATH`import_sync.php"
        $curlFtpOut = & curl.exe -sS --ftp-create-dirs `
            -T $importSyncLocal `
            --user "$FTP_USER`:$FTP_PASS" `
            "$ftpUri" 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-OK "import_sync.php deploye ($([Math]::Round((Get-Item $importSyncLocal).Length / 1KB, 1)) Ko)."
        } else {
            Write-Warn "Upload FTP echoue (code $LASTEXITCODE) : $curlFtpOut"
            Write-Warn "Continuing with existing server version."
        }
    } catch {
        Write-Warn "Upload FTP de import_sync.php echoue : $_"
        Write-Warn "Continuing with existing server version."
    }
} else {
    Write-Warn "import_sync.php introuvable en local ($importSyncLocal). Skipping deploy."
}

# ── Étape 3b : Upload HTTP direct + import ────────────────────────
Write-Step "Envoi du dump SQL via HTTPS vers $IMPORT_URL..."

$curlCmd = Get-Command curl.exe -ErrorAction SilentlyContinue
if (-not $curlCmd) {
    Write-Fail "curl.exe introuvable. Installez curl ou activez OpenSSH/Windows curl."
    Remove-Item $DUMP_FILE -ErrorAction SilentlyContinue
    exit 1
}

try {
    $curlOutput = & curl.exe -sS `
        -X POST `
        -F "token=$IMPORT_TOKEN" `
        -F "sql_file=@$DUMP_FILE;type=application/sql" `
        "$IMPORT_URL"

    try {
        $json = $curlOutput | ConvertFrom-Json
    } catch {
        Write-Fail "Reponse serveur non-JSON."
        Write-Warn "Contenu brut: $curlOutput"
        if ($curlOutput -match "Access denied for user") {
            Write-Warn "La connexion MySQL distante est refusee. Verifiez /includes/db_config.php sur le serveur cPanel."
        }
        Remove-Item $DUMP_FILE -ErrorAction SilentlyContinue
        exit 1
    }

    if ($json.success -eq $true) {
        Write-OK "Import reussi : $($json.message)"
        if ($json.rows_affected) {
            Write-OK "Lignes traitees : $($json.rows_affected)"
        }
    } else {
        Write-Fail "Erreur import : $($json.message)"
        Write-Warn "Reponse brute: $curlOutput"
        Remove-Item $DUMP_FILE -ErrorAction SilentlyContinue
        exit 1
    }
} catch {
    Write-Fail "Erreur HTTP/upload : $($_.Exception.Message)"
    Write-Warn "Verifiez IMPORT_URL, token et que import_sync.php est en ligne."
    Remove-Item $DUMP_FILE -ErrorAction SilentlyContinue
    exit 1
}

# ── Étape 5 : Nettoyage local ──────────────────────────────────────
Remove-Item $DUMP_FILE -ErrorAction SilentlyContinue
Write-OK "Fichier temporaire local supprimé."

Write-Host ""
Write-Host "========================================" -ForegroundColor DarkGreen
Write-Host "  SYNCHRONISATION TERMINEE AVEC SUCCES" -ForegroundColor Green
Write-Host "  Page en ligne : $($IMPORT_URL -replace '_sync/import_sync\.php', 'consulter_resultat.php')" -ForegroundColor Gray
Write-Host "========================================" -ForegroundColor DarkGreen
Write-Host ""
