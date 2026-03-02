<?php
    header('Content-Type: text/html; charset=UTF-8');
// tabs/notes.php
// Ce fichier sert de contrôleur pour la gestion des notes.
// Il inclut les fichiers de contenu spécifiques en fonction de l'onglet actif.

// Assurez-vous que la connexion à la base de données est établie.
// require_once 'includes/db_connection.php'; // Décommenter si nécessaire

// Inclure les fonctions nécessaires
require_once 'includes/domaine_functions.php';

// ================================================
// RÉCUPÉRATION DE L'ANNÉE ACADÉMIQUE
// Priorité: 1. URL ($_GET['annee']), 2. Année en cours
// ================================================
$id_annee = getAnneeFromUrlOrDefault($pdo, $_GET['annee'] ?? null);

if (!$id_annee) {
    echo '<div class="alert alert-danger">Aucune année académique configurée. Veuillez contacter l\'administrateur.</div>';
    exit;
}

// Récupérer les détails de l'année pour affichage
$stmt = $pdo->prepare("SELECT id_annee, date_debut, date_fin, statut FROM t_anne_academique WHERE id_annee = ?");
$stmt->execute([$id_annee]);
$annee = $stmt->fetch(PDO::FETCH_ASSOC);

$annee_academique = $id_annee;

// Définir l'onglet actif par défaut
$notes_tab = $_GET['tabnotes'] ?? 'cotation';

// Liste des onglets autorisés
$allowed_notes_tabs = ['cotation', 'deliberation', 'palmares'];
if (!in_array($notes_tab, $allowed_notes_tabs)) {
    $notes_tab = 'cotation';
}

// Assurer la présence des paramètres nécessaires (id_domaine, mention, promotion)
$id_domaine = isset($_GET['id']) ? (int) $_GET['id'] : null;
$mention_id = isset($_GET['mention']) ? (int) $_GET['mention'] : null;
$promotion_code = isset($_GET['promotion']) ? htmlspecialchars($_GET['promotion']) : '';
$id_semestre = isset($_GET['semestre']) ? (int) $_GET['semestre'] : null;

// Vérification de la présence des paramètres de base
if (!$id_domaine || !$mention_id || !$promotion_code) {
    // Rediriger ou afficher une erreur si les paramètres manquent
    echo '<div class="alert alert-danger">Paramètres de navigation manquants.</div>';
    exit;
}


// ---- Fonctions de récupération des données ----
// Ces fonctions sont conservées ici car elles pourraient être utilisées par plusieurs onglets.

/**
 * Récupère la liste des éléments constitutifs (EC) pour une promotion, un semestre et une mention donnés.
 *
 * @param PDO $pdo L'objet de connexion à la base de données.
 * @param string $promotion_code Le code de la promotion (ex: 'L1', 'L2').
 * @param int $id_semestre L'identifiant du semestre.
 * @param int $mention_id L'identifiant de la mention.
 * @return array Un tableau d'EC.
 */
function getECsForPromotion($pdo, $promotion_code, $id_semestre, $mention_id)
{
    $sql = "
        SELECT
            ue.id_ue,
            ue.code_ue,
            ue.libelle AS libelle_ue,
            ec.id_ec,
            ec.libelle AS libelle_ec
        FROM t_unite_enseignement ue
        JOIN t_mention_ue mue 
            ON ue.id_ue = mue.id_ue
        JOIN t_association_promo ap 
            ON mue.id_mention = ap.id_mention
        LEFT JOIN t_element_constitutif ec 
            ON ue.id_ue = ec.id_ue
        WHERE ap.code_promotion = :code_promo
        AND mue.semestre = :id_semestre
        AND mue.id_mention = :id_mention
        ORDER BY ue.libelle, ec.libelle;

    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'code_promo' => $promotion_code,
        'id_semestre' => $id_semestre,
        'id_mention' => $mention_id
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


/**
 * Récupère la liste des étudiants d'une promotion et d'une mention.
 *
 * @param PDO $pdo L'objet de connexion à la base de données.
 * @param string $promotion_code Le code de la promotion.
 * @param int $mention_id L'identifiant de la mention.
 * @return array Un tableau d'étudiants.
 */
function getStudentsForPromotionAndMention($pdo, $promotion_code, $mention_id)
{
    $sql = "SELECT * FROM vue_etudiants_mention_promotion WHERE code_promotion = ? AND id_mention = ? ORDER BY nom_etu ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$promotion_code, $mention_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>

<div class="container-fluid mt-4">

    <!-- Onglets de navigation -->
    <ul class="nav nav-tabs" id="notesTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo ($notes_tab === 'cotation') ? 'active' : ''; ?>" id="cotation-tab"
                href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=notes&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&tabnotes=cotation"
                role="tab">
                <i class="bi bi-pencil-square"></i> Fiche de cotation
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo ($notes_tab === 'deliberation') ? 'active' : ''; ?>" id="deliberation-tab"
                href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=notes&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&tabnotes=deliberation"
                role="tab">
                <i class="bi bi-table"></i> Grille de délibération
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo ($notes_tab === 'palmares') ? 'active' : ''; ?>" id="palmares-tab"
                href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=notes&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $id_annee; ?>&tabnotes=palmares"
                role="tab">
                <i class="bi bi-trophy-fill"></i> Palmarès
            </a>
        </li>
    </ul>

    <!-- Contenu des onglets -->
    <div class="tab-content mt-3" id="notesTabsContent">
        <?php
        // Inclure le fichier correspondant à l'onglet actif.
        if ($notes_tab === 'cotation') {
            include 'cotation.php';
        } elseif ($notes_tab === 'deliberation') {
            include 'deliberation.php';
        } elseif ($notes_tab === 'palmares') {
            include 'palmares.php';
        } else {
            // Afficher un message d'erreur si l'onglet n'est pas reconnu
            echo '<div class="alert alert-warning">Contenu non disponible pour cet onglet.</div>';
        }
        ?>
    </div>
</div>