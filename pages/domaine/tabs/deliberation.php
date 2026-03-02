<?php
// Inclure les fonctions nécessaires
require_once 'includes/domaine_functions.php';
    header('Content-Type: text/html; charset=UTF-8');

// ================================================
// RÉCUPÉRATION DE L'ANNÉE ACADÉMIQUE
// Priorité: 1. URL ($_GET['annee']), 2. Année en cours
// ================================================
$id_annee = getAnneeFromUrlOrDefault($pdo, $_GET['annee'] ?? null);

if (!$id_annee) {
    echo "<div class='alert alert-danger'>Erreur : Aucune année académique configurée. Veuillez contacter l'administrateur.</div>";
    return;
}

// Récupération des autres filtres depuis GET
$id_semestre = isset($_GET['semestre']) && in_array($_GET['semestre'], ['1', '2']) ? (int) $_GET['semestre'] : null;
$code_promo = $_GET['promotion'] ?? null;
$id_mention = $_GET['mention'] ?? '';

// Validation des paramètres obligatoires
if (empty($id_mention)) {
    echo "<div class='alert alert-danger'>Erreur : ID mention manquant</div>";
    return;
}

// Récupération du nom de la mention à partir de l'id mention de le vue dans la table t_mention
$sqlMention = "SELECT libelle FROM t_mention WHERE id_mention=?";
$stmtMention = $pdo->prepare($sqlMention);
$stmtMention->execute([$id_mention]);
$mention = $stmtMention->fetchColumn();

// Extraire l'année académique à partir de l'id_annee
$sqlAnnee = "SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?";
$stmtAnnee = $pdo->prepare($sqlAnnee);
$stmtAnnee->execute([$id_annee]);
$annee = $stmtAnnee->fetch(PDO::FETCH_ASSOC);
$annee_academique = date('Y', strtotime($annee['date_debut'])) . '-' . date('Y', strtotime($annee['date_fin']));


// Déterminer les flags d'affichage
$afficher_s1 = $id_semestre === 1;
$afficher_s2 = $id_semestre === 2;
$afficher_tous = is_null($id_semestre);

// Définir semestre_filter pour les formulaires - utiliser la même logique que les autres boutons
$semestre_filter = isset($_GET['semestre']) && in_array($_GET['semestre'], ['1', '2']) ? $_GET['semestre'] : '';

// Ajouter le paramètre pour choisir entre vue normale et vue avec rattrapage
$mode_rattrapage = isset($_GET['rattrapage']) && $_GET['rattrapage'] === '1';

// *** UTILISATION UNIQUE DE vue_grille_deliberation (qui fonctionne parfaitement) ***
// La logique de rattrapage sera gérée dans le code PHP, pas dans une vue séparée
// Étape 1: Récupérer la structure des cours (UE/EC) depuis la vue standard
$sql_structure = "
    SELECT DISTINCT
        matricule,
        nom_complet,
        code_ue,
        libelle_ue,
        credits,
        code_ec,
        libelle_ec,
        coef_ec,
        id_semestre,
        semestre_mention,
        code_promotion,
        id_ue,
        id_ec
    FROM vue_grille_deliberation
    WHERE id_annee = :annee
    " . ($id_mention ? "AND id_mention = :mention" : "") . "
    " . ($id_semestre ? "AND semestre_mention = :semestre" : "") . "
    " . ($code_promo ? "AND code_promotion = :promo" : "") . "
    ORDER BY nom_complet, code_ue, code_ec
";

$stmt = $pdo->prepare($sql_structure);

// Paramètres dynamiques
$params = ['annee' => $id_annee];
if ($id_mention)
    $params['mention'] = $id_mention;
if ($id_semestre)
    $params['semestre'] = $id_semestre;
if ($code_promo)
    $params['promo'] = $code_promo;

$stmt->execute($params);
$structure = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Étape 2: Récupérer toutes les cotes récentes (EC et UE séparément)
$cotes_recentes = [];
if (!empty($structure)) {
    // SOLUTION CORRIGÉE: Récupérer les cotes EC et UE avec la bonne logique
    
    // Partie 1: Cotes des EC (éléments constitutifs)
    $sql_cotes_ec = "
        SELECT 
            c.matricule,
            c.id_ec,
            c.id_ue,
            c.cote_s1,
            c.cote_s2,
            c.cote_rattrapage_s1,
            c.cote_rattrapage_s2,
            c.date_rattrapage_s1,
            c.date_rattrapage_s2,
            c.id_note
        FROM t_cote c
        INNER JOIN (
            SELECT 
                matricule,
                id_ec,
                MAX(id_note) as max_id_note
            FROM t_cote
            WHERE id_annee = :annee
            " . ($id_mention ? "AND id_mention = :mention" : "") . "
            AND id_ec IS NOT NULL
            GROUP BY matricule, id_ec
        ) latest_ec ON c.matricule = latest_ec.matricule 
        AND c.id_ec = latest_ec.id_ec 
        AND c.id_note = latest_ec.max_id_note
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
    
    // Partie 2: Cotes des UE (unités d'enseignement sans EC)
    $sql_cotes_ue = "
        SELECT 
            c.matricule,
            c.id_ec,
            c.id_ue,
            c.cote_s1,
            c.cote_s2,
            c.cote_rattrapage_s1,
            c.cote_rattrapage_s2,
            c.date_rattrapage_s1,
            c.date_rattrapage_s2,
            c.id_note
        FROM t_cote c
        INNER JOIN (
            SELECT 
                matricule,
                id_ue,
                MAX(id_note) as max_id_note
            FROM t_cote
            WHERE id_annee = :annee
            " . ($id_mention ? "AND id_mention = :mention" : "") . "
            AND id_ec IS NULL
            AND id_ue IS NOT NULL
            GROUP BY matricule, id_ue
        ) latest_ue ON c.matricule = latest_ue.matricule 
        AND c.id_ue = latest_ue.id_ue 
        AND c.id_note = latest_ue.max_id_note
        WHERE c.id_annee = :annee
        " . ($id_mention ? "AND c.id_mention = :mention" : "") . "
        AND c.id_ec IS NULL
        AND c.id_ue IS NOT NULL
    ";
    
    $stmt_cotes_ue = $pdo->prepare($sql_cotes_ue);
    $stmt_cotes_ue->execute($params);
    
    while ($row = $stmt_cotes_ue->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['matricule'] . '_UE_' . $row['id_ue'];
        $cotes_recentes[$key] = $row;
    }
}

// Étape 3: Fusionner structure et cotes avec la logique corrigée
$resultats = [];
foreach ($structure as $row) {
    $matricule = $row['matricule'];
    
    // LOGIQUE CORRIGÉE: Déterminer la bonne clé selon le type d'élément
    // Un EC distinct a : id_ec IS NOT NULL ET id_ec != id_ue ET id_ec != '' 
    if (!empty($row['id_ec']) && $row['id_ec'] != $row['id_ue'] && $row['code_ec'] != $row['code_ue']) {
        // C'est un EC (élément constitutif distinct de l'UE)
        $key = $matricule . '_EC_' . $row['id_ec'];
        $element_type = 'EC';
    } else {
        // C'est une UE sans EC (id_ec NULL, vide, ou égal à id_ue)
        $key = $matricule . '_UE_' . $row['id_ue'];
        $element_type = 'UE';
    }
    
    // Récupérer les cotes correspondantes avec la bonne clé
    $cote_data = $cotes_recentes[$key] ?? null;
    
    // DEBUG: Ajouter info pour diagnostic
    $row['debug_element_type'] = $element_type;
    $row['debug_key'] = $key;
    $row['debug_cote_found'] = $cote_data ? 'OUI' : 'NON';
    
    // Fusionner les données
    $row['cote_s1'] = $cote_data['cote_s1'] ?? null;
    $row['cote_s2'] = $cote_data['cote_s2'] ?? null;
    $row['cote_rattrapage_s1'] = $cote_data['cote_rattrapage_s1'] ?? null;
    $row['cote_rattrapage_s2'] = $cote_data['cote_rattrapage_s2'] ?? null;
    $row['date_rattrapage_s1'] = $cote_data['date_rattrapage_s1'] ?? null;
    $row['date_rattrapage_s2'] = $cote_data['date_rattrapage_s2'] ?? null;
    
    // Calculs pour mode rattrapage
    if ($mode_rattrapage) {
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
        // Mode normal
        if ($row['code_ec'] != $row['code_ue']) {
            $row['moyenne_ec'] = (($cote_data['cote_s1'] ?? 0) + ($cote_data['cote_s2'] ?? 0)) / 2;
        } else {
            $row['moyenne_ec'] = $cote_data['cote_s1'] ?? 0;
        }
    }
    
    $resultats[] = $row;
}

// Debug : afficher les paramètres et le nombre de résultats
if (empty($resultats)) {
    echo "<div class='alert alert-warning'>Aucune donnée trouvée. Paramètres: ";
    echo "Année: $id_annee, Mention: $id_mention, Semestre: $id_semestre, Promotion: $code_promo";
    echo "</div>";
}




// Jointure Récupération du nom de la filière à partir de l'id de la mention
$sqlFiliere = "SELECT f.nomFiliere, f.idFiliere 
               FROM t_filiere f 
               INNER JOIN t_mention m ON f.idFiliere = m.idFiliere 
               WHERE m.id_mention = ?";
$stmtFiliere = $pdo->prepare($sqlFiliere);
$stmtFiliere->execute([$id_mention]);
$filiere = $stmtFiliere->fetchColumn();
$resultFilieres = $stmtFiliere->fetchAll(PDO::FETCH_ASSOC);
$id_filiere = $resultFilieres[0]['idFiliere'] ?? null;

// Jointure de récupération du nom de domaine à partir de l'id_domaine de la table filière
$sqlDomaine = "SELECT d.nom_domaine as domaine 
               FROM t_domaine d 
               INNER JOIN t_filiere f ON d.id_domaine = f.id_domaine 
               INNER JOIN t_mention m ON f.idFiliere = m.idFiliere 
               WHERE m.id_mention = ?";
$stmtDomaine = $pdo->prepare($sqlDomaine);
$stmtDomaine->execute([$id_mention]);
$domaine = $stmtDomaine->fetchColumn();



// Organisation des données
$etudiants = [];
$ues = [];

// Créer un cache des IDs d'UE et EC pour les liens
$ue_ids = [];
$ec_ids = [];

foreach ($resultats as $row) {
    $mat = $row['matricule'];
    $codeUE = $row['code_ue'];
    $codeEC = $row['code_ec'];
    $semestre = (int) ($row['semestre_mention'] ?? $row['id_semestre']); // Utiliser semestre_mention (1 ou 2) si disponible

    // Initialiser l'UE
    if (!isset($ues[$codeUE])) {
        $ues[$codeUE] = [
            'libelle' => $row['libelle_ue'],
            'credits' => isset($row['credits']) ? (float) $row['credits'] : 0,
            'ecs' => []
        ];
    }
    
    // Cacher les IDs pour les liens - utiliser directement ceux de la structure
    if (!isset($ue_ids[$codeUE]) && !empty($row['id_ue'])) {
        $ue_ids[$codeUE] = $row['id_ue'];
    }
    
    if (!empty($codeEC) && !isset($ec_ids[$codeEC]) && !empty($row['id_ec'])) {
        $ec_ids[$codeEC] = $row['id_ec'];
    }

    // Détecter UE sans EC (placeholder)
    $isUeSansEc = ($codeEC === $codeUE) || (isset($row['id_ec']) && strpos($row['id_ec'], 'UE_') === 0) || empty($codeEC);

    if ($isUeSansEc) {
        // On garde un "ec" placeholder pour connaître le semestre mais on utilisera le crédit UE
        $ues[$codeUE]['ecs'][$codeEC] = [
            'libelle' => $row['libelle_ec'] ?? $row['libelle_ue'],
            'coef' => null,
            'semestre' => $semestre,
            'is_ue_sans_ec' => true
        ];
    } else {
        // EC normal : stocker le coef sous la clé 'coef'
        if (!isset($ues[$codeUE]['ecs'][$codeEC])) {
            $ues[$codeUE]['ecs'][$codeEC] = [
                'libelle' => $row['libelle_ec'],
                'coef' => isset($row['coef_ec']) ? (float) $row['coef_ec'] : 1,
                'semestre' => $semestre,
                'is_ue_sans_ec' => false
            ];
        }
    }

    // Notes par étudiant (inchangé)
    if (!isset($etudiants[$mat])) {
        $etudiants[$mat] = [
            'nom' => $row['nom_complet'],
            'notes' => []
        ];
    }

    if (!isset($etudiants[$mat]['notes'][$codeUE])) {
        $etudiants[$mat]['notes'][$codeUE] = [];
    }

    if (!isset($etudiants[$mat]['notes'][$codeUE][$codeEC])) {
        $etudiants[$mat]['notes'][$codeUE][$codeEC] = [
            's1' => null,
            's2' => null,
            'moy' => null
        ];
    }

    // Stocker les notes en fonction du mode - Avec la nouvelle logique, on a toujours toutes les données
    if ($mode_rattrapage) {
        $etudiants[$mat]['notes'][$codeUE][$codeEC] = [
            's1' => $row['meilleure_cote_s1'],
            's2' => $row['meilleure_cote_s2'],
            'moy' => $row['moyenne_ec'],
            'est_rattrapage_s1' => $row['est_rattrapage_s1'],
            'est_rattrapage_s2' => $row['est_rattrapage_s2'],
            'note_normale_s1' => $row['cote_s1'],
            'note_normale_s2' => $row['cote_s2'],
            'note_rattrapage_s1' => $row['cote_rattrapage_s1'],
            'note_rattrapage_s2' => $row['cote_rattrapage_s2']
        ];
    } else {
        // En mode normal, on affiche les notes normales uniquement
        $etudiants[$mat]['notes'][$codeUE][$codeEC] = [
            's1' => $row['cote_s1'],  // Garder null au lieu de convertir en 0
            's2' => $row['cote_s2'],  // Garder null au lieu de convertir en 0
            'moy' => $row['moyenne_ec'],
            // En mode normal, on n'a pas accès aux données de rattrapage depuis la vue standard
            'meilleure_s1' => $row['cote_s1'],
            'meilleure_s2' => $row['cote_s2'],
            'est_rattrapage_s1' => 0,
            'est_rattrapage_s2' => 0
        ];
    }
}

// Supprimer la première ligne vide si elle existe
if (isset($etudiants['']) && empty($etudiants['']['nom'])) {
    unset($etudiants['']);
}

// En mode rattrapage, filtrer pour ne garder que les étudiants qui ont des notes de rattrapage
if ($mode_rattrapage) {
    $etudiants_rattrapage = [];
    foreach ($etudiants as $matricule => $etudiant) {
        $a_rattrapage = false;
        
        // Vérifier si l'étudiant a au moins une note de rattrapage
        foreach ($etudiant['notes'] as $codeUE => $ues_notes) {
            foreach ($ues_notes as $codeEC => $ec_notes) {
                // Vérification multiple : flag de rattrapage OU cotes de rattrapage présentes
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
                if (!$a_cote_rattrapage && $mode_rattrapage) {
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
}


// Construire listes UE/EC par semestre (les "credits" d'un EC = son coef ; pour UE sans EC on garde credit UE)
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
                // UE sans EC : la "cellule" représente l'UE entière -> on utilise le crédit UE
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
                $ues_s1[$codeUE]['ecs'][$codeEC]['credits'] = $coef; // crédit EC = coef
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



// Fonctions de calcul

// Total des notes pondérées pour un semestre
function calcTotalNotesPonderees($notes, $ues, $semestre)
{
    $total = 0;
    foreach ($ues as $codeUE => $ue) {
        // Cas 1 : UE sans EC → utiliser son crédit directement
        if (empty($ue['ecs'])) {
            $note = $notes[$codeUE]['ue'][$semestre] ?? null; // note directe d'UE si existe
            if (!is_null($note)) {
                $total += $note * $ue['credits'];
            }
        } else {
            // Cas 2 : UE avec EC → utiliser les coefficients des EC
            foreach ($ue['ecs'] as $codeEC => $ec) {
                if (isset($notes[$codeUE][$codeEC])) {
                    $key = $semestre === 1 ? 's1' : 's2';
                    $note = $notes[$codeUE][$codeEC][$key] ?? null;
                    if (!is_null($note)) {
                        $coef = $ec['coef'] ?? 1;
                        $total += $note * $coef;
                    }
                }
            }
        }
    }
    return $total;
}

// Total des coefficients / crédits
function calcTotalCoef($ues)
{
    $total = 0;
    foreach ($ues as $ue) {
        if (empty($ue['ecs'])) {
            $total += $ue['credits'] ?? 0;
        } else {
            foreach ($ue['ecs'] as $ec) {
                $coef = $ec['coef'] ?? 1;
                $total += $coef;
            }
        }
    }
    return $total;
}

// Coefficients validés (somme des coefs/crédits des EC validés)
function calcCoefsValides($notes, $ues)
{
    $totalCoefsValides = 0;
    foreach ($ues as $codeUE => $ue) {
        if (empty($ue['ecs'])) {
            // UE sans EC → validation sur la note UE
            $noteS1 = $notes[$codeUE]['ue']['s1'] ?? null;
            $noteS2 = $notes[$codeUE]['ue']['s2'] ?? null;
            // Vérifier que la note existe, est supérieure à 0 et >= 10
            if (($noteS1 !== null && $noteS1 > 0 && $noteS1 >= 10) || ($noteS2 !== null && $noteS2 > 0 && $noteS2 >= 10)) {
                $totalCoefsValides += $ue['credits'];
            }
        } else {
            foreach ($ue['ecs'] as $codeEC => $ec) {
                if (isset($notes[$codeUE][$codeEC])) {
                    $noteS1 = $notes[$codeUE][$codeEC]['s1'] ?? null;
                    $noteS2 = $notes[$codeUE][$codeEC]['s2'] ?? null;
                    $coef = $ec['coef'] ?? 1;

                    // Vérifier que la note existe, est supérieure à 0 et >= 10
                    if (($noteS1 !== null && $noteS1 > 0 && $noteS1 >= 10) || ($noteS2 !== null && $noteS2 > 0 && $noteS2 >= 10)) {
                        $totalCoefsValides += $coef;
                    }
                }
            }
        }
    }
    return $totalCoefsValides;
}

// Total des crédits validés pour un étudiant
function calcCreditsValides($notes, $ues)
{
    $totalCredits = 0;
    foreach ($ues as $codeUE => $ue) {
        if (empty($ue['ecs'])) {
            // UE sans EC → validation sur la note UE
            $noteS1 = $notes[$codeUE]['ue']['s1'] ?? null;
            $noteS2 = $notes[$codeUE]['ue']['s2'] ?? null;
            // Vérifier que la note existe, est supérieure à 0 et >= 10
            if (($noteS1 !== null && $noteS1 > 0 && $noteS1 >= 10) || ($noteS2 !== null && $noteS2 > 0 && $noteS2 >= 10)) {
                $totalCredits += $ue['credits'];
            }
        } else {
            // UE avec EC → validée si tous ses EC (ou une partie) sont validés
            $ueValidee = false;
            foreach ($ue['ecs'] as $codeEC => $ec) {
                if (isset($notes[$codeUE][$codeEC])) {
                    $noteS1 = $notes[$codeUE][$codeEC]['s1'] ?? null;
                    $noteS2 = $notes[$codeUE][$codeEC]['s2'] ?? null;
                    // Vérifier que la note existe, est supérieure à 0 et >= 10
                    if (($noteS1 !== null && $noteS1 > 0 && $noteS1 >= 10) || ($noteS2 !== null && $noteS2 > 0 && $noteS2 >= 10)) {
                        $ueValidee = true;
                        break;
                    }
                }
            }
            if ($ueValidee) {
                // Crédit de l'UE = somme des coefs de ses EC
                foreach ($ue['ecs'] as $ec) {
                    $totalCredits += $ec['coef'] ?? 1;
                }
            }
        }
    }
    return $totalCredits;
}

// Moyenne pondérée
function calcMoyennePonderee($totalNotes, $totalCredits)
{
    return $totalCredits ? $totalNotes / $totalCredits : 0;
}

// Mention
function getMention($moy)
{
    if ($moy >= 18)
        return 'A';
    if ($moy >= 16)
        return 'B';
    if ($moy >= 14)
        return 'C';
    if ($moy >= 12)
        return 'D';
    if ($moy >= 10)
        return 'E';
    return 'F';
}

// Total des crédits (UE sans EC = crédits UE, UE avec EC = somme coefs EC)
function calcTotalCredits($ues)
{
    $total = 0;
    foreach ($ues as $ue) {
        if (empty($ue['ecs'])) {
            $total += $ue['credits'] ?? 0;
        } else {
            foreach ($ue['ecs'] as $ec) {
                $total += $ec['coef'] ?? 1;
            }
        }
    }
    return $total;
}

?>


<style>
    table {
        width: 100%;
        border-collapse: collapse;
        font-family: Arial, sans-serif;
        font-size: 11px;
        border: 1px solid #000;
        /* Bordure globale */
    }

    table th,
    table td {
        border: 1px solid #000;
        /* Bordures visibles partout */
        text-align: center;
        padding: 1px;
    }
    
    /* Styles pour mise en forme conditionnelle */
    .note-echec {
        background-color: #ffebee !important;
    }
    
    .note-manquante {
        background-color: #f5f5f5 !important;
    }
    
    /* Style pour les notes de rattrapage */
    .note-rattrapage {
        background-color: #fff3cd !important;
        border: 2px solid #d39e00 !important;
    }
    
    /* Indicateur pour les notes de rattrapage */
    .rattrapage-indicator {
        color: #d63031;
        font-weight: bold;
        font-size: 8px;
        vertical-align: super;
    }

    thead td {
        background-color: #f4f4f4;
        font-weight: bold;
    }

    tbody tr:nth-child(even) {
        background-color: #fafafa;
    }

    tbody tr:hover {
        background-color: #f1f1f1;
    }

    .text-center {
        text-align: center;
    }

    .student-info {
        text-align: left;
        width: 260px;
        /* Ajustez la largeur selon vos besoins */
    }

    .vertical-text {
        writing-mode: vertical-rl;
        transform: rotate(180deg);
        white-space: nowrap;
        height: 300px;
        /* Ajustez la hauteur selon vos besoins */
        text-align: left;
    }

    .code-ue {
        writing-mode: vertical-rl;
        transform: rotate(180deg);
        white-space: nowrap;
        text-align: left;
        height: auto;
        /* Ajustez la hauteur selon vos besoins */
    }

    /* Conteneur du menu */
    .semestre-menu {
        margin-bottom: 20px;
        display: flex;
        gap: 10px;
        /* Espace entre les boutons */
        flex-wrap: wrap;
    }

    /* Style général des boutons */
    .semestre-menu form button {
        background-color: #007BFF;
        /* Bleu universitaire */
        color: #fff;
        border: none;
        padding: 8px 16px;
        font-size: 14px;
        cursor: pointer;
        border-radius: 4px;
        transition: background-color 0.2s, transform 0.2s;
    }

    /* Hover / survol */
    .semestre-menu form button:hover {
        background-color: #0056b3;
        transform: translateY(-2px);
    }

    /* Focus (pour accessibilité) */
    .semestre-menu form button:focus {
        outline: 2px solid #333;
        outline-offset: 2px;
    }

    /* Style pour les boutons d'impression */
    .semestre-menu a {
        transition: background-color 0.2s, transform 0.2s;
        margin-right: 5px;
    }

    .semestre-menu a:hover {
        transform: translateY(-2px);
        text-decoration: none;
    }

    /* Style spécifique pour les boutons grille (vert) */
    .semestre-menu a[style*="28a745"]:hover {
        background-color: #218838 !important;
    }

    /* Style spécifique pour les boutons PV (rouge) */
    .semestre-menu a[style*="dc3545"]:hover {
        background-color: #c82333 !important;
    }

    /* Style pour le séparateur */
    .semestre-menu div {
        align-self: center;
    }

    /* Responsive : sur petits écrans, les boutons s'empilent */
    @media (max-width: 500px) {
        .semestre-menu {
            flex-direction: column;
        }
    }
</style>
<!-- Menu de triage par semestre et bouton d'impression -->
<div class="semestre-menu">
    <?php
    $id_domaine = $_GET['id'] ?? '';
    $code_promo = $_GET['promotion'] ?? '';
    $semestres = ['', 1, 2];
    foreach ($semestres as $val):
        $label = $val === '' ? 'Tous les semestres' : 'Semestre ' . $val;
        ?>
        <form method="get" style="display:inline;">
            <input type="hidden" name="page" value="domaine">
            <input type="hidden" name="action" value="view">
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($id_domaine); ?>">
            <input type="hidden" name="mention" value="<?php echo htmlspecialchars($id_mention); ?>">
            <input type="hidden" name="tab" value="notes">
            <input type="hidden" name="promotion" value="<?php echo htmlspecialchars($code_promo); ?>">
            <input type="hidden" name="tabnotes" value="deliberation">
            <input type="hidden" name="semestre" value="<?php echo htmlspecialchars($val); ?>">
            <button type="submit"><?php echo htmlspecialchars($label); ?></button>
        </form>
    <?php endforeach; ?>

    <!-- Séparateur visuel -->
    <div style="border-left: 3px solid #ddd; height: 40px; margin: 0 15px;"></div>

    <!-- Boutons pour mode session -->
    <?php
    $current_rattrapage = isset($_GET['rattrapage']) && $_GET['rattrapage'] === '1';
    
    
    $modes = [
        ['value' => '0', 'label' => 'Session Normale', 'color' => '#007BFF'],
        ['value' => '1', 'label' => 'Session Rattrapage', 'color' => '#ffc107']
    ];
    foreach ($modes as $mode):
        $is_active = ($mode['value'] === '1' && $current_rattrapage) || ($mode['value'] === '0' && !$current_rattrapage);
        $bg_color = $is_active ? $mode['color'] : '#6c757d';
        ?>
        <form method="get" style="display:inline;">
            <input type="hidden" name="page" value="domaine">
            <input type="hidden" name="action" value="view">
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($id_domaine); ?>">
            <input type="hidden" name="mention" value="<?php echo htmlspecialchars($id_mention); ?>">
            <input type="hidden" name="tab" value="notes">
            <input type="hidden" name="promotion" value="<?php echo htmlspecialchars($code_promo); ?>">
            <input type="hidden" name="tabnotes" value="deliberation">
            <input type="hidden" name="semestre" value="<?php echo htmlspecialchars($_GET['semestre'] ?? ''); ?>">
            <input type="hidden" name="rattrapage" value="<?php echo htmlspecialchars($mode['value']); ?>">
            <button type="submit" style="background-color: <?php echo $bg_color; ?>; color: #fff; border: none; padding: 8px 16px; font-size: 14px; cursor: pointer; border-radius: 4px; margin-right: 5px;">
                <?php echo htmlspecialchars($mode['label']); ?>
                <?php if ($is_active): ?>
                    <i class="bi bi-check-circle-fill" style="margin-left: 5px;"></i>
                <?php endif; ?>
            </button>
        </form>
    <?php endforeach; ?>

    <!-- Séparateur visuel -->
    <div style="border-left: 3px solid #ddd; height: 40px; margin: 0 15px;"></div>

    <div style="text-align: right;">
        <button onclick="printGrilleDeliberation()"
            style="transition: background-color 0.2s; background-color: #28a745; color: #fff; border: none; padding: 8px 16px; font-size: 14px; cursor: pointer; border-radius: 4px;">
            <img src="icons/print-icon.png" alt="" class="bi bi-printer"> Imprimer
        </button>
    </div>

    <!-- Séparateur visuel pour les PV -->
    <div style="border-left: 3px solid #ddd; height: 40px; margin: 0 15px;"></div>

    <!-- Bouton pour imprimer le PV de délibération (annuel uniquement) -->
    <?php
    $pvUrl = "pages/domaine/tabs/imprimer_pv_deliberation.php?mention=" . urlencode($id_mention) .
        "&promotion=" . urlencode($code_promo);
    ?>
    <a href="<?php echo $pvUrl; ?>" target="_blank"
        style="background-color: #dc3545; color: #fff; border: none; padding: 8px 16px; font-size: 14px; cursor: pointer; border-radius: 4px; text-decoration: none; display: inline-block; transition: background-color 0.2s;">
        <i class="bi bi-file-text"></i> PV de Délibération
    </a>
</div>

<style>
    table {
        width: 100%;
        border-collapse: collapse;
        font-family: 'Century Gothic';
        font-size: 11px;
        border: 3px solid #000;
    }

    table th,
    table td {
        border: 1px solid #000;
        text-align: center;
        padding: 0px;
    }

    .student-info {
        text-align: left;
        width: 260px;
    }

    .vertical-text {
        writing-mode: vertical-rl;
        transform: rotate(180deg);
        white-space: nowrap;
        height: auto;
        text-align: left;
        padding-bottom: 5px;
        padding-top: 2px;
    }

    .code-ue {
        writing-mode: vertical-rl;
        transform: rotate(180deg);
        white-space: nowrap;
        text-align: left;
        height: auto;
        padding-bottom: 5px;
        padding-top: 2px;
    }

    tbody tr:nth-child(even) {
        background-color: #fafafa;
    }

    tbody tr:hover {
        background-color: #f1f1f1;
    }

    .entete-texte {
        font-size: 13px;
        font-weight: 600;
        font-family: 'Century Gothic';
        transform: rotate(0deg);
        text-transform: uppercase;
    }

    .lignes-rdc {
        width: 70%;
        display: flex;
        margin: auto;
    }

    .blue,
    .jaune,
    .rouge {
        height: 5px;
        width: 32%;
    }

    .blue {
        background-color: blue;
    }

    .jaune {
        background-color: yellow;
    }

    .rouge {
        background-color: red;
    }

    .nom-app {
        font-size: 1.7em;
        letter-spacing: 5px;
        font-weight: 900;
        background-color: #003958;
        color: #fff;
        margin-top: 5px;
    }
    
    /* Styles pour les liens sur les notes */
    td a {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        transition: all 0.2s ease;
        min-width: 20px;
        text-align: center;
    }
    
    td a:hover {
        background-color: #f8f9fa;
        text-decoration: underline !important;
        transform: scale(1);
        cursor: pointer;
    }
    
    /* Style pour les notes de rattrapage (en gras et noir) */
    td a[style*="font-weight: bold"]:hover {
        background-color: #f8f9fa;
        color: #000 !important;
    }
    
    /* Style spécial pour les cellules vides */
    td a[style*="color: #666"]:hover {
        background-color: #e9ecef;
        color: #007bff !important;
    }
    
    /* Améliorer la zone cliquable */
    td {
        padding: 1px !important;
    }
    
    td a {
        width: 100%;
        height: 100%;
        min-height: 20px;
    }
</style>

<!-- ----------------------- -->
<!-- Tableau principal -->
<!-- ----------------------- -->

<?php if ($mode_rattrapage): ?>
<!-- Légende pour le mode rattrapage -->
<div style="margin-bottom: 15px; padding: 10px; background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 5px;">
    <div style="font-weight: bold; color: #856404; margin-bottom: 5px;">
        <i class="bi bi-info-circle"></i> Mode Session de Rattrapage - Légende
    </div>
    <div style="font-size: 12px; color: #856404;">
        <span style="color: #000; font-weight: bold;">Note*</span> = Note de rattrapage (meilleure note entre session normale et rattrapage)<br>
        <strong>Seuls les étudiants ayant des notes de rattrapage sont affichés</strong>
    </div>
</div>
<?php endif; ?>

<div class="grille_deliberation">
    <table>
        <!-- En-tête générale -->
        <tr>
            <td rowspan="4" colspan="2" class="text-center" style="width:270px !important; padding: 5px;">

                <div style="font-weight: bold; font-size: 14px;">Université Notre Dame de Lomami</div>
                <div>
                    <img src="/../../img/logo.gif" style="width: 50px;">
                </div>
                <div class="entete-texte">FILIERE : <?php echo $filiere; ?></div>
                <div class="entete-texte">MENTION : <?php echo $mention ?></div>
                <div class="entete-texte">
                    Grille de délibération
                    <?php if ($mode_rattrapage): ?>
                        <span style="color: #000000ff; font-weight: bold;"> - SESSION DE RATTRAPAGE</span>
                    <?php else: ?>
                        <span style="color: #000000ff; font-weight: bold;"> - SESSION NORMALE</span>
                    <?php endif; ?>
                </div>
                <div class="entete-texte">Promotion : <?php echo htmlspecialchars($code_promo); ?></div>
                <div style="font-size: 14px; font-weight: 900;">ANNEE ACADEMIQUE <?php echo $annee_academique; ?></div>
                <div class="lignes-rdc">
                    <div class="blue"></div>
                    <div class="jaune"></div>
                    <div class="rouge"></div>
                </div>
                <div class="nom-app"><span style="color: #f8bc10; ">ACA</span>NIQUE</div>
            </td>
            <?php
            $nbrS1 = 0;
            $nbrS2 = 0;
            foreach ($ues_s1 as $codeUE => $ue) {
                $nbrS1 += count($ue['ecs']);
            }
            foreach ($ues_s2 as $codeUE => $ue) {
                $nbrS2 += count($ue['ecs']);
            }
            ?>
            <?php if ($afficher_s1 || $afficher_tous): ?>
                <td colspan="<?php echo $nbrS1 + 3; ?>" class="text-center"
                    style="font-weight: bold; border-right: #000 5px solid;">
                    Semestre 1</td>
            <?php endif; ?>

            <?php if ($afficher_s2 || $afficher_tous): ?>
                <td colspan="<?php echo $nbrS2 + 3; ?>" class="text-center"
                    style="font-weight: bold; border-right: #000 5px solid;">
                    Semestre 2</td>
            <?php endif; ?>

            <?php if ($afficher_tous): ?>
                <td colspan="6" class="text-center" style="font-weight: bold; border-right: #000 3px solid;">Annuelle</td>
            <?php endif; ?>
        </tr>

        <!-- Codes UE et EC -->
        <?php
        // Requête pour récupérer le nombre d'ECs d'une UE donnée (exemple avec $codeUE)
        
        // Exemple d'utilisation : $nbEcs = getNombreEcsParUe($pdo, 'UE123');
        ?>
        <tr>
            <?php if ($afficher_s1 || $afficher_tous): ?>
                <?php foreach ($ues_s1 as $codeUE => $ue): ?>
                    <?php
                    // Compter uniquement les EC réellement affichés (éviter colspan=0)
                    $nbEcs = 0;
                    foreach ($ue['ecs'] as $ec) {
                        $nbEcs++;
                    }
                    ?>
                    <td class="vertical-text code-ue" colspan="<?php echo max(1, $nbEcs); ?>">
                        <?php echo htmlspecialchars($codeUE); ?>
                    </td>
                <?php endforeach; ?>
                <td colspan="3" style="background:black; border-right: #000 5px solid;"></td>
            <?php endif; ?>

            <?php if ($afficher_s2 || $afficher_tous): ?>
                <?php foreach ($ues_s2 as $codeUE => $ue): ?>
                    <?php
                    $nbEcs = 0;
                    foreach ($ue['ecs'] as $ec) {
                        $nbEcs++;
                    }
                    ?>
                    <td class="vertical-text code-ue" colspan="<?php echo max(1, $nbEcs); ?>">
                        <?php echo htmlspecialchars($codeUE); ?>
                    </td>
                <?php endforeach; ?>
                <td colspan="3" style="background:black; border-right: #000 5px solid;"></td>
            <?php endif; ?>

            <?php if ($afficher_tous): ?>
                <td colspan="6" style="background:black; border-right: #000 3px solid;"></td>
            <?php endif; ?>
        </tr>

        <!-- Codes EC -->
        <tr>
            <?php if ($afficher_s1 || $afficher_tous): ?>
                <?php foreach ($ues_s1 as $codeUE => $ue): ?>
                    <?php foreach ($ue['ecs'] as $codeEC => $ec): ?>
                        <td class="vertical-text"><?php echo htmlspecialchars($ec['libelle']); ?></td>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <td class="vertical-text" style="background:gray;">Total Notes Pondérées</td>
                <td class="vertical-text" style="background:gray;">Moyenne Pondérée</td>
                <td class="vertical-text" style="background:gray; border-right: #000 5px solid;">Crédits S1</td>
            <?php endif; ?>

            <?php if ($afficher_s2 || $afficher_tous): ?>
                <?php foreach ($ues_s2 as $codeUE => $ue): ?>
                    <?php foreach ($ue['ecs'] as $codeEC => $ec): ?>
                        <td class="vertical-text"><?php echo htmlspecialchars($ec['libelle']); ?></td>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <td class="vertical-text" style="background:gray;">Total Notes Pondérées</td>
                <td class="vertical-text" style="background:gray;">Moyenne Pondérée</td>
                <td class="vertical-text" style="background:gray; border-right: #000 5px solid;">Crédits S2</td>
            <?php endif; ?>

            <?php if ($afficher_tous): ?>
                <td class="vertical-text" style="background:gray;">Moyenne Annuelle</td>
                <td class="vertical-text" style="background:gray;">Total crédit validés Semestre 1 et 2</td>
                <td class="vertical-text" style="background:gray;">Total Notes Anneul</td>
                <td class="vertical-text" style="background:gray;">Pourcentage</td>
                <td class="vertical-text" style="background:gray;">Mention</td>
                <td class="vertical-text" style="background:gray;">Décision</td>
            <?php endif; ?>
        </tr>

        <!-- Ligne des maxima -->
        <tr>
            <?php if ($afficher_s1 || $afficher_tous):
                $ues_ref = $ues_s1;
                // cellules "20" pour chaque EC / UE
                foreach ($ues_ref as $ue) {
                    foreach ($ue['ecs'] as $ec) {
                        echo '<td style="background:gray;">20</td>';
                    }
                }

                // Calcul max pondéré et total des "crédits" effectifs pour S1
                $maxTotal = 0;
                $totalCoefS1 = 0;
                foreach ($ues_ref as $ue) {
                    foreach ($ue['ecs'] as $ec) {
                        if (!empty($ec['is_ue_sans_ec'])) {
                            $c = isset($ec['credits']) ? $ec['credits'] : (isset($ue['credits']) ? $ue['credits'] : 0);
                        } else {
                            $c = isset($ec['coef']) ? $ec['coef'] : 1;
                        }
                        $maxTotal += 20 * $c;
                        $totalCoefS1 += $c;
                    }
                }
                $moyPonderee = $totalCoefS1 > 0 ? $maxTotal / $totalCoefS1 : 0;
                ?>
                <td style="background:gray;"><?php echo number_format($maxTotal, 0); ?></td>
                <td style="background:gray;"><?php echo number_format($moyPonderee, 1); ?></td>
                <td style="background:gray; border-right: #000 5px solid;"><?php echo $totalCoefS1; ?></td>
            <?php endif; ?>

            <?php if ($afficher_s2 || $afficher_tous):
                $ues_ref = $ues_s2;
                foreach ($ues_ref as $ue) {
                    foreach ($ue['ecs'] as $ec) {
                        echo '<td style="background:gray;">20</td>';
                    }
                }
                $maxTotalS2 = 0;
                $totalCoefS2 = 0;
                foreach ($ues_ref as $ue) {
                    foreach ($ue['ecs'] as $ec) {
                        $c = !empty($ec['is_ue_sans_ec']) ? (isset($ec['credits']) ? $ec['credits'] : (isset($ue['credits']) ? $ue['credits'] : 0)) : (isset($ec['coef']) ? $ec['coef'] : 1);
                        $maxTotalS2 += 20 * $c;
                        $totalCoefS2 += $c;
                    }
                }
                $moyPondereeS2 = $totalCoefS2 > 0 ? $maxTotalS2 / $totalCoefS2 : 0;
                ?>
                <td style="background:gray;"><?php echo number_format($maxTotalS2, 0); ?></td>
                <td style="background:gray;"><?php echo number_format($moyPondereeS2, 1); ?></td>
                <td style="background:gray; border-right: #000 5px solid;"><?php echo $totalCoefS2; ?></td>
            <?php endif; ?>

            <?php if ($afficher_tous):
                $totalCoefAnnuel = $totalCoefS1 + $totalCoefS2;


                $maxTotalNotesAnnuel = 0;
                foreach ($ues as $ue) {
                    foreach ($ue['ecs'] as $ec) {
                        $c = $ec['credits'] ?? 0;
                        $maxTotalNotesAnnuel += 20 * $c;
                    }
                }
                $maxTotalNotesAnnuel = $maxTotal + $maxTotalS2;
                $moyenneAnnuelleMax = 20;
                ?>
                <td style="background:gray;"><?php echo number_format($moyenneAnnuelleMax, 1); ?></td>
                <td style="background:gray;"><?php echo $totalCoefAnnuel; ?></td>
                <td style="background:gray;"><?php echo $maxTotalNotesAnnuel; ?></td>
                <td style="background:gray;">100</td>
                <td style="background:black;"></td>
                <td style="border-right: #000 3px solid; background: #000;"></td>
            <?php endif; ?>
        </tr>

        <!-- Ligne des coefficients/crédits -->
        <tr style="background:gray; font-weight: bold;">
            <td>N°</td>
            <td>Nom, Postnom et Prénom</td>

            <?php if ($afficher_s1 || $afficher_tous):
                foreach ($ues_s1 as $ue) {
                    foreach ($ue['ecs'] as $ec) {
                        if (!empty($ec['is_ue_sans_ec'])) {
                            echo '<td>' . ($ec['credits'] ?? $ue['credits'] ?? '-') . '</td>';
                        } else {
                            echo '<td>' . ($ec['coef'] ?? '-') . '</td>';
                        }
                    }
                }
                echo '<td colspan="3" style="background:black; border-right: #000 5px solid !important;"></td>';
            endif;

            if ($afficher_s2 || $afficher_tous):
                foreach ($ues_s2 as $ue) {
                    foreach ($ue['ecs'] as $ec) {
                        if (!empty($ec['is_ue_sans_ec'])) {
                            echo '<td>' . ($ec['credits'] ?? $ue['credits'] ?? '-') . '</td>';
                        } else {
                            echo '<td>' . ($ec['coef'] ?? '-') . '</td>';
                        }
                    }
                }
                echo '<td colspan="3" style="background:black; border-right: #000 5px solid !important;"></td>';
            endif;

            if ($afficher_tous) {
                echo '<td colspan="6" style="background:black; border-right: #000 3px solid !important;"></td>';
            }
            ?>
        </tr>
        <style>
            a .lien_profil:hover {
                text-decoration: underline;
                color: #f8bc10;
            }
        </style>
        <!-- Lignes étudiants -->
        <?php $i = 1;
        foreach ($etudiants as $mat => $data): 
            $matricule = trim($mat) ;
        ?>
            <tr style="font-weight: none;">
                <td style="padding: 3px;"><?php echo $i++; ?></td>
                <td class="student-info" "><a class="lien_profil" href="index.php?page=profiletudiant&matricule=<?php echo $matricule  ?>"
                        style="color: #000; text-decoration: none; font-size: 12px; font-family: 'Century Gothic'; text-align: left;"><?php echo $data['nom']; ?></a>
                </td>

                <?php if ($afficher_s1 || $afficher_tous):
                    foreach ($ues_s1 as $codeUE => $ue):
                        foreach ($ue['ecs'] as $codeEC => $ec):
                            // *** AMÉLIORATION : Logique d'affichage des notes comme dans cotation.php ***
                            $noteAfficher = '-';
                            $estRattrapage = false;
                            $noteNormale = null;
                            
                            if (isset($data['notes'][$codeUE][$codeEC])) {
                                $noteData = $data['notes'][$codeUE][$codeEC];
                                
                                if ($mode_rattrapage) {
                                    // En mode rattrapage, afficher la meilleure note (qui peut être normale ou rattrapage)
                                    if (isset($noteData['s1']) && $noteData['s1'] !== null && $noteData['s1'] > 0) {
                                        $noteAfficher = $noteData['s1'];
                                        $estRattrapage = isset($noteData['est_rattrapage_s1']) && $noteData['est_rattrapage_s1'];
                                        $noteNormale = $noteData['note_normale_s1'] ?? null;
                                    }
                                } else {
                                    // En mode normal, afficher la note normale uniquement
                                    if (isset($noteData['s1']) && $noteData['s1'] !== null && $noteData['s1'] > 0) {
                                        $noteAfficher = $noteData['s1'];
                                        // En mode normal, pas d'indication de rattrapage
                                        $estRattrapage = false;
                                    }
                                }
                            }

                            // Déterminer la classe CSS pour la cellule
                            $cellClass = '';
                            $titleInfo = '';
                            if (is_numeric($noteAfficher) && $noteAfficher > 0) {
                                if ($noteAfficher < 10) {
                                    $cellClass = 'class="note-echec"';
                                }
                                if ($estRattrapage && $noteNormale !== null) {
                                    $titleInfo = " (Note normale: " . number_format($noteNormale, 1) . " - Rattrapage: " . number_format($noteAfficher, 1) . ")";
                                }
                            } else {
                                $cellClass = 'class="note-manquante"';
                            }
                            ?>
                            <td <?php echo $cellClass; ?>>
                                <?php
                                // Construire l'URL vers la fiche de cotation (toujours, même pour les cellules vides)
                                $cotation_url = "index.php?page=domaine&action=view&id=" . urlencode($id_domaine ?? '') . 
                                               "&mention=" . urlencode($id_mention) . 
                                               "&tab=notes&promotion=" . urlencode($code_promo) . 
                                               "&annee=" . urlencode($id_annee) .
                                               "&tabnotes=cotation&semestre=1";
                                
                                // Ajouter EC ou UE selon le cas
                                if (isset($ue['ecs'][$codeEC]) && !empty($ue['ecs'][$codeEC]['is_ue_sans_ec'])) {
                                    // C'est une UE sans EC
                                    $cotation_url .= "&ue=" . urlencode($ue_ids[$codeUE] ?? '');
                                } else {
                                    // C'est un EC
                                    $cotation_url .= "&ec=" . urlencode($ec_ids[$codeEC] ?? '');
                                }
                                
                                // Ajouter le mode rattrapage si nécessaire
                                if ($mode_rattrapage) {
                                    $cotation_url .= "&rattrapage=1";
                                }
                                
                                // Ajouter l'ancre pour l'étudiant
                                $cotation_url .= "#etudiant_" . urlencode($matricule);
                                
                                if (is_numeric($noteAfficher) && $noteAfficher > 0) {
                                    $noteDisplay = number_format((float) $noteAfficher, 0);
                                    
                                    // Style différent selon si c'est une note de rattrapage
                                    if ($estRattrapage) {
                                        // Note de rattrapage - affichage en noir gras
                                        echo '<a href="' . htmlspecialchars($cotation_url) . '" style="color: #000; font-weight: bold; text-decoration: none; font-size: 11px; font-family: \'Century Gothic\'; padding: 0px !important;" title="Note de rattrapage' . htmlspecialchars($titleInfo) . ' - Cliquer pour modifier">' . $noteDisplay . '</a>';
                                    } else {
                                        // Note normale
                                        echo '<a href="' . htmlspecialchars($cotation_url) . '" style="color: #000; text-decoration: none; font-size: 11px; font-family: \'Century Gothic\'; padding: 0px !important;" title="Note normale' . htmlspecialchars($titleInfo) . ' - Cliquer pour modifier">' . $noteDisplay . '</a>';
                                    }
                                } else {
                                    // Cellule vide avec lien pour saisir - affichage en rouge
                                    echo '<a href="' . htmlspecialchars($cotation_url) . '" style="color: #d63031; text-decoration: none; font-size: 11px; font-family: \'Century Gothic\'; padding: 2px 6px; display: inline-block; font-weight: bold;" title="Cliquer pour saisir une note">-</a>';
                                }
                                ?>
                            </td>
                        <?php endforeach;
                    endforeach;

                    $totalS1 = 0;
                    $creditsS1 = 0;
                    $totalCoefS1 = 0;
                    foreach ($ues_s1 as $codeUE => $ue) {
                        foreach ($ue['ecs'] as $codeEC => $ec) {
                            $val = isset($data['notes'][$codeUE][$codeEC]['s1']) ? $data['notes'][$codeUE][$codeEC]['s1'] : null;
                            // Use EC coef if present, otherwise use UE credits for UE without EC
                            $coef = isset($ec['is_ue_sans_ec']) && $ec['is_ue_sans_ec'] ? ($ec['credits'] ?? $ue['credits'] ?? 1) : ($ec['coef'] ?? 1);
                            // Exclure les notes nulles, vides, non-numériques ET les notes égales à 0 (qui correspondent à "-")
                            if ($val !== null && $val !== '' && is_numeric($val) && $val > 0) {
                                $totalS1 += $val * $coef;
                                $totalCoefS1 += $coef;
                            }
                        }
                    }
                    $creditsS1 = 0;
                    foreach ($ues_s1 as $codeUE => $ue) {
                        $ueValidee = false;
                        foreach ($ue['ecs'] as $codeEC => $ec) {
                            $val = $data['notes'][$codeUE][$codeEC]['s1'] ?? null;
                            // Vérifier que la note existe, est supérieure à 0 et >= 10 (exclure les notes à 0)
                            if ($val !== null && $val > 0 && $val >= 10) {
                                $ueValidee = true;
                                break;
                            }
                        }
                        if ($ueValidee) {
                            $creditsS1 += $ue['credits'];
                        }
                    }
                    $moyS1 = $totalCoefS1 > 0 ? $totalS1 / $totalCoefS1 : 0;
                    ?>
                    <td style="background:gray;"><?php echo number_format($totalS1, 0); ?></td>
                    <td style="background:gray;"><?php echo number_format($moyS1, 1); ?></td>
                    <td style="background:gray; border-right: #000 5px solid;"><?php echo $creditsS1; ?></td>

                <?php endif; ?>

                <?php if ($afficher_s2 || $afficher_tous):
                    foreach ($ues_s2 as $codeUE => $ue):
                        foreach ($ue['ecs'] as $codeEC => $ec):
                            // *** AMÉLIORATION : Logique d'affichage des notes S2 comme dans cotation.php ***
                            $noteAfficher = '-';
                            $estRattrapage = false;
                            $noteNormale = null;
                            
                            if (isset($data['notes'][$codeUE][$codeEC])) {
                                $noteData = $data['notes'][$codeUE][$codeEC];
                                
                                if ($mode_rattrapage) {
                                    // En mode rattrapage, afficher la meilleure note (qui peut être normale ou rattrapage)
                                    if (isset($noteData['s2']) && $noteData['s2'] !== null && $noteData['s2'] > 0) {
                                        $noteAfficher = $noteData['s2'];
                                        $estRattrapage = isset($noteData['est_rattrapage_s2']) && $noteData['est_rattrapage_s2'];
                                        $noteNormale = $noteData['note_normale_s2'] ?? null;
                                    }
                                } else {
                                    // En mode normal, afficher la note normale uniquement
                                    if (isset($noteData['s2']) && $noteData['s2'] !== null && $noteData['s2'] > 0) {
                                        $noteAfficher = $noteData['s2'];
                                        // En mode normal, pas d'indication de rattrapage
                                        $estRattrapage = false;
                                    }
                                }
                            }

                            // Déterminer la classe CSS pour la cellule
                            $cellClass = '';
                            $titleInfo = '';
                            if (is_numeric($noteAfficher) && $noteAfficher > 0) {
                                if ($noteAfficher < 10) {
                                    $cellClass = 'class="note-echec"';
                                }
                                if ($estRattrapage && $noteNormale !== null) {
                                    $titleInfo = " (Note normale: " . number_format($noteNormale, 1) . " - Rattrapage: " . number_format($noteAfficher, 1) . ")";
                                }
                            } else {
                                $cellClass = 'class="note-manquante"';
                            }
                            ?>
                            <td <?php echo $cellClass; ?>>
                                <?php
                                // Construire l'URL vers la fiche de cotation pour S2 (toujours, même pour les cellules vides)
                                $cotation_url = "index.php?page=domaine&action=view&id=" . urlencode($id_domaine ?? '') . 
                                               "&mention=" . urlencode($id_mention) . 
                                               "&tab=notes&promotion=" . urlencode($code_promo) . 
                                               "&annee=" . urlencode($id_annee) .
                                               "&tabnotes=cotation&semestre=2";
                                
                                // Ajouter EC ou UE selon le cas
                                if (isset($ue['ecs'][$codeEC]) && !empty($ue['ecs'][$codeEC]['is_ue_sans_ec'])) {
                                    // C'est une UE sans EC
                                    $cotation_url .= "&ue=" . urlencode($ue_ids[$codeUE] ?? '');
                                } else {
                                    // C'est un EC
                                    $cotation_url .= "&ec=" . urlencode($ec_ids[$codeEC] ?? '');
                                }
                                
                                // Ajouter le mode rattrapage si nécessaire
                                if ($mode_rattrapage) {
                                    $cotation_url .= "&rattrapage=1";
                                }
                                
                                // Ajouter l'ancre pour l'étudiant
                                $cotation_url .= "#etudiant_" . urlencode($matricule);
                                
                                if (is_numeric($noteAfficher) && $noteAfficher > 0) {
                                    $noteDisplay = number_format((float) $noteAfficher, 0);
                                    
                                    // Style différent selon si c'est une note de rattrapage
                                    if ($estRattrapage) {
                                        // Note de rattrapage - affichage en noir gras
                                        echo '<a href="' . htmlspecialchars($cotation_url) . '" style="color: #000; font-weight: bold; text-decoration: none; font-size: 11px; font-family: \'Century Gothic\'; padding: 0px !important;" title="Note de rattrapage' . htmlspecialchars($titleInfo) . ' - Cliquer pour modifier">' . $noteDisplay . '</a>';
                                    } else {
                                        // Note normale
                                        echo '<a href="' . htmlspecialchars($cotation_url) . '" style="color: #000; text-decoration: none; font-size: 11px; font-family: \'Century Gothic\'; padding: 0px !important;" title="Note normale' . htmlspecialchars($titleInfo) . ' - Cliquer pour modifier">' . $noteDisplay . '</a>';
                                    }
                                } else {
                                    // Cellule vide avec lien pour saisir - affichage en rouge
                                    echo '<a href="' . htmlspecialchars($cotation_url) . '" style="color: #d63031; text-decoration: none; font-size: 11px; font-family: \'Century Gothic\'; padding: 2px 6px; display: inline-block; font-weight: bold;" title="Cliquer pour saisir une note">-</a>';
                                }
                                ?>
                            </td>
                            <?php
                        endforeach;
                    endforeach;

                    $totalS2 = 0;
                    $creditsS2 = 0;
                    $totalCoefS2 = 0;
                    foreach ($ues_s2 as $codeUE => $ue) {
                        foreach ($ue['ecs'] as $codeEC => $ec) {
                            $val = isset($data['notes'][$codeUE][$codeEC]['s2']) ? $data['notes'][$codeUE][$codeEC]['s2'] : null;
                            // Use EC coef if present, otherwise use UE credits for UE without EC
                            $coef = isset($ec['is_ue_sans_ec']) && $ec['is_ue_sans_ec'] ? ($ec['credits'] ?? $ue['credits'] ?? 1) : ($ec['coef'] ?? 1);
                            // Exclure les notes nulles, vides, non-numériques ET les notes égales à 0 (qui correspondent à "-")
                            if ($val !== null && $val !== '' && is_numeric($val) && $val > 0) {
                                $totalS2 += $val * $coef;
                                $totalCoefS2 += $coef;
                            }
                        }
                    }
                    $creditsS2 = 0;
                    foreach ($ues_s2 as $codeUE => $ue) {
                        $ueValidee = false;
                        foreach ($ue['ecs'] as $codeEC => $ec) {
                            $val = $data['notes'][$codeUE][$codeEC]['s2'] ?? null;
                            // Vérifier que la note existe, est supérieure à 0 et >= 10 (exclure les notes à 0)
                            if (!is_null($val) && $val > 0 && $val >= 10) {
                                $ueValidee = true;
                                break;
                            }
                        }
                        if ($ueValidee) {
                            $creditsS2 += $ue['credits'];
                        }
                    }
                    $moyS2 = $totalCoefS2 > 0 ? $totalS2 / $totalCoefS2 : 0;
                    ?>
                    <td style="background:gray;"><?php echo number_format($totalS2, 0); ?></td>
                    <td style="background:gray;"><?php echo number_format($moyS2, 1); ?></td>
                    <td style="background:gray; border-right: #000 5px solid;">
                        <?php
                        echo $creditsS2;

                        ?>
                    </td>

                <?php endif; ?>

                <?php if ($afficher_tous):
                    // Calculer les variables manquantes pour S1 et S2
                    if (!isset($totalS1)) {
                        $totalS1 = 0;
                        $totalCoefS1 = 0;
                        foreach ($ues_s1 as $codeUE => $ue) {
                            foreach ($ue['ecs'] as $codeEC => $ec) {
                                $val = null;
                                if (isset($data['notes'][$codeUE][$codeEC])) {
                                    $val = $data['notes'][$codeUE][$codeEC]['s1'];
                                }
                                if (!is_null($val) && $val > 0) {
                                    $totalS1 += $val * $ec['coef'];
                                    $totalCoefS1 += $ec['coef'];
                                }
                            }
                        }

                    }

                    if (!isset($totalS2)) {
                        $totalS2 = 0;
                        $totalCoefS2 = 0;
                        foreach ($ues_s2 as $codeUE => $ue) {
                            foreach ($ue['ecs'] as $codeEC => $ec) {
                                $val = null;
                                if (isset($data['notes'][$codeUE][$codeEC])) {
                                    $val = $data['notes'][$codeUE][$codeEC]['s2'];
                                }
                                if (!is_null($val) && $val > 0) {
                                    $totalS2 += $val * $ec['coef'];
                                    $totalCoefS2 += $ec['coef'];
                                }
                            }
                        }
                    }

                    // Calcul des crédits validés pour les vues "tous" (S1 + S2 combinés)
                    $creditsValides_S1 = 0;
                    $creditsValides_S2 = 0;
                    
                    // Calcul des crédits S1 validés
                    foreach ($ues_s1 as $codeUE => $ue) {
                        $ueValidee = false;
                        foreach ($ue['ecs'] as $codeEC => $ec) {
                            $val = $data['notes'][$codeUE][$codeEC]['s1'] ?? null;
                            // Vérifier que la note existe, est supérieure à 0 et >= 10
                            if ($val !== null && $val > 0 && $val >= 10) {
                                $ueValidee = true;
                                break;
                            }
                        }
                        if ($ueValidee) {
                            $creditsValides_S1 += $ue['credits'];
                        }
                    }
                    
                    // Calcul des crédits S2 validés
                    foreach ($ues_s2 as $codeUE => $ue) {
                        $ueValidee = false;
                        foreach ($ue['ecs'] as $codeEC => $ec) {
                            $val = $data['notes'][$codeUE][$codeEC]['s2'] ?? null;
                            // Vérifier que la note existe, est supérieure à 0 et >= 10
                            if ($val !== null && $val > 0 && $val >= 10) {
                                $ueValidee = true;
                                break;
                            }
                        }
                        if ($ueValidee) {
                            $creditsValides_S2 += $ue['credits'];
                        }
                    }

                    // Moyenne annuelle
                    $moyennes = [];
                    foreach ($data['notes'] as $ecs) {
                        foreach ($ecs as $note) {
                            if (!is_null($note['moy']))
                                $moyennes[] = $note['moy'];
                        }
                    }
                    $moyAnn = count($moyennes) ? array_sum($moyennes) / count($moyennes) : 0;
                    $totalCreditsValides = $creditsValides_S1 + $creditsValides_S2;

                    // Total des notes pondérées annuelles (S1 + S2)
                    $totalNotesAnnuel = $totalS1 + $totalS2;

                    // Calcul du pourcentage - utiliser la variable correcte
                    $pourcent = ($maxTotalNotesAnnuel > 0) ? ($totalNotesAnnuel / $maxTotalNotesAnnuel) * 100 : 0;

                    // Calcul de la moyenne annuelle basée sur les moyennes semestrielles
                    $totalCoefAnnuelEtudiant = $creditsS1 + $creditsS2;
                    $moyAnnPonderee = 0;
                    $totalCoefAnnuel = $totalCoefS1 + $totalCoefS2;
                    if ($totalCoefAnnuel > 0) {
                        $moyAnnPonderee = ($totalS1 + $totalS2) / $totalCoefAnnuel;
                    }
                    ?>
                    <td style="background:gray;"><?php echo number_format($moyAnnPonderee, 1); ?></td>
                    <td style="background:gray;"><?php echo $totalCoefAnnuelEtudiant; ?></td>
                    <td style="background:gray;"><?php echo number_format($totalNotesAnnuel, 0); ?></td>
                    <td style="background:gray;"><?php echo number_format($pourcent, 1); ?>%</td>
                    <td style="background:gray;"><?php echo getMention($moyAnnPonderee); ?></td>
                    <td style="background:gray; border-right: #000 3px solid;">
                        <?php
                        if ($moyAnnPonderee >= 10) {
                            echo "ADMIS";
                        } else {
                            echo "AJOURNÉ";
                        }
                        ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </table>

    <!-- ----------------------- -->
    <!-- Légende des mentions -->
    <!-- ----------------------- -->
    <div style="text-align: left; font-style: italic; font-size: 11px; font-weight: bold;">
        Mention :
        ➢ ≥ 10/20 = Passable (E) |
        ➢ ≥ 12/20 = Assez Bien (D) |
        ➢ ≥ 14/20 = Bien (C) |
        ➢ ≥ 16/20 = Très Bien (B) |
        ➢ ≥ 18/20 = Excellence (A)
    </div>
    <div
        style="text-align: right; font-style: italic; font-size: 12px; font-family: 'Century Gothic'; margin-top: 20px;">
        Fait à Kabinda, le <strong><?php echo date('d/m/Y'); ?>
    </div>
    <style>
        .footer {
            width: 100%;
            text-align: center;
            border: 1px solid #ffffffff !important;
        }

        .footer tr {
            border: 1px solid #ffffffff !important;
        }

        .footer tr td {
            border: 1px solid #ffffffff !important;
        }
    </style>
    <div style="padding: 10px; border: #000 solid 1px; margin-top: 15px;">
        <table class="footer" style="border: #000 1px solid !important;">
            <tr>
                <td style="border-right: #000 1px solid !important;">
                    Président du Jury
                </td>
                <td style="border-right: #000 1px solid !important;">
                    Secrétaire du Jury
                </td>
                <td>
                    Membres du Jury
                </td>
            </tr>
            <tr>
                <td style="height: 200px; border-right: #000 1px solid !important;">

                </td>
                <td style="height: 200px; border-right: #000 1px solid !important;">

                </td>
                <td>

                </td>
            </tr>
        </table>

    </div>
</div>

<script>
    function printGrilleDeliberation() {
        var printContents = document.querySelector('.grille_deliberation').innerHTML;
        var originalContents = document.body.innerHTML;
        var win = window.open('', '', 'height=900,width=1200');
        win.document.write('<html><head><title>Impression Grille de Délibération</title>');
        // Copier les styles du document principal
        var styles = document.querySelectorAll('style, link[rel="stylesheet"]');
        styles.forEach(function (style) {
            win.document.write(style.outerHTML);
        });
        win.document.write('</head><body>');
        win.document.write(printContents);
        win.document.write('</body></html>');
        win.document.close();
        win.focus();
        win.print();
        win.close();
        document.body.innerHTML = originalContents;
    }
</script>