<?php
function logAccess(PDO $pdo, string $username, string $module, string $action, bool $granted) {
    try {
        // Créer la table si elle n'existe pas
        $pdo->exec("CREATE TABLE IF NOT EXISTS t_access_log (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL,
            module VARCHAR(50) NOT NULL,
            action VARCHAR(50) NOT NULL,
            granted BOOLEAN NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $stmt = $pdo->prepare("
            INSERT INTO t_access_log (username, module, action, granted, ip_address, user_agent)
            VALUES (:username, :module, :action, :granted, :ip, :ua)
        ");

        $stmt->execute([
            'username' => $username,
            'module' => $module,
            'action' => $action,
            'granted' => $granted ? 1 : 0,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);

        // Si l'accès est refusé, envoyer une notification à l'administrateur
        if (!$granted) {
            error_log("Tentative d'accès non autorisé : Utilisateur=$username, Module=$module, Action=$action, IP=" . 
                     ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        }

    } catch (PDOException $e) {
        error_log("Erreur lors de la journalisation de l'accès : " . $e->getMessage());
    }
}

function getAccessLogs(PDO $pdo, ?string $username = null, ?string $module = null, ?string $startDate = null, ?string $endDate = null): array {
    try {
        $conditions = [];
        $params = [];

        if ($username) {
            $conditions[] = "username = :username";
            $params['username'] = $username;
        }
        if ($module) {
            $conditions[] = "module = :module";
            $params['module'] = $module;
        }
        if ($startDate) {
            $conditions[] = "created_at >= :start_date";
            $params['start_date'] = $startDate;
        }
        if ($endDate) {
            $conditions[] = "created_at <= :end_date";
            $params['end_date'] = $endDate;
        }

        $sql = "SELECT * FROM t_access_log";
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        $sql .= " ORDER BY created_at DESC LIMIT 1000";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des logs d'accès : " . $e->getMessage());
        return [];
    }
}
