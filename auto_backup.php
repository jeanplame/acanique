<?php
/**
 * Système de sauvegarde automatique pour Acadenique
 * À exécuter via cron (Linux/Mac) ou Planificateur de tâches (Windows)
 */

require_once __DIR__ . '/backup_system.php';

class AutoBackup {
    private $backupSystem;
    private $config;
    
    public function __construct() {
        $this->backupSystem = new BackupSystem();
        
        // Configuration des sauvegardes automatiques
        $this->config = [
            'daily_backup' => true,        // Sauvegarde quotidienne
            'weekly_backup' => true,       // Sauvegarde hebdomadaire
            'monthly_backup' => true,      // Sauvegarde mensuelle
            'critical_tables' => [         // Tables critiques à sauvegarder quotidiennement
                't_utilisateur',
                't_cote',
                't_etudiant',
                't_inscription',
                't_logs_connexion',
                't_configuration'
            ],
            'notification_email' => null,  // Email pour notifications (optionnel)
            'max_execution_time' => 300    // Temps maximum d'exécution en secondes
        ];
        
        // Configurer le temps d'exécution
        set_time_limit($this->config['max_execution_time']);
    }
    
    /**
     * Point d'entrée principal pour les sauvegardes automatiques
     */
    public function run() {
        $this->log("Début du processus de sauvegarde automatique");
        
        $today = date('Y-m-d');
        $dayOfWeek = date('w'); // 0 = Dimanche, 1 = Lundi, etc.
        $dayOfMonth = date('j');
        
        $backupsCreated = [];
        $errors = [];
        
        try {
            // Sauvegarde quotidienne (tables critiques)
            if ($this->config['daily_backup']) {
                $result = $this->createDailyBackup();
                if ($result['success']) {
                    $backupsCreated[] = $result;
                    $this->log("Sauvegarde quotidienne créée avec succès");
                } else {
                    $errors[] = "Sauvegarde quotidienne: " . $result['error'];
                    $this->log("Erreur sauvegarde quotidienne: " . $result['error'], 'ERROR');
                }
            }
            
            // Sauvegarde hebdomadaire (complète) - Le dimanche
            if ($this->config['weekly_backup'] && $dayOfWeek == 0) {
                $result = $this->createWeeklyBackup();
                if ($result['success']) {
                    $backupsCreated[] = $result;
                    $this->log("Sauvegarde hebdomadaire créée avec succès");
                } else {
                    $errors[] = "Sauvegarde hebdomadaire: " . $result['error'];
                    $this->log("Erreur sauvegarde hebdomadaire: " . $result['error'], 'ERROR');
                }
            }
            
            // Sauvegarde mensuelle (archive) - Le 1er du mois
            if ($this->config['monthly_backup'] && $dayOfMonth == 1) {
                $result = $this->createMonthlyBackup();
                if ($result['success']) {
                    $backupsCreated[] = $result;
                    $this->log("Sauvegarde mensuelle créée avec succès");
                } else {
                    $errors[] = "Sauvegarde mensuelle: " . $result['error'];
                    $this->log("Erreur sauvegarde mensuelle: " . $result['error'], 'ERROR');
                }
            }
            
            // Vérification de l'espace disque
            $this->checkDiskSpace();
            
            // Nettoyage automatique
            $this->performMaintenance();
            
            // Envoyer les notifications si configurées
            if (!empty($backupsCreated) || !empty($errors)) {
                $this->sendNotification($backupsCreated, $errors);
            }
            
            $this->log("Processus de sauvegarde automatique terminé avec succès");
            
        } catch (Exception $e) {
            $this->log("Erreur critique dans le processus de sauvegarde: " . $e->getMessage(), 'CRITICAL');
            $this->sendErrorNotification($e->getMessage());
        }
    }
    
    /**
     * Créer la sauvegarde quotidienne (tables critiques)
     */
    private function createDailyBackup() {
        $name = 'auto_daily_' . date('Y-m-d');
        return $this->backupSystem->createPartialBackup($this->config['critical_tables'], $name);
    }
    
    /**
     * Créer la sauvegarde hebdomadaire (complète)
     */
    private function createWeeklyBackup() {
        $name = 'auto_weekly_' . date('Y-W');
        return $this->backupSystem->createFullBackup($name);
    }
    
    /**
     * Créer la sauvegarde mensuelle (archive)
     */
    private function createMonthlyBackup() {
        $name = 'auto_monthly_' . date('Y-m');
        return $this->backupSystem->createFullBackup($name);
    }
    
    /**
     * Vérifier l'espace disque disponible
     */
    private function checkDiskSpace() {
        $diskInfo = $this->backupSystem->getDiskInfo();
        $freeSpaceGB = $diskInfo['free_space'] / (1024 * 1024 * 1024);
        
        if ($freeSpaceGB < 1) { // Moins de 1 GB libre
            $this->log("Attention: Espace disque faible (" . round($freeSpaceGB, 2) . " GB)", 'WARNING');
        }
        
        // Calculer le pourcentage d'utilisation
        $usedPercentage = (($diskInfo['total_space'] - $diskInfo['free_space']) / $diskInfo['total_space']) * 100;
        
        if ($usedPercentage > 90) {
            $this->log("Attention: Disque utilisé à " . round($usedPercentage, 1) . "%", 'WARNING');
        }
    }
    
    /**
     * Effectuer la maintenance automatique
     */
    private function performMaintenance() {
        // Nettoyer les sauvegardes quotidiennes anciennes (garder 7 jours)
        $this->cleanOldBackups('auto_daily_', 7);
        
        // Nettoyer les sauvegardes hebdomadaires anciennes (garder 8 semaines)
        $this->cleanOldBackups('auto_weekly_', 8);
        
        // Nettoyer les sauvegardes mensuelles anciennes (garder 12 mois)
        $this->cleanOldBackups('auto_monthly_', 12);
        
        $this->log("Maintenance automatique effectuée");
    }
    
    /**
     * Nettoyer les anciennes sauvegardes par type
     */
    private function cleanOldBackups($prefix, $keepCount) {
        $backupPath = __DIR__ . '/backups/';
        $pattern = $backupPath . $prefix . '*.{sql,sql.gz}';
        $files = glob($pattern, GLOB_BRACE);
        
        // Trier par date de modification (plus récent en premier)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // Supprimer les fichiers excédentaires
        $filesToDelete = array_slice($files, $keepCount);
        foreach ($filesToDelete as $file) {
            if (unlink($file)) {
                $this->log("Sauvegarde automatique supprimée: " . basename($file));
            }
        }
    }
    
    /**
     * Envoyer une notification de succès/erreur
     */
    private function sendNotification($backupsCreated, $errors) {
        if (!$this->config['notification_email']) {
            return;
        }
        
        $subject = "Rapport de sauvegarde automatique - " . date('Y-m-d');
        $message = $this->generateNotificationMessage($backupsCreated, $errors);
        
        // Utiliser mail() PHP ou une librairie comme PHPMailer selon vos préférences
        mail($this->config['notification_email'], $subject, $message);
    }
    
    /**
     * Envoyer une notification d'erreur critique
     */
    private function sendErrorNotification($error) {
        if (!$this->config['notification_email']) {
            return;
        }
        
        $subject = "ERREUR CRITIQUE - Sauvegarde automatique - " . date('Y-m-d');
        $message = "Une erreur critique s'est produite lors de la sauvegarde automatique:\n\n";
        $message .= $error . "\n\n";
        $message .= "Veuillez vérifier le système de sauvegarde immédiatement.\n";
        $message .= "Serveur: " . php_uname('n') . "\n";
        $message .= "Date/Heure: " . date('Y-m-d H:i:s');
        
        mail($this->config['notification_email'], $subject, $message);
    }
    
    /**
     * Générer le message de notification
     */
    private function generateNotificationMessage($backupsCreated, $errors) {
        $message = "Rapport de sauvegarde automatique\n";
        $message .= "=====================================\n\n";
        $message .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $message .= "Serveur: " . php_uname('n') . "\n\n";
        
        if (!empty($backupsCreated)) {
            $message .= "SAUVEGARDES CRÉÉES AVEC SUCCÈS:\n";
            $message .= "-------------------------------\n";
            foreach ($backupsCreated as $backup) {
                $message .= "- " . basename($backup['filename']) . "\n";
                $message .= "  Taille: " . $this->backupSystem->formatBytes($backup['size']) . "\n";
                $message .= "  Tables: " . $backup['tables_count'] . "\n\n";
            }
        }
        
        if (!empty($errors)) {
            $message .= "ERREURS RENCONTRÉES:\n";
            $message .= "-------------------\n";
            foreach ($errors as $error) {
                $message .= "- " . $error . "\n";
            }
            $message .= "\n";
        }
        
        // Informations sur l'espace disque
        $diskInfo = $this->backupSystem->getDiskInfo();
        $message .= "INFORMATIONS DISQUE:\n";
        $message .= "-------------------\n";
        $message .= "Espace libre: " . $this->backupSystem->formatBytes($diskInfo['free_space']) . "\n";
        $message .= "Taille des sauvegardes: " . $this->backupSystem->formatBytes($diskInfo['backup_size']) . "\n";
        
        return $message;
    }
    
    /**
     * Logger les événements
     */
    private function log($message, $level = 'INFO') {
        $logFile = __DIR__ . '/logs/auto_backup.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Tester la configuration des sauvegardes automatiques
     */
    public function testConfiguration() {
        echo "Test de la configuration des sauvegardes automatiques\n";
        echo "=====================================================\n\n";
        
        // Tester la création des répertoires
        $backupDir = __DIR__ . '/backups/';
        $logDir = __DIR__ . '/logs/';
        
        echo "Vérification des répertoires:\n";
        echo "- Backups: " . ($this->checkDirectory($backupDir) ? "✓" : "✗") . " $backupDir\n";
        echo "- Logs: " . ($this->checkDirectory($logDir) ? "✓" : "✗") . " $logDir\n\n";
        
        // Tester la connexion à la base de données
        echo "Test de connexion à la base de données:\n";
        try {
            $tables = $this->backupSystem->getAllTables();
            echo "✓ Connexion réussie (" . count($tables) . " tables trouvées)\n\n";
        } catch (Exception $e) {
            echo "✗ Erreur de connexion: " . $e->getMessage() . "\n\n";
        }
        
        // Tester la création d'une sauvegarde de test
        echo "Test de création de sauvegarde:\n";
        $result = $this->backupSystem->createPartialBackup(['t_configuration'], 'test_auto_backup');
        if ($result['success']) {
            echo "✓ Sauvegarde de test créée avec succès\n";
            echo "  Fichier: " . basename($result['filename']) . "\n";
            echo "  Taille: " . $this->backupSystem->formatBytes($result['size']) . "\n";
            
            // Supprimer la sauvegarde de test
            unlink($result['filename']);
            echo "  (Sauvegarde de test supprimée)\n\n";
        } else {
            echo "✗ Erreur lors de la création de la sauvegarde de test: " . $result['error'] . "\n\n";
        }
        
        // Afficher la configuration actuelle
        echo "Configuration actuelle:\n";
        echo "- Sauvegarde quotidienne: " . ($this->config['daily_backup'] ? "Activée" : "Désactivée") . "\n";
        echo "- Sauvegarde hebdomadaire: " . ($this->config['weekly_backup'] ? "Activée" : "Désactivée") . "\n";
        echo "- Sauvegarde mensuelle: " . ($this->config['monthly_backup'] ? "Activée" : "Désactivée") . "\n";
        echo "- Tables critiques: " . implode(', ', $this->config['critical_tables']) . "\n";
        echo "- Email de notification: " . ($this->config['notification_email'] ?: "Non configuré") . "\n\n";
        
        echo "Test terminé.\n";
    }
    
    /**
     * Vérifier et créer un répertoire si nécessaire
     */
    private function checkDirectory($dir) {
        if (!is_dir($dir)) {
            return mkdir($dir, 0755, true);
        }
        return is_writable($dir);
    }
}

// Exécution du script
if (php_sapi_name() === 'cli') {
    $autoBackup = new AutoBackup();
    
    // Vérifier les arguments de ligne de commande
    $options = getopt('th', ['test', 'help']);
    
    if (isset($options['h']) || isset($options['help'])) {
        echo "Utilisation: php auto_backup.php [options]\n";
        echo "Options:\n";
        echo "  -t, --test     Tester la configuration\n";
        echo "  -h, --help     Afficher cette aide\n\n";
        echo "Configuration pour tâches automatiques:\n";
        echo "Linux/Mac (crontab -e):\n";
        echo "0 2 * * * /usr/bin/php " . __FILE__ . " > /dev/null 2>&1\n\n";
        echo "Windows (Planificateur de tâches):\n";
        echo "Programme: php.exe\n";
        echo "Arguments: \"" . __FILE__ . "\"\n";
        echo "Planification: Quotidienne à 02:00\n";
        exit(0);
    }
    
    if (isset($options['t']) || isset($options['test'])) {
        $autoBackup->testConfiguration();
    } else {
        $autoBackup->run();
    }
} else {
    // Mode web (pour tests)
    if (isset($_GET['test'])) {
        $autoBackup = new AutoBackup();
        echo "<pre>";
        $autoBackup->testConfiguration();
        echo "</pre>";
    } else {
        echo "Ce script doit être exécuté en ligne de commande ou avec ?test pour les tests.";
    }
}
?>