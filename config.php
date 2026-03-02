<?php
/**
 * Configuration simple pour les scripts de sauvegarde
 * (version standalone pour CLI)
 */

// Configuration de la base de données
$host = "localhost";
$dbname = "lmd_db";
$username = "root";
$password = "mysarnye";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if (php_sapi_name() === 'cli') {
        echo "Erreur de connexion à la base de données: " . $e->getMessage() . "\n";
    } else {
        $connectionError = $e->getMessage();
    }
    $pdo = null;
}

// Configuration des répertoires
define('BACKUP_DIR', __DIR__ . '/backups');
define('LOG_DIR', __DIR__ . '/logs');

// Créer les répertoires nécessaires
if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
}
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}
?>