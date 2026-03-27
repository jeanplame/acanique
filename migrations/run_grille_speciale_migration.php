<?php
require_once __DIR__ . '/../includes/db_config.php';

echo "=== Migration: Table t_grille_speciale ===\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS t_grille_speciale (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titre VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            id_annee INT NOT NULL,
            id_mention VARCHAR(50) NOT NULL,
            code_promotion VARCHAR(50) NOT NULL,
            semestre_filter VARCHAR(10) DEFAULT '',
            mode_rattrapage TINYINT(1) DEFAULT 0,
            selected_matricules JSON NOT NULL,
            selected_ue_ec_keys JSON NOT NULL,
            cree_par VARCHAR(100) NOT NULL,
            date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_modification DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_annee_mention_promo (id_annee, id_mention, code_promotion),
            INDEX idx_cree_par (cree_par),
            INDEX idx_date_creation (date_creation)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "OK: Table creee avec succes.\n";

    $stmt = $pdo->query("DESCRIBE t_grille_speciale");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  - " . $c['Field'] . " (" . $c['Type'] . ")\n";
    }
} catch (PDOException $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
}
