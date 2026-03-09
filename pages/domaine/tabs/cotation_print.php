<?php
    header('Content-Type: text/html; charset=UTF-8');
// cotation_print.php
// Ce fichier est une page autonome pour l'impression de la fiche de cotation.

// Inclure la connexion à la base de données
// Assurez-vous que ce chemin est correct.
require_once '../../../includes/db_config.php';

// Récupération des paramètres de l'URL
$id_domaine = isset($_GET['id']) ? (int) $_GET['id'] : null;
$mention_id = isset($_GET['mention']) ? (int) $_GET['mention'] : null;
$promotion_code = isset($_GET['promotion']) ? $_GET['promotion'] : null;
$id_semestre = isset($_GET['semestre']) ? (int) $_GET['semestre'] : null;
$selected_ec_id = isset($_GET['ec']) ? (int) $_GET['ec'] : null;

// Vérifier que les paramètres nécessaires sont présents
if (!$id_domaine || !$mention_id || !$promotion_code || !$id_semestre || !$selected_ec_id) {
    die("Paramètres d'impression manquants.");
}

// Initialisation des variables
$domaine_name = '';
$mention_name = '';
$annee_name = '';
$ec_actuel = ['libelle' => ''];
$enseignant_name = 'Nom de l\'Enseignant';

// --- Récupération des détails de l'entête en se basant sur la BDD fournie ---

// 1. Récupération du nom du domaine
$stmt_domaine = $pdo->prepare("SELECT nom_domaine FROM t_domaine WHERE id_domaine = ?");
$stmt_domaine->execute([$id_domaine]);
$domaine_name = $stmt_domaine->fetchColumn();

// 2. Récupération du nom de la mention (colonne 'libelle')
$stmt_mention = $pdo->prepare("SELECT libelle FROM t_mention WHERE id_mention = ?");
$stmt_mention->execute([$mention_id]);
$mention_name = $stmt_mention->fetchColumn();

// 3. Récupération de l'année académique via la promotion
// La table `t_anne_academique` n'a pas de nom, on utilise les dates
$stmt_annee = $pdo->prepare("
    SELECT a.date_debut, a.date_fin
    FROM t_anne_academique a
    JOIN t_association_promo ap ON a.id_annee = ap.id_annee
    WHERE ap.code_promotion = ? AND ap.id_mention = ?
    LIMIT 1
");
$stmt_annee->execute([$promotion_code, $mention_id]);
$annee_data = $stmt_annee->fetch(PDO::FETCH_ASSOC);

if ($annee_data) {
    $annee_name = date('Y', strtotime($annee_data['date_debut'])) . '-' . date('Y', strtotime($annee_data['date_fin']));
}

// 4. Récupération des détails du cours (EC)
$stmt_ec = $pdo->prepare("SELECT * FROM t_element_constitutif WHERE id_ec = ? AND is_programmed = 1");
$stmt_ec->execute([$selected_ec_id]);
$ec_actuel = $stmt_ec->fetch(PDO::FETCH_ASSOC);


// 5. Récupération des étudiants de la promotion et de la mention
// On joint t_etudiant et t_inscription pour obtenir les informations complètes
$sql_students = "
    SELECT e.matricule, e.nom_etu, e.postnom_etu, e.prenom_etu
    FROM t_etudiant e
    JOIN t_inscription i ON e.matricule = i.matricule
    WHERE i.code_promotion = :promotion_code AND i.id_mention = :mention_id
";
$stmt_students = $pdo->prepare($sql_students);
$stmt_students->execute([
    'promotion_code' => $promotion_code,
    'mention_id' => $mention_id
]);
$students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

// 6. Récupération des notes existantes
$existing_notes = [];
if ($selected_ec_id) {
    $sql_notes = "SELECT matricule, cote_s1, cote_s2 FROM t_cote WHERE id_ec = :id_ec";
    $stmt_notes = $pdo->prepare($sql_notes);
    $stmt_notes->execute(['id_ec' => $selected_ec_id]);
    while ($row = $stmt_notes->fetch(PDO::FETCH_ASSOC)) {
        $existing_notes[$row['matricule']] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiche de cotation - <?php echo htmlspecialchars($ec_actuel['libelle']); ?></title>
    <style>
        body {
            font-family: "Tw Cent MT", serif;
            margin: 0;
            font-size: 12pt;
            line-height: 1.5;
        }
        .container {
            width: 100%;
            margin: 0 auto;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            text-align: left;
            margin-bottom: 0.2cm; /* Réduit l'espace sous l'en-tête */
        }
        .logo {
            width: 80px; /* Ajuste la taille du logo pour qu'il soit plus petit */
            height: auto;
            margin-right: 15px; /* Réduit l'espace entre le logo et le texte */
        }
        .header-text {
            flex-grow: 1;
            text-align: center; /* Centre le texte de l'en-tête */
            line-height: 1.2; /* Ajuste l'interligne pour un meilleur espacement */
        }
        .header .h1 {
            font-family: 'Edwardian Script ITC', sans-serif; /* Ou une police calligraphique si disponible */
            font-size: 28pt; /* Taille de police plus petite */
            margin: 0;
            padding: 0;
        }
        .header h3 {
            margin: 0;
            padding: 0;
            text-transform: uppercase;
            font-size: 12pt; /* Taille de police plus petite */
        }
        .header p {
            margin: 0;
            font-size: 10pt; /* Taille de police plus petite pour l'e-mail */
        }
        .divider {
            border-bottom: 3px double #000;
            margin: 10px 0 20px; /* Ajuste les marges pour réduire l'espace */
        }
        .title-section {
            text-align: center;
            margin-bottom: 0.2cm; /* Réduit l'espace sous le titre */
        }
        .title-section h3 {
            font-size: 18pt;
            margin: 0;
            padding: 0;
            text-transform: uppercase;
            display: inline-block;
            position: relative;
        }
        .title-section h3:after {
            content: '';
            display: block;
            position: absolute;
            bottom: -5px;
            left: 50%;
            transform: translateX(-50%);
            width: 80%;
            border-bottom: 2px solid #000;
        }
        /* Style pour les informations académiques */
        .info-section {
            margin-bottom: 1cm;
        }
        .info-section p {
            margin: 0 0 2px 0; /* Réduit l'espace entre chaque ligne d'information */
        }
        .table-notes {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1cm; /* Réduit l'espace sous le tableau */
        }
        .table-notes th, .table-notes td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }
        .table-notes th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 2cm;
            page-break-inside: avoid;
        }
        .signature-block {
            width: 45%;
            text-align: center;
            border-top: 1px solid #000;
            padding-top: 10px;
        }
        @page {
            margin: 2cm;
        }
        .h2 {
            font-size: 16pt; /* Taille de police plus petite pour les sous-titres */
            margin: 0;
            padding: 0;
            text-transform: uppercase;
            font-family: 'Franklin Gothic Medium', 'Arial Narrow', Arial, sans-serif;
            line-height: 21px;
        }
        
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <!-- Remplacer l'URL ci-dessous par l'emplacement réel de l'image de votre logo -->
            <img src="../../../img/logo.gif" alt="Logo de l'Université" class="logo">
            <div class="header-text">
                <div class="h1">République Démocratique du Congo</div>
                <div class="h2">UNIVERSITE NOTRE DAME DE LOMAMI (UNILO)</div>
                <div class="h2">SECRETARIAT GENERAL ACADEMIQUE</div>
                <div class="email">E-mail: <a href="mailto:sgac@unilo.net">sgac@unilo.net</a></div>
            </div>
            <img src="../../../img/logo.gif" alt="Logo de l'Université" class="logo">
        </div>
        <div class="divider"></div>

        <div class="title-section">
            <h3>FICHE DE COTATION</h3>
        </div>

        <!-- Informations académiques qui étaient dans l'en-tête, déplacées ici -->
        <div class="info-section">
            <table style="width: 100%; border: 1px solid #000; padding: 6px;">
                <tr style="border: 1px #000 solid;">
                    <td style="width: 50%; border-right: 1px solid #000;">
                        Enseignant : .................................................... <br>
                        Cours : <?php echo htmlspecialchars($ec_actuel['libelle']); ?> - <?php echo $id_semestre == 1 ? 'Premier Semestre' : 'Deuxième Semestre'; ?> <br>
                        Domaine : <?php echo htmlspecialchars($domaine_name); ?> <br>
                    </td>
                    <td>
                        Mention : <?php echo htmlspecialchars($mention_name); ?> <br>
                        Promotion : <?php echo htmlspecialchars($promotion_code); ?> <br>
                        Année Académique : <?php echo htmlspecialchars($annee_name); ?> <br>
                    </td>

                </tr>
                <tr>
                    <td colspan="2" style="text-align: center; font-style: italic; font-weight: bold;">
                        Volume horaire : Heures théoriques : <?php echo htmlspecialchars($ec_actuel['heures_th'] ?? 'Non défini'); ?> | Heures pratiques : <?php echo htmlspecialchars($ec_actuel['heures_tp'] ?? 'Non défini'); ?> | Heures Travaux dirigés : <?php echo htmlspecialchars($ec_actuel['heures_td'] ?? 'Non défini'); ?>
                    </td>
                </tr>
            </table>
        </div>

        <table class="table-notes">
            <thead>
                <tr>
                    <th style="text-align: center;">N°</th>
                    <th style="text-align: center;">Matricule</th>
                    <th style="text-align: center;">Nom et Postnom</th>
                    <th style="text-align: center;">Prénom</th>
                    <?php if ($id_semestre == 1): ?>
                    <th style="text-align: center;">Note S1</th>
                    <?php endif; ?>
                    <?php if ($id_semestre == 2): ?>
                    <th style="text-align: center;">Note S2</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php $numero = 1; foreach ($students as $student): ?>
                    <tr>
                        <td><?php echo $numero++; ?></td>
                        <td><?php echo htmlspecialchars($student['matricule']); ?></td>
                        <td><?php echo htmlspecialchars($student['nom_etu'] . ' ' . $student['postnom_etu']); ?></td>
                        <td><?php echo htmlspecialchars($student['prenom_etu']); ?></td>
                        <?php if ($id_semestre == 1): ?>
                        <td style="text-align: center;"><?php echo htmlspecialchars($existing_notes[$student['matricule']]['cote_s1'] ?? ''); ?></td>
                        <?php endif; ?>
                        <?php if ($id_semestre == 2): ?>
                        <td style="text-align: center;"><?php echo htmlspecialchars($existing_notes[$student['matricule']]['cote_s2'] ?? ''); ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="signatures">
            <div class="signature-block">
                <p>Signature de l'Enseignant</p>
            </div>
            <div class="signature-block">
                <p>Signature du Doyen/Vice-doyen</p>
            </div>
        </div>
    </div>
</body>
</html>
