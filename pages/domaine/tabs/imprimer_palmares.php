<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * IMPRESSION DU PALMARÈS
 * Format officiel conforme LMD/RDC
 * Logique EXACTE de deliberation.php
 */

require_once '../../../includes/db_config.php';
session_start();

// Récupération des paramètres
$id_annee = $_GET['annee'] ?? $_SESSION['id_annee'] ?? 1;
$code_promo = $_GET['promotion'] ?? $_SESSION['promotion'] ?? null;
$id_mention = $_GET['mention'] ?? '';
$mode_rattrapage = isset($_GET['rattrapage']) && $_GET['rattrapage'] === '1';

if (empty($id_mention) || empty($id_annee)) {
    die("Erreur : Paramètres manquants");
}

// Récupération des informations
$sqlMention = "SELECT m.libelle, f.nomFiliere 
               FROM t_mention m 
               INNER JOIN t_filiere f ON m.idFiliere = f.idFiliere 
               WHERE m.id_mention = ?";
$stmtMention = $pdo->prepare($sqlMention);
$stmtMention->execute([$id_mention]);
$mention_info = $stmtMention->fetch(PDO::FETCH_ASSOC);
$mention = $mention_info['libelle'] ?? '';
$filiere = $mention_info['nomFiliere'] ?? '';

$sqlAnnee = "SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?";
$stmtAnnee = $pdo->prepare($sqlAnnee);
$stmtAnnee->execute([$id_annee]);
$annee = $stmtAnnee->fetch(PDO::FETCH_ASSOC);
$annee_academique = date('Y', strtotime($annee['date_debut'])) . '-' . date('Y', strtotime($annee['date_fin']));

// =====================================================================
// LOGIQUE EXACTE DE deliberation.php
// =====================================================================

$params = ['annee' => $id_annee];
if ($id_mention)
    $params['mention'] = $id_mention;
if ($code_promo)
    $params['promo'] = $code_promo;

// Étape 1: Structure
$sql_structure = "
    SELECT DISTINCT
        matricule, nom_complet, code_ue, libelle_ue, credits,
        code_ec, libelle_ec, coef_ec, id_semestre, semestre_mention,
        code_promotion, id_ue, id_ec
    FROM vue_grille_deliberation
    WHERE id_annee = :annee
    " . ($id_mention ? "AND id_mention = :mention" : "") . "
    " . ($code_promo ? "AND code_promotion = :promo" : "") . "
    ORDER BY nom_complet, code_ue, code_ec
";

$stmt = $pdo->prepare($sql_structure);
$stmt->execute($params);
$structure = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Étape 2: Cotes
$cotes_recentes = [];
if (!empty($structure)) {
    // Cotes EC
    $sql_cotes_ec = "
        SELECT c.matricule, c.id_ec, c.id_ue, c.cote_s1, c.cote_s2,
               c.cote_rattrapage_s1, c.cote_rattrapage_s2, c.id_note
        FROM t_cote c
        INNER JOIN (
            SELECT matricule, id_ec, MAX(id_note) as max_id_note
            FROM t_cote
            WHERE id_annee = :annee
            " . ($id_mention ? "AND id_mention = :mention" : "") . "
            AND id_ec IS NOT NULL
            GROUP BY matricule, id_ec
        ) latest_ec ON c.matricule = latest_ec.matricule 
        AND c.id_ec = latest_ec.id_ec AND c.id_note = latest_ec.max_id_note
        WHERE c.id_annee = :annee
        " . ($id_mention ? "AND c.id_mention = :mention" : "") . "
        AND c.id_ec IS NOT NULL
    ";

    $stmt_cotes_ec = $pdo->prepare($sql_cotes_ec);
    $stmt_cotes_ec->execute($params);
    while ($row = $stmt_cotes_ec->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['matricule'] . '_EC_' . $row['id_ec'];
        $cotes_recentes[$key] = $row;
    }

    // Cotes UE
    $sql_cotes_ue = "
        SELECT c.matricule, c.id_ec, c.id_ue, c.cote_s1, c.cote_s2,
               c.cote_rattrapage_s1, c.cote_rattrapage_s2, c.id_note
        FROM t_cote c
        INNER JOIN (
            SELECT matricule, id_ue, MAX(id_note) as max_id_note
            FROM t_cote
            WHERE id_annee = :annee
            " . ($id_mention ? "AND id_mention = :mention" : "") . "
            AND id_ec IS NULL AND id_ue IS NOT NULL
            GROUP BY matricule, id_ue
        ) latest_ue ON c.matricule = latest_ue.matricule 
        AND c.id_ue = latest_ue.id_ue AND c.id_note = latest_ue.max_id_note
        WHERE c.id_annee = :annee
        " . ($id_mention ? "AND c.id_mention = :mention" : "") . "
        AND c.id_ec IS NULL AND c.id_ue IS NOT NULL
    ";

    $stmt_cotes_ue = $pdo->prepare($sql_cotes_ue);
    $stmt_cotes_ue->execute($params);
    while ($row = $stmt_cotes_ue->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['matricule'] . '_UE_' . $row['id_ue'];
        $cotes_recentes[$key] = $row;
    }
}

// Étape 3: Fusionner
$resultats_bruts = [];
foreach ($structure as $row) {
    $matricule = $row['matricule'];

    if (!empty($row['id_ec']) && $row['id_ec'] != $row['id_ue'] && $row['code_ec'] != $row['code_ue']) {
        $key = $matricule . '_EC_' . $row['id_ec'];
    } else {
        $key = $matricule . '_UE_' . $row['id_ue'];
    }

    $cote_data = $cotes_recentes[$key] ?? null;

    $row['cote_s1'] = $cote_data['cote_s1'] ?? null;
    $row['cote_s2'] = $cote_data['cote_s2'] ?? null;
    $row['cote_rattrapage_s1'] = $cote_data['cote_rattrapage_s1'] ?? null;
    $row['cote_rattrapage_s2'] = $cote_data['cote_rattrapage_s2'] ?? null;

    if ($mode_rattrapage) {
        // LOGIQUE EXACTE de deliberation.php (lignes 207-215)
        $cote_rattrapage_s1 = $cote_data['cote_rattrapage_s1'] ?? 0;
        $cote_s1 = $cote_data['cote_s1'] ?? 0;
        $row['meilleure_cote_s1'] = ($cote_rattrapage_s1 > $cote_s1) ? $cote_rattrapage_s1 : $cote_s1;
        $row['est_rattrapage_s1'] = ($cote_rattrapage_s1 > $cote_s1) ? 1 : 0;

        $cote_rattrapage_s2 = $cote_data['cote_rattrapage_s2'] ?? 0;
        $cote_s2 = $cote_data['cote_s2'] ?? 0;
        $row['meilleure_cote_s2'] = ($cote_rattrapage_s2 > $cote_s2) ? $cote_rattrapage_s2 : $cote_s2;
        $row['est_rattrapage_s2'] = ($cote_rattrapage_s2 > $cote_s2) ? 1 : 0;

        // Moyenne avec rattrapage
        if ($row['code_ec'] != $row['code_ue']) {
            $row['moyenne_ec'] = ($row['meilleure_cote_s1'] + $row['meilleure_cote_s2']) / 2;
        } else {
            $row['moyenne_ec'] = $row['meilleure_cote_s1'];
        }
    } else {
        if ($row['code_ec'] != $row['code_ue']) {
            $row['moyenne_ec'] = (($cote_data['cote_s1'] ?? 0) + ($cote_data['cote_s2'] ?? 0)) / 2;
        } else {
            $row['moyenne_ec'] = $cote_data['cote_s1'] ?? 0;
        }
    }

    $resultats_bruts[] = $row;
}

// Étape 4: Organiser
$etudiants = [];
$ues = [];

foreach ($resultats_bruts as $row) {
    $mat = $row['matricule'];
    $codeUE = $row['code_ue'];
    $codeEC = $row['code_ec'];
    $semestre = (int) ($row['semestre_mention'] ?? $row['id_semestre']);

    if (!isset($ues[$codeUE])) {
        $ues[$codeUE] = [
            'libelle' => $row['libelle_ue'],
            'credits' => isset($row['credits']) ? (float) $row['credits'] : 0,
            'ecs' => []
        ];
    }

    $isUeSansEc = ($codeEC === $codeUE) || empty($codeEC);

    if ($isUeSansEc) {
        $ues[$codeUE]['ecs'][$codeEC] = [
            'libelle' => $row['libelle_ec'] ?? $row['libelle_ue'],
            'coef' => null,
            'semestre' => $semestre,
            'is_ue_sans_ec' => true
        ];
    } else {
        if (!isset($ues[$codeUE]['ecs'][$codeEC])) {
            $ues[$codeUE]['ecs'][$codeEC] = [
                'libelle' => $row['libelle_ec'],
                'coef' => isset($row['coef_ec']) ? (float) $row['coef_ec'] : 1,
                'semestre' => $semestre,
                'is_ue_sans_ec' => false
            ];
        }
    }

    if (!isset($etudiants[$mat])) {
        $etudiants[$mat] = ['nom' => $row['nom_complet'], 'notes' => []];
    }

    if (!isset($etudiants[$mat]['notes'][$codeUE])) {
        $etudiants[$mat]['notes'][$codeUE] = [];
    }

    if (!isset($etudiants[$mat]['notes'][$codeUE][$codeEC])) {
        $etudiants[$mat]['notes'][$codeUE][$codeEC] = ['s1' => null, 's2' => null];
    }

    // Stocker les notes en fonction du mode (LOGIQUE EXACTE de deliberation.php lignes 339-369)
    if ($mode_rattrapage) {
        $etudiants[$mat]['notes'][$codeUE][$codeEC] = [
            's1' => $row['meilleure_cote_s1'],
            's2' => $row['meilleure_cote_s2'],
            'est_rattrapage_s1' => $row['est_rattrapage_s1'],
            'est_rattrapage_s2' => $row['est_rattrapage_s2'],
            'note_normale_s1' => $row['cote_s1'],
            'note_normale_s2' => $row['cote_s2'],
            'note_rattrapage_s1' => $row['cote_rattrapage_s1'],
            'note_rattrapage_s2' => $row['cote_rattrapage_s2']
        ];
    } else {
        $etudiants[$mat]['notes'][$codeUE][$codeEC] = [
            's1' => $row['cote_s1'],
            's2' => $row['cote_s2']
        ];
    }
}

// FILTRAGE SELON LE MODE
if ($mode_rattrapage) {
    // MODE RATTRAPAGE: Ne garder QUE les étudiants avec notes de rattrapage
    $etudiants_rattrapage = [];
    foreach ($etudiants as $matricule => $etudiant) {
        $a_rattrapage = false;

        // Vérifier si l'étudiant a au moins une note de rattrapage
        foreach ($etudiant['notes'] as $codeUE => $ues_notes) {
            foreach ($ues_notes as $codeEC => $ec_notes) {
                $a_cote_rattrapage = false;

                // Méthode 1: Vérifier les flags de rattrapage
                if (
                    (isset($ec_notes['est_rattrapage_s1']) && $ec_notes['est_rattrapage_s1']) ||
                    (isset($ec_notes['est_rattrapage_s2']) && $ec_notes['est_rattrapage_s2'])
                ) {
                    $a_cote_rattrapage = true;
                }

                // Méthode 2: Vérifier directement les cotes de rattrapage
                if (!$a_cote_rattrapage) {
                    if (
                        (isset($ec_notes['note_rattrapage_s1']) && $ec_notes['note_rattrapage_s1'] > 0) ||
                        (isset($ec_notes['note_rattrapage_s2']) && $ec_notes['note_rattrapage_s2'] > 0)
                    ) {
                        $a_cote_rattrapage = true;
                    }
                }

                // Méthode 3: Si les meilleures cotes sont différentes des cotes normales
                if (!$a_cote_rattrapage) {
                    if (
                        (isset($ec_notes['s1']) && isset($ec_notes['note_normale_s1']) &&
                            $ec_notes['s1'] != $ec_notes['note_normale_s1'] && $ec_notes['s1'] > 0) ||
                        (isset($ec_notes['s2']) && isset($ec_notes['note_normale_s2']) &&
                            $ec_notes['s2'] != $ec_notes['note_normale_s2'] && $ec_notes['s2'] > 0)
                    ) {
                        $a_cote_rattrapage = true;
                    }
                }

                if ($a_cote_rattrapage) {
                    $a_rattrapage = true;
                    break 2; // Sortir des deux boucles
                }
            }
        }

        // Ne garder que les étudiants qui ont des notes de rattrapage
        if ($a_rattrapage) {
            $etudiants_rattrapage[$matricule] = $etudiant;
        }
    }

    $etudiants = $etudiants_rattrapage;

    // Debug: Afficher le nombre d'étudiants filtrés
    if (empty($etudiants)) {
        error_log("PALMARES RATTRAPAGE: Aucun étudiant avec notes de rattrapage trouvé!");
    } else {
        error_log("PALMARES RATTRAPAGE: " . count($etudiants) . " étudiant(s) avec notes de rattrapage");
    }
} else {
    // MODE NORMAL: EXCLURE les étudiants qui ont des notes de rattrapage
    $etudiants_normaux = [];
    foreach ($etudiants as $matricule => $etudiant) {
        $a_rattrapage = false;

        // Vérifier si l'étudiant a au moins une note de rattrapage
        foreach ($etudiant['notes'] as $codeUE => $ues_notes) {
            foreach ($ues_notes as $codeEC => $ec_notes) {
                // Vérifier dans les données brutes si rattrapage existe
                $key_ec = $matricule . '_EC_' . $codeEC;
                $key_ue = $matricule . '_UE_' . $codeUE;

                // Chercher dans cotes_recentes
                $cote_info = null;
                if (isset($cotes_recentes[$key_ec])) {
                    $cote_info = $cotes_recentes[$key_ec];
                } elseif (isset($cotes_recentes[$key_ue])) {
                    $cote_info = $cotes_recentes[$key_ue];
                }

                if ($cote_info) {
                    if (
                        (isset($cote_info['cote_rattrapage_s1']) && $cote_info['cote_rattrapage_s1'] > 0) ||
                        (isset($cote_info['cote_rattrapage_s2']) && $cote_info['cote_rattrapage_s2'] > 0)
                    ) {
                        $a_rattrapage = true;
                        break 2;
                    }
                }
            }
        }

        // En mode normal, on ne garde QUE ceux qui n'ont PAS de rattrapage
        if (!$a_rattrapage) {
            $etudiants_normaux[$matricule] = $etudiant;
        }
    }

    $etudiants = $etudiants_normaux;

    // Debug: Afficher le nombre d'étudiants filtrés
    error_log("PALMARES NORMAL: " . count($etudiants) . " étudiant(s) sans notes de rattrapage");
}

// Séparer UE
$ues_s1 = [];
$ues_s2 = [];
foreach ($ues as $codeUE => $ue) {
    foreach ($ue['ecs'] as $codeEC => $ec) {
        $sem = $ec['semestre'] ?? 0;
        $isPlaceholder = !empty($ec['is_ue_sans_ec']);

        if ($sem === 1) {
            if (!isset($ues_s1[$codeUE])) {
                $ues_s1[$codeUE] = ['libelle' => $ue['libelle'], 'credits' => 0, 'ecs' => []];
            }
            if ($isPlaceholder) {
                $ues_s1[$codeUE]['ecs'][$codeEC] = [
                    'libelle' => $ec['libelle'] ?? $ue['libelle'],
                    'coef' => null,
                    'credits' => $ue['credits'] ?? 0,
                    'is_ue_sans_ec' => true
                ];
                $ues_s1[$codeUE]['credits'] += $ue['credits'] ?? 0;
            } else {
                $coef = $ec['coef'] ?? 1;
                $ues_s1[$codeUE]['ecs'][$codeEC] = $ec;
                $ues_s1[$codeUE]['ecs'][$codeEC]['credits'] = $coef;
                $ues_s1[$codeUE]['credits'] += $coef;
            }
        } elseif ($sem === 2) {
            if (!isset($ues_s2[$codeUE])) {
                $ues_s2[$codeUE] = ['libelle' => $ue['libelle'], 'credits' => 0, 'ecs' => []];
            }
            if ($isPlaceholder) {
                $ues_s2[$codeUE]['ecs'][$codeEC] = [
                    'libelle' => $ec['libelle'] ?? $ue['libelle'],
                    'coef' => null,
                    'credits' => $ue['credits'] ?? 0,
                    'is_ue_sans_ec' => true
                ];
                $ues_s2[$codeUE]['credits'] += $ue['credits'] ?? 0;
            } else {
                $coef = $ec['coef'] ?? 1;
                $ues_s2[$codeUE]['ecs'][$codeEC] = $ec;
                $ues_s2[$codeUE]['ecs'][$codeEC]['credits'] = $coef;
                $ues_s2[$codeUE]['credits'] += $coef;
            }
        }
    }
}

// Fonctions
function getMentionLMD($moyenne)
{
    if ($moyenne >= 18)
        return 'Excellence';
    if ($moyenne >= 16)
        return 'Très Bien';
    if ($moyenne >= 14)
        return 'Bien';
    if ($moyenne >= 12)
        return 'Assez Bien';
    if ($moyenne >= 10)
        return 'Passable';
    return 'Défaillants';
}

function getCodeMention($moyenne)
{
    if ($moyenne >= 18)
        return 'A';
    if ($moyenne >= 16)
        return 'B';
    if ($moyenne >= 14)
        return 'C';
    if ($moyenne >= 12)
        return 'D';
    if ($moyenne >= 10)
        return 'E';
    return 'F';
}

// Infos étudiants
$sqlEtudiants = "SELECT matricule, nom_etu, postnom_etu, prenom_etu, sexe, nationalite 
                 FROM t_etudiant WHERE matricule IN ('" . implode("','", array_keys($etudiants)) . "')";
$stmtEtudiants = $pdo->query($sqlEtudiants);
$infos_etudiants = [];
while ($row = $stmtEtudiants->fetch(PDO::FETCH_ASSOC)) {
    $infos_etudiants[$row['matricule']] = $row;
}

// Calculer palmarès (LOGIQUE EXACTE de deliberation.php)
$palmares = [];
foreach ($etudiants as $mat => $data) {
    // S1
    $totalS1 = 0;
    $creditsS1 = 0;
    $totalCoefS1 = 0;
    foreach ($ues_s1 as $codeUE => $ue) {
        foreach ($ue['ecs'] as $codeEC => $ec) {
            $val = isset($data['notes'][$codeUE][$codeEC]['s1']) ? $data['notes'][$codeUE][$codeEC]['s1'] : null;
            $coef = isset($ec['is_ue_sans_ec']) && $ec['is_ue_sans_ec'] ? ($ec['credits'] ?? $ue['credits'] ?? 1) : ($ec['coef'] ?? 1);
            if ($val !== null && $val !== '' && is_numeric($val) && $val > 0) {
                $totalS1 += $val * $coef;
                $totalCoefS1 += $coef;
            }
        }
    }
    foreach ($ues_s1 as $codeUE => $ue) {
        $ueValidee = false;
        foreach ($ue['ecs'] as $codeEC => $ec) {
            $val = $data['notes'][$codeUE][$codeEC]['s1'] ?? null;
            if ($val !== null && $val > 0 && $val >= 10) {
                $ueValidee = true;
                break;
            }
        }
        if ($ueValidee) {
            $creditsS1 += $ue['credits'];
        }
    }

    // S2
    $totalS2 = 0;
    $creditsS2 = 0;
    $totalCoefS2 = 0;
    foreach ($ues_s2 as $codeUE => $ue) {
        foreach ($ue['ecs'] as $codeEC => $ec) {
            $val = isset($data['notes'][$codeUE][$codeEC]['s2']) ? $data['notes'][$codeUE][$codeEC]['s2'] : null;
            $coef = isset($ec['is_ue_sans_ec']) && $ec['is_ue_sans_ec'] ? ($ec['credits'] ?? $ue['credits'] ?? 1) : ($ec['coef'] ?? 1);
            if ($val !== null && $val !== '' && is_numeric($val) && $val > 0) {
                $totalS2 += $val * $coef;
                $totalCoefS2 += $coef;
            }
        }
    }
    foreach ($ues_s2 as $codeUE => $ue) {
        $ueValidee = false;
        foreach ($ue['ecs'] as $codeEC => $ec) {
            $val = $data['notes'][$codeUE][$codeEC]['s2'] ?? null;
            if ($val !== null && $val > 0 && $val >= 10) {
                $ueValidee = true;
                break;
            }
        }
        if ($ueValidee) {
            $creditsS2 += $ue['credits'];
        }
    }

    // ANNUEL
    $totalCoefAnnuel = $totalCoefS1 + $totalCoefS2;
    $totalAnnuel = $totalS1 + $totalS2;
    $moyenneAnnuelle = $totalCoefAnnuel > 0 ? $totalAnnuel / $totalCoefAnnuel : 0;
    $creditsValides = $creditsS1 + $creditsS2;
    $pourcentage = $totalCoefAnnuel > 0 ? ($creditsValides / $totalCoefAnnuel) * 100 : 0;

    // NOUVELLE LOGIQUE: Compter le nombre d'échecs (notes < 10)
    $nombreEchecs = 0;

    // Compter échecs S1
    foreach ($ues_s1 as $codeUE => $ue) {
        foreach ($ue['ecs'] as $codeEC => $ec) {
            $val = $data['notes'][$codeUE][$codeEC]['s1'] ?? null;
            if ($val !== null && $val > 0 && $val < 10) {
                $nombreEchecs++;
            }
        }
    }

    // Compter échecs S2
    foreach ($ues_s2 as $codeUE => $ue) {
        foreach ($ue['ecs'] as $codeEC => $ec) {
            $val = $data['notes'][$codeUE][$codeEC]['s2'] ?? null;
            if ($val !== null && $val > 0 && $val < 10) {
                $nombreEchecs++;
            }
        }
    }

    // Décision: ADMIS si 0, 1 ou 2 échecs maximum
    $decision = ($nombreEchecs <= 2) ? 'ADMIS' : 'AJOURNÉ';

    $info = $infos_etudiants[$mat] ?? [];

    $palmares[] = [
        'matricule' => $mat,
        'nom' => $info['nom_etu'] ?? '',
        'postnom' => $info['postnom_etu'] ?? '',
        'prenom' => $info['prenom_etu'] ?? '',
        'sexe' => $info['sexe'] ?? '',
        'nationalite' => $info['nationalite'] ?? '',
        'nom_complet' => $data['nom'],
        'moyenne' => $moyenneAnnuelle,
        'credits_valides' => $creditsValides,
        'total_credits' => $totalCoefAnnuel,
        'pourcentage' => $pourcentage,
        'mention' => getMentionLMD($moyenneAnnuelle),
        'code_mention' => getCodeMention($moyenneAnnuelle),
        'decision' => $decision,
        'nombre_echecs' => $nombreEchecs
    ];
}

// Trier
usort($palmares, function ($a, $b) {
    return $b['moyenne'] <=> $a['moyenne'];
});

// Statistiques
$mentions_stats = [
    'Excellence' => 0,
    'Très Bien' => 0,
    'Bien' => 0,
    'Assez Bien' => 0,
    'Passable' => 0,
    'Défaillants' => 0
];

foreach ($palmares as $resultat) {
    $mentions_stats[$resultat['mention']]++;
}

// Grouper par mention
$palmares_par_mention = [
    'Excellence' => [],
    'Très Bien' => [],
    'Bien' => [],
    'Assez Bien' => [],
    'Passable' => [],
    'Défaillants' => []
];

foreach ($palmares as $resultat) {
    $palmares_par_mention[$resultat['mention']][] = $resultat;
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palmarès
        <?= htmlspecialchars($_GET['promotion'] ?? '') . " " . htmlspecialchars($mention) . " " . htmlspecialchars($annee_academique) ?>
    </title>
    <style>
        @page {
            margin: 15mm;
        }

        body {
            font-family: 'Century Gothic', sans-serif;
            font-size: 11pt;
            line-height: 1.3;
            color: #000;
        }


        .stats-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
            margin-left: auto;
        }

        .stats-table th,
        .stats-table td {
            border: 1px solid #000;
            padding: 4px 8px;
            text-align: left;
        }

        .stats-table th {
            background-color: #b6b5b5ff;
            font-weight: bold;
            text-align: left;
        }

        .stats-table td.number {
            text-align: right;
            font-weight: normal;
        }


        .palmares-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            margin-bottom: 20px;
        }

        .palmares-table th,
        .palmares-table td {
            border: 1px solid #000;
            padding: 5px 4px;
            text-align: left;
        }

        .palmares-table th {
            background-color: #d0d0d0;
            font-weight: bold;
            text-align: center;
            font-size: 8.5pt;
        }

        .palmares-table td.center {
            text-align: center;
        }

        .palmares-table td.number {
            text-align: center;
            font-weight: bold;
        }

        .mention-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 8pt;
        }

        .mention-A {
            background-color: #28a745;
            color: white;
        }

        .mention-B {
            background-color: #17a2b8;
            color: white;
        }

        .mention-C {
            background-color: #007bff;
            color: white;
        }

        .mention-D {
            background-color: #ffc107;
            color: #000;
        }

        .mention-E {
            background-color: #6c757d;
            color: white;
        }

        .mention-F {
            background-color: #dc3545;
            color: white;
        }

        .decision-admis {
            color: #28a745;
            font-weight: bold;
        }

        .decision-ajourne {
            color: #dc3545;
            font-weight: bold;
        }

        @media print {
            .page-break {
                page-break-before: always;
            }

            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9pt;
            border-top: 2px solid #000;
            padding-top: 10px;
        }

        .entetez {
            width: 100%;
            margin-bottom: 25px;
        }

        .bloc1 {
            display: inline-block;
            width: 49%;
            float: left;
            padding-top: 30px;
        }

        .bloc2 {
            display: inline-block;
            width: 49%;
            
        }

        .title-box {
            border: 2px solid #000;
            border-radius: 50px;
            padding: 5px;
            text-align: center;
            margin: auto;
            background-color: #b6b5b5ff;
            font-size: 12px;
            font-weight: bold;
            width: 85%;

        }
        .stats-header{
            text-align: center;
            width: 100%;
        }
        .info-section{
            vertical-align: top;
            text-align: center;
        }
        .info-section p{
            line-height: 1.4;
            margin: 0;
        }
    </style>
</head>

<body>
    <div class="entetez">

        <div>
            <div class="bloc1">
                <div class="title-box">
                    PALMARÈS DE LA <?= $mode_rattrapage ? '2<sup>ère</sup>' : '1<sup>ère</sup>' ?> SESSION
                </div>
                <div class="info-section">
                    <p>Année académique : <strong><?= $annee_academique ?></strong></p>
                    <p>SECTION : <strong><?= strtoupper(htmlspecialchars($filiere)) ?></strong></p>
                    <p>PROMOTION : <strong><?= strtoupper($code_promo ?? 'N/A') ?>
                            <?= htmlspecialchars($mention) ?></strong></p>
                </div>
            </div>
            <div class="bloc2">
                <div class="stats-header">Tableau statistique</div>
                <div class="entete-stat">
                    <table class="stats-table">
                        <thead>
                            <tr style="background-color: #505050ff !important; font-weight: bold;">
                                <th>Grade CECT/Appréciations</th>
                                <th>Effectif</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Excellence (A)</td>
                                <td class="number"><?= $mentions_stats['Excellence'] ?></td>
                            </tr>
                            <tr>
                                <td>Très Bien (B)</td>
                                <td class="number"><?= $mentions_stats['Très Bien'] ?></td>
                            </tr>
                            <tr>
                                <td>Bien (C)</td>
                                <td class="number"><?= $mentions_stats['Bien'] ?></td>
                            </tr>
                            <tr>
                                <td>Assez Bien (D)</td>
                                <td class="number"><?= $mentions_stats['Assez Bien'] ?></td>
                            </tr>
                            <tr>
                                <td>Passable (E)</td>
                                <td class="number"><?= $mentions_stats['Passable'] ?></td>
                            </tr>
                            <tr>
                                <td>Insuffisant (F)</td>
                                <td class="number"><?= $mentions_stats['Défaillants'] ?></td>
                            </tr>
                            <tr>
                                <td>Inadmissible (G)</td>
                                <td class="number">0</td>
                            </tr>
                            <tr style="background-color: #e8f5e9;">
                                <td><strong>Assimilés aux insatisfaisants (AI)</strong></td>
                                <td class="number"><strong>0</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>


    <div class="main-content">
        <?php
        // Définir l'ordre d'affichage des mentions
        $ordre_mentions = ['Excellence', 'Très Bien', 'Bien', 'Assez Bien', 'Passable', 'Défaillants'];
        $rang_global = 0; // Numérotation continue pour le podium
        
        foreach ($ordre_mentions as $mention_nom):
            if (empty($palmares_par_mention[$mention_nom]))
                continue;

            $code_mention_map = [
                'Excellence' => 'A',
                'Très Bien' => 'B',
                'Bien' => 'C',
                'Assez Bien' => 'D',
                'Passable' => 'E',
                'Défaillants' => 'F'
            ];
            $code = $code_mention_map[$mention_nom];

            // Couleur de fond pour le titre selon la mention
            $bg_colors = [
                'Excellence' => '#28a745',
                'Très Bien' => '#17a2b8',
                'Bien' => '#007bff',
                'Assez Bien' => '#ffc107',
                'Passable' => '#6c757d',
                'Défaillants' => '#dc3545'
            ];
            $bg_color = $bg_colors[$mention_nom] ?? '#333';
            ?>
            <div class="section-title" style="background-color: <?= $bg_color ?>;">
                <?= $mention_nom ?> (<?= $code ?>) - <?= count($palmares_par_mention[$mention_nom]) ?> étudiant(s)
            </div>
            <table class="palmares-table">
                <thead>
                    <tr>
                        <th style="width: 30px;">N°</th>
                        <th style="width: 120px;">NOM</th>
                        <th style="width: 120px;">POSTNOM</th>
                        <th style="width: 120px;">PRÉNOM</th>
                        <th style="width: 35px;">SEXE</th>
                        <th style="width: 80px;">MATRICULE</th>
                        <th style="width: 60px;">NATIONALITÉ</th>
                        <th style="width: 60px;">MOYENNE/20</th>
                        <th style="width: 60px;">CRÉDITS VALIDÉS</th>
                        <th style="width: 70px;">DÉCISION JURY</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rang_local = 0;
                    foreach ($palmares_par_mention[$mention_nom] as $index => $resultat):
                        $rang_local++;
                        $rang_global++;

                        $decision_class = $resultat['decision'] === 'ADMIS' ? 'decision-admis' : 'decision-ajourne';
                        ?>
                        <tr>
                            <td class="center"><?= $rang_local ?></td>
                            <td><?= htmlspecialchars(mb_strtoupper($resultat['nom'])) ?></td>
                            <td><?= htmlspecialchars(mb_strtoupper($resultat['postnom'])) ?></td>
                            <td><?= htmlspecialchars(ucwords(strtolower($resultat['prenom']))) ?></td>
                            <td class="center"><?= htmlspecialchars($resultat['sexe']) ?></td>
                            <td class="center"><?= htmlspecialchars($resultat['matricule']) ?></td>
                            <td class="center"><?= htmlspecialchars($resultat['nationalite']) ?></td>
                            <td class="number"><?= number_format($resultat['moyenne'], 2) ?></td>
                            <td class="center"><?= number_format($resultat['credits_valides'], 0) ?></td>
                            <td class="center <?= $decision_class ?>"><?= $resultat['decision'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </div>

    <div class="footer">
        <p>Kabinda, le <?= date('d/m/Y') ?></p>
        <p><strong>UNILO - Université Notre Dame de Lomami</strong></p>
    </div>

    <script>
        window.onload = function () {
            window.print();
        };
    </script>
</body>

</html>