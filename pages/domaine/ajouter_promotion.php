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

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $annee = trim($_POST['annee'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // Validation
    if (empty($annee)) {
        $error = "L'année académique est obligatoire.";
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $annee)) {
        $error = "Le format de l'année doit être AAAA-AAAA (ex: 2024-2025)";
    } else {
        // Vérifier si cette année existe déjà pour cette mention
        $check_query = "SELECT id_promotion FROM promotion WHERE annee = ? AND id_mention = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $annee, $mention_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $error = "Une promotion existe déjà pour cette année académique.";
        } else {
            // Insertion
            $insert_query = "INSERT INTO promotion (annee, description, id_mention) VALUES (?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssi", $annee, $description, $mention_id);
            
            if ($insert_stmt->execute()) {
                header("Location: promotions.php?mention=" . $mention_id . "&success=1");
                exit;
            } else {
                $error = "Erreur lors de l'ajout de la promotion.";
            }
        }
    }
}

// Calculer l'année académique par défaut
$annee_actuelle = date('Y');
$annee_suivante = $annee_actuelle + 1;
$annee_defaut = $annee_actuelle . '-' . $annee_suivante;
?>

<div class="container mt-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Filières</a></li>
            <li class="breadcrumb-item"><a href="index.php?filiere=<?php echo $mention['id_filiere']; ?>"><?php echo htmlspecialchars($mention['filiere_libelle']); ?></a></li>
            <li class="breadcrumb-item"><a href="promotions.php?mention=<?php echo $mention_id; ?>"><?php echo htmlspecialchars($mention['libelle']); ?></a></li>
            <li class="breadcrumb-item active">Nouvelle promotion</li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        Nouvelle promotion - <?php echo htmlspecialchars($mention['libelle']); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="annee" class="form-label">Année académique</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="annee" 
                                   name="annee" 
                                   value="<?php echo isset($_POST['annee']) ? htmlspecialchars($_POST['annee']) : $annee_defaut; ?>"
                                   pattern="\d{4}-\d{4}"
                                   placeholder="2024-2025"
                                   title="Format: AAAA-AAAA (ex: 2024-2025)"
                                   required>
                            <small class="form-text text-muted">
                                Format: AAAA-AAAA (ex: 2024-2025)
                            </small>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description (optionnelle)</label>
                            <textarea class="form-control" 
                                      id="description" 
                                      name="description" 
                                      rows="3"
                                      placeholder="Description optionnelle de la promotion"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Enregistrer
                            </button>
                            <a href="promotions.php?mention=<?php echo $mention_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Annuler
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once('../../includes/footer.php'); ?>
