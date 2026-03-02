<?php
/**
 * Configuration des Notifications Email - Interface d'Administration
 * Page de configuration pour le système de notifications des sauvegardes
 */

session_start();

// Vérification de la session admin
if (!isset($_SESSION['username']) || $_SESSION['level'] != 'admin') {
    header('Location: login.php');
    exit();
}

require_once 'config.php';
require_once 'backup_notification_system.php';

$message = '';
$messageType = '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $config = [
            'backup_notifications_enabled' => isset($_POST['notifications_enabled']) ? 1 : 0,
            'backup_email_smtp_host' => $_POST['smtp_host'] ?? '',
            'backup_email_smtp_port' => (int)($_POST['smtp_port'] ?? 587),
            'backup_email_smtp_user' => $_POST['smtp_user'] ?? '',
            'backup_email_smtp_pass' => $_POST['smtp_pass'] ?? '',
            'backup_email_from' => $_POST['from_email'] ?? '',
            'backup_admin_emails' => $_POST['admin_emails'] ?? ''
        ];
        
        // Mise à jour des paramètres dans la base
        foreach ($config as $key => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO t_configuration (cle, valeur) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)
            ");
            $stmt->execute([$key, $value]);
        }
        
        // Test d'envoi si demandé
        if (isset($_POST['send_test']) && !empty($_POST['test_email'])) {
            $notifications = new BackupNotificationSystem();
            $testResult = $notifications->sendTestEmail($_POST['test_email']);
            
            if ($testResult) {
                $message = "Configuration sauvegardée et email de test envoyé avec succès !";
                $messageType = 'success';
            } else {
                $message = "Configuration sauvegardée mais échec de l'envoi de l'email de test.";
                $messageType = 'warning';
            }
        } else {
            $message = "Configuration des notifications sauvegardée avec succès !";
            $messageType = 'success';
        }
        
    } catch (Exception $e) {
        $message = "Erreur lors de la sauvegarde : " . $e->getMessage();
        $messageType = 'danger';
    }
}

// Charger la configuration actuelle
$currentConfig = [];
try {
    $stmt = $pdo->prepare("SELECT cle, valeur FROM t_configuration WHERE cle LIKE 'backup_%'");
    $stmt->execute();
    $currentConfig = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $currentConfig = [];
}

// Valeurs par défaut
$defaults = [
    'backup_notifications_enabled' => '0',
    'backup_email_smtp_host' => 'smtp.gmail.com',
    'backup_email_smtp_port' => '587',
    'backup_email_smtp_user' => '',
    'backup_email_smtp_pass' => '',
    'backup_email_from' => 'noreply@acadenique.com',
    'backup_admin_emails' => ''
];

foreach ($defaults as $key => $value) {
    if (!isset($currentConfig[$key])) {
        $currentConfig[$key] = $value;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuration Notifications - Acadenique</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .config-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .config-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }
        .config-section {
            border-left: 4px solid #0d6efd;
            padding-left: 20px;
            margin: 2rem 0;
        }
        .form-label {
            font-weight: 600;
            color: #495057;
        }
        .btn-test {
            background: linear-gradient(135deg, #28a745, #20c997);
            border: none;
            color: white;
        }
        .btn-test:hover {
            background: linear-gradient(135deg, #218838, #17a2b8);
            color: white;
        }
        .config-info {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            border-radius: 0 5px 5px 0;
            margin: 15px 0;
        }
        .password-toggle {
            cursor: pointer;
        }
    </style>
</head>
<body class="bg-light">

<div class="config-header">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1><i class="bi bi-envelope-gear"></i> Configuration des Notifications</h1>
                <p class="mb-0">Paramétrage des notifications email pour le système de sauvegarde</p>
            </div>
            <div class="col-md-4 text-end">
                <a href="backup_manager_admin.php" class="btn btn-light">
                    <i class="bi bi-arrow-left"></i> Retour aux Sauvegardes
                </a>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'x-circle') ?>"></i>
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="POST" class="needs-validation" novalidate>
        <div class="row">
            <!-- Configuration Générale -->
            <div class="col-md-12">
                <div class="card config-card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-gear"></i> Configuration Générale</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="notifications_enabled" 
                                   name="notifications_enabled" <?= $currentConfig['backup_notifications_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="notifications_enabled">
                                <strong>Activer les notifications email</strong>
                            </label>
                            <div class="form-text">Active ou désactive complètement le système de notifications</div>
                        </div>

                        <div class="config-info">
                            <h6><i class="bi bi-info-circle"></i> Types de Notifications</h6>
                            <ul class="mb-0">
                                <li><strong>Succès de sauvegarde :</strong> Envoyé après chaque sauvegarde réussie</li>
                                <li><strong>Échec de sauvegarde :</strong> Envoyé en cas d'erreur lors de la sauvegarde</li>
                                <li><strong>Rapport quotidien :</strong> Résumé quotidien des sauvegardes</li>
                                <li><strong>Alerte espace disque :</strong> Avertissement quand l'espace devient critique</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Configuration SMTP -->
            <div class="col-md-6">
                <div class="card config-card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-server"></i> Serveur SMTP</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="smtp_host" class="form-label">Serveur SMTP</label>
                            <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                   value="<?= htmlspecialchars($currentConfig['backup_email_smtp_host']) ?>"
                                   placeholder="smtp.gmail.com">
                            <div class="form-text">Adresse du serveur SMTP (ex: smtp.gmail.com, smtp.outlook.com)</div>
                        </div>

                        <div class="mb-3">
                            <label for="smtp_port" class="form-label">Port SMTP</label>
                            <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                   value="<?= htmlspecialchars($currentConfig['backup_email_smtp_port']) ?>"
                                   placeholder="587" min="1" max="65535">
                            <div class="form-text">Port SMTP (587 pour TLS, 465 pour SSL, 25 pour non-sécurisé)</div>
                        </div>

                        <div class="mb-3">
                            <label for="smtp_user" class="form-label">Nom d'utilisateur</label>
                            <input type="text" class="form-control" id="smtp_user" name="smtp_user" 
                                   value="<?= htmlspecialchars($currentConfig['backup_email_smtp_user']) ?>"
                                   placeholder="votre.email@gmail.com">
                            <div class="form-text">Adresse email ou nom d'utilisateur pour l'authentification SMTP</div>
                        </div>

                        <div class="mb-3">
                            <label for="smtp_pass" class="form-label">Mot de passe</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="smtp_pass" name="smtp_pass" 
                                       value="<?= htmlspecialchars($currentConfig['backup_email_smtp_pass']) ?>"
                                       placeholder="Mot de passe d'application">
                                <button class="btn btn-outline-secondary password-toggle" type="button" onclick="togglePassword('smtp_pass')">
                                    <i class="bi bi-eye" id="smtp_pass_icon"></i>
                                </button>
                            </div>
                            <div class="form-text">Mot de passe ou mot de passe d'application pour l'authentification</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Configuration des Destinataires -->
            <div class="col-md-6">
                <div class="card config-card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-people"></i> Destinataires</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="from_email" class="form-label">Email expéditeur</label>
                            <input type="email" class="form-control" id="from_email" name="from_email" 
                                   value="<?= htmlspecialchars($currentConfig['backup_email_from']) ?>"
                                   placeholder="noreply@acadenique.com">
                            <div class="form-text">Adresse email qui apparaîtra comme expéditeur</div>
                        </div>

                        <div class="mb-3">
                            <label for="admin_emails" class="form-label">Emails des administrateurs</label>
                            <textarea class="form-control" id="admin_emails" name="admin_emails" rows="4"
                                      placeholder="admin1@exemple.com&#10;admin2@exemple.com&#10;admin3@exemple.com"><?= htmlspecialchars($currentConfig['backup_admin_emails']) ?></textarea>
                            <div class="form-text">Une adresse email par ligne. Ces adresses recevront toutes les notifications</div>
                        </div>

                        <div class="config-info">
                            <h6><i class="bi bi-lightbulb"></i> Conseil Gmail</h6>
                            <p class="mb-0">Pour Gmail, utilisez un <strong>mot de passe d'application</strong> plutôt que votre mot de passe habituel. 
                            Activez l'authentification à 2 facteurs puis générez un mot de passe d'application dans les paramètres de sécurité.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test et Validation -->
            <div class="col-md-12">
                <div class="card config-card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-check2-circle"></i> Test de Configuration</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <label for="test_email" class="form-label">Email de test</label>
                                <input type="email" class="form-control" id="test_email" name="test_email" 
                                       placeholder="votre.email@exemple.com">
                                <div class="form-text">Adresse pour envoyer un email de test après la sauvegarde</div>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check me-3">
                                    <input class="form-check-input" type="checkbox" id="send_test" name="send_test">
                                    <label class="form-check-label" for="send_test">
                                        Envoyer un email de test
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Boutons d'action -->
        <div class="text-center mb-4">
            <button type="submit" class="btn btn-primary btn-lg me-3">
                <i class="bi bi-floppy"></i> Sauvegarder la Configuration
            </button>
            <a href="backup_manager_admin.php" class="btn btn-outline-secondary btn-lg">
                <i class="bi bi-x-circle"></i> Annuler
            </a>
        </div>
    </form>

    <!-- Guide de Configuration -->
    <div class="card config-card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-book"></i> Guide de Configuration Rapide</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <h6><i class="bi bi-google"></i> Gmail</h6>
                    <ul class="small">
                        <li>Serveur: smtp.gmail.com</li>
                        <li>Port: 587 (TLS)</li>
                        <li>Utilisateur: votre.email@gmail.com</li>
                        <li>Mot de passe: Mot de passe d'application</li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h6><i class="bi bi-microsoft"></i> Outlook/Hotmail</h6>
                    <ul class="small">
                        <li>Serveur: smtp-mail.outlook.com</li>
                        <li>Port: 587 (TLS)</li>
                        <li>Utilisateur: votre.email@outlook.com</li>
                        <li>Mot de passe: Votre mot de passe</li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h6><i class="bi bi-envelope"></i> Autres</h6>
                    <ul class="small">
                        <li>Consultez votre fournisseur email</li>
                        <li>Vérifiez les ports et protocoles</li>
                        <li>Testez avant de sauvegarder</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(inputId + '_icon');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

// Validation du formulaire
(function() {
    'use strict';
    window.addEventListener('load', function() {
        var forms = document.getElementsByClassName('needs-validation');
        Array.prototype.filter.call(forms, function(form) {
            form.addEventListener('submit', function(event) {
                if (form.checkValidity() === false) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    }, false);
})();

// Toggle des sections basé sur l'activation des notifications
document.getElementById('notifications_enabled').addEventListener('change', function() {
    const isEnabled = this.checked;
    const configSections = document.querySelectorAll('.card-body input, .card-body textarea');
    
    configSections.forEach(element => {
        if (element.id !== 'notifications_enabled') {
            element.disabled = !isEnabled;
        }
    });
});

// Appliquer l'état initial
document.addEventListener('DOMContentLoaded', function() {
    const notificationsEnabled = document.getElementById('notifications_enabled').checked;
    if (!notificationsEnabled) {
        const configSections = document.querySelectorAll('.card-body input, .card-body textarea');
        configSections.forEach(element => {
            if (element.id !== 'notifications_enabled') {
                element.disabled = true;
            }
        });
    }
});
</script>

</body>
</html>