<?php
/**
 * Fonctions pour le filtrage des domaines selon l'utilisateur
 */

/**
 * Obtient l'ID du domaine autorisé pour un utilisateur basé sur son nom d'utilisateur
 * @param string $username Nom d'utilisateur
 * @return int|null ID du domaine autorisé ou null si tous sont autorisés
 */
function getAuthorizedDomainForUser(string $username): ?int {
    // Mapping des noms d'utilisateur vers les IDs de domaine
    $userDomainMapping = [
        'informatique' => 4,  // Sciences et Technologies (STECH)
        'economie' => 3,      // Sciences Economiques et de Gestion (SEG)
        'agronomie' => 1,     // Sciences Agronomiques et Environnement (SAEEC)
        'psychologie' => 6,   // Sciences Psychologiques et de l'Education (SPEDL)
        'droit' => 5,         // Sciences Juridiques, Politiques et Administratives (SJPA)
    ];
    
    return $userDomainMapping[$username] ?? null;
}

/**
 * Filtre les domaines selon l'utilisateur connecté
 * @param array $domaines Liste complète des domaines
 * @param string $username Nom d'utilisateur connecté
 * @return array Liste filtrée des domaines
 */
function filterDomainesForUser(array $domaines, string $username): array {
    // L'admin voit tous les domaines
    if ($username === 'admin') {
        return $domaines;
    }
    
    // Obtenir l'ID du domaine autorisé pour cet utilisateur
    $authorizedDomainId = getAuthorizedDomainForUser($username);
    
    // Si aucun domaine spécifique n'est défini, retourner tous les domaines
    if ($authorizedDomainId === null) {
        return $domaines;
    }
    
    // Filtrer pour ne garder que le domaine autorisé
    return array_filter($domaines, function($domaine) use ($authorizedDomainId) {
        return $domaine['id_domaine'] == $authorizedDomainId;
    });
}

/**
 * Obtient les informations sur le domaine autorisé pour un utilisateur
 * @param PDO $pdo Connexion à la base de données
 * @param string $username Nom d'utilisateur
 * @return array|null Informations du domaine ou null
 */
function getUserAuthorizedDomainInfo(PDO $pdo, string $username): ?array {
    $authorizedDomainId = getAuthorizedDomainForUser($username);
    
    if ($authorizedDomainId === null) {
        return null; // Utilisateur a accès à tous les domaines
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM t_domaine WHERE id_domaine = ?");
        $stmt->execute([$authorizedDomainId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("Erreur lors de la récupération du domaine autorisé : " . $e->getMessage());
        return null;
    }
}

/**
 * Obtient les noms d'utilisateur et leurs domaines autorisés (pour debug/admin)
 * @return array Mapping username => domaine info
 */
function getUserDomainMappingInfo(): array {
    return [
        'informatique' => [
            'id_domaine' => 4,
            'code_domaine' => 'STECH',
            'nom_domaine' => 'Sciences et Technologies'
        ],
        'economie' => [
            'id_domaine' => 3,
            'code_domaine' => 'SEG',
            'nom_domaine' => 'Sciences Economiques et de Gestion'
        ],
        'agronomie' => [
            'id_domaine' => 1,
            'code_domaine' => 'SAEEC',
            'nom_domaine' => 'Sciences Agronomiques et Environnement'
        ],
        'psychologie' => [
            'id_domaine' => 6,
            'code_domaine' => 'SPEDL',
            'nom_domaine' => 'Sciences Psychologiques et de l\'Education'
        ],
        'droit' => [
            'id_domaine' => 5,
            'code_domaine' => 'SJPA',
            'nom_domaine' => 'Sciences Juridiques, Politiques et Administratives'
        ]
    ];
}

/**
 * Vérifie si un utilisateur a accès à un domaine spécifique
 * @param string $username Nom d'utilisateur
 * @param int $domainId ID du domaine à vérifier
 * @return bool True si l'utilisateur a accès, false sinon
 */
function userHasAccessToDomain(string $username, int $domainId): bool {
    // L'admin a accès à tout
    if ($username === 'admin') {
        return true;
    }
    
    $authorizedDomainId = getAuthorizedDomainForUser($username);
    
    // Si aucune restriction, accès à tout
    if ($authorizedDomainId === null) {
        return true;
    }
    
    // Vérifier si le domaine demandé correspond au domaine autorisé
    return $domainId == $authorizedDomainId;
}
?>