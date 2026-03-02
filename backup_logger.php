<?php
/**
 * Système de logging centralisé pour les sauvegardes
 * Gestion des logs avec rotation et différents niveaux
 */

class BackupLogger {
    
    private $logFile;
    private $level;
    private $config;
    
    const LEVELS = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];
    
    public function __construct($logFile = null, $config = null) {
        require_once __DIR__ . '/backup_config.php';
        
        $this->config = $config ?: BackupConfig::getConfig()['logging'];
        $this->logFile = $logFile ?: __DIR__ . '/logs/backup_system.log';
        $this->level = $this->config['level'] ?? 'INFO';
        
        // Créer le répertoire de logs si nécessaire
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Logger un message avec niveau DEBUG
     */
    public function debug($message, $context = []) {
        $this->log('DEBUG', $message, $context);
    }
    
    /**
     * Logger un message avec niveau INFO
     */
    public function info($message, $context = []) {
        $this->log('INFO', $message, $context);
    }
    
    /**
     * Logger un message avec niveau WARNING
     */
    public function warning($message, $context = []) {
        $this->log('WARNING', $message, $context);
    }
    
    /**
     * Logger un message avec niveau ERROR
     */
    public function error($message, $context = []) {
        $this->log('ERROR', $message, $context);
    }
    
    /**
     * Logger un message avec niveau CRITICAL
     */
    public function critical($message, $context = []) {
        $this->log('CRITICAL', $message, $context);
    }
    
    /**
     * Logger un message avec un niveau spécifique
     */
    public function log($level, $message, $context = []) {
        // Vérifier si le niveau est suffisant pour être loggé
        if (!$this->shouldLog($level)) {
            return;
        }
        
        // Effectuer la rotation si nécessaire
        $this->rotateLogIfNeeded();
        
        // Formater le message
        $formattedMessage = $this->formatMessage($level, $message, $context);
        
        // Écrire dans le fichier
        file_put_contents($this->logFile, $formattedMessage . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        // Envoyer une notification si c'est une erreur critique
        if ($level === 'CRITICAL' || $level === 'ERROR') {
            $this->handleCriticalMessage($level, $message, $context);
        }
    }
    
    /**
     * Vérifier si un message doit être loggé selon le niveau configuré
     */
    private function shouldLog($level) {
        $currentLevelValue = self::LEVELS[$this->level] ?? 1;
        $messageLevelValue = self::LEVELS[$level] ?? 1;
        
        return $messageLevelValue >= $currentLevelValue;
    }
    
    /**
     * Formater un message de log
     */
    private function formatMessage($level, $message, $context) {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        
        $format = $this->config['log_format'] ?? '[{date}] [{level}] {message}';
        
        $formatted = str_replace(
            ['{date}', '{level}', '{message}'],
            [$timestamp, $level, $message],
            $format
        );
        
        if ($this->config['include_trace'] && ($level === 'ERROR' || $level === 'CRITICAL')) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $traceStr = $this->formatTrace($trace);
            $formatted .= "\nTrace: " . $traceStr;
        }
        
        return $formatted . $contextStr;
    }
    
    /**
     * Formater une stack trace
     */
    private function formatTrace($trace) {
        $traceLines = [];
        foreach ($trace as $i => $frame) {
            if ($i === 0) continue; // Ignorer cette fonction
            
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? 'unknown';
            $class = isset($frame['class']) ? $frame['class'] . '::' : '';
            
            $traceLines[] = sprintf(
                "#%d %s(%d): %s%s()",
                $i - 1,
                basename($file),
                $line,
                $class,
                $function
            );
        }
        
        return implode(' -> ', array_slice($traceLines, 0, 3));
    }
    
    /**
     * Effectuer la rotation des logs si nécessaire
     */
    private function rotateLogIfNeeded() {
        if (!$this->config['rotate_logs'] || !file_exists($this->logFile)) {
            return;
        }
        
        $maxSize = $this->config['max_file_size'] ?? 5 * 1024 * 1024; // 5 MB par défaut
        
        if (filesize($this->logFile) > $maxSize) {
            $this->rotateLogs();
        }
    }
    
    /**
     * Effectuer la rotation des fichiers de logs
     */
    private function rotateLogs() {
        $keepFiles = $this->config['keep_log_files'] ?? 10;
        $logDir = dirname($this->logFile);
        $logName = basename($this->logFile, '.log');
        
        // Déplacer les logs existants
        for ($i = $keepFiles - 1; $i >= 1; $i--) {
            $oldFile = "$logDir/{$logName}.{$i}.log";
            $newFile = "$logDir/{$logName}." . ($i + 1) . ".log";
            
            if (file_exists($oldFile)) {
                if ($i === $keepFiles - 1) {
                    unlink($oldFile); // Supprimer le plus ancien
                } else {
                    rename($oldFile, $newFile);
                }
            }
        }
        
        // Renommer le fichier actuel
        if (file_exists($this->logFile)) {
            rename($this->logFile, "$logDir/{$logName}.1.log");
        }
    }
    
    /**
     * Gérer les messages critiques
     */
    private function handleCriticalMessage($level, $message, $context) {
        // Ici on pourrait envoyer des notifications, alertes, etc.
        // Pour l'instant, on se contente de logger
        
        // Si les notifications email sont activées
        require_once __DIR__ . '/backup_config.php';
        $notifConfig = BackupConfig::getNotificationConfig();
        
        if ($notifConfig['email_enabled'] && $notifConfig['notify_on_error']) {
            $this->sendErrorNotification($level, $message, $context);
        }
    }
    
    /**
     * Envoyer une notification d'erreur par email
     */
    private function sendErrorNotification($level, $message, $context) {
        require_once __DIR__ . '/backup_config.php';
        $notifConfig = BackupConfig::getNotificationConfig();
        
        if (!$notifConfig['email_address']) {
            return;
        }
        
        $subject = "[$level] Acadenique Backup System Alert";
        $body = "Une erreur " . strtolower($level) . " s'est produite dans le système de sauvegarde:\n\n";
        $body .= "Message: $message\n";
        $body .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $body .= "Serveur: " . php_uname('n') . "\n";
        
        if (!empty($context)) {
            $body .= "Contexte: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
        }
        
        $body .= "\nVeuillez vérifier les logs pour plus de détails.";
        
        mail($notifConfig['email_address'], $subject, $body);
    }
    
    /**
     * Obtenir les logs récents
     */
    public function getRecentLogs($lines = 100) {
        if (!file_exists($this->logFile)) {
            return [];
        }
        
        $logs = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_slice(array_reverse($logs), 0, $lines);
    }
    
    /**
     * Obtenir les logs par niveau
     */
    public function getLogsByLevel($level, $lines = 50) {
        $allLogs = $this->getRecentLogs(500); // Prendre plus de logs pour filtrer
        $filteredLogs = [];
        
        foreach ($allLogs as $log) {
            if (strpos($log, "[$level]") !== false) {
                $filteredLogs[] = $log;
                if (count($filteredLogs) >= $lines) {
                    break;
                }
            }
        }
        
        return $filteredLogs;
    }
    
    /**
     * Obtenir les statistiques des logs
     */
    public function getLogStats($days = 7) {
        if (!file_exists($this->logFile)) {
            return [
                'total' => 0,
                'by_level' => [],
                'by_date' => [],
                'file_size' => 0
            ];
        }
        
        $logs = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $stats = [
            'total' => count($logs),
            'by_level' => [],
            'by_date' => [],
            'file_size' => filesize($this->logFile)
        ];
        
        $cutoffDate = date('Y-m-d', strtotime("-$days days"));
        
        foreach ($logs as $log) {
            // Extraire le niveau
            if (preg_match('/\[([^\]]+)\] \[([^\]]+)\]/', $log, $matches)) {
                $date = $matches[1];
                $level = $matches[2];
                
                // Filtrer par date si nécessaire
                if ($date >= $cutoffDate) {
                    $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
                    
                    $dateOnly = substr($date, 0, 10);
                    $stats['by_date'][$dateOnly] = ($stats['by_date'][$dateOnly] ?? 0) + 1;
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Nettoyer les anciens logs
     */
    public function cleanOldLogs($days = 30) {
        $logDir = dirname($this->logFile);
        $pattern = $logDir . '/*.log';
        $files = glob($pattern);
        $cutoffTime = time() - ($days * 24 * 60 * 60);
        $deletedFiles = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $deletedFiles++;
                    $this->info("Ancien fichier de log supprimé: " . basename($file));
                }
            }
        }
        
        return $deletedFiles;
    }
    
    /**
     * Exporter les logs en format CSV
     */
    public function exportLogsToCSV($outputFile, $days = 30) {
        $logs = $this->getRecentLogs(10000); // Prendre beaucoup de logs
        $cutoffDate = date('Y-m-d', strtotime("-$days days"));
        
        $csvData = [];
        $csvData[] = ['Date', 'Niveau', 'Message']; // En-têtes
        
        foreach ($logs as $log) {
            if (preg_match('/\[([^\]]+)\] \[([^\]]+)\] (.+)/', $log, $matches)) {
                $date = $matches[1];
                $level = $matches[2];
                $message = $matches[3];
                
                if ($date >= $cutoffDate) {
                    $csvData[] = [$date, $level, $message];
                }
            }
        }
        
        $fp = fopen($outputFile, 'w');
        foreach ($csvData as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
        
        return count($csvData) - 1; // -1 pour les en-têtes
    }
    
    /**
     * Vérifier l'état de santé du système de logs
     */
    public function healthCheck() {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'warnings' => []
        ];
        
        // Vérifier l'espace disque
        $logDir = dirname($this->logFile);
        $freeSpace = disk_free_space($logDir);
        if ($freeSpace < 100 * 1024 * 1024) { // 100 MB
            $health['issues'][] = 'Espace disque faible pour les logs';
            $health['status'] = 'warning';
        }
        
        // Vérifier la taille du fichier de log
        if (file_exists($this->logFile)) {
            $logSize = filesize($this->logFile);
            $maxSize = $this->config['max_file_size'];
            
            if ($logSize > $maxSize * 0.9) {
                $health['warnings'][] = 'Fichier de log proche de la taille maximale';
            }
        }
        
        // Vérifier les permissions
        if (!is_writable(dirname($this->logFile))) {
            $health['issues'][] = 'Répertoire de logs non accessible en écriture';
            $health['status'] = 'error';
        }
        
        return $health;
    }
}

// Utilisation simple pour les autres scripts
function logBackup($message, $level = 'INFO', $context = []) {
    static $logger = null;
    if ($logger === null) {
        $logger = new BackupLogger();
    }
    $logger->log($level, $message, $context);
}
?>