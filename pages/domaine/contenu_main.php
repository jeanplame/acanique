<?php
    header('Content-Type: text/html; charset=UTF-8');
// Démarrage de la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inclusions des fichiers nécessaires
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions_config.php';
require_once __DIR__ . '/../../includes/db_config.php';

// Variables de session et paramètres
$username = isset($_SESSION['user_id']) ? trim($_SESSION['user_id']) : '';
$requested_tab = $_GET['tab'] ?? 'inscriptions';
$id_domaine = $_GET['id'] ?? null;

// Debug: Log du nom d'utilisateur pour diagnostic
if ($username) {
    error_log("contenu_main.php: Username = '$username', Is admin = " . ($username === 'admin' ? 'YES' : 'NO'));
}

// Configuration des permissions par onglet
$tabPermissions = [
    'inscriptions' => ['module' => 'Inscriptions', 'perm' => 'S'],
    'ue' => ['module' => 'Cours', 'perm' => 'S'],
    'notes' => ['module' => 'Cotes', 'perm' => 'S'],
    'cotation' => ['module' => 'Cotes', 'perm' => 'U'],
    'deliberation' => ['module' => 'Cotes', 'perm' => 'A'],
];

// Déterminer les onglets disponibles pour l'utilisateur
$available_tabs = [];

// L'admin a accès à tous les onglets - Utiliser la même logique que hasPermission()
$is_admin = (trim(strtolower($username)) === 'admin');
if ($is_admin) {
    $available_tabs = array_keys($tabPermissions);
    error_log("contenu_main.php: Admin detected (username='$username', trimmed='" . trim(strtolower($username)) . "') - All tabs granted: " . implode(', ', $available_tabs));
} else {
    error_log("contenu_main.php: Non-admin user '$username' - Checking individual permissions");
    foreach ($tabPermissions as $tab => $permission) {
        $hasPermission = hasPermission($pdo, $username, $permission['module'], $permission['perm']);
        if ($hasPermission) {
            $available_tabs[] = $tab;
        }
        error_log("contenu_main.php: Tab '$tab' ({$permission['module']}-{$permission['perm']}): " . ($hasPermission ? 'GRANTED' : 'DENIED'));
    }
}

// Si l'onglet demandé n'est pas disponible, rediriger vers le premier disponible
$active_tab = $requested_tab;
if (!in_array($requested_tab, $available_tabs)) {
    if (!empty($available_tabs)) {
        $active_tab = $available_tabs[0];
        // Rediriger pour éviter la confusion dans l'URL
        $redirect_url = "?page=domaine&action=view&id=" . $id_domaine;
        if (isset($_GET['mention'])) $redirect_url .= "&mention=" . $_GET['mention'];
        if (isset($_GET['promotion'])) $redirect_url .= "&promotion=" . $_GET['promotion'];
        $redirect_url .= "&tab=" . $active_tab;
        
        header("Location: " . $redirect_url);
        exit;
    } else {
        // Aucun onglet disponible, afficher une erreur
        $active_tab = null;
    }
}

// Vérification des permissions pour l'onglet actif
$has_tab_permission = true;
$permission_error_message = '';

if ($active_tab && isset($tabPermissions[$active_tab])) {
    $module = $tabPermissions[$active_tab]['module'];
    $perm = $tabPermissions[$active_tab]['perm'];
    
    // L'admin a toujours les permissions
    if ($username === 'admin') {
        $has_tab_permission = true;
    } elseif (!hasPermission($pdo, $username, $module, $perm)) {
        $has_tab_permission = false;
        $permission_error_message = "Vous n'avez pas l'autorisation nécessaire pour accéder à l'onglet <strong>" . ucfirst($active_tab) . "</strong>. 
                                   (Permission requise : $module - $perm)";
    }
} elseif ($active_tab === null) {
    $has_tab_permission = false;
    $permission_error_message = "Vous n'avez accès à aucun onglet de cette section.";
}


if (!$username) {
    die('<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Vous devez être connecté pour accéder à cette page.</div>');
}


// Assurez-vous que les variables de l'URL sont définies, sinon utilisez des valeurs par défaut
$annee_academique = $_GET['annee'] ?? $id_annee ?? null;
$mention_id = $_GET['mention'] ?? null;
$promotion_code = $_GET['promotion'] ?? null;

// Si annee_academique n'est pas défini dans l'URL mais $id_annee existe (depuis view.php)
if (!$annee_academique && isset($id_annee)) {
    $annee_academique = $id_annee;
}

// Récupération sécurisée de l'utilisateur connecté
$username = isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') : '';
$nom_complet = isset($_SESSION['nom_complet']) ? htmlspecialchars($_SESSION['nom_complet'], ENT_QUOTES, 'UTF-8') : '';
?>


<div class="col-md-8 col-lg-9 vh-100 overflow-auto">
    <?php if ($mention_id): ?>
        <?php
        // Récupérer les détails complets de la mention et filtrer par promotion si elle est sélectionnée
        $sql = "
            SELECT 
                m.*,
                f.nomFiliere,
                f.code_filiere,
                COUNT(DISTINCT ue.id_ue) as total_ue,
                COUNT(DISTINCT ec.id_ec) as total_ec,
                SUM(ue.credits) as total_credits,
                COUNT(DISTINCT i.id_inscription) as total_inscrits,
                COUNT(DISTINCT CASE WHEN i.statut = 'Actif' THEN i.id_inscription END) as inscrits_actifs,
                (SELECT semestre FROM t_unite_enseignement ue2 
                 INNER JOIN t_mention_ue mu2 ON ue2.id_ue = mu2.id_ue 
                 WHERE mu2.id_mention = m.id_mention 
                 ORDER BY semestre DESC LIMIT 1) as dernier_semestre
            FROM t_mention m 
            INNER JOIN t_filiere f ON m.idFiliere = f.idFiliere
            LEFT JOIN t_mention_ue mu ON m.id_mention = mu.id_mention
            LEFT JOIN t_unite_enseignement ue ON mu.id_ue = ue.id_ue AND ue.is_programmed = 1
            LEFT JOIN t_element_constitutif ec ON ue.id_ue = ec.id_ue AND ec.is_programmed = 1
            LEFT JOIN t_inscription i ON m.id_mention = i.id_mention
                AND i.id_annee = ?
        ";

        // Si une promotion est sélectionnée, ajoutez une clause WHERE pour la requête principale
        if ($promotion_code) {
            $sql .= " AND i.code_promotion = ?";
        }

        $sql .= "
            WHERE m.id_mention = ?
            GROUP BY m.id_mention, f.idFiliere
        ";

        $stmt = $pdo->prepare($sql);

        // Exécution de la requête avec les paramètres appropriés
        if ($promotion_code) {
            $stmt->execute([$annee_academique, $promotion_code, $mention_id]);
        } else {
            $stmt->execute([$annee_academique, $mention_id]);
        }

        $mention = $stmt->fetch(PDO::FETCH_ASSOC);

        // Récupérer toutes les promotions de la filière pour le sélecteur principal
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.* FROM t_promotion p 
            INNER JOIN t_filiere_promotion fp ON p.code_promotion = fp.code_promotion 
            WHERE fp.id_filiere = ?
            ORDER BY p.nom_promotion
        ");
        $stmt->execute([$mention['idFiliere']]);
        $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div class="p-3">
            <!-- En-tête de la mention avec statistiques -->
            <!-- Onglets avec icônes -->
            <ul class="nav nav-tabs mb-3">
                <!-- Onglet Inscriptions -->
                <?php 
                $inscriptions_permission = ($username === 'admin') ? true : hasPermission($pdo, $username, 'Inscriptions', 'S');
                if ($inscriptions_permission): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'inscriptions' ? 'active' : ''; ?>"
                            href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&annee=<?php echo $annee_academique; ?>&tab=inscriptions<?php echo $promotion_code ? '&promotion=' . $promotion_code : ''; ?>">
                            <i class="bi bi-person-plus"></i> Inscriptions
                        </a>
                    </li>
                <?php endif; ?>

                <!-- Onglet Unités d'enseignement -->
                <?php 
                $ue_permission = ($username === 'admin') ? true : hasPermission($pdo, $username, 'Cours', 'S');
                if ($ue_permission): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'ue' ? 'active' : ''; ?>"
                            href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&annee=<?php echo $annee_academique; ?>&tab=ue<?php echo $promotion_code ? '&promotion=' . $promotion_code : ''; ?>">
                            <i class="bi bi-book"></i> Unités d'enseignement
                        </a>
                    </li>
                <?php endif; ?>

                <!-- Onglet Notes -->
                <?php 
                $notes_permission = ($username === 'admin') ? true : hasPermission($pdo, $username, 'Cotes', 'S');
                if ($notes_permission): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'notes' ? 'active' : ''; ?>"
                            href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&annee=<?php echo $annee_academique; ?>&tab=notes<?php echo $promotion_code ? '&promotion=' . $promotion_code : ''; ?>">
                            <i class="bi bi-clipboard-data"></i> Notes
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <!-- Message si aucun onglet disponible -->
            <?php if (empty($available_tabs)): ?>
                <div class="alert alert-warning">
                    <h4 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> Aucun contenu disponible</h4>
                    <p>Vous n'avez accès à aucun onglet de cette section.</p>
                    <hr>
                    <p class="mb-0">Permissions requises :</p>
                    <ul class="mb-0">
                        <li><strong>Inscriptions - Lecture (S)</strong> pour voir les inscriptions</li>
                        <li><strong>Cours - Lecture (S)</strong> pour voir les unités d'enseignement</li>
                        <li><strong>Cotes - Lecture (S)</strong> pour voir les notes</li>
                    </ul>
                </div>
            <?php else: ?>
                <!-- Contenu des onglets -->
                <div class="tab-content">
                    <?php if (!$has_tab_permission): ?>
                        <div class="alert alert-danger">
                            <h4 class="alert-heading"><i class="bi bi-shield-exclamation"></i> Accès refusé</h4>
                            <p><?php echo $permission_error_message; ?></p>
                            <hr>
                            <p class="mb-0">Contactez votre administrateur pour obtenir les permissions nécessaires.</p>
                        </div>
                    <?php else: ?>
                        <?php if ($active_tab === 'inscriptions'): ?>
                            <?php include 'tabs/inscriptions.php'; ?>
                        <?php elseif ($active_tab === 'ue'): ?>
                            <?php include 'tabs/ue.php'; ?>
                        <?php elseif ($active_tab === 'notes'): ?>
                            <?php include 'tabs/notes.php'; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>


        <!-- Afficher le formulaire de filière si filiere=add passe dans l'url -->
        <?php if (isset($_GET['filiere']) && $_GET['filiere'] === 'add'): ?>
            <div class="form-container p-5">
                <?php
                // Fonction pour traiter l'ajout de filière
                function processAddFiliere($pdo, $id_domaine, $postData, $username) {
                    $errors = [];
                    $success = false;
                    
                    $nomFiliere = trim($postData['nomFiliere'] ?? '');
                    $codeFiliere = trim($postData['codeFiliere'] ?? '');
                    $description = trim($postData['description'] ?? '');
                    
                    // Validation des données
                    if (empty($nomFiliere)) $errors[] = "Le nom de la filière est requis.";
                    if (empty($codeFiliere)) $errors[] = "Le code de la filière est requis.";
                    if (empty($description)) $errors[] = "La description est requise.";
                    // if (empty($username)) $errors[] = "Utilisateur non authentifié.";
                    if ($id_domaine === 0) $errors[] = "Domaine invalide.";
                    
                    // Vérifier l'unicité du code filière
                    if (empty($errors)) {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM t_filiere WHERE code_filiere = ?");
                        $stmt->execute([$codeFiliere]);
                        if ($stmt->fetchColumn() > 0) {
                            $errors[] = "Ce code de filière existe déjà.";
                        }
                    }
                    
                    // Insertion si pas d'erreurs
                    if (empty($errors)) {
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO t_filiere (code_filiere, id_domaine, username, nomFiliere, description) 
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$codeFiliere, $id_domaine, $username, $nomFiliere, $description]);
                            $success = true;
                        } catch (PDOException $e) {
                            $errors[] = "Erreur lors de l'enregistrement : " . $e->getMessage();
                        }
                    }
                    
                    return ['success' => $success, 'errors' => $errors];
                }

                // Récupération de l'id_domaine depuis l'URL
                $id_domaine = isset($_GET['id']) ? (int) $_GET['id'] : 0;
                $errors = [];
                $success = false;

                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $result = processAddFiliere($pdo, $id_domaine, $_POST, $username);
                    $errors = $result['errors'];
                    $success = $result['success'];
                    
                    if ($success) {
                        echo "<script src='../../js/sweetalert2.all.min.js'></script>";
                        echo "<script>
                            Swal.fire({
                                title: 'Succès!',
                                text: 'La filière a été enregistrée avec succès',
                                icon: 'success',
                                showConfirmButton: false,
                                timer: 1500,
                                willClose: () => {
                                    window.location.href = '?page=domaine&action=view&id=" . $id_domaine . "';
                                }
                            });
                        </script>";
                        exit();
                    }
                }

                // Affichage des erreurs
                if (!empty($errors)) {
                    echo "<div class='alert alert-danger'>";
                    echo "<ul class='mb-0'>";
                    foreach ($errors as $error) {
                        echo "<li>" . htmlspecialchars($error) . "</li>";
                    }
                    echo "</ul>";
                    echo "</div>";
                }
                ?>
                <h3><i class="bi bi-plus-circle"></i> Ajouter une nouvelle filière</h3>
                <p class="text-muted">Veuillez remplir le formulaire ci-dessous pour ajouter une nouvelle filière au domaine sélectionné.</p>
                
                <form method="POST" action="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&filiere=add" novalidate>
                    <div class="mb-3">
                        <label for="nomFiliere" class="form-label">
                            <i class="bi bi-tag"></i> Nom de la filière <span class="text-danger">*</span>
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="nomFiliere" 
                               name="nomFiliere" 
                               value="<?php echo htmlspecialchars($_POST['nomFiliere'] ?? '', ENT_QUOTES); ?>"
                               required 
                               minlength="3"
                               maxlength="100">
                        <div class="invalid-feedback">
                            Le nom de la filière doit contenir entre 3 et 100 caractères.
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="codeFiliere" class="form-label">
                            <i class="bi bi-code"></i> Code de la filière <span class="text-danger">*</span>
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="codeFiliere" 
                               name="codeFiliere" 
                               value="<?php echo htmlspecialchars($_POST['codeFiliere'] ?? '', ENT_QUOTES); ?>"
                               required 
                               pattern="[A-Z0-9]{2,10}"
                               title="Le code doit contenir uniquement des lettres majuscules et des chiffres (2-10 caractères)">
                        <div class="invalid-feedback">
                            Le code doit contenir uniquement des lettres majuscules et des chiffres (2-10 caractères).
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">
                            <i class="bi bi-file-text"></i> Description <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" 
                                  id="description" 
                                  name="description" 
                                  rows="3" 
                                  required 
                                  minlength="10"
                                  maxlength="500"><?php echo htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES); ?></textarea>
                        <div class="invalid-feedback">
                            La description doit contenir entre 10 et 500 caractères.
                        </div>
                        <div class="form-text">
                            <span id="charCount">0</span>/500 caractères
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Ajouter la filière
                        </button>
                        <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&annee=<?php echo $annee_academique; ?>" class="btn btn-secondary">
                            <i class="bi bi-x-lg"></i> Annuler
                        </a>
                    </div>
                </form>

                <script>
                // Validation côté client
                (() => {
                    'use strict';
                    const form = document.querySelector('form');
                    const description = document.getElementById('description');
                    const charCount = document.getElementById('charCount');
                    
                    // Compteur de caractères
                    if (description && charCount) {
                        description.addEventListener('input', function() {
                            charCount.textContent = this.value.length;
                        });
                        // Initialiser le compteur
                        charCount.textContent = description.value.length;
                    }
                    
                    // Validation du formulaire
                    if (form) {
                        form.addEventListener('submit', function(event) {
                            if (!form.checkValidity()) {
                                event.preventDefault();
                                event.stopPropagation();
                            }
                            form.classList.add('was-validated');
                        });
                    }
                })();
                </script>
            </div>
        <?php endif; ?>




        <div class="p-5 text-center">
            <h3>Sélectionnez une mention</h3>
            <p class="text-muted">Veuillez sélectionner une mention dans le menu de gauche pour voir ses détails.</p>
        </div>
    <?php endif; ?>
</div>

<script>
// Activer les tooltips Bootstrap pour les onglets désactivés
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>