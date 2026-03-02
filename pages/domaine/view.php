<?php
require_once __DIR__ . '/../../includes/db_config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions_config.php';
require_once __DIR__ . '/../../includes/permission_helpers.php';
    header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../includes/domain_filter.php';
require_once __DIR__ . '/../../includes/domaine_functions.php';

// Vérifier que l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// ================================================
// RÉCUPÉRATION DE L'ANNÉE ACADÉMIQUE
// Priorité: 1. URL ($_GET['annee']), 2. Année en cours
// ================================================
$id_annee = getAnneeFromUrlOrDefault($pdo, $_GET['annee'] ?? null);

if (!$id_annee) {
    $_SESSION['error'] = "Aucune année académique configurée. Veuillez contacter l'administrateur.";
    header('Location: ?page=dashboard');
    exit();
}

// Vérifier l'accès au domaine demandé
$id_domaine = (int)($_GET['id'] ?? 0);
$currentUsername = $_SESSION['user_id'];

if ($id_domaine && !userHasAccessToDomain($currentUsername, $id_domaine)) {
    $_SESSION['error'] = "Accès refusé à ce domaine. Vous n'êtes autorisé qu'à accéder à votre domaine spécifique.";
    header('Location: ?page=dashboard');
    exit();
}

// Vérifier les permissions disponibles pour cette page
$has_course_read_permission = hasPermission($pdo, $_SESSION['user_id'], 'Cours', 'S');
$has_inscriptions_permission = hasPermission($pdo, $_SESSION['user_id'], 'Inscriptions', 'S');
$has_cotes_permission = hasPermission($pdo, $_SESSION['user_id'], 'Cotes', 'S');

// Calculer combien d'onglets sont disponibles
$available_tabs_count = 0;
if ($has_course_read_permission) $available_tabs_count++;
if ($has_inscriptions_permission) $available_tabs_count++;
if ($has_cotes_permission) $available_tabs_count++;

// Vérification de l'ID du domaine
if (!isset($_GET['id'])) {
    header('Location: ?page=dashboard');
    exit();
}

// Initialisation des variables
$id_domaine = (int)$_GET['id'];
$mention_id = isset($_GET['mention']) ? (int)$_GET['mention'] : null;
$promotion_code = isset($_GET['promotion']) ? htmlspecialchars($_GET['promotion']) : '';
$active_tab = isset($_GET['tab']) ? htmlspecialchars($_GET['tab']) : 'overview';

// Stocker l'année académique pour utilisation dans les includes
$annee_academique = $id_annee;

// Générer les boutons d'action selon les permissions
$actionButtons = '';
if (canAccess('Cours', 'U')) {
    $actionButtons .= '<a href="?page=domaine&action=edit&id='.$id_domaine.'&annee='.$id_annee.'" class="btn btn-primary btn-sm">
                        <i class="bi bi-pencil"></i> Modifier
                      </a> ';
}
if (canAccess('Cours', 'D')) {
    $actionButtons .= '<a href="#" onclick="confirmDelete('.$id_domaine.'); return false;" 
                         class="btn btn-danger btn-sm">
                        <i class="bi bi-trash"></i> Supprimer
                      </a>';
}

// Supprimer les anciennes lignes de récupération d'année académique (déjà fait en haut)
// $annee_academique est déjà défini au début du fichier




try {
    // Récupération du domaine avec statistiques
    $stmt = $pdo->prepare("
        SELECT d.*, 
            (SELECT COUNT(*) FROM t_filiere f WHERE f.id_domaine = d.id_domaine) as nb_filieres,
            (SELECT COUNT(*) FROM t_mention m WHERE m.idFiliere IN (SELECT idFiliere FROM t_filiere WHERE id_domaine = d.id_domaine)) as nb_mentions,
            (SELECT COUNT(*) FROM t_inscription i WHERE i.id_filiere IN (SELECT idFiliere FROM t_filiere WHERE id_domaine = d.id_domaine) AND i.id_annee = ?) as nb_inscrits
        FROM t_domaine d 
        WHERE d.id_domaine = ?
    ");
    $stmt->execute([$annee_academique, $id_domaine]);
    $domaine = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // if (!$domaine) {
    //     $_SESSION['error'] = "Domaine introuvable";
    //     header('Location: ?page=dashboard');
    //     exit();
    // } else {
    //     $_SESSION['succes'] = "Domaine trouvé". $domaine['nom_domaine'];
    // }
    
    // Récupération des filières avec statistiques
    $stmt = $pdo->prepare("
        SELECT f.*, 
            (SELECT COUNT(*) FROM t_mention m WHERE m.idFiliere = f.idFiliere) as nb_mentions,
            (SELECT COUNT(*) FROM t_inscription i WHERE i.id_filiere = f.idFiliere AND i.id_annee = ?) as nb_inscrits,
            u.nom_complet as responsable
        FROM t_filiere f 
        LEFT JOIN t_utilisateur u ON f.username = u.username
        WHERE f.id_domaine = ? 
        ORDER BY f.nomFiliere
    ");
    $stmt->execute([$annee_academique, $id_domaine]);
    $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = "Une erreur est survenue lors de la récupération des données";
}

?>
<!-- En-tête avec le nom du domaine et les statistiques -->
<?php
// Vider les erreurs de permission existantes car la page est maintenant accessible
if (isset($_SESSION['permission_error'])) {
    unset($_SESSION['permission_error']);
}

// Afficher un message informatif si l'utilisateur a un accès limité
if ($available_tabs_count === 0) {
    echo '<div class="container-fluid">';
    echo '<div class="alert alert-warning" role="alert">';
    echo '<h4 class="alert-heading"><i class="bi bi-info-circle"></i> Accès limité</h4>';
    echo '<p>Vous n\'avez actuellement accès à aucun onglet de cette section.</p>';
    echo '<hr>';
    echo '<p class="mb-2"><strong>Permissions requises :</strong></p>';
    echo '<ul class="mb-2">';
    echo '<li><strong>Cours - Lecture (S)</strong> : Pour voir les informations du domaine et les unités d\'enseignement</li>';
    echo '<li><strong>Inscriptions - Lecture (S)</strong> : Pour voir les inscriptions des étudiants</li>';
    echo '<li><strong>Cotes - Lecture (S)</strong> : Pour voir les notes et évaluations</li>';
    echo '</ul>';
    echo '<p class="mb-0">Contactez votre administrateur pour obtenir les permissions nécessaires.</p>';
    echo '</div>';
    echo '</div>';
    return; // Arrêter l'exécution si aucun onglet disponible
} 
?>
<div class="container-fluid py-2 bg-light border-bottom">
    <div class="row align-items-center">
        <div class="col">
            <h2 class="h4 mb-0">
                <?php echo htmlspecialchars($domaine['nom_domaine']); ?>
                <small class="text-muted"><?php echo htmlspecialchars($domaine['code_domaine']); ?></small>
            </h2>
        </div>
        <div class="col-auto">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-bank"></i> <?php echo $domaine['nb_filieres']; ?> filières
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-mortarboard"></i> <?php echo $domaine['nb_mentions']; ?> mentions
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-people"></i> <?php echo $domaine['nb_inscrits']; ?> inscrits
                </button>
            </div>
            <?php if ($mention_id): ?>
                <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&annee=<?php echo $id_annee; ?>" class="btn btn-link">
                    <i class="bi bi-arrow-left"></i> Retour à la mention
                </a>
            <?php else: ?>
                <a href="?page=dashboard" class="btn btn-link">
                    <i class="bi bi-arrow-left"></i> Retour au tableau de bord
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Layout principal -->
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar (30%) -->
        
        <?php include('menu_bar.php')?>
        <!-- Contenu principal (70%) -->
        
        <?php
        include('contenu_main.php')
        ?>

    </div>
</div>

<!-- CSS uniquement -->
<link href="/css/bootstrap.min.css" rel="stylesheet">
<link href="/css/bootstrap-icons.min.css" rel="stylesheet">
<!-- Bootstrap JS pour les composants interactifs -->
<link rel="stylesheet" href="/js/bootstrap.bundle.min.js">

<!-- CSS uniquement -->
<link href="/css/bootstrap.min.css" rel="stylesheet">
<link href="/css/bootstrap-icons.min.css" rel="stylesheet">
