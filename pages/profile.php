<?php
// Inclut les fichiers de configuration de la base de données et d'authentification.
require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/auth.php';

// Démarre la session si elle n'est pas déjà démarrée.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifie si l'utilisateur est connecté. Si non, le redirige vers la page de connexion.
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Récupère le nom d'utilisateur depuis la session.
$username = $_SESSION['user_id'];

// --- Traitement des formulaires classiques ---
$message = "";
$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    switch ($action) {
        case 'update_profile':
            $nom = trim($_POST['nom_complet']);
            if ($nom) {
                $stmt = $pdo->prepare("UPDATE t_utilisateur SET nom_complet=:nom WHERE username=:user");
                $stmt->execute(['nom' => $nom, 'user' => $username]);
                $message = "Profil mis à jour avec succès.";
            }
            break;
        case 'update_password':
            $old = trim($_POST['old_password'] ?? '');
            $new = trim($_POST['new_password'] ?? '');
            
            // Validation des champs
            if (empty($old)) {
                $error = "Veuillez saisir votre ancien mot de passe.";
            } elseif (empty($new)) {
                $error = "Veuillez saisir un nouveau mot de passe.";
            } elseif (strlen($new) < 4) {
                $error = "Le nouveau mot de passe doit contenir au moins 4 caractères.";
            } elseif ($old === $new) {
                $error = "Le nouveau mot de passe doit être différent de l'ancien.";
            } else {
                try {
                    // Récupérer le mot de passe actuel
                    $stmt = $pdo->prepare("SELECT motdepasse FROM t_utilisateur WHERE username = :user");
                    $stmt->execute(['user' => $username]);
                    $hashed = $stmt->fetchColumn();
                    
                    if (!$hashed) {
                        $error = "Utilisateur non trouvé.";
                    } elseif (password_verify($old, $hashed)) {
                        // Ancien mot de passe correct, effectuer la mise à jour
                        $new_hashed = password_hash($new, PASSWORD_BCRYPT);
                        $update_stmt = $pdo->prepare("UPDATE t_utilisateur SET motdepasse = :pwd WHERE username = :user");
                        
                        if ($update_stmt->execute(['pwd' => $new_hashed, 'user' => $username])) {
                            $message = "Mot de passe changé avec succès.";
                            
                            // Log du changement de mot de passe
                            if (file_exists(__DIR__ . '/../includes/login_logger.php')) {
                                require_once __DIR__ . '/../includes/login_logger.php';
                                logLoginAttempt($pdo, $username, true, "Changement de mot de passe réussi");
                            }
                            
                            // Optionnel : invalider tous les tokens remember_me pour forcer une nouvelle connexion
                            $pdo->prepare("UPDATE t_utilisateur SET remember_token = NULL, token_expires = NULL WHERE username = :user")
                                ->execute(['user' => $username]);
                                
                        } else {
                            $error = "Erreur lors de la mise à jour du mot de passe.";
                        }
                    } else {
                        $error = "Ancien mot de passe incorrect.";
                        
                        // Log de la tentative échouée
                        if (file_exists(__DIR__ . '/../includes/login_logger.php')) {
                            require_once __DIR__ . '/../includes/login_logger.php';
                            logLoginAttempt($pdo, $username, false, "Tentative de changement de mot de passe avec ancien mot de passe incorrect");
                        }
                    }
                } catch (PDOException $e) {
                    error_log("Erreur lors du changement de mot de passe pour $username: " . $e->getMessage());
                    $error = "Une erreur est survenue lors du changement de mot de passe.";
                }
            }
            break;
        case 'add_user':
            $u = trim($_POST['new_username']);
            $n = trim($_POST['new_nom']);
            $r = trim($_POST['new_role']);
            $p = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
            
            try {
                $stmt = $pdo->prepare("INSERT INTO t_utilisateur(username,nom_complet,motdepasse,role) VALUES(:u,:n,:p,:r)");
                $stmt->execute(['u' => $u, 'n' => $n, 'p' => $p, 'r' => $r]);
                $message = "Nouvel utilisateur ajouté avec succès.";
            } catch (PDOException $e) {
                $error = "Erreur lors de l'ajout : L'utilisateur existe peut-être déjà.";
            }
            break;
            
        case 'edit_user':
            $target_username = $_POST['target_username'];
            $new_nom = trim($_POST['edit_nom']);
            $new_role = trim($_POST['edit_role']);
            
            if ($target_username && $new_nom && $new_role) {
                try {
                    $stmt = $pdo->prepare("UPDATE t_utilisateur SET nom_complet=:nom, role=:role WHERE username=:user");
                    $stmt->execute(['nom' => $new_nom, 'role' => $new_role, 'user' => $target_username]);
                    $message = "Utilisateur '$target_username' modifié avec succès.";
                } catch (PDOException $e) {
                    $error = "Erreur lors de la modification : " . $e->getMessage();
                }
            } else {
                $error = "Tous les champs sont requis pour la modification.";
            }
            break;
            
        case 'delete_user':
            $target_username = $_POST['target_username'];
            
            // Empêcher la suppression de l'admin et de soi-même
            if ($target_username === 'admin') {
                $error = "Impossible de supprimer le compte administrateur.";
            } elseif ($target_username === $username) {
                $error = "Vous ne pouvez pas supprimer votre propre compte.";
            } elseif ($target_username) {
                try {
                    $pdo->beginTransaction();
                    
                    // Supprimer d'abord les permissions de l'utilisateur
                    $stmt = $pdo->prepare("DELETE FROM t_utilisateur_autorisation WHERE username = ?");
                    $stmt->execute([$target_username]);
                    
                    // Puis supprimer l'utilisateur
                    $stmt = $pdo->prepare("DELETE FROM t_utilisateur WHERE username = ?");
                    $stmt->execute([$target_username]);
                    
                    $pdo->commit();
                    $message = "Utilisateur '$target_username' supprimé avec succès.";
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error = "Erreur lors de la suppression : " . $e->getMessage();
                }
            } else {
                $error = "Nom d'utilisateur requis pour la suppression.";
            }
            break;
            
        case 'reset_password':
            $target_username = $_POST['target_username'];
            $new_password = $_POST['new_password'];
            
            if ($target_username && $new_password) {
                try {
                    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE t_utilisateur SET motdepasse=:pwd WHERE username=:user");
                    $stmt->execute(['pwd' => $hashed_password, 'user' => $target_username]);
                    $message = "Mot de passe de '$target_username' réinitialisé avec succès.";
                } catch (PDOException $e) {
                    $error = "Erreur lors de la réinitialisation : " . $e->getMessage();
                }
            } else {
                $error = "Utilisateur et nouveau mot de passe requis.";
            }
            break;
    }
}

// --- Récupération des informations de l'utilisateur courant ---
$stmt = $pdo->prepare("SELECT username,nom_complet,role FROM t_utilisateur WHERE username=:user");
$stmt->execute(['user' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// --- Récupération des autorisations de l'utilisateur courant ---
if ($username === 'admin') {
    $stmt = $pdo->query("SELECT DISTINCT module,code_permission FROM t_autorisation ORDER BY module,code_permission");
    $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $modules = [];
    foreach ($perms as $p) {
        $modules[$p['module']][$p['code_permission']] = 1;
    }
} else {
    $stmt = $pdo->prepare("SELECT a.module, a.code_permission, ua.est_autorise 
        FROM t_autorisation a
        LEFT JOIN t_utilisateur_autorisation ua
        ON a.id_autorisation = ua.id_autorisation AND ua.username = :user
        ORDER BY a.module, a.code_permission");
    $stmt->execute(['user' => $username]);
    $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $modules = [];
    foreach ($perms as $p) {
        $modules[$p['module']][$p['code_permission']] = $p['est_autorise'];
    }
}

// --- Liste de tous les utilisateurs pour la gestion ---
$allUsers = $pdo->query("SELECT username, nom_complet, role FROM t_utilisateur ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

$selectedUser = $_POST['selected_user'] ?? null;
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil & Gestion Utilisateurs</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: #f0f2f5;
            font-family: "Segoe UI", Roboto, sans-serif;
        }

        .profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: bold;
            color: #6c757d;
            margin: auto;
        }

        /* Styles pour l'onglet audit */
        #audit-tab-btn {
            border: none !important;
            position: relative;
        }
        
        #audit-tab-btn:hover {
            background-color: #fff3cd !important;
        }
        
        #audit-tab-btn.active {
            background-color: #ffc107 !important;
            color: #000 !important;
        }
        
        .audit-table th {
            background-color: #343a40 !important;
            color: white !important;
        }
        
        .badge-action {
            font-size: 0.7em;
            padding: 0.25em 0.5em;
        }
        
        .cote-change {
            font-weight: 500;
        }
        
        .cote-old {
            color: #6c757d;
            text-decoration: line-through;
        }
        
        .cote-new {
            color: #198754;
            font-weight: bold;
        }

        .perm-table td,
        .perm-table th {
            text-align: center;
        }

        .card {
            border-radius: 12px;
        }

        .form-switch .form-check-input {
            cursor: pointer;
        }

        #message-box {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
            opacity: 0;
            transition: opacity 0.5s ease-in-out;
            min-width: 250px;
        }

        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 12px;
            z-index: 10;
        }
    </style>
</head>

<body>
    <div class="container-fluid py-4">
        <!-- Conteneur pour les messages dynamiques -->
        <div id="message-box" class="alert d-flex align-items-center" role="alert"></div>

        <div class="row">
            <!-- Colonne gauche -->
            <div class="col-md-4">
                <div class="card mb-3 shadow-sm text-center">
                    <div class="card-body">
                        <div class="profile-photo mb-3"><?= strtoupper(substr($user['nom_complet'], 0, 2)) ?></div>
                        <h5><?= htmlspecialchars($user['nom_complet']) ?></h5>
                        <p class="text-muted">@<?= htmlspecialchars($user['username']) ?></p>
                        <span class="badge bg-primary">Rôle: <?= htmlspecialchars($user['role']) ?></span>
                    </div>
                </div>
                <div class="card shadow-sm">
                    <div class="card-header">Vos autorisations</div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0 perm-table">
                            <thead>
                                <tr>
                                    <th>Module</th>
                                    <th>S</th>
                                    <th>I</th>
                                    <th>U</th>
                                    <th>D</th>
                                    <th>A</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($modules as $module => $perms): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($module) ?></td>
                                        <?php foreach (['S', 'I', 'U', 'D', 'A'] as $p): ?>
                                            <td>
                                                <?= (isset($perms[$p]) && $perms[$p]) ?
                                                    "<span class='text-success fw-bold'>✔</span>" :
                                                    "<span class='text-danger fw-bold'>✖</span>"
                                                    ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Colonne droite -->
            <div class="col-md-8">
                <ul class="nav nav-tabs" id="profileTab">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab"
                            data-bs-target="#edit-profile">Modifier profil</button></li>
                    <?php if ($username === 'admin'): ?>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab"
                                data-bs-target="#manage-users">Gérer utilisateurs</button></li>
                    <?php endif; ?>
                    <li class="nav-item"><button class="nav-link" id="audit-tab-btn" data-bs-toggle="tab"
                            data-bs-target="#audit-history" onclick="handleAuditTabClick(event)">
                            <i class="bi bi-shield-lock"></i> Audit</button></li>
                </ul>
                <div class="tab-content mt-3">
                    <!-- Modifier profil -->
                    <div class="tab-pane fade show active" id="edit-profile">
                        <div class="card shadow-sm p-3">
                            <h6 class="border-bottom pb-2">Modifier vos informations</h6>
                            <form method="post" class="mb-3">
                                <input type="hidden" name="action" value="update_profile">
                                <label for="nom_complet" class="form-label">Nom complet</label>
                                <input type="text" name="nom_complet" id="nom_complet" class="form-control mb-2"
                                    value="<?= htmlspecialchars($user['nom_complet']) ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Mettre à jour</button>
                            </form>
                            <hr>
                            <h6 class="border-bottom pb-2">Changer votre mot de passe</h6>
                            <form method="post" id="passwordChangeForm">
                                <input type="hidden" name="action" value="update_password">
                                
                                <label for="old_password" class="form-label">Ancien mot de passe</label>
                                <input type="password" name="old_password" id="old_password" class="form-control mb-2"
                                    required minlength="4" autocomplete="current-password">
                                
                                <label for="new_password" class="form-label">Nouveau mot de passe</label>
                                <input type="password" name="new_password" id="new_password" class="form-control mb-2"
                                    required minlength="4" autocomplete="new-password">
                                <small class="form-text text-muted">Minimum 4 caractères</small>
                                
                                <label for="confirm_password" class="form-label">Confirmer le nouveau mot de passe</label>
                                <input type="password" id="confirm_password" class="form-control mb-2"
                                    required minlength="4" autocomplete="new-password">
                                <small class="form-text text-muted">Ressaisissez le nouveau mot de passe</small>
                                
                                <div class="mt-3">
                                    <button type="submit" class="btn btn-warning btn-sm" id="changePasswordBtn">
                                        <i class="bi bi-shield-lock"></i> Changer le mot de passe
                                    </button>
                                </div>
                            </form>
                            
                            <script>
                            document.getElementById('passwordChangeForm').addEventListener('submit', function(e) {
                                const newPassword = document.getElementById('new_password').value;
                                const confirmPassword = document.getElementById('confirm_password').value;
                                
                                if (newPassword !== confirmPassword) {
                                    e.preventDefault();
                                    alert('Les mots de passe ne correspondent pas. Veuillez vérifier.');
                                    document.getElementById('confirm_password').focus();
                                    return false;
                                }
                                
                                if (newPassword.length < 4) {
                                    e.preventDefault();
                                    alert('Le mot de passe doit contenir au moins 4 caractères.');
                                    document.getElementById('new_password').focus();
                                    return false;
                                }
                                
                                // Confirmation avant soumission
                                if (!confirm('Êtes-vous sûr de vouloir changer votre mot de passe ?')) {
                                    e.preventDefault();
                                    return false;
                                }
                            });
                            
                            // Validation en temps réel
                            document.getElementById('confirm_password').addEventListener('input', function() {
                                const newPassword = document.getElementById('new_password').value;
                                const confirmPassword = this.value;
                                const button = document.getElementById('changePasswordBtn');
                                
                                if (newPassword && confirmPassword) {
                                    if (newPassword === confirmPassword) {
                                        this.style.borderColor = '#28a745';
                                        button.disabled = false;
                                    } else {
                                        this.style.borderColor = '#dc3545';
                                        button.disabled = true;
                                    }
                                } else {
                                    this.style.borderColor = '';
                                    button.disabled = false;
                                }
                            });
                            </script>
                        </div>
                    </div>

                    <!-- Gérer utilisateurs -->
                    <?php if ($username === 'admin'): ?>
                        <div class="tab-pane fade" id="manage-users">
                            <div class="row">
                                <!-- Colonne de gauche - Ajouter utilisateur -->
                                <div class="col-md-6">
                                    <div class="card shadow-sm p-3 mb-3">
                                        <h6 class="border-bottom pb-2"><i class="bi bi-person-plus"></i> Ajouter un utilisateur</h6>
                                        <form method="post" class="row g-2">
                                            <input type="hidden" name="action" value="add_user">
                                            <div class="col-12">
                                                <label for="new_username" class="form-label">Nom d'utilisateur</label>
                                                <input type="text" name="new_username" id="new_username" class="form-control"
                                                    placeholder="Ex: jean.dupont" required>
                                            </div>
                                            <div class="col-12">
                                                <label for="new_nom" class="form-label">Nom complet</label>
                                                <input type="text" name="new_nom" id="new_nom" class="form-control"
                                                    placeholder="Ex: Jean Dupont" required>
                                            </div>
                                            <div class="col-12">
                                                <label for="new_role" class="form-label">Rôle</label>
                                                <select name="new_role" id="new_role" class="form-select" required>
                                                    <option value="">-- Sélectionnez un rôle --</option>
                                                    <option value="etudiant">Étudiant</option>
                                                    <option value="professeur">Professeur</option>
                                                    <option value="coordinateur">Coordinateur</option>
                                                    <option value="administrateur">Administrateur</option>
                                                    <option value="secretaire">Secrétaire</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label for="new_password" class="form-label">Mot de passe</label>
                                                <input type="password" name="new_password" id="new_password" class="form-control"
                                                    placeholder="Minimum 6 caractères" required minlength="6">
                                            </div>
                                            <div class="col-12 text-end">
                                                <button type="submit" class="btn btn-success">
                                                    <i class="bi bi-person-plus"></i> Ajouter l'utilisateur
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                
                                <!-- Colonne de droite - Liste des utilisateurs -->
                                <div class="col-md-6">
                                    <div class="card shadow-sm p-3 mb-3">
                                        <h6 class="border-bottom pb-2"><i class="bi bi-people"></i> Utilisateurs existants</h6>
                                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                            <table class="table table-sm table-hover">
                                                <thead class="table-light sticky-top">
                                                    <tr>
                                                        <th>Utilisateur</th>
                                                        <th>Rôle</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($allUsers as $u): ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?= htmlspecialchars($u['nom_complet']) ?></strong><br>
                                                                <small class="text-muted">@<?= htmlspecialchars($u['username']) ?></small>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-secondary"><?= htmlspecialchars($u['role']) ?></span>
                                                            </td>
                                                            <td>
                                                                <div class="btn-group-vertical btn-group-sm" role="group">
                                                                    <!-- Bouton Modifier -->
                                                                    <button type="button" class="btn btn-outline-primary btn-sm" 
                                                                            data-bs-toggle="modal" 
                                                                            data-bs-target="#editUserModal"
                                                                            data-username="<?= htmlspecialchars($u['username']) ?>"
                                                                            data-nom="<?= htmlspecialchars($u['nom_complet']) ?>"
                                                                            data-role="<?= htmlspecialchars($u['role']) ?>"
                                                                            title="Modifier">
                                                                        <i class="bi bi-pencil"></i>
                                                                    </button>
                                                                    
                                                                    <!-- Bouton Réinitialiser mot de passe -->
                                                                    <button type="button" class="btn btn-outline-warning btn-sm" 
                                                                            data-bs-toggle="modal" 
                                                                            data-bs-target="#resetPasswordModal"
                                                                            data-username="<?= htmlspecialchars($u['username']) ?>"
                                                                            data-nom="<?= htmlspecialchars($u['nom_complet']) ?>"
                                                                            title="Réinitialiser mot de passe">
                                                                        <i class="bi bi-key"></i>
                                                                    </button>
                                                                    
                                                                    <!-- Bouton Supprimer (pas pour admin et soi-même) -->
                                                                    <?php if ($u['username'] !== 'admin' && $u['username'] !== $username): ?>
                                                                        <button type="button" class="btn btn-outline-danger btn-sm" 
                                                                                data-bs-toggle="modal" 
                                                                                data-bs-target="#deleteUserModal"
                                                                                data-username="<?= htmlspecialchars($u['username']) ?>"
                                                                                data-nom="<?= htmlspecialchars($u['nom_complet']) ?>"
                                                                                title="Supprimer">
                                                                            <i class="bi bi-trash"></i>
                                                                        </button>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Section des permissions -->
                            <div class="card shadow-sm p-3">
                                <h6 class="border-bottom pb-2"><i class="bi bi-shield-lock"></i> Gérer les autorisations</h6>
                                <select id="user-select" name="selected_user" class="form-select mb-2">
                                    <option value="">-- Sélectionnez un utilisateur pour voir ses permissions --</option>
                                    <?php foreach ($allUsers as $u): ?>
                                        <option value="<?= htmlspecialchars($u['username']) ?>">
                                            <?= htmlspecialchars($u['nom_complet']) ?>
                                            (@<?= htmlspecialchars($u['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="permissions-container">
                                    <!-- Le contenu de la table des permissions sera chargé ici par JavaScript -->
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Onglet Audit (sécurisé) -->
                    <div class="tab-pane fade" id="audit-history">
                        <div class="card shadow-sm p-3">
                            <div class="d-flex align-items-center mb-3">
                                <i class="bi bi-shield-lock me-2 text-warning"></i>
                                <h6 class="border-bottom pb-2 mb-0">Historique des modifications de cotes</h6>
                            </div>
                            
                            <!-- Message d'authentification requis -->
                            <div id="auth-required-message" class="alert alert-warning">
                                <i class="bi bi-lock me-1"></i>
                                <strong>Authentification requise :</strong> 
                                Cliquez sur le bouton ci-dessous pour accéder à l'historique des modifications de cotes.
                                <br><br>
                                <button class="btn btn-warning" onclick="requestAuditAccess()">
                                    <i class="bi bi-unlock"></i> Demander l'accès
                                </button>
                            </div>

                            <!-- Interface après authentification (cachée par défaut) -->
                            <div id="audit-interface" style="display: none;">
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle me-1"></i>
                                    Accès autorisé. Vous pouvez maintenant consulter l'historique des modifications.
                                </div>

                                <!-- Filtres de recherche -->
                                <div class="row g-3 mb-4">
                                    <div class="col-md-3">
                                        <label for="filter-student" class="form-label">Étudiant</label>
                                        <input type="text" id="filter-student" class="form-control" placeholder="Matricule ou nom">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="filter-course" class="form-label">Cours</label>
                                        <input type="text" id="filter-course" class="form-control" placeholder="Code cours">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="filter-user" class="form-label">Utilisateur</label>
                                        <input type="text" id="filter-user" class="form-control" placeholder="Username">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="filter-date" class="form-label">Date</label>
                                        <input type="date" id="filter-date" class="form-control">
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <button class="btn btn-primary btn-sm" onclick="loadAuditHistory()">
                                        <i class="bi bi-search"></i> Rechercher
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm" onclick="clearAuditFilters()">
                                        <i class="bi bi-x-circle"></i> Effacer filtres
                                    </button>
                                </div>

                                <!-- Conteneur pour l'historique -->
                                <div id="audit-history-container">
                                    <div class="text-center text-muted py-4">
                                        <i class="bi bi-search fs-1"></i>
                                        <p class="mt-2">Utilisez les filtres ci-dessus pour rechercher dans l'historique des modifications.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    
    <script>
        /**
         * Affiche un message de notification temporaire.
         * @param {string} message - Le message à afficher.
         * @param {string} type - Le type d'alerte ('success', 'danger', 'warning').
         */
        function showMessage(message, type) {
            const msgBox = document.getElementById('message-box');
            msgBox.textContent = message;
            msgBox.className = `alert d-flex align-items-center alert-${type}`;
            msgBox.style.opacity = 1;
            setTimeout(() => {
                msgBox.style.opacity = 0;
            }, 3000);
        }

        // --- GESTION DES PERMISSIONS DYNAMIQUES (AJAX) ---
        const permissionsContainer = document.getElementById('permissions-container');

        /**
         * Charge et affiche la table des permissions pour l'utilisateur sélectionné.
         * @param {string} username - Le nom d'utilisateur.
         */
        function loadPermissions(username) {
            if (!username) {
                permissionsContainer.innerHTML = ''; // Cache la table si aucun utilisateur n'est sélectionné.
                return;
            }

            // Vérifier si le conteneur existe (seulement pour admin)
            if (!permissionsContainer) {
                return;
            }

            // Affiche un indicateur de chargement.
            permissionsContainer.innerHTML = `
                <div class="d-flex justify-content-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Chargement...</span>
                    </div>
                </div>
            `;

            // Envoie une requête POST pour récupérer les permissions.
            fetch('../pages/get_permissions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `selected_user=${encodeURIComponent(username)}`
            })
                .then(res => {
                    if (!res.ok) {
                        throw new Error('Réponse réseau incorrecte');
                    }
                    return res.json();
                })
                .then(data => {
                    if (data.success) {
                        // Construit la table HTML avec les permissions.
                        let tableHtml = `
                        <h6>Autorisations de ${data.nom_complet}</h6>
                        <table class="table table-sm perm-table">
                            <thead>
                                <tr>
                                    <th>Module</th>
                                    <th>S</th>
                                    <th>I</th>
                                    <th>U</th>
                                    <th>D</th>
                                    <th>A</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;
                        data.permissions.forEach(p => {
                            tableHtml += `
                            <tr data-module="${p.module}" data-username="${data.username}">
                                <td>${p.module}</td>
                                <td class="text-center">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input perm-switch" type="checkbox" data-perm="S" ${p.s_perm ? 'checked' : ''}>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input perm-switch" type="checkbox" data-perm="I" ${p.i_perm ? 'checked' : ''}>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input perm-switch" type="checkbox" data-perm="U" ${p.u_perm ? 'checked' : ''}>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input perm-switch" type="checkbox" data-perm="D" ${p.d_perm ? 'checked' : ''}>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input perm-switch" type="checkbox" data-perm="A" ${p.a_perm ? 'checked' : ''}>
                                    </div>
                                </td>
                            </tr>
                        `;
                        });
                        tableHtml += `</tbody></table>`;
                        permissionsContainer.innerHTML = tableHtml;
                        attachSwitchListeners(); // Attache les écouteurs d'événements aux nouveaux switches.
                    } else {
                        showMessage(data.message || "Erreur lors du chargement des permissions.", 'danger');
                        permissionsContainer.innerHTML = '';
                    }
                })
                .catch(err => {
                    console.error('Erreur fetch:', err);
                    showMessage("Erreur serveur ou réseau lors du chargement.", 'danger');
                    permissionsContainer.innerHTML = '';
                });
        }

        /**
         * Attache les écouteurs d'événements aux interrupteurs de permission.
         */
        function attachSwitchListeners() {
            document.querySelectorAll('.perm-switch').forEach(s => {
                s.addEventListener('change', function () {
                    const tr = this.closest('tr');
                    const module = tr.dataset.module;
                    const selectedUser = tr.dataset.username;
                    const perm = this.dataset.perm;
                    const value = this.checked ? 1 : 0;

                    // Envoie une requête pour mettre à jour la permission.
                    fetch('../pages/update_permissions.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ selectedUser, module, perm, value })
                    })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                showMessage("Mise à jour réussie !", 'success');
                                tr.style.backgroundColor = '#d4edda';
                                setTimeout(() => tr.style.backgroundColor = '', 500);
                            } else {
                                showMessage('Erreur: ' + data.message, 'danger');
                                this.checked = !this.checked; // Annule l'action si échec.
                            }
                        })
                        .catch(err => {
                            console.error('Erreur fetch:', err);
                            showMessage('Erreur serveur ou réseau.', 'danger');
                            this.checked = !this.checked; // Annule l'action si échec.
                        });
                });
            });
        }

        // Écouteur pour le changement de sélection d'utilisateur (seulement pour admin).
        const userSelect = document.getElementById('user-select');
        if (userSelect) {
            userSelect.addEventListener('change', function () {
                loadPermissions(this.value);
            });
        }

        // Gère les messages de succès/erreur au chargement initial.
        const initialMessage = "<?= addslashes($message) ?>";
        const initialError = "<?= addslashes($error) ?>";
        if (initialMessage) {
            showMessage(initialMessage, 'success');
        }
        if (initialError) {
            showMessage(initialError, 'danger');
        }

        // --- GESTION DE L'ONGLET AUDIT SÉCURISÉ ---
        let auditAuthenticated = false;
        const auditPassword = '@Sgac2025';

        /**
         * Gère le clic sur l'onglet audit
         */
        function handleAuditTabClick(event) {
            // Si pas encore authentifié, on empêche l'activation normale de l'onglet
            if (!auditAuthenticated) {
                event.preventDefault();
                requestAuditAccess();
                return false;
            }
        }

        /**
         * Demande l'authentification pour accéder à l'onglet audit
         */
        function requestAuditAccess() {
            // Demande simple avec prompt pour commencer
            const enteredPassword = prompt("🔒 Accès sécurisé à l'historique des modifications\n\nVeuillez saisir le mot de passe d'accès :");
            
            if (enteredPassword === null) {
                // Utilisateur a annulé
                showMessage("Accès annulé", "info");
                return;
            }
            
            if (enteredPassword === auditPassword) {
                auditAuthenticated = true;
                showAuthenticatedInterface();
                showMessage('✅ Accès autorisé à l\'historique des modifications', 'success');
                
                // Charge automatiquement quelques données pour test
                setTimeout(() => {
                    loadAuditHistory();
                }, 500);
            } else {
                showMessage('❌ Mot de passe incorrect. Accès refusé.', 'danger');
            }
        }

        /**
         * Affiche l'interface après authentification réussie
         */
        function showAuthenticatedInterface() {
            document.getElementById('auth-required-message').style.display = 'none';
            document.getElementById('audit-interface').style.display = 'block';
            
            // Active l'onglet audit
            const auditTab = document.getElementById('audit-tab-btn');
            const auditPane = document.getElementById('audit-history');
            
            // Désactive les autres onglets
            document.querySelectorAll('.nav-link').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('show', 'active');
            });
            
            // Active l'onglet audit
            auditTab.classList.add('active');
            auditPane.classList.add('show', 'active');
        }

        /**
         * Charge l'historique des modifications avec les filtres appliqués
         */
        function loadAuditHistory() {
            if (!auditAuthenticated) {
                showMessage('Authentification requise pour accéder à l\'historique', 'warning');
                return;
            }

            const container = document.getElementById('audit-history-container');
            
            // Affichage du loader
            container.innerHTML = `
                <div class="d-flex justify-content-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Chargement de l'historique...</span>
                    </div>
                    <div class="ms-3">
                        <h6 class="mb-0">Chargement de l'historique</h6>
                        <small class="text-muted">Récupération des données...</small>
                    </div>
                </div>
            `;

            // Récupération des filtres
            const filters = {
                access_password: auditPassword,
                student_filter: document.getElementById('filter-student').value.trim(),
                course_filter: document.getElementById('filter-course').value.trim(),
                user_filter: document.getElementById('filter-user').value.trim(),
                date_filter: document.getElementById('filter-date').value
            };

            // Requête AJAX
            fetch('../pages/get_audit_history.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(filters)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Réponse serveur:', data); // Debug
                if (data.success) {
                    displayAuditHistory(data.data, data.total);
                } else {
                    container.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <strong>Information :</strong> ${data.message}
                            <br><br>
                            <button class="btn btn-outline-primary btn-sm" onclick="showTestData()">
                                <i class="bi bi-eye"></i> Afficher des données de test
                            </button>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Erreur AJAX:', error);
                container.innerHTML = `
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Mode démo :</strong> Impossible de se connecter au serveur d'audit.
                        <br><br>
                        <button class="btn btn-primary btn-sm" onclick="showTestData()">
                            <i class="bi bi-play"></i> Afficher des données de démonstration
                        </button>
                    </div>
                `;
            });
        }

        /**
         * Affiche des données de test pour démontrer l'interface
         */
        function showTestData() {
            const testData = [
                {
                    id_audit: 1,
                    action_type: 'UPDATE',
                    date_modification: '14/09/2025 10:30:15',
                    etudiant: 'MUKENDI KABASELE John',
                    matricule: 'ETU20250001',
                    cours: 'MATH101 - Mathématiques générales',
                    ue: 'UE01 - Sciences exactes',
                    modificateur: 'Admin Système (admin)',
                    ancienne_cote_s1: 12,
                    nouvelle_cote_s1: 15,
                    ancienne_cote_s2: 10,
                    nouvelle_cote_s2: 14,
                    commentaire: 'Correction après vérification'
                },
                {
                    id_audit: 2,
                    action_type: 'INSERT',
                    date_modification: '14/09/2025 09:15:30',
                    etudiant: 'KALALA MBUYI Marie',
                    matricule: 'ETU20250002',
                    cours: 'PHYS102 - Physique appliquée',
                    ue: 'UE02 - Sciences physiques',
                    modificateur: 'Professeur Martin (prof_martin)',
                    ancienne_cote_s1: null,
                    nouvelle_cote_s1: 16,
                    ancienne_cote_s2: null,
                    nouvelle_cote_s2: 18,
                    commentaire: 'Première saisie des notes'
                }
            ];

            displayAuditHistory(testData, testData.length);
            
            // Afficher un message d'information
            document.querySelector('#audit-history-container').insertAdjacentHTML('afterbegin', `
                <div class="alert alert-info mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Données de démonstration :</strong> Voici un aperçu de l'interface d'audit avec des données fictives.
                </div>
            `);
        }

        /**
         * Affiche l'historique des modifications dans un tableau
         */
        function displayAuditHistory(records, total) {
            const container = document.getElementById('audit-history-container');
            
            if (records.length === 0) {
                container.innerHTML = `
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-1"></i>
                        Aucune modification trouvée avec les critères de recherche spécifiés.
                    </div>
                `;
                return;
            }

            let tableHTML = `
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Historique des modifications (${total} entrée(s))</h6>
                    <button class="btn btn-outline-success btn-sm" onclick="exportAuditHistory()">
                        <i class="bi bi-download"></i> Exporter
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm">
                        <thead class="table-dark">
                            <tr>
                                <th>Date/Heure</th>
                                <th>Action</th>
                                <th>Étudiant</th>
                                <th>Cours</th>
                                <th>S1 (Ancien→Nouveau)</th>
                                <th>S2 (Ancien→Nouveau)</th>
                                <th>Modificateur</th>
                                <th>Détails</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            records.forEach(record => {
                // Formatage des cotes
                const s1Change = formatCoteChange(record.ancienne_cote_s1, record.nouvelle_cote_s1);
                const s2Change = formatCoteChange(record.ancienne_cote_s2, record.nouvelle_cote_s2);
                
                // Badge pour le type d'action
                const actionBadge = getActionBadge(record.action_type);

                tableHTML += `
                    <tr>
                        <td class="text-nowrap">${record.date_modification}</td>
                        <td>${actionBadge}</td>
                        <td>
                            <div class="fw-bold">${record.etudiant}</div>
                            <small class="text-muted">${record.matricule}</small>
                        </td>
                        <td>
                            <div class="fw-bold">${record.cours}</div>
                            <small class="text-muted">${record.ue}</small>
                        </td>
                        <td>${s1Change}</td>
                        <td>${s2Change}</td>
                        <td>
                            <div class="fw-bold">${record.modificateur}</div>
                        </td>
                        <td>
                            <button class="btn btn-outline-info btn-sm" onclick="showRecordDetails(${record.id_audit}, '${record.action_type}')">
                                <i class="bi bi-eye"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });

            tableHTML += `
                        </tbody>
                    </table>
                </div>
            `;

            container.innerHTML = tableHTML;
        }

        /**
         * Formate l'affichage des changements de cotes
         */
        function formatCoteChange(oldValue, newValue) {
            if (oldValue === null && newValue !== null) {
                return `<span class="badge bg-success">+${newValue}</span>`;
            } else if (oldValue !== null && newValue === null) {
                return `<span class="badge bg-danger">-${oldValue}</span>`;
            } else if (oldValue !== newValue) {
                return `<span class="text-muted">${oldValue}</span> → <span class="fw-bold text-primary">${newValue}</span>`;
            } else {
                return `<span class="text-muted">${newValue || 'N/A'}</span>`;
            }
        }

        /**
         * Retourne le badge approprié pour le type d'action
         */
        function getActionBadge(actionType) {
            switch (actionType) {
                case 'INSERT':
                    return '<span class="badge bg-success">Ajout</span>';
                case 'UPDATE':
                    return '<span class="badge bg-warning">Modification</span>';
                case 'DELETE':
                    return '<span class="badge bg-danger">Suppression</span>';
                case 'CURRENT':
                    return '<span class="badge bg-info">Actuel</span>';
                default:
                    return '<span class="badge bg-secondary">Inconnu</span>';
            }
        }

        /**
         * Efface tous les filtres de recherche
         */
        function clearAuditFilters() {
            document.getElementById('filter-student').value = '';
            document.getElementById('filter-course').value = '';
            document.getElementById('filter-user').value = '';
            document.getElementById('filter-date').value = '';
            
            // Recharge l'historique sans filtres
            if (auditAuthenticated) {
                loadAuditHistory();
            }
        }

        /**
         * Exporte l'historique en CSV (fonction placeholder)
         */
        function exportAuditHistory() {
            showMessage('Fonction d\'export en cours de développement', 'info');
        }

        /**
         * Affiche les détails complets d'un enregistrement
         */
        function showRecordDetails(auditId, actionType) {
            showMessage(`Détails pour l'enregistrement ${auditId} (${actionType})`, 'info');
        }
    </script>

    <!-- Modales pour la gestion des utilisateurs -->
    
    <!-- Modal Modifier Utilisateur -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editUserModalLabel">
                        <i class="bi bi-pencil"></i> Modifier un utilisateur
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="target_username" id="edit_target_username">
                        
                        <div class="mb-3">
                            <label for="edit_username_display" class="form-label">Nom d'utilisateur</label>
                            <input type="text" id="edit_username_display" class="form-control" readonly>
                            <small class="text-muted">Le nom d'utilisateur ne peut pas être modifié</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_nom" class="form-label">Nom complet</label>
                            <input type="text" name="edit_nom" id="edit_nom" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_role" class="form-label">Rôle</label>
                            <select name="edit_role" id="edit_role" class="form-select" required>
                                <option value="etudiant">Étudiant</option>
                                <option value="professeur">Professeur</option>
                                <option value="coordinateur">Coordinateur</option>
                                <option value="administrateur">Administrateur</option>
                                <option value="secretaire">Secrétaire</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check"></i> Sauvegarder les modifications
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Réinitialiser Mot de Passe -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="resetPasswordModalLabel">
                        <i class="bi bi-key"></i> Réinitialiser le mot de passe
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="target_username" id="reset_target_username">
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Attention !</strong> Cette action va changer le mot de passe de l'utilisateur 
                            <strong id="reset_user_display"></strong>.
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_password" class="form-label">Nouveau mot de passe</label>
                            <input type="password" name="new_password" id="new_password_reset" class="form-control" 
                                   required minlength="6" placeholder="Minimum 6 caractères">
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirmer le mot de passe</label>
                            <input type="password" id="confirm_password" class="form-control" 
                                   required minlength="6" placeholder="Répétez le mot de passe">
                            <div class="invalid-feedback">
                                Les mots de passe ne correspondent pas.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-warning" id="confirmResetPassword">
                            <i class="bi bi-key"></i> Réinitialiser le mot de passe
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Supprimer Utilisateur -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteUserModalLabel">
                        <i class="bi bi-trash"></i> Supprimer un utilisateur
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="target_username" id="delete_target_username">
                        
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>ATTENTION - Action irréversible !</strong>
                        </div>
                        
                        <p>Vous êtes sur le point de supprimer définitivement l'utilisateur :</p>
                        <div class="text-center p-3 bg-light rounded">
                            <strong id="delete_user_display" class="text-danger"></strong>
                        </div>
                        
                        <div class="mt-3">
                            <h6>Cette action va :</h6>
                            <ul>
                                <li>Supprimer définitivement le compte utilisateur</li>
                                <li>Supprimer toutes ses permissions</li>
                                <li>Rendre impossible toute connexion future</li>
                            </ul>
                        </div>
                        
                        <div class="mt-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="confirmDelete" required>
                                <label class="form-check-label text-danger" for="confirmDelete">
                                    <strong>Je comprends que cette action est irréversible</strong>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger" id="confirmDeleteBtn" disabled>
                            <i class="bi bi-trash"></i> Supprimer définitivement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // JavaScript pour gérer les modales
        
        // Modal Modifier Utilisateur
        document.getElementById('editUserModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const username = button.getAttribute('data-username');
            const nom = button.getAttribute('data-nom');
            const role = button.getAttribute('data-role');
            
            document.getElementById('edit_target_username').value = username;
            document.getElementById('edit_username_display').value = username;
            document.getElementById('edit_nom').value = nom;
            document.getElementById('edit_role').value = role;
        });

        // Modal Réinitialiser Mot de Passe
        document.getElementById('resetPasswordModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const username = button.getAttribute('data-username');
            const nom = button.getAttribute('data-nom');
            
            document.getElementById('reset_target_username').value = username;
            document.getElementById('reset_user_display').textContent = nom + ' (@' + username + ')';
            
            // Reset form
            document.getElementById('new_password_reset').value = '';
            document.getElementById('confirm_password').value = '';
        });

        // Validation des mots de passe
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password_reset').value;
            const confirmPassword = this.value;
            const submitBtn = document.getElementById('confirmResetPassword');
            
            if (newPassword !== confirmPassword) {
                this.classList.add('is-invalid');
                submitBtn.disabled = true;
            } else {
                this.classList.remove('is-invalid');
                submitBtn.disabled = false;
            }
        });

        // Modal Supprimer Utilisateur
        document.getElementById('deleteUserModal').addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const username = button.getAttribute('data-username');
            const nom = button.getAttribute('data-nom');
            
            document.getElementById('delete_target_username').value = username;
            document.getElementById('delete_user_display').textContent = nom + ' (@' + username + ')';
            
            // Reset checkbox
            document.getElementById('confirmDelete').checked = false;
            document.getElementById('confirmDeleteBtn').disabled = true;
        });

        // Gérer la case à cocher de confirmation de suppression
        document.getElementById('confirmDelete').addEventListener('change', function() {
            document.getElementById('confirmDeleteBtn').disabled = !this.checked;
        });
    </script>
</body>

</html>