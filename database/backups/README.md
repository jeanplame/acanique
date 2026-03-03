# Sauvegardes de Base de Données - Acadenique

## Description

Ce dossier contient les sauvegardes automatiques de la base de données MySQL `lmd_db`.

## Configuration

- **Base de données** : `lmd_db`
- **Serveur MySQL** : `localhost`
- **Fréquence** : Horaire (toutes les heures) et au démarrage
- **Rétention** : Les 7 dernières sauvegardes sont conservées

## Fichiers

Les sauvegardes sont nommées avec le format : `lmd_db-YYYY-MM-DD_HH-mm-ss.sql`

Exemple : `lmd_db-2026-03-02_15-30-45.sql`

## Utilisation

### Restaurer une sauvegarde

```bash
mysql -u root -p lmd_db < lmd_db-2026-03-02_15-30-45.sql
```

### Sauvegarde manuelle

Pour déclencher une sauvegarde manuellement :

```powershell
powershell.exe -ExecutionPolicy Bypass -File "c:\wamp64\www\acadenique\backup-db.ps1"
```

## Automatisation

Les sauvegardes se font automatiquement via le script `auto-sync.bat` qui s'exécute :
- Au démarrage de l'ordinateur
- Toutes les heures (si tu as configuré la tâche planifiée)

## Logs

Les logs de sauvegarde sont disponibles dans `.git-sync.log`

## Nettoyage

Les sauvegardes de plus de 7 jours sont supprimées automatiquement pour économiser de l'espace.

---

**Important** : Ne supprimez pas le fichier `.gitkeep` - il maintient la structure du dossier.
