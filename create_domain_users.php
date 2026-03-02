<?php
/**
 * Script pour créer les utilisateurs de test pour chaque domaine
 */

session_start();
require_once 'includes/db_config.php';
require_once 'includes/auth.php';
require_once 'includes/domain_filter.php';

echo "<h2>🏫 Création des utilisateurs de domaine</h2>";

// Se connecter comme admin pour créer les utilisateurs
$stmt = $pdo->prepare("SELECT username FROM t_utilisateur WHERE username = 'admin'");
$stmt->execute();
if ($stmt->fetch()) {
    $_SESSION['user_id'] = 'admin';
    $_SESSION['nom_complet'] = 'Administrateur';
    $_SESSION['role'] = 'administrateur';
} else {
    echo "<p style='color: red;'>❌ Impossible de se connecter en tant qu'admin</p>";
    exit;
}

// Liste des utilisateurs à créer
$usersToCreate = [
    [
        'username' => 'informatique',
        'nom_complet' => 'Coordinateur Informatique',
        'password' => 'info123',
        'role' => 'coordinateur',
        'domaine' => 'Sciences et Technologies'
    ],
    [
        'username' => 'economie',
        'nom_complet' => 'Coordinateur Économie',
        'password' => 'eco123',
        'role' => 'coordinateur',
        'domaine' => 'Sciences Economiques et de Gestion'
    ],
    [
        'username' => 'agronomie',
        'nom_complet' => 'Coordinateur Agronomie',
        'password' => 'agro123',
        'role' => 'coordinateur',
        'domaine' => 'Sciences Agronomiques et Environnement'
    ],
    [
        'username' => 'psychologie',
        'nom_complet' => 'Coordinateur Psychologie',
        'password' => 'psy123',
        'role' => 'coordinateur',
        'domaine' => 'Sciences Psychologiques et de l\'Education'
    ],
    [
        'username' => 'droit',
        'nom_complet' => 'Coordinateur Droit',
        'password' => 'droit123',
        'role' => 'coordinateur',
        'domaine' => 'Sciences Juridiques, Politiques et Administratives'
    ]
];

echo "<h3>📝 Utilisateurs à créer :</h3>";

try {
    $pdo->beginTransaction();
    
    foreach ($usersToCreate as $user) {
        // Vérifier si l'utilisateur existe déjà
        $stmt = $pdo->prepare("SELECT username FROM t_utilisateur WHERE username = ?");
        $stmt->execute([$user['username']]);
        
        if ($stmt->fetch()) {
            echo "<p>⚠️ L'utilisateur <strong>{$user['username']}</strong> existe déjà.</p>";
            continue;
        }
        
        // Créer l'utilisateur
        $hashedPassword = password_hash($user['password'], PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO t_utilisateur (username, nom_complet, motdepasse, role) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['username'],
            $user['nom_complet'],
            $hashedPassword,
            $user['role']
        ]);
        
        echo "<div style='background: #d4edda; color: #155724; padding: 10px; margin: 5px 0; border-radius: 3px;'>";
        echo "<p>✅ <strong>{$user['username']}</strong> créé avec succès</p>";
        echo "<p><strong>Nom :</strong> {$user['nom_complet']}</p>";
        echo "<p><strong>Mot de passe :</strong> {$user['password']}</p>";
        echo "<p><strong>Domaine autorisé :</strong> {$user['domaine']}</p>";
        echo "</div>";
    }
    
    $pdo->commit();
    echo "<hr>";
    echo "<div style='background: #d1ecf1; color: #0c5460; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
    echo "<h4>🎉 Création terminée !</h4>";
    echo "<p>Tous les utilisateurs de domaine ont été créés avec succès.</p>";
    echo "</div>";
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "<p style='color: red;'>❌ Erreur lors de la création : " . $e->getMessage() . "</p>";
}

// Afficher le mapping des domaines
echo "<h3>🗺️ Mapping des utilisateurs et domaines :</h3>";
$domainMapping = getUserDomainMappingInfo();

echo "<table style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
echo "<tr style='background: #f8f9fa;'>";
echo "<th style='border: 1px solid #dee2e6; padding: 8px;'>Nom d'utilisateur</th>";
echo "<th style='border: 1px solid #dee2e6; padding: 8px;'>Mot de passe</th>";
echo "<th style='border: 1px solid #dee2e6; padding: 8px;'>Code domaine</th>";
echo "<th style='border: 1px solid #dee2e6; padding: 8px;'>Nom du domaine</th>";
echo "</tr>";

foreach ($usersToCreate as $user) {
    $domainInfo = $domainMapping[$user['username']];
    echo "<tr>";
    echo "<td style='border: 1px solid #dee2e6; padding: 8px;'><strong>{$user['username']}</strong></td>";
    echo "<td style='border: 1px solid #dee2e6; padding: 8px;'>{$user['password']}</td>";
    echo "<td style='border: 1px solid #dee2e6; padding: 8px;'><span style='background: #007bff; color: white; padding: 2px 6px; border-radius: 3px;'>{$domainInfo['code_domaine']}</span></td>";
    echo "<td style='border: 1px solid #dee2e6; padding: 8px;'>{$domainInfo['nom_domaine']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";

echo "<h3>🧪 Instructions de test :</h3>";
echo "<ol>";
echo "<li><strong>Déconnectez-vous</strong> de votre session actuelle</li>";
echo "<li><strong>Connectez-vous</strong> avec l'un des comptes créés :</li>";
echo "<ul>";
foreach ($usersToCreate as $user) {
    echo "<li><strong>{$user['username']}</strong> / <strong>{$user['password']}</strong> → Voir uniquement {$user['domaine']}</li>";
}
echo "</ul>";
echo "<li><strong>Vérifiez</strong> que seul le domaine correspondant est visible sur le dashboard</li>";
echo "<li><strong>Testez</strong> l'accès direct aux autres domaines (doit être refusé)</li>";
echo "</ol>";

echo "<div style='background: #fff3cd; color: #856404; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
echo "<h4>💡 Comment ça fonctionne :</h4>";
echo "<ul>";
echo "<li><strong>Filtrage automatique</strong> : Chaque utilisateur ne voit que son domaine dans la liste</li>";
echo "<li><strong>Protection d'accès</strong> : Impossible d'accéder directement à un autre domaine via URL</li>";
echo "<li><strong>Admin exception</strong> : L'utilisateur 'admin' voit tous les domaines</li>";
echo "<li><strong>Basé sur le nom d'utilisateur</strong> : Le système utilise le nom d'utilisateur pour déterminer le domaine autorisé</li>";
echo "</ul>";
echo "</div>";

// Liens de test
echo "<div style='text-align: center; margin: 20px 0;'>";
echo "<a href='logout.php' style='background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 5px;'>Se déconnecter</a>";
echo "<a href='index.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 5px;'>Aller au dashboard</a>";
echo "</div>";

?>
<style>
body { font-family: Arial, sans-serif; margin: 20px; max-width: 1000px; }
h2, h3, h4 { color: #333; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #dee2e6; padding: 8px; text-align: left; }
th { background-color: #f8f9fa; }
</style>