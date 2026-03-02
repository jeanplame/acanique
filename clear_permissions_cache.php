<?php
// clear_permissions_cache.php
// Outil pour vider le cache des permissions

session_start();

echo "<!DOCTYPE html>";
echo "<html><head><title>Vider le Cache des Permissions</title>";
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">';
echo "</head><body>";

echo "<div class='container py-4'>";
echo "<h1><i class='bi bi-arrow-clockwise'></i> Gestion du Cache des Permissions</h1>";

// Informations sur le cache actuel
echo "<div class='card mb-4'>";
echo "<div class='card-header'><h3>📊 État actuel du cache</h3></div>";
echo "<div class='card-body'>";

if (isset($_SESSION['permissions_cache'])) {
    $cache_time = isset($_SESSION['permissions_cache_time']) ? $_SESSION['permissions_cache_time'] : 0;
    $age = time() - $cache_time;
    
    echo "<div class='alert alert-info'>";
    echo "<p><strong>Cache des permissions :</strong> ✅ Présent</p>";
    echo "<p><strong>Dernière mise à jour :</strong> " . date('Y-m-d H:i:s', $cache_time) . "</p>";
    echo "<p><strong>Âge du cache :</strong> $age secondes</p>";
    echo "<p><strong>Utilisateur :</strong> " . ($_SESSION['user_id'] ?? 'Non connecté') . "</p>";
    echo "</div>";
    
    echo "<h5>Contenu du cache :</h5>";
    echo "<pre style='max-height: 300px; overflow-y: auto; background: #f8f9fa; padding: 15px; border-radius: 5px;'>";
    echo htmlspecialchars(print_r($_SESSION['permissions_cache'], true));
    echo "</pre>";
    
} else {
    echo "<div class='alert alert-warning'>";
    echo "<p><strong>Cache des permissions :</strong> ❌ Absent</p>";
    echo "<p>Le cache sera créé lors de la prochaine vérification de permission.</p>";
    echo "</div>";
}

echo "</div></div>";

// Action de vidage du cache
echo "<div class='card mb-4'>";
echo "<div class='card-header'><h3>🗑️ Vider le cache</h3></div>";
echo "<div class='card-body'>";

if (isset($_POST['clear_cache'])) {
    // Vider le cache
    unset($_SESSION['permissions_cache']);
    unset($_SESSION['permissions_cache_time']);
    
    echo "<div class='alert alert-success'>";
    echo "<i class='bi bi-check-circle'></i> <strong>Cache vidé avec succès !</strong><br>";
    echo "Les permissions seront rechargées depuis la base de données lors de la prochaine vérification.";
    echo "</div>";
    
    echo "<script>setTimeout(function(){ window.location.reload(); }, 2000);</script>";
} else {
    echo "<p>Videz le cache pour forcer le rechargement des permissions depuis la base de données :</p>";
    
    echo "<form method='post'>";
    echo "<button type='submit' name='clear_cache' class='btn btn-warning'>";
    echo "<i class='bi bi-trash'></i> Vider le cache des permissions";
    echo "</button>";
    echo "</form>";
    
    echo "<div class='alert alert-info mt-3'>";
    echo "<i class='bi bi-info-circle'></i> <strong>Quand utiliser cet outil :</strong><br>";
    echo "• Après avoir modifié les permissions dans la base de données<br>";
    echo "• Si les changements de permissions ne sont pas pris en compte<br>";
    echo "• Pour tester immédiatement de nouvelles permissions<br>";
    echo "</div>";
}

echo "</div></div>";

// Outils de test
echo "<div class='card mb-4'>";
echo "<div class='card-header'><h3>🧪 Tests rapides</h3></div>";
echo "<div class='card-body'>";

if (isset($_SESSION['user_id'])) {
    require_once 'includes/db_config.php';
    require_once 'includes/auth.php';
    
    echo "<h5>Test des permissions actuelles :</h5>";
    
    $tests = [
        ['module' => 'Cours', 'perm' => 'S', 'desc' => 'Accès page domaine'],
        ['module' => 'Inscriptions', 'perm' => 'S', 'desc' => 'Onglet inscriptions'],
        ['module' => 'Cotes', 'perm' => 'S', 'desc' => 'Onglet notes'],
        ['module' => 'Utilisateurs', 'perm' => 'A', 'desc' => 'Administration'],
    ];
    
    foreach ($tests as $test) {
        $hasPermission = hasPermission($pdo, $_SESSION['user_id'], $test['module'], $test['perm']);
        $class = $hasPermission ? 'success' : 'danger';
        $icon = $hasPermission ? 'check-circle' : 'x-circle';
        $status = $hasPermission ? 'AUTORISÉ' : 'REFUSÉ';
        
        echo "<div class='alert alert-$class mb-2'>";
        echo "<i class='bi bi-$icon'></i> <strong>{$test['module']}-{$test['perm']}</strong> : $status - {$test['desc']}";
        echo "</div>";
    }
} else {
    echo "<div class='alert alert-warning'>";
    echo "Connectez-vous pour tester les permissions.";
    echo "</div>";
}

echo "</div></div>";

// Instructions
echo "<div class='card mb-4'>";
echo "<div class='card-header bg-info text-white'><h3>📋 Comment procéder</h3></div>";
echo "<div class='card-body'>";

echo "<h5>Pour appliquer des modifications de permissions :</h5>";
echo "<ol>";
echo "<li><strong>Modifiez les permissions</strong> dans phpMyAdmin ou votre interface de base de données</li>";
echo "<li><strong>Revenez sur cette page</strong> et cliquez sur \"Vider le cache des permissions\"</li>";
echo "<li><strong>Testez immédiatement</strong> vos modifications</li>";
echo "</ol>";

echo "<div class='alert alert-warning mt-3'>";
echo "<i class='bi bi-lightbulb'></i> <strong>Astuce :</strong> Vous pouvez aussi forcer le rechargement en vous déconnectant et reconnectant, mais cette méthode est plus rapide !";
echo "</div>";

echo "</div></div>";

// Liens de navigation
echo "<div class='text-center'>";
echo "<a href='index.php' class='btn btn-primary me-2'><i class='bi bi-house'></i> Accueil</a>";
echo "<a href='init_permissions_correct_structure.php' class='btn btn-info me-2'><i class='bi bi-gear'></i> Gestion permissions</a>";
echo "<a href='fix_user_permissions.php' class='btn btn-secondary me-2'><i class='bi bi-tools'></i> Diagnostic</a>";
echo "<a href='index.php?page=domaine&action=view&id=4' class='btn btn-success'><i class='bi bi-eye'></i> Tester page domaine</a>";
echo "</div>";

echo "</div>";
echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>';
echo "</body></html>";
?>