<?php

header('Location: consulter_resultat.php', true, 302);
exit;

/**
 * SYSTÈME DE CONSULTATION DES RÉSULTATS - VERSION STABLE V3
 * Simple, robuste, fonctionnelle
 */

$dbConfigCandidates = [
    __DIR__ . '/../_sync/db_config_sync.php',
    __DIR__ . '/../includes/db_config.php',
    __DIR__ . '/includes/db_config.php',
    __DIR__ . '/_sync/db_config_sync.php',
];

$dbConfigLoaded = false;
foreach ($dbConfigCandidates as $dbConfigFile) {
    if (file_exists($dbConfigFile)) {
        require_once $dbConfigFile;
        $dbConfigLoaded = true;
        break;
    }
}

if (!$dbConfigLoaded) {
    die('Configuration base de donnees introuvable.');
}

$conn = null;
$error_message = "";
$success_message = "";
$student_data = null;
$results = [];
$inscription_data = null;
$publication_active = true;
$publication_message = "La consultation des resultats est temporairement suspendue. Veuillez reessayer plus tard.";

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

// Etat de publication publique des resultats
try {
    if ($conn) {
        $stmt_publication = $conn->prepare("SELECT cle, valeur FROM t_configuration WHERE cle IN ('resultats_publication_active', 'resultats_publication_message')");
        $stmt_publication->execute();
        $publication_config = $stmt_publication->fetchAll(PDO::FETCH_KEY_PAIR);

        if (isset($publication_config['resultats_publication_active'])) {
            $publication_active = $publication_config['resultats_publication_active'] === '1';
        }

        if (!empty($publication_config['resultats_publication_message'])) {
            $publication_message = $publication_config['resultats_publication_message'];
        }
    }
} catch (Exception $e) {
    error_log("[CONSULTER_RESULTAT] Error fetching publication status: " . $e->getMessage());
}

// Traitement de la recherche
if (!$publication_active && isset($_POST['matricule'])) {
    $error_message = $publication_message;
} elseif ($publication_active && isset($_POST['matricule']) && !empty($_POST['matricule']) && $conn) {
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
                $domaine_id = null;
                $filiere_id = null;
                $code_promotion = null;
                $domaine_libelle = 'Non défini';
                $filiere_libelle = 'Non définie';
                $mention_libelle = 'Non définie';
                $promotion_libelle = 'Non définie';
                
                try {
                    if (!$annee_academique_courante) {
                        $error_message = "Aucune année académique active configurée.";
                    } else {
                        try {
                            // Récupérer l'inscription avec tous les JOINs nécessaires
                            // Matricule → t_inscription → t_filiere → t_domaine
                            //                            → t_mention
                            //                            → t_promotion
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
                            
                            // Vérifier que l'étudiant est inscrit à l'année académique courante
                            if (!$inscription_data) {
                                $error_message = "Cet étudiant n'est pas inscrit pour l'année académique courante (" . htmlspecialchars($annee_academique_libelle) . ").";
                                $results = []; // Vider les résultats
                            } else {
                                $domaine_id = $inscription_data['id_domaine'] ?? null;
                                $filiere_id = $inscription_data['id_filiere'] ?? null;
                                $code_promotion = $inscription_data['code_promotion'] ?? null;
                                
                                // Récupérer les libellés depuis les données JOIN
                                $domaine_libelle = $inscription_data['domaine_libelle'] ?? 'Non défini';
                                $filiere_libelle = $inscription_data['filiere_libelle'] ?? 'Non définie';
                                $mention_libelle = $inscription_data['mention_libelle'] ?? 'Non définie';
                                $promotion_libelle = $inscription_data['promotion_libelle'] ?? 'Non définie';
                            }
                        } catch (Exception $e) {
                            $error_message = "Erreur SQL lors de la récupération de l'inscription : " . $e->getMessage();
                            error_log("[CONSULTER_RESULTAT] Inscription SQL error: " . $e->getMessage());
                            $results = [];
                        }
                    }
                } catch (Exception $e) {
                    $error_message = "Erreur lors de la vérification de l'inscription : " . $e->getMessage();
                    error_log("[CONSULTER_RESULTAT] Inscription verification error: " . $e->getMessage());
                    $results = [];
                }

                // 3. Récupérer les notes avec l'UE parente des ECs
                try {
                    // Requête améliorée qui récupère l'UE parente quand on a un EC
                    $query = "
                        SELECT 
                            c.id_note,
                            c.id_ue,
                            c.id_ec,
                            c.cote_s1,
                            c.cote_s2,
                            c.cote_rattrapage_s1,
                            c.cote_rattrapage_s2,
                            COALESCE(ue.libelle, parent_ue.libelle) as ue_libelle,
                            COALESCE(ue.code_ue, parent_ue.code_ue) as code_ue,
                            COALESCE(ue.credits, parent_ue.credits) as ue_credits,
                            ec.libelle as ec_libelle,
                            COALESCE(c.id_ue, ec.id_ue) as effective_ue_id
                        FROM t_cote c
                        LEFT JOIN t_unite_enseignement ue ON c.id_ue = ue.id_ue
                        LEFT JOIN t_element_constitutif ec ON c.id_ec = ec.id_ec
                        LEFT JOIN t_unite_enseignement parent_ue ON ec.id_ue = parent_ue.id_ue
                        WHERE c.matricule = ?
                    ";
                    
                    $params = [$matricule];
                    
                    // Ajouter le filtre d'année académique si disponible
                    if ($annee_academique_courante) {
                        $query .= " AND c.id_annee = ?";
                        $params[] = $annee_academique_courante;
                    }
                    
                    $query .= " ORDER BY effective_ue_id ASC, c.id_ec ASC, c.id_note DESC";
                    
                    $stmt_res = $conn->prepare($query);
                    $stmt_res->execute($params);
                    $all_results = $stmt_res->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Grouper par UE pour afficher déstructurement
                    $results_grouped = [];
                    $seen = [];
                    
                    foreach ($all_results as $row) {
                        $ue_id = $row['effective_ue_id'] ?? 0;
                        $ec_id = $row['id_ec'] ?? 0;
                        $key = $ue_id . '|' . $ec_id;
                        
                        // Éviter les doublons (garder la première note)
                        if (!isset($seen[$key])) {
                            if (!isset($results_grouped[$ue_id])) {
                                $results_grouped[$ue_id] = [
                                    'ue_info' => [
                                        'ue_libelle' => $row['ue_libelle'],
                                        'code_ue' => $row['code_ue'],
                                        'ue_credits' => $row['ue_credits']
                                    ],
                                    'items' => []
                                ];
                            }
                            $results_grouped[$ue_id]['items'][] = $row;
                            $seen[$key] = true;
                        }
                    }
                    
                    // Aplatir pour l'affichage
                    $results = [];
                    foreach ($results_grouped as $ue_data) {
                        foreach ($ue_data['items'] as $item) {
                            $results[] = $item;
                        }
                    }
                    
                    
                    
                } catch (PDOException $e) {
                    $error_message = "Erreur lors de la récupération des notes.";
                    error_log("[CONSULTER_RESULTAT] Notes query error: " . $e->getMessage());
                    $results = [];
                }
            } else {
                $error_message = "Aucun étudiant trouvé avec ce matricule.";
            }
        } catch (PDOException $e) {
            $error_message = "Erreur lors de la recherche.";
            error_log("[CONSULTER_RESULTAT] Student query error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultation des Notes - UNILO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: Arial, sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        
        .entête {
            text-align: center;
            margin-bottom: 20px;
            font-family: "Tw Cen MT", sans-serif !important;
            color: black !important;
        }

        .entête p {
            margin: 0;
            line-height: 1.3;
        }

        .esu {
            font-family: "Edwardian Script ITC", cursive;
            font-size: 2.2rem;
        }

        .unilo {
            font-family: "Tw Cen MT", sans-serif;
            font-size: 1.2rem;
            font-weight: 900;
        }

        .contact {
            font-family: "Tw Cen MT", sans-serif;
            font-size: 1rem;
            font-weight: 600;
        }

        .infos-etu {
            font-family: "Tw Cen MT", sans-serif;
            font-size: 1rem;
            font-style: italic;
        }

        .titre-doc .titre {
            line-height: 1.7;
            font-size: 1.6rem;
            font-weight: 900;
            margin-top: 0;
        }

        .texte {
            text-align: justify;
        }

        .texte span {
            font-weight: bold;
        }

        table.word-style {
            border-collapse: collapse;
            font-family: "Century Gothic", Arial, sans-serif;
            font-size: 12px;
            margin: 10px 0;
            width: 100%;
        }

        table.word-style th,
        table.word-style td {
            border: 1px solid #000;
            padding: 4px 6px;
        }

        table.word-style th {
            background-color: #f2f2f2;
            font-weight: bold;
        }

        table.word-style tbody tr:hover {
            background-color: #e6f2ff;
        }

        .table-danger {
            background-color: #f8d7da !important;
        }

        @media print { 
            .no-print { display: none; }
            body { background-color: white; }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Formulaire de recherche -->
        <div class="row justify-content-center no-print mb-4">
            <div class="col-md-6">
                <div class="card p-4">
                    <h3 class="text-center mb-3 fw-bold">Consultation des Notes</h3>
                    <?php if (!$publication_active): ?>
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-pause-circle"></i> <?php echo htmlspecialchars($publication_message); ?>
                        </div>
                    <?php endif; ?>
                    <form method="POST" class="d-flex gap-2">
                        <input type="text" name="matricule" class="form-control form-control-lg"
                            placeholder="Entrez votre matricule..." <?php echo !$publication_active ? 'disabled' : ''; ?> required
                            value="<?php echo isset($_POST['matricule']) ? htmlspecialchars($_POST['matricule']) : ''; ?>">
                        <button type="submit" class="btn btn-primary btn-lg px-4" <?php echo !$publication_active ? 'disabled' : ''; ?>>
                            <i class="fas fa-search"></i> Chercher
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Messages d'erreur -->
        <?php if ($error_message): ?>
            <div class="alert alert-danger no-print">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message && !$error_message): ?>
            <div class="alert alert-info no-print">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <!-- Relevé Professionnel -->
        <?php if ($student_data && !empty($results)): ?>
            <div class="card p-4 mb-4">
                <!-- Entête professionnel UNILO -->
                <div class="entête">
                    <table style="width: 100%; border-bottom: 7px double black;">
                        <tr>
                            <td style="width: 10%;">
                                <img src="../../img/logo.gif" alt="Logo" style="width: 80px; height: 80px;">
                            </td>
                            <td style="text-align: center;">
                                <p class="esu">ENSEIGNEMENT SUPERIEUR ET UNIVERSITAIRE</p>
                                <p class="unilo">UNIVERSITE NOTRE DAME DE LOMAMI</p>
                                <p class="unilo">SECRETARIAT GENERAL ACADEMIQUE</p>
                                <p class="contact">Contact : <a href="mailto:sgac@unilo.net">sgac@unilo.net</a></p>
                                <p class="contact">Téléphone : +243 813 677 556 / 898 472 255</p>
                            </td>
                            <td style="width: 10%;">
                                <img src="<?php echo $student_data['photo'] ?? '../../img/default-user.png'; ?>" 
                                     alt="Photo" style="width: 80px; height: 80px;">
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Titre -->
                <div class="titre-doc text-center my-4">
                    <p class="fw-bold titre" style="text-decoration: underline;">RELEVE DE NOTES</p>
                </div>

                <!-- Description du cursus académique -->
                <div class="texte mb-4">
                    <p>
                        <?php
                        $titre_civilite = ($student_data['sexe'] ?? '') === 'M' ? 'Monsieur' : 'Madame';
                        $nom_complet = htmlspecialchars(
                            trim(($student_data['nom_etu'] ?? '') . ' ' . 
                                 ($student_data['postnom_etu'] ?? '') . ' ' . 
                                 ($student_data['prenom_etu'] ?? ''))
                        );
                        $lieu_naiss = htmlspecialchars($student_data['lieu_naiss'] ?? '');
                        $date_naiss = '';
                        
                        if (!empty($student_data['date_naiss'])) {
                            try {
                                $date = new DateTime($student_data['date_naiss']);
                                $formatter = new IntlDateFormatter('fr_FR', IntlDateFormatter::LONG, IntlDateFormatter::NONE);
                                $formatter->setPattern('d MMMM yyyy');
                                $date_naiss = htmlspecialchars($formatter->format($date));
                            } catch (Exception $e) {
                                $date_naiss = htmlspecialchars($student_data['date_naiss']);
                            }
                        }
                        ?>
                        
                        <?php echo $titre_civilite . ' ' . $nom_complet; ?>, 
                        né<?php echo ($student_data['sexe'] ?? '') === 'F' ? 'e' : ''; ?> à 
                        <span><?php echo !empty($lieu_naiss) ? $lieu_naiss : '................'; ?></span>, le 
                        <span><?php echo !empty($date_naiss) ? $date_naiss : '................'; ?></span>,
                        a obtenu à l'issue du premier et second semestre, de l'année académique 
                        <span><?php echo htmlspecialchars($annee_academique_libelle); ?></span>,
                        les résultats obtenus régulièrement l'ensemble des 
                        <span>UE (et ECUE)</span> prévus au programme de
                        <span><?php echo htmlspecialchars($promotion_libelle); ?></span>,
                        en <span><?php echo htmlspecialchars($domaine_libelle); ?></span>,
                        Filière de <span><?php echo htmlspecialchars($filiere_libelle); ?></span>,
                        mention <span><?php echo htmlspecialchars($mention_libelle); ?></span>.
                    </p>
                </div>

                <!-- Tableau des notes par semestre -->
                <div class="table-responsive mb-4">
                    <table class="word-style">
                        <thead>
                            <tr>
                                <th style="border:1px solid #000; padding:4px;">Code UE</th>
                                <th style="border:1px solid #000; padding:4px;">Unités d'enseignements (UE) et éléments constitutifs (ECUE)</th>
                                <th style="border:1px solid #000; padding:4px;">Crédits</th>
                                <th style="border:1px solid #000; padding:4px;">Notes S1 (/20)</th>
                                <th style="border:1px solid #000; padding:4px;">Notes S2 (/20)</th>
                                <th style="border:1px solid #000; padding:4px;">Meilleure Note (/20)</th>
                                <th style="border:1px solid #000; padding:4px;">Décision</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total_note = 0;
                            $total_credits = 0;
                            $credits_valides = 0;
                            $note_count = 0;
                            $last_ue_id = null;
                            
                            foreach ($results as $row):
                                $cote_s1 = (float)($row['cote_s1'] ?? 0);
                                $cote_s2 = (float)($row['cote_s2'] ?? 0);
                                $note_meilleure = max($cote_s1, $cote_s2);
                                $credits = (float)($row['ue_credits'] ?? 0);
                                $decision = $note_meilleure >= 10 ? 'Validé' : 'Non validé';
                                $row_class = $note_meilleure < 10 ? 'table-danger' : '';
                                
                                $is_ec = !empty($row['id_ec']) && !empty($row['ec_libelle']);
                                $effective_ue_id = $row['effective_ue_id'] ?? 0;
                                
                                // Ne calculer les stats que pour les lignes qui ont une note
                                if ($note_meilleure > 0) {
                                    $total_note += $note_meilleure;
                                    // Ajouter les crédits seulement une fois par UE (pas pour chaque EC)
                                    if ($last_ue_id !== $effective_ue_id || !$is_ec) {
                                        $total_credits += $credits;
                                    }
                                    $note_count++;
                                    if ($note_meilleure >= 10) {
                                        if ($last_ue_id !== $effective_ue_id || !$is_ec) {
                                            $credits_valides += $credits;
                                        }
                                    }
                                }
                                
                                $last_ue_id = $effective_ue_id;
                            ?>
                            <tr class="<?php echo $row_class; ?>" style="<?php echo $is_ec ? 'background-color: #f9f9f9;' : ''; ?>">
                                <td style="border:1px solid #000; padding:4px; text-align: center; font-weight: <?php echo $is_ec ? 'normal' : 'bold'; ?>;">
                                    <?php if ($is_ec): ?>
                                        <small style="color: #666;">└─ <?php echo htmlspecialchars($row['code_ue'] ?? 'N/A'); ?></small>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($row['code_ue'] ?? 'N/A'); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="border:1px solid #000; padding:4px; font-weight: <?php echo $is_ec ? 'normal' : 'bold'; ?>;">
                                    <?php if ($is_ec): ?>
                                        <div style="margin-left: 20px; padding-top: 2px; padding-bottom: 2px;">
                                            <small style="color: #666;">
                                                <strong><?php echo htmlspecialchars($row['ue_libelle'] ?? ''); ?></strong>
                                            </small>
                                            <br>
                                            <small style="color: #333; font-style: italic;">
                                                EC : <?php echo htmlspecialchars($row['ec_libelle']); ?>
                                            </small>
                                        </div>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($row['ue_libelle'] ?? ''); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="border:1px solid #000; padding:4px; text-align: center; font-weight: <?php echo $is_ec ? 'normal' : 'bold'; ?>;">
                                    <?php if (!$is_ec): ?>
                                        <?php echo htmlspecialchars($credits); ?>
                                    <?php else: ?>
                                        <small>-</small>
                                    <?php endif; ?>
                                </td>
                                <td style="border:1px solid #000; padding:4px; text-align: center;">
                                    <?php echo $cote_s1 > 0 ? number_format($cote_s1, 2) : '-'; ?>
                                </td>
                                <td style="border:1px solid #000; padding:4px; text-align: center;">
                                    <?php echo $cote_s2 > 0 ? number_format($cote_s2, 2) : '-'; ?>
                                </td>
                                <td style="border:1px solid #000; padding:4px; text-align: center; font-weight: bold;">
                                    <?php echo number_format($note_meilleure, 2); ?>
                                </td>
                                <td style="border:1px solid #000; padding:4px; text-align: center;">
                                    <?php echo $decision; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Synthèse et signatures -->
                <div class="row mt-5" style="font-family: 'Century Gothic', Arial, sans-serif; font-size: 14px;">
                    <div class="col-6">
                        <div style="margin-bottom: 15px;">
                            <strong>Total crédits :</strong> <?php echo $total_credits; ?>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <strong>Crédits validés :</strong> <?php echo $credits_valides; ?>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <strong>Moyenne annuelle :</strong> 
                            <?php echo $note_count > 0 ? number_format($total_note / $note_count, 2) : '0.00'; ?> /20
                        </div>
                        <div style="margin-bottom: 15px;">
                            <strong>Mention :</strong>
                            <?php
                            $moyenne = $note_count > 0 ? $total_note / $note_count : 0;
                            $mention = "Non attribuée";
                            if ($moyenne >= 16) $mention = "Distinction";
                            elseif ($moyenne >= 14) $mention = "Grande Satisfaction";
                            elseif ($moyenne >= 12) $mention = "Satisfaction";
                            elseif ($moyenne >= 10) $mention = "Passable";
                            echo $mention;
                            ?>
                        </div>
                        <div style="margin-top: 40px; text-align: center; font-weight: bold;">
                            LE DOYEN DE LA FACULTE
                        </div>
                        <div style="margin-top: 40px; text-align: center; font-weight: bold;">
                            Prof Abbé Pierre ILUNGA KALE
                        </div>
                    </div>
                    <div class="col-6">
                        <div style="margin-bottom: 15px;">
                            <strong>Décision :</strong>
                            <?php 
                            echo ($note_count > 0 && ($total_note / $note_count) >= 10) ? 
                                '<span style="color: green; font-weight: bold;">ADMIS</span>' : 
                                '<span style="color: red; font-weight: bold;">AJOURNÉ</span>';
                            ?>
                        </div>
                        <div style="margin-top: 60px; font-weight: bold;">
                            Fait à Kabinda le ......../......../<?php echo date('Y'); ?>
                        </div>
                        <div style="margin-top: 40px; text-align: center; font-weight: bold;">
                            LE SECRETAIRE GENERAL ACADEMIQUE
                        </div>
                        <div style="margin-top: 40px; text-align: center; font-weight: bold;">
                            Prof Mgr Lambert KANKENZA MUTEBA
                        </div>
                    </div>
                </div>

                <!-- Bouton imprimer -->
                <div class="d-flex justify-content-end mt-4 no-print">
                    <button class="btn btn-primary btn-lg rounded-pill shadow-sm" onclick="window.print()">
                        <i class="fas fa-print me-2"></i> Imprimer le relevé de notes
                    </button>
                </div>
            </div>

        <?php elseif ($student_data && empty($results) && !$error_message): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> Cet étudiant n'a pas encore de notes enregistrées.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
