<?php
error_reporting(E_ALL);

// Test A : TCP sur 465 puis upgrade crypto manuellement
echo "A) TCP+crypto upgrade mail.unilo.cd:465 ... \n";
$ctx = stream_context_create(['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]]);
$s = @stream_socket_client('tcp://mail.unilo.cd:465', $e, $m, 15, STREAM_CLIENT_CONNECT, $ctx);
if (!$s) { echo "   TCP ECHEC: $m ($e)\n"; }
else {
    stream_set_timeout($s, 10);
    // Activer SSL manuellement
    $crypto = @stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_ANY_CLIENT);
    if ($crypto === false) {
        echo "   CRYPTO ECHEC. OpenSSL: " . openssl_error_string() . "\n";
    } elseif ($crypto === 0) {
        echo "   CRYPTO EN ATTENTE (non bloquant)\n";
    } else {
        echo "   CRYPTO OK!\n";
        $r = fgets($s, 515);
        echo "   REP: " . trim($r) . "\n";
    }
    fclose($s);
}

// Test B : TCP sur 587 lire banner puis STARTTLS
echo "\nB) TCP mail.unilo.cd:587 + STARTTLS ... \n";
$ctx2 = stream_context_create(['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]]);
$s2 = @stream_socket_client('tcp://mail.unilo.cd:587', $e, $m, 15, STREAM_CLIENT_CONNECT, $ctx2);
if (!$s2) { echo "   TCP ECHEC: $m ($e)\n"; }
else {
    stream_set_timeout($s2, 10);
    $banner = fgets($s2, 515);
    echo "   BANNER: " . trim($banner) . "\n";
    fwrite($s2, "EHLO localhost\r\n");
    $ehlo = '';
    while (($line = fgets($s2, 515)) !== false) {
        $ehlo .= $line;
        if (preg_match('/^\d{3}\s/', $line)) break;
    }
    echo "   EHLO:\n" . $ehlo;
    fwrite($s2, "STARTTLS\r\n");
    $stls = fgets($s2, 515);
    echo "   STARTTLS: " . trim($stls) . "\n";
    if (str_starts_with(trim($stls), '220')) {
        $crypto = @stream_socket_enable_crypto($s2, true, STREAM_CRYPTO_METHOD_ANY_CLIENT);
        echo "   CRYPTO: " . ($crypto ? 'OK!' : 'ECHEC - ' . openssl_error_string()) . "\n";
    }
    fclose($s2);
}

// Infos OpenSSL
echo "\nOpenSSL version: " . OPENSSL_VERSION_TEXT . "\n";
echo "FIN\n";
