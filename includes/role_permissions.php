<?php
// Définition des rôles et leurs permissions par défaut
$ROLE_PERMISSIONS = [
    'administrateur' => [
        '*' => ['S', 'I', 'U', 'D', 'A'] // Toutes les permissions sur tous les modules
    ],
    'enseignant' => [
        'notes' => ['S', 'I', 'U'],
        'cours' => ['S'],
        'etudiants' => ['S'],
        'deliberation' => ['S']
    ],
    'secretaire' => [
        'etudiants' => ['S', 'I', 'U'],
        'inscriptions' => ['S', 'I', 'U'],
        'notes' => ['S']
    ],
    'etudiant' => [
        'notes' => ['S'],
        'cours' => ['S'],
        'inscriptions' => ['S']
    ]
];

function setupRolePermissions(PDO $pdo, string $username, string $role) {
    global $ROLE_PERMISSIONS;
    
    try {
        // Supprimer les anciennes permissions
        $stmt = $pdo->prepare("DELETE FROM t_utilisateur_autorisation WHERE username = :username");
        $stmt->execute(['username' => $username]);
        
        // Si le rôle n'existe pas dans la configuration, sortir
        if (!isset($ROLE_PERMISSIONS[$role])) {
            return;
        }
        
        // Ajouter les nouvelles permissions selon le rôle
        foreach ($ROLE_PERMISSIONS[$role] as $module => $permissions) {
            foreach ($permissions as $perm) {
                // Si le module est *, appliquer à tous les modules existants
                if ($module === '*') {
                    $modules = $pdo->query("SELECT DISTINCT module FROM t_autorisation")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($modules as $m) {
                        addPermission($pdo, $username, $m, $perm);
                    }
                } else {
                    addPermission($pdo, $username, $module, $perm);
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Erreur lors de la configuration des permissions du rôle : " . $e->getMessage());
    }
}

function addPermission(PDO $pdo, string $username, string $module, string $permission) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO t_utilisateur_autorisation (username, id_autorisation, est_autorise)
            SELECT :username, id_autorisation, 1
            FROM t_autorisation
            WHERE module = :module AND code_permission = :perm
        ");
        $stmt->execute([
            'username' => $username,
            'module' => $module,
            'perm' => $permission
        ]);
    } catch (PDOException $e) {
        error_log("Erreur lors de l'ajout de la permission : " . $e->getMessage());
    }
}
