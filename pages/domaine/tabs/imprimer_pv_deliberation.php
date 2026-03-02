<?php
require_once '../../../includes/db_config.php';
require_once '../../../includes/auth.php';
    header('Content-Type: text/html; charset=UTF-8');
// Exemple de données (à remplacer par les données réelles)
$id_annee = $_SESSION['id_annee'] ?? 1;
$id_semestre = isset($_GET['semestre']) && in_array($_GET['semestre'], [1, 2]) ? (int) $_GET['semestre'] : null;
$code_promo = $_GET['promotion'] ?? $_SESSION['promotion'] ?? null;
$id_mention = $_GET['mention'] ?? '';

// Validation des paramètres obligatoires
if (empty($id_mention)) {
    echo "<div class='alert alert-danger'>Erreur : ID mention manquant</div>";
    return;
}

if (empty($id_annee)) {
    echo "<div class='alert alert-danger'>Erreur : ID année manquant</div>";
    return;
}

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
    " . ($id_mention ? "AND notes.id_mention = :mention" : "") . "
    " . ($id_semestre ? "AND notes.id_semestre = :semestre" : "") . "
    " . ($code_promo ? "AND notes.code_promotion = :promo" : "") . "
    ORDER BY notes.nom_complet, notes.code_ue, notes.code_ec
";

$stmt = $pdo->prepare($sql);

// Paramètres dynamiques
$params = ['annee' => $id_annee];
if ($id_mention)
    $params['mention'] = $id_mention;
if ($id_semestre)
    $params['semestre'] = $id_semestre;
if ($code_promo)
    $params['promo'] = $code_promo;

$stmt->execute($params);
$resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);



// Debug : afficher les paramètres et le nombre de résultats
if (empty($resultats)) {
    echo "<div class='alert alert-warning'>Aucune donnée trouvée. Paramètres: ";
    echo "Année: $id_annee, Mention: $id_mention, Semestre: $id_semestre, Promotion: $code_promo";
    echo "</div>";
}


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
$sqlDomaine = "SELECT d.nom_domaine as domaine 
               FROM t_domaine d 
               INNER JOIN t_filiere f ON d.id_domaine = f.id_domaine 
               INNER JOIN t_mention m ON f.idFiliere = m.idFiliere 
               WHERE m.id_mention = ?";
$stmtDomaine = $pdo->prepare($sqlDomaine);
$stmtDomaine->execute([$id_mention]);
$domaine = $stmtDomaine->fetchColumn();



// Organisation des données
$etudiants = [];
$ues = [];

foreach ($resultats as $row) {
    $mat = $row['matricule'];
    $codeUE = $row['code_ue'];
    $codeEC = $row['code_ec'];
    $semestre = (int) $row['id_semestre'];

    // Initialiser l'UE
    if (!isset($ues[$codeUE])) {
        $ues[$codeUE] = [
            'libelle' => $row['libelle_ue'],
            'credits' => isset($row['credits']) ? (float) $row['credits'] : 0,
            'ecs' => []
        ];
    }

    // Détecter UE sans EC (placeholder)
    $isUeSansEc = ($codeEC === $codeUE) || (isset($row['id_ec']) && strpos($row['id_ec'], 'UE_') === 0) || empty($codeEC);

    if ($isUeSansEc) {
        // On garde un "ec" placeholder pour connaître le semestre mais on utilisera le crédit UE
        $ues[$codeUE]['ecs'][$codeEC] = [
            'libelle' => $row['libelle_ec'] ?? $row['libelle_ue'],
            'coef' => null,
            'semestre' => $semestre,
            'is_ue_sans_ec' => true
        ];
    } else {
        // EC normal : stocker le coef sous la clé 'coef'
        if (!isset($ues[$codeUE]['ecs'][$codeEC])) {
            $ues[$codeUE]['ecs'][$codeEC] = [
                'libelle' => $row['libelle_ec'],
                'coef' => isset($row['coef_ec']) ? (float) $row['coef_ec'] : 1,
                'semestre' => $semestre,
                'is_ue_sans_ec' => false
            ];
        }
    }

    // Notes par étudiant (inchangé)
    if (!isset($etudiants[$mat])) {
        $etudiants[$mat] = [
            'nom' => $row['nom_complet'],
            'notes' => []
        ];
    }

    if (!isset($etudiants[$mat]['notes'][$codeUE])) {
        $etudiants[$mat]['notes'][$codeUE] = [];
    }

    if (!isset($etudiants[$mat]['notes'][$codeUE][$codeEC])) {
        $etudiants[$mat]['notes'][$codeUE][$codeEC] = [
            's1' => null,
            's2' => null,
            'moy' => null
        ];
    }

    $etudiants[$mat]['notes'][$codeUE][$codeEC] = [
        's1' => $row['cote_s1'],
        's2' => $row['cote_s2'],
        'moy' => $row['moyenne_ec']
    ];
}

// Supprimer la première ligne vide si elle existe
if (isset($etudiants['']) && empty($etudiants['']['nom'])) {
    unset($etudiants['']);
}


// Construire listes UE/EC par semestre (les "credits" d'un EC = son coef ; pour UE sans EC on garde credit UE)
$ues_s1 = [];
$ues_s2 = [];

foreach ($ues as $codeUE => $ue) {
    foreach ($ue['ecs'] as $codeEC => $ec) {
        $sem = $ec['semestre'] ?? 0;
        $isPlaceholder = !empty($ec['is_ue_sans_ec']);

        if ($sem === 1) {
            if (!isset($ues_s1[$codeUE])) {
                $ues_s1[$codeUE] = ['libelle' => $ue['libelle'], 'credits' => 0, 'ecs' => []];
            }
            if ($isPlaceholder) {
                // UE sans EC : la "cellule" représente l'UE entière -> on utilise le crédit UE
                $ues_s1[$codeUE]['ecs'][$codeEC] = [
                    'libelle' => $ec['libelle'] ?? $ue['libelle'],
                    'coef' => null,
                    'credits' => $ue['credits'] ?? 0,
                    'is_ue_sans_ec' => true
                ];
                $ues_s1[$codeUE]['credits'] += $ue['credits'] ?? 0;
            } else {
                $coef = $ec['coef'] ?? 1;
                $ues_s1[$codeUE]['ecs'][$codeEC] = $ec;
                $ues_s1[$codeUE]['ecs'][$codeEC]['credits'] = $coef; // crédit EC = coef
                $ues_s1[$codeUE]['credits'] += $coef;
            }
        } elseif ($sem === 2) {
            if (!isset($ues_s2[$codeUE])) {
                $ues_s2[$codeUE] = ['libelle' => $ue['libelle'], 'credits' => 0, 'ecs' => []];
            }
            if ($isPlaceholder) {
                $ues_s2[$codeUE]['ecs'][$codeEC] = [
                    'libelle' => $ec['libelle'] ?? $ue['libelle'],
                    'coef' => null,
                    'credits' => $ue['credits'] ?? 0,
                    'is_ue_sans_ec' => true
                ];
                $ues_s2[$codeUE]['credits'] += $ue['credits'] ?? 0;
            } else {
                $coef = $ec['coef'] ?? 1;
                $ues_s2[$codeUE]['ecs'][$codeEC] = $ec;
                $ues_s2[$codeUE]['ecs'][$codeEC]['credits'] = $coef;
                $ues_s2[$codeUE]['credits'] += $coef;
            }
        }
    }
}


// Initialisation des groupes
$definitifs = [];
$compenses = [];
$ajournes = [];
$defaillants = [];

// Classification selon règles académiques
foreach ($etudiants as $matricule => $etudiant) {
    $notes = $etudiant['notes'];
    $absent = false;
    foreach ($notes as $n) {
        if (!isset($n['s1']) || !isset($n['s2']) || $n['s1'] === null || $n['s2'] === null) {
            $absent = true;
            break;
        }
    }

    if ($absent) {
        $etudiants[$matricule]['decision'] = 'DEFAILLANT(E)';
        $defaillants[] = $etudiant;
    } elseif ($etudiant['moyenne_annuelle'] >= 10) {
        $etudiants[$matricule]['decision'] = 'ADMIS(E) DÉFINITIF';
        $definitifs[] = $etudiant;
    } elseif ($etudiant['moyenne_annuelle'] >= 9) {
        $etudiants[$matricule]['decision'] = 'ADMIS(E) PAR COMPENSATION';
        $compenses[] = $etudiant;
    } else {
        $etudiants[$matricule]['decision'] = 'AJOURNÉ(E)';
        $ajournes[] = $etudiant;
    }
}


// Total des notes pondérées pour un semestre
function calcTotalNotesPonderees($notes, $ues, $semestre)
{
    $total = 0;
    foreach ($ues as $codeUE => $ue) {
        // Cas 1 : UE sans EC → utiliser son crédit directement
        if (empty($ue['ecs'])) {
            $note = $notes[$codeUE]['ue'][$semestre] ?? null; // note directe d'UE si existe
            if (!is_null($note)) {
                $total += $note * $ue['credits'];
            }
        } else {
            // Cas 2 : UE avec EC → utiliser les coefficients des EC
            foreach ($ue['ecs'] as $codeEC => $ec) {
                if (isset($notes[$codeUE][$codeEC])) {
                    $key = $semestre === 1 ? 's1' : 's2';
                    $note = $notes[$codeUE][$codeEC][$key] ?? null;
                    if (!is_null($note)) {
                        $coef = $ec['coef'] ?? 1;
                        $total += $note * $coef;
                    }
                }
            }
        }
    }
    return $total;
}

// Total des coefficients / crédits
function calcTotalCoef($ues)
{
    $total = 0;
    foreach ($ues as $ue) {
        if (empty($ue['ecs'])) {
            $total += $ue['credits'] ?? 0;
        } else {
            foreach ($ue['ecs'] as $ec) {
                $coef = $ec['coef'] ?? 1;
                $total += $coef;
            }
        }
    }
    return $total;
}

// Coefficients validés (somme des coefs/crédits des EC validés)
function calcCoefsValides($notes, $ues)
{
    $totalCoefsValides = 0;
    foreach ($ues as $codeUE => $ue) {
        if (empty($ue['ecs'])) {
            // UE sans EC → validation sur la note UE
            $noteS1 = $notes[$codeUE]['ue']['s1'] ?? null;
            $noteS2 = $notes[$codeUE]['ue']['s2'] ?? null;
            if (($noteS1 !== null && $noteS1 >= 10) || ($noteS2 !== null && $noteS2 >= 10)) {
                $totalCoefsValides += $ue['credits'];
            }
        } else {
            foreach ($ue['ecs'] as $codeEC => $ec) {
                if (isset($notes[$codeUE][$codeEC])) {
                    $noteS1 = $notes[$codeUE][$codeEC]['s1'] ?? null;
                    $noteS2 = $notes[$codeUE][$codeEC]['s2'] ?? null;
                    $coef = $ec['coef'] ?? 1;

                    if (($noteS1 !== null && $noteS1 >= 10) || ($noteS2 !== null && $noteS2 >= 10)) {
                        $totalCoefsValides += $coef;
                    }
                }
            }
        }
    }
    return $totalCoefsValides;
}

// Total des crédits validés pour un étudiant
function calcCreditsValides($notes, $ues)
{
    $totalCredits = 0;
    foreach ($ues as $codeUE => $ue) {
        if (empty($ue['ecs'])) {
            // UE sans EC → validation sur la note UE
            $noteS1 = $notes[$codeUE]['ue']['s1'] ?? null;
            $noteS2 = $notes[$codeUE]['ue']['s2'] ?? null;
            if (($noteS1 !== null && $noteS1 >= 10) || ($noteS2 !== null && $noteS2 >= 10)) {
                $totalCredits += $ue['credits'];
            }
        } else {
            // UE avec EC → validée si tous ses EC (ou une partie) sont validés
            $ueValidee = false;
            foreach ($ue['ecs'] as $codeEC => $ec) {
                if (isset($notes[$codeUE][$codeEC])) {
                    $noteS1 = $notes[$codeUE][$codeEC]['s1'] ?? null;
                    $noteS2 = $notes[$codeUE][$codeEC]['s2'] ?? null;
                    if (($noteS1 !== null && $noteS1 >= 10) || ($noteS2 !== null && $noteS2 >= 10)) {
                        $ueValidee = true;
                        break;
                    }
                }
            }
            if ($ueValidee) {
                // Crédit de l'UE = somme des coefs de ses EC
                foreach ($ue['ecs'] as $ec) {
                    $totalCredits += $ec['coef'] ?? 1;
                }
            }
        }
    }
    return $totalCredits;
}

// Moyenne pondérée
function calcMoyennePonderee($totalNotes, $totalCredits)
{
    return $totalCredits ? $totalNotes / $totalCredits : 0;
}

// Mention
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

// Total des crédits (UE sans EC = crédits UE, UE avec EC = somme coefs EC)
function calcTotalCredits($ues)
{
    $total = 0;
    foreach ($ues as $ue) {
        if (empty($ue['ecs'])) {
            $total += $ue['credits'] ?? 0;
        } else {
            foreach ($ue['ecs'] as $ec) {
                $total += $ec['coef'] ?? 1;
            }
        }
    }
    return $total;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>PV de Délibération <?php echo htmlspecialchars($annee_academique); ?></title>
    <style>
        body {
            font-family: "Times New Roman", serif;
            font-size: 11pt;
            line-height: 1.5;
            margin: 20px 40px;
        }
        .header {
            text-align: center;
        }
        .header img {
            width: 70px;
            float: left;
        }
        .title-box {
            border: 1px solid #000;
            border-radius: 5px;
            padding: 5px 15px;
            width: fit-content;
            margin: 20px auto;
            font-weight: bold;
        }
        ul {
            list-style-type: none;
            padding-left: 20px;
        }
        ul li::before {
            content: "🔸 "; /* puce type emoji comme sur l'image */
        }
        ol {
            padding-left: 20px;
        }
        .signatures {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
            text-align: center;
        }
        .signatures div {
            width: 30%;
        }
    </style>
</head>
<body>

<div class="header">
    <img src="../../../img/logo.gif" alt="Logo">
    <p>
        ENSEIGNEMENT SUPERIEUR ET UNIVERSITAIRE, RECHERCHE SCIENTIFIQUE ET INNOVATION <br>
        <strong>UNIVERSITE NOTRE DAME DE LOMAMI</strong> <br>
        <strong>UNILO</strong> <br>
        <strong>DOMAINE DE SCIENCES ECONOMIQUES ET DE GESTION</strong>
    </p>
</div>

<div class="title-box">PROCES VERBAL DE DELIBERATION</div>

<p style="text-align: justify;">
    Le jury chargé de procéder aux examens en troisième année de licence en sciences économiques et de gestion,
    filière <u><?php echo htmlspecialchars($filiere); ?></u>,
    Mention <u><?php echo htmlspecialchars($mention); ?></u>, a reçu les examens des candidats.
</p>

<p>Après délibération à huis clos, le jury a pris les décisions suivantes :</p>

<ul>
    <li>Sont admis avec capitalisation définitive des crédits : <strong><?php echo count($definitifs); ?> étudiants</strong></li>
    <li>Sont admis après compensation des notes : <strong><?php echo count($compenses); ?> étudiants</strong></li>
    <li>Sont ajournés et doivent revenir à la session de rattrapage : <strong><?php echo count($ajournes); ?> étudiants</strong></li>
    <li>Sont défaillants (absences) : <strong><?php echo count($defaillants); ?> étudiants</strong></li>
</ul>

<?php if (empty($definitifs)): ?>
    <p><u>Sont admis avec capitalisation des crédits :</u></p>
    <ol>
        <?php foreach ($definitifs as $etudiant): ?>
            <li><?php echo strtoupper($etudiant['nom_complet']); ?></li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php if (empty($compenses)): ?>
    <p><u>Sont admis après compensation des notes :</u></p>
    <ol>
        <?php foreach ($compenses as $etudiant): ?>
            <li><?php echo strtoupper($etudiant['nom_complet']); ?></li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php if (empty($ajournes)): ?>
    <p><u>Sont ajournés :</u></p>
    <ol>
        <?php foreach ($ajournes as $etudiant): ?>
            <li><?php echo strtoupper($etudiant['nom_complet']); ?></li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php if (empty($defaillants)): ?>
    <p><u>Sont défaillants :</u></p>
    <ol>
        <?php foreach ($defaillants as $etudiant): ?>
            <li><?php echo strtoupper($etudiant['nom_complet']); ?></li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>


<p style="text-align: justify; margin-top: 20px;">
    En foi de quoi, nous déclarons close la session de deuxième semestre de l’année académique
    <u><?php echo htmlspecialchars($annee_academique); ?></u> en troisième année de licence sciences économiques et de gestion,
    filière <u><?php echo htmlspecialchars($filiere); ?></u>, Mention <u><?php echo htmlspecialchars($mention); ?></u>.
</p>

<p style="text-align: right; margin-top: 20px;">
    Fait à Kabinda le <?php echo date('d/m/Y'); ?>
</p>

<div class="signatures">
    <div>
        <strong>Le Président du jury</strong><br><br><br>
        ........................
    </div>
    <div>
        <strong>Le Secrétaire du jury</strong><br><br><br>
        ........................
    </div>
    <div>
        <strong>Les membres du jury</strong><br><br><br>
        ........................
    </div>
</div>

</body>
</html>
