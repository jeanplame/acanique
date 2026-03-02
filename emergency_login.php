<?php
/**
 * Script d'urgence pour se connecter automatiquement comme admin
 * À utiliser si vous ne pouvez pas vous connecter normalement
 */

session_start();
require_once __DIR__ . '/includes/db_config.php';

echo "<h2>🚑 Connexion d'urgence - Administrateur</h2>";

// Vérifier si l'utilisateur admin existe
try {
    $stmt = $pdo->prepare("SELECT username, nom_complet, motdepasse, role FROM t_utilisateur WHERE username = 'admin'");
    $stmt->execute();
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin) {
        echo "<div style='color: green;'>✅ Utilisateur admin trouvé en base de données</div>";
        echo "<p><strong>Username:</strong> " . htmlspecialchars($admin['username']) . "</p>";
        echo "<p><strong>Nom:</strong> " . htmlspecialchars($admin['nom_complet']) . "</p>";
        echo "<p><strong>Rôle:</strong> " . htmlspecialchars($admin['role']) . "</p>";
        
        // Connexion automatique
        if (isset($_GET['connect']) && $_GET['connect'] === 'yes') {
            $_SESSION['user_id'] = $admin['username'];
            $_SESSION['nom_complet'] = $admin['nom_complet'];
            $_SESSION['role'] = $admin['role'];
            $_SESSION['login_time'] = time();
            
            echo "<div style='background: #d4edda; color: #155724; padding: 15px; margin: 15px 0; border-radius: 5px;'>";
            echo "<h4>🎉 Connexion automatique réussie !</h4>";
            echo "<p>Vous êtes maintenant connecté en tant qu'administrateur.</p>";
            echo "<p><strong>Session créée :</strong></p>";
            echo "<ul>";
            echo "<li>user_id: " . $_SESSION['user_id'] . "</li>";
            echo "<li>nom_complet: " . $_SESSION['nom_complet'] . "</li>";
            echo "<li>role: " . $_SESSION['role'] . "</li>";
            echo "<li>login_time: " . date('Y-m-d H:i:s', $_SESSION['login_time']) . "</li>";
            echo "</ul>";
            echo "</div>";
            
            echo "<div style='text-align: center; margin: 20px 0;'>";
            echo "<a href='test_connection.php' style='padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>Tester la connexion</a>";
            echo "<a href='backup_simple.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>Système de sauvegarde simple</a>";
            echo "<a href='backup_manager.php' style='padding: 10px 20px; background: #6f42c1; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>Système de sauvegarde complet</a>";
            echo "</div>";
            
        } else {
            echo "<div style='background: #fff3cd; color: #856404; padding: 15px; margin: 15px 0; border-radius: 5px;'>";
            echo "<p><strong>⚠️ Prêt pour la connexion automatique</strong></p>";
            echo "<p>Cliquez sur le bouton ci-dessous pour vous connecter automatiquement :</p>";
            echo "<a href='?connect=yes' style='padding: 10px 20px; background: #ffc107; color: #212529; text-decoration: none; border-radius: 5px; font-weight: bold;'>Se connecter automatiquement</a>";
            echo "</div>";
        }
        
    } else {
        echo "<div style='color: red;'>❌ Utilisateur admin non trouvé</div>";
        
        // Créer l'utilisateur admin
        if (isset($_GET['create']) && $_GET['create'] === 'yes') {
            $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO t_utilisateur (username, nom_complet, motdepasse, role) VALUES (?, ?, ?, ?)");
            $stmt->execute(['admin', 'Administrateur Système', $hashedPassword, 'administrateur']);
            
            echo "<div style='background: #d4edda; color: #155724; padding: 15px; margin: 15px 0; border-radius: 5px;'>";
            echo "<h4>✅ Utilisateur admin créé avec succès !</h4>";
            echo "<p><strong>Identifiants créés :</strong></p>";
            echo "<ul>";
            echo "<li><strong>Username:</strong> admin</li>";
            echo "<li><strong>Mot de passe:</strong> admin123</li>";
            echo "<li><strong>Rôle:</strong> administrateur</li>";
            echo "</ul>";
            echo "<p><a href='?' style='padding: 8px 16px; background: #007bff; color: white; text-decoration: none; border-radius: 4px;'>Recharger la page</a></p>";
            echo "</div>";
            
        } else {
            echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; margin: 15px 0; border-radius: 5px;'>";
            echo "<p><strong>⚠️ Aucun utilisateur administrateur trouvé</strong></p>";
            echo "<p>Voulez-vous créer un utilisateur admin par défaut ?</p>";
            echo "<ul>";
            echo "<li><strong>Username:</strong> admin</li>";
            echo "<li><strong>Mot de passe:</strong> admin123</li>";
            echo "<li><strong>Rôle:</strong> administrateur</li>";
            echo "</ul>";
            echo "<a href='?create=yes' style='padding: 10px 20px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px;'>Créer l'utilisateur admin</a>";
            echo "</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>❌ Erreur de base de données: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Diagnostic de session
echo "<h3>🔍 Diagnostic de session</h3>";
echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
echo "<p><strong>Session status:</strong> " . session_status() . " (1=disabled, 2=active)</p>";
echo "<p><strong>Session save path:</strong> " . session_save_path() . "</p>";

if (!empty($_SESSION)) {
    echo "<p><strong>Variables de session actuelles :</strong></p>";
    echo "<pre style='background: #f8f9fa; padding: 10px; border-radius: 5px;'>";
    print_r($_SESSION);
    echo "</pre>";
} else {
    echo "<p><strong>Session vide</strong> - Aucune variable de session définie</p>";
}

?>

<style>
body {
    font-family: Arial, sans-serif;
    max-width: 800px;
    margin: 20px auto;
    padding: 20px;
    background-color: #f8f9fa;
}

h2, h3 {
    color: #333;
    border-bottom: 2px solid #007bff;
    padding-bottom: 10px;
}

p {
    line-height: 1.6;
}

a {
    display: inline-block;
    margin: 5px;
}

ul {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    border-left: 4px solid #007bff;
}
</style>