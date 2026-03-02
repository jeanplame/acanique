<?php
    /**
     * Générateur de QR Code local
     * Utilise la bibliothèque PHP QR Code (http://phpqrcode.sourceforge.net/)
     * Cette version simplifiée génère directement une image PNG
     */

    // Inclusion de la bibliothèque phpqrcode si disponible
    // Si la bibliothèque n'est pas disponible, installez-la ou placez-la dans le même répertoire
    if (file_exists('phpqrcode/qrlib.php')) {
        require_once('phpqrcode/qrlib.php');
    } else {
        // Fallback: génération d'un QR code basique avec du texte
        header('Content-Type: text/html; charset=UTF-8');
        $image = imagecreate(100, 100);
        $bgColor = imagecolorallocate($image, 255, 255, 255);
        $textColor = imagecolorallocate($image, 0, 0, 0);
        imagestring($image, 5, 5, 40, 'QR Code', $textColor);
        imagepng($image);
        imagedestroy($image);
        exit;
    }

    // Paramètres
    $data = isset($_GET['data']) ? $_GET['data'] : 'QR Code';
    $size = isset($_GET['size']) ? intval($_GET['size']) : 100;
    $errorCorrectionLevel = 'L'; // L=Low, M=Medium, Q=Quartile, H=High

    // Génération du QR code
    header('Content-Type: text/html; charset=UTF-8');
    QRcode::png($data, null, $errorCorrectionLevel, min(max($size/10, 1), 10), 2);
    exit;
?>
