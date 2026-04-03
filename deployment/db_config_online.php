<?php
/**
 * db_config.php — Configuration base de données (VERSION CPANEL)
 *
 * ─── À UPLOADER SUR CPANEL DANS : public_html/includes/ ───────────
 *
 * COMMENT TROUVER CES INFORMATIONS :
 *  1. Connectez-vous à votre cPanel
 *  2. Allez dans "Bases de données MySQL"
 *  3. Créez une base de données  → notez le nom complet (ex: user123_lmd)
 *  4. Créez un utilisateur       → notez le nom complet (ex: user123_acadmin)
 *  5. Associez l'utilisateur à la base avec TOUS LES PRIVILÈGES
 *  6. L'hôte est toujours "localhost" sur cPanel
 */

if (!defined('DB_CONFIG_INCLUDED')) {
    define('DB_CONFIG_INCLUDED', true);

    // ── À REMPLIR AVEC VOS INFOS CPANEL ─────────────────────────────
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'unilocd_resulat_db');  // cPanel: prefixe_nombase
    define('DB_USER', 'unilocd');             // cPanel: prefixe_nomutilisateur
    define('DB_PASS', '3csI8)2YHtg+1R');      // Votre mot de passe BD
    // ────────────────────────────────────────────────────────────────
}
