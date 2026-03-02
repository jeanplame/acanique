<?php
require_once('../../includes/db_config.php');
require_once('../../includes/header.php');
    header('Content-Type: text/html; charset=UTF-8');

// Récupération de l'ID de la filière sélectionnée
$selected_filiere = isset($_GET['filiere']) ? intval($_GET['filiere']) : null;

// Récupération de toutes les filières
$query_filieres = "SELECT * FROM filiere ORDER BY libelle";
$result_filieres = $conn->query($query_filieres);

// Récupération des mentions si une filière est sélectionnée
$mentions = array();
if ($selected_filiere) {
    $query_mentions = "SELECT * FROM mention WHERE id_filiere = ? ORDER BY libelle";
    $stmt = $conn->prepare($query_mentions);
    $stmt->bind_param("i", $selected_filiere);
    $stmt->execute();
    $result_mentions = $stmt->get_result();
}

?>

<div class="container-fluid">
    <div class="row">
        <!-- Liste des filières -->
        <div class="col-md-3">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Filières</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php while ($filiere = $result_filieres->fetch_assoc()): ?>
                            <a href="?filiere=<?php echo $filiere['id_filiere']; ?>" 
                               class="list-group-item list-group-item-action <?php echo ($selected_filiere == $filiere['id_filiere']) ? 'active' : ''; ?>">
                                <?php echo htmlspecialchars($filiere['libelle']); ?>
                            </a>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Liste des mentions -->
        <div class="col-md-9">
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    La mention a été ajoutée avec succès.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($selected_filiere): ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Mentions</h5>
                        <a href="ajouter_mention.php?filiere=<?php echo $selected_filiere; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Ajouter une mention
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if ($result_mentions && $result_mentions->num_rows > 0): ?>
                            <div class="list-group">
                                <?php while ($mention = $result_mentions->fetch_assoc()): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($mention['libelle']); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($mention['code_mention']); ?></small>
                                            </div>
                                            <div>
                                                <a href="promotions.php?mention=<?php echo $mention['id_mention']; ?>" class="btn btn-info btn-sm me-2">
                                                    Voir les promotions
                                                </a>
                                                <a href="ajouter_promotion.php?mention=<?php echo $mention['id_mention']; ?>" class="btn btn-secondary btn-sm">
                                                    <i class="fas fa-plus"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                Aucune mention n'existe pour cette filière.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    Veuillez sélectionner une filière pour voir ses mentions.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once('../../includes/footer.php'); ?>
