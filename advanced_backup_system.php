<?php
/**
 * Gestionnaire de Sauvegarde Programmée Avancé
 * Version 3.0 avec fonctionnalités étendues
 */

class AdvancedBackupSystem extends BackupSystemOptimized {
    private $schedulerConfig;
    private $notifications;
    
    public function __construct() {
        parent::__construct();
        
        $this->schedulerConfig = [
            'log_file' => __DIR__ . '/logs/scheduler.log',
            'lock_file' => __DIR__ . '/logs/scheduler.lock',
            'max_execution_time' => 3600, // 1 heure max
            'email_enabled' => false,
            'cloud_enabled' => false
        ];
        
        // Créer le répertoire de logs si nécessaire
        $logDir = dirname($this->schedulerConfig['log_file']);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Initialiser le système de notifications
        try {
            require_once 'backup_notification_system.php';
            $this->notifications = new BackupNotificationSystem();
        } catch (Exception $e) {
            $this->notifications = null;
            error_log("Impossible de charger le système de notifications: " . $e->getMessage());
        }
    }
    
    /**
     * Créer une nouvelle programmation de sauvegarde
     */
    public function createSchedule($data) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO t_backup_schedule 
                (name, frequency, time, day_of_week, day_of_month, is_active, backup_type, 
                 keep_backups, email_notifications, email_recipients, cloud_sync, cloud_provider) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $data['name'],
                $data['frequency'],
                $data['time'],
                $data['day_of_week'] ?? null,
                $data['day_of_month'] ?? null,
                $data['is_active'] ?? 1,
                $data['backup_type'] ?? 'full',
                $data['keep_backups'] ?? 30,
                $data['email_notifications'] ?? 0,
                $data['email_recipients'] ?? null,
                $data['cloud_sync'] ?? 0,
                $data['cloud_provider'] ?? null
            ]);
            
            if ($result) {
                $scheduleId = $this->pdo->lastInsertId();
                
                // Calculer la prochaine exécution
                $this->updateNextRun($scheduleId);
                
                return [
                    'success' => true,
                    'message' => 'Programmation créée avec succès',
                    'schedule_id' => $scheduleId
                ];
            }
            
            return ['success' => false, 'message' => 'Erreur lors de la création'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Obtenir toutes les programmations
     */
    public function getSchedules() {
        try {
            $stmt = $this->pdo->query("
                SELECT s.*, 
                       CASE 
                           WHEN s.last_run IS NULL THEN 'Jamais exécuté'
                           ELSE CONCAT('Il y a ', TIMESTAMPDIFF(HOUR, s.last_run, NOW()), ' heures')
                       END as last_run_ago,
                       CASE 
                           WHEN s.next_run IS NULL THEN 'Non programmé'
                           WHEN s.next_run < NOW() THEN 'En retard'
                           ELSE CONCAT('Dans ', TIMESTAMPDIFF(HOUR, NOW(), s.next_run), ' heures')
                       END as next_run_in
                FROM t_backup_schedule s 
                ORDER BY s.is_active DESC, s.next_run ASC
            ");
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Calculer la prochaine exécution
     */
    public function updateNextRun($scheduleId) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM t_backup_schedule WHERE id = ?");
            $stmt->execute([$scheduleId]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$schedule) return false;
            
            $nextRun = new DateTime();
            $time = explode(':', $schedule['time']);
            $nextRun->setTime((int)$time[0], (int)$time[1], 0);
            
            switch ($schedule['frequency']) {
                case 'daily':
                    if ($nextRun < new DateTime()) {
                        $nextRun->add(new DateInterval('P1D'));
                    }
                    break;
                    
                case 'weekly':
                    $dayOfWeek = $schedule['day_of_week'] ?? 1; // Lundi par défaut
                    $nextRun->modify('next ' . $this->getDayName($dayOfWeek));
                    break;
                    
                case 'monthly':
                    $dayOfMonth = $schedule['day_of_month'] ?? 1;
                    $nextRun->setDate($nextRun->format('Y'), $nextRun->format('m'), $dayOfMonth);
                    if ($nextRun < new DateTime()) {
                        $nextRun->add(new DateInterval('P1M'));
                    }
                    break;
            }
            
            $updateStmt = $this->pdo->prepare("UPDATE t_backup_schedule SET next_run = ? WHERE id = ?");
            $updateStmt->execute([$nextRun->format('Y-m-d H:i:s'), $scheduleId]);
            
            return true;
            
        } catch (Exception $e) {
            $this->logScheduler("Erreur calcul prochaine exécution: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    /**
     * Exécuter les sauvegardes programmées
     */
    public function runScheduledBackups() {
        // Vérifier le verrou
        if ($this->isLocked()) {
            $this->logScheduler("Scheduler déjà en cours d'exécution", 'WARNING');
            return false;
        }
        
        $this->createLock();
        
        try {
            $this->logScheduler("Démarrage du scheduler de sauvegardes");
            
            // Récupérer les tâches à exécuter
            $stmt = $this->pdo->prepare("
                SELECT * FROM t_backup_schedule 
                WHERE is_active = 1 
                AND next_run IS NOT NULL 
                AND next_run <= NOW()
                ORDER BY next_run ASC
            ");
            $stmt->execute();
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $executed = 0;
            $failed = 0;
            
            foreach ($schedules as $schedule) {
                $this->logScheduler("Exécution de: " . $schedule['name']);
                
                $startTime = microtime(true);
                
                // Enregistrer le début dans l'historique
                $historyId = $this->createHistoryRecord($schedule['id'], $schedule['backup_type']);
                
                try {
                    // Créer le nom de la sauvegarde
                    $backupName = $this->generateScheduledBackupName($schedule);
                    
                    // Exécuter la sauvegarde
                    $result = $this->createFullBackup($backupName);
                    
                    $duration = microtime(true) - $startTime;
                    
                    if ($result['success']) {
                        // Mettre à jour l'historique avec succès
                        $this->updateHistoryRecord($historyId, 'success', $result, $duration);
                        
                        // Nettoyer les anciennes sauvegardes si nécessaire
                        $this->cleanOldScheduledBackups($schedule['id'], $schedule['keep_backups']);
                        
                        // Envoyer notification email si activé
                        if ($schedule['email_notifications']) {
                            $this->sendEmailNotification($schedule, $result, 'success');
                        }
                        
                        // Synchroniser vers le cloud si activé
                        if ($schedule['cloud_sync']) {
                            $this->syncToCloud($schedule, $result);
                        }
                        
                        $executed++;
                        $this->logScheduler("Sauvegarde réussie: " . $result['filename']);
                        
                    } else {
                        $this->updateHistoryRecord($historyId, 'failed', null, $duration, $result['message']);
                        
                        if ($schedule['email_notifications']) {
                            $this->sendEmailNotification($schedule, null, 'failed', $result['message']);
                        }
                        
                        $failed++;
                        $this->logScheduler("Sauvegarde échouée: " . $result['message'], 'ERROR');
                    }
                    
                } catch (Exception $e) {
                    $duration = microtime(true) - $startTime;
                    $this->updateHistoryRecord($historyId, 'failed', null, $duration, $e->getMessage());
                    
                    $failed++;
                    $this->logScheduler("Exception lors de la sauvegarde: " . $e->getMessage(), 'ERROR');
                }
                
                // Mettre à jour la dernière exécution et calculer la prochaine
                $this->pdo->prepare("UPDATE t_backup_schedule SET last_run = NOW() WHERE id = ?")
                         ->execute([$schedule['id']]);
                
                $this->updateNextRun($schedule['id']);
            }
            
            $this->logScheduler("Scheduler terminé - Exécutées: $executed, Échouées: $failed");
            
            return [
                'success' => true,
                'executed' => $executed,
                'failed' => $failed,
                'total' => count($schedules)
            ];
            
        } catch (Exception $e) {
            $this->logScheduler("Erreur fatale du scheduler: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'message' => $e->getMessage()];
            
        } finally {
            $this->removeLock();
        }
    }
    
    /**
     * Obtenir les statistiques d'historique
     */
    public function getBackupStats($days = 30) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_backups,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_backups,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_backups,
                    AVG(duration) as avg_duration,
                    SUM(file_size) as total_size,
                    SUM(compressed_size) as total_compressed_size,
                    DATE(created_at) as backup_date,
                    COUNT(*) as daily_count
                FROM t_backup_history 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at)
                ORDER BY backup_date DESC
            ");
            
            $stmt->execute([$days]);
            $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Statistiques globales
            $globalStmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_backups,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_backups,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_backups,
                    AVG(duration) as avg_duration,
                    SUM(file_size) as total_size,
                    SUM(compressed_size) as total_compressed_size,
                    MAX(created_at) as last_backup
                FROM t_backup_history 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            
            $globalStmt->execute([$days]);
            $globalStats = $globalStmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'global' => $globalStats,
                'daily' => $dailyStats
            ];
            
        } catch (Exception $e) {
            return ['global' => [], 'daily' => []];
        }
    }
    
    /**
     * Enregistrer les statistiques de la base de données
     */
    public function recordDatabaseStats() {
        try {
            // Obtenir la taille de la base de données
            $stmt = $this->pdo->query("
                SELECT 
                    SUM(data_length + index_length) as total_size,
                    COUNT(*) as tables_count
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
            ");
            $dbStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Compter les vues
            $viewsStmt = $this->pdo->query("
                SELECT COUNT(*) as views_count 
                FROM information_schema.VIEWS 
                WHERE table_schema = DATABASE()
            ");
            $viewsCount = $viewsStmt->fetch(PDO::FETCH_ASSOC)['views_count'];
            
            // Trouver la plus grosse table
            $largestStmt = $this->pdo->query("
                SELECT 
                    table_name as largest_table,
                    (data_length + index_length) as largest_table_size
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
                ORDER BY (data_length + index_length) DESC 
                LIMIT 1
            ");
            $largest = $largestStmt->fetch(PDO::FETCH_ASSOC);
            
            // Compter le total des lignes (approximatif)
            $rowsStmt = $this->pdo->query("
                SELECT SUM(table_rows) as total_rows 
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
            ");
            $totalRows = $rowsStmt->fetch(PDO::FETCH_ASSOC)['total_rows'];
            
            // Insérer les statistiques
            $insertStmt = $this->pdo->prepare("
                INSERT INTO t_database_stats 
                (recorded_at, total_size, tables_count, views_count, total_rows, largest_table, largest_table_size)
                VALUES (NOW(), ?, ?, ?, ?, ?, ?)
            ");
            
            $insertStmt->execute([
                $dbStats['total_size'],
                $dbStats['tables_count'],
                $viewsCount,
                $totalRows,
                $largest['largest_table'],
                $largest['largest_table_size']
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->logScheduler("Erreur enregistrement stats DB: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    // Méthodes privées utilitaires
    private function generateScheduledBackupName($schedule) {
        $prefix = strtolower(str_replace(' ', '_', $schedule['name']));
        $prefix = preg_replace('/[^a-z0-9_]/', '', $prefix);
        return $prefix . '_' . date('Y-m-d_H-i-s');
    }
    
    private function createHistoryRecord($scheduleId, $backupType) {
        $stmt = $this->pdo->prepare("
            INSERT INTO t_backup_history 
            (schedule_id, backup_name, backup_type, file_size, status, created_by)
            VALUES (?, 'En cours...', ?, 0, 'in_progress', ?)
        ");
        
        $stmt->execute([$scheduleId, $backupType, $_SESSION['user_id'] ?? null]);
        return $this->pdo->lastInsertId();
    }
    
    private function updateHistoryRecord($historyId, $status, $result = null, $duration = 0, $errorMessage = null) {
        if ($result && $status === 'success') {
            $stmt = $this->pdo->prepare("
                UPDATE t_backup_history SET 
                    backup_name = ?, file_size = ?, compressed_size = ?,
                    tables_count = ?, views_count = ?, rows_count = ?,
                    duration = ?, status = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $result['filename'],
                $result['size'],
                $result['compressed_size'] ?? 0,
                $result['tables'],
                $result['views'],
                $result['total_rows'],
                $duration,
                $status,
                $historyId
            ]);
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE t_backup_history SET 
                    status = ?, duration = ?, error_message = ?
                WHERE id = ?
            ");
            
            $stmt->execute([$status, $duration, $errorMessage, $historyId]);
        }
    }
    
    private function getDayName($dayNumber) {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        return $days[$dayNumber - 1] ?? 'monday';
    }
    
    private function isLocked() {
        return file_exists($this->schedulerConfig['lock_file']);
    }
    
    private function createLock() {
        file_put_contents($this->schedulerConfig['lock_file'], getmypid());
    }
    
    private function removeLock() {
        if (file_exists($this->schedulerConfig['lock_file'])) {
            unlink($this->schedulerConfig['lock_file']);
        }
    }
    
    private function logScheduler($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message\n";
        
        file_put_contents($this->schedulerConfig['log_file'], $logMessage, FILE_APPEND | LOCK_EX);
        
        if (php_sapi_name() === 'cli') {
            echo $logMessage;
        }
    }
    
    private function sendEmailNotification($schedule, $result, $status, $errorMessage = null) {
        if (!$this->notifications) {
            $this->logScheduler("Système de notifications non disponible", 'WARNING');
            return;
        }
        
        try {
            $recipients = !empty($schedule['email_recipients']) ? 
                explode(',', $schedule['email_recipients']) : null;
            
            if ($status === 'success' && $result) {
                $sent = $this->notifications->sendBackupSuccessNotification($result, $recipients);
                $this->logScheduler("Notification de succès envoyée: " . json_encode($sent));
            } elseif ($status === 'failed') {
                $error = $errorMessage ?: "Erreur inconnue lors de la sauvegarde programmée";
                $sent = $this->notifications->sendBackupFailureNotification($error, $recipients);
                $this->logScheduler("Notification d'échec envoyée: " . json_encode($sent));
            }
        } catch (Exception $e) {
            $this->logScheduler("Erreur envoi notification: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function syncToCloud($schedule, $result) {
        // TODO: Implémenter la synchronisation cloud
        $this->logScheduler("Cloud sync pour " . $result['filename']);
    }
    
    private function cleanOldScheduledBackups($scheduleId, $keepBackups) {
        // TODO: Nettoyer les anciennes sauvegardes programmées
        $this->logScheduler("Nettoyage anciennes sauvegardes (garder $keepBackups)");
    }
}

// Test CLI si exécuté directement
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    echo "=== Test du système de sauvegarde programmée ===\n";
    
    try {
        $backup = new AdvancedBackupSystem();
        
        // Enregistrer les stats de la DB
        echo "📊 Enregistrement des statistiques DB...\n";
        $backup->recordDatabaseStats();
        
        // Exécuter les sauvegardes programmées
        echo "⏰ Exécution des sauvegardes programmées...\n";
        $result = $backup->runScheduledBackups();
        
        if ($result['success']) {
            echo "✅ Scheduler exécuté avec succès\n";
            echo "   - Exécutées: " . $result['executed'] . "\n";
            echo "   - Échouées: " . $result['failed'] . "\n";
            echo "   - Total: " . $result['total'] . "\n";
        } else {
            echo "❌ Erreur: " . $result['message'] . "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Exception: " . $e->getMessage() . "\n";
    }
}
?>