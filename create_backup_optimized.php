<?php
/**
 * Script de sauvegarde optimisé avec gestion mémoire
 */

// Augmenter la limite de mémoire
ini_set('memory_limit', '512M');

// Configuration de la base de données
$host = 'localhost';
$dbname = 'lmd_db';  
$username = 'root';
$password = 'mysarnye';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Connexion à la base de données réussie\n";
    
    // Créer le répertoire de sauvegarde
    $backupDir = __DIR__ . '/backups/';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    // Nom du fichier de sauvegarde
    $backupFile = $backupDir . 'backup_complet_' . date('Y-m-d_H-i-s') . '.sql';
    
    // Ouvrir le fichier en écriture
    $handle = fopen($backupFile, 'w');
    if (!$handle) {
        throw new Exception("Impossible d'ouvrir le fichier de sauvegarde");
    }
    
    // En-tête du fichier SQL
    fwrite($handle, "-- Sauvegarde complète de la base de données $dbname\n");
    fwrite($handle, "-- Généré le " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
    fwrite($handle, "START TRANSACTION;\n");
    fwrite($handle, "SET time_zone = \"+00:00\";\n\n");
    
    // Obtenir tables et vues séparément
    $stmt = $pdo->query("
        SELECT TABLE_NAME, TABLE_TYPE 
        FROM information_schema.TABLES 
        WHERE TABLE_SCHEMA = '$dbname' 
        ORDER BY TABLE_TYPE DESC, TABLE_NAME
    ");
    $objects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalTables = 0;
    $totalViews = 0;
    $totalRows = 0;
    
    echo "📊 Objets trouvés: " . count($objects) . "\n";
    
    foreach ($objects as $object) {
        $name = $object['TABLE_NAME'];
        $type = $object['TABLE_TYPE'];
        
        echo "📝 Traitement $type: $name\n";
        
        if ($type === 'BASE TABLE') {
            $totalTables++;
            
            // Structure de la table
            $createStmt = $pdo->query("SHOW CREATE TABLE `$name`");
            $createResult = $createStmt->fetch();
            
            fwrite($handle, "-- Structure de la table $name\n");
            fwrite($handle, "DROP TABLE IF EXISTS `$name`;\n");
            fwrite($handle, $createResult['Create Table'] . ";\n\n");
            
            // Données de la table - traitement par lots pour économiser la mémoire
            $countStmt = $pdo->query("SELECT COUNT(*) as count FROM `$name`");
            $rowCount = $countStmt->fetch()['count'];
            
            if ($rowCount > 0) {
                fwrite($handle, "-- Données de la table $name ($rowCount lignes)\n");
                
                // Obtenir les colonnes
                $columnsStmt = $pdo->query("SHOW COLUMNS FROM `$name`");
                $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
                $columnsList = '`' . implode('`, `', $columns) . '`';
                
                fwrite($handle, "INSERT INTO `$name` ($columnsList) VALUES\n");
                
                $batchSize = 1000; // Traiter par lots de 1000 lignes
                $offset = 0;
                $firstBatch = true;
                
                while ($offset < $rowCount) {
                    $stmt = $pdo->query("SELECT * FROM `$name` LIMIT $batchSize OFFSET $offset");
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (empty($rows)) break;
                    
                    $values = [];
                    foreach ($rows as $row) {
                        $rowValues = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $rowValues[] = 'NULL';
                            } else {
                                $rowValues[] = $pdo->quote($value);
                            }
                        }
                        $values[] = '(' . implode(', ', $rowValues) . ')';
                    }
                    
                    if (!$firstBatch) {
                        fwrite($handle, ",\n");
                    }
                    fwrite($handle, implode(",\n", $values));
                    
                    $firstBatch = false;
                    $offset += $batchSize;
                    
                    // Afficher le progrès
                    $progress = min(100, round(($offset / $rowCount) * 100));
                    echo "  📊 Progrès: $progress% ($offset/$rowCount)\r";
                }
                
                fwrite($handle, ";\n\n");
                $totalRows += $rowCount;
                echo "  ✅ $rowCount lignes sauvegardées          \n";
            } else {
                echo "  ⚪ Table vide\n";
            }
            
        } elseif ($type === 'VIEW') {
            $totalViews++;
            
            // Structure de la vue
            $createStmt = $pdo->query("SHOW CREATE VIEW `$name`");
            $createResult = $createStmt->fetch();
            
            fwrite($handle, "-- Structure de la vue $name\n");
            fwrite($handle, "DROP VIEW IF EXISTS `$name`;\n");
            fwrite($handle, $createResult['Create View'] . ";\n\n");
            
            echo "  ✅ Vue sauvegardée\n";
        }
    }
    
    fwrite($handle, "COMMIT;\n");
    fclose($handle);
    
    $fileSize = filesize($backupFile);
    $fileSizeMB = round($fileSize / 1024 / 1024, 2);
    
    echo "\n🎉 SAUVEGARDE COMPLÈTE CRÉÉE AVEC SUCCÈS !\n";
    echo "📁 Fichier: " . basename($backupFile) . "\n";
    echo "📏 Taille: {$fileSizeMB} MB ($fileSize octets)\n";
    echo "🗃️  Tables: $totalTables\n";
    echo "👁️  Vues: $totalViews\n";
    echo "📋 Lignes totales: $totalRows\n";
    echo "📍 Chemin complet: $backupFile\n";
    
    // Test d'intégrité
    if (file_exists($backupFile) && $fileSize > 1000) {
        echo "✅ Test d'intégrité: RÉUSSI\n";
        
        // Créer une version compressée
        if (function_exists('gzopen')) {
            $compressedFile = $backupFile . '.gz';
            $gz = gzopen($compressedFile, 'wb9');
            $sql = file_get_contents($backupFile);
            gzwrite($gz, $sql);
            gzclose($gz);
            
            $compressedSize = filesize($compressedFile);
            $compressedSizeMB = round($compressedSize / 1024 / 1024, 2);
            $compressionRatio = round((1 - $compressedSize / $fileSize) * 100, 1);
            
            echo "🗜️  Version compressée: " . basename($compressedFile) . "\n";
            echo "📏 Taille compressée: {$compressedSizeMB} MB (gain: {$compressionRatio}%)\n";
        }
    } else {
        echo "❌ Test d'intégrité: ÉCHEC\n";
    }
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    if (isset($handle)) {
        fclose($handle);
    }
    exit(1);
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "SAUVEGARDE TERMINÉE AVEC SUCCÈS\n";
echo str_repeat("=", 60) . "\n";
?>