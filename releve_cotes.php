<?php
require_once __DIR__ . '/includes/db_config.php';

use Vtiful\Kernel\Format;
// carte_etudiant.php

// Ce fichier est destiné à être inclus dans la page principale du profil de l'étudiant.
// Les variables PHP comme $etudiant sont censées être déjà définies
// par le script principal (etudiant_profil.php) avant que ce fichier ne soit inclus.

$etudiant = null;
$academic_record = [];
$error = '';
$success = '';
$matricule = $_GET['matricule'];

// Assurez-vous que la connexion à la base de données ($pdo) est disponible.
// Si ce fichier est inclus après 'db_config.php' dans le script principal, $pdo devrait être défini.
// Sinon, vous pourriez avoir besoin de le 'require_once' ici.
// require_once __DIR__ . '/../includes/db_config.php'; // Décommenter si $pdo n'est pas disponible
$annee_academique_courante_libelle = '';
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
    $annee_academique_courante_libelle = date('Y', strtotime($annee['date_debut'])) . '-' . date('Y', strtotime($annee['date_fin']));

    // $stmt_annee_id = $pdo->query("SELECT valeur FROM t_configuration WHERE cle = 'annee_academique_courante'");
    // $id_annee_courante = $stmt_annee_id->fetchColumn();
} catch (PDOException $e) {
    error_log("Error fetching current academic year: " . $e->getMessage());
    $annee_academique_courante_libelle = date('Y') . '-' . (date('Y') + 1); // Fallback
}



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
    // 1. Récupération de l'année académique courante depuis la table t_configuration

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
    $annee_academique_courante_libelle = date('Y', strtotime($annee['date_debut'])) . '-' . date('Y', strtotime($annee['date_fin']));


    if (!$annee_academique_courante) {
        throw new Exception("L'année académique courante n'est pas configurée.");
    }

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

        // 5. Récupération du relevé de notes
        if ($annee_academique_courante) {
            $sql_notes = "
                SELECT
                    ue.libelle AS libelle_ue,
                    ec.libelle AS libelle_ec,
                    ec.coefficient AS credits_ec,
                    c.cote_s1,
                    c.cote_s2
                FROM t_cote c
                JOIN t_element_constitutif ec ON c.id_ec = ec.id_ec
                JOIN t_unite_enseignement ue ON ec.id_ue = ue.id_ue
                INNER JOIN (
                    SELECT id_ec, MAX(id_note) AS dernier_id
                    FROM t_cote
                    WHERE matricule = ?
                    AND id_annee = ?
                    GROUP BY id_ec
                ) last_notes ON c.id_note = last_notes.dernier_id
                ORDER BY ue.libelle, ec.libelle
            ";
            $stmt_notes = $pdo->prepare($sql_notes);
            $stmt_notes->execute([$matricule, $annee_academique_courante]);
            $academic_record = $stmt_notes->fetchAll(PDO::FETCH_ASSOC);
        }

    }
} catch (PDOException $e) {
    $error = "Erreur lors de la récupération des données : " . $e->getMessage();
} catch (Exception $e) {
    $error = "Erreur de configuration : " . $e->getMessage();
    $annee_academique_courante = null;
}
// Récupérer la filière de l'étudiant à partir de son inscription active la plus récente
$filiere_nom = 'Non définie';
try {
    $stmt_filiere_nom = $pdo->prepare("
        SELECT f.nomFiliere
        FROM t_inscription i
        JOIN t_filiere f ON i.id_filiere = f.idFiliere
        WHERE i.matricule = ? AND i.statut = 'Actif'
        ORDER BY i.date_inscription DESC
        LIMIT 1
    ");
    // Check if $etudiant is an array and contains the key 'matricule'
    if (is_array($etudiant) && isset($etudiant['matricule'])) {
        $stmt_filiere_nom->execute([$etudiant['matricule']]);
        $filiere_nom = $stmt_filiere_nom->fetchColumn() ?: 'Non définie';
    } else {
        $filiere_nom = 'Non définie'; // Provide a default value
    }
} catch (PDOException $e) {
    error_log("Error fetching student's filiere name: " . $e->getMessage());
}

// Fonction pour générer un QR code de base (simulé ou pour une vraie implémentation)
// Pour une vraie implémentation, vous auriez besoin d'une bibliothèque de génération de QR code.
// Ici, nous utilisons une URL d'API de QR code public à titre d'exemple.
function generateQRCodeUrl($data)
{
    // Cette URL est un service tiers et doit être utilisée avec prudence en production.
    // Pour une production, envisagez une solution côté serveur ou une bibliothèque JS sécurisée.
    return "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($data);
}

$qr_data = "Matricule: " . (isset($etudiant['matricule']) ? $etudiant['matricule'] : 'N/A') . "\nNom: " . (isset($etudiant['nom_etu']) ? $etudiant['nom_etu'] : 'N/A') . " " . (isset($etudiant['postnom_etu']) ? $etudiant['postnom_etu'] : 'N/A') . " " . (isset($etudiant['prenom_etu']) ? $etudiant['prenom_etu'] : 'N/A');
$qr_code_url = generateQRCodeUrl($qr_data);

// Récupérer la mention et la promotion depuis l'objet $etudiant déjà rempli
$mention_libelle = $etudiant['mention_libelle'] ?? 'Non définie';
$promotion_nom = $etudiant['nom_promotion'] ?? 'Non définie';


// Assuming $etudiant['matricule'] is defined earlier in the main script (etudiant_profil.php)
$matricule = $etudiant['matricule'] ?? null; // Use null coalescing operator to provide a default value if $etudiant['matricule'] is not set

$sql_filiere = "SELECT nom_filiere FROM vue_etudiant_inscription WHERE matricule = ?";
$stmt_filiere = $pdo->prepare($sql_filiere);
$stmt_filiere->execute([$matricule]);
$filiere_etudiant['nom_filiere'] = $stmt_filiere->fetchColumn() ?: 'Non défini';
$filiere = $filiere_etudiant['nom_filiere'];
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

$sqlEtud = "SELECT * FROM t_etudiant WHERE matricule = ?";
$stmtEtud = $pdo->prepare($sqlEtud);
$stmtEtud->execute([$matricule]);
$ResultEtud = $stmtEtud->fetch(PDO::FETCH_ASSOC);

?>
<div class="card shadow-lg p-4 mb-4" style="position: relative; overflow: hidden;">
    <!-- Filigrane (watermark) sous forme d'image -->
    <div style="
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        opacity: 0.3;
        z-index: 0;
        pointer-events: none;
        width: 90%;
        height: auto;
        text-align: center;
        margin-top: 150px;
    ">
        <img src="<?php echo ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $_SERVER['HTTP_HOST']; ?>/img/logo.gif" alt="Filigrane UNILO" style="width: 100%; height: auto; filter: grayscale(100%);">
    </div>
    <!-- Entête professionnel et académique -->
    <style>
        .entête {
            text-align: centerc !important;
            margin-bottom: 20px;
            font-family: "Tw Cen MT", sans-serif !important;
            color: black !important;
            font-size: 13px;
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

        .contact {
            font-family: "Tw Cen MT", sans-serif;
            font-size: 1rem;
            font-weight: 600;

        }

        .infos-etu {
            font-family: "Tw Cen MT", sans-serif;
            font-size: 12px;
            font-style: italic;
        }

        .contact2 {
            font-family: "Tw Cen MT", sans-serif;
            font-size: 1rem;
            font-weight: 600;
            border-bottom: 7px double black;
        }
    </style>
    <div class="entête" style="border-bottom: #000 solid 1px;">
        <table style="width: 100%; border-bottom: 7px double black;">
            <tr>
                <td style="width: 10%;">
                    <img src="<?php echo ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $_SERVER['HTTP_HOST']; ?>/img/logo.gif" alt="" style="width: 80px; height: 80px;">
                </td>
                <td style="text-align: center;">
                    <p class="esu">ENSEIGNEMENT SUPERIEUR ET UNIVERSITAIRE</p>
                    <p class="unilo">UNIVERSITE NOTRE DAME DE LOMAMI</p>
                    <p class="unilo">SECRETARIAT GENERAL ACADEMIQUE</p>
                    <p class="contact">Contact : <a href="mailto:sgac@unilo.net">sgac@unilo.net</a></p>
                    <p class="contact">Téléphone : +243 813 677 556 / 898 472 255</p>

                </td>
                <td style="width: 10%;">
                    <img src="<?php
                        $photo = $ResultEtud['photo'] ?? '../img/default-user.png';
                        // If photo path does not start with http, make it absolute
                        if (strpos($photo, 'http') !== 0) {
                            $photo = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $_SERVER['HTTP_HOST'] . (substr($photo, 0, 1) === '/' ? '' : '/') . ltrim($photo, './');
                        }
                        echo $photo;
                    ?>" alt=""
                        style="width: 80px;">
                </td>
            </tr>
        </table>
        <table style="width: 100%; margin-bottom: 0px; font-size: 9px;">
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
                font-family: 'Century Gothic' !important;
            }
        </style>
        <div class="text-center fw-bold titre" style="text-decoration: underline; 
                font-size: 1.6rem; margin-top: 0; line-height: 1;
                font-weight: 900;
                margin-top: 0; text-align: center; font-family: 'Century Gothic', sans-serif;">RELEVE DE NOTES
        </div>
        <div
            style="text-align: center; font-size: 0.7rem; font-weight: 600; margin-top: 0; font-family: 'Century Gothic', sans-serif; font-style: italic; margin-bottom: 10px;">
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
        </div>

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
            padding: 0 !important;
            padding-left: 5px;
            padding-right: 5px;
            /* Aucune marge interne */
            vertical-align: middle;
        }

        p {
            font-family: 'Century Gothic', sans-serif;
            font-size: 13px;
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
    // Connexion PDO
    //$pdo = new PDO("mysql:host=localhost;dbname=lmd_db;charset=utf8", "root", "");
    
    // Matricule dynamique
    $sql = "SELECT * FROM vue_programme_etudiant 
                                    WHERE matricule = :matricule 
                                    ORDER BY id_semestre, id_ue, id_ec";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['matricule' => $matricule]);
    $academic_record = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $row_class = '';

    // Définir la classe uniquement si cote_s1 existe et est inférieure à 10
    if (isset($note['cote_s1']) && $note['cote_s1'] < 10) {
        $row_class = 'table-danger';
    }
    ?>

    <?php if (!empty($academic_record)): ?>
        <div class="table-responsive">

            <table class="word-style" style="border-collapse: collapse; width: 100%;">
                <thead>
                    <tr>
                        <th style="border:1px solid #000; padding:4px;">Code UE</th>
                        <th style="border:1px solid #000; padding:4px;">Unités d’enseignements (UE) et éléments constitutifs
                            (ECUE)</th>
                        <th style="border:1px solid #000; padding:4px;">Crédits</th>
                        <th style="border:1px solid #000; padding:4px;">Notes (/20)</th>
                        <th style="border:1px solid #000; padding:4px;">Notes pondérées<br><span
                                style="font-size:10px;">(Nbre crédits x 20)</span></th>
                        <th style="border:1px solid #000; padding:4px;">Décision<br><span style="font-size:10px;">(validé ou
                                non)</span></th>
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
                                    <td colspan="5" style="border:1px solid #000; padding:4px; font-weight:bold;">
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
                $credits = isset($note['coefficient']) ? (float) $note['coefficient'] : (float) ($note['credits'] ?? 0);

                // Cote annuelle (si existe), sinon somme S1+S2, sinon 0
                if (isset($note['cote'])) {
                    $cote = (float) $note['cote'];
                } else {
                    $cote = (isset($note['cote_s1']) ? (float) $note['cote_s1'] : 0) + (isset($note['cote_s2']) ? (float) $note['cote_s2'] : 0);
                    // Si les deux sont 0, alors on regarde s'il y a une seule cote
                    if ($cote == 0 && isset($note['cote_s1']))
                        $cote = (float) $note['cote_s1'];
                    if ($cote == 0 && isset($note['cote_s2']))
                        $cote = (float) $note['cote_s2'];
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
            <!-- Bloc synthèse et signatures façon capture -->
            <style>
                .synthese-signatures-table {
                    width: 100%;
                    margin-top: 30px;
                    font-family: 'Century Gothic', Arial, sans-serif;
                    font-size: 14px;
                    border: none;
                }

                .synthese-signatures-table td {
                    vertical-align: top;
                    border: none;
                    padding: 0 10px 0 0;
                }

                .synthese-gauche {
                    width: 50%;
                    padding-right: 30px;
                }

                .synthese-droite {
                    width: 50%;
                    padding-left: 30px;
                }

                .synthese-label {
                    font-weight: normal;
                }

                .synthese-value {
                    float: right;
                    font-weight: normal;
                }

                .synthese-sign {
                    margin-top: 40px;
                    font-weight: bold;
                    text-align: center;
                }

                .synthese-sign2 {
                    margin-top: 30px;
                    font-weight: bold;
                    text-align: center;
                }

                .synthese-nom {
                    margin-top: 10px;
                    font-weight: bold;
                    text-align: center;
                }

                .synthese-mention {
                    font-weight: normal;
                }

                .synthese-mention span {
                    font-weight: normal;
                }
            </style>
            
            <table style="width: 100%; font-family: 'Century Gothic', Arial, sans-serif; font-size: 14px; border: none; margin-top: 20px;">
                <tr>
                    <td>Total crédits : <span style="font-weight: bold;"><?php echo $total_credits; ?></span></td>
                    <td style="text-align: center;">Mention :
                        <span style="font-weight: bold;">
                            <?php
                            $mention = "Non attribuée";
                            if ($moyenne >= 16)
                                $mention = "Très bien";
                            elseif ($moyenne >= 14)
                                $mention = "Assez bien";
                            elseif ($moyenne >= 12)
                                $mention = "Bien";
                            elseif ($moyenne >= 10)
                                $mention = "Passable";
                            echo $mention;
                            ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td>Crédits validés : <span style="font-weight: bold;"><?php echo $credits_valides; ?></span></td>
                    <td style="text-align: center;">Décision du jury : </td>
                </tr>
                <tr>
                    <td colspan="2">Total notes pondérées annuel :
                        <span style="font-weight: bold;"><?php echo number_format($total_ponderees, 2) . " /" . $max_ponderees; ?></span>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">Moyenne annuelle pondérée :
                        <span style="font-weight: bold;"><?php echo number_format($moyenne, 2) . " /20"; ?></span>
                    </td>
                </tr>
                <tr>
                    <td style="text-align: center;">
                        <br>
                        Le Doyen de la Faculté <br> <br> <br> <br> 
                        <div style="font-weight: bold;">Abbé Pierre ILUNGA KALE</div>
                        <div style="font-style: italic;">Professeur Associé</div>
                    </td>
                    <td style="text-align: center;">
                        <br>
                        Le Secrétaire Général Académique <br> <br> <br> <br> 
                        <div style="font-weight: bold; text-decoration: underline;">Mgr Lambert KANKENZA MUTEBA</div> 
                        <div style="font-style: italic;">Professeur Associé</div>
                    </td>
                </tr>
            </table>
            <div style="clear: both;"></div>


        </div>


    <?php else: ?>
        <div class="alert alert-info">Aucune note trouvée pour cet étudiant durant l'année
            académique actuelle.</div>
    <?php endif; ?>
</div>
