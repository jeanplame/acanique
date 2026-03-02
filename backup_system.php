<?php
/**
 * Système de sauvegarde pour la base de données Acadenique
 * Permet de créer des sauvegardes complètes ou partielles de la base de données lmd_db
 */

class BackupSystem {
    private $pdo;
    private $config;
    
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
            'max_backups' => 30, // Nombre maximum de sauvegardes à conserver
            'compression' => true, // Activer la compression GZIP
            'log_file' => __DIR__ . '/logs/backup.log'
        ];
        
        // Créer les répertoires nécessaires
        $this->createDirectories();
    }
    
    /**
     * Initialiser la connexion à la base de données
     */
    private function initializeConnection() {
        // Configuration par défaut
        $host = 'localhost';
        $dbname = 'lmd_db';
        $username = 'root';
        $password = 'mysarnye';
        
        // Essayer de charger la configuration depuis db_config.php si disponible
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
    
    /**
     * Créer les répertoires nécessaires pour les sauvegardes et logs
     */
    private function createDirectories() {
        $directories = [
            dirname($this->config['backup_path']),
            dirname($this->config['log_file'])
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Écrire dans le fichier de log
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents($this->config['log_file'], $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Sauvegarde complète de la base de données
     */
    public function createFullBackup($customName = null) {
        try {
            $backupName = $customName ?: 'full_backup_' . date('Y-m-d_H-i-s');
            $filename = $this->config['backup_path'] . $backupName . '.sql';
            
            $this->log("Début de la sauvegarde complète: $backupName");
            
            // Obtenir la liste de toutes les tables
            $tables = $this->getAllTables();
            
            // Créer la sauvegarde
            $sql = $this->generateBackupSQL($tables);
            
            // Écrire le fichier
            if ($this->config['compression']) {
                $filename .= '.gz';
                file_put_contents($filename, gzencode($sql));
            } else {
                file_put_contents($filename, $sql);
            }
            
            $this->log("Sauvegarde complète créée avec succès: $filename");
            
            // Nettoyer les anciennes sauvegardes
            $this->cleanOldBackups();
            
            return [
                'success' => true,
                'filename' => $filename,
                'size' => filesize($filename),
                'tables_count' => count($tables)
            ];
            
        } catch (Exception $e) {
            $this->log("Erreur lors de la sauvegarde complète: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Sauvegarde partielle (tables sélectionnées)
     */
    public function createPartialBackup($tables, $customName = null) {
        try {
            $backupName = $customName ?: 'partial_backup_' . date('Y-m-d_H-i-s');
            $filename = $this->config['backup_path'] . $backupName . '.sql';
            
            $this->log("Début de la sauvegarde partielle: $backupName, Tables: " . implode(', ', $tables));
            
            // Vérifier que les tables existent
            $validTables = $this->validateTables($tables);
            
            if (empty($validTables)) {
                throw new Exception("Aucune table valide spécifiée");
            }
            
            // Créer la sauvegarde
            $sql = $this->generateBackupSQL($validTables);
            
            // Écrire le fichier
            if ($this->config['compression']) {
                $filename .= '.gz';
                file_put_contents($filename, gzencode($sql));
            } else {
                file_put_contents($filename, $sql);
            }
            
            $this->log("Sauvegarde partielle créée avec succès: $filename");
            
            return [
                'success' => true,
                'filename' => $filename,
                'size' => filesize($filename),
                'tables_count' => count($validTables)
            ];
            
        } catch (Exception $e) {
            $this->log("Erreur lors de la sauvegarde partielle: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Sauvegarde des données uniquement (sans structure)
     */
    public function createDataOnlyBackup($customName = null) {
        try {
            $backupName = $customName ?: 'data_only_' . date('Y-m-d_H-i-s');
            $filename = $this->config['backup_path'] . $backupName . '.sql';
            
            $this->log("Début de la sauvegarde données uniquement: $backupName");
            
            $tables = $this->getAllTables();
            $sql = $this->generateBackupSQL($tables, false, true); // Structure = false, Data = true
            
            if ($this->config['compression']) {
                $filename .= '.gz';
                file_put_contents($filename, gzencode($sql));
            } else {
                file_put_contents($filename, $sql);
            }
            
            $this->log("Sauvegarde données uniquement créée avec succès: $filename");
            
            return [
                'success' => true,
                'filename' => $filename,
                'size' => filesize($filename),
                'tables_count' => count($tables)
            ];
            
        } catch (Exception $e) {
            $this->log("Erreur lors de la sauvegarde données: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtenir la liste de toutes les tables
     */
    private function getAllTables() {
        $stmt = $this->pdo->query("SHOW TABLES");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Valider que les tables existent
     */
    private function validateTables($tables) {
        $allTables = $this->getAllTables();
        return array_intersect($tables, $allTables);
    }
    
    /**
     * Générer le SQL de sauvegarde
     */
    private function generateBackupSQL($tables, $includeStructure = true, $includeData = true) {
        $sql = "-- Sauvegarde de la base de données " . DB_NAME . "\n";
        $sql .= "-- Généré le " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Tables: " . implode(', ', $tables) . "\n\n";
        
        $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $sql .= "START TRANSACTION;\n";
        $sql .= "SET time_zone = \"+00:00\";\n\n";
        
        foreach ($tables as $table) {
            $sql .= "-- --------------------------------------------------------\n";
            $sql .= "-- Table: $table\n";
            $sql .= "-- --------------------------------------------------------\n\n";
            
            if ($includeStructure) {
                // Structure de la table
                $createTable = $this->pdo->query("SHOW CREATE TABLE `$table`")->fetch();
                $sql .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql .= $createTable['Create Table'] . ";\n\n";
            }
            
            if ($includeData) {
                // Données de la table
                $stmt = $this->pdo->query("SELECT * FROM `$table`");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    // Obtenir les colonnes
                    $columns = array_keys($rows[0]);
                    $columnsList = '`' . implode('`, `', $columns) . '`';
                    
                    $sql .= "INSERT INTO `$table` ($columnsList) VALUES\n";
                    
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
                    
                    $sql .= implode(",\n", $values) . ";\n\n";
                }
            }
        }
        
        $sql .= "COMMIT;\n";
        return $sql;
    }
    
    /**
     * Nettoyer les anciennes sauvegardes
     */
    private function cleanOldBackups() {
        $backupFiles = glob($this->config['backup_path'] . '*.{sql,sql.gz}', GLOB_BRACE);
        
        // Trier par date de modification (plus récent en premier)
        usort($backupFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // Supprimer les sauvegardes excédentaires
        $filesToDelete = array_slice($backupFiles, $this->config['max_backups']);
        foreach ($filesToDelete as $file) {
            unlink($file);
            $this->log("Ancienne sauvegarde supprimée: " . basename($file));
        }
    }
    
    /**
     * Lister les sauvegardes disponibles
     */
    public function listBackups() {
        $backupFiles = glob($this->config['backup_path'] . '*.{sql,sql.gz}', GLOB_BRACE);
        $backups = [];
        
        foreach ($backupFiles as $file) {
            $backups[] = [
                'filename' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'compressed' => str_ends_with($file, '.gz')
            ];
        }
        
        // Trier par date (plus récent en premier)
        usort($backups, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return $backups;
    }
    
    /**
     * Obtenir les informations sur l'espace disque
     */
    public function getDiskInfo() {
        $backupPath = $this->config['backup_path'];
        
        return [
            'backup_path' => $backupPath,
            'free_space' => disk_free_space($backupPath),
            'total_space' => disk_total_space($backupPath),
            'backup_size' => $this->getBackupsSize()
        ];
    }
    
    /**
     * Calculer la taille totale des sauvegardes
     */
    private function getBackupsSize() {
        $backupFiles = glob($this->config['backup_path'] . '*.{sql,sql.gz}', GLOB_BRACE);
        $totalSize = 0;
        
        foreach ($backupFiles as $file) {
            $totalSize += filesize($file);
        }
        
        return $totalSize;
    }
    
    /**
     * Formater la taille en octets
     */
    public function formatBytes($size, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        
        return round($size, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Vérifier l'intégrité d'une sauvegarde
     */
    public function verifyBackup($filename) {
        $filepath = $this->config['backup_path'] . $filename;
        
        if (!file_exists($filepath)) {
            return ['valid' => false, 'error' => 'Fichier non trouvé'];
        }
        
        try {
            if (str_ends_with($filename, '.gz')) {
                $content = gzdecode(file_get_contents($filepath));
            } else {
                $content = file_get_contents($filepath);
            }
            
            // Vérifications basiques
            $checks = [
                'has_start_transaction' => strpos($content, 'START TRANSACTION') !== false,
                'has_commit' => strpos($content, 'COMMIT') !== false,
                'has_create_table' => strpos($content, 'CREATE TABLE') !== false,
                'size_ok' => strlen($content) > 100
            ];
            
            $valid = array_reduce($checks, function($carry, $check) {
                return $carry && $check;
            }, true);
            
            return [
                'valid' => $valid,
                'checks' => $checks,
                'size' => strlen($content)
            ];
            
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

// Utilisation en mode CLI ou interface web
if (php_sapi_name() === 'cli') {
    // Mode ligne de commande
    $backup = new BackupSystem();
    
    $options = getopt('t:n:h', ['type:', 'name:', 'help', 'tables:']);
    
    if (isset($options['h']) || isset($options['help'])) {
        echo "Utilisation: php backup_system.php [options]\n";
        echo "Options:\n";
        echo "  -t, --type     Type de sauvegarde (full, partial, data)\n";
        echo "  -n, --name     Nom personnalisé pour la sauvegarde\n";
        echo "  --tables       Tables à sauvegarder (séparées par des virgules, pour partial)\n";
        echo "  -h, --help     Afficher cette aide\n";
        exit(0);
    }
    
    $type = $options['t'] ?? $options['type'] ?? 'full';
    $name = $options['n'] ?? $options['name'] ?? null;
    
    switch ($type) {
        case 'full':
            $result = $backup->createFullBackup($name);
            break;
        case 'partial':
            $tables = isset($options['tables']) ? explode(',', $options['tables']) : [];
            if (empty($tables)) {
                echo "Erreur: Spécifiez les tables avec --tables\n";
                exit(1);
            }
            $result = $backup->createPartialBackup($tables, $name);
            break;
        case 'data':
            $result = $backup->createDataOnlyBackup($name);
            break;
        default:
            echo "Type de sauvegarde non valide. Utilisez: full, partial, ou data\n";
            exit(1);
    }
    
    if ($result['success']) {
        echo "Sauvegarde créée avec succès:\n";
        echo "- Fichier: " . $result['filename'] . "\n";
        echo "- Taille: " . $backup->formatBytes($result['size']) . "\n";
        echo "- Tables: " . $result['tables_count'] . "\n";
    } else {
        echo "Erreur lors de la sauvegarde: " . $result['error'] . "\n";
        exit(1);
    }
}
?>