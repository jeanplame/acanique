<?php
require_once '../../../includes/db_config.php';
require_once '../../../includes/auth.php';
    header('Content-Type: text/html; charset=UTF-8');

// Vérifier que l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../../login.php');
    exit();
}

// Récupération des filtres depuis GET ou session
$id_annee = $_SESSION['id_annee'] ?? 1;
$id_semestre = isset($_GET['semestre']) && in_array($_GET['semestre'], [1, 2]) ? (int) $_GET['semestre'] : null;
$code_promo = $_GET['promotion'] ?? $_SESSION['promotion'] ?? null;
$id_mention = $_GET['mention'] ?? '';

// Récupération du nom de la mention à partir de l'id mention de le vue dans la table t_mention
$sqlMention = "SELECT libelle FROM t_mention WHERE id_mention=?";
$stmtMention = $pdo->prepare($sqlMention);
$stmtMention->execute([$id_mention]);
$mention = $stmtMention->fetchColumn();

// Extraire l'année académique à partir de l'id_annee
$sqlAnnee = "SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?";
$stmtAnnee = $pdo->prepare($sqlAnnee);
$stmtAnnee->execute([$id_annee]);
$annee = $stmtAnnee->fetch(PDO::FETCH_ASSOC);
$annee_academique = date('Y', strtotime($annee['date_debut'])) . '-' . date('Y', strtotime($annee['date_fin']));

// Déterminer les flags d'affichage
$afficher_s1 = $id_semestre === 1;
$afficher_s2 = $id_semestre === 2;
$afficher_tous = is_null($id_semestre);

// Requête PDO optimisée
$sql = "
    SELECT 
        notes.matricule,
        notes.nom_complet,
        notes.code_ue,
        notes.libelle_ue,
        notes.credits,
        notes.code_ec,
        notes.libelle_ec,
        notes.coef_ec,
        notes.cote_s1,
        notes.cote_s2,
        notes.moyenne_ec,
        notes.id_semestre,
        notes.code_promotion
    FROM vue_grille_deliberation notes
    WHERE notes.id_annee = :annee
    " . ($id_semestre ? "AND notes.id_semestre = :semestre" : "") . "
    " . ($code_promo ? "AND notes.code_promotion = :promo" : "") . "
    ORDER BY notes.nom_complet, notes.code_ue, notes.code_ec
";

$stmt = $pdo->prepare($sql);

// Paramètres dynamiques
$params = ['annee' => $id_annee];
if ($id_semestre)
    $params['semestre'] = $id_semestre;
if ($code_promo)
    $params['promo'] = $code_promo;

$stmt->execute($params);
$resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Jointure Récupération du nom de la filière à partir de l'id de la mention
$sqlFiliere = "SELECT f.nomFiliere, f.idFiliere 
               FROM t_filiere f 
               INNER JOIN t_mention m ON f.idFiliere = m.idFiliere 
               WHERE m.id_mention = ?";
$stmtFiliere = $pdo->prepare($sqlFiliere);
$stmtFiliere->execute([$id_mention]);
$filiere = $stmtFiliere->fetchColumn();
$resultFilieres = $stmtFiliere->fetchAll(PDO::FETCH_ASSOC);
$id_filiere = $resultFilieres[0]['idFiliere'] ?? null;

// Jointure de récupération du nom de domaine à partir de l'id_domaine de la table filière
$sqlDomaine = "SELECT d.id_domaine, d.nom_domaine as domaine 
               FROM t_domaine d 
               INNER JOIN t_filiere f ON d.id_domaine = f.id_domaine 
               INNER JOIN t_mention m ON f.idFiliere = m.idFiliere 
               WHERE m.id_mention = ?";
$stmtDomaine = $pdo->prepare($sqlDomaine);
$stmtDomaine->execute([$id_mention]);
$rowDomaine = $stmtDomaine->fetch(PDO::FETCH_ASSOC);
$domaine = $rowDomaine['domaine'] ?? '';
$id_domaine_jury = $rowDomaine['id_domaine'] ?? null;

// Récupération des membres du jury pour ce domaine et cette année
$jury_president = [];
$jury_secretaires = [];
$jury_membres = [];
if ($id_domaine_jury && $id_annee) {
    try {
        $stmtJury = $pdo->prepare("
            SELECT nom_complet, titre_academique, fonction, role_jury, ordre_affichage
            FROM t_jury_nomination
            WHERE id_domaine = :id_domaine AND id_annee = :id_annee
            ORDER BY FIELD(role_jury, 'president', 'secretaire', 'membre'), ordre_affichage, nom_complet
        ");
        $stmtJury->execute([':id_domaine' => $id_domaine_jury, ':id_annee' => $id_annee]);
        $juryAll = $stmtJury->fetchAll(PDO::FETCH_ASSOC);
        foreach ($juryAll as $jm) {
            $label = ($jm['titre_academique'] ? htmlspecialchars($jm['titre_academique']) . ' ' : '') . htmlspecialchars($jm['nom_complet']);
            if ($jm['fonction']) $label .= '<br><small style="font-weight:normal;font-style:italic;">' . htmlspecialchars($jm['fonction']) . '</small>';
            if ($jm['role_jury'] === 'president') $jury_president[] = $label;
            elseif ($jm['role_jury'] === 'secretaire') $jury_secretaires[] = $label;
            else $jury_membres[] = $label;
        }
    } catch (PDOException $e) {
        error_log("Erreur récupération jury impression: " . $e->getMessage());
    }
}

// Organisation des données
$etudiants = [];
$ues = [];
foreach ($resultats as $row) {
    $mat = $row['matricule'];
    $codeUE = $row['code_ue'];
    $codeEC = $row['code_ec'];

    // UE + EC pour construire l'en-tête
    if (!isset($ues[$codeUE])) {
        $ues[$codeUE] = [
            'libelle' => $row['libelle_ue'],
            'credits' => $row['credits'],
            'ecs' => []
        ];
    }
    if (!isset($ues[$codeUE]['ecs'][$codeEC])) {
        $ues[$codeUE]['ecs'][$codeEC] = [
            'libelle' => $row['libelle_ec'],
            'coef' => (float) $row['coef_ec'],
            'semestre' => (int) ($row['id_semestre'] ?? 0)
        ];
    }

    // Notes par étudiant
    $etudiants[$mat]['nom'] = $row['nom_complet'];
    $etudiants[$mat]['notes'][$codeUE][$codeEC] = [
        's1' => $row['cote_s1'],
        's2' => $row['cote_s2'],
        'moy' => $row['moyenne_ec']
    ];
}

// Construire listes UE/EC par semestre
$ues_s1 = [];
$ues_s2 = [];
foreach ($ues as $codeUE => $ue) {
    foreach ($ue['ecs'] as $codeEC => $ec) {
        if ($ec['semestre'] === 1) {
            $ues_s1[$codeUE]['libelle'] = $ue['libelle'];
            $ues_s1[$codeUE]['credits'] = $ue['credits'];
            $ues_s1[$codeUE]['ecs'][$codeEC] = $ec;
        } elseif ($ec['semestre'] === 2) {
            $ues_s2[$codeUE]['libelle'] = $ue['libelle'];
            $ues_s2[$codeUE]['credits'] = $ue['credits'];
            $ues_s2[$codeUE]['ecs'][$codeEC] = $ec;
        }
    }
}

// Fonctions de calcul
function calcTotalNotesPonderees($notes, $semestre)
{
    $total = 0;
    foreach ($notes as $ecs) {
        foreach ($ecs as $note) {
            $key = $semestre === 1 ? 's1' : 's2';
            if (!is_null($note[$key])) {
                $total += $note[$key] * $note['coef'];
            }
        }
    }
    return $total;
}

function calcTotalCredits($ues)
{
    $total = 0;
    foreach ($ues as $ue) {
        foreach ($ue['ecs'] as $ec) {
            $total += $ec['coef'];
        }
    }
    return $total;
}

// Calcule des crédits validés
function calcCreditsValides($notes, $ues)
{
    $totalCreditsValides = 0;

    foreach ($ues as $codeUE => $ue) {
        foreach ($ue['ecs'] as $codeEC => $ec) {
            // Vérifier les notes pour S1 et S2
            $noteS1 = $notes[$codeUE][$codeEC]['s1'] ?? null;
            $noteS2 = $notes[$codeUE][$codeEC]['s2'] ?? null;

            // Si note ≥ 10 dans un semestre, on ajoute les crédits de l'UE
            if (($noteS1 !== null && $noteS1 >= 10) || ($noteS2 !== null && $noteS2 >= 10)) {
                $totalCreditsValides = $noteS1 + $noteS2;
                // On ajoute les crédits une seule fois même si validé dans les deux semestres
                break;
            }
        }
    }

    return $totalCreditsValides;
}

function calcMoyennePonderee($totalNotes, $totalCredits)
{
    return $totalCredits ? $totalNotes / $totalCredits : 0;
}

function getMention($moy)
{
    if ($moy >= 18)
        return 'A';
    if ($moy >= 16)
        return 'B';
    if ($moy >= 14)
        return 'C';
    if ($moy >= 12)
        return 'D';
    if ($moy >= 10)
        return 'E';
    return 'F';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grille de Délibération - <?php echo htmlspecialchars($mention); ?> - <?php echo htmlspecialchars($annee_academique); ?></title>
    <style>
        @media print {
            body {
                margin: 0;
                padding: 10px;
            }
            .no-print {
                display: none;
            }
            .page-break {
                page-break-before: always;
            }
        }

        body {
            font-family: 'Century Gothic', Arial, sans-serif;
            margin: 0;
            padding: 10px;
            background: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Century Gothic';
            font-size: 11px;
            border: 3px solid #000;
        }

        table th,
        table td {
            border: 1px solid #000;
            text-align: center;
            padding: 0px;
        }

        .student-info {
            text-align: left;
            width: 260px;
        }

        .vertical-text {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            white-space: nowrap;
            height: auto;
            text-align: left;
            padding-bottom: 5px;
            padding-top: 2px;
        }

        .code-ue {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            white-space: nowrap;
            text-align: left;
            height: auto;
            padding-bottom: 5px;
            padding-top: 2px;
        }

        tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        .entete-texte {
            font-size: 13px;
            font-weight: 600;
            font-family: 'Century Gothic';
            transform: rotate(0deg);
            text-transform: uppercase;
        }

        .lignes-rdc {
            width: 70%;
            display: flex;
            margin: auto;
        }

        .blue,
        .jaune,
        .rouge {
            height: 5px;
            width: 32%;
        }

        .blue {
            background-color: blue;
        }

        .jaune {
            background-color: yellow;
        }

        .rouge {
            background-color: red;
        }

        .nom-app {
            font-size: 1.7em;
            letter-spacing: 5px;
            font-weight: 900;
            background-color: #003958;
            color: #fff;
            margin-top: 5px;
        }

        .footer {
            width: 100%;
            text-align: center;
            border: 1px solid #ffffffff !important;
        }
        .footer tr {
            border: 1px solid #ffffffff !important;
        }
        .footer tr td {
            border: 1px solid #ffffffff !important;
        }

        .print-buttons {
            margin: 20px 0;
            text-align: center;
        }

        .btn {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 0 10px;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            background-color: #0056b3;
        }

        .btn-success {
            background-color: #28a745;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .btn-secondary {
            background-color: #6c757d;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }
    </style>
</head>
<body>

<!-- Boutons d'impression (cachés à l'impression) -->
<div class="print-buttons no-print">
    <button class="btn btn-success" onclick="window.print()">
        <i class="bi bi-printer"></i> Imprimer la grille
    </button>
    <a href="javascript:history.back()" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> Retour
    </a>
</div>

<!-- Tableau principal -->
<table>
    <!-- En-tête générale -->
    <tr>
        <td rowspan="4" colspan="2" class="text-center">
            <div style="font-weight: bold; font-size: 14px;">Université Notre Dame de Lomami</div>
            <div>
                <img src="../../../img/logo.gif" style="width: 50px;">
            </div>
            <div class="entete-texte">FILIERE : <?php echo $filiere; ?></div>
            <div class="entete-texte">MENTION : <?php echo $mention ?></div>
            <div class="entete-texte">Grille de délibération</div>
            <div class="entete-texte">Promotion : <?php echo htmlspecialchars($code_promo); ?></div>
            <div style="font-size: 14px; font-weight: 900;">ANNEE ACADEMIQUE <?php echo $annee_academique; ?></div>
            <div class="lignes-rdc">
                <div class="blue"></div>
                <div class="jaune"></div>
                <div class="rouge"></div>
            </div>
            <div class="nom-app"><span style="color: #f8bc10; ">ACA</span>NIQUE</div>
        </td>
        <?php
        $nbrS1 = 0;
        $nbrS2 = 0;
        foreach ($ues_s1 as $codeUE => $ue) {
            $nbrS1 += count($ue);
        }
        foreach ($ues_s2 as $codeUE => $ue) {
            $nbrS2 += count($ue);
        }
        ?>
        <?php if ($afficher_s1 || $afficher_tous): ?>
            <td colspan="<?php echo $nbrS1 + 4; ?>" class="text-center" style="font-weight: bold; border-right: #000 5px solid;">
                Semestre 1</td>
        <?php endif; ?>

        <?php if ($afficher_s2 || $afficher_tous): ?>
            <td colspan="<?php echo $nbrS2 + 2; ?>" class="text-center" style="font-weight: bold; border-right: #000 5px solid;">
                Semestre 2</td>
        <?php endif; ?>

        <?php if ($afficher_tous): ?>
            <td colspan="6" class="text-center" style="font-weight: bold; border-right: #000 3px solid;">Annuelle</td>
        <?php endif; ?>
    </tr>

    <!-- Codes UE et EC -->
    <tr>
        <?php if ($afficher_s1 || $afficher_tous):
            foreach ($ues_s1 as $codeUE => $ue): ?>
                <td class="vertical-text code-ue" colspan="<?php echo count($ue['ecs']); ?>"><?php echo $codeUE; ?></td>
            <?php endforeach; ?>
            <td colspan="4" style="background:black; border-right: #000 5px solid;"></td>
        <?php endif; ?>

        <?php if ($afficher_s2 || $afficher_tous):
            foreach ($ues_s2 as $codeUE => $ue): ?>
                <td class="vertical-text code-ue" colspan="<?php echo count($ue['ecs']); ?>"><?php echo $codeUE; ?></td>
            <?php endforeach; ?>
            <td colspan="4" style="background:black; border-right: #000 5px solid;"></td>
        <?php endif; ?>

        <?php if ($afficher_tous): ?>
            <td colspan="6" style="background:black; border-right: #000 3px solid;"></td>
        <?php endif; ?>
    </tr>

    <!-- Codes EC -->
    <tr>
        <?php if ($afficher_s1 || $afficher_tous): ?>
            <?php foreach ($ues_s1 as $codeUE => $ue): ?>
                <?php foreach ($ue['ecs'] as $codeEC => $ec): ?>
                    <td class="vertical-text"><?php echo htmlspecialchars($ec['libelle']); ?></td>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <td class="vertical-text" style="background:gray;">Total Notes Pondérées</td>
            <td class="vertical-text" style="background:gray;">Moyenne Pondérée</td>
            <td class="vertical-text" style="background:gray;">Crédits S1</td>
            <td class="vertical-text" style="background:gray; border-right: #000 5px solid !important;">Mention</td>
        <?php endif; ?>

        <?php if ($afficher_s2 || $afficher_tous): ?>
            <?php foreach ($ues_s2 as $codeUE => $ue): ?>
                <?php foreach ($ue['ecs'] as $codeEC => $ec): ?>
                    <td class="vertical-text"><?php echo htmlspecialchars($ec['libelle']); ?></td>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <td class="vertical-text" style="background:gray;">Total Notes Pondérées</td>
            <td class="vertical-text" style="background:gray;">Moyenne Pondérée</td>
            <td class="vertical-text" style="background:gray;">Crédits S2</td>
            <td class="vertical-text" style="background:gray; border-right: #000 5px solid !important;">Mention</td>
        <?php endif; ?>

        <?php if ($afficher_tous): ?>
            <td class="vertical-text" style="background:gray;">Moyenne Annuelle</td>
            <td class="vertical-text" style="background:gray;">Total crédit validés Semestre 1 et 2</td>
            <td class="vertical-text" style="background:gray;">Total Notes Anneul</td>
            <td class="vertical-text" style="background:gray;">Pourcentage</td>
            <td class="vertical-text" style="background:gray;">Mention</td>
            <td class="vertical-text" style="background:gray;">Décision</td>
        <?php endif; ?>
    </tr>

    <!-- Ligne des maxima -->
    <tr>
        <?php if ($afficher_s1 || $afficher_tous):
            // référence des UEs à utiliser pour S1
            $ues_ref = $ues_s1;
            
            foreach ($ues_ref as $ue):
                foreach ($ue['ecs'] as $ec): ?>
                    <td style="background:gray;">20</td>
                <?php endforeach;
            endforeach;

            // Calcul du maximum total pondéré (20 * coef) et du total des coef pour S1 uniquement
            $maxTotal = 0;
            $totalCoef = 0;
            foreach ($ues_ref as $ue) {
                foreach ($ue['ecs'] as $ec) {
                    $maxTotal += 20 * $ec['coef'];
                    $totalCoef += $ec['coef'];
                }
            }
            $moyPonderee = $totalCoef > 0 ? $maxTotal / $totalCoef : 0;
            ?>
            <td style="background:gray;"><?php echo number_format($maxTotal, 0); ?></td>
            <td style="background:gray;"><?php echo number_format($moyPonderee, 0); ?></td>
            <td style="background:gray;"><?php echo $totalCoef; ?></td>
            <td style="background:black; border-right: #000 5px solid;"></td>
        <?php endif; ?>

        <?php if ($afficher_s2 || $afficher_tous):
            $ues_ref = $ues_s2;
            foreach ($ues_ref as $ue):
                foreach ($ue['ecs'] as $ec): ?>
                    <td style="background:gray;">20</td>
                <?php endforeach;
            endforeach;

            $maxTotal = 0;
            $totalCoef = 0;
            foreach ($ues_ref as $ue) {
                foreach ($ue['ecs'] as $ec) {
                    $maxTotal += 20 * $ec['coef'];
                    $totalCoef += $ec['coef'];
                }
            }
            $moyPonderee = $totalCoef > 0 ? $maxTotal / $totalCoef : 0;
            ?>
            <td style="background:gray;"><?php echo number_format($maxTotal, 0); ?></td>
            <td style="background:gray;"><?php echo number_format($moyPonderee, 0); ?></td>
            <td style="background:gray;"><?php echo $totalCoef; ?></td>
            <td style="background:black; border-right: #000 5px solid !important;"></td>
        <?php endif; ?>

        <?php if ($afficher_tous):
            $totalCoef = calcTotalCredits($ues);
            $maxTotalNotes = 0;
            $pourcent = 0;
            foreach ($ues as $ue) {
                foreach ($ue['ecs'] as $ec) {
                    $maxTotalNotes += 20 * $ec['coef'];
                }
            }
            ?>
            <td style="background:gray;"><?php echo number_format($moyPonderee, 0); ?></td>
            <td style="background:gray;"><?php echo $totalCoef; ?></td>
            <td style="background:gray;"><?php echo $maxTotalNotes ?></td>
            <td style="background:gray;">100</td>
            <td style="background:black;"></td>
            <td style="border-right: #000 3px solid; background: #000;"></td>
        <?php endif; ?>
    </tr>

    <!-- Ligne des crédits pour chaque élément constitutifs ou unité d'enseignement -->
    <tr style="background:gray; font-weight: bold;">
        <td>N°</td>
        <td>Nom, Postnom et Prénom</td>

        <?php if ($afficher_s1 || $afficher_tous):
            foreach ($ues_s1 as $ue):
                foreach ($ue['ecs'] as $ec): ?>
                    <td><?php echo $ec['coef']; ?></td>
                <?php endforeach;
            endforeach; ?>
            <td colspan="4" style="background:black; border-right: #000 5px solid;"></td>
        <?php endif; ?>

        <?php if ($afficher_s2 || $afficher_tous):
            foreach ($ues_s2 as $ue):
                foreach ($ue['ecs'] as $ec): ?>
                    <td><?php echo $ec['coef']; ?></td>
                <?php endforeach;
            endforeach; ?>
            <td colspan="4" style="background:black; border-right: #000 5px solid;"></td>
        <?php endif; ?>

        <?php if ($afficher_tous): ?>
            <td colspan="6" style="background:black; border-right: #000 3px solid;"></td>
        <?php endif; ?>
    </tr>

    <!-- Lignes étudiants -->
    <?php $i = 1;
    foreach ($etudiants as $mat => $data): ?>
        <tr style="font-weight: none;">
            <td><?php echo $i++; ?></td>
            <td class="student-info"><?php echo $data['nom']; ?></td>

            <?php if ($afficher_s1 || $afficher_tous):
                foreach ($ues_s1 as $codeUE => $ue):
                    foreach ($ue['ecs'] as $codeEC => $ec): ?>
                        <td><?php echo $data['notes'][$codeUE][$codeEC]['s1'] ?? '-'; ?></td>
                    <?php endforeach; endforeach;

                $totalS1 = 0;
                $creditsS1 = 0;
                foreach ($ues_s1 as $ue) {
                    foreach ($ue['ecs'] as $ec) {
                        $val = $data['notes'][$codeUE][$codeEC]['s1'] ?? null;
                        if (!is_null($val))
                            $totalS1 += $val * $ec['coef'];
                        if (!is_null($val) && $val >= 10) {
                            $creditsS1 += $ec['coef'];
                        }
                    }
                }
                $moyS1 = calcMoyennePonderee($totalS1, $creditsS1);
                ?>
                <td style="background:gray;"><?php echo number_format($totalS1, 0); ?></td>
                <td style="background:gray;"><?php echo number_format($moyS1, 0); ?></td>
                <td style="background:gray;"><?php echo $creditsS1; ?></td>
                <td style="background:gray; border-right: #000 5px solid;"><?php echo getMention($moyS1); ?></td>
            <?php endif; ?>

            <?php if ($afficher_s2 || $afficher_tous):
                foreach ($ues_s2 as $codeUE => $ue):
                    foreach ($ue['ecs'] as $codeEC => $ec): ?>
                        <td><?php echo $data['notes'][$codeUE][$codeEC]['s2'] ?? '-'; ?></td>
                        <?php
                    endforeach;
                endforeach;

                $totalS2 = 0;
                $creditsS2 = 0;
                foreach ($ues_s2 as $ue) {
                    foreach ($ue['ecs'] as $ec) {
                        $val = $data['notes'][$codeUE][$codeEC]['s2'] ?? null;
                        if (!is_null($val))
                            $totalS2 += $val * $ec['coef'];
                        if (!is_null($val) && $val >= 10) {
                            $creditsS2 += $ec['coef'];
                        }
                    }
                }
                $moyS2 = calcMoyennePonderee($totalS2, $creditsS2);
                ?>
                <td style="background:gray;"><?php echo number_format($totalS2, 0); ?></td>
                <td style="background:gray;"><?php echo number_format($moyS2, 0); ?></td>
                <td style="background:gray;"><?php echo $creditsS2; ?></td>
                <td style="background:gray; border-right: #000 5px solid;"><?php echo getMention($moyS2); ?></td>
            <?php endif; ?>

            <?php if ($afficher_tous):
                // Moyenne annuelle
                $moyennes = [];
                foreach ($data['notes'] as $ecs) {
                    foreach ($ecs as $note) {
                        if (!is_null($note['moy']))
                            $moyennes[] = $note['moy'];
                    }
                }
                $moyAnn = count($moyennes) ? array_sum($moyennes) / count($moyennes) : 0;
                $totalCreditsValides = calcCreditsValides($data['notes'], $ues);
                // Total des notes pondérées annuelles (S1 + S2)
                $totalNotesAnnuel = $totalS1 + $totalS2;

                // Calcul du pourcentage
                $totalNotes = $totalS1 + $totalS2;
                $pourcent = ($totalNotes / $maxTotalNotes) * 100;
                ?>
                <td style="background:gray;"><?php echo number_format($moyAnn, 0); ?></td>
                <td style="background:gray;"><?php echo calcCreditsValides($data['notes'], $ues); ?></td>
                <td style="background:gray;"><?php echo number_format($totalNotesAnnuel, 0); ?></td>
                <td style="background:gray;"><?php echo number_format($pourcent, 2); ?></td>
                <td style="background:gray;"></td>
                <td style="background:gray; border-right: #000 3px solid;"><?php echo getMention($moyAnn); ?></td>
            <?php endif; ?>

        </tr>
    <?php endforeach; ?>
</table>

<!-- Légende des mentions -->
<div style="text-align: left; font-style: italic; font-size: 11px; font-weight: bold; margin-top: 20px;">
    Mention :
    ➢ ≥ 10/20 = Passable (E) |
    ➢ ≥ 12/20 = Assez Bien (D) |
    ➢ ≥ 14/20 = Bien (C) |
    ➢ ≥ 16/20 = Très Bien (B) |
    ➢ ≥ 18/20 = Excellence (A)
</div>

<div style="text-align: right; font-style: italic; font-size: 12px; font-family: 'Century Gothic'; margin-top: 20px;">
    Fait à Kabinda, le <strong><?php echo date('d/m/Y'); ?></strong>
</div>

<div style="padding: 10px; border: #000 solid 1px; margin-top: 15px;">
    <table class="footer" style="border: #000 1px solid !important; width: 100%;">
        <tr>
            <td style="border-right: #000 1px solid !important; font-weight: bold; text-align: center; padding: 8px;">
                Président du Jury
            </td>
            <td style="border-right: #000 1px solid !important; font-weight: bold; text-align: center; padding: 8px;">
                Secrétaire(s) du Jury
            </td>
            <td style="font-weight: bold; text-align: center; padding: 8px;">
                Membres du Jury
            </td>
        </tr>
        <tr>
            <td style="min-height: 120px; border-right: #000 1px solid !important; vertical-align: top; padding: 10px; text-align: center;">
                <?php if (!empty($jury_president)): ?>
                    <?php foreach ($jury_president as $p): ?>
                        <div style="margin-bottom: 4px; font-weight: 600;"><?= $p ?></div>
                        <div style="margin-bottom: 20px; color: #aaa; letter-spacing: 2px;"></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="color: #999; padding-top: 30px;">........................</div>
                <?php endif; ?>
            </td>
            <td style="min-height: 120px; border-right: #000 1px solid !important; vertical-align: top; padding: 10px; text-align: center;">
                <?php if (!empty($jury_secretaires)): ?>
                    <?php foreach ($jury_secretaires as $s): ?>
                        <div style="margin-bottom: 4px; font-weight: 600;"><?= $s ?></div>
                        <div style="margin-bottom: 20px; color: #aaa; letter-spacing: 2px;"></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="color: #999; padding-top: 30px;">........................</div>
                <?php endif; ?>
            </td>
            <td style="min-height: 120px; vertical-align: top; padding: 10px; text-align: center;">
                <?php if (!empty($jury_membres)): ?>
                    <?php foreach ($jury_membres as $m): ?>
                        <div style="margin-bottom: 4px; font-weight: 600;"><?= $m ?></div>
                        <div style="margin-bottom: 20px; color: #aaa; letter-spacing: 2px;"></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="color: #999; padding-top: 30px;">........................</div>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    
</div>
<div style="text-align: right; margin-top: 100px; padding: 20px 0; opacity: 0.8; clear: both;">
    <div style="display: inline-block; vertical-align: middle; margin-right: 10px;">
        <svg width="26" height="26" viewBox="0 0 100 100">
            <defs>
                <linearGradient id="grad_final" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#dcdcdc;stop-opacity:1" />
                    <stop offset="100%" style="stop-color:#b0b0b0;stop-opacity:1" />
                </linearGradient>
            </defs>
            <path d="M 10 10 L 50 2 L 90 10 L 90 50 C 90 80, 50 100, 50 100 C 50 100, 10 80, 10 50 Z" opacity="1"/>
            <path d="M 40 75 L 60 25 L 70 35" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round"/>
            <path d="M 65,30 A 25 25 0 0 0 40 45" fill="none" stroke="#fff" stroke-width="6" opacity="0.9"/>
        </svg>
    </div>
    <div style="
        display: inline-block;
        vertical-align: middle;
        font-family: 'Century Gothic', sans-serif;
        font-size: 12pt;
        color: #525252ff;
        letter-spacing: 3px;
        text-transform: uppercase;
        line-height: 1.1;
        font-weight: 900 /* Très gras */ !important;
    ">
        ACANIQUE
        <br>
        <span style="
            text-decoration: underline;
            font-style: italic;
            font-variant: small-caps;
            text-transform: none;
            letter-spacing: normal;
            font-size: 10pt;
            line-height: 1.1;
            font-weight: bold;
        ">
            Académie Numérique
        </span>
    </div>
</div>

<script>
// Auto-focus pour l'impression
window.onload = function() {
    // Si le paramètre print=1 est dans l'URL, déclencher l'impression automatiquement
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('print') === '1') {
        setTimeout(function() {
            window.print();
        }, 1000);
    }
};
</script>

</body>
</html>
