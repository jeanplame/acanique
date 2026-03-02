<?php
    header('Content-Type: text/html; charset=UTF-8');
// Récupération de l'ID de la filière
$filiere_id = isset($_GET['filiere']) ? intval($_GET['filiere']) : 0;

// Récupération de l'année académique depuis l'URL ou par défaut
require_once 'includes/domaine_functions.php';
$id_annee = getAnneeFromUrlOrDefault($pdo, $_GET['annee'] ?? null);

// Vérifier si la filière existe
$query_filiere = "SELECT nomFiliere FROM t_filiere WHERE idFiliere = ?";
$stmt = $pdo->prepare($query_filiere);
$stmt->execute([$filiere_id]);
$filiere = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$filiere) {
    $_SESSION['error'] = "Filière introuvable.";
    header('Location: ?page=error&error_code=404');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $libelle = trim($_POST['libelle'] ?? '');
    $code = trim($_POST['code_mention'] ?? '');
    
    // Validation
    if (empty($libelle) || empty($code)) {
        $_SESSION['error'] = "Tous les champs sont obligatoires.";
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        // Vérifier si le code mention existe déjà
        $check_query = "SELECT id_mention FROM t_mention WHERE code_mention = ? AND idFiliere = ?";
        $check_stmt = $pdo->prepare($check_query);
        $check_stmt->execute([$code, $filiere_id]);
        
        if ($check_stmt->rowCount() > 0) {
            $error = "Ce code de mention existe déjà pour cette filière.";
        } else {
            // Insertion
            $insert_query = "INSERT INTO t_mention (libelle, code_mention, idFiliere) VALUES (?, ?, ?)";
            $insert_stmt = $pdo->prepare($insert_query);
            
            if ($insert_stmt->execute([$libelle, $code, $filiere_id])) {
                $_SESSION['success'] = "La mention a été ajoutée avec succès.";
                header("Location: ?page=domaine&action=view&id=" . $filiere_id);
                exit;
            } else {
                $_SESSION['error'] = "Erreur lors de l'ajout de la mention.";
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            }
        }
    }
}
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        Ajouter une mention - <?php echo htmlspecialchars($filiere['nomFiliere']); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger">
                            <?php 
                            echo $_SESSION['error'];
                            unset($_SESSION['error']);
                            ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="libelle" class="form-label">Libellé de la mention</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="libelle" 
                                   name="libelle" 
                                   value="<?php echo isset($_POST['libelle']) ? htmlspecialchars($_POST['libelle']) : ''; ?>"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label for="code_mention" class="form-label">Code de la mention</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="code_mention" 
                                   name="code_mention"
                                   value="<?php echo isset($_POST['code_mention']) ? htmlspecialchars($_POST['code_mention']) : ''; ?>"
                                   pattern="[A-Za-z0-9-]+"
                                   title="Lettres, chiffres et tirets uniquement"
                                   required>
                            <small class="form-text text-muted">
                                Utilisez uniquement des lettres, des chiffres et des tirets
                            </small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-floppy-fill"></i> Enregistrer
                            </button>
                            <a href="?page=domaine&action=view&id=<?php echo $filiere_id; ?>&annee=<?php echo $id_annee; ?>" class="btn btn-secondary">
                                <i class="bi bi-x-lg"></i> Annuler
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>


