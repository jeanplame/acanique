<div class="col-md-4 col-lg-3 border-end vh-100 overflow-auto">
    <div class="p-3">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show py-2">
                <?php
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2">
                <?php
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Liste des filières et mentions -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">Filières et Mentions</h6>
            <a href="?page=domaine&action=view&id=<?php echo $id_domaine; ?>&annee=<?php echo $id_annee; ?>&filiere=add"
                class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle"></i> Nouvelle filière
            </a>
        </div>
        <style>
            .list-group-item {
                background-color: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 0.25rem;
                margin-bottom: 0.5rem;
            }

            .list-group-item:hover {
                background-color: #e9ecef;
            }

            .list-group-item .text-muted:hover {
                color: #ffffffff !important;
            }
        </style>
        <?php foreach ($filieres as $filiere): ?>
            <?php
            // Récupération des mentions de la filière avec statistiques
            $stmt = $pdo->prepare("
                SELECT 
                    m.*,
                    (SELECT COUNT(DISTINCT ue.id_ue)
                        FROM t_unite_enseignement ue
                        INNER JOIN t_mention_ue mu ON ue.id_ue = mu.id_ue
                        WHERE mu.id_mention = m.id_mention) as nb_ue,
                    (SELECT COUNT(DISTINCT i.id_inscription)
                        FROM t_inscription i
                        WHERE i.id_mention = m.id_mention
                        AND i.id_annee = ?) as nb_inscrits,
                    (SELECT COUNT(DISTINCT c.id_note)
                        FROM t_cote c
                        WHERE c.id_mention = m.id_mention
                        AND c.id_annee = ?) as nb_notes
                FROM t_mention m 
                WHERE m.idFiliere = ?
                ORDER BY m.libelle
            ");
            $stmt->execute([$annee_academique, $annee_academique, $filiere['idFiliere']]);
            $mentions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="list-group">


                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h6 class="mb-0">
                                <?php echo htmlspecialchars($filiere['nomFiliere']); ?>
                                <small>(<?php echo htmlspecialchars($filiere['code_filiere']); ?>)</small>
                            </h6>
                        </div>
                        <div class="btn-group">
                            <a href="?page=domaine&action=ajouter_mention&filiere=<?php echo $filiere['idFiliere']; ?>&annee=<?php echo $id_annee; ?>"
                                class="btn btn-sm btn-outline-primary" title="Ajouter une mention">
                                Ajouter
                            </a>

                        </div>
                    </div>
                    <div class="small mb-2">
                        <span class="badge bg-info">
                            <i class="bi bi-mortarboard"></i> <?php echo $filiere['nb_mentions']; ?> mentions
                        </span>
                        <span class="badge bg-secondary ms-1">
                            <i class="bi bi-people"></i> <?php echo $filiere['nb_inscrits']; ?> inscrits
                        </span>
                    </div>

                    <?php include 'traite_mentions.php'; ?>

                </div>

            </div>
        <?php endforeach; ?>
    </div>
</div>