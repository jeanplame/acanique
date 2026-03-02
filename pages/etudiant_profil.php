<?php
// On s'assure que le fichier de configuration de la base de données est inclus.
require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/auth.php';
    header('Content-Type: text/html; charset=UTF-8');

// Vérifier si une session est déjà demarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}



// Initialisation des variables pour éviter les erreurs
$etudiant = null;
$academic_record = [];
$error = '';
$success = '';



// On vérifie si le matricule de l'étudiant est passé dans l'URL
if (!isset($_GET['matricule']) || empty($_GET['matricule'])) {
    // Si le matricule est manquant ou invalide, on redirige
    header('Location: ?page=dashboard');
    exit();
}

$matricule = $_GET['matricule'];

$sql_id_domaine = "SELECT id_domaine FROM vue_programme_etudiant WHERE matricule = ?";
$stmt_domaine = $pdo->prepare($sql_id_domaine);
$stmt_domaine->execute([$matricule]);
$domaine_etudiant['id_domaine'] = $stmt_domaine->fetchColumn() ?: 'Non défini';
$domaine_id = $domaine_etudiant['id_domaine'];

$sql_id_filiere = "SELECT idFiliere FROM vue_programme_etudiant WHERE matricule = ?";
$stmt_filiere = $pdo->prepare($sql_id_filiere);
$stmt_filiere->execute([$matricule]);
$filiere_etudiant['idFiliere'] = $stmt_filiere->fetchColumn() ?: 'Non défini';
$filiere_id = $filiere_etudiant['idFiliere'];

$sql_id_mention = "SELECT id_mention FROM vue_programme_etudiant WHERE matricule = ?";
$stmt_mention = $pdo->prepare($sql_id_mention);
$stmt_mention->execute([$matricule]);
$mention_etudiant['id_mention'] = $stmt_mention->fetchColumn() ?: 'Non définie';
$mention_id = $mention_etudiant['id_mention'];

// Récupérer le code de la promotion de l'étudiant
$sql_code_promotion = "SELECT code_promotion FROM t_inscription WHERE matricule = ? AND statut = 'Actif' LIMIT 1";
$stmt_code_promotion = $pdo->prepare($sql_code_promotion);
$stmt_code_promotion->execute([$matricule]);
$code_promotion = $stmt_code_promotion->fetchColumn() ?: 'Non défini';

// =================================================================================================
// GESTION DE LA SOUMISSION DU FORMULAIRE DE MISE À JOUR DES INFORMATIONS ET DE LA PHOTO
// Cette partie gère l'ensemble des données du formulaire, y compris la photo rognée si elle a été modifiée.
// =================================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {

    if (!empty($_POST['cropped_image_data'])) {
        // Logique de traitement de la photo (tu dois la mettre ici)
        try {
            // 1. Décodage de l'image Base64
            $imageData = $_POST['cropped_image_data'];
            list($type, $imageData) = explode(';', $imageData);
            list(, $imageData) = explode(',', $imageData);
            $imageData = base64_decode($imageData);

            // 2. Définition du chemin de sauvegarde
            $uploadDir = __DIR__ . '/../uploads/photos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $fileName = 'photo_' . $matricule . '_' . time() . '.jpeg';
            $filePath = $uploadDir . $fileName;
            $webPath = '../uploads/photos/' . $fileName; // Chemin relatif pour l'URL

            // 3. Enregistrement du fichier image
            if (file_put_contents($filePath, $imageData)) {
                // 4. Mise à jour du chemin de la photo dans la base de données
                $sql = "UPDATE t_etudiant SET photo = ?, date_mise_a_jour = NOW() WHERE matricule = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$webPath, $matricule]);

                $success = "La photo de profil a été mise à jour avec succès.";
                // On met à jour la variable etudiant pour un affichage immédiat
                $etudiant['photo'] = $webPath;

            } else {
                throw new Exception("Impossible d'enregistrer l'image sur le serveur.");
            }

        } catch (PDOException $e) {
            $error = "Erreur de base de données lors de la mise à jour de la photo : " . $e->getMessage();
        } catch (Exception $e) {
            $error = "Erreur lors du traitement de la photo : " . $e->getMessage();
        }
    }

    if (isset($_POST['update_profile'])) {
        # code...
        try {
            // Démarre la transaction pour s'assurer que les deux mises à jour réussissent ou échouent ensemble
            $pdo->beginTransaction();

            // 2. GESTION DES AUTRES INFORMATIONS DE L'ÉTUDIANT
            $sql = "UPDATE t_etudiant SET
                    nom_etu = ?,
                    postnom_etu = ?,
                    prenom_etu = ?,
                    sexe = ?,
                    date_naiss = ?,
                    lieu_naiss = ?,
                    nationalite = ?,
                    adresse = ?,
                    telephone = ?,
                    email = ?,
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

            $pdo->commit();
            $success = "Les informations de l'étudiant et la photo ont été mises à jour avec succès.";

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Erreur lors de la mise à jour : " . $e->getMessage();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Erreur de configuration ou d'enregistrement : " . $e->getMessage();
        }
    }

}


// =================================================================================================
// RÉCUPÉRATION DES INFORMATIONS DE L'ÉTUDIANT ET DE SES NOTES
// Ce code reste inchangé, il récupère les données pour l'affichage de la page.
// =================================================================================================
try {

    $sql = "
        SELECT a.id_annee, a.date_debut, a.date_fin, a.statut
        FROM t_configuration c
        JOIN t_anne_academique a ON a.id_annee = c.valeur
        WHERE c.cle = 'annee_encours'
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $annee = $stmt->fetch(PDO::FETCH_ASSOC);

    // ==========================
    // Gestion si aucune année n'est définie
    // ==========================

    $annee_academique_courante = $annee['id_annee'];
    // 1. Récupération de l'année académique courante depuis la table t_configuration


    // 2. Récupération des infos de base de l'étudiant
    $sql_etudiant_base = "SELECT * FROM t_etudiant WHERE matricule = ?";
    $stmt_etudiant_base = $pdo->prepare($sql_etudiant_base);
    $stmt_etudiant_base->execute([$matricule]);
    $etudiant = $stmt_etudiant_base->fetch(PDO::FETCH_ASSOC);

    if (!$etudiant) {
        $error = "Aucun étudiant trouvé avec ce matricule.";
    } else {
        // Pas besoin d'ID interne pour les jointures, on utilise directement 'matricule'

        // 3. Récupération de la mention de l'étudiant
        $sql_mention = "SELECT m.libelle AS mention_libelle
                            FROM t_inscription i
                            JOIN t_mention m ON i.id_mention = m.id_mention
                            WHERE i.matricule = ? AND i.statut = 'Actif'";
        $stmt_mention = $pdo->prepare($sql_mention);
        $stmt_mention->execute([$matricule]);
        $etudiant['mention_libelle'] = $stmt_mention->fetchColumn() ?: 'Non définie';

        // 4. Récupération de la promotion de l'étudiant
        $sql_promotion = "SELECT p.nom_promotion
                              FROM t_inscription i
                              JOIN t_promotion p ON i.code_promotion = p.code_promotion
                              WHERE i.matricule = ? AND i.statut = 'Actif'";
        $stmt_promotion = $pdo->prepare($sql_promotion);
        $stmt_promotion->execute([$matricule]);
        $etudiant['nom_promotion'] = $stmt_promotion->fetchColumn() ?: 'Non définie';



    }

} catch (PDOException $e) {
    $error = "Erreur lors de la récupération des données : " . $e->getMessage();
} catch (Exception $e) {
    $error = "Erreur de configuration : " . $e->getMessage();
    $annee_academique_courante = null;
}

// Traitement de l'uploads de la photo et mise à jour de la photo de profil de l'étudiant 
// Traitement de l'uploads de la photo et mise à jour de la photo de profil de l'étudiant
// 6. Récupération du domaine de l'étudiant à partir de la table mention, filière et enfin domaine
$sql_domaine = "SELECT nom_domaine FROM vue_etudiants_domaines WHERE matricule = ?";
$stmt_domaine = $pdo->prepare($sql_domaine);
$stmt_domaine->execute([$matricule]);
$domaine_etudiant['nom_domaine'] = $stmt_domaine->fetchColumn() ?: 'Non défini';
$domaine = $domaine_etudiant['nom_domaine'];

// 7. Récupérer la filière de l'étudiant
$sql_filiere = "SELECT nom_filiere FROM vue_etudiant_inscription WHERE matricule = ?";
$stmt_filiere = $pdo->prepare($sql_filiere);
$stmt_filiere->execute([$matricule]);
$filiere_etudiant['nom_filiere'] = $stmt_filiere->fetchColumn() ?: 'Non défini';
$filiere = $filiere_etudiant['nom_filiere'];

// 8. Récuperer la mention de l'étudiant
$sql_mention = "SELECT nom_mention FROM vue_etudiant_inscription WHERE matricule = ?";
$stmt_mention = $pdo->prepare($sql_mention);
$stmt_mention->execute([$matricule]);
$mention_etudiant['nom_mention'] = $stmt_mention->fetchColumn() ?: 'Non définie';
$mention = $mention_etudiant['nom_mention'];

// 9. Récupérer toutes les infos de l'étudiant
$sql_infos_etudiant = "SELECT * FROM vue_etudiant_inscription WHERE matricule = ?";
$stmt_infos_etudiant = $pdo->prepare($sql_infos_etudiant);
$stmt_infos_etudiant->execute([$matricule]);
$infos_etudiant = $stmt_infos_etudiant->fetch(PDO::FETCH_ASSOC);

// 10. Récupération et formatage de l'année académique
$annee_academique_formatee = 'Non définie';
if ($annee_academique_courante) {
    try {
        $sql_annee = "SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?";
        $stmt_annee_details = $pdo->prepare($sql_annee);
        $stmt_annee_details->execute([$annee_academique_courante]);
        $annee_details = $stmt_annee_details->fetch(PDO::FETCH_ASSOC);

        if ($annee_details) {
            $date_debut = new DateTime($annee_details['date_debut']);
            $date_fin = new DateTime($annee_details['date_fin']);
            $annee_academique_formatee = $date_debut->format('Y') . '-' . $date_fin->format('Y');
        }
    } catch (Exception $e) {
        // En cas d'erreur, $annee_academique_formatee conservera sa valeur par défaut 'Non définie'
        $error .= " Erreur lors du formatage de l'année académique.";
    }
}

?>


<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil de l'étudiant</title>
    <!-- Bootstrap CSS - Local -->
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons - Local -->
    <link rel="stylesheet" href="../css/bootstrap-icons.css">
    <!-- Cropper.js CSS - Local -->
    <link href="../css/cropper.min.css" rel="stylesheet">
    <!-- Liens CSS d'origine -->
    <link rel="icon" type="image/png" href="images/icons/favicon.ico" />
    <link rel="stylesheet" type="text/css" href="vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="fonts/font-awesome-4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" type="text/css" href="fonts/Linearicons-Free-v1.0.0/icon-font.min.css">
    <link rel="stylesheet" type="text/css" href="vendor/animate/animate.css">
    <link rel="stylesheet" type="text/css" href="vendor/css-hamburgers/hamburgers.min.css">
    <link rel="stylesheet" type="text/css" href="vendor/animsition/css/animsition.min.css">
    <link rel="stylesheet" type="text/css" href="vendor/select2/select2.min.css">
    <link rel="stylesheet" type="text/css" href="vendor/daterangepicker/daterangepicker.css">
    <link rel="stylesheet" type="text/css" href="css/util.css">
    <link rel="stylesheet" type="text/css" href="css/main.css">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <style>
        /* Styles personnalisés pour la mise en page de profil */
        .profile-cover {
            height: 250px;
            background-size: cover;
            background-position: center;
            border-top-left-radius: 0.75rem;
            border-top-right-radius: 0.75rem;
            position: relative;
        }

        .profile-cover .btn-upload {
            position: absolute;
            bottom: 1rem;
            right: 1rem;
        }

        .profile-pic-container {
            position: absolute;
            left: 2rem;
            top: 100%;
            transform: translateY(-50%);
        }

        .profile-pic {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 4px solid #fff;
            object-fit: cover;
        }

        .profile-pic-lg {
            width: 180px;
            height: 180px;
        }

        .profile-header-content {
            margin-top: 2rem;
        }

        @media (min-width: 768px) {
            .profile-pic-container {
                left: 2rem;
                top: 100%;
                transform: translateY(-50%);
            }

            .profile-header-content {
                margin-top: 0;
            }
        }

        /* Styles pour la nouvelle disposition 35%/65% */
        .col-custom-35 {
            flex: 0 0 auto;
            width: 35%;
        }

        .col-custom-65 {
            flex: 0 0 auto;
            width: 65%;
        }

        @media (max-width: 767.98px) {

            .col-custom-35,
            .col-custom-65 {
                width: 100%;
                /* Sur les petits écrans, les colonnes reprennent toute la largeur */
            }
        }

        .profile-pic-container {
            position: relative;
            display: inline-block;
        }

        .profile-pic {
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #fff;
        }

        .upload-btn {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background-color: rgba(0, 0, 0, 0.6);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .upload-btn:hover {
            background-color: rgba(0, 0, 0, 0.8);
        }

        /* Styles pour le modal de rognage */
        .modal-body .img-container {
            max-width: 100%;
            max-height: 400px;
        }

        .img-container img {
            max-width: 100%;
        }

        .cropper-container {
            width: 100% !important;
            height: 100% !important;
        }
    </style>
</head>

<body class="bg-light min-vh-100">
    <!-- Contenu HTML de la page restructurée -->
    <div class="container-fluid" style="padding-top: 2rem; padding-bottom: 2rem;">
        <!-- Marges verticales réduites ici -->
        <div id="alert-container">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <strong class="font-bold">Erreur!</strong>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <div class="alert alert-success" role="alert">
                    <strong class="font-bold">Succès!</strong>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
        </div>


        <?php if ($etudiant): ?>
            <!-- Contenu principal avec deux colonnes -->
            <div class="row g-4">
                <!-- Colonne de gauche (environ 35%) -->
                <div class="col-md-4 col-custom-35">
                    <!-- Section de la photo de couverture et du profil (entête - reste au-dessus des colonnes) -->
                    <div class="card shadow-lg mb-4 position-relative">
                        <div class="profile-cover"
                            style="background-image: url('<?php echo htmlspecialchars($etudiant['photo'] ?? '../../img/default-user.png'); ?>');">
                        </div>

                        <div class="d-flex flex-column flex-md-row align-items-center align-items-md-end p-4">
                            <div class="profile-pic-container">
                                <img id="profileImage" class="profile-pic shadow-lg"
                                    src="<?php echo htmlspecialchars($etudiant['photo'] ?? '../../img/default-user.png'); ?>"
                                    alt="Photo de l'étudiant">
                                <button type="button" class="upload-btn" data-bs-toggle="modal"
                                    data-bs-target="#uploadPhotoModal">
                                    <i class="bi bi-camera-fill"></i>
                                </button>
                            </div>
                            <div class="ms-md-5 mt-5 mt-md-0 text-center text-md-start flex-grow-1 profile-header-content"
                                style="margin-top: 2rem; text-align: left !important; font-size: 12px !important; margin-top: 0px important;">
                                <div class="fs-2 fw-bold text-dark" style="font-size: 17px !important;">
                                    <?php echo htmlspecialchars($etudiant['nom_etu'] . ' ' . $etudiant['postnom_etu'] . ' ' . $etudiant['prenom_etu']); ?>
                                </div>
                                <div class="fs-6.5">Matricule :
                                    <strong><?php echo htmlspecialchars($etudiant['matricule']); ?></strong>
                                </div>
                                <div class="fs-6.5 mb-0">
                                    <i class="bi bi-mortarboard-fill text-primary me-2"></i>
                                    Promotion :
                                    <strong><?php echo htmlspecialchars($etudiant['mention_libelle'] ?? 'Non définie'); ?></strong><br>
                                    <i class="bi bi-person-badge-fill text-primary me-2"></i>Promotion :
                                    <strong><?php echo htmlspecialchars($etudiant['nom_promotion'] ?? 'Non définie'); ?></strong>
                                </div>
                            </div>
                        </div>

                        <?php
                        // Requête affichage des promotions suivies par l'étudiant
                        $query = "SELECT p.nom_promotion, p.code_promotion FROM t_promotion p
                                      JOIN t_inscription i ON p.code_promotion = i.code_promotion
                                      WHERE i.matricule = :matricule";
                        $stmt = $pdo->prepare($query);
                        $stmt->execute(['matricule' => $etudiant['matricule']]);
                        $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        if (!empty($promotions)) {
                            echo '<ul class="list-unstyled">';
                            foreach ($promotions as $promotion) {
                                // Afficher la liste de promotions sous forme des menus (liens) avec un joli et moderne design
                                echo '<li class="mb-2" style="border-radius: 5px; width: 50px; background-color: #005b8b; text-align: center; padding: 5px; color:#000 !important;">
                                            <a href="?page=etudiant_profil&matricule=' . $matricule . '&action=promotion&code=' . $promotion['code_promotion'] . '"
                                               class="text-decoration-none text-light">
                                                ' . htmlspecialchars($promotion['code_promotion']) . '
                                            </a>
                                          </li>';
                            }
                            echo '</ul>';
                        } else {
                            echo '<p>Aucune promotion suivie.</p>';
                        }
                        ?>
                    </div>
                    <!-- À propos -->
                    <div class="card shadow-lg p-4 mb-4">
                        <h3 class="fs-5 fw-bold mb-3">À propos</h3>
                        <ul class="list-unstyled fw-bold" style="color: black !important; font-weight: bold;">
                            <li class="d-flex align-items-center mb-2">
                                <i class="bi bi-gender-ambiguous text-primary me-3"></i>
                                Sexe: <?php echo htmlspecialchars($etudiant['sexe']); ?>
                            </li>
                            <li class="d-flex align-items-center mb-2">
                                <i class="bi bi-cake2-fill text-purple me-3"></i>
                                Date de naissance: <?php echo htmlspecialchars($etudiant['date_naiss']); ?>
                            </li>
                            <li class="d-flex align-items-center mb-2">
                                <i class="bi bi-geo-alt-fill text-danger me-3"></i>
                                Lieu de naissance: <?php echo htmlspecialchars($etudiant['lieu_naiss']); ?>
                            </li>
                            <li class="d-flex align-items-center mb-2">
                                <i class="bi bi-flag-fill text-success me-3"></i>
                                Nationalité: <?php echo htmlspecialchars($etudiant['nationalite']); ?>
                            </li>
                            <li class="d-flex align-items-center mb-2">
                                <i class="fas fa-phone me-3"></i>
                                Téléphone: <?php echo htmlspecialchars($etudiant['telephone']); ?>
                            </li>
                            <li class="d-flex align-items-center mb-2">
                                <i class="fas fa-envelope text-warning me-3"></i>
                                E-mail: <?php echo htmlspecialchars($etudiant['email']); ?>
                            </li>
                            <li class="d-flex align-items-center mb-2">
                                <i class="fas fa-home text-info me-3"></i>
                                Adresse: <?php echo htmlspecialchars($etudiant['adresse']); ?>
                            </li>
                        </ul>
                    </div>

                    <!-- Informations académiques et familiales -->
                    <div class="card shadow-lg p-4 mb-4">
                        <h3 class="fs-5 fw-bold mb-3">Informations académiques et familiales</h3>
                        <div class="row g-3">
                            <div class="col-12"><strong>Section suivie:</strong>
                                <?php echo htmlspecialchars($etudiant['section_suivie'] ?? ''); ?></div>
                            <div class="col-12"><strong>Pourcentage du diplôme:</strong>
                                <?php echo htmlspecialchars($etudiant['pourcentage_dipl'] ?? ''); ?>%</div>
                            <div class="col-12"><strong>Nom du père:</strong>
                                <?php echo htmlspecialchars($etudiant['nom_pere'] ?? ''); ?></div>
                            <div class="col-12"><strong>Nom de la mère:</strong>
                                <?php echo htmlspecialchars($etudiant['nom_mere'] ?? ''); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Colonne de droite (environ 65%) avec onglets -->
                <div class="col-md-8 col-custom-65">
                    <ul class="nav nav-tabs mb-4" id="studentProfileTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="grades-tab" data-bs-toggle="tab" data-bs-target="#grades"
                                type="button" role="tab" aria-controls="grades" aria-selected="true">Relevé des
                                Cotes</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="card-tab" data-bs-toggle="tab" data-bs-target="#card" type="button"
                                role="tab" aria-controls="card" aria-selected="false">Carte d'Étudiant</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="curriculum-tab" data-bs-toggle="tab" data-bs-target="#curriculum"
                                type="button" role="tab" aria-controls="curriculum" aria-selected="false">Cursus de
                                l'Étudiant</button>
                        </li>
                        <!-- Le bouton "Modifier le profil" renvoie vers la page d'action de modification, non un onglet interne -->
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="edit-profile-tab" data-bs-toggle="tab"
                                data-bs-target="#edit-profile" type="button" role="tab" aria-controls="edit-profile"
                                aria-selected="false">Modifier le Profil</button>
                        </li>
                    </ul>

                    <div class="tab-content" id="studentProfileTabsContent">
                        <!-- Volet d'onglet: Relevé des Cotes -->
                        <div class="tab-pane fade show active" id="grades" role="tabpanel" aria-labelledby="grades-tab"
                            id="releveCotes">
                            <div class="card shadow-lg p-4 mb-4">
                                <!-- Entête professionnel et académique -->
                                <style>
                                    .entête {
                                        text-align: center;
                                        margin-bottom: 20px;
                                        font-family: "Tw Cen MT", sans-serif !important;
                                        color: black !important;
                                    }

                                    .entête p {
                                        margin: 0;
                                        line-height: 1.3;
                                    }

                                    .esu {
                                        font-family: "Edwardian Script ITC", cursive;
                                        font-size: 2.2rem;
                                    }

                                    .unilo {
                                        font-family: "Tw Cen MT", sans-serif;
                                        font-size: 1.2rem;
                                        font-weight: 900;
                                    }

                                    .contact {
                                        font-family: "Tw Cen MT", sans-serif;
                                        font-size: 1rem;
                                        font-weight: 600;

                                    }

                                    .infos-etu {
                                        font-family: "Tw Cen MT", sans-serif;
                                        font-size: 1rem;
                                        font-style: italic;
                                    }

                                    .contact2 {
                                        font-family: "Tw Cen MT", sans-serif;
                                        font-size: 1rem;
                                        font-weight: 600;
                                        border-bottom: 7px double black;
                                    }

                                    .entête {
                                        text-align: centerc !important;
                                        margin-bottom: 20px;
                                        font-family: "Tw Cen MT", sans-serif !important;
                                        color: black !important;
                                    }

                                    .entête p {
                                        margin: 0;
                                        line-height: 1.3;
                                    }

                                    .esu {
                                        font-family: "Tw Cent MT", sans-serif !important;
                                        font-size: 1rem;
                                        font-weight: 900;
                                        text-transform: uppercase;

                                    }

                                    .unilo {
                                        font-family: "Tw Cen MT", sans-serif;
                                        font-size: 0.9rem;
                                        font-weight: 900;
                                    }
                                </style>
                                <div class="entête">
                                    <table style="width: 100%; border-bottom: 7px double black;">
                                        <tr>
                                            <td style="width: 10%;">
                                                <img src="../../img/logo.gif" alt="" style="width: 80px; height: 80px;">
                                            </td>
                                            <td style="text-align: center;">
                                                <p class="esu">ENSEIGNEMENT SUPERIEUR ET UNIVERSITAIRE</p>
                                                <p class="unilo">UNIVERSITE NOTRE DAME DE LOMAMI</p>
                                                <p class="unilo">SECRETARIAT GENERAL ACADEMIQUE</p>
                                                <p class="contact">Contact : <a
                                                        href="mailto:sgac@unilo.net">sgac@unilo.net</a></p>
                                                <p class="contact">Téléphone : +243 813 677 556 / 898 472 255</p>

                                            </td>
                                            <td style="width: 10%;">
                                                <img src="<?php echo $etudiant['photo'] ?? '../../img/default-user.png'; ?>"
                                                    alt="" style="width: 80px;">
                                            </td>
                                        </tr>
                                    </table>
                                    <table style="width: 100%;">
                                        <tr>
                                            <td>
                                                <p class="infos-etu">Domaine des
                                                    <span><?php echo htmlspecialchars($domaine); ?></span>
                                                </p>
                                            </td>
                                            <td>
                                                <p class="infos-etu">Filière :
                                                    <span><?php echo htmlspecialchars($filiere); ?></span>
                                                </p>
                                            </td>
                                            <td>
                                                <p class="infos-etu">Mention :
                                                    <span><?php echo htmlspecialchars($mention); ?></span>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="titre-doc">
                                    <style>
                                        .titre-doc .titre {
                                            line-height: 1.7;
                                            font-size: 1.6rem;
                                            font-weight: 900;
                                            margin-top: 0;
                                        }
                                    </style>
                                    <p class="text-center fw-bold titre" style="text-decoration: underline;">RELEVE DE NOTES
                                    </p>
                                    <p class="text-center">
                                        N° <span class="fw-bold">
                                            <?php
                                            // Extraire l'année de début de l'année académique formatée (ex: 2023-2024 -> 2023)
                                            $annee_debut = 'XXXX';
                                            if (isset($annee_academique_formatee) && preg_match('/^(\d{4})/', $annee_academique_formatee, $matches)) {
                                                $annee_debut = $matches[1];
                                            }

                                            // Utiliser l'ID de l'étudiant pour un numéro unique et le formater
                                            // Assurez-vous que la colonne 'id_etudiant' est sélectionnée dans votre requête initiale.
                                            // Si le nom de la colonne est différent (ex: 'id'), ajustez-le ici.
                                            $numero_document = '000'; // Valeur par défaut
                                            if (isset($etudiant['id_etudiant'])) {
                                                $numero_document = str_pad($etudiant['id_etudiant'], 3, '0', STR_PAD_LEFT);
                                            }

                                            // Générer le numéro complet du document
                                            echo $numero_document . "/SGA/UNILO/{$annee_debut}/";
                                            ?>
                                        </span>
                                    </p>
                                    <br>
                                </div>
                                <div class="texte">
                                    <style>
                                        .texte {
                                            text-align: justify;
                                        }

                                        .texte span {
                                            font-weight: bold;
                                        }
                                    </style>
                                    <p>
                                        <?php
                                        if ($etudiant['sexe'] === 'M') {
                                            $titre = 'Monsieur';
                                        } else {
                                            $titre = 'Madame';
                                        }
                                        ?>
                                        <?php echo $titre . ' ' . htmlspecialchars($etudiant['nom_etu'] . ' ' . $etudiant['postnom_etu'] . ' ' . $etudiant['prenom_etu']); ?>,
                                        né à <span><?php echo htmlspecialchars($etudiant['lieu_naiss']); ?></span>, le
                                        <span><?php
                                        // S'assurer que l'extension intl est activée pour que cela fonctionne
                                        if (!empty($etudiant['date_naiss'])) {
                                            try {
                                                $date = new DateTime($etudiant['date_naiss']);
                                                // Formatteur pour afficher la date en français (ex: 12 Août 2025)
                                                $formatter = new IntlDateFormatter('fr_FR', IntlDateFormatter::LONG, IntlDateFormatter::NONE);
                                                $formatter->setPattern('d MMMM yyyy');
                                                echo htmlspecialchars($formatter->format($date));
                                            } catch (Exception $e) {
                                                // En cas d'erreur de date, afficher la date originale
                                                echo htmlspecialchars($etudiant['date_naiss']);
                                            }
                                        }
                                        ?></span>, a obtenu à l'issue du premier et second semestre, de l'année
                                        académique <span><?php echo htmlspecialchars($annee_academique_formatee); ?></span>,
                                        les résultats obtenus régulièrement l’ensemble des <span>UE(et ECUE)</span> prévus
                                        au programme de
                                        <span><?php echo htmlspecialchars($infos_etudiant['nom_promotion']); ?></span>,
                                        en <span><?php echo htmlspecialchars($infos_etudiant['nom_domaine']); ?></span>,
                                        Filière de
                                        <span><?php echo htmlspecialchars($infos_etudiant['nom_filiere']); ?></span>,
                                        mention <span><?php echo htmlspecialchars($mention); ?></span>.

                                    </p>
                                </div>
                                <style>
                                    table.word-style {
                                        border-collapse: collapse;
                                        width: 100%;
                                        font-family: Arial, sans-serif;
                                        font-size: 14px;
                                    }

                                    table.word-style th,
                                    table.word-style td {
                                        border: 1px solid #000;
                                        padding-left: 5px;
                                        padding-right: 5px;
                                        /* Aucune marge interne */
                                        vertical-align: middle;
                                    }

                                    table.word-style th {
                                        background-color: #f2f2f2;
                                        /* Fond clair sur l’entête */
                                        font-weight: bold;
                                    }

                                    /* Optionnel : mettre en surbrillance la ligne au survol (pas obligatoire, simple confort visuel) */
                                    table.word-style tbody tr:hover {
                                        background-color: #e6f2ff;
                                    }

                                    /* Style pour les notes insuffisantes, couleur rouge fond clair */
                                    .table-danger {
                                        background-color: #f8d7da;
                                        color: #842029;
                                    }

                                    /* Style type Word : pas de marges internes excessives, bordures fines */
                                    table.word-style {
                                        border-collapse: collapse;
                                        font-family: "Century Gothic";
                                        font-size: 12px;
                                        margin: 10px 0;
                                        width: 100%;
                                    }

                                    table.word-style th,
                                    table.word-style td {
                                        border: 1px solid #000;
                                        padding: 4px 6px;
                                    }

                                    .table-danger {
                                        background-color: #f8d7da !important;
                                    }

                                    /* Centrer texte des crédits, cotes, pondérées */
                                    table.word-style td:nth-child(3),
                                    table.word-style td:nth-child(4),
                                    table.word-style td:nth-child(5) {
                                        text-align: center;
                                    }
                                </style>

                                <?php

                                // $stmt_notes = $pdo->prepare($sql_notes);
                                // $stmt_notes->execute([$matricule, $annee_academique_courante, $domaine_id, $filiere_id]);
                                // $academic_record = $stmt_notes->fetchAll(PDO::FETCH_ASSOC);
                                // // Matricule dynamique
                                $sql = "SELECT * FROM vue_programme_etudiant 
                                    WHERE matricule = ?
                                    AND id_annee = ?
                                    AND id_domaine = ?
                                    AND idFiliere = ?
                                    AND code_promotion = ?";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute([$matricule, $annee_academique_courante, $domaine_id, $filiere_id, $code_promotion]);
                                $academic_record = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                $row_class = '';

                                // Définir la classe uniquement si cote_s1 existe et est inférieure à 10
                                if (isset($note['cote_s1']) && $note['cote_s1'] < 10) {
                                    $row_class = 'table-danger';
                                }
                                ?>


                                <div class="table-responsive">
                                    <table class="word-style" style="border-collapse: collapse; width: 100%;">
                                        <thead>
                                            <tr>
                                                <th style="border:1px solid #000; padding:4px;">Code UE</th>
                                                <th style="border:1px solid #000; padding:4px;">Unités d’enseignements (UE)
                                                    et éléments constitutifs (ECUE)</th>
                                                <th style="border:1px solid #000; padding:4px;">Crédits</th>
                                                <th style="border:1px solid #000; padding:4px;">Notes (/20)</th>
                                                <th style="border:1px solid #000; padding:4px;">Notes pondérées<br><span
                                                        style="font-size:10px;">(Nbre crédits x 20)</span></th>
                                                <th style="border:1px solid #000; padding:4px;">Décision<br><span
                                                        style="font-size:10px;">(validé ou non)</span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php for ($semestre = 1; $semestre <= 2; $semestre++): ?>
                                                <tr>
                                                    <td colspan="6"
                                                        style="padding:3px; font-weight:bold; text-align:center; background-color:#005e91; color:#fff;">
                                                        SEMESTRE <?= $semestre ?>
                                                    </td>
                                                </tr>
                                                <?php
                                                // Grouper les ECUE par UE pour ce semestre
                                                $ues = [];
                                                foreach ($academic_record as $note) {
                                                    if ($note['id_semestre'] == $semestre) {
                                                        $code_ue = $note['code_ue'];
                                                        if (!isset($ues[$code_ue])) {
                                                            $ues[$code_ue] = [
                                                                'ue' => $note['unite_enseignement'],
                                                                'credits_ue' => $note['credits_ue'] ?? $note['credits'],
                                                                'ecues' => [],
                                                                // Pour UE sans ECUE
                                                                'has_ecue' => false,
                                                                'ue_note' => null,
                                                                'ue_credits' => $note['credits_ue'] ?? $note['credits'],
                                                                'ue_ponderee' => null,
                                                                'ue_decision' => null,
                                                            ];
                                                        }
                                                        // Si l'ECUE existe (element_constitutif non vide)
                                                        if (!empty($note['element_constitutif'])) {
                                                            $ues[$code_ue]['ecues'][] = $note;
                                                            $ues[$code_ue]['has_ecue'] = true;
                                                        } else {
                                                            // UE sans ECUE, stocker les infos pour affichage direct
                                                            $cote = ($semestre == 1) ? ($note['cote_s1'] ?? 0) : ($note['cote_s2'] ?? 0);
                                                            $credits_ue = $note['credits_ue'] ?? $note['credits'];
                                                            $ponderee = $credits_ue * $cote;
                                                            $decision = ($cote >= 10) ? 'Validé' : 'Non validé';
                                                            $ues[$code_ue]['ue_note'] = $cote;
                                                            $ues[$code_ue]['ue_credits'] = $credits_ue;
                                                            $ues[$code_ue]['ue_ponderee'] = $ponderee;
                                                            $ues[$code_ue]['ue_decision'] = $decision;
                                                        }
                                                    }
                                                }
                                                if (empty($ues)) {
                                                    echo '<tr><td colspan="6" class="text-center">Aucune donnée pour ce semestre.</td></tr>';
                                                }
                                                ?>
                                                <?php foreach ($ues as $code_ue => $ue_data): ?>
                                                    <?php if ($ue_data['has_ecue']): ?>
                                                        <?php $ecues = $ue_data['ecues']; ?>
                                                        <?php $rowspan = count($ecues) + 1; // +1 for UE row ?>
                                                        <!-- Ligne Unité d’enseignement -->
                                                        <tr>
                                                            <td rowspan="<?= $rowspan ?>"
                                                                style="border:1px solid #000; padding:4px; text-align:center; vertical-align:middle;">
                                                                <?= htmlspecialchars($code_ue); ?>
                                                            </td>
                                                            <td colspan="5"
                                                                style="border:1px solid #000; padding:4px; font-weight:bold;">
                                                                <?= htmlspecialchars($ue_data['ue']); ?>
                                                            </td>
                                                        </tr>
                                                        <!-- Lignes ECUE -->
                                                        <?php foreach ($ecues as $note): ?>
                                                            <?php
                                                            $cote = ($semestre == 1) ? ($note['cote_s1'] ?? 0) : ($note['cote_s2'] ?? 0);
                                                            $credits_ecue = $note['coefficient'] ?? $note['credits'];
                                                            $ponderee = $credits_ecue * $cote;
                                                            $row_class = ($cote < 10) ? 'table-danger' : '';
                                                            ?>
                                                            <tr class="<?= $row_class; ?>">
                                                                <td style="border:1px solid #000; padding:4px;">
                                                                    <?= htmlspecialchars($note['element_constitutif']); ?>
                                                                </td>
                                                                <td style="border:1px solid #000; padding:4px; text-align:center;">
                                                                    <?= htmlspecialchars($credits_ecue); ?>
                                                                </td>
                                                                <td style="border:1px solid #000; padding:4px; text-align:center;">
                                                                    <?= htmlspecialchars($cote); ?>
                                                                </td>
                                                                <td style="border:1px solid #000; padding:4px; text-align:center;">
                                                                    <?= number_format($ponderee, 2); ?>
                                                                </td>
                                                                <td style="border:1px solid #000; padding:4px; text-align:center;">
                                                                    <?= ($cote >= 10) ? 'Validé' : 'Non validé'; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <!-- UE sans ECUE -->
                                                        <?php
                                                        $row_class = ($ue_data['ue_note'] < 10) ? 'table-danger' : '';
                                                        ?>
                                                        <tr class="<?= $row_class; ?>">
                                                            <td style="border:1px solid #000; padding:4px; text-align:center;">
                                                                <?= htmlspecialchars($code_ue); ?>
                                                            </td>
                                                            <td style="border:1px solid #000; padding:4px; font-weight:bold;">
                                                                <?= htmlspecialchars($ue_data['ue']); ?>
                                                            </td>
                                                            <td style="border:1px solid #000; padding:4px; text-align:center;">
                                                                <?= htmlspecialchars($ue_data['ue_credits']); ?>
                                                            </td>
                                                            <td style="border:1px solid #000; padding:4px; text-align:center;">
                                                                <?= htmlspecialchars($ue_data['ue_note']); ?>
                                                            </td>
                                                            <td style="border:1px solid #000; padding:4px; text-align:center;">
                                                                <?= number_format($ue_data['ue_ponderee'] ?? 0, 2); ?>
                                                            </td>
                                                            <td style="border:1px solid #000; padding:4px; text-align:center;">
                                                                <?= $ue_data['ue_decision']; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endfor; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pied du relevé des cotes : synthèse et signatures (mise en forme proche de la capture) -->
                                <?php
                                    // Calculs synthétiques
                                    $total_credits = 0;
                                    $credits_valides = 0;
                                    $total_ponderees = 0;

                                    // Pour éviter de compter plusieurs fois les crédits d'une même UE/ECUE, on utilise un tableau de suivi
                                    $credits_counted = [];
                                    $credits_valides_counted = [];

                                    foreach ($academic_record as $note) {
                                        // On considère chaque ECUE ou UE distincte par son code_ue + element_constitutif (ou juste code_ue si pas d'ECUE)
                                        $key = $note['code_ue'] . '|' . ($note['element_constitutif'] ?? '');

                                        // Crédits pour cette ligne
                                        $credits = isset($note['coefficient']) ? (float)$note['coefficient'] : (float)($note['credits'] ?? 0);

                                        // Cote annuelle (si existe), sinon somme S1+S2, sinon 0
                                        if (isset($note['cote'])) {
                                            $cote = (float)$note['cote'];
                                        } else {
                                            $cote = (isset($note['cote_s1']) ? (float)$note['cote_s1'] : 0) + (isset($note['cote_s2']) ? (float)$note['cote_s2'] : 0);
                                            // Si les deux sont 0, alors on regarde s'il y a une seule cote
                                            if ($cote == 0 && isset($note['cote_s1'])) $cote = (float)$note['cote_s1'];
                                            if ($cote == 0 && isset($note['cote_s2'])) $cote = (float)$note['cote_s2'];
                                        }

                                        // Total crédits (on compte chaque ECUE/UE une seule fois)
                                        if (!isset($credits_counted[$key])) {
                                            $total_credits += $credits;
                                            $credits_counted[$key] = true;
                                        }

                                        // Crédits validés (si la cote annuelle >= 10)
                                        if ($cote >= 10 && !isset($credits_valides_counted[$key])) {
                                            $credits_valides += $credits;
                                            $credits_valides_counted[$key] = true;
                                        }

                                        // Total pondérées annuel
                                        $total_ponderees += $cote * $credits;
                                    }

                                    $max_ponderees = $total_credits * 20;
                                    $moyenne = $total_credits > 0 ? $total_ponderees / $total_credits : 0;
                                ?>
                                <div class="row mt-4" style="font-family: 'Century Gothic', Arial, sans-serif; font-size: 14px;">
                                    <div class="col-6" style="vertical-align: top;">
                                        <div style="margin-bottom: 6px;">
                                            Total crédits : 
                                            <span>
                                                <?php echo $total_credits; ?>
                                            </span>
                                        </div>
                                        <div style="margin-bottom: 6px;">
                                            Crédits validés : 
                                            <span>
                                                <?php echo $credits_valides; ?>
                                            </span>
                                        </div>
                                        <div style="margin-bottom: 6px;">
                                            Total notes pondérées annuel : 
                                            <span>
                                                <?php echo number_format($total_ponderees, 2) . " /" . $max_ponderees . " (" . $total_credits . "x20)"; ?>
                                            </span>
                                        </div>
                                        <div style="margin-bottom: 6px;">
                                            Moyenne annuelle pondérée : 
                                            <span>
                                                <?php echo number_format($moyenne, 2) . " /20"; ?>
                                            </span>
                                        </div>
                                        <div style="margin-top: 30px; font-weight: bold; text-align: center;">
                                            LE DOYEN DE LA FACULTE
                                        </div>
                                        <div style="margin-top: 30px; text-align: center; font-weight: bold;">
                                            Prof Abbé Pierre ILUNGA KALE
                                        </div>
                                    </div>
                                    <div class="col-6" style="vertical-align: top;">
                                        <div style="margin-bottom: 6px;">
                                            Mention : 
                                            <span>
                                                <?php
                                                    $mention = "Non attribuée";
                                                    if ($moyenne >= 16) $mention = "Distinction";
                                                    elseif ($moyenne >= 14) $mention = "Grande Satisfaction";
                                                    elseif ($moyenne >= 12) $mention = "Satisfaction";
                                                    elseif ($moyenne >= 10) $mention = "Passable";
                                                    echo $mention . " définitive de crédits";
                                                ?>
                                            </span>
                                        </div>
                                        <div style="margin-bottom: 6px;">
                                            Décision du jury
                                        </div>
                                        <div style="margin-top: 30px; font-weight: bold;">
                                            Fait à Kabinda le ......../......../2025
                                        </div>
                                        <div style="margin-top: 30px; font-weight: bold;">
                                            LE SECRETAIRE GENERAL ACADEMIQUE
                                        </div>
                                        <div style="margin-top: 30px; font-weight: bold;">
                                            Prof Mgr Lambert KANKENZA MUTEBA
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end mt-4">
                                <a href="releve_cotes.php?matricule=<?php echo urlencode($etudiant['matricule']); ?>"
                                    target="_blank" class="btn btn-gradient-primary btn-lg rounded-pill shadow-sm"
                                    style="background: linear-gradient(90deg, #005e91 0%, #00b4d8 100%); color: #fff; border: none; font-weight: 600; letter-spacing: 1px;">
                                    <i class="fas fa-print me-2"></i> Imprimer le relevé de notes
                                </a>
                            </div>
                        </div>

                        <!-- Volet d'onglet: Carte d'Étudiant (Espace réservé) -->
                        <div class="tab-pane fade" id="card" role="tabpanel" aria-labelledby="card-tab">
                            <div class="card shadow-lg p-4 mb-4">
                                <h3 class="fs-5 fw-bold mb-3">Carte d'Étudiant</h3>
                                <?php
                                include_once 'carte_etudiant.php';
                                ?>
                                <!-- Ajoutez ici le code pour la carte d'étudiant -->
                            </div>
                        </div>

                        <!-- Volet d'onglet: Cursus de l'Étudiant (Espace réservé) -->
                        <div class="tab-pane fade" id="curriculum" role="tabpanel" aria-labelledby="curriculum-tab">
                            <div class="card shadow-lg p-4 mb-4">
                                <h3 class="fs-5 fw-bold mb-3">Cursus de l'Étudiant</h3>
                                <?php
                                include_once 'cursus_etudiant.php';
                                ?>
                                <!-- Ajoutez ici le code pour le cursus de l'étudiant -->
                            </div>
                        </div>
                        <!-- Volet d'onglet: Modifier le Profil -->
                        <div class="tab-pane fade" id="edit-profile" role="tabpanel" aria-labelledby="edit-profile-tab">
                            <div class="card shadow-lg p-4 mb-4">

                                <!-- Formulaire de mise à jour du profil en mode "édition" (s'affiche en plein écran) -->
                                <div class="card shadow-lg p-4 mb-4">
                                    <h3 class="fs-5 fw-bold mb-3">Modifier le profil</h3>
                                    <form method="POST" enctype="multipart/form-data">

                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="nom_etu" class="form-label">Nom</label>
                                                <input type="text" class="form-control" id="nom_etu" name="nom_etu"
                                                    value="<?php echo htmlspecialchars($etudiant['nom_etu'] ?? ''); ?>"
                                                    required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="postnom_etu" class="form-label">Postnom</label>
                                                <input type="text" class="form-control" id="postnom_etu" name="postnom_etu"
                                                    value="<?php echo htmlspecialchars($etudiant['postnom_etu'] ?? ''); ?>"
                                                    required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="prenom_etu" class="form-label">Prénom</label>
                                                <input type="text" class="form-control" id="prenom_etu" name="prenom_etu"
                                                    value="<?php echo htmlspecialchars($etudiant['prenom_etu'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="sexe" class="form-label">Sexe</label>
                                                <select class="form-select" id="sexe" name="sexe">
                                                    <option value="M" <?php if (($etudiant['sexe'] ?? '') === 'M')
                                                        echo 'selected'; ?>>Masculin
                                                    </option>
                                                    <option value="F" <?php if (($etudiant['sexe'] ?? '') === 'F')
                                                        echo 'selected'; ?>>Féminin
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="date_naiss_etu" class="form-label">Date de naissance</label>
                                                <input type="date" class="form-control" id="date_naiss_etu"
                                                    name="date_naiss_etu"
                                                    value="<?php echo htmlspecialchars($etudiant['date_naiss'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="lieu_naiss_etu" class="form-label">Lieu de naissance</label>
                                                <input type="text" class="form-control" id="lieu_naiss_etu"
                                                    name="lieu_naiss_etu"
                                                    value="<?php echo htmlspecialchars($etudiant['lieu_naiss'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="nationalite" class="form-label">Nationalité</label>
                                                <input type="text" class="form-control" id="nationalite" name="nationalite"
                                                    value="<?php echo htmlspecialchars($etudiant['nationalite'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="adresse_etu" class="form-label">Adresse</label>
                                                <input type="text" class="form-control" id="adresse_etu" name="adresse_etu"
                                                    value="<?php echo htmlspecialchars($etudiant['adresse'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="telephone_etu" class="form-label">Téléphone</label>
                                                <input type="text" class="form-control" id="telephone_etu"
                                                    name="telephone_etu"
                                                    value="<?php echo htmlspecialchars($etudiant['telephone'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="email_etu" class="form-label">E-mail</label>
                                                <input type="email" class="form-control" id="email_etu" name="email_etu"
                                                    value="<?php echo htmlspecialchars($etudiant['email'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="section_suivie" class="form-label">Section suivie</label>
                                                <input type="text" class="form-control" id="section_suivie"
                                                    name="section_suivie"
                                                    value="<?php echo htmlspecialchars($etudiant['section_suivie'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="pourcentage_dipl" class="form-label">Pourcentage du
                                                    diplôme</label>
                                                <input type="number" class="form-control" id="pourcentage_dipl"
                                                    name="pourcentage_dipl"
                                                    value="<?php echo htmlspecialchars($etudiant['pourcentage_dipl'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="nom_pere" class="form-label">Nom du père</label>
                                                <input type="text" class="form-control" id="nom_pere" name="nom_pere"
                                                    value="<?php echo htmlspecialchars($etudiant['nom_pere'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="nom_mere" class="form-label">Nom de la mère</label>
                                                <input type="text" class="form-control" id="nom_mere" name="nom_mere"
                                                    value="<?php echo htmlspecialchars($etudiant['nom_mere'] ?? ''); ?>">
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-end pt-4 mt-4 border-top">
                                            <button type="submit" class="btn btn-primary rounded-pill"
                                                name="update_profile">Enregistrer</button>
                                        </div>
                                    </form>
                                </div>
                                <!-- Ajoutez ici le code pour modifier le profil de l'étudiant -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal pour le téléchargement et le rognage de la photo -->
    <div class="modal fade" id="uploadPhotoModal" tabindex="-1" aria-labelledby="uploadPhotoModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form id="updatePhotoForm" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="uploadPhotoModalLabel">Mettre à jour la photo de profil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body text-center">
                        <div class="img-container d-flex justify-content-center align-items-center bg-light border p-3"
                            style="height: 400px; width: 100%; overflow: hidden;">
                            <img id="imageToCrop"
                                src="<?php echo htmlspecialchars($etudiant['photo'] ?? '../../img/default-user.png'); ?>"
                                alt="Image à rogner" style="max-width: 100%; max-height: 100%;">
                        </div>
                    </div>
                    <div class="modal-footer d-flex justify-content-between">
                        <button type="button" class="btn btn-secondary rounded-pill"
                            data-bs-dismiss="modal">Annuler</button>
                        <div>
                            <input type="file" id="fileInput" name="photo" accept="image/*" class="d-none">
                            <input type="hidden" id="croppedImageDataInput" name="cropped_image_data">
                            <button type="button" class="btn btn-info rounded-pill" id="selectImageBtn">
                                <i class="fas fa-folder-open me-2"></i>Choisir une image
                            </button>
                            <button type="button" class="btn btn-primary rounded-pill" id="cropAndApplyBtn" disabled>
                                <i class="fas fa-check me-2"></i>Appliquer
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- Bootstrap JS Bundle avec Popper - Local -->
    <script src="../js/bootstrap.bundle.min.js"></script>
    <!-- Cropper.js - Local -->
    <script src="../js/cropper.min.js"></script>
    <script>
        // Fonction pour afficher une alerte de manière dynamique
        function showAlert(message, type) {
            const alertContainer = document.getElementById('alert-container');
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = `
                <strong class="font-bold">${type === 'success' ? 'Succès!' : 'Erreur!'}</strong>
                <span>${message}</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            alertContainer.appendChild(alertDiv);
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Activation de l'onglet par défaut (grades-tab)
            var gradesTab = new bootstrap.Tab(document.querySelector('#grades-tab'));
            gradesTab.show();

            // Variables globales
            const uploadPhotoModal = new bootstrap.Modal(document.getElementById('uploadPhotoModal'));
            const imageToCrop = document.getElementById('imageToCrop');
            const fileInput = document.getElementById('fileInput');
            const selectImageBtn = document.getElementById('selectImageBtn');
            const cropAndApplyBtn = document.getElementById('cropAndApplyBtn');
            let cropper = null;

            // Champ caché dans le formulaire principal pour la photo
            const croppedImageDataInput = document.getElementById('croppedImageDataInput');
            const profileImage = document.getElementById('profileImage');

            // Déclenche le clic sur l'input de fichier quand on clique sur le bouton "Choisir une image"
            selectImageBtn.addEventListener('click', function () {
                fileInput.click();
            });

            // Gère le changement de fichier
            fileInput.addEventListener('change', function (e) {
                const files = e.target.files;
                if (files && files.length > 0) {
                    const reader = new FileReader();
                    reader.onload = function (event) {
                        // Affiche l'image dans le modal
                        imageToCrop.src = event.target.result;

                        // Initialise Cropper.js après que l'image a été chargée
                        if (cropper) {
                            cropper.destroy();
                        }
                        cropper = new Cropper(imageToCrop, {
                            // Ratio 3:4 pour le format passeport
                            aspectRatio: 3 / 4,
                            viewMode: 1,
                            dragMode: 'move',
                            autoCropArea: 0.8,
                            responsive: true,
                            background: true,
                            rotatable: false,
                            scalable: false
                        });

                        cropAndApplyBtn.disabled = false;
                    };
                    reader.readAsDataURL(files[0]);
                }
            });

            cropAndApplyBtn.addEventListener('click', function () {
                if (cropper) {
                    // Récupère les données de l'image rognée en base64
                    const croppedCanvas = cropper.getCroppedCanvas({
                        width: 300,
                        height: 400,
                    });
                    const croppedImageData = croppedCanvas.toDataURL('image/jpeg');

                    // Met à jour le champ caché avec les données de l'image
                    document.getElementById('croppedImageDataInput').value = croppedImageData;

                    // Soumet le formulaire
                    document.getElementById('updatePhotoForm').submit();

                    // Pas besoin de fermer le modal ici, la soumission de la page le fera
                }
            });

            // Réinitialise le cropper quand le modal est fermé
            document.getElementById('uploadPhotoModal').addEventListener('hidden.bs.modal', function () {
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
                imageToCrop.src = ''; // Réinitialise l'image
                fileInput.value = null; // Réinitialise l'input de fichier
                cropAndApplyBtn.disabled = true;
            });
        });
    </script>
</body>

</html>