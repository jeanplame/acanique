<?php
    header('Content-Type: text/html; charset=UTF-8');
// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions_config.php';
require_once __DIR__ . '/../../includes/db_config.php';

// Récupération de l'utilisateur connecté
$username = $_SESSION['user_id'] ?? null;

if (!$username) {
    die('<div class="alert alert-danger">Vous devez être connecté pour accéder à cette page.</div>');
}

// Récupération des paramètres
$notes_tab = $_GET['tab'] ?? 'cotation';
$active_tab = $_GET['tab'] ?? 'inscriptions';

// Vérifier les permissions pour l'onglet actif
if (!checkTabPermission($pdo, $active_tab, $username)) {
    echo "<div class='alert alert-danger text-center'>
            Vous n'avez pas l'autorisation nécessaire pour accéder à l'onglet <strong>$active_tab</strong>.
          </div>";
    return;
}

// Initialisation des variables
$annee_academique = $_GET['annee'] ?? null;
$mention_id = $_GET['mention'] ?? null;
$promotion_code = $_GET['promotion'] ?? null;

// Récupération sécurisée des informations de l'utilisateur
$nom_complet = isset($_SESSION['nom_complet']) ? htmlspecialchars($_SESSION['nom_complet'], ENT_QUOTES, 'UTF-8') : '';
?>

<div class="container-fluid">
    <!-- Navigation par onglets -->
    <ul class="nav nav-tabs mb-3">
        <?php if (checkTabPermission($pdo, 'cotation')): ?>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'cotation' ? 'active' : '' ?>" href="?tab=cotation">
                <i class="bi bi-list-check"></i> Cotation
            </a>
        </li>
        <?php endif; ?>

        <?php if (checkTabPermission($pdo, 'deliberation')): ?>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'deliberation' ? 'active' : '' ?>" href="?tab=deliberation">
                <i class="bi bi-file-text"></i> Délibération
            </a>
        </li>
        <?php endif; ?>

        <?php if (checkTabPermission($pdo, 'inscriptions')): ?>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'inscriptions' ? 'active' : '' ?>" href="?tab=inscriptions">
                <i class="bi bi-person-plus"></i> Inscriptions
            </a>
        </li>
        <?php endif; ?>

        <?php if (checkTabPermission($pdo, 'ue')): ?>
        <li class="nav-item">
            <a class="nav-link <?= $active_tab === 'ue' ? 'active' : '' ?>" href="?tab=ue">
                <i class="bi bi-book"></i> UE
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Contenu de l'onglet -->
    <div class="tab-content">
        <?php
        $tab_file = __DIR__ . '/tabs/' . $active_tab . '.php';
        if (file_exists($tab_file)) {
            include $tab_file;
        } else {
            echo "<div class='alert alert-warning'>Contenu de l'onglet non disponible.</div>";
        }
        ?>
    </div>

    <!-- Barre d'actions -->
    <div class="action-bar mt-4">
        <?php if ($active_tab === 'inscriptions' && checkActionPermission($pdo, 'Inscriptions', 'add')): ?>
            <button type="button" class="btn btn-primary" onclick="showAddInscriptionModal()">
                <i class="bi bi-plus-circle"></i> Nouvelle inscription
            </button>
        <?php endif; ?>

        <?php if ($active_tab === 'cotation' && checkActionPermission($pdo, 'Cotes', 'add')): ?>
            <button type="button" class="btn btn-primary" onclick="showAddNoteModal()">
                <i class="bi bi-plus-circle"></i> Nouvelle note
            </button>
        <?php endif; ?>

        <?php if ($active_tab === 'ue' && checkActionPermission($pdo, 'Cours', 'add')): ?>
            <button type="button" class="btn btn-primary" onclick="showAddUEModal()">
                <i class="bi bi-plus-circle"></i> Nouvelle UE
            </button>
        <?php endif; ?>
    </div>
</div>

<script>
function showAddInscriptionModal() {
    // Code pour afficher la modale d'inscription
}

function showAddNoteModal() {
    // Code pour afficher la modale d'ajout de note
}

function showAddUEModal() {
    // Code pour afficher la modale d'ajout d'UE
}
</script>
