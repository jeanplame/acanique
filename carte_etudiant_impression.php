<?php
// carte_etudiant_impression.php

require_once __DIR__ . '/includes/db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialisation
$etudiant = null;
$academic_record = [];
$error = '';
$success = '';

// Vérification du paramètre matricule
if (!isset($_GET['matricule']) || empty(trim($_GET['matricule']))) {
    header('Location: ?page=dashboard');
    exit();
}

$matricule = trim($_GET['matricule']);


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


// Récupération infos étudiant
try {
    $sql_etudiant = "SELECT * FROM vue_etudiant_inscription WHERE matricule = ?";
    $stmt_etudiant = $pdo->prepare($sql_etudiant);
    $stmt_etudiant->execute([$matricule]);
    $etudiant = $stmt_etudiant->fetch(PDO::FETCH_ASSOC);

    if (!$etudiant) {
        die("Aucun étudiant trouvé avec ce matricule.");
    }

    $sqlEtud = "SELECT * FROM t_etudiant WHERE matricule = ?";
    $stmtEtud = $pdo->prepare($sqlEtud);
    $stmtEtud->execute([$matricule]);
    $ResultEtud = $stmtEtud->fetch(PDO::FETCH_ASSOC);

    // Mention
    $etudiant['mention_libelle'] = $etudiant['nom_mention'] ?? 'Non définie';

    // Promotion
    $sql_promotion = "SELECT p.nom_promotion FROM t_inscription i JOIN t_promotion p ON i.code_promotion = p.code_promotion WHERE i.matricule = ? AND i.statut = 'Actif' LIMIT 1";
    $stmt_promotion = $pdo->prepare($sql_promotion);
    $stmt_promotion->execute([$matricule]);
    $etudiant['nom_promotion'] = $stmt_promotion->fetchColumn() ?: 'Non définie';

    // Filière
    $sql_filiere = "SELECT nom_filiere FROM vue_etudiant_inscription WHERE matricule = ?";
    $stmt_filiere = $pdo->prepare($sql_filiere);
    $stmt_filiere->execute([$matricule]);
    $etudiant['nom_filiere'] = $stmt_filiere->fetchColumn() ?: 'Non définie';

    // Domaine
    $sql_domaine = "SELECT nom_domaine FROM vue_etudiants_domaines WHERE matricule = ?";
    $stmt_domaine = $pdo->prepare($sql_domaine);
    $stmt_domaine->execute([$matricule]);
    $etudiant['nom_domaine'] = $stmt_domaine->fetchColumn() ?: 'Non défini';





} catch (Exception $e) {
    die("Erreur récupération données étudiant : " . $e->getMessage());
}
$annee_academique_courante = $id_annee_courante ?? null;
// Relevé notes année courante
if ($annee_academique_courante) {
    $sql_notes = "SELECT ue.libelle AS libelle_ue, ec.libelle AS libelle_ec, ec.coefficient AS credits_ec, c.cote_s1, c.cote_s2
                      FROM t_cote c
                      JOIN t_element_constitutif ec ON c.id_ec = ec.id_ec
                      JOIN t_unite_enseignement ue ON ec.id_ue = ue.id_ue
                      WHERE c.matricule = ? AND c.id_annee = ?
                      ORDER BY ue.libelle, ec.libelle";
    $stmt_notes = $pdo->prepare($sql_notes);
    $stmt_notes->execute([$matricule, $annee_academique_courante]);
    $academic_record = $stmt_notes->fetchAll(PDO::FETCH_ASSOC);
}

// Formatage année académique
$annee_academique_formatee = 'Non définie';
if ($annee_academique_courante) {
    $sql_annee = "SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?";
    $stmt_annee_details = $pdo->prepare($sql_annee);
    $stmt_annee_details->execute([$annee_academique_courante]);
    $annee_details = $stmt_annee_details->fetch(PDO::FETCH_ASSOC);

    if ($annee_details) {
        $date_debut = new DateTime($annee_details['date_debut']);
        $date_fin = new DateTime($annee_details['date_fin']);
        $annee_academique_formatee = $date_debut->format('Y') . '-' . $date_fin->format('Y');
    }
}
// Fonction QR code simple (API publique)
function generateQRCodeUrl(string $data): string
{
    return "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($data);
}

$qr_data = "Matricule: " . ($etudiant['matricule'] ?? 'N/A') . "\nNom: " .
    trim(($etudiant['nom_etu'] ?? '') . ' ' . ($etudiant['postnom_etu'] ?? '') . ' ' . ($etudiant['prenom_etu'] ?? ''));

$qr_code_url = generateQRCodeUrl($qr_data);

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8" />
    <title>Carte Étudiant - <?= htmlspecialchars($etudiant['matricule'] ?? '') ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
        }

        .carte {
            width: 350px;
            height: 220px;
            border: 1px solid #333;
            padding: 15px;
            position: relative;
            box-sizing: border-box;
        }

        .photo {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 100px;
            height: 120px;
            object-fit: cover;
            border: 1px solid #999;
        }

        .infos {
            max-width: 220px;
        }

        .qr-code {
            position: absolute;
            bottom: 15px;
            right: 15px;
            width: 100px;
            height: 100px;
        }

        h2 {
            margin-top: 0;
            margin-bottom: 10px;
        }

        .field-label {
            font-weight: bold;
            margin-top: 6px;
        }

        .student-card-container {
            display: flex;
            justify-content: center;
            gap: 15px;
            font-family: "Century Gothic" !important;
        }

        .student-card {
            width: 460px;
            height: 260px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            font-family: Arial, sans-serif;
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
            margin-bottom: 8px;
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
        @media print {
            body * {
                visibility: hidden;
            }

            #printable-area,
            #printable-area * {
                visibility: visible;
            }

            #printable-area {
                position: absolute;
                left: 0;
                top: 0;
            }

            .print-button {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="student-card-container" id="printable-area">
        <!-- Recto -->
        <div class="student-card card-front" style="font-family: 'Century Gothic' !important;">
            <!-- <div class="card-header">FILIERE : <strong><?php echo $etudiant['filiere'] ?? 'Inconnue'; ?></strong></div> -->
            <table style="width: 100%; font-size: 8px; font-family: 'Century Gothic' !important;">
                <tr class="card-header">
                    <td colspan="2" style="padding-top: 5px; padding-bottom: 5px;">Filière :
                        <?php echo $etudiant['nom_filiere'] ?>
                    </td>
                </tr>
                <tr>
                    <td style="width: 18%; text-align:center;">
                        <img src="<?php echo $ResultEtud['photo'] ?? '../../img/default-user.png'; ?>" alt="Photo"
                            style="width:100%; margin-top:6px;">
                    </td>
                    <td style="padding-left: 5px; margin-top: 0px; margin-bottom: 1px solid black;">
                        Nom: <strong><?php echo $etudiant['nom_etu'] ?? 'Inconnu'; ?> </strong>&nbsp &nbsp &nbsp &nbsp
                        &nbsp
                        &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp
                        &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp &nbsp Sexe :
                        <strong><?php echo $ResultEtud['sexe'] ?? 'Inconnu'; ?></strong></strong><br>
                        Postnom: <strong><?php echo $ResultEtud['postnom_etu'] ?? 'Inconnu'; ?> </strong><br>
                        Prénom:<strong> <?php echo $ResultEtud['prenom_etu'] ?? 'Inconnu'; ?> </strong><br>
                        Lieu/Date naiss.: <strong><?php echo $ResultEtud['lieu_naiss'] ?? 'Inconnu'; ?> /
                            <?php $datefo = $ResultEtud['date_naiss'];
                            $datefo = date('d/m/Y');
                            echo $datefo ?? 'Inconnu'; ?>
                        </strong><br>
                        Adresse: <strong><?php echo $ResultEtud['adresse'] ?? 'Inconnue'; ?> </strong><br>
                        Promotion: <strong><?php echo $etudiant['nom_promotion'] ?? 'Inconnue'; ?> </strong><br>
                        Année académique:
                        <strong><?php echo $annee_academique_courante_libelle ?? 'Inconnue'; ?></strong>
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
                        <strong>Nom du père : <?php echo $ResultEtud['nom_pere']; ?></strong> <br>
                        <strong>Nom de la mère : <?php echo $ResultEtud['nom_mere']; ?></strong><br>
                        <div style="text-align: right; font-size: 9px;">
                            Fait à Kabinda, le <?php echo date('d M Y'); ?> <br> <br>
                            Sceau et signature de l'autorité
                        </div>
                    </td>
                </tr>

            </table>
        </div>

        <!-- Verso -->
        <div class="student-card card-back">
            <table style="width: 100%; height: 100%;">
                <tr>
                    <td style="width: 8%;"><img src="../../img/logo.gif" alt="Logo UNILO" style="width:100%;"></td>
                    <td>
                        UNIVERSITE NOTRE DAME DE LOMAMI <br>
                        <b>Secrétariat Général Académique</b>
                    </td>
                    <td style="width: 8%;"><img src="../../img/drapeau_rdc.png" alt="Drapeau RDC" style="width:100%;">
                    </td>
                </tr>
                <tr>
                    <?php
                    $numero_document = '000'; // Valeur par défaut
                    if ($ResultEtud['id_etudiant']) {
                        $numero_document = str_pad($ResultEtud['id_etudiant'], 3, '0', STR_PAD_LEFT);
                    }
                    ?>
                    <td colspan="3"
                        style="width: 100%; padding:5px; background-color: #00515e; color: white; font-size: 13px; font-weight:700;">
                        CARTE
                        D'ETUDIANT N° <?php echo $numero_document . '-' . $annee_academique_courante_libelle ?></td>
                    
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
</body>

</html>