<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * Contrôleur pour la gestion des notes - VERSION CORRIGÉE
 * 
 * Ce fichier gère l'affichage des onglets de notes (cotation et délibération)
 * et assure la validation des paramètres et la sécurité des données.
 * 
 * @author ACANIQUE Academic System
 * @version 2.0
 * @date 2025-09-12
 * 
 * Paramètres requis:
 * - id: ID du domaine (entier positif)
 * - mention: ID de la mention (entier positif) 
 * - promotion: Code de promotion (format: L1, L2, L3, M1, M2, D1, D2, D3)
 * - tabnotes: Onglet actif (cotation|deliberation) [optionnel, défaut: cotation]
 * - semestre: ID du semestre (entier positif) [optionnel]
 */

// Vérification de la connexion à la base de données
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log("Erreur: Connexion à la base de données non disponible dans notes.php");
    echo '<div class="alert alert-danger">Erreur de connexion à la base de données.</div>';
    exit;
}

// ==========================
// Récupération de l'année académique courante
// ==========================

try {
    $sql = "
        SELECT a.id_annee, a.date_debut, a.date_fin, a.statut
        FROM t_configuration c
        JOIN t_anne_academique a ON a.id_annee = c.valeur
        WHERE c.cle = 'annee_encours'
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $annee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$annee) {
        // Tentative de récupération de l'année par défaut basée sur la date actuelle
        $sql_default = "
            SELECT id_annee, date_debut, date_fin, statut
            FROM t_anne_academique 
            WHERE date_debut <= CURDATE() AND date_fin >= CURDATE() AND statut = 'active'
            ORDER BY date_debut DESC
            LIMIT 1
        ";
        $stmt_default = $pdo->prepare($sql_default);
        $stmt_default->execute();
        $annee = $stmt_default->fetch(PDO::FETCH_ASSOC);
        
        if (!$annee) {
            throw new Exception("Aucune année académique active trouvée");
        }
        
        // Log pour l'administrateur
        error_log("Attention: Année académique courante non configurée. Utilisation de l'année par défaut: " . $annee['id_annee']);
    }

    $annee_academique = $annee['id_annee'];
    $id_annee = $annee_academique;

} catch (PDOException $e) {
    error_log("Erreur SQL lors de la récupération de l'année académique: " . $e->getMessage());
    echo '<div class="alert alert-danger">Erreur lors de la récupération de l\'année académique.</div>';
    exit;
} catch (Exception $e) {
    error_log("Erreur: " . $e->getMessage());
    echo '<div class="alert alert-warning">Aucune année académique active trouvée. Veuillez contacter l\'administrateur.</div>';
    exit;
}

// ==========================
// Validation et sécurisation des paramètres
// ==========================

// Fonction utilitaire pour valider les entiers
function validateInteger($value, $min = 1, $max = PHP_INT_MAX) {
    $int_value = filter_var($value, FILTER_VALIDATE_INT);
    return ($int_value !== false && $int_value >= $min && $int_value <= $max) ? $int_value : null;
}

// Fonction utilitaire pour valider les chaînes de caractères
function validateString($value, $pattern = '/^[a-zA-Z0-9_-]+$/', $max_length = 10) {
    $clean_value = trim(filter_var($value, FILTER_SANITIZE_STRING));
    return (strlen($clean_value) <= $max_length && preg_match($pattern, $clean_value)) ? $clean_value : null;
}

// Définir l'onglet actif par défaut avec validation
$allowed_tabs = ['cotation', 'deliberation'];
$notes_tab = isset($_GET['tabnotes']) && in_array($_GET['tabnotes'], $allowed_tabs) 
    ? $_GET['tabnotes'] 
    : 'cotation';

// Validation et récupération sécurisée des paramètres
$id_domaine = isset($_GET['id']) ? validateInteger($_GET['id']) : null;
$mention_id = isset($_GET['mention']) ? validateInteger($_GET['mention']) : null;
$promotion_code = isset($_GET['promotion']) ? validateString($_GET['promotion'], '/^[L|M|D][1-3]$/', 5) : null;
$id_semestre = isset($_GET['semestre']) ? validateInteger($_GET['semestre'], 1, 10) : null;

// Validation des paramètres obligatoires
$missing_params = [];
if (!$id_domaine) $missing_params[] = 'ID du domaine';
if (!$mention_id) $missing_params[] = 'ID de la mention';
if (!$promotion_code) $missing_params[] = 'Code de promotion';

// Vérification de la présence des paramètres de base
if (!empty($missing_params)) {
    $error_message = 'Paramètres manquants ou invalides : ' . implode(', ', $missing_params);
    error_log("Erreur de paramètres dans notes.php: " . $error_message . " - URL: " . $_SERVER['REQUEST_URI']);
    echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>';
    echo '<div class="alert alert-info">
            <strong>Aide :</strong> 
            <ul>
                <li>ID domaine doit être un entier positif</li>
                <li>ID mention doit être un entier positif</li>
                <li>Code promotion doit suivre le format L1, L2, L3, M1, M2, D1, D2, D3</li>
            </ul>
          </div>';
    exit;
}

// Vérification supplémentaire : s'assurer que les entités existent en base
try {
    // Vérifier l'existence du domaine
    $stmt_domaine = $pdo->prepare("SELECT id_domaine FROM t_domaine WHERE id_domaine = ?");
    $stmt_domaine->execute([$id_domaine]);
    if (!$stmt_domaine->fetch()) {
        throw new Exception("Le domaine spécifié n'existe pas");
    }

    // Vérifier l'existence de la mention et sa relation avec le domaine
    $stmt_mention = $pdo->prepare("
        SELECT m.id_mention 
        FROM t_mention m 
        INNER JOIN t_filiere f ON m.idFiliere = f.idFiliere 
        WHERE m.id_mention = ? AND f.id_domaine = ?
    ");
    $stmt_mention->execute([$mention_id, $id_domaine]);
    if (!$stmt_mention->fetch()) {
        throw new Exception("La mention spécifiée n'existe pas dans ce domaine");
    }

    // Vérifier l'existence de l'association promotion-mention
    $stmt_promo = $pdo->prepare("
        SELECT ap.id_asso 
        FROM t_association_promo ap 
        WHERE ap.code_promotion = ? AND ap.id_mention = ?
    ");
    $stmt_promo->execute([$promotion_code, $mention_id]);
    if (!$stmt_promo->fetch()) {
        throw new Exception("L'association promotion-mention spécifiée n'existe pas");
    }

} catch (Exception $e) {
    error_log("Erreur de validation des entités: " . $e->getMessage());
    echo '<div class="alert alert-danger">Les paramètres fournis ne correspondent à aucune donnée valide.</div>';
    exit;
}

// ---- Fonctions de récupération des données optimisées ----

/**
 * Récupère la liste des éléments constitutifs (EC) pour une promotion, un semestre et une mention donnés.
 *
 * @param PDO $pdo L'objet de connexion à la base de données.
 * @param string $promotion_code Le code de la promotion (ex: 'L1', 'L2').
 * @param int $id_semestre L'identifiant du semestre.
 * @param int $mention_id L'identifiant de la mention.
 * @return array Un tableau d'EC.
 * @throws Exception En cas d'erreur de base de données.
 */
function getECsForPromotion($pdo, $promotion_code, $id_semestre, $mention_id)
{
    try {
        // Requête optimisée avec indexation appropriée
        $sql = "
            SELECT DISTINCT
                ue.id_ue,
                ue.code_ue,
                ue.libelle AS libelle_ue,
                ue.credits_ue,
                ec.id_ec,
                ec.libelle AS libelle_ec,
                ec.credits_ec,
                mue.semestre
            FROM t_unite_enseignement ue
            INNER JOIN t_mention_ue mue ON ue.id_ue = mue.id_ue
            INNER JOIN t_association_promo ap ON mue.id_mention = ap.id_mention
            LEFT JOIN t_element_constitutif ec ON ue.id_ue = ec.id_ue
            WHERE ap.code_promotion = :code_promo
                AND mue.semestre = :id_semestre
                AND mue.id_mention = :id_mention
                AND ue.is_programmed = 1
                AND (ec.is_programmed = 1 OR ec.id_ec IS NULL)
            ORDER BY ue.libelle ASC, ec.libelle ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'code_promo' => $promotion_code,
            'id_semestre' => $id_semestre,
            'id_mention' => $mention_id
        ]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log pour le debogage en cas de resultats vides
        if (empty($results)) {
            error_log("Aucun EC trouve pour: promotion=$promotion_code, semestre=$id_semestre, mention=$mention_id");
        }
        
        return $results;
        
    } catch (PDOException $e) {
        error_log("Erreur SQL dans getECsForPromotion: " . $e->getMessage());
        throw new Exception("Erreur lors de la récupération des éléments constitutifs");
    }
}

/**
 * Récupère la liste des étudiants d'une promotion et d'une mention avec gestion d'erreurs.
 *
 * @param PDO $pdo L'objet de connexion à la base de données.
 * @param string $promotion_code Le code de la promotion.
 * @param int $mention_id L'identifiant de la mention.
 * @return array Un tableau d'étudiants.
 * @throws Exception En cas d'erreur de base de données.
 */
function getStudentsForPromotionAndMention($pdo, $promotion_code, $mention_id)
{
    try {
        // Vérifier d'abord si la vue existe
        $stmt_check = $pdo->query("SHOW TABLES LIKE 'vue_etudiants_mention_promotion'");
        if ($stmt_check->rowCount() === 0) {
            // Fallback vers une requête directe si la vue n'existe pas
            $sql = "
                SELECT DISTINCT
                    e.id_etudiant,
                    e.nom,
                    e.prenom,
                    e.numero_etudiant,
                    e.email,
                    i.code_promotion,
                    m.id_mention,
                    m.nom_mention
                FROM t_etudiant e
                INNER JOIN t_inscription i ON e.id_etudiant = i.id_etudiant
                INNER JOIN t_association_promo ap ON i.code_promotion = ap.code_promotion
                INNER JOIN t_mention m ON ap.id_mention = m.id_mention
                WHERE i.code_promotion = :code_promotion 
                    AND m.id_mention = :id_mention
                    AND i.statut = 'actif'
                    AND e.statut = 'actif'
                ORDER BY e.nom ASC, e.prenom ASC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'code_promotion' => $promotion_code,
                'id_mention' => $mention_id
            ]);
        } else {
            // Utiliser la vue si elle existe
            $sql = "
                SELECT * 
                FROM vue_etudiants_mention_promotion 
                WHERE code_promotion = :code_promotion 
                    AND id_mention = :id_mention
                ORDER BY nom ASC, prenom ASC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'code_promotion' => $promotion_code,
                'id_mention' => $mention_id
            ]);
        }
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log pour le debogage
        if (empty($results)) {
            error_log("Aucun etudiant trouve pour: promotion=$promotion_code, mention=$mention_id");
        }
        
        return $results;
        
    } catch (PDOException $e) {
        error_log("Erreur SQL dans getStudentsForPromotionAndMention: " . $e->getMessage());
        throw new Exception("Erreur lors de la récupération des étudiants");
    }
}

?>

<div class="container-fluid mt-4">

    <!-- Onglets de navigation -->
    <ul class="nav nav-tabs" id="notesTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo ($notes_tab === 'cotation') ? 'active' : ''; ?>" id="cotation-tab"
                href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=notes&promotion=<?php echo $promotion_code; ?>&tabnotes=cotation"
                role="tab">
                <i class="fas fa-edit me-2"></i>Fiche de cotation
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo ($notes_tab === 'deliberation') ? 'active' : ''; ?>" id="deliberation-tab"
                href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=notes&promotion=<?php echo $promotion_code; ?>&tabnotes=deliberation"
                role="tab">
                <i class="fas fa-table me-2"></i>Grille de délibération
            </a>
        </li>
    </ul>

    <!-- Contenu des onglets -->
    <div class="tab-content mt-3" id="notesTabsContent">
        <?php
        // Inclusion sécurisée des fichiers correspondant à l'onglet actif
        $allowed_files = [
            'cotation' => 'cotation.php',
            'deliberation' => 'deliberation.php'
        ];
        
        if (isset($allowed_files[$notes_tab])) {
            $file_to_include = $allowed_files[$notes_tab];
            $file_path = __DIR__ . '/' . $file_to_include;
            
            // Vérification de l'existence et de la sécurité du fichier
            if (file_exists($file_path) && is_readable($file_path)) {
                // Vérifier que le fichier est dans le bon répertoire (protection contre path traversal)
                $real_path = realpath($file_path);
                $allowed_dir = realpath(__DIR__);
                
                if ($real_path && strpos($real_path, $allowed_dir) === 0) {
                    try {
                        include $file_path;
                    } catch (Exception $e) {
                        error_log("Erreur lors de l'inclusion de $file_to_include: " . $e->getMessage());
                        echo '<div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Erreur lors du chargement du contenu.
                              </div>';
                    }
                } else {
                    error_log("Tentative d'accès non autorisé au fichier: $file_path");
                    echo '<div class="alert alert-danger">
                            <i class="fas fa-lock me-2"></i>
                            Accès non autorisé.
                          </div>';
                }
            } else {
                error_log("Fichier non trouvé ou non lisible: $file_path");
                echo '<div class="alert alert-warning">
                        <i class="fas fa-file-excel me-2"></i>
                        Contenu non disponible pour cet onglet (fichier manquant).
                        <br><small class="text-muted">Fichier attendu: ' . htmlspecialchars($file_to_include) . '</small>
                      </div>';
            }
        } else {
            // Onglet non reconnu
            echo '<div class="alert alert-warning">
                    <i class="fas fa-question-circle me-2"></i>
                    Contenu non disponible pour cet onglet.
                  </div>';
        }
        ?>
    </div>
</div>

<script>
// Amélioration de l'expérience utilisateur
document.addEventListener('DOMContentLoaded', function() {
    // Animation des onglets
    const tabs = document.querySelectorAll('.nav-link');
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Ajouter un effet de chargement
            const content = document.getElementById('notesTabsContent');
            content.style.opacity = '0.5';
            setTimeout(() => {
                content.style.opacity = '1';
            }, 200);
        });
    });
    
    // Affichage des informations de contexte
    const contextInfo = `
        <div class="alert alert-info mb-3" style="font-size: 0.9em;">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Contexte:</strong> 
            Domaine #<?php echo $id_domaine; ?> | 
            Mention #<?php echo $mention_id; ?> | 
            Promotion <?php echo $promotion_code; ?> | 
            Année <?php echo $id_annee; ?>
            <?php if ($id_semestre): ?>
                | Semestre <?php echo $id_semestre; ?>
            <?php endif; ?>
        </div>
    `;
    
    const container = document.querySelector('.container-fluid');
    if (container) {
        container.insertAdjacentHTML('afterbegin', contextInfo);
    }
});
</script>