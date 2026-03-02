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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_promotion') {
    $id_mention = isset($_POST['id_mention']) ? (int)$_POST['id_mention'] : 0;
    $code_promotion = trim($_POST['code_promotion'] ?? '');
    $nom_promotion = trim($_POST['nom_promotion'] ?? '');

    try {
        // Vérifier que la mention existe
        $stmt = $pdo->prepare("SELECT idFiliere FROM t_mention WHERE id_mention = ?");
        $stmt->execute([$id_mention]);
        $idFiliere = $stmt->fetchColumn();

        if (!$idFiliere) {
            throw new Exception('La mention spécifiée n\'existe pas');
        }

        // Commencer une transaction
        $pdo->beginTransaction();

        // Insérer la promotion
        $stmt = $pdo->prepare("INSERT INTO t_promotion (code_promotion, nom_promotion) VALUES (?, ?)");
        $stmt->execute([$code_promotion, $nom_promotion]);

        // Associer la promotion à la filière
        $stmt = $pdo->prepare("INSERT INTO t_filiere_promotion (id_filiere, code_promotion) VALUES (?, ?)");
        $stmt->execute([$idFiliere, $code_promotion]);

        // Valider la transaction
        $pdo->commit();
        $_SESSION['success'] = 'Promotion ajoutée avec succès';

    } catch (PDOException $e) {
        // Annuler la transaction en cas d'erreur
        $pdo->rollBack();
        
        if ($e->getCode() == 23000) {
            $_SESSION['error'] = 'Ce code de promotion existe déjà';
        } else {
            $_SESSION['error'] = 'Erreur lors de l\'ajout de la promotion';
            error_log($e->getMessage());
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    
    // Rediriger vers la page précédente avec les paramètres
    header('Location: ' . $_SERVER['HTTP_REFERER'] . '&mention=' . $id_mention);
    exit;
}

$_SESSION['error'] = 'Requête invalide';
header('Location: ' . $_SERVER['HTTP_REFERER']);
exit;
