<?php
/**
 * Tâche Cron pour les Rapports Quotidiens et Maintenance
 * À exécuter quotidiennement via cron ou tâche Windows
 * 
 * Usage: php backup_daily_tasks.php
 */

// Seul CLI autorisé
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Accès interdit - CLI uniquement');
}

require_once 'config.php';
require_once 'backup_notification_system.php';
require_once 'backup_dashboard_manager.php';

class BackupDailyTasks {
    private $notifications;
    private $dashboard;
    private $logFile;
    
    public function __construct() {
        $this->notifications = new BackupNotificationSystem();
        $this->dashboard = new BackupDashboardManager();
        $this->logFile = __DIR__ . '/logs/daily_tasks.log';
        
        // Créer le répertoire de logs
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Exécuter toutes les tâches quotidiennes
     */
    public function runDailyTasks() {
        $this->log("=== Début des tâches quotidiennes ===");
        $startTime = microtime(true);
        $results = [];
        
        try {
            // 1. Vérifier l'espace disque
            $results['disk_check'] = $this->checkDiskSpace();
            
            // 2. Nettoyer les anciennes sauvegardes
            $results['cleanup'] = $this->cleanupOldBackups();
            
            // 3. Envoyer le rapport quotidien
            $results['daily_report'] = $this->sendDailyReport();
            
            // 4. Vérifier l'intégrité des sauvegardes récentes
            $results['integrity_check'] = $this->checkBackupIntegrity();
            
            // 5. Optimiser la base de données des logs
            $results['db_optimization'] = $this->optimizeDatabase();
            
            $duration = microtime(true) - $startTime;
            $this->log("=== Tâches quotidiennes terminées en " . round($duration, 2) . "s ===");
            
            return [
                'success' => true,
                'duration' => $duration,
                'results' => $results
            ];
            
        } catch (Exception $e) {
            $this->log("ERREUR CRITIQUE: " . $e->getMessage(), 'ERROR');
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'results' => $results
            ];
        }
    }
    
    /**
     * Vérifier l'espace disque et envoyer des alertes si nécessaire
     */
    private function checkDiskSpace() {
        $this->log("Vérification de l'espace disque...");
        
        try {
            $backupDir = __DIR__ . '/backups';
            $totalSpace = disk_total_space($backupDir);
            $freeSpace = disk_free_space($backupDir);
            $usedSpace = $totalSpace - $freeSpace;
            $usagePercent = round(($usedSpace / $totalSpace) * 100, 1);
            
            $this->log("Espace disque utilisé: {$usagePercent}%");
            
            // Envoyer alerte si usage > 85%
            if ($usagePercent >= 85) {
                $this->log("ALERTE: Espace disque critique ({$usagePercent}%)", 'WARNING');
                $this->notifications->sendDiskSpaceAlert($usagePercent);
                
                return [
                    'status' => 'warning',
                    'usage_percent' => $usagePercent,
                    'alert_sent' => true
                ];
            }
            
            return [
                'status' => 'ok',
                'usage_percent' => $usagePercent,
                'alert_sent' => false
            ];
            
        } catch (Exception $e) {
            $this->log("Erreur vérification espace disque: " . $e->getMessage(), 'ERROR');
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Nettoyer les anciennes sauvegardes selon la politique de rétention
     */
    private function cleanupOldBackups() {
        $this->log("Nettoyage des anciennes sauvegardes...");
        
        try {
            $backupDir = __DIR__ . '/backups';
            $retentionDays = 30; // Configurable
            $cutoffDate = time() - ($retentionDays * 24 * 3600);
            
            $deleted = 0;
            $totalSize = 0;
            
            if (is_dir($backupDir)) {
                $files = glob($backupDir . '/*.{sql,gz}', GLOB_BRACE);
                
                foreach ($files as $file) {
                    $fileTime = filemtime($file);
                    if ($fileTime < $cutoffDate) {
                        $size = filesize($file);
                        if (unlink($file)) {
                            $deleted++;
                            $totalSize += $size;
                            $this->log("Supprimé: " . basename($file) . " (" . round($size/1024/1024, 2) . " MB)");
                        }
                    }
                }
            }
            
            $this->log("Nettoyage terminé: {$deleted} fichiers supprimés (" . round($totalSize/1024/1024, 2) . " MB libérés)");
            
            return [
                'status' => 'ok',
                'files_deleted' => $deleted,
                'space_freed_mb' => round($totalSize/1024/1024, 2)
            ];
            
        } catch (Exception $e) {
            $this->log("Erreur nettoyage: " . $e->getMessage(), 'ERROR');
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Envoyer le rapport quotidien par email
     */
    private function sendDailyReport() {
        $this->log("Envoi du rapport quotidien...");
        
        try {
            $result = $this->notifications->sendDailyReport();
            
            if ($result) {
                $this->log("Rapport quotidien envoyé avec succès");
                return ['status' => 'ok', 'sent' => true];
            } else {
                $this->log("Aucun destinataire configuré pour le rapport quotidien", 'WARNING');
                return ['status' => 'warning', 'sent' => false];
            }
            
        } catch (Exception $e) {
            $this->log("Erreur envoi rapport quotidien: " . $e->getMessage(), 'ERROR');
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Vérifier l'intégrité des sauvegardes récentes
     */
    private function checkBackupIntegrity() {
        $this->log("Vérification de l'intégrité des sauvegardes...");
        
        try {
            $backupDir = __DIR__ . '/backups';
            $checked = 0;
            $corrupted = 0;
            
            if (is_dir($backupDir)) {
                // Vérifier les sauvegardes des 7 derniers jours
                $files = glob($backupDir . '/*.{sql,gz}', GLOB_BRACE);
                $recentFiles = array_filter($files, function($file) {
                    return filemtime($file) > (time() - 7 * 24 * 3600);
                });
                
                foreach ($recentFiles as $file) {
                    $checked++;
                    
                    // Vérifications basiques
                    if (filesize($file) < 1024) { // Fichier trop petit
                        $corrupted++;
                        $this->log("ATTENTION: Fichier suspect (trop petit): " . basename($file), 'WARNING');
                        continue;
                    }
                    
                    // Vérifier les fichiers compressés
                    if (pathinfo($file, PATHINFO_EXTENSION) === 'gz') {
                        $handle = gzopen($file, 'r');
                        if ($handle === false) {
                            $corrupted++;
                            $this->log("ERREUR: Fichier GZ corrompu: " . basename($file), 'ERROR');
                        } else {
                            gzclose($handle);
                        }
                    }
                }
            }
            
            $this->log("Vérification intégrité terminée: {$checked} fichiers vérifiés, {$corrupted} problèmes détectés");
            
            return [
                'status' => $corrupted > 0 ? 'warning' : 'ok',
                'files_checked' => $checked,
                'corrupted_files' => $corrupted
            ];
            
        } catch (Exception $e) {
            $this->log("Erreur vérification intégrité: " . $e->getMessage(), 'ERROR');
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Optimiser la base de données des logs
     */
    private function optimizeDatabase() {
        $this->log("Optimisation de la base de données...");
        
        try {
            global $pdo;
            
            // Nettoyer les anciens logs de plus de 90 jours
            $stmt = $pdo->prepare("DELETE FROM t_backup_history WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            $deletedLogs = $stmt->execute() ? $stmt->rowCount() : 0;
            
            // Optimiser les tables
            $tables = ['t_backup_history', 't_backup_schedule', 't_database_stats'];
            $optimized = 0;
            
            foreach ($tables as $table) {
                try {
                    $pdo->exec("OPTIMIZE TABLE $table");
                    $optimized++;
                } catch (Exception $e) {
                    $this->log("Erreur optimisation table $table: " . $e->getMessage(), 'WARNING');
                }
            }
            
            $this->log("Optimisation terminée: {$optimized} tables optimisées, {$deletedLogs} anciens logs supprimés");
            
            return [
                'status' => 'ok',
                'tables_optimized' => $optimized,
                'logs_deleted' => $deletedLogs
            ];
            
        } catch (Exception $e) {
            $this->log("Erreur optimisation DB: " . $e->getMessage(), 'ERROR');
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Logger avec timestamp
     */
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        echo $logMessage;
    }
}

// Point d'entrée CLI
if (isset($argv) && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    echo "🔄 Démarrage des tâches quotidiennes de maintenance...\n\n";
    
    try {
        $tasks = new BackupDailyTasks();
        $result = $tasks->runDailyTasks();
        
        if ($result['success']) {
            echo "\n✅ Toutes les tâches ont été exécutées avec succès!\n";
            echo "⏱️  Durée totale: " . round($result['duration'], 2) . " secondes\n";
            
            // Afficher le résumé
            echo "\n📊 Résumé des tâches:\n";
            foreach ($result['results'] as $task => $taskResult) {
                $status = $taskResult['status'] ?? 'unknown';
                $icon = $status === 'ok' ? '✅' : ($status === 'warning' ? '⚠️' : '❌');
                echo "   $icon " . ucfirst(str_replace('_', ' ', $task)) . ": $status\n";
            }
            
            exit(0);
        } else {
            echo "\n❌ Erreur lors de l'exécution des tâches quotidiennes\n";
            echo "Erreur: " . $result['error'] . "\n";
            exit(1);
        }
        
    } catch (Exception $e) {
        echo "\n💥 Exception critique: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>