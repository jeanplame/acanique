<?php
function renderSidebar($domaine, $filieres) {
    include __DIR__ . '/../templates/sidebar.php';
    header('Content-Type: text/html; charset=UTF-8');
}

function renderMainContent($domaine, $inscriptions, $promotions, $mention_id, $promotion_code) {
    include __DIR__ . '/../templates/main-content.php';
}

function renderModals() {
    include __DIR__ . '/../templates/modals/add-mention.php';
    include __DIR__ . '/../templates/modals/add-promotion.php';
    include __DIR__ . '/../templates/modals/add-inscription.php';
    include __DIR__ . '/../templates/modals/add-filiere.php';
}
