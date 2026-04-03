<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * Traitement des actions d'inscription
 * Gestion des inscriptions, désinscriptions et réinscriptions
 * 
 * @version 1.0
 * @created 2025-10-29
 */

// Protection contre l'accès direct - vérifier que le fichier est bien inclus
if (!isset($pdo)) {
    http_response_code(403);
    exit('Accès interdit');
}

// Vérification de la connexion à la base de données
if (!($pdo instanceof PDO)) {
    $_SESSION['error'] = "Erreur: La connexion à la base de données n'est pas disponible.";
    return;
}

$action = $_POST['action_inscription'] ?? $_GET['action_inscription'] ?? '';

// ============================================================================
// RÉINSCRIPTION MULTIPLE D'ÉTUDIANTS
// ============================================================================
if ($action === 'reinscrire' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Récupération et validation des données
        $new_mention_id = filter_input(INPUT_POST, 'new_mention_id', FILTER_VALIDATE_INT);
        $new_promotion_code = trim($_POST['new_promotion_code'] ?? '');
        $etudiants = $_POST['etudiants'] ?? [];
        
        // Validation des paramètres
        if (!$new_mention_id || empty($new_promotion_code)) {
            $_SESSION['error'] = "Erreur: Mention et promotion sont obligatoires.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        if (empty($etudiants) || !is_array($etudiants)) {
            $_SESSION['error'] = "Erreur: Aucun étudiant sélectionné.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        // Vérifier que la mention existe
        $stmt_check_mention = $pdo->prepare("SELECT id_mention FROM t_mention WHERE id_mention = ?");
        $stmt_check_mention->execute([$new_mention_id]);
        if (!$stmt_check_mention->fetch()) {
            $_SESSION['error'] = "Erreur: La mention sélectionnée n'existe pas.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        // Vérifier que la promotion existe
        $stmt_check_promo = $pdo->prepare("SELECT code_promotion FROM t_promotion WHERE code_promotion = ?");
        $stmt_check_promo->execute([$new_promotion_code]);
        if (!$stmt_check_promo->fetch()) {
            $_SESSION['error'] = "Erreur: La promotion sélectionnée n'existe pas.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        // Début de la transaction
        $pdo->beginTransaction();
        
        $inscriptions_reussies = 0;
        $inscriptions_echouees = 0;
        $details_erreurs = [];
        
        foreach ($etudiants as $matricule) {
            $matricule = trim($matricule);
            
            if (empty($matricule)) {
                continue;
            }
            
            try {
                // Vérifier que l'étudiant existe
                $stmt_check_etudiant = $pdo->prepare("SELECT matricule, nom_etu, postnom_etu, prenom_etu FROM t_etudiant WHERE matricule = ?");
                $stmt_check_etudiant->execute([$matricule]);
                $etudiant = $stmt_check_etudiant->fetch(PDO::FETCH_ASSOC);
                
                if (!$etudiant) {
                    $details_erreurs[] = "Matricule $matricule : étudiant introuvable";
                    $inscriptions_echouees++;
                    continue;
                }
                
                // Vérifier si l'étudiant n'est pas déjà inscrit dans cette mention/promotion pour cette année
                $stmt_check_existing = $pdo->prepare("
                    SELECT id_inscription 
                    FROM t_inscription 
                    WHERE matricule = ? 
                      AND id_mention = ? 
                      AND code_promotion = ? 
                      AND id_annee = ?
                      AND statut = 'Actif'
                ");
                $stmt_check_existing->execute([
                    $matricule,
                    $new_mention_id,
                    $new_promotion_code,
                    $annee_academique
                ]);
                
                if ($stmt_check_existing->fetch()) {
                    $details_erreurs[] = "{$etudiant['nom_etu']} {$etudiant['postnom_etu']} {$etudiant['prenom_etu']} : déjà inscrit dans cette mention/promotion pour cette année";
                    $inscriptions_echouees++;
                    continue;
                }
                
                // Créer la nouvelle inscription
                $stmt_insert = $pdo->prepare("
                    INSERT INTO t_inscription (
                        matricule,
                        id_mention,
                        code_promotion,
                        id_annee,
                        date_inscription,
                        statut,
                        username,
                        id_filiere
                    ) VALUES (?, ?, ?, ?, NOW(), 'Actif', ?, (SELECT idFiliere FROM t_mention WHERE id_mention = ?))
                ");
                
                $stmt_insert->execute([
                    $matricule,
                    $new_mention_id,
                    $new_promotion_code,
                    $annee_academique,
                    $_SESSION['username'] ?? 'system',
                    $new_mention_id
                ]);
                
                $inscriptions_reussies++;
                
            } catch (PDOException $e) {
                $details_erreurs[] = "{$etudiant['nom_etu']} {$etudiant['postnom_etu']} {$etudiant['prenom_etu']} : " . $e->getMessage();
                $inscriptions_echouees++;
            }
        }
        
        // Commit de la transaction
        $pdo->commit();
        
        // Message de résultat
        if ($inscriptions_reussies > 0) {
            $message = "<strong>Succès !</strong> $inscriptions_reussies étudiant(s) réinscrit(s) avec succès.";
            
            if ($inscriptions_echouees > 0) {
                $message .= "<br><strong>Attention :</strong> $inscriptions_echouees inscription(s) ont échoué.";
                if (!empty($details_erreurs)) {
                    $message .= "<br><small>Détails :<ul class='mb-0 mt-1'>";
                    foreach (array_slice($details_erreurs, 0, 5) as $erreur) {
                        $message .= "<li>" . htmlspecialchars($erreur) . "</li>";
                    }
                    if (count($details_erreurs) > 5) {
                        $message .= "<li>... et " . (count($details_erreurs) - 5) . " autre(s) erreur(s)</li>";
                    }
                    $message .= "</ul></small>";
                }
            }
            
            $_SESSION['success'] = $message;
        } else {
            $message = "<strong>Erreur !</strong> Aucune inscription n'a pu être effectuée.";
            if (!empty($details_erreurs)) {
                $message .= "<br><small>Détails :<ul class='mb-0 mt-1'>";
                foreach (array_slice($details_erreurs, 0, 10) as $erreur) {
                    $message .= "<li>" . htmlspecialchars($erreur) . "</li>";
                }
                $message .= "</ul></small>";
            }
            $_SESSION['error'] = $message;
        }
        
        // Redirection vers la liste des inscriptions
        header("Location: ?page=domaine&action=view&id=$id_domaine&mention=$mention_id&tab=inscriptions&promotion=$promotion_code&annee=$annee_academique&sub_tab=liste");
        exit;
        
    } catch (PDOException $e) {
        // Rollback en cas d'erreur
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        $_SESSION['error'] = "Erreur lors de la réinscription : " . htmlspecialchars($e->getMessage());
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}

// ============================================================================
// INSCRIPTION D'UN NOUVEL ÉTUDIANT
// ============================================================================
elseif ($action === 'inscrire' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Récupération des données
        $matricule = trim($_POST['matricule'] ?? '');
        $nom_etu = trim($_POST['nom'] ?? '');
        $postnom_etu = trim($_POST['postnom'] ?? '');
        $prenom_etu = trim($_POST['prenom'] ?? '');
        $sexe = trim($_POST['sexe'] ?? '');
        $date_naiss = trim($_POST['date_naissance'] ?? '');
        $lieu_naiss = trim($_POST['lieu_naissance'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        
        // Validation
        if (empty($matricule) || empty($nom_etu) || empty($prenom_etu) || empty($sexe)) {
            $_SESSION['error'] = "Erreur: Les champs matricule, nom, prénom et sexe sont obligatoires.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        $pdo->beginTransaction();
        
        // Vérifier si l'étudiant existe déjà
        $stmt_check = $pdo->prepare("SELECT matricule FROM t_etudiant WHERE matricule = ?");
        $stmt_check->execute([$matricule]);
        
        if (!$stmt_check->fetch()) {
            // Créer l'étudiant s'il n'existe pas
            $stmt_create_student = $pdo->prepare("
                INSERT INTO t_etudiant (
                    matricule, nom_etu, postnom_etu, prenom_etu, sexe, date_naiss, 
                    lieu_naiss, email, telephone, date_ajout, date_mise_a_jour
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $stmt_create_student->execute([
                $matricule,
                $nom_etu,
                $postnom_etu,
                $prenom_etu,
                $sexe,
                $date_naiss ?: null,
                $lieu_naiss ?: null,
                $email ?: null,
                $telephone ?: null
            ]);
        }
        
        // Vérifier si déjà inscrit
        $stmt_check_inscription = $pdo->prepare("
            SELECT id_inscription 
            FROM t_inscription 
            WHERE matricule = ? 
              AND id_mention = ? 
              AND code_promotion = ? 
              AND id_annee = ?
        ");
        $stmt_check_inscription->execute([
            $matricule,
            $mention_id,
            $promotion_code,
            $annee_academique
        ]);
        
        if ($stmt_check_inscription->fetch()) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur: Cet étudiant est déjà inscrit dans cette mention/promotion pour cette année.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        // Créer l'inscription
        $stmt_insert_inscription = $pdo->prepare("
            INSERT INTO t_inscription (
                matricule,
                id_mention,
                code_promotion,
                id_annee,
                date_inscription,
                statut,
                username,
                id_filiere
            ) VALUES (?, ?, ?, ?, NOW(), 'Actif', ?, (SELECT idFiliere FROM t_mention WHERE id_mention = ?))
        ");
        
        $stmt_insert_inscription->execute([
            $matricule,
            $mention_id,
            $promotion_code,
            $annee_academique,
            $_SESSION['username'] ?? 'system',
            $mention_id
        ]);
        
        $pdo->commit();
        
        $_SESSION['success'] = "Étudiant inscrit avec succès !";
        header("Location: ?page=domaine&action=view&id=$id_domaine&mention=$mention_id&tab=inscriptions&promotion=$promotion_code&annee=$annee_academique&sub_tab=liste");
        exit;
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = "Erreur lors de l'inscription : " . htmlspecialchars($e->getMessage());
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}

// ============================================================================
// SUPPRESSION MULTIPLE D'INSCRIPTIONS
// ============================================================================
elseif ($action === 'desinscrire_multiple' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $ids = $_POST['selected_inscriptions'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            $_SESSION['error'] = "Erreur: Aucun étudiant sélectionné pour la suppression.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids)) {
            $_SESSION['error'] = "Erreur: Sélection invalide.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }

        $pdo->beginTransaction();

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql_delete = "
            DELETE FROM t_inscription
            WHERE id_inscription IN ($placeholders)
              AND id_mention = ?
              AND code_promotion = ?
              AND id_annee = ?
        ";
        $stmt_delete = $pdo->prepare($sql_delete);
        $params = array_merge($ids, [$mention_id, $promotion_code, $annee_academique]);
        $stmt_delete->execute($params);

        $deleted = $stmt_delete->rowCount();
        $pdo->commit();

        if ($deleted > 0) {
            $_SESSION['success'] = "$deleted inscription(s) supprimée(s) avec succès.";
        } else {
            $_SESSION['error'] = "Aucune inscription n'a été supprimée (éléments introuvables ou hors filtre courant).";
        }

        header("Location: ?page=domaine&action=view&id=$id_domaine&mention=$mention_id&tab=inscriptions&promotion=$promotion_code&annee=$annee_academique&sub_tab=liste");
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = "Erreur lors de la suppression multiple : " . htmlspecialchars($e->getMessage());
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}

// ============================================================================
// DÉSINSCRIPTION (SUPPRESSION SIMPLE)
// ============================================================================
elseif ($action === 'desinscrire' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id_inscription = filter_input(INPUT_POST, 'id_inscription', FILTER_VALIDATE_INT);
        
        if (!$id_inscription) {
            $_SESSION['error'] = "Erreur: Identifiant d'inscription invalide.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        // Supprimer l'inscription ciblée dans le périmètre courant
        $stmt = $pdo->prepare(" 
            DELETE FROM t_inscription 
            WHERE id_inscription = ?
              AND id_mention = ?
              AND code_promotion = ?
              AND id_annee = ?
        ");
        
        $stmt->execute([$id_inscription, $mention_id, $promotion_code, $annee_academique]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['success'] = "Inscription supprimée avec succès !";
        } else {
            $_SESSION['error'] = "Erreur: Inscription introuvable.";
        }
        
        header("Location: ?page=domaine&action=view&id=$id_domaine&mention=$mention_id&tab=inscriptions&promotion=$promotion_code&annee=$annee_academique&sub_tab=liste");
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Erreur lors de la désinscription : " . htmlspecialchars($e->getMessage());
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}

// ============================================================================
// DÉPLACEMENT D'INSCRITS (CHANGEMENT MENTION/PROMOTION)
// ============================================================================
elseif ($action === 'deplacer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $new_mention_id = filter_input(INPUT_POST, 'depl_mention_dest', FILTER_VALIDATE_INT);
        if (!$new_mention_id) {
            $new_mention_id = filter_input(INPUT_POST, 'new_mention_id', FILTER_VALIDATE_INT);
        }

        $new_promotion_code = trim($_POST['depl_promo_dest'] ?? '');
        if (empty($new_promotion_code)) {
            $new_promotion_code = trim($_POST['new_promotion_code'] ?? '');
        }

        $etudiants = $_POST['etudiants_deplacer'] ?? $_POST['etudiants'] ?? [];
        
        // Validation
        if (!$new_mention_id || empty($new_promotion_code)) {
            $_SESSION['error'] = "Erreur: nouvelle mention et promotion sont obligatoires.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        if (empty($etudiants) || !is_array($etudiants)) {
            $_SESSION['error'] = "Erreur: Aucun étudiant sélectionné.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        // Vérifier que le nouvelle mention exist e
        $stmt_check_mention = $pdo->prepare("SELECT id_mention FROM t_mention WHERE id_mention = ?");
        $stmt_check_mention->execute([$new_mention_id]);
        if (!$stmt_check_mention->fetch()) {
            $_SESSION['error'] = "Erreur: Nouvelle mention inexistante.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        // Vérifier promotion
        $stmt_check_promo = $pdo->prepare("SELECT code_promotion FROM t_promotion WHERE code_promotion = ?");
        $stmt_check_promo->execute([$new_promotion_code]);
        if (!$stmt_check_promo->fetch()) {
            $_SESSION['error'] = "Erreur: Nouvelle promotion inexistante.";
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        $pdo->beginTransaction();
        
        $deplacements_reussis = 0;
        $deplacements_echoues = 0;
        $details_erreurs = [];
        
        foreach ($etudiants as $matricule) {
            $matricule = trim($matricule);
            if (empty($matricule)) continue;
            
            try {
                // Vérifier que l'étudiant existe et que l'inscription existe
                $stmt_check_existing = $pdo->prepare("
                    SELECT id_inscription, id_mention, code_promotion
                    FROM t_inscription
                    WHERE matricule = ? AND id_annee = ?
                    LIMIT 1
                ");
                $stmt_check_existing->execute([$matricule, $annee_academique]);
                $existing = $stmt_check_existing->fetch(PDO::FETCH_ASSOC);
                
                if (!$existing) {
                    $details_erreurs[] = "Matricule $matricule : pas d'inscription trouvée pour cette année";
                    $deplacements_echoues++;
                    continue;
                }
                
                // Vérifier qu'il n'est pas déjà inscrit à la destination
                $stmt_check_dest = $pdo->prepare("
                    SELECT id_inscription 
                    FROM t_inscription
                    WHERE matricule = ? 
                      AND id_mention = ? 
                      AND code_promotion = ? 
                      AND id_annee = ?
                ");
                $stmt_check_dest->execute([
                    $matricule,
                    $new_mention_id,
                    $new_promotion_code,
                    $annee_academique
                ]);
                
                if ($stmt_check_dest->fetch()) {
                    $details_erreurs[] = "Matricule $matricule : déjà inscrit à la mention/promotion de destination";
                    $deplacements_echoues++;
                    continue;
                }
                
                // Effectuer le déplacement (mise à jour mention/promotion)
                $stmt_update = $pdo->prepare("
                    UPDATE t_inscription
                    SET id_mention = ?, code_promotion = ?
                    WHERE id_inscription = ? AND id_annee = ?
                ");
                
                $stmt_update->execute([
                    $new_mention_id,
                    $new_promotion_code,
                    $existing['id_inscription'],
                    $annee_academique
                ]);
                
                $deplacements_reussis++;
                
            } catch (PDOException $e) {
                $details_erreurs[] = "Matricule $matricule : " . $e->getMessage();
                $deplacements_echoues++;
            }
        }
        
        $pdo->commit();
        
        // Message de résultat
        if ($deplacements_reussis > 0) {
            $message = "<strong>Succès !</strong> $deplacements_reussis étudiant(s) déplacé(s) avec succès.";
            
            if ($deplacements_echoues > 0) {
                $message .= "<br><strong>Attention :</strong> $deplacements_echoues déplacement(s) ont échoué.";
                if (!empty($details_erreurs)) {
                    $message .= "<br><small>Détails :<ul class='mb-0 mt-1'>";
                    foreach (array_slice($details_erreurs, 0, 5) as $erreur) {
                        $message .= "<li>" . htmlspecialchars($erreur) . "</li>";
                    }
                    if (count($details_erreurs) > 5) {
                        $message .= "<li>... et " . (count($details_erreurs) - 5) . " autre(s) erreur(s)</li>";
                    }
                    $message .= "</ul></small>";
                }
            }
            
            $_SESSION['success'] = $message;
        } else {
            $message = "<strong>Erreur !</strong> Aucun étudiant n'a pu être déplacé.";
            if (!empty($details_erreurs)) {
                $message .= "<br><small>Détails :<ul class='mb-0 mt-1'>";
                foreach (array_slice($details_erreurs, 0, 10) as $erreur) {
                    $message .= "<li>" . htmlspecialchars($erreur) . "</li>";
                }
                $message .= "</ul></small>";
            }
            $_SESSION['error'] = $message;
        }
        
        // Redirection vers la liste des inscriptions
        header("Location: ?page=domaine&action=view&id=$id_domaine&mention=$mention_id&tab=inscriptions&promotion=$promotion_code&annee=$annee_academique&sub_tab=liste");
        exit;
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        $_SESSION['error'] = "Erreur lors du déplacement : " . htmlspecialchars($e->getMessage());
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}

?>
