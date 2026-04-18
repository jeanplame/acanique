<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/page_permissions.php';
require_once 'includes/login_logger.php';
require_once 'includes/license_system.php';
require_once 'includes/payment_system.php';

// Générer le token CSRF si il n'existe pas
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Démarrer la mise en tampon de sortie
ob_start();

// Gérer la déconnexion
if (isset($_GET['page']) && $_GET['page'] === 'logout') {
    if (isset($_SESSION['user_id'])) {
        try {
            $stmt = $pdo->prepare("UPDATE t_utilisateur SET remember_token = NULL, token_expires = NULL WHERE username = ?");
            $stmt->execute([$_SESSION['user_id']]);
        } catch (PDOException $e) {
            error_log("Erreur lors de la déconnexion : " . $e->getMessage());
        }
    }

    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 42000, '/');
    }
    session_destroy();
    header("Location: ?page=login");
    exit();
}

// Récupération des paramètres de l'URL
$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;
$publicPages = ['login', 'logout'];

// Traiter la connexion si nécessaire
if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['pass'] ?? '');
    $remember = isset($_POST['remember-me']);

    // Vérifier le token CSRF si disponible
    if (isset($_POST['csrf_token']) && isset($_SESSION['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $error = "Token de sécurité invalide";
            logLoginAttempt($pdo, $username, false, "Token CSRF invalide");
        }
    }

    if (!isset($error)) {
        if (empty($username) || empty($password)) {
            $error = "Veuillez remplir tous les champs";
            logLoginAttempt($pdo, $username, false, "Champs vides");
        } else {
            try {
                $stmt = $pdo->prepare("SELECT username, nom_complet, motdepasse, role FROM t_utilisateur WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['motdepasse'])) {
                    // Connexion réussie
                    $_SESSION['user_id'] = $user['username'];
                    $_SESSION['nom_complet'] = $user['nom_complet'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['login_time'] = time(); // Pour le nettoyage automatique du cache

                    // Gestion du "Se souvenir de moi"
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));

                        $stmt = $pdo->prepare("UPDATE t_utilisateur SET remember_token = ?, token_expires = ? WHERE username = ?");
                        $stmt->execute([$token, $expiry, $user['username']]);

                        // Cookie sécurisé
                        setcookie('remember_token', $token, [
                            'expires' => time() + (30 * 24 * 60 * 60),
                            'path' => '/',
                            'domain' => '',
                            'secure' => isset($_SERVER['HTTPS']),
                            'httponly' => true,
                            'samesite' => 'Lax'
                        ]);

                        logLoginAttempt($pdo, $user['username'], true, "Connexion réussie avec Remember Me");
                    } else {
                        logLoginAttempt($pdo, $user['username'], true, "Connexion réussie");
                    }

                    // Marquer la connexion fraîche pour le preloader
                    $_SESSION['just_logged_in'] = true;

                    // Vider le tampon avant la redirection
                    ob_end_clean();
                    header("Location: ?page=dashboard");
                    exit();
                } else {
                    $error = "Nom d'utilisateur ou mot de passe incorrect";
                    logLoginAttempt($pdo, $username, false, "Identifiants incorrects");
                }
            } catch (PDOException $e) {
                error_log("Erreur de connexion : " . $e->getMessage());
                $error = "Une erreur est survenue. Veuillez réessayer plus tard.";
                logLoginAttempt($pdo, $username, false, "Erreur système: " . $e->getMessage());
            }
        }
    }
}

// Vérification de l'authentification pour les pages protégées
if (!isLoggedIn() && !in_array($page, $publicPages, true)) {
    // Vider le tampon avant la redirection
    ob_end_clean();
    header("Location: ?page=login");
    exit();
}

if (isLoggedIn()) {
    licenseBootstrap($pdo);

    // Tant que la licence n'est pas active, forcer l'écran d'activation.
    if (isLicenseActivationRequired($pdo) && !in_array($page, ['activation', 'logout'], true)) {
        ob_end_clean();
        header("Location: ?page=activation");
        exit();
    }

    // Si la licence est déjà active, inutile de rester sur la page d'activation.
    if (!isLicenseActivationRequired($pdo) && $page === 'activation') {
        ob_end_clean();
        header("Location: ?page=dashboard");
        exit();
    }
}

// Nettoyage automatique du cache des permissions
if (!in_array($page, ['login', 'activation'], true)) {
    autoCleanPermissionsCache($pdo);
}

// Vérification des permissions pour la page demandée (sans bloquer)
if (!in_array($page, ['login', 'activation'], true)) {
    checkPagePermission($pdo, $page);
}

// Inclusion du header pour les pages authentifiées
if (!in_array($page, ['login', 'activation'], true)) {
    require_once 'includes/header.php';
}

// Inclusion de la page demandée
$page_path = '';
switch ($page) {
    case 'dashboard':
        $page_path = 'pages/dashboard.php';
        break;
    case 'profiletudiant':
        $page_path = 'pages/etudiant_profil.php';
        break;
    case 'profile':
        $page_path = 'pages/profile.php';
        break;
    case 'students':
        $page_path = 'pages/admin/students.php';
        break;
    case 'courses':
        $page_path = 'pages/admin/courses.php';
        break;
    case 'settings':
        $page_path = 'pages/admin/settings.php';
        break;
    case 'backup_manager':
        $page_path = 'backup_manager_admin.php';
        break;
    case 'backup_dashboard':
        $page_path = 'backup_dashboard_advanced.php';
        break;
    case 'config_annees_academiques':
        $page_path = 'pages/config_annees_academiques.php';
        break;
    case 'jury_nomination':
        $page_path = 'pages/jury_nomination.php';
        break;
    case 'publication_resultats':
        $page_path = 'pages/admin/publication_resultats.php';
        break;

    case 'login':
        $page_path = 'pages/login.php';
        break;
    case 'activation':
        $page_path = 'pages/activation.php';
        break;
    case 'domaine':
        switch ($action) {
            case 'view':
                $page_path = 'pages/domaine/view.php';
                break;
            case 'select':
                // Redirection pour la sélection d'année académique
                // Conserver tous les paramètres sauf 'action'
                $params = $_GET;
                unset($params['action']);
                $params['action'] = 'view';
                $query_string = http_build_query($params);
                header("Location: ?" . $query_string);
                exit();
            case 'ajouter_mention':
                $page_path = 'pages/domaine/ajouter_mention.php';
                break;
            case 'promotions':
                $page_path = 'pages/domaine/promotions.php';
                break;
            case 'ajouter_promotion':
                $page_path = 'pages/domaine/ajouter_promotion.php';
                break;
            case 'liste_etudiants':
                $page_path = 'pages/domaine/liste_etudiants.php';
                break;
            case 'ajouter_etudiant':
                $page_path = 'pages/domaine/ajouter_etudiant.php';
                break;
            case 'modifier_etudiant':
                $page_path = 'pages/domaine/modifier_etudiant.php';
                break;
            case 'supprimer_etudiant':
                $page_path = 'pages/domaine/supprimer_etudiant.php';
                break;
            default:
                $page_path = 'pages/error/404.php';
        }
        break;
    case 'error':
        $page_path = 'pages/error/' . ($error_code ?? '404') . '.php';
        break;
    default:
        $page_path = 'pages/error/404.php';
}

// Inclusion du contenu de la page
if (file_exists($page_path)) {
    require $page_path;
} else {
    require 'pages/error/404.php';
}

// Récupération du contenu mis en tampon
$content = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACANIQUE - UNILO</title>
    <link rel="icon" type="image/png" href="images/icons/favicon.ico" />
    <link rel="stylesheet" type="text/css" href="vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="fonts/font-awesome-4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" type="text/css" href="fonts/Linearicons-Free-v1.0.0/icon-font.min.css">
    <link rel="stylesheet" type="text/css" href="vendor/animate/animate.css">
    <link rel="stylesheet" type="text/css" href="vendor/css-hamburgers/hamburgers.min.css">
    <link rel="stylesheet" type="text/css" href="vendor/animsition/css/animsition.min.css">
    <link rel="stylesheet" type="text/css" href="vendor/select2/select2.min.css">
    <link rel="stylesheet" type="text/css" href="vendor/daterangepicker/daterangepicker.css">
    <link rel="stylesheet" type="text/css" href="css/util.css">
    <link rel="stylesheet" type="text/css" href="css/main.css">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    <link href="css/domaine.css" rel="stylesheet">
    <link href="css/ai_chat.css" rel="stylesheet">
    <link href="css/preloader.css" rel="stylesheet">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
</head>

<body>
    <?php
    // Preloader post-login
    $showPreloader = !empty($_SESSION['just_logged_in']) && $page === 'dashboard';
    if ($showPreloader) {
        unset($_SESSION['just_logged_in']);
        $preloaderUser = htmlspecialchars($_SESSION['nom_complet'] ?? 'Utilisateur', ENT_QUOTES, 'UTF-8');
    ?>
        <div id="preloader-overlay" class="preloader-overlay" data-force="1">
            <img src="img/logo.gif" alt="ACANIQUE" class="preloader-logo">
            <div class="preloader-title">ACANIQUE &mdash; UNILO</div>
            <div class="preloader-subtitle">Universit&eacute; Notre Dame de Lomami</div>
            <div class="preloader-progress-track">
                <div class="preloader-progress-bar"></div>
            </div>
            <div class="preloader-status">Initialisation&hellip;</div>
            <div class="preloader-percent">0%</div>
            <div class="preloader-dots"><span></span><span></span><span></span></div>
            <div class="preloader-welcome">Bienvenue, <?= $preloaderUser ?> &#128075;</div>
        </div>
    <?php } ?>
    <?php echo $content; ?>

    <script src="vendor/jquery/jquery-3.2.1.min.js"></script>
    <script src="vendor/animsition/js/animsition.min.js"></script>
    <script src="vendor/bootstrap/js/popper.js"></script>
    <script src="vendor/bootstrap/js/bootstrap.min.js"></script>
    <script src="vendor/select2/select2.min.js"></script>
    <script src="vendor/daterangepicker/moment.min.js"></script>
    <script src="vendor/daterangepicker/daterangepicker.js"></script>
    <script src="vendor/countdowntime/countdowntime.js"></script>
    <script src="js/main.js"></script>
    <?php if ($showPreloader ?? false): ?>
        <script src="js/preloader.js"></script>
    <?php endif; ?>
    <?php if (!empty($_SESSION['user_id'])): ?>
        <script src="js/ai_chat.js"></script>
    <?php endif; ?>
</body>

</html>