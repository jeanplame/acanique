<?php
// On s'assure que le fichier de configuration de la base de données est inclus.
require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/auth.php';
    header('Content-Type: text/html; charset=UTF-8');

// Vérifier si une session est déjà demarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérifier les permissions - Modifier un profil étudiant nécessite la permission de modification sur les Inscriptions
requirePermission('Inscriptions', 'U');

// Initialisation des variables pour les messages
$error = '';
$success = '';

// On s'assure que le matricule de l'étudiant est bien présent
if (!isset($_GET['matricule']) || empty($_GET['matricule'])) {
    $error = "Matricule manquant. Impossible de mettre à jour le profil.";
    return; // On arrête l'exécution si le matricule est manquant
}

$matricule = $_GET['matricule'];

// =================================================================================================
// GESTION DE LA SOUMISSION DU FORMULAIRE DE MISE À JOUR DES INFORMATIONS
// =================================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    try {
        $sql = "UPDATE t_etudiant SET
                    nom_etu = ?,
                    postnom_etu = ?,
                    prenom_etu = ?,
                    sexe = ?,
                    date_naiss_etu = ?,
                    lieu_naiss_etu = ?,
                    nationalite = ?,
                    adresse_etu = ?,
                    telephone_etu = ?,
                    email_etu = ?,
                    section_suivie = ?,
                    pourcentage_dipl = ?,
                    nom_pere = ?,
                    nom_mere = ?,
                    date_mise_a_jour = NOW()
                WHERE matricule = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['nom_etu'],
            $_POST['postnom_etu'],
            $_POST['prenom_etu'],
            $_POST['sexe'],
            $_POST['date_naiss_etu'],
            $_POST['lieu_naiss_etu'],
            $_POST['nationalite'],
            $_POST['adresse_etu'],
            $_POST['telephone_etu'],
            $_POST['email_etu'],
            $_POST['section_suivie'],
            $_POST['pourcentage_dipl'],
            $_POST['nom_pere'],
            $_POST['nom_mere'],
            $matricule
        ]);

        // Redirection avec un message de succès pour éviter la soumission multiple du formulaire
        header('Location: ?page=etudiant_profil&matricule=' . $matricule . '&success=update');
        exit();

    } catch (PDOException $e) {
        $error = "Erreur lors de la mise à jour des informations : " . $e->getMessage();
    }
}

// =================================================================================================
// GESTION DU TÉLÉCHARGEMENT DE LA PHOTO DE PROFIL
// =================================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo') {
    if (isset($_FILES['photo_profil']) && $_FILES['photo_profil']['error'] === UPLOAD_ERR_OK) {
        $target_dir = "../../uploads/profile_pics/";
        // On s'assure que le dossier de destination existe
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        $file_extension = pathinfo($_FILES['photo_profil']['name'], PATHINFO_EXTENSION);
        // On crée un nom de fichier unique et sécurisé basé sur le matricule
        $new_file_name = $matricule . '.' . strtolower($file_extension);
        $target_file = $target_dir . $new_file_name;
        $upload_ok = 1;
        $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

        // Vérification de la taille du fichier (limite 500KB)
        if ($_FILES['photo_profil']['size'] > 500000) {
            $error = "Désolé, votre fichier est trop volumineux.";
            $upload_ok = 0;
        }

        // Autoriser certains formats de fichiers
        if ($imageFileType != "jpg" && $imageFileType != "png" && $imageFileType != "jpeg" && $imageFileType != "gif") {
            $error = "Désolé, seuls les fichiers JPG, JPEG, PNG & GIF sont autorisés.";
            $upload_ok = 0;
        }

        // Si tout est bon, on tente de télécharger le fichier
        if ($upload_ok == 1) {
            if (move_uploaded_file($_FILES['photo_profil']['tmp_name'], $target_file)) {
                // Mise à jour de la base de données avec le nouveau chemin de la photo
                $sql_photo = "UPDATE t_etudiant SET photo_etu = ? WHERE matricule = ?";
                $stmt_photo = $pdo->prepare($sql_photo);
                if ($stmt_photo->execute([$target_file, $matricule])) {
                    // Redirection avec un message de succès
                    header('Location: ?page=etudiant_profil&matricule=' . $matricule . '&success=photo');
                    exit();
                } else {
                    $error = "Erreur lors de la mise à jour du chemin de la photo dans la base de données.";
                }
            } else {
                $error = "Désolé, une erreur est survenue lors du téléchargement de votre fichier.";
            }
        }
    } else {
        $error = "Erreur lors du téléchargement du fichier. Code d'erreur : " . ($_FILES['photo_profil']['error'] ?? 'inconnu');
    }
}
?>
