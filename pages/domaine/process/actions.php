<?php
require_once '../../../includes/db_config.php';
    header('Content-Type: text/html; charset=UTF-8');

function supprimerFiliere($pdo, $id) {
    try {
        // Vérifier s'il y a des mentions associées
        $check_mentions = "SELECT COUNT(*) FROM t_mention WHERE idFiliere = ?";
        $stmt = $pdo->prepare($check_mentions);
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = "Impossible de supprimer cette filière car elle contient des mentions.";
            return false;
        }

        // Supprimer les promotions associées
        $delete_promotions = "DELETE FROM t_filiere_promotion WHERE id_filiere = ?";
        $stmt = $pdo->prepare($delete_promotions);
        $stmt->execute([$id]);

        // Supprimer la filière
        $delete_filiere = "DELETE FROM t_filiere WHERE idFiliere = ?";
        $stmt = $pdo->prepare($delete_filiere);
        $stmt->execute([$id]);

        $_SESSION['success'] = "La filière a été supprimée avec succès.";
        return true;
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $_SESSION['error'] = "Une erreur est survenue lors de la suppression.";
        return false;
    }
}

function supprimerMention($pdo, $id) {
    try {
        // Vérifier s'il y a des inscriptions associées
        $check_inscriptions = "SELECT COUNT(*) FROM t_inscription WHERE id_mention = ?";
        $stmt = $pdo->prepare($check_inscriptions);
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['error'] = "Impossible de supprimer cette mention car elle a des étudiants inscrits.";
            return false;
        }

        // Supprimer les UEs associées
        $delete_ues = "DELETE FROM t_mention_ue WHERE id_mention = ?";
        $stmt = $pdo->prepare($delete_ues);
        $stmt->execute([$id]);

        // Supprimer la mention
        $delete_mention = "DELETE FROM t_mention WHERE id_mention = ?";
        $stmt = $pdo->prepare($delete_mention);
        $stmt->execute([$id]);

        $_SESSION['success'] = "La mention a été supprimée avec succès.";
        return true;
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $_SESSION['error'] = "Une erreur est survenue lors de la suppression.";
        return false;
    }
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    
    switch ($action) {
        case 'supprimer_filiere':
            if (supprimerFiliere($pdo, $id)) {
                header("Location: ?page=domaine&action=view&id=" . $_POST['domaine_id']);
            } else {
                header("Location: " . $_SERVER['HTTP_REFERER']);
            }
            exit;
            
        case 'supprimer_mention':
            if (supprimerMention($pdo, $id)) {
                header("Location: " . $_SERVER['HTTP_REFERER']);
            } else {
                header("Location: " . $_SERVER['HTTP_REFERER']);
            }
            exit;
    }
}
