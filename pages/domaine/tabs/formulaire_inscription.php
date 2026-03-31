<?php
header('Content-Type: text/html; charset=UTF-8');
/**
 * Formulaire d'inscription des étudiants
 * Gestion de l'inscription d'un nouvel étudiant avec validation complète
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

// Protection CSRF
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fonction de validation sécurisée
function validateInput($data, $type = 'string', $required = false, $maxLength = null)
{
    if (empty($data) && $required) {
        return ['valid' => false, 'message' => 'Ce champ est obligatoire.'];
    }

    if (empty($data)) {
        return ['valid' => true, 'value' => ''];
    }

    switch ($type) {
        case 'email':
            $email = filter_var($data, FILTER_VALIDATE_EMAIL);
            if (!$email) {
                return ['valid' => false, 'message' => 'Format d\'email invalide.'];
            }
            return ['valid' => true, 'value' => $email];

        case 'phone':
            $phone = preg_replace('/[^0-9+\-\s\(\)]/', '', $data);
            if (strlen($phone) < 8) {
                return ['valid' => false, 'message' => 'Numéro de téléphone trop court.'];
            }
            return ['valid' => true, 'value' => $phone];

        case 'date':
            $date = DateTime::createFromFormat('Y-m-d', $data);
            if (!$date) {
                return ['valid' => false, 'message' => 'Format de date invalide.'];
            }
            return ['valid' => true, 'value' => $data];

        case 'string':
        default:
            $cleaned = htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
            if ($maxLength && strlen($cleaned) > $maxLength) {
                return ['valid' => false, 'message' => "Maximum {$maxLength} caractères autorisés."];
            }
            return ['valid' => true, 'value' => $cleaned];
    }
}

// Fonction pour générer un matricule unique
function generateMatricule($pdo)
{
    $attempts = 0;
    $maxAttempts = 10;

    do {
        $attempts++;
        $matricule = 'ETU' . date('y') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM t_etudiant WHERE matricule = ?");
        $stmt->execute([$matricule]);
        $exists = $stmt->fetchColumn() > 0;

        if (!$exists) {
            return $matricule;
        }
    } while ($attempts < $maxAttempts);

    throw new Exception("Impossible de générer un matricule unique après {$maxAttempts} tentatives.");
}

// Gestion sécurisée de l'année académique
$currentYear = date('Y');
$startDate = "$currentYear-09-01";
$endDate = ($currentYear + 1) . "-06-30";

try {
    $sql = "
        SELECT a.id_annee, a.date_debut, a.date_fin, a.statut, a.annee_academique
        FROM t_configuration c
        JOIN t_anne_academique a ON a.id_annee = c.valeur
        WHERE c.cle = 'annee_encours'
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $annee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$annee) {
        throw new Exception("Aucune année académique active configurée.");
    }

    $annee_academique = $annee['id_annee'];
    $date_debut = $annee['date_debut'];
    $date_fin = $annee['date_fin'];
    $statut = $annee['statut'];
    $annee_libelle = $annee['annee_academique'];
} catch (Exception $e) {
    error_log("Erreur récupération année académique: " . $e->getMessage());
    $error = "Erreur de configuration: Année académique non disponible.";
}

// Initialisation sécurisée des variables
$error = null;
$success = null;
$validation_errors = [];
$mention_id = filter_var($_GET['mention'] ?? null, FILTER_VALIDATE_INT);
$promotion_code = $_GET['promotion'] ?? '';
$id_domaine = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

// Validation des paramètres requis
if (!$mention_id || !$promotion_code || !$id_domaine) {
    $error = "Paramètres manquants. Veuillez accéder au formulaire depuis la page appropriée.";
}

// Récupération sécurisée des informations de filière
$id_filiere_form = null;
if ($mention_id && $promotion_code) {
    try {
        // Récupération et vérification de la filière en une seule requête
        $stmt = $pdo->prepare("
            SELECT t1.idFiliere
            FROM t_mention t1
            JOIN t_filiere_promotion t2 ON t1.idFiliere = t2.id_filiere
            WHERE t1.id_mention = ? AND t2.code_promotion = ?
        ");
        $stmt->execute([$mention_id, $promotion_code]);
        $id_filiere_form = $stmt->fetchColumn();

        // Fallback si aucune filière n'est trouvée avec cette mention et cette promotion
        if (!$id_filiere_form) {
            $stmtFiliere = $pdo->prepare("
                SELECT f.idFiliere
                FROM t_filiere f
                WHERE f.id_domaine = ?
                ORDER BY f.nomFiliere
                LIMIT 1
            ");
            $stmtFiliere->execute([$id_domaine]);
            $id_filiere_form = $stmtFiliere->fetchColumn();
        }
    } catch (Exception $e) {
        error_log("Erreur récupération filière: " . $e->getMessage());
        $id_filiere_form = null;
    }
}



// Traitement sécurisé du formulaire avec validation complète
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification du token CSRF

    // Validation de tous les champs
    $fields = [
        'nom_etu' => validateInput($_POST['nom_etu'] ?? '', 'string', true, 50),
        'postnom_etu' => validateInput($_POST['postnom_etu'] ?? '', 'string', true, 50),
        'prenom_etu' => validateInput($_POST['prenom_etu'] ?? '', 'string', true, 50),
        'sexe' => validateInput($_POST['sexe'] ?? '', 'string', true),
        'date_naiss' => validateInput($_POST['date_naiss'] ?? '', 'date', false),
        'lieu_naiss' => validateInput($_POST['lieu_naiss'] ?? '', 'string', false, 100),
        'nationalite' => validateInput($_POST['nationalite'] ?? '', 'string', false, 50),
        'adresse' => validateInput($_POST['adresse'] ?? '', 'string', false, 255),
        'telephone' => validateInput($_POST['telephone'] ?? '', 'phone', false),
        'email' => validateInput($_POST['email'] ?? '', 'email', false),
        'nom_pere' => validateInput($_POST['nom_pere'] ?? '', 'string', false, 100),
        'nom_mere' => validateInput($_POST['nom_mere'] ?? '', 'string', false, 100)
    ];

    // Validation du sexe
    if ($fields['sexe']['valid'] && !in_array($fields['sexe']['value'], ['M', 'F'])) {
        $fields['sexe'] = ['valid' => false, 'message' => 'Sexe invalide.'];
    }

    // Validation de la date de naissance
    if ($fields['date_naiss']['valid'] && !empty($fields['date_naiss']['value'])) {
        $birthDate = new DateTime($fields['date_naiss']['value']);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;

        if ($age < 15 || $age > 80) {
            $fields['date_naiss'] = ['valid' => false, 'message' => 'Âge doit être entre 15 et 80 ans.'];
        }
    }

    // Collecte des erreurs de validation
    foreach ($fields as $fieldName => $validation) {
        if (!$validation['valid']) {
            $validation_errors[$fieldName] = $validation['message'];
        }
    }

    // Récupération et validation des champs cachés
    $annee_academique_form = filter_var($_POST['annee_academique'] ?? null, FILTER_VALIDATE_INT);
    $mention_id_form = filter_var($_POST['mention_id'] ?? null, FILTER_VALIDATE_INT);
    $promotion_code_form = $_GET['promotion'] ?? '';
    $id_filiere_form_post = filter_var($_POST['id_filiere'] ?? null, FILTER_VALIDATE_INT);

    if (!$annee_academique_form || !$mention_id_form || !$promotion_code_form || !$id_filiere_form_post) {
        $error = "Erreur: Informations de contexte manquantes.";
    }

    // Si aucune erreur de validation
    if (empty($validation_errors) && !$error) {
        try {
            // Début de la transaction
            $pdo->beginTransaction();

            // Génération du matricule unique
            $matricule = generateMatricule($pdo);

            // Préparation des données avec timestamps
            $date_ajout = date('Y-m-d H:i:s');
            $date_mise_a_jour = date('Y-m-d H:i:s');
            $username = $_SESSION['user_id'] ?? 'system';

            // Insertion de l'étudiant
            $stmt_insert_etu = $pdo->prepare("
                    INSERT INTO t_etudiant (
                        matricule, nom_etu, postnom_etu, prenom_etu, sexe, date_naiss, 
                        lieu_naiss, nationalite, adresse, telephone, email, nom_pere, 
                        nom_mere, date_ajout, date_mise_a_jour
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

            // Gestion de la date de naissance (NULL si vide)
            $date_naiss_value = !empty($fields['date_naiss']['value']) ? $fields['date_naiss']['value'] : null;

            $stmt_insert_etu->execute([
                $matricule,
                $fields['nom_etu']['value'],
                $fields['postnom_etu']['value'],
                $fields['prenom_etu']['value'],
                $fields['sexe']['value'],
                $date_naiss_value,
                $fields['lieu_naiss']['value'],
                $fields['nationalite']['value'],
                $fields['adresse']['value'],
                $fields['telephone']['value'],
                $fields['email']['value'],
                $fields['nom_pere']['value'],
                $fields['nom_mere']['value'],
                $date_ajout,
                $date_mise_a_jour
            ]);

            // Insertion de l'inscription
            $date_inscription = date('Y-m-d H:i:s');
            $statut_inscription = 'Actif';

            $stmt_insert_inscription = $pdo->prepare("
                    INSERT INTO t_inscription (
                        username, matricule, id_filiere, id_mention, id_annee, 
                        code_promotion, date_inscription, statut, date_mise_a_jour
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

            $stmt_insert_inscription->execute([
                $username,
                $matricule,
                $id_filiere_form_post,
                $mention_id_form,
                $annee_academique_form,
                $promotion_code_form,
                $date_inscription,
                $statut_inscription,
                $date_mise_a_jour
            ]);

            // Validation de la transaction
            $pdo->commit();

            // Message de succès
            $success_message = "L'étudiant " . htmlspecialchars($fields['prenom_etu']['value']) . " " .
                htmlspecialchars($fields['nom_etu']['value']) . " a été inscrit avec succès. " .
                "Matricule attribué : " . $matricule;

            // Redirection avec message de succès
            $redirect_url = "?page=domaine&action=view&id=$id_domaine&mention=$mention_id_form&tab=inscriptions&promotion=$promotion_code_form&annee=$annee_academique_form&sub_tab=inscrire";

            header("Location: $redirect_url");
            exit();
        } catch (Exception $e) {
            // Annulation de la transaction en cas d'erreur
            $pdo->rollBack();
            error_log("Erreur inscription étudiant: " . $e->getMessage());
            $error = "Erreur lors de l'inscription : " . $e->getMessage();
        }
    }
}
?>

<!-- Styles CSS modernes pour le formulaire d'inscription -->
<link href="../../includes/css/bootstrap-icons.css" rel="stylesheet">
<script src="../../includes/dist/sweetalert2.all.min.js"></script>



<!-- Card principale avec design moderne -->
<div class="card shadow-lg border-0">
    <div class="card-header card-header-modern">
        <h4 class="mb-0 d-flex align-items-center">
            <span class="me-2">👤</span>
            Inscription d'un Nouvel Étudiant
        </h4>
        <small class="opacity-75">Veuillez remplir tous les champs obligatoires marqués d'un astérisque (*)</small>
    </div>
    <div class="card-body p-4">

        <!-- Affichage des erreurs -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>Erreur :</strong> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Importation de la police Plus Jakarta Sans pour un rendu ultra-moderne -->
        <style>
            /* =========================================
       DESIGN SYSTEM - FORMULAIRE COMPACT
       ========================================= */
            .ultra-compact-wrapper {
                max-width: 850px;
                margin: 2rem auto;
                padding: 2rem;
                background: #ffffff;
                border-radius: 8px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.03);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            }

            /* Grilles personnalisées pour réduire l'espace */
            .c-grid-3 {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 12px;
            }

            .c-grid-2 {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .c-grid-1 {
                display: grid;
                grid-template-columns: 1fr;
                gap: 12px;
            }

            @media (max-width: 768px) {

                .c-grid-3,
                .c-grid-2 {
                    grid-template-columns: 1fr;
                }
            }

            /* Sections minimalistes avec accent à gauche */
            .c-section {
                border-left: 3px solid #e2e8f0;
                padding-left: 1.25rem;
                margin-bottom: 2rem;
            }

            .c-section h5 {
                font-size: 0.85rem;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: #64748b;
                margin-top: 0;
                margin-bottom: 1rem;
                font-weight: 700;
            }

            /* =========================================
       NOUVEAUX LABELS FLOTTANTS (Sur-mesure)
       ========================================= */
            .c-input-group {
                position: relative;
                width: 100%;
                margin-bottom: 4px;
            }

            .c-input {
                width: 100%;
                box-sizing: border-box;
                height: 42px;
                /* Hauteur très compacte */
                padding: 16px 12px 4px 12px;
                /* Espace en haut pour le petit label */
                font-size: 13px;
                color: #0f172a;
                background-color: #f8fafc;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                outline: none;
                transition: all 0.2s ease;
            }

            textarea.c-input {
                height: 80px;
                resize: vertical;
            }

            /* Le label par défaut au centre */
            .c-label {
                position: absolute;
                left: 12px;
                top: 12px;
                font-size: 13px;
                color: #94a3b8;
                pointer-events: none;
                transition: transform 0.2s ease, font-size 0.2s ease, color 0.2s ease;
                transform-origin: left top;
            }

            /* L'animation quand on clique (focus) ou qu'il y a du texte (not placeholder-shown) */
            .c-input:focus,
            .c-input:not(:placeholder-shown) {
                background-color: #ffffff;
                border-color: #0ea5e9;
                box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
            }

            .c-input:focus~.c-label,
            .c-input:not(:placeholder-shown)~.c-label,
            .c-label.active

            /* Forcé pour les Select/Date */
                {
                transform: translateY(-8px);
                font-size: 10px;
                color: #0ea5e9;
                font-weight: 600;
            }

            /* Gestion des erreurs */
            .c-input.has-error {
                border-color: #ef4444;
                background-color: #fef2f2;
            }

            .c-input.has-error:focus~.c-label,
            .c-input.has-error:not(:placeholder-shown)~.c-label {
                color: #ef4444;
            }

            .c-error-msg {
                display: block;
                font-size: 11px;
                color: #ef4444;
                margin-top: 4px;
                margin-left: 2px;
            }

            /* Boutons compacts */
            .c-actions {
                display: flex;
                justify-content: flex-end;
                gap: 12px;
                margin-top: 2rem;
                padding-top: 1.5rem;
                border-top: 1px solid #f1f5f9;
            }

            .c-btn {
                padding: 0 1.25rem;
                height: 38px;
                font-size: 13px;
                font-weight: 600;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.2s;
                border: none;
            }

            .c-btn-reset {
                background: transparent;
                color: #64748b;
                border: 1px solid #cbd5e1;
            }

            .c-btn-reset:hover {
                background: #f1f5f9;
                color: #0f172a;
            }

            .c-btn-submit {
                background: #0ea5e9;
                color: white;
                box-shadow: 0 2px 4px rgba(14, 165, 233, 0.2);
            }

            .c-btn-submit:hover {
                background: #0284c7;
                box-shadow: 0 4px 8px rgba(14, 165, 233, 0.3);
            }
        </style>

        <div class="ultra-compact-wrapper">
            <form method="POST" id="inscriptionForm" novalidate>
                <!-- Token CSRF et variables cachées (Inchangés) -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="annee_academique" value="<?= htmlspecialchars($annee_academique) ?>">
                <input type="hidden" name="mention_id" value="<?= htmlspecialchars($mention_id) ?>">
                <input type="hidden" name="promotion_code" value="<?= htmlspecialchars($promotion_code) ?>">
                <input type="hidden" name="id_filiere" value="<?= htmlspecialchars($id_filiere_form) ?>">

                <!-- Section Informations Personnelles -->
                <div class="c-section">
                    <h5>Informations Personnelles</h5>

                    <div class="c-grid-3">
                        <div class="c-input-group">
                            <!-- IMPORTANT : Le placeholder=" " (avec espace) est vital pour faire fonctionner le label flottant purement en CSS -->
                            <input type="text" id="nom_etu" name="nom_etu" placeholder=" " required maxlength="50"
                                class="c-input <?= isset($validation_errors['nom_etu']) ? 'has-error' : '' ?>"
                                value="<?= htmlspecialchars($_POST['nom_etu'] ?? '') ?>">
                            <label for="nom_etu" class="c-label">Nom de famille *</label>
                            <?php if (isset($validation_errors['nom_etu'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['nom_etu']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="c-input-group">
                            <input type="text" id="postnom_etu" name="postnom_etu" placeholder=" " required maxlength="50"
                                class="c-input <?= isset($validation_errors['postnom_etu']) ? 'has-error' : '' ?>"
                                value="<?= htmlspecialchars($_POST['postnom_etu'] ?? '') ?>">
                            <label for="postnom_etu" class="c-label">Post-nom *</label>
                            <?php if (isset($validation_errors['postnom_etu'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['postnom_etu']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="c-input-group">
                            <input type="text" id="prenom_etu" name="prenom_etu" placeholder=" " required maxlength="50"
                                class="c-input <?= isset($validation_errors['prenom_etu']) ? 'has-error' : '' ?>"
                                value="<?= htmlspecialchars($_POST['prenom_etu'] ?? '') ?>">
                            <label for="prenom_etu" class="c-label">Prénom *</label>
                            <?php if (isset($validation_errors['prenom_etu'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['prenom_etu']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="c-grid-2 mt-3" style="margin-top: 12px;">
                        <div class="c-input-group">
                            <select id="sexe" name="sexe" required
                                class="c-input <?= isset($validation_errors['sexe']) ? 'has-error' : '' ?>">
                                <option value="" disabled <?= empty($_POST['sexe']) ? 'selected' : '' ?>></option>
                                <option value="M" <?= (($_POST['sexe'] ?? '') === 'M') ? 'selected' : '' ?>>Masculin</option>
                                <option value="F" <?= (($_POST['sexe'] ?? '') === 'F') ? 'selected' : '' ?>>Féminin</option>
                            </select>
                            <!-- Classe 'active' ajoutée pour forcer le label en haut pour les menus déroulants -->
                            <label for="sexe" class="c-label active">Sexe *</label>
                            <?php if (isset($validation_errors['sexe'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['sexe']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="c-input-group">
                            <input type="date" id="date_naiss" name="date_naiss"
                                max="<?= date('Y-m-d', strtotime('-15 years')) ?>"
                                min="<?= date('Y-m-d', strtotime('-80 years')) ?>"
                                class="c-input <?= isset($validation_errors['date_naiss']) ? 'has-error' : '' ?>"
                                value="<?= htmlspecialchars($_POST['date_naiss'] ?? '') ?>">
                            <label for="date_naiss" class="c-label active">Date de naissance</label>
                            <?php if (isset($validation_errors['date_naiss'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['date_naiss']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Section Informations Complémentaires -->
                <div class="c-section">
                    <h5>Informations Complémentaires</h5>
                    <div class="c-grid-2">
                        <div class="c-input-group">
                            <input type="text" id="lieu_naiss" name="lieu_naiss" placeholder=" " maxlength="100"
                                class="c-input <?= isset($validation_errors['lieu_naiss']) ? 'has-error' : '' ?>"
                                value="<?= htmlspecialchars($_POST['lieu_naiss'] ?? '') ?>">
                            <label for="lieu_naiss" class="c-label">Lieu de naissance</label>
                            <?php if (isset($validation_errors['lieu_naiss'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['lieu_naiss']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="c-input-group">
                            <input type="text" id="nationalite" name="nationalite" placeholder=" " maxlength="50"
                                class="c-input <?= isset($validation_errors['nationalite']) ? 'has-error' : '' ?>"
                                value="<?= htmlspecialchars($_POST['nationalite'] ?? '') ?>">
                            <label for="nationalite" class="c-label">Nationalité</label>
                            <?php if (isset($validation_errors['nationalite'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['nationalite']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="c-grid-1" style="margin-top: 12px;">
                        <div class="c-input-group">
                            <textarea id="adresse" name="adresse" placeholder=" " maxlength="255"
                                class="c-input <?= isset($validation_errors['adresse']) ? 'has-error' : '' ?>"><?= htmlspecialchars($_POST['adresse'] ?? '') ?></textarea>
                            <label for="adresse" class="c-label">Adresse complète</label>
                            <?php if (isset($validation_errors['adresse'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['adresse']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Section Contact -->
                <div class="c-section">
                    <h5>Contact</h5>
                    <div class="c-grid-2">
                        <div class="c-input-group">
                            <input type="tel" id="telephone" name="telephone" placeholder=" " pattern="[0-9+\-\s()]{10,15}"
                                class="c-input <?= isset($validation_errors['telephone']) ? 'has-error' : '' ?>"
                                value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>">
                            <label for="telephone" class="c-label">Numéro de téléphone</label>
                            <?php if (isset($validation_errors['telephone'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['telephone']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="c-input-group">
                            <input type="email" id="email" name="email" placeholder=" "
                                class="c-input <?= isset($validation_errors['email']) ? 'has-error' : '' ?>"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            <label for="email" class="c-label">Adresse e-mail</label>
                            <?php if (isset($validation_errors['email'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['email']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Section Famille -->
                <div class="c-section">
                    <h5>Informations Familiales</h5>
                    <div class="c-grid-2">
                        <div class="c-input-group">
                            <input type="text" id="nom_pere" name="nom_pere" placeholder=" " maxlength="100"
                                class="c-input <?= isset($validation_errors['nom_pere']) ? 'has-error' : '' ?>"
                                value="<?= htmlspecialchars($_POST['nom_pere'] ?? '') ?>">
                            <label for="nom_pere" class="c-label">Nom complet du père</label>
                            <?php if (isset($validation_errors['nom_pere'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['nom_pere']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="c-input-group">
                            <input type="text" id="nom_mere" name="nom_mere" placeholder=" " maxlength="100"
                                class="c-input <?= isset($validation_errors['nom_mere']) ? 'has-error' : '' ?>"
                                value="<?= htmlspecialchars($_POST['nom_mere'] ?? '') ?>">
                            <label for="nom_mere" class="c-label">Nom complet de la mère</label>
                            <?php if (isset($validation_errors['nom_mere'])): ?>
                                <span class="c-error-msg"><?= htmlspecialchars($validation_errors['nom_mere']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="c-actions">
                    <button type="button" class="c-btn c-btn-reset" onclick="resetForm()">
                        Réinitialiser
                    </button>
                    <button type="submit" class="c-btn c-btn-submit" id="submitBtn">
                        Inscrire l'Étudiant
                    </button>
                </div>
            </form>
        </div>

        <!-- Tableau des inscriptions existantes -->
        <div class="mt-5">
            <h5>Liste des inscriptions</h5>
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Matricule</th>
                            <th>Nom</th>
                            <th>Post-nom</th>
                            <th>Prénom</th>
                            <th>Sexe</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Récupération des inscriptions pour la promotion/mention/année en cours
                        $inscriptions = [];
                        if ($id_filiere_form && $mention_id && $promotion_code && $annee_academique) {
                            $stmt = $pdo->prepare("
                                SELECT e.*, i.id_inscription, i.matricule AS matricule_insc
                                FROM t_inscription i
                                JOIN t_etudiant e ON i.matricule = e.matricule
                                WHERE i.id_filiere = ? AND i.id_mention = ? AND i.code_promotion = ? AND i.id_annee = ?
                                ORDER BY e.nom_etu, e.postnom_etu, e.prenom_etu
                            ");
                            $stmt->execute([$id_filiere_form, $mention_id, $promotion_code, $annee_academique]);
                            $inscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        }

                        // Gestion de la modification/suppression
                        $edit_id = $_GET['edit'] ?? null;
                        $edit_id = filter_var($edit_id, FILTER_VALIDATE_INT);
                        $delete_id = $_GET['delete'] ?? null;
                        $delete_id = filter_var($delete_id, FILTER_VALIDATE_INT);

                        // Suppression
                        if ($delete_id) {
                            // CSRF protection for delete
                            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_token']) && hash_equals($_SESSION['csrf_token'], $_POST['delete_token'])) {
                                try {
                                    $pdo->beginTransaction();
                                    // Suppression de l'inscription
                                    $stmt = $pdo->prepare("DELETE FROM t_inscription WHERE id_inscription = ?");
                                    $stmt->execute([$delete_id]);
                                    // Optionnel: suppression de l'étudiant si plus d'inscription
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM t_inscription WHERE matricule = (SELECT matricule FROM t_inscription WHERE id_inscription = ?)");
                                    $stmt->execute([$delete_id]);
                                    if ($stmt->fetchColumn() == 0) {
                                        $stmt = $pdo->prepare("DELETE FROM t_etudiant WHERE matricule = (SELECT matricule FROM t_inscription WHERE id_inscription = ?)");
                                        $stmt->execute([$delete_id]);
                                    }

                                    $redirect_url = $_SERVER["REQUEST_URI"];
                                    header("Location: " . $redirect_url);
                                    exit;
                                } catch (Exception $e) {
                                    $pdo->rollBack();
                                    echo '<div class="alert alert-danger">Erreur lors de la suppression : ' . htmlspecialchars($e->getMessage()) . '</div>';
                                }
                            }
                        }

                        // Modification
                        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id']) && isset($_POST['edit_token']) && hash_equals($_SESSION['csrf_token'], $_POST['edit_token'])) {
                            $edit_id_post = filter_var($_POST['edit_id'], FILTER_VALIDATE_INT);
                            if ($edit_id_post) {
                                // Récupérer les champs à modifier
                                $fields_edit = [
                                    'nom_etu' => validateInput($_POST['edit_nom_etu'] ?? '', 'string', true, 50),
                                    'postnom_etu' => validateInput($_POST['edit_postnom_etu'] ?? '', 'string', true, 50),
                                    'prenom_etu' => validateInput($_POST['edit_prenom_etu'] ?? '', 'string', true, 50),
                                    'sexe' => validateInput($_POST['edit_sexe'] ?? '', 'string', true)

                                ];
                                $edit_errors = [];
                                foreach ($fields_edit as $k => $v) {
                                    if (!$v['valid'])
                                        $edit_errors[$k] = $v['message'];
                                }
                                if (empty($edit_errors)) {
                                    try {
                                        // Récupérer le matricule de l'inscription
                                        $stmt = $pdo->prepare("SELECT matricule FROM t_inscription WHERE id_inscription = ?");
                                        $stmt->execute([$edit_id_post]);
                                        $matricule_edit = $stmt->fetchColumn();
                                        if ($matricule_edit) {
                                            $stmt = $pdo->prepare("
                                                UPDATE t_etudiant SET
                                                    nom_etu = ?, postnom_etu = ?, prenom_etu = ?, sexe = ?, date_mise_a_jour = NOW()
                                                WHERE matricule = ?
                                            ");
                                            $stmt->execute([
                                                $fields_edit['nom_etu']['value'],
                                                $fields_edit['postnom_etu']['value'],
                                                $fields_edit['prenom_etu']['value'],
                                                $fields_edit['sexe']['value'],
                                                $matricule_edit
                                            ]);
                                            // Définir l'URL de redirection après modification
                                            $redirect_url = $_SERVER['REQUEST_URI'];
                                            header("Location: " . $redirect_url);
                                            exit();
                                        } else {
                                            echo '<div class="alert alert-danger">Inscription non trouvée pour la modification.</div>';
                                        }
                                    } catch (Exception $e) {
                                        echo '<div class="alert alert-danger">Erreur lors de la modification : ' . htmlspecialchars($e->getMessage()) . '</div>';
                                    }
                                } else {
                                    echo '<div class="alert alert-danger">Erreur de validation lors de la modification.</div>';
                                }
                            }
                        }

                        foreach ($inscriptions as $insc):
                            if ($edit_id && $insc['id_inscription'] == $edit_id):
                        ?>
                                <form method="POST">
                                    <input type="hidden" name="edit_id" value="<?= $insc['id_inscription'] ?>">
                                    <input type="hidden" name="edit_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <tr>
                                        <td><?= htmlspecialchars($insc['matricule_insc']) ?></td>
                                        <td><input type="text" name="edit_nom_etu"
                                                value="<?= htmlspecialchars($insc['nom_etu']) ?>" class="form-control"
                                                maxlength="50" required></td>
                                        <td><input type="text" name="edit_postnom_etu"
                                                value="<?= htmlspecialchars($insc['postnom_etu']) ?>" class="form-control"
                                                maxlength="50" required></td>
                                        <td><input type="text" name="edit_prenom_etu"
                                                value="<?= htmlspecialchars($insc['prenom_etu']) ?>" class="form-control"
                                                maxlength="50" required></td>
                                        <td>
                                            <select name="edit_sexe" class="form-select" required>
                                                <option value="M" <?= $insc['sexe'] === 'M' ? 'selected' : '' ?>>M</option>
                                                <option value="F" <?= $insc['sexe'] === 'F' ? 'selected' : '' ?>>F</option>
                                            </select>
                                        </td>

                                        <td>
                                            <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
                                            <a href="<?= strtok($_SERVER["REQUEST_URI"], '?') ?>"
                                                class="btn btn-secondary btn-sm">Annuler</a>
                                        </td>
                                    </tr>
                                </form>
                            <?php else: ?>
                                <tr>
                                    <td><?= htmlspecialchars($insc['matricule_insc']) ?></td>
                                    <td><?= htmlspecialchars($insc['nom_etu']) ?></td>
                                    <td><?= htmlspecialchars($insc['postnom_etu']) ?></td>
                                    <td><?= htmlspecialchars($insc['prenom_etu']) ?></td>
                                    <td><?= htmlspecialchars($insc['sexe']) ?></td>

                                    <td>
                                        <a href="?<?= http_build_query(array_merge($_GET, ['edit' => $insc['id_inscription']])) ?>"
                                            class="btn btn-outline-primary btn-sm">Modifier</a>
                                        <form method="POST"
                                            action="?<?= http_build_query(array_merge($_GET, ['delete' => $insc['id_inscription']])) ?>"
                                            style="display:inline-block;"
                                            onsubmit="return confirm('Confirmer la suppression ?');">
                                            <input type="hidden" name="delete_token"
                                                value="<?= htmlspecialchars($csrf_token) ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Supprimer</button>
                                        </form>
                                    </td>
                                </tr>
                        <?php endif;
                        endforeach; ?>
                        <?php if (empty($inscriptions)): ?>
                            <tr>
                                <td colspan="14" class="text-center">Aucune inscription trouvée.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>