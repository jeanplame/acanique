<?php

/**
 * SYSTÈME DE CONSULTATION DES RÉSULTATS - VERSION V4 STABLE
 * Simple, robuste, avec affichage correct des UE/EC
 */

require_once __DIR__ . '/../includes/db_config.php';

$conn = null;
$error_message = "";
$success_message = "";
$student_data = null;
$results_array = [];
$inscription_data = null;

// Connexion
try {
    $conn = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    $error_message = "Erreur de connexion à la base de données.";
    error_log("[CONSULTER_RESULTAT] Connection Error: " . $e->getMessage());
}

// Récupérer l'année académique courante
$annee_academique_courante = null;
$annee_academique_libelle = 'Non définie';
try {
    $stmt_annee = $conn->query("SELECT valeur FROM t_configuration WHERE cle = 'annee_encours'");
    $annee_academique_courante = $stmt_annee->fetchColumn();

    // Récupérer le libellé de l'année académique
    if ($annee_academique_courante) {
        $stmt_annee_details = $conn->prepare("SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?");
        $stmt_annee_details->execute([$annee_academique_courante]);
        $annee_details = $stmt_annee_details->fetch(PDO::FETCH_ASSOC);

        if ($annee_details) {
            $annee_academique_libelle = date('Y', strtotime($annee_details['date_debut'])) . '-' .
                date('Y', strtotime($annee_details['date_fin']));
        }
    }
} catch (Exception $e) {
    error_log("[CONSULTER_RESULTAT] Error fetching current academic year: " . $e->getMessage());
}

// Traitement de la recherche
if (isset($_POST['matricule']) && !empty($_POST['matricule']) && $conn) {
    $matricule = trim($_POST['matricule']);

    if (!preg_match('/^[A-Za-z0-9]+$/', $matricule)) {
        $error_message = "Matricule invalide.";
    } else {
        try {
            // 1. Récupérer l'étudiant
            $stmt = $conn->prepare("SELECT * FROM t_etudiant WHERE matricule = ?");
            $stmt->execute([$matricule]);
            $student_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($student_data) {
                // 2. Récupérer l'inscription pour l'année académique courante
                $domaine_libelle = 'Non défini';
                $filiere_libelle = 'Non définie';
                $mention_libelle = 'Non définie';
                $promotion_libelle = 'Non définie';

                if (!$annee_academique_courante) {
                    $error_message = "Aucune année académique active configurée.";
                } else {
                    // Inscription avec JOINs
                    $stmt_insc = $conn->prepare("
                        SELECT 
                            i.*,
                            COALESCE(f.nomFiliere, 'Non définie') as filiere_libelle,
                            COALESCE(d.nom_domaine, 'Non défini') as domaine_libelle,
                            COALESCE(m.libelle, 'Non définie') as mention_libelle,
                            COALESCE(p.nom_promotion, 'Non définie') as promotion_libelle
                        FROM t_inscription i
                        LEFT JOIN t_filiere f ON i.id_filiere = f.idFiliere
                        LEFT JOIN t_domaine d ON f.id_domaine = d.id_domaine
                        LEFT JOIN t_mention m ON i.id_mention = m.id_mention
                        LEFT JOIN t_promotion p ON i.code_promotion = p.code_promotion
                        WHERE i.matricule = ? AND i.statut = 'Actif' AND i.id_annee = ?
                        LIMIT 1
                    ");
                    $stmt_insc->execute([$matricule, $annee_academique_courante]);
                    $inscription_data = $stmt_insc->fetch(PDO::FETCH_ASSOC);

                    // Vérifier l'inscription
                    if (!$inscription_data) {
                        $error_message = "Cet étudiant n'est pas inscrit pour l'année académique courante (" . htmlspecialchars($annee_academique_libelle) . ").";
                    } else {
                        $domaine_libelle = $inscription_data['domaine_libelle'] ?? 'Non défini';
                        $filiere_libelle = $inscription_data['filiere_libelle'] ?? 'Non définie';
                        $mention_libelle = $inscription_data['mention_libelle'] ?? 'Non définie';
                        $promotion_libelle = $inscription_data['promotion_libelle'] ?? 'Non définie';

                        // 3. Récupérer TOUTES les notes (UE et EC) depuis la vue fiabilisée
                        $matching_view = false;
                        try {
                            $stmtViewExist = $conn->query("SHOW TABLES LIKE 'vue_grille_deliberation'");
                            if ($stmtViewExist->rowCount() > 0) {
                                $matching_view = true;
                            }
                        } catch (Exception $e) {
                            // pas fatal, on continue avec la requête t_cote
                        }

                        if ($matching_view) {
                            $query = "
                                SELECT 
                                    id_ue,
                                    code_ue,
                                    libelle_ue,
                                    credits,
                                    id_ec,
                                    code_ec,
                                    libelle_ec,
                                    coef_ec,
                                    cote_s1,
                                    cote_s2,
                                    cote_rattrapage_s1,
                                    cote_rattrapage_s2,
                                    meilleure_cote_s1,
                                    meilleure_cote_s2
                                FROM vue_grille_deliberation
                                WHERE matricule = ? AND id_annee = ?
                                ORDER BY code_ue ASC, code_ec ASC
                            ";
                            $stmt_res = $conn->prepare($query);
                            $stmt_res->execute([$matricule, $annee_academique_courante]);
                            $all_results = $stmt_res->fetchAll(PDO::FETCH_ASSOC);
                        } else {
                            // Fallback précédent si la vue n'existe pas
                            $query = "
                                SELECT 
                                    c.id_note, c.id_ue, c.id_ec, 
                                    c.cote_s1, c.cote_s2,
                                    ue.id_ue, ue.libelle as ue_libelle, ue.code_ue, ue.credits,
                                    ec.id_ec, ec.code_ec, ec.libelle as ec_libelle, ec.coefficient,
                                    COALESCE(ec.id_ue, c.id_ue) as group_by_ue
                                FROM t_cote c
                                LEFT JOIN t_unite_enseignement ue ON c.id_ue = ue.id_ue
                                LEFT JOIN t_element_constitutif ec ON c.id_ec = ec.id_ec
                                WHERE c.matricule = ? AND c.id_annee = ?
                                ORDER BY group_by_ue ASC, ec.id_ec ASC, c.id_note DESC
                            ";
                            $stmt_res = $conn->prepare($query);
                            $stmt_res->execute([$matricule, $annee_academique_courante]);
                            $all_results = $stmt_res->fetchAll(PDO::FETCH_ASSOC);
                        }

                        // Grouper COMPLÈTEMENT avant affichage : comme dans grille_deliberation
                        $ues_data = [];        // Structure UE contenant tous les ECs
                        $ues_order = [];       // Pour garder l'ordre des UEs
                        $seen_notes = [];      // Pour éviter les doublons

                        foreach ($all_results as $row) {
                            if ($matching_view) {
                                $code_ue = $row['code_ue'];
                                $code_ec = $row['code_ec'];
                                $ue_libelle = $row['libelle_ue'];
                                $ue_credits = (float)($row['credits'] ?? 0);
                                $ec_libelle = $row['libelle_ec'];

                                // Gestion des cotes/rattrapages en grille de délibération
                                $cote_s1 = $row['meilleure_cote_s1'] ?? $row['cote_s1'];
                                $cote_s2 = $row['meilleure_cote_s2'] ?? $row['cote_s2'];
                                $is_ec = !empty($code_ec) && $code_ec !== $code_ue;
                            } else {
                                $code_ue = $row['code_ue'];
                                $code_ec = $row['code_ec'] ?? null;
                                $ue_libelle = $row['ue_libelle'];
                                $ue_credits = (float)($row['credits'] ?? 0);
                                $ec_libelle = $row['ec_libelle'] ?? null;
                                $coef_ec = isset($row['coefficient']) ? (float)$row['coefficient'] : ($row['credits'] ?? 0);
                                $cote_s1 = $row['cote_s1'];
                                $cote_s2 = $row['cote_s2'];
                                $is_ec = !empty($row['id_ec']);
                            }

                            $note_key = $code_ue . '|' . ($is_ec ? $code_ec : 'UE');

                            if (isset($seen_notes[$note_key])) {
                                continue;
                            }
                            $seen_notes[$note_key] = true;

                            if (!isset($ues_data[$code_ue])) {
                                $ues_data[$code_ue] = [
                                    'code_ue' => $code_ue,
                                    'libelle' => $ue_libelle,
                                    'credits' => $ue_credits,
                                    'ecs' => []
                                ];
                                $ues_order[] = $code_ue;
                            }

                            if ($is_ec) {
                                if (!isset($ues_data[$code_ue]['ecs'][$code_ec])) {
                                    $ues_data[$code_ue]['ecs'][$code_ec] = [
                                        'code' => $code_ec,
                                        'libelle' => $ec_libelle,
                                        'coef' => ($matching_view ? (float)($row['coef_ec'] ?? 0) : $coef_ec),
                                        'cote_s1' => (float)$cote_s1,
                                        'cote_s2' => (float)$cote_s2,
                                        'type' => 'ec'
                                    ];
                                }
                            } else {
                                if (!isset($ues_data[$code_ue]['ecs']['UE'])) {
                                    $ues_data[$code_ue]['ecs']['UE'] = [
                                        'code' => $code_ue,
                                        'libelle' => $ue_libelle,
                                        'coef' => (float)$ue_credits,
                                        'cote_s1' => (float)$cote_s1,
                                        'cote_s2' => (float)$cote_s2,
                                        'type' => 'ue'
                                    ];
                                }
                            }
                        }

                        // Transformer en array pour affichage (garder la structure hiérarchique)
                        $results_array = [];
                        foreach ($ues_order as $code_ue) {
                            $ue_info = $ues_data[$code_ue];

                            // Détecter si l'UE a des ECs
                            $has_ec = false;
                            foreach ($ue_info['ecs'] as $item) {
                                if ($item['type'] === 'ec') {
                                    $has_ec = true;
                                    break;
                                }
                            }

                            if ($has_ec) {
                                // Ajouter ligne UE header (affiche crédits de l'UE)
                                $results_array[] = [
                                    'type' => 'ue_header',
                                    'code_ue' => $ue_info['code_ue'],
                                    'ue_libelle' => $ue_info['libelle'],
                                    'ue_credits' => $ue_info['credits'],
                                    'cote_s1' => null,
                                    'cote_s2' => null,
                                    'ec_libelle' => null,
                                    'code_ec' => null,
                                    'coef_ec' => null,
                                    'row_credits' => $ue_info['credits'],
                                    'group_by_ue' => $code_ue
                                ];

                                foreach ($ue_info['ecs'] as $item) {
                                    if ($item['type'] !== 'ec') {
                                        continue;
                                    }

                                    $results_array[] = [
                                        'type' => 'ec',
                                        'code_ue' => $ue_info['code_ue'],
                                        'ue_libelle' => $ue_info['libelle'],
                                        'ue_credits' => $ue_info['credits'],
                                        'cote_s1' => $item['cote_s1'],
                                        'cote_s2' => $item['cote_s2'],
                                        'ec_libelle' => $item['libelle'],
                                        'code_ec' => $item['code'],
                                        'coef_ec' => $item['coef'],
                                        'row_credits' => null,
                                        'group_by_ue' => $code_ue
                                    ];
                                }
                            } else {
                                // UE sans EC : afficher une seule ligne
                                $item = reset($ue_info['ecs']);
                                $results_array[] = [
                                    'type' => 'ue',
                                    'code_ue' => $ue_info['code_ue'],
                                    'ue_libelle' => $ue_info['libelle'],
                                    'ue_credits' => $ue_info['credits'],
                                    'cote_s1' => $item['cote_s1'],
                                    'cote_s2' => $item['cote_s2'],
                                    'ec_libelle' => null,
                                    'code_ec' => null,
                                    'coef_ec' => null,
                                    'row_credits' => $ue_info['credits'],
                                    'group_by_ue' => $code_ue
                                ];
                            }
                        }

                        // Pré-calcul des crédits totaux et crédit validés (inclut rattrapage via meilleures cotes)
                        $ue_validation = [];
                        foreach ($ues_order as $code_ue) {
                            $ue_info = $ues_data[$code_ue];
                            $credits = (float)($ue_info['credits'] ?? 0);
                            $valid = false;

                            foreach ($ue_info['ecs'] as $item) {
                                $cote_s1 = $item['cote_s1'];
                                $cote_s2 = $item['cote_s2'];
                                $note_meilleure = null;

                                if ($cote_s1 !== null || $cote_s2 !== null) {
                                    $note_meilleure = max($cote_s1 ?? -INF, $cote_s2 ?? -INF);
                                }

                                if ($note_meilleure !== null && $note_meilleure >= 10) {
                                    $valid = true;
                                    break;
                                }
                            }

                            $ue_validation[$code_ue] = [
                                'credits' => $credits,
                                'validated' => $valid
                            ];
                        }

                        $total_credits = 0;
                        $credits_valides = 0;
                        foreach ($ue_validation as $ue_status) {
                            $total_credits += $ue_status['credits'];
                            if ($ue_status['validated']) {
                                $credits_valides += $ue_status['credits'];
                            }
                        }
                    }
                }
            } else {
                $error_message = "Aucun étudiant trouvé avec ce matricule.";
            }
        } catch (Exception $e) {
            $error_message = "Erreur lors de la recherche : " . $e->getMessage();
            error_log("[CONSULTER_RESULTAT] Search error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes — UNILO</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            /* Palette moderne : Slate & Indigo */
            --bg-body: #f8fafc;
            --bg-surface: #ffffff;
            --bg-surface-hover: #f1f5f9;

            --text-main: #0f172a;
            --text-muted: #475569;
            --text-faint: #94a3b8;

            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --primary-light: #e0e7ff;

            --border-light: #e2e8f0;
            --border-mid: #cbd5e1;

            --danger: #e11d48;
            --danger-light: #ffe4e6;
            --success: #059669;
            --success-light: #d1fae5;
            --warning: #d97706;
            --warning-light: #fef3c7;

            --ue-bg: #f8fafc;

            --radius-sm: 6px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;

            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            background: linear-gradient(to bottom right, rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.6)), url('https://images.unsplash.com/photo-1522202176988-66273c2fd55f?ixlib=rb-4.0.3&auto=format&fit=crop&w=2000&q=80') center center / cover fixed no-repeat;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            font-size: 14px;
            color: var(--text-main);
            line-height: 1.5;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Layout ── */
        .page {
            max-width: 100%;
            /* Permet à la section hero de prendre toute la largeur */
            margin: 0 auto;
        }

        /* ── Hero Section (Contient Header + Search) ── */
        .hero-wrapper {
            /* background: linear-gradient(to bottom right, rgba(0, 0, 0, 0.8), rgba(0, 0, 0, 0.8)), */
            /* url('../img/IMG-20251020-WA0082.jpg') center/cover no-repeat; */
            padding: 2rem 1.5rem 5rem;
            margin-bottom: 2.5rem;
            box-shadow: var(--shadow-lg);
            color: #fff;
        }

        .hero-container {
            max-width: 960px;
            margin: 0 auto;
        }

        .search-card {
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.24);
            max-width: 760px;
            margin: 0 auto;
        }

        /* ── Header (à l'intérieur de la Hero) ── */
        .site-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 2rem;
        }

        .logo-mark {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), #818cf8);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 700;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: var(--shadow-sm);
        }

        .site-name {
            font-size: 14px;
            font-weight: 600;
            color: #cbd5e1;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        /* ── Search Area (dans la Hero) ── */
        .search-content {
            text-align: center;
        }

        .search-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: #ffffff;
            letter-spacing: -0.02em;
        }

        .search-sub {
            font-size: 1.125rem;
            color: #dbdbdb;
            margin-bottom: 3rem;
            max-width: 600px;
            margin-inline: auto;
        }

        .search-row {
            display: flex;
            gap: 12px;
            max-width: 640px;
            margin: 0 auto;
        }

        .search-input {
            flex: 1;
            height: 60px;
            padding: 0 24px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 16px;
            color: var(--text-main);
            background: #ffffff;
            outline: none;
            transition: var(--transition);
            box-shadow: var(--shadow-lg);
        }

        .search-input:focus {
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.4);
        }

        .btn-search {
            height: 60px;
            padding: 0 36px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        .btn-search:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
        }

        /* ── Result area ── */
        .results-container {
            max-width: 960px;
            margin: 0 auto;
            padding: 0 1.5rem 5rem;
        }

        /* ── Alerts ── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            border-radius: var(--radius-md);
            font-size: 14px;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-danger {
            background: var(--danger-light);
            color: #be123c;
        }

        .alert-info {
            background: var(--primary-light);
            color: #3730a3;
        }

        .alert-warn {
            background: var(--warning-light);
            color: #92400e;
        }

        /* ── Result card ── */
        .result-card {
            background: none !important;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .result-card+.result-card {
            margin-top: 2rem;
        }

        /* ── Student info ── */
        .student-band {
            padding: 2rem;
            border-bottom: 1px solid var(--border-light);
            background: none !important;
        }

        .student-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-main);
            letter-spacing: -0.02em;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem;
        }

        .info-item {

            display: flex;
            flex-direction: column;
            gap: 4px;

            background: #fff;
            opacity: 0.6;
        }

        .info-label {
            font-size: 12px;
            text-transform: uppercase;
            color: black;
            font-weight: 600;
            letter-spacing: 0.05em;
            opacity: 1;
        }

        .info-value {
            font-size: 15px;
            color: var(--text-main);
            font-weight: 500;
        }

        /* ── Table ── */
        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            /* background: url('https://images.unsplash.com/photo-1446776811953-b23d57bd21aa?ixlib=rb-4.0.3&auto=format&fit=crop&w=1600&q=80') center/cover no-repeat; */
            border-radius: var(--radius-md);
            padding: 1rem;
            background: none !important;
        }

        table.notes-table {
            border-collapse: collapse;
            width: 100%;
            font-size: 14px;
            text-align: left;
            background: rgba(255, 255, 255, 0.69);
            border-radius: var(--radius-md);
            overflow: hidden;
        }

        .notes-table th {
            background: var(--bg-surface-hover);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-muted);
            padding: 14px 20px;
            border-bottom: 2px solid var(--border-light);
            white-space: nowrap;
            letter-spacing: 0.05em;
        }

        .notes-table td {
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
        }

        /* UE header row */
        .tr-ue-header td {
            background: var(--ue-bg);
            font-weight: 600;
            color: var(--text-main);
            padding-top: 16px;
            padding-bottom: 16px;
        }

        /* EC row */
        .tr-ec td {
            color: var(--text-muted);
            transition: background 0.15s;
        }

        .tr-ec:hover td {
            background: var(--bg-body);
        }

        /* ======== RESULT CARD ======== */
        .result-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-mid);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            padding: 1rem;
            margin: 1rem 0;
        }

        .student-band {
            display: grid;
            gap: 0.8rem;
            background: var(--primary-light);
            border: 1px solid var(--primary);
            padding: 1rem 1.2rem;
            border-radius: var(--radius-md);
            margin-bottom: 0.9rem;
        }

        .student-name {
            font-size: 1.2rem;
            font-weight: 900;
            color: var(--primary);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 0.8rem;
        }

        .info-item {
            background: #fff;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 0.45rem 0.7rem;
        }

        .info-label {
            display: block;
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.2rem;
        }

        .info-value {
            font-size: 0.93rem;
            color: var(--text-main);
            font-weight: 600;
        }

        /* Fail row */
        .tr-fail td {
            background: var(--danger-light);
            color: #9f1239;
        }

        .code-cell {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 13px;
            color: var(--text-faint);
        }

        .ec-indent {
            padding-left: 32px !important;
        }

        .ec-label {
            font-size: 14px;
        }

        .num-cell {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .center-cell {
            text-align: center;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 9999px;
            /* Pill shape */
            font-size: 12px;
            font-weight: 600;
        }

        .badge-valide {
            background: var(--success-light);
            color: var(--success);
        }

        .badge-invalide {
            background: var(--danger-light);
            color: var(--danger);
        }

        /* ── Summary band ── */
        .summary-band {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            padding: 2rem;
            background: var(--bg-surface-hover);
        }

        .stat-pill {
            background: var(--bg-surface);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 16px;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .stat-pill .label {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--text-muted);
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .stat-pill .value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.02em;
        }

        .stat-pill .value.small {
            font-size: 16px;
            font-weight: 600;
        }

        .decision-pill {
            border-radius: var(--radius-md);
            padding: 16px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 6px;
        }

        .decision-pill.admis {
            background: var(--success-light);
            border: 1px solid #a7f3d0;
        }

        .decision-pill.ajourn {
            background: var(--danger-light);
            border: 1px solid #fecdd3;
        }

        .decision-pill .label {
            font-size: 12px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.05em;
        }

        .decision-pill.admis .label {
            color: #065f46;
        }

        .decision-pill.ajourn .label {
            color: #9f1239;
        }

        .decision-pill .value {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .decision-pill.admis .value {
            color: var(--success);
        }

        .decision-pill.ajourn .value {
            color: var(--danger);
        }

        /* ── Print ── */
        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .hero-wrapper {
                background: #f8fafc;
                color: #000;
                padding: 2rem;
            }

            .hero-container {
                max-width: 100%;
            }

            .search-title,
            .search-sub {
                color: #000;
            }

            .result-card {
                border: 1px solid #ddd;
                box-shadow: none;
            }

            .summary-band {
                background: #fff;
                border-top: 1px solid #ddd;
            }
        }

        /* ── Mobile ── */
        @media (max-width: 768px) {
            .hero-wrapper {
                padding: 1.5rem 1rem 3.5rem;
            }

            .site-header {
                margin-bottom: 2.5rem;
            }

            .search-title {
                font-size: 2rem;
            }

            .search-row {
                flex-direction: column;
            }

            .search-input,
            .btn-search {
                height: 56px;
                width: 100%;
                padding: 20px;
            }

            .results-container {
                padding: 0 1rem 3rem;
            }

            .summary-band {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="page">

        <!-- Hero -->
        <div class="hero-wrapper no-print">
            <div class="hero-container">
                <div class="hero-content">

                    <div class="search-card">
                        <p class="search-title" style="text-align: center;">Votre relevé de notes</p>
                        <p class="search-sub" style="text-align: center;">Entrez votre numéro de matricule pour accéder à vos résultats académiques.</p>
                        <form method="POST" class="search-row">
                            <input
                                type="text"
                                name="matricule"
                                class="search-input"
                                placeholder="Ex. : ETUXXXXXX"
                                required
                                value="<?php echo isset($_POST['matricule']) ? htmlspecialchars($_POST['matricule']) : ''; ?>">
                            <button type="submit" class="btn-search">
                                <i class="fas fa-search" style="font-size:13px;"></i>
                                Rechercher
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts -->
    </div> <!-- .hero-container -->
    </div> <!-- .hero-wrapper -->

    <?php if ($error_message): ?>
        <div class="alert alert-danger no-print">
            <i class="fas fa-exclamation-circle" style="margin-top:2px;font-size:14px;flex-shrink:0;"></i>
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <?php if ($success_message && !$error_message): ?>
        <div class="alert alert-info no-print">
            <i class="fas fa-check-circle" style="margin-top:2px;font-size:14px;flex-shrink:0;"></i>
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <!-- No notes warning -->
    <?php if ($student_data && empty($results_array) && !$error_message): ?>
        <div class="alert alert-warn">
            <i class="fas fa-exclamation-triangle" style="margin-top:2px;font-size:14px;flex-shrink:0;"></i>
            Cet étudiant n'a pas encore de notes enregistrées pour cette période.
        </div>
    <?php endif; ?>

    <!-- Main result card -->
    <?php if ($student_data && !empty($results_array)): ?>
        <?php
        $titre_civilite = ($student_data['sexe'] ?? '') === 'M' ? 'Monsieur' : 'Madame';
        $nom_complet = htmlspecialchars(trim(
            ($student_data['nom_etu'] ?? '') . ' ' .
                ($student_data['postnom_etu'] ?? '') . ' ' .
                ($student_data['prenom_etu'] ?? '')
        ));
        ?>
        <div class="result-card">

            <!-- Student identity -->
            <div class="student-band">
                <div class="student-name"><?php echo $nom_complet; ?></div>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Matricule</span>
                        <span class="info-value"><?php echo htmlspecialchars($student_data['matricule'] ?? ''); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Domaine</span>
                        <span class="info-value"><?php echo htmlspecialchars($domaine_libelle); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Filière</span>
                        <span class="info-value"><?php echo htmlspecialchars($filiere_libelle); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Mention</span>
                        <span class="info-value"><?php echo htmlspecialchars($mention_libelle); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Promotion</span>
                        <span class="info-value"><?php echo htmlspecialchars($promotion_libelle); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Année académique</span>
                        <span class="info-value"><?php echo htmlspecialchars($annee_academique_libelle); ?></span>
                    </div>
                </div>
            </div>

            <!-- Notes table -->
            <div class="table-wrap">
                <table class="notes-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Unité / Élément constitutif</th>
                            <th class="num-cell">Crédits</th>
                            <th class="num-cell">S1 /20</th>
                            <th class="num-cell">S2 /20</th>
                            <th class="num-cell">Meilleure</th>
                            <th class="center-cell">Décision</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total_note   = 0;
                        $note_count   = 0;

                        foreach ($results_array as $row):
                            $cote_s1 = array_key_exists('cote_s1', $row) && $row['cote_s1'] !== null ? (float)$row['cote_s1'] : null;
                            $cote_s2 = array_key_exists('cote_s2', $row) && $row['cote_s2'] !== null ? (float)$row['cote_s2'] : null;

                            if (array_key_exists('meilleure_cote_s1', $row) && $row['meilleure_cote_s1'] !== null) {
                                $cote_s1 = (float)$row['meilleure_cote_s1'];
                            }
                            if (array_key_exists('meilleure_cote_s2', $row) && $row['meilleure_cote_s2'] !== null) {
                                $cote_s2 = (float)$row['meilleure_cote_s2'];
                            }

                            $note_meilleure = ($cote_s1 === null && $cote_s2 === null)
                                ? null
                                : max($cote_s1 ?? -INF, $cote_s2 ?? -INF);

                            $row_credits  = (float)($row['row_credits'] ?? 0);
                            $coef_ec      = isset($row['coef_ec']) ? (float)$row['coef_ec'] : null;
                            $is_ec        = $row['type'] === 'ec';
                            $is_ue_header = $row['type'] === 'ue_header';

                            $code_label         = $is_ec ? ($row['code_ec'] ?? $row['code_ue'] ?? '—') : ($row['code_ue'] ?? '—');
                            $credit_coef_value  = $is_ec
                                ? ($coef_ec !== null ? number_format($coef_ec, 2) : '—')
                                : ($row_credits > 0 ? number_format($row_credits, 2) : '—');

                            $decision = ($note_meilleure !== null && $note_meilleure >= 10) ? 'Validé' : 'Non validé';

                            if ($note_meilleure !== null) {
                                $total_note += $note_meilleure;
                                $note_count++;
                            }

                            $tr_class = $is_ue_header ? 'tr-ue-header' : ($is_ec ? 'tr-ec' : '');
                            if (!$is_ue_header && $note_meilleure !== null && $note_meilleure < 10 && $note_meilleure > 0) {
                                $tr_class .= ' tr-fail';
                            }
                        ?>
                            <tr class="<?php echo trim($tr_class); ?>">
                                <td class="code-cell <?php echo $is_ec ? 'ec-indent' : ''; ?>">
                                    <?php echo htmlspecialchars($code_label); ?>
                                </td>
                                <td class="<?php echo $is_ec ? 'ec-indent ec-label' : ''; ?>">
                                    <?php if ($is_ue_header): ?>
                                        <?php echo htmlspecialchars($row['ue_libelle'] ?? ''); ?>
                                    <?php elseif ($is_ec): ?>
                                        <?php echo htmlspecialchars($row['ec_libelle'] ?? ''); ?>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($row['ue_libelle'] ?? ''); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="num-cell"><?php echo $credit_coef_value; ?></td>
                                <td class="num-cell">
                                    <?php echo (!$is_ue_header && $cote_s1 !== null) ? number_format($cote_s1, 2) : '—'; ?>
                                </td>
                                <td class="num-cell">
                                    <?php echo (!$is_ue_header && $cote_s2 !== null) ? number_format($cote_s2, 2) : '—'; ?>
                                </td>
                                <td class="num-cell" style="font-weight:600;">
                                    <?php echo (!$is_ue_header && $note_meilleure !== null) ? number_format($note_meilleure, 2) : '—'; ?>
                                </td>
                                <td class="center-cell">
                                    <?php if ($is_ue_header || $note_meilleure === null || $note_meilleure === 0.0): ?>
                                        <span style="color:var(--text-faint);">—</span>
                                    <?php elseif ($note_meilleure >= 10): ?>
                                        <span class="badge badge-valide">Validé</span>
                                    <?php else: ?>
                                        <span class="badge badge-invalide">Non validé</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Summary -->
            <?php
            $moyenne = $note_count > 0 ? $total_note / $note_count : 0;
            if ($moyenne >= 16)      $mention_calc = 'Distinction';
            elseif ($moyenne >= 14)  $mention_calc = 'Grande Satisfaction';
            elseif ($moyenne >= 12)  $mention_calc = 'Satisfaction';
            elseif ($moyenne >= 10)  $mention_calc = 'Passable';
            else                     $mention_calc = 'Non attribuée';

            $admis = $note_count > 0 && $moyenne >= 10;
            ?>
            <div class="summary-band">
                <div class="stat-pill">
                    <div class="label">Crédits totaux</div>
                    <div class="value"><?php echo $total_credits; ?></div>
                </div>
                <div class="stat-pill">
                    <div class="label">Crédits validés</div>
                    <div class="value"><?php echo $credits_valides; ?></div>
                </div>
                <div class="stat-pill">
                    <div class="label">Moyenne annuelle</div>
                    <div class="value"><?php echo $note_count > 0 ? number_format($moyenne, 2) : '—'; ?> <span style="font-size:13px;color:var(--text-muted);">/20</span></div>
                </div>
                <div class="stat-pill">
                    <div class="label">Mention</div>
                    <div class="value small"><?php echo $mention_calc; ?></div>
                </div>
                <div class="decision-pill <?php echo $admis ? 'admis' : 'ajourn'; ?>">
                    <div class="label">Décision</div>
                    <div class="value"><?php echo $admis ? 'Admis(e)' : 'Ajourné(e)'; ?></div>
                </div>
            </div>

        </div><!-- /.result-card -->
    <?php endif; ?>

    </div><!-- /.page -->
</body>

</html>