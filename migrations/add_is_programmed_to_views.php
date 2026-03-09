<?php
/**
 * Migration: Ajouter le filtre is_programmed aux vues SQL
 * - vue_ue_ec_complete
 * - vue_grille_deliberation
 */

require_once __DIR__ . '/../includes/db_config.php';

echo "=== Migration: Ajout filtre is_programmed aux vues ===\n\n";

try {
    // 1. Mettre à jour vue_ue_ec_complete
    echo "1. Mise à jour de vue_ue_ec_complete...\n";
    $pdo->exec("
        CREATE OR REPLACE VIEW vue_ue_ec_complete AS
        SELECT 
            ue.id_ue,
            ue.code_ue,
            ue.libelle AS libelle_ue,
            ue.credits,
            ue.code_promotion,
            ec.id_ec,
            ec.code_ec,
            ec.libelle AS libelle_ec,
            mu.id_mention,
            mu.semestre,
            ec.coefficient AS coef_ec
        FROM t_unite_enseignement ue
        JOIN t_mention_ue mu ON mu.id_ue = ue.id_ue
        LEFT JOIN t_element_constitutif ec ON ec.id_ue = ue.id_ue
            AND ec.is_programmed = 1
        LEFT JOIN t_mention_ue_ec muec ON muec.id_mention_ue = mu.id_mention_ue 
            AND muec.id_ec = ec.id_ec
        WHERE ue.is_programmed = 1
    ");
    echo "   ✅ vue_ue_ec_complete mise à jour avec filtre is_programmed\n\n";

    // 2. Mettre à jour vue_grille_deliberation
    echo "2. Mise à jour de vue_grille_deliberation...\n";
    $pdo->exec("
        CREATE OR REPLACE VIEW vue_grille_deliberation AS
        SELECT 
            e.matricule,
            CONCAT(e.nom_etu, ' ', e.postnom_etu, ' ', e.prenom_etu) AS nom_complet,
            i.code_promotion,
            i.id_mention,
            i.id_annee,
            ue.id_semestre,
            mue.semestre AS semestre_mention,
            ue.id_ue,
            ue.code_ue,
            ue.libelle AS libelle_ue,
            ue.credits,
            CASE WHEN ec.id_ec IS NOT NULL THEN ec.id_ec ELSE CONCAT('UE_', ue.id_ue) END AS id_ec,
            CASE WHEN ec.code_ec IS NOT NULL THEN ec.code_ec ELSE ue.code_ue END AS code_ec,
            CASE WHEN ec.libelle IS NOT NULL THEN ec.libelle ELSE ue.libelle END AS libelle_ec,
            CASE WHEN ec.coefficient IS NOT NULL THEN ec.coefficient ELSE ue.credits END AS coef_ec,
            COALESCE(cotes.cote_s1, 0.00) AS cote_s1,
            COALESCE(cotes.cote_s2, 0.00) AS cote_s2,
            cotes.cote_rattrapage_s1,
            cotes.cote_rattrapage_s2,
            cotes.date_rattrapage_s1,
            cotes.date_rattrapage_s2,
            CASE 
                WHEN cotes.cote_rattrapage_s1 IS NOT NULL AND cotes.cote_rattrapage_s1 > COALESCE(cotes.cote_s1, 0)
                THEN cotes.cote_rattrapage_s1 
                ELSE COALESCE(cotes.cote_s1, 0.00) 
            END AS meilleure_cote_s1,
            CASE 
                WHEN cotes.cote_rattrapage_s2 IS NOT NULL AND cotes.cote_rattrapage_s2 > COALESCE(cotes.cote_s2, 0)
                THEN cotes.cote_rattrapage_s2 
                ELSE COALESCE(cotes.cote_s2, 0.00) 
            END AS meilleure_cote_s2,
            CASE 
                WHEN COALESCE(cotes.cote_s1, 0) > 0 AND COALESCE(cotes.cote_s2, 0) > 0 THEN
                    ROUND((
                        (CASE WHEN cotes.cote_rattrapage_s1 IS NOT NULL AND cotes.cote_rattrapage_s1 > cotes.cote_s1 
                              THEN cotes.cote_rattrapage_s1 ELSE COALESCE(cotes.cote_s1, 0) END)
                        + 
                        (CASE WHEN cotes.cote_rattrapage_s2 IS NOT NULL AND cotes.cote_rattrapage_s2 > cotes.cote_s2 
                              THEN cotes.cote_rattrapage_s2 ELSE COALESCE(cotes.cote_s2, 0) END)
                    ) / 2, 2)
                WHEN COALESCE(cotes.cote_s1, 0) > 0 AND COALESCE(cotes.cote_s2, 0) = 0 THEN
                    CASE WHEN cotes.cote_rattrapage_s1 IS NOT NULL AND cotes.cote_rattrapage_s1 > cotes.cote_s1 
                         THEN cotes.cote_rattrapage_s1 ELSE COALESCE(cotes.cote_s1, 0) END
                WHEN COALESCE(cotes.cote_s1, 0) = 0 AND COALESCE(cotes.cote_s2, 0) > 0 THEN
                    CASE WHEN cotes.cote_rattrapage_s2 IS NOT NULL AND cotes.cote_rattrapage_s2 > cotes.cote_s2 
                         THEN cotes.cote_rattrapage_s2 ELSE COALESCE(cotes.cote_s2, 0) END
                ELSE 0.00 
            END AS moyenne_ec,
            CASE WHEN cotes.cote_rattrapage_s1 IS NOT NULL AND cotes.cote_rattrapage_s1 > COALESCE(cotes.cote_s1, 0) THEN 1 ELSE 0 END AS utilise_rattrapage_s1,
            CASE WHEN cotes.cote_rattrapage_s2 IS NOT NULL AND cotes.cote_rattrapage_s2 > COALESCE(cotes.cote_s2, 0) THEN 1 ELSE 0 END AS utilise_rattrapage_s2
        FROM t_etudiant e
        JOIN t_inscription i ON i.matricule = e.matricule AND i.statut = 'Actif'
        JOIN t_mention_ue mue ON mue.id_mention = i.id_mention
        JOIN t_unite_enseignement ue ON ue.id_ue = mue.id_ue AND ue.code_promotion = i.code_promotion
            AND ue.is_programmed = 1
        LEFT JOIN t_element_constitutif ec ON ec.id_ue = ue.id_ue
            AND ec.is_programmed = 1
        LEFT JOIN t_mention_ue_ec muec ON muec.id_mention_ue = mue.id_mention_ue AND muec.id_ec = ec.id_ec
        LEFT JOIN (
            SELECT c1.matricule, c1.id_ec, c1.id_ue, c1.id_annee, c1.id_mention,
                   c1.cote_s1, c1.cote_s2, 
                   c1.cote_rattrapage_s1, c1.cote_rattrapage_s2,
                   c1.date_rattrapage_s1, c1.date_rattrapage_s2
            FROM t_cote c1
            JOIN (
                SELECT matricule, COALESCE(id_ec, 0) AS id_ec_key, COALESCE(id_ue, 0) AS id_ue_key,
                       id_annee, id_mention, MAX(id_note) AS max_id_note
                FROM t_cote
                GROUP BY matricule, COALESCE(id_ec, 0), COALESCE(id_ue, 0), id_annee, id_mention
            ) c2 ON c1.id_note = c2.max_id_note
        ) cotes ON cotes.matricule = e.matricule 
            AND cotes.id_annee = i.id_annee 
            AND cotes.id_mention = i.id_mention
            AND (
                (ec.id_ec IS NOT NULL AND cotes.id_ec = ec.id_ec) OR
                (ec.id_ec IS NULL AND cotes.id_ue = ue.id_ue) OR
                (ec.id_ec IS NOT NULL AND cotes.id_ec IS NULL AND cotes.id_ue = ue.id_ue)
            )
        WHERE (ec.id_ec IS NULL OR muec.id_ec IS NOT NULL OR muec.id_ec IS NULL)
        ORDER BY e.nom_etu, e.postnom_etu, e.prenom_etu, mue.semestre, ue.code_ue, COALESCE(ec.code_ec, ue.code_ue)
    ");
    echo "   ✅ vue_grille_deliberation mise à jour avec filtre is_programmed\n\n";

    echo "=== Migration terminée avec succès ===\n";

} catch (Exception $e) {
    echo "❌ ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}
