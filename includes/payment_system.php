<?php
/**
 * Système de paiement Mobile Money & Carte bancaire pour ACADENIQUE
 * Supporte : Airtel Money, Vodacom M-Pesa, Orange Money, Africell, VISA, Mastercard
 * Mode test (sandbox) inclus
 */

if (!defined('PAYMENT_SYSTEM_INCLUDED')) {
    define('PAYMENT_SYSTEM_INCLUDED', true);

    define('PAYMENT_STATUS_PENDING', 'pending');
    define('PAYMENT_STATUS_SUCCESS', 'success');
    define('PAYMENT_STATUS_FAILED', 'failed');
    define('PAYMENT_STATUS_CANCELLED', 'cancelled');

    define('PAYMENT_AMOUNT', 183.86);
    define('PAYMENT_CURRENCY', 'USD');

    define('PAYMENT_PROVIDERS', [
        'airtel'   => ['name' => 'Airtel Money',    'prefix' => ['097', '099'], 'type' => 'mobile'],
        'vodacom'  => ['name' => 'Vodacom M-Pesa',  'prefix' => ['081', '082'], 'type' => 'mobile'],
        'orange'   => ['name' => 'Orange Money',     'prefix' => ['084', '085'], 'type' => 'mobile'],
        'africell' => ['name' => 'Africell Money',   'prefix' => ['090', '091'], 'type' => 'mobile'],
        'visa'     => ['name' => 'VISA',             'prefix' => [],             'type' => 'card'],
        'mastercard' => ['name' => 'Mastercard',     'prefix' => [],             'type' => 'card'],
    ]);

    // =====================================================
    // BOOTSTRAP : création table paiements
    // =====================================================

    function paymentBootstrap(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS t_license_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reference VARCHAR(50) NOT NULL UNIQUE,
                phone VARCHAR(20) NOT NULL DEFAULT '',
                provider VARCHAR(20) NOT NULL,
                card_last4 VARCHAR(4) DEFAULT NULL,
                card_holder VARCHAR(100) DEFAULT NULL,
                amount DECIMAL(10,2) NOT NULL,
                currency VARCHAR(5) NOT NULL DEFAULT 'USD',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                gateway_ref VARCHAR(100) DEFAULT NULL,
                user_id VARCHAR(50) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                paid_at DATETIME DEFAULT NULL,
                INDEX idx_status (status),
                INDEX idx_reference (reference)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Ajout colonnes carte bancaire si absentes (migration)
        $cols = $pdo->query("SHOW COLUMNS FROM t_license_payments")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('card_last4', $cols)) {
            $pdo->exec("ALTER TABLE t_license_payments ADD COLUMN card_last4 VARCHAR(4) DEFAULT NULL AFTER provider");
        }
        if (!in_array('card_holder', $cols)) {
            $pdo->exec("ALTER TABLE t_license_payments ADD COLUMN card_holder VARCHAR(100) DEFAULT NULL AFTER card_last4");
        }

        // Config par défaut : mode test activé
        if (paymentConfigGet($pdo, 'payment_mode') === null) {
            paymentConfigSet($pdo, 'payment_mode', 'test');
        }
        if (paymentConfigGet($pdo, 'payment_gateway_merchant') === null) {
            paymentConfigSet($pdo, 'payment_gateway_merchant', '');
        }
        if (paymentConfigGet($pdo, 'payment_gateway_token') === null) {
            paymentConfigSet($pdo, 'payment_gateway_token', '');
        }
    }

    // =====================================================
    // CONFIG helpers (réutilise t_configuration)
    // =====================================================

    function paymentConfigGet(PDO $pdo, string $key, ?string $default = null): ?string {
        $stmt = $pdo->prepare('SELECT valeur FROM t_configuration WHERE cle = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return $default;
        }
        return (string)$value;
    }

    function paymentConfigSet(PDO $pdo, string $key, ?string $value): void {
        $stmt = $pdo->prepare(
            'INSERT INTO t_configuration (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)'
        );
        $stmt->execute([$key, $value ?? '']);
    }

    // =====================================================
    // GÉNÉRATION RÉFÉRENCE UNIQUE
    // =====================================================

    function paymentGenerateReference(): string {
        return 'PAY-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('ymd');
    }

    // =====================================================
    // VALIDATION NUMÉRO TÉLÉPHONE
    // =====================================================

    function paymentValidatePhone(string $phone): string {
        // Nettoyage
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // +243xxx → 0xxx
        if (str_starts_with($phone, '+243')) {
            $phone = '0' . substr($phone, 4);
        }
        // 243xxx → 0xxx
        if (str_starts_with($phone, '243') && strlen($phone) === 12) {
            $phone = '0' . substr($phone, 3);
        }

        return $phone;
    }

    function paymentDetectProvider(string $phone): ?string {
        $phone = paymentValidatePhone($phone);
        $prefix = substr($phone, 0, 3);

        foreach (PAYMENT_PROVIDERS as $code => $provider) {
            if (in_array($prefix, $provider['prefix'], true)) {
                return $code;
            }
        }
        return null;
    }

    // =====================================================
    // INITIER UN PAIEMENT
    // =====================================================

    function paymentInitiate(PDO $pdo, string $phone, string $provider, string $userId, array $cardData = []): array {
        $providerInfo = PAYMENT_PROVIDERS[$provider] ?? null;

        // Validation provider
        if (!$providerInfo) {
            return ['success' => false, 'message' => 'Moyen de paiement non supporte.'];
        }

        $isCard = $providerInfo['type'] === 'card';

        if ($isCard) {
            // Validation carte
            $cardNumber = preg_replace('/\s+/', '', $cardData['number'] ?? '');
            $cardHolder = trim($cardData['holder'] ?? '');
            $cardExpiry = trim($cardData['expiry'] ?? '');
            $cardCvv = trim($cardData['cvv'] ?? '');

            if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19 || !ctype_digit($cardNumber)) {
                return ['success' => false, 'message' => 'Numero de carte invalide.'];
            }
            if ($cardHolder === '') {
                return ['success' => false, 'message' => 'Nom du titulaire requis.'];
            }
            if (!preg_match('/^\d{2}\/\d{2}$/', $cardExpiry)) {
                return ['success' => false, 'message' => 'Date d expiration invalide (format: MM/AA).'];
            }
            if (strlen($cardCvv) < 3 || strlen($cardCvv) > 4 || !ctype_digit($cardCvv)) {
                return ['success' => false, 'message' => 'Code CVV invalide.'];
            }
            $phone = '';
        } else {
            // Validation Mobile Money
            $phone = paymentValidatePhone($phone);
            if (strlen($phone) < 10 || strlen($phone) > 13) {
                return ['success' => false, 'message' => 'Numero de telephone invalide. Format: 09XXXXXXXX'];
            }
        }

        // Vérifier s'il y a déjà un paiement réussi
        $existingSuccess = paymentGetLastSuccessful($pdo);
        if ($existingSuccess) {
            return ['success' => false, 'message' => 'Un paiement a deja ete valide. Vous pouvez demander votre cle d activation.'];
        }

        // Vérifier s'il y a un paiement pending récent (< 10 min)
        $pending = paymentGetPending($pdo);
        if ($pending) {
            $createdAt = strtotime($pending['created_at']);
            $elapsed = time() - $createdAt;
            if ($elapsed < 600) {
                $remaining = 600 - $elapsed;
                return [
                    'success' => false,
                    'message' => 'Un paiement est deja en cours (ref: ' . $pending['reference'] . '). Attendez ' . ceil($remaining / 60) . ' min ou verifiez le statut.',
                    'payment' => $pending,
                ];
            }
            // Expirer l'ancien paiement
            paymentUpdateStatus($pdo, $pending['id'], PAYMENT_STATUS_FAILED);
        }

        $reference = paymentGenerateReference();
        $mode = paymentConfigGet($pdo, 'payment_mode', 'test');

        // Insertion en base
        $cardLast4 = null;
        $cardHolderSafe = null;
        if ($isCard) {
            $cardLast4 = substr($cardNumber, -4);
            $cardHolderSafe = $cardHolder;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO t_license_payments (reference, phone, provider, amount, currency, status, user_id, card_last4, card_holder) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $reference,
            $phone,
            $provider,
            PAYMENT_AMOUNT,
            PAYMENT_CURRENCY,
            PAYMENT_STATUS_PENDING,
            $userId,
            $cardLast4,
            $cardHolderSafe,
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        if ($mode === 'test') {
            $methodLabel = $isCard ? ('carte ' . PAYMENT_PROVIDERS[$provider]['name'] . ' ****' . $cardLast4) : PAYMENT_PROVIDERS[$provider]['name'];
            return [
                'success' => true,
                'message' => 'Paiement initie via ' . $methodLabel . '. Reference: ' . $reference . '. Confirmez en cliquant sur "Confirmer le paiement".',
                'payment_id' => $paymentId,
                'reference' => $reference,
                'test_mode' => true,
            ];
        }

        // MODE PRODUCTION : appel API gateway
        if ($isCard) {
            $gatewayResult = paymentCallCardGateway($pdo, $paymentId, $reference, $cardNumber, $cardHolder, $cardExpiry, $cardCvv);
        } else {
            $gatewayResult = paymentCallGateway($pdo, $paymentId, $reference, $phone, $provider);
        }
        if (!$gatewayResult['success']) {
            paymentUpdateStatus($pdo, $paymentId, PAYMENT_STATUS_FAILED);
            return ['success' => false, 'message' => 'Echec initiation paiement: ' . $gatewayResult['message']];
        }

        $methodLabel = $isCard ? ('carte ' . PAYMENT_PROVIDERS[$provider]['name']) : PAYMENT_PROVIDERS[$provider]['name'];
        return [
            'success' => true,
            'message' => 'Paiement initie via ' . $methodLabel . '. Reference: ' . $reference,
            'payment_id' => $paymentId,
            'reference' => $reference,
            'test_mode' => false,
        ];
    }

    // =====================================================
    // CONFIRMER PAIEMENT (mode test)
    // =====================================================

    function paymentConfirmTest(PDO $pdo, string $reference): array {
        $mode = paymentConfigGet($pdo, 'payment_mode', 'test');
        if ($mode !== 'test') {
            return ['success' => false, 'message' => 'La confirmation manuelle n est disponible qu en mode test.'];
        }

        $payment = paymentGetByReference($pdo, $reference);
        if (!$payment) {
            return ['success' => false, 'message' => 'Paiement introuvable (ref: ' . $reference . ').'];
        }

        if ($payment['status'] === PAYMENT_STATUS_SUCCESS) {
            return ['success' => true, 'message' => 'Ce paiement est deja confirme.'];
        }

        if ($payment['status'] !== PAYMENT_STATUS_PENDING) {
            return ['success' => false, 'message' => 'Ce paiement ne peut plus etre confirme (statut: ' . $payment['status'] . ').'];
        }

        paymentUpdateStatus($pdo, (int)$payment['id'], PAYMENT_STATUS_SUCCESS);
        $stmt = $pdo->prepare('UPDATE t_license_payments SET paid_at = NOW() WHERE id = ?');
        $stmt->execute([(int)$payment['id']]);

        // Envoi SMS de confirmation
        $payment['status'] = PAYMENT_STATUS_SUCCESS;
        $smsResult = paymentSendSmsConfirmation($pdo, $payment);
        $smsNote = $smsResult['success'] ? ' SMS de confirmation envoye.' : '';

        return ['success' => true, 'message' => 'Paiement confirme avec succes ! Vous pouvez maintenant demander votre cle d activation.' . $smsNote];
    }

    // =====================================================
    // VÉRIFIER STATUT PAIEMENT
    // =====================================================

    function paymentCheckStatus(PDO $pdo, string $reference): array {
        $payment = paymentGetByReference($pdo, $reference);
        if (!$payment) {
            return ['success' => false, 'message' => 'Paiement introuvable.', 'status' => null];
        }

        $mode = paymentConfigGet($pdo, 'payment_mode', 'test');

        // En mode production, vérifier auprès du gateway
        if ($mode !== 'test' && $payment['status'] === PAYMENT_STATUS_PENDING && !empty($payment['gateway_ref'])) {
            $gatewayStatus = paymentCheckGatewayStatus($pdo, $payment);
            if ($gatewayStatus === PAYMENT_STATUS_SUCCESS) {
                paymentUpdateStatus($pdo, (int)$payment['id'], PAYMENT_STATUS_SUCCESS);
                $stmt = $pdo->prepare('UPDATE t_license_payments SET paid_at = NOW() WHERE id = ?');
                $stmt->execute([(int)$payment['id']]);
                $payment['status'] = PAYMENT_STATUS_SUCCESS;
                // Envoi SMS de confirmation
                paymentSendSmsConfirmation($pdo, $payment);
            } elseif ($gatewayStatus === PAYMENT_STATUS_FAILED) {
                paymentUpdateStatus($pdo, (int)$payment['id'], PAYMENT_STATUS_FAILED);
                $payment['status'] = PAYMENT_STATUS_FAILED;
            }
        }

        $statusLabels = [
            PAYMENT_STATUS_PENDING => 'En attente de confirmation',
            PAYMENT_STATUS_SUCCESS => 'Paye',
            PAYMENT_STATUS_FAILED => 'Echoue',
            PAYMENT_STATUS_CANCELLED => 'Annule',
        ];

        return [
            'success' => true,
            'status' => $payment['status'],
            'label' => $statusLabels[$payment['status']] ?? $payment['status'],
            'payment' => $payment,
        ];
    }

    // =====================================================
    // QUERIES
    // =====================================================

    function paymentGetByReference(PDO $pdo, string $reference): ?array {
        $stmt = $pdo->prepare('SELECT * FROM t_license_payments WHERE reference = ?');
        $stmt->execute([$reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function paymentGetLastSuccessful(PDO $pdo): ?array {
        $stmt = $pdo->prepare('SELECT * FROM t_license_payments WHERE status = ? ORDER BY paid_at DESC LIMIT 1');
        $stmt->execute([PAYMENT_STATUS_SUCCESS]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function paymentGetPending(PDO $pdo): ?array {
        $stmt = $pdo->prepare('SELECT * FROM t_license_payments WHERE status = ? ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([PAYMENT_STATUS_PENDING]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    function paymentUpdateStatus(PDO $pdo, int $id, string $status): void {
        $stmt = $pdo->prepare('UPDATE t_license_payments SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    function paymentHasValidPayment(PDO $pdo): bool {
        $payment = paymentGetLastSuccessful($pdo);
        return $payment !== null;
    }

    // =====================================================
    // GATEWAY API (production) — à configurer
    // =====================================================

    function paymentCallGateway(PDO $pdo, int $paymentId, string $reference, string $phone, string $provider): array {
        $merchant = paymentConfigGet($pdo, 'payment_gateway_merchant', '');
        $token = paymentConfigGet($pdo, 'payment_gateway_token', '');

        if ($merchant === '' || $token === '') {
            return ['success' => false, 'message' => 'Parametres gateway non configures (merchant/token).'];
        }

        // Exemple d'intégration Flexpay (à adapter selon votre gateway)
        $apiUrl = 'https://backend.flexpay.cd/api/rest/v1/paymentService';
        $body = json_encode([
            'merchant' => $merchant,
            'type' => '1',
            'phone' => $phone,
            'reference' => $reference,
            'amount' => (string)PAYMENT_AMOUNT,
            'currency' => PAYMENT_CURRENCY,
            'callbackUrl' => '',
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Erreur reseau: ' . $curlError];
        }

        $data = json_decode($response, true);
        if ($httpCode === 200 && isset($data['code']) && $data['code'] === '0') {
            // Stocker la référence gateway
            $orderNumber = $data['orderNumber'] ?? '';
            $stmt = $pdo->prepare('UPDATE t_license_payments SET gateway_ref = ? WHERE id = ?');
            $stmt->execute([$orderNumber, $paymentId]);
            return ['success' => true, 'gateway_ref' => $orderNumber];
        }

        $msg = $data['message'] ?? ('HTTP ' . $httpCode);
        return ['success' => false, 'message' => $msg];
    }

    function paymentCheckGatewayStatus(PDO $pdo, array $payment): string {
        $merchant = paymentConfigGet($pdo, 'payment_gateway_merchant', '');
        $token = paymentConfigGet($pdo, 'payment_gateway_token', '');

        if ($merchant === '' || $token === '' || empty($payment['gateway_ref'])) {
            return PAYMENT_STATUS_PENDING;
        }

        $apiUrl = 'https://backend.flexpay.cd/api/rest/v1/check/' . urlencode($payment['gateway_ref']);
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return PAYMENT_STATUS_PENDING;
        }

        $data = json_decode($response, true);
        if (isset($data['transaction'])) {
            $txStatus = $data['transaction']['status'] ?? '';
            if ($txStatus === '0') {
                return PAYMENT_STATUS_SUCCESS;
            }
            if ($txStatus === '2') {
                return PAYMENT_STATUS_FAILED;
            }
        }

        return PAYMENT_STATUS_PENDING;
    }

    // =====================================================
    // GATEWAY CARTE BANCAIRE (production) — à configurer
    // =====================================================

    function paymentCallCardGateway(PDO $pdo, int $paymentId, string $reference, string $cardNumber, string $cardHolder, string $cardExpiry, string $cardCvv): array {
        $merchant = paymentConfigGet($pdo, 'payment_gateway_merchant', '');
        $token = paymentConfigGet($pdo, 'payment_gateway_token', '');

        if ($merchant === '' || $token === '') {
            return ['success' => false, 'message' => 'Parametres gateway non configures (merchant/token).'];
        }

        // Exemple d'intégration Stripe-like ou Flexpay card (à adapter)
        $apiUrl = 'https://backend.flexpay.cd/api/rest/v1/paymentService';
        $body = json_encode([
            'merchant' => $merchant,
            'type' => '2',
            'reference' => $reference,
            'amount' => (string)PAYMENT_AMOUNT,
            'currency' => PAYMENT_CURRENCY,
            'card' => [
                'number' => $cardNumber,
                'holder' => $cardHolder,
                'expiry' => $cardExpiry,
                'cvv' => $cardCvv,
            ],
            'callbackUrl' => '',
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'Erreur reseau: ' . $curlError];
        }

        $data = json_decode($response, true);
        if ($httpCode === 200 && isset($data['code']) && $data['code'] === '0') {
            $orderNumber = $data['orderNumber'] ?? '';
            $stmt = $pdo->prepare('UPDATE t_license_payments SET gateway_ref = ? WHERE id = ?');
            $stmt->execute([$orderNumber, $paymentId]);
            return ['success' => true, 'gateway_ref' => $orderNumber];
        }

        $msg = $data['message'] ?? ('HTTP ' . $httpCode);
        return ['success' => false, 'message' => $msg];
    }

    // Helper : détecter le type de provider
    function paymentIsCardProvider(string $provider): bool {
        return isset(PAYMENT_PROVIDERS[$provider]) && PAYMENT_PROVIDERS[$provider]['type'] === 'card';
    }

    // =====================================================
    // ENVOI SMS APRÈS PAIEMENT RÉUSSI
    // =====================================================

    define('SMS_DEST_NUMBER', '+243826647766');

    /**
     * Envoie un SMS de confirmation de paiement via Africa's Talking.
     * Le SMS est TOUJOURS envoyé (test ou prod) si l'API est configurée.
     */
    function paymentSendSmsConfirmation(PDO $pdo, array $payment): array {
        $providerInfo = PAYMENT_PROVIDERS[$payment['provider']] ?? null;
        $providerName = $providerInfo ? $providerInfo['name'] : $payment['provider'];

        // Détail du mode de paiement
        if (!empty($payment['card_last4'])) {
            $modePaiement = $providerName . ' ****' . $payment['card_last4'];
        } elseif (!empty($payment['phone'])) {
            $modePaiement = $providerName . ' ' . $payment['phone'];
        } else {
            $modePaiement = $providerName;
        }

        $montant = number_format((float)$payment['amount'], 2, ',', ' ') . ' ' . ($payment['currency'] ?? 'USD');
        $message = "Felicitations, votre paiement a reussi avec succes. Montant paye : {$montant}, mode de paiement : {$modePaiement}. Ref: {$payment['reference']}";

        // Log systématique
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Récupérer config Africa's Talking
        $atUsername = paymentConfigGet($pdo, 'at_username', '');
        $atApiKey   = paymentConfigGet($pdo, 'at_api_key', '');
        $atSenderId = paymentConfigGet($pdo, 'at_sender_id', '');
        $atEnvironment = paymentConfigGet($pdo, 'at_environment', 'sandbox');

        if (empty($atUsername) || empty($atApiKey)) {
            $logEntry = '[' . date('Y-m-d H:i:s') . '] SMS NON ENVOYE (Africa\'s Talking non configure) -> ' . SMS_DEST_NUMBER . "\n"
                      . 'Message: ' . $message . "\n"
                      . "Action: Configurez at_username et at_api_key dans la page d'activation (section admin).\n"
                      . str_repeat('-', 60) . "\n";
            @file_put_contents($logDir . '/sms_log.txt', $logEntry, FILE_APPEND | LOCK_EX);

            return ['success' => false, 'message' => 'Africa\'s Talking non configure. Allez dans les parametres admin pour saisir vos identifiants API.'];
        }

        // URL API selon l'environnement (sandbox ou production)
        $baseUrl = ($atEnvironment === 'sandbox')
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';

        // Construire les données POST (format form-urlencoded, requis par Africa's Talking)
        $postFields = [
            'username' => $atUsername,
            'to'       => SMS_DEST_NUMBER,
            'message'  => $message,
        ];
        if (!empty($atSenderId)) {
            $postFields['from'] = $atSenderId;
        }

        $ch = curl_init($baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postFields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'apiKey: ' . $atApiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Log du résultat
        $status = $curlError ? "ERREUR CURL: $curlError" : "HTTP $httpCode";
        $logEntry = '[' . date('Y-m-d H:i:s') . "] SMS Africa's Talking ({$atEnvironment}) -> " . SMS_DEST_NUMBER . "\n"
                  . 'Message: ' . $message . "\n"
                  . 'Statut: ' . $status . "\n"
                  . 'Reponse: ' . ($response ?: '(vide)') . "\n"
                  . str_repeat('-', 60) . "\n";
        @file_put_contents($logDir . '/sms_log.txt', $logEntry, FILE_APPEND | LOCK_EX);

        if ($curlError) {
            return ['success' => false, 'message' => 'Erreur envoi SMS: ' . $curlError];
        }

        if ($httpCode === 201 || $httpCode === 200) {
            // Vérifier la réponse JSON
            $jsonResponse = @json_decode($response, true);
            $recipients = $jsonResponse['SMSMessageData']['Recipients'] ?? [];
            if (!empty($recipients)) {
                $recipientStatus = $recipients[0]['status'] ?? 'Unknown';
                $recipientCost = $recipients[0]['cost'] ?? '';
                return ['success' => true, 'message' => "SMS envoye a " . SMS_DEST_NUMBER . " (statut: {$recipientStatus}, cout: {$recipientCost})."];
            }
            return ['success' => true, 'message' => 'SMS envoye a ' . SMS_DEST_NUMBER . '.'];
        }

        return ['success' => false, 'message' => "Echec envoi SMS (HTTP {$httpCode}). Verifiez vos identifiants Africa's Talking."];
    }

} // fin PAYMENT_SYSTEM_INCLUDED
