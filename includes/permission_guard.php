<?php
/**
 * Permission Guard - Protection automatique des pages
 * 
 * Ce fichier doit être inclus au début de chaque page protégée
 * Il vérifie automatiquement les permissions basées sur la configuration
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/page_permissions.php';

// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérification automatique des permissions
autoCheckPermissions($pdo);

/**
 * Fonction à appeler pour désactiver la vérification automatique sur une page spécifique
 */
function disableAutoPermissionCheck() {
    // Cette fonction peut être appelée avant l'inclusion de ce fichier
    // pour désactiver la vérification automatique sur des pages spéciales
    global $DISABLE_AUTO_PERMISSION_CHECK;
    $DISABLE_AUTO_PERMISSION_CHECK = true;
}

/**
 * Fonction pour forcer une vérification de permission spécifique
 */
function forcePermissionCheck(string $module, string $permission) {
    global $pdo;
    
    if (!isset($_SESSION['user_id'])) {
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = (strpos($currentDir, 'pages') !== false) ? '../' : '';
        header("Location: {$basePath}login.php");
        exit;
    }
    
    if (!hasPermission($pdo, $_SESSION['user_id'], $module, $permission)) {
        error_log("Accès forcé refusé - User: {$_SESSION['user_id']}, Module: $module, Permission: $permission");
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = (strpos($currentDir, 'pages') !== false) ? '../' : '';
        header("Location: {$basePath}access-denied.php");
        exit;
    }
}

/**
 * Fonction utilitaire pour les pages AJAX
 */
function checkAjaxPermissions(string $module, string $permission) {
    global $pdo;
    
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Non authentifié']);
        exit;
    }
    
    if (!hasPermission($pdo, $_SESSION['user_id'], $module, $permission)) {
        echo json_encode(['success' => false, 'message' => 'Permissions insuffisantes']);
        exit;
    }
}

/**
 * Fonction pour créer un bouton conditionnel
 */
function createConditionalButton(string $module, string $permission, string $text, string $action, string $class = 'btn btn-primary'): string {
    global $pdo;
    
    if (!isset($_SESSION['user_id']) || !hasPermission($pdo, $_SESSION['user_id'], $module, $permission)) {
        return '';
    }
    
    return "<button class=\"$class\" onclick=\"$action\">$text</button>";
}

/**
 * Fonction pour créer un lien conditionnel
 */
function createConditionalLink(string $module, string $permission, string $text, string $url, string $class = ''): string {
    global $pdo;
    
    if (!isset($_SESSION['user_id']) || !hasPermission($pdo, $_SESSION['user_id'], $module, $permission)) {
        return '';
    }
    
    $classAttr = $class ? " class=\"$class\"" : '';
    return "<a href=\"$url\"$classAttr>$text</a>";
}