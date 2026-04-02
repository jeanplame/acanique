<?php
if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/db_config.php';
}
if (!function_exists('paymentBootstrap')) {
    require_once __DIR__ . '/../includes/payment_system.php';
}

if (!isset($activationMessage)) {
    $activationMessage = null;
}

$activationMessageType = 'info';
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'administrateur';

licenseBootstrap($pdo);
paymentBootstrap($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $activationMessage = 'Token de securite invalide.';
        $activationMessageType = 'danger';
    } else {
        $action = $_POST['activation_action'] ?? '';

        if ($action === 'pay_initiate') {
            $payPhone = trim($_POST['pay_phone'] ?? '');
            $payProvider = trim($_POST['pay_provider'] ?? '');
            $result = paymentInitiate($pdo, $payPhone, $payProvider, $_SESSION['user_id'] ?? 'inconnu');
            $activationMessage = $result['message'];
            $activationMessageType = $result['success'] ? 'success' : 'danger';
        } elseif ($action === 'pay_card_initiate') {
            $payProvider = trim($_POST['pay_card_type'] ?? '');
            $cardData = [
                'number' => $_POST['card_number'] ?? '',
                'holder' => $_POST['card_holder'] ?? '',
                'expiry' => $_POST['card_expiry'] ?? '',
                'cvv'    => $_POST['card_cvv'] ?? '',
            ];
            $result = paymentInitiate($pdo, '', $payProvider, $_SESSION['user_id'] ?? 'inconnu', $cardData);
            $activationMessage = $result['message'];
            $activationMessageType = $result['success'] ? 'success' : 'danger';
        } elseif ($action === 'pay_confirm_test') {
            $payRef = trim($_POST['pay_reference'] ?? '');
            $result = paymentConfirmTest($pdo, $payRef);
            $activationMessage = $result['message'];
            $activationMessageType = $result['success'] ? 'success' : 'danger';
        } elseif ($action === 'pay_check') {
            $payRef = trim($_POST['pay_reference'] ?? '');
            $result = paymentCheckStatus($pdo, $payRef);
            if ($result['success']) {
                $activationMessage = 'Statut du paiement: ' . $result['label'];
                $activationMessageType = $result['status'] === PAYMENT_STATUS_SUCCESS ? 'success' : 'info';
            } else {
                $activationMessage = $result['message'];
                $activationMessageType = 'danger';
            }
        } elseif ($action === 'send_key') {
            if (!paymentHasValidPayment($pdo)) {
                $activationMessage = 'Vous devez d abord effectuer le paiement avant de recevoir une cle d activation.';
                $activationMessageType = 'danger';
            } else {
                $result = licenseRequestActivationKey($pdo, $_SESSION['user_id'] ?? 'inconnu');
                $activationMessage = $result['message'];
                $activationMessageType = $result['success'] ? 'success' : 'danger';
            }
        } elseif ($action === 'save_smtp') {
            if (!$isAdmin) {
                $activationMessage = 'Action reservee a l administrateur.';
                $activationMessageType = 'danger';
            } else {
                $smtpData = [
                    'host' => $_POST['smtp_host'] ?? '',
                    'port' => $_POST['smtp_port'] ?? '587',
                    'username' => $_POST['smtp_username'] ?? '',
                    'password' => $_POST['smtp_password'] ?? '',
                    'security' => $_POST['smtp_security'] ?? 'tls',
                    'from_email' => $_POST['smtp_from_email'] ?? '',
                    'from_name' => $_POST['smtp_from_name'] ?? 'ACADENIQUE Activation',
                    'enabled' => isset($_POST['smtp_enabled']) ? '1' : '0',
                ];

                $result = licenseSaveSmtpConfig($pdo, $smtpData);
                $activationMessage = $result['message'];
                $activationMessageType = $result['success'] ? 'success' : 'danger';
            }
        } elseif ($action === 'test_smtp') {
            if (!$isAdmin) {
                $activationMessage = 'Action reservee a l administrateur.';
                $activationMessageType = 'danger';
            } else {
                $testEmail = trim($_POST['smtp_test_email'] ?? '');
                if ($testEmail === '') {
                    $testEmail = licenseConfigGet($pdo, 'license_target_email', LICENSE_TARGET_EMAIL) ?? LICENSE_TARGET_EMAIL;
                }

                $result = licenseSendTestSmtp($pdo, $testEmail);
                $activationMessage = $result['message'];
                $activationMessageType = $result['success'] ? 'success' : 'danger';
            }
        } elseif ($action === 'save_at_config') {
            if (!$isAdmin) {
                $activationMessage = 'Action reservee a l administrateur.';
                $activationMessageType = 'danger';
            } else {
                paymentConfigSet($pdo, 'at_username', trim($_POST['at_username'] ?? ''));
                paymentConfigSet($pdo, 'at_api_key', trim($_POST['at_api_key'] ?? ''));
                paymentConfigSet($pdo, 'at_sender_id', trim($_POST['at_sender_id'] ?? ''));
                paymentConfigSet($pdo, 'at_environment', ($_POST['at_environment'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox');
                $activationMessage = 'Configuration Africa\'s Talking enregistree.';
                $activationMessageType = 'success';
            }
        } elseif ($action === 'test_sms') {
            if (!$isAdmin) {
                $activationMessage = 'Action reservee a l administrateur.';
                $activationMessageType = 'danger';
            } else {
                // Simuler un paiement fictif pour tester l'envoi SMS
                $testPayment = [
                    'provider' => 'vodacom',
                    'phone' => SMS_DEST_NUMBER,
                    'card_last4' => '',
                    'card_holder' => '',
                    'amount' => PAYMENT_AMOUNT,
                    'currency' => PAYMENT_CURRENCY,
                    'reference' => 'TEST-SMS-' . date('YmdHis'),
                ];
                $smsResult = paymentSendSmsConfirmation($pdo, $testPayment);
                $activationMessage = $smsResult['message'];
                $activationMessageType = $smsResult['success'] ? 'success' : 'danger';
            }
        } elseif ($action === 'toggle_license') {
            if (!$isAdmin) {
                $activationMessage = 'Action reservee a l administrateur.';
                $activationMessageType = 'danger';
            } else {
                $newStatus = ($_POST['new_license_status'] ?? '') === 'active' ? LICENSE_STATUS_ACTIVE : LICENSE_STATUS_EXPIRED;
                $result = licenseSetStatusManually($pdo, $newStatus, $_SESSION['user_id'] ?? 'inconnu');
                $activationMessage = $result['message'];
                $activationMessageType = $result['success'] ? 'success' : 'danger';

                if ($result['success'] && $newStatus === LICENSE_STATUS_ACTIVE) {
                    header('Location: ?page=dashboard');
                    exit();
                }
            }
        } elseif ($action === 'activate') {
            $key = trim($_POST['activation_key'] ?? '');
            $result = licenseActivateWithKey($pdo, $key, $_SESSION['user_id'] ?? 'inconnu');
            $activationMessage = $result['message'];
            $activationMessageType = $result['success'] ? 'success' : 'danger';

            if ($result['success']) {
                header('Location: ?page=dashboard');
                exit();
            }
        }
    }
}

$targetEmail = licenseConfigGet($pdo, 'license_target_email', LICENSE_TARGET_EMAIL) ?? LICENSE_TARGET_EMAIL;
$lastSentAt = licenseConfigGet($pdo, 'license_activation_last_sent_at', '');
$keyExpiresAt = licenseConfigGet($pdo, 'license_activation_key_expires_at', '');
$currentLicenseStatus = licenseConfigGet($pdo, 'license_status', LICENSE_STATUS_EXPIRED) ?? LICENSE_STATUS_EXPIRED;
$smtpConfig = licenseGetSmtpConfig($pdo);
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');

// État du paiement
$hasPaid = paymentHasValidPayment($pdo);
$pendingPayment = paymentGetPending($pdo);
$lastPayment = paymentGetLastSuccessful($pdo);
$paymentMode = paymentConfigGet($pdo, 'payment_mode', 'test');
?>

<style>
    .act-page {
        min-height: 100vh;
        background: #f5f6f8;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 16px;
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    }

    .act-wrap {
        width: 100%;
        max-width: 520px;
    }

    .act-logo {
        text-align: center;
        margin-bottom: 32px;
    }

    .act-logo span {
        display: inline-block;
        font-size: 22px;
        font-weight: 700;
        color: #1a1a2e;
        letter-spacing: .5px;
    }

    .act-logo small {
        display: block;
        font-size: 12px;
        font-weight: 400;
        color: #8b8fa3;
        margin-top: 2px;
        letter-spacing: .3px;
    }

    .act-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e4e6ed;
    }

    .act-status {
        padding: 20px 28px;
        border-bottom: 1px solid #e4e6ed;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .act-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex-shrink: 0;
    }

    .act-dot--expired {
        background: #e74c3c;
        box-shadow: 0 0 0 3px rgba(231, 76, 60, .15);
    }

    .act-dot--active {
        background: #27ae60;
        box-shadow: 0 0 0 3px rgba(39, 174, 96, .15);
    }

    .act-status-text {
        font-size: 14px;
        color: #555;
    }

    .act-status-text strong {
        color: #1a1a2e;
    }

    .act-body {
        padding: 28px;
    }

    .act-price {
        text-align: center;
        padding: 24px 20px;
        margin-bottom: 24px;
        background: #fafbfc;
        border: 1px solid #ebedf2;
        border-radius: 8px;
    }

    .act-price-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: #8b8fa3;
        font-weight: 600;
        margin-bottom: 6px;
    }

    .act-price-amount {
        font-size: 42px;
        font-weight: 700;
        color: #1a1a2e;
        line-height: 1;
    }

    .act-price-amount sup {
        font-size: 18px;
        font-weight: 600;
        vertical-align: super;
        margin-left: 2px;
    }

    .act-price-desc {
        font-size: 13px;
        color: #8b8fa3;
        margin-top: 8px;
    }

    .act-steps {
        margin-bottom: 24px;
        padding: 20px;
        background: #fffcf5;
        border: 1px solid #f0e6d2;
        border-radius: 8px;
    }

    .act-steps-title {
        font-size: 13px;
        font-weight: 600;
        color: #9a7b2e;
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: .5px;
    }

    .act-steps ol {
        margin: 0;
        padding-left: 18px;
    }

    .act-steps li {
        font-size: 13.5px;
        color: #594a2a;
        line-height: 1.7;
    }

    .act-steps li strong {
        font-weight: 600;
    }

    .act-contact {
        margin-top: 14px;
        font-size: 12.5px;
        color: #9a7b2e;
    }

    .act-contact a {
        color: #6b5520;
        font-weight: 600;
        text-decoration: none;
    }

    .act-info {
        margin-bottom: 20px;
    }

    .act-info-row {
        display: flex;
        justify-content: space-between;
        padding: 7px 0;
        font-size: 13px;
        border-bottom: 1px solid #f2f3f5;
    }

    .act-info-row:last-child {
        border-bottom: none;
    }

    .act-info-row .lbl {
        color: #8b8fa3;
    }

    .act-info-row .val {
        color: #1a1a2e;
        font-weight: 500;
        text-align: right;
    }

    .act-msg {
        padding: 12px 16px;
        border-radius: 6px;
        font-size: 13.5px;
        margin-bottom: 20px;
    }

    .act-msg--success {
        background: #eafaf1;
        color: #1e7e46;
        border: 1px solid #c3edd4;
    }

    .act-msg--danger {
        background: #fef0f0;
        color: #c0392b;
        border: 1px solid #f5d0d0;
    }

    .act-msg--info {
        background: #eef6ff;
        color: #2471a3;
        border: 1px solid #c9e0f5;
    }

    .act-field {
        margin-bottom: 16px;
    }

    .act-field label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #555;
        text-transform: uppercase;
        letter-spacing: .5px;
        margin-bottom: 6px;
    }

    .act-input {
        width: 100%;
        padding: 12px 14px;
        font-size: 16px;
        font-family: 'Consolas', 'SF Mono', monospace;
        letter-spacing: 3px;
        text-align: center;
        border: 1.5px solid #d6d9e1;
        border-radius: 8px;
        background: #fafbfc;
        color: #1a1a2e;
        outline: none;
        transition: border-color .2s;
    }

    .act-input:focus {
        border-color: #3b5bdb;
        background: #fff;
    }

    .act-input::placeholder {
        color: #c5c8d4;
        letter-spacing: 2px;
        font-size: 14px;
    }

    .act-btn {
        display: block;
        width: 100%;
        padding: 13px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: background .2s, transform .1s;
    }

    .act-btn:active {
        transform: scale(.985);
    }

    .act-btn--primary {
        background: #1a1a2e;
        color: #fff;
    }

    .act-btn--primary:hover {
        background: #2d2d4a;
    }

    .act-btn--ghost {
        background: none;
        color: #8b8fa3;
        font-weight: 500;
        margin-top: 8px;
        font-size: 13px;
    }

    .act-btn--ghost:hover {
        color: #555;
    }

    .act-sep {
        border: none;
        border-top: 1px solid #ebedf2;
        margin: 24px 0;
    }

    .act-send {
        text-align: center;
        margin-bottom: 20px;
    }

    .act-send button {
        background: none;
        border: 1.5px solid #d6d9e1;
        border-radius: 8px;
        padding: 10px 20px;
        font-size: 13px;
        font-weight: 500;
        color: #555;
        cursor: pointer;
        transition: all .2s;
    }

    .act-send button:hover {
        border-color: #3b5bdb;
        color: #3b5bdb;
    }

    .act-admin {
        margin-top: 0;
        padding: 24px 28px;
        border-top: 1px solid #e4e6ed;
        background: #fafbfc;
        border-radius: 0 0 12px 12px;
    }

    .act-admin-title {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: #8b8fa3;
        font-weight: 600;
        margin-bottom: 16px;
    }

    .act-admin-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 12px;
    }

    .act-admin-field {
        margin-bottom: 10px;
    }

    .act-admin-field label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: #8b8fa3;
        text-transform: uppercase;
        letter-spacing: .4px;
        margin-bottom: 4px;
    }

    .act-admin-field input,
    .act-admin-field select {
        width: 100%;
        padding: 8px 10px;
        font-size: 13px;
        border: 1.5px solid #d6d9e1;
        border-radius: 6px;
        background: #fff;
        color: #1a1a2e;
        outline: none;
        transition: border-color .2s;
    }

    .act-admin-field input:focus,
    .act-admin-field select:focus {
        border-color: #3b5bdb;
    }

    .act-admin-check {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #555;
        margin: 12px 0 14px;
    }

    .act-admin-check input {
        width: 16px;
        height: 16px;
        accent-color: #3b5bdb;
    }

    .act-admin-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .act-admin-btn {
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 12.5px;
        font-weight: 600;
        cursor: pointer;
        border: 1.5px solid #d6d9e1;
        background: #fff;
        color: #555;
        transition: all .2s;
    }

    .act-admin-btn:hover {
        border-color: #3b5bdb;
        color: #3b5bdb;
    }

    .act-admin-btn--fill {
        background: #1a1a2e;
        color: #fff;
        border-color: #1a1a2e;
    }

    .act-admin-btn--fill:hover {
        background: #2d2d4a;
        border-color: #2d2d4a;
        color: #fff;
    }

    .act-admin-btn--warn {
        border-color: #e67e22;
        color: #e67e22;
    }

    .act-admin-btn--warn:hover {
        background: #e67e22;
        color: #fff;
    }

    .act-footer {
        text-align: center;
        margin-top: 24px;
        font-size: 12px;
        color: #b0b3c1;
    }

    .pay-tabs { display: flex; gap: 0; margin-bottom: 16px; border: 1.5px solid #d6d9e1; border-radius: 8px; overflow: hidden; }
    .pay-tab { flex: 1; padding: 10px 12px; text-align: center; font-size: 13px; font-weight: 600; cursor: pointer; background: #fafbfc; color: #8b8fa3; border: none; transition: all .2s; }
    .pay-tab:not(:last-child) { border-right: 1.5px solid #d6d9e1; }
    .pay-tab.active { background: #1a1a2e; color: #fff; }
    .pay-tab:hover:not(.active) { background: #eef0f4; color: #555; }
    .pay-panel { display: none; }
    .pay-panel.active { display: block; }
    .card-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .card-grid .full { grid-column: 1 / -1; }
</style>

<div class="act-page">
    <div class="act-wrap">

        <div class="act-logo">
            <span>ACADENIQUE</span>
            <small>Systeme de gestion academique</small>
        </div>

        <div class="act-card">

            <div class="act-status">
                <div class="act-dot <?= $currentLicenseStatus === LICENSE_STATUS_ACTIVE ? 'act-dot--active' : 'act-dot--expired' ?>"></div>
                <div class="act-status-text">
                    Base de donnees cloud <strong><?= $currentLicenseStatus === LICENSE_STATUS_ACTIVE ? 'active' : 'suspendue' ?></strong>
                </div>
            </div>

            <div class="act-body">

                <?php if ($activationMessage): ?>
                    <div class="act-msg act-msg--<?= htmlspecialchars($activationMessageType, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($activationMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($currentLicenseStatus !== LICENSE_STATUS_ACTIVE): ?>
                <div class="act-msg act-msg--danger" style="text-align: center; padding: 18px 20px; font-size: 14px; line-height: 1.6;">
                    <strong style="display: block; font-size: 15px; margin-bottom: 6px;">Acces aux donnees suspendu</strong>
                    Votre periode d essai gratuit du systeme de gestion de base de donnees cloud a expire, ou la limite de donnees gratuites a ete atteinte. Pour continuer a acceder a vos donnees et utiliser la plateforme, veuillez renouveler votre abonnement annuel.
                </div>
                <?php endif; ?>

                <div class="act-price">
                    <div class="act-price-label">Abonnement annuel &mdash; SGBD Cloud</div>
                    <div class="act-price-amount">183,86<sup>$</sup></div>
                    <div class="act-price-desc">Valable 1 an &middot; Acces complet aux donnees &middot; Support inclus &middot; Mises a jour</div>
                </div>
                    
                <div class="act-steps">
                    <div class="act-steps-title">Procedure d activation</div>
                    <ol>
                        <li>Effectuez le paiement de <strong>183,86 $</strong> via <strong>Mobile Money</strong> ou <strong>Carte bancaire</strong> ci-dessous.</li>
                        <li>Confirmez la transaction sur votre telephone.</li>
                        <li>Une fois le paiement valide, demandez votre <strong>cle d activation</strong>.</li>
                        <li>Saisissez la cle pour reactiver l acces a la base de donnees.</li>
                    </ol>
                    <div class="act-contact">Assistance : <a href="mailto:jeanmarie.ibanga@gmail.com">jeanmarie.ibanga@gmail.com</a></div>
                </div>

                <!-- ====== ETAPE 1 : PAIEMENT ====== -->
                <div style="margin-bottom: 24px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: <?= $hasPaid ? '#27ae60' : '#1a1a2e' ?>; color: #fff; font-size: 13px; font-weight: 700; flex-shrink: 0;">1</span>
                        <span style="font-size: 14px; font-weight: 600; color: #1a1a2e;">Paiement
                            <?php if ($hasPaid): ?>
                                <span style="color: #27ae60; font-weight: 500; font-size: 12px; margin-left: 6px;">&#10003; Paye</span>
                            <?php endif; ?>
                            
                        </span>
                    </div>

                    <?php if (!$hasPaid): ?>
                        <?php if ($pendingPayment): ?>
                            <!-- Paiement en attente -->
                            <?php
                                $pendingProvInfo = PAYMENT_PROVIDERS[$pendingPayment['provider']] ?? null;
                                $pendingLabel = $pendingProvInfo ? $pendingProvInfo['name'] : $pendingPayment['provider'];
                                if (!empty($pendingPayment['card_last4'])) {
                                    $pendingLabel .= ' ****' . $pendingPayment['card_last4'];
                                } elseif (!empty($pendingPayment['phone'])) {
                                    $pendingLabel .= ' ' . $pendingPayment['phone'];
                                }
                            ?>
                            <div class="act-msg act-msg--info" style="margin-bottom: 14px;">
                                Paiement en attente &mdash; Ref: <strong><?= htmlspecialchars($pendingPayment['reference'], ENT_QUOTES, 'UTF-8') ?></strong>
                                &middot; <?= htmlspecialchars($pendingLabel, ENT_QUOTES, 'UTF-8') ?>
                            </div>

                            <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
                                <?php if ($paymentMode === 'test'): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="activation_action" value="pay_confirm_test">
                                    <input type="hidden" name="pay_reference" value="<?= htmlspecialchars($pendingPayment['reference'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="act-admin-btn act-admin-btn--fill">Confirmer le paiement</button>
                                </form>
                                <?php endif; ?>

                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="activation_action" value="pay_check">
                                    <input type="hidden" name="pay_reference" value="<?= htmlspecialchars($pendingPayment['reference'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="act-admin-btn">Verifier le statut</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <!-- Onglets Mobile Money / Carte bancaire -->
                            <div class="pay-tabs">
                                <button type="button" class="pay-tab active" onclick="paySwitch('mobile')">Mobile Money</button>
                                <button type="button" class="pay-tab" onclick="paySwitch('card')">Carte bancaire</button>
                            </div>

                            <!-- Panel Mobile Money -->
                            <div id="pay-panel-mobile" class="pay-panel active">
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="activation_action" value="pay_initiate">

                                    <div class="act-field" style="margin-bottom: 12px;">
                                        <label for="pay_provider">Operateur</label>
                                        <select id="pay_provider" name="pay_provider" style="width: 100%; padding: 11px 14px; font-size: 14px; border: 1.5px solid #d6d9e1; border-radius: 8px; background: #fafbfc; color: #1a1a2e; outline: none;" required>
                                            <option value="">-- Choisir l operateur --</option>
                                            <?php foreach (PAYMENT_PROVIDERS as $code => $prov): ?>
                                                <?php if ($prov['type'] === 'mobile'): ?>
                                                <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($prov['name'], ENT_QUOTES, 'UTF-8') ?> (<?= implode(', ', $prov['prefix']) ?>)</option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="act-field" style="margin-bottom: 14px;">
                                        <label for="pay_phone">Numero de telephone</label>
                                        <input type="tel" id="pay_phone" name="pay_phone" style="width: 100%; padding: 12px 14px; font-size: 16px; font-family: 'Consolas', 'SF Mono', monospace; letter-spacing: 1.5px; border: 1.5px solid #d6d9e1; border-radius: 8px; background: #fafbfc; color: #1a1a2e; outline: none; text-align: center;" placeholder="09XXXXXXXX" required maxlength="15" autocomplete="tel">
                                    </div>

                                    <button type="submit" class="act-btn act-btn--primary">Payer <?= number_format(PAYMENT_AMOUNT, 2, ',', ' ') ?> $ via Mobile Money</button>
                                </form>
                            </div>

                            <!-- Panel Carte bancaire -->
                            <div id="pay-panel-card" class="pay-panel">
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="activation_action" value="pay_card_initiate">

                                    <div class="act-field" style="margin-bottom: 12px;">
                                        <label for="pay_card_type">Type de carte</label>
                                        <select id="pay_card_type" name="pay_card_type" style="width: 100%; padding: 11px 14px; font-size: 14px; border: 1.5px solid #d6d9e1; border-radius: 8px; background: #fafbfc; color: #1a1a2e; outline: none;" required>
                                            <option value="visa">VISA</option>
                                            <option value="mastercard">Mastercard</option>
                                        </select>
                                    </div>

                                    <div class="card-grid">
                                        <div class="act-field full" style="margin-bottom: 10px;">
                                            <label for="card_number">Numero de carte</label>
                                            <input type="text" id="card_number" name="card_number" style="width: 100%; padding: 12px 14px; font-size: 16px; font-family: 'Consolas', 'SF Mono', monospace; letter-spacing: 2px; border: 1.5px solid #d6d9e1; border-radius: 8px; background: #fafbfc; color: #1a1a2e; outline: none; text-align: center;" placeholder="4000 0000 0000 0000" required maxlength="19" autocomplete="cc-number" inputmode="numeric">
                                        </div>

                                        <div class="act-field full" style="margin-bottom: 10px;">
                                            <label for="card_holder">Nom du titulaire</label>
                                            <input type="text" id="card_holder" name="card_holder" style="width: 100%; padding: 11px 14px; font-size: 14px; border: 1.5px solid #d6d9e1; border-radius: 8px; background: #fafbfc; color: #1a1a2e; outline: none; text-transform: uppercase;" placeholder="NOM PRENOM" required autocomplete="cc-name">
                                        </div>

                                        <div class="act-field" style="margin-bottom: 10px;">
                                            <label for="card_expiry">Expiration</label>
                                            <input type="text" id="card_expiry" name="card_expiry" style="width: 100%; padding: 11px 14px; font-size: 14px; font-family: 'Consolas', monospace; border: 1.5px solid #d6d9e1; border-radius: 8px; background: #fafbfc; color: #1a1a2e; outline: none; text-align: center;" placeholder="MM/AA" required maxlength="5" autocomplete="cc-exp" inputmode="numeric">
                                        </div>

                                        <div class="act-field" style="margin-bottom: 10px;">
                                            <label for="card_cvv">CVV</label>
                                            <input type="password" id="card_cvv" name="card_cvv" style="width: 100%; padding: 11px 14px; font-size: 14px; font-family: 'Consolas', monospace; border: 1.5px solid #d6d9e1; border-radius: 8px; background: #fafbfc; color: #1a1a2e; outline: none; text-align: center;" placeholder="123" required maxlength="4" autocomplete="cc-csc" inputmode="numeric">
                                        </div>
                                    </div>

                                    <button type="submit" class="act-btn act-btn--primary" style="margin-top: 4px;">Payer <?= number_format(PAYMENT_AMOUNT, 2, ',', ' ') ?> $ par carte</button>
                                </form>
                            </div>

                            <script>
                            function paySwitch(tab) {
                                document.querySelectorAll('.pay-tab').forEach(function(t) { t.classList.remove('active'); });
                                document.querySelectorAll('.pay-panel').forEach(function(p) { p.classList.remove('active'); });
                                document.getElementById('pay-panel-' + tab).classList.add('active');
                                event.target.classList.add('active');
                            }
                            // Formatage auto numéro de carte (espaces tous les 4 chiffres)
                            var cardInput = document.getElementById('card_number');
                            if (cardInput) {
                                cardInput.addEventListener('input', function(e) {
                                    var v = e.target.value.replace(/\s+/g, '').replace(/\D/g, '');
                                    e.target.value = v.replace(/(.{4})/g, '$1 ').trim();
                                });
                            }
                            // Formatage auto expiration (MM/AA)
                            var expInput = document.getElementById('card_expiry');
                            if (expInput) {
                                expInput.addEventListener('input', function(e) {
                                    var v = e.target.value.replace(/\D/g, '');
                                    if (v.length >= 2) v = v.substring(0,2) + '/' + v.substring(2,4);
                                    e.target.value = v;
                                });
                            }
                            </script>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="act-msg act-msg--success" style="margin-bottom: 0;">
                            Paiement valide le <?= htmlspecialchars(date('d/m/Y a H:i', strtotime($lastPayment['paid_at'])), ENT_QUOTES, 'UTF-8') ?>
                            &middot; Ref: <?= htmlspecialchars($lastPayment['reference'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if (!empty($lastPayment['card_last4'])): ?>
                                &middot; Carte ****<?= htmlspecialchars($lastPayment['card_last4'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <hr class="act-sep">

                <!-- ====== ETAPE 2 : DEMANDER LA CLÉ ====== -->
                <div style="margin-bottom: 24px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: <?= $hasPaid ? '#1a1a2e' : '#d6d9e1' ?>; color: #fff; font-size: 13px; font-weight: 700; flex-shrink: 0;">2</span>
                        <span style="font-size: 14px; font-weight: 600; color: <?= $hasPaid ? '#1a1a2e' : '#b0b3c1' ?>;">Recevoir la cle d activation</span>
                    </div>

                    <?php if ($hasPaid): ?>
                    <div class="act-info" style="margin-bottom: 14px;">
                        <div class="act-info-row">
                            <span class="lbl">Email de reception</span>
                            <span class="val"><?= htmlspecialchars($targetEmail, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php if (!empty($lastSentAt)): ?>
                        <div class="act-info-row">
                            <span class="lbl">Dernier envoi</span>
                            <span class="val"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($lastSentAt)), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($keyExpiresAt)): ?>
                        <div class="act-info-row">
                            <span class="lbl">Expiration cle</span>
                            <span class="val"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($keyExpiresAt)), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="act-send">
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="activation_action" value="send_key">
                            <button type="submit">Envoyer une cle par email</button>
                        </form>
                    </div>
                    <?php else: ?>
                    <div style="text-align: center; padding: 16px; color: #b0b3c1; font-size: 13px;">
                        Effectuez le paiement ci-dessus pour debloquer cette etape.
                    </div>
                    <?php endif; ?>
                </div>

                <hr class="act-sep">

                <!-- ====== ETAPE 3 : ACTIVER ====== -->
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                        <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: <?= $hasPaid ? '#1a1a2e' : '#d6d9e1' ?>; color: #fff; font-size: 13px; font-weight: 700; flex-shrink: 0;">3</span>
                        <span style="font-size: 14px; font-weight: 600; color: <?= $hasPaid ? '#1a1a2e' : '#b0b3c1' ?>;">Activer la licence</span>
                    </div>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="activation_action" value="activate">
                        <div class="act-field">
                            <label for="activation_key">Cle d activation</label>
                            <input type="text" class="act-input" id="activation_key" name="activation_key" placeholder="XXXXX-XXXXX-XXXXX-XXXXX-XXXXX" required maxlength="29" autocomplete="off">
                        </div>
                        <button type="submit" class="act-btn act-btn--primary">Reactiver l acces</button>
                    </form>
                </div>

                <form method="post" style="text-align:center">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <a href="?page=logout" class="act-btn act-btn--ghost" style="display:inline-block; text-decoration:none;">Se deconnecter</a>
                </form>

            </div>

            <?php if ($isAdmin): ?>
                <div class="act-admin">
                    <div class="act-admin-title">Administration</div>

                    <form method="post" style="margin-bottom: 16px;">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="activation_action" value="toggle_license">
                        <?php if ($currentLicenseStatus === LICENSE_STATUS_ACTIVE): ?>
                            <input type="hidden" name="new_license_status" value="expired">
                            <button type="submit" class="act-admin-btn act-admin-btn--warn">Desactiver la licence</button>
                        <?php else: ?>
                            <input type="hidden" name="new_license_status" value="active">
                            <button type="submit" class="act-admin-btn act-admin-btn--fill">Activer manuellement</button>
                        <?php endif; ?>
                    </form>

                    <div class="act-admin-title" style="margin-top: 8px;">Configuration SMTP</div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="activation_action" value="save_smtp">

                        <div class="act-admin-grid">
                            <div class="act-admin-field">
                                <label for="smtp_host">Serveur</label>
                                <input type="text" id="smtp_host" name="smtp_host" value="<?= htmlspecialchars($smtpConfig['host'], ENT_QUOTES, 'UTF-8') ?>" placeholder="smtp.gmail.com">
                            </div>
                            <div class="act-admin-field">
                                <label for="smtp_port">Port</label>
                                <input type="number" id="smtp_port" name="smtp_port" value="<?= htmlspecialchars((string)$smtpConfig['port'], ENT_QUOTES, 'UTF-8') ?>" min="1" max="65535">
                            </div>
                            <div class="act-admin-field">
                                <label for="smtp_username">Utilisateur</label>
                                <input type="text" id="smtp_username" name="smtp_username" value="<?= htmlspecialchars($smtpConfig['username'], ENT_QUOTES, 'UTF-8') ?>" placeholder="compte@gmail.com">
                            </div>
                            <div class="act-admin-field">
                                <label for="smtp_password">Mot de passe</label>
                                <input type="password" id="smtp_password" name="smtp_password" value="<?= htmlspecialchars($smtpConfig['password'], ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="act-admin-field">
                                <label for="smtp_security">Securite</label>
                                <select id="smtp_security" name="smtp_security">
                                    <option value="tls" <?= $smtpConfig['security'] === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                                    <option value="ssl" <?= $smtpConfig['security'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                    <option value="none" <?= $smtpConfig['security'] === 'none' ? 'selected' : '' ?>>Aucune</option>
                                </select>
                            </div>
                            <div class="act-admin-field">
                                <label for="smtp_from_email">Email expediteur</label>
                                <input type="email" id="smtp_from_email" name="smtp_from_email" value="<?= htmlspecialchars($smtpConfig['from_email'], ENT_QUOTES, 'UTF-8') ?>" placeholder="noreply@domaine.com">
                            </div>
                        </div>
                        <div class="act-admin-field">
                            <label for="smtp_from_name">Nom expediteur</label>
                            <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= htmlspecialchars($smtpConfig['from_name'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <label class="act-admin-check">
                            <input type="checkbox" name="smtp_enabled" <?= $smtpConfig['enabled'] ? 'checked' : '' ?>>
                            Activer SMTP authentifie
                        </label>
                        <button type="submit" class="act-admin-btn act-admin-btn--fill">Enregistrer</button>
                    </form>

                    <form method="post" style="margin-top: 12px; display: flex; gap: 8px; align-items: flex-end;">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="activation_action" value="test_smtp">
                        <div class="act-admin-field" style="flex: 1; margin-bottom: 0;">
                            <label for="smtp_test_email">Test SMTP</label>
                            <input type="email" id="smtp_test_email" name="smtp_test_email" placeholder="email de test (optionnel)">
                        </div>
                        <button type="submit" class="act-admin-btn" style="white-space: nowrap;">Tester</button>
                    </form>

                    <div class="act-admin-title" style="margin-top: 20px;">Configuration SMS &mdash; Africa's Talking</div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="activation_action" value="save_at_config">

                        <div class="act-admin-grid">
                            <div class="act-admin-field">
                                <label for="at_username">Username</label>
                                <input type="text" id="at_username" name="at_username" value="<?= htmlspecialchars(paymentConfigGet($pdo, 'at_username', ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="sandbox (ou votre username)">
                            </div>
                            <div class="act-admin-field">
                                <label for="at_api_key">API Key</label>
                                <input type="text" id="at_api_key" name="at_api_key" value="<?= htmlspecialchars(paymentConfigGet($pdo, 'at_api_key', ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Votre cle API">
                            </div>
                            <div class="act-admin-field">
                                <label for="at_environment">Environnement</label>
                                <select id="at_environment" name="at_environment">
                                    <option value="sandbox" <?= paymentConfigGet($pdo, 'at_environment', 'sandbox') === 'sandbox' ? 'selected' : '' ?>>Sandbox (test)</option>
                                    <option value="live" <?= paymentConfigGet($pdo, 'at_environment', 'sandbox') === 'live' ? 'selected' : '' ?>>Production</option>
                                </select>
                            </div>
                            <div class="act-admin-field">
                                <label for="at_sender_id">Sender ID <small>(optionnel)</small></label>
                                <input type="text" id="at_sender_id" name="at_sender_id" value="<?= htmlspecialchars(paymentConfigGet($pdo, 'at_sender_id', ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="ACADENIQUE">
                            </div>
                        </div>
                        <p style="font-size: 11px; color: #777; margin: 8px 0 12px;">Destination SMS : <strong><?= SMS_DEST_NUMBER ?></strong> &mdash; <a href="https://africastalking.com" target="_blank" rel="noopener" style="color:#1a1a2e;">Creer un compte Africa's Talking</a></p>
                        <button type="submit" class="act-admin-btn act-admin-btn--fill">Enregistrer SMS</button>
                    </form>

                    <form method="post" style="margin-top: 12px;">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="activation_action" value="test_sms">
                        <button type="submit" class="act-admin-btn">Envoyer un SMS test</button>
                    </form>
                </div>
            <?php endif; ?>

        </div>

        <div class="act-footer">&copy; <?= date('Y') ?> ACADENIQUE &mdash; Tous droits reserves</div>

    </div>
</div>