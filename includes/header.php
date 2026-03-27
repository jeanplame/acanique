<?php
if (!defined('HEADER_INCLUDED')) {
    define('HEADER_INCLUDED', true);

    // Configurer l'encodage de sortie
    header('Content-Type: text/html; charset=UTF-8');

    if (session_status() === PHP_SESSION_NONE) {
        // Sécuriser la session
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
        ini_set('session.use_strict_mode', 1);
        session_start();
    }

    // Protection : si l'utilisateur n'est pas connecté, rediriger
    if (empty($_SESSION['user_id']) || empty($_SESSION['nom_complet'])) {
        header("Location: login.php");
        exit();
    }

    require_once __DIR__ . '/db_config.php';

    // ----- Traitement recherche AJAX -----
    if (isset($_GET['search_term'])) {
        $term = trim($_GET['search_term']);
        
        // Requête enrichie avec les informations du domaine, mention, filière et parcours académique
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                e.matricule,
                e.nom_etu,
                e.postnom_etu,
                e.prenom_etu,
                e.photo,
                d.nom_domaine as genie_logiciel,
                m.libelle as mention_libelle,
                f.nomFiliere as filiere_nom,
                i.code_promotion,
                COUNT(DISTINCT i.id_annee) as nb_annees_parcours,
                GROUP_CONCAT(DISTINCT aa.date_debut ORDER BY aa.date_debut SEPARATOR ', ') as annees_parcours
            FROM t_etudiant e
            LEFT JOIN t_inscription i ON e.matricule = i.matricule
            LEFT JOIN t_mention m ON i.id_mention = m.id_mention
            LEFT JOIN t_filiere f ON i.id_filiere = f.idFiliere
            LEFT JOIN t_domaine d ON f.id_domaine = d.id_domaine
            LEFT JOIN t_anne_academique aa ON i.id_annee = aa.id_annee
            WHERE e.matricule LIKE :term 
               OR e.nom_etu LIKE :term 
               OR e.postnom_etu LIKE :term 
               OR e.prenom_etu LIKE :term
               OR d.nom_domaine LIKE :term
               OR m.libelle LIKE :term
            GROUP BY e.matricule, e.nom_etu, e.postnom_etu, e.prenom_etu, e.photo, d.nom_domaine, m.libelle, f.nomFiliere
            ORDER BY e.nom_etu, e.postnom_etu
            LIMIT 10
        ");
        
        $stmt->execute(['term' => "%$term%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Enrichir les résultats avec des informations supplémentaires formatées
        foreach ($results as &$result) {
            // Formater le nom complet
            $result['nom_complet'] = trim($result['nom_etu'] . ' ' . $result['postnom_etu'] . ' ' . $result['prenom_etu']);
            
            // Traiter la photo de l'étudiant
            if ($result['photo']) {
                $photo_path = $result['photo'];
                
                // Différentes tentatives de résolution du chemin
                $possible_paths = [
                    $photo_path, // Chemin original
                    str_replace('../', '', $photo_path), // Supprimer ../
                    'uploads/photos/' . basename($photo_path), // Juste le nom de fichier
                    ltrim($photo_path, './'), // Supprimer ./ du début
                ];
                
                $result['has_photo'] = false;
                $result['photo_url'] = 'img/default-user.png';
                
                // Tester chaque chemin possible
                foreach ($possible_paths as $test_path) {
                    // Chemin absolu depuis la racine du projet
                    $full_path = __DIR__ . '/../' . $test_path;
                    
                    if (file_exists($full_path)) {
                        $result['photo_url'] = $test_path;
                        $result['has_photo'] = true;
                        $result['debug_working_path'] = $test_path;
                        $result['debug_full_path'] = $full_path;
                        break;
                    }
                }
                
                // Debug supprimé - fonctionnel
            } else {
                $result['photo_url'] = 'img/default-user.png'; // Image par défaut
                $result['has_photo'] = false;
            }
            
            // Vérifier et formater le nom du domaine
            if (!$result['genie_logiciel']) {
                $result['genie_logiciel'] = 'Génie logiciel';
            }
            
            // Calculer les années de parcours
            if (!$result['nb_annees_parcours']) {
                $result['nb_annees_parcours'] = 0;
                $result['parcours_text'] = 'Parcours académique : Aucune inscription';
            } else {
                $result['parcours_text'] = 'Parcours académique : ' . $result['nb_annees_parcours'] . ' année(s)';
            }
        }
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($results);
        exit;
    }


    // Récupérer les informations de l'utilisateur depuis la session
    $userId = $_SESSION['user_id'];
    $nom_complet = htmlspecialchars($_SESSION['nom_complet'], ENT_QUOTES, 'UTF-8');
    $username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');

    // Récupération de toutes les années académiques
    $annees = [];
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 't_anne_academique'");
        if ($stmt->fetch()) {
            $stmt = $pdo->query("SELECT * FROM t_anne_academique ORDER BY date_debut DESC");
            $annees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Récupérer l'année active
        // Priorité : 1. URL ($_GET['annee']), 2. Année en cours (configuration)
        $annee_active = null;
        $annee_active_id = null;
        
        // Si une année est dans l'URL, l'utiliser
        if (isset($_GET['annee']) && is_numeric($_GET['annee'])) {
            $annee_active_id = (int)$_GET['annee'];
        } else {
            // Sinon, utiliser l'année en cours
            $stmt = $pdo->query("SELECT valeur FROM t_configuration WHERE cle = 'annee_encours'");
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $annee_active_id = $row['valeur'];
            }
        }
        
        // Trouver les détails de l'année active
        if ($annee_active_id) {
            foreach ($annees as $annee) {
                if ($annee['id_annee'] == $annee_active_id) {
                    $annee_active = $annee;
                    break;
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération des données: " . $e->getMessage());
    }
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <!-- Favicon -->
    <link href="img/favicon.ico" rel="icon">

    <!-- Bootstrap CSS - Local avec fallback CDN -->
    <link rel="stylesheet" href="css/bootstrap.min.css">
    
    <!-- Bootstrap Icons - Local (fonctionne sans internet) -->
    <link rel="stylesheet" href="css/bootstrap-icons.css">
    
    <!-- CSS personnalisés de l'application -->
    <link href="css/style.css" rel="stylesheet">
    <link href="css/dashboard.css" rel="stylesheet">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="images/icons/favicon.ico" />
    
    <style>
        /* Styles pour la recherche d'étudiants */
        #search-results {
            max-height: 400px;
            overflow-y: auto;
            border-radius: 10px;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            border: 1px solid #dee2e6;
            background: white;
        }
        
        #search-results::-webkit-scrollbar {
            width: 6px;
        }
        
        #search-results::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        #search-results::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        
        #search-results::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        .search-results-header {
            border-radius: 10px 10px 0 0;
            font-weight: 600;
        }
        
        .student-avatar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .student-photo {
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        }
        
        .student-photo:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        /* Fallback pour les images qui ne se chargent pas */
        .student-photo[src=""], .student-photo:not([src]) {
            display: none !important;
        }
        
        .list-group-item:last-child {
            border-radius: 0 0 10px 10px;
        }
        
        .list-group-item:hover {
            transform: translateX(5px);
            transition: all 0.2s ease;
        }
        
        #search-student {
            border-radius: 20px;
            border: 2px solid #e9ecef;
            padding: 8px 16px;
            transition: all 0.3s ease;
        }
        
        #search-student:focus {
            border-color: #0d6efd !important;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }
        
        .input-group:focus-within .input-group-text {
            border-color: #0d6efd !important;
        }
        
        .input-group:focus-within .input-group-text i {
            color: #0d6efd !important;
        }
        
        /* Animation pour les résultats */
        .list-group-item {
            animation: fadeInUp 0.3s ease;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            #search-results {
                max-height: 300px;
                left: -15px !important;
                right: -15px !important;
                width: calc(100% + 30px) !important;
            }
        }
    </style>
    <link href="css/domaine.css" rel="stylesheet">
    <meta charset="UTF-8">


    <!-- Bootstrap JS Bundle with Popper -->
    <script src="js/bootstrap.bundle.min.js"></script>

    <style>
        body {
            font-family: 'Tw Cen MT', sans-serif !important;
            font-size: 18px;
        }

        .logo {
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .logo img {
            height: 45px;
            width: auto;
        }

        .navbar {
            background-color: #003958 !important;
            /* Dark background for the navbar */
            border-bottom: 1px solid #eee;
            /* Subtle border at the bottom */

        }

        .navbar-nav .nav-link {
            color: #fff;
            /* Adjust link color */
        }

        .navbar-nav .nav-link:hover {
            color: #ddd;
            /* Lighter color on hover */
        }

        .navbar-brand {
            color: #fff;
            /* Adjust brand color */
        }

        .navbar-brand:hover {
            color: #ddd;
            /* Lighter color on hover */
        }

        .navbar-toggler {
            border-color: #fff;
            /* White border for the toggler */
        }

        .navbar a {
            color: #f8bc10;
            /* White text for links */
            text-decoration: none;

        }

        .nav-annee {
            color: #f8bc10;
            /* White text for links */
            text-decoration: none;
            margin-left: 40px;
            font-style: italic;
        }
    </style>

    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">

            <a href="index.php" class="logo">
                <img src="img/logo_acanique.png" alt="Logo ACANIQUE" />
            </a>
            <div class="collapse navbar-collapse" id="navbarScroll">
                <!-- Année Académique -->
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item dropdown">
                        <div class="d-flex">
                            <?php if (!empty($annees)): ?>
                                <?php foreach ($annees as $annee): ?>
                                    <?php
                                    $params = $_GET;
                                    $params['action'] = 'select';
                                    $params['annee'] = $annee['id_annee'];
                                    $new_query_string = http_build_query($params);

                                    $is_active = $annee_active && $annee['id_annee'] == $annee_active['id_annee'];
                                    $annee_debut = date('Y', strtotime($annee['date_debut']));
                                    $annee_fin = date('Y', strtotime($annee['date_fin']));
                                    ?>
                                    <a class="btn btn-outline-warning <?php echo $is_active ? 'active annee_encours' : ''; ?> nav-annee"
                                       href="?<?php echo $new_query_string; ?>"
                                       style="margin-right: 5px;">
                                        <?php echo htmlspecialchars($annee_debut . '-' . $annee_fin); ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="nav-link nav-annee">Aucune année académique disponible</span>
                            <?php endif; ?>
                        </div>
                    </li>
                </ul>
                <!-- Profile dropdown -->
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            <i class="bi bi-person-circle"></i> <?php echo $nom_complet; ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="?page=profile">
                                <i class="bi bi-person me-1"></i> Mon Profil
                            </a></li>
                            <li><a class="dropdown-item" href="?page=dashboard&action=settings">
                                <i class="bi bi-gear me-1"></i> Paramètres
                            </a></li>
                            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'administrateur'): ?>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li class="dropdown-header text-muted">
                                <i class="bi bi-shield-lock me-1"></i> Administration
                            </li>
                            <li><a class="dropdown-item" href="?page=config_annees_academiques">
                                <i class="bi bi-calendar3 me-1"></i> Années Académiques
                            </a></li>
                            <li><a class="dropdown-item" href="?page=jury_nomination">
                                <i class="bi bi-people-fill me-1"></i> Nomination Jury
                            </a></li>
                            <li><a class="dropdown-item" href="?page=backup_manager">
                                <i class="bi bi-database-gear me-1"></i> Gestion des Sauvegardes
                            </a></li>
                            <li><a class="dropdown-item" href="?page=backup_dashboard">
                                <i class="bi bi-graph-up me-1"></i> Tableau de Bord Sauvegardes
                            </a></li>
                            <?php endif; ?>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="index.php?page=logout">
                                <i class="bi bi-box-arrow-right me-1"></i> Déconnexion
                            </a></li>
                        </ul>
                    </li>
                </ul>
                <form class="d-flex position-relative" role="search" style="margin-left: 20px; min-width: 300px;">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0" style="border-radius: 20px 0 0 20px; border-color: #e9ecef;">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input id="search-student" class="form-control border-start-0" type="search" 
                               placeholder="Rechercher un étudiant (nom, matricule...)"
                               aria-label="Search" autocomplete="off"
                               style="border-radius: 0 20px 20px 0; border-color: #e9ecef;">
                    </div>
                    <div id="search-results" class="position-absolute w-100" style="top: calc(100% + 5px); z-index: 1050;">
                    </div>
                </form>

            </div>
        </div>
    </nav>



    <script>
        // JavaScript code can be added here if needed
        document.addEventListener('DOMContentLoaded', function () {
            // Example: Add any dynamic behavior or event listeners here
            console.log('Navbar loaded successfully');

            // Ajout des scripts Bootstrap pour gérer les dropdowns
            var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
            var dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
                return new bootstrap.Dropdown(dropdownToggleEl);
            });

            // Gestion du clic sur le lien de déconnexion, mais seulement si l'élément existe
            var logoutLink = document.querySelector('a[href="index.php?page=logout"]');
            if (logoutLink) {
                logoutLink.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.location.href = this.getAttribute('href');
                });
            }
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('search-student');
            const resultsContainer = document.getElementById('search-results');

            searchInput.addEventListener('input', function () {
                const query = this.value.trim();
                if (query.length < 2) {
                    resultsContainer.innerHTML = '';
                    return;
                }
                
                // Ajouter un indicateur de chargement
                resultsContainer.innerHTML = `
                    <div class="list-group-item d-flex align-items-center">
                        <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                        <small class="text-muted">Recherche en cours...</small>
                    </div>
                `;
                
                fetch(`?search_term=${encodeURIComponent(query)}`)
                    .then(res => res.json())
                    .then(data => {
                        resultsContainer.innerHTML = '';
                        if (data.length > 0) {
                            // Ajouter l'en-tête des résultats
                            const header = document.createElement('div');
                            header.className = 'search-results-header px-3 py-2 bg-primary text-white small';
                            header.innerHTML = `
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-search me-2"></i>
                                    <span>Résultats trouvés</span>
                                    <span class="badge bg-light text-primary ms-auto">${data.length}</span>
                                </div>
                            `;
                            resultsContainer.appendChild(header);
                            
                            data.forEach(etudiant => {
                                const item = document.createElement('a');
                                item.href = `?page=profiletudiant&matricule=${encodeURIComponent(etudiant.matricule)}`;
                                item.className = 'list-group-item list-group-item-action border-0 p-3';
                                item.style.cursor = 'pointer';
                                

                                
                                // Créer le contenu détaillé comme dans la capture
                                // Logique simplifiée : afficher soit la photo, soit l'avatar par défaut, jamais les deux
                                let avatarHtml;
                                
                                if (etudiant.has_photo) {
                                    avatarHtml = `
                                        <div class="avatar-container" style="width: 50px; height: 50px; position: relative;">
                                            <img src="${etudiant.photo_url}" alt="Photo ${etudiant.nom_complet}" 
                                                 class="student-photo rounded-circle" 
                                                 style="width: 50px; height: 50px; object-fit: cover; border: 2px solid #dee2e6; display: block;"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div class="student-avatar bg-secondary rounded-circle align-items-center justify-content-center" 
                                                 style="width: 50px; height: 50px; position: absolute; top: 0; left: 0; display: none;">
                                                <i class="bi bi-person-fill text-white fs-4"></i>
                                            </div>
                                        </div>
                                    `;
                                } else {
                                    avatarHtml = `
                                        <div class="student-avatar rounded-circle d-flex align-items-center justify-content-center" 
                                            style="width: 50px; height: 50px;">
                                            <img src="img/default-user.png" alt="Default user" class="rounded-circle" 
                                                style="width: 100%; height: 100%; object-fit: cover;">
                                        </div>
                                    `;
                                }
                                
                                item.innerHTML = `
                                    <div class="d-flex">
                                        <div class="flex-shrink-0 me-3">
                                            ${avatarHtml}
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex w-100 justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1 fw-bold text-dark">${etudiant.nom_complet.toUpperCase()}</h6>
                                                    <p class="mb-1 text-muted small">
                                                        <span class="text-success fw-medium">Matricule :</span> 
                                                        <span class="fw-bold">${etudiant.matricule}</span>
                                                    </p>
                                                    <p class="mb-1 text-muted small">
                                                        <span class="text-primary fw-medium">L1N</span>
                                                        <span class="fw-medium">${etudiant.genie_logiciel || 'Génie logiciel'}</span>
                                                    </p>
                                                    <p class="mb-0 text-muted small">
                                                        <span class="text-info">${etudiant.parcours_text}</span>
                                                    </p>
                                                </div>
                                                <small class="text-muted">
                                                    <i class="bi bi-chevron-right"></i>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                `;
                                
                                // Ajouter un effet de survol
                                item.addEventListener('mouseenter', function() {
                                    this.style.backgroundColor = '#f8f9fa';
                                    this.style.borderLeft = '4px solid #0d6efd';
                                });
                                
                                item.addEventListener('mouseleave', function() {
                                    this.style.backgroundColor = '';
                                    this.style.borderLeft = '';
                                });
                                
                                resultsContainer.appendChild(item);
                            });
                        } else {
                            resultsContainer.innerHTML = `
                                <div class="list-group-item text-center py-4">
                                    <i class="bi bi-search text-muted fs-1 d-block mb-2"></i>
                                    <p class="text-muted mb-0">Aucun résultat trouvé pour "${query}"</p>
                                    <small class="text-muted">Essayez avec le matricule, nom ou prénom</small>
                                </div>
                            `;
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        resultsContainer.innerHTML = `
                            <div class="list-group-item text-center py-3">
                                <i class="bi bi-exclamation-triangle text-danger"></i>
                                <small class="text-danger ms-1">Erreur lors de la recherche</small>
                            </div>
                        `;
                    });
            });

            // Cacher la liste quand on clique ailleurs
            document.addEventListener('click', function (e) {
                if (!resultsContainer.contains(e.target) && e.target !== searchInput) {
                    resultsContainer.innerHTML = '';
                }
            });
        });
    </script>


    <?php
}
?>