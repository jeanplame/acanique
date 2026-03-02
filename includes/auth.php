<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fonction pour vérifier si l'utilisateur est connecté
function isLoggedIn() {
    // Vérifier d'abord si l'utilisateur est en session
    if (isset($_SESSION['user_id'])) {
        return true;
    }

    // Vérifier le cookie "Se souvenir de moi"
    if (isset($_COOKIE['remember_token']) && !empty($_COOKIE['remember_token'])) {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("SELECT username, nom_complet, role, token_expires FROM t_utilisateur 
                                 WHERE remember_token = ? AND token_expires > NOW()");
            $stmt->execute([$_COOKIE['remember_token']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Réinitialiser la session avec logging
                $_SESSION['user_id'] = $user['username'];
                $_SESSION['nom_complet'] = $user['nom_complet'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_time'] = time();
                $_SESSION['remember_me_login'] = true; // Marquer comme connexion automatique
                
                // Log de la reconnexion automatique
                require_once __DIR__ . '/login_logger.php';
                logLoginAttempt($pdo, $user['username'], true, "Reconnexion automatique via remember_token");
                
                // Optionnel : renouveler le token pour plus de sécurité
                $newToken = bin2hex(random_bytes(32));
                $newExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                
                $updateStmt = $pdo->prepare("UPDATE t_utilisateur SET remember_token = ?, token_expires = ? WHERE username = ?");
                $updateStmt->execute([$newToken, $newExpiry, $user['username']]);
                
                // Mettre à jour le cookie
                setcookie('remember_token', $newToken, [
                    'expires' => time() + (30 * 24 * 60 * 60),
                    'path' => '/',
                    'domain' => '',
                    'secure' => isset($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
                
                return true;
            } else {
                // Token invalide ou expiré, supprimer le cookie
                setcookie('remember_token', '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'domain' => '',
                    'secure' => isset($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
                
                // Log de la tentative avec token invalide
                require_once __DIR__ . '/login_logger.php';
                logLoginAttempt($pdo, null, false, "Token remember_token invalide ou expiré");
            }
        } catch (PDOException $e) {
            // En cas d'erreur, on considère l'utilisateur comme non connecté
            error_log("Erreur lors de la vérification du token: " . $e->getMessage());
            
            // Log de l'erreur
            require_once __DIR__ . '/login_logger.php';
            logLoginAttempt($pdo, null, false, "Erreur système lors de la vérification du token: " . $e->getMessage());
        }
    }

    return false;
}

// Fonction pour vérifier si l'utilisateur a un rôle spécifique
function hasRole($requiredRole) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $requiredRole;
}

// Fonction pour rediriger si l'utilisateur n'est pas connecté
function requireLogin() {
    if (!isLoggedIn()) {
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = (strpos($currentDir, 'pages') !== false) ? '../' : '';
        header("Location: {$basePath}login.php");
        exit();
    }
}

// Fonction pour rediriger si l'utilisateur n'a pas le rôle requis
function requireRole($requiredRole) {
    requireLogin();
    if (!hasRole($requiredRole)) {
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = (strpos($currentDir, 'pages') !== false) ? '../' : '';
        header("Location: {$basePath}access-denied.php");
        exit();
    }
}

// Fonction pour nettoyer la session et les cookies
function clearUserSession() {
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time()-42000, '/');
    }
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time()-42000, '/');
    }
    session_destroy();
}

function loadUserPermissions(PDO $pdo, string $username): array {
    // Pour l'admin, donner toutes les permissions
    if (trim(strtolower($username)) === 'admin') {
        return ['*' => ['*' => true]]; // L'admin a toutes les permissions
    }

    $stmt = $pdo->prepare("
        SELECT a.module, a.code_permission, COALESCE(ua.est_autorise, 0) as est_autorise
        FROM t_autorisation a
        LEFT JOIN t_utilisateur_autorisation ua 
        ON a.id_autorisation = ua.id_autorisation 
        AND ua.username = :user
        ORDER BY a.module, a.code_permission
    ");
    $stmt->execute(['user' => $username]);
    
    $permissions = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $permissions[$row['module']][$row['code_permission']] = (bool)$row['est_autorise'];
    }
    
    return $permissions;
}

function initializePermissionsCache(PDO $pdo, string $username) {
    if (!isset($_SESSION['permissions_cache'])) {
        $_SESSION['permissions_cache'] = [];
    }
    $_SESSION['permissions_cache'] = loadUserPermissions($pdo, $username);
    $_SESSION['permissions_cache_time'] = time();
}

/**
 * Vide le cache des permissions pour forcer un rechargement
 * Utile après modification des permissions en base de données
 */
function clearPermissionsCache() {
    unset($_SESSION['permissions_cache']);
    unset($_SESSION['permissions_cache_time']);
    error_log("Cache des permissions vidé pour l'utilisateur : " . ($_SESSION['user_id'] ?? 'inconnu'));
}

/**
 * Force le rechargement du cache des permissions
 * @param PDO $pdo Connexion à la base de données
 * @param string $username Nom d'utilisateur
 */
function refreshPermissionsCache(PDO $pdo, string $username) {
    clearPermissionsCache();
    initializePermissionsCache($pdo, $username);
}

/**
 * Vide automatiquement le cache des permissions selon certaines conditions
 * @param PDO $pdo Connexion à la base de données
 */
function autoCleanPermissionsCache(PDO $pdo) {
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    
    // Conditions pour vider automatiquement le cache
    $shouldClear = false;
    
    // 1. Si c'est une nouvelle session (moins de 2 minutes)
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] < 120)) {
        $shouldClear = true;
    }
    
    // 2. Si on accède à des pages importantes pour la première fois dans cette session
    $currentPage = $_GET['page'] ?? 'dashboard';
    $importantPages = ['dashboard', 'domaine', 'settings', 'users'];
    
    if (in_array($currentPage, $importantPages)) {
        $sessionKey = 'visited_' . $currentPage;
        if (!isset($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = time();
            $shouldClear = true;
        }
    }
    
    // 3. Si le cache est très ancien (plus de 5 minutes)
    if (isset($_SESSION['permissions_cache_time']) && 
        (time() - $_SESSION['permissions_cache_time'] > 300)) {
        $shouldClear = true;
    }
    
    // Vider le cache si nécessaire
    if ($shouldClear) {
        clearPermissionsCache();
        error_log("Cache des permissions vidé automatiquement pour l'utilisateur : " . $_SESSION['user_id']);
    }
}

function hasPermission(PDO $pdo, string $username, string $module, string $code_permission): bool {
    try {
        // Vérifier que l'utilisateur est connecté
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        // Pour l'admin, donner toutes les permissions (vérification robuste)
        if(trim(strtolower($username)) === 'admin') {
            error_log("hasPermission: Admin access granted for '$username' (module=$module, permission=$code_permission)");
            return true;
        }
        
        // Vérifier si le cache des permissions doit être rechargé (toutes les 30 secondes)
        if (!isset($_SESSION['permissions_cache']) || 
            !isset($_SESSION['permissions_cache_time']) || 
            (time() - $_SESSION['permissions_cache_time'] > 30)) {
            initializePermissionsCache($pdo, $username);
        }
        
        // Vérifier les permissions depuis le cache
        if (isset($_SESSION['permissions_cache']['*']['*']) && $_SESSION['permissions_cache']['*']['*']) {
            // L'utilisateur a toutes les permissions (admin)
            return true;
        }
        
        if (isset($_SESSION['permissions_cache'][$module][$code_permission])) {
            $result = $_SESSION['permissions_cache'][$module][$code_permission];
            error_log("hasPermission: User '$username' - Module '$module' - Permission '$code_permission' = " . ($result ? 'GRANTED' : 'DENIED'));
            return $result;
        }
        
        // Si le module/permission n'existe pas dans le cache, retourner false
        error_log("hasPermission: User '$username' - Module '$module' - Permission '$code_permission' = NOT_FOUND (denied)");
        return false;
        
    } catch (PDOException $e) {
        error_log("Erreur lors de la vérification des permissions: " . $e->getMessage());
        return false;
    }
}

// Pour bloquer l'accès à un fichier si pas autorisé
function debugPermissions(PDO $pdo, string $username) {
    if (!isset($_SESSION['permissions_cache'])) {
        initializePermissionsCache($pdo, $username);
    }
    error_log("Permissions en cache pour $username : " . print_r($_SESSION['permissions_cache'], true));
    
    // Vérifier les permissions directement dans la base de données
    $stmt = $pdo->prepare("
        SELECT a.module, a.code_permission, ua.est_autorise
        FROM t_autorisation a
        LEFT JOIN t_utilisateur_autorisation ua 
        ON a.id_autorisation = ua.id_autorisation 
        AND ua.username = :user
    ");
    $stmt->execute(['user' => $username]);
    $dbPerms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Permissions en base de données pour $username : " . print_r($dbPerms, true));
}

function checkPermissionOrDie(PDO $pdo, string $username, string $module, string $code_permission, string $message = '') {
    require_once __DIR__ . '/access_logger.php';
    
    // Debug des permissions avant la vérification
    debugPermissions($pdo, $username);
    
    $hasPermission = hasPermission($pdo, $username, $module, $code_permission);
    
    // Journaliser la tentative d'accès
    logAccess($pdo, $username, $module, $code_permission, $hasPermission);
    
    if (!$hasPermission) {
        if (!$message) $message = "Vous n'avez pas l'autorisation nécessaire pour accéder à cette fonctionnalité.";
        
        // Enregistrer la tentative d'accès non autorisée dans les logs système
        error_log("Accès refusé : Utilisateur=$username, Module=$module, Permission=$code_permission");
        
        die("<div class='alert alert-danger m-3'>$message</div>");
    }
}

function hasAnyPermission(PDO $pdo, string $username, string $module, array $permissions): bool {
    foreach ($permissions as $permission) {
        if (hasPermission($pdo, $username, $module, $permission)) {
            return true;
        }
    }
    return false;
}

function hasAllPermissions(PDO $pdo, string $username, string $module, array $permissions): bool {
    foreach ($permissions as $permission) {
        if (!hasPermission($pdo, $username, $module, $permission)) {
            return false;
        }
    }
    return true;
}

/**
 * Fonction simplifiée pour protéger une page avec des permissions
 * @param string $module Le module requis (ex: 'Utilisateurs', 'Cotes', 'Inscriptions')
 * @param string $permission La permission requise (S, I, U, D, A)
 * @param string $redirectPage Page de redirection en cas d'accès refusé (défaut: access-denied.php)
 */
function requirePermission(string $module, string $permission, string $redirectPage = 'access-denied.php') {
    global $pdo;
    
    // Vérifier que l'utilisateur est connecté
    if (!isLoggedIn()) {
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = (strpos($currentDir, 'pages') !== false) ? '../' : '';
        header("Location: {$basePath}login.php");
        exit();
    }
    
    $username = $_SESSION['user_id'];
    
    // Vérifier la permission
    if (!hasPermission($pdo, $username, $module, $permission)) {
        // Log de la tentative d'accès non autorisée
        error_log("Accès refusé - User: $username, Module: $module, Permission: $permission, Page: " . $_SERVER['REQUEST_URI']);
        
        // Redirection vers la page d'erreur (utiliser un chemin relatif)
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = (strpos($currentDir, 'pages') !== false) ? '../' : '';
        header("Location: {$basePath}$redirectPage");
        exit();
    }
}

/**
 * Fonction pour vérifier plusieurs permissions (au moins une doit être accordée)
 */
function requireAnyPermission(string $module, array $permissions, string $redirectPage = 'access-denied.php') {
    global $pdo;
    
    if (!isLoggedIn()) {
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = (strpos($currentDir, 'pages') !== false) ? '../' : '';
        header("Location: {$basePath}login.php");
        exit();
    }
    
    $username = $_SESSION['user_id'];
    
    if (!hasAnyPermission($pdo, $username, $module, $permissions)) {
        error_log("Accès refusé - User: $username, Module: $module, Permissions: " . implode(',', $permissions) . ", Page: " . $_SERVER['REQUEST_URI']);
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = (strpos($currentDir, 'pages') !== false) ? '../' : '';
        header("Location: {$basePath}$redirectPage");
        exit();
    }
}

/**
 * Fonction pour afficher un contenu conditionnel basé sur les permissions
 */
function canAccess(string $module, string $permission): bool {
    global $pdo;
    
    if (!isLoggedIn() || !isset($_SESSION['user_id'])) {
        return false;
    }
    
    return hasPermission($pdo, $_SESSION['user_id'], $module, $permission);
}

/**
 * Fonction helper pour les templates - affiche le contenu seulement si l'utilisateur a la permission
 */
function showIfAllowed(string $module, string $permission, string $content): string {
    return canAccess($module, $permission) ? $content : '';
}

// Inclure la configuration de la base de données
require_once __DIR__ . '/db_config.php';
?>
