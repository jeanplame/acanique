<?php
function initializePermissionTables(PDO $pdo) {
    try {
        // Création de la table des autorisations si elle n'existe pas
        $pdo->exec("CREATE TABLE IF NOT EXISTS t_autorisation (
            id_autorisation INT AUTO_INCREMENT PRIMARY KEY,
            module VARCHAR(50) NOT NULL,
            code_permission CHAR(1) NOT NULL,
            description VARCHAR(255),
            UNIQUE KEY unique_module_perm (module, code_permission)
        )");

        // Création de la table des autorisations utilisateur si elle n'existe pas
        $pdo->exec("CREATE TABLE IF NOT EXISTS t_utilisateur_autorisation (
            username VARCHAR(50) NOT NULL,
            id_autorisation INT NOT NULL,
            est_autorise TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (username, id_autorisation),
            FOREIGN KEY (id_autorisation) REFERENCES t_autorisation(id_autorisation)
        )");

        // Vérifier si des autorisations de base existent déjà
        $stmt = $pdo->query("SELECT COUNT(*) FROM t_autorisation");
        $count = $stmt->fetchColumn();

        // Si aucune autorisation n'existe, insérer les autorisations de base
        if ($count == 0) {
            // Définition des modules et leurs permissions
            $basePermissions = [
                'Cotes' => ['S', 'I', 'U', 'D', 'A'],
                'Cours' => ['S', 'I', 'U', 'D', 'A'],
                'Inscriptions' => ['S', 'I', 'U', 'D', 'A'],
                'Utilisateurs' => ['S', 'I', 'U', 'D', 'A']
            ];

            // Description des codes de permission
            $permDescriptions = [
                'S' => 'Lecture',
                'I' => 'Insertion',
                'U' => 'Modification',
                'D' => 'Suppression',
                'A' => 'Administration'
            ];

            // Insérer toutes les permissions de base
            $stmt = $pdo->prepare("INSERT INTO t_autorisation (module, code_permission, description) VALUES (?, ?, ?)");
            
            foreach ($basePermissions as $module => $permissions) {
                foreach ($permissions as $perm) {
                    $description = "$module - {$permDescriptions[$perm]}";
                    $stmt->execute([$module, $perm, $description]);
                }
            }

            // Donner toutes les permissions à l'administrateur
            if (isset($_SESSION['user_id']) && $_SESSION['user_id'] === 'admin') {
                $stmt = $pdo->prepare("
                    INSERT INTO t_utilisateur_autorisation (username, id_autorisation, est_autorise)
                    SELECT 'admin', id_autorisation, 1 FROM t_autorisation
                ");
                $stmt->execute();
            }
        }

        return true;
    } catch (PDOException $e) {
        error_log("Erreur lors de l'initialisation des tables de permissions : " . $e->getMessage());
        return false;
    }
}

// Initialiser les tables si nécessaire
if (isset($pdo)) {
    initializePermissionTables($pdo);
}
