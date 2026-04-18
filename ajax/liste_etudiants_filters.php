<?php
/**
 * AJAX handler pour les filtres dynamiques de la liste des étudiants.
 * Actions : filieres, mentions, promotions, count
 */
require_once __DIR__ . '/../includes/db_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$action = trim($_GET['action'] ?? '');

try {
    switch ($action) {
        case 'filieres':
            echo json_encode(getFilieres($pdo));
            break;
        case 'mentions':
            echo json_encode(getMentions($pdo));
            break;
        case 'promotions':
            echo json_encode(getPromotions($pdo));
            break;
        case 'count':
            echo json_encode(getCount($pdo));
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action non reconnue']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}

function getFilieres(PDO $pdo): array
{
    $domaineId = (int) ($_GET['domaine'] ?? 0);
    if ($domaineId > 0) {
        $stmt = $pdo->prepare(
            "SELECT idFiliere AS id, nomFiliere AS label
             FROM t_filiere WHERE id_domaine = ? ORDER BY nomFiliere"
        );
        $stmt->execute([$domaineId]);
    } else {
        $stmt = $pdo->query(
            "SELECT idFiliere AS id, nomFiliere AS label
             FROM t_filiere ORDER BY nomFiliere"
        );
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMentions(PDO $pdo): array
{
    $filiereId = (int) ($_GET['filiere'] ?? 0);
    $domaineId = (int) ($_GET['domaine'] ?? 0);

    if ($filiereId > 0) {
        $stmt = $pdo->prepare(
            "SELECT id_mention AS id, libelle AS label
             FROM t_mention WHERE idFiliere = ? ORDER BY libelle"
        );
        $stmt->execute([$filiereId]);
    } elseif ($domaineId > 0) {
        $stmt = $pdo->prepare(
            "SELECT m.id_mention AS id, m.libelle AS label
             FROM t_mention m
             INNER JOIN t_filiere f ON m.idFiliere = f.idFiliere
             WHERE f.id_domaine = ?
             ORDER BY m.libelle"
        );
        $stmt->execute([$domaineId]);
    } else {
        $stmt = $pdo->query(
            "SELECT id_mention AS id, libelle AS label
             FROM t_mention ORDER BY libelle"
        );
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPromotions(PDO $pdo): array
{
    $mentionId = (int) ($_GET['mention'] ?? 0);
    $filiereId = (int) ($_GET['filiere'] ?? 0);
    $domaineId = (int) ($_GET['domaine'] ?? 0);
    $anneeId   = (int) ($_GET['annee'] ?? 0);

    $conditions = [];
    $params     = [];
    $needMentionJoin = false;
    $needFiliereJoin = false;

    if ($anneeId > 0) {
        $conditions[] = 'i.id_annee = ?';
        $params[]     = $anneeId;
    }
    if ($mentionId > 0) {
        $conditions[] = 'i.id_mention = ?';
        $params[]     = $mentionId;
    }
    if ($filiereId > 0) {
        $conditions[]     = 'f.idFiliere = ?';
        $params[]         = $filiereId;
        $needMentionJoin  = true;
        $needFiliereJoin  = true;
    }
    if ($domaineId > 0) {
        $conditions[]     = 'f.id_domaine = ?';
        $params[]         = $domaineId;
        $needMentionJoin  = true;
        $needFiliereJoin  = true;
    }

    if (!empty($conditions)) {
        $joins = '';
        if ($needMentionJoin) {
            $joins .= ' LEFT JOIN t_mention m ON i.id_mention = m.id_mention';
        }
        if ($needFiliereJoin) {
            $joins .= ' LEFT JOIN t_filiere f ON m.idFiliere = f.idFiliere';
        }
        $where = 'WHERE ' . implode(' AND ', $conditions);
        $sql   = "SELECT DISTINCT p.code_promotion AS id, p.nom_promotion AS label
                  FROM t_inscription i
                  INNER JOIN t_promotion p ON i.code_promotion = p.code_promotion
                  $joins
                  $where
                  ORDER BY p.nom_promotion, p.code_promotion";
        $stmt  = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $stmt = $pdo->query(
            "SELECT code_promotion AS id, nom_promotion AS label
             FROM t_promotion ORDER BY nom_promotion, code_promotion"
        );
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCount(PDO $pdo): array
{
    $anneeId   = (int) ($_GET['annee'] ?? 0);
    $domaineId = (int) ($_GET['domaine'] ?? 0);
    $filiereId = (int) ($_GET['filiere'] ?? 0);
    $mentionId = (int) ($_GET['mention'] ?? 0);
    $promotion = trim($_GET['promotion'] ?? '');
    $sexe      = strtoupper(trim($_GET['sexe'] ?? ''));
    $q         = trim($_GET['q'] ?? '');

    if ($anneeId <= 0) {
        $stmtY   = $pdo->query("SELECT CAST(valeur AS UNSIGNED) FROM t_configuration WHERE cle = 'annee_encours' LIMIT 1");
        $anneeId = (int) ($stmtY->fetchColumn() ?: 0);
    }

    $conditions = [];
    $params     = [];

    if ($anneeId > 0) {
        $conditions[] = 'i.id_annee = ?';
        $params[]     = $anneeId;
    }
    if ($domaineId > 0) {
        $conditions[] = 'd.id_domaine = ?';
        $params[]     = $domaineId;
    }
    if ($filiereId > 0) {
        $conditions[] = 'f.idFiliere = ?';
        $params[]     = $filiereId;
    }
    if ($mentionId > 0) {
        $conditions[] = 'i.id_mention = ?';
        $params[]     = $mentionId;
    }
    if ($promotion !== '') {
        $conditions[] = 'i.code_promotion = ?';
        $params[]     = $promotion;
    }
    if ($sexe === 'M' || $sexe === 'F') {
        $conditions[] = 'e.sexe = ?';
        $params[]     = $sexe;
    }
    if ($q !== '') {
        $conditions[] = '(e.matricule LIKE ? OR e.nom_etu LIKE ? OR e.postnom_etu LIKE ? OR e.prenom_etu LIKE ?)';
        $keyword      = '%' . $q . '%';
        $params[]     = $keyword;
        $params[]     = $keyword;
        $params[]     = $keyword;
        $params[]     = $keyword;
    }

    $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $sql = "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN e.sexe = 'M' THEN 1 ELSE 0 END) AS hommes,
                SUM(CASE WHEN e.sexe = 'F' THEN 1 ELSE 0 END) AS femmes,
                COUNT(DISTINCT CONCAT(
                    COALESCE(d.nom_domaine, ''),
                    '||', COALESCE(f.nomFiliere, ''),
                    '||', COALESCE(m.libelle, ''),
                    '||', COALESCE(i.code_promotion, '')
                )) AS sections
            FROM t_inscription i
            INNER JOIN t_etudiant e ON i.matricule = e.matricule
            LEFT JOIN t_mention m ON i.id_mention = m.id_mention
            LEFT JOIN t_filiere f ON m.idFiliere = f.idFiliere
            LEFT JOIN t_domaine d ON f.id_domaine = d.id_domaine
            $where";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total'    => (int) ($row['total'] ?? 0),
        'hommes'   => (int) ($row['hommes'] ?? 0),
        'femmes'   => (int) ($row['femmes'] ?? 0),
        'sections' => (int) ($row['sections'] ?? 0),
    ];
}
