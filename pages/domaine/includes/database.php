<?php
    header('Content-Type: text/html; charset=UTF-8');
// Fonctions de requêtes pour le domaine
function getDomainInfo($pdo, $id_domaine) {
    $stmt = $pdo->prepare("SELECT * FROM t_domaine WHERE id_domaine = ?");
    $stmt->execute([$id_domaine]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getFilieres($pdo, $id_domaine) {
    $stmt = $pdo->prepare("
        SELECT f.*, COUNT(DISTINCT m.id_mention) as nb_mentions,
        (SELECT COUNT(DISTINCT i.matricule) 
         FROM t_inscription i 
         JOIN t_mention m2 ON i.id_mention = m2.id_mention 
         WHERE m2.idFiliere = f.idFiliere) as nb_etudiants
        FROM t_filiere f
        LEFT JOIN t_mention m ON f.idFiliere = m.idFiliere
        WHERE f.id_domaine = ?
        GROUP BY f.idFiliere
    ");
    $stmt->execute([$id_domaine]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPromotions($pdo, $mention_id) {
    $stmt = $pdo->prepare("
        SELECT p.*, 
               (SELECT COUNT(DISTINCT i.matricule) 
                FROM t_inscription i 
                WHERE i.id_mention = ? AND i.code_promotion = p.code_promotion) as nb_etudiants
        FROM t_promotion p
        JOIN t_filiere_promotion fp ON p.code_promotion = fp.code_promotion
        JOIN t_mention m ON m.idFiliere = fp.id_filiere
        WHERE m.id_mention = ?
        ORDER BY p.code_promotion
    ");
    $stmt->execute([$mention_id, $mention_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getInscriptions($pdo, $mention_id, $promotion_code) {
    $stmt = $pdo->prepare("
        SELECT i.*, e.* 
        FROM t_inscription i
        JOIN t_etudiant e ON i.matricule = e.matricule
        WHERE i.id_mention = ? AND i.code_promotion = ?
        ORDER BY e.nom_etu, e.postnom_etu, e.prenom_etu
    ");
    $stmt->execute([$mention_id, $promotion_code]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
