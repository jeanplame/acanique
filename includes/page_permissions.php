<?php
// Fichier : includes/page_permissions.php
// Configuration des permissions requises pour chaque page

/**
 * Affiche un message d'erreur de permission si il y en a un
 * @return bool true si il y a une erreur, false sinon
 */
function displayPermissionError() {
    if (isset($_SESSION['permission_error'])) {
        $error = $_SESSION['permission_error'];
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
        echo '<h4 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> Accès refusé</h4>';
        echo '<p class="mb-1">' . htmlspecialchars($error['message']) . '</p>';
        echo '<small class="text-muted">' . htmlspecialchars($error['details']) . '</small>';
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>';
        echo '</div>';
        
        // Supprimer l'erreur après l'affichage
        unset($_SESSION['permission_error']);
        return true;
    }
    return false;
}

/**
 * Vérifie si l'utilisateur a une erreur de permission pour une page
 * @param string $page Le nom de la page à vérifier
 * @return bool true si il y a une erreur pour cette page
 */
function hasPermissionError($page = null) {
    if (isset($_SESSION['permission_error'])) {
        if ($page === null) {
            return true;
        }
        return $_SESSION['permission_error']['page'] === $page;
    }
    return false;
}

$PAGE_PERMISSIONS = [
    // Pages principales
    'dashboard' => ['module' => 'Utilisateurs', 'perm' => 'S'],
    'etudiant_profil' => ['module' => 'Inscriptions', 'perm' => 'S'],
    'modifier_profil' => ['module' => 'Inscriptions', 'perm' => 'U'],
    'profile' => ['module' => 'Utilisateurs', 'perm' => 'S'],
    
    // Gestion des cours et notes
    'courses' => ['module' => 'Cours', 'perm' => 'S'],
    'manage_courses' => ['module' => 'Cours', 'perm' => 'A'],
    'notes' => ['module' => 'Cotes', 'perm' => 'S'],
    'manage_notes' => ['module' => 'Cotes', 'perm' => 'U'],
    
    // Administration
    'students' => ['module' => 'Inscriptions', 'perm' => 'A'],
    'settings' => ['module' => 'Utilisateurs', 'perm' => 'A'],
    'get_permissions' => ['module' => 'Utilisateurs', 'perm' => 'A'],
    'update_permissions' => ['module' => 'Utilisateurs', 'perm' => 'A'],
    'get_audit_history' => ['module' => 'Cotes', 'perm' => 'A'],
    
    // Pages du domaine
    'domaine' => ['module' => 'Cours', 'perm' => 'S'],
    'domaine/view' => ['module' => 'Cours', 'perm' => 'S'],
    'domaine/manage' => ['module' => 'Cours', 'perm' => 'A'],
];

/**
 * Vérification spéciale pour la page domaine - accessible si au moins une permission d'onglet
 */
function checkDomainePagePermission(PDO $pdo, string $username) {
    // Vérifier les permissions pour chaque onglet de la page domaine
    $tabPermissions = [
        ['module' => 'Cours', 'perm' => 'S'],        // Onglet UE
        ['module' => 'Inscriptions', 'perm' => 'S'], // Onglet Inscriptions
        ['module' => 'Cotes', 'perm' => 'S']         // Onglet Notes
    ];
    
    foreach ($tabPermissions as $permission) {
        if (hasPermission($pdo, $username, $permission['module'], $permission['perm'])) {
            return true; // Au moins une permission trouvée
        }
    }
    
    return false; // Aucune permission trouvée
}

/**
 * Vérification automatique des permissions basée sur l'URL
 */
function checkPagePermission(PDO $pdo, string $page) {
    global $PAGE_PERMISSIONS;
    
    // Pages publiques ou de connexion (sans restriction)
    $publicPages = ['login', 'access-denied', 'logout', 'index'];
    if (in_array($page, $publicPages)) {
        return true;
    }
    
    // Vérifier si l'utilisateur est connecté
    if (!isset($_SESSION['user_id'])) {
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = (strpos($currentDir, 'pages') !== false) ? '../' : '';
        header("Location: {$basePath}login.php");
        exit;
    }
    
    // Cas spécial pour la page domaine - vérification des permissions multiples
    if ($page === 'domaine') {
        if (!checkDomainePagePermission($pdo, $_SESSION['user_id'])) {
            // Log de la tentative d'accès non autorisée
            error_log("Accès refusé à la page domaine - User: {$_SESSION['user_id']}, Aucune permission d'onglet trouvée");
            
            // Au lieu de rediriger, stocker l'erreur en session
            $_SESSION['permission_error'] = [
                'message' => "Vous n'avez pas les permissions nécessaires pour accéder à cette page.",
                'details' => "Permission requise : Au moins une des suivantes : Cours-S, Inscriptions-S, ou Cotes-S",
                'page' => $page
            ];
            return false;
        }
        return true;
    }
    
    // Cas spécial pour le dashboard - accessible avec n'importe quelle permission de lecture
    if ($page === 'dashboard') {
        $basicModules = ['Utilisateurs', 'Cours', 'Inscriptions', 'Cotes'];
        foreach ($basicModules as $module) {
            if (hasPermission($pdo, $_SESSION['user_id'], $module, 'S')) {
                return true; // Au moins une permission trouvée
            }
        }
        
        // Log de la tentative d'accès non autorisée
        error_log("Accès refusé au dashboard - User: {$_SESSION['user_id']}, Aucune permission de base trouvée");
        
        $_SESSION['permission_error'] = [
            'message' => "Vous n'avez pas les permissions nécessaires pour accéder à cette page.",
            'details' => "Permission requise : Au moins une permission de lecture dans un module",
            'page' => $page
        ];
        return false;
    }
    
    // Vérifier si la page existe dans la configuration
    if (!isset($PAGE_PERMISSIONS[$page])) {
        return true; // Page sans restriction spécifique
    }
    
    // Vérifier la permission
    $config = $PAGE_PERMISSIONS[$page];
    if (!hasPermission($pdo, $_SESSION['user_id'], $config['module'], $config['perm'])) {
        // Log de la tentative d'accès non autorisée
        error_log("Accès refusé - User: {$_SESSION['user_id']}, Page: $page, Module: {$config['module']}, Permission: {$config['perm']}");
        
        // Au lieu de rediriger, stocker l'erreur en session
        $_SESSION['permission_error'] = [
            'message' => "Vous n'avez pas les permissions nécessaires pour accéder à cette page.",
            'details' => "Permission requise : {$config['module']} - {$config['perm']}",
            'page' => $page
        ];
        return false;
    }
    
    return true;
}

/**
 * Vérification automatique des permissions basée sur le nom de fichier actuel
 */
function autoCheckPermissions(PDO $pdo) {
    // Obtenir le nom du fichier actuel sans extension
    $currentFile = basename($_SERVER['SCRIPT_NAME'], '.php');
    $currentDir = basename(dirname($_SERVER['SCRIPT_NAME']));
    
    // Construire le chemin de page pour la vérification
    $pagePath = $currentDir !== 'pages' && $currentDir !== 'acadenique' ? 
                "$currentDir/$currentFile" : $currentFile;
    
    return checkPagePermission($pdo, $pagePath);
}

/**
 * Helper pour afficher un contenu seulement si l'utilisateur a les permissions
 */
function showIfHasPermission(string $module, string $permission, string $content): string {
    global $pdo;
    
    if (!isset($_SESSION['user_id'])) {
        return '';
    }
    
    return hasPermission($pdo, $_SESSION['user_id'], $module, $permission) ? $content : '';
}

/**
 * Helper pour cacher des éléments de navigation selon les permissions
 */
function getNavigationItems(): array {
    global $pdo;
    
    if (!isset($_SESSION['user_id'])) {
        return [];
    }
    
    $items = [];
    
    // Dashboard - toujours visible pour les utilisateurs connectés
    $items['dashboard'] = ['title' => 'Tableau de bord', 'url' => 'pages/dashboard.php'];
    
    // Inscriptions
    if (hasPermission($pdo, $_SESSION['user_id'], 'Inscriptions', 'S')) {
        $items['students'] = ['title' => 'Étudiants', 'url' => 'pages/students.php'];
    }
    
    // Cours
    if (hasPermission($pdo, $_SESSION['user_id'], 'Cours', 'S')) {
        $items['courses'] = ['title' => 'Cours', 'url' => 'pages/courses.php'];
    }
    
    // Cotes
    if (hasPermission($pdo, $_SESSION['user_id'], 'Cotes', 'S')) {
        $items['notes'] = ['title' => 'Notes', 'url' => 'pages/notes.php'];
    }
    
    // Administration
    if (hasPermission($pdo, $_SESSION['user_id'], 'Utilisateurs', 'A')) {
        $items['admin'] = ['title' => 'Administration', 'url' => 'pages/admin.php'];
    }
    
    return $items;
}
