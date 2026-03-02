<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * Modal pour l'ajout d'une mention
 */
?>
<div class="modal fade" id="addMentionModal" tabindex="-1" aria-labelledby="addMentionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addMentionModalLabel">Ajouter une mention</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="pages/domaine/process/add_mention.php" class="needs-validation" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_mention">
                    <input type="hidden" name="idFiliere" id="idFiliere">
                    
                    <div class="mb-3">
                        <label for="code_mention" class="form-label">Code de la mention</label>
                        <input type="text" class="form-control" id="code_mention" name="code_mention" required maxlength="10">
                        <div class="invalid-feedback">Ce champ est requis</div>
                    </div>

                    <div class="mb-3">
                        <label for="libelle" class="form-label">Libellé de la mention</label>
                        <input type="text" class="form-control" id="libelle" name="libelle" required maxlength="100">
                        <div class="invalid-feedback">Ce champ est requis</div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
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
