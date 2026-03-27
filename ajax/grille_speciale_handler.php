<?php
/**
 * Handler AJAX pour la gestion des grilles spéciales de délibération
 * Opérations : save, list, load, delete
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

// Vérifier l'authentification
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

require_once __DIR__ . '/../includes/db_config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'save':
        saveGrilleSpeciale($pdo);
        break;
    case 'list':
        listGrillesSpeciales($pdo);
        break;
    case 'load':
        loadGrilleSpeciale($pdo);
        break;
    case 'delete':
        deleteGrilleSpeciale($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Action inconnue']);
}

function saveGrilleSpeciale($pdo) {
    $titre = trim($_POST['titre'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $id_annee = $_POST['id_annee'] ?? '';
    $id_mention = $_POST['id_mention'] ?? '';
    $code_promotion = $_POST['code_promotion'] ?? '';
    $semestre_filter = $_POST['semestre_filter'] ?? '';
    $mode_rattrapage = ($_POST['mode_rattrapage'] ?? '0') === '1' ? 1 : 0;
    $selected_matricules = $_POST['selected_matricules'] ?? [];
    $selected_ue_ec_keys = $_POST['selected_ue_ec_keys'] ?? [];

    if (empty($titre)) {
        echo json_encode(['success' => false, 'message' => 'Le titre est obligatoire']);
        return;
    }
    if (empty($id_annee) || empty($id_mention) || empty($code_promotion)) {
        echo json_encode(['success' => false, 'message' => 'Paramètres académiques manquants']);
        return;
    }
    if (empty($selected_matricules) || empty($selected_ue_ec_keys)) {
        echo json_encode(['success' => false, 'message' => 'Veuillez sélectionner au moins un étudiant et une UE/EC']);
        return;
    }

    // Sanitize arrays
    $selected_matricules = array_values(array_filter(array_map('trim', (array)$selected_matricules)));
    $selected_ue_ec_keys = array_values(array_filter(array_map('trim', (array)$selected_ue_ec_keys)));

    try {
        $sql = "INSERT INTO t_grille_speciale 
                (titre, description, id_annee, id_mention, code_promotion, semestre_filter, mode_rattrapage, selected_matricules, selected_ue_ec_keys, cree_par)
                VALUES (:titre, :description, :id_annee, :id_mention, :code_promotion, :semestre_filter, :mode_rattrapage, :matricules, :ue_ec, :cree_par)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'titre' => $titre,
            'description' => $description ?: null,
            'id_annee' => $id_annee,
            'id_mention' => $id_mention,
            'code_promotion' => $code_promotion,
            'semestre_filter' => $semestre_filter,
            'mode_rattrapage' => $mode_rattrapage,
            'matricules' => json_encode($selected_matricules),
            'ue_ec' => json_encode($selected_ue_ec_keys),
            'cree_par' => $_SESSION['user_id']
        ]);

        echo json_encode([
            'success' => true, 
            'message' => 'Grille spéciale enregistrée avec succès',
            'id' => $pdo->lastInsertId()
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'enregistrement : ' . $e->getMessage()]);
    }
}

function listGrillesSpeciales($pdo) {
    $id_annee = $_GET['id_annee'] ?? $_POST['id_annee'] ?? '';
    $id_mention = $_GET['id_mention'] ?? $_POST['id_mention'] ?? '';
    $code_promotion = $_GET['code_promotion'] ?? $_POST['code_promotion'] ?? '';

    try {
        $sql = "SELECT id, titre, description, semestre_filter, mode_rattrapage, cree_par, date_creation, date_modification,
                       JSON_LENGTH(selected_matricules) AS nb_etudiants,
                       JSON_LENGTH(selected_ue_ec_keys) AS nb_ue_ec
                FROM t_grille_speciale
                WHERE id_annee = :annee AND id_mention = :mention AND code_promotion = :promo
                ORDER BY date_creation DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'annee' => $id_annee,
            'mention' => $id_mention,
            'promo' => $code_promotion
        ]);
        $grilles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'grilles' => $grilles]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
    }
}

function loadGrilleSpeciale($pdo) {
    $id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID invalide']);
        return;
    }

    try {
        $sql = "SELECT * FROM t_grille_speciale WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $grille = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$grille) {
            echo json_encode(['success' => false, 'message' => 'Grille non trouvée']);
            return;
        }

        $grille['selected_matricules'] = json_decode($grille['selected_matricules'], true);
        $grille['selected_ue_ec_keys'] = json_decode($grille['selected_ue_ec_keys'], true);

        echo json_encode(['success' => true, 'grille' => $grille]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
    }
}

function deleteGrilleSpeciale($pdo) {
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID invalide']);
        return;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM t_grille_speciale WHERE id = :id");
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Grille supprimée']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Grille non trouvée']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
    }
}
