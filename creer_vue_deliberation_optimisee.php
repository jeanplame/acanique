<?php
/**
 * Script pour créer la nouvelle vue_grille_deliberation optimisée
 * Date: 12 octobre 2025
 * 
 * Cette vue est basée sur la logique de cotation.php qui fonctionne correctement
 */

require_once 'includes/db_config.php';

// Utiliser la base de données lmd_db
$pdo->exec("USE " . DB_NAME);

echo "<!DOCTYPE html>";
echo "<html lang='fr'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
echo "<title>Création de la vue vue_grille_deliberation optimisée</title>";
echo "<style>
    body { font-family: Arial, sans-serif; max-width: 1200px; margin: 20px auto; padding: 0 20px; }
    h2 { color: #333; border-bottom: 3px solid #4CAF50; padding-bottom: 10px; }
    h3 { color: #555; margin-top: 30px; }
    .success { color: green; padding: 10px; background-color: #e8f5e9; border-left: 4px solid green; margin: 10px 0; }
    .warning { color: orange; padding: 10px; background-color: #fff3e0; border-left: 4px solid orange; margin: 10px 0; }
    .error { color: red; padding: 10px; background-color: #ffebee; border-left: 4px solid red; margin: 10px 0; }
    .info { color: #1976d2; padding: 10px; background-color: #e3f2fd; border-left: 4px solid #1976d2; margin: 10px 0; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th { background-color: #4CAF50; color: white; padding: 10px; text-align: left; }
    td { padding: 8px; border: 1px solid #ddd; }
    tr:nth-child(even) { background-color: #f9f9f9; }
    .btn { display: inline-block; padding: 10px 20px; background-color: #2196F3; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; }
    .btn:hover { background-color: #0b7dda; }
    .highlight { background-color: #ffeb3b; font-weight: bold; }
    .code { background-color: #f5f5f5; padding: 15px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace; margin: 10px 0; }
</style>";
echo "</head>";
echo "<body>";
echo "<h2>🔧 Création de la vue vue_grille_deliberation optimisée</h2>";
echo "<hr>";

try {
    // Démarrer une transaction
    $pdo->beginTransaction();
    
    echo "<h3>📋 Étape 1: Sauvegarde de l'ancienne vue</h3>";
    
    // Vérifier si la vue existe
    $checkView = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW' AND Tables_in_lmd_db = 'vue_grille_deliberation'");
    $viewExists = $checkView->rowCount() > 0;
    
    if ($viewExists) {
        // Créer une table de sauvegarde avec les données actuelles
        $backupTableName = 'vue_grille_deliberation_backup_' . date('Ymd_His');
        try {
            $pdo->exec("CREATE TABLE `$backupTableName` AS SELECT * FROM vue_grille_deliberation LIMIT 100");
            echo "<div class='success'>✓ Sauvegarde créée: $backupTableName (100 premiers enregistrements)</div>";
        } catch (PDOException $e) {
            echo "<div class='warning'>⚠ Sauvegarde partielle: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        
        // Obtenir la définition de l'ancienne vue
        try {
            $showCreate = $pdo->query("SHOW CREATE VIEW vue_grille_deliberation");
            $oldViewDef = $showCreate->fetch(PDO::FETCH_ASSOC);
            file_put_contents(__DIR__ . '/vue_grille_deliberation_old_' . date('Ymd_His') . '.sql', 
                              $oldViewDef['Create View']);
            echo "<div class='success'>✓ Définition de l'ancienne vue sauvegardée</div>";
        } catch (PDOException $e) {
            echo "<div class='warning'>⚠ Impossible de sauvegarder la définition: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    } else {
        echo "<div class='info'>ℹ Aucune vue existante à sauvegarder</div>";
    }
    
    echo "<h3>🗑️ Étape 2: Suppression de l'ancienne vue</h3>";
    $pdo->exec("DROP VIEW IF EXISTS vue_grille_deliberation");
    echo "<div class='success'>✓ Ancienne vue supprimée</div>";
    
    echo "<h3>🏗️ Étape 3: Création de la nouvelle vue</h3>";
    
    // Lire et exécuter le fichier SQL
    $sqlContent = file_get_contents(__DIR__ . '/vue_grille_deliberation_optimisee.sql');
    
    // Extraire seulement la partie CREATE VIEW (ignorer les commentaires finaux)
    preg_match('/CREATE VIEW.*?;/s', $sqlContent, $matches);
    
    if (isset($matches[0])) {
        $createViewSQL = $matches[0];
        $pdo->exec($createViewSQL);
        echo "<div class='success'>✓ Nouvelle vue créée avec succès</div>";
        
        // Afficher les nouvelles colonnes
        echo "<div class='info'>";
        echo "<strong>Nouvelles colonnes ajoutées :</strong><br>";
        echo "• <code>semestre_mention</code> : Numéro du semestre (1 ou 2) pour le filtrage<br>";
        echo "• <code>cote_rattrapage_s1/s2</code> : Cotes de rattrapage<br>";
        echo "• <code>meilleure_cote_s1/s2</code> : Meilleures cotes (normale vs rattrapage)<br>";
        echo "• <code>utilise_rattrapage_s1/s2</code> : Indicateurs de rattrapage utilisé<br>";
        echo "• <code>date_rattrapage_s1/s2</code> : Dates des rattrapages<br>";
        echo "</div>";
    } else {
        throw new Exception("Impossible d'extraire la requête CREATE VIEW du fichier SQL");
    }
    
    echo "<h3>🔍 Étape 4: Vérification de la vue</h3>";
    
    // Tester la vue
    $testQuery = "SELECT COUNT(*) as total FROM vue_grille_deliberation";
    $result = $pdo->query($testQuery);
    $count = $result->fetchColumn();
    
    echo "<div class='success'>✓ La vue contient {$count} enregistrements</div>";
    
    // Vérifier les colonnes
    $columnsQuery = "SHOW COLUMNS FROM vue_grille_deliberation";
    $columnsResult = $pdo->query($columnsQuery);
    $columns = $columnsResult->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div class='info'><strong>Colonnes disponibles:</strong></div>";
    echo "<div class='code'>";
    $newColumns = ['semestre_mention', 'cote_rattrapage_s1', 'cote_rattrapage_s2', 'meilleure_cote_s1', 'meilleure_cote_s2', 'utilise_rattrapage_s1', 'utilise_rattrapage_s2'];
    foreach ($columns as $col) {
        $highlight = in_array($col['Field'], $newColumns) ? ' class="highlight"' : '';
        echo "<span$highlight>" . $col['Field'] . "</span>, ";
    }
    echo "</div>";
    
    // Test des semestres
    echo "<h3>📊 Étape 5: Test du filtrage par semestre</h3>";
    
    $semestreQuery = "SELECT semestre_mention, COUNT(*) as nb FROM vue_grille_deliberation GROUP BY semestre_mention ORDER BY semestre_mention";
    $semestreResult = $pdo->query($semestreQuery);
    $semestres = $semestreResult->fetchAll(PDO::FETCH_ASSOC);
    
    if ($semestres) {
        echo "<table>";
        echo "<tr><th>Semestre</th><th>Nombre d'éléments</th></tr>";
        foreach ($semestres as $sem) {
            echo "<tr>";
            echo "<td><strong>Semestre " . ($sem['semestre_mention'] ?: 'Non défini') . "</strong></td>";
            echo "<td>" . $sem['nb'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        if (count($semestres) >= 2) {
            echo "<div class='success'>✓ Le filtrage par semestre est opérationnel</div>";
        } else {
            echo "<div class='warning'>⚠ Un seul semestre détecté. Vérifiez la configuration des semestres dans t_mention_ue</div>";
        }
    } else {
        echo "<div class='error'>✗ Aucune donnée de semestre trouvée</div>";
    }
    
    // Afficher un échantillon avec les nouvelles colonnes
    echo "<h3>📋 Étape 6: Échantillon des données</h3>";
    $sampleQuery = "SELECT 
        matricule, 
        nom_complet, 
        code_ue, 
        libelle_ue,
        code_ec,
        libelle_ec,
        semestre_mention,
        cote_s1, 
        cote_s2,
        cote_rattrapage_s1,
        cote_rattrapage_s2,
        meilleure_cote_s1,
        meilleure_cote_s2,
        moyenne_ec,
        utilise_rattrapage_s1,
        utilise_rattrapage_s2
    FROM vue_grille_deliberation 
    WHERE (cote_s1 > 0 OR cote_s2 > 0 OR cote_rattrapage_s1 > 0 OR cote_rattrapage_s2 > 0)
    ORDER BY semestre_mention, nom_complet, code_ue
    LIMIT 10";
    
    $sampleResult = $pdo->query($sampleQuery);
    $samples = $sampleResult->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($samples) > 0) {
        echo "<table>";
        echo "<tr>";
        echo "<th>Matricule</th>";
        echo "<th>Nom</th>";
        echo "<th>UE</th>";
        echo "<th>EC</th>";
        echo "<th>Sem.</th>";
        echo "<th>S1</th>";
        echo "<th>S2</th>";
        echo "<th>Ratt.S1</th>";
        echo "<th>Ratt.S2</th>";
        echo "<th>Meill.S1</th>";
        echo "<th>Meill.S2</th>";
        echo "<th>Moyenne</th>";
        echo "<th>Utilise Ratt.</th>";
        echo "</tr>";
        
        foreach ($samples as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['matricule']) . "</td>";
            echo "<td>" . htmlspecialchars(substr($row['nom_complet'], 0, 20)) . "...</td>";
            echo "<td>" . htmlspecialchars($row['code_ue']) . "</td>";
            echo "<td>" . htmlspecialchars($row['code_ec']) . "</td>";
            echo "<td class='highlight'>" . ($row['semestre_mention'] ?: '-') . "</td>";
            echo "<td>" . number_format($row['cote_s1'], 2) . "</td>";
            echo "<td>" . number_format($row['cote_s2'], 2) . "</td>";
            echo "<td>" . ($row['cote_rattrapage_s1'] ? number_format($row['cote_rattrapage_s1'], 2) : '-') . "</td>";
            echo "<td>" . ($row['cote_rattrapage_s2'] ? number_format($row['cote_rattrapage_s2'], 2) : '-') . "</td>";
            echo "<td style='background-color: #e8f5e9;'>" . number_format($row['meilleure_cote_s1'], 2) . "</td>";
            echo "<td style='background-color: #e8f5e9;'>" . number_format($row['meilleure_cote_s2'], 2) . "</td>";
            echo "<td style='background-color: #fff3e0; font-weight: bold;'>" . number_format($row['moyenne_ec'], 2) . "</td>";
            echo "<td>" . ($row['utilise_rattrapage_s1'] ? 'S1 ' : '') . ($row['utilise_rattrapage_s2'] ? 'S2' : '') . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    } else {
        echo "<div class='warning'>⚠ Aucune donnée avec des cotes trouvée</div>";
    }
    
    // Valider la transaction
    $pdo->commit();
    
    echo "<hr>";
    echo "<h3 style='color: green;'>✅ Vue créée avec succès!</h3>";
    
    echo "<div class='info'>";
    echo "<h4>🎯 Comment utiliser la nouvelle vue :</h4>";
    echo "<div class='code'>";
    echo "-- Filtrer par Semestre 1 :<br>";
    echo "SELECT * FROM vue_grille_deliberation WHERE semestre_mention = 1;<br><br>";
    echo "-- Filtrer par Semestre 2 :<br>";
    echo "SELECT * FROM vue_grille_deliberation WHERE semestre_mention = 2;<br><br>";
    echo "-- Voir tous les rattrapages :<br>";
    echo "SELECT * FROM vue_grille_deliberation WHERE (utilise_rattrapage_s1 = 1 OR utilise_rattrapage_s2 = 1);";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='success'>";
    echo "<h4>✅ Prochaines étapes :</h4>";
    echo "<ul>";
    echo "<li>✓ La vue est maintenant compatible avec le filtrage par semestre</li>";
    echo "<li>✓ Les notes de rattrapage sont automatiquement prises en compte</li>";
    echo "<li>✓ Le code PHP de délibération devrait maintenant fonctionner correctement</li>";
    echo "<li>✓ Testez la page de délibération avec les filtres Semestre 1 et Semestre 2</li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    // Annuler la transaction en cas d'erreur
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo "<div class='error'>";
    echo "<h3>❌ Erreur lors de la création de la vue</h3>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Fichier:</strong> " . htmlspecialchars($e->getFile()) . "</p>";
    echo "<p><strong>Ligne:</strong> " . $e->getLine() . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='index.php' class='btn'>← Retour à l'accueil</a> ";
echo "<a href='diagnostic_deliberation.php' class='btn'>Diagnostic</a> ";
echo "<a href='?page=domaine&action=view&id=1&tab=deliberation' class='btn'>Tester la délibération</a></p>";
echo "</body>";
echo "</html>";
?>