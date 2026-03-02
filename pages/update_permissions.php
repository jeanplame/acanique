<?php
// Fichier : ../pages/update_permissions.php

// Inclut les fichiers nécessaires.
require_once __DIR__ . '/../includes/db_config.php';
    header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../includes/auth.php';

// Démarre la session et vérifie si l'utilisateur est authentifié et est un admin.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Sécurité : Seul l'utilisateur 'admin' peut modifier les permissions.
if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== 'admin') {
    http_response_code(403); // Interdit
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit;
}

// Récupère le contenu JSON brut de la requête.
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// S'assure que les données nécessaires sont présentes.
if (!isset($data['selectedUser'], $data['module'], $data['perm'], $data['value'])) {
    http_response_code(400); // Mauvaise requête
    echo json_encode(['success' => false, 'message' => 'Données manquantes.']);
    exit;
}

$selectedUser = $data['selectedUser'];
$module = $data['module'];
$perm = $data['perm'];
$value = (int)$data['value'];

// Requête pour trouver l'ID de l'autorisation à partir du module et du code de permission.
$stmt = $pdo->prepare("SELECT id_autorisation FROM t_autorisation WHERE module = :module AND code_permission = :perm");
$stmt->execute(['module' => $module, 'perm' => $perm]);
$authId = $stmt->fetchColumn();

if (!$authId) {
    echo json_encode(['success' => false, 'message' => 'Permission inconnue.']);
    exit;
}

// Requête pour mettre à jour ou insérer la permission.
try {
    // Supprime d'abord toutes les anciennes entrées pour cette permission
    $stmt = $pdo->prepare("DELETE FROM t_utilisateur_autorisation 
                          WHERE username = :username AND id_autorisation = :id_autorisation");
    $stmt->execute([
        'username' => $selectedUser,
        'id_autorisation' => $authId
    ]);
    
    // Insère la nouvelle permission
    $stmt = $pdo->prepare("INSERT INTO t_utilisateur_autorisation (username, id_autorisation, est_autorise) 
                          VALUES (:username, :id_autorisation, :est_autorise)");
    $stmt->execute([
        'username' => $selectedUser,
        'id_autorisation' => $authId,
        'est_autorise' => $value
    ]);

    // Réponse de succès.
    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    http_response_code(500); // Erreur interne du serveur
    echo json_encode(['success' => false, 'message' => 'Erreur de base de données : ' . $e->getMessage()]);
}
