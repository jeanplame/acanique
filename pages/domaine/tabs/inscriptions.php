<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * Gestion des inscriptions étudiantes
 * Onglet pour la gestion des inscriptions, désinscriptions et réinscriptions
 * 
 * @version 2.0
 * @author Système Académique
 * @created 2024
 * @updated 2025-09-14
 */

// Démarrage de la session si nécessaire
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Protection contre l'accès direct
if (!defined('ACADEMIC_ACCESS') && !isset($_SESSION)) {
    http_response_code(403);
    exit('Accès interdit');
}

// Vérification de la connexion à la base de données
if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo '<div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i> 
            Erreur: La connexion à la base de données n\'est pas disponible.
          </div>';
    return;
}

// Fonction utilitaire pour la validation sécurisée des paramètres
function validateParam($value, $type = 'string', $min = null, $max = null) {
    if ($value === null || $value === '') return null;
    
    switch ($type) {
        case 'int':
            $val = filter_var($value, FILTER_VALIDATE_INT);
            if ($val === false) return null;
            if ($min !== null && $val < $min) return null;
            if ($max !== null && $val > $max) return null;
            return $val;
            
        case 'string':
            $val = trim(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
            if ($min !== null && strlen($val) < $min) return null;
            if ($max !== null && strlen($val) > $max) return null;
            return $val;
            
        case 'alphanumeric':
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) return null;
            return $value;
            
        default:
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

// Récupération et validation sécurisée des paramètres
$inscription_sub_tab = validateParam($_GET['sub_tab'] ?? 'liste', 'alphanumeric') ?: 'liste';
$id_domaine = validateParam($_GET['id'] ?? null, 'int', 1);
$mention_id = validateParam($_GET['mention'] ?? null, 'int', 1);
$promotion_code = validateParam($_GET['promotion'] ?? '', 'alphanumeric', 1, 20);
$annee_academique = validateParam($_GET['annee'] ?? null, 'string', 1, 20);

// Validation des onglets autorisés
$tabs_autorises = ['liste', 'inscrire', 'reinscrire'];
if (!in_array($inscription_sub_tab, $tabs_autorises)) {
    $inscription_sub_tab = 'liste';
}

// Génération d'un token CSRF pour les formulaires
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fonction pour déterminer la promotion inférieure dans la hiérarchie LMD
function getPromotionInferieure($code_promotion) {
    $hierarchie = [
        'L2' => 'L1',
        'L3' => 'L2',
        'M1' => 'L3',
        'M2' => 'M1'
    ];
    return $hierarchie[strtoupper($code_promotion)] ?? null;
}

// Fonction améliorée pour récupérer l'année académique active avec cache
function getAnneeAcademique($pdo) {
    static $cached_annee = null;
    
    if ($cached_annee !== null) {
        return $cached_annee;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id_annee FROM t_anne_academique WHERE date_fin >= CURDATE() ORDER BY date_debut DESC LIMIT 1");
        $stmt->execute();
        $annee = $stmt->fetch(PDO::FETCH_ASSOC);
        $cached_annee = $annee ? $annee['id_annee'] : null;
        return $cached_annee;
    } catch (PDOException $e) {
        error_log("Erreur récupération année académique: " . $e->getMessage());
        return null;
    }
}

// Récupération de l'année académique si non spécifiée
if (!$annee_academique) {
    $annee_academique = getAnneeAcademique($pdo);
}

// Validation des paramètres requis avec message amélioré
if (empty($promotion_code)) {
    echo '<div class="alert alert-warning border-0 shadow-sm">
              <div class="d-flex align-items-center">
                  <i class="bi bi-info-circle-fill me-3" style="font-size: 1.5rem;"></i>
                  <div>
                      <h6 class="alert-heading mb-1">Sélection requise</h6>
                      <p class="mb-0">Veuillez sélectionner une promotion pour afficher les inscriptions.</p>
                  </div>
              </div>
          </div>';
    return;
}

// Gestion sécurisée des actions avec validation CSRF
if (isset($_GET['action_inscription']) || isset($_POST['action_inscription'])) {
    // Vérification du token CSRF pour les actions POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            echo '<div class="alert alert-danger">
                    <i class="bi bi-shield-exclamation"></i> 
                    Erreur de sécurité: Token CSRF invalide.
                  </div>';
            return;
        }
    }
    
    if (file_exists(__DIR__ . '/actions_inscriptions.php')) {
        require __DIR__ . '/actions_inscriptions.php';
    }
}



?>

<div class="card shadow-sm border-0">
    <!-- En-tête avec statistiques rapides -->
    <div class="card-header bg-gradient-primary text-white">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">
                    <i class="bi bi-people-fill me-2"></i>
                    Gestion des Inscriptions
                </h5>
                <small class="opacity-75">
                    Promotion: <strong><?php echo htmlspecialchars($promotion_code); ?></strong> | 
                    Année: <strong><?php echo htmlspecialchars($annee_academique); ?></strong>
                </small>
            </div>
            <div class="text-end">
                <div class="btn-group">
                    <button type="button" class="btn btn-light btn-sm" onclick="refreshPage()">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                    <button type="button" class="btn btn-light btn-sm" onclick="exportData()">
                        <i class="bi bi-download"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Onglets améliorés avec icônes -->
    <div class="card-header bg-light border-bottom">
        <ul class="nav nav-tabs card-header-tabs border-0">
            <li class="nav-item">
                <a class="nav-link <?php echo $inscription_sub_tab === 'liste' ? 'active' : ''; ?> d-flex align-items-center"
                    href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=inscriptions&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $annee_academique; ?>&sub_tab=liste">
                    <i class="bi bi-list-ul me-2"></i>
                    <span>Liste des inscrits</span>
                    <span class="badge bg-primary ms-2" id="countInscrits">...</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $inscription_sub_tab === 'inscrire' ? 'active' : ''; ?> d-flex align-items-center"
                    href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=inscriptions&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $annee_academique; ?>&sub_tab=inscrire">
                    <i class="bi bi-person-plus me-2"></i>
                    <span>Nouvelle inscription</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $inscription_sub_tab === 'reinscrire' ? 'active' : ''; ?> d-flex align-items-center"
                    href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=inscriptions&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $annee_academique; ?>&sub_tab=reinscrire">
                    <i class="bi bi-arrow-repeat me-2"></i>
                    <span>Réinscription</span>
                </a>
            </li>
        </ul>
    </div>

    <div class="card-body">
        <!-- Contenu des sous-onglets -->
        <div class="tab-content">
            <?php if ($inscription_sub_tab === 'liste'): ?>
                <?php
                // Requête optimisée pour récupérer les inscriptions avec statistiques
                $sql_inscriptions = "
                    SELECT
                        i.id_inscription,
                        i.date_inscription,
                        i.statut,
                        e.matricule,
                        e.nom_etu,
                        e.postnom_etu,
                        e.prenom_etu,
                        e.sexe,
                        e.telephone,
                        e.email,
                        f.nomFiliere,
                        m.libelle AS nomMention,
                        p.nom_promotion,
                        TIMESTAMPDIFF(DAY, i.date_inscription, NOW()) as jours_depuis_inscription,
                        CASE 
                            WHEN i.statut = 'Actif' THEN 'success'
                            WHEN i.statut = 'En attente' THEN 'warning'
                            WHEN i.statut = 'Suspendu' THEN 'danger'
                            ELSE 'secondary'
                        END as badge_class
                    FROM t_inscription i
                    INNER JOIN t_etudiant e ON i.matricule = e.matricule
                    INNER JOIN t_mention m ON i.id_mention = m.id_mention
                    INNER JOIN t_filiere f ON m.idFiliere = f.idFiliere
                    LEFT JOIN t_promotion p ON i.code_promotion = p.code_promotion
                    WHERE i.id_annee = :annee_academique
                    AND i.id_mention = :mention_id
                    AND i.code_promotion = :promotion_code
                    ORDER BY 
                        CASE WHEN i.statut = 'Actif' THEN 1
                             WHEN i.statut = 'En attente' THEN 2
                             ELSE 3 END,
                        e.nom_etu ASC, e.postnom_etu ASC
                ";

                // Requête pour les statistiques
                $sql_stats = "
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN i.statut = 'Actif' THEN 1 ELSE 0 END) as actifs,
                        SUM(CASE WHEN i.statut = 'En attente' THEN 1 ELSE 0 END) as en_attente,
                        SUM(CASE WHEN i.statut = 'Suspendu' THEN 1 ELSE 0 END) as suspendus,
                        SUM(CASE WHEN e.sexe = 'M' THEN 1 ELSE 0 END) as hommes,
                        SUM(CASE WHEN e.sexe = 'F' THEN 1 ELSE 0 END) as femmes
                    FROM t_inscription i
                    INNER JOIN t_etudiant e ON i.matricule = e.matricule
                    WHERE i.id_annee = :annee_academique
                    AND i.id_mention = :mention_id
                    AND i.code_promotion = :promotion_code
                ";

                $stmt_inscriptions = $pdo->prepare($sql_inscriptions);
                $stmt_stats = $pdo->prepare($sql_stats);
                
                // Paramètres pour les requêtes
                $params = [
                    ':annee_academique' => $annee_academique,
                    ':mention_id' => $mention_id,
                    ':promotion_code' => $promotion_code
                ];

                try {
                    // Exécution des requêtes
                    $stmt_inscriptions->execute($params);
                    $inscriptions = $stmt_inscriptions->fetchAll(PDO::FETCH_ASSOC);
                    
                    $stmt_stats->execute($params);
                    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
                    
                } catch (PDOException $e) {
                    error_log("Erreur SQL inscriptions: " . $e->getMessage());
                    echo '<div class="alert alert-danger border-0">
                            <i class="bi bi-exclamation-triangle"></i>
                            Erreur lors de la récupération des inscriptions: ' . htmlspecialchars($e->getMessage()) . '
                          </div>';
                    $inscriptions = [];
                    $stats = ['total' => 0, 'actifs' => 0, 'en_attente' => 0, 'suspendus' => 0, 'hommes' => 0, 'femmes' => 0];
                }
                ?>
                
                <!-- Tableau de bord avec statistiques -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card bg-primary text-white h-100">
                            <div class="card-body d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="card-title mb-1">Total Inscrits</h6>
                                    <h3 class="mb-0"><?php echo $stats['total']; ?></h3>
                                </div>
                                <div class="ms-3">
                                    <i class="bi bi-people-fill" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card bg-success text-white h-100">
                            <div class="card-body d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="card-title mb-1">Actifs</h6>
                                    <h3 class="mb-0"><?php echo $stats['actifs']; ?></h3>
                                </div>
                                <div class="ms-3">
                                    <i class="bi bi-check-circle-fill" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card bg-warning text-white h-100">
                            <div class="card-body d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="card-title mb-1">En Attente</h6>
                                    <h3 class="mb-0"><?php echo $stats['en_attente']; ?></h3>
                                </div>
                                <div class="ms-3">
                                    <i class="bi bi-clock-fill" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card bg-info text-white h-100">
                            <div class="card-body d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="card-title mb-1">Répartition</h6>
                                    <small>H: <?php echo $stats['hommes']; ?> | F: <?php echo $stats['femmes']; ?></small>
                                </div>
                                <div class="ms-3">
                                    <i class="bi bi-gender-ambiguous" style="font-size: 2rem;"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Barre d'outils -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="d-flex align-items-center">
                        <h5 class="mb-0 me-3">
                            <i class="bi bi-table me-2"></i>Liste des Inscriptions
                        </h5>
                        <div class="input-group" style="width: 300px;">
                            <span class="input-group-text">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" 
                                   class="form-control" 
                                   id="searchInscriptions" 
                                   placeholder="Rechercher par nom, matricule..."
                                   onkeyup="filterTable()">
                        </div>
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportToCSV()">
                            <i class="bi bi-download"></i> CSV
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="printTable()">
                            <i class="bi bi-printer"></i> Imprimer
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise"></i> Actualiser
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <?php if (!empty($inscriptions)): ?>
                        <table class="table table-hover align-middle" id="inscriptionsTable">
                            <thead class="table-dark">
                                <tr>
                                    <th width="5%" class="text-center">#</th>
                                    <th width="12%">Matricule</th>
                                    <th width="25%">Étudiant</th>
                                    <th width="15%">Filière</th>
                                    <th width="12%">Contact</th>
                                    <th width="10%">Inscription</th>
                                    <th width="10%" class="text-center">Statut</th>
                                    <th width="11%" class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; ?>
                                <?php foreach ($inscriptions as $inscription): ?>
                                    <tr class="inscription-row">
                                        <td class="text-center fw-bold text-muted">
                                            <?php echo $counter++; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?php echo htmlspecialchars($inscription['matricule']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-circle me-3 bg-<?php echo $inscription['sexe'] === 'M' ? 'primary' : 'pink'; ?> text-white d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-person-fill"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold">
                                                        <?php echo htmlspecialchars($inscription['nom_etu'] . ' ' . $inscription['postnom_etu']); ?>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($inscription['prenom_etu']); ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($inscription['nomFiliere']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($inscription['nom_promotion'] ?: 'Non défini'); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($inscription['telephone'])): ?>
                                                <div class="mb-1">
                                                    <i class="bi bi-telephone text-primary me-1"></i>
                                                    <small><?php echo htmlspecialchars($inscription['telephone']); ?></small>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($inscription['email'])): ?>
                                                <div>
                                                    <i class="bi bi-envelope text-success me-1"></i>
                                                    <small><?php echo htmlspecialchars($inscription['email']); ?></small>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (empty($inscription['telephone']) && empty($inscription['email'])): ?>
                                                <small class="text-muted">Non renseigné</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="text-center">
                                                <div class="fw-semibold"><?php echo date('d/m/Y', strtotime($inscription['date_inscription'])); ?></div>
                                                <small class="text-muted">
                                                    Il y a <?php echo $inscription['jours_depuis_inscription']; ?> jours
                                                </small>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?php echo $inscription['badge_class']; ?> px-3 py-2">
                                                <i class="bi bi-<?php 
                                                    echo $inscription['statut'] === 'Actif' ? 'check-circle' : 
                                                         ($inscription['statut'] === 'En attente' ? 'clock' : 'x-circle'); 
                                                ?>"></i>
                                                <?php echo htmlspecialchars($inscription['statut']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group" role="group">
                                                <button type="button" 
                                                        class="btn btn-outline-info btn-sm" 
                                                        title="Voir le profil"
                                                        onclick="voirProfil('<?php echo htmlspecialchars($inscription['matricule']); ?>')">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                
                                                <?php if ($inscription['statut'] === 'En attente'): ?>
                                                    <button type="button" 
                                                            class="btn btn-outline-success btn-sm" 
                                                            title="Activer l'inscription"
                                                            onclick="changerStatut(<?php echo $inscription['id_inscription']; ?>, 'Actif')">
                                                        <i class="bi bi-check-lg"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($inscription['statut'] === 'Actif'): ?>
                                                    <button type="button" 
                                                            class="btn btn-outline-warning btn-sm" 
                                                            title="Suspendre"
                                                            onclick="changerStatut(<?php echo $inscription['id_inscription']; ?>, 'Suspendu')">
                                                        <i class="bi bi-pause"></i>
                                                    </button>
                                                <?php elseif ($inscription['statut'] === 'Suspendu'): ?>
                                                    <button type="button" 
                                                            class="btn btn-outline-success btn-sm" 
                                                            title="Réactiver"
                                                            onclick="changerStatut(<?php echo $inscription['id_inscription']; ?>, 'Actif')">
                                                        <i class="bi bi-play"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button type="button" 
                                                        class="btn btn-outline-danger btn-sm" 
                                                        title="Désinscrire"
                                                        onclick="confirmerDesinscription(<?php echo $inscription['id_inscription']; ?>, '<?php echo htmlspecialchars($inscription['nom_etu'] . ' ' . $inscription['postnom_etu']); ?>')">
                                                    <i class="bi bi-person-dash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <div class="mb-4">
                                <i class="bi bi-people" style="font-size: 5rem; color: #6c757d; opacity: 0.5;"></i>
                            </div>
                            <h4 class="text-muted mb-3">Aucune inscription trouvée</h4>
                            <p class="text-muted mb-4 px-4">
                                Il n'y a actuellement aucun étudiant inscrit pour cette promotion 
                                (<strong><?php echo htmlspecialchars($promotion_code); ?></strong>) 
                                dans l'année académique <strong><?php echo htmlspecialchars($annee_academique); ?></strong>.
                            </p>
                            <div class="d-flex flex-column flex-sm-row justify-content-center gap-3">
                                <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=inscriptions&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $annee_academique; ?>&sub_tab=inscrire" 
                                   class="btn btn-primary btn-lg">
                                    <i class="bi bi-person-plus me-2"></i>
                                    Inscrire un nouvel étudiant
                                </a>
                                <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=inscriptions&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $annee_academique; ?>&sub_tab=reinscrire" 
                                   class="btn btn-outline-secondary btn-lg">
                                    <i class="bi bi-arrow-repeat me-2"></i>
                                    Réinscrire un étudiant
                                </a>
                            </div>
                            <?php
                            // Suggestion automatique : étudiants de la promotion inférieure
                            $promo_inferieure = getPromotionInferieure($promotion_code);
                            if ($promo_inferieure) {
                                try {
                                    $sql_suggestion = "
                                        SELECT COUNT(*) as nb_etudiants
                                        FROM t_inscription i
                                        INNER JOIN t_mention m ON i.id_mention = m.id_mention
                                        INNER JOIN t_filiere f ON m.idFiliere = f.idFiliere
                                        INNER JOIN t_anne_academique aa ON i.id_annee = aa.id_annee
                                        WHERE f.id_domaine = :id_domaine
                                          AND i.id_mention = :mention_id
                                          AND i.code_promotion = :promo_inf
                                          AND i.statut = 'Actif'
                                          AND aa.date_fin < CURDATE()
                                          AND i.matricule NOT IN (
                                              SELECT i2.matricule FROM t_inscription i2
                                              WHERE i2.code_promotion = :promo_actuelle
                                                AND i2.id_mention = :mention_id2
                                                AND i2.id_annee = :annee_actuelle
                                          )
                                    ";
                                    $stmt_suggestion = $pdo->prepare($sql_suggestion);
                                    $stmt_suggestion->execute([
                                        ':id_domaine' => $id_domaine,
                                        ':mention_id' => $mention_id,
                                        ':promo_inf' => $promo_inferieure,
                                        ':promo_actuelle' => $promotion_code,
                                        ':mention_id2' => $mention_id,
                                        ':annee_actuelle' => $annee_academique
                                    ]);
                                    $nb_suggestion = $stmt_suggestion->fetch(PDO::FETCH_ASSOC)['nb_etudiants'];
                                    
                                    if ($nb_suggestion > 0) {
                            ?>
                            <hr class="my-4">
                            <div class="row justify-content-center">
                                <div class="col-md-10">
                                    <div class="card border-primary border-2">
                                        <div class="card-body text-start">
                                            <h6 class="card-title text-primary">
                                                <i class="bi bi-lightbulb-fill text-warning me-2"></i>
                                                Suggestion de réinscription
                                            </h6>
                                            <p class="mb-3">
                                                <strong><?php echo $nb_suggestion; ?></strong> étudiant(s) de la promotion 
                                                <strong><?php echo htmlspecialchars($promo_inferieure); ?></strong> 
                                                (années passées) sont éligibles pour une réinscription en 
                                                <strong><?php echo htmlspecialchars($promotion_code); ?></strong>.
                                            </p>
                                            <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=inscriptions&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $annee_academique; ?>&sub_tab=reinscrire" 
                                               class="btn btn-primary">
                                                <i class="bi bi-arrow-repeat me-2"></i>
                                                Voir et réinscrire ces étudiants
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php
                                    }
                                } catch (PDOException $e) {
                                    error_log("Erreur suggestion réinscription: " . $e->getMessage());
                                }
                            }
                            ?>
                            <hr class="my-4">
                            <div class="row justify-content-center">
                                <div class="col-md-8">
                                    <div class="card bg-light border-0">
                                        <div class="card-body">
                                            <h6 class="card-title">
                                                <i class="bi bi-lightbulb text-warning me-2"></i>
                                                Suggestions
                                            </h6>
                                            <ul class="list-unstyled mb-0 text-start">
                                                <li><i class="bi bi-check2 text-success me-2"></i>Vérifiez que la promotion sélectionnée est correcte</li>
                                                <li><i class="bi bi-check2 text-success me-2"></i>Assurez-vous que l'année académique est active</li>
                                                <li><i class="bi bi-check2 text-success me-2"></i>Consultez les autres promotions de cette mention</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($inscription_sub_tab === 'inscrire'): ?>
                <!-- Le contenu du formulaire d'inscription sera inclus ici -->
                <?php include 'formulaire_inscription.php'; ?>
            <?php elseif ($inscription_sub_tab === 'reinscrire'): ?>
                <!-- Formulaire de réinscription multiple amélioré -->
                <div class="row">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-arrow-repeat me-2"></i>
                                    Réinscription d'étudiants
                                </h5>
                            </div>
                            <div class="card-body">
                                <!-- Formulaire de configuration de la réinscription -->
                                <form method="POST" 
                                      action="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=inscriptions&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $annee_academique; ?>&sub_tab=reinscrire"
                                      id="formReinscrire"
                                      class="needs-validation" novalidate>
                                    
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action_inscription" value="reinscrire">
                                    
                                    <?php
                                    $promo_inf_reinscrire = getPromotionInferieure($promotion_code);
                                    ?>
                                    <div class="alert alert-info border-0 mb-4">
                                        <i class="bi bi-info-circle-fill me-2"></i>
                                        <strong>Réinscription :</strong> Sélectionnez un ou plusieurs étudiants à réinscrire dans la promotion <strong><?php echo htmlspecialchars($promotion_code); ?></strong> pour l'année académique <strong><?php echo htmlspecialchars($annee_academique); ?></strong>.
                                    </div>
                                    <?php if ($promo_inf_reinscrire): ?>
                                    <div class="alert alert-warning border-0 mb-4">
                                        <i class="bi bi-arrow-up-circle-fill me-2"></i>
                                        <strong>Source :</strong> La liste ci-dessous affiche les étudiants de la promotion <strong><?php echo htmlspecialchars($promo_inf_reinscrire); ?></strong> inscrits lors d'années académiques passées, qui ne sont pas encore inscrits en <strong><?php echo htmlspecialchars($promotion_code); ?></strong> pour l'année <strong><?php echo htmlspecialchars($annee_academique); ?></strong>.
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="row mb-4">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="nouvelle_mention" class="form-label fw-semibold">
                                                    <i class="bi bi-bookmark me-1"></i>
                                                    Nouvelle Mention *
                                                </label>
                                                <select class="form-select form-select-lg" 
                                                        id="nouvelle_mention" 
                                                        name="new_mention_id" 
                                                        required>
                                                    <option value="">Sélectionner une mention...</option>
                                                    <?php
                                                    try {
                                                        $stmt_mentions = $pdo->prepare("
                                                            SELECT DISTINCT m.id_mention, m.libelle, m.code_mention 
                                                            FROM t_mention m
                                                            INNER JOIN t_filiere f ON m.idFiliere = f.idFiliere
                                                            WHERE f.id_domaine = ? 
                                                            ORDER BY m.libelle
                                                        ");
                                                        $stmt_mentions->execute([$id_domaine]);
                                                        while ($mention = $stmt_mentions->fetch(PDO::FETCH_ASSOC)):
                                                    ?>
                                                        <option value="<?php echo $mention['id_mention']; ?>"
                                                                <?php echo ($mention['id_mention'] == $mention_id) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($mention['libelle'] . ' (' . $mention['code_mention'] . ')'); ?>
                                                        </option>
                                                    <?php 
                                                        endwhile;
                                                    } catch (PDOException $e) {
                                                        echo '<option value="">Erreur de chargement</option>';
                                                    }
                                                    ?>
                                                </select>
                                                <div class="invalid-feedback">
                                                    Veuillez sélectionner une mention
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="nouvelle_promotion" class="form-label fw-semibold">
                                                    <i class="bi bi-award me-1"></i>
                                                    Nouvelle Promotion *
                                                </label>
                                                <select class="form-select form-select-lg" 
                                                        id="nouvelle_promotion" 
                                                        name="new_promotion_code" 
                                                        required>
                                                    <option value="">Sélectionner une promotion...</option>
                                                    <?php
                                                    try {
                                                        $stmt_promotions = $pdo->prepare("SELECT code_promotion, nom_promotion FROM t_promotion ORDER BY nom_promotion");
                                                        $stmt_promotions->execute();
                                                        while ($promotion = $stmt_promotions->fetch(PDO::FETCH_ASSOC)):
                                                    ?>
                                                        <option value="<?php echo $promotion['code_promotion']; ?>"
                                                                <?php echo ($promotion['code_promotion'] == $promotion_code) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($promotion['nom_promotion'] . ' (' . $promotion['code_promotion'] . ')'); ?>
                                                        </option>
                                                    <?php 
                                                        endwhile;
                                                    } catch (PDOException $e) {
                                                        echo '<option value="">Erreur de chargement</option>';
                                                    }
                                                    ?>
                                                </select>
                                                <div class="invalid-feedback">
                                                    Veuillez sélectionner une promotion
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Liste des étudiants à réinscrire -->
                                    <div class="card border">
                                        <div class="card-header bg-light">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h6 class="mb-0">
                                                    <i class="bi bi-people me-2"></i>
                                                    Sélectionner les étudiants à réinscrire
                                                </h6>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="selectAll">
                                                    <label class="form-check-label fw-semibold" for="selectAll">
                                                        Tout sélectionner
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="table-responsive" style="max-height: 500px;">
                                                <table class="table table-hover mb-0">
                                                    <thead class="table-light sticky-top">
                                                        <tr>
                                                            <th width="50">
                                                                <input type="checkbox" id="selectAllHeader" class="form-check-input">
                                                            </th>
                                                            <th>Matricule</th>
                                                            <th>Nom & Prénom</th>
                                                            <th>Sexe</th>
                                                            <th>Mention Actuelle</th>
                                                            <th>Promotion Actuelle</th>
                                                            <th>Année d'inscription</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        try {
                                                            // Déterminer la promotion inférieure pour suggérer les étudiants à réinscrire
                                                            $promo_source = getPromotionInferieure($promotion_code);
                                                            $promotion_recherche = $promo_source ?: $promotion_code;
                                                            
                                                            // Récupérer les étudiants de la promotion inférieure (années passées uniquement)
                                                            // ou de la même promotion si pas de promotion inférieure
                                                            $query_etudiants = "
                                                                SELECT DISTINCT
                                                                    e.matricule,
                                                                    e.nom_etu,
                                                                    e.postnom_etu,
                                                                    e.prenom_etu,
                                                                    e.sexe,
                                                                    m.libelle as mention_libelle,
                                                                    m.code_mention,
                                                                    i.code_promotion,
                                                                    p.nom_promotion,
                                                                    CONCAT(DATE_FORMAT(aa.date_debut, '%Y'), '-', DATE_FORMAT(aa.date_fin, '%Y')) as annee_academique,
                                                                    i.id_inscription
                                                                FROM t_inscription i
                                                                INNER JOIN t_etudiant e ON i.matricule = e.matricule
                                                                INNER JOIN t_mention m ON i.id_mention = m.id_mention
                                                                INNER JOIN t_promotion p ON i.code_promotion = p.code_promotion
                                                                INNER JOIN t_anne_academique aa ON i.id_annee = aa.id_annee
                                                                INNER JOIN t_filiere f ON m.idFiliere = f.idFiliere
                                                                WHERE f.id_domaine = ?
                                                                  AND i.statut = 'Actif'
                                                                  AND i.code_promotion = ?
                                                                  AND aa.date_fin < CURDATE()
                                                                  AND i.matricule NOT IN (
                                                                      SELECT i2.matricule FROM t_inscription i2
                                                                      WHERE i2.code_promotion = ?
                                                                        AND i2.id_annee = ?
                                                                  )
                                                                ORDER BY e.nom_etu, e.postnom_etu, e.prenom_etu
                                                            ";
                                                            
                                                            $stmt_etudiants = $pdo->prepare($query_etudiants);
                                                            $stmt_etudiants->execute([$id_domaine, $promotion_recherche, $promotion_code, $annee_academique]);
                                                            $etudiants = $stmt_etudiants->fetchAll(PDO::FETCH_ASSOC);
                                                            
                                                            if (count($etudiants) > 0):
                                                                foreach ($etudiants as $etudiant):
                                                        ?>
                                                            <tr>
                                                                <td>
                                                                    <div class="form-check">
                                                                        <input class="form-check-input etudiant-checkbox" 
                                                                               type="checkbox" 
                                                                               name="etudiants[]" 
                                                                               value="<?php echo htmlspecialchars($etudiant['matricule']); ?>"
                                                                               id="etudiant_<?php echo htmlspecialchars($etudiant['matricule']); ?>">
                                                                    </div>
                                                                </td>
                                                                <td class="fw-semibold">
                                                                    <?php echo htmlspecialchars($etudiant['matricule']); ?>
                                                                </td>
                                                                <td>
                                                                    <?php echo htmlspecialchars($etudiant['nom_etu'] . ' ' . $etudiant['postnom_etu'] . ' ' . $etudiant['prenom_etu']); ?>
                                                                </td>
                                                                <td>
                                                                    <?php if ($etudiant['sexe'] == 'M'): ?>
                                                                        <span class="badge bg-primary">
                                                                            <i class="bi bi-gender-male"></i> M
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-pink">
                                                                            <i class="bi bi-gender-female"></i> F
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <small><?php echo htmlspecialchars($etudiant['mention_libelle']); ?></small>
                                                                </td>
                                                                <td>
                                                                    <span class="badge bg-secondary">
                                                                        <?php echo htmlspecialchars($etudiant['code_promotion']); ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <small class="text-muted">
                                                                        <?php echo htmlspecialchars($etudiant['annee_academique']); ?>
                                                                    </small>
                                                                </td>
                                                            </tr>
                                                        <?php 
                                                                endforeach;
                                                            else:
                                                        ?>
                                                            <tr>
                                                                <td colspan="7" class="text-center py-4 text-muted">
                                                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                                    Aucun étudiant trouvé dans ce domaine
                                                                </td>
                                                            </tr>
                                                        <?php 
                                                            endif;
                                                        } catch (PDOException $e) {
                                                            echo '<tr><td colspan="7" class="text-center text-danger py-4">
                                                                    <i class="bi bi-exclamation-triangle"></i> 
                                                                    Erreur de chargement: ' . htmlspecialchars($e->getMessage()) . '
                                                                  </td></tr>';
                                                        }
                                                        ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="card-footer bg-light">
                                            <small class="text-muted">
                                                <span id="compteur-selection">0</span> étudiant(s) sélectionné(s)
                                            </small>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center mt-4">
                                        <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention_id; ?>&tab=inscriptions&promotion=<?php echo $promotion_code; ?>&annee=<?php echo $annee_academique; ?>&sub_tab=liste" 
                                           class="btn btn-outline-secondary btn-lg">
                                            <i class="bi bi-arrow-left me-2"></i>
                                            Annuler
                                        </a>
                                        <button type="submit" class="btn btn-primary btn-lg" id="btnReinscrire" disabled>
                                            <i class="bi bi-check-lg me-2"></i>
                                            Réinscrire <span id="nombre-selection">0</span> étudiant(s)
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <script>
                // Script pour gérer la sélection multiple
                document.addEventListener('DOMContentLoaded', function() {
                    const selectAll = document.getElementById('selectAll');
                    const selectAllHeader = document.getElementById('selectAllHeader');
                    const checkboxes = document.querySelectorAll('.etudiant-checkbox');
                    const compteur = document.getElementById('compteur-selection');
                    const nombreSelection = document.getElementById('nombre-selection');
                    const btnReinscrire = document.getElementById('btnReinscrire');
                    
                    function updateCounter() {
                        const checked = document.querySelectorAll('.etudiant-checkbox:checked').length;
                        compteur.textContent = checked;
                        nombreSelection.textContent = checked;
                        btnReinscrire.disabled = checked === 0;
                    }
                    
                    // Sélectionner/désélectionner tout
                    selectAll.addEventListener('change', function() {
                        checkboxes.forEach(cb => cb.checked = this.checked);
                        selectAllHeader.checked = this.checked;
                        updateCounter();
                    });
                    
                    selectAllHeader.addEventListener('change', function() {
                        checkboxes.forEach(cb => cb.checked = this.checked);
                        selectAll.checked = this.checked;
                        updateCounter();
                    });
                    
                    // Mise à jour du compteur
                    checkboxes.forEach(cb => {
                        cb.addEventListener('change', function() {
                            const allChecked = Array.from(checkboxes).every(c => c.checked);
                            const noneChecked = Array.from(checkboxes).every(c => !c.checked);
                            
                            selectAll.checked = allChecked;
                            selectAllHeader.checked = allChecked;
                            selectAll.indeterminate = !allChecked && !noneChecked;
                            selectAllHeader.indeterminate = !allChecked && !noneChecked;
                            
                            updateCounter();
                        });
                    });
                    
                    // Validation du formulaire
                    const form = document.getElementById('formReinscrire');
                    form.addEventListener('submit', function(e) {
                        const checked = document.querySelectorAll('.etudiant-checkbox:checked').length;
                        if (checked === 0) {
                            e.preventDefault();
                            alert('Veuillez sélectionner au moins un étudiant à réinscrire.');
                            return false;
                        }
                        
                        if (!confirm(`Êtes-vous sûr de vouloir réinscrire ${checked} étudiant(s) ?`)) {
                            e.preventDefault();
                            return false;
                        }
                    });
                });
                </script>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Styles CSS personnalisés -->
<style>
.bg-gradient-primary {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
}

.bg-pink {
    background-color: #e83e8c !important;
}

.avatar-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    font-size: 18px;
}

.card {
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

.table th {
    font-weight: 600;
    font-size: 0.9rem;
    border-bottom: 2px solid #dee2e6;
}

.table td {
    font-size: 0.85rem;
    vertical-align: middle;
}

.btn-group .btn {
    margin: 0 1px;
}

.btn:hover {
    transform: translateY(-1px);
    transition: all 0.2s ease;
}

.inscription-row:hover {
    background-color: rgba(0, 123, 255, 0.05);
}

.nav-link.active {
    background-color: #007bff !important;
    color: white !important;
}

.alert {
    border: none;
    border-radius: 10px;
}

.form-control:focus, .form-select:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

@media (max-width: 768px) {
    .btn-group {
        flex-direction: column;
    }
    
    .btn-group .btn {
        margin: 1px 0;
        border-radius: 0.375rem !important;
    }
    
    .avatar-circle {
        width: 30px;
        height: 30px;
        font-size: 14px;
    }
    
    .table td, .table th {
        font-size: 0.8rem;
        padding: 0.5rem;
    }
}

/* Animation de chargement */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

.loading::after {
    content: "";
    position: absolute;
    top: 50%;
    left: 50%;
    width: 20px;
    height: 20px;
    margin: -10px 0 0 -10px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #007bff;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<!-- JavaScript pour les fonctionnalités interactives -->
<script>
// Mise à jour du compteur dans l'onglet
document.addEventListener('DOMContentLoaded', function() {
    const countElement = document.getElementById('countInscrits');
    if (countElement) {
        const tableRows = document.querySelectorAll('#inscriptionsTable tbody tr');
        countElement.textContent = tableRows.length;
    }
    
    // Validation Bootstrap
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
});

// Fonction de recherche dans le tableau
function filterTable() {
    const searchTerm = document.getElementById('searchInscriptions').value.toLowerCase();
    const tableRows = document.querySelectorAll('#inscriptionsTable tbody tr');
    let visibleCount = 0;
    
    tableRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    // Mise à jour du compteur
    const countElement = document.getElementById('countInscrits');
    if (countElement) {
        countElement.textContent = searchTerm ? visibleCount : tableRows.length;
    }
}

// Fonction pour voir le profil
function voirProfil(matricule) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Profil Étudiant',
            html: `
                <div class="text-start">
                    <div class="d-flex align-items-center mb-3">
                        <div class="avatar-circle bg-primary text-white d-flex align-items-center justify-content-center me-3">
                            <i class="bi bi-person-fill"></i>
                        </div>
                        <div>
                            <h6 class="mb-0">Matricule: ${matricule}</h6>
                            <small class="text-muted">Étudiant inscrit</small>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Le profil détaillé sera disponible dans une prochaine version.
                    </div>
                </div>
            `,
            icon: 'info',
            confirmButtonText: 'Fermer',
            confirmButtonColor: '#007bff'
        });
    } else {
        alert('Profil de l\'étudiant: ' + matricule);
    }
}

// Fonction pour changer le statut
function changerStatut(idInscription, nouveauStatut) {
    const statutTexte = {
        'Actif': { text: 'Actif', icon: 'success' },
        'En attente': { text: 'En Attente', icon: 'warning' },
        'Suspendu': { text: 'Suspendu', icon: 'error' }
    };
    
    const statut = statutTexte[nouveauStatut];
    
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Confirmer le changement',
            text: `Voulez-vous vraiment changer le statut vers "${statut.text}" ?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Oui, changer',
            cancelButtonText: 'Annuler',
            confirmButtonColor: '#007bff',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                // Construction de l'URL
                const url = new URL(window.location);
                url.searchParams.set('action_inscription', 'changer_statut');
                url.searchParams.set('id_inscription', idInscription);
                url.searchParams.set('nouveau_statut', nouveauStatut);
                
                // Redirection
                window.location.href = url.toString();
            }
        });
    } else {
        if (confirm(`Voulez-vous vraiment changer le statut vers "${statut.text}" ?`)) {
            const url = new URL(window.location);
            url.searchParams.set('action_inscription', 'changer_statut');
            url.searchParams.set('id_inscription', idInscription);
            url.searchParams.set('nouveau_statut', nouveauStatut);
            window.location.href = url.toString();
        }
    }
}

// Fonction pour confirmer la désinscription
function confirmerDesinscription(idInscription, nomEtudiant) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Désinscrire l\'étudiant',
            html: `
                <div class="text-start">
                    <p>Voulez-vous vraiment désinscrire <strong>${nomEtudiant}</strong> ?</p>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Attention :</strong> Cette action est irréversible et supprimera toutes les données d'inscription.
                    </div>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Oui, désinscrire',
            cancelButtonText: 'Annuler',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                const url = new URL(window.location);
                url.searchParams.set('action_inscription', 'desinscrire');
                url.searchParams.set('id_inscription', idInscription);
                window.location.href = url.toString();
            }
        });
    } else {
        if (confirm(`Voulez-vous vraiment désinscrire ${nomEtudiant} ? Cette action est irréversible.`)) {
            const url = new URL(window.location);
            url.searchParams.set('action_inscription', 'desinscrire');
            url.searchParams.set('id_inscription', idInscription);
            window.location.href = url.toString();
        }
    }
}

// Fonction d'export CSV
function exportToCSV() {
    const table = document.getElementById('inscriptionsTable');
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [];
        const cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length - 1; j++) { // Exclure la colonne Actions
            let cellText = cols[j].innerText.replace(/"/g, '""');
            cellText = cellText.replace(/\n/g, ' ').trim();
            row.push('"' + cellText + '"');
        }
        
        csv.push(row.join(','));
    }
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', `inscriptions_<?php echo $promotion_code; ?>_${new Date().toISOString().split('T')[0]}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Fonction d'impression
function printTable() {
    const printContent = document.getElementById('inscriptionsTable');
    if (!printContent) return;
    
    const originalContent = document.body.innerHTML;
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <html>
        <head>
            <title>Liste des Inscriptions - <?php echo htmlspecialchars($promotion_code); ?></title>
            <link href="../../../css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { font-family: Arial, sans-serif; font-size: 12px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; font-weight: bold; }
                .no-print { display: none; }
                @media print {
                    .btn-group, .btn { display: none !important; }
                }
            </style>
        </head>
        <body>
            <div class="container-fluid">
                <h2 class="text-center mb-4">Liste des Inscriptions</h2>
                <p><strong>Promotion:</strong> <?php echo htmlspecialchars($promotion_code); ?></p>
                <p><strong>Année Académique:</strong> <?php echo htmlspecialchars($annee_academique); ?></p>
                <p><strong>Date d'impression:</strong> ${new Date().toLocaleDateString('fr-FR')}</p>
                <hr>
                ${printContent.outerHTML}
            </div>
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.print();
}

// Fonctions utilitaires
function refreshPage() {
    location.reload();
}

function exportData() {
    exportToCSV();
}

// Gestion des tooltips
document.addEventListener('DOMContentLoaded', function() {
    // Initialiser les tooltips Bootstrap si disponible
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
});
</script>