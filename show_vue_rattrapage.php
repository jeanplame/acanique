<?php
require_once 'includes/db_config.php';

try {
    $stmt = $pdo->query('SHOW CREATE VIEW vue_grille_deliberation_avec_rattrapage');
    $result = $stmt->fetch();
    echo "=== STRUCTURE DE LA VUE RATTRAPAGE ===\n\n";
    echo $result['Create View'];
    echo "\n\n=== FIN ===\n";
} catch (Exception $e) {
    echo "Erreur: " . $e->getMessage();
}
?>