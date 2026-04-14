<?php
/**
 * Tableau de bord de gestion des résultats — UNILO
 * ──────────────────────────────────────────────────────────────────────
 * Page autonome et auto-suffisante. Peut être hébergée directement en
 * ligne (cPanel) comme consulter_resultat.php, sans dépendance au
 * framework admin local.
 *
 * Fonctionnalités :
 *  - Authentification par mot de passe (configurable ci-dessous)
 *  - Publication / Suspension des résultats
 *  - Synchronisation locale → en ligne (uniquement quand exécutée en local)
 *  - Statistiques : étudiants, inscriptions, année, dernière sync
 *
 * Accès en ligne : https://solution.unilo.cd/pages/gestion_resultats.php
 */

session_start();

// ═══════════════════════════════════════════════════════════════════════
//  CONFIGURATION — Modifier le mot de passe ici
// ═══════════════════════════════════════════════════════════════════════

define('DASHBOARD_PASSWORD', 'unilo2026');

// ═══════════════════════════════════════════════════════════════════════
//  CONNEXION BASE DE DONNÉES (même logique que consulter_resultat.php)
// ═══════════════════════════════════════════════════════════════════════

$dbCandidates = [
    __DIR__ . '/../_sync/db_config_sync.php',
    __DIR__ . '/../includes/db_config.php',
    __DIR__ . '/includes/db_config.php',
    __DIR__ . '/_sync/db_config_sync.php',
];

$conn    = null;
$dbError = '';

foreach ($dbCandidates as $f) {
    if (file_exists($f)) {
        require_once $f;
        break;
    }
}

try {
    if (defined('DB_HOST')) {
        $conn = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } else {
        $dbError = 'Fichier de configuration DB introuvable.';
    }
} catch (PDOException $e) {
    $dbError = 'Connexion impossible : ' . $e->getMessage();
    error_log('[GESTION_RESULTATS] ' . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════
//  DÉCONNEXION
// ═══════════════════════════════════════════════════════════════════════

if (isset($_GET['logout'])) {
    unset($_SESSION['gestion_auth'], $_SESSION['gestion_csrf']);
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
//  TOKEN CSRF
// ═══════════════════════════════════════════════════════════════════════

if (empty($_SESSION['gestion_csrf'])) {
    $_SESSION['gestion_csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['gestion_csrf'];

// ═══════════════════════════════════════════════════════════════════════
//  AUTHENTIFICATION (formulaire simple)
// ═══════════════════════════════════════════════════════════════════════

$authError = '';

if (isset($_POST['login_password'])) {
    if ($_POST['login_password'] === DASHBOARD_PASSWORD) {
        $_SESSION['gestion_auth'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } else {
        $authError = 'Mot de passe incorrect.';
    }
}

$isAuth = !empty($_SESSION['gestion_auth']);

// ═══════════════════════════════════════════════════════════════════════
//  SYNCHRONISATION LOCALE (PowerShell, uniquement sur la machine locale)
// ═══════════════════════════════════════════════════════════════════════

$syncScriptPath = false;

foreach ([
    __DIR__ . '/../deployment/sync_vers_cpanel.ps1',
    __DIR__ . '/../../deployment/sync_vers_cpanel.ps1',
] as $candidate) {
    $resolved = realpath($candidate);
    if ($resolved !== false) {
        $syncScriptPath = $resolved;
        break;
    }
}

$canSync = $syncScriptPath !== false && function_exists('exec');

function triggerLocalSync(): array
{
    global $syncScriptPath;

    if ($syncScriptPath === false) {
        return [
            'success' => false,
            'message' => 'Script de synchronisation introuvable.',
            'output'  => [],
        ];
    }
    if (!function_exists('exec')) {
        return [
            'success' => false,
            'message' => 'exec() est désactivé sur ce serveur.',
            'output'  => [],
        ];
    }

    $output   = [];
    $exitCode = 1;
    exec(
        'powershell.exe -NoProfile -ExecutionPolicy Bypass -File '
        . escapeshellarg($syncScriptPath) . ' 2>&1',
        $output,
        $exitCode
    );

    return [
        'success' => $exitCode === 0,
        'message' => $exitCode === 0
            ? 'Synchronisation terminée avec succès.'
            : 'La synchronisation a échoué. Consultez le journal ci-dessous.',
        'output'  => $output,
    ];
}

// ═══════════════════════════════════════════════════════════════════════
//  HELPER : Mise à jour d'une clé de configuration
// ═══════════════════════════════════════════════════════════════════════

function upsertConfig(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare(
        'INSERT INTO t_configuration (cle, valeur) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)'
    )->execute([$key, $value]);
}

// ═══════════════════════════════════════════════════════════════════════
//  TRAITEMENT DES ACTIONS POST
// ═══════════════════════════════════════════════════════════════════════

$actionSuccess = '';
$actionError   = '';
$syncOutput    = [];

if ($isAuth && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {

    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $actionError = 'Jeton de sécurité invalide. Veuillez réessayer.';
    } elseif (!$conn) {
        $actionError = 'Connexion à la base de données indisponible.';
    } else {
        try {
            switch ($_POST['action']) {

                case 'publish':
                    upsertConfig($conn, 'resultats_publication_active', '1');
                    $actionSuccess = 'Les résultats sont maintenant accessibles en ligne.';
                    break;

                case 'suspend':
                    $msg = trim($_POST['message_suspension'] ?? '');
                    if ($msg === '') {
                        $msg = 'La consultation des résultats est temporairement suspendue. Veuillez réessayer plus tard.';
                    }
                    upsertConfig($conn, 'resultats_publication_active', '0');
                    upsertConfig($conn, 'resultats_publication_message', $msg);
                    $actionSuccess = 'La consultation des résultats a été suspendue.';
                    break;

                case 'sync_now':
                    $syncResult = triggerLocalSync();
                    $syncOutput = $syncResult['output'];
                    if ($syncResult['success']) {
                        $actionSuccess = $syncResult['message'];
                    } else {
                        $actionError = $syncResult['message'];
                    }
                    break;
            }
        } catch (Exception $e) {
            $actionError = 'Erreur : ' . $e->getMessage();
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  LECTURE DES STATISTIQUES (après les actions pour avoir l'état frais)
// ═══════════════════════════════════════════════════════════════════════

$pub_active      = false;
$pub_message     = 'La consultation des résultats est temporairement suspendue.';
$derniere_sync   = null;
$annee_label     = 'Non définie';
$nb_etudiants    = 0;
$nb_inscriptions = 0;

if ($isAuth && $conn) {
    try {
        $configs = $conn->query(
            "SELECT cle, valeur FROM t_configuration
             WHERE cle IN (
                 'resultats_publication_active',
                 'resultats_publication_message',
                 'derniere_sync',
                 'annee_encours'
             )"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $pub_active    = ($configs['resultats_publication_active'] ?? '0') === '1';
        $pub_message   = $configs['resultats_publication_message'] ?? $pub_message;
        $derniere_sync = $configs['derniere_sync'] ?? null;

        if (!empty($configs['annee_encours'])) {
            $stmt = $conn->prepare(
                'SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?'
            );
            $stmt->execute([$configs['annee_encours']]);
            $yr = $stmt->fetch();
            if ($yr) {
                $annee_label = date('Y', strtotime($yr['date_debut']))
                    . '–' . date('Y', strtotime($yr['date_fin']));
            }
        }

        $nb_etudiants    = (int) $conn->query('SELECT COUNT(*) FROM t_etudiant')->fetchColumn();
        $nb_inscriptions = (int) $conn->query(
            "SELECT COUNT(*) FROM t_inscription WHERE statut = 'Actif'"
        )->fetchColumn();

    } catch (Exception $e) {
        $actionError = 'Erreur de lecture des données : ' . $e->getMessage();
    }
}

// Formatage de la date de dernière sync
$derniere_sync_fmt = $derniere_sync
    ? date('d/m/Y à H:i', strtotime($derniere_sync))
    : 'Jamais synchronisé';

// URL publique de consultation (auto-détection)
$baseUrl    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST'];
$publicUrl  = $baseUrl . '/pages/consulter_resultat.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des résultats — UNILO</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f0f4f8; font-family: 'Segoe UI', sans-serif; }

        .header-bar {
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a9e 100%);
            color: #fff;
            padding: 18px 24px;
        }
        .header-bar h1 { font-size: 1.4rem; font-weight: 700; margin: 0; }
        .header-bar small { opacity: .75; font-size: .85rem; }

        .status-banner {
            font-weight: 600;
            letter-spacing: .5px;
            border-radius: 0;
            padding: 10px 24px;
            font-size: .92rem;
        }

        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.07);
            transition: transform .15s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
        }
        .stat-value { font-size: 2rem; font-weight: 700; line-height: 1.1; }
        .stat-label { font-size: .8rem; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }

        .action-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.07);
        }
        .action-card .card-title { font-weight: 700; font-size: 1.05rem; }

        .sync-info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 20px;
        }

        .log-terminal {
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            font-size: .82rem;
            max-height: 280px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        /* Page de connexion */
        .login-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1e3a5f 0%, #2d5a9e 100%);
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            border: none;
            border-radius: 16px;
            box-shadow: 0 16px 48px rgba(0,0,0,.25);
        }
    </style>
</head>
<body>

<?php if (!$isAuth): ?>
<!-- ══════════════════════════ PAGE DE CONNEXION ══════════════════════════ -->
<div class="login-wrap">
    <div class="login-card card p-4">
        <div class="text-center mb-4">
            <div class="mb-3">
                <span style="font-size:2.8rem;">🎓</span>
            </div>
            <h2 class="fw-bold" style="color:#1e3a5f;">UNILO</h2>
            <p class="text-muted mb-0">Gestion des résultats</p>
        </div>

        <?php if ($authError): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($authError); ?></div>
        <?php endif; ?>
        <?php if ($dbError): ?>
            <div class="alert alert-warning py-2"><i class="bi bi-database-exclamation me-2"></i><?php echo htmlspecialchars($dbError); ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="mb-3">
                <label class="form-label fw-semibold" for="login_password">Mot de passe</label>
                <input type="password" id="login_password" name="login_password"
                       class="form-control form-control-lg"
                       placeholder="••••••••" autofocus required>
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-lg fw-semibold">
                <i class="bi bi-box-arrow-in-right me-2"></i>Accéder au tableau de bord
            </button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ══════════════════════════ TABLEAU DE BORD ═══════════════════════════ -->

<!-- En-tête -->
<div class="header-bar d-flex justify-content-between align-items-center">
    <div>
        <h1><i class="bi bi-speedometer2 me-2"></i>Gestion des résultats</h1>
        <small>Université de Lubumbashi — UNILO</small>
    </div>
    <div class="d-flex align-items-center gap-3">
        <a href="<?php echo htmlspecialchars($publicUrl); ?>" target="_blank"
           class="btn btn-sm btn-outline-light">
            <i class="bi bi-box-arrow-up-right me-1"></i>Page publique
        </a>
        <a href="?logout=1" class="btn btn-sm btn-outline-light">
            <i class="bi bi-box-arrow-right me-1"></i>Déconnexion
        </a>
    </div>
</div>

<!-- Bandeau statut -->
<div class="status-banner alert mb-0 <?php echo $pub_active ? 'alert-success' : 'alert-danger'; ?>">
    <i class="bi <?php echo $pub_active ? 'bi-check-circle-fill' : 'bi-stop-circle-fill'; ?> me-2"></i>
    <?php if ($pub_active): ?>
        Les résultats sont <strong>actuellement en ligne</strong> — les étudiants peuvent consulter leurs notes.
    <?php else: ?>
        La consultation est <strong>suspendue</strong> — les étudiants voient le message de suspension.
    <?php endif; ?>
</div>

<div class="container py-4">

    <!-- Alertes actions -->
    <?php if ($actionSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($actionSuccess); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($actionError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($actionError); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($dbError): ?>
    <div class="alert alert-warning">
        <i class="bi bi-database-exclamation me-2"></i><?php echo htmlspecialchars($dbError); ?>
    </div>
    <?php endif; ?>

    <!-- ── Statistiques ─────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">
            <div class="stat-card card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#dbeafe; color:#1d4ed8;">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <div class="stat-value text-primary"><?php echo number_format($nb_etudiants); ?></div>
                        <div class="stat-label">Étudiants</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-card card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#dcfce7; color:#16a34a;">
                        <i class="bi bi-person-check-fill"></i>
                    </div>
                    <div>
                        <div class="stat-value text-success"><?php echo number_format($nb_inscriptions); ?></div>
                        <div class="stat-label">Inscriptions actives</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-card card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#fef9c3; color:#ca8a04;">
                        <i class="bi bi-calendar3"></i>
                    </div>
                    <div>
                        <div class="stat-value" style="font-size:1.3rem; color:#ca8a04;"><?php echo htmlspecialchars($annee_label); ?></div>
                        <div class="stat-label">Année académique</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-3">
            <div class="stat-card card h-100 p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon" style="background:#f3e8ff; color:#7c3aed;">
                        <i class="bi bi-arrow-repeat"></i>
                    </div>
                    <div>
                        <div class="stat-value" style="font-size:1rem; color:#7c3aed;"><?php echo htmlspecialchars($derniere_sync_fmt); ?></div>
                        <div class="stat-label">Dernière sync</div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── Actions publication ──────────────────────────────────────────── -->
    <h5 class="fw-bold mb-3"><i class="bi bi-broadcast-pin me-2 text-primary"></i>Publication des résultats</h5>
    <div class="row g-3 mb-4">

        <!-- Publier -->
        <div class="col-md-6">
            <div class="action-card card h-100">
                <div class="card-body d-flex flex-column">
                    <h6 class="card-title text-success">
                        <i class="bi bi-check-circle-fill me-2"></i>Mettre en ligne
                    </h6>
                    <p class="card-text text-muted small flex-grow-1">
                        Rend la page de consultation accessible à tous les étudiants.
                        Vérifiez que la synchronisation des données a été effectuée avant de publier.
                    </p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="publish">
                        <button type="submit" class="btn btn-success w-100 fw-semibold"
                                <?php echo $pub_active ? 'disabled' : ''; ?>>
                            <i class="bi bi-broadcast me-2"></i>Publier les résultats
                        </button>
                    </form>
                    <?php if ($pub_active): ?>
                    <small class="text-success mt-2"><i class="bi bi-check2"></i> Déjà en ligne</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Suspendre -->
        <div class="col-md-6">
            <div class="action-card card h-100">
                <div class="card-body d-flex flex-column">
                    <h6 class="card-title text-danger">
                        <i class="bi bi-pause-circle-fill me-2"></i>Suspendre la consultation
                    </h6>
                    <p class="card-text text-muted small">
                        Bloque l'accès public et affiche un message aux étudiants.
                    </p>
                    <form method="post" class="flex-grow-1 d-flex flex-column">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="suspend">
                        <div class="mb-3 flex-grow-1">
                            <label for="message_suspension" class="form-label small fw-semibold">Message affiché aux étudiants</label>
                            <textarea id="message_suspension" name="message_suspension"
                                      class="form-control form-control-sm" rows="2"><?php echo htmlspecialchars($pub_message); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger w-100 fw-semibold"
                                <?php echo !$pub_active ? 'disabled' : ''; ?>>
                            <i class="bi bi-pause-circle me-2"></i>Suspendre les résultats
                        </button>
                    </form>
                    <?php if (!$pub_active): ?>
                    <small class="text-danger mt-2"><i class="bi bi-pause2"></i> Déjà suspendu</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- ── Synchronisation des données ─────────────────────────────────── -->
    <h5 class="fw-bold mb-3"><i class="bi bi-arrow-repeat me-2 text-primary"></i>Synchronisation des données</h5>

    <?php if ($canSync): ?>
    <!-- Mode local : bouton de synchro disponible -->
    <div class="action-card card mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h6 class="fw-bold mb-1">
                        <i class="bi bi-pc-display me-2 text-primary"></i>Pousser les données locales vers le serveur
                    </h6>
                    <p class="text-muted small mb-0">
                        Exporte les tables de la base locale et les importe sur
                        <code>solution.unilo.cd</code>. Les fichiers PHP/CSS modifiés
                        doivent être re-uploadés manuellement via FTP.
                    </p>
                </div>
                <div class="col-md-4 mt-3 mt-md-0">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="sync_now">
                        <button type="submit" class="btn btn-primary w-100 fw-semibold">
                            <i class="bi bi-cloud-upload me-2"></i>Synchroniser maintenant
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($syncOutput)): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h6 class="fw-bold mb-3"><i class="bi bi-terminal-fill me-2"></i>Journal de synchronisation</h6>
            <div class="log-terminal"><?php echo htmlspecialchars(implode("\n", $syncOutput)); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- Mode en ligne : instructions de synchronisation -->
    <div class="action-card card mb-4">
        <div class="card-body">
            <div class="sync-info-box">
                <div class="d-flex gap-3 align-items-start">
                    <div class="stat-icon flex-shrink-0" style="background:#e0f2fe; color:#0284c7; width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
                        <i class="bi bi-info-circle-fill"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1">Synchronisation depuis votre ordinateur</h6>
                        <p class="text-muted small mb-2">
                            La synchronisation doit être lancée depuis votre machine locale (Windows / WAMP).
                            Ouvrez <strong>PowerShell</strong> et exécutez la commande suivante :
                        </p>
                        <code class="d-block bg-dark text-light rounded p-2 small user-select-all">powershell -ExecutionPolicy Bypass -File "C:\wamp64\www\acadenique\deployment\sync_vers_cpanel.ps1"</code>
                        <p class="text-muted small mt-2 mb-1">
                            <i class="bi bi-lightbulb me-1 text-warning"></i>
                            Si PowerShell refuse, faites un clic droit sur PowerShell → <em>Exécuter en tant qu'administrateur</em>.
                        </p>
                        <p class="text-muted small mt-2 mb-0">
                            <i class="bi bi-clock me-1"></i>
                            Dernière synchronisation reçue : <strong><?php echo htmlspecialchars($derniere_sync_fmt); ?></strong>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Lien vers la page publique ──────────────────────────────────── -->
    <div class="sync-info-box d-flex align-items-center justify-content-between gap-3">
        <div>
            <strong>Page de consultation publique</strong>
            <div class="text-muted small"><?php echo htmlspecialchars($publicUrl); ?></div>
        </div>
        <a href="<?php echo htmlspecialchars($publicUrl); ?>" target="_blank" class="btn btn-outline-primary btn-sm flex-shrink-0">
            <i class="bi bi-box-arrow-up-right me-1"></i>Ouvrir
        </a>
    </div>

</div><!-- /container -->

<?php endif; /* isAuth */ ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
