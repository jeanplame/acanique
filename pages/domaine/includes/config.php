<?php
// Configuration globale
require_once 'includes/domaine_functions.php';
    header('Content-Type: text/html; charset=UTF-8');

// Fonction utilitaire pour générer les URLs de navigation
function buildUrl($params = []) {
    $baseParams = [
        'page' => 'domaine',
        'action' => 'view',
        'id' => $_GET['id']
    ];
    
    if (isset($_GET['mention'])) {
        $baseParams['mention'] = $_GET['mention'];
        $baseParams['mention_libelle'] = $_GET['mention_libelle'] ?? '';
    }
    
    if (isset($_GET['promotion'])) {
        $baseParams['promotion'] = $_GET['promotion'];
        $baseParams['promotion_nom'] = $_GET['promotion_nom'] ?? '';
    }
    
    $finalParams = array_merge($baseParams, $params);
    return '?' . http_build_query($finalParams);
}
