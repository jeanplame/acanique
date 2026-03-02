<?php

function getCurrentAcademicYear($pdo) {
    try {
        // Vérifier s'il existe une année académique en cours dans la configuration
        $stmt = $pdo->prepare("SELECT valeur FROM t_configuration WHERE cle = 'annee_academique_courante'");
        $stmt->execute();
        $currentYear = $stmt->fetch(PDO::FETCH_COLUMN);

        if ($currentYear) {
            // Récupérer les détails de l'année académique
            $stmt = $pdo->prepare("SELECT * FROM t_anne_academique WHERE id_annee = ?");
            $stmt->execute([$currentYear]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return null;
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération de l'année académique : " . $e->getMessage());
        return null;
    }
}

function getAllAcademicYears($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM t_anne_academique ORDER BY date_debut DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des années académiques : " . $e->getMessage());
        return [];
    }
}

function getAllDomaines($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM t_domaine ORDER BY code_domaine");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des domaines : " . $e->getMessage());
        return [];
    }
}

function createAcademicYear($pdo, $dateDebut, $dateFin, $statut = 'active') {
    try {
        $stmt = $pdo->prepare("INSERT INTO t_anne_academique (date_debut, date_fin, statut) VALUES (?, ?, ?)");
        if ($stmt->execute([$dateDebut, $dateFin, $statut])) {
            $id = $pdo->lastInsertId();
            
            // Définir comme année courante si c'est la première année créée
            $stmt = $pdo->query("SELECT COUNT(*) FROM t_anne_academique");
            if ($stmt->fetchColumn() == 1) {
                $stmt = $pdo->prepare("INSERT INTO t_configuration (cle, valeur) VALUES ('annee_encours', ?) 
                                     ON DUPLICATE KEY UPDATE valeur = ?");
                $stmt->execute([$id, $id]);
            }
            
            return $id;
        }
        return false;
    } catch (PDOException $e) {
        error_log("Erreur lors de la création de l'année académique : " . $e->getMessage());
        return false;
    }
}

function updateAcademicYear($pdo, $idAnnee, $dateDebut, $dateFin, $statut = 'active') {
    try {
        $stmt = $pdo->prepare("UPDATE t_anne_academique SET date_debut = ?, date_fin = ?, statut = ? WHERE id_annee = ?");
        return $stmt->execute([$dateDebut, $dateFin, $statut, $idAnnee]);
    } catch (PDOException $e) {
        error_log("Erreur lors de la mise à jour de l'année académique : " . $e->getMessage());
        return false;
    }
}

function deleteAcademicYear($pdo, $idAnnee) {
    try {
        // Vérifier si c'est l'année en cours
        $stmt = $pdo->prepare("SELECT valeur FROM t_configuration WHERE cle = 'annee_encours'");
        $stmt->execute();
        $anneeEncours = $stmt->fetchColumn();
        
        if ($anneeEncours == $idAnnee) {
            return false; // Ne pas supprimer l'année en cours
        }
        
        // Vérifier s'il y a des semestres
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM t_semestre WHERE id_annee = ?");
        $stmt->execute([$idAnnee]);
        if ($stmt->fetchColumn() > 0) {
            return false; // Ne pas supprimer si des semestres existent
        }
        
        $stmt = $pdo->prepare("DELETE FROM t_anne_academique WHERE id_annee = ?");
        return $stmt->execute([$idAnnee]);
    } catch (PDOException $e) {
        error_log("Erreur lors de la suppression de l'année académique : " . $e->getMessage());
        return false;
    }
}

function setCurrentAcademicYear($pdo, $idAnnee) {
    try {
        $stmt = $pdo->prepare("INSERT INTO t_configuration (cle, valeur) VALUES ('annee_encours', ?) 
                              ON DUPLICATE KEY UPDATE valeur = ?");
        return $stmt->execute([$idAnnee, $idAnnee]);
    } catch (PDOException $e) {
        error_log("Erreur lors de la définition de l'année courante : " . $e->getMessage());
        return false;
    }
}

function createSemestersForYear($pdo, $idAnnee) {
    try {
        // Récupérer les dates de l'année
        $stmt = $pdo->prepare("SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?");
        $stmt->execute([$idAnnee]);
        $annee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$annee) {
            return false;
        }
        
        // Vérifier si des semestres existent déjà
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM t_semestre WHERE id_annee = ?");
        $stmt->execute([$idAnnee]);
        if ($stmt->fetchColumn() > 0) {
            return false; // Des semestres existent déjà
        }
        
        $dateDebut = $annee['date_debut'];
        $dateFin = $annee['date_fin'];
        
        // Calculer la mi-année
        $diff = (strtotime($dateFin) - strtotime($dateDebut)) / 2;
        $dateMoitie = date('Y-m-d', strtotime($dateDebut) + $diff);
        
        $pdo->beginTransaction();
        
        // Semestre 1
        $stmt1 = $pdo->prepare("INSERT INTO t_semestre (code_semestre, nom_semestre, id_annee, date_debut, date_fin, statut) 
                               VALUES (?, ?, ?, ?, ?, ?)");
        $stmt1->execute(['S1', 'Semestre 1', $idAnnee, $dateDebut, $dateMoitie, 'active']);
        
        // Semestre 2
        $dateDebutS2 = date('Y-m-d', strtotime($dateMoitie . ' +1 day'));
        $stmt2 = $pdo->prepare("INSERT INTO t_semestre (code_semestre, nom_semestre, id_annee, date_debut, date_fin, statut) 
                               VALUES (?, ?, ?, ?, ?, ?)");
        $stmt2->execute(['S2', 'Semestre 2', $idAnnee, $dateDebutS2, $dateFin, 'active']);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erreur lors de la création des semestres : " . $e->getMessage());
        return false;
    }
}

function deleteSemestersForYear($pdo, $idAnnee) {
    try {
        // Vérifier s'il y a des UE associées
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM t_unite_enseignement ue
            INNER JOIN t_semestre s ON ue.id_semestre = s.id_semestre
            WHERE s.id_annee = ?
        ");
        $stmt->execute([$idAnnee]);
        
        if ($stmt->fetchColumn() > 0) {
            return false; // Ne pas supprimer si des UE existent
        }
        
        $stmt = $pdo->prepare("DELETE FROM t_semestre WHERE id_annee = ?");
        return $stmt->execute([$idAnnee]);
    } catch (PDOException $e) {
        error_log("Erreur lors de la suppression des semestres : " . $e->getMessage());
        return false;
    }
}

function getAcademicYearStats($pdo, $idAnnee) {
    $stats = [
        'nb_semestres' => 0,
        'nb_inscriptions' => 0,
        'nb_ue' => 0,
        'nb_ec' => 0
    ];
    
    try {
        // Nombre de semestres
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM t_semestre WHERE id_annee = ?");
        $stmt->execute([$idAnnee]);
        $stats['nb_semestres'] = $stmt->fetchColumn();
        
        // Nombre d'inscriptions
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM t_inscription WHERE id_annee = ?");
        $stmt->execute([$idAnnee]);
        $stats['nb_inscriptions'] = $stmt->fetchColumn();
        
        // Nombre d'UE
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT ue.id_ue) 
            FROM t_unite_enseignement ue
            INNER JOIN t_semestre s ON ue.id_semestre = s.id_semestre
            WHERE s.id_annee = ?
        ");
        $stmt->execute([$idAnnee]);
        $stats['nb_ue'] = $stmt->fetchColumn();
        
        // Nombre d'EC
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT ec.id_ec) 
            FROM t_element_constitutif ec
            INNER JOIN t_unite_enseignement ue ON ec.id_ue = ue.id_ue
            INNER JOIN t_semestre s ON ue.id_semestre = s.id_semestre
            WHERE s.id_annee = ?
        ");
        $stmt->execute([$idAnnee]);
        $stats['nb_ec'] = $stmt->fetchColumn();
        
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des statistiques : " . $e->getMessage());
    }
    
    return $stats;
}

function createDomaine($pdo, $codeDomaine, $nomDomaine, $description = '') {
    try {
        $stmt = $pdo->prepare("INSERT INTO t_domaine (code_domaine, nom_domaine, description) VALUES (?, ?, ?)");
        return $stmt->execute([$codeDomaine, $nomDomaine, $description]);
    } catch (PDOException $e) {
        error_log("Erreur lors de la création du domaine : " . $e->getMessage());
        return false;
    }
}

/**
 * Récupère l'ID de l'année académique depuis l'URL ou retourne l'année en cours par défaut
 * 
 * @param PDO $pdo Instance PDO
 * @param string|null $getParam Valeur de $_GET['annee'] si disponible
 * @return int|null ID de l'année académique
 */
function getAnneeFromUrlOrDefault($pdo, $getParam = null) {
    try {
        // Si l'année est passée en paramètre GET et est valide
        if ($getParam !== null && is_numeric($getParam)) {
            $id_annee = (int)$getParam;
            // Vérifier que cette année existe
            $stmt = $pdo->prepare("SELECT id_annee FROM t_anne_academique WHERE id_annee = ?");
            $stmt->execute([$id_annee]);
            if ($stmt->fetchColumn()) {
                return $id_annee;
            }
        }
        
        // Sinon, récupérer l'année en cours depuis la configuration
        $stmt = $pdo->prepare("
            SELECT a.id_annee 
            FROM t_configuration c
            JOIN t_anne_academique a ON a.id_annee = c.valeur
            WHERE c.cle = 'annee_encours'
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? (int)$result['id_annee'] : null;
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération de l'année académique : " . $e->getMessage());
        return null;
    }
}

/**
 * Construit un paramètre d'URL avec l'année académique
 * 
 * @param int|null $id_annee ID de l'année académique (null = année en cours)
 * @return string Paramètre URL "&annee=X" ou chaîne vide
 */
function buildAnneeUrlParam($id_annee = null) {
    if ($id_annee === null) {
        return '';
    }
    return '&annee=' . intval($id_annee);
}

?>
