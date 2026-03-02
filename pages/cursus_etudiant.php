<?php
// cursus_etudiant.php

// Ce fichier est destiné à être inclus dans la page principale du profil de l'étudiant.
// Les variables PHP comme $etudiant sont censées être déjà définies
// par le script principal (etudiant_profil.php) avant que ce fichier ne soit inclus.

if (!isset($etudiant)) {
    echo '<div class="alert alert-warning" role="alert">Les informations de l\'étudiant ne sont pas disponibles.</div>';
    return; // Arrêter l'exécution si $etudiant n'est pas défini
}

// Assurez-vous que la connexion à la base de données ($pdo) est disponible.
// Si ce fichier est inclus après 'db_config.php' dans le script principal, $pdo devrait être défini.
// Sinon, vous pourriez avoir besoin de le 'require_once' ici.
// require_once __DIR__ . '/../includes/db_config.php'; // Décommenter si $pdo n'est pas disponible

$matricule = $etudiant['matricule']; // Récupérer le matricule de l'étudiant

$historical_cursus = [];

try {
    // 1. Récupération de toutes les inscriptions de l'étudiant pour chaque année académique,
    // en construisant le libellé de l'année à partir des dates de début et de fin
    $sql_historical_inscriptions = "
        SELECT
            i.id_annee,
            CONCAT(YEAR(an.date_debut), '-', YEAR(an.date_fin)) AS annee_libelle,
            p.nom_promotion,
            m.libelle AS mention_libelle,
            i.statut AS statut_inscription
        FROM t_inscription i
        JOIN t_anne_academique an ON i.id_annee = an.id_annee
        JOIN t_promotion p ON i.code_promotion = p.code_promotion
        JOIN t_mention m ON i.id_mention = m.id_mention
        WHERE i.matricule = ?
        ORDER BY an.date_debut ASC"; // Ordonner par date de début d'année académique pour un affichage chronologique
    $stmt_historical_inscriptions = $pdo->prepare($sql_historical_inscriptions);
    $stmt_historical_inscriptions->execute([$matricule]);
    $historical_cursus = $stmt_historical_inscriptions->fetchAll(PDO::FETCH_ASSOC);

    // 2. Pour chaque inscription, calculer la moyenne annuelle et la décision
    foreach ($historical_cursus as &$inscription) {
        $annual_average = 0;
        $decision = 'Échec'; // Valeur par défaut si aucune condition n'est remplie

        // Récupérer toutes les notes pour cette année académique et cet étudiant
        $sql_annual_grades = "
            SELECT
                (c.cote_s1 + c.cote_s2) AS total_cote_ec,
                ec.coefficient AS credits_ec
            FROM t_cote c
            JOIN t_element_constitutif ec ON c.id_ec = ec.id_ec
            WHERE c.matricule = ? AND c.id_annee = ?";
        $stmt_annual_grades = $pdo->prepare($sql_annual_grades);
        $stmt_annual_grades->execute([$matricule, $inscription['id_annee']]);
        $annual_grades = $stmt_annual_grades->fetchAll(PDO::FETCH_ASSOC);

        $total_weighted_sum = 0;
        $total_credits = 0;

        foreach ($annual_grades as $grade) {
            // Calculer la note moyenne de l'élément constitutif et la pondérer par les crédits
            $total_weighted_sum += ($grade['total_cote_ec'] / 2) * $grade['credits_ec'];
            $total_credits += $grade['credits_ec'];
        }

        if ($total_credits > 0) {
            $annual_average = round($total_weighted_sum / $total_credits, 2); // Arrondir à 2 décimales
        }

        // Déterminer la décision basée sur la moyenne annuelle (selon les règles LMD en RDC)
        if ($annual_average >= 16) {
            $decision = 'Très Bien';
        } elseif ($annual_average >= 14) {
            $decision = 'Bien';
        } elseif ($annual_average >= 12) {
            $decision = 'Assez Bien';
        } elseif ($annual_average >= 10) {
            $decision = 'Passable';
        } else {
            $decision = 'Échec';
        }

        $inscription['moyenne_annuelle'] = $annual_average;
        $inscription['decision'] = $decision;
    }

} catch (PDOException $e) {
    echo '<div class="alert alert-danger" role="alert">Erreur lors de la récupération de l\'historique du cursus : ' . htmlspecialchars($e->getMessage()) . '</div>';
    $historical_cursus = [];
}

// Fetch data for re-enrollment form
$current_year = date('Y');
$next_academic_year_display = ($current_year) . '-' . ($current_year + 1);

// Fetch all promotions for the dropdown
$all_promotions = [];
try {
    $stmt_all_promotions = $pdo->query("SELECT code_promotion, nom_promotion FROM t_promotion ORDER BY nom_promotion ASC");
    $all_promotions = $stmt_all_promotions->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log the error for debugging, but don't stop the page load
    error_log("Error fetching promotions: " . $e->getMessage());
}

// Fetch student's current filiere_id to filter mentions
$student_current_filiere_id = null;
try {
    // Get the most recent active filiere for the student
    $stmt_filiere = $pdo->prepare("SELECT id_filiere FROM t_inscription WHERE matricule = ? AND statut = 'Actif' ORDER BY date_inscription DESC LIMIT 1");
    $stmt_filiere->execute([$matricule]);
    $student_current_filiere_id = $stmt_filiere->fetchColumn();
} catch (PDOException $e) {
    error_log("Error fetching student's filiere: " . $e->getMessage());
}

// Fetch mentions based on student's current filière, or all if not found/error
$available_mentions = [];
try {
    if ($student_current_filiere_id) {
        $stmt_available_mentions = $pdo->prepare("SELECT id_mention, libelle FROM t_mention WHERE idFiliere = ? ORDER BY libelle ASC");
        $stmt_available_mentions->execute([$student_current_filiere_id]);
    } else {
        // Fallback: get all mentions if no specific filiere found for the student's active inscription
        $stmt_available_mentions = $pdo->query("SELECT id_mention, libelle FROM t_mention ORDER BY libelle ASC");
    }
    $available_mentions = $stmt_available_mentions->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching mentions: " . $e->getMessage());
}

?>

<div class="card shadow-lg p-4 mb-4">
    <h3 class="fs-5 fw-bold mb-3">Cursus de l'Étudiant</h3>

    <!-- Informations de base de l'étudiant (déjà présentes dans l'en-tête, mais répétées ici pour le contexte de l'onglet) -->
    <div class="row g-3 mb-4">
        <div class="col-md-12">
            <p><strong>Matricule :</strong> <?php echo htmlspecialchars($etudiant['matricule']); ?></p>
        </div>
        <div class="col-md-12">
            <p><strong>Nom complet :</strong> <?php echo htmlspecialchars($etudiant['nom_etu'] . ' ' . $etudiant['postnom_etu'] . ' ' . $etudiant['prenom_etu']); ?></p>
        </div>
    </div>

    <hr class="my-4">

    <h4 class="fs-6 fw-bold mb-3">Historique du Cursus</h4>
    <?php if (!empty($historical_cursus)): ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Année académique</th>
                        <th>Promotion</th>
                        <th>Mention</th>
                        <th>Moyenne annuelle</th>
                        <th>Décision (Mention)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historical_cursus as $cursus_year): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($cursus_year['annee_libelle']); ?></td>
                        <td><?php echo htmlspecialchars($cursus_year['nom_promotion']); ?></td>
                        <td><?php echo htmlspecialchars($cursus_year['mention_libelle']); ?></td>
                        <td><?php echo htmlspecialchars(number_format($cursus_year['moyenne_annuelle'], 2)); ?></td>
                        <td>
                            <?php
                            $decision_class = '';
                            if ($cursus_year['decision'] === 'Échec') {
                                $decision_class = 'text-danger fw-bold';
                            } elseif ($cursus_year['decision'] === 'Très Bien' || $cursus_year['decision'] === 'Bien') {
                                $decision_class = 'text-success fw-bold';
                            } elseif ($cursus_year['decision'] === 'Assez Bien' || $cursus_year['decision'] === 'Passable') {
                                $decision_class = 'text-info fw-bold';
                            }
                            echo '<span class="' . $decision_class . '">' . htmlspecialchars($cursus_year['decision']) . '</span>';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Nouvelle ligne pour le formulaire de réinscription -->
                    <tr>
                        <td><?php echo htmlspecialchars($next_academic_year_display); ?></td>
                        <td>
                            <select class="form-select form-select-sm" id="reEnrollPromotion" name="reEnrollPromotion">
                                <option value="" selected disabled>Sélectionnez promotion</option>
                                <?php foreach ($all_promotions as $promo): ?>
                                    <option value="<?php echo htmlspecialchars($promo['code_promotion']); ?>"><?php echo htmlspecialchars($promo['nom_promotion']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select class="form-select form-select-sm" id="reEnrollMention" name="reEnrollMention">
                                <option value="" selected disabled>Sélectionnez mention</option>
                                <?php foreach ($available_mentions as $mention): ?>
                                    <option value="<?php echo htmlspecialchars($mention['id_mention']); ?>"><?php echo htmlspecialchars($mention['libelle']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>N/A</td> <!-- Moyenne annuelle pour la réinscription -->
                        <td>
                            <button type="button" class="btn btn-sm btn-success w-100" id="submitReEnrollment">
                                <i class="fas fa-arrow-alt-circle-right me-1"></i> Réinscrire
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-info" role="alert">Aucun historique de cursus trouvé pour cet étudiant.</div>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Année académique</th>
                        <th>Promotion</th>
                        <th>Mention</th>
                        <th>Moyenne annuelle</th>
                        <th>Décision (Mention)</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Nouvelle ligne pour le formulaire de première inscription si l'historique est vide -->
                    <tr>
                        <td><?php echo htmlspecialchars($next_academic_year_display); ?></td>
                        <td>
                            <select class="form-select form-select-sm" id="reEnrollPromotion" name="reEnrollPromotion">
                                <option value="" selected disabled>Sélectionnez promotion</option>
                                <?php foreach ($all_promotions as $promo): ?>
                                    <option value="<?php echo htmlspecialchars($promo['code_promotion']); ?>"><?php echo htmlspecialchars($promo['nom_promotion']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select class="form-select form-select-sm" id="reEnrollMention" name="reEnrollMention">
                                <option value="" selected disabled>Sélectionnez mention</option>
                                <?php foreach ($available_mentions as $mention): ?>
                                    <option value="<?php echo htmlspecialchars($mention['id_mention']); ?>"><?php echo htmlspecialchars($mention['libelle']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>N/A</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-success w-100" id="submitReEnrollment">
                                <i class="fas fa-plus-circle me-1"></i> Inscrire
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <hr class="my-4">

    <!-- Section JavaScript pour gérer la soumission du formulaire de réinscription -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const submitButton = document.getElementById('submitReEnrollment');
            if (submitButton) {
                submitButton.addEventListener('click', function() {
                    const academicYear = '<?php echo $next_academic_year_display; ?>';
                    const promotionCode = document.getElementById('reEnrollPromotion').value;
                    const mentionId = document.getElementById('reEnrollMention').value;
                    const matricule = '<?php echo htmlspecialchars($matricule); ?>'; // Matricule de l'étudiant

                    if (!promotionCode || !mentionId) {
                        alert('Veuillez sélectionner une promotion et une mention pour la réinscription.');
                        return;
                    }

                    // Ici, vous enverriez ces données à un script PHP via AJAX.
                    // Par exemple, en utilisant fetch API:
                    /*
                    fetch('votre_script_reinscription.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            matricule: matricule,
                            annee_academique: academicYear,
                            promotion_code: promotionCode,
                            mention_id: mentionId
                        }),
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Réinscription effectuée avec succès !');
                            // Actualiser la page ou la section du tableau
                            window.location.reload();
                        } else {
                            alert('Erreur lors de la réinscription : ' + data.message);
                        }
                    })
                    .catch((error) => {
                        console.error('Erreur:', error);
                        alert('Une erreur de communication est survenue.');
                    });
                    */
                    alert(`Préparation à la réinscription pour l'année ${academicYear}:\nMatricule: ${matricule}\nPromotion: ${promotionCode}\nMention ID: ${mentionId}\n\n(L'implémentation de la soumission réelle via AJAX est nécessaire.)`);
                });
            }
        });
    </script>
</div>
