<?php
/**
 * import_sync.php — Endpoint sécurisé d'import SQL
 *
 * ─── À UPLOADER SUR CPANEL DANS : public_html/_sync/ ───────────────
 *
 * Ce script reçoit le fichier SQL envoyé par sync_vers_cpanel.ps1
 * et l'importe dans la base de données cPanel.
 *
 * SÉCURITÉ :
 *  - Protégé par token secret (POST)
 *  - Accepte uniquement les requêtes POST
 *  - Supprime le fichier SQL après import
 *  - Limite la taille du fichier (50 Mo max)
 *  - Journalise chaque opération
 */

// ── Token secret ─────────────────────────────────────────────────
// DOIT être identique à $IMPORT_TOKEN dans sync_vers_cpanel.ps1
define('SYNC_TOKEN', 'UNILO_SYNC_2026_9aK3pL8qR5mN2x');

// ── Taille max du fichier SQL accepté (50 Mo) ─────────────────────
define('MAX_FILE_SIZE', 50 * 1024 * 1024);

// ── Chemin du fichier SQL déposé par FTP ──────────────────────────
define('SQL_FILE', __DIR__ . '/acadenique_sync.sql');

// ── Fichier journal ───────────────────────────────────────────────
define('LOG_FILE', __DIR__ . '/sync_log.txt');

// ─────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
// Aucune info de débogage PHP exposée au client
ini_set('display_errors', 0);
error_reporting(0);

/**
 * Retourne une réponse JSON et termine le script.
 */
function respond($success, $message, $extra = array())
{
    echo json_encode(array_merge(array('success' => $success, 'message' => $message), $extra));
    exit;
}

/**
 * Écrit une ligne dans le journal.
 */
function logLine($line)
{
    $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
    $entry = '[' . date('Y-m-d H:i:s') . '] [' . $remoteAddr . '] ' . $line . PHP_EOL;
    file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Compatibilite PHP 7.x: equivalent simple de str_starts_with.
 */
function startsWithText($haystack, $needle)
{
    return substr($haystack, 0, strlen($needle)) === $needle;
}

// ── Vérifications préliminaires ───────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Méthode non autorisée.', ['code' => 405]);
}

$token = isset($_POST['token']) ? trim($_POST['token']) : '';
if (!hash_equals(SYNC_TOKEN, $token)) {
    logLine("REFUSÉ — token invalide.");
    http_response_code(403);
    respond(false, 'Token invalide.', ['code' => 403]);
}

$sqlFilePath = SQL_FILE;

// Mode 1: upload HTTP direct (recommande)
if (isset($_FILES['sql_file']) && is_array($_FILES['sql_file'])) {
    $uploadError = isset($_FILES['sql_file']['error']) ? $_FILES['sql_file']['error'] : UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        respond(false, 'Echec upload du fichier SQL.');
    }

    $tmpUploaded = isset($_FILES['sql_file']['tmp_name']) ? $_FILES['sql_file']['tmp_name'] : '';
    if ($tmpUploaded === '' || !is_uploaded_file($tmpUploaded)) {
        respond(false, 'Fichier upload invalide.');
    }

    $uploadedSize = isset($_FILES['sql_file']['size']) ? (int)$_FILES['sql_file']['size'] : 0;
    if ($uploadedSize <= 0) {
        respond(false, 'Fichier SQL vide.');
    }
    if ($uploadedSize > MAX_FILE_SIZE) {
        logLine("ERREUR — upload trop volumineux : {$uploadedSize} octets.");
        respond(false, 'Fichier SQL trop volumineux (' . round($uploadedSize / 1048576, 1) . ' Mo max: 50 Mo).');
    }

    if (!move_uploaded_file($tmpUploaded, SQL_FILE)) {
        respond(false, 'Impossible de stocker temporairement le fichier SQL uploadé.');
    }
}

// Mode 2: fichier deja depose en FTP (fallback)
if (!file_exists($sqlFilePath)) {
    logLine("ERREUR — fichier SQL introuvable : " . $sqlFilePath);
    respond(false, 'Fichier SQL introuvable. Uploadez-le via HTTP ou FTP.');
}

$fileSize = filesize($sqlFilePath);
if ($fileSize > MAX_FILE_SIZE) {
    logLine("ERREUR — fichier trop volumineux : {$fileSize} octets.");
    respond(false, 'Fichier SQL trop volumineux (' . round($fileSize / 1048576, 1) . ' Mo max: 50 Mo).');
}

if ($fileSize === 0) {
    respond(false, 'Fichier SQL vide.');
}

// ── Connexion à la base de données cPanel ─────────────────────────
// Priorite: config dediee dans _sync, puis fallback sur ../includes/db_config.php
$dbConfigSyncPath = __DIR__ . '/db_config_sync.php';
$dbConfigPath = __DIR__ . '/../includes/db_config.php';

if (file_exists($dbConfigSyncPath)) {
    require_once $dbConfigSyncPath;
} elseif (file_exists($dbConfigPath)) {
    require_once $dbConfigPath;
} else {
    logLine("ERREUR — config BD introuvable: $dbConfigSyncPath ou $dbConfigPath");
    respond(false, 'Configuration base de données introuvable.');
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
        DB_USER,
        DB_PASS,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        )
    );
} catch (PDOException $e) {
    logLine("ERREUR connexion BD : " . $e->getMessage());
    respond(false, 'Impossible de se connecter à la base de données.');
}

// ── Import du SQL ─────────────────────────────────────────────────
$sql = file_get_contents($sqlFilePath);
if ($sql === false) {
    logLine("ERREUR — lecture du fichier SQL échouée.");
    respond(false, 'Impossible de lire le fichier SQL.');
}

$rowsAffected = 0;

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0; SET NAMES utf8;");

    // Découper par statement pour un meilleur contrôle des erreurs
    // On utilise la séquence ";\n" typique d'un mysqldump
    $statements = array_filter(
        array_map('trim', explode(";\n", $sql)),
        function ($s) {
            return !empty($s)
                && !startsWithText($s, '--')
                && !startsWithText($s, '/*');
        }
    );

    $pdo->beginTransaction();
    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        $result = $pdo->exec($stmt);
        if ($result !== false) {
            $rowsAffected += max(0, $result);
        }
    }
    $pdo->commit();

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logLine("ERREUR import SQL : " . $e->getMessage());
    respond(false, 'Erreur lors de l\'import : ' . $e->getMessage());
}

// ── Nettoyage du fichier SQL ──────────────────────────────────────
@unlink($sqlFilePath);

logLine("SUCCÈS — {$rowsAffected} lignes traitées. Fichier SQL supprimé.");

respond(true, 'Import réussi.', array(
    'rows_affected' => $rowsAffected,
    'tables_synced' => 14,
    'timestamp'     => date('Y-m-d H:i:s'),
));
