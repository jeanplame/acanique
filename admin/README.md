# 📋 Dossier Administration

Ce dossier contient les outils administratifs de l'application.

## 🔧 Gestion des Modes

### Fichier: `switch-mode.php`

#### Accès
```
http://localhost/acadenique/admin/switch-mode.php
```

#### Authentification requise
- **Rôle:** Administrateur uniquement
- **Connexion:** Vous devez être connecté avec un compte admin

#### Description
Interface web pour basculer entre les modes de développement et d'utilisation:

- **🔧 Mode Développement:** Pour modifier et mettre à jour l'application
- **▶️ Mode Utilisation:** Pour l'utilisation normale en production

#### Fonctionnalités

1. **Affichage du mode actuel**
   - Statut en temps réel
   - Badge visuel (🔧 DEV ou ▶️ PROD)
   - Description du mode

2. **Sélection du mode**
   - Boutons pour passer en développement
   - Boutons pour passer en utilisation
   - Bouton de basculement rapide

3. **Informations**
   - Liste des fonctionnalités de chaque mode
   - Avantages et cas d'usage
   - Dernière modification

## 📁 Structure de sécurité

```
admin/
├── switch-mode.php          ← Interface de gestion des modes
├── README.md                ← Ce fichier
└── .htaccess                ← (À créer) Restrictions de sécurité
```

## 🔐 Sécurité

⚠️ **Important:**

1. **Authentification**
   - Seuls les administrateurs peuvent accéder à cette section
   - Une vérification de session est faite à chaque accès

2. **Recommandations**
   - Protégez le dossier `admin/` avec `.htaccess`
   - Limitez l'accès à des adresses IP spécifiques si possible
   - Utilisez HTTPS en production

### Créer un fichier `.htaccess` pour plus de sécurité

```apache
# .htaccess dans le dossier admin/

<FilesMatch "\.php$">
    Require all denied
</FilesMatch>

# Seules les pages specificées sont autorisées
<FilesMatch "^switch-mode\.php$">
    Require all granted
</FilesMatch>

# Ajouter une protection supplémentaire
<Files "*">
    SetEnvIf Request_URI "^.*$" ACCEPT=1
    SetEnvIf Request_URI "^.*\.php$" ACCEPT=0
    Order allow,deny
    Allow from env=ACCEPT
</Files>
```

## 📊 Logs et Suivi

Tous les changements de mode sont enregistrés dans:
```
logs/environment.log
```

Exemple:
```
[2026-03-02 10:15:30] Mode: production | Action: Mode basculé | Détails: {"ancien":"production","nouveau":"development"}
[2026-03-02 10:20:45] Mode: development | Action: Mode défini | Détails: {"mode":"development"}
```

## 🚀 Workflow recommandé

### Faire une mise à jour

```
1. Connectez-vous comme admin
2. Allez à admin/switch-mode.php
3. Cliquez "Passer en Développement"
4. Modifiez l'application
5. Testez les changements
6. Retournez à admin/switch-mode.php
7. Cliquez "Passer en Utilisation"
```

## 📝 Notes

- Le fichier `environment.json` à la racine stocke le mode actuel
- Les changements sont appliqués immédiatement
- Tout nouveau script PHP peut utiliser le mode via `EnvironmentManager`

## 🆘 Dépannage

### Je ne vois pas la page?
- Vérifiez que vous êtes connecté comme admin
- Vérifiez le chemin exact: `/acadenique/admin/switch-mode.php`

### Les changements ne prennent pas effet?
- Rafraîchissez la page web
- Vérifiez que le fichier `environment.json` a les bonnes permissions

### Erreur d'authentification?
- Vérifiez votre session utilisateur
- Reconnectez-vous si nécessaire

---

**Créé:** 2026-03-02
**Dernière mise à jour:** 2026-03-02
