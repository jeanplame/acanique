<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * Récupération de l'historique des modifications de cotes
 * Accès sécurisé par mot de passe
 * Version simplifie qui fonctionne avec les données existantes
 */

require_once '../includes/db_config.php';
require_once '../includes/auth.php';
session_start();

// Vérification de l'authentification utilisateur
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

// Vérification des permissions - L'accès à l'audit nécessite les permissions d'administration sur les Cotes
if (!canAccess('Cotes', 'A')) {
    echo json_encode(['success' => false, 'message' => 'Permissions insuffisantes pour accéder à l\'audit']);
    exit;
}

// Vérification de la requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

// Récupération et validation des données
$input = json_decode(file_get_contents('php://input'), true);

// Vérification du mot de passe d'accès
$required_password = '@Sgac2025';
if (!isset($input['access_password']) || $input['access_password'] !== $required_password) {
    echo json_encode(['success' => false, 'message' => 'Mot de passe incorrect']);
    exit;
}

try {
    // Construction de la requête avec filtres pour les données actuelles de t_cote
    $where_conditions = ['1=1']; // Condition de base
    $params = [];

    // Filtre par étudiant (matricule ou nom)
    if (!empty($input['student_filter'])) {
        $where_conditions[] = "(c.matricule LIKE :student OR CONCAT(e.nom_etu, ' ', e.postnom_etu, ' ', COALESCE(e.prenom_etu, '')) LIKE :student_name)";
        $params[':student'] = '%' . $input['student_filter'] . '%';
        $params[':student_name'] = '%' . $input['student_filter'] . '%';
    }

    // Filtre par cours
    if (!empty($input['course_filter'])) {
        $where_conditions[] = "(ec.code_ec LIKE :course OR ec.libelle LIKE :course_name)";
        $params[':course'] = '%' . $input['course_filter'] . '%';
        $params[':course_name'] = '%' . $input['course_filter'] . '%';
    }

    // Filtre par utilisateur
    if (!empty($input['user_filter'])) {
        $where_conditions[] = "c.username LIKE :user";
        $params[':user'] = '%' . $input['user_filter'] . '%';
    }

    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

    // Requête pour récupérer les données actuelles de t_cote comme "historique"
    $sql = "
        SELECT 
            c.id_note,
            'CURRENT' as action_type,
            c.matricule,
            c.username as username_modificateur,
            c.id_ec,
            c.id_annee,
            c.id_mention,
            c.id_promotion,
            c.id_ue,
            NULL as ancienne_cote_s1,
            c.cote_s1 as nouvelle_cote_s1,
            NULL as ancienne_cote_s2,
            c.cote_s2 as nouvelle_cote_s2,
            NOW() as date_modification,
            'Données actuelles dans le système' as commentaire,
            e.nom_etu,
            e.postnom_etu,
            e.prenom_etu,
            ec.code_ec,
            ec.libelle as intitule_ec,
            ue.code_ue,
            ue.libelle as intitule_ue,
            u.nom_complet as modificateur_nom,
            CONCAT(YEAR(an.date_debut), '-', YEAR(an.date_fin)) as annee_academique,
            m.libelle as mention_nom,
            pr.nom_promotion as promotion_nom
        FROM t_cote c
        LEFT JOIN t_etudiant e ON c.matricule = e.matricule
        LEFT JOIN t_element_constitutif ec ON c.id_ec = ec.id_ec
        LEFT JOIN t_unite_enseignement ue ON c.id_ue = ue.id_ue
        LEFT JOIN t_utilisateur u ON c.username = u.username
        LEFT JOIN t_anne_academique an ON c.id_annee = an.id_annee
        LEFT JOIN t_mention m ON c.id_mention = m.id_mention
        LEFT JOIN t_inscription i ON c.matricule = i.matricule AND c.id_annee = i.id_annee
        LEFT JOIN t_promotion pr ON i.code_promotion = pr.code_promotion
        $where_clause
        ORDER BY c.id_note DESC
        LIMIT 50
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $audit_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatage des données pour l'affichage
    $formatted_records = [];
    foreach ($audit_records as $record) {
        $formatted_records[] = [
            'id_audit' => $record['id_note'],
            'action_type' => $record['action_type'],
            'date_modification' => date('d/m/Y H:i:s', strtotime($record['date_modification'])),
            'etudiant' => trim(($record['nom_etu'] ?? '') . ' ' . ($record['postnom_etu'] ?? '') . ' ' . ($record['prenom_etu'] ?? '')),
            'matricule' => $record['matricule'],
            'cours' => ($record['code_ec'] ?? 'N/A') . ' - ' . ($record['intitule_ec'] ?? 'Cours non défini'),
            'ue' => ($record['code_ue'] ?? 'N/A') . ' - ' . ($record['intitule_ue'] ?? 'UE non définie'),
            'modificateur' => ($record['modificateur_nom'] ?? 'Utilisateur inconnu') . ' (' . $record['username_modificateur'] . ')',
            'ancienne_cote_s1' => $record['ancienne_cote_s1'],
            'nouvelle_cote_s1' => $record['nouvelle_cote_s1'],
            'ancienne_cote_s2' => $record['ancienne_cote_s2'],
            'nouvelle_cote_s2' => $record['nouvelle_cote_s2'],
            'annee_academique' => $record['annee_academique'] ?? 'Non définie',
            'mention' => $record['mention_nom'] ?? 'Non définie',
            'promotion' => $record['promotion_nom'] ?? 'Non définie',
            'commentaire' => $record['commentaire'] ?? ''
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $formatted_records,
        'total' => count($formatted_records),
        'message' => 'Données actuelles récupérées avec succès (mode découverte)'
    ]);

} catch (PDOException $e) {
    error_log("Erreur base de données dans get_audit_history.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Erreur de base de données: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Erreur générale dans get_audit_history.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Erreur serveur: ' . $e->getMessage()
    ]);
}
?>