<?php
// Fichier : includes/permission_helpers.php
// Fonctions utilitaires pour la gestion des permissions dans les vues

require_once 'page_permissions.php';
require_once 'auth.php';

/**
 * Affiche le contenu seulement si l'utilisateur a les permissions requises
 * Sinon affiche un message d'erreur
 * 
 * @param string $module Le module requis
 * @param string $permission La permission requise
 * @param callable $content_callback Fonction qui affiche le contenu
 * @param string $error_message Message d'erreur personnalisé (optionnel)
 */
function showWithPermission($module, $permission, $content_callback, $error_message = null) {
    global $pdo;
    
    if (hasPermission($pdo, $_SESSION['user_id'], $module, $permission)) {
        // L'utilisateur a la permission, afficher le contenu
        if (is_callable($content_callback)) {
            $content_callback();
        } else {
            echo $content_callback;
        }
    } else {
        // L'utilisateur n'a pas la permission, afficher un message d'erreur
        if ($error_message === null) {
            $error_message = "Vous n'avez pas les permissions nécessaires pour voir ce contenu. (Permission requise : $module - $permission)";
        }
        
        echo '<div class="alert alert-warning" role="alert">';
        echo '<i class="fas fa-exclamation-triangle me-2"></i>';
        echo htmlspecialchars($error_message);
        echo '</div>';
    }
}

/**
 * Retourne le contenu HTML seulement si l'utilisateur a les permissions
 * 
 * @param string $module Le module requis
 * @param string $permission La permission requise
 * @param string $content Le contenu HTML à afficher
 * @return string Le contenu ou un message d'erreur
 */
function getContentWithPermission($module, $permission, $content) {
    global $pdo;
    
    if (hasPermission($pdo, $_SESSION['user_id'], $module, $permission)) {
        return $content;
    } else {
        return '<div class="alert alert-warning" role="alert">' .
               '<i class="fas fa-exclamation-triangle me-2"></i>' .
               "Vous n'avez pas les permissions nécessaires pour voir ce contenu. (Permission requise : $module - $permission)" .
               '</div>';
    }
}

/**
 * Vérifie les permissions et retourne un array avec le statut et le message
 * 
 * @param string $module Le module requis
 * @param string $permission La permission requise
 * @return array ['allowed' => bool, 'message' => string]
 */
function checkPermissionStatus($module, $permission) {
    global $pdo;
    
    $allowed = hasPermission($pdo, $_SESSION['user_id'], $module, $permission);
    
    return [
        'allowed' => $allowed,
        'message' => $allowed ? '' : "Permission requise : $module - $permission"
    ];
}

/**
 * Affiche un onglet seulement si l'utilisateur a les permissions
 * Masque complètement l'onglet si pas de permission (ne l'affiche pas désactivé)
 * 
 * @param string $module Le module requis
 * @param string $permission La permission requise
 * @param string $tab_id L'ID de l'onglet
 * @param string $tab_name Le nom de l'onglet
 * @param bool $active Si l'onglet est actif
 * @return string Le HTML de l'onglet ou une chaîne vide
 */
function getTabWithPermission($module, $permission, $tab_id, $tab_name, $active = false) {
    global $pdo;
    
    if (hasPermission($pdo, $_SESSION['user_id'], $module, $permission)) {
        $active_class = $active ? ' active' : '';
        return '<li class="nav-item">' .
               '<a class="nav-link' . $active_class . '" id="' . $tab_id . '-tab" data-bs-toggle="tab" href="#' . $tab_id . '" role="tab">' .
               htmlspecialchars($tab_name) .
               '</a></li>';
    }
    
    // Retourner une chaîne vide - l'onglet est complètement masqué
    return '';
}

/**
 * Affiche le contenu d'un onglet seulement si l'utilisateur a les permissions
 * 
 * @param string $module Le module requis
 * @param string $permission La permission requise
 * @param string $tab_id L'ID de l'onglet
 * @param callable|string $content_callback Le contenu ou fonction qui génère le contenu
 * @param bool $active Si l'onglet est actif
 * @return string Le HTML du contenu de l'onglet
 */
function getTabContentWithPermission($module, $permission, $tab_id, $content_callback, $active = false) {
    global $pdo;
    
    if (hasPermission($pdo, $_SESSION['user_id'], $module, $permission)) {
        $active_class = $active ? ' show active' : '';
        $content = '';
        
        if (is_callable($content_callback)) {
            ob_start();
            $content_callback();
            $content = ob_get_clean();
        } else {
            $content = $content_callback;
        }
        
        return '<div class="tab-pane fade' . $active_class . '" id="' . $tab_id . '" role="tabpanel">' .
               $content .
               '</div>';
    }
    
    return '<div class="tab-pane fade' . ($active ? ' show active' : '') . '" id="' . $tab_id . '" role="tabpanel">' .
           '<div class="alert alert-warning" role="alert">' .
           '<i class="fas fa-exclamation-triangle me-2"></i>' .
           "Vous n'avez pas les permissions nécessaires pour voir ce contenu. (Permission requise : $module - $permission)" .
           '</div>' .
           '</div>';
}