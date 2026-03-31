<?php
/**
 * SYSTÈME DE CONSULTATION DES RÉSULTATS - STRUCTURE RÉVISÉE (t_cote)
 */

// Configuration de la connexion
$db_host = 'localhost';
$db_name = 'lmd_db';
$db_user = 'root';
$db_pass = 'mysarnye';

$conn = null;
$error_message = "";
$student_data = null;
$results = [];

try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    $error_message = "Erreur de connexion : " . $e->getMessage();
}

// Traitement de la recherche
if (isset($_POST['matricule']) && !empty($_POST['matricule']) && $conn) {
    $matricule = htmlspecialchars($_POST['matricule']);

    try {
        // 1. Récupérer les infos de l'étudiant depuis t_etudiant
        $stmt = $conn->prepare("SELECT * FROM t_etudiant WHERE matricule = ?");
        $stmt->execute([$matricule]);
        $student_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($student_data) {
            /**
             * 2. Récupérer les notes depuis t_cote
             * On gère le cas où l'id_ec est NULL (note assignée à l'UE directement)
             * ou assignée à un EC spécifique.
             */
            $query = "
                SELECT 
                    ue.libelle as ue_libelle, 
                    ue.code_ue,
                    ue.credits as ue_credits,
                    ec.libelle as ec_libelle,
                    ec.code_ec,
                    c.cote_s1,
                    c.cote_s2,
                    c.cote_rattrapage_s1,
                    c.cote_rattrapage_s2
                FROM t_cote c
                LEFT JOIN t_unite_enseignement ue ON c.id_ue = ue.id_ue
                LEFT JOIN t_element_constitutif ec ON c.id_ec = ec.id_ec
                WHERE c.matricule = ?
                ORDER BY ue.id_ue, ec.id_ec
            ";
            $stmt_res = $conn->prepare($query);
            $stmt_res->execute([$matricule]);
            $results = $stmt_res->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $error_message = "Aucun dossier trouvé pour le matricule : " . $matricule;
        }
    } catch(PDOException $e) {
        $error_message = "Erreur SQL : " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Résultats Étudiants - LMD</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .bg-lmd { background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); color: white; }
        .table thead { background-color: #f1f5f9; }
        .note-val { font-weight: bold; }
        .valide { color: #198754; }
        .echec { color: #dc3545; }
        @media print { .no-print { display: none; } .card { box-shadow: none; border: 1px solid #eee; } }
    </style>
</head>
<body>

<div class="container py-5">
    <!-- Formulaire de recherche -->
    <div class="row justify-content-center no-print mb-4">
        <div class="col-md-6 text-center">
            <h2 class="mb-4 fw-bold">Consultation des Notes</h2>
            <div class="card p-4">
                <form method="POST" class="d-flex gap-2">
                    <input type="text" name="matricule" class="form-control form-control-lg" 
                           placeholder="Entrez votre matricule..." required
                           value="<?php echo isset($_POST['matricule']) ? htmlspecialchars($_POST['matricule']) : ''; ?>">
                    <button type="submit" class="btn btn-primary btn-lg px-4">Rechercher</button>
                </form>
            </div>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger shadow-sm"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <?php if ($student_data): ?>
        <!-- Nouvelle mise en forme professionnelle du relevé -->
        <div class="tab-pane fade show active" id="grades" role="tabpanel" aria-labelledby="grades-tab" id="releveCotes">
            <div class="card shadow-lg p-4 mb-4">
                <!-- Entête professionnel et académique -->
                <style>
                    .entête { text-align: center; margin-bottom: 20px; font-family: 'Tw Cen MT', sans-serif !important; color: black !important; }
                    .entête p { margin: 0; line-height: 1.3; }
                    .esu { font-family: 'Edwardian Script ITC', cursive; font-size: 2.2rem; }
                    .unilo { font-family: 'Tw Cen MT', sans-serif; font-size: 1.2rem; font-weight: 900; }
                    .contact { font-family: 'Tw Cen MT', sans-serif; font-size: 1rem; font-weight: 600; }
                    .infos-etu { font-family: 'Tw Cen MT', sans-serif; font-size: 1rem; font-style: italic; }
                    .contact2 { font-family: 'Tw Cen MT', sans-serif; font-size: 1rem; font-weight: 600; border-bottom: 7px double black; }
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
                                <p class="contact">Contact : <a href="mailto:sgac@unilo.net">sgac@unilo.net</a></p>
                                <p class="contact">Téléphone : +243 813 677 556 / 898 472 255</p>
                            </td>
                            <td style="width: 10%;">
                                <img src="<?php echo $student_data['photo'] ?? '../../img/default-user.png'; ?>" alt="" style="width: 80px;">
                            </td>
                        </tr>
                    </table>
                    <table style="width: 100%;">
                        <tr>
                            <td><p class="infos-etu">Domaine : <span><?php echo htmlspecialchars($student_data['domaine'] ?? ''); ?></span></p></td>
                            <td><p class="infos-etu">Filière : <span><?php echo htmlspecialchars($student_data['filiere'] ?? ''); ?></span></p></td>
                            <td><p class="infos-etu">Mention : <span><?php echo htmlspecialchars($student_data['mention'] ?? ''); ?></span></p></td>
                        </tr>
                    </table>
                </div>
                <div class="titre-doc">
                    <style>.titre-doc .titre { line-height: 1.7; font-size: 1.6rem; font-weight: 900; margin-top: 0; }</style>
                    <p class="text-center fw-bold titre" style="text-decoration: underline;">RELEVE DE NOTES</p>
                    <p class="text-center">N° <span class="fw-bold"><?php echo str_pad($student_data['id_etudiant'] ?? 0, 3, '0', STR_PAD_LEFT); ?>/SGA/UNILO/<?php echo date('Y'); ?>/</span></p>
                    <br>
                </div>
                <div class="texte">
                    <style>.texte { text-align: justify; } .texte span { font-weight: bold; }</style>
                    <p>
                        <?php $titre = ($student_data['sexe'] ?? 'M') === 'M' ? 'Monsieur' : 'Madame'; ?>
                        <?php echo $titre . ' ' . htmlspecialchars($student_data['nom_etu'] . ' ' . ($student_data['postnom_etu'] ?? '') . ' ' . $student_data['prenom_etu']); ?>,
                        né à <span><?php echo htmlspecialchars($student_data['lieu_naiss'] ?? ''); ?></span>, le
                        <span><?php if (!empty($student_data['date_naiss'])) { try { $date = new DateTime($student_data['date_naiss']); $formatter = new IntlDateFormatter('fr_FR', IntlDateFormatter::LONG, IntlDateFormatter::NONE); $formatter->setPattern('d MMMM yyyy'); echo htmlspecialchars($formatter->format($date)); } catch (Exception $e) { echo htmlspecialchars($student_data['date_naiss']); } } ?></span>, a obtenu à l'issue de l'année académique <span><?php echo date('Y'); ?></span>, les résultats obtenus régulièrement à l’ensemble des <span>UE(et ECUE)</span> prévus au programme.<br>
                    </p>
                </div>
                <style>
                    table.word-style { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 14px; }
                    table.word-style th, table.word-style td { border: 1px solid #000; padding: 4px 6px; vertical-align: middle; }
                    table.word-style th { background-color: #f2f2f2; font-weight: bold; }
                    table.word-style tbody tr:hover { background-color: #e6f2ff; }
                    .table-danger { background-color: #f8d7da !important; color: #842029; }
                    table.word-style td:nth-child(3), table.word-style td:nth-child(4), table.word-style td:nth-child(5) { text-align: center; }
                </style>
                <div class="table-responsive">
                    <table class="word-style" style="border-collapse: collapse; width: 100%;">
                        <thead>
                            <tr>
                                <th>Code UE</th>
                                <th>Unités d’enseignements (UE) et éléments constitutifs (ECUE)</th>
                                <th>Crédits</th>
                                <th>Notes (/20)</th>
                                <th>Notes pondérées<br><span style="font-size:10px;">(Nbre crédits x 20)</span></th>
                                <th>Décision<br><span style="font-size:10px;">(validé ou non)</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // On suppose que $results contient toutes les notes de l'étudiant (UE/ECUE)
                            $ues = [];
                            foreach ($results as $note) {
                                $code_ue = $note['code_ue'] ?? '';
                                if (!isset($ues[$code_ue])) {
                                    $ues[$code_ue] = [
                                        'ue' => $note['ue_libelle'] ?? '',
                                        'credits_ue' => $note['ue_credits'] ?? 0,
                                        'ecues' => [],
                                        'has_ecue' => false,
                                        'ue_note' => null,
                                        'ue_credits' => $note['ue_credits'] ?? 0,
                                        'ue_ponderee' => null,
                                        'ue_decision' => null,
                                    ];
                                }
                                if (!empty($note['ec_libelle'])) {
                                    $ues[$code_ue]['ecues'][] = $note;
                                    $ues[$code_ue]['has_ecue'] = true;
                                } else {
                                    // UE sans ECUE
                                    $cote = max($note['cote_s1'] ?? 0, $note['cote_s2'] ?? 0);
                                    $credits_ue = $note['ue_credits'] ?? 0;
                                    $ponderee = $credits_ue * $cote;
                                    $decision = ($cote >= 10) ? 'Validé' : 'Non validé';
                                    $ues[$code_ue]['ue_note'] = $cote;
                                    $ues[$code_ue]['ue_credits'] = $credits_ue;
                                    $ues[$code_ue]['ue_ponderee'] = $ponderee;
                                    $ues[$code_ue]['ue_decision'] = $decision;
                                }
                            }
                            foreach ($ues as $code_ue => $ue_data):
                                if ($ue_data['has_ecue']):
                                    $ecues = $ue_data['ecues'];
                                    $rowspan = count($ecues) + 1;
                            ?>
                                <tr>
                                    <td rowspan="<?= $rowspan ?>" style="border:1px solid #000; padding:4px; text-align:center; vertical-align:middle;">
                                        <?= htmlspecialchars($code_ue); ?>
                                    </td>
                                    <td colspan="5" style="border:1px solid #000; padding:4px; font-weight:bold;">
                                        <?= htmlspecialchars($ue_data['ue']); ?>
                                    </td>
                                </tr>
                                <?php foreach ($ecues as $note):
                                    $cote = max($note['cote_s1'] ?? 0, $note['cote_s2'] ?? 0);
                                    $credits_ecue = $note['coefficient'] ?? $note['ue_credits'] ?? 0;
                                    $ponderee = $credits_ecue * $cote;
                                    $row_class = ($cote < 10) ? 'table-danger' : '';
                                ?>
                                <tr class="<?= $row_class; ?>">
                                    <td style="border:1px solid #000; padding:4px;">
                                        <?= htmlspecialchars($note['ec_libelle']); ?>
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
                            <?php else:
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
                            <?php endif; endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Synthèse et signatures -->
                <?php
                    $total_credits = 0;
                    $credits_valides = 0;
                    $total_ponderees = 0;
                    $credits_counted = [];
                    $credits_valides_counted = [];
                    foreach ($results as $note) {
                        $key = ($note['code_ue'] ?? '') . '|' . ($note['ec_libelle'] ?? '');
                        $credits = isset($note['coefficient']) ? (float)$note['coefficient'] : (float)($note['ue_credits'] ?? 0);
                        $cote = max($note['cote_s1'] ?? 0, $note['cote_s2'] ?? 0);
                        if (!isset($credits_counted[$key])) {
                            $total_credits += $credits;
                            $credits_counted[$key] = true;
                        }
                        if ($cote >= 10 && !isset($credits_valides_counted[$key])) {
                            $credits_valides += $credits;
                            $credits_valides_counted[$key] = true;
                        }
                        $total_ponderees += $cote * $credits;
                    }
                    $max_ponderees = $total_credits * 20;
                    $moyenne = $total_credits > 0 ? $total_ponderees / $total_credits : 0;
                ?>
                <div class="row mt-4" style="font-family: 'Century Gothic', Arial, sans-serif; font-size: 14px;">
                    <div class="col-6" style="vertical-align: top;">
                        <div style="margin-bottom: 6px;">Total crédits : <span><?php echo $total_credits; ?></span></div>
                        <div style="margin-bottom: 6px;">Crédits validés : <span><?php echo $credits_valides; ?></span></div>
                        <div style="margin-bottom: 6px;">Total notes pondérées annuel : <span><?php echo number_format($total_ponderees, 2) . " /" . $max_ponderees . " (" . $total_credits . "x20)"; ?></span></div>
                        <div style="margin-bottom: 6px;">Moyenne annuelle pondérée : <span><?php echo number_format($moyenne, 2) . " /20"; ?></span></div>
                        <div style="margin-top: 30px; font-weight: bold; text-align: center;">LE DOYEN DE LA FACULTE</div>
                        <div style="margin-top: 30px; text-align: center; font-weight: bold;">Prof Abbé Pierre ILUNGA KALE</div>
                    </div>
                    <div class="col-6" style="vertical-align: top;">
                        <div style="margin-bottom: 6px;">Mention : <span><?php $mention = "Non attribuée"; if ($moyenne >= 16) $mention = "Distinction"; elseif ($moyenne >= 14) $mention = "Grande Satisfaction"; elseif ($moyenne >= 12) $mention = "Satisfaction"; elseif ($moyenne >= 10) $mention = "Passable"; echo $mention . " définitive de crédits"; ?></span></div>
                        <div style="margin-bottom: 6px;">Décision du jury</div>
                        <div style="margin-top: 30px; font-weight: bold;">Fait à Kabinda le ......../......../<?php echo date('Y'); ?></div>
                        <div style="margin-top: 30px; font-weight: bold;">LE SECRETAIRE GENERAL ACADEMIQUE</div>
                        <div style="margin-top: 30px; font-weight: bold;">Prof Mgr Lambert KANKENZA MUTEBA</div>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-4">
                    <a href="releve_cotes.php?matricule=<?php echo urlencode($student_data['matricule']); ?>" target="_blank" class="btn btn-gradient-primary btn-lg rounded-pill shadow-sm" style="background: linear-gradient(90deg, #005e91 0%, #00b4d8 100%); color: #fff; border: none; font-weight: 600; letter-spacing: 1px;"><i class="fas fa-print me-2"></i> Imprimer le relevé de notes</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>