<?php
require_once('../../includes/db_config.php');
require_once('../../includes/header.php');
    header('Content-Type: text/html; charset=UTF-8');

$mention_id = isset($_GET['mention']) ? intval($_GET['mention']) : 0;

// Récupérer les informations de la mention et de sa filière
$query_mention = "SELECT m.*, f.libelle as filiere_libelle 
                 FROM mention m 
                 JOIN filiere f ON m.id_filiere = f.id_filiere 
                 WHERE m.id_mention = ?";
$stmt = $conn->prepare($query_mention);
$stmt->bind_param("i", $mention_id);
$stmt->execute();
$mention = $stmt->get_result()->fetch_assoc();

if (!$mention) {
    header('Location: index.php');
    exit;
}

// Récupérer les promotions
$query_promotions = "SELECT p.*, 
                           (SELECT COUNT(*) FROM inscription i WHERE i.id_promotion = p.id_promotion) as nb_etudiants
                    FROM promotion p 
                    WHERE p.id_mention = ? 
                    ORDER BY p.annee DESC";
$stmt = $conn->prepare($query_promotions);
$stmt->bind_param("i", $mention_id);
$stmt->execute();
$result_promotions = $stmt->get_result();
?>

<div class="container-fluid mt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Filières</a></li>
            <li class="breadcrumb-item"><a href="index.php?filiere=<?php echo $mention['id_filiere']; ?>"><?php echo htmlspecialchars($mention['filiere_libelle']); ?></a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($mention['libelle']); ?></li>
        </ol>
    </nav>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            La promotion a été ajoutée avec succès.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                Promotions - <?php echo htmlspecialchars($mention['libelle']); ?>
            </h5>
            <a href="ajouter_promotion.php?mention=<?php echo $mention_id; ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> Nouvelle promotion
            </a>
        </div>
        <div class="card-body">
            <?php if ($result_promotions->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Année académique</th>
                                <th>Description</th>
                                <th class="text-center">Nombre d'étudiants</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($promotion = $result_promotions->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($promotion['annee']); ?></td>
                                    <td>
                                        <?php echo $promotion['description'] ? htmlspecialchars($promotion['description']) : '<em class="text-muted">Aucune description</em>'; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info">
                                            <?php echo $promotion['nb_etudiants']; ?> étudiant(s)
                                        </span>
                                    </td>
                                    <td>
                                        <a href="liste_etudiants.php?promotion=<?php echo $promotion['id_promotion']; ?>" 
                                           class="btn btn-sm btn-info">
                                            <i class="fas fa-users"></i> Voir les étudiants
                                        </a>
                                        <a href="ajouter_etudiant.php?promotion=<?php echo $promotion['id_promotion']; ?>" 
                                           class="btn btn-sm btn-secondary">
                                            <i class="fas fa-user-plus"></i> Ajouter
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    Aucune promotion n'existe pour cette mention.
                    <a href="ajouter_promotion.php?mention=<?php echo $mention_id; ?>" class="alert-link">
                        Cliquez ici pour en ajouter une
                    </a>.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once('../../includes/footer.php'); ?>
