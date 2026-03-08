<?php
    header('Content-Type: text/html; charset=UTF-8');
// tabs/programmation.php
// 
// Ce fichier gère la programmation/déprogrammation des UEs et ECs pour une promotion.
// Permet aux utilisateurs connectés de marquer les UEs/ECs comme programmées ou non.
// Les variables $mention_id, $promotion_code, $id_domaine sont disponibles depuis le fichier parent.

require_once __DIR__ . '/../../includes/domaine_functions.php';
require_once __DIR__ . '/../../includes/db_config.php';

// Récupérer l'année académique
$id_annee = getAnneeFromUrlOrDefault($pdo, $_GET['annee'] ?? null);
$id_semestre = isset($_GET['semestre']) ? (int) $_GET['semestre'] : null;

// Récupérer les paramètres depuis l'URL
$id_domaine = isset($_GET['id']) ? (int) $_GET['id'] : null;
$mention_id = isset($_GET['mention']) ? (int) $_GET['mention'] : null;
$promotion_code = isset($_GET['promotion']) ? htmlspecialchars($_GET['promotion']) : '';

// Récupérer le username de l'utilisateur connecté
$username = isset($_SESSION['user_id']) ? trim($_SESSION['user_id']) : 'system';

// ========================================================
// TRAITEMENT DES REQUÊTES AJAX POUR MISE À JOUR
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'toggle_ue' || $_POST['action'] === 'toggle_ec')) {
    header('Content-Type: application/json; charset=UTF-8');
    
    $action = htmlspecialchars($_POST['action']);
    $id_element = (int) ($_POST['id'] ?? 0);
    $is_programmed = (int) ($_POST['is_programmed'] ?? 0);
    
    if ($id_element <= 0) {
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
                echo json_encode(['success' => false, 'error' => 'UE non trouvée']);
                $pdo->rollBack();
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
                echo json_encode(['success' => false, 'error' => 'EC non trouvé']);
                $pdo->rollBack();
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
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Mise à jour réussie']);
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ========================================================
// VÉRIFIER SI ANNÉE ACADÉMIQUE ET SEMESTRES EXISTENT
// ========================================================
if (!$id_annee) {
    echo '<div class="alert alert-danger">
        <strong>Erreur :</strong> Aucune année académique configurée. Veuillez contacter l\'administrateur.
    </div>';
    return;
}

// Récupérer les semestres de l'année
$stmt_semestres = $pdo->prepare("SELECT id_semestre, nom_semestre FROM t_semestre WHERE id_annee = ? ORDER BY nom_semestre ASC");
$stmt_semestres->execute([$id_annee]);
$semestres = $stmt_semestres->fetchAll(PDO::FETCH_ASSOC);

if (empty($semestres)) {
    echo '<div class="alert alert-info">
        Aucun semestre n\'a été trouvé pour l\'année académique en cours.
    </div>';
    return;
}

// ========================================================
// INTERFACE DE SÉLECTION DU SEMESTRE
// ========================================================
if (!$id_semestre) {
    echo '<div class="alert alert-info">
        <strong>Sélectionnez un semestre</strong> pour programmer les UEs et ECs.
    </div>';
    echo '<form method="GET" class="row g-3 mb-4">';
    echo '  <input type="hidden" name="page" value="domaine">';
    echo '  <input type="hidden" name="action" value="view">';
    echo '  <input type="hidden" name="id" value="' . $id_domaine . '">';
    echo '  <input type="hidden" name="mention" value="' . $mention_id . '">';
    echo '  <input type="hidden" name="promotion" value="' . $promotion_code . '">';
    echo '  <input type="hidden" name="tab" value="programmation">';
    echo '  <input type="hidden" name="annee" value="' . $id_annee . '">';
    echo '  <div class="col-auto">';
    echo '    <label class="form-label">Semestre :</label>';
    echo '    <select class="form-select" name="semestre" onchange="this.form.submit()">';
    echo '      <option value="">-- Choisir un semestre --</option>';
    foreach ($semestres as $sem) {
        echo '<option value="' . $sem['id_semestre'] . '">' . htmlspecialchars($sem['nom_semestre']) . '</option>';
    }
    echo '    </select>';
    echo '  </div>';
    echo '</form>';
    return;
}

// ========================================================
// RÉCUPÉRER LES UES ET ECS POUR LE SEMESTRE
// ========================================================
$stmt_ues = $pdo->prepare("
    SELECT 
        ue.id_ue,
        ue.code_ue,
        ue.libelle,
        ue.credits,
        ue.is_programmed,
        COUNT(ec.id_ec) as nb_ecs
    FROM t_unite_enseignement ue
    LEFT JOIN t_element_constitutif ec ON ue.id_ue = ec.id_ue
    WHERE ue.code_promotion = ? 
        AND ue.id_semestre = ?
    GROUP BY ue.id_ue
    ORDER BY ue.code_ue ASC
");
$stmt_ues->execute([$promotion_code, $id_semestre]);
$ues = $stmt_ues->fetchAll(PDO::FETCH_ASSOC);

if (empty($ues)) {
    echo '<div class="alert alert-info">
        Aucune unité d\'enseignement n\'existe pour cette promotion et ce semestre.
    </div>';
    return;
}

// ========================================================
// AFFICHAGE DE L'INTERFACE DE PROGRAMMATION
// ========================================================
?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-primary text-white">
        <h4 class="mb-0">
            📋 Programmation des UEs/ECs
            <small class="ms-2">Promotion: <strong><?php echo htmlspecialchars($promotion_code); ?></strong></small>
        </h4>
    </div>
    
    <div class="card-body">
        <p class="text-muted">
            Cochez les unités d'enseignement et éléments constitutifs que vous souhaitez programmer pour cette année académique.
            Les UEs/ECs programmées seront disponibles pour la saisie des notes et la délibération.
        </p>
        
        <div class="mb-3">
            <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&promotion=<?php echo $promotion_code; ?>&tab=programmation&annee=<?php echo $id_annee; ?>" 
               class="btn btn-outline-secondary btn-sm">
                ← Retour
            </a>
            <button type="button" class="btn btn-success btn-sm" onclick="toggleAllUEs(true)">
                ✓ Programmer toutes les UEs
            </button>
            <button type="button" class="btn btn-warning btn-sm" onclick="toggleAllUEs(false)">
                ✗ Déprogrammer toutes les UEs
            </button>
        </div>

        <div id="programmation-list">
            <?php foreach ($ues as $ue): ?>
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
                    $ecs = $stmt_ecs->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($ecs)) {
                        echo '<div class="card-body pt-3">';
                        echo '<div class="table-responsive">';
                        echo '<table class="table table-sm table-hover mb-0">';
                        echo '<thead class="table-light">';
                        echo '<tr>';
                        echo '<th width="40px"></th>';
                        echo '<th>Code</th>';
                        echo '<th>Libellé</th>';
                        echo '<th width="120px" class="text-center">Coefficient</th>';
                        echo '<th width="100px" class="text-center">Statut</th>';
                        echo '</tr>';
                        echo '</thead>';
                        echo '<tbody>';
                        
                        foreach ($ecs as $ec) {
                            $ec_status_class = $ec['is_programmed'] ? 'text-success' : 'text-danger';
                            $ec_status_text = $ec['is_programmed'] ? '✓ Programmée' : '✗ Déprogrammée';
                            
                            echo '<tr>';
                            echo '<td>';
                            echo '<input 
                                class="form-check-input ec-checkbox ec-checkbox-' . $ue['id_ue'] . '" 
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
                    }
                    ?>
                </div>
            <?php endforeach; ?>
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
    
    // Envoyer la mise à jour via AJAX
    fetch('', {
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
            // Mettre à jour le style de la carte
            const card = document.querySelector(`input[data-ue-id="${ueId}"]`).closest('.card');
            if (card) {
                card.classList.remove('border-success', 'border-danger');
                card.classList.add(isProgrammed ? 'border-success' : 'border-danger');
            }
            
            // Mettre à jour aussi les ECs enfants
            updateECsForUE(ueId, isProgrammed);
            
            // Afficher le toast
            showToast('UE mise à jour avec succès', 'success');
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
    
    // Envoyer la mise à jour via AJAX
    fetch('', {
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
            showToast('EC mise à jour avec succès', 'success');
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

function updateECsForUE(ueId, isProgrammed) {
    const ecCheckboxes = document.querySelectorAll(`.ec-checkbox-${ueId}`);
    ecCheckboxes.forEach(checkbox => {
        if (checkbox.checked !== (isProgrammed === 1)) {
            checkbox.checked = (isProgrammed === 1);
            // On pourrait faire une mise à jour AJAX pour chaque EC aussi,
            // mais pour simplifier, on supposera qu'une UE déprogrammée
            // signifie que tous ses ECs le sont aussi (logique métier)
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

function showToast(message, type = 'info') {
    // Simple toast notification - à adapter selon votre framework UI
    console.log(`[${type.toUpperCase()}] ${message}`);
}
</script>

<?php
// FIN DU FICHIER
?>
