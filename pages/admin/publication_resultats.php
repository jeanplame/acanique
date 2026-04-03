<?php
/**
 * Gestion de la publication en ligne des résultats.
 * Page réservée aux administrateurs.
 */

require_once 'includes/db_config.php';
require_once 'includes/auth.php';

requireLogin();

if (!hasRole('administrateur')) {
    header('Location: ?page=dashboard');
    exit();
}

$success = '';
$error = '';
$syncOutput = [];

function executeOnlineSync(): array {
    $scriptPath = realpath(__DIR__ . '/../../deployment/sync_vers_cpanel.ps1');

    if ($scriptPath === false || !file_exists($scriptPath)) {
        return [
            'success' => false,
            'message' => 'Script de synchronisation introuvable.',
            'output' => [],
        ];
    }

    if (!function_exists('exec')) {
        return [
            'success' => false,
            'message' => 'La fonction exec() est désactivée sur ce serveur local.',
            'output' => [],
        ];
    }

    $output = [];
    $exitCode = 1;
    $command = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($scriptPath) . ' 2>&1';

    exec($command, $output, $exitCode);

    return [
        'success' => $exitCode === 0,
        'message' => $exitCode === 0
            ? 'Synchronisation terminée avec succès.'
            : 'La synchronisation a échoué. Consultez le détail ci-dessous.',
        'output' => $output,
    ];
}

function upsertConfigValue(PDO $pdo, string $key, string $value): bool {
    $stmt = $pdo->prepare(
        "INSERT INTO t_configuration (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)"
    );
    return $stmt->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Jeton de sécurité invalide. Veuillez réessayer.";
    } else {
        try {
            if ($_POST['action'] === 'publish') {
                if (upsertConfigValue($pdo, 'resultats_publication_active', '1')) {
                    $success = "La consultation des résultats est maintenant en ligne.";
                } else {
                    $error = "Impossible de publier les résultats pour le moment.";
                }
            } elseif ($_POST['action'] === 'sync_now') {
                $syncResult = executeOnlineSync();
                $syncOutput = $syncResult['output'];

                if ($syncResult['success']) {
                    $success = $syncResult['message'];
                } else {
                    $error = $syncResult['message'];
                }
            } elseif ($_POST['action'] === 'suspend') {
                $message = trim($_POST['message_suspension'] ?? '');
                if ($message === '') {
                    $message = "La consultation des résultats est temporairement suspendue. Veuillez réessayer plus tard.";
                }

                $okActive = upsertConfigValue($pdo, 'resultats_publication_active', '0');
                $okMsg = upsertConfigValue($pdo, 'resultats_publication_message', $message);

                if ($okActive && $okMsg) {
                    $success = "La consultation des résultats a été suspendue.";
                } else {
                    $error = "Impossible de suspendre la consultation pour le moment.";
                }
            }
        } catch (Exception $e) {
            $error = "Erreur lors de la mise à jour: " . $e->getMessage();
        }
    }
}

$publicationActive = false;
$publicationMessage = "La consultation des résultats est temporairement suspendue. Veuillez réessayer plus tard.";

try {
    $stmt = $pdo->prepare("SELECT cle, valeur FROM t_configuration WHERE cle IN ('resultats_publication_active', 'resultats_publication_message')");
    $stmt->execute();
    $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (isset($configs['resultats_publication_active'])) {
        $publicationActive = $configs['resultats_publication_active'] === '1';
    } else {
        $publicationActive = false;
    }

    if (!empty($configs['resultats_publication_message'])) {
        $publicationMessage = $configs['resultats_publication_message'];
    }
} catch (Exception $e) {
    $error = "Erreur de lecture de la configuration: " . $e->getMessage();
}
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="bi bi-broadcast-pin me-2"></i>Publication des résultats en ligne</h2>
        <span class="badge <?php echo $publicationActive ? 'bg-success' : 'bg-danger'; ?>">
            <?php echo $publicationActive ? 'EN LIGNE' : 'SUSPENDU'; ?>
        </span>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">État actuel</h5>
            <p class="mb-2">
                URL publique: <a href="pages/consulter_resultat.php" target="_blank">pages/consulter_resultat.php</a>
            </p>
            <p class="mb-0 text-muted">
                <?php if ($publicationActive): ?>
                    Les étudiants peuvent consulter leurs résultats en ce moment.
                <?php else: ?>
                    Message actuellement affiché aux étudiants: <?php echo htmlspecialchars($publicationMessage); ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="card border-success h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title text-success"><i class="bi bi-cloud-check me-2"></i>Mettre en ligne</h5>
                    <p class="card-text">Autoriser immédiatement la consultation publique des résultats.</p>
                    <form method="post" class="mt-auto">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <input type="hidden" name="action" value="publish">
                        <button type="submit" class="btn btn-success w-100">Publier les résultats</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-primary h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title text-primary"><i class="bi bi-arrow-repeat me-2"></i>Synchroniser</h5>
                    <p class="card-text">Pousser immédiatement les données locales vers la plateforme en ligne.</p>
                    <form method="post" class="mt-auto">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <input type="hidden" name="action" value="sync_now">
                        <button type="submit" class="btn btn-primary w-100">Synchroniser les modifications</button>
                    </form>
                    <small class="text-muted mt-2">Cette action synchronise les données. Les fichiers PHP/CSS modifiés doivent toujours être re-uplodés manuellement.</small>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-danger h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title text-danger"><i class="bi bi-pause-circle me-2"></i>Suspendre la consultation</h5>
                    <p class="card-text">Bloquer l'accès public et afficher un message aux étudiants.</p>
                    <form method="post" class="mt-auto">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        <input type="hidden" name="action" value="suspend">
                        <label for="message_suspension" class="form-label">Message de suspension</label>
                        <textarea id="message_suspension" name="message_suspension" class="form-control mb-3" rows="3"><?php echo htmlspecialchars($publicationMessage); ?></textarea>
                        <button type="submit" class="btn btn-danger w-100">Suspendre les résultats</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($syncOutput)): ?>
        <div class="card shadow-sm mt-4">
            <div class="card-body">
                <h5 class="card-title mb-3"><i class="bi bi-terminal me-2"></i>Journal de synchronisation</h5>
                <pre class="mb-0" style="white-space: pre-wrap; background:#0f172a; color:#e2e8f0; padding:16px; border-radius:8px;"><?php echo htmlspecialchars(implode(PHP_EOL, $syncOutput)); ?></pre>
            </div>
        </div>
    <?php endif; ?>
</div>