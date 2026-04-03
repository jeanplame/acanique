<?php
/**
 * AJAX Handler pour la programmation des UEs/ECs
 * Route: pages/domaine/ajax/handle_programmation.php
 * 
 * Traite les requêtes AJAX pour programmer/déprogrammer les UEs et ECs
 */

// Désactiver l'affichage des erreurs (pour éviter que du HTML interfère avec la réponse JSON)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Nettoyer tout buffer de sortie qui aurait pu être créé
if (ob_get_level() > 0) {
    ob_clean();
}

// Démarrer session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Définir le type de contenu JSON AVANT toute inclusion
header('Content-Type: application/json; charset=UTF-8');

// Inclure la configuration base de données (3 niveaux: ajax -> domaine -> pages -> racine)
require_once __DIR__ . '/../../../includes/db_config.php';

// Vérifier que c'est une requête POST avec action valide
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Requête invalide']);
    exit;
}

$action = htmlspecialchars($_POST['action']);
$id_element = (int) ($_POST['id'] ?? 0);
$is_programmed = (int) ($_POST['is_programmed'] ?? 0);
$semestre_id = isset($_POST['semestre_id']) && $_POST['semestre_id'] !== '' ? (int) $_POST['semestre_id'] : null;
$mention_id = isset($_POST['mention_id']) && $_POST['mention_id'] !== '' ? (int) $_POST['mention_id'] : null;
$username = isset($_SESSION['user_id']) ? trim($_SESSION['user_id']) : 'system';

// Valider l'action
if (!in_array($action, ['toggle_ue', 'toggle_ec', 'set_ue_semestre'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Action invalide']);
    exit;
}

if ($id_element <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID invalide']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    if ($action === 'toggle_ue') {
        // Récupérer les infos de l'UE avant modification
        $stmt_before = $pdo->prepare("SELECT is_programmed, code_ue, libelle FROM t_unite_enseignement WHERE id_ue = ?");
        $stmt_before->execute([$id_element]);
        $ue_before = $stmt_before->fetch(PDO::FETCH_ASSOC);
        
        if (!$ue_before) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'UE non trouvée']);
            exit;
        }
        
        $ancien_statut = (int) $ue_before['is_programmed'];
        $nouveau_statut = $is_programmed;
        
        // Mettre à jour l'UE
        $stmt_update = $pdo->prepare("UPDATE t_unite_enseignement SET is_programmed = ? WHERE id_ue = ?");
        $stmt_update->execute([$nouveau_statut, $id_element]);
        
        // Enregistrer dans l'audit
        $stmt_audit = $pdo->prepare("
            INSERT INTO t_audit_programmation 
            (type_element, id_element, ancien_statut, nouveau_statut, username, commentaire)
            VALUES ('UE', ?, ?, ?, ?, ?)
        ");
        $stmt_audit->execute([
            $id_element,
            $ancien_statut,
            $nouveau_statut,
            $username,
            "UE: {$ue_before['code_ue']} - {$ue_before['libelle']}"
        ]);
        
    } elseif ($action === 'toggle_ec') {
        // Récupérer les infos de l'EC avant modification
        $stmt_before = $pdo->prepare("SELECT is_programmed, code_ec, libelle FROM t_element_constitutif WHERE id_ec = ?");
        $stmt_before->execute([$id_element]);
        $ec_before = $stmt_before->fetch(PDO::FETCH_ASSOC);
        
        if (!$ec_before) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'EC non trouvé']);
            exit;
        }
        
        $ancien_statut = (int) $ec_before['is_programmed'];
        $nouveau_statut = $is_programmed;
        
        // Mettre à jour l'EC
        $stmt_update = $pdo->prepare("UPDATE t_element_constitutif SET is_programmed = ? WHERE id_ec = ?");
        $stmt_update->execute([$nouveau_statut, $id_element]);
        
        // Enregistrer dans l'audit
        $stmt_audit = $pdo->prepare("
            INSERT INTO t_audit_programmation 
            (type_element, id_element, ancien_statut, nouveau_statut, username, commentaire)
            VALUES ('EC', ?, ?, ?, ?, ?)
        ");
        $stmt_audit->execute([
            $id_element,
            $ancien_statut,
            $nouveau_statut,
            $username,
            "EC: {$ec_before['code_ec']} - {$ec_before['libelle']}"
        ]);
    } elseif ($action === 'set_ue_semestre') {
        $stmt_before = $pdo->prepare("SELECT id_semestre, code_ue, libelle FROM t_unite_enseignement WHERE id_ue = ?");
        $stmt_before->execute([$id_element]);
        $ue_before = $stmt_before->fetch(PDO::FETCH_ASSOC);

        if (!$ue_before) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'UE non trouvée']);
            exit;
        }

        $stmt_update = $pdo->prepare("UPDATE t_unite_enseignement SET id_semestre = ? WHERE id_ue = ?");
        $stmt_update->execute([$semestre_id, $id_element]);

        if (!empty($mention_id)) {
            $stmt_update_mu = $pdo->prepare("UPDATE t_mention_ue SET semestre = ? WHERE id_ue = ? AND id_mention = ?");
            $stmt_update_mu->execute([$semestre_id, $id_element, $mention_id]);
        } else {
            $stmt_update_mu = $pdo->prepare("UPDATE t_mention_ue SET semestre = ? WHERE id_ue = ?");
            $stmt_update_mu->execute([$semestre_id, $id_element]);
        }

        $stmt_audit = $pdo->prepare("
            INSERT INTO t_audit_programmation
            (type_element, id_element, ancien_statut, nouveau_statut, username, commentaire)
            VALUES ('UE', ?, ?, ?, ?, ?)
        ");
        $stmt_audit->execute([
            $id_element,
            $ue_before['id_semestre'] !== null ? (int) $ue_before['id_semestre'] : 0,
            $semestre_id !== null ? (int) $semestre_id : 0,
            $username,
            "Semestre UE: {$ue_before['code_ue']} - {$ue_before['libelle']}"
        ]);
    }
    
    // Valider la transaction
    $pdo->commit();
    
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Mise à jour réussie']);
    exit;
    
} catch (Exception $e) {
    // Erreur - annuler la transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur: ' . $e->getMessage()]);
    exit;
}
