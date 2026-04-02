<?php

if (!defined('LICENSE_SYSTEM_INCLUDED')) {
    define('LICENSE_SYSTEM_INCLUDED', true);

    define('LICENSE_STATUS_ACTIVE', 'active');
    define('LICENSE_STATUS_EXPIRED', 'expired');
    define('LICENSE_TARGET_EMAIL', 'jeanmarie.ibanga@gmail.com');
    define('LICENSE_KEY_EXPIRY_HOURS', 24);
    define('LICENSE_KEY_RESEND_COOLDOWN_SECONDS', 300);

    function licenseSetLastMailError(PDO $pdo, string $message): void {
        licenseConfigSet($pdo, 'license_last_mail_error', $message);
    }

    function licenseClearLastMailError(PDO $pdo): void {
        licenseConfigSet($pdo, 'license_last_mail_error', '');
    }

    function licenseGetLastMailError(PDO $pdo): string {
        return (string)(licenseConfigGet($pdo, 'license_last_mail_error', '') ?? '');
    }

    function licenseGetSmtpConfig(PDO $pdo): array {
        return [
            'host' => trim((string)licenseConfigGet($pdo, 'license_smtp_host', '')),
            'port' => (int)(licenseConfigGet($pdo, 'license_smtp_port', '587') ?? '587'),
            'username' => trim((string)licenseConfigGet($pdo, 'license_smtp_username', '')),
            'password' => (string)licenseConfigGet($pdo, 'license_smtp_password', ''),
            'security' => strtolower(trim((string)licenseConfigGet($pdo, 'license_smtp_security', 'tls'))),
            'from_email' => trim((string)licenseConfigGet($pdo, 'license_smtp_from_email', '')),
            'from_name' => trim((string)licenseConfigGet($pdo, 'license_smtp_from_name', 'ACADENIQUE Activation')),
            'enabled' => licenseConfigGet($pdo, 'license_smtp_enabled', '0') === '1',
        ];
    }

    function licenseSaveSmtpConfig(PDO $pdo, array $data): array {
        $host = trim((string)($data['host'] ?? ''));
        $port = (int)($data['port'] ?? 0);
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $security = strtolower(trim((string)($data['security'] ?? 'tls')));
        $fromEmail = trim((string)($data['from_email'] ?? ''));
        $fromName = trim((string)($data['from_name'] ?? 'ACADENIQUE Activation'));
        $enabled = !empty($data['enabled']) ? '1' : '0';

        if (!in_array($security, ['tls', 'ssl', 'none'], true)) {
            return ['success' => false, 'message' => 'Type de securite SMTP invalide.'];
        }

        if ($enabled === '1') {
            if ($host === '' || $port <= 0 || $username === '' || $password === '') {
                return ['success' => false, 'message' => 'Parametres SMTP incomplets pour activer SMTP.'];
            }
            if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => 'Adresse expediteur SMTP invalide.'];
            }
        }

        licenseConfigSet($pdo, 'license_smtp_host', $host);
        licenseConfigSet($pdo, 'license_smtp_port', (string)$port);
        licenseConfigSet($pdo, 'license_smtp_username', $username);
        licenseConfigSet($pdo, 'license_smtp_password', $password);
        licenseConfigSet($pdo, 'license_smtp_security', $security);
        licenseConfigSet($pdo, 'license_smtp_from_email', $fromEmail);
        licenseConfigSet($pdo, 'license_smtp_from_name', $fromName);
        licenseConfigSet($pdo, 'license_smtp_enabled', $enabled);

        return ['success' => true, 'message' => 'Configuration SMTP enregistree.'];
    }

    function licenseSmtpExpect($stream, int $code, ?string &$serverResponse = null): bool {
        $response = '';
        while (($line = fgets($stream, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }
        $serverResponse = trim($response);
        return str_starts_with($response, (string)$code);
    }

    function licenseSmtpWrite($stream, string $command): bool {
        return fwrite($stream, $command . "\r\n") !== false;
    }

    function licenseSendViaSmtp(PDO $pdo, string $to, string $subject, string $html, ?string &$error = null): bool {
        $error = null;
        $cfg = licenseGetSmtpConfig($pdo);
        if (!$cfg['enabled']) {
            $error = 'SMTP non active dans la configuration.';
            return false;
        }

        $host = $cfg['host'];
        $port = $cfg['port'];
        $user = $cfg['username'];
        $pass = $cfg['password'];
        $security = $cfg['security'];

        if ($host === '' || $port <= 0 || $user === '' || $pass === '') {
            $error = 'Parametres SMTP incomplets (host/port/username/password).';
            return false;
        }

        if (!function_exists('stream_socket_client')) {
            $error = 'Fonction stream_socket_client indisponible dans PHP.';
            return false;
        }

        if (($security === 'tls' || $security === 'ssl') && !extension_loaded('openssl')) {
            $error = 'Extension openssl non active dans PHP (requise pour TLS/SSL).';
            return false;
        }

        $sslContext = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            ],
        ]);

        $transportHost = $security === 'ssl' ? 'ssl://' . $host : $host;
        $stream = @stream_socket_client($transportHost . ':' . $port, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $sslContext);
        if (!$stream) {
            $error = 'Connexion SMTP impossible vers ' . $host . ':' . $port . ' (' . $security . '): ' . $errstr . ' (' . $errno . ').';
            return false;
        }

        stream_set_timeout($stream, 30);

        $srvResp = null;
        if (!licenseSmtpExpect($stream, 220, $srvResp)) {
            $detail = $srvResp !== '' ? $srvResp : '(aucune reponse — timeout ou port/securite incompatibles)';
            $error = 'Serveur SMTP: reponse initiale invalide. Reponse: ' . $detail . '. Verifiez port=' . $port . ' avec securite=' . $security . '. Essayez: port 465+SSL ou port 587+STARTTLS.';
            fclose($stream);
            return false;
        }

        if (!licenseSmtpWrite($stream, 'EHLO localhost') || !licenseSmtpExpect($stream, 250, $srvResp)) {
            $error = 'Serveur SMTP: EHLO refuse. Reponse: ' . ($srvResp ?: '(vide)');
            fclose($stream);
            return false;
        }

        if ($security === 'tls') {
            if (!licenseSmtpWrite($stream, 'STARTTLS') || !licenseSmtpExpect($stream, 220, $srvResp)) {
                $error = 'Serveur SMTP: STARTTLS refuse. Reponse: ' . ($srvResp ?: '(vide)') . '. Essayez securite=SSL avec port=465.';
                fclose($stream);
                return false;
            }

            if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                $error = 'Echec negociation TLS. Le serveur ne supporte peut-etre pas TLS. Essayez securite=SSL avec port=465.';
                fclose($stream);
                return false;
            }

            if (!licenseSmtpWrite($stream, 'EHLO localhost') || !licenseSmtpExpect($stream, 250, $srvResp)) {
                $error = 'Serveur SMTP: EHLO apres TLS refuse. Reponse: ' . ($srvResp ?: '(vide)');
                fclose($stream);
                return false;
            }
        }

        if (!licenseSmtpWrite($stream, 'AUTH LOGIN') || !licenseSmtpExpect($stream, 334, $srvResp)) {
            $error = 'Serveur SMTP: AUTH LOGIN refuse. Reponse: ' . ($srvResp ?: '(vide)');
            fclose($stream);
            return false;
        }

        if (!licenseSmtpWrite($stream, base64_encode($user)) || !licenseSmtpExpect($stream, 334, $srvResp)) {
            $error = 'Serveur SMTP: username refuse. Reponse: ' . ($srvResp ?: '(vide)');
            fclose($stream);
            return false;
        }

        if (!licenseSmtpWrite($stream, base64_encode($pass)) || !licenseSmtpExpect($stream, 235, $srvResp)) {
            $error = 'Authentification SMTP echouee. Reponse: ' . ($srvResp ?: '(vide)') . '. Verifiez username/mot de passe/app-password.';
            fclose($stream);
            return false;
        }

        $fromEmail = $cfg['from_email'] !== '' ? $cfg['from_email'] : $user;
        $fromName = $cfg['from_name'] !== '' ? $cfg['from_name'] : 'ACADENIQUE Activation';

        if (!licenseSmtpWrite($stream, 'MAIL FROM:<' . $fromEmail . '>') || !licenseSmtpExpect($stream, 250)) {
            $error = 'Serveur SMTP: MAIL FROM refuse.';
            fclose($stream);
            return false;
        }

        if (!licenseSmtpWrite($stream, 'RCPT TO:<' . $to . '>') || !licenseSmtpExpect($stream, 250)) {
            $error = 'Serveur SMTP: RCPT TO refuse pour le destinataire.';
            fclose($stream);
            return false;
        }

        if (!licenseSmtpWrite($stream, 'DATA') || !licenseSmtpExpect($stream, 354)) {
            $error = 'Serveur SMTP: DATA refuse.';
            fclose($stream);
            return false;
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?='
        ];

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $html . "\r\n.";

        if (!licenseSmtpWrite($stream, $data) || !licenseSmtpExpect($stream, 250)) {
            $error = 'Serveur SMTP: envoi du message refuse.';
            fclose($stream);
            return false;
        }

        licenseSmtpWrite($stream, 'QUIT');
        fclose($stream);

        return true;
    }

    function licenseNow(): string {
        return date('Y-m-d H:i:s');
    }

    function licenseConfigGet(PDO $pdo, string $key, ?string $default = null): ?string {
        $stmt = $pdo->prepare('SELECT valeur FROM t_configuration WHERE cle = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        return (string)$value;
    }

    function licenseConfigSet(PDO $pdo, string $key, ?string $value): void {
        $stmt = $pdo->prepare(
            'INSERT INTO t_configuration (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)'
        );
        $stmt->execute([$key, $value ?? '']);
    }

    function licenseBootstrap(PDO $pdo): void {
        $status = licenseConfigGet($pdo, 'license_status');
        if ($status === null || $status === '') {
            // Par défaut le système est expiré tant qu'une activation n'est pas validée.
            licenseConfigSet($pdo, 'license_status', LICENSE_STATUS_EXPIRED);
        }

        $targetEmail = licenseConfigGet($pdo, 'license_target_email');
        if ($targetEmail === null || $targetEmail === '') {
            licenseConfigSet($pdo, 'license_target_email', LICENSE_TARGET_EMAIL);
        }

        if (licenseConfigGet($pdo, 'license_smtp_port') === null) {
            licenseConfigSet($pdo, 'license_smtp_port', '587');
        }
        if (licenseConfigGet($pdo, 'license_smtp_security') === null) {
            licenseConfigSet($pdo, 'license_smtp_security', 'tls');
        }
        if (licenseConfigGet($pdo, 'license_smtp_from_name') === null) {
            licenseConfigSet($pdo, 'license_smtp_from_name', 'ACADENIQUE Activation');
        }
        if (licenseConfigGet($pdo, 'license_smtp_enabled') === null) {
            licenseConfigSet($pdo, 'license_smtp_enabled', '0');
        }
    }

    function isLicenseActive(PDO $pdo): bool {
        $status = licenseConfigGet($pdo, 'license_status', LICENSE_STATUS_EXPIRED);

        return $status === LICENSE_STATUS_ACTIVE;
    }

    function isLicenseActivationRequired(PDO $pdo): bool {
        return !isLicenseActive($pdo);
    }

    function licenseGenerateKey(int $groups = 5, int $charsPerGroup = 5): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $keyParts = [];

        for ($groupIndex = 0; $groupIndex < $groups; $groupIndex++) {
            $part = '';
            for ($charIndex = 0; $charIndex < $charsPerGroup; $charIndex++) {
                $randomIndex = random_int(0, strlen($alphabet) - 1);
                $part .= $alphabet[$randomIndex];
            }
            $keyParts[] = $part;
        }

        return implode('-', $keyParts);
    }

    function licenseNormalizeKey(string $key): string {
        $key = strtoupper(trim($key));
        $alnumOnly = preg_replace('/[^A-Z0-9]/', '', $key);
        if ($alnumOnly === null) {
            return '';
        }

        if (strlen($alnumOnly) !== 25) {
            return $key;
        }

        return implode('-', str_split($alnumOnly, 5));
    }

    function licenseSendViaPhpMailSafe(string $recipientEmail, string $subject, string $message, array $headers): bool {
        set_error_handler(static function () {
            return true;
        });

        try {
            $ok = mail($recipientEmail, $subject, $message, implode("\r\n", $headers));
        } finally {
            restore_error_handler();
        }

        return $ok === true;
    }

    function licenseSendActivationEmail(string $recipientEmail, string $activationKey, string $expiresAt): bool {
        global $pdo;

        $subject = 'Cle d activation ACADENIQUE';
        $message = "<html><body style='font-family: Arial, sans-serif;'>"
            . "<h2>Activation ACADENIQUE</h2>"
            . "<p>Voici votre cle d activation :</p>"
            . "<p style='font-size: 24px; font-weight: bold; letter-spacing: 2px;'>" . htmlspecialchars($activationKey, ENT_QUOTES, 'UTF-8') . "</p>"
            . "<p>Expiration de la cle : " . htmlspecialchars(date('d/m/Y H:i', strtotime($expiresAt)), ENT_QUOTES, 'UTF-8') . "</p>"
            . "<p>Utilisez cette cle dans l interface d activation pour debloquer le systeme.</p>"
            . "<hr><small>Message automatique ACADENIQUE</small>"
            . "</body></html>";

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ACADENIQUE <noreply@acadenique.local>'
        ];

        if (isset($pdo) && $pdo instanceof PDO) {
            $smtpConfig = licenseGetSmtpConfig($pdo);

            if ($smtpConfig['enabled']) {
                $smtpError = null;
                $smtpSent = licenseSendViaSmtp($pdo, $recipientEmail, $subject, $message, $smtpError);
                if ($smtpSent) {
                    licenseClearLastMailError($pdo);
                    return true;
                }

                // SMTP active mais echec: on tente un fallback silencieux.
                if (licenseSendViaPhpMailSafe($recipientEmail, $subject, $message, $headers)) {
                    licenseSetLastMailError($pdo, 'SMTP en echec mais fallback mail() utilise.');
                    return true;
                }

                licenseSetLastMailError($pdo, 'Echec SMTP: ' . ($smtpError ?? 'raison inconnue') . ' | Echec fallback mail() local.');
                return false;
            }

            if (licenseSendViaPhpMailSafe($recipientEmail, $subject, $message, $headers)) {
                licenseSetLastMailError($pdo, 'SMTP desactive: envoi realise via fallback mail().');
                return true;
            }

            licenseSetLastMailError($pdo, 'SMTP desactive et fallback mail() indisponible sur ce serveur.');
            return false;
        }

        return licenseSendViaPhpMailSafe($recipientEmail, $subject, $message, $headers);
    }

    function licenseSetStatusManually(PDO $pdo, string $newStatus, string $changedBy): array {
        if (!in_array($newStatus, [LICENSE_STATUS_ACTIVE, LICENSE_STATUS_EXPIRED], true)) {
            return ['success' => false, 'message' => 'Statut de licence invalide.'];
        }

        licenseConfigSet($pdo, 'license_status', $newStatus);
        licenseConfigSet($pdo, 'license_status_changed_at', licenseNow());
        licenseConfigSet($pdo, 'license_status_changed_by', $changedBy);

        if ($newStatus === LICENSE_STATUS_EXPIRED) {
            licenseConfigSet($pdo, 'license_activation_key_hash', '');
            licenseConfigSet($pdo, 'license_activation_key_expires_at', '');
        }

        return [
            'success' => true,
            'message' => $newStatus === LICENSE_STATUS_ACTIVE
                ? 'Licence activee manuellement.'
                : 'Licence desactivee: systeme repasse en mode expire.'
        ];
    }

    function licenseSendTestSmtp(PDO $pdo, string $to): array {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Adresse email de test invalide.'];
        }

        $subject = 'Test SMTP ACADENIQUE';
        $html = "<html><body style='font-family: Arial, sans-serif;'>"
            . "<h3>Test SMTP reussi</h3>"
            . "<p>Date: " . htmlspecialchars(date('d/m/Y H:i:s'), ENT_QUOTES, 'UTF-8') . "</p>"
            . "<p>Le transport SMTP authentifie est operationnel.</p>"
            . "</body></html>";

        $smtpError = null;
        $ok = licenseSendViaSmtp($pdo, $to, $subject, $html, $smtpError);
        if (!$ok) {
            return ['success' => false, 'message' => 'Echec SMTP: ' . ($smtpError ?? 'raison inconnue')];
        }

        return ['success' => true, 'message' => 'Email de test SMTP envoye a ' . $to . '.'];
    }

    function licenseRequestActivationKey(PDO $pdo, string $requestedBy): array {
        licenseBootstrap($pdo);

        if (isLicenseActive($pdo)) {
            return [
                'success' => false,
                'message' => 'Le systeme est deja active.'
            ];
        }

        $targetEmail = licenseConfigGet($pdo, 'license_target_email', LICENSE_TARGET_EMAIL) ?? LICENSE_TARGET_EMAIL;

        $lastSentAt = licenseConfigGet($pdo, 'license_activation_last_sent_at');
        if (!empty($lastSentAt)) {
            $elapsed = time() - strtotime($lastSentAt);
            if ($elapsed < LICENSE_KEY_RESEND_COOLDOWN_SECONDS) {
                $remaining = LICENSE_KEY_RESEND_COOLDOWN_SECONDS - $elapsed;
                return [
                    'success' => false,
                    'message' => 'Attendez encore ' . $remaining . ' seconde(s) avant un nouvel envoi.'
                ];
            }
        }

        $rawKey = licenseGenerateKey();
        $normalizedKey = licenseNormalizeKey($rawKey);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . LICENSE_KEY_EXPIRY_HOURS . ' hours'));

        licenseConfigSet($pdo, 'license_activation_key_hash', password_hash($normalizedKey, PASSWORD_DEFAULT));
        licenseConfigSet($pdo, 'license_activation_key_expires_at', $expiresAt);
        licenseConfigSet($pdo, 'license_activation_last_sent_at', licenseNow());
        licenseConfigSet($pdo, 'license_activation_last_requested_by', $requestedBy);

        $sent = licenseSendActivationEmail($targetEmail, $normalizedKey, $expiresAt);
        if (!$sent) {
            licenseConfigSet($pdo, 'license_activation_key_hash', '');
            licenseConfigSet($pdo, 'license_activation_key_expires_at', '');

            $lastError = licenseGetLastMailError($pdo);

            return [
                'success' => false,
                'message' => 'Echec de l envoi mail. ' . ($lastError !== '' ? $lastError : 'Configurez SMTP dans l interface Activation (admin), puis testez SMTP.')
            ];
        }

        return [
            'success' => true,
            'message' => 'Cle envoyee a ' . $targetEmail . '.'
        ];
    }

    function licenseActivateWithKey(PDO $pdo, string $inputKey, string $activatedBy): array {
        licenseBootstrap($pdo);

        if (isLicenseActive($pdo)) {
            return [
                'success' => true,
                'message' => 'Le systeme est deja active.'
            ];
        }

        $storedHash = licenseConfigGet($pdo, 'license_activation_key_hash', '');
        $expiresAt = licenseConfigGet($pdo, 'license_activation_key_expires_at', '');

        if (empty($storedHash) || empty($expiresAt)) {
            return [
                'success' => false,
                'message' => 'Aucune cle active. Veuillez d abord demander un envoi par mail.'
            ];
        }

        if (strtotime($expiresAt) < time()) {
            return [
                'success' => false,
                'message' => 'La cle a expire. Demandez une nouvelle cle.'
            ];
        }

        $normalizedInput = licenseNormalizeKey($inputKey);
        if (!password_verify($normalizedInput, $storedHash)) {
            return [
                'success' => false,
                'message' => 'Cle invalide.'
            ];
        }

        licenseConfigSet($pdo, 'license_status', LICENSE_STATUS_ACTIVE);
        licenseConfigSet($pdo, 'license_activated_at', licenseNow());
        licenseConfigSet($pdo, 'license_activated_by', $activatedBy);
        licenseConfigSet($pdo, 'license_activation_key_hash', '');
        licenseConfigSet($pdo, 'license_activation_key_expires_at', '');

        return [
            'success' => true,
            'message' => 'Activation validee avec succes.'
        ];
    }
}
