<?php
// Protection pour éviter l'inclusion multiple du fichier
if (!defined('DB_CONFIG_INCLUDED')) {
    define('DB_CONFIG_INCLUDED', true);

    // Définition des constantes de connexion à la base de données
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'lmd_db');
    define('DB_USER', 'root');
    define('DB_PASS', 'mysarnye');

    try {
        // Tenter de se connecter sans spécifier la base de données pour la création initiale
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Vérifier si la base de données existe
        $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . DB_NAME . "'");

        if (!$stmt->fetch()) {
            // Si la base de données n'existe pas, on la crée
            $pdo->exec("CREATE DATABASE " . DB_NAME);

            // Reconnexion à la nouvelle base de données
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Importer le fichier SQL pour créer les tables et les vues
            $sql = file_get_contents(__DIR__ . '/../db/lmd_db.sql');
            $pdo->exec($sql);

            // Insérer des données initiales
            $promotions = [
                ['L1', 'Première licence'],
                ['L2', 'Deuxième licence'],
                ['L3', 'Troisième licence'],
                ['M1', 'Master 1'],
                ['M2', 'Master 2']
            ];

            $stmt = $pdo->prepare("INSERT INTO t_promotion (code_promotion, nom_promotion) VALUES (?, ?)");
            foreach ($promotions as $promotion) {
                $stmt->execute($promotion);
            }

            // S'assurer que les tables sont supprimées dans le bon ordre
            // $pdo->exec("DROP TABLE IF EXISTS t_logs_connexion");
            // $pdo->exec("DROP TABLE IF EXISTS t_utilisateur");

            // Créer la table t_utilisateur
            $pdo->exec("CREATE TABLE IF NOT EXISTS t_utilisateur (
                username varchar(25) NOT NULL,
                nom_complet varchar(50) NOT NULL,
                motdepasse varchar(255) NOT NULL,
                role varchar(100) NOT NULL,
                remember_token varchar(64) NULL,
                token_expires datetime NULL,
                PRIMARY KEY (username)
            )");

            // Créer la table t_logs_connexion
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
                FOREIGN KEY (username) REFERENCES t_utilisateur(username) ON DELETE SET NULL
            )");

            // Créer la table t_anne_academique
            $pdo->exec("CREATE TABLE IF NOT EXISTS t_anne_academique (
                id_annee INT AUTO_INCREMENT PRIMARY KEY,
                date_debut DATE NOT NULL,
                date_fin DATE NOT NULL
            )");

            // Insérer une année académique par défaut
            $annee_debut = date('Y-m-d', strtotime('2025-09-15'));
            $annee_fin = date('Y-m-d', strtotime('2026-07-15'));
            $stmt = $pdo->prepare("INSERT INTO t_anne_academique (date_debut, date_fin) VALUES (?, ?)");
            $stmt->execute([$annee_debut, $annee_fin]);

            // Créer la table de configuration
            $pdo->exec("CREATE TABLE IF NOT EXISTS t_configuration (
                cle VARCHAR(50) PRIMARY KEY,
                valeur TEXT NOT NULL
            )");

            // Insérer la configuration de l'année active
            $stmt = $pdo->prepare("INSERT INTO t_configuration (cle, valeur) VALUES (?, ?)");
            $stmt->execute(['annee_encours', '1']);

            // Insérer l'utilisateur admin par défaut
            $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO t_utilisateur (username, nom_complet, motdepasse, role) VALUES (?, ?, ?, ?)");
            $stmt->execute(['admin', 'Jean Marie IBANGA', $hashedPassword, 'administrateur']);
            
        }

        // Connexion finale à la base de données spécifique pour le reste de l'application
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

    } catch (PDOException $e) {
        // Arrêter le script en cas d'erreur de connexion fatale
        die("Erreur de connexion : " . $e->getMessage());
    }
}
?>