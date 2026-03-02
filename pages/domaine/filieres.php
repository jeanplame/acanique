<?php 
/**
 * Page pour la gestion des filières d'un domaine
 */
require_once 'includes/db_config.php';

$id_domaine = $_GET['id'] ?? null;
if (!$id_domaine) {
    header('Location: ?page=dashboard');
    exit();
}
$id_domaine = (int)$id_domaine;

// Récupération de l'année académique depuis l'URL ou par défaut
require_once 'includes/domaine_functions.php';
$id_annee = getAnneeFromUrlOrDefault($pdo, $_GET['annee'] ?? null);

// Récupération des informations du domaine
$stmt = $pdo->prepare("SELECT * FROM t_domaine WHERE id_domaine = ?");
$stmt->execute([$id_domaine]);
$domaine = $stmt->fetch(PDO::FETCH_ASSOC);
// if (!$domaine) {
//     $_SESSION['error'] = "Domaine introuvable";
//     header('Location: ?page=dashboard');
//     exit();
// }
$_SESSION['domaine'] = $domaine;
// Récupération de l'année académique actuelle
require_once 'includes/domaine_functions.php';
$current_year = getCurrentAcademicYear($pdo);
if (!$current_year) {
    $_SESSION['error'] = "Aucune année académique en cours trouvée";
    header('Location: ?page=dashboard');
    exit();
}
$_SESSION['current_year'] = $current_year;

// Récupération des filières du domaine
$stmt = $pdo->prepare("SELECT * FROM t_filiere WHERE id_domaine = ? ORDER BY nom_filiere");
$stmt->execute([$id_domaine]);
$filiere = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$filiere) {
    $_SESSION['error'] = "Aucune filière trouvée pour ce domaine";
    header('Location: ?page=dashboard');
    exit();
}

// Récupération des mentions pour chaque filière
$filieres_with_mentions = [];
foreach ($filiere as $f) {
    $stmt = $pdo->prepare("SELECT * FROM t_mention WHERE id_filiere = ? ORDER BY nom_mention");
    $stmt->execute([$f['id_filiere']]);
    $mentions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $f['mentions'] = $mentions;
    $filieres_with_mentions[] = $f;
}

// Logique pour gérer les filières 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_filiere') {
        $nom_filiere = trim($_POST['nom_filiere']);
        if ($nom_filiere) {
            $stmt = $pdo->prepare("INSERT INTO t_filiere (id_domaine, nom_filiere) VALUES (?, ?)");
            if ($stmt->execute([$id_domaine, $nom_filiere])) {
                $_SESSION['success'] = "Filière ajoutée avec succès";
            } else {
                $_SESSION['error'] = "Erreur lors de l'ajout de la filière";
            }
        } else {
            $_SESSION['error'] = "Le nom de la filière ne peut pas être vide";
        }
        header('Location: ?page=domaine&action=view&id=' . $id_domaine);
        exit();
    }
}
// Affichage de la page
require_once 'includes/header.php';
?>
<div class="container">
    <h1 class="mt-4">Gestion des filières pour le domaine <?php echo htmlspecialchars($domaine['nom_domaine']); ?></h1>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="mb-3">
        <input type="hidden" name="action" value="add_filiere">
        <div class="input-group mb-3">
            <input type="text" name="nom_filiere" class="form-control" placeholder="Nom de la nouvelle filière" required>
            <button class="btn btn-primary" type="submit">Ajouter Filière</button>
        </div>
    </form>

    <h2>Liste des filières</h2>
    <ul class="list-group">
        <?php foreach ($filieres_with_mentions as $filiere): ?>
            <li class="list-group-item">
                <strong><?php echo htmlspecialchars($filiere['nom_filiere']); ?></strong>
                <span class="badge bg-secondary"><?php echo count($filiere['mentions']); ?> Mentions</span>
                <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&annee=<?php echo $id_annee; ?>&filiere=<?php echo $filiere['id_filiere']; ?>" class="btn btn-sm btn-info float-end">Voir Mentions</a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php
// Récupérer le paramètre de l'url pour afficher cette page et inclure
if (isset($_GET['filiere']) && $_GET['filiere'] === 'add') {
    $id_filiere = (int)$_GET['filiere'];
    $stmt = $pdo->prepare("SELECT * FROM t_filiere WHERE id_filiere = ?");
    $stmt->execute([$id_filiere]);
    $filiere_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($filiere_details) {
        echo "<h3>Détails de la filière: " . htmlspecialchars($filiere_details['nom_filiere']) . "</h3>";
        // Afficher les mentions associées à cette filière
        if (!empty($filiere_details['mentions'])) {
            echo "<ul class='list-group'>";
            foreach ($filiere_details['mentions'] as $mention) {
                echo "<li class='list-group-item'>" . htmlspecialchars($mention['nom_mention']) . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>Aucune mention trouvée pour cette filière.</p>";
        }
    } else {
        echo "<p>Filière introuvable.</p>";
    }
}
?>
</div>
