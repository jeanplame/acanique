<?php
/**
 * Gestionnaire d'Environnement - Basculer entre Développement et Utilisation
 * 
 * Modes disponibles:
 * - 'development' : Mode développement (modification, mise à jour, tests)
 * - 'production'  : Mode utilisation (application normale)
 */

class EnvironmentManager {
    
    const DEVELOPMENT = 'development';
    const PRODUCTION = 'production';
    
    private static $configFile;
    private static $instance;
    private $currentMode;
    
    private function __construct() {
        self::$configFile = __DIR__ . '/../environment.json';
        $this->loadEnvironment();
    }
    
    /**
     * Récupère l'instance unique du gestionnaire
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Charge la configuration du mode depuis le fichier
     */
    private function loadEnvironment() {
        if (!file_exists(self::$configFile)) {
            // Créer le fichier par défaut
            $this->currentMode = self::PRODUCTION;
            $this->saveEnvironment();
        } else {
            $config = json_decode(file_get_contents(self::$configFile), true);
            $this->currentMode = $config['mode'] ?? self::PRODUCTION;
        }
    }
    
    /**
     * Sauvegarde la configuration du mode
     */
    private function saveEnvironment() {
        $config = [
            'mode' => $this->currentMode,
            'last_changed' => date('Y-m-d H:i:s'),
            'description' => $this->getModeDescription()
        ];
        file_put_contents(self::$configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * Retourne le mode actuel
     */
    public function getMode() {
        return $this->currentMode;
    }
    
    /**
     * Vérifie si on est en mode développement
     */
    public function isDevelopment() {
        return $this->currentMode === self::DEVELOPMENT;
    }
    
    /**
     * Vérifie si on est en mode production
     */
    public function isProduction() {
        return $this->currentMode === self::PRODUCTION;
    }
    
    /**
     * Bascule entre les modes
     */
    public function toggleMode() {
        $this->currentMode = $this->isDevelopment() ? self::PRODUCTION : self::DEVELOPMENT;
        $this->saveEnvironment();
        return $this->currentMode;
    }
    
    /**
     * Définit le mode
     */
    public function setMode($mode) {
        if (!in_array($mode, [self::DEVELOPMENT, self::PRODUCTION])) {
            throw new InvalidArgumentException("Mode invalide: $mode");
        }
        $this->currentMode = $mode;
        $this->saveEnvironment();
    }
    
    /**
     * Retourne une description du mode
     */
    public function getModeDescription() {
        switch ($this->currentMode) {
            case self::DEVELOPMENT:
                return 'Mode Développement - Modifications et mises à jour activées';
            case self::PRODUCTION:
                return 'Mode Utilisation - Application en production';
            default:
                return 'Mode inconnu';
        }
    }
    
    /**
     * Retourne les informations du mode actuel
     */
    public function getModeInfo() {
        return [
            'mode' => $this->currentMode,
            'isDevelopment' => $this->isDevelopment(),
            'isProduction' => $this->isProduction(),
            'description' => $this->getModeDescription(),
            'icon' => $this->getModeIcon()
        ];
    }
    
    /**
     * Retourne une icône pour le mode
     */
    public function getModeIcon() {
        return $this->isDevelopment() ? '🔧' : '▶️';
    }
    
    /**
     * Affiche un badge HTML du mode actuel
     */
    public function getModeBadge() {
        $class = $this->isDevelopment() ? 'badge-danger' : 'badge-success';
        $text = $this->isDevelopment() ? 'DÉVELOPPEMENT' : 'UTILISATION';
        return '<span class="badge ' . $class . '">' . $text . '</span>';
    }
    
    /**
     * Enregistre une action liée au mode
     */
    public function logModeAction($action, $details = null) {
        $logFile = __DIR__ . '/../logs/environment.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] Mode: {$this->currentMode} | Action: $action";
        
        if ($details !== null) {
            $logEntry .= " | Détails: " . (is_array($details) ? json_encode($details) : $details);
        }
        
        $logEntry .= "\n";
        
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}

// Initialisation automatique
$environmentManager = EnvironmentManager::getInstance();
?>
