<?php
/**
 * Handler AJAX pour la gestion des nominations du jury
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

// Vérification d'authentification
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non authentifié']);
    exit;
}

// Vérification du rôle administrateur
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'administrateur') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit;
}

require_once __DIR__ . '/../includes/db_config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        listJuryMembers($pdo);
        break;
    case 'add':
        addJuryMember($pdo);
        break;
    case 'update':
        updateJuryMember($pdo);
        break;
    case 'delete':
        deleteJuryMember($pdo);
        break;
    case 'get':
        getJuryMember($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Action inconnue']);
}

/**
 * Lister les membres du jury pour un domaine et une année
 */
function listJuryMembers($pdo) {
    $id_domaine = filter_input(INPUT_GET, 'id_domaine', FILTER_VALIDATE_INT);
    $id_annee = filter_input(INPUT_GET, 'id_annee', FILTER_VALIDATE_INT);

    if (!$id_domaine || !$id_annee) {
        echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT jn.*, d.nom_domaine, d.code_domaine
            FROM t_jury_nomination jn
            JOIN t_domaine d ON jn.id_domaine = d.id_domaine
            WHERE jn.id_domaine = :id_domaine AND jn.id_annee = :id_annee
            ORDER BY FIELD(jn.role_jury, 'president', 'secretaire', 'membre'), jn.ordre_affichage, jn.nom_complet
        ");
        $stmt->execute([':id_domaine' => $id_domaine, ':id_annee' => $id_annee]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $members]);
    } catch (PDOException $e) {
        error_log("Erreur listJuryMembers: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des données']);
    }
}

/**
 * Ajouter un membre du jury
 */
function addJuryMember($pdo) {
    $id_domaine = filter_input(INPUT_POST, 'id_domaine', FILTER_VALIDATE_INT);
    $id_annee = filter_input(INPUT_POST, 'id_annee', FILTER_VALIDATE_INT);
    $nom_complet = trim($_POST['nom_complet'] ?? '');
    $titre_academique = trim($_POST['titre_academique'] ?? '');
    $fonction = trim($_POST['fonction'] ?? '');
    $role_jury = trim($_POST['role_jury'] ?? '');

    // Validation
    if (!$id_domaine || !$id_annee || empty($nom_complet) || empty($role_jury)) {
        echo json_encode(['success' => false, 'message' => 'Tous les champs obligatoires doivent être remplis']);
        return;
    }

    if (!in_array($role_jury, ['president', 'secretaire', 'membre'])) {
        echo json_encode(['success' => false, 'message' => 'Rôle de jury invalide']);
        return;
    }

    // Vérifier qu'il n'y a qu'un seul président par domaine/année
    if ($role_jury === 'president') {
        try {
            $check = $pdo->prepare("
                SELECT COUNT(*) FROM t_jury_nomination 
                WHERE id_domaine = :id_domaine AND id_annee = :id_annee AND role_jury = 'president'
            ");
            $check->execute([':id_domaine' => $id_domaine, ':id_annee' => $id_annee]);
            if ($check->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Un président de jury existe déjà pour ce domaine. Veuillez d\'abord modifier ou supprimer le président actuel.']);
                return;
            }
        } catch (PDOException $e) {
            error_log("Erreur vérification président: " . $e->getMessage());
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO t_jury_nomination (id_domaine, id_annee, nom_complet, titre_academique, fonction, role_jury, ordre_affichage)
            VALUES (:id_domaine, :id_annee, :nom_complet, :titre_academique, :fonction, :role_jury, :ordre)
        ");

        // Calculer l'ordre d'affichage
        $orderStmt = $pdo->prepare("
            SELECT COALESCE(MAX(ordre_affichage), 0) + 1 
            FROM t_jury_nomination 
            WHERE id_domaine = :id_domaine AND id_annee = :id_annee AND role_jury = :role_jury
        ");
        $orderStmt->execute([':id_domaine' => $id_domaine, ':id_annee' => $id_annee, ':role_jury' => $role_jury]);
        $ordre = $orderStmt->fetchColumn();

        $stmt->execute([
            ':id_domaine' => $id_domaine,
            ':id_annee' => $id_annee,
            ':nom_complet' => $nom_complet,
            ':titre_academique' => $titre_academique ?: null,
            ':fonction' => $fonction ?: null,
            ':role_jury' => $role_jury,
            ':ordre' => $ordre
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Membre du jury ajouté avec succès',
            'id' => $pdo->lastInsertId()
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo json_encode(['success' => false, 'message' => 'Cette personne est déjà nominée avec ce rôle pour ce domaine']);
        } else {
            error_log("Erreur addJuryMember: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'ajout']);
        }
    }
}

/**
 * Modifier un membre du jury
 */
function updateJuryMember($pdo) {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $nom_complet = trim($_POST['nom_complet'] ?? '');
    $titre_academique = trim($_POST['titre_academique'] ?? '');
    $fonction = trim($_POST['fonction'] ?? '');
    $role_jury = trim($_POST['role_jury'] ?? '');

    if (!$id || empty($nom_complet) || empty($role_jury)) {
        echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
        return;
    }

    if (!in_array($role_jury, ['president', 'secretaire', 'membre'])) {
        echo json_encode(['success' => false, 'message' => 'Rôle de jury invalide']);
        return;
    }

    // Vérifier unicité du président si changement vers président
    if ($role_jury === 'president') {
        try {
            $check = $pdo->prepare("
                SELECT jn.id_domaine, jn.id_annee FROM t_jury_nomination jn WHERE jn.id = :id
            ");
            $check->execute([':id' => $id]);
            $current = $check->fetch();

            if ($current) {
                $checkPres = $pdo->prepare("
                    SELECT COUNT(*) FROM t_jury_nomination 
                    WHERE id_domaine = :id_domaine AND id_annee = :id_annee AND role_jury = 'president' AND id != :id
                ");
                $checkPres->execute([
                    ':id_domaine' => $current['id_domaine'],
                    ':id_annee' => $current['id_annee'],
                    ':id' => $id
                ]);
                if ($checkPres->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'message' => 'Un autre président de jury existe déjà pour ce domaine']);
                    return;
                }
            }
        } catch (PDOException $e) {
            error_log("Erreur vérification président update: " . $e->getMessage());
        }
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE t_jury_nomination 
            SET nom_complet = :nom_complet, titre_academique = :titre_academique, 
                fonction = :fonction, role_jury = :role_jury
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':nom_complet' => $nom_complet,
            ':titre_academique' => $titre_academique ?: null,
            ':fonction' => $fonction ?: null,
            ':role_jury' => $role_jury
        ]);

        echo json_encode(['success' => true, 'message' => 'Membre du jury modifié avec succès']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo json_encode(['success' => false, 'message' => 'Cette nomination existe déjà']);
        } else {
            error_log("Erreur updateJuryMember: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erreur lors de la modification']);
        }
    }
}

/**
 * Supprimer un membre du jury
 */
function deleteJuryMember($pdo) {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID manquant']);
        return;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM t_jury_nomination WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Membre du jury supprimé avec succès']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Membre introuvable']);
        }
    } catch (PDOException $e) {
        error_log("Erreur deleteJuryMember: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression']);
    }
}

/**
 * Récupérer un membre du jury par ID
 */
function getJuryMember($pdo) {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID manquant']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM t_jury_nomination WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($member) {
            echo json_encode(['success' => true, 'data' => $member]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Membre introuvable']);
        }
    } catch (PDOException $e) {
        error_log("Erreur getJuryMember: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération']);
    }
}
