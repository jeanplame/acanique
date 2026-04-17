<?php
require_once __DIR__ . '/../../includes/db_config.php';

header('Content-Type: text/html; charset=UTF-8');

if (!function_exists('printStudentListEsc')) {
    function printStudentListEsc($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$promotionParam = trim((string) ($_GET['promotion'] ?? ($_GET['code_promotion'] ?? '')));
$mentionId = isset($_GET['mention']) ? (int) $_GET['mention'] : 0;
$anneeId = isset($_GET['annee']) ? (int) $_GET['annee'] : 0;
$idDomaine = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$printMode = isset($_GET['print']) && $_GET['print'] === '1';
$globalMode = ($promotionParam === '');

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$baseUrl = '';
$pagesPos = strpos($scriptName, '/pages/');
if ($pagesPos !== false) {
    $baseUrl = substr($scriptName, 0, $pagesPos);
} else {
    $baseUrl = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
}
$logoPath = ($baseUrl !== '' ? $baseUrl : '') . '/img/logo.gif';

if ($anneeId <= 0) {
    try {
        $stmtActiveYear = $pdo->query("SELECT CAST(valeur AS UNSIGNED) FROM t_configuration WHERE cle = 'annee_encours' LIMIT 1");
        $anneeId = (int) ($stmtActiveYear->fetchColumn() ?: 0);
    } catch (Exception $e) {
        $anneeId = 0;
    }
}

$anneeLibelle = 'Toutes les années';
if ($anneeId > 0) {
    try {
        $stmtAnnee = $pdo->prepare(
            "SELECT CONCAT(YEAR(date_debut), '-', YEAR(date_fin)) AS libelle
             FROM t_anne_academique
             WHERE id_annee = :id_annee
             LIMIT 1"
        );
        $stmtAnnee->execute([':id_annee' => $anneeId]);
        $anneeLibelle = $stmtAnnee->fetchColumn() ?: $anneeLibelle;
    } catch (Exception $e) {
        $anneeLibelle = 'Toutes les années';
    }
}

$sections = [];
$errorMessage = null;

try {
    if ($globalMode) {
        $conditions = [];
        $params = [];

        if ($anneeId > 0) {
            $conditions[] = 'i.id_annee = :annee_id';
            $params[':annee_id'] = $anneeId;
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT
                    e.matricule,
                    e.nom_etu,
                    e.postnom_etu,
                    e.prenom_etu,
                    e.sexe,
                    COALESCE(p.nom_promotion, i.code_promotion, 'Non définie') AS nom_promotion,
                    COALESCE(i.code_promotion, 'Non définie') AS code_promotion,
                    COALESCE(m.libelle, 'Non définie') AS nom_mention,
                    COALESCE(f.nomFiliere, 'Non définie') AS nom_filiere,
                    COALESCE(d.nom_domaine, 'Non défini') AS nom_domaine
                FROM t_inscription i
                INNER JOIN t_etudiant e ON i.matricule = e.matricule
                LEFT JOIN t_promotion p ON i.code_promotion = p.code_promotion
                LEFT JOIN t_mention m ON i.id_mention = m.id_mention
                LEFT JOIN t_filiere f ON m.idFiliere = f.idFiliere
                LEFT JOIN t_domaine d ON f.id_domaine = d.id_domaine
                $whereClause
                ORDER BY d.nom_domaine ASC, f.nomFiliere ASC, m.libelle ASC, p.nom_promotion ASC, e.nom_etu ASC, e.postnom_etu ASC, e.prenom_etu ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $groupKey = implode('||', [
                $row['nom_domaine'] ?? 'Non défini',
                $row['nom_filiere'] ?? 'Non définie',
                $row['nom_mention'] ?? 'Non définie',
                $row['nom_promotion'] ?? ($row['code_promotion'] ?? 'Non définie')
            ]);

            if (!isset($sections[$groupKey])) {
                $sections[$groupKey] = [
                    'domaine' => $row['nom_domaine'] ?? 'Non défini',
                    'filiere' => $row['nom_filiere'] ?? 'Non définie',
                    'mention' => $row['nom_mention'] ?? 'Non définie',
                    'promotion' => $row['nom_promotion'] ?? ($row['code_promotion'] ?? 'Non définie'),
                    'rows' => [],
                    'hommes' => 0,
                    'femmes' => 0,
                ];
            }

            $sections[$groupKey]['rows'][] = $row;
            if (($row['sexe'] ?? '') === 'M') {
                $sections[$groupKey]['hommes']++;
            } elseif (($row['sexe'] ?? '') === 'F') {
                $sections[$groupKey]['femmes']++;
            }
        }

        if (empty($sections)) {
            $errorMessage = "Aucune inscription trouvée pour l'année académique sélectionnée.";
        }
    } else {
        $conditions = ['i.code_promotion = :promotion_code'];
        $params = [':promotion_code' => $promotionParam];

        if ($anneeId > 0) {
            $conditions[] = 'i.id_annee = :annee_id';
            $params[':annee_id'] = $anneeId;
        }

        if ($mentionId > 0) {
            $conditions[] = 'i.id_mention = :mention_id';
            $params[':mention_id'] = $mentionId;
        }

        if ($idDomaine > 0) {
            $conditions[] = 'd.id_domaine = :id_domaine';
            $params[':id_domaine'] = $idDomaine;
        }

        $sql = "SELECT
                    e.matricule,
                    e.nom_etu,
                    e.postnom_etu,
                    e.prenom_etu,
                    e.sexe,
                    COALESCE(p.nom_promotion, i.code_promotion, 'Non définie') AS nom_promotion,
                    COALESCE(i.code_promotion, 'Non définie') AS code_promotion,
                    COALESCE(m.libelle, 'Non définie') AS nom_mention,
                    COALESCE(f.nomFiliere, 'Non définie') AS nom_filiere,
                    COALESCE(d.nom_domaine, 'Non défini') AS nom_domaine
                FROM t_inscription i
                INNER JOIN t_etudiant e ON i.matricule = e.matricule
                LEFT JOIN t_promotion p ON i.code_promotion = p.code_promotion
                LEFT JOIN t_mention m ON i.id_mention = m.id_mention
                LEFT JOIN t_filiere f ON m.idFiliere = f.idFiliere
                LEFT JOIN t_domaine d ON f.id_domaine = d.id_domaine
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY e.nom_etu ASC, e.postnom_etu ASC, e.prenom_etu ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $hommes = 0;
            $femmes = 0;
            foreach ($rows as $row) {
                if (($row['sexe'] ?? '') === 'M') {
                    $hommes++;
                } elseif (($row['sexe'] ?? '') === 'F') {
                    $femmes++;
                }
            }

            $sections['single'] = [
                'domaine' => $rows[0]['nom_domaine'] ?? 'Non défini',
                'filiere' => $rows[0]['nom_filiere'] ?? 'Non définie',
                'mention' => $rows[0]['nom_mention'] ?? 'Non définie',
                'promotion' => $rows[0]['nom_promotion'] ?? $promotionParam,
                'rows' => $rows,
                'hommes' => $hommes,
                'femmes' => $femmes,
            ];
        } else {
            $errorMessage = "Aucun étudiant trouvé pour la promotion demandée.";
        }
    }
} catch (Exception $e) {
    $errorMessage = "Impossible de charger la liste des étudiants.";
}

$sectionCount = count($sections);
$printQuery = $_GET;
$printQuery['print'] = '1';
$printUrl = '?' . http_build_query($printQuery);
?>

<style>
    .student-print-wrapper {
        max-width: 1100px;
        margin: 1.5rem auto;
    }

    .print-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }

    .print-intro {
        background: #f8f9fa;
        border-left: 4px solid #0d6efd;
        padding: 12px 15px;
        margin-bottom: 1rem;
        border-radius: 8px;
    }

    .print-section {
        margin-bottom: 24px;
    }

    .print-sheet {
        background: #fff;
        color: #000;
        border-radius: 12px;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        padding: 20px 24px 30px;
    }

    .page-break {
        page-break-after: always;
        break-after: page;
    }

    .print-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        border-bottom: 2px solid #111;
        padding-bottom: 10px;
        margin-bottom: 14px;
    }

    .print-header .logo {
        width: 62px;
        height: 62px;
        object-fit: contain;
        flex-shrink: 0;
    }

    .print-header .title-block {
        flex: 1;
        text-align: center;
    }

    .print-header .title-block .school-name {
        font-family: 'Brush Script MT', 'Segoe Script', cursive;
        font-size: 2rem;
        line-height: 1.1;
        font-weight: 700;
        margin: 0;
    }

    .print-header .title-block .office-name {
        font-size: 1.25rem;
        font-weight: 800;
        margin: 2px 0;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .print-header .title-block .service-name {
        font-size: 1.05rem;
        font-weight: 700;
        margin: 0;
    }

    .meta-lines {
        margin: 16px 0 18px;
    }

    .meta-row {
        display: flex;
        align-items: flex-end;
        gap: 8px;
        margin-bottom: 6px;
        font-size: 15px;
    }

    .meta-label {
        min-width: 145px;
        font-weight: 600;
    }

    .meta-value {
        flex: 1;
        min-height: 24px;
        border-bottom: 1px dotted #333;
        padding: 0 4px 2px;
    }

    .meta-value small {
        color: #444;
        font-size: 12px;
    }

    .student-list-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 6px;
        font-size: 14px;
    }

    .student-list-table th,
    .student-list-table td {
        border: 1px solid #bdbdbd;
        padding: 8px 10px;
    }

    .student-list-table th {
        background: #000;
        color: #fff;
        text-align: center;
        font-weight: 700;
    }

    .student-list-table td {
        background: #f2f2f2;
        height: 34px;
    }

    .student-list-table td:nth-child(2),
    .student-list-table td:nth-child(3),
    .student-list-table td:nth-child(4) {
        background: #ebebeb;
    }

    .table-summary {
        margin-top: 10px;
        font-size: 13px;
        color: #222;
    }

    .signature-date {
        text-align: center;
        margin-top: 24px;
        font-size: 15px;
    }

    .signature-block {
        text-align: center;
        margin-top: 18px;
    }

    .signature-block .service {
        font-weight: 700;
        font-size: 15px;
        margin-bottom: 28px;
    }

    .signature-block .name {
        font-weight: 800;
        font-size: 16px;
        text-decoration: underline;
        margin-bottom: 4px;
    }

    .signature-block .role {
        font-size: 14px;
    }

    @media print {
        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        body {
            background: #fff !important;
        }

        .no-print,
        .navbar,
        .sidebar,
        header,
        footer {
            display: none !important;
        }

        .student-print-wrapper {
            max-width: none;
            margin: 0;
        }

        .print-sheet {
            box-shadow: none;
            border-radius: 0;
            padding: 0;
        }
    }
</style>

<div class="student-print-wrapper">
    <div class="print-actions no-print">
        <button type="button" class="btn btn-outline-secondary" onclick="history.back()">
            <i class="bi bi-arrow-left"></i> Retour
        </button>
        <button type="button" class="btn btn-primary" onclick="window.print()">
            <i class="bi bi-printer"></i> Imprimer maintenant
        </button>
        <?php if (!$printMode): ?>
            <a href="<?= printStudentListEsc($printUrl) ?>" target="_blank" class="btn btn-outline-primary">
                <i class="bi bi-box-arrow-up-right"></i> Ouvrir en mode impression
            </a>
        <?php endif; ?>
    </div>

    <div class="print-intro no-print">
        <strong>Impression générale :</strong>
        cette page regroupe automatiquement les étudiants par <strong>domaine</strong>, <strong>filière</strong>, <strong>mention</strong> et <strong>promotion</strong>.
        <br>
        Année académique : <strong><?= printStudentListEsc($anneeLibelle) ?></strong>
        — tableaux générés : <strong><?= (int) $sectionCount ?></strong>
    </div>

    <?php if ($errorMessage): ?>
        <div class="alert alert-warning no-print"><?= printStudentListEsc($errorMessage) ?></div>
    <?php endif; ?>

    <?php $sectionIndex = 0; ?>
    <?php foreach ($sections as $section): ?>
        <?php $sectionIndex++; ?>
        <?php $totalEtudiants = count($section['rows']); ?>
        <div class="print-section <?= $sectionIndex < $sectionCount ? 'page-break' : '' ?>">
            <div class="print-sheet">
                <div class="print-header">
                    <img src="<?= printStudentListEsc($logoPath) ?>" alt="Logo UNILO" class="logo">
                    <div class="title-block">
                        <p class="school-name">Université Notre Dame de Lomami</p>
                        <p class="office-name">Secrétariat General Académique</p>
                        <p class="service-name">Cellule Informatique et du Numérique</p>
                    </div>
                    <img src="<?= printStudentListEsc($logoPath) ?>" alt="Logo UNILO" class="logo">
                </div>

                <div class="meta-lines">
                    <div class="meta-row">
                        <span class="meta-label">Domaine :</span>
                        <span class="meta-value"><?= printStudentListEsc($section['domaine']) ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Filière/Mention :</span>
                        <span class="meta-value"><?= printStudentListEsc($section['filiere'] . ' / ' . $section['mention']) ?></span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">Promotion :</span>
                        <span class="meta-value">
                            <?= printStudentListEsc($section['promotion']) ?>
                            <small>— Année académique <?= printStudentListEsc($anneeLibelle) ?></small>
                        </span>
                    </div>
                </div>

                <table class="student-list-table">
                    <thead>
                        <tr>
                            <th style="width: 14%;">Matricule</th>
                            <th style="width: 22%;">Nom</th>
                            <th style="width: 25%;">Postnom</th>
                            <th style="width: 25%;">Prénom</th>
                            <th style="width: 14%;">Sexe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($section['rows'] as $etudiant): ?>
                            <tr>
                                <td><?= printStudentListEsc($etudiant['matricule'] ?? '') ?></td>
                                <td><?= printStudentListEsc($etudiant['nom_etu'] ?? '') ?></td>
                                <td><?= printStudentListEsc($etudiant['postnom_etu'] ?? '') ?></td>
                                <td><?= printStudentListEsc($etudiant['prenom_etu'] ?? '') ?></td>
                                <td style="text-align:center;"><?= printStudentListEsc($etudiant['sexe'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php for ($i = $totalEtudiants; $i < max(8, $totalEtudiants); $i++): ?>
                            <tr>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>

                <div class="table-summary">
                    Effectif total : <strong><?= (int) $totalEtudiants ?></strong>
                    &nbsp;|&nbsp; Masculin : <strong><?= (int) $section['hommes'] ?></strong>
                    &nbsp;|&nbsp; Féminin : <strong><?= (int) $section['femmes'] ?></strong>
                </div>

                <div class="signature-date">
                    Fait à Kabinda, le <?= date('d/m/Y') ?>
                </div>

                <div class="signature-block">
                    <div class="service">Pour la Cellule Informatique et du Numérique</div>
                    <div class="name">Ir. Jean Marie IBANGA MBAYO</div>
                    <div class="role">Ass. &amp; Développeur full-stack</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($printMode): ?>
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 300);
        });
    </script>
<?php endif; ?>
