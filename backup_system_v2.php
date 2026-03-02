<?php
/**
 * Système de sauvegarde optimisé et corrigé pour Acadenique
 * Version 2.0 avec gestion des vues et optimisation mémoire
 */

class BackupSystemOptimized {
    protected $pdo;
    protected $config;
    
    public function __construct() {
        global $pdo;
        
        // Si pas de connexion globale, créer une nouvelle connexion
        if (!$pdo) {
            $this->initializeConnection();
        } else {
            $this->pdo = $pdo;
        }
        
        $this->config = [
            'backup_path' => __DIR__ . '/backups/',
            'max_backups' => 30,
            'compression' => true,
            'log_file' => __DIR__ . '/logs/backup.log',
            'memory_limit' => '512M',
            'batch_size' => 1000
        ];
        
        // Augmenter la limite de mémoire
        ini_set('memory_limit', $this->config['memory_limit']);
        
        $this->createDirectories();
    }
    
    private function initializeConnection() {
        $host = 'localhost';
        $dbname = 'lmd_db';
        $username = 'root';
        $password = 'mysarnye';
        
        $configFile = __DIR__ . '/includes/db_config.php';
        if (file_exists($configFile)) {
            try {
                require_once $configFile;
                global $pdo;
                if ($pdo instanceof PDO) {
                    $this->pdo = $pdo;
                    return;
                }
            } catch (Exception $e) {
                // Continuer avec la configuration par défaut
            }
        }
        
        try {
            $this->pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new Exception("Erreur de connexion à la base de données: " . $e->getMessage());
        }
    }
    
    private function createDirectories() {
        if (!is_dir($this->config['backup_path'])) {
            mkdir($this->config['backup_path'], 0755, true);
        }
        
        $logDir = dirname($this->config['log_file']);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    public function createFullBackup($customName = null) {
        try {
            $backupName = $customName ?: 'full_backup_' . date('Y-m-d_H-i-s');
            $filename = $this->config['backup_path'] . $backupName . '.sql';
            
            $this->log("Début de la sauvegarde complète: $backupName");
            
            // Créer la sauvegarde optimisée
            $result = $this->createOptimizedBackup($filename);
            
            if ($result['success']) {
                $this->log("Sauvegarde complète créée avec succès: " . $result['filename']);
                $this->cleanOldBackups();
                
                return [
                    'success' => true,
                    'message' => 'Sauvegarde complète créée avec succès',
                    'filename' => $result['filename'],
                    'size' => $result['size'],
                    'compressed_size' => $result['compressed_size'] ?? null,
                    'tables' => $result['tables'],
                    'views' => $result['views'],
                    'total_rows' => $result['total_rows']
                ];
            } else {
                throw new Exception($result['message']);
            }
            
        } catch (Exception $e) {
            $this->log("Erreur lors de la sauvegarde complète: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ];
        }
    }
    
    private function createOptimizedBackup($filename) {
        // Obtenir tous les objets (tables et vues)
        $stmt = $this->pdo->query("
            SELECT TABLE_NAME, TABLE_TYPE 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_TYPE DESC, TABLE_NAME
        ");
        $objects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Ouvrir le fichier en écriture
        $handle = fopen($filename, 'w');
        if (!$handle) {
            throw new Exception("Impossible d'ouvrir le fichier de sauvegarde");
        }
        
        // En-tête du fichier SQL
        fwrite($handle, "-- Sauvegarde complète de la base de données\n");
        fwrite($handle, "-- Généré le " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Système: Acadenique Backup System v2.0\n\n");
        fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        fwrite($handle, "START TRANSACTION;\n");
        fwrite($handle, "SET time_zone = \"+00:00\";\n\n");
        
        $totalTables = 0;
        $totalViews = 0;
        $totalRows = 0;
        
        foreach ($objects as $object) {
            $name = $object['TABLE_NAME'];
            $type = $object['TABLE_TYPE'];
            
            if ($type === 'BASE TABLE') {
                $totalTables++;
                $rows = $this->backupTable($handle, $name);
                $totalRows += $rows;
            } elseif ($type === 'VIEW') {
                $totalViews++;
                $this->backupView($handle, $name);
            }
        }
        
        fwrite($handle, "COMMIT;\n");
        fclose($handle);
        
        $fileSize = filesize($filename);
        $result = [
            'success' => true,
            'filename' => basename($filename),
            'size' => $fileSize,
            'tables' => $totalTables,
            'views' => $totalViews,
            'total_rows' => $totalRows
        ];
        
        // Compression si activée
        if ($this->config['compression']) {
            $compressedFile = $filename . '.gz';
            $gz = gzopen($compressedFile, 'wb9');
            $sql = file_get_contents($filename);
            gzwrite($gz, $sql);
            gzclose($gz);
            
            $result['compressed_size'] = filesize($compressedFile);
            $result['filename'] = basename($compressedFile);
            
            // Supprimer le fichier non compressé
            unlink($filename);
        }
        
        return $result;
    }
    
    private function backupTable($handle, $tableName) {
        // Structure de la table
        $createStmt = $this->pdo->query("SHOW CREATE TABLE `$tableName`");
        $createResult = $createStmt->fetch();
        
        fwrite($handle, "-- ========================================\n");
        fwrite($handle, "-- Table: $tableName\n");
        fwrite($handle, "-- ========================================\n");
        fwrite($handle, "DROP TABLE IF EXISTS `$tableName`;\n");
        fwrite($handle, $createResult['Create Table'] . ";\n\n");
        
        // Données de la table - traitement par lots
        $countStmt = $this->pdo->query("SELECT COUNT(*) as count FROM `$tableName`");
        $rowCount = $countStmt->fetch()['count'];
        
        if ($rowCount == 0) {
            fwrite($handle, "-- Table $tableName est vide\n\n");
            return 0;
        }
        
        fwrite($handle, "-- Données de la table $tableName ($rowCount lignes)\n");
        
        // Obtenir les colonnes
        $columnsStmt = $this->pdo->query("SHOW COLUMNS FROM `$tableName`");
        $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
        $columnsList = '`' . implode('`, `', $columns) . '`';
        
        fwrite($handle, "INSERT INTO `$tableName` ($columnsList) VALUES\n");
        
        $batchSize = $this->config['batch_size'];
        $offset = 0;
        $firstBatch = true;
        
        while ($offset < $rowCount) {
            $stmt = $this->pdo->query("SELECT * FROM `$tableName` LIMIT $batchSize OFFSET $offset");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($rows)) break;
            
            $values = [];
            foreach ($rows as $row) {
                $rowValues = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $rowValues[] = 'NULL';
                    } else {
                        $rowValues[] = $this->pdo->quote($value);
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
        }
        
        fwrite($handle, ";\n\n");
        return $rowCount;
    }
    
    private function backupView($handle, $viewName) {
        $createStmt = $this->pdo->query("SHOW CREATE VIEW `$viewName`");
        $createResult = $createStmt->fetch();
        
        fwrite($handle, "-- ========================================\n");
        fwrite($handle, "-- Vue: $viewName\n");
        fwrite($handle, "-- ========================================\n");
        fwrite($handle, "DROP VIEW IF EXISTS `$viewName`;\n");
        fwrite($handle, $createResult['Create View'] . ";\n\n");
    }
    
    public function listBackups() {
        $backups = [];
        $files = glob($this->config['backup_path'] . '*.{sql,gz}', GLOB_BRACE);
        
        foreach ($files as $file) {
            $backups[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'date' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }
        
        // Trier par date décroissante
        usort($backups, function($a, $b) {
            return filemtime($b['path']) - filemtime($a['path']);
        });
        
        return $backups;
    }
    
    private function cleanOldBackups() {
        $backups = $this->listBackups();
        $toDelete = array_slice($backups, $this->config['max_backups']);
        
        foreach ($toDelete as $backup) {
            if (unlink($backup['path'])) {
                $this->log("Ancienne sauvegarde supprimée: " . $backup['name']);
            }
        }
    }
    
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        
        file_put_contents($this->config['log_file'], $logMessage, FILE_APPEND | LOCK_EX);
        
        if (php_sapi_name() === 'cli') {
            echo $logMessage;
        }
    }
    
    public function getStats() {
        $backups = $this->listBackups();
        $totalSize = array_sum(array_column($backups, 'size'));
        
        return [
            'total_backups' => count($backups),
            'total_size' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'oldest_backup' => end($backups)['date'] ?? null,
            'newest_backup' => $backups[0]['date'] ?? null
        ];
    }
}

// Test si exécuté directement
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    echo "Test du système de sauvegarde optimisé...\n";
    echo str_repeat("=", 50) . "\n";
    
    try {
        $backup = new BackupSystemOptimized();
        $result = $backup->createFullBackup('test_optimized_' . date('Y-m-d_H-i-s'));
        
        if ($result['success']) {
            echo "✅ Sauvegarde créée avec succès !\n";
            echo "📁 Fichier: " . $result['filename'] . "\n";
            echo "📏 Taille: " . round($result['size'] / 1024 / 1024, 2) . " MB\n";
            if (isset($result['compressed_size'])) {
                echo "🗜️  Taille compressée: " . round($result['compressed_size'] / 1024 / 1024, 2) . " MB\n";
                $gain = round((1 - $result['compressed_size'] / $result['size']) * 100, 1);
                echo "💾 Gain de compression: {$gain}%\n";
            }
            echo "🗃️  Tables: " . $result['tables'] . "\n";
            echo "👁️  Vues: " . $result['views'] . "\n";
            echo "📋 Lignes totales: " . number_format($result['total_rows']) . "\n";
            
            echo "\n📊 Statistiques générales:\n";
            $stats = $backup->getStats();
            echo "🗂️  Total des sauvegardes: " . $stats['total_backups'] . "\n";
            echo "💿 Espace utilisé: " . $stats['total_size_mb'] . " MB\n";
            
        } else {
            echo "❌ Erreur: " . $result['message'] . "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Exception: " . $e->getMessage() . "\n";
    }
    
    echo str_repeat("=", 50) . "\n";
    echo "Test terminé.\n";
}
?>