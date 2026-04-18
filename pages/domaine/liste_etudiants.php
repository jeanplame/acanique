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
$filiereId = isset($_GET['filiere']) ? (int) $_GET['filiere'] : 0;
$anneeId = isset($_GET['annee']) ? (int) $_GET['annee'] : 0;
$idDomaine = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$searchKeyword = trim((string) ($_GET['q'] ?? ''));
$sexeFilter = strtoupper(trim((string) ($_GET['sexe'] ?? '')));
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
$domainesOptions = [];
$filiereOptions = [];
$mentionOptions = [];
$promotionOptions = [];

try {
    $stmtDomaines = $pdo->query("SELECT id_domaine, nom_domaine FROM t_domaine ORDER BY nom_domaine ASC");
    $domainesOptions = $stmtDomaines->fetchAll(PDO::FETCH_ASSOC);

    if ($idDomaine > 0) {
        $stmtFilieres = $pdo->prepare("SELECT idFiliere, nomFiliere FROM t_filiere WHERE id_domaine = :id_domaine ORDER BY nomFiliere ASC");
        $stmtFilieres->execute([':id_domaine' => $idDomaine]);
    } else {
        $stmtFilieres = $pdo->query("SELECT idFiliere, nomFiliere FROM t_filiere ORDER BY nomFiliere ASC");
    }
    $filiereOptions = $stmtFilieres->fetchAll(PDO::FETCH_ASSOC);

    if ($filiereId > 0) {
        $stmtMentions = $pdo->prepare("SELECT id_mention, libelle FROM t_mention WHERE idFiliere = :id_filiere ORDER BY libelle ASC");
        $stmtMentions->execute([':id_filiere' => $filiereId]);
    } elseif ($idDomaine > 0) {
        $stmtMentions = $pdo->prepare(
            "SELECT m.id_mention, m.libelle
             FROM t_mention m
             INNER JOIN t_filiere f ON m.idFiliere = f.idFiliere
             WHERE f.id_domaine = :id_domaine
             ORDER BY m.libelle ASC"
        );
        $stmtMentions->execute([':id_domaine' => $idDomaine]);
    } else {
        $stmtMentions = $pdo->query("SELECT id_mention, libelle FROM t_mention ORDER BY libelle ASC");
    }
    $mentionOptions = $stmtMentions->fetchAll(PDO::FETCH_ASSOC);

    $stmtPromotions = $pdo->query("SELECT code_promotion, nom_promotion FROM t_promotion ORDER BY nom_promotion ASC, code_promotion ASC");
    $promotionOptions = $stmtPromotions->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $domainesOptions = [];
    $filiereOptions = [];
    $mentionOptions = [];
    $promotionOptions = [];
}

try {
    if ($globalMode) {
        $conditions = [];
        $params = [];

        if ($anneeId > 0) {
            $conditions[] = 'i.id_annee = :annee_id';
            $params[':annee_id'] = $anneeId;
        }

        if ($idDomaine > 0) {
            $conditions[] = 'd.id_domaine = :id_domaine';
            $params[':id_domaine'] = $idDomaine;
        }

        if ($filiereId > 0) {
            $conditions[] = 'f.idFiliere = :id_filiere';
            $params[':id_filiere'] = $filiereId;
        }

        if ($mentionId > 0) {
            $conditions[] = 'i.id_mention = :mention_id';
            $params[':mention_id'] = $mentionId;
        }

        if ($sexeFilter === 'M' || $sexeFilter === 'F') {
            $conditions[] = 'e.sexe = :sexe';
            $params[':sexe'] = $sexeFilter;
        }

        if ($searchKeyword !== '') {
            $conditions[] = '(e.matricule LIKE :keyword OR e.nom_etu LIKE :keyword OR e.postnom_etu LIKE :keyword OR e.prenom_etu LIKE :keyword)';
            $params[':keyword'] = '%' . $searchKeyword . '%';
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

        if ($filiereId > 0) {
            $conditions[] = 'f.idFiliere = :id_filiere';
            $params[':id_filiere'] = $filiereId;
        }

        if ($idDomaine > 0) {
            $conditions[] = 'd.id_domaine = :id_domaine';
            $params[':id_domaine'] = $idDomaine;
        }

        if ($sexeFilter === 'M' || $sexeFilter === 'F') {
            $conditions[] = 'e.sexe = :sexe';
            $params[':sexe'] = $sexeFilter;
        }

        if ($searchKeyword !== '') {
            $conditions[] = '(e.matricule LIKE :keyword OR e.nom_etu LIKE :keyword OR e.postnom_etu LIKE :keyword OR e.prenom_etu LIKE :keyword)';
            $params[':keyword'] = '%' . $searchKeyword . '%';
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
    @font-face {
        font-family: 'Alisandra Demo';
        src: local('Alisandra Demo'), local('AlisandraDemo');
        font-weight: normal;
        font-style: normal;
        color: black;
    }

    .student-print-wrapper {
        max-width: 1100px;
        margin: 1.5rem auto;
        font-family: 'Tw Cen MT', Arial, sans-serif;
    }

    .filter-panel {
        background: #ffffff;
        border: 1px solid #dfe3ea;
        border-radius: 10px;
        padding: 18px 18px 14px;
        margin-bottom: 1rem;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
        transition: box-shadow 0.3s;
    }

    .filter-panel:focus-within {
        box-shadow: 0 4px 20px rgba(13, 110, 253, 0.12);
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 10px;
        align-items: end;
    }

    .filter-panel label {
        font-size: 12px;
        font-weight: 700;
        margin-bottom: 4px;
        display: flex;
        align-items: center;
        gap: 5px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        color: #555;
    }

    .filter-panel label .filter-icon {
        font-size: 13px;
        color: #0d6efd;
    }

    .filter-panel .form-control,
    .filter-panel .form-select {
        min-height: 40px;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .filter-panel .form-select.is-loading {
        background-image: none;
        color: #999;
        pointer-events: none;
    }

    .filter-panel .form-select.is-loading::after {
        content: '';
    }

    .select-wrapper {
        position: relative;
    }

    .select-wrapper .spinner-border {
        position: absolute;
        right: 30px;
        top: 50%;
        transform: translateY(-50%);
        width: 16px;
        height: 16px;
        border-width: 2px;
        display: none;
        color: #0d6efd;
    }

    .select-wrapper.loading .spinner-border {
        display: inline-block;
    }

    .select-wrapper.loading .form-select {
        color: #999;
        pointer-events: none;
    }

    .filter-badge-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
        flex-wrap: wrap;
        gap: 8px;
    }

    .filter-badge-row .filter-title {
        font-weight: 700;
        font-size: 15px;
        color: #333;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    #results-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: linear-gradient(135deg, #0d6efd, #0b5ed7);
        color: #fff;
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        transition: transform 0.2s, opacity 0.2s;
        min-width: 160px;
        justify-content: center;
    }

    #results-badge.pulse {
        animation: badgePulse 0.6s ease;
    }

    @keyframes badgePulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.06); }
    }

    .active-filters-row {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 8px;
    }

    .active-filter-tag {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: #e7f1ff;
        color: #0d6efd;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.15s;
    }

    .active-filter-tag:hover {
        background: #cfe2ff;
    }

    .active-filter-tag .remove-tag {
        font-size: 14px;
        font-weight: 700;
        line-height: 1;
        opacity: 0.6;
    }

    .active-filter-tag .remove-tag:hover {
        opacity: 1;
    }

    #results-container {
        transition: opacity 0.3s ease;
    }

    #results-container.is-loading {
        opacity: 0.35;
        pointer-events: none;
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
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border-bottom: 2px solid #111;
        padding-bottom: 6px;
        margin-bottom: 8px;
    }

    .print-header .logo {
        width: 58px;
        height: 58px;
        object-fit: contain;
        flex-shrink: 0;
    }

    .print-header .title-block {
        flex: 1;
        text-align: center;
        line-height: 1;
    }

    .print-header .title-block p {
        margin: 0;
        line-height: 1;
        color: black;
    }

    .print-header .title-block .school-name {
        font-family: 'Alisandra Demo', 'Brush Script MT', 'Segoe Script', cursive;
        font-size: 2rem;
        line-height: 0.95;
        font-weight: 400;
        margin: 0;
    }

    .print-header .title-block .office-name {
        font-size: 1.2rem;
        font-weight: 800;
        margin: 0;
        text-transform: uppercase;
        letter-spacing: 0.2px;
    }

    .print-header .title-block .service-name {
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
    }

    .meta-lines {
        margin: 10px 0 12px;
    }

    .meta-row {
        display: flex;
        align-items: flex-end;
        gap: 6px;
        margin-bottom: 2px;
        font-size: 15px;
        line-height: 1;
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
        background-color: white !important;
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
        background: #ffffff;
        height: 34px;
    }

    .student-list-table td:nth-child(2),
    .student-list-table td:nth-child(3),
    .student-list-table td:nth-child(4) {
        background: #ffffff;
    }

    .table-summary {
        margin-top: 8px;
        font-size: 13px;
        color: #222;
        line-height: 1;
    }

    .signature-date {
        text-align: center;
        margin-top: 16px;
        font-size: 15px;
        line-height: 1;
    }

    .signature-block {
        text-align: center;
        margin-top: 12px;
        line-height: 1;
    }

    .signature-block .service {
        font-weight: 700;
        font-size: 15px;
        margin-bottom: 14px;
        line-height: 1;
    }

    .signature-block .name {
        font-weight: 800;
        font-size: 16px;
        text-decoration: underline;
        margin-bottom: 2px;
        line-height: 1;
    }

    .signature-block .role {
        font-size: 14px;
        line-height: 1;
    }

    @media print {
        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        html,
        body {
            background: #fff !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        body * {
            visibility: hidden;
        }

        .student-print-wrapper,
        .student-print-wrapper * {
            visibility: visible;
        }

        .student-print-wrapper {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            max-width: none;
            margin: 0;
        }

        .no-print,
        .navbar,
        .sidebar,
        header,
        footer,
        #ai-chat-fab,
        #ai-chat-window,
        [id^='ai-chat'],
        [class*='ai-chat'],
        [class*='jadbot'] {
            display: none !important;
            visibility: hidden !important;
        }

        .print-sheet {
            box-shadow: none;
            border-radius: 0;
            padding: 0;
        }
    }
</style>

<div class="student-print-wrapper">
    <form method="get" id="filter-form" class="filter-panel no-print"
          data-ajax-url="<?= printStudentListEsc($baseUrl) ?>/ajax/liste_etudiants_filters.php">
        <input type="hidden" name="page" value="domaine">
        <input type="hidden" name="action" value="liste_etudiants">
        <?php if ($anneeId > 0): ?>
            <input type="hidden" name="annee" value="<?= (int) $anneeId ?>">
        <?php endif; ?>

        <div class="filter-badge-row">
            <span class="filter-title">
                <i class="bi bi-funnel-fill" style="color:#0d6efd"></i> Filtres intelligents
            </span>
            <span id="results-badge">
                <i class="bi bi-people-fill"></i>
                <span id="badge-text">Chargement...</span>
            </span>
        </div>

        <div class="filter-grid">
            <div>
                <label for="q"><i class="bi bi-search filter-icon"></i> Recherche</label>
                <input type="text" id="q" name="q" class="form-control"
                       placeholder="Nom, postnom ou matricule..."
                       value="<?= printStudentListEsc($searchKeyword) ?>"
                       autocomplete="off">
            </div>
            <div>
                <label for="filter-domaine"><i class="bi bi-diagram-3 filter-icon"></i> Domaine</label>
                <div class="select-wrapper" id="wrap-domaine">
                    <select id="filter-domaine" name="id" class="form-select">
                        <option value="">Tous les domaines</option>
                        <?php foreach ($domainesOptions as $domaineOption): ?>
                            <option value="<?= (int) $domaineOption['id_domaine'] ?>" <?= $idDomaine === (int) $domaineOption['id_domaine'] ? 'selected' : '' ?>>
                                <?= printStudentListEsc($domaineOption['nom_domaine']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="spinner-border" role="status"></div>
                </div>
            </div>
            <div>
                <label for="filter-filiere"><i class="bi bi-collection filter-icon"></i> Filière</label>
                <div class="select-wrapper" id="wrap-filiere">
                    <select id="filter-filiere" name="filiere" class="form-select">
                        <option value="">Toutes les filières</option>
                        <?php foreach ($filiereOptions as $filiereOption): ?>
                            <option value="<?= (int) $filiereOption['idFiliere'] ?>" <?= $filiereId === (int) $filiereOption['idFiliere'] ? 'selected' : '' ?>>
                                <?= printStudentListEsc($filiereOption['nomFiliere']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="spinner-border" role="status"></div>
                </div>
            </div>
            <div>
                <label for="filter-mention"><i class="bi bi-bookmark filter-icon"></i> Mention</label>
                <div class="select-wrapper" id="wrap-mention">
                    <select id="filter-mention" name="mention" class="form-select">
                        <option value="">Toutes les mentions</option>
                        <?php foreach ($mentionOptions as $mentionOption): ?>
                            <option value="<?= (int) $mentionOption['id_mention'] ?>" <?= $mentionId === (int) $mentionOption['id_mention'] ? 'selected' : '' ?>>
                                <?= printStudentListEsc($mentionOption['libelle']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="spinner-border" role="status"></div>
                </div>
            </div>
            <div>
                <label for="filter-promotion"><i class="bi bi-mortarboard filter-icon"></i> Promotion</label>
                <div class="select-wrapper" id="wrap-promotion">
                    <select id="filter-promotion" name="promotion" class="form-select">
                        <option value="">Toutes les promotions</option>
                        <?php foreach ($promotionOptions as $promotionOption): ?>
                            <?php $promotionValue = (string) ($promotionOption['code_promotion'] ?? ''); ?>
                            <option value="<?= printStudentListEsc($promotionValue) ?>" <?= $promotionParam === $promotionValue ? 'selected' : '' ?>>
                                <?= printStudentListEsc(($promotionOption['nom_promotion'] ?? $promotionValue) . ' (' . $promotionValue . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="spinner-border" role="status"></div>
                </div>
            </div>
            <div>
                <label for="filter-sexe"><i class="bi bi-gender-ambiguous filter-icon"></i> Sexe</label>
                <select id="filter-sexe" name="sexe" class="form-select">
                    <option value="">Tous</option>
                    <option value="M" <?= $sexeFilter === 'M' ? 'selected' : '' ?>>Masculin</option>
                    <option value="F" <?= $sexeFilter === 'F' ? 'selected' : '' ?>>Féminin</option>
                </select>
            </div>
            <div class="d-flex gap-2 flex-wrap align-items-end">
                <button type="submit" class="btn btn-primary" id="btn-filter">
                    <i class="bi bi-funnel"></i> Appliquer
                </button>
                <a href="?page=domaine&action=liste_etudiants<?= $anneeId > 0 ? '&annee=' . (int) $anneeId : '' ?>" class="btn btn-outline-secondary" id="btn-reset">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </div>

        <div class="active-filters-row" id="active-filters"></div>
    </form>

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

    <div id="results-container">
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
                        <p class="service-name">Cellule Informatique et Numérique</p>
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
                    <div class="service">CELLULE INFORMATIQUE ET NUMERIQUE</div>
                    <br>
                    <div class="name">Ir. Jean Marie IBANGA MBAYO</div>
                    <div class="role">Ass. &amp; Développeur full-stack</div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div><!-- /#results-container -->
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form         = document.getElementById('filter-form');
    const domaineSel   = document.getElementById('filter-domaine');
    const filiereSel   = document.getElementById('filter-filiere');
    const mentionSel   = document.getElementById('filter-mention');
    const promotionSel = document.getElementById('filter-promotion');
    const sexeSel      = document.getElementById('filter-sexe');
    const searchInput  = document.getElementById('q');
    const badgeText    = document.getElementById('badge-text');
    const badge        = document.getElementById('results-badge');
    const activeRow    = document.getElementById('active-filters');
    const resultsBox   = document.getElementById('results-container');
    const ajaxBase     = form.dataset.ajaxUrl;
    const anneeVal     = form.querySelector('input[name="annee"]')?.value || '';

    let submitTimer = null;
    let countTimer  = null;
    let countAbort  = null;

    /* ── helpers ───────────────────────────────────────── */
    async function fetchJson(url, signal) {
        const r = await fetch(url, { signal: signal || undefined });
        if (!r.ok) throw new Error(r.status);
        return r.json();
    }

    function ajaxUrl(action, extra) {
        let url = ajaxBase + '?action=' + encodeURIComponent(action);
        if (anneeVal) url += '&annee=' + encodeURIComponent(anneeVal);
        if (extra) {
            Object.entries(extra).forEach(function(pair) {
                if (pair[1]) url += '&' + encodeURIComponent(pair[0]) + '=' + encodeURIComponent(pair[1]);
            });
        }
        return url;
    }

    function setWrapLoading(id, on) {
        var w = document.getElementById(id);
        if (w) w.classList.toggle('loading', !!on);
    }

    function populateSelect(sel, data, placeholder, keepValue) {
        var cur = keepValue || '';
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        data.forEach(function(item) {
            var o = document.createElement('option');
            o.value = item.id;
            o.textContent = item.label + (sel === promotionSel ? ' (' + item.id + ')' : '');
            if (String(item.id) === String(cur)) o.selected = true;
            sel.appendChild(o);
        });
    }

    /* ── cascade loaders ───────────────────────────────── */
    async function loadFilieres(domaineId, keep) {
        setWrapLoading('wrap-filiere', true);
        try {
            var data = await fetchJson(ajaxUrl('filieres', { domaine: domaineId }));
            populateSelect(filiereSel, data, 'Toutes les filières', keep);
        } catch(e) {}
        setWrapLoading('wrap-filiere', false);
    }

    async function loadMentions(filiereId, domaineId, keep) {
        setWrapLoading('wrap-mention', true);
        try {
            var data = await fetchJson(ajaxUrl('mentions', { filiere: filiereId, domaine: domaineId }));
            populateSelect(mentionSel, data, 'Toutes les mentions', keep);
        } catch(e) {}
        setWrapLoading('wrap-mention', false);
    }

    async function loadPromotions(mentionId, filiereId, domaineId, keep) {
        setWrapLoading('wrap-promotion', true);
        try {
            var data = await fetchJson(ajaxUrl('promotions', { mention: mentionId, filiere: filiereId, domaine: domaineId }));
            populateSelect(promotionSel, data, 'Toutes les promotions', keep);
        } catch(e) {}
        setWrapLoading('wrap-promotion', false);
    }

    /* ── live count badge ──────────────────────────────── */
    function scheduleCount() {
        clearTimeout(countTimer);
        if (countAbort) countAbort.abort();
        countTimer = setTimeout(updateCount, 250);
    }

    async function updateCount() {
        var ctrl = new AbortController();
        countAbort = ctrl;
        try {
            var data = await fetchJson(ajaxUrl('count', {
                domaine:   domaineSel.value,
                filiere:   filiereSel.value,
                mention:   mentionSel.value,
                promotion: promotionSel.value,
                sexe:      sexeSel.value,
                q:         searchInput.value.trim()
            }), ctrl.signal);
            badgeText.textContent = data.total + ' étudiant(s) · ' + data.sections + ' section(s) · ' + data.hommes + 'H / ' + data.femmes + 'F';
            badge.classList.add('pulse');
            setTimeout(function() { badge.classList.remove('pulse'); }, 600);
        } catch(e) {
            if (e.name !== 'AbortError') badgeText.textContent = '—';
        }
    }

    /* ── active filter tags ────────────────────────────── */
    function refreshTags() {
        activeRow.innerHTML = '';
        var filters = [
            { sel: domaineSel,   param: 'id',        label: 'Domaine' },
            { sel: filiereSel,   param: 'filiere',   label: 'Filière' },
            { sel: mentionSel,   param: 'mention',   label: 'Mention' },
            { sel: promotionSel, param: 'promotion', label: 'Promotion' },
            { sel: sexeSel,      param: 'sexe',      label: 'Sexe' }
        ];
        filters.forEach(function(f) {
            if (f.sel.value) {
                var text = f.sel.options[f.sel.selectedIndex]?.textContent?.trim() || f.sel.value;
                var tag = document.createElement('span');
                tag.className = 'active-filter-tag';
                tag.innerHTML = '<strong>' + f.label + ':</strong> ' + escHtml(text) + ' <span class="remove-tag">&times;</span>';
                tag.querySelector('.remove-tag').addEventListener('click', function() {
                    f.sel.value = '';
                    f.sel.dispatchEvent(new Event('change'));
                });
                activeRow.appendChild(tag);
            }
        });
        if (searchInput.value.trim()) {
            var tag = document.createElement('span');
            tag.className = 'active-filter-tag';
            tag.innerHTML = '<strong>Recherche:</strong> ' + escHtml(searchInput.value.trim()) + ' <span class="remove-tag">&times;</span>';
            tag.querySelector('.remove-tag').addEventListener('click', function() {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
            });
            activeRow.appendChild(tag);
        }
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    /* ── auto-submit with results loading ──────────────── */
    function scheduleSubmit(delay) {
        clearTimeout(submitTimer);
        submitTimer = setTimeout(function() {
            if (resultsBox) resultsBox.classList.add('is-loading');
            form.submit();
        }, delay || 700);
    }

    /* ── event: domaine changes ────────────────────────── */
    domaineSel.addEventListener('change', async function() {
        filiereSel.value   = '';
        mentionSel.value   = '';
        promotionSel.value = '';
        await Promise.all([
            loadFilieres(this.value),
            loadMentions('', this.value),
            loadPromotions('', '', this.value)
        ]);
        scheduleCount();
        refreshTags();
        scheduleSubmit();
    });

    /* ── event: filière changes ────────────────────────── */
    filiereSel.addEventListener('change', async function() {
        mentionSel.value   = '';
        promotionSel.value = '';
        await Promise.all([
            loadMentions(this.value, domaineSel.value),
            loadPromotions('', this.value, domaineSel.value)
        ]);
        scheduleCount();
        refreshTags();
        scheduleSubmit();
    });

    /* ── event: mention changes ────────────────────────── */
    mentionSel.addEventListener('change', async function() {
        promotionSel.value = '';
        await loadPromotions(this.value, filiereSel.value, domaineSel.value);
        scheduleCount();
        refreshTags();
        scheduleSubmit();
    });

    /* ── event: promotion / sexe changes ───────────────── */
    promotionSel.addEventListener('change', function() {
        scheduleCount();
        refreshTags();
        scheduleSubmit();
    });

    sexeSel.addEventListener('change', function() {
        scheduleCount();
        refreshTags();
        scheduleSubmit();
    });

    /* ── event: live search (debounced) ────────────────── */
    searchInput.addEventListener('input', function() {
        scheduleCount();
        refreshTags();
        clearTimeout(submitTimer);
        submitTimer = setTimeout(function() {
            if (resultsBox) resultsBox.classList.add('is-loading');
            form.submit();
        }, 1000);
    });

    /* ── prevent double-submit on Enter ────────────────── */
    form.addEventListener('submit', function() {
        clearTimeout(submitTimer);
        if (resultsBox) resultsBox.classList.add('is-loading');
    });

    /* ── initial state ────────────────────────────────── */
    updateCount();
    refreshTags();
});
</script>

<?php if ($printMode): ?>
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 300);
        });
    </script>
<?php endif; ?>