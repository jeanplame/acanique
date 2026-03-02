<?php
/**
 * Gestionnaire d'utilisateurs adapté à la vraie structure DB
 */

session_start();
require_once 'includes/db_config.php';
require_once 'includes/auth.php';

// Vérifier que l'utilisateur a accès à la gestion des utilisateurs
requirePermission('Utilisateurs', 'S');

// Traitement des actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_user':
            if (hasPermission($pdo, $_SESSION['user_id'], 'Utilisateurs', 'I')) {
                $username = trim($_POST['username']);
                $nom_complet = trim($_POST['nom_complet']);
                $role = $_POST['role'];
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                
                try {
                    $stmt = $pdo->prepare("INSERT INTO t_utilisateur (username, nom_complet, motdepasse, role) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$username, $nom_complet, $password, $role]);
                    $message = "Utilisateur ajouté avec succès";
                } catch (Exception $e) {
                    $error = "Erreur lors de l'ajout : " . $e->getMessage();
                }
            } else {
                $error = "Permission insuffisante pour ajouter des utilisateurs";
            }
            break;
            
        case 'edit_user':
            if (hasPermission($pdo, $_SESSION['user_id'], 'Utilisateurs', 'U')) {
                $username = $_POST['username'];
                $nom_complet = trim($_POST['nom_complet']);
                $role = $_POST['role'];
                
                try {
                    $stmt = $pdo->prepare("UPDATE t_utilisateur SET nom_complet = ?, role = ? WHERE username = ?");
                    $stmt->execute([$nom_complet, $role, $username]);
                    $message = "Utilisateur modifié avec succès";
                } catch (Exception $e) {
                    $error = "Erreur lors de la modification : " . $e->getMessage();
                }
            } else {
                $error = "Permission insuffisante pour modifier des utilisateurs";
            }
            break;
            
        case 'delete_user':
            if (hasPermission($pdo, $_SESSION['user_id'], 'Utilisateurs', 'D')) {
                $username = $_POST['username'];
                
                if ($username === 'admin') {
                    $error = "Impossible de supprimer l'administrateur";
                } else {
                    try {
                        $pdo->beginTransaction();
                        
                        // Supprimer d'abord les permissions
                        $stmt = $pdo->prepare("DELETE FROM t_utilisateur_autorisation WHERE username = ?");
                        $stmt->execute([$username]);
                        
                        // Puis l'utilisateur
                        $stmt = $pdo->prepare("DELETE FROM t_utilisateur WHERE username = ?");
                        $stmt->execute([$username]);
                        
                        $pdo->commit();
                        $message = "Utilisateur supprimé avec succès";
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = "Erreur lors de la suppression : " . $e->getMessage();
                    }
                }
            } else {
                $error = "Permission insuffisante pour supprimer des utilisateurs";
            }
            break;
            
        case 'reset_password':
            if (hasPermission($pdo, $_SESSION['user_id'], 'Utilisateurs', 'U')) {
                $username = $_POST['username'];
                $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                
                try {
                    $stmt = $pdo->prepare("UPDATE t_utilisateur SET motdepasse = ? WHERE username = ?");
                    $stmt->execute([$new_password, $username]);
                    $message = "Mot de passe réinitialisé avec succès";
                } catch (Exception $e) {
                    $error = "Erreur lors de la réinitialisation : " . $e->getMessage();
                }
            } else {
                $error = "Permission insuffisante pour réinitialiser les mots de passe";
            }
            break;
            
        case 'update_permissions':
            if (hasPermission($pdo, $_SESSION['user_id'], 'Utilisateurs', 'A')) {
                $username = $_POST['username'];
                $permissions = $_POST['permissions'] ?? [];
                
                try {
                    $pdo->beginTransaction();
                    
                    // Supprimer les anciennes permissions
                    $stmt = $pdo->prepare("DELETE FROM t_utilisateur_autorisation WHERE username = ?");
                    $stmt->execute([$username]);
                    
                    // Ajouter les nouvelles permissions
                    $stmt = $pdo->prepare("INSERT INTO t_utilisateur_autorisation (username, id_autorisation, est_autorise) VALUES (?, ?, 1)");
                    foreach ($permissions as $id_auth) {
                        $stmt->execute([$username, $id_auth]);
                    }
                    
                    $pdo->commit();
                    $message = "Permissions mises à jour avec succès";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Erreur lors de la mise à jour des permissions : " . $e->getMessage();
                }
            } else {
                $error = "Permission insuffisante pour gérer les permissions";
            }
            break;
    }
}

// Récupérer la liste des utilisateurs
try {
    $stmt = $pdo->query("SELECT * FROM t_utilisateur ORDER BY username");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des utilisateurs : " . $e->getMessage();
    $users = [];
}

// Récupérer les autorisations disponibles
try {
    $stmt = $pdo->query("SELECT * FROM t_autorisation ORDER BY module, code_permission");
    $autorisations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $autorisations = [];
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Utilisateurs</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h2>👥 Gestion des Utilisateurs</h2>
        
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Bouton d'ajout -->
        <?php if (hasPermission($pdo, $_SESSION['user_id'], 'Utilisateurs', 'I')): ?>
        <div class="mb-3">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                ➕ Ajouter un utilisateur
            </button>
        </div>
        <?php endif; ?>
        
        <!-- Liste des utilisateurs -->
        <div class="card">
            <div class="card-header">
                <h5>👤 Liste des Utilisateurs</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Nom Complet</th>
                                <th>Rôle</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                                <td><?= htmlspecialchars($user['nom_complet']) ?></td>
                                <td>
                                    <span class="badge bg-info"><?= htmlspecialchars($user['role']) ?></span>
                                </td>
                                <td>
                                    <?php if (hasPermission($pdo, $_SESSION['user_id'], 'Utilisateurs', 'U')): ?>
                                    <button class="btn btn-sm btn-outline-primary" onclick="editUser('<?= $user['username'] ?>', '<?= htmlspecialchars($user['nom_complet']) ?>', '<?= $user['role'] ?>')">
                                        ✏️ Modifier
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if (hasPermission($pdo, $_SESSION['user_id'], 'Utilisateurs', 'A')): ?>
                                    <button class="btn btn-sm btn-outline-success" onclick="managePermissions('<?= $user['username'] ?>')">
                                        🔑 Permissions
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if (hasPermission($pdo, $_SESSION['user_id'], 'Utilisateurs', 'U')): ?>
                                    <button class="btn btn-sm btn-outline-warning" onclick="resetPassword('<?= $user['username'] ?>')">
                                        🔐 Mot de passe
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if (hasPermission($pdo, $_SESSION['user_id'], 'Utilisateurs', 'D') && $user['username'] !== 'admin'): ?>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteUser('<?= $user['username'] ?>')">
                                        🗑️ Supprimer
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-secondary">🔙 Retour au tableau de bord</a>
        </div>
    </div>

    <!-- Modal Ajouter Utilisateur -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">➕ Ajouter un Utilisateur</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_user">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nom Complet</label>
                            <input type="text" name="nom_complet" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rôle</label>
                            <select name="role" class="form-control" required>
                                <option value="user">Utilisateur</option>
                                <option value="admin">Administrateur</option>
                                <option value="gestionnaire">Gestionnaire</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mot de passe</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modifier Utilisateur -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">✏️ Modifier un Utilisateur</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="username" id="edit_username">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" id="edit_username_display" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nom Complet</label>
                            <input type="text" name="nom_complet" id="edit_nom_complet" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rôle</label>
                            <select name="role" id="edit_role" class="form-control" required>
                                <option value="user">Utilisateur</option>
                                <option value="admin">Administrateur</option>
                                <option value="gestionnaire">Gestionnaire</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Modifier</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Permissions -->
    <div class="modal fade" id="permissionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">🔑 Gérer les Permissions</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_permissions">
                        <input type="hidden" name="username" id="perm_username">
                        <div id="permissions_list">
                            <?php
                            $modules = [];
                            foreach ($autorisations as $auth) {
                                $modules[$auth['module']][] = $auth;
                            }
                            
                            foreach ($modules as $module => $auths): ?>
                            <div class="mb-3">
                                <h6><?= htmlspecialchars($module) ?></h6>
                                <div class="row">
                                    <?php foreach ($auths as $auth): ?>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" 
                                                   name="permissions[]" 
                                                   value="<?= $auth['id_autorisation'] ?>"
                                                   id="perm_<?= $auth['id_autorisation'] ?>">
                                            <label class="form-check-label" for="perm_<?= $auth['id_autorisation'] ?>">
                                                <strong><?= $auth['code_permission'] ?></strong> - <?= $auth['description'] ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success">Mettre à jour</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js"></script>
    <script>
        function editUser(username, nom_complet, role) {
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_username_display').value = username;
            document.getElementById('edit_nom_complet').value = nom_complet;
            document.getElementById('edit_role').value = role;
            
            var modal = new bootstrap.Modal(document.getElementById('editUserModal'));
            modal.show();
        }
        
        function deleteUser(username) {
            if (confirm('Êtes-vous sûr de vouloir supprimer l\'utilisateur "' + username + '" ?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="delete_user">' +
                               '<input type="hidden" name="username" value="' + username + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function resetPassword(username) {
            var password = prompt('Nouveau mot de passe pour ' + username + ' :');
            if (password) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="reset_password">' +
                               '<input type="hidden" name="username" value="' + username + '">' +
                               '<input type="hidden" name="new_password" value="' + password + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function managePermissions(username) {
            document.getElementById('perm_username').value = username;
            
            // Charger les permissions actuelles via AJAX serait mieux,
            // mais pour simplifier, on va juste ouvrir le modal
            var modal = new bootstrap.Modal(document.getElementById('permissionsModal'));
            modal.show();
        }
    </script>
</body>
</html>