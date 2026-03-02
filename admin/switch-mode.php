<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

// Inclure le gestionnaire d'environnement
require_once __DIR__ . '/../includes/EnvironmentManager.php';
require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/page_permissions.php';

// Vérifier que l'utilisateur est connecté
$current_user = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

if (!$current_user) {
    header('Location: ../index.php?page=login');
    exit;
}

// Vérifier les permissions admin
// Le rôle est déjà dans la session après login
$userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : null;

if ($userRole !== 'administrateur') {
    http_response_code(403);
    die('Accès refusé. Seul un administrateur peut accéder à cette page.');
}

$environment = EnvironmentManager::getInstance();
$currentMode = $environment->getMode();
$modeInfo = $environment->getModeInfo();

// Traiter les actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle-mode') {
        $newMode = $environment->toggleMode();
        $environment->logModeAction('Mode basculé', ['ancien' => $currentMode, 'nouveau' => $newMode]);
        
        $messageType = 'success';
        $message = "Mode basculé avec succès! Mode actuel: <strong>" . ucfirst($newMode) . "</strong>";
        
        // Rafraîchir les données
        $currentMode = $newMode;
        $modeInfo = $environment->getModeInfo();
    }
    
    if ($action === 'set-mode') {
        $newMode = $_POST['mode'] ?? '';
        if (in_array($newMode, ['development', 'production'])) {
            $environment->setMode($newMode);
            $environment->logModeAction('Mode défini', ['mode' => $newMode]);
            
            $messageType = 'success';
            $message = "Mode défini à <strong>" . ucfirst($newMode) . "</strong>";
            
            $currentMode = $newMode;
            $modeInfo = $environment->getModeInfo();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Modes - Développement/Utilisation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 900px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            padding: 40px;
            margin-top: 30px;
        }
        
        .mode-header {
            text-align: center;
            margin-bottom: 40px;
            border-bottom: 3px solid #667eea;
            padding-bottom: 20px;
        }
        
        .mode-header h1 {
            color: #333;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .mode-card {
            border: 3px solid #ddd;
            border-radius: 10px;
            padding: 30px;
            margin: 20px 0;
            transition: all 0.3s ease;
        }
        
        .mode-card.active {
            border-color: #667eea;
            background-color: #f8f9ff;
        }
        
        .mode-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .mode-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #333;
        }
        
        .mode-description {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .badge-danger {
            background-color: #dc3545 !important;
            color: white;
            padding: 8px 15px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .badge-success {
            background-color: #28a745 !important;
            color: white;
            padding: 8px 15px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .btn-large {
            padding: 12px 30px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        .btn-toggle {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        
        .btn-toggle:hover {
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-toggle:active {
            transform: translateY(0);
        }
        
        .development-features, .production-features {
            margin-top: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 5px;
            border-left: 5px solid #667eea;
        }
        
        .development-features h5, .production-features h5 {
            color: #667eea;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .features-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .features-list li {
            padding: 5px 0;
            color: #555;
        }
        
        .features-list li:before {
            content: "✓ ";
            color: #667eea;
            font-weight: bold;
            margin-right: 8px;
        }
        
        .alert {
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .mode-info {
            background: #f0f0f0;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 5px solid #667eea;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #333;
        }
        
        .info-value {
            color: #667eea;
            font-weight: 600;
        }
        
        .back-to-app {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        
        .back-to-app a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .back-to-app a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="mode-header">
            <h1>⚙️ Gestion des Modes</h1>
            <p style="color: #666; margin: 0;">Basculez entre le mode Développement et le mode Utilisation</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'warning'; ?>" role="alert">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Mode actuel -->
        <div class="mode-info">
            <div class="info-item">
                <span class="info-label">Mode Actuel:</span>
                <span class="info-value"><?php echo ucfirst($currentMode); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Statut:</span>
                <span><?php echo $environment->getModeBadge(); ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Description:</span>
                <span class="info-value"><?php echo $modeInfo['description']; ?></span>
            </div>
        </div>

        <!-- Mode Développement -->
        <div class="mode-card <?php echo $currentMode === 'development' ? 'active' : ''; ?>">
            <div class="mode-icon">🔧</div>
            <div class="mode-title">Mode Développement</div>
            <div class="mode-description">
                Activez ce mode quand vous devez modifier l'application, ajouter des fonctionnalités ou faire des mises à jour.
            </div>
            
            <div class="development-features">
                <h5>✨ Fonctionnalités Activées:</h5>
                <ul class="features-list">
                    <li>Modifications du code et des fichiers</li>
                    <li>Accès à tous les fichiers de diagnostic</li>
                    <li>Mise en cache désactivée</li>
                    <li>Messages d'erreur détaillés</li>
                    <li>Logs de débogage complets</li>
                    <li>Accès aux outils administrateur avancés</li>
                </ul>
            </div>

            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="action" value="set-mode">
                <input type="hidden" name="mode" value="development">
                <button type="submit" class="btn btn-large btn-toggle" 
                    <?php echo $currentMode === 'development' ? 'disabled' : ''; ?>>
                    <?php echo $currentMode === 'development' ? '✓ Mode Actuel' : 'Passer en Développement'; ?>
                </button>
            </form>
        </div>

        <!-- Mode Utilisation -->
        <div class="mode-card <?php echo $currentMode === 'production' ? 'active' : ''; ?>">
            <div class="mode-icon">▶️</div>
            <div class="mode-title">Mode Utilisation</div>
            <div class="mode-description">
                Activez ce mode pour utiliser l'application en production. C'est le mode normal d'utilisation.
            </div>
            
            <div class="production-features">
                <h5>🔒 Sécurité & Performance:</h5>
                <ul class="features-list">
                    <li>Application stable et en production</li>
                    <li>Mise en cache activée pour la performance</li>
                    <li>Messages d'erreur limités (sécurité)</li>
                    <li>Logs minimalistes</li>
                    <li>Accès utilisateur normal</li>
                    <li>Optimisation complète</li>
                </ul>
            </div>

            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="action" value="set-mode">
                <input type="hidden" name="mode" value="production">
                <button type="submit" class="btn btn-large btn-toggle" 
                    <?php echo $currentMode === 'production' ? 'disabled' : ''; ?>
                    <?php echo $currentMode === 'production' ? '✓ Mode Actuel' : 'Passer en Utilisation'; ?>
                </button>
            </form>
        </div>

        <!-- Basculement rapide -->
        <div style="text-align: center; margin-top: 40px;">
            <h4>Basculement Rapide</h4>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="toggle-mode">
                <button type="submit" class="btn btn-large btn-warning">
                    ⚡ Basculer le Mode (<?php echo ucfirst($currentMode === 'development' ? 'production' : 'development'); ?>)
                </button>
            </form>
        </div>

        <!-- Retour à l'application -->
        <div class="back-to-app">
            <a href="../index.php">« Retour à l'application</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
