<?php
    header('Content-Type: text/html; charset=UTF-8');

use Vtiful\Kernel\Format;
// carte_etudiant.php

// Ce fichier est destiné à être inclus dans la page principale du profil de l'étudiant.
// Les variables PHP comme $etudiant sont censées être déjà définies
// par le script principal (etudiant_profil.php) avant que ce fichier ne soit inclus.

if (!isset($etudiant)) {
    echo '<div class="alert alert-warning" role="alert">Les informations de l\'étudiant ne sont pas disponibles pour générer la carte.</div>';
    return; // Arrêter l'exécution si $etudiant n'est pas défini
}

// Assurez-vous que la connexion à la base de données ($pdo) est disponible.
// Si ce fichier est inclus après 'db_config.php' dans le script principal, $pdo devrait être défini.
// Sinon, vous pourriez avoir besoin de le 'require_once' ici.
// require_once __DIR__ . '/../includes/db_config.php'; // Décommenter si $pdo n'est pas disponible

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
    $stmt_filiere_nom->execute([$etudiant['matricule']]);
    $filiere_nom = $stmt_filiere_nom->fetchColumn() ?: 'Non définie';
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

$qr_data = "Matricule: " . $etudiant['matricule'] . "\nNom: " . $etudiant['nom_etu'] . " " . $etudiant['postnom_etu'] . " " . $etudiant['prenom_etu'];
$qr_code_url = generateQRCodeUrl($qr_data);

// Récupérer la mention et la promotion depuis l'objet $etudiant déjà rempli
$mention_libelle = $etudiant['mention_libelle'] ?? 'Non définie';
$promotion_nom = $etudiant['nom_promotion'] ?? 'Non définie';

// Récupérer l'année académique courante depuis la table t_configuration
$annee_academique_courante_libelle = '';
try {
    $stmt_annee_id = $pdo->query("SELECT valeur FROM t_configuration WHERE cle = 'annee_academique_courante'");
    $id_annee_courante = $stmt_annee_id->fetchColumn();

    if ($id_annee_courante) {
        $stmt_annee_details = $pdo->prepare("SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?");
        $stmt_annee_details->execute([$id_annee_courante]);
        $annee_details = $stmt_annee_details->fetch(PDO::FETCH_ASSOC);

        if ($annee_details) {
            $annee_academique_courante_libelle = date('Y', strtotime($annee_details['date_debut'])) . '-' . date('Y', strtotime($annee_details['date_fin']));
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching current academic year: " . $e->getMessage());
    $annee_academique_courante_libelle = date('Y') . '-' . (date('Y') + 1); // Fallback
}

$sql_filiere = "SELECT nom_filiere FROM vue_etudiant_inscription WHERE matricule = ?";
$stmt_filiere = $pdo->prepare($sql_filiere);
$stmt_filiere->execute([$matricule]);
$filiere_etudiant['nom_filiere'] = $stmt_filiere->fetchColumn() ?: 'Non défini';
$filiere = $filiere_etudiant['nom_filiere'];

?>

<style>
    /* ===== Carte d'Étudiant (Recto-Verso) ===== */
    .student-card-container {
        display: flex;
        justify-content: center;
        gap: 15px;
        background: #f4f4f4;
        font-family: "Century Gothic" !important;
    }

    .student-card {
        width: 460px;
        height: 230px;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        font-family: "Century Gothic";
        font-size: 10px;
    }

    .card-front {
        padding: 10px 15px;
        display: flex;
        flex-direction: column;
    }

    .card-header {
        font-weight: bold;
        color: white;
        font-size: 11px;
        text-align: center;
        background-color: #00515e;

    }

    .card-photo-info {
        display: flex;
        gap: 10px;
    }

    .card-photo {
        width: 70px;
        height: 80px;
        border-radius: 3px;
        object-fit: cover;
        border: 1px solid #ccc;
    }

    .card-info p {
        margin: 0 0 2px;
        font-size: 8px;
    }

    .card-info strong {
        font-weight: bold;
    }

    .qr-section {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-top: auto;
        font-size: 10px;
    }

    .qr-section img {
        width: 60px;
        height: 60px;
        border: 1px solid #ccc;
    }

    .card-signature {
        font-size: 9px;
        text-align: right;
        margin-top: 4px;
    }

    /* Verso */
    .card-back {
        padding: 10px;
        display: flex;
        flex-direction: column;
        
        align-items: center;
        text-align: center;
    }

    .back-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
    }

    .back-header img {
        height: 30px;
    }

    .back-title {
        font-weight: bold;
        font-size: 12px;
        margin-top: 5px;
    }

    .ribbon {
        display: flex;
        width: 100%;
        height: 4px;
        margin: 8px 0;
    }



    .back-image {
        width: 70px;
        margin: 8px 0;
    }

    .validity {
        color: red;
        font-weight: bold;
        font-size: 10px;
        margin-bottom: 5px;
    }

    .back-footer {
        font-size: 9px;
    }

    
</style>

<div class="student-card-container" id="printable-area">
    <!-- Recto -->
    <div class="student-card " style="font-family: 'Century Gothic' !important;">
        <!-- <div class="card-header">FILIERE : <strong><?php echo $etudiant['filiere'] ?? 'Inconnue'; ?></strong></div> -->
        <table class="card-front" style="width: 100%; font-size: 8px; font-family: 'Century Gothic' !important;">
            <tr class="card-header">
                <td colspan="2" style="padding-top: 2px; padding-bottom: 2px;">Filière : <?php echo $filiere ?></td>
            </tr>
            <tr>
                <td style="width: 15%; text-align:center; hoverflow:hidden; ">
                    <img src="<?php echo $etudiant['photo'] ?? '../../img/default-user.png'; ?>" alt="Photo"
                        style="width:100%; margin-top:2px;">
                </td>
                <td style="padding-left: 5px; margin-top: 0px; margin-bottom: 1px solid black;">
                    Nom: <strong><?php echo $etudiant['nom_etu'] ?? 'Inconnu'; ?> </strong>&nbsp &nbsp &nbsp &nbsp &nbsp
                    &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp
                    &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp Sexe :
                    <strong><?php echo $etudiant['sexe'] ?? 'Inconnu'; ?></strong></strong><br>
                    Postnom: <strong><?php echo $etudiant['postnom_etu'] ?? 'Inconnu'; ?> </strong><br>
                    Prénom:<strong> <?php echo $etudiant['prenom_etu'] ?? 'Inconnu'; ?> </strong><br>
                    Lieu/Date naiss.: <strong><?php echo $etudiant['lieu_naiss'] ?? 'Inconnu'; ?> /
                        <?php $datefo = $etudiant['date_naiss'];
                        $datefo = date('d/m/Y');
                        echo $datefo ?? 'Inconnu'; ?>
                    </strong><br>
                    Adresse: <strong><?php echo $etudiant['adresse'] ?? 'Inconnue'; ?> </strong><br>
                    Promotion: <strong><?php echo $etudiant['nom_promotion'] ?? 'Inconnue'; ?> </strong><br>
                    Année académique: <strong><?php echo $annee_academique_courante_libelle ?? 'Inconnue'; ?></strong>
                </td>
            </tr>
            <tr>
                <td style="text-align:center;">
                    Matricule
                    <div style="background:#00515e; padding:2px; color: white;">
                        <?php echo $etudiant['matricule'] ?? 'Inconnu'; ?>
                    </div> <br>
                </td>
                <td>
                    <hr style="background-color:black;">
                </td>
            </tr>
            <tr>
                <td style="text-align:center;">
                    <img src="<?php echo $qr_code_url; ?>"
                        style="width:100%; border: 1px solid black; height:55px; margin-top:0px;">
                    ACANIQUE
                </td>
                <td style="padding-left:4px;">
                    <strong>Nom du père : <?php echo $etudiant['nom_pere']; ?></strong> <br>
                    <strong>Nom de la mère : <?php echo $etudiant['nom_mere']; ?></strong><br>
                    <div style="text-align: right; font-size: 9px;">
                        Fait à Kabinda, le <?php echo date('d M Y'); ?> <br> <br>
                        Sceau et signature de l'autorité
                    </div>
                </td>
            </tr>

        </table>
    </div>

    <!-- Verso -->
    <div class="student-card">
        <table style="width: 100%; font-size: 8px; font-family: 'Century Gothic' !important;">
            <tr>
                <td style="width: 8%;"><img src="../../img/logo.gif" alt="Logo UNILO" style="width:100%;"></td>
                <td>
                    UNIVERSITE NOTRE DAME DE LOMAMI <br>
                    <b>Secrétariat Général Académique</b>
                </td>
                <td style="width: 8%;"><img src="../../img/drapeau_rdc.png" alt="Drapeau RDC" style="width:100%;"></td>
            </tr>
            <tr>
                <?php
                $numero_document = '000'; // Valeur par défaut
                if (isset($etudiant['id_etudiant'])) {
                    $numero_document = str_pad($etudiant['id_etudiant'], 3, '0', STR_PAD_LEFT);
                }
                ?>
                <td colspan="3"
                    style="width: 100%; padding:5px; background-color: #00515e; color: white; font-size: 13px; font-weight:700;">
                    CARTE
                    D'ETUDIANT N° <?php echo $numero_document . '-' . $annee_academique_courante_libelle ?></td>
                <style>
                    .ribbon div {
                        flex: 1;
                    }

                    .ribbon div .blue {
                        background: #007bff;
                    }

                    .ribbon div .yellow {
                        background: #ffc107;
                    }

                    .ribbon div .red {
                        background: #dc3545;
                    }
                </style>
                <div class="ribbon">
                    <div class="blue"></div>
                    <div class="yellow"></div>
                    <div class="red"></div>
                </div>
            </tr>
            <tr>
                <td colspan="3"><img class="back-image" src="../../img/acanique-carte.png" alt="Diplôme"
                        style="width: 30%;"></td>
            </tr>
            <tr>
                <td colspan="3">
                    <div class="back-footer" style="font-style:italic; font-size: 10px;">
                        Les Autorités tant Civiles, Policières que Militaires
                        sont priées de venir en aide au porteur de la présente
                        en cas de nécessité.
                    </div>
                </td>
            </tr>
        </table>

    </div>

</div>
<!-- bouton pour imprimer la carte d'étudiant -->
<div class="text-center mt-4 mb-4">
    <button class="btn btn-primary" style="
        padding: 10px 25px;
        background-color: #00515e;
        border: none;
        border-radius: 5px;
        color: white;
        font-weight: 500;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        display: inline-flex;
        align-items: center;
        gap: 8px;"
        onclick="window.open('carte_etudiant_impression.php?matricule=<?php echo urlencode($etudiant['matricule']); ?>', '_blank')">
        <i class="fas fa-print"></i>
        Imprimer la carte
    </button>
</div>


<script>
    function printCarte() {
        // Optionnel: temporisation pour garantir le rendu CSS
        setTimeout(() => {
            window.print();
        }, 100);
    }
    // Fonction pour imprimer la carte d'étudiant

</script>