<?php
session_start();
require_once '../../../includes/db_config.php';
    header('Content-Type: text/html; charset=UTF-8');

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Vous devez être connecté pour effectuer cette action';
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_mention') {
    $idFiliere = isset($_POST['idFiliere']) ? (int)$_POST['idFiliere'] : 0;
    $code_mention = trim($_POST['code_mention'] ?? '');
    $libelle = trim($_POST['libelle'] ?? '');
    $description = trim($_POST['description'] ?? '');

    try {
        $stmt = $pdo->prepare("INSERT INTO t_mention (code_mention, idFiliere, libelle, description) VALUES (?, ?, ?, ?)");
        
        if ($stmt->execute([$code_mention, $idFiliere, $libelle, $description])) {
            $_SESSION['success'] = 'Mention ajoutée avec succès';
        } else {
            $_SESSION['error'] = 'Erreur lors de l\'ajout de la mention';
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $_SESSION['error'] = 'Ce code de mention existe déjà';
        } else {
            $_SESSION['error'] = 'Erreur lors de l\'ajout de la mention';
        }
    }
    
    header('Location: ' . $_SERVER['HTTP_REFERER'] . '&filiere=' . $idFiliere);
    exit;
}

$_SESSION['error'] = 'Requête invalide';
header('Location: ' . $_SERVER['HTTP_REFERER']);
exit;