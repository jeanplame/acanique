<?php
/**
 * Fonction pour enregistrer les tentatives de connexion
 */

function logLoginAttempt(PDO $pdo, ?string $username, bool $success, string $message = '') {
    try {
        // Créer la table si elle n'existe pas
        $pdo->exec("CREATE TABLE IF NOT EXISTS t_logs_connexion (
            id_log INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(25) NULL,
            date_tentative DATETIME DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            success TINYINT(1) DEFAULT 0,
            message TEXT,
            INDEX idx_username (username),
            INDEX idx_date (date_tentative),
            INDEX idx_ip (ip_address),
            INDEX idx_success (success)
        )");

        $stmt = $pdo->prepare("
            INSERT INTO t_logs_connexion (username, ip_address, user_agent, success, message)
            VALUES (:username, :ip, :ua, :success, :message)
        ");

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_REAL_IP']) && !empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip_address = $_SERVER['HTTP_X_REAL_IP'];
        }

        $stmt->execute([
            'username' => $username,
            'ip' => $ip_address,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'success' => $success ? 1 : 0,
            'message' => $message
        ]);

        // Log en plus dans le fichier de log du serveur
        $status = $success ? 'SUCCESS' : 'FAILED';
        $user = $username ?? 'unknown';
        error_log("LOGIN_ATTEMPT: User=$user, Status=$status, IP=$ip_address, Message=$message");

        return true;

    } catch (PDOException $e) {
        error_log("Erreur lors de l'enregistrement du log de connexion : " . $e->getMessage());
        return false;
    }
}

/**
 * Fonction pour obtenir les statistiques de connexion
 */
function getLoginStats(PDO $pdo, int $days = 7): array {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(date_tentative) as date,
                COUNT(*) as total_attempts,
                SUM(success) as successful_logins,
                COUNT(DISTINCT username) as unique_users,
                COUNT(DISTINCT ip_address) as unique_ips
            FROM t_logs_connexion 
            WHERE date_tentative >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY DATE(date_tentative)
            ORDER BY date DESC
        ");
        
        $stmt->execute(['days' => $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des statistiques de connexion : " . $e->getMessage());
        return [];
    }
}

/**
 * Fonction pour obtenir les dernières tentatives de connexion d'un utilisateur
 */
function getUserLoginHistory(PDO $pdo, string $username, int $limit = 10): array {
    try {
        $stmt = $pdo->prepare("
            SELECT date_tentative, ip_address, success, message
            FROM t_logs_connexion 
            WHERE username = :username
            ORDER BY date_tentative DESC
            LIMIT :limit
        ");
        
        $stmt->execute(['username' => $username, 'limit' => $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération de l'historique de connexion : " . $e->getMessage());
        return [];
    }
}

/**
 * Fonction pour détecter les tentatives suspectes
 */
function detectSuspiciousActivity(PDO $pdo, int $minutes = 15, int $max_attempts = 5): array {
    try {
        $stmt = $pdo->prepare("
            SELECT ip_address, COUNT(*) as attempts, 
                   GROUP_CONCAT(DISTINCT username) as usernames_tried
            FROM t_logs_connexion 
            WHERE date_tentative >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
            AND success = 0
            GROUP BY ip_address
            HAVING attempts >= :max_attempts
            ORDER BY attempts DESC
        ");
        
        $stmt->execute(['minutes' => $minutes, 'max_attempts' => $max_attempts]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Erreur lors de la détection d'activité suspecte : " . $e->getMessage());
        return [];
    }
}
?>