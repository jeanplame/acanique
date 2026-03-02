<?php
require_once 'includes/domaine_functions.php';
require_once 'includes/auth.php';
require_once 'includes/permission_helpers.php';
require_once 'includes/domain_filter.php';
    header('Content-Type: text/html; charset=UTF-8');

// Note: Les permissions pour le dashboard sont gérées dans page_permissions.php et index.php

// Récupérer l'année académique en cours
$currentYear = getCurrentAcademicYear($pdo);
$allYears = getAllAcademicYears($pdo);
$allDomaines = getAllDomaines($pdo);

// Filtrer les domaines selon l'utilisateur connecté
$currentUsername = $_SESSION['user_id'] ?? 'guest';
$domaines = filterDomainesForUser($allDomaines, $currentUsername);

$_SESSION['id_annee_academique'] = $currentYear ? $currentYear['id_annee'] : null;
$annee_encours = $_SESSION['id_annee_academique'];

// ==========================
// Récupération de l'année académique en cours
// ==========================
// On récupère l'ID de l'année en cours (stockée dans t_configuration)
$sql = "
    SELECT a.id_annee, a.date_debut, a.date_fin, a.statut
    FROM t_configuration c
    JOIN t_anne_academique a ON a.id_annee = c.valeur
    WHERE c.cle = 'annee_encours'
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$annee = $stmt->fetch(PDO::FETCH_ASSOC);

$libelle = '';
$date_debut = null;
$date_fin = null;
$statut = null;
$id_annee = null;

if ($annee) {
    $id_annee = $annee['id_annee'];
    $date_debut = $annee['date_debut'];
    $date_fin = $annee['date_fin'];
    $statut = $annee['statut'];

    // Construction du libellé (ex: "2024-2025")
    $libelle = date('Y', strtotime($date_debut)) . '-' . date('Y', strtotime($date_fin));

} else {
    echo "<p class='text-danger'><strong>Aucune année académique n'est encore configurée.</strong></p>";
    $id_annee = null; // Éviter les erreurs si pas d'année configurée
}

// Traitement du formulaire d'ajout d'année académique
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_year') {
        $dateDebut = $_POST['date_debut'];
        $dateFin = $_POST['date_fin'];

        if (createAcademicYear($pdo, $dateDebut, $dateFin)) {
            header("Location: ?page=dashboard");
            exit();
        } else {
            $error = "Erreur lors de la création de l'année académique";
        }
    } elseif ($_POST['action'] === 'add_domaine') {
        // Le code du domaine est maintenant généré automatiquement
        $codeDomaine = $_POST['code_domaine'];
        $nomDomaine = $_POST['nom_domaine'];
        $description = $_POST['description'] ?? '';

        if (createDomaine($pdo, $codeDomaine, $nomDomaine, $description)) {
            header("Location: ?page=dashboard");
            exit();
        } else {
            $error = "Erreur lors de la création du domaine";
        }
    } elseif ($_POST['action'] === 'delete_domaine' && isset($_POST['id_domaine'])) {
        $idDomaine = $_POST['id_domaine'];
        // requête de suppression du domaine 
        $sql = "DELETE FROM t_domaine WHERE id_domaine = ?";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$idDomaine])) {
            header("Location: ?page=dashboard");
            exit();
        } else {
            $error = "Erreur lors de la suppression du domaine";
        }
    } elseif ($_POST['action'] === 'edit_domaine' && isset($_POST['id_domaine'])) {
        $idDomaine = $_POST['id_domaine'];
        $codeDomaine = $_POST['code_domaine'];
        $nomDomaine = $_POST['nom_domaine'];
        $description = $_POST['description'] ?? '';
        // Requête de mise à jour du domaine
        $sql = "UPDATE t_domaine SET code_domaine = ?, nom_domaine = ?, description = ? WHERE id_domaine = ?";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$codeDomaine, $nomDomaine, $description, $idDomaine])) {
            header("Location: ?page=dashboard");
            exit();
        } else {
            $error = "Erreur lors de la mise à jour du domaine";
        }
    } 
}
// === Traitement add_semestres (POST) ===
if (isset($_POST['btnCreerSemestres']) && $id_annee) {
    // Vérifier qu'aucun semestre n'existe déjà pour l'année en cours
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM t_semestre WHERE id_annee = ?");
    $stmt_check->execute([$id_annee]);

    if ((int)$stmt_check->fetchColumn() === 0) {
        try {
            // Démarrer une transaction pour assurer l'atomicité
            $pdo->beginTransaction();

            // Calcul de la moitié de l'année académique
            $diff = (strtotime($date_fin) - strtotime($date_debut)) / 2;
            $date_moitie = date('Y-m-d', strtotime($date_debut) + $diff);

            // Création du premier semestre
            $stmt1 = $pdo->prepare("
                INSERT INTO t_semestre 
                    (code_semestre, nom_semestre, id_annee, date_debut, date_fin, statut) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt1->execute(['S1', 'Semestre 1', $id_annee, $date_debut, $date_moitie, 'Actif']);

            // Création du deuxième semestre
            $stmt2 = $pdo->prepare("
                INSERT INTO t_semestre 
                    (code_semestre, nom_semestre, id_annee, date_debut, date_fin, statut) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt2->execute([
                'S2',
                'Semestre 2',
                $id_annee,
                date('Y-m-d', strtotime('+1 day', strtotime($date_moitie))),
                $date_fin,
                'Actif'
            ]);

            // Validation de la transaction
            $pdo->commit();

            header("Location: ?page=dashboard&msg=semestres_ok");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Erreur lors de la création des semestres : " . $e->getMessage();
        }
    } else {
        header("Location: ?page=dashboard");
        exit();
    }
}

?>

<style>
/* Amélioration du design des formulaires */
.modern-form {
    background: linear-gradient(145deg, #f8f9fa, #ffffff);
    border: 1px solid #e3e6f0;
    border-radius: 15px;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    transition: all 0.3s ease;
}

.modern-form:hover {
    box-shadow: 0 0.25rem 2rem 0 rgba(58, 59, 69, 0.2);
    transform: translateY(-2px);
}

.form-label {
    font-weight: 600;
    color: #5a5c69;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
}

.form-label::before {
    content: "";
    width: 4px;
    height: 4px;
    background: linear-gradient(45deg, #4e73df, #224abe);
    border-radius: 50%;
    margin-right: 8px;
}

.form-control, .form-select {
    border: 2px solid #e3e6f0;
    border-radius: 10px;
    padding: 12px 15px;
    font-size: 14px;
    transition: all 0.3s ease;
    background-color: #fff;
}

.form-control:focus, .form-select:focus {
    border-color: #4e73df;
    box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
    background-color: #fff;
}

.form-control[readonly] {
    background-color: #f8f9fa;
    border-color: #dee2e6;
}

.btn-primary {
    background: linear-gradient(45deg, #4e73df, #224abe);
    border: none;
    border-radius: 10px;
    padding: 12px 30px;
    font-weight: 600;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    background: linear-gradient(45deg, #224abe, #1cc88a);
    transform: translateY(-1px);
    box-shadow: 0 0.25rem 2rem 0 rgba(78, 115, 223, 0.4);
}

.btn-secondary {
    background: linear-gradient(45deg, #858796, #6c757d);
    border: none;
    border-radius: 10px;
    padding: 12px 25px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-secondary:hover {
    background: linear-gradient(45deg, #6c757d, #5a6268);
    transform: translateY(-1px);
}

.modal-content {
    border: none;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.175);
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-bottom: none;
    padding: 20px 30px;
}

.modal-title {
    font-weight: 700;
    font-size: 18px;
}

.btn-close {
    filter: invert(1);
}

.modal-body {
    padding: 30px;
    background-color: #f8f9fa;
}

.modal-footer {
    background-color: #fff;
    border-top: 1px solid #e3e6f0;
    padding: 20px 30px;
}

.card {
    border: none;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    transition: all 0.3s ease;
}

.card:hover {
    box-shadow: 0 0.25rem 2rem 0 rgba(58, 59, 69, 0.2);
}

.card-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-bottom: none;
    padding: 20px 25px;
}

.card-title {
    margin: 0;
    font-weight: 700;
    font-size: 16px;
}

.invalid-feedback {
    color: #e74a3b;
    font-size: 12px;
    font-weight: 500;
}

.form-control.is-invalid {
    border-color: #e74a3b;
}

.form-control.is-valid {
    border-color: #1cc88a;
}

/* Animation pour les champs de formulaire */
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-2px); }
    20%, 40%, 60%, 80% { transform: translateX(2px); }
}

.form-control.is-invalid {
    animation: shake 0.5s ease-in-out;
}

/* Indicateur de champ requis */
.required-field::after {
    content: " *";
    color: #e74a3b;
    font-weight: bold;
}

/* Icônes pour les types de champs */
.input-group-text {
    background: linear-gradient(45deg, #f8f9fa, #e9ecef);
    border: 2px solid #e3e6f0;
    border-right: none;
    border-radius: 10px 0 0 10px;
    transition: all 0.3s ease;
}

.input-group .form-control {
    border-left: none;
    border-radius: 0 10px 10px 0;
}

/* Effet focus sur les groupes d'inputs */
.input-group.focused .input-group-text {
    border-color: #4e73df;
    background: linear-gradient(45deg, #4e73df, #224abe);
    color: white;
}

/* Animations pour les alertes */
.alert {
    border-radius: 10px;
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.alert-info {
    background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
    color: #0c5460;
}

.alert-warning {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    color: #856404;
}

.alert-danger {
    background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
    color: #721c24;
}

/* Amélioration des tooltips */
.form-text {
    font-size: 12px;
    color: #6c757d;
    margin-top: 5px;
}

/* Loading state pour les boutons */
.btn.loading {
    position: relative;
    pointer-events: none;
}

.btn.loading::after {
    content: "";
    position: absolute;
    width: 16px;
    height: 16px;
    top: 50%;
    left: 50%;
    margin-left: -8px;
    margin-top: -8px;
    border: 2px solid transparent;
    border-top-color: currentColor;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Responsive improvements */
@media (max-width: 768px) {
    .modal-dialog {
        margin: 10px;
    }
    
    .card-body {
        padding: 15px;
    }
    
    .modal-body {
        padding: 20px 15px;
    }
}

/* Hover effect sur les cards de domaine */
.domain-card {
    transition: all 0.3s ease;
}

.domain-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 3rem rgba(0, 0, 0, 0.2);
}
</style>

<div class="container-fluid">
    <?php 
    // Afficher les messages d'erreur de permission
    displayPermissionError();
    ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($allYears)): ?>
        <!-- Formulaire d'ajout d'année académique -->
        <div class="card modern-form">
            <div class="card-header">
                <h4 class="card-title">
                    <i class="bi bi-calendar-plus me-2"></i>
                    Configurez une année académique
                </h4>
            </div>
            <div class="card-body">
                <form method="POST" class="needs-validation" novalidate>
                    <input type="hidden" name="action" value="add_year">

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="date_debut" class="form-label required-field">
                                    <i class="bi bi-calendar-event text-success me-1"></i>
                                    Date de début
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-calendar3"></i>
                                    </span>
                                    <input type="date" class="form-control" id="date_debut" name="date_debut" required>
                                </div>
                                <div class="invalid-feedback">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    Veuillez sélectionner une date de début
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="date_fin" class="form-label required-field">
                                    <i class="bi bi-calendar-x text-danger me-1"></i>
                                    Date de fin
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-calendar3"></i>
                                    </span>
                                    <input type="date" class="form-control" id="date_fin" name="date_fin" required>
                                </div>
                                <div class="invalid-feedback">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    Veuillez sélectionner une date de fin
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-2"></i>
                            Créer l'année académique
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <?php
        // Vérifier si des semestres existent pour l'année académique en cours
        $semestre_count = 0; // Valeur par défaut
        if ($id_annee) {
            $sql_semestre_count = "SELECT COUNT(*) FROM t_semestre WHERE id_annee = ?";
            $stmt_semestre_count = $pdo->prepare($sql_semestre_count);
            $stmt_semestre_count->execute([$id_annee]);
            $semestre_count = $stmt_semestre_count->fetchColumn();
        }

        if ($semestre_count == 0): ?>
            <!-- Formulaire pour ajouter les semestres si aucun n'existe -->
            <div class="card text-center">
                <div class="card-header">
                    <h4 class="card-title text-danger">Configuration requise : Semestres</h4>
                </div>
                <div class="card-body">
                    <p class="card-text">
                        Aucun semestre n'est configuré pour l'année académique en cours
                        (<?= htmlspecialchars($libelle) ?>).
                        Veuillez créer les deux semestres standards pour continuer.
                    </p>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_semestres">
                        <button type="submit" class="btn btn-primary" name="btnCreerSemestres">Créer les semestres S1 et S2</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Affichage des domaines et option d'ajout -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title">Domaines d'études</h4>
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal"
                                data-bs-target="#addDomaineModal">
                                <i class="bi bi-plus-circle"></i> Ajouter un domaine
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($domaines)): ?>
                                <div class="alert alert-info" role="alert">
                                    Aucun domaine n'est encore créé. Commencez par en ajouter un !
                                </div>
                            <?php else: ?>
                                <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-6 g-3">
                                    <?php foreach ($domaines as $domaine): ?>
                                        <?php
                                        // Requête pour compter le nombre de filières pour le domaine en cours
                                        $sqlFiliereCount = "SELECT COUNT(*) FROM t_filiere WHERE id_domaine = :id_domaine";
                                        $stmtFiliereCount = $pdo->prepare($sqlFiliereCount);
                                        $stmtFiliereCount->bindParam(':id_domaine', $domaine['id_domaine'], PDO::PARAM_INT);
                                        $stmtFiliereCount->execute();
                                        $filiereCount = $stmtFiliereCount->fetchColumn();

                                        // Requête pour compter le nombre d'étudiants inscrits pour le domaine en cours
                                        $etudiantCount = 0; // Valeur par défaut
                                        if ($id_annee) {
                                            $sqlEtudiantCount = "
                                                SELECT COUNT(DISTINCT i.matricule)
                                                FROM t_inscription i
                                                INNER JOIN t_mention m ON i.id_mention = m.id_mention
                                                INNER JOIN t_filiere f ON m.idFiliere = f.idFiliere
                                                WHERE f.id_domaine = :id_domaine
                                                AND i.id_annee = :annee_academique
                                                AND i.statut = 'Actif'
                                            ";
                                            $stmtEtudiantCount = $pdo->prepare($sqlEtudiantCount);
                                            $stmtEtudiantCount->bindParam(':id_domaine', $domaine['id_domaine'], PDO::PARAM_INT);
                                            $stmtEtudiantCount->bindParam(':annee_academique', $id_annee, PDO::PARAM_INT);
                                            $stmtEtudiantCount->execute();
                                            $etudiantCount = $stmtEtudiantCount->fetchColumn();
                                        }
                                        ?>
                                        <div class="col">
                                            <a href="?page=domaine&action=view&id=<?php echo $domaine['id_domaine']; ?>&annee=<?php echo $annee_encours; ?>"
                                                class="text-decoration-none">
                                                <div class="card domain-card">
                                                    <div class="card-body">
                                                        <div class="d-flex justify-content-between align-items-start">
                                                            <h5 class="card-title text-dark">
                                                                <?php echo htmlspecialchars($domaine['nom_domaine']); ?>
                                                            </h5>
                                                            <span class="badge bg-primary">
                                                                <?php echo htmlspecialchars($domaine['code_domaine']); ?>
                                                            </span>
                                                        </div>
                                                        <p class="card-text text-muted small">
                                                            <?php echo htmlspecialchars($domaine['description'] ?? 'Aucune description disponible'); ?>
                                                        </p>
                                                        <div class="domain-stats mt-3">
                                                            <div class="row g-2">
                                                                <div class="col-6">
                                                                    <div class="p-2 border rounded text-center">
                                                                        <div class="small text-muted">Filières</div>
                                                                        <div class="h5 mb-0">
                                                                            <?php echo htmlspecialchars($filiereCount); ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-6">
                                                                    <div class="p-2 border rounded text-center">
                                                                        <div class="small text-muted">Étudiants</div>
                                                                        <div class="h5 mb-0">
                                                                            <?php echo htmlspecialchars($etudiantCount); ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="card-footer bg-transparent">
                                                        <div class="d-flex justify-content-end">
                                                            <div class="btn-group">
                                                                <form action="" method="POST">
                                                                    <input type="hidden" name="id_domaine"
                                                                        value="<?php echo $domaine['id_domaine']; ?>">
                                                                    <button type="submit" class="btn btn-outline-primary btn-sm"
                                                                        style="margin-right: 5px;" data-bs-toggle="modal"
                                                                        data-bs-target="#editDomaineModal<?php echo $domaine['id_domaine']; ?>">
                                                                        <i class="bi bi-pencil"></i>
                                                                    </button>
                                                                </form>
                                                                <!-- Modal de modification du domaine -->
                                                                <div class="modal fade"
                                                                    id="editDomaineModal<?php echo $domaine['id_domaine']; ?>"
                                                                    tabindex="-1"
                                                                    aria-labelledby="editDomaineModalLabel<?php echo $domaine['id_domaine']; ?>"
                                                                    aria-hidden="true">
                                                                    <div class="modal-dialog modal-lg">
                                                                        <div class="modal-content">
                                                                            <div class="modal-header">
                                                                                <h5 class="modal-title"
                                                                                    id="editDomaineModalLabel<?php echo $domaine['id_domaine']; ?>">
                                                                                    <i class="bi bi-pencil-square me-2"></i>
                                                                                    Modifier le domaine : <?php echo htmlspecialchars($domaine['nom_domaine']); ?>
                                                                                </h5>
                                                                                <button type="button" class="btn-close"
                                                                                    data-bs-dismiss="modal" aria-label="Close"></button>
                                                                            </div>
                                                                            <form method="POST" class="needs-validation" novalidate>
                                                                                <div class="modal-body">
                                                                                    <input type="hidden" name="action"
                                                                                        value="edit_domaine">
                                                                                    <input type="hidden" name="id_domaine"
                                                                                        value="<?php echo $domaine['id_domaine']; ?>">

                                                                                    <div class="alert alert-warning d-flex align-items-center" role="alert">
                                                                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                                                                        <div>
                                                                                            <strong>Attention :</strong> La modification d'un domaine peut affecter les mentions et filières associées.
                                                                                        </div>
                                                                                    </div>

                                                                                    <div class="row">
                                                                                        <div class="col-md-4">
                                                                                            <div class="mb-3">
                                                                                                <label for="code_domaine_<?php echo $domaine['id_domaine']; ?>"
                                                                                                    class="form-label">
                                                                                                    <i class="bi bi-tag text-info me-1"></i>
                                                                                                    Code du domaine
                                                                                                </label>
                                                                                                <div class="input-group">
                                                                                                    <span class="input-group-text">
                                                                                                        <i class="bi bi-hash"></i>
                                                                                                    </span>
                                                                                                    <input type="text" class="form-control bg-light"
                                                                                                        id="code_domaine_<?php echo $domaine['id_domaine']; ?>" name="code_domaine"
                                                                                                        value="<?php echo htmlspecialchars($domaine['code_domaine']); ?>"
                                                                                                        readonly>
                                                                                                </div>
                                                                                                <div class="form-text">
                                                                                                    <i class="bi bi-lock text-muted me-1"></i>
                                                                                                    Code non modifiable
                                                                                                </div>
                                                                                            </div>
                                                                                        </div>

                                                                                        <div class="col-md-8">
                                                                                            <div class="mb-3">
                                                                                                <label for="nom_domaine_<?php echo $domaine['id_domaine']; ?>" class="form-label required-field">
                                                                                                    <i class="bi bi-mortarboard text-primary me-1"></i>
                                                                                                    Nom du domaine
                                                                                                </label>
                                                                                                <div class="input-group">
                                                                                                    <span class="input-group-text">
                                                                                                        <i class="bi bi-bookmark"></i>
                                                                                                    </span>
                                                                                                    <input type="text" class="form-control"
                                                                                                        id="nom_domaine_<?php echo $domaine['id_domaine']; ?>" name="nom_domaine"
                                                                                                        value="<?php echo htmlspecialchars($domaine['nom_domaine']); ?>"
                                                                                                        required>
                                                                                                </div>
                                                                                                <div class="invalid-feedback">
                                                                                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                                                                                    Le nom du domaine est requis
                                                                                                </div>
                                                                                            </div>
                                                                                        </div>
                                                                                    </div>

                                                                                    <div class="mb-3">
                                                                                        <label for="description_<?php echo $domaine['id_domaine']; ?>"
                                                                                            class="form-label">
                                                                                            <i class="bi bi-journal-text text-success me-1"></i>
                                                                                            Description
                                                                                        </label>
                                                                                        <div class="input-group">
                                                                                            <span class="input-group-text">
                                                                                                <i class="bi bi-card-text"></i>
                                                                                            </span>
                                                                                            <textarea class="form-control" id="description_<?php echo $domaine['id_domaine']; ?>"
                                                                                                name="description"
                                                                                                rows="4" placeholder="Description détaillée du domaine"><?php echo htmlspecialchars($domaine['description'] ?? ''); ?></textarea>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                                <div class="modal-footer">
                                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                                                        <i class="bi bi-x-circle me-2"></i>
                                                                                        Annuler
                                                                                    </button>
                                                                                    <button type="submit" class="btn btn-primary">
                                                                                        <i class="bi bi-check-circle me-2"></i>
                                                                                        Enregistrer les modifications
                                                                                    </button>
                                                                                </div>
                                                                            </form>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                <!-- Bouton de suppression du domaine -->
                                                                <form action="" method="post">
                                                                    <input type="hidden" name="action" value="delete_domaine">
                                                                    <input type="hidden" name="id_domaine"
                                                                        value="<?php echo $domaine['id_domaine']; ?>">
                                                                    <button type="submit" class="btn btn-outline-danger btn-sm"
                                                                        onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce domaine ?');">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Modal pour ajouter un domaine -->
<div class="modal fade" id="addDomaineModal" tabindex="-1" aria-labelledby="addDomaineModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addDomaineModalLabel">
                    <i class="bi bi-plus-circle me-2"></i>
                    Ajouter un nouveau domaine
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" class="needs-validation" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_domaine">
                    
                    <div class="alert alert-info d-flex align-items-center" role="alert">
                        <i class="bi bi-info-circle me-2"></i>
                        <div>
                            <strong>Astuce :</strong> Le code du domaine sera généré automatiquement à partir du nom saisi.
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="add_code_domaine" class="form-label">
                                    <i class="bi bi-tag text-info me-1"></i>
                                    Code du domaine
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-hash"></i>
                                    </span>
                                    <input type="text" class="form-control bg-light" id="add_code_domaine" name="code_domaine" readonly placeholder="Auto-généré">
                                </div>
                                <div class="form-text">
                                    <i class="bi bi-lightbulb text-warning me-1"></i>
                                    Généré automatiquement
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="add_nom_domaine" class="form-label required-field">
                                    <i class="bi bi-mortarboard text-primary me-1"></i>
                                    Nom du domaine
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="bi bi-bookmark"></i>
                                    </span>
                                    <input type="text" class="form-control" id="add_nom_domaine" name="nom_domaine" 
                                           placeholder="Ex: Sciences Informatiques" required>
                                </div>
                                <div class="invalid-feedback">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    Veuillez saisir le nom du domaine
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="add_description" class="form-label">
                            <i class="bi bi-journal-text text-success me-1"></i>
                            Description
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-card-text"></i>
                            </span>
                            <textarea class="form-control" id="add_description" name="description" rows="4" 
                                      placeholder="Description détaillée du domaine d'étude (optionnel)"></textarea>
                        </div>
                        <div class="form-text">
                            <i class="bi bi-info-circle text-muted me-1"></i>
                            Une description claire aide les étudiants à mieux comprendre le domaine
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>
                        Annuler
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>
                        Créer le domaine
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Fonction pour générer un code de domaine unique basé sur le nom du domaine
    function generateDomaineCodeFromName(name) {
        // Si le nom est vide, on retourne une chaîne vide
        if (!name) {
            return '';
        }

        // On divise le nom en mots
        const words = name.split(' ');
        let code = '';

        // On prend la première lettre de chaque mot, en majuscule
        for (let i = 0; i < words.length; i++) {
            if (words[i].length > 0) {
                code += words[i].charAt(0).toUpperCase();
            }
        }

        // Si le code est trop court, on complète avec des lettres supplémentaires
        if (code.length < 5) {
            const letters = name.replace(/\s/g, '').toUpperCase();
            let i = 0;
            while (code.length < 5 && i < letters.length) {
                if (!code.includes(letters[i])) {
                    code += letters[i];
                }
                i++;
            }
        }

        // On s'assure que le code n'excède pas 7 caractères
        return code.slice(0, 7);
    }

    // Fonction de confirmation de suppression
    function confirmDelete(domainId) {
        // Remplacé 'confirm' par une alerte simple pour éviter les problèmes d'affichage dans certains contextes
        alert('Êtes-vous sûr de vouloir supprimer ce domaine ?');
        window.location.href = '?page=domaine&action=delete&id=' + domainId;
    }

    // Validation des formulaires Bootstrap
    (function () {
        'use strict'
        var forms = document.querySelectorAll('.needs-validation')
        Array.prototype.slice.call(forms).forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!form.checkValidity()) {
                    event.preventDefault()
                    event.stopPropagation()
                }
                form.classList.add('was-validated')
            }, false)
        })
    })()

    // Remplissage automatique du code du domaine lors de la saisie du nom du domaine
    document.addEventListener('DOMContentLoaded', function () {
        var nomDomaineInput = document.getElementById('add_nom_domaine');
        var codeDomaineInput = document.getElementById('add_code_domaine');

        if (nomDomaineInput && codeDomaineInput) {
            nomDomaineInput.addEventListener('input', function () {
                const generatedCode = generateDomaineCodeFromName(this.value);
                codeDomaineInput.value = generatedCode;
                
                // Animation visuelle pour montrer que le code a été généré
                if (generatedCode) {
                    codeDomaineInput.classList.add('is-valid');
                    codeDomaineInput.classList.remove('is-invalid');
                } else {
                    codeDomaineInput.classList.remove('is-valid', 'is-invalid');
                }
            });

            // Effet de focus amélioré
            nomDomaineInput.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });
            
            nomDomaineInput.addEventListener('blur', function() {
                this.parentElement.classList.remove('focused');
            });
        }

        // Amélioration de l'expérience utilisateur pour les dates
        const dateInputs = document.querySelectorAll('input[type="date"]');
        dateInputs.forEach(function(input) {
            // Validation en temps réel des dates
            input.addEventListener('change', function() {
                validateDateInputs();
            });
        });

        // Animation d'ouverture des modals
        const modals = document.querySelectorAll('.modal');
        modals.forEach(function(modal) {
            modal.addEventListener('show.bs.modal', function() {
                this.querySelector('.modal-dialog').style.transform = 'scale(0.8)';
                this.querySelector('.modal-dialog').style.opacity = '0';
                
                setTimeout(() => {
                    this.querySelector('.modal-dialog').style.transform = 'scale(1)';
                    this.querySelector('.modal-dialog').style.opacity = '1';
                    this.querySelector('.modal-dialog').style.transition = 'all 0.3s ease';
                }, 10);
            });
        });

        // Confirmation améliorée pour la suppression
        const deleteButtons = document.querySelectorAll('button[onclick*="confirm"]');
        deleteButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Créer une confirmation personnalisée
                const confirmationHtml = `
                    <div class="modal fade" id="deleteConfirmModal" tabindex="-1">
                        <div class="modal-dialog modal-sm">
                            <div class="modal-content">
                                <div class="modal-header bg-danger text-white">
                                    <h6 class="modal-title">
                                        <i class="bi bi-exclamation-triangle me-2"></i>
                                        Confirmation de suppression
                                    </h6>
                                </div>
                                <div class="modal-body text-center">
                                    <i class="bi bi-trash3 text-danger" style="font-size: 3rem;"></i>
                                    <p class="mt-3 mb-0">Êtes-vous sûr de vouloir supprimer ce domaine ?</p>
                                    <small class="text-muted">Cette action est irréversible.</small>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                                        <i class="bi bi-x-circle me-1"></i>Annuler
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm" id="confirmDeleteBtn">
                                        <i class="bi bi-trash me-1"></i>Supprimer
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                if (!document.getElementById('deleteConfirmModal')) {
                    document.body.insertAdjacentHTML('beforeend', confirmationHtml);
                }
                
                const confirmModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
                confirmModal.show();
                
                document.getElementById('confirmDeleteBtn').onclick = () => {
                    this.closest('form').submit();
                };
            });
        });
    });

    // Fonction de validation des dates
    function validateDateInputs() {
        const dateDebut = document.getElementById('date_debut');
        const dateFin = document.getElementById('date_fin');
        
        if (dateDebut && dateFin && dateDebut.value && dateFin.value) {
            const debut = new Date(dateDebut.value);
            const fin = new Date(dateFin.value);
            
            if (debut >= fin) {
                dateFin.setCustomValidity('La date de fin doit être postérieure à la date de début');
                dateFin.classList.add('is-invalid');
            } else {
                dateFin.setCustomValidity('');
                dateFin.classList.remove('is-invalid');
                dateFin.classList.add('is-valid');
                dateDebut.classList.add('is-valid');
            }
        }
    }
</script>