<?php
require_once 'config.php';

echo "📝 Insertion des configurations par défaut...\n";

$defaultConfigs = [
    ['backup_notifications_enabled', '0', 'Activer les notifications email pour les sauvegardes'],
    ['backup_email_smtp_host', 'smtp.gmail.com', 'Serveur SMTP pour l\'envoi d\'emails'],
    ['backup_email_smtp_port', '587', 'Port SMTP'],
    ['backup_email_smtp_user', '', 'Nom d\'utilisateur SMTP'],
    ['backup_email_smtp_pass', '', 'Mot de passe SMTP'],
    ['backup_email_from', 'noreply@acadenique.com', 'Adresse email expéditeur'],
    ['backup_admin_emails', '', 'Emails des administrateurs (un par ligne)'],
    ['backup_retention_days', '30', 'Nombre de jours de rétention des sauvegardes'],
    ['backup_compression_enabled', '1', 'Activer la compression GZIP des sauvegardes'],
    ['backup_auto_cleanup', '1', 'Nettoyage automatique des anciennes sauvegardes'],
    ['backup_disk_alert_threshold', '85', 'Seuil d\'alerte d\'espace disque (%)'],
    ['backup_max_file_size', '2147483648', 'Taille maximale des fichiers de sauvegarde (2GB)']
];

try {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO t_configuration (cle, valeur, description) 
        VALUES (?, ?, ?)
    ");
    
    $inserted = 0;
    foreach ($defaultConfigs as $config) {
        if ($stmt->execute($config)) {
            $inserted++;
        }
    }
    
    echo "✅ $inserted configurations par défaut ajoutées\n";
} catch (Exception $e) {
    echo "❌ Erreur insertion configurations: " . $e->getMessage() . "\n";
}
?>