<?php
// Configuration des permissions pour tous les modules du système

// Permissions pour les onglets et les actions
$TAB_PERMISSIONS = [
    // Gestion des notes/cotes
    'cotation' => [
        'module' => 'Cotes',
        'perm' => 'S'
    ],
    'deliberation' => [
        'module' => 'Cotes',
        'perm' => 'A'
    ],
    
    // Gestion des inscriptions
    'inscriptions' => [
        'module' => 'Inscriptions',
        'perm' => 'S'
    ],
    'add_inscription' => [
        'module' => 'Inscriptions',
        'perm' => 'I'
    ],
    
    // Gestion des UE
    'ue' => [
        'module' => 'Cours',
        'perm' => 'S'
    ],
    'add_ue' => [
        'module' => 'Cours',
        'perm' => 'I'
    ],
    
    // Gestion des utilisateurs
    'users' => [
        'module' => 'Utilisateurs',
        'perm' => 'S'
    ],
    'add_user' => [
        'module' => 'Utilisateurs',
        'perm' => 'I'
    ]
];

// Mapping des actions aux permissions
$ACTION_PERMISSIONS = [
    'view' => 'S',     // Select/Read
    'add' => 'I',      // Insert
    'edit' => 'U',     // Update
    'delete' => 'D',   // Delete
    'validate' => 'A', // Admin/Approve
];

// Vérifier les permissions pour un onglet
function checkTabPermission($pdo, $tab, $username = null) {
    global $TAB_PERMISSIONS;
    
    if (!$username && isset($_SESSION['user_id'])) {
        $username = $_SESSION['user_id'];
    }
    
    if (!isset($TAB_PERMISSIONS[$tab])) {
        return true; // Si l'onglet n'est pas défini dans les permissions, on autorise par défaut
    }
    
    $config = $TAB_PERMISSIONS[$tab];
    return hasPermission($pdo, $username, $config['module'], $config['perm']);
}

// Vérifier les permissions pour une action
function checkActionPermission($pdo, $module, $action, $username = null) {
    global $ACTION_PERMISSIONS;
    
    if (!$username && isset($_SESSION['user_id'])) {
        $username = $_SESSION['user_id'];
    }
    
    if (!isset($ACTION_PERMISSIONS[$action])) {
        return false;
    }
    
    return hasPermission($pdo, $username, $module, $ACTION_PERMISSIONS[$action]);
}

// Générer les boutons d'action en fonction des permissions
function generateActionButtons($pdo, $module, $id, $options = []) {
    $buttons = '';
    
    // Structure des boutons avec leurs permissions requises
    $buttonConfig = [
        'view' => [
            'html' => '<a href="?page=%s&action=view&id=%d" class="btn btn-sm btn-info" title="Voir">
                        <i class="bi bi-eye"></i></a>',
            'perm' => 'S'
        ],
        'edit' => [
            'html' => '<a href="?page=%s&action=edit&id=%d" class="btn btn-sm btn-primary" title="Modifier">
                        <i class="bi bi-pencil"></i></a>',
            'perm' => 'U'
        ],
        'delete' => [
            'html' => '<a href="#" onclick="confirmDelete(%d); return false;" class="btn btn-sm btn-danger" title="Supprimer">
                        <i class="bi bi-trash"></i></a>',
            'perm' => 'D'
        ],
        'validate' => [
            'html' => '<a href="?page=%s&action=validate&id=%d" class="btn btn-sm btn-success" title="Valider">
                        <i class="bi bi-check-circle"></i></a>',
            'perm' => 'A'
        ]
    ];
    
    foreach ($buttonConfig as $action => $config) {
        if (checkActionPermission($pdo, $module, $action)) {
            $buttons .= sprintf($config['html'], strtolower($module), $id) . ' ';
        }
    }
    
    return $buttons;
}
