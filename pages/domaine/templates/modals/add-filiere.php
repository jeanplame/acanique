<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * Modal pour l'ajout d'une filière
 */
?>
<div class="modal fade" id="addFiliereModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ajouter une filière</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" class="needs-validation" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_filiere">
                    
                    <div class="mb-3">
                        <label for="code_filiere" class="form-label">Code de la filière</label>
                        <input type="text" class="form-control" id="code_filiere" name="code_filiere" required>
                        <div class="invalid-feedback">Ce champ est requis</div>
                    </div>

                    <div class="mb-3">
                        <label for="nom_filiere" class="form-label">Nom de la filière</label>
                        <input type="text" class="form-control" id="nom_filiere" name="nom_filiere" required>
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
