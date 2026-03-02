<?php
require_once __DIR__ . '/includes/db_config.php';
require_once __DIR__ . '/includes/auth.php';

// Vérifier si l'utilisateur est administrateur
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== 'admin') {
    die("Seul l'administrateur peut exécuter ce script.");
}

try {
    // S'assurer que les tables de permissions sont initialisées
    require_once __DIR__ . '/includes/permission_init.php';
    initializePermissionTables($pdo);

    // Trouver l'ID de l'autorisation pour la lecture du module system
    $stmt = $pdo->prepare("
        SELECT id_autorisation 
        FROM t_autorisation 
        WHERE module = 'system' AND code_permission = 'S'
    ");
    $stmt->execute();
    $authId = $stmt->fetchColumn();

    if ($authId) {
        // Accorder la permission à l'utilisateur saf
        $stmt = $pdo->prepare("
            REPLACE INTO t_utilisateur_autorisation (username, id_autorisation, est_autorise)
            VALUES (:username, :auth_id, 1)
        ");
        $stmt->execute([
            'username' => 'saf',
            'auth_id' => $authId
        ]);

        echo "Permission accordée avec succès à l'utilisateur 'saf'.<br>";
        echo "<a href='index.php'>Retour à l'accueil</a>";

        // Vider le cache des permissions pour l'utilisateur
        if (isset($_SESSION['permissions_cache'])) {
            unset($_SESSION['permissions_cache']);
        }
    } else {
        echo "Erreur : Permission 'system/S' non trouvée.";
    }

} catch (PDOException $e) {
    echo "Erreur lors de l'attribution des permissions : " . htmlspecialchars($e->getMessage());
}
