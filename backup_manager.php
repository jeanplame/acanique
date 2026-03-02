<?php
/**
 * Interface d'administration pour la gestion des sauvegardes
 * Accessible via l'interface web pour les administrateurs
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

// Vérifier que l'utilisateur est admin
$isAdmin = false;
try {
    $stmt = $pdo->prepare("SELECT role FROM t_utilisateur WHERE username = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userRole = $stmt->fetchColumn();
    
    // Vérifications multiples pour l'admin
    if ($userRole && in_array(strtolower($userRole), ['administrateur', 'admin', 'administrator'])) {
        $isAdmin = true;
    }
    
    // Vérification spéciale pour l'utilisateur 'admin'
    if (!$isAdmin && $_SESSION['user_id'] === 'admin') {
        $isAdmin = true;
    }
    
    // Vérification par la variable de session role si elle existe
    if (!$isAdmin && isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), ['administrateur', 'admin', 'administrator'])) {
        $isAdmin = true;
    }
    
} catch (Exception $e) {
    error_log("Erreur lors de la vérification des permissions: " . $e->getMessage());
}

if (!$isAdmin) {
    http_response_code(403);
    die("
    <div style='font-family: Arial, sans-serif; text-align: center; margin-top: 50px;'>
        <h2>🔒 Accès Refusé</h2>
        <p>Seuls les administrateurs peuvent accéder au système de sauvegarde.</p>
        <p>Utilisateur connecté: <strong>" . htmlspecialchars($_SESSION['user_id'] ?? 'Non connecté') . "</strong></p>
        <p>Nom: <strong>" . htmlspecialchars($_SESSION['nom_complet'] ?? 'Non défini') . "</strong></p>
        <p>Rôle (session): <strong>" . htmlspecialchars($_SESSION['role'] ?? 'Non défini') . "</strong></p>
        <p>Rôle (DB): <strong>" . htmlspecialchars($userRole ?? 'Non défini') . "</strong></p>
        <br>
        <a href='index.php?page=dashboard' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Retour au tableau de bord</a>
        <br><br>
        <small><a href='debug_session.php'>Voir détails session</a></small>
    </div>");
}

$backupSystem = new BackupSystem();
$message = '';
$messageType = '';

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_full_backup':
            $name = !empty($_POST['backup_name']) ? $_POST['backup_name'] : null;
            $result = $backupSystem->createFullBackup($name);
            if ($result['success']) {
                $message = "Sauvegarde complète créée avec succès : " . basename($result['filename']);
                $messageType = 'success';
            } else {
                $message = "Erreur lors de la création de la sauvegarde : " . $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'create_partial_backup':
            $tables = $_POST['selected_tables'] ?? [];
            $name = !empty($_POST['backup_name']) ? $_POST['backup_name'] : null;
            
            if (empty($tables)) {
                $message = "Veuillez sélectionner au moins une table.";
                $messageType = 'error';
            } else {
                $result = $backupSystem->createPartialBackup($tables, $name);
                if ($result['success']) {
                    $message = "Sauvegarde partielle créée avec succès : " . basename($result['filename']);
                    $messageType = 'success';
                } else {
                    $message = "Erreur lors de la création de la sauvegarde : " . $result['error'];
                    $messageType = 'error';
                }
            }
            break;
            
        case 'create_data_backup':
            $name = !empty($_POST['backup_name']) ? $_POST['backup_name'] : null;
            $result = $backupSystem->createDataOnlyBackup($name);
            if ($result['success']) {
                $message = "Sauvegarde des données créée avec succès : " . basename($result['filename']);
                $messageType = 'success';
            } else {
                $message = "Erreur lors de la création de la sauvegarde : " . $result['error'];
                $messageType = 'error';
            }
            break;
            
        case 'delete_backup':
            $filename = $_POST['filename'] ?? '';
            if ($filename && $this->deleteBackup($filename)) {
                $message = "Sauvegarde supprimée avec succès : $filename";
                $messageType = 'success';
            } else {
                $message = "Erreur lors de la suppression de la sauvegarde.";
                $messageType = 'error';
            }
            break;
            
        case 'restore_backup':
            $filename = $_POST['filename'] ?? '';
            if ($filename) {
                $result = $this->restoreBackup($filename);
                if ($result['success']) {
                    $message = "Base de données restaurée avec succès depuis : $filename";
                    $messageType = 'success';
                } else {
                    $message = "Erreur lors de la restauration : " . $result['error'];
                    $messageType = 'error';
                }
            }
            break;
    }
}

// Obtenir les informations nécessaires pour l'affichage
$backups = $backupSystem->listBackups();
$diskInfo = $backupSystem->getDiskInfo();

// Obtenir la liste des tables pour la sauvegarde partielle
try {
    $stmt = $pdo->query("SHOW TABLES");
    $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $allTables = [];
}

/**
 * Supprimer une sauvegarde
 */
function deleteBackup($filename) {
    $filepath = __DIR__ . '/backups/' . $filename;
    if (file_exists($filepath) && is_file($filepath)) {
        return unlink($filepath);
    }
    return false;
}

/**
 * Restaurer une sauvegarde (fonction basique - à étendre selon les besoins)
 */
function restoreBackup($filename) {
    global $pdo;
    
    try {
        $filepath = __DIR__ . '/backups/' . $filename;
        
        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => 'Fichier de sauvegarde non trouvé'];
        }
        
        // Lire le contenu du fichier
        if (str_ends_with($filename, '.gz')) {
            $sql = gzdecode(file_get_contents($filepath));
        } else {
            $sql = file_get_contents($filepath);
        }
        
        if ($sql === false) {
            return ['success' => false, 'error' => 'Impossible de lire le fichier de sauvegarde'];
        }
        
        // Créer une sauvegarde de sécurité avant la restauration
        $backupSystem = new BackupSystem();
        $securityBackup = $backupSystem->createFullBackup('before_restore_' . date('Y-m-d_H-i-s'));
        
        if (!$securityBackup['success']) {
            return ['success' => false, 'error' => 'Impossible de créer la sauvegarde de sécurité'];
        }
        
        // Désactiver les vérifications de clés étrangères temporairement
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // Exécuter le SQL de restauration
        $pdo->exec($sql);
        
        // Réactiver les vérifications de clés étrangères
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        return ['success' => true, 'security_backup' => $securityBackup['filename']];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionnaire de Sauvegardes - Acadenique</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            text-align: center;
        }
        
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 300;
        }
        
        .content {
            padding: 30px;
        }
        
        .tabs {
            display: flex;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 5px;
            margin-bottom: 30px;
        }
        
        .tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: transparent;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 500;
        }
        
        .tab.active {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            color: #667eea;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .card h3 {
            margin-top: 0;
            color: #495057;
            font-size: 18px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #495057;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a6fd8;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .alert {
            padding: 15px;
            border: 1px solid transparent;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        
        .alert-error {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        
        .checkbox-group {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .checkbox-item input {
            margin-right: 8px;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .progress {
            background: #e9ecef;
            border-radius: 4px;
            height: 8px;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .progress-bar {
            background: #28a745;
            height: 100%;
            transition: width 0.3s;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background: white;
            margin: 15% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 500px;
        }
        
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: black;
        }
        
        @media (max-width: 768px) {
            .container {
                margin: 10px;
                border-radius: 0;
            }
            
            .content {
                padding: 20px;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                margin-bottom: 5px;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🛡️ Gestionnaire de Sauvegardes</h1>
            <p>Administration des sauvegardes de la base de données Acadenique</p>
        </div>
        
        <div class="content">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistiques -->
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($backups); ?></div>
                    <div class="stat-label">Sauvegardes disponibles</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $backupSystem->formatBytes($diskInfo['backup_size']); ?></div>
                    <div class="stat-label">Espace utilisé</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $backupSystem->formatBytes($diskInfo['free_space']); ?></div>
                    <div class="stat-label">Espace libre</div>
                </div>
            </div>
            
            <!-- Onglets -->
            <div class="tabs">
                <button class="tab active" onclick="showTab('create')">Créer une sauvegarde</button>
                <button class="tab" onclick="showTab('manage')">Gérer les sauvegardes</button>
                <button class="tab" onclick="showTab('settings')">Paramètres</button>
            </div>
            
            <!-- Contenu des onglets -->
            <div id="create" class="tab-content active">
                <div class="card">
                    <h3>Sauvegarde complète</h3>
                    <p>Créer une sauvegarde complète de toute la base de données (structure + données).</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_full_backup">
                        <div class="form-group">
                            <label for="full_backup_name">Nom personnalisé (optionnel)</label>
                            <input type="text" id="full_backup_name" name="backup_name" class="form-control" 
                                   placeholder="Ex: backup_avant_migration">
                        </div>
                        <button type="submit" class="btn btn-primary">Créer la sauvegarde complète</button>
                    </form>
                </div>
                
                <div class="card">
                    <h3>Sauvegarde partielle</h3>
                    <p>Créer une sauvegarde de tables sélectionnées uniquement.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_partial_backup">
                        <div class="form-group">
                            <label for="partial_backup_name">Nom personnalisé (optionnel)</label>
                            <input type="text" id="partial_backup_name" name="backup_name" class="form-control" 
                                   placeholder="Ex: backup_utilisateurs">
                        </div>
                        <div class="form-group">
                            <label>Tables à sauvegarder</label>
                            <div class="checkbox-group">
                                <?php foreach ($allTables as $table): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="table_<?php echo $table; ?>" 
                                               name="selected_tables[]" value="<?php echo $table; ?>">
                                        <label for="table_<?php echo $table; ?>"><?php echo $table; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Créer la sauvegarde partielle</button>
                    </form>
                </div>
                
                <div class="card">
                    <h3>Sauvegarde des données uniquement</h3>
                    <p>Créer une sauvegarde des données sans la structure des tables.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_data_backup">
                        <div class="form-group">
                            <label for="data_backup_name">Nom personnalisé (optionnel)</label>
                            <input type="text" id="data_backup_name" name="backup_name" class="form-control" 
                                   placeholder="Ex: donnees_migration">
                        </div>
                        <button type="submit" class="btn btn-success">Créer la sauvegarde des données</button>
                    </form>
                </div>
            </div>
            
            <div id="manage" class="tab-content">
                <div class="card">
                    <h3>Sauvegardes disponibles</h3>
                    <?php if (empty($backups)): ?>
                        <p>Aucune sauvegarde disponible.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Nom du fichier</th>
                                    <th>Date</th>
                                    <th>Taille</th>
                                    <th>Type</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $backup): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($backup['filename']); ?></td>
                                        <td><?php echo $backup['date']; ?></td>
                                        <td><?php echo $backupSystem->formatBytes($backup['size']); ?></td>
                                        <td>
                                            <?php if ($backup['compressed']): ?>
                                                <span class="badge bg-info">Compressé</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Non compressé</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo 'backups/' . $backup['filename']; ?>" 
                                               class="btn btn-primary btn-sm" download>Télécharger</a>
                                            
                                            <button onclick="verifyBackup('<?php echo $backup['filename']; ?>')" 
                                                    class="btn btn-warning btn-sm">Vérifier</button>
                                            
                                            <button onclick="confirmRestore('<?php echo $backup['filename']; ?>')" 
                                                    class="btn btn-success btn-sm">Restaurer</button>
                                            
                                            <button onclick="confirmDelete('<?php echo $backup['filename']; ?>')" 
                                                    class="btn btn-danger btn-sm">Supprimer</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <div id="settings" class="tab-content">
                <div class="card">
                    <h3>Informations système</h3>
                    <table class="table">
                        <tr>
                            <th>Répertoire des sauvegardes</th>
                            <td><?php echo htmlspecialchars($diskInfo['backup_path']); ?></td>
                        </tr>
                        <tr>
                            <th>Espace disque total</th>
                            <td><?php echo $backupSystem->formatBytes($diskInfo['total_space']); ?></td>
                        </tr>
                        <tr>
                            <th>Espace libre</th>
                            <td><?php echo $backupSystem->formatBytes($diskInfo['free_space']); ?></td>
                        </tr>
                        <tr>
                            <th>Espace utilisé par les sauvegardes</th>
                            <td><?php echo $backupSystem->formatBytes($diskInfo['backup_size']); ?></td>
                        </tr>
                        <tr>
                            <th>Nombre de tables</th>
                            <td><?php echo count($allTables); ?></td>
                        </tr>
                    </table>
                    
                    <div class="progress">
                        <?php 
                        $usedPercentage = ($diskInfo['total_space'] - $diskInfo['free_space']) / $diskInfo['total_space'] * 100;
                        ?>
                        <div class="progress-bar" style="width: <?php echo min(100, $usedPercentage); ?>%"></div>
                    </div>
                    <p><small>Utilisation du disque: <?php echo round($usedPercentage, 1); ?>%</small></p>
                </div>
                
                <div class="card">
                    <h3>Actions de maintenance</h3>
                    <p>Effectuer des opérations de maintenance sur le système de sauvegarde.</p>
                    <button onclick="cleanOldBackups()" class="btn btn-warning">Nettoyer les anciennes sauvegardes</button>
                    <button onclick="optimizeDatabase()" class="btn btn-primary">Optimiser la base de données</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modales -->
    <div id="confirmModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3 id="modalTitle">Confirmation</h3>
            <p id="modalMessage">Êtes-vous sûr de vouloir effectuer cette action ?</p>
            <div style="text-align: right; margin-top: 20px;">
                <button class="btn btn-secondary" onclick="closeModal()">Annuler</button>
                <button id="confirmButton" class="btn btn-danger">Confirmer</button>
            </div>
        </div>
    </div>

    <script>
        // Gestion des onglets
        function showTab(tabName) {
            // Cacher tous les contenus d'onglets
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));
            
            // Désactiver tous les onglets
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Activer l'onglet et le contenu sélectionnés
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }
        
        // Gestion des modales
        function confirmDelete(filename) {
            document.getElementById('modalTitle').textContent = 'Supprimer la sauvegarde';
            document.getElementById('modalMessage').textContent = 
                'Êtes-vous sûr de vouloir supprimer la sauvegarde "' + filename + '" ? Cette action est irréversible.';
            document.getElementById('confirmButton').onclick = function() {
                deleteBackup(filename);
            };
            document.getElementById('confirmModal').style.display = 'block';
        }
        
        function confirmRestore(filename) {
            document.getElementById('modalTitle').textContent = 'Restaurer la sauvegarde';
            document.getElementById('modalMessage').textContent = 
                'Êtes-vous sûr de vouloir restaurer la base de données depuis "' + filename + '" ? ' +
                'Cette action remplacera toutes les données actuelles. Une sauvegarde de sécurité sera créée automatiquement.';
            document.getElementById('confirmButton').onclick = function() {
                restoreBackup(filename);
            };
            document.getElementById('confirmModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('confirmModal').style.display = 'none';
        }
        
        function deleteBackup(filename) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="delete_backup">' +
                           '<input type="hidden" name="filename" value="' + filename + '">';
            document.body.appendChild(form);
            form.submit();
        }
        
        function restoreBackup(filename) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="restore_backup">' +
                           '<input type="hidden" name="filename" value="' + filename + '">';
            document.body.appendChild(form);
            form.submit();
        }
        
        function verifyBackup(filename) {
            // Implémentation de la vérification via AJAX
            fetch('backup_verify.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'filename=' + encodeURIComponent(filename)
            })
            .then(response => response.json())
            .then(data => {
                if (data.valid) {
                    alert('✅ La sauvegarde "' + filename + '" est valide.');
                } else {
                    alert('❌ La sauvegarde "' + filename + '" semble corrompue: ' + (data.error || 'Erreur inconnue'));
                }
            })
            .catch(error => {
                alert('Erreur lors de la vérification: ' + error);
            });
        }
        
        // Fermer la modale en cliquant sur X ou en dehors
        document.querySelector('.close').onclick = closeModal;
        window.onclick = function(event) {
            const modal = document.getElementById('confirmModal');
            if (event.target === modal) {
                closeModal();
            }
        }
        
        // Sélection de toutes les tables
        function selectAllTables() {
            const checkboxes = document.querySelectorAll('input[name="selected_tables[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = true);
        }
        
        function unselectAllTables() {
            const checkboxes = document.querySelectorAll('input[name="selected_tables[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = false);
        }
        
        // Ajouter des boutons de sélection/désélection
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxGroup = document.querySelector('.checkbox-group');
            if (checkboxGroup) {
                const controls = document.createElement('div');
                controls.style.marginBottom = '10px';
                controls.innerHTML = 
                    '<button type="button" onclick="selectAllTables()" class="btn btn-sm btn-secondary">Tout sélectionner</button> ' +
                    '<button type="button" onclick="unselectAllTables()" class="btn btn-sm btn-secondary">Tout désélectionner</button>';
                checkboxGroup.parentNode.insertBefore(controls, checkboxGroup);
            }
        });
    </script>
</body>
</html>