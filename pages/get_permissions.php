<?php
// Fichier : ../pages/get_permissions.php

// Inclut les fichiers nécessaires.
require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/auth.php';

// Démarre la session et vérifie si l'utilisateur est authentifié et est un admin.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Sécurité : Vérification des droits d'accès
if (!isset($_SESSION['user_id'])) {
    http_response_code(403); // Interdit
    echo json_encode(['success' => false, 'message' => 'Utilisateur non connecté.']);
    exit;
}

$currentUser = $_SESSION['user_id'];

// Seul l'admin peut voir les permissions des autres utilisateurs
// Les autres utilisateurs ne peuvent voir que leurs propres permissions
if ($currentUser !== 'admin' && (!isset($_POST['selected_user']) || $_POST['selected_user'] !== $currentUser)) {
    http_response_code(403); // Interdit
    echo json_encode(['success' => false, 'message' => 'Accès refusé. Vous ne pouvez consulter que vos propres permissions.']);
    exit;
}

// Vérifie si le nom d'utilisateur a été envoyé via POST.
if (!isset($_POST['selected_user'])) {
    http_response_code(400); // Mauvaise requête
    echo json_encode(['success' => false, 'message' => 'Utilisateur non spécifié.']);
    exit;
}

$selectedUser = $_POST['selected_user'];

// Requête pour récupérer les permissions de l'utilisateur.
$stmt = $pdo->prepare("SELECT a.module, a.code_permission, ua.est_autorise
    FROM t_autorisation a
    LEFT JOIN t_utilisateur_autorisation ua ON a.id_autorisation = ua.id_autorisation AND ua.username = :user
    ORDER BY a.module, a.code_permission");
$stmt->execute(['user' => $selectedUser]);
$rawPermissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Réorganise les permissions pour les regrouper par module.
$permissionsByModule = [];
foreach ($rawPermissions as $perm) {
    $module = $perm['module'];
    if (!isset($permissionsByModule[$module])) {
        $permissionsByModule[$module] = [
            'module' => $module,
            'est_autorise' => false, // Initialise est_autorise à false
        ];
    }
    // Ajoute les permissions individuelles (S, I, U, D, A)
    $permissionsByModule[$module][strtolower($perm['code_permission']) . '_perm'] = ($perm['est_autorise'] == 1);
}

// Récupère le nom complet de l'utilisateur pour l'afficher sur l'interface.
$stmt = $pdo->prepare("SELECT nom_complet FROM t_utilisateur WHERE username = :user");
$stmt->execute(['user' => $selectedUser]);
$nomComplet = $stmt->fetchColumn();

// Renvoie les données au format JSON.
header('Content-Type: text/html; charset=UTF-8');
echo json_encode([
    'success' => true,
    'username' => $selectedUser,
    'nom_complet' => $nomComplet,
    'permissions' => array_values($permissionsByModule) // array_values() pour avoir un tableau d'objets simple
]);
