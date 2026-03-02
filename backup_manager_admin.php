<?php
/**
 * Gestionnaire de Sauvegardes - Interface Administrateur Acadenique
 * Version intégrée avec authentification et design Acadenique
 */

// Vérifier que l'utilisateur est connecté (fait déjà dans index.php)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['nom_complet'])) {
    header("Location: ?page=login");
    exit();
}

// Inclure le système de sauvegarde
require_once 'backup_system_v2.php';

// Initialiser le système de sauvegarde
$backupSystem = null;
$connectionError = null;

try {
    $backupSystem = new BackupSystemOptimized();
} catch (Exception $e) {
    $connectionError = $e->getMessage();
}

// Récupérer les statistiques
$stats = [];
$backups = [];

if ($backupSystem) {
    try {
        $stats = $backupSystem->getStats();
        $backups = $backupSystem->listBackups();
    } catch (Exception $e) {
        $connectionError = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Sauvegardes - Acadenique</title>
    
    <style>
        .backup-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .backup-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .stats-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .backup-file-icon {
            color: #0d6efd;
            font-size: 1.2rem;
        }
        
        .backup-actions .btn {
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 0.875rem;
        }
        
        .progress-bar {
            transition: width 0.3s ease;
        }
        
        .alert-floating {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 1060;
            min-width: 300px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .loading-spinner {
            display: none;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(13, 110, 253, 0.05);
        }
        
        .badge {
            font-size: 0.75rem;
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6b4190 100%);
        }
    </style>
</head>

<body class="bg-light">
    <div class="container-fluid mt-4">
        <!-- En-tête de la page -->
        <div class="row mb-4">
            <div class="col">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="h3 mb-1">
                            <i class="bi bi-database-gear text-primary me-2"></i>
                            Gestion des Sauvegardes
                        </h2>
                        <p class="text-muted mb-0">Administration des sauvegardes de la base de données Acadenique</p>
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary me-2" id="createBackupBtn" 
                                <?= $connectionError ? 'disabled' : '' ?>>
                            <i class="bi bi-plus-circle me-1"></i>
                            Nouvelle Sauvegarde
                        </button>
                        <a href="backup_notifications_config.php" class="btn btn-outline-warning me-2">
                            <i class="bi bi-envelope-gear me-1"></i>
                            Notifications
                        </a>
                        <a href="backup_dashboard_advanced.php" class="btn btn-outline-info">
                            <i class="bi bi-graph-up me-1"></i>
                            Tableau de Bord
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Messages d'alerte -->
        <div id="alertContainer"></div>
        
        <?php if ($connectionError): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Erreur de connexion :</strong> <?= htmlspecialchars($connectionError) ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistiques -->
        <?php if (!$connectionError): ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card backup-card h-100">
                    <div class="card-body text-center">
                        <div class="stats-icon bg-primary bg-opacity-10 text-primary mx-auto mb-3">
                            <i class="bi bi-archive"></i>
                        </div>
                        <h3 class="h4 mb-1"><?= number_format($stats['total_backups']) ?></h3>
                        <p class="text-muted mb-0">Sauvegardes</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card backup-card h-100">
                    <div class="card-body text-center">
                        <div class="stats-icon bg-success bg-opacity-10 text-success mx-auto mb-3">
                            <i class="bi bi-hdd"></i>
                        </div>
                        <h3 class="h4 mb-1"><?= $stats['total_size_mb'] ?> MB</h3>
                        <p class="text-muted mb-0">Espace utilisé</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card backup-card h-100">
                    <div class="card-body text-center">
                        <div class="stats-icon bg-info bg-opacity-10 text-info mx-auto mb-3">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <h3 class="h6 mb-1">
                            <?= $stats['newest_backup'] ? date('d/m/Y H:i', strtotime($stats['newest_backup'])) : 'Aucune' ?>
                        </h3>
                        <p class="text-muted mb-0">Dernière sauvegarde</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card backup-card h-100">
                    <div class="card-body text-center">
                        <div class="stats-icon bg-warning bg-opacity-10 text-warning mx-auto mb-3">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <h3 class="h4 mb-1">Actif</h3>
                        <p class="text-muted mb-0">Système</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Liste des sauvegardes -->
        <?php if (!$connectionError): ?>
        <div class="card backup-card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list-ul me-2"></i>
                        Sauvegardes Existantes
                    </h5>
                    <button type="button" class="btn btn-sm btn-light" id="refreshBackupsBtn">
                        <i class="bi bi-arrow-clockwise me-1"></i>
                        Actualiser
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($backups)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                        <h5 class="text-muted mt-3">Aucune sauvegarde trouvée</h5>
                        <p class="text-muted">Créez votre première sauvegarde pour commencer.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="backupsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Fichier</th>
                                    <th>Date de création</th>
                                    <th>Taille</th>
                                    <th>Type</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $backup): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-file-earmark-zip backup-file-icon me-2"></i>
                                            <div>
                                                <div class="fw-medium"><?= htmlspecialchars($backup['name']) ?></div>
                                                <small class="text-muted"><?= basename($backup['path']) ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-nowrap"><?= date('d/m/Y H:i:s', strtotime($backup['date'])) ?></span>
                                        <br>
                                        <small class="text-muted">
                                            <?php
                                            $timeAgo = time() - strtotime($backup['date']);
                                            if ($timeAgo < 3600) {
                                                echo 'Il y a ' . floor($timeAgo / 60) . ' min';
                                            } elseif ($timeAgo < 86400) {
                                                echo 'Il y a ' . floor($timeAgo / 3600) . ' h';
                                            } else {
                                                echo 'Il y a ' . floor($timeAgo / 86400) . ' j';
                                            }
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php
                                        $sizeMB = round($backup['size'] / 1024 / 1024, 2);
                                        if ($sizeMB < 1) {
                                            echo round($backup['size'] / 1024, 0) . ' KB';
                                        } else {
                                            echo $sizeMB . ' MB';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (strpos($backup['name'], '.gz') !== false): ?>
                                            <span class="badge bg-success">Compressé</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Non compressé</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="backup-actions">
                                            <a href="backup_ajax_handler.php?action=download&file=<?= urlencode($backup['name']) ?>" 
                                               class="btn btn-sm btn-outline-primary" download>
                                                <i class="bi bi-download"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-danger delete-backup-btn" 
                                                    data-filename="<?= htmlspecialchars($backup['name']) ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal de création de sauvegarde -->
    <div class="modal fade" id="createBackupModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>
                        Créer une Nouvelle Sauvegarde
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createBackupForm">
                        <div class="mb-3">
                            <label for="backupName" class="form-label">Nom de la sauvegarde (optionnel)</label>
                            <input type="text" class="form-control" id="backupName" name="backupName" 
                                   placeholder="ex: backup_avant_mise_a_jour">
                            <div class="form-text">Si vide, un nom automatique sera généré avec la date et l'heure.</div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Information :</strong> Cette sauvegarde inclura toutes les tables et vues de la base de données avec compression automatique.
                        </div>
                        
                        <div id="backupProgress" class="d-none">
                            <div class="d-flex align-items-center mb-2">
                                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                <span>Création en cours...</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                     style="width: 100%"></div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-primary" id="confirmCreateBackup">
                        <i class="bi bi-download me-1"></i>
                        Créer la Sauvegarde
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation de suppression -->
    <div class="modal fade" id="deleteBackupModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Confirmer la Suppression
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Êtes-vous sûr de vouloir supprimer cette sauvegarde ?</p>
                    <div class="alert alert-warning">
                        <strong>Fichier :</strong> <span id="deleteFileName"></span>
                    </div>
                    <p class="text-danger">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Attention :</strong> Cette action est irréversible.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBackup">
                        <i class="bi bi-trash me-1"></i>
                        Supprimer
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Variables globales
        let currentFileToDelete = '';
        
        // Fonction pour afficher les alertes
        function showAlert(message, type = 'success') {
            const alertContainer = document.getElementById('alertContainer');
            const alertId = 'alert-' + Date.now();
            
            const alertHtml = `
                <div id="${alertId}" class="alert alert-${type} alert-dismissible fade show alert-floating" role="alert">
                    <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            alertContainer.insertAdjacentHTML('beforeend', alertHtml);
            
            // Auto-suppression après 5 secondes
            setTimeout(() => {
                const alert = document.getElementById(alertId);
                if (alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            }, 5000);
        }
        
        // Gestionnaire de création de sauvegarde
        document.getElementById('createBackupBtn').addEventListener('click', function() {
            const modal = new bootstrap.Modal(document.getElementById('createBackupModal'));
            modal.show();
        });
        
        // Confirmation de création
        document.getElementById('confirmCreateBackup').addEventListener('click', function() {
            const form = document.getElementById('createBackupForm');
            const backupName = document.getElementById('backupName').value.trim();
            const progressDiv = document.getElementById('backupProgress');
            const button = this;
            
            // Désactiver le bouton et afficher le progrès
            button.disabled = true;
            progressDiv.classList.remove('d-none');
            
            // Préparer les données
            const formData = new FormData();
            formData.append('action', 'create');
            if (backupName) {
                formData.append('backup_name', backupName);
            }
            
            // Envoyer la requête
            fetch('backup_ajax_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Sauvegarde créée avec succès : ' + data.filename, 'success');
                    
                    // Fermer le modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('createBackupModal'));
                    modal.hide();
                    
                    // Réinitialiser le formulaire
                    form.reset();
                    
                    // Actualiser la liste
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert('Erreur : ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showAlert('Erreur lors de la création de la sauvegarde', 'danger');
            })
            .finally(() => {
                button.disabled = false;
                progressDiv.classList.add('d-none');
            });
        });
        
        // Gestionnaire de suppression
        document.querySelectorAll('.delete-backup-btn').forEach(button => {
            button.addEventListener('click', function() {
                const filename = this.getAttribute('data-filename');
                currentFileToDelete = filename;
                
                document.getElementById('deleteFileName').textContent = filename;
                
                const modal = new bootstrap.Modal(document.getElementById('deleteBackupModal'));
                modal.show();
            });
        });
        
        // Confirmation de suppression
        document.getElementById('confirmDeleteBackup').addEventListener('click', function() {
            if (!currentFileToDelete) return;
            
            const button = this;
            button.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('filename', currentFileToDelete);
            
            fetch('backup_ajax_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Sauvegarde supprimée avec succès', 'success');
                    
                    // Fermer le modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteBackupModal'));
                    modal.hide();
                    
                    // Actualiser la liste
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert('Erreur : ' + data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                showAlert('Erreur lors de la suppression', 'danger');
            })
            .finally(() => {
                button.disabled = false;
            });
        });
        
        // Actualiser la liste
        document.getElementById('refreshBackupsBtn').addEventListener('click', function() {
            location.reload();
        });
        
        // Réinitialiser les modals quand ils se ferment
        document.getElementById('createBackupModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('createBackupForm').reset();
            document.getElementById('backupProgress').classList.add('d-none');
            document.getElementById('confirmCreateBackup').disabled = false;
        });
        
        document.getElementById('deleteBackupModal').addEventListener('hidden.bs.modal', function() {
            currentFileToDelete = '';
            document.getElementById('confirmDeleteBackup').disabled = false;
        });
    </script>
</body>
</html>