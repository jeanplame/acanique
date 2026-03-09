<?php
    header('Content-Type: text/html; charset=UTF-8');
// tabs/cotation.php
// Ce fichier est inclus dans notes.php et gère l'affichage et la logique de la fiche de cotation.
// Il utilise les variables initialisées dans notes.php ($pdo, $id_domaine, etc.).

// Récupération des semestres pour afficher dans le sélecteur
$semestres = [];
if ($id_annee) {
    $stmt_semestres = $pdo->prepare("SELECT id_semestre, nom_semestre FROM t_semestre WHERE id_annee = ?");
    $stmt_semestres->execute([$id_annee]);
    $semestres = $stmt_semestres->fetchAll(PDO::FETCH_ASSOC);
}

// Définir le semestre et l'EC/UE sélectionnés
$id_semestre = isset($_GET['semestre']) ? (int) $_GET['semestre'] : null;

// Définir le mode de cotation (normal ou rattrapage)
$mode_rattrapage = isset($_GET['rattrapage']) && $_GET['rattrapage'] === '1';

// On suppose que la fonction getECsForPromotion est définie et retourne un tableau d'ECs
// Récupération des cours (ECs) pour le semestre, la promotion et la mention sélectionnés
$ecs = [];
if ($id_semestre) {
    // Si la fonction getECsForPromotion n'existe pas, vous pouvez utiliser la requête ci-dessous
    // $stmt_ecs = $pdo->prepare("SELECT * FROM vue_ue_ec_complete WHERE code_promotion = ? AND id_mention = ? AND semestre = ?");
    // $stmt_ecs->execute([$promotion_code, $mention_id, $id_semestre]);
    // $ecs = $stmt_ecs->fetchAll(PDO::FETCH_ASSOC);
    $ecs = getECsForPromotion($pdo, $promotion_code, $id_semestre, $mention_id);
}

// Définir le cours (EC) actif ou l'UE active
$selected_ec_id = isset($_GET['ec']) && intval($_GET['ec']) ? (int) $_GET['ec'] : null;
$selected_ue_id = isset($_GET['ue']) && intval($_GET['ue']) ? (int) $_GET['ue'] : null;


// --- DÉBUT DE LA CORRECTION AMÉLIORÉE ---
// On récupère les informations de l'EC ou de l'UE sélectionnée en une seule fois
// pour les utiliser plus tard dans le titre de la fiche de cotation.
$selected_item = null;
$is_ec_selected = !empty($selected_ec_id);
$is_ue_selected = !empty($selected_ue_id);

if ($is_ec_selected) {
    // Essayer d'abord avec la vue complète
    $stmt_selected_item = $pdo->prepare("SELECT * FROM vue_ue_ec_complete WHERE id_ec = ? LIMIT 1");
    $stmt_selected_item->execute([$selected_ec_id]);
    $selected_item = $stmt_selected_item->fetch(PDO::FETCH_ASSOC);
    
    // Si la vue ne retourne rien, fallback sur les tables directes
    if (!$selected_item) {
        $stmt_fallback = $pdo->prepare("
            SELECT 
                ec.id_ec,
                ec.code_ec,
                ec.libelle as libelle_ec,
                ue.id_ue,
                ue.code_ue,
                ue.libelle as libelle_ue,
                ue.credits
            FROM t_element_constitutif ec
            INNER JOIN t_unite_enseignement ue ON ec.id_ue = ue.id_ue
            WHERE ec.id_ec = ? AND ue.is_programmed = 1 AND ec.is_programmed = 1 LIMIT 1
        ");
        $stmt_fallback->execute([$selected_ec_id]);
        $selected_item = $stmt_fallback->fetch(PDO::FETCH_ASSOC);
    }
} elseif ($is_ue_selected) {
    // Essayer d'abord avec la vue complète
    $stmt_selected_item = $pdo->prepare("SELECT * FROM vue_ue_ec_complete WHERE id_ue = ? AND id_ec IS NULL LIMIT 1");
    $stmt_selected_item->execute([$selected_ue_id]);
    $selected_item = $stmt_selected_item->fetch(PDO::FETCH_ASSOC);
    
    // Si la vue ne retourne rien, fallback sur la table directe
    if (!$selected_item) {
        $stmt_fallback = $pdo->prepare("
            SELECT 
                id_ue,
                code_ue,
                libelle as libelle_ue,
                credits
            FROM t_unite_enseignement
            WHERE id_ue = ? AND is_programmed = 1 LIMIT 1
        ");
        $stmt_fallback->execute([$selected_ue_id]);
        $selected_item = $stmt_fallback->fetch(PDO::FETCH_ASSOC);
    }
}

// Debug: Enregistrer les cas d'échec pour analyse
if (($is_ec_selected || $is_ue_selected) && !$selected_item) {
    error_log("COTATION ERROR: Impossible de trouver les données pour " . 
        ($is_ec_selected ? "EC ID: $selected_ec_id" : "UE ID: $selected_ue_id"));
}
// --- FIN DE LA CORRECTION AMÉLIORÉE ---


// Récupérer les étudiants de la promotion et de la mention
// Note : La fonction getStudentsForPromotionAndMention n'est pas dans le code fourni, on suppose qu'elle est définie ailleurs.
$students = getStudentsForPromotionAndMention($pdo, $promotion_code, $mention_id);

$existing_notes = [];
$stmt_notes = null; // Initialisation

if ($id_annee > 0) {
    // Priorité EC si sélectionné
    if ($is_ec_selected) {
        $sql_notes = "
            SELECT matricule, cote_s1, cote_s2, 
                   cote_rattrapage_s1, cote_rattrapage_s2,
                   date_rattrapage_s1, date_rattrapage_s2,
                   is_rattrapage_s1, is_rattrapage_s2
            FROM t_cote
            WHERE id_ec = :id_ec AND id_annee = :id_annee
        ";
        $stmt_notes = $pdo->prepare($sql_notes);
        $stmt_notes->execute([
            'id_ec' => $selected_ec_id,
            'id_annee' => $id_annee
        ]);
    }
    // Sinon si UE sélectionné sans EC
    elseif ($is_ue_selected) {
        $sql_notes = "
            SELECT matricule, cote_s1, cote_s2,
                   cote_rattrapage_s1, cote_rattrapage_s2,
                   date_rattrapage_s1, date_rattrapage_s2,
                   is_rattrapage_s1, is_rattrapage_s2
            FROM t_cote
            WHERE id_ue = :id_ue AND id_annee = :id_annee
        ";
        $stmt_notes = $pdo->prepare($sql_notes);
        $stmt_notes->execute([
            'id_ue' => $selected_ue_id,
            'id_annee' => $id_annee
        ]);
    }

    // Récupération sécurisée des notes
    if ($stmt_notes !== null) {
        while ($row = $stmt_notes->fetch(PDO::FETCH_ASSOC)) {
            $existing_notes[$row['matricule']] = $row;
        }
    }
}


// Gérer la soumission du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Déterminer si on enregistre une EC ou une UE
    $id_ec_to_save = $_POST['ec_id'] ?? null;
    $id_ue_to_save = $_POST['ue_id'] ?? null; // Pour les UE sans EC
    $notes = $_POST['notes'] ?? [];
    $notes_rattrapage = $_POST['notes_rattrapage'] ?? [];
    $is_rattrapage_mode = isset($_POST['rattrapage_mode']) && $_POST['rattrapage_mode'] == '1';
    $username = $_SESSION['user_id']; // Nom d'utilisateur connecté
    $id_mention = $mention_id;
    $id_promotion = 1; // Remplacer par l'ID de la promotion

    // *** CORRECTION : Logique UPDATE/INSERT sécurisée pour éviter les doublons ***
    
    // Requête pour vérifier si l'enregistrement existe
    $sql_check = "
        SELECT id_note, cote_s1, cote_s2, cote_rattrapage_s1, cote_rattrapage_s2
        FROM t_cote 
        WHERE matricule = :matricule 
        AND " . ($id_ec_to_save ? "id_ec = :id_element" : "id_ue = :id_element AND id_ec IS NULL") . "
        AND id_annee = :id_annee 
        AND id_mention = :id_mention
        ORDER BY id_note DESC
        LIMIT 1
    ";
    $stmt_check = $pdo->prepare($sql_check);
    
    // Requête UPDATE
    if ($is_rattrapage_mode) {
        $sql_update = "
            UPDATE t_cote 
            SET cote_rattrapage_s1 = :cote_rattrapage_s1, 
                cote_rattrapage_s2 = :cote_rattrapage_s2,
                date_rattrapage_s1 = :date_rattrapage_s1,
                date_rattrapage_s2 = :date_rattrapage_s2,
                is_rattrapage_s1 = :is_rattrapage_s1,
                is_rattrapage_s2 = :is_rattrapage_s2,
                username = :username
            WHERE id_note = :id_note
        ";
    } else {
        $sql_update = "
            UPDATE t_cote 
            SET cote_s1 = :cote_s1, 
                cote_s2 = :cote_s2,
                username = :username
            WHERE id_note = :id_note
        ";
    }
    $stmt_update = $pdo->prepare($sql_update);
    
    // Requête INSERT
    if ($is_rattrapage_mode) {
        $sql_insert = "
            INSERT INTO t_cote (matricule, username, id_ec, id_ue, id_annee, id_mention, id_promotion, 
                               cote_s1, cote_s2, cote_rattrapage_s1, cote_rattrapage_s2, 
                               date_rattrapage_s1, date_rattrapage_s2, is_rattrapage_s1, is_rattrapage_s2)
            VALUES (:matricule, :username, :id_ec, :id_ue, :id_annee, :id_mention, :id_promotion, 
                   :cote_s1, :cote_s2, :cote_rattrapage_s1, :cote_rattrapage_s2,
                   :date_rattrapage_s1, :date_rattrapage_s2, :is_rattrapage_s1, :is_rattrapage_s2)
        ";
    } else {
        $sql_insert = "
            INSERT INTO t_cote (matricule, username, id_ec, id_ue, id_annee, id_mention, id_promotion, cote_s1, cote_s2)
            VALUES (:matricule, :username, :id_ec, :id_ue, :id_annee, :id_mention, :id_promotion, :cote_s1, :cote_s2)
        ";
    }
    $stmt_insert = $pdo->prepare($sql_insert);

    foreach ($students as $student) {
        $matricule = $student['matricule'];
        
        // *** LOGIQUE CORRIGÉE : Vérifier si l'enregistrement existe déjà ***
        $stmt_check->execute([
            'matricule' => $matricule,
            'id_element' => $id_ec_to_save ?: $id_ue_to_save,
            'id_annee' => $id_annee,
            'id_mention' => $id_mention
        ]);
        $existing_record = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        if ($is_rattrapage_mode) {
            // Mode rattrapage
            $cote_rattrapage_s1 = isset($notes_rattrapage[$matricule]['s1']) && $notes_rattrapage[$matricule]['s1'] !== '' 
                ? floatval(str_replace(',', '.', $notes_rattrapage[$matricule]['s1'])) : null;
            $cote_rattrapage_s2 = isset($notes_rattrapage[$matricule]['s2']) && $notes_rattrapage[$matricule]['s2'] !== '' 
                ? floatval(str_replace(',', '.', $notes_rattrapage[$matricule]['s2'])) : null;
            
            if ($existing_record) {
                // UPDATE - Enregistrement existant
                $stmt_update->execute([
                    'cote_rattrapage_s1' => $cote_rattrapage_s1,
                    'cote_rattrapage_s2' => $cote_rattrapage_s2,
                    'date_rattrapage_s1' => $cote_rattrapage_s1 !== null ? date('Y-m-d H:i:s') : null,
                    'date_rattrapage_s2' => $cote_rattrapage_s2 !== null ? date('Y-m-d H:i:s') : null,
                    'is_rattrapage_s1' => $cote_rattrapage_s1 !== null ? 1 : 0,
                    'is_rattrapage_s2' => $cote_rattrapage_s2 !== null ? 1 : 0,
                    'username' => $username,
                    'id_note' => $existing_record['id_note']
                ]);
            } else {
                // INSERT - Nouvel enregistrement
                $cote_s1_existante = isset($existing_notes[$matricule]['cote_s1']) ? floatval($existing_notes[$matricule]['cote_s1']) : 0.0;
                $cote_s2_existante = isset($existing_notes[$matricule]['cote_s2']) ? floatval($existing_notes[$matricule]['cote_s2']) : 0.0;
                
                $stmt_insert->execute([
                    'matricule' => $matricule,
                    'username' => $username,
                    'id_ec' => $id_ec_to_save,
                    'id_ue' => $id_ue_to_save,
                    'id_annee' => $id_annee,
                    'id_mention' => $id_mention,
                    'id_promotion' => $id_promotion,
                    'cote_s1' => $cote_s1_existante,
                    'cote_s2' => $cote_s2_existante,
                    'cote_rattrapage_s1' => $cote_rattrapage_s1,
                    'cote_rattrapage_s2' => $cote_rattrapage_s2,
                    'date_rattrapage_s1' => $cote_rattrapage_s1 !== null ? date('Y-m-d H:i:s') : null,
                    'date_rattrapage_s2' => $cote_rattrapage_s2 !== null ? date('Y-m-d H:i:s') : null,
                    'is_rattrapage_s1' => $cote_rattrapage_s1 !== null ? 1 : 0,
                    'is_rattrapage_s2' => $cote_rattrapage_s2 !== null ? 1 : 0
                ]);
            }
        } else {
            // Mode normal
            $cote_s1 = isset($notes[$matricule]['s1']) ? floatval(str_replace(',', '.', $notes[$matricule]['s1'])) : 0.0;
            $cote_s2 = isset($notes[$matricule]['s2']) ? floatval(str_replace(',', '.', $notes[$matricule]['s2'])) : 0.0;

            // Adapter selon le semestre sélectionné
            if ($id_semestre === 1) {
                $cote_s2_finale = $existing_record ? floatval($existing_record['cote_s2']) : 0.0;
                $cote_s1_finale = $cote_s1;
            } elseif ($id_semestre === 2) {
                $cote_s1_finale = $existing_record ? floatval($existing_record['cote_s1']) : 0.0;
                $cote_s2_finale = $cote_s2;
            } else {
                $cote_s1_finale = $cote_s1;
                $cote_s2_finale = $cote_s2;
            }

            if ($existing_record) {
                // UPDATE - Enregistrement existant
                $stmt_update->execute([
                    'cote_s1' => $cote_s1_finale,
                    'cote_s2' => $cote_s2_finale,
                    'username' => $username,
                    'id_note' => $existing_record['id_note']
                ]);
            } else {
                // INSERT - Nouvel enregistrement
                $stmt_insert->execute([
                    'matricule' => $matricule,
                    'username' => $username,
                    'id_ec' => $id_ec_to_save,
                    'id_ue' => $id_ue_to_save,
                    'id_annee' => $id_annee,
                    'id_mention' => $id_mention,
                    'id_promotion' => $id_promotion,
                    'cote_s1' => $cote_s1_finale,
                    'cote_s2' => $cote_s2_finale
                ]);
            }
        }
    }

    $message = $is_rattrapage_mode ? "Notes de rattrapage enregistrées avec succès!" : "Notes enregistrées avec succès pour l'UE/EC sélectionné(e).";
}

// Afficher le message de succès si présent
if (isset($message) && $message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif;


// --- Correction pour éviter les erreurs de variables non définies ---
// Initialisation des variables pour l'en-tête, au cas où aucune valeur n'est trouvée.
$semestre_actuel_name = '';
$enseignant_name = 'Nom de l\'Enseignant';
// Fin de la correction

// Recherche des noms de semestre
if ($id_semestre && !empty($semestres)) {
    $semestre_actuel = array_filter($semestres, fn($s) => $s['id_semestre'] == $id_semestre);
    $semestre_actuel_name = current($semestre_actuel)['nom_semestre'] ?? '';
}

// Déterminer le semestre actif pour l'affichage conditionnel
$is_semestre_1 = (strpos($semestre_actuel_name, 'Semestre 1') !== false);
$is_semestre_2 = (strpos($semestre_actuel_name, 'Semestre 2') !== false);

$promotion_code = trim((string)$promotion_code);
$id_semestre = trim((string)$id_semestre);

?>

<!-- Le code HTML qui était dans la div `tab-pane` de l'onglet cotation -->
<div class="tab-pane fade show active" id="cotation" role="tabpanel" aria-labelledby="cotation-tab">
    <style>
        .table-notes {
            width: 100%;
            border-collapse: collapse;
        }

        .table-notes th,
        .table-notes td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }

        .table-notes th {
            background-color: #f2f2f2;
        }
        
        /* Styles pour le mode rattrapage */
        .rattrapage-input:disabled {
            background-color: #f8f9fa;
            border-color: #dee2e6;
        }
        
        .note-rattrapage-success {
            background-color: #d4edda !important;
        }
        
        .note-normale-readonly {
            background-color: #f8f9fa;
            color: #6c757d;
        }
    </style>
    <div class="row no-print">
        <div class="mb-3">
            <!-- Menu pour selectionner les semestres à partir des boutons -->
            <nav>
                <?php foreach ($semestres as $semestre): ?>
                    <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=notes&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&tabnotes=cotation&semestre=<?php echo $semestre['id_semestre']; ?>"
                        class="btn btn-outline-primary <?php echo ($semestre['id_semestre'] == $id_semestre && !$mode_rattrapage) ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($semestre['nom_semestre']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        
        <?php if ($id_semestre): ?>
        <!-- Boutons pour basculer entre mode normal et rattrapage -->
        <div class="mb-3">
            <div class="btn-group" role="group" aria-label="Mode de cotation">
                <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=notes&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&tabnotes=cotation&semestre=<?php echo $id_semestre; ?>"
                   class="btn <?php echo !$mode_rattrapage ? 'btn-primary' : 'btn-outline-primary'; ?>">
                    <i class="fas fa-edit"></i> Session Normale
                </a>
                <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=notes&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&tabnotes=cotation&semestre=<?php echo $id_semestre; ?>&rattrapage=1"
                   class="btn <?php echo $mode_rattrapage ? 'btn-warning' : 'btn-outline-warning'; ?>">
                    <i class="fas fa-redo"></i> Session de Rattrapage
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="col-md-12 mb-3">
            <h2 class="h4 mb-3">
                <?php if ($mode_rattrapage): ?>
                    <span class="badge bg-warning text-dark">RATTRAPAGE</span> 
                <?php endif; ?>
                Fiche de cotation pour le <?php echo htmlspecialchars($semestre_actuel_name); ?>
                <?php if ($mode_rattrapage): ?>
                    <small class="text-muted">(Session de rattrapage)</small>
                <?php endif; ?>
            </h2>
        </div>
    </div>

    <div class="row">
        <!-- Colonne de gauche : Liste des cours (EC) -->
        <div class="col-md-3 no-print">
            <div class="card">
                <div class="card-header">
                    Liste des cours
                </div>
                <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">

                    <?php
                    // Récupération sécurisée des paramètres GET
                    $prom1 = isset($_GET['promotion']) ? $_GET['promotion'] : null;
                    $mentio = isset($_GET['mention']) ? $_GET['mention'] : null;
                    $semestr = isset($_GET['semestre']) ? $_GET['semestre'] : null;

                    // Préparation et exécution de la requête
                    $sql = "SELECT * FROM vue_ue_ec_complete
                        WHERE code_promotion = ? AND id_mention = ? AND semestre = ?
                        ORDER BY id_ue, libelle_ec";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$prom1, $mentio, $semestr]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Regrouper les EC par UE
                    $ues = [];
                    foreach ($results as $row) {
                        $ues[$row['id_ue']]['ue'] = $row;
                        if (!empty($row['id_ec'])) {
                            $ues[$row['id_ue']]['ecs'][] = $row;
                        }
                    }
                    ?>


                    <style>
                        .list-group-item {
                            text-decoration: none;
                            background-color: #05435f;
                        }

                        .list-group-item-action {
                            color: black !important;
                            background-color: #cdcdcd !important;
                            border-bottom: 3px solid #05435f;
                        }

                        .list-group-item-action:hover {
                            background-color: #0a78b1 !important;
                            color: #fff;
                            margin-bottom: 5px;
                        }

                        .list-group-item-action a:hover {
                            color: #fff;
                            background-color: #0a78b1;
                        }
                    </style>
                    <?php foreach ($ues as $ue): ?>
                        <?php if (!empty($ue['ecs'])): ?>
                            <!-- Si l’UE a des EC, on affiche uniquement les EC -->
                            <?php foreach ($ue['ecs'] as $ec): ?>
                                <a href="?page=domaine&action=view&id=<?php echo htmlspecialchars($id_domaine); ?>&mention=<?php echo htmlspecialchars($mentio); ?>&tab=notes&promotion=<?php echo htmlspecialchars($prom1); ?>&annee=<?php echo htmlspecialchars($id_annee); ?>&tabnotes=cotation&semestre=<?php echo htmlspecialchars($semestr); ?>&ec=<?php echo htmlspecialchars($ec['id_ec']); ?><?php echo $mode_rattrapage ? '&rattrapage=1' : ''; ?>"
                                    class="list-group-item list-group-item-action">
                                    <?= htmlspecialchars($ec['code_ue'] . ' - ' . $ec['libelle_ue'] . ' (' . $ec['libelle_ec'] . ')') ?>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- Si l'UE n'a aucun EC, on affiche l'UE directement -->
                            <a href="?page=domaine&action=view&id=<?php echo htmlspecialchars($id_domaine); ?>&mention=<?php echo htmlspecialchars($mentio); ?>&tab=notes&promotion=<?php echo htmlspecialchars($prom1); ?>&annee=<?php echo htmlspecialchars($id_annee); ?>&tabnotes=cotation&semestre=<?php echo htmlspecialchars($semestr); ?>&ue=<?php echo htmlspecialchars($ue['ue']['id_ue']); ?><?php echo $mode_rattrapage ? '&rattrapage=1' : ''; ?>"
                                class="list-group-item list-group-item-action bg-secondary text-white">
                                <?= htmlspecialchars($ue['ue']['code_ue'] . ' - ' . $ue['ue']['libelle_ue']) ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                </div>

            </div>
        </div>

        <!-- Colonne de droite : Fiche de cotation -->
        <div class="col-md-9">
            <?php if ($is_ec_selected || $is_ue_selected): ?>
                <div class="card">
                    <div class="card-body" id="printable-content">
                        <!-- En-tête professionnelle et académique pour l'impression -->
                        <div class="text-center mb-4 print-header">
                            <h5 class="mt-3">
                                FICHE DE COTATION
                                <?php if ($is_ec_selected && $selected_item && isset($selected_item['libelle_ec'])): ?>
                                    <!-- Utilisation de la variable $selected_item pour l'EC -->
                                    EC - <?php echo htmlspecialchars($selected_item['libelle_ec']); ?>
                                <?php elseif ($is_ue_selected && $selected_item && isset($selected_item['libelle_ue'])): ?>
                                    <!-- Utilisation de la variable $selected_item pour l'UE -->
                                    UE - <?php echo htmlspecialchars($selected_item['libelle_ue']); ?>
                                <?php elseif ($is_ec_selected): ?>
                                    <!-- Fallback si les données EC ne sont pas trouvées -->
                                    EC - [ID: <?php echo htmlspecialchars($selected_ec_id); ?>] (Données non trouvées)
                                <?php elseif ($is_ue_selected): ?>
                                    <!-- Fallback si les données UE ne sont pas trouvées -->
                                    UE - [ID: <?php echo htmlspecialchars($selected_ue_id); ?>] (Données non trouvées)
                                <?php endif; ?>
                            </h5>
                        </div>

                        <?php
                        // Détermination de l'ID à utiliser (EC ou UE)
                        $id_selectionne = $selected_ec_id ?? $selected_ue_id;
                        $promotion_code = trim($promotion_code);
                        $id_semestre = trim($id_semestre);

                        ?>

                        <form
                            action="?page=domaine&action=view&id=<?= htmlspecialchars($id_domaine) ?>&mention=<?= htmlspecialchars($mention_id) ?>&tab=notes&promotion=<?= htmlspecialchars($promotion_code) ?>&tabnotes=cotation&semestre=<?= htmlspecialchars($id_semestre) ?><?= $is_ec_selected ? '&ec=' . htmlspecialchars($selected_ec_id) : '&ue=' . htmlspecialchars($selected_ue_id) ?><?= $mode_rattrapage ? '&rattrapage=1' : '' ?>"
                            method="POST">
                            <!-- Champs cachés selon le cas -->
                            <?php if ($is_ec_selected): ?>
                                <input type="hidden" name="ec_id" value="<?= htmlspecialchars($selected_ec_id) ?>">
                            <?php else: ?>
                                <input type="hidden" name="ue_id" value="<?= htmlspecialchars($selected_ue_id) ?>">
                            <?php endif; ?>
                            
                            <!-- Champ caché pour le mode rattrapage -->
                            <?php if ($mode_rattrapage): ?>
                                <input type="hidden" name="rattrapage_mode" value="1">
                            <?php endif; ?>

                            <div class="table-responsive">
                                <table class="table table-striped table-bordered">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Matricule</th>
                                            <th>Nom complet</th>
                                            <?php if ($mode_rattrapage): ?>
                                                <?php if ($is_semestre_1): ?>
                                                    <th class="bg-warning text-dark">Note Normale S1</th>
                                                    <th class="bg-danger text-white">Rattrapage S1 (max 20)</th>
                                                    <th class="bg-success text-white">Meilleure Note S1</th>
                                                <?php elseif ($is_semestre_2): ?>
                                                    <th class="bg-warning text-dark">Note Normale S2</th>
                                                    <th class="bg-danger text-white">Rattrapage S2 (max 20)</th>
                                                    <th class="bg-success text-white">Meilleure Note S2</th>
                                                <?php else: ?>
                                                    <th class="bg-warning text-dark">Note Normale S1</th>
                                                    <th class="bg-danger text-white">Rattrapage S1 (max 20)</th>
                                                    <th class="bg-success text-white">Meilleure S1</th>
                                                    <th class="bg-warning text-dark">Note Normale S2</th>
                                                    <th class="bg-danger text-white">Rattrapage S2 (max 20)</th>
                                                    <th class="bg-success text-white">Meilleure S2</th>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if ($is_semestre_1): ?>
                                                    <th>1er SEM (max 20)</th>
                                                <?php elseif ($is_semestre_2): ?>
                                                    <th>2ème SEM (max 20)</th>
                                                <?php else: ?>
                                                    <th>1er SEM (max 20)</th>
                                                    <th>2ème SEM (max 20)</th>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student): ?>
                                            <?php 
                                            $matricule = $student['matricule'];
                                            $cote_s1 = $existing_notes[$matricule]['cote_s1'] ?? 0;
                                            $cote_s2 = $existing_notes[$matricule]['cote_s2'] ?? 0;
                                            $cote_rattrapage_s1 = $existing_notes[$matricule]['cote_rattrapage_s1'] ?? null;
                                            $cote_rattrapage_s2 = $existing_notes[$matricule]['cote_rattrapage_s2'] ?? null;
                                            
                                            // Calcul des meilleures notes
                                            $meilleure_s1 = $cote_rattrapage_s1 !== null && $cote_rattrapage_s1 > $cote_s1 ? $cote_rattrapage_s1 : $cote_s1;
                                            $meilleure_s2 = $cote_rattrapage_s2 !== null && $cote_rattrapage_s2 > $cote_s2 ? $cote_rattrapage_s2 : $cote_s2;
                                            ?>
                                            <tr id="etudiant_<?= htmlspecialchars($student['matricule']) ?>">
                                                <td><?= htmlspecialchars($student['matricule']) ?></td>
                                                <td><?= htmlspecialchars($student['nom_etu'] . ' ' . $student['postnom_etu'] . ' ' . $student['prenom_etu']) ?></td>

                                                <?php if ($mode_rattrapage): ?>
                                                    <?php if ($is_semestre_1): ?>
                                                        <!-- Note normale S1 (lecture seule) -->
                                                        <td class="bg-light">
                                                            <input type="text" class="form-control-plaintext text-center" 
                                                                   value="<?= number_format($cote_s1, 2) ?>" readonly>
                                                        </td>
                                                        <!-- Note de rattrapage S1 (éditable) -->
                                                        <td>
                                                            <input type="number" step="0.01" 
                                                                   name="notes_rattrapage[<?= $matricule ?>][s1]" 
                                                                   class="form-control text-center <?= $cote_s1 < 10 ? 'border-warning' : '' ?>"
                                                                   min="0" max="20" placeholder="0.00"
                                                                   value="<?= $cote_rattrapage_s1 !== null ? number_format($cote_rattrapage_s1, 2) : '' ?>"
                                                                   <?= $cote_s1 >= 10 ? 'disabled title="Note normale >= 10, rattrapage non autorisé"' : '' ?>>
                                                        </td>
                                                        <!-- Meilleure note S1 (calculée) -->
                                                        <td class="bg-light text-center fw-bold">
                                                            <?= number_format($meilleure_s1, 2) ?>
                                                            <?php if ($cote_rattrapage_s1 !== null && $cote_rattrapage_s1 > $cote_s1): ?>
                                                                <small class="text-success d-block">← Rattrapage</small>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php elseif ($is_semestre_2): ?>
                                                        <!-- Note normale S2 (lecture seule) -->
                                                        <td class="bg-light">
                                                            <input type="text" class="form-control-plaintext text-center" 
                                                                   value="<?= number_format($cote_s2, 2) ?>" readonly>
                                                        </td>
                                                        <!-- Note de rattrapage S2 (éditable) -->
                                                        <td>
                                                            <input type="number" step="0.01" 
                                                                   name="notes_rattrapage[<?= $matricule ?>][s2]" 
                                                                   class="form-control text-center <?= $cote_s2 < 10 ? 'border-warning' : '' ?>"
                                                                   min="0" max="20" placeholder="0.00"
                                                                   value="<?= $cote_rattrapage_s2 !== null ? number_format($cote_rattrapage_s2, 2) : '' ?>"
                                                                   <?= $cote_s2 >= 10 ? 'disabled title="Note normale >= 10, rattrapage non autorisé"' : '' ?>>
                                                        </td>
                                                        <!-- Meilleure note S2 (calculée) -->
                                                        <td class="bg-light text-center fw-bold">
                                                            <?= number_format($meilleure_s2, 2) ?>
                                                            <?php if ($cote_rattrapage_s2 !== null && $cote_rattrapage_s2 > $cote_s2): ?>
                                                                <small class="text-success d-block">← Rattrapage</small>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php else: ?>
                                                        <!-- S1 -->
                                                        <td class="bg-light">
                                                            <input type="text" class="form-control-plaintext text-center" 
                                                                   value="<?= number_format($cote_s1, 2) ?>" readonly>
                                                        </td>
                                                        <td>
                                                            <input type="number" step="0.01" 
                                                                   name="notes_rattrapage[<?= $matricule ?>][s1]" 
                                                                   class="form-control text-center <?= $cote_s1 < 10 ? 'border-warning' : '' ?>"
                                                                   min="0" max="20" placeholder="0.00"
                                                                   value="<?= $cote_rattrapage_s1 !== null ? number_format($cote_rattrapage_s1, 2) : '' ?>"
                                                                   <?= $cote_s1 >= 10 ? 'disabled title="Note normale >= 10, rattrapage non autorisé"' : '' ?>>
                                                        </td>
                                                        <td class="bg-light text-center fw-bold">
                                                            <?= number_format($meilleure_s1, 2) ?>
                                                            <?php if ($cote_rattrapage_s1 !== null && $cote_rattrapage_s1 > $cote_s1): ?>
                                                                <small class="text-success d-block">← R</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <!-- S2 -->
                                                        <td class="bg-light">
                                                            <input type="text" class="form-control-plaintext text-center" 
                                                                   value="<?= number_format($cote_s2, 2) ?>" readonly>
                                                        </td>
                                                        <td>
                                                            <input type="number" step="0.01" 
                                                                   name="notes_rattrapage[<?= $matricule ?>][s2]" 
                                                                   class="form-control text-center <?= $cote_s2 < 10 ? 'border-warning' : '' ?>"
                                                                   min="0" max="20" placeholder="0.00"
                                                                   value="<?= $cote_rattrapage_s2 !== null ? number_format($cote_rattrapage_s2, 2) : '' ?>"
                                                                   <?= $cote_s2 >= 10 ? 'disabled title="Note normale >= 10, rattrapage non autorisé"' : '' ?>>
                                                        </td>
                                                        <td class="bg-light text-center fw-bold">
                                                            <?= number_format($meilleure_s2, 2) ?>
                                                            <?php if ($cote_rattrapage_s2 !== null && $cote_rattrapage_s2 > $cote_s2): ?>
                                                                <small class="text-success d-block">← R</small>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <!-- Mode normal -->
                                                    <?php if ($is_semestre_1): ?>
                                                        <td>
                                                            <input type="number" step="0.01" length="5"
                                                                name="notes[<?= $student['matricule'] ?>][s1]" class="form-control"
                                                                min="0" max="20"
                                                                value="<?= htmlspecialchars($existing_notes[$student['matricule']]['cote_s1'] ?? 0) ?>">
                                                        </td>
                                                    <?php elseif ($is_semestre_2): ?>
                                                        <td>
                                                            <input type="number" step="0.01" length="5"
                                                                name="notes[<?= $student['matricule'] ?>][s2]" class="form-control"
                                                                min="0" max="20"
                                                                value="<?= htmlspecialchars($existing_notes[$student['matricule']]['cote_s2'] ?? 0) ?>">
                                                        </td>
                                                    <?php else: ?>
                                                        <td>
                                                            <input type="number" step="0.01" length="5"
                                                                name="notes[<?= $student['matricule'] ?>][s1]" class="form-control"
                                                                min="0" max="20"
                                                                value="<?= htmlspecialchars($existing_notes[$student['matricule']]['cote_s1'] ?? 0) ?>">
                                                        </td>
                                                        <td>
                                                            <input type="number" step="0.01" length="5"
                                                                name="notes[<?= $student['matricule'] ?>][s2]" class="form-control"
                                                                min="0" max="20"
                                                                value="<?= htmlspecialchars($existing_notes[$student['matricule']]['cote_s2'] ?? 0) ?>">
                                                        </td>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3 d-flex justify-content-between no-print">
                                <?php if ($mode_rattrapage): ?>
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-save"></i> Enregistrer les notes de rattrapage
                                    </button>
                                    <div class="alert alert-info flex-grow-1 mx-3 mb-0 py-2">
                                        <small>
                                            <i class="fas fa-info-circle"></i>
                                            <strong>Mode Rattrapage :</strong> 
                                            Seuls les étudiants avec une note < 10/20 peuvent passer le rattrapage. 
                                            Note maximale de rattrapage : 20/20
                                        </small>
                                    </div>
                                <?php else: ?>
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save"></i> Enregistrer les notes
                                    </button>
                                <?php endif; ?>

                                <!-- Impression -->
                                <?php
                                $print_url = "../pages/domaine/tabs/cotation_print.php?id=" . urlencode($id_domaine) .
                                    "&mention=" . urlencode($mention_id) .
                                    "&promotion=" . urlencode($promotion_code) .
                                    "&semestre=" . urlencode($id_semestre) .
                                    ($is_ec_selected ? "&ec=" . urlencode($selected_ec_id) : "&ue=" . urlencode($selected_ue_id));
                                ?>
                                <button type="button" class="btn btn-primary"
                                    onclick="window.open('<?= $print_url ?>','_blank');">
                                    <i class="fas fa-print"></i> Imprimer la fiche
                                </button>
                            </div>
                        </form>

                    </div>
                </div>
                
                <?php if ($mode_rattrapage): ?>
                <!-- Script pour améliorer l'UX en mode rattrapage -->
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Ajouter des tooltips pour les règles de rattrapage
                    const inputs = document.querySelectorAll('input[name*="notes_rattrapage"]');
                    inputs.forEach(function(input) {
                        if (!input.disabled) {
                            input.addEventListener('input', function() {
                                const value = parseFloat(this.value);
                                if (value > 20) {
                                    this.value = 20;
                                }
                                if (value < 0) {
                                    this.value = 0;
                                }
                            });
                            
                            // Validation visuelle
                            input.addEventListener('blur', function() {
                                const value = parseFloat(this.value);
                                const row = this.closest('tr');
                                if (value > 0 && value <= 20) {
                                    this.classList.add('is-valid');
                                    this.classList.remove('is-invalid');
                                } else if (this.value !== '') {
                                    this.classList.add('is-invalid');
                                    this.classList.remove('is-valid');
                                } else {
                                    this.classList.remove('is-valid', 'is-invalid');
                                }
                            });
                        }
                    });
                    
                    // Confirmation avant soumission
                    const form = document.querySelector('form');
                    form.addEventListener('submit', function(e) {
                        const inputs = form.querySelectorAll('input[name*="notes_rattrapage"]');
                        let hasValues = false;
                        inputs.forEach(function(input) {
                            if (input.value && parseFloat(input.value) > 0) {
                                hasValues = true;
                            }
                        });
                        
                        if (hasValues) {
                            if (!confirm('Êtes-vous sûr de vouloir enregistrer ces notes de rattrapage ? Cette action sera tracée dans l\'historique.')) {
                                e.preventDefault();
                            }
                        }
                    });
                });
                </script>
                <?php endif; ?>
                
                <!-- Script pour gérer le défilement vers l'étudiant ciblé -->
                <script>
                // Fonction pour gérer le focus sur l'étudiant
                function focusOnStudent() {
                    console.log('Recherche de l\'ancre dans l\'URL...');
                    
                    // Vérifier s'il y a une ancre dans l'URL
                    if (window.location.hash) {
                        const targetId = window.location.hash.substring(1);
                        console.log('Ancre trouvée:', targetId);
                        
                        const targetElement = document.getElementById(targetId);
                        console.log('Élément trouvé:', targetElement);
                        
                        if (targetElement) {
                            console.log('Défilement vers l\'étudiant...');
                            
                            // Défilement immédiat
                            targetElement.scrollIntoView({ 
                                behavior: 'smooth', 
                                block: 'center' 
                            });
                            
                            // Ajouter un effet de surbrillance temporaire
                            targetElement.style.backgroundColor = '#fff3cd';
                            targetElement.style.transition = 'background-color 0.3s ease';
                            targetElement.style.border = '2px solid #ffc107';
                            
                            setTimeout(function() {
                                targetElement.style.backgroundColor = '';
                                targetElement.style.border = '';
                            }, 3000);
                            
                        } else {
                            console.log('Aucun élément trouvé avec l\'ID:', targetId);
                            // Essayer de trouver l'élément après un délai
                            setTimeout(function() {
                                const delayedTarget = document.getElementById(targetId);
                                if (delayedTarget) {
                                    console.log('Élément trouvé après délai');
                                    delayedTarget.scrollIntoView({ 
                                        behavior: 'smooth', 
                                        block: 'center' 
                                    });
                                    delayedTarget.style.backgroundColor = '#fff3cd';
                                    delayedTarget.style.transition = 'background-color 0.3s ease';
                                    delayedTarget.style.border = '2px solid #ffc107';
                                    
                                    setTimeout(function() {
                                        delayedTarget.style.backgroundColor = '';
                                        delayedTarget.style.border = '';
                                    }, 3000);
                                }
                            }, 1000);
                        }
                    } else {
                        console.log('Aucune ancre dans l\'URL');
                    }
                }

                // Exécuter quand le DOM est prêt
                document.addEventListener('DOMContentLoaded', focusOnStudent);
                
                // Exécuter aussi quand la page est complètement chargée (au cas où)
                window.addEventListener('load', function() {
                    setTimeout(focusOnStudent, 500);
                });
                
                // Exécuter si l'ancre change (navigation avec ancres)
                window.addEventListener('hashchange', focusOnStudent);
                </script>
            <?php else: ?>
                <div class="alert alert-info">
                    Veuillez sélectionner un cours ou une UE pour afficher la fiche de cotation.
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>
