<?php
/**
 * Version simplifiée du gestionnaire de sauvegarde
 * Accessible à tous les utilisateurs connectés (pour les tests)
 */

session_start();

// Utiliser le même système d'authentification qu'Acadenique
require_once __DIR__ . '/includes/db_config.php';
require_once __DIR__ . '/includes/auth.php';

// Vérifier si l'utilisateur est connecté (avec support remember me)
if (!isLoggedIn()) {
    header("Location: index.php?page=login");
    exit();
}

require_once __DIR__ . '/backup_system.php';

$backupSystem = new BackupSystem();
$message = '';
$messageType = '';

// Traitement des actions de sauvegarde simple
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'create_full_backup') {
        $name = !empty($_POST['backup_name']) ? $_POST['backup_name'] : null;
        $result = $backupSystem->createFullBackup($name);
        
        if ($result['success']) {
            $message = "✅ Sauvegarde créée avec succès : " . basename($result['filename']);
            $messageType = 'success';
        } else {
            $message = "❌ Erreur : " . $result['error'];
            $messageType = 'error';
        }
    }
}

// Obtenir les sauvegardes disponibles
$backups = $backupSystem->listBackups();
$diskInfo = $backupSystem->getDiskInfo();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sauvegarde Simple - Acadenique</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f8f9fa; }
        .container { max-width: 900px; margin-top: 20px; }
        .card { border: none; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .alert-success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
        .alert-error { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3 class="mb-0">🛡️ Système de Sauvegarde - Version Simple</h3>
                        <small>Connecté en tant que: <?php echo htmlspecialchars($_SESSION['nom_complet']); ?></small>
                    </div>
                    
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                                <?php echo htmlspecialchars($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Statistiques -->
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="card bg-info text-white">
                                    <div class="card-body text-center">
                                        <h4><?php echo count($backups); ?></h4>
                                        <p class="mb-0">Sauvegardes</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-success text-white">
                                    <div class="card-body text-center">
                                        <h4><?php echo $backupSystem->formatBytes($diskInfo['backup_size']); ?></h4>
                                        <p class="mb-0">Espace utilisé</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-warning text-white">
                                    <div class="card-body text-center">
                                        <h4><?php echo $backupSystem->formatBytes($diskInfo['free_space']); ?></h4>
                                        <p class="mb-0">Espace libre</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Formulaire de sauvegarde -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5>Créer une sauvegarde complète</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="create_full_backup">
                                    <div class="mb-3">
                                        <label for="backup_name" class="form-label">Nom de la sauvegarde (optionnel)</label>
                                        <input type="text" class="form-control" id="backup_name" name="backup_name" 
                                               placeholder="Ex: sauvegarde_avant_mise_a_jour">
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-download"></i> Créer la sauvegarde
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Liste des sauvegardes -->
                        <div class="card">
                            <div class="card-header">
                                <h5>Sauvegardes disponibles (<?php echo count($backups); ?>)</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($backups)): ?>
                                    <p class="text-muted text-center py-4">
                                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                        Aucune sauvegarde disponible
                                    </p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Nom du fichier</th>
                                                    <th>Date</th>
                                                    <th>Taille</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($backups as $backup): ?>
                                                    <tr>
                                                        <td>
                                                            <code><?php echo htmlspecialchars($backup['filename']); ?></code>
                                                        </td>
                                                        <td><?php echo $backup['date']; ?></td>
                                                        <td><?php echo $backupSystem->formatBytes($backup['size']); ?></td>
                                                        <td>
                                                            <a href="backups/<?php echo urlencode($backup['filename']); ?>" 
                                                               class="btn btn-sm btn-outline-primary" download>
                                                                Télécharger
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mt-4 text-center">
                            <a href="index.php" class="btn btn-secondary">Retour au tableau de bord</a>
                            <a href="backup_manager.php" class="btn btn-outline-primary">Version complète</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>