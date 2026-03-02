<?php
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json');

if (!isset($_GET['q']) || strlen($_GET['q']) < 2) {
    echo json_encode([]);
    exit;
}

$searchTerm = '%' . $_GET['q'] . '%';

try {
    $stmt = $pdo->prepare("
        SELECT e.matricule, e.nom_etu, e.postnom_etu, e.prenom_etu
        FROM t_etudiant e
        JOIN t_inscription i ON e.matricule = i.matricule
        WHERE (e.nom_etu LIKE :search OR e.postnom_etu LIKE :search OR e.prenom_etu LIKE :search OR e.matricule LIKE :search)
        AND i.statut = 'Actif'
        LIMIT 10
    ");
    
    $stmt->bindParam(':search', $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($results);
} catch (PDOException $e) {
    error_log("Erreur de recherche: " . $e->getMessage());
    echo json_encode([]);
}