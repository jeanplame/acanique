<?php
    header('Content-Type: text/html; charset=UTF-8');
/**
 * Modal pour l'ajout d'une inscription
 */
?>
<div class="modal fade" id="addInscriptionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nouvelle inscription</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addInscriptionForm" method="POST" action="pages/domaine/process/add_inscription.php" class="needs-validation" novalidate>
                <div class="modal-body">
                    <!-- Champs cachés -->
                    <input type="hidden" name="action" value="add_inscription">
                    <input type="hidden" name="id_mention" value="<?php echo isset($_GET['mention']) ? htmlspecialchars($_GET['mention']) : ''; ?>">
                    <input type="hidden" name="code_promotion" value="<?php echo isset($_GET['promotion']) ? htmlspecialchars($_GET['promotion']) : ''; ?>">
                    <input type="hidden" name="username" value="<?php echo isset($_SESSION['user_id']) ? htmlspecialchars($_SESSION['user_id']) : ''; ?>">
                    <input type="hidden" name="statut" value="actif">
                    <input type="hidden" name="type_inscription" value="normale">
                    <input type="hidden" name="date_inscription" id="date_inscription">

                    <!-- Informations d'inscription -->
                    <?php include 'modals/inscription-form-fields.php'; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
