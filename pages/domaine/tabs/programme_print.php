<?php
    header('Content-Type: text/html; charset=UTF-8');
// tabs/programme_print.php

// Inclure les fichiers de configuration nécessaires
// Le chemin a été corrigé pour remonter jusqu'au dossier racine de votre projet.
// Assurez-vous que le chemin est correct selon la structure de votre projet.
require_once '../../../includes/db_config.php'; // Chemin corrigé pour trouver db_config.php
//require_once '../../../config/pdo_connexion.php'; // Supposons que la connexion PDO est également dans un dossier de configuration

// Récupération des paramètres nécessaires depuis l'URL
$id_domaine = isset($_GET['id']) ? (int) $_GET['id'] : null;
$mention_id = isset($_GET['mention']) ? (int) $_GET['mention'] : null;
$promotion_code = isset($_GET['promotion']) ? htmlspecialchars($_GET['promotion']) : '';
$id_semestre = isset($_GET['semestre']) ? (int) $_GET['semestre'] : null;

// Vérifier si les paramètres essentiels sont présents
if (empty($id_semestre) || empty($promotion_code)) {
    echo '<div class="alert alert-danger">Erreur : Les informations de la promotion et du semestre sont manquantes.</div>';
    exit;
}

try {
    // Utilisation de la connexion PDO existante
    // $pdo est supposé être défini dans pdo_connexion.php
    if (!isset($pdo)) {
        throw new Exception("La connexion PDO n'est pas définie.");
    }

    // Récupérer les UE et les EC comme dans ue.php
    $sql_ues = "
        SELECT
            ue.id_ue, ue.code_ue, ue.libelle, ue.heures_th as ue_heures_th, ue.heures_td as ue_heures_td, ue.heures_tp as ue_heures_tp, ue.credits as ue_credits,
            ec.id_ec, ec.code_ec, ec.libelle as ec_libelle, ec.heures_th as ec_heures_th, ec.heures_td as ec_heures_td, ec.heures_tp as ec_heures_tp, ec.coefficient
        FROM t_unite_enseignement ue
        LEFT JOIN t_element_constitutif ec ON ue.id_ue = ec.id_ue AND ec.is_programmed = 1
        WHERE ue.id_semestre = ? AND ue.code_promotion = ? AND ue.is_programmed = 1
        ORDER BY ue.code_ue ASC, ec.code_ec ASC
    ";
    $stmt_ues = $pdo->prepare($sql_ues);
    $stmt_ues->execute([$id_semestre, $promotion_code]);
    $results = $stmt_ues->fetchAll(PDO::FETCH_ASSOC);

    // Organiser les résultats par UE
    $ues = [];
    foreach ($results as $row) {
        $id_ue = $row['id_ue'];
        if (!isset($ues[$id_ue])) {
            $ues[$id_ue] = [
                'code_ue' => $row['code_ue'],
                'libelle' => $row['libelle'],
                'ue_heures_th' => $row['ue_heures_th'],
                'ue_heures_td' => $row['ue_heures_td'],
                'ue_heures_tp' => $row['ue_heures_tp'],
                'ue_credits' => $row['ue_credits'],
                'ecs' => []
            ];
        }
        if ($row['id_ec']) {
            $ues[$id_ue]['ecs'][] = [
                'id_ec' => $row['id_ec'],
                'code_ec' => $row['code_ec'],
                'ec_libelle' => $row['ec_libelle'],
                'coefficient' => $row['coefficient'],
                'ec_heures_th' => $row['ec_heures_th'],
                'ec_heures_td' => $row['ec_heures_td'],
                'ec_heures_tp' => $row['ec_heures_tp'],
            ];
        }
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Erreur : ' . $e->getMessage() . '</div>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Programme d'études - Impression</title>
    <!-- Inclure les styles pour l'impression -->
    <link rel="stylesheet" href="../../includes/dist/css/bootstrap.min.css">
    <link href="../../includes/css/all.min.css" crossorigin>
    <!-- <link href="webfonts/" rel="stylesheet"> -->
    <link rel="stylesheet" href="../../includes/css/bootstrap-icons.min.css">
    <link href="../../includes/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../includes/css/style.css" rel="stylesheet">

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
    <link href="css/domaine.css" rel="stylesheet">
    <style>
        /* Styles spécifiques pour l'impression */
        body {
            font-family: 'Tw Cent MT' !important;
            color: #000;
        }

        .container-fluid {
            width: 100%;
            margin-top: 20px;
        }

        .header-print {
            text-align: center;
            margin-bottom: 20px;
        }

        .header-print .logo {
            width: 100px;
        }

        .header-print h2,
        .header-print h3,
        .header-print p {
            margin: 0;
        }

        .header-print .separator {
            border-top: 3px double #000;
            margin: 10px auto;
            width: 80%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }

        thead th {
            background-color: #f2f2f2;
            text-align: center;
        }

        .table-info {
            background-color: #d1ecf1 !important;
        }

        @media print {
            .btn {
                display: none;
                /* Masquer le bouton d'impression */
            }
        }
    </style>
</head>

<body onload="window.print()">

    <div class="container-fluid">
        <!-- ============================================== -->
        <!-- Début de l'en-tête ajouté -->
        <!-- ============================================== -->
        <div class="container-fluid text-center mt-3">
            <div class="d-flex align-items-center justify-content-center">
                <!-- Logo -->
                <div class="me-4">
                    <!--  -->
                    <img src="../../../img/logo.gif" alt="Logo UNILO" style="width: 50px;">
                </div>
                <!-- Texte de l'en-tête -->
                <div>
                    <h2 class="mb-0" style="font-family: 'Brush Script MT', cursive; font-size: 2rem;">République
                        Démocratique du Congo</h2>
                    <h3 class="mb-0" style="font-weight: bold; font-size: 1.2rem;">UNIVERSITE NOTRE DAME DE LOMAMI
                        (UNILO)</h3>
                    <p class="mb-0">SECRETARIAT GENERAL ACADEMIQUE</p>
                    <p class="mb-0">E-mail: <a href="mailto:sgac@unilo.net">sgac@unilo.net</a></p>
                </div>
            </div>
            <!-- Ligne de séparation -->
            <div style="border-top: 3px double #000; margin: 10px auto; width: 80%;"></div>
            <h1 style="font-weight: bold; font-size: 1.5rem;">PROGRAMME D'ÉTUDE - 2024-2025</h1>
        </div>
        <!-- ============================================== -->
        <!-- Fin de l'en-tête ajouté -->
        <!-- ============================================== -->

        <!-- Tableau des UE et EC -->
        <div class="table-responsive">
            <?php if (!empty($ues)): ?>
                <table class="table" style="border-color: #000 !important;">
                    <thead style="color: #000; border-color: #000 !important; background: #4c4c4c !important ;">
                        <tr style="text-align: center;" style="background:#787878">
                            <th rowspan="2" class="align-middle">Code UE</th>
                            <th rowspan="2" class="align-middle">Intitulés des UE</th>
                            <th colspan="3" class="text-center">Heures</th>
                            <th colspan="2" class="align-middle">Crédits</th>
                        </tr>
                        <tr style="text-align: center;">
                            <th>CM/TH</th>
                            <th>TD</th>
                            <th>TP</th>
                            <th>EC</th>
                            <th>UE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ues as $id_ue => $ue): ?>
                            <?php
                            $rowspan = count($ue['ecs']) > 0 ? count($ue['ecs']) + 1 : 1;
                            ?>
                            <!-- Ligne principale pour l'UE -->
                            <tr style="border-color: #000 !important;">
                                <td rowspan="<?php echo $rowspan; ?>" class="align-middle text-center font-weight-bold">
                                    <?php echo htmlspecialchars($ue['code_ue']); ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($ue['libelle']); ?></strong></td>
                                <td style="text-align: center;"><?php echo htmlspecialchars($ue['ue_heures_th']); ?>h</td>
                                <td style="text-align: center;"><?php echo htmlspecialchars($ue['ue_heures_td']); ?>h</td>
                                <td style="text-align: center;"><?php echo htmlspecialchars($ue['ue_heures_tp']); ?>h</td>
                                <td style="text-align: center;"></td>
                                <td class="align-middle text-center">
                                    <?php echo htmlspecialchars($ue['ue_credits']); ?>
                                </td>
                            </tr>
                            <!-- Lignes pour les Éléments Constitutifs -->
                            <?php if (!empty($ue['ecs'])): ?>
                                <?php foreach ($ue['ecs'] as $ec): ?>
                                    <tr>
                                        <td><em><?php echo htmlspecialchars($ec['ec_libelle']); ?>
                                                (<?php echo htmlspecialchars($ec['code_ec']); ?>)</em></td>
                                        <td style="text-align: center;"><?php echo htmlspecialchars($ec['ec_heures_th']); ?>h</td>
                                        <td style="text-align: center;"><?php echo htmlspecialchars($ec['ec_heures_td']); ?>h</td>
                                        <td style="text-align: center;"><?php echo htmlspecialchars($ec['ec_heures_tp']); ?>h</td>
                                        <td style="text-align: center;"><?php echo htmlspecialchars($ec['coefficient']); ?></td>
                                        <td></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="alert alert-info text-center mt-3">
                    Aucune Unité d'Enseignement trouvée pour cette sélection.
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>