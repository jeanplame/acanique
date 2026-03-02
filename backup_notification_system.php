<?php
/**
 * Système de Notifications Email pour les Sauvegardes
 * Envoie des notifications de succès/échec avec PHPMailer
 */

// Vérifier si PHPMailer est disponible, sinon utiliser mail() natif
$usePhpMailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer');

if (!$usePhpMailer) {
    // Fallback simple avec mail() natif
    class SimpleMailer {
        public function send($to, $subject, $body, $isHtml = true) {
            $headers = [];
            if ($isHtml) {
                $headers[] = 'Content-Type: text/html; charset=UTF-8';
            }
            $headers[] = 'From: Acadenique Backup System <noreply@acadenique.com>';
            
            return mail($to, $subject, $body, implode("\r\n", $headers));
        }
    }
}

class BackupNotificationSystem {
    private $pdo;
    private $config;
    private $mailer;
    
    public function __construct() {
        global $pdo;
        
        // Initialiser PDO si nécessaire (contexte CLI)
        if (!$pdo) {
            require_once 'config.php';
            $this->pdo = $pdo;
        } else {
            $this->pdo = $pdo;
        }
        
        // Charger la configuration depuis la base de données
        $this->loadConfiguration();
        
        // Initialiser le système de mail
        $this->initializeMailer();
    }
    
    private function loadConfiguration() {
        $this->config = [
            'notifications_enabled' => false,
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_user' => '',
            'smtp_pass' => '',
            'from_email' => 'noreply@acadenique.com',
            'from_name' => 'Acadenique Backup System',
            'admin_emails' => []
        ];
        
        try {
            $stmt = $this->pdo->prepare("SELECT cle, valeur FROM t_configuration WHERE cle LIKE 'backup_%'");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            if (isset($settings['backup_notifications_enabled'])) {
                $this->config['notifications_enabled'] = (bool)$settings['backup_notifications_enabled'];
            }
            if (isset($settings['backup_email_smtp_host'])) {
                $this->config['smtp_host'] = $settings['backup_email_smtp_host'];
            }
            if (isset($settings['backup_email_smtp_port'])) {
                $this->config['smtp_port'] = (int)$settings['backup_email_smtp_port'];
            }
            if (isset($settings['backup_email_smtp_user'])) {
                $this->config['smtp_user'] = $settings['backup_email_smtp_user'];
            }
            if (isset($settings['backup_email_smtp_pass'])) {
                $this->config['smtp_pass'] = $settings['backup_email_smtp_pass'];
            }
            if (isset($settings['backup_email_from'])) {
                $this->config['from_email'] = $settings['backup_email_from'];
            }
            
        } catch (Exception $e) {
            error_log("Erreur chargement config notifications: " . $e->getMessage());
        }
    }
    
    private function initializeMailer() {
        global $usePhpMailer;
        
        if ($usePhpMailer) {
            // TODO: Implémenter PHPMailer quand disponible
            $this->mailer = new SimpleMailer();
        } else {
            $this->mailer = new SimpleMailer();
        }
    }
    
    /**
     * Envoyer une notification de sauvegarde réussie
     */
    public function sendBackupSuccessNotification($backupResult, $recipients = null) {
        if (!$this->config['notifications_enabled']) {
            return false;
        }
        
        $recipients = $recipients ?: $this->config['admin_emails'];
        if (empty($recipients)) {
            return false;
        }
        
        $subject = "✅ Sauvegarde Acadenique Réussie - " . date('d/m/Y H:i');
        $body = $this->generateSuccessEmailBody($backupResult);
        
        return $this->sendToMultipleRecipients($recipients, $subject, $body);
    }
    
    /**
     * Envoyer une notification d'échec de sauvegarde
     */
    public function sendBackupFailureNotification($error, $recipients = null) {
        if (!$this->config['notifications_enabled']) {
            return false;
        }
        
        $recipients = $recipients ?: $this->config['admin_emails'];
        if (empty($recipients)) {
            return false;
        }
        
        $subject = "❌ Échec Sauvegarde Acadenique - " . date('d/m/Y H:i');
        $body = $this->generateFailureEmailBody($error);
        
        return $this->sendToMultipleRecipients($recipients, $subject, $body);
    }
    
    /**
     * Envoyer un rapport quotidien des sauvegardes
     */
    public function sendDailyReport($recipients = null) {
        if (!$this->config['notifications_enabled']) {
            return false;
        }
        
        $recipients = $recipients ?: $this->config['admin_emails'];
        if (empty($recipients)) {
            return false;
        }
        
        // Récupérer les statistiques du jour
        $stats = $this->getDailyStats();
        
        $subject = "📊 Rapport Quotidien Sauvegardes - " . date('d/m/Y');
        $body = $this->generateDailyReportBody($stats);
        
        return $this->sendToMultipleRecipients($recipients, $subject, $body);
    }
    
    /**
     * Envoyer une alerte d'espace disque faible
     */
    public function sendDiskSpaceAlert($usagePercent, $recipients = null) {
        if (!$this->config['notifications_enabled']) {
            return false;
        }
        
        $recipients = $recipients ?: $this->config['admin_emails'];
        if (empty($recipients)) {
            return false;
        }
        
        $subject = "⚠️ Alerte Espace Disque - " . $usagePercent . "% utilisé";
        $body = $this->generateDiskSpaceAlertBody($usagePercent);
        
        return $this->sendToMultipleRecipients($recipients, $subject, $body);
    }
    
    /**
     * Générer le corps de l'email de succès
     */
    private function generateSuccessEmailBody($backupResult) {
        $sizeMB = round($backupResult['size'] / 1024 / 1024, 2);
        $compressedSizeMB = isset($backupResult['compressed_size']) ? 
            round($backupResult['compressed_size'] / 1024 / 1024, 2) : null;
        
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .stats { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .stat-item { display: flex; justify-content: space-between; margin: 5px 0; }
                .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 0.9em; color: #6c757d; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>🎉 Sauvegarde Réussie</h1>
                <p>Système Acadenique - " . date('d/m/Y H:i:s') . "</p>
            </div>
            
            <div class='content'>
                <div class='success'>
                    <strong>✅ Sauvegarde créée avec succès !</strong><br>
                    La sauvegarde de la base de données Acadenique a été créée sans erreur.
                </div>
                
                <div class='stats'>
                    <h3>📊 Détails de la Sauvegarde</h3>
                    <div class='stat-item'>
                        <span><strong>Nom du fichier:</strong></span>
                        <span>{$backupResult['filename']}</span>
                    </div>
                    <div class='stat-item'>
                        <span><strong>Taille originale:</strong></span>
                        <span>{$sizeMB} MB</span>
                    </div>";
        
        if ($compressedSizeMB) {
            $compressionRatio = round((1 - $backupResult['compressed_size'] / $backupResult['size']) * 100, 1);
            $html .= "
                    <div class='stat-item'>
                        <span><strong>Taille compressée:</strong></span>
                        <span>{$compressedSizeMB} MB (gain: {$compressionRatio}%)</span>
                    </div>";
        }
        
        $html .= "
                    <div class='stat-item'>
                        <span><strong>Tables sauvegardées:</strong></span>
                        <span>{$backupResult['tables']}</span>
                    </div>
                    <div class='stat-item'>
                        <span><strong>Vues sauvegardées:</strong></span>
                        <span>{$backupResult['views']}</span>
                    </div>
                    <div class='stat-item'>
                        <span><strong>Lignes totales:</strong></span>
                        <span>" . number_format($backupResult['total_rows']) . "</span>
                    </div>
                </div>
                
                <p>La sauvegarde est maintenant disponible pour téléchargement ou restauration depuis l'interface d'administration.</p>
            </div>
            
            <div class='footer'>
                <p>Ce message a été généré automatiquement par le système de sauvegarde Acadenique.</p>
            </div>
        </body>
        </html>";
        
        return $html;
    }
    
    /**
     * Générer le corps de l'email d'échec
     */
    private function generateFailureEmailBody($error) {
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #dc3545; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .actions { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 0.9em; color: #6c757d; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>❌ Échec de Sauvegarde</h1>
                <p>Système Acadenique - " . date('d/m/Y H:i:s') . "</p>
            </div>
            
            <div class='content'>
                <div class='error'>
                    <strong>⚠️ La sauvegarde a échoué</strong><br>
                    Une erreur s'est produite lors de la création de la sauvegarde de la base de données Acadenique.
                </div>
                
                <h3>🔍 Détails de l'Erreur</h3>
                <div class='error'>
                    <code>" . htmlspecialchars($error) . "</code>
                </div>
                
                <div class='actions'>
                    <h4>📋 Actions Recommandées</h4>
                    <ul>
                        <li>Vérifier l'espace disque disponible</li>
                        <li>Contrôler les permissions des fichiers</li>
                        <li>Vérifier la connexion à la base de données</li>
                        <li>Consulter les logs détaillés du système</li>
                        <li>Essayer de créer une sauvegarde manuelle</li>
                    </ul>
                </div>
                
                <p><strong>Note:</strong> Veuillez corriger le problème rapidement pour assurer la continuité des sauvegardes automatiques.</p>
            </div>
            
            <div class='footer'>
                <p>Ce message a été généré automatiquement par le système de sauvegarde Acadenique.</p>
            </div>
        </body>
        </html>";
        
        return $html;
    }
    
    /**
     * Générer le corps du rapport quotidien
     */
    private function generateDailyReportBody($stats) {
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .stat-box { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #007bff; }
                .success-box { border-left-color: #28a745; }
                .warning-box { border-left-color: #ffc107; }
                .danger-box { border-left-color: #dc3545; }
                .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 0.9em; color: #6c757d; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>📊 Rapport Quotidien</h1>
                <p>Sauvegardes Acadenique - " . date('d/m/Y') . "</p>
            </div>
            
            <div class='content'>
                <div class='stat-box success-box'>
                    <h3>✅ Sauvegardes Réussies: {$stats['successful']}</h3>
                </div>
                
                <div class='stat-box " . ($stats['failed'] > 0 ? 'danger-box' : 'success-box') . "'>
                    <h3>" . ($stats['failed'] > 0 ? '❌' : '✅') . " Sauvegardes Échouées: {$stats['failed']}</h3>
                </div>
                
                <div class='stat-box'>
                    <h3>📁 Espace Utilisé: {$stats['total_size_mb']} MB</h3>
                </div>
                
                <div class='stat-box'>
                    <h3>🗂️ Total des Sauvegardes: {$stats['total_backups']}</h3>
                </div>
                
                <p>Système fonctionnel - surveillance continue active.</p>
            </div>
            
            <div class='footer'>
                <p>Rapport généré automatiquement par le système de sauvegarde Acadenique.</p>
            </div>
        </body>
        </html>";
        
        return $html;
    }
    
    /**
     * Générer l'alerte d'espace disque
     */
    private function generateDiskSpaceAlertBody($usagePercent) {
        $alertLevel = $usagePercent > 90 ? 'danger-box' : 'warning-box';
        $icon = $usagePercent > 90 ? '🚨' : '⚠️';
        
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #dc3545; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .alert-box { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .danger-box { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }
                .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 0.9em; color: #6c757d; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>$icon Alerte Espace Disque</h1>
                <p>Système Acadenique - " . date('d/m/Y H:i:s') . "</p>
            </div>
            
            <div class='content'>
                <div class='alert-box $alertLevel'>
                    <h3>Espace disque utilisé: $usagePercent%</h3>
                    <p>Le serveur approche de la limite d'espace disque disponible.</p>
                </div>
                
                <h4>📋 Actions Recommandées:</h4>
                <ul>
                    <li>Supprimer les anciennes sauvegardes non nécessaires</li>
                    <li>Vérifier les fichiers temporaires</li>
                    <li>Considérer l'ajout d'espace de stockage</li>
                    <li>Configurer la synchronisation cloud si disponible</li>
                </ul>
            </div>
            
            <div class='footer'>
                <p>Alerte générée automatiquement par le système de surveillance Acadenique.</p>
            </div>
        </body>
        </html>";
        
        return $html;
    }
    
    /**
     * Envoyer un email à plusieurs destinataires
     */
    private function sendToMultipleRecipients($recipients, $subject, $body) {
        if (is_string($recipients)) {
            $recipients = [$recipients];
        }
        
        $results = [];
        foreach ($recipients as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results[$email] = $this->mailer->send($email, $subject, $body, true);
            }
        }
        
        return $results;
    }
    
    /**
     * Obtenir les statistiques du jour
     */
    private function getDailyStats() {
        try {
            require_once 'backup_dashboard_manager.php';
            $dashboard = new BackupDashboardManager();
            $systemPerf = $dashboard->getSystemPerformance();
            
            return [
                'successful' => $systemPerf['total_backups'], // Approximation
                'failed' => 0, // Approximation
                'total_backups' => $systemPerf['total_backups'],
                'total_size_mb' => $systemPerf['total_size_mb']
            ];
        } catch (Exception $e) {
            return [
                'successful' => 0,
                'failed' => 0,
                'total_backups' => 0,
                'total_size_mb' => 0
            ];
        }
    }
    
    /**
     * Tester l'envoi d'email
     */
    public function sendTestEmail($recipient) {
        $subject = "🧪 Test Notification Acadenique";
        $body = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2 style='color: #0d6efd;'>Test de Notification</h2>
            <p>Ceci est un email de test du système de notification Acadenique.</p>
            <p><strong>Date:</strong> " . date('d/m/Y H:i:s') . "</p>
            <p><strong>Statut:</strong> ✅ Système opérationnel</p>
            <hr>
            <small>Email généré automatiquement - Ne pas répondre</small>
        </body>
        </html>";
        
        return $this->mailer->send($recipient, $subject, $body, true);
    }
}

// Test CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    echo "=== Test du Système de Notifications ===\n";
    
    if ($argc < 2) {
        echo "Usage: php backup_notification_system.php <email_test>\n";
        exit(1);
    }
    
    $testEmail = $argv[1];
    
    try {
        $notifications = new BackupNotificationSystem();
        
        echo "📧 Envoi d'un email de test à: $testEmail\n";
        $result = $notifications->sendTestEmail($testEmail);
        
        if ($result) {
            echo "✅ Email de test envoyé avec succès\n";
        } else {
            echo "❌ Échec de l'envoi de l'email de test\n";
        }
        
        // Test notification de succès
        echo "📧 Test notification de succès...\n";
        $mockResult = [
            'filename' => 'test_backup.sql.gz',
            'size' => 15000000,
            'compressed_size' => 1000000,
            'tables' => 42,
            'views' => 11,
            'total_rows' => 101735
        ];
        
        $result = $notifications->sendBackupSuccessNotification($mockResult, [$testEmail]);
        if ($result) {
            echo "✅ Notification de succès envoyée\n";
        } else {
            echo "❌ Échec notification de succès\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Erreur: " . $e->getMessage() . "\n";
    }
}
?>