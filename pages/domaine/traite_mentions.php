<?php
// Inclure les fonctions nécessaires
require_once 'includes/domaine_functions.php';
    header('Content-Type: text/html; charset=UTF-8');

// ================================================
// RÉCUPÉRATION DE L'ANNÉE ACADÉMIQUE
// Priorité: 1. URL ($_GET['annee']), 2. Année en cours
// ================================================
$id_annee = getAnneeFromUrlOrDefault($pdo, $_GET['annee'] ?? null);

if (!$id_annee) {
    echo "<div class='alert alert-danger'>Erreur : Aucune année académique configurée. Veuillez contacter l'administrateur.</div>";
    return;
}

// Récupérer les détails de l'année
$stmt = $pdo->prepare("SELECT * FROM t_anne_academique WHERE id_annee = ?");
$stmt->execute([$id_annee]);
$annee = $stmt->fetch(PDO::FETCH_ASSOC);

$date_debut = $annee['date_debut'];
$date_fin = $annee['date_fin'];
$statut = $annee['statut'];
$idDomaine = $_GET['id'] ?? null;

$stmt = $pdo->prepare("SELECT * FROM t_domaine WHERE id_domaine = ?");
$stmt->execute([$idDomaine]);
$domaine = $stmt->fetch(PDO::FETCH_ASSOC);

// Construction d’un libellé automatique (ex: "2024-2025")
$libelle = date('Y', strtotime($date_debut)) . '-' . date('Y', strtotime($date_fin));

// On vérifie si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer le type d'action
    $action_type = $_POST['action_type'] ?? '';

    // Bloc de gestion des erreurs
    try {
        // Logique pour associer plusieurs promotions
        if ($action_type === 'associer_promotions' && isset($_POST['promotions']) && is_array($_POST['promotions'])) {
            $promotions_a_associer = $_POST['promotions'];

            // Préparation de la requête d'insertion
            $stmt = $pdo->prepare("
                INSERT INTO t_association_promo (code_promotion, id_mention, id_annee) 
                VALUES (?, ?, ?)
            ");

            // Boucler sur les promotions sélectionnées et les insérer
            foreach ($promotions_a_associer as $code_promotion) {
                $stmt->execute([$code_promotion, $id_mention = $_POST['id_mention'], $id_annee]);
            }

            // Redirection après l'action pour éviter la soumission multiple
            header("Location: ?page=domaine&action=view&id={$id_domaine}&mention_id={$idMention}");
            exit();

            // Logique pour désassocier une seule promotion
        } elseif ($action_type === 'desassocier_promotion' && isset($_POST['code_promotion'])) {
            $code_promotion = $_POST['code_promotion'];

            // Préparation de la requête de suppression
            $stmt = $pdo->prepare("
                DELETE FROM t_association_promo 
                WHERE id_mention = ? AND code_promotion = ? AND id_annee = ?
            ");
            $stmt->execute([$id_mention = $_POST['id_mention'], $code_promotion, $id_annee]);
            $id_mention = $_POST['id_mention'] ?? '';
            // Redirection après l'action pour éviter la soumission multiple
            header("Location: ?page=domaine&action=view&id={$id_domaine}&mention_id={$id_mention}");
            exit();
        }
    } catch (PDOException $e) {
        // Gérer les erreurs de la base de données, par exemple en affichant un message
        // Pour un environnement de production, il est préférable de ne pas afficher l'erreur directement
        echo "Erreur de base de données : " . $e->getMessage();
    }

}
?>
<style>
    /* CSS pour mettre en évidence l'accordéon sélectionné */
    .accordion-item.selected {
        border: 2px solid #007bff;
        /* Bordure bleue pour l'élément sélectionné */
        border-radius: 0.25rem;
        box-shadow: 0 0 10px rgba(0, 123, 255, 0.25);
        /* Ombre légère pour le faire ressortir */
        transition: all 0.3s ease-in-out;
    }

    .accordion-item.selected .accordion-button {
        color: #007bff;
        /* Couleur de texte différente pour le bouton */
        background-color: #e9f5ff;
        /* Fond légèrement coloré */
    }

    /* Style de base pour les boutons d'accordéon non sélectionnés */
    .accordion-button:not(.collapsed) {
        color: #0c63e4;
        background-color: #e7f1ff;
        box-shadow: inset 0 -1px 0 rgba(0, 0, 0, .125);
    }

    .accordion-button:focus {
        box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25);
    }
</style>

<!-- Accordéon mis à jour avec la classe 'selected' et des formulaires séparés -->
<div class="accordion" id="accordionMentions">
    <?php foreach ($mentions as $mention): ?>
        <div class="accordion-item <?php echo ($mention_id == $mention['id_mention']) ? 'selected' : ''; ?>">
            <h2 class="accordion-header" id="heading<?php echo $mention['id_mention']; ?>">
                <button class="accordion-button <?php echo ($mention_id != $mention['id_mention']) ? 'collapsed' : ''; ?>"
                    type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $mention['id_mention']; ?>"
                    aria-expanded="<?php echo ($mention_id == $mention['id_mention']) ? 'true' : 'false'; ?>"
                    aria-controls="collapse<?php echo $mention['id_mention']; ?>">
                    <div class="d-flex flex-column flex-grow-1">
                        <div class="d-flex justify-content-between align-items-center w-100">
                            <strong><?php echo htmlspecialchars($mention['libelle']); ?></strong>
                            <small class="badge bg-secondary">
                                <?php echo htmlspecialchars($mention['code_mention']); ?>
                            </small>
                        </div>
                        <div class="small mt-1">
                            <span class="me-2">
                                <i class="bi bi-book text-info"></i> <?php echo $mention['nb_ue']; ?>
                            </span>
                            <span class="me-2">
                                <i class="bi bi-people text-success"></i>
                                <?php echo $mention['nb_inscrits']; ?>
                            </span>
                            <span>
                                <i class="bi bi-clipboard2-check text-warning"></i>
                                <?php echo $mention['nb_notes']; ?>
                            </span>
                        </div>
                    </div>
                </button>
            </h2>
            <?php
            // Get current mention's promotions with enrollment count
            $stmt = $pdo->prepare("
            SELECT 
                p.*,
                COUNT(DISTINCT i.id_inscription) as nb_inscrits
                FROM t_promotion p
                INNER JOIN t_inscription i ON i.code_promotion = p.code_promotion
                WHERE i.id_mention = ?
                    AND i.id_annee = ?
                    AND i.id_filiere = ?
                GROUP BY p.code_promotion
                ORDER BY p.code_promotion DESC
            ");
            $stmt->execute([$mention['id_mention'], $id_annee, $mention['idFiliere']]);
            $mentionPromotions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            //echo 'Filière : ' . $mention['idFiliere'];
            ?>
            <div id="collapse<?php echo $mention['id_mention']; ?>"
                class="accordion-collapse collapse <?php echo ($mention_id == $mention['id_mention']) ? 'show' : ''; ?>"
                aria-labelledby="heading<?php echo $mention['id_mention']; ?>">
                <div class="accordion-body">


                    <!-- Début du formulaire de gestion pour l'ajout -->
                    <div class="card mt-3">
                        <div class="card-header py-2">
                            <h6 class="mb-0">Gestion des promotions pour
                                <?php echo htmlspecialchars($mention['libelle']); ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <!-- Associations actuelles : Chaque promotion a son propre formulaire pour la suppression -->
                            <h6 class="mb-3">Promotions actuelles</h6>
                            <div class="mb-4">
                                <?php
                                $stmt = $pdo->prepare("
                                    SELECT 
                                        p.code_promotion, 
                                        p.nom_promotion, 
                                        COUNT(DISTINCT i.id_inscription) as nb_inscrits
                                    FROM t_promotion p
                                    INNER JOIN t_association_promo ap 
                                        ON p.code_promotion = ap.code_promotion
                                    LEFT JOIN t_inscription i 
                                        ON p.code_promotion = i.code_promotion 
                                        AND i.id_mention = ap.id_mention 
                                        AND i.id_annee = ap.id_annee
                                    WHERE ap.id_mention = ? AND ap.id_annee = ?
                                    GROUP BY p.code_promotion, p.nom_promotion
                                    ORDER BY p.code_promotion
                                ");
                                $stmt->execute([$mention['id_mention'], $id_annee]);
                                $currentPromotions = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                if (count($currentPromotions) > 0):
                                    ?>
                                    <div class="list-group">
                                        <?php foreach ($currentPromotions as $promo):
                                            $isActive = isset($_GET['promotion']) && $_GET['promotion'] == $promo['code_promotion'];
                                            ?>
                                            <style>
                                                .list-group-item:hover {
                                                    background-color: #003958;
                                                    color: white !important;
                                                }

                                                a:hover {
                                                    color: white;
                                                }
                                            </style>
                                            <div style="background-color: #1f5f85ff;"
                                                class="list-group-item d-flex justify-content-between align-items-center p-3 hover-shadow transition <?php echo $isActive ? 'active bg-primary text-white' : ''; ?>">
                                                <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&mention=<?php echo $mention['id_mention']; ?>&promotion=<?php echo $promo['code_promotion']; ?>&annee=<?php echo $id_annee; ?>&tab=inscriptions"
                                                    class="text-decoration-none <?php echo $isActive ? 'text-white' : 'text-dark'; ?>">
                                                    <div style="color: #ffffffff !important;">
                                                        <?php echo htmlspecialchars($promo['code_promotion']); ?>
                                                        <span
                                                            class="badge <?php echo $isActive ? 'bg-white text-primary' : 'bg-info'; ?> ms-2">
                                                            <?php echo $promo['nb_inscrits']; ?> inscrits
                                                        </span>
                                                    </div>
                                                </a>
                                                <!-- Chaque bouton de suppression a maintenant son propre formulaire -->
                                                <form action="?page=domaine&action=view&id=<?php echo $id_domaine; ?>" method="POST"
                                                    class="d-inline-block">
                                                    <input type="hidden" name="id_mention"
                                                        value="<?php echo $mention['id_mention']; ?>">
                                                    <input type="hidden" name="id_domaine" value="<?php echo $id_domaine; ?>">
                                                    <input type="hidden" name="id_annee" value="<?php echo $annee_academique; ?>">
                                                    <input type="hidden" name="action_type" value="desassocier_promotion">
                                                    <input type="hidden" name="code_promotion"
                                                        value="<?php echo $promo['code_promotion']; ?>">
                                                    <button type="submit"
                                                        class="btn btn-sm <?php echo $isActive ? 'btn-light' : 'btn-outline-danger'; ?>">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">Aucune promotion associée</div>
                                <?php endif; ?>
                            </div>

                            <!-- Promotions disponibles : Un formulaire unique pour les associer -->

                            <?php
                            $stmt = $pdo->prepare("
                                SELECT p.*
                                FROM t_promotion p
                                WHERE p.code_promotion NOT IN (
                                    SELECT code_promotion FROM t_association_promo 
                                    WHERE id_mention = ? AND id_annee = ?
                                )
                                ORDER BY p.nom_promotion
                            ");
                            $stmt->execute([$mention['id_mention'], $id_annee]);
                            $availablePromotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>

                            <?php if (count($availablePromotions) > 0): ?>
                                <!-- Début du formulaire d'ajout -->
                                <h6 class="mb-3">Ajouter des promotions</h6>
                                <?php
                                // Formulaire d'ajout de promotions
                                //echo 'Mention : ' . $mention['id_mention'] . ' Domaine : ' . is_array($domaine) . ' Année : ' . $id_annee;
                                ?>
                                <form action="?page=domaine&action=view&id=<?php echo $id_domaine; ?>" method="POST">
                                    <input type="hidden" name="id_mention" value="<?php echo $mention['id_mention']; ?>">
                                    <input type="hidden" name="id_domaine" value="<?php echo $id_domaine; ?>">
                                    <input type="hidden" name="id_annee" value="<?php echo $id_annee; ?>">
                                    <input type="hidden" name="action_type" value="associer_promotions">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover" style="font-size: 12px;">
                                            <thead>
                                                <tr class="table-primary">
                                                    <th class="fw-bold">Promotion</th>
                                                    <th width="100" class="text-center">Sélec</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($availablePromotions as $promo): ?>
                                                    <tr class="align-middle hover-bg-light">
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <span class="fw-medium text-dark">
                                                                    <?php echo htmlspecialchars($promo['code_promotion']); ?>
                                                                </span>
                                                                <span class="badge bg-light text-secondary ms-2">
                                                                    <?php echo htmlspecialchars($promo['nom_promotion'] ?? ''); ?>
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td class="text-center">
                                                            <div class="form-check d-flex justify-content-center mb-0">
                                                                <input type="checkbox" class="form-check-input" name="promotions[]"
                                                                    value="<?php echo htmlspecialchars($promo['code_promotion']); ?>"
                                                                    style="cursor: pointer; width: 1.2rem; height: 1.2rem; margin: 0;">
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-end mt-3">
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="bi bi-plus-circle"></i> Associer les promotions
                                        </button>
                                    </div>
                                </form>
                                <!-- Fin du formulaire d'ajout -->
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>