<?php
// tabs/ue.php
// Ce fichier gère l'affichage des Unités d'Enseignement (UE) pour une promotion et un semestre donnés.

header('Content-Type: text/html; charset=UTF-8');

// Inclure les fonctions nécessaires
require_once 'includes/domaine_functions.php';

// ================================================
// RÉCUPÉRATION DE L'ANNÉE ACADÉMIQUE
// Priorité: 1. URL ($_GET['annee']), 2. Année en cours
// ================================================
$id_annee = getAnneeFromUrlOrDefault($pdo, $_GET['annee'] ?? null);

// Définir le sous-onglet actif.
// On utilise 'liste' par défaut, mais on peut aussi avoir 'ajouter_ue' ou 'gerer_ec'.
$ue_sub_tab = $_GET['sub_tab'] ?? 'liste';

// Récupération des paramètres nécessaires depuis l'URL
$id_domaine = isset($_GET['id']) ? (int) $_GET['id'] : null;
$mention_id = isset($_GET['mention']) ? (int) $_GET['mention'] : null;
$promotion_code = isset($_GET['promotion']) ? htmlspecialchars($_GET['promotion']) : '';
$id_semestre = isset($_GET['semestre']) ? (int) $_GET['semestre'] : null;
$id_ue = isset($_GET['id_ue']) ? (int) $_GET['id_ue'] : null; // Nouvel identifiant pour l'UE

// Vérification de l'année académique
if (!$id_annee) {
    echo '<div class="alert alert-danger">Aucune année académique configurée. Veuillez contacter l\'administrateur.</div>';
    return;
}

$clone_message = null;
$clone_message_type = 'info';

// Récupérer TOUJOURS les semestres disponibles pour l'année académique
$sql_semestres = "SELECT id_semestre, nom_semestre FROM t_semestre WHERE id_annee = ? ORDER BY nom_semestre ASC";
$stmt_semestres = $pdo->prepare($sql_semestres);
$stmt_semestres->execute([$id_annee]);
$semestres = $stmt_semestres->fetchAll(PDO::FETCH_ASSOC);

if (empty($semestres)) {
    echo '<div class="alert alert-info">Aucun semestre n\'a été trouvé pour l\'année académique en cours.</div>';
    return;
}

// =========================
// CLONAGE UE/EC D'UNE ANNÉE PASSÉE
// =========================
if ($ue_sub_tab === 'liste' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clone_semestre_source'])) {
    $source_semestre = (int) ($_POST['clone_semestre_source'] ?? 0);
    $target_semestre = (int) ($_POST['clone_semestre_cible'] ?? 0);

    if ($source_semestre > 0 && $target_semestre > 0 && $target_semestre === $id_semestre) {
        try {
            $pdo->beginTransaction();

            $sql_source_ues = "
                SELECT ue.id_ue, ue.code_ue, ue.libelle, ue.credits, ue.heures_th, ue.heures_td, ue.heures_tp, mu.semestre
                FROM t_unite_enseignement ue
                INNER JOIN t_mention_ue mu ON mu.id_ue = ue.id_ue
                WHERE ue.id_semestre = ?
                  AND ue.code_promotion = ?
                  AND mu.id_mention = ?
                ORDER BY ue.code_ue ASC
            ";
            $stmt_source_ues = $pdo->prepare($sql_source_ues);
            $stmt_source_ues->execute([$source_semestre, $promotion_code, $mention_id]);
            $source_ues = $stmt_source_ues->fetchAll(PDO::FETCH_ASSOC);

            if (empty($source_ues)) {
                throw new Exception('Aucune UE source trouvée pour le semestre sélectionné.');
            }

            $ues_clonees = 0;
            $ecs_clones = 0;

            foreach ($source_ues as $source_ue) {
                // Éviter les doublons UE : même promotion + code UE + semestre cible
                $sql_check_ue = "SELECT id_ue FROM t_unite_enseignement WHERE code_promotion = ? AND code_ue = ? AND id_semestre = ? LIMIT 1";
                $stmt_check_ue = $pdo->prepare($sql_check_ue);
                $stmt_check_ue->execute([$promotion_code, $source_ue['code_ue'], $target_semestre]);
                $target_ue_id = $stmt_check_ue->fetchColumn();

                if (!$target_ue_id) {
                    $sql_insert_ue = "
                        INSERT INTO t_unite_enseignement
                        (code_promotion, code_ue, libelle, credits, id_semestre, heures_th, heures_td, heures_tp, is_programmed)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
                    ";
                    $stmt_insert_ue = $pdo->prepare($sql_insert_ue);
                    $stmt_insert_ue->execute([
                        $promotion_code,
                        $source_ue['code_ue'],
                        $source_ue['libelle'],
                        $source_ue['credits'],
                        $target_semestre,
                        $source_ue['heures_th'],
                        $source_ue['heures_td'],
                        $source_ue['heures_tp']
                    ]);
                    $target_ue_id = (int) $pdo->lastInsertId();
                    $ues_clonees++;
                }

                // Lier la mention à l'UE si absent
                $sql_check_mu = "SELECT id_mention_ue FROM t_mention_ue WHERE id_mention = ? AND id_ue = ? LIMIT 1";
                $stmt_check_mu = $pdo->prepare($sql_check_mu);
                $stmt_check_mu->execute([$mention_id, $target_ue_id]);
                $target_mention_ue_id = $stmt_check_mu->fetchColumn();

                if (!$target_mention_ue_id) {
                    $sql_insert_mu = "INSERT INTO t_mention_ue (id_mention, id_ue, semestre, credits) VALUES (?, ?, ?, ?)";
                    $stmt_insert_mu = $pdo->prepare($sql_insert_mu);
                    $stmt_insert_mu->execute([$mention_id, $target_ue_id, $target_semestre, (int) $source_ue['credits']]);
                    $target_mention_ue_id = (int) $pdo->lastInsertId();
                }

                // Cloner les EC de l'UE source vers UE cible
                $sql_source_ecs = "
                    SELECT id_ec, code_ec, libelle, coefficient, heures_th, heures_td, heures_tp
                    FROM t_element_constitutif
                    WHERE id_ue = ?
                    ORDER BY code_ec ASC
                ";
                $stmt_source_ecs = $pdo->prepare($sql_source_ecs);
                $stmt_source_ecs->execute([$source_ue['id_ue']]);
                $source_ecs = $stmt_source_ecs->fetchAll(PDO::FETCH_ASSOC);

                foreach ($source_ecs as $source_ec) {
                    $sql_check_ec = "SELECT id_ec FROM t_element_constitutif WHERE id_ue = ? AND code_ec = ? LIMIT 1";
                    $stmt_check_ec = $pdo->prepare($sql_check_ec);
                    $stmt_check_ec->execute([$target_ue_id, $source_ec['code_ec']]);
                    $target_ec_id = $stmt_check_ec->fetchColumn();

                    if (!$target_ec_id) {
                        $sql_insert_ec = "
                            INSERT INTO t_element_constitutif
                            (id_ue, code_ec, libelle, coefficient, heures_th, heures_td, heures_tp, is_programmed)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 0)
                        ";
                        $stmt_insert_ec = $pdo->prepare($sql_insert_ec);
                        $stmt_insert_ec->execute([
                            $target_ue_id,
                            $source_ec['code_ec'],
                            $source_ec['libelle'],
                            $source_ec['coefficient'],
                            $source_ec['heures_th'],
                            $source_ec['heures_td'],
                            $source_ec['heures_tp']
                        ]);
                        $target_ec_id = (int) $pdo->lastInsertId();
                        $ecs_clones++;
                    }

                    // Lier EC à la mention_ue si absent
                    $sql_check_muec = "SELECT 1 FROM t_mention_ue_ec WHERE id_mention_ue = ? AND id_ec = ? LIMIT 1";
                    $stmt_check_muec = $pdo->prepare($sql_check_muec);
                    $stmt_check_muec->execute([$target_mention_ue_id, $target_ec_id]);

                    if (!$stmt_check_muec->fetchColumn()) {
                        $sql_insert_muec = "INSERT INTO t_mention_ue_ec (id_mention_ue, id_ec) VALUES (?, ?)";
                        $stmt_insert_muec = $pdo->prepare($sql_insert_muec);
                        $stmt_insert_muec->execute([$target_mention_ue_id, $target_ec_id]);
                    }
                }
            }

            $pdo->commit();
            $clone_message_type = 'success';
            $clone_message = "Clonage terminé : {$ues_clonees} UE(s) et {$ecs_clones} EC(s) copiés avec is_programmed = 0.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $clone_message_type = 'danger';
            $clone_message = 'Erreur lors du clonage : ' . $e->getMessage();
        }
    } else {
        $clone_message_type = 'warning';
        $clone_message = 'Sélection invalide pour le clonage.';
    }
}

// =========================
// AJOUT D'UNE UE
// =========================
if ($ue_sub_tab === 'ajouter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $code_ue = htmlspecialchars($_POST['code_ue'] ?? '');
    $libelle = htmlspecialchars($_POST['libelle'] ?? '');
    $heures_th = (int) ($_POST['heures_th'] ?? 0);
    $heures_td = (int) ($_POST['heures_td'] ?? 0);
    $heures_tp = (int) ($_POST['heures_tp'] ?? 0);
    $credits = (int) ($_POST['credits'] ?? 0); // Saisie manuelle

    if (!empty($code_ue) && !empty($libelle) && $credits > 0) {

        // Empêcher les doublons UE pour la même mention/promotion
        $sql_check_ue_dup = "
            SELECT ue.id_ue
            FROM t_unite_enseignement ue
            INNER JOIN t_mention_ue mu ON mu.id_ue = ue.id_ue
            WHERE ue.code_promotion = ? AND mu.id_mention = ? AND ue.code_ue = ?
            LIMIT 1
        ";
        $stmt_check_ue_dup = $pdo->prepare($sql_check_ue_dup);
        $stmt_check_ue_dup->execute([$promotion_code, $mention_id, $code_ue]);
        if ($stmt_check_ue_dup->fetchColumn()) {
            echo '<div class="alert alert-warning">Cette UE existe déjà pour cette mention/promotion.</div>';
        } else {

        $pdo->beginTransaction();

        // 1. Insertion dans t_unite_enseignement
        $sql_insert_ue = "INSERT INTO t_unite_enseignement (
                code_promotion, id_semestre, code_ue, libelle, credits, heures_th, heures_td, heures_tp
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_ue = $pdo->prepare($sql_insert_ue);
        $stmt_ue->execute([
            $promotion_code,
            null,
            $code_ue,
            $libelle,
            $credits,
            $heures_th,
            $heures_td,
            $heures_tp
        ]);
        $id_ue = $pdo->lastInsertId();

        // 2. Lier à la mention via t_mention_ue
        $sql_link_ue = "INSERT INTO t_mention_ue (id_mention, id_ue, semestre) VALUES (?, ?, ?)";
        $stmt_link_ue = $pdo->prepare($sql_link_ue);
        $stmt_link_ue->execute([$mention_id, $id_ue, null]);

        $pdo->commit();

        echo '<div class="alert alert-success">UE ajoutée avec succès (' . $credits . ' crédits).</div>';

        }

    } else {
        echo '<div class="alert alert-warning">Veuillez remplir tous les champs obligatoires.</div>';
    }
}

// =========================
// AJOUT D'UN EC
// =========================
if ($ue_sub_tab === 'gerer_ec' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($id_ue)) {
    $code_ec = htmlspecialchars($_POST['code_ec'] ?? '');
    $libelle_ec = htmlspecialchars($_POST['libelle_ec'] ?? '');
    $heures_th_ec = (int) ($_POST['heures_th_ec'] ?? 0);
    $heures_td_ec = (int) ($_POST['heures_td_ec'] ?? 0);
    $heures_tp_ec = (int) ($_POST['heures_tp_ec'] ?? 0);
    $coefficient = (int) ($_POST['coefficient'] ?? 0); // Saisie manuelle

    if (!empty($code_ec) && !empty($libelle_ec) && $coefficient > 0) {
        try {
            $pdo->beginTransaction();

            // Empêcher les doublons EC (même code_ec dans la même UE)
            $sql_check_ec_dup = "SELECT id_ec FROM t_element_constitutif WHERE id_ue = ? AND code_ec = ? LIMIT 1";
            $stmt_check_ec_dup = $pdo->prepare($sql_check_ec_dup);
            $stmt_check_ec_dup->execute([$id_ue, $code_ec]);
            if ($stmt_check_ec_dup->fetchColumn()) {
                throw new Exception('Cet EC existe déjà dans cette UE.');
            }

            // 1. Vérifier crédits UE
            $sql_credits_ue = "SELECT credits FROM t_unite_enseignement WHERE id_ue = ?";
            $stmt_credits_ue = $pdo->prepare($sql_credits_ue);
            $stmt_credits_ue->execute([$id_ue]);
            $credits_ue = $stmt_credits_ue->fetchColumn();

            $sql_total_coeff = "SELECT SUM(coefficient) FROM t_element_constitutif WHERE id_ue = ?";
            $stmt_total_coeff = $pdo->prepare($sql_total_coeff);
            $stmt_total_coeff->execute([$id_ue]);
            $total_coeff_actuel = $stmt_total_coeff->fetchColumn() ?: 0;

            if (($total_coeff_actuel + $coefficient) > $credits_ue) {
                throw new Exception('Total des coefficients dépasse les crédits de l\'UE.');
            }

            // 2. Insertion EC
            $sql_insert_ec = "INSERT INTO t_element_constitutif (
                id_ue, code_ec, libelle, coefficient, heures_th, heures_td, heures_tp
            ) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt_insert_ec = $pdo->prepare($sql_insert_ec);
            $stmt_insert_ec->execute([
                $id_ue,
                $code_ec,
                $libelle_ec,
                $coefficient,
                $heures_th_ec,
                $heures_td_ec,
                $heures_tp_ec
            ]);
            $id_ec = $pdo->lastInsertId();

            // 3. Lier EC via t_mention_ue_ec
            $sql_get_mention_ue = "SELECT id_mention_ue FROM t_mention_ue WHERE id_mention = ? AND id_ue = ?";
            $stmt_get_mention_ue = $pdo->prepare($sql_get_mention_ue);
            $stmt_get_mention_ue->execute([$mention_id, $id_ue]);
            $id_mention_ue = $stmt_get_mention_ue->fetchColumn();

            $sql_link_ec = "INSERT INTO t_mention_ue_ec (id_mention_ue, id_ec) VALUES (?, ?)";
            $stmt_link_ec = $pdo->prepare($sql_link_ec);
            $stmt_link_ec->execute([$id_mention_ue, $id_ec]);

            $pdo->commit();

            echo '<div class="alert alert-success">EC ajouté avec succès.</div>';
        } catch (Exception $e) {
            $pdo->rollBack();
            echo '<div class="alert alert-danger">Erreur ajout EC : ' . $e->getMessage() . '</div>';
        }
    } else {
        echo '<div class="alert alert-warning">Veuillez remplir tous les champs EC.</div>';
    }
}



?>

<!-- ============================================ -->
<!-- SÉLECTEUR DE SEMESTRE PERSISTANT            -->
<!-- ============================================ -->
<div class="card mb-3 shadow-sm">
    <div class="card-body py-2">
        <div class="row align-items-center">
            <div class="col-auto">
                <label for="semestre_select" class="col-form-label fw-bold mb-0">
                    <i class="fas fa-calendar-alt me-1"></i> Semestre :
                </label>
            </div>
            <div class="col-md-4">
                <select class="form-select" id="semestre_select" onchange="changeSemestre(this.value)">
                    <option value="">-- Choisir un semestre --</option>
                    <?php foreach ($semestres as $sem): ?>
                        <option value="<?php echo $sem['id_semestre']; ?>" <?php echo ($id_semestre == $sem['id_semestre']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sem['nom_semestre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($id_semestre): ?>
                <div class="col-auto">
                    <?php
                    $nom_semestre_actif = '';
                    foreach ($semestres as $sem) {
                        if ($sem['id_semestre'] == $id_semestre) {
                            $nom_semestre_actif = $sem['nom_semestre'];
                            break;
                        }
                    }
                    ?>
                    <span class="badge bg-success fs-6">
                        <i class="fas fa-check-circle me-1"></i> <?php echo htmlspecialchars($nom_semestre_actif); ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function changeSemestre(idSemestre) {
    if (!idSemestre) return;
    const params = new URLSearchParams(window.location.search);
    params.set('semestre', idSemestre);
    // Garder le sub_tab actuel sauf gerer_ec (qui dépend d'un id_ue spécifique)
    const currentSubTab = params.get('sub_tab');
    if (currentSubTab === 'gerer_ec') {
        params.set('sub_tab', 'liste');
        params.delete('id_ue');
    }
    window.location.search = params.toString();
}
</script>

<?php if (false && empty($id_semestre)): ?>
    <div class="alert alert-warning text-center">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Veuillez sélectionner un semestre ci-dessus pour afficher les unités d'enseignement.
    </div>
<?php else: ?>

<div class="card mt-0">
    <!-- Onglets pour la gestion des UE -->
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item">
                <a class="nav-link <?php echo $ue_sub_tab === 'liste' ? 'active' : ''; ?>"
                    href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&semestre=<?php echo $id_semestre; ?>&tab=ue&sub_tab=liste">
                    Liste des UE
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $ue_sub_tab === 'ajouter' ? 'active' : ''; ?>"
                    href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&semestre=<?php echo $id_semestre; ?>&tab=ue&sub_tab=ajouter">
                    Ajouter une UE
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $ue_sub_tab === 'programmation' ? 'active' : ''; ?>"
                    href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&semestre=<?php echo $id_semestre; ?>&tab=ue&sub_tab=programmation">
                    <i class="bi bi-calendar-check"></i> Programmation
                </a>
            </li>
            <!-- Nouvel onglet pour gérer les EC -->
            <?php if (!empty($id_ue)): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $ue_sub_tab === 'gerer_ec' ? 'active' : ''; ?>"
                        href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&semestre=<?php echo $id_semestre; ?>&tab=ue&sub_tab=gerer_ec&id_ue=<?php echo $id_ue; ?>">
                        Gérer les EC
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="card-body">

        <!-- Contenu des sous-onglets -->
        <div class="tab-content">
            <?php if ($ue_sub_tab === 'liste'): ?>
                <?php
                try {
                    // Requête SQL pour récupérer les UE et leurs EC
                    $sql_ues = "
                        SELECT * FROM v_unites_enseignements WHERE code_promotion = ? AND id_mention = ?
                    ";
                    $params_ues = [$promotion_code, $mention_id];
                    if (!empty($id_semestre)) {
                        $sql_ues .= " AND id_semestre = ?";
                        $params_ues[] = $id_semestre;
                    }
                    $sql_ues .= ";";

                    $stmt_ues = $pdo->prepare($sql_ues);
                    $stmt_ues->execute($params_ues);

                    $results = $stmt_ues->fetchAll(PDO::FETCH_ASSOC);

                    // Organiser les résultats par UE
                    $ues = [];
                    foreach ($results as $row) {
                        $id_ue = $row['id_ue'];
                        if (!isset($ues[$id_ue])) {
                            $ues[$id_ue] = [
                                'code_ue' => $row['code_ue'],
                                'libelle' => $row['ue_libelle'],
                                'ue_heures_th' => $row['ue_heures_th'],
                                'ue_heures_td' => $row['ue_heures_td'],
                                'ue_heures_tp' => $row['ue_heures_tp'],
                                'ue_credits' => $row['ue_credits'],
                                'ecs' => []
                            ];
                        }
                        if ($row['id_ec']) {
                            $ues[$id_ue]['ecs'][] = [
                                'id_ec' => $row['id_ec'],
                                'code_ec' => $row['code_ec'],
                                'ec_libelle' => $row['ec_libelle'],
                                'coefficient' => $row['coefficient'],
                                'ec_heures_th' => $row['ec_heures_th'],
                                'ec_heures_td' => $row['ec_heures_td'],
                                'ec_heures_tp' => $row['ec_heures_tp'],
                                'ec_total_heures' => $row['ec_total_heures']
                            ];
                        }
                    }

                } catch (PDOException $e) {
                    echo '<div class="alert alert-danger">Erreur de base de données : ' . $e->getMessage() . '</div>';
                    $ues = [];
                }
                ?>
                <?php if (!empty($clone_message)): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($clone_message_type); ?>">
                        <?php echo htmlspecialchars($clone_message); ?>
                    </div>
                <?php endif; ?>
                <div class="table-responsive">
                    <?php if (!empty($ues)): ?>
                        <table class="table table-bordered table-hover">
                            <thead class="table-dark">
                                <tr style="text-align: center;">
                                    <th rowspan="2" class="align-middle">Code UE</th>
                                    <th rowspan="2" class="align-middle">Intitulés des UE</th>
                                    <th colspan="3" class="text-center">Heures</th>
                                    <th colspan="2" class="align-middle">Crédits</th>
                                    <th rowspan="2" class="align-middle">Actions</th>
                                </tr>
                                <tr style="text-align: center;">
                                    <th>CM/TH</th>
                                    <th>TD</th>
                                    <th>TP</th>
                                    <th>EC</th>
                                    <th>UE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ues as $id_ue => $ue): ?>
                                    <?php
                                    // Calculer le rowspan pour les colonnes Code UE et Actions
                                    $rowspan = count($ue['ecs']) > 0 ? count($ue['ecs']) + 1 : 1;
                                    ?>
                                    <!-- Ligne principale pour l'UE -->
                                    <tr class="table-info">
                                        <td rowspan="<?php echo $rowspan; ?>" class="align-middle text-center font-weight-bold">
                                            <?php echo htmlspecialchars($ue['code_ue']); ?>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($ue['libelle']); ?></strong></td>
                                        <td style="text-align: center;"><?php echo htmlspecialchars($ue['ue_heures_th']); ?>h</td>
                                        <td style="text-align: center;"><?php echo htmlspecialchars($ue['ue_heures_td']); ?>h</td>
                                        <td style="text-align: center;"><?php echo htmlspecialchars($ue['ue_heures_tp']); ?>h</td>
                                        <td style="text-align: center;"></td>
                                        <td class="align-middle text-center">
                                            <?php echo htmlspecialchars($ue['ue_credits']); ?>
                                        </td>
                                        <td rowspan="<?php echo $rowspan; ?>" class="align-middle text-center">
                                            <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&semestre=<?php echo $id_semestre; ?>&tab=ue&sub_tab=gerer_ec&id_ue=<?php echo $id_ue; ?>"
                                                class="btn btn-sm btn-primary" title="Gérer les EC">
                                                <i class="fas fa-list-alt"></i> Gérer EC
                                            </a>
                                        </td>
                                    </tr>
                                    <!-- Lignes pour les Éléments Constitutifs -->
                                    <?php if (!empty($ue['ecs'])): ?>
                                        <?php foreach ($ue['ecs'] as $ec): ?>
                                            <tr>
                                                <td style="text-align: left; padding-left: 20px;">
                                                    <?php echo htmlspecialchars($ec['code_ec']); ?> -
                                                    <?php echo htmlspecialchars($ec['ec_libelle']); ?>
                                                </td>
                                                <td style="text-align: center;"><?php echo htmlspecialchars($ec['ec_heures_th']); ?>h</td>
                                                <td style="text-align: center;"><?php echo htmlspecialchars($ec['ec_heures_td']); ?>h</td>
                                                <td style="text-align: center;"><?php echo htmlspecialchars($ec['ec_heures_tp']); ?>h</td>
                                                <td style="text-align: center;"><?php echo htmlspecialchars($ec['coefficient']); ?></td>
                                                <td style="text-align: center;"></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php
                            // Calcul du total des crédits pour le semestre sélectionné
                            $sql_total_credits = "SELECT SUM(ue.credits) AS total_credits
                                FROM t_unite_enseignement ue
                                INNER JOIN t_mention_ue mu ON mu.id_ue = ue.id_ue
                                WHERE ue.code_promotion = ? AND mu.id_mention = ?";
                            $params_total_credits = [$promotion_code, $mention_id];
                            if (!empty($id_semestre)) {
                                $sql_total_credits .= " AND ue.id_semestre = ?";
                                $params_total_credits[] = $id_semestre;
                            }
                            $stmt_total_credits = $pdo->prepare($sql_total_credits);
                            $stmt_total_credits->execute($params_total_credits);
                            $total_credits = $stmt_total_credits->fetchColumn();
                            ?>
                            <tr>
                                <td colspan="6" class="text-right">Total des crédits pour le semestre :</td>
                                <td class="text-center"><?php echo htmlspecialchars($total_credits ?: 0); ?></td>
                                <td></td>
                            </tr>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-info text-center mt-3">
                            Aucune Unité d'Enseignement trouvée pour cette sélection.
                        </div>

                        <?php
                        $sql_sources_clone = "
                            SELECT DISTINCT
                                s.id_semestre,
                                s.nom_semestre,
                                aa.id_annee,
                                aa.date_debut,
                                aa.date_fin,
                                COUNT(DISTINCT ue.id_ue) AS nb_ues
                            FROM t_unite_enseignement ue
                            INNER JOIN t_mention_ue mu ON mu.id_ue = ue.id_ue
                            INNER JOIN t_semestre s ON s.id_semestre = ue.id_semestre
                            INNER JOIN t_anne_academique aa ON aa.id_annee = s.id_annee
                            WHERE ue.code_promotion = ?
                              AND mu.id_mention = ?
                              AND s.id_annee <> ?
                            GROUP BY s.id_semestre, s.nom_semestre, aa.id_annee, aa.date_debut, aa.date_fin
                            HAVING nb_ues > 0
                            ORDER BY aa.date_debut DESC, s.nom_semestre ASC
                        ";
                        $stmt_sources_clone = $pdo->prepare($sql_sources_clone);
                        $stmt_sources_clone->execute([$promotion_code, $mention_id, $id_annee]);
                        $sources_clone = $stmt_sources_clone->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <?php if (!empty($sources_clone)): ?>
                            <div class="card border-warning mt-3">
                                <div class="card-body">
                                    <h5 class="card-title mb-3">
                                        <i class="fas fa-copy me-2"></i>Suggestion : cloner depuis une année passée
                                    </h5>
                                    <p class="text-muted mb-3">
                                        Aucun programme n'existe pour ce semestre. Vous pouvez cloner des UE/EC depuis une année précédente.
                                        Les éléments clonés seront créés avec <strong>is_programmed = 0</strong>.
                                    </p>
                                    <form method="POST" action="">
                                        <input type="hidden" name="clone_semestre_cible" value="<?php echo (int) $id_semestre; ?>">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-md-8">
                                                <label for="clone_semestre_source" class="form-label">Semestre source</label>
                                                <select class="form-select" id="clone_semestre_source" name="clone_semestre_source" required>
                                                    <option value="">-- Choisir un semestre à cloner --</option>
                                                    <?php foreach ($sources_clone as $source): ?>
                                                        <option value="<?php echo (int) $source['id_semestre']; ?>">
                                                            <?php
                                                            echo htmlspecialchars(
                                                                $source['nom_semestre']
                                                                . ' | Année ' . date('Y', strtotime($source['date_debut']))
                                                                . '-' . date('Y', strtotime($source['date_fin']))
                                                                . ' | ' . (int) $source['nb_ues'] . ' UE'
                                                            );
                                                            ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4 d-grid">
                                                <button type="submit" class="btn btn-warning"
                                                    onclick="return confirm('Cloner les UE/EC dans ce semestre avec is_programmed=0 ?');">
                                                    <i class="fas fa-clone me-1"></i> Cloner le programme
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($ues)): ?>
                        <div class="d-flex justify-content-end mb-3">
                            <a href="../pages/domaine/tabs/programme_print.php?id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&promotion=<?php echo $promotion_code; ?>&semestre=<?php echo $id_semestre; ?>"
                                class="btn btn-secondary" target="_blank">
                                <i class="fas fa-print"></i> Imprimer le programme
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($ue_sub_tab === 'ajouter'): ?>
                <div class="container mt-3">
                    <h3>Ajouter une nouvelle Unité d'Enseignement</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="promotion_code" value="<?php echo htmlspecialchars($promotion_code); ?>">
                        <input type="hidden" name="id_semestre" value="<?php echo htmlspecialchars($id_semestre); ?>">
                        <div class="form-group mb-3">
                            <label for="code_ue">Code de l'UE</label>
                            <input type="text" class="form-control" id="code_ue" name="code_ue" required>
                        </div>
                        <div class="form-group mb-3">
                            <label for="libelle">Libellé</label>
                            <input type="text" class="form-control" id="libelle" name="libelle" required>
                        </div>
                        <div class="form-group mb-3">
                            <label for="heures_th">Heures TH (Théorie)</label>
                            <input type="number" class="form-control" id="heures_th" name="heures_th" value="0" min="0">
                        </div>
                        <div class="form-group mb-3">
                            <label for="heures_td">Heures TD (Travaux Dirigés)</label>
                            <input type="number" class="form-control" id="heures_td" name="heures_td" value="0" min="0">
                        </div>
                        <div class="form-group mb-3">
                            <label for="heures_tp">Heures TP (Travaux Pratiques)</label>
                            <input type="number" class="form-control" id="heures_tp" name="heures_tp" value="0" min="0">
                        </div>
                        <div class="form-group mb-3">
                            <label for="credits">Nombre de crédits</label>
                            <input type="number" class="form-control" id="credits" name="credits" value="0" min="0"
                                required>
                        </div>
                        <button type="submit" class="btn btn-primary">Ajouter l'UE</button>
                    </form>
                </div>

                <?php
                // Gestion de la suppression d'une UE
                if (isset($_POST['delete_ue_id']) && is_numeric($_POST['delete_ue_id'])) {
                    $delete_ue_id = (int) $_POST['delete_ue_id'];
                    try {
                        $pdo->beginTransaction();

                        // Supprimer les liaisons EC
                        $sql_del_ec_links = "DELETE FROM t_mention_ue_ec WHERE id_mention_ue IN (SELECT id_mention_ue FROM t_mention_ue WHERE id_ue = ?)";
                        $stmt_del_ec_links = $pdo->prepare($sql_del_ec_links);
                        $stmt_del_ec_links->execute([$delete_ue_id]);

                        // Supprimer les EC
                        $sql_del_ec = "DELETE FROM t_element_constitutif WHERE id_ue = ?";
                        $stmt_del_ec = $pdo->prepare($sql_del_ec);
                        $stmt_del_ec->execute([$delete_ue_id]);

                        // Supprimer la liaison mention_ue
                        $sql_del_mention_ue = "DELETE FROM t_mention_ue WHERE id_ue = ?";
                        $stmt_del_mention_ue = $pdo->prepare($sql_del_mention_ue);
                        $stmt_del_mention_ue->execute([$delete_ue_id]);

                        // Supprimer l'UE
                        $sql_del_ue = "DELETE FROM t_unite_enseignement WHERE id_ue = ?";
                        $stmt_del_ue = $pdo->prepare($sql_del_ue);
                        $stmt_del_ue->execute([$delete_ue_id]);

                        $pdo->commit();
                        echo '<div class="alert alert-success">Unité d\'Enseignement supprimée avec succès.</div>';
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        echo '<div class="alert alert-danger">Erreur lors de la suppression : ' . $e->getMessage() . '</div>';
                    }
                }

                // Gestion de la modification d'une UE
                $edit_ue = null;
                if (isset($_GET['edit_ue_id']) && is_numeric($_GET['edit_ue_id'])) {
                    $edit_ue_id = (int) $_GET['edit_ue_id'];
                    $sql_edit_ue = "SELECT * FROM t_unite_enseignement WHERE id_ue = ?";
                    $stmt_edit_ue = $pdo->prepare($sql_edit_ue);
                    $stmt_edit_ue->execute([$edit_ue_id]);
                    $edit_ue = $stmt_edit_ue->fetch(PDO::FETCH_ASSOC);
                }

                if (isset($_POST['edit_ue_id']) && is_numeric($_POST['edit_ue_id'])) {
                    $edit_ue_id = (int) $_POST['edit_ue_id'];
                    $code_ue = htmlspecialchars($_POST['code_ue_edit'] ?? '');
                    $libelle = htmlspecialchars($_POST['libelle_edit'] ?? '');
                    $heures_th = (int) ($_POST['heures_th_edit'] ?? 0);
                    $heures_td = (int) ($_POST['heures_td_edit'] ?? 0);
                    $heures_tp = (int) ($_POST['heures_tp_edit'] ?? 0);
                    $credits = (int) ($_POST['credits_edit'] ?? 0);

                    if (!empty($code_ue) && !empty($libelle) && $credits > 0) {
                        try {
                            $pdo->beginTransaction();
                            $sql_update_ue = "UPDATE t_unite_enseignement SET code_ue = ?, libelle = ?, heures_th = ?, heures_td = ?, heures_tp = ?, credits = ? WHERE id_ue = ?";
                            $stmt_update_ue = $pdo->prepare($sql_update_ue);
                            $stmt_update_ue->execute([
                                $code_ue,
                                $libelle,
                                $heures_th,
                                $heures_td,
                                $heures_tp,
                                $credits,
                                $edit_ue_id
                            ]);
                            $pdo->commit();
                            echo '<div class="alert alert-success">Unité d\'Enseignement modifiée avec succès.</div>';
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            echo '<div class="alert alert-danger">Erreur lors de la modification : ' . $e->getMessage() . '</div>';
                        }
                    } else {
                        echo '<div class="alert alert-warning">Veuillez remplir tous les champs obligatoires.</div>';
                    }
                }

                // Affichage du tableau des UE existantes
                $sql_ues = "SELECT ue.id_ue, ue.code_ue, ue.libelle, ue.heures_th, ue.heures_td, ue.heures_tp, ue.credits
                            FROM t_unite_enseignement ue
                            INNER JOIN t_mention_ue mu ON mu.id_ue = ue.id_ue
                            WHERE ue.code_promotion = ? AND mu.id_mention = ?";
                $params_ues_ajouter = [$promotion_code, $mention_id];
                if (!empty($id_semestre)) {
                    $sql_ues .= " AND ue.id_semestre = ?";
                    $params_ues_ajouter[] = $id_semestre;
                }
                $sql_ues .= " ORDER BY ue.code_ue ASC";
                $stmt_ues = $pdo->prepare($sql_ues);
                $stmt_ues->execute($params_ues_ajouter);
                $ues = $stmt_ues->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <div class="table-responsive mt-4">
                    <h4>Liste des Unités d'Enseignement</h4>
                    <table class="table table-bordered table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Code UE</th>
                                <th>Libellé</th>
                                <th>Heures TH</th>
                                <th>Heures TD</th>
                                <th>Heures TP</th>
                                <th>Crédits</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ues as $ue): ?>
                                <?php if ($edit_ue && $edit_ue['id_ue'] == $ue['id_ue']): ?>
                                    <tr>
                                        <form method="POST" action="">
                                            <td>
                                                <input type="text" name="code_ue_edit" class="form-control"
                                                    value="<?php echo htmlspecialchars($ue['code_ue']); ?>" required>
                                            </td>
                                            <td>
                                                <input type="text" name="libelle_edit" class="form-control"
                                                    value="<?php echo htmlspecialchars($ue['libelle']); ?>" required>
                                            </td>
                                            <td>
                                                <input type="number" name="heures_th_edit" class="form-control"
                                                    value="<?php echo htmlspecialchars($ue['heures_th']); ?>" min="0">
                                            </td>
                                            <td>
                                                <input type="number" name="heures_td_edit" class="form-control"
                                                    value="<?php echo htmlspecialchars($ue['heures_td']); ?>" min="0">
                                            </td>
                                            <td>
                                                <input type="number" name="heures_tp_edit" class="form-control"
                                                    value="<?php echo htmlspecialchars($ue['heures_tp']); ?>" min="0">
                                            </td>
                                            <td>
                                                <input type="number" name="credits_edit" class="form-control"
                                                    value="<?php echo htmlspecialchars($ue['credits']); ?>" min="0" required>
                                            </td>
                                            <td>
                                                <input type="hidden" name="edit_ue_id" value="<?php echo $ue['id_ue']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success" title="Enregistrer">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&semestre=<?php echo $id_semestre; ?>&tab=ue&sub_tab=ajouter"
                                                    class="btn btn-sm btn-secondary" title="Annuler">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            </td>
                                        </form>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($ue['code_ue']); ?></td>
                                        <td><?php echo htmlspecialchars($ue['libelle']); ?></td>
                                        <td><?php echo htmlspecialchars($ue['heures_th']); ?></td>
                                        <td><?php echo htmlspecialchars($ue['heures_td']); ?></td>
                                        <td><?php echo htmlspecialchars($ue['heures_tp']); ?></td>
                                        <td><?php echo htmlspecialchars($ue['credits']); ?></td>
                                        <td>
                                            <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&semestre=<?php echo $id_semestre; ?>&tab=ue&sub_tab=ajouter&edit_ue_id=<?php echo $ue['id_ue']; ?>"
                                                class="btn btn-sm btn-warning" title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="POST" action="" style="display:inline;"
                                                onsubmit="return confirm('Voulez-vous vraiment supprimer cette UE ?');">
                                                <input type="hidden" name="delete_ue_id" value="<?php echo $ue['id_ue']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Supprimer">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            
                        </tbody>

                    </table>
                </div>

            <?php elseif ($ue_sub_tab === 'gerer_ec' && !empty($id_ue)): ?>
                <?php
                // Récupérer l'UE parente pour affichage
                $sql_ue_info = "SELECT code_ue, libelle FROM t_unite_enseignement WHERE id_ue = ?";
                $stmt_ue_info = $pdo->prepare($sql_ue_info);
                $stmt_ue_info->execute([$id_ue]);
                $ue_info = $stmt_ue_info->fetch(PDO::FETCH_ASSOC);

                if ($ue_info):
                    ?>
                    <div class="container mt-3">
                        <h3>Gérer les Éléments Constitutifs pour l'UE : <?php echo htmlspecialchars($ue_info['libelle']); ?>
                            (<?php echo htmlspecialchars($ue_info['code_ue']); ?>)</h3>

                        <!-- Formulaire d'ajout d'EC -->
                        <div class="card mb-4">
                            <div class="card-header">Ajouter un nouvel Élément Constitutif</div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="id_ue" value="<?php echo htmlspecialchars($id_ue); ?>">
                                    <div class="form-group mb-3">
                                        <label for="code_ec">Code de l'EC</label>
                                        <input type="text" class="form-control" id="code_ec" name="code_ec" required>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="libelle_ec">Libellé</label>
                                        <input type="text" class="form-control" id="libelle_ec" name="libelle_ec" required>
                                    </div>
                                    <!-- Champs des heures qui serviront au calcul -->
                                    <div class="form-group mb-3">
                                        <label for="heures_th_ec">Heures TH</label>
                                        <input type="number" class="form-control" id="heures_th_ec" name="heures_th_ec"
                                            value="0" min="0">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="heures_td_ec">Heures TD</label>
                                        <input type="number" class="form-control" id="heures_td_ec" name="heures_td_ec"
                                            value="0" min="0">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="heures_tp_ec">Heures TP</label>
                                        <input type="number" class="form-control" id="heures_tp_ec" name="heures_tp_ec"
                                            value="0" min="0">
                                    </div>
                                    <!-- Champ d'affichage du coefficient calculé -->
                                    <div class="form-group mb-3">
                                        <label for="coefficient">Crédits</label>
                                        <input type="number" class="form-control" id="coefficient" name="coefficient" value="0"
                                            min="0" required>
                                    </div>
                                    <button type="submit" class="btn btn-success">Ajouter l'EC</button>
                                </form>
                            </div>
                        </div>

                        <!-- Liste des EC existants -->
                        <?php
                        $sql_ecs = "
                            SELECT id_ec, code_ec, libelle, coefficient, heures_th, heures_td, heures_tp, total_heures
                            FROM t_element_constitutif
                            WHERE id_ue = ?
                            ORDER BY code_ec ASC
                        ";
                        $stmt_ecs = $pdo->prepare($sql_ecs);
                        $stmt_ecs->execute([$id_ue]);
                        $ecs = $stmt_ecs->fetchAll(PDO::FETCH_ASSOC);

                        if (!empty($ecs)):
                            ?>
                            <h4>Liste des Éléments Constitutifs</h4>
                            <?php
                            // Gestion de la suppression d'un EC
                            if (isset($_POST['delete_ec_id']) && is_numeric($_POST['delete_ec_id'])) {
                                $delete_ec_id = (int) $_POST['delete_ec_id'];
                                try {
                                    $pdo->beginTransaction();

                                    // Supprimer la liaison dans t_mention_ue_ec
                                    $sql_del_link = "DELETE FROM t_mention_ue_ec WHERE id_ec = ?";
                                    $stmt_del_link = $pdo->prepare($sql_del_link);
                                    $stmt_del_link->execute([$delete_ec_id]);

                                    // Supprimer l'EC
                                    $sql_del_ec = "DELETE FROM t_element_constitutif WHERE id_ec = ?";
                                    $stmt_del_ec = $pdo->prepare($sql_del_ec);
                                    $stmt_del_ec->execute([$delete_ec_id]);

                                    $pdo->commit();
                                    echo '<div class="alert alert-success">Élément constitutif supprimé avec succès.</div>';
                                } catch (Exception $e) {
                                    $pdo->rollBack();
                                    echo '<div class="alert alert-danger">Erreur lors de la suppression : ' . $e->getMessage() . '</div>';
                                }
                                // Rafraîchir la liste des EC après suppression
                                $stmt_ecs->execute([$id_ue]);
                                $ecs = $stmt_ecs->fetchAll(PDO::FETCH_ASSOC);
                            }

                            // Gestion de la modification d'un EC
                            $edit_ec = null;
                            if (isset($_GET['edit_ec_id']) && is_numeric($_GET['edit_ec_id'])) {
                                $edit_ec_id = (int) $_GET['edit_ec_id'];
                                foreach ($ecs as $ec) {
                                    if ($ec['id_ec'] == $edit_ec_id) {
                                        $edit_ec = $ec;
                                        break;
                                    }
                                }
                            }

                            if (isset($_POST['edit_ec_id']) && is_numeric($_POST['edit_ec_id'])) {
                                $edit_ec_id = (int) $_POST['edit_ec_id'];
                                $code_ec = htmlspecialchars($_POST['code_ec_edit'] ?? '');
                                $libelle_ec = htmlspecialchars($_POST['libelle_ec_edit'] ?? '');
                                $heures_th_ec = (int) ($_POST['heures_th_ec_edit'] ?? 0);
                                $heures_td_ec = (int) ($_POST['heures_td_ec_edit'] ?? 0);
                                $heures_tp_ec = (int) ($_POST['heures_tp_ec_edit'] ?? 0);
                                $coefficient = (int) ($_POST['coefficient_edit'] ?? 0);

                                if (!empty($code_ec) && !empty($libelle_ec) && $coefficient > 0) {
                                    try {
                                        $pdo->beginTransaction();

                                        // Vérifier crédits UE pour ne pas dépasser le total
                                        $sql_credits_ue = "SELECT credits FROM t_unite_enseignement WHERE id_ue = ?";
                                        $stmt_credits_ue = $pdo->prepare($sql_credits_ue);
                                        $stmt_credits_ue->execute([$id_ue]);
                                        $credits_ue = $stmt_credits_ue->fetchColumn();

                                        $sql_total_coeff = "SELECT SUM(coefficient) FROM t_element_constitutif WHERE id_ue = ? AND id_ec != ?";
                                        $stmt_total_coeff = $pdo->prepare($sql_total_coeff);
                                        $stmt_total_coeff->execute([$id_ue, $edit_ec_id]);
                                        $total_coeff_actuel = $stmt_total_coeff->fetchColumn() ?: 0;

                                        if (($total_coeff_actuel + $coefficient) > $credits_ue) {
                                            throw new Exception('Total des coefficients dépasse les crédits de l\'UE.');
                                        }

                                        // Mettre à jour l'EC
                                        $sql_update_ec = "UPDATE t_element_constitutif SET code_ec = ?, libelle = ?, coefficient = ?, heures_th = ?, heures_td = ?, heures_tp = ? WHERE id_ec = ?";
                                        $stmt_update_ec = $pdo->prepare($sql_update_ec);
                                        $stmt_update_ec->execute([
                                            $code_ec,
                                            $libelle_ec,
                                            $coefficient,
                                            $heures_th_ec,
                                            $heures_td_ec,
                                            $heures_tp_ec,
                                            $edit_ec_id
                                        ]);

                                        $pdo->commit();
                                        echo '<div class="alert alert-success">Élément constitutif modifié avec succès.</div>';
                                    } catch (Exception $e) {
                                        $pdo->rollBack();
                                        echo '<div class="alert alert-danger">Erreur lors de la modification : ' . $e->getMessage() . '</div>';
                                    }
                                    // Rafraîchir la liste des EC après modification
                                    $stmt_ecs->execute([$id_ue]);
                                    $ecs = $stmt_ecs->fetchAll(PDO::FETCH_ASSOC);
                                } else {
                                    echo '<div class="alert alert-warning">Veuillez remplir tous les champs EC.</div>';
                                }
                            }
                            ?>

                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead>
                                        <tr>
                                            <th>Code EC</th>
                                            <th>Libellé</th>
                                            <th>Coefficient</th>
                                            <th>Heures TH</th>
                                            <th>Heures TD</th>
                                            <th>Heures TP</th>
                                            <th>Total Heures</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ecs as $ec): ?>
                                            <?php if ($edit_ec && $edit_ec['id_ec'] == $ec['id_ec']): ?>
                                                <tr>
                                                    <form method="POST" action="">
                                                        <td>
                                                            <input type="text" name="code_ec_edit" class="form-control"
                                                                value="<?php echo htmlspecialchars($ec['code_ec']); ?>" required>
                                                        </td>
                                                        <td>
                                                            <input type="text" name="libelle_ec_edit" class="form-control"
                                                                value="<?php echo htmlspecialchars($ec['libelle']); ?>" required>
                                                        </td>
                                                        <td>
                                                            <input type="number" name="coefficient_edit" class="form-control"
                                                                value="<?php echo htmlspecialchars($ec['coefficient']); ?>" min="0"
                                                                required>
                                                        </td>
                                                        <td>
                                                            <input type="number" name="heures_th_ec_edit" class="form-control"
                                                                value="<?php echo htmlspecialchars($ec['heures_th']); ?>" min="0">
                                                        </td>
                                                        <td>
                                                            <input type="number" name="heures_td_ec_edit" class="form-control"
                                                                value="<?php echo htmlspecialchars($ec['heures_td']); ?>" min="0">
                                                        </td>
                                                        <td>
                                                            <input type="number" name="heures_tp_ec_edit" class="form-control"
                                                                value="<?php echo htmlspecialchars($ec['heures_tp']); ?>" min="0">
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($ec['total_heures']); ?>
                                                        </td>
                                                        <td>
                                                            <input type="hidden" name="edit_ec_id" value="<?php echo $ec['id_ec']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-success" title="Enregistrer">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&semestre=<?php echo $id_semestre; ?>&tab=ue&sub_tab=gerer_ec&id_ue=<?php echo $id_ue; ?>"
                                                                class="btn btn-sm btn-secondary" title="Annuler">
                                                                <i class="fas fa-times"></i>
                                                            </a>
                                                        </td>
                                                    </form>
                                                </tr>
                                            <?php else: ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($ec['code_ec']); ?></td>
                                                    <td><?php echo htmlspecialchars($ec['libelle']); ?></td>
                                                    <td><?php echo htmlspecialchars($ec['coefficient']); ?></td>
                                                    <td><?php echo htmlspecialchars($ec['heures_th']); ?></td>
                                                    <td><?php echo htmlspecialchars($ec['heures_td']); ?></td>
                                                    <td><?php echo htmlspecialchars($ec['heures_tp']); ?></td>
                                                    <td><?php echo htmlspecialchars($ec['total_heures']); ?></td>
                                                    <td>
                                                        <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&semestre=<?php echo $id_semestre; ?>&tab=ue&sub_tab=gerer_ec&id_ue=<?php echo $id_ue; ?>&edit_ec_id=<?php echo $ec['id_ec']; ?>"
                                                            class="btn btn-sm btn-warning" title="Modifier EC">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <form method="POST" action="" style="display:inline;"
                                                            onsubmit="return confirm('Voulez-vous vraiment supprimer cet élément constitutif ?');">
                                                            <input type="hidden" name="delete_ec_id" value="<?php echo $ec['id_ec']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger" title="Supprimer EC">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        <?php else: ?>
                            <div class="alert alert-info text-center mt-3">
                                Aucun élément constitutif n'a encore été ajouté pour cette UE.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger text-center mt-3">
                        Erreur : UE non trouvée ou ID UE manquant.
                    </div>
                <?php endif; ?>

            <?php elseif ($ue_sub_tab === 'programmation'): ?>
                <!-- SOUS-ONGLET: PROGRAMMATION DES UES/ECS -->
                <?php
                // ========================================================
                // RÉCUPÉRER LES UES ET ECS (filtre semestre optionnel)
                // ========================================================
                $sql_prog = "
                    SELECT 
                        ue.id_ue,
                        ue.code_ue,
                        ue.libelle,
                        ue.credits,
                        ue.id_semestre,
                        ue.is_programmed,
                        COUNT(ec.id_ec) as nb_ecs
                    FROM t_unite_enseignement ue
                    INNER JOIN t_mention_ue mu ON mu.id_ue = ue.id_ue
                    LEFT JOIN t_element_constitutif ec ON ue.id_ue = ec.id_ue
                    WHERE ue.code_promotion = ? 
                        AND mu.id_mention = ?
                ";
                $params_prog = [$promotion_code, $mention_id];
                if (!empty($id_semestre)) {
                    $sql_prog .= " AND ue.id_semestre = ?";
                    $params_prog[] = $id_semestre;
                }
                $sql_prog .= " GROUP BY ue.id_ue ORDER BY ue.code_ue ASC";
                $stmt_ues = $pdo->prepare($sql_prog);
                $stmt_ues->execute($params_prog);
                $ues_prog = $stmt_ues->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            📋 Programmation des UEs/ECs
                            <small class="ms-2">Semestre</small>
                        </h4>
                    </div>
                    
                    <div class="card-body">
                        <p class="text-muted">
                            Cochez les unités d'enseignement et éléments constitutifs que vous souhaitez programmer pour cette année académique.
                            Les UEs/ECs programmées seront disponibles pour la saisie des notes et la délibération.
                        </p>
                        <div class="alert alert-info py-2">
                            Le semestre n'est plus obligatoire lors de la création d'une UE. Affectez-le ici pendant la programmation.
                        </div>
                        
                        <div class="mb-3">
                            <button type="button" class="btn btn-success btn-sm" onclick="toggleAllUEs(true)">
                                ✓ Programmer toutes les UEs
                            </button>
                            <button type="button" class="btn btn-warning btn-sm" onclick="toggleAllUEs(false)">
                                ✗ Déprogrammer toutes les UEs
                            </button>
                        </div>

                        <div id="programmation-list">
                            <?php if (!empty($ues_prog)): ?>
                                <?php foreach ($ues_prog as $ue): ?>
                                    <div class="card mb-3 border-start border-4 <?php echo $ue['is_programmed'] ? 'border-success' : 'border-danger'; ?>">
                                        <div class="card-header bg-light">
                                            <div class="row align-items-center">
                                                <div class="col">
                                                    <div class="form-check">
                                                        <input 
                                                            class="form-check-input ue-checkbox" 
                                                            type="checkbox" 
                                                            id="ue_<?php echo $ue['id_ue']; ?>"
                                                            data-ue-id="<?php echo $ue['id_ue']; ?>"
                                                            <?php echo $ue['is_programmed'] ? 'checked' : ''; ?>
                                                            onchange="toggleUE(this)">
                                                        <label class="form-check-label fw-bold" for="ue_<?php echo $ue['id_ue']; ?>">
                                                            <code><?php echo htmlspecialchars($ue['code_ue']); ?></code>
                                                            - <?php echo htmlspecialchars($ue['libelle']); ?>
                                                            <span class="badge bg-info ms-2"><?php echo $ue['credits']; ?> crédits</span>
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-auto">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <label class="mb-0 small text-muted">Semestre</label>
                                                        <select class="form-select form-select-sm"
                                                                style="min-width: 180px;"
                                                                onchange="updateUESemester(<?php echo (int) $ue['id_ue']; ?>, this.value)">
                                                            <option value="">Non défini</option>
                                                            <?php foreach ($semestres as $sem): ?>
                                                                <option value="<?php echo (int) $sem['id_semestre']; ?>"
                                                                    <?php echo ((int) ($ue['id_semestre'] ?? 0) === (int) $sem['id_semestre']) ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($sem['nom_semestre']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-auto">
                                                    <small class="text-muted">
                                                        <?php echo $ue['nb_ecs']; ?> EC<?php echo $ue['nb_ecs'] > 1 ? 's' : ''; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- AFFICHAGE DES ECS SOUS L'UE -->
                                        <?php
                                        $stmt_ecs = $pdo->prepare("
                                            SELECT id_ec, code_ec, libelle, coefficient, is_programmed
                                            FROM t_element_constitutif
                                            WHERE id_ue = ?
                                            ORDER BY code_ec ASC
                                        ");
                                        $stmt_ecs->execute([$ue['id_ue']]);
                                        $ecs_prog = $stmt_ecs->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        if (!empty($ecs_prog)) {
                                            echo '<div class="card-body pt-3">';
                                            echo '<div class="table-responsive">';
                                            echo '<table class="table table-sm table-hover mb-0">';
                                            echo '<thead class="table-light">';
                                            echo '<tr>';
                                            echo '<th width="110px" class="text-center">Programmer EC</th>';
                                            echo '<th>Code</th>';
                                            echo '<th>Libellé</th>';
                                            echo '<th width="120px" class="text-center">Coefficient</th>';
                                            echo '<th width="100px" class="text-center">Statut</th>';
                                            echo '</tr>';
                                            echo '</thead>';
                                            echo '<tbody>';
                                            
                                            foreach ($ecs_prog as $ec) {
                                                $ec_status_class = $ec['is_programmed'] ? 'text-success' : 'text-danger';
                                                $ec_status_text = $ec['is_programmed'] ? '✓ Programmée' : '✗ Déprogrammée';
                                                
                                                echo '<tr>';
                                                echo '<td class="text-center">';
                                                echo '<input 
                                                    class="form-check-input ec-checkbox ec-checkbox-' . $ue['id_ue'] . '" 
                                                    style="transform: scale(1.2); cursor: pointer;"
                                                    title="Programmer / Déprogrammer cet EC"
                                                    type="checkbox" 
                                                    id="ec_' . $ec['id_ec'] . '" 
                                                    data-ec-id="' . $ec['id_ec'] . '"
                                                    ' . ($ec['is_programmed'] ? 'checked' : '') . '
                                                    onchange="toggleEC(this)">';
                                                echo '</td>';
                                                echo '<td><code>' . htmlspecialchars($ec['code_ec']) . '</code></td>';
                                                echo '<td>' . htmlspecialchars($ec['libelle']) . '</td>';
                                                echo '<td class="text-center"><span class="badge bg-secondary">' . $ec['coefficient'] . '</span></td>';
                                                echo '<td class="text-center">';
                                                echo '<small class="' . $ec_status_class . '">' . $ec_status_text . '</small>';
                                                echo '</td>';
                                                echo '</tr>';
                                            }
                                            
                                            echo '</tbody>';
                                            echo '</table>';
                                            echo '</div>';
                                            echo '</div>';
                                        } else {
                                            echo '<div class="card-body">';
                                            echo '<div class="alert alert-warning mb-0">Aucun EC trouvé pour cette UE. Ajoutez des EC dans l\'onglet EC pour pouvoir les programmer ici.</div>';
                                            echo '</div>';
                                        }
                                        ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    Aucune unité d'enseignement n'existe pour ce filtre.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 pt-3 border-top">
                            <small class="text-muted">
                                💡 <strong>Statuts :</strong> Les UEs/ECs programmées (✓) seront visibles pour la saisie des notes.<br>
                                Les UEs/ECs déprogrammées (✗) ne seront pas utilisées durant cette année académique.
                            </small>
                        </div>
                    </div>
                </div>

                <!-- JAVASCRIPT POUR GESTION DES CHECKBOXES -->
                <script>
                function toggleUE(checkbox) {
                    const ueId = checkbox.getAttribute('data-ue-id');
                    const isProgrammed = checkbox.checked ? 1 : 0;
                    
                    fetch('pages/domaine/ajax/handle_programmation.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            'action': 'toggle_ue',
                            'id': ueId,
                            'is_programmed': isProgrammed
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const card = document.querySelector(`input[data-ue-id="${ueId}"]`).closest('.card');
                            if (card) {
                                card.classList.remove('border-success', 'border-danger');
                                card.classList.add(isProgrammed ? 'border-success' : 'border-danger');
                            }
                            updateECsForUE(ueId, isProgrammed);
                        } else {
                            alert('Erreur : ' + (data.error || 'Impossible de mettre à jour l\'UE'));
                            checkbox.checked = !checkbox.checked;
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert('Erreur de connexion');
                        checkbox.checked = !checkbox.checked;
                    });
                }

                function toggleEC(checkbox) {
                    const ecId = checkbox.getAttribute('data-ec-id');
                    const isProgrammed = checkbox.checked ? 1 : 0;
                    
                    fetch('pages/domaine/ajax/handle_programmation.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            'action': 'toggle_ec',
                            'id': ecId,
                            'is_programmed': isProgrammed
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                        } else {
                            alert('Erreur : ' + (data.error || 'Impossible de mettre à jour l\'EC'));
                            checkbox.checked = !checkbox.checked;
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert('Erreur de connexion');
                        checkbox.checked = !checkbox.checked;
                    });
                }

                function updateUESemester(ueId, semestreId) {
                    fetch('pages/domaine/ajax/handle_programmation.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            'action': 'set_ue_semestre',
                            'id': ueId,
                            'semestre_id': semestreId,
                            'mention_id': '<?php echo (int) $mention_id; ?>'
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            alert('Erreur : ' + (data.error || 'Impossible de mettre à jour le semestre de l\'UE'));
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert('Erreur de connexion');
                    });
                }

                function updateECsForUE(ueId, isProgrammed) {
                    const ecCheckboxes = document.querySelectorAll(`.ec-checkbox-${ueId}`);
                    ecCheckboxes.forEach(checkbox => {
                        if (checkbox.checked !== (isProgrammed === 1)) {
                            checkbox.checked = (isProgrammed === 1);
                            // Persister aussi le statut EC en base (pas seulement l'UI)
                            toggleEC(checkbox);
                        }
                    });
                }

                function toggleAllUEs(shouldProgram) {
                    const ueCheckboxes = document.querySelectorAll('.ue-checkbox');
                    ueCheckboxes.forEach(checkbox => {
                        if (checkbox.checked !== shouldProgram) {
                            checkbox.checked = shouldProgram;
                            toggleUE(checkbox);
                        }
                    });
                }
                </script>

            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>