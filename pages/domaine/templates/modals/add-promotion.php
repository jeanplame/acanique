<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * Modal pour l'ajout d'une promotion
 */
?>
<div class="modal fade" id="addPromotionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ajouter une promotion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="pages/domaine/process/add_promotion.php" class="needs-validation" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_promotion">
                    <input type="hidden" name="id_mention" id="id_mention">
                    
                    <div class="mb-3">
                        <label for="code_promotion" class="form-label">Code de la promotion</label>
                        <input type="text" class="form-control" id="code_promotion" name="code_promotion" required maxlength="5">
                        <div class="invalid-feedback">Ce champ est requis</div>
                    </div>

                    <div class="mb-3">
                        <label for="nom_promotion" class="form-label">Nom de la promotion</label>
                        <input type="text" class="form-control" id="nom_promotion" name="nom_promotion" required maxlength="25">
                        <div class="invalid-feedback">Ce champ est requis</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button type="submit" class="btn btn-primary">Ajouter</button>
                </div>
            </form>
        </div>
    </div>
</div>
