<?php
require_once 'includes/auth.php';
requireLogin(); // L'utilisateur doit au moins être connecté pour voir cette page
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accès Refusé - Acadenique</title>
    <link rel="stylesheet" type="text/css" href="vendor/bootstrap/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0">Accès Refusé</h4>
                    </div>
                    <div class="card-body">
                        <p class="lead">Désolé, vous n'avez pas les permissions nécessaires pour accéder à cette page.</p>
                        <p>Veuillez contacter l'administrateur si vous pensez qu'il s'agit d'une erreur.</p>
                        <a href="index.php" class="btn btn-primary">Retourner à l'accueil</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
