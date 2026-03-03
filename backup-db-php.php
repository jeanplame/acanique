<?php
/**
 * Script de sauvegarde MySQL utilisant PHP/PDO (optimisé pour grandes bases)
 * Usage: php backup-db-php.php
 */

// Augmenter la limite de mémoire
ini_set('memory_limit', '512M');

// Configuration
$host = "localhost";
$dbname = "lmd_db";
$username = "root";
$password = "mysarnye";
$backupDir = __DIR__ . "/database/backups";

// Créer le dossier s'il n'existe pas
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

try {
    // Connexion à la base
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $timestamp = date("Y-m-d_H-i-s");
    $backupFile = "$backupDir/{$dbname}-{$timestamp}.sql";
    
    echo "[" . date("Y-m-d H:i:s") . "] Sauvegarde de $dbname en cours...\n";
    
    // Ouvrir le fichier pour écrire
    $fp = fopen($backupFile, 'w');
    if (!$fp) {
        throw new Exception("Impossible de créer le fichier: $backupFile");
    }
    
    fwrite($fp, "-- Sauvegarde de base de donnees: $dbname\n");
    fwrite($fp, "-- Date: " . date("Y-m-d H:i:s") . "\n");
    fwrite($fp, "-- Host: $host\n");
    fwrite($fp, "-- !\n");
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($fp, "\n");
    
    // Récupérer toutes les tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        echo "Export de la table: $table\n";
        
        // Récupérer la structure de création
        try {
            $createTableResult = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            
            fwrite($fp, "\n");
            fwrite($fp, "-- Structure de la table '$table'\n");
            fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
            
            // Les clés possibles changent selon que c'est une table ou une vue
            $createKey = isset($createTableResult['Create Table']) ? 'Create Table' : 'Create View';
            if (isset($createTableResult[$createKey])) {
                fwrite($fp, $createTableResult[$createKey] . ";\n");
            }
        } catch (Exception $e) {
            fwrite($fp, "-- Erreur lors du export de la table $table: " . $e->getMessage() . "\n");
            continue;
        }
        
        // Vérifier si c'est une vue
        $viewCheck = $pdo->query("SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='$dbname' AND TABLE_NAME='$table'")->fetch(PDO::FETCH_ASSOC);
        
        if ($viewCheck['TABLE_TYPE'] === 'VIEW') {
            // C'est une vue, pas besoin d'insérer des données
            continue;
        }
        
        // Insérer les données (ligne par ligne pour économiser la mémoire)
        fwrite($fp, "\n-- Données de la table '$table'\n");
        
        $stmt = $pdo->query("SELECT * FROM `$table`");
        $firstRow = true;
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($firstRow) {
                // Écrire l'en-tête INSERT une seule fois
                $columns = array_keys($row);
                $columnNames = "`" . implode("`, `", $columns) . "`";
                $prefix = "INSERT INTO `$table` ($columnNames) VALUES ";
                $firstRow = false;
            } else {
                $prefix = " ";
            }
            
            $values = array_map(function($val) use ($pdo) {
                if ($val === null) {
                    return "NULL";
                }
                return $pdo->quote($val);
            }, $row);
            
            fwrite($fp, $prefix . "(" . implode(", ", $values) . ");\n");
        }
    }
    
    fwrite($fp, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    
    $fileSize = round(filesize($backupFile) / 1024 / 1024, 2);
    echo "[" . date("Y-m-d H:i:s") . "] OK - Sauvegarde reussie: " . basename($backupFile) . " ($fileSize MB)\n";
    
    // Nettoyer les anciennes sauvegardes (garder seulement les 7 dernières)
    $files = glob("$backupDir/{$dbname}-*.sql");
    if ($files) {
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        foreach (array_slice($files, 7) as $oldFile) {
            unlink($oldFile);
            echo "Suppression de l'ancienne sauvegarde: " . basename($oldFile) . "\n";
        }
    }
    
    exit(0);
    
} catch (Exception $e) {
    echo "[" . date("Y-m-d H:i:s") . "] ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}
?>

