<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * Template pour le contenu principal
 * @var array $domaine Les informations du domaine
 * @var array $inscriptions La liste des inscriptions
 * @var array $promotions La liste des promotions
 * @var int $mention_id L'ID de la mention sélectionnée
 * @var string $promotion_code Le code de la promotion sélectionnée
 */
?>
<div class="col-12 col-lg-8 col-xl-9">
    <div class="p-4" id="mainContent">
        <?php if (empty($mention_id) || empty($promotion_code)): ?>
            <div class="alert alert-info initial-message">
                Sélectionnez une filière et une promotion pour voir les détails.
            </div>
        <?php else: ?>
            <div class="promotion-details">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4><?php echo htmlspecialchars($promotion_nom); ?></h4>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="showAddInscriptionModal()">
                            <i class="fas fa-plus"></i> Nouvelle inscription
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Matricule</th>
                                <th>Nom complet</th>
                                <th>Date d'inscription</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($inscriptions)): ?>
                                <?php foreach ($inscriptions as $inscription): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($inscription['matricule']); ?></td>
                                        <td><?php echo htmlspecialchars($inscription['nom_etu'] . ' ' . $inscription['postnom_etu'] . ' ' . $inscription['prenom_etu']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($inscription['date_inscription'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $inscription['statut'] === 'actif' ? 'success' : 'danger'; ?>">
                                                <?php echo htmlspecialchars($inscription['statut']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="?page=domaine&action=edit_inscription&id=<?php echo $inscription['id_inscription']; ?>&annee=<?php echo $id_annee ?? $annee_academique; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <a href="?page=domaine&action=delete_inscription&id=<?php echo $inscription['id_inscription']; ?>&annee=<?php echo $id_annee ?? $annee_academique; ?>" 
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette inscription ?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center">Aucune inscription trouvée</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
