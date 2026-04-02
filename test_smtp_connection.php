<?php
// Test de connexion SMTP vers mail.unilo.cd
echo "== Test connexion SMTP mail.unilo.cd ==\n\n";

$ctx = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ],
]);

// Test 1: SSL port 465
echo "1) ssl://mail.unilo.cd:465 ... ";
$s = @stream_socket_client('ssl://mail.unilo.cd:465', $e, $m, 30, STREAM_CLIENT_CONNECT, $ctx);
if (!$s) {
    echo "ECHEC: $m ($e)\n";
} else {
    stream_set_timeout($s, 10);
    $r = fgets($s, 515);
    echo "OK: " . trim($r) . "\n";
    fclose($s);
}

// Test 2: STARTTLS port 587
echo "2) mail.unilo.cd:587 (STARTTLS) ... ";
$s2 = @stream_socket_client('mail.unilo.cd:587', $e2, $m2, 30, STREAM_CLIENT_CONNECT, $ctx);
if (!$s2) {
    echo "ECHEC: $m2 ($e2)\n";
} else {
    stream_set_timeout($s2, 10);
    $r2 = fgets($s2, 515);
    echo "OK: " . trim($r2) . "\n";
    fclose($s2);
}

// Test 3: plain port 25
echo "3) mail.unilo.cd:25 ... ";
$s3 = @stream_socket_client('mail.unilo.cd:25', $e3, $m3, 30, STREAM_CLIENT_CONNECT, $ctx);
if (!$s3) {
    echo "ECHEC: $m3 ($e3)\n";
} else {
    stream_set_timeout($s3, 10);
    $r3 = fgets($s3, 515);
    echo "OK: " . trim($r3) . "\n";
    fclose($s3);
}

// Test 4: SSL sur unilo.cd directement (sans "mail.")
echo "4) ssl://unilo.cd:465 ... ";
$s4 = @stream_socket_client('ssl://unilo.cd:465', $e4, $m4, 30, STREAM_CLIENT_CONNECT, $ctx);
if (!$s4) {
    echo "ECHEC: $m4 ($e4)\n";
} else {
    stream_set_timeout($s4, 10);
    $r4 = fgets($s4, 515);
    echo "OK: " . trim($r4) . "\n";
    fclose($s4);
}

echo "\n== Fin ==\n";
