<?php
/**
 * EXEMPLES D'UTILISATION DU SYSTÈME DE MODES
 * 
 * Ce fichier montre comment intégrer la gestion des modes dans votre application.
 * Vous n'avez pas besoin de l'exécuter directement - c'est un guide.
 */

// ============================================================================
// EXEMPLE 1: Récupérer le mode actuel
// ============================================================================

require_once 'includes/EnvironmentManager.php';

$env = EnvironmentManager::getInstance();

// Récupérer le mode (retourne 'development' ou 'production')
$currentMode = $env->getMode();
echo "Mode actuel: " . $currentMode . "\n";

// Vérifier le mode
if ($env->isDevelopment()) {
    echo "Application en mode DÉVELOPPEMENT\n";
} else if ($env->isProduction()) {
    echo "Application en mode UTILISATION\n";
}

// ============================================================================
// EXEMPLE 2: Utiliser les fonctions helper
// ============================================================================

require_once 'includes/environment_helpers.php';

// Afficher le statut
displayModeStatus(); // Affiche un badge

// Les fonctions courtes
if (isDev()) {
    echo "En développement\n";
}

if (isProd()) {
    echo "En production\n";
}

// ============================================================================
// EXEMPLE 3: Code conditionnels selon le mode
// ============================================================================

// Gestion des erreurs
try {
    // Votre code ici
    throw new Exception("Erreur d'exemple");
} catch (Exception $e) {
    if ($env->isDevelopment()) {
        // Afficher l'erreur complète en développement
        echo "ERREUR DÉTAILLÉE: " . $e->getMessage() . "\n";
        echo "Fichier: " . $e->getFile() . "\n";
        echo "Ligne: " . $e->getLine() . "\n";
    } else {
        // Message générique en production
        echo "Une erreur s'est produite. Veuillez réessayer.\n";
    }
}

// ============================================================================
// EXEMPLE 4: Affichage de variables de débogage
// ============================================================================

$userData = [
    'id' => 123,
    'name' => 'Jean Dupont',
    'email' => 'jean@example.com'
];

// Cette fonction n'affiche la variable que si on est en développement
debugDump($userData, "Données utilisateur");

// ============================================================================
// EXEMPLE 5: Exécution conditionnelle
// ============================================================================

// Exécuter du code SEULEMENT en développement
runInDevelopment(function() {
    echo "Ce code s'exécute SEULEMENT en mode développement\n";
    echo "Parfait pour les tests et les diagnostics\n";
});

// Exécuter du code SEULEMENT en production
runInProduction(function() {
    echo "Ce code s'exécute SEULEMENT en mode utilisation\n";
    echo "Parfait pour les optimisations\n";
});

// ============================================================================
// EXEMPLE 6: Gestion des caches
// ============================================================================

if ($env->isDevelopment()) {
    // Désactiver le cache en développement
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "Cache DÉSACTIVÉ (fichiers toujours à jour)\n";
} else {
    // Activer le cache en production
    header('Cache-Control: public, max-age=3600');
    echo "Cache ACTIVÉ (performance optimisée)\n";
}

// ============================================================================
// EXEMPLE 7: Affichage du statut dans une page web
// ============================================================================

// En incluant cela dans vos templates:
?>
<!DOCTYPE html>
<html>
<head>
    <title>Exemple d'intégration des modes</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .dev-only { background: #fff3cd; border: 1px solid #ffc107; padding: 10px; margin: 10px 0; }
        .prod-only { background: #d1ecf1; border: 1px solid #17a2b8; padding: 10px; margin: 10px 0; }
    </style>
</head>
<body>

<?php
require_once 'includes/EnvironmentManager.php';
$env = EnvironmentManager::getInstance();
?>

    <h1>Votre Application</h1>

    <?php if ($env->isDevelopment()): ?>
        <div class="dev-only">
            <strong>🔧 MODE DÉVELOPPEMENT ACTIF</strong><br>
            Vous pouvez modifier l'application. Pensez à basculer en mode utilisation quand c'est prêt.
            <br><a href="admin/switch-mode.php">Gérer les modes →</a>
        </div>
    <?php endif; ?>

    <?php if ($env->isProduction()): ?>
        <div class="prod-only">
            <strong>▶️ MODE UTILISATION ACTIF</strong><br>
            L'application est en mode normal. Pour faire des modifications, passez en mode développement.
        </div>
    <?php endif; ?>

    <!-- Contenu de votre page -->
    <p>Bienvenue dans votre application!</p>

</body>
</html>

<?php
// ============================================================================
// EXEMPLE 8: Enregistrer les actions importantes
// ============================================================================

$env->logModeAction('Accès à ma page', [
    'utilisateur' => $_SESSION['user_id'] ?? 'anonyme',
    'timestamp' => date('Y-m-d H:i:s')
]);

// ============================================================================
// EXEMPLE 9: Obtenir les informations complètes du mode
// ============================================================================

$modeInfo = $env->getModeInfo();
/*
Retourne un tableau comme:
[
    'mode' => 'development',
    'isDevelopment' => true,
    'isProduction' => false,
    'description' => 'Mode Développement - Modifications et mises à jour activées',
    'icon' => '🔧'
]
*/

// ============================================================================
// EXEMPLE 10: Dans un fichier de configuration
// ============================================================================

/*
Dans votre config.php ou config-custom.php:

<?php
require_once 'includes/EnvironmentManager.php';

$env = EnvironmentManager::getInstance();

// Configuration selon le mode
$config = [
    'database' => [
        'host' => 'localhost',
        'user' => 'root',
        'password' => 'mysarnye',
        'name' => 'lmd_db'
    ],
    'cache' => [
        'enabled' => $env->isProduction(),  // Cache seulement en production
        'ttl' => 3600
    ],
    'debug' => [
        'display_errors' => $env->isDevelopment(),
        'log_level' => $env->isDevelopment() ? 'DEBUG' : 'ERROR'
    ],
    'security' => [
        'require_ssl' => $env->isProduction(),
        'session_timeout' => $env->isDevelopment() ? 0 : 1800
    ]
];
?>
*/

echo "\n=== EXEMPLES D'UTILISATION TERMINÉS ===\n";
?>
