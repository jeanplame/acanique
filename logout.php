<?php
session_start();
require_once 'includes/db_config.php';
require_once 'includes/login_logger.php';

try {
    $username = $_SESSION['user_id'] ?? null;
    
    // Supprimer le remember_token de la base de données si l'utilisateur est connecté
    if ($username) {
        $stmt = $pdo->prepare("UPDATE t_utilisateur SET remember_token = NULL, token_expires = NULL WHERE username = ?");
        $stmt->execute([$username]);
        
        // Log de la déconnexion
        logLoginAttempt($pdo, $username, true, "Déconnexion manuelle");
    }

    // Enregistrer le message avant de détruire la session
    $_SESSION['flash_message'] = [
        'type' => 'success',
        'message' => 'Vous avez été déconnecté avec succès.'
    ];

    // Détruire toutes les variables de session
    $_SESSION = array();

    // Effacer le cookie de session de manière sécurisée
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    // Effacer le cookie "remember_token" s'il existe de manière sécurisée
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    // Détruire la session
    session_destroy();

} catch (PDOException $e) {
    // Logger l'erreur mais ne pas l'afficher à l'utilisateur
    error_log("Erreur lors de la déconnexion : " . $e->getMessage());
    
    // Créer une nouvelle session pour le message d'erreur
    session_start();
    $_SESSION['flash_message'] = [
        'type' => 'warning',
        'message' => 'La déconnexion a réussi mais avec quelques avertissements.'
    ];
}

// Rediriger vers la page de connexion avec les paramètres d'URL
header('Location: ?page=login');
exit();
