<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * PALMARÈS - Système de classement LMD/RDC
 * Génère le palmarès officiel après délibération
 * Conforme aux normes du système LMD en République Démocratique du Congo
 * 
 * Utilise EXACTEMENT la même logique que deliberation.php
 */

// Inclure les fonctions nécessaires
require_once 'includes/domaine_functions.php';

// ================================================
// RÉCUPÉRATION DE L'ANNÉE ACADÉMIQUE
// Priorité: 1. URL ($_GET['annee']), 2. Année en cours
// ================================================
$id_annee = getAnneeFromUrlOrDefault($pdo, $_GET['annee'] ?? null);

if (!$id_annee) {
    echo "<div class='alert alert-danger'>Erreur : Aucune année académique configurée. Veuillez contacter l'administrateur.</div>";
    return;
}

// Récupération des autres filtres
$code_promo = $_GET['promotion'] ?? null;
$id_mention = $_GET['mention'] ?? '';
$mode_rattrapage = isset($_GET['rattrapage']) && $_GET['rattrapage'] === '1';

// Validation des paramètres
if (empty($id_mention)) {
    echo "<div class='alert alert-danger'>Erreur : ID mention manquant pour afficher le palmarès</div>";
    return;
}

// Récupération des informations de la mention
$sqlMention = "SELECT libelle FROM t_mention WHERE id_mention=?";
$stmtMention = $pdo->prepare($sqlMention);
$stmtMention->execute([$id_mention]);
$mention = $stmtMention->fetchColumn();

// Récupération de l'année académique
$sqlAnnee = "SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?";
$stmtAnnee = $pdo->prepare($sqlAnnee);
$stmtAnnee->execute([$id_annee]);
$annee = $stmtAnnee->fetch(PDO::FETCH_ASSOC);
$annee_academique = date('Y', strtotime($annee['date_debut'])) . '-' . date('Y', strtotime($annee['date_fin']));

// =====================================================================
// LOGIQUE EXACTE DE deliberation.php
// =====================================================================

// Paramètres
$id_semestre = null; // Toujours annuel pour le palmarès
$params = ['annee' => $id_annee];
if ($id_mention) $params['mention'] = $id_mention;
if ($code_promo) $params['promo'] = $code_promo;

// Étape 1: Récupérer la structure
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

// Étape 2: Récupérer les cotes
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

// Étape 4: Organiser par étudiant (COMME deliberation.php)
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

    // Stocker les notes en fonction du mode (LOGIQUE EXACTE de deliberation.php)
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
                if ((isset($ec_notes['est_rattrapage_s1']) && $ec_notes['est_rattrapage_s1']) ||
                    (isset($ec_notes['est_rattrapage_s2']) && $ec_notes['est_rattrapage_s2'])) {
                    $a_cote_rattrapage = true;
                }
                
                // Méthode 2: Vérifier directement les cotes de rattrapage
                if (!$a_cote_rattrapage) {
                    if ((isset($ec_notes['note_rattrapage_s1']) && $ec_notes['note_rattrapage_s1'] > 0) ||
                        (isset($ec_notes['note_rattrapage_s2']) && $ec_notes['note_rattrapage_s2'] > 0)) {
                        $a_cote_rattrapage = true;
                    }
                }
                
                // Méthode 3: Si les meilleures cotes sont différentes des cotes normales
                if (!$a_cote_rattrapage) {
                    if ((isset($ec_notes['s1']) && isset($ec_notes['note_normale_s1']) && 
                         $ec_notes['s1'] != $ec_notes['note_normale_s1'] && $ec_notes['s1'] > 0) ||
                        (isset($ec_notes['s2']) && isset($ec_notes['note_normale_s2']) && 
                         $ec_notes['s2'] != $ec_notes['note_normale_s2'] && $ec_notes['s2'] > 0)) {
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
                    if ((isset($cote_info['cote_rattrapage_s1']) && $cote_info['cote_rattrapage_s1'] > 0) ||
                        (isset($cote_info['cote_rattrapage_s2']) && $cote_info['cote_rattrapage_s2'] > 0)) {
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

// Séparer UE par semestre
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

// Fonction mention
function getMentionLMD($moyenne) {
    if ($moyenne >= 18) return 'Excellence';
    if ($moyenne >= 16) return 'Très Bien';
    if ($moyenne >= 14) return 'Bien';
    if ($moyenne >= 12) return 'Assez Bien';
    if ($moyenne >= 10) return 'Passable';
    return 'Défaillants';
}

function getCodeMention($moyenne) {
    if ($moyenne >= 18) return 'A';
    if ($moyenne >= 16) return 'B';
    if ($moyenne >= 14) return 'C';
    if ($moyenne >= 12) return 'D';
    if ($moyenne >= 10) return 'E';
    return 'F';
}

// Calculer le palmarès (LOGIQUE EXACTE de deliberation.php)
$palmares = [];
foreach ($etudiants as $mat => $data) {
    // Calcul S1 (COMME deliberation.php lignes 1376-1400)
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
    
    // Calcul S2 (COMME deliberation.php)
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
    
    // Calcul ANNUEL (COMME deliberation.php lignes 1554-1560)
    $totalCoefAnnuel = $totalCoefS1 + $totalCoefS2;
    $totalAnnuel = $totalS1 + $totalS2;
    $moyenneAnnuelle = $totalCoefAnnuel > 0 ? $totalAnnuel / $totalCoefAnnuel : 0;
    $creditsValides = $creditsS1 + $creditsS2;
    $pourcentage = $totalCoefAnnuel > 0 ? ($creditsValides / $totalCoefAnnuel) * 100 : 0;
    
    // Compter le nombre d'échecs (notes < 10) - Critère LMD : admis avec max 2 échecs
    $nombreEchecs = 0;
    
    // Compter les échecs S1
    foreach ($ues_s1 as $codeUE => $ue) {
        foreach ($ue['ecs'] as $codeEC => $ec) {
            $val = $data['notes'][$codeUE][$codeEC]['s1'] ?? null;
            if ($val !== null && $val > 0 && $val < 10) {
                $nombreEchecs++;
            }
        }
    }
    
    // Compter les échecs S2
    foreach ($ues_s2 as $codeUE => $ue) {
        foreach ($ue['ecs'] as $codeEC => $ec) {
            $val = $data['notes'][$codeUE][$codeEC]['s2'] ?? null;
            if ($val !== null && $val > 0 && $val < 10) {
                $nombreEchecs++;
            }
        }
    }
    
    // Décision : ADMIS si maximum 2 échecs, sinon AJOURNÉ
    $decision = ($nombreEchecs <= 2) ? 'ADMIS' : 'AJOURNÉ';
    
    $palmares[] = [
        'matricule' => $mat,
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
usort($palmares, function($a, $b) {
    return $b['moyenne'] <=> $a['moyenne'];
});

// Statistiques
$total_etudiants = count($palmares);
$total_admis = 0;
$total_ajournes = 0;
$mentions_stats = [
    'Excellence' => 0, 'Très Bien' => 0, 'Bien' => 0,
    'Assez Bien' => 0, 'Passable' => 0, 'Défaillants' => 0
];

foreach ($palmares as $resultat) {
    $mentions_stats[$resultat['mention']]++;
    if ($resultat['decision'] === 'ADMIS') {
        $total_admis++;
    } else {
        $total_ajournes++;
    }
}

$taux_reussite = $total_etudiants > 0 ? ($total_admis / $total_etudiants) * 100 : 0;
?>

<!-- Interface du Palmarès -->
<div class="container-fluid mt-4">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-trophy-fill me-2"></i>
                    Palmarès - <?= htmlspecialchars($mention) ?> (<?= $annee_academique ?>)
                </h5>
                <div>
                    <a href="pages/domaine/tabs/imprimer_palmares.php?mention=<?= $id_mention ?>&annee=<?= $id_annee ?>&rattrapage=<?= $mode_rattrapage ? '1' : '0' ?><?= $code_promo ? '&promotion=' . urlencode($code_promo) : '' ?>" 
                       class="btn btn-light btn-sm" target="_blank">
                        <i class="bi bi-printer-fill me-1"></i> Imprimer
                    </a>
                </div>
            </div>
        </div>

        <div class="card-body">
            <!-- Filtres -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Type de session</label>
                    <div class="btn-group w-100" role="group">
                        <?php 
                        $base_url = "index.php?page=domaine&action=view&id=" . ($_GET['id'] ?? '') . 
                                    "&mention=" . $id_mention . "&tab=notes&promotion=" . $code_promo . "&tabnotes=palmares";
                        ?>
                        <input type="radio" class="btn-check" name="session_type" id="session_normale" value="normale" 
                               <?= !$mode_rattrapage ? 'checked' : '' ?> onchange="location.href='<?= $base_url ?>&rattrapage=0'">
                        <label class="btn btn-outline-primary" for="session_normale">Session Normale</label>
                        
                        <input type="radio" class="btn-check" name="session_type" id="session_rattrapage" value="rattrapage" 
                               <?= $mode_rattrapage ? 'checked' : '' ?> onchange="location.href='<?= $base_url ?>&rattrapage=1'">
                        <label class="btn btn-outline-primary" for="session_rattrapage">Avec Rattrapage</label>
                    </div>
                </div>
            </div>

            <!-- Statistiques -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0"><?= $total_etudiants ?></h3>
                            <small>Total Étudiants</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0"><?= $total_admis ?></h3>
                            <small>Admis (<?= number_format($taux_reussite, 1) ?>%)</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-danger text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0"><?= $total_ajournes ?></h3>
                            <small>Ajournés</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h3 class="mb-0"><?= number_format($palmares[0]['moyenne'] ?? 0, 2) ?>/20</h3>
                            <small>Meilleure Moyenne</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Répartition des mentions -->
            <div class="row mb-4">
                <div class="col-12">
                    <h6 class="fw-bold mb-3">Répartition par mention</h6>
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Mention</th>
                                <th>Code</th>
                                <th>Effectif</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="table-success">
                                <td>Excellence (≥ 18/20)</td>
                                <td>A</td>
                                <td><?= $mentions_stats['Excellence'] ?></td>
                            </tr>
                            <tr class="table-success">
                                <td>Très Bien (≥ 16/20)</td>
                                <td>B</td>
                                <td><?= $mentions_stats['Très Bien'] ?></td>
                            </tr>
                            <tr class="table-info">
                                <td>Bien (≥ 14/20)</td>
                                <td>C</td>
                                <td><?= $mentions_stats['Bien'] ?></td>
                            </tr>
                            <tr class="table-warning">
                                <td>Assez Bien (≥ 12/20)</td>
                                <td>D</td>
                                <td><?= $mentions_stats['Assez Bien'] ?></td>
                            </tr>
                            <tr>
                                <td>Passable (≥ 10/20)</td>
                                <td>E</td>
                                <td><?= $mentions_stats['Passable'] ?></td>
                            </tr>
                            <tr class="table-danger">
                                <td>Défaillants (< 10/20)</td>
                                <td>F</td>
                                <td><?= $mentions_stats['Défaillants'] ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Classement des étudiants -->
            <div class="row">
                <div class="col-12">
                    <h6 class="fw-bold mb-3">Classement général</h6>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Rang</th>
                                    <th>Matricule</th>
                                    <th>Nom complet</th>
                                    <th class="text-center">Moyenne/20</th>
                                    <th class="text-center">Crédits validés</th>
                                    <th class="text-center">Mention</th>
                                    <th class="text-center">Décision</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $rang = 0;
                                foreach ($palmares as $index => $resultat): 
                                    $rang++;
                                    $row_class = '';
                                    if ($resultat['decision'] === 'AJOURNÉ') $row_class = 'table-danger';
                                ?>
                                <tr class="<?= $row_class ?>">
                                    <td><?= $rang ?></td>
                                    <td><?= htmlspecialchars($resultat['matricule']) ?></td>
                                    <td><?= htmlspecialchars($resultat['nom_complet']) ?></td>
                                    <td class="text-center fw-bold"><?= number_format($resultat['moyenne'], 2) ?></td>
                                    <td class="text-center"><?= number_format($resultat['credits_valides'], 0) ?></td>
                                    <td class="text-center">
                                        <?php
                                        $badge_color = 'secondary';
                                        switch($resultat['code_mention']) {
                                            case 'A':
                                            case 'B':
                                                $badge_color = 'success';
                                                break;
                                            case 'C':
                                                $badge_color = 'info';
                                                break;
                                            case 'D':
                                                $badge_color = 'warning';
                                                break;
                                            case 'E':
                                                $badge_color = 'secondary';
                                                break;
                                            case 'F':
                                                $badge_color = 'danger';
                                                break;
                                        }
                                        ?>
                                        <span class="badge bg-<?= $badge_color ?>">
                                            <?= htmlspecialchars($resultat['mention']) ?> (<?= $resultat['code_mention'] ?>)
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $resultat['decision'] === 'ADMIS' ? 'success' : 'danger' ?>">
                                            <?= $resultat['decision'] ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

