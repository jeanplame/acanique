<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * Template pour la barre latérale
 * @var array $domaine Les informations du domaine
 * @var array $filieres La liste des filières
 */
?>
<div class="col-12 col-lg-4 col-xl-3 border-end sidebar-management">
    <div class="card border-0 rounded-0 h-100">
        <div class="card-header bg-light border-bottom">
            <h5 class="card-title mb-0">
                <?php echo htmlspecialchars($domaine['nom_domaine']); ?>
                <span class="badge bg-primary ms-2"><?php echo htmlspecialchars($domaine['code_domaine']); ?></span>
            </h5>
        </div>
        
        <!-- Onglets de navigation -->
        <div class="nav nav-tabs" role="tablist">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#filieres" type="button" role="tab">
                Filières
            </button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#mentions" type="button" role="tab">
                Mentions
            </button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#promotions" type="button" role="tab">
                Promotions
            </button>
        </div>

        <!-- Contenu des onglets -->
        <div class="tab-content p-3">
            <!-- Onglet Filières -->
            <div class="tab-pane fade show active" id="filieres" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Liste des filières</h6>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addFiliereModal">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>

                <div class="list-group list-group-flush filiere-list">
                    <?php foreach ($filieres as $filiere): ?>
                        <div class="list-group-item list-group-item-action" data-filiere-id="<?php echo $filiere['idFiliere']; ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($filiere['nomFiliere']); ?></h6>
                                    <small class="text-muted"><?php echo htmlspecialchars($filiere['code_filiere']); ?></small>
                                </div>
                                <div class="text-end">
                                    <small class="d-block text-muted"><?php echo $filiere['nb_mentions']; ?> mentions</small>
                                    <small class="d-block text-muted"><?php echo $filiere['nb_etudiants']; ?> étudiants</small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Onglet Mentions -->
            <div class="tab-pane fade" id="mentions" role="tabpanel">
                <div id="mentionsContent">
                    <div class="alert alert-info">
                        Sélectionnez d'abord une filière pour gérer ses mentions.
                    </div>
                </div>
            </div>

            <!-- Onglet Promotions -->
            <div class="tab-pane fade" id="promotions" role="tabpanel">
                <div id="promotionsContent">
                    <div class="alert alert-info">
                        Sélectionnez d'abord une mention pour gérer ses promotions.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
