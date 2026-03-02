<?php
/**
 * Fonctions utilitaires pour la gestion des modes
 * Fichier helper à inclure dans vos scripts PHP
 */

require_once __DIR__ . '/EnvironmentManager.php';

$env = EnvironmentManager::getInstance();

/**
 * Échos le statut du mode actuel
 */
function displayModeStatus() {
    global $env;
    echo $env->getModeBadge();
}

/**
 * Retourne le badge HTML du mode
 */
function getModeStatus() {
    global $env;
    return $env->getModeBadge();
}

/**
 * Vérifier si on est en développement (plus court)
 */
function isDev() {
    global $env;
    return $env->isDevelopment();
}

/**
 * Vérifier si on est en production (plus court)
 */
function isProd() {
    global $env;
    return $env->isProduction();
}

/**
 * Exécuter du code uniquement en développement
 */
function runInDevelopment(callable $callback) {
    global $env;
    if ($env->isDevelopment()) {
        return $callback();
    }
    return null;
}

/**
 * Exécuter du code uniquement en production
 */
function runInProduction(callable $callback) {
    global $env;
    if ($env->isProduction()) {
        return $callback();
    }
    return null;
}

/**
 * Afficher une variable de débogage (seulement en développement)
 */
function debugDump($var, $label = '') {
    global $env;
    if ($env->isDevelopment()) {
        echo '<pre style="background: #f0f0f0; padding: 10px; margin: 10px 0; border-left: 5px solid #667eea;">';
        if ($label) {
            echo '<strong>' . htmlspecialchars($label) . ':</strong><br>';
        }
        var_dump($var);
        echo '</pre>';
    }
}

/**
 * Logger une action (selon le niveau de détail du mode)
 */
function logAction($action, $details = null) {
    global $env;
    
    $logMessage = "[$action]";
    if ($details) {
        $logMessage .= " " . json_encode($details);
    }
    
    $env->logModeAction($action, $details);
    
    // Console log en développement
    if ($env->isDevelopment()) {
        error_log($logMessage);
    }
}

/**
 * Gérer les erreurs selon le mode
 */
function handleError($errorTitle, $errorMessage, $errorDetails = null) {
    global $env;
    
    if ($env->isDevelopment()) {
        // Erreur complète en développement
        echo '<div style="border: 2px solid #dc3545; background: #f8d7da; color: #721c24; padding: 15px; margin: 10px 0; border-radius: 5px;">';
        echo '<h4>' . htmlspecialchars($errorTitle) . '</h4>';
        echo '<p>' . htmlspecialchars($errorMessage) . '</p>';
        if ($errorDetails) {
            echo '<pre style="background: #fff; padding: 10px; margin-top: 10px; overflow-x: auto;">';
            echo htmlspecialchars(print_r($errorDetails, true));
            echo '</pre>';
        }
        echo '</div>';
    } else {
        // Message générique en production
        echo '<div style="border: 2px solid #dc3545; background: #f8d7da; color: #721c24; padding: 15px; margin: 10px 0; border-radius: 5px;">';
        echo '<h4>' . ucfirst($errorTitle) . '</h4>';
        echo '<p>Une erreur s\'est produite. Veuillez réessayer ou contacter l\'administrateur.</p>';
        echo '</div>';
    }
}

/**
 * Configurer les en-têtes HTTP selon le mode
 */
function setModeHeaders() {
    global $env;
    
    if ($env->isDevelopment()) {
        // Pas de cache en développement
        header('Pragma: no-cache');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Expires: 0');
    } else {
        // Cache en production (1 heure)
        header('Cache-Control: public, max-age=3600');
    }
}

/**
 * Afficher un badge du mode (Bootstrap)
 */
function modeIndicator() {
    global $env;
    
    if ($env->isDevelopment()) {
        return '<span class="badge bg-danger" title="Mode Développement">DEV</span>';
    } else {
        return '<span class="badge bg-success" title="Mode Utilisation">PROD</span>';
    }
}

/**
 * Obtenir l'URL de la page de gestion des modes (si l'utilisateur est admin)
 */
function getModeManagementUrl() {
    return '/acadenique/admin/switch-mode.php';
}

/**
 * Vérifier si l'utilisateur peut accéder à la gestion des modes
 */
function canManageModes() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    // Vérifier que c'est un admin
    try {
        global $pdo;
        $stmt = $pdo->prepare("SELECT role FROM t_utilisateur WHERE username = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        return $user && $user['role'] === 'Admin';
    } catch (Exception $e) {
        return false;
    }
}

?>
