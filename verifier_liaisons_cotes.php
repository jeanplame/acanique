<?php
/**
 * Script de vérification des liaisons UE/EC/Cotes
 * pour identifier les problèmes spécifiques d'affichage
 */

require_once __DIR__ . '/includes/db_config.php';

echo "<h1>Vérification des liaisons UE/EC/Cotes</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    .success { color: green; background-color: #d4edda; padding: 10px; margin: 10px 0; }
    .error { color: red; background-color: #f8d7da; padding: 10px; margin: 10px 0; }
    .warning { color: orange; background-color: #fff3cd; padding: 10px; margin: 10px 0; }
    .section { margin: 20px 0; padding: 15px; border: 1px solid #ccc; }
</style>";

try {
    // 1. Vérifier les UE avec et sans EC
    echo "<div class='section'>";
    echo "<h2>1. Analyse des UE et EC</h2>";
    
    $stmt = $pdo->query("
        SELECT 
            ue.code_ue,
            ue.libelle as ue_libelle,
            ue.credits as ue_credits,
            COUNT(ec.id_ec) as nb_ec,
            GROUP_CONCAT(ec.code_ec SEPARATOR ', ') as codes_ec
        FROM t_unite_enseignement ue
        LEFT JOIN t_element_constitutif ec ON ue.id_ue = ec.id_ue
        GROUP BY ue.id_ue, ue.code_ue, ue.libelle, ue.credits
        ORDER BY ue.code_ue
    ");
    
    $ues_ec = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>UE avec leurs EC:</h3>";
    echo "<table>";
    echo "<tr><th>Code UE</th><th>Libellé UE</th><th>Crédits</th><th>Nb EC</th><th>Codes EC</th></tr>";
    
    $ues_sans_ec = 0;
    foreach ($ues_ec as $row) {
        $style = $row['nb_ec'] == 0 ? 'background-color: #fff3cd;' : '';
        if ($row['nb_ec'] == 0) $ues_sans_ec++;
        
        echo "<tr style='$style'>";
        echo "<td>{$row['code_ue']}</td>";
        echo "<td>{$row['ue_libelle']}</td>";
        echo "<td>{$row['ue_credits']}</td>";
        echo "<td>{$row['nb_ec']}</td>";
        echo "<td>" . ($row['codes_ec'] ?: 'Aucun EC') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<p><strong>$ues_sans_ec UE sans éléments constitutifs</strong> (surlignées en jaune)</p>";
    echo "</div>";

    // 2. Vérifier les cotes par type
    echo "<div class='section'>";
    echo "<h2>2. Analyse des cotes par type</h2>";
    
    $stmt = $pdo->query("
        SELECT 
            'Cotes sur EC' as type,
            COUNT(*) as nb_enregistrements,
            COUNT(DISTINCT matricule) as nb_etudiants,
            SUM(CASE WHEN cote_s1 > 0 THEN 1 ELSE 0 END) as cotes_s1_positives,
            SUM(CASE WHEN cote_s2 > 0 THEN 1 ELSE 0 END) as cotes_s2_positives
        FROM t_cote 
        WHERE id_ec IS NOT NULL
        
        UNION ALL
        
        SELECT 
            'Cotes sur UE' as type,
            COUNT(*) as nb_enregistrements,
            COUNT(DISTINCT matricule) as nb_etudiants,
            SUM(CASE WHEN cote_s1 > 0 THEN 1 ELSE 0 END) as cotes_s1_positives,
            SUM(CASE WHEN cote_s2 > 0 THEN 1 ELSE 0 END) as cotes_s2_positives
        FROM t_cote 
        WHERE id_ec IS NULL AND id_ue IS NOT NULL
    ");
    
    $stats_cotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table>";
    echo "<tr><th>Type</th><th>Enregistrements</th><th>Étudiants</th><th>Cotes S1 > 0</th><th>Cotes S2 > 0</th></tr>";
    foreach ($stats_cotes as $stat) {
        echo "<tr>";
        echo "<td>{$stat['type']}</td>";
        echo "<td>{$stat['nb_enregistrements']}</td>";
        echo "<td>{$stat['nb_etudiants']}</td>";
        echo "<td>{$stat['cotes_s1_positives']}</td>";
        echo "<td>{$stat['cotes_s2_positives']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "</div>";

    // 3. Identifier les étudiants avec cotes manquantes
    echo "<div class='section'>";
    echo "<h2>3. Étudiants avec cotes potentiellement manquantes</h2>";
    
    $stmt = $pdo->query("
        SELECT 
            e.matricule,
            CONCAT(e.nom_etu, ' ', e.prenom_etu) as nom_complet,
            i.code_promotion,
            COUNT(DISTINCT ue.id_ue) as nb_ue_programme,
            COUNT(DISTINCT c.id_note) as nb_cotes_enregistrees,
            COUNT(DISTINCT CASE WHEN c.cote_s1 > 0 OR c.cote_s2 > 0 THEN c.id_note END) as nb_cotes_positives
        FROM t_etudiant e
        INNER JOIN t_inscription i ON e.matricule = i.matricule AND i.statut = 'Actif'
        INNER JOIN t_unite_enseignement ue ON i.code_promotion = ue.code_promotion
        INNER JOIN t_mention_ue mu ON mu.id_mention = i.id_mention AND mu.id_ue = ue.id_ue
        LEFT JOIN t_cote c ON c.matricule = e.matricule AND c.id_annee = i.id_annee
        GROUP BY e.matricule, e.nom_etu, e.prenom_etu, i.code_promotion
        HAVING nb_cotes_enregistrees = 0 OR nb_cotes_positives = 0
        ORDER BY nom_complet
        LIMIT 10
    ");
    
    $etudiants_probleme = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($etudiants_probleme) > 0) {
        echo "<div class='warning'>⚠️ Étudiants avec problèmes de cotes:</div>";
        echo "<table>";
        echo "<tr><th>Matricule</th><th>Nom</th><th>Promotion</th><th>UE programme</th><th>Cotes enregistrées</th><th>Cotes positives</th></tr>";
        foreach ($etudiants_probleme as $etudiant) {
            echo "<tr>";
            echo "<td>{$etudiant['matricule']}</td>";
            echo "<td>{$etudiant['nom_complet']}</td>";
            echo "<td>{$etudiant['code_promotion']}</td>";
            echo "<td>{$etudiant['nb_ue_programme']}</td>";
            echo "<td>{$etudiant['nb_cotes_enregistrees']}</td>";
            echo "<td>{$etudiant['nb_cotes_positives']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='success'>✅ Tous les étudiants ont des cotes enregistrées</div>";
    }
    echo "</div>";

    // 4. Vérifier les problèmes de jointure dans la vue
    echo "<div class='section'>";
    echo "<h2>4. Test de la vue actuelle</h2>";
    
    try {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_lignes,
                COUNT(CASE WHEN cote_s1 > 0 THEN 1 END) as lignes_s1,
                COUNT(CASE WHEN cote_s2 > 0 THEN 1 END) as lignes_s2,
                COUNT(CASE WHEN cote_s1 > 0 OR cote_s2 > 0 THEN 1 END) as lignes_avec_cotes,
                COUNT(CASE WHEN cote_s1 = 0 AND cote_s2 = 0 THEN 1 END) as lignes_sans_cotes
            FROM vue_grille_deliberation
        ");
        
        $vue_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<table>";
        echo "<tr><th>Métrique</th><th>Valeur</th><th>Pourcentage</th></tr>";
        foreach ($vue_stats as $key => $value) {
            $percent = $vue_stats['total_lignes'] > 0 ? round(($value / $vue_stats['total_lignes']) * 100, 1) : 0;
            echo "<tr>";
            echo "<td>" . ucfirst(str_replace('_', ' ', $key)) . "</td>";
            echo "<td>$value</td>";
            echo "<td>{$percent}%</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        $taux_couverture = $vue_stats['total_lignes'] > 0 ? 
            round(($vue_stats['lignes_avec_cotes'] / $vue_stats['total_lignes']) * 100, 1) : 0;
        
        if ($taux_couverture < 50) {
            echo "<div class='error'>❌ Taux de couverture faible: {$taux_couverture}% - Problème critique</div>";
        } elseif ($taux_couverture < 80) {
            echo "<div class='warning'>⚠️ Taux de couverture moyen: {$taux_couverture}% - Améliorations possibles</div>";
        } else {
            echo "<div class='success'>✅ Bon taux de couverture: {$taux_couverture}%</div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Erreur avec la vue: " . $e->getMessage() . "</div>";
    }
    echo "</div>";

    // 5. Recommandations spécifiques
    echo "<div class='section'>";
    echo "<h2>5. Recommandations</h2>";
    
    echo "<div class='warning'>";
    echo "<h3>Actions recommandées:</h3>";
    echo "<ol>";
    
    if ($ues_sans_ec > 0) {
        echo "<li><strong>UE sans EC:</strong> $ues_sans_ec UE n'ont pas d'éléments constitutifs. Vérifier si c'est normal ou créer les EC manquants.</li>";
    }
    
    if (count($etudiants_probleme) > 0) {
        echo "<li><strong>Cotes manquantes:</strong> " . count($etudiants_probleme) . " étudiants n'ont pas de cotes. Vérifier la saisie des notes.</li>";
    }
    
    echo "<li><strong>Appliquer la correction:</strong> Exécuter le script fix_cotes_deliberation.php pour corriger la vue.</li>";
    echo "<li><strong>Tester l'affichage:</strong> Vérifier que les cotes apparaissent correctement dans la grille de délibération.</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<p><a href='fix_cotes_deliberation.php' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔧 Appliquer les corrections</a></p>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h2>❌ Erreur lors de la vérification</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "<p><em>Vérification terminée - " . date('Y-m-d H:i:s') . "</em></p>";
?>