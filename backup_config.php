<?php
/**
 * Configuration centralisée pour le système de sauvegarde
 * Paramètres et configuration pour tous les composants de sauvegarde
 */

class BackupConfig {
    
    /**
     * Configuration principale du système de sauvegarde
     */
    public static function getConfig() {
        return [
            // Répertoires
            'paths' => [
                'backup_dir' => __DIR__ . '/backups/',
                'log_dir' => __DIR__ . '/logs/',
                'temp_dir' => __DIR__ . '/temp/',
                'archive_dir' => __DIR__ . '/backups/archives/' // Pour les sauvegardes anciennes
            ],
            
            // Paramètres de sauvegarde
            'backup' => [
                'max_backups' => 30,              // Nombre maximum de sauvegardes à conserver
                'compression' => true,            // Activer la compression GZIP
                'chunk_size' => 1000,            // Nombre de lignes par chunk pour les grosses tables
                'max_execution_time' => 900,     // Temps maximum d'exécution (15 minutes)
                'memory_limit' => '512M',        // Limite mémoire pour les sauvegardes
                'verify_after_backup' => true,   // Vérifier l'intégrité après création
            ],
            
            // Sauvegarde automatique
            'auto_backup' => [
                'enabled' => true,
                'daily_backup' => true,
                'weekly_backup' => true,
                'monthly_backup' => true,
                'critical_tables' => [
                    't_utilisateur',
                    't_cote',
                    't_etudiant',
                    't_inscription',
                    't_logs_connexion',
                    't_configuration',
                    't_autorisation',
                    't_utilisateur_autorisation'
                ],
                'retention' => [
                    'daily' => 7,      // Garder 7 jours de sauvegardes quotidiennes
                    'weekly' => 8,     // Garder 8 semaines de sauvegardes hebdomadaires
                    'monthly' => 12    // Garder 12 mois de sauvegardes mensuelles
                ]
            ],
            
            // Sécurité et accès
            'security' => [
                'allowed_roles' => ['administrateur'],
                'require_auth' => true,
                'log_all_actions' => true,
                'encrypt_sensitive_backups' => false, // À implémenter si nécessaire
                'backup_permissions' => 0640,
                'allowed_restore_users' => ['admin'] // Utilisateurs autorisés à restaurer
            ],
            
            // Notifications
            'notifications' => [
                'email_enabled' => false,
                'email_address' => null, // À configurer: 'admin@exemple.com'
                'smtp_config' => [
                    'host' => 'localhost',
                    'port' => 587,
                    'username' => '',
                    'password' => '',
                    'encryption' => 'tls'
                ],
                'notify_on_success' => false,
                'notify_on_error' => true,
                'notify_on_restore' => true
            ],
            
            // Monitoring et alertes
            'monitoring' => [
                'disk_space_warning' => 1024 * 1024 * 1024, // 1 GB
                'disk_space_critical' => 512 * 1024 * 1024,  // 512 MB
                'backup_age_warning' => 24 * 60 * 60,        // 24 heures
                'backup_age_critical' => 48 * 60 * 60,       // 48 heures
                'max_log_size' => 10 * 1024 * 1024,          // 10 MB
                'enable_health_check' => true
            ],
            
            // Base de données
            'database' => [
                'connection_timeout' => 30,
                'query_timeout' => 300,
                'use_transactions' => true,
                'foreign_key_checks' => false, // Désactivé pendant les opérations
                'sql_mode' => 'TRADITIONAL',
                'charset' => 'utf8mb4'
            ],
            
            // Logs
            'logging' => [
                'level' => 'INFO', // DEBUG, INFO, WARNING, ERROR, CRITICAL
                'max_file_size' => 5 * 1024 * 1024, // 5 MB
                'rotate_logs' => true,
                'keep_log_files' => 10,
                'log_format' => '[{date}] [{level}] {message}',
                'include_trace' => false // Inclure la stack trace pour les erreurs
            ]
        ];
    }
    
    /**
     * Obtenir la configuration spécifique pour les sauvegardes automatiques
     */
    public static function getAutoBackupConfig() {
        $config = self::getConfig();
        return $config['auto_backup'];
    }
    
    /**
     * Obtenir la configuration de sécurité
     */
    public static function getSecurityConfig() {
        $config = self::getConfig();
        return $config['security'];
    }
    
    /**
     * Vérifier si un utilisateur est autorisé à effectuer des sauvegardes
     */
    public static function isUserAuthorized($role, $username = null) {
        $security = self::getSecurityConfig();
        
        if (!$security['require_auth']) {
            return true;
        }
        
        return in_array($role, $security['allowed_roles']);
    }
    
    /**
     * Vérifier si un utilisateur est autorisé à restaurer
     */
    public static function canUserRestore($username, $role) {
        $security = self::getSecurityConfig();
        
        if (in_array($username, $security['allowed_restore_users'])) {
            return true;
        }
        
        return in_array($role, $security['allowed_roles']);
    }
    
    /**
     * Obtenir la configuration des notifications
     */
    public static function getNotificationConfig() {
        $config = self::getConfig();
        return $config['notifications'];
    }
    
    /**
     * Créer les répertoires nécessaires avec les bonnes permissions
     */
    public static function initializeDirectories() {
        $config = self::getConfig();
        $paths = $config['paths'];
        $permissions = $config['security']['backup_permissions'];
        
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                mkdir($path, $permissions, true);
            }
            
            // Créer un fichier .htaccess pour sécuriser les répertoires web-accessibles
            $htaccess = $path . '.htaccess';
            if (!file_exists($htaccess) && strpos($path, '/backups/') !== false) {
                file_put_contents($htaccess, "Deny from all\n");
            }
        }
        
        return true;
    }
    
    /**
     * Valider la configuration
     */
    public static function validateConfig() {
        $config = self::getConfig();
        $errors = [];
        
        // Vérifier les répertoires
        foreach ($config['paths'] as $name => $path) {
            $dir = dirname($path);
            if (!is_dir($dir) && !is_writable(dirname($dir))) {
                $errors[] = "Répertoire $name non accessible: $path";
            }
        }
        
        // Vérifier les limites
        if ($config['backup']['max_execution_time'] < 60) {
            $errors[] = "Temps d'exécution maximum trop faible (minimum 60 secondes)";
        }
        
        // Vérifier la configuration email si activée
        if ($config['notifications']['email_enabled'] && !$config['notifications']['email_address']) {
            $errors[] = "Email de notification non configuré";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Obtenir les statistiques de configuration
     */
    public static function getConfigStats() {
        $config = self::getConfig();
        
        return [
            'backup_dir' => $config['paths']['backup_dir'],
            'compression_enabled' => $config['backup']['compression'],
            'auto_backup_enabled' => $config['auto_backup']['enabled'],
            'notifications_enabled' => $config['notifications']['email_enabled'],
            'security_enabled' => $config['security']['require_auth'],
            'critical_tables_count' => count($config['auto_backup']['critical_tables']),
            'max_backups' => $config['backup']['max_backups'],
            'retention_days' => $config['auto_backup']['retention']['daily'],
            'log_level' => $config['logging']['level']
        ];
    }
    
    /**
     * Mettre à jour une valeur de configuration (pour l'interface d'administration)
     */
    public static function updateConfig($key, $value) {
        // Cette fonction pourrait être étendue pour permettre la modification
        // de la configuration via l'interface web
        // Pour l'instant, elle retourne false car la config est en dur
        return false;
    }
    
    /**
     * Obtenir la configuration sous forme de tableau pour l'affichage
     */
    public static function getConfigForDisplay() {
        $config = self::getConfig();
        
        return [
            'Général' => [
                'Répertoire de sauvegarde' => $config['paths']['backup_dir'],
                'Compression activée' => $config['backup']['compression'] ? 'Oui' : 'Non',
                'Nombre max de sauvegardes' => $config['backup']['max_backups'],
                'Temps d\'exécution max' => $config['backup']['max_execution_time'] . ' secondes'
            ],
            'Sauvegarde automatique' => [
                'Activée' => $config['auto_backup']['enabled'] ? 'Oui' : 'Non',
                'Sauvegarde quotidienne' => $config['auto_backup']['daily_backup'] ? 'Oui' : 'Non',
                'Sauvegarde hebdomadaire' => $config['auto_backup']['weekly_backup'] ? 'Oui' : 'Non',
                'Sauvegarde mensuelle' => $config['auto_backup']['monthly_backup'] ? 'Oui' : 'Non',
                'Tables critiques' => implode(', ', $config['auto_backup']['critical_tables'])
            ],
            'Sécurité' => [
                'Authentification requise' => $config['security']['require_auth'] ? 'Oui' : 'Non',
                'Rôles autorisés' => implode(', ', $config['security']['allowed_roles']),
                'Log des actions' => $config['security']['log_all_actions'] ? 'Oui' : 'Non'
            ],
            'Notifications' => [
                'Email activé' => $config['notifications']['email_enabled'] ? 'Oui' : 'Non',
                'Adresse email' => $config['notifications']['email_address'] ?: 'Non configurée',
                'Notifier succès' => $config['notifications']['notify_on_success'] ? 'Oui' : 'Non',
                'Notifier erreurs' => $config['notifications']['notify_on_error'] ? 'Oui' : 'Non'
            ],
            'Monitoring' => [
                'Alerte espace disque' => self::formatBytes($config['monitoring']['disk_space_warning']),
                'Seuil critique' => self::formatBytes($config['monitoring']['disk_space_critical']),
                'Contrôle de santé' => $config['monitoring']['enable_health_check'] ? 'Activé' : 'Désactivé'
            ],
            'Logs' => [
                'Niveau de log' => $config['logging']['level'],
                'Taille max fichier' => self::formatBytes($config['logging']['max_file_size']),
                'Rotation des logs' => $config['logging']['rotate_logs'] ? 'Activée' : 'Désactivée',
                'Fichiers à conserver' => $config['logging']['keep_log_files']
            ]
        ];
    }
    
    /**
     * Formater la taille en octets
     */
    private static function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

// Initialiser les répertoires lors du chargement
BackupConfig::initializeDirectories();
?>