<?php
/**
 * Script d'exécution de la migration: Système de programmation des UEs/ECs
 * Ce script exécute le fichier de migration SQL
 */

require_once __DIR__ . '/../config.php';

echo "=== Migration: Système de programmation des UEs/ECs ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

if (!isset($pdo) || !$pdo) {
    echo "❌ ERREUR: Impossible de se connecter à la base de données.\n";
    exit(1);
}

try {
    // Lire le fichier de migration
    $migration_file = __DIR__ . '/add_is_programmed_columns.sql';
    
    if (!file_exists($migration_file)) {
        echo "❌ ERREUR: Fichier de migration non trouvé: $migration_file\n";
        exit(1);
    }
    
    $sql_content = file_get_contents($migration_file);
    
    // Exécuter les requêtes SQL
    // On utilise exec() pour gérer les DELIMITER correctement
    $lines = explode("\n", $sql_content);
    $current_query = '';
    $delimiter = ';';
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Ignorer les commentaires et lignes vides
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }
        
        // Déterminer le délimiteur
        if (strpos($line, 'DELIMITER') === 0) {
            $parts = explode(' ', $line);
            if (count($parts) > 1) {
                $delimiter = $parts[1];
            }
            continue;
        }
        
        $current_query .= $line . "\n";
        
        // Vérifier si la requête est complète
        if (substr($line, -strlen($delimiter)) === $delimiter) {
            // Supprimer le délimiteur de fin
            $query = substr($current_query, 0, -strlen($delimiter));
            $query = trim($query);
            
            if (!empty($query)) {
                try {
                    echo "⏳ Exécution: " . substr($query, 0, 60) . "...\n";
                    $pdo->exec($query);
                    echo "✓ OK\n";
                } catch (PDOException $e) {
                    // Certaines erreurs sont attendues (ex: colonne déjà existante)
                    $error_msg = $e->getMessage();
                    if (strpos($error_msg, 'already exists') !== false || 
                        strpos($error_msg, 'Duplicate column') !== false) {
                        echo "✓ OK (déjà existant)\n";
                    } else {
                        echo "⚠️ AVERTISSEMENT: " . $error_msg . "\n";
                    }
                }
            }
            
            $current_query = '';
            $delimiter = ';'; // Réinitialiser le délimiteur par défaut
        }
    }
    
    echo "\n=== ✅ Migration exécutée avec succès! ===\n";
    
} catch (Exception $e) {
    echo "❌ ERREUR CRITIQUE: " . $e->getMessage() . "\n";
    exit(1);
}

?>
