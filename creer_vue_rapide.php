<?php
/**
 * Script rapide pour créer la vue optimisée
 */

require_once 'includes/db_config.php';

// Utiliser la base de données lmd_db
$pdo->exec("USE " . DB_NAME);

echo "<h2>🚀 Création rapide de la vue optimisée</h2>";

try {
    // Supprimer l'ancienne vue si elle existe
    echo "<p>🗑️ Suppression de l'ancienne vue...</p>";
    $pdo->exec("DROP VIEW IF EXISTS vue_grille_deliberation_old");
    $pdo->exec("CREATE VIEW vue_grille_deliberation_old AS SELECT * FROM vue_grille_deliberation");
    $pdo->exec("DROP VIEW IF EXISTS vue_grille_deliberation");
    
    // Créer la nouvelle vue avec semestre_mention
    echo "<p>⚙️ Création de la nouvelle vue...</p>";
    
    $sql_vue = "
    CREATE VIEW vue_grille_deliberation AS
    SELECT DISTINCT
        -- Informations étudiant et cursus
        et.matricule,
        CONCAT(et.nom, ' ', et.prenom) as nom_complet,
        i.code_promotion,
        i.id_mention,
        i.id_annee,
        
        -- Informations semestre  
        s.id_semestre,
        mue.semestre as semestre_mention, -- 🆕 COLONNE CLÉE pour filtrage correct
        
        -- Informations UE
        ue.id_ue,
        ue.code_ue,
        ue.libelle as libelle_ue,
        mue.credit as credits,
        
        -- Informations EC (ou placeholder pour UE sans EC)
        COALESCE(ec.id_ec, CONCAT('UE_', ue.id_ue)) as id_ec,
        COALESCE(ec.code_ec, ue.code_ue) as code_ec,
        COALESCE(ec.libelle, ue.libelle) as libelle_ec,
        COALESCE(ec.coef, 1) as coef_ec,
        
        -- Cotes normales
        c.cote_s1,
        c.cote_s2,
        
        -- Cotes de rattrapage  
        c.cote_rattrapage_s1,
        c.cote_rattrapage_s2,
        
        -- Meilleures cotes (normale vs rattrapage)
        CASE 
            WHEN c.cote_rattrapage_s1 IS NOT NULL AND c.cote_rattrapage_s1 > COALESCE(c.cote_s1, 0) 
            THEN c.cote_rattrapage_s1 
            ELSE c.cote_s1 
        END as meilleure_cote_s1,
        
        CASE 
            WHEN c.cote_rattrapage_s2 IS NOT NULL AND c.cote_rattrapage_s2 > COALESCE(c.cote_s2, 0) 
            THEN c.cote_rattrapage_s2 
            ELSE c.cote_s2 
        END as meilleure_cote_s2,
        
        -- Moyenne calculée avec meilleures cotes
        CASE
            WHEN (CASE WHEN c.cote_rattrapage_s1 IS NOT NULL AND c.cote_rattrapage_s1 > COALESCE(c.cote_s1, 0) THEN c.cote_rattrapage_s1 ELSE c.cote_s1 END) IS NOT NULL
             AND (CASE WHEN c.cote_rattrapage_s2 IS NOT NULL AND c.cote_rattrapage_s2 > COALESCE(c.cote_s2, 0) THEN c.cote_rattrapage_s2 ELSE c.cote_s2 END) IS NOT NULL
            THEN ((CASE WHEN c.cote_rattrapage_s1 IS NOT NULL AND c.cote_rattrapage_s1 > COALESCE(c.cote_s1, 0) THEN c.cote_rattrapage_s1 ELSE c.cote_s1 END) + 
                  (CASE WHEN c.cote_rattrapage_s2 IS NOT NULL AND c.cote_rattrapage_s2 > COALESCE(c.cote_s2, 0) THEN c.cote_rattrapage_s2 ELSE c.cote_s2 END)) / 2
            ELSE NULL
        END as moyenne_ec
        
    FROM t_etudiant et
    INNER JOIN t_inscription i ON et.matricule = i.matricule
    INNER JOIN t_semestre s ON i.id_annee = s.id_annee AND i.id_mention = s.id_mention
    INNER JOIN t_mention_ue mue ON i.id_mention = mue.id_mention
    INNER JOIN t_unite_enseignement ue ON mue.id_ue = ue.id_ue
    LEFT JOIN t_element_constitutif ec ON ue.id_ue = ec.id_ue
    LEFT JOIN t_cote c ON et.matricule = c.matricule 
                      AND i.id_annee = c.id_annee 
                      AND (
                          (ec.id_ec IS NOT NULL AND c.id_ec = ec.id_ec) OR
                          (ec.id_ec IS NULL AND c.id_ue = ue.id_ue)
                      )
    
    -- Filtrer par semestre logique (mue.semestre = 1 ou 2)
    WHERE mue.semestre = s.numero_semestre
    
    ORDER BY et.nom, et.prenom, mue.semestre, ue.code_ue, ec.code_ec
    ";
    
    $pdo->exec($sql_vue);
    
    echo "<div style='color: green; padding: 10px; background: #e8f5e9; border-left: 4px solid green;'>";
    echo "✅ <strong>Vue créée avec succès !</strong><br>";
    echo "🎯 La colonne <strong>semestre_mention</strong> est maintenant disponible<br>";
    echo "📊 Vous pouvez utiliser le filtrage par semestre dans délibération.php";
    echo "</div>";
    
    // Test rapide
    echo "<h3>🧪 Test rapide</h3>";
    $test = $pdo->query("SELECT COUNT(*) as total, 
                                COUNT(DISTINCT semestre_mention) as nb_semestres,
                                GROUP_CONCAT(DISTINCT semestre_mention) as semestres
                         FROM vue_grille_deliberation LIMIT 1")->fetch();
    
    echo "<p>📊 <strong>Résultats :</strong></p>";
    echo "<ul>";
    echo "<li>Total lignes : " . $test['total'] . "</li>";
    echo "<li>Semestres disponibles : " . $test['semestres'] . "</li>";
    echo "<li>Nombre de semestres : " . $test['nb_semestres'] . "</li>";
    echo "</ul>";
    
    echo "<h3>🎯 Prochaines étapes</h3>";
    echo "<ol>";
    echo "<li>🔄 Recharger la page de délibération</li>";
    echo "<li>🎯 Tester le filtrage 'Semestre 1' et 'Semestre 2'</li>";
    echo "<li>✅ Tester 'Tous les semestres' - maintenant chaque semestre devrait afficher ses propres UE/EC</li>";
    echo "</ol>";
    
    echo "<p><a href='pages/domaine/tabs/deliberation.php' style='background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>🎯 Tester la délibération</a></p>";
    
} catch (Exception $e) {
    echo "<div style='color: red; padding: 10px; background: #ffebee; border-left: 4px solid red;'>";
    echo "❌ <strong>Erreur :</strong> " . $e->getMessage();
    echo "</div>";
    
    // Essayer de restaurer l'ancienne vue
    try {
        $pdo->exec("DROP VIEW IF EXISTS vue_grille_deliberation");
        $pdo->exec("CREATE VIEW vue_grille_deliberation AS SELECT * FROM vue_grille_deliberation_old");
        echo "<p>🔄 Ancienne vue restaurée</p>";
    } catch (Exception $e2) {
        echo "<p>❌ Impossible de restaurer l'ancienne vue : " . $e2->getMessage() . "</p>";
    }
}
?>