# Acadenique

## Description

**Acadenique** est une plateforme de gestion académique complète conçue pour les établissements scolaires et universitaires. Elle offre des outils robustes pour la gestion des étudiants, des cours, des notes, des délibérations et bien d'autres fonctionnalités essentielles.

## Fonctionnalités principales

- 👥 **Gestion des utilisateurs** : Authentification sécurisée, gestion des rôles et permissions
- 📚 **Gestion des domaines et cours** : Organisation hiérarchique des programmes académiques
- 📊 **Gestion des notes** : Saisie, modification et consultation des évaluations
- ⚖️ **Délibération** : Calcul automatique des résultats et génération de rapports
- 🎓 **Rattrapage** : Gestion des sessions de rattrapage et évaluations
- 📋 **Palmarès** : Classement des étudiants par performance
- 💾 **Système de sauvegarde** : Backups automatisés et restoration rapide
- 🔐 **Contrôle d'accès** : Permissions granulaires par utilisateur et domaine
- 🌍 **Mode développement/Production** : Basculer entre les modes selon les besoins

## Structure du projet

```
acadenique/
├── admin/              # Interface d'administration
├── pages/              # Pages principales de l'application
│   ├── domaine/        # Gestion des domaines
│   ├── etudiant/       # Gestion des étudiants
│   └── palmes/         # Palmarès
├── includes/           # Fichiers include réutilisables
├── ajax/               # Endpoints AJAX
├── js/                 # JavaScript frontend
├── css/                # Feuilles de style
├── assets/             # Ressources statiques
├── database/           # Scripts de base de données
└── config.php          # Configuration principale
```

## Installation

### Prérequis

- PHP 7.4+
- MySQL 5.7+
- Serveur web (Apache, Nginx, etc.)

### Étapes d'installation

1. **Cloner le dépôt**
   ```bash
   git clone https://github.com/jeanplame/acadenique.git
   cd acadenique
   ```

2. **Configurer la base de données**
   - Créer une base de données MySQL
   - Importer les scripts SQL du dossier `database/`

3. **Configuration**
   - Copier `environment-examples.php` en `environment.json`
   - Adapter les paramètres de connexion à votre environnement

4. **Accès initial**
   - Accéder à `http://localhost/acadenique`
   - Se connecter avec les identifiants par défaut

## Utilisation

### Gestion des permissions

L'application utilise un système de permissions granulaires :
- Permissions par utilisateur
- Permissions par domaine
- Permissions par action (voir, créer, modifier, supprimer)

### Sauvegarde

Les sauvegardes peuvent être effectuées :
- Manuellement via l'interface d'administration
- Automatiquement via le planificateur de tâches

### Modes de développement

- **Mode développement** : Affiche les erreurs, logs détaillés
- **Mode production** : Optimisé pour la performance, masque les erreurs

## Configuration des années académiques

L'application supporte la gestion de plusieurs années académiques avec :
- Configuration des périodes
- Organisation par semestres
- Gestion des unités d'enseignement (UE)
- Suivi des éléments constitutifs (EC)

## Support et documentation

Pour plus d'informations, consultez :
- Les guides dans le dossier `docs/`
- La documentation des API admin
- Les commentaires dans le code source

## Licence

Ce projet est développé pour un usage académique et institutionnel.

## Auteur

Développé par l'équipe académique - 2025/2026

---

**Dernière mise à jour :** Mars 2026
