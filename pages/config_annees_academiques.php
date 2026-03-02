<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * Configuration des Années Académiques
 * Page réservée aux administrateurs pour gérer les années académiques et leurs semestres
 */

require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/domaine_functions.php';

// Vérification de l'authentification
requireLogin();

// Vérification que l'utilisateur est administrateur
if (!hasRole('administrateur')) {
    header('Location: ?page=access-denied');
    exit();
}

// Variables de messages
$success = '';
$error = '';

// ==========================================
// TRAITEMENT DES ACTIONS POST
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            // Ajouter une nouvelle année académique
            case 'add_year':
                $dateDebut = $_POST['date_debut'] ?? '';
                $dateFin = $_POST['date_fin'] ?? '';
                $statut = $_POST['statut'] ?? 'active';

                if (empty($dateDebut) || empty($dateFin)) {
                    $error = "Veuillez renseigner toutes les dates.";
                } elseif (strtotime($dateDebut) >= strtotime($dateFin)) {
                    $error = "La date de fin doit être postérieure à la date de début.";
                } else {
                    // Vérifier si une année avec ces dates existe déjà
                    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM t_anne_academique WHERE date_debut = ? OR date_fin = ?");
                    $stmt_check->execute([$dateDebut, $dateFin]);
                    
                    if ($stmt_check->fetchColumn() > 0) {
                        $error = "Une année académique avec ces dates existe déjà.";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO t_anne_academique (date_debut, date_fin, statut) VALUES (?, ?, ?)");
                        
                        if ($stmt->execute([$dateDebut, $dateFin, $statut])) {
                            $id_annee = $pdo->lastInsertId();
                            
                            // Si c'est la première année, la définir comme année en cours
                            $stmt_count = $pdo->query("SELECT COUNT(*) FROM t_anne_academique");
                            if ($stmt_count->fetchColumn() == 1) {
                                $stmt_config = $pdo->prepare("INSERT INTO t_configuration (cle, valeur) VALUES ('annee_encours', ?) 
                                                             ON DUPLICATE KEY UPDATE valeur = ?");
                                $stmt_config->execute([$id_annee, $id_annee]);
                            }
                            
                            $success = "Année académique créée avec succès !";
                        } else {
                            $error = "Erreur lors de la création de l'année académique.";
                        }
                    }
                }
                break;

            // Modifier une année académique
            case 'edit_year':
                $idAnnee = $_POST['id_annee'] ?? 0;
                $dateDebut = $_POST['date_debut'] ?? '';
                $dateFin = $_POST['date_fin'] ?? '';
                $statut = $_POST['statut'] ?? 'active';

                if (empty($dateDebut) || empty($dateFin)) {
                    $error = "Veuillez renseigner toutes les dates.";
                } elseif (strtotime($dateDebut) >= strtotime($dateFin)) {
                    $error = "La date de fin doit être postérieure à la date de début.";
                } else {
                    $stmt = $pdo->prepare("UPDATE t_anne_academique SET date_debut = ?, date_fin = ?, statut = ? WHERE id_annee = ?");
                    
                    if ($stmt->execute([$dateDebut, $dateFin, $statut, $idAnnee])) {
                        $success = "Année académique modifiée avec succès !";
                    } else {
                        $error = "Erreur lors de la modification de l'année académique.";
                    }
                }
                break;

            // Supprimer une année académique
            case 'delete_year':
                $idAnnee = $_POST['id_annee'] ?? 0;
                
                // Vérifier si l'année est l'année en cours
                $stmt_check = $pdo->prepare("SELECT valeur FROM t_configuration WHERE cle = 'annee_encours'");
                $stmt_check->execute();
                $annee_encours = $stmt_check->fetchColumn();
                
                if ($annee_encours == $idAnnee) {
                    $error = "Impossible de supprimer l'année académique en cours. Veuillez d'abord définir une autre année comme année en cours.";
                } else {
                    // Vérifier s'il y a des semestres associés
                    $stmt_semestres = $pdo->prepare("SELECT COUNT(*) FROM t_semestre WHERE id_annee = ?");
                    $stmt_semestres->execute([$idAnnee]);
                    
                    if ($stmt_semestres->fetchColumn() > 0) {
                        $error = "Impossible de supprimer cette année car elle contient des semestres. Supprimez d'abord les semestres.";
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM t_anne_academique WHERE id_annee = ?");
                        
                        if ($stmt->execute([$idAnnee])) {
                            $success = "Année académique supprimée avec succès !";
                        } else {
                            $error = "Erreur lors de la suppression de l'année académique.";
                        }
                    }
                }
                break;

            // Définir l'année en cours
            case 'set_current_year':
                $idAnnee = $_POST['id_annee'] ?? 0;
                
                $stmt = $pdo->prepare("INSERT INTO t_configuration (cle, valeur) VALUES ('annee_encours', ?) 
                                      ON DUPLICATE KEY UPDATE valeur = ?");
                
                if ($stmt->execute([$idAnnee, $idAnnee])) {
                    $success = "Année académique définie comme année en cours !";
                } else {
                    $error = "Erreur lors de la définition de l'année en cours.";
                }
                break;

            // Créer les semestres pour une année
            case 'create_semesters':
                $idAnnee = $_POST['id_annee'] ?? 0;
                
                // Vérifier que l'année existe
                $stmt_annee = $pdo->prepare("SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?");
                $stmt_annee->execute([$idAnnee]);
                $annee = $stmt_annee->fetch(PDO::FETCH_ASSOC);
                
                if (!$annee) {
                    $error = "Année académique introuvable.";
                } else {
                    // Vérifier s'il existe déjà des semestres
                    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM t_semestre WHERE id_annee = ?");
                    $stmt_check->execute([$idAnnee]);
                    
                    if ($stmt_check->fetchColumn() > 0) {
                        $error = "Des semestres existent déjà pour cette année académique.";
                    } else {
                        // Calculer la date de mi-année
                        $dateDebut = $annee['date_debut'];
                        $dateFin = $annee['date_fin'];
                        $diff = (strtotime($dateFin) - strtotime($dateDebut)) / 2;
                        $dateMoitie = date('Y-m-d', strtotime($dateDebut) + $diff);
                        
                        $pdo->beginTransaction();
                        
                        try {
                            // Création du Semestre 1
                            $stmt1 = $pdo->prepare("INSERT INTO t_semestre (code_semestre, nom_semestre, id_annee, date_debut, date_fin, statut) 
                                                    VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt1->execute(['S1', 'Semestre 1', $idAnnee, $dateDebut, $dateMoitie, 'active']);
                            
                            // Création du Semestre 2
                            $dateDebutS2 = date('Y-m-d', strtotime($dateMoitie . ' +1 day'));
                            $stmt2 = $pdo->prepare("INSERT INTO t_semestre (code_semestre, nom_semestre, id_annee, date_debut, date_fin, statut) 
                                                    VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt2->execute(['S2', 'Semestre 2', $idAnnee, $dateDebutS2, $dateFin, 'active']);
                            
                            $pdo->commit();
                            $success = "Semestres créés avec succès !";
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $error = "Erreur lors de la création des semestres : " . $e->getMessage();
                        }
                    }
                }
                break;

            // Supprimer les semestres d'une année
            case 'delete_semesters':
                $idAnnee = $_POST['id_annee'] ?? 0;
                
                // Vérifier s'il y a des UE associées aux semestres
                $stmt_check = $pdo->prepare("
                    SELECT COUNT(*) FROM t_unite_enseignement ue
                    INNER JOIN t_semestre s ON ue.id_semestre = s.id_semestre
                    WHERE s.id_annee = ?
                ");
                $stmt_check->execute([$idAnnee]);
                
                if ($stmt_check->fetchColumn() > 0) {
                    $error = "Impossible de supprimer les semestres car des UE y sont associées.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM t_semestre WHERE id_annee = ?");
                    
                    if ($stmt->execute([$idAnnee])) {
                        $success = "Semestres supprimés avec succès !";
                    } else {
                        $error = "Erreur lors de la suppression des semestres.";
                    }
                }
                break;
        }
    } catch (PDOException $e) {
        $error = "Erreur de base de données : " . $e->getMessage();
        error_log("Erreur config années académiques : " . $e->getMessage());
    }
}

// ==========================================
// RÉCUPÉRATION DES DONNÉES
// ==========================================

// Récupérer toutes les années académiques
$allYears = getAllAcademicYears($pdo);

// Récupérer l'année en cours
$stmt_current = $pdo->prepare("SELECT valeur FROM t_configuration WHERE cle = 'annee_encours'");
$stmt_current->execute();
$currentYearId = $stmt_current->fetchColumn();

// Récupérer les semestres pour chaque année
$yearSemesters = [];
foreach ($allYears as $year) {
    $stmt_sem = $pdo->prepare("SELECT * FROM t_semestre WHERE id_annee = ? ORDER BY code_semestre");
    $stmt_sem->execute([$year['id_annee']]);
    $yearSemesters[$year['id_annee']] = $stmt_sem->fetchAll(PDO::FETCH_ASSOC);
}

// Statistiques par année
$yearStats = [];
foreach ($allYears as $year) {
    $stats = [
        'nb_semestres' => 0,
        'nb_inscriptions' => 0,
        'nb_domaines' => 0
    ];
    
    // Nombre de semestres
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM t_semestre WHERE id_annee = ?");
    $stmt->execute([$year['id_annee']]);
    $stats['nb_semestres'] = $stmt->fetchColumn();
    
    // Nombre d'inscriptions
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM t_inscription WHERE id_annee = ?");
    $stmt->execute([$year['id_annee']]);
    $stats['nb_inscriptions'] = $stmt->fetchColumn();
    
    // Nombre de domaines actifs
    $stmt = $pdo->query("SELECT COUNT(DISTINCT id_domaine) FROM t_domaine");
    $stats['nb_domaines'] = $stmt->fetchColumn();
    
    $yearStats[$year['id_annee']] = $stats;
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration des Années Académiques</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #1cc88a 0%, #17a673 100%);
            --warning-gradient: linear-gradient(135deg, #f6c23e 0%, #dda20a 100%);
            --danger-gradient: linear-gradient(135deg, #e74a3b 0%, #c92a1f 100%);
            --info-gradient: linear-gradient(135deg, #36b9cc 0%, #258391 100%);
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }

        .page-header {
            background: var(--primary-gradient);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.15);
        }

        .page-header h1 {
            margin: 0;
            font-weight: 700;
        }

        .page-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 0.25rem 1rem rgba(0, 0, 0, 0.1);
            margin-bottom: 25px;
            transition: all 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 0.5rem 2rem rgba(0, 0, 0, 0.15);
            transform: translateY(-5px);
        }

        .card-header {
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px 25px;
            font-weight: 600;
        }

        .year-card {
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
        }

        .year-card.current {
            border-left-color: #1cc88a;
            background: linear-gradient(135deg, #f0fff4 0%, #e6fffa 100%);
        }

        .year-card:hover {
            border-left-width: 6px;
        }

        .badge-custom {
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .badge-current {
            background: var(--success-gradient);
            color: white;
        }

        .badge-inactive {
            background: linear-gradient(135deg, #858796 0%, #6c757d 100%);
            color: white;
        }

        .stat-box {
            background: linear-gradient(145deg, #ffffff, #f8f9fa);
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            border: 2px solid #e3e6f0;
            transition: all 0.3s ease;
        }

        .stat-box:hover {
            border-color: #667eea;
            transform: scale(1.05);
        }

        .stat-box .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
        }

        .stat-box .stat-label {
            font-size: 0.85rem;
            color: #858796;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 10px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(102, 126, 234, 0.4);
        }

        .btn-success {
            background: var(--success-gradient);
            border: none;
            border-radius: 10px;
            padding: 10px 25px;
            font-weight: 600;
        }

        .btn-warning {
            background: var(--warning-gradient);
            border: none;
            border-radius: 10px;
            padding: 10px 25px;
            font-weight: 600;
            color: white;
        }

        .btn-danger {
            background: var(--danger-gradient);
            border: none;
            border-radius: 10px;
            padding: 10px 25px;
            font-weight: 600;
        }

        .btn-info {
            background: var(--info-gradient);
            border: none;
            border-radius: 10px;
            padding: 10px 25px;
            font-weight: 600;
            color: white;
        }

        .modal-content {
            border: none;
            border-radius: 20px;
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 20px 20px 0 0;
            padding: 25px 30px;
        }

        .modal-body {
            padding: 30px;
        }

        .form-control, .form-select {
            border: 2px solid #e3e6f0;
            border-radius: 10px;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .form-label {
            font-weight: 600;
            color: #5a5c69;
            margin-bottom: 8px;
        }

        .alert {
            border: none;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }

        .semester-badge {
            display: inline-block;
            background: linear-gradient(135deg, #36b9cc 0%, #258391 100%);
            color: white;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 5px;
        }

        .action-buttons .btn {
            margin: 2px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: linear-gradient(145deg, #ffffff, #f8f9fa);
            border-radius: 15px;
            border: 2px dashed #dee2e6;
        }

        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 20px;
        }

        .timeline-item {
            border-left: 3px solid #667eea;
            padding-left: 20px;
            margin-bottom: 15px;
            position: relative;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 0;
            width: 13px;
            height: 13px;
            background: #667eea;
            border-radius: 50%;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- En-tête de la page -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1><i class="bi bi-calendar3"></i> Configuration des Années Académiques</h1>
                    <p class="mb-0">Gérez les années académiques et leurs semestres</p>
                </div>
                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addYearModal">
                    <i class="bi bi-plus-circle"></i> Nouvelle Année
                </button>
            </div>
        </div>

        <!-- Messages de succès/erreur -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Liste des années académiques -->
        <?php if (empty($allYears)): ?>
            <div class="empty-state">
                <i class="bi bi-calendar-x"></i>
                <h3>Aucune année académique configurée</h3>
                <p class="text-muted">Commencez par créer votre première année académique</p>
                <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#addYearModal">
                    <i class="bi bi-plus-circle me-2"></i>Créer une année académique
                </button>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($allYears as $year): 
                    $isCurrent = ($year['id_annee'] == $currentYearId);
                    $libelle = date('Y', strtotime($year['date_debut'])) . '-' . date('Y', strtotime($year['date_fin']));
                    $stats = $yearStats[$year['id_annee']];
                    $semesters = $yearSemesters[$year['id_annee']];
                ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card year-card <?php echo $isCurrent ? 'current' : ''; ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <h4 class="mb-0">
                                        <i class="bi bi-calendar-event text-primary"></i>
                                        <?php echo htmlspecialchars($libelle); ?>
                                    </h4>
                                    <?php if ($isCurrent): ?>
                                        <span class="badge-custom badge-current">
                                            <i class="bi bi-star-fill"></i> En cours
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-custom badge-inactive">
                                            <?php echo htmlspecialchars(ucfirst($year['statut'] ?? 'inactive')); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="timeline-item">
                                    <small class="text-muted">
                                        <i class="bi bi-calendar-check"></i> 
                                        <?php echo date('d/m/Y', strtotime($year['date_debut'])); ?>
                                    </small>
                                </div>
                                <div class="timeline-item">
                                    <small class="text-muted">
                                        <i class="bi bi-calendar-x"></i> 
                                        <?php echo date('d/m/Y', strtotime($year['date_fin'])); ?>
                                    </small>
                                </div>

                                <!-- Statistiques -->
                                <div class="row g-2 my-3">
                                    <div class="col-4">
                                        <div class="stat-box">
                                            <div class="stat-value"><?php echo $stats['nb_semestres']; ?></div>
                                            <div class="stat-label">Semestres</div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="stat-box">
                                            <div class="stat-value"><?php echo $stats['nb_inscriptions']; ?></div>
                                            <div class="stat-label">Inscrits</div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="stat-box">
                                            <div class="stat-value"><?php echo $stats['nb_domaines']; ?></div>
                                            <div class="stat-label">Domaines</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Semestres -->
                                <?php if (!empty($semesters)): ?>
                                    <div class="mb-3">
                                        <small class="text-muted d-block mb-2">Semestres :</small>
                                        <?php foreach ($semesters as $sem): ?>
                                            <span class="semester-badge">
                                                <?php echo htmlspecialchars($sem['code_semestre']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Actions -->
                                <div class="action-buttons mt-3 d-flex flex-wrap gap-1">
                                    <?php if (!$isCurrent): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="set_current_year">
                                            <input type="hidden" name="id_annee" value="<?php echo $year['id_annee']; ?>">
                                            <button type="submit" class="btn btn-success btn-sm" title="Définir comme année en cours">
                                                <i class="bi bi-check-circle"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (empty($semesters)): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="create_semesters">
                                            <input type="hidden" name="id_annee" value="<?php echo $year['id_annee']; ?>">
                                            <button type="submit" class="btn btn-info btn-sm" title="Créer les semestres">
                                                <i class="bi bi-plus-square"></i> Semestres
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer les semestres ?');">
                                            <input type="hidden" name="action" value="delete_semesters">
                                            <input type="hidden" name="id_annee" value="<?php echo $year['id_annee']; ?>">
                                            <button type="submit" class="btn btn-warning btn-sm" title="Supprimer les semestres">
                                                <i class="bi bi-trash"></i> Semestres
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-primary btn-sm" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editYearModal<?php echo $year['id_annee']; ?>"
                                            title="Modifier">
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <?php if (!$isCurrent): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer cette année ?');">
                                            <input type="hidden" name="action" value="delete_year">
                                            <input type="hidden" name="id_annee" value="<?php echo $year['id_annee']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Supprimer">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal de modification pour chaque année -->
                    <div class="modal fade" id="editYearModal<?php echo $year['id_annee']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <i class="bi bi-pencil-square me-2"></i>
                                        Modifier l'année <?php echo htmlspecialchars($libelle); ?>
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <div class="modal-body">
                                        <input type="hidden" name="action" value="edit_year">
                                        <input type="hidden" name="id_annee" value="<?php echo $year['id_annee']; ?>">

                                        <div class="mb-3">
                                            <label for="edit_date_debut_<?php echo $year['id_annee']; ?>" class="form-label">
                                                <i class="bi bi-calendar-event text-success"></i> Date de début
                                            </label>
                                            <input type="date" class="form-control" 
                                                   id="edit_date_debut_<?php echo $year['id_annee']; ?>" 
                                                   name="date_debut" 
                                                   value="<?php echo $year['date_debut']; ?>" 
                                                   required>
                                        </div>

                                        <div class="mb-3">
                                            <label for="edit_date_fin_<?php echo $year['id_annee']; ?>" class="form-label">
                                                <i class="bi bi-calendar-x text-danger"></i> Date de fin
                                            </label>
                                            <input type="date" class="form-control" 
                                                   id="edit_date_fin_<?php echo $year['id_annee']; ?>" 
                                                   name="date_fin" 
                                                   value="<?php echo $year['date_fin']; ?>" 
                                                   required>
                                        </div>

                                        <div class="mb-3">
                                            <label for="edit_statut_<?php echo $year['id_annee']; ?>" class="form-label">
                                                <i class="bi bi-toggle-on text-info"></i> Statut
                                            </label>
                                            <select class="form-select" id="edit_statut_<?php echo $year['id_annee']; ?>" name="statut">
                                                <option value="active" <?php echo ($year['statut'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="inactive" <?php echo ($year['statut'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                            <i class="bi bi-x-circle me-2"></i>Annuler
                                        </button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-2"></i>Enregistrer
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal d'ajout d'année académique -->
    <div class="modal fade" id="addYearModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>
                        Nouvelle Année Académique
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" class="needs-validation" novalidate>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_year">

                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Info :</strong> Les semestres pourront être créés automatiquement après la création de l'année.
                        </div>

                        <div class="mb-3">
                            <label for="add_date_debut" class="form-label">
                                <i class="bi bi-calendar-event text-success"></i> Date de début *
                            </label>
                            <input type="date" class="form-control" id="add_date_debut" name="date_debut" required>
                            <div class="invalid-feedback">
                                Veuillez sélectionner une date de début
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="add_date_fin" class="form-label">
                                <i class="bi bi-calendar-x text-danger"></i> Date de fin *
                            </label>
                            <input type="date" class="form-control" id="add_date_fin" name="date_fin" required>
                            <div class="invalid-feedback">
                                Veuillez sélectionner une date de fin
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="add_statut" class="form-label">
                                <i class="bi bi-toggle-on text-info"></i> Statut
                            </label>
                            <select class="form-select" id="add_statut" name="statut">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-2"></i>Annuler
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-2"></i>Créer l'année
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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

        // Validation des dates (fin > début)
        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                const dateDebut = form.querySelector('[name="date_debut"]');
                const dateFin = form.querySelector('[name="date_fin"]');
                
                if (dateDebut && dateFin) {
                    if (new Date(dateDebut.value) >= new Date(dateFin.value)) {
                        e.preventDefault();
                        alert('La date de fin doit être postérieure à la date de début !');
                        return false;
                    }
                }
            });
        });

        // Auto-dismiss alerts après 5 secondes
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>
