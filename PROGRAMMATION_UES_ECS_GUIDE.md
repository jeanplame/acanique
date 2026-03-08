# 📋 Documentation: Système de Programmation des UEs/ECs

## 📅 Date de Mise en Œuvre
**7 mars 2026**

---

## 🎯 Vue d'Ensemble

Vous pouvez maintenant contrôler quelles **Unités d'Enseignement (UEs)** et **Éléments Constitutifs (ECs)** sont disponibles pour une année académique donnée. Avant de pouvoir saisir des notes ou faire de la délibération, vous devez d'abord **programmer** (activer) les UEs/ECs pour cette année.

**Bénéfices:**
- ✅ Flexibilité pédagogique : Adapter les programmes d'études chaque année
- ✅ Aucune perte de données : Les données historiques restent intactes
- ✅ Zéro rupture : Rétrocompatibilité complete (par défaut, toutes les UEs/ECs existantes sont programmées)
- ✅ Audit complet : Historique des programmations/déprogrammations

---

## 🔧 Changements Techniques Apportés

### 1. **Base de Données - Nouvelles Colonnes**

#### `t_unite_enseignement`
```sql
ALTER TABLE t_unite_enseignement 
ADD COLUMN is_programmed TINYINT(1) DEFAULT 1
```
- **0** = UE déprogrammée (non disponible)
- **1** = UE programmée (disponible) ✅ PAR DÉFAUT

#### `t_element_constitutif`
```sql
ALTER TABLE t_element_constitutif 
ADD COLUMN is_programmed TINYINT(1) DEFAULT 1
```
- **0** = EC déprogrammé (non disponible)
- **1** = EC programmé (disponible) ✅ PAR DÉFAUT

### 2. **Nouvelle Table d'Audit**

#### `t_audit_programmation`
Enregistre toutes les modifications de programmation (qui a changé quoi, quand, et pourquoi):
- `type_element`: UE ou EC
- `id_element`: ID de l'UE/EC modifié
- `ancien_statut`: Statut avant (0 ou 1)
- `nouveau_statut`: Statut après (0 ou 1)
- `username`: Utilisateur qui a effectué le changement
- `date_modification`: Timestamp
- `commentaire`: Description (code et libellé)

### 3. **Nouvelles Vues SQL**

#### `vue_grille_deliberation` (MISE À JOUR)
Ajout de filtres:
```sql
AND ue.is_programmed = 1
AND (ec.is_programmed = 1 OR ec.id_ec IS NULL)
```

Cela garantit que seules les UEs/ECs programmées apparaissent dans :
- Les grilles de délibération
- Les calculs de moyennes
- Les exports PDF

### 4. **Fonctions PHP Modifiées**

#### `getECsForPromotion()` dans `notes.php` et `notes_fixed.php`
Filtre ajouté:
```sql
AND ue.is_programmed = 1
AND (ec.is_programmed = 1 OR ec.id_ec IS NULL)
```

---

## 🚀 Utilisation: Nouvel Onglet "Programmation des UEs/ECs"

### Accès
**Domaine** → Sélectionner une Filière → Sélectionner une Mention → Sélectionner une Promotion → **Onglet "Programmation des UEs/ECs"**

### Interface

#### 1️⃣ **Sélection du Semestre**
- Choisissez le semestre (S1 ou S2)
- Les UEs/ECs du semestre sélectionné s'affichent

#### 2️⃣ **Liste des UEs**
Pour chaque UE, vous verrez:
- ☑️ **Checkbox** : Cochez pour programmer / Décochez pour déprogrammer
- **Code UE** (couleur bleu/code)
- **Libellé UE**
- **Nombre de crédits** (badge bleu)
- **Nombre d'ECs** associés

#### 3️⃣ **Liste des ECs sous chaque UE**
Pour chaque EC, vous verrez:
- ☑️ **Checkbox** : Cochez/Décochez
- **Code EC** (couleur bleu/code)
- **Libellé EC**
- **Coefficient** (badge gris)
- **Statut** : ✓ Programmée ou ✗ Déprogrammée

#### 4️⃣ **Actions Rapides**
```
[✓ Programmer toutes les UEs]  [✗ Déprogrammer toutes les UEs]
```

### Comportement

**Quand vous cochez une UE/EC:**
- Le changement est envoyé en AJAX (pas de rechargement)
- Un enregistrement est créé dans `t_audit_programmation`
- La carte de l'UE change de couleur :
  - 🟢 **Vert** = Programmée
  - 🔴 **Rouge** = Déprogrammée

---

## 📊 Flux de Données

```
┌─────────────────────────────────────────────────────────────┐
│ ANNÉE ACADÉMIQUE 2025-2026                                  │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ÉTAPE 1: PROGRAMMATION (NOUVEAU) 📋                        │
│  ├─ Programmer les UEs/ECs pour l'année                    │
│  ├─ Onglet: "Programmation des UEs/ECs"                   │
│  └─ ✅ Tous les changements sont auditées                  │
│                                                              │
│  ÉTAPE 2: SAISIE DES INSCRIPTIONS                          │
│  ├─ Inscrire les étudiants                                │
│  └─ Onglet: "Inscriptions"                                │
│                                                              │
│  ÉTAPE 3: SAISIE DES NOTES                                │
│  ├─ Saisir les notes dans les ECs/UEs programmées         │
│  ├─ Onglet: "Notes" → "Cotation"                          │
│  └─ ✅ SEULES les UEs/ECs programmées sont disponibles    │
│                                                              │
│  ÉTAPE 4: DÉLIBÉRATION                                     │
│  ├─ Calcul des moyennes (UEs/ECs programmées seulement)   │
│  ├─ Onglet: "Notes" → "Délibération"                     │
│  └─ ✅ Export PV et palmarès                             │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

---

## 🔒 Permissions

L'onglet "Programmation des UEs/ECs" est accessible à :
- **Administrateurs** (toujours)
- **Utilisateurs avec** : Module **Cotes**, Permission **S** (Select)

Configuration dans `contenu_main.php`:
```php
'programmation' => ['module' => 'Cotes', 'perm' => 'S'],
```

---

## 📝 Exemple Pratique

### Scénario: Configuration pour L1-S1 (2025-2026)

**Vous avez:** 
- UE1: Mathématiques (3 crédits)
  - EC1: Algèbre (2 coeff)
  - EC2: Géométrie (1 coeff)
- UE2: Informatique (2 crédits)
  - EC3: C++ (2 coeff)
  
**Vous faites:**
1. Allez à **Programmation des UEs/ECs**
2. Sélectionnez **Semestre 1**
3. ✅ Cochez **Mathématiques** et ses deux ECs
4. ❌ Décochez **Informatique** (pas offerte cette année)
5. Cliquez sur chaque EC pour confirmer

**Résultat:**
- ✅ Les notes peuvent être saisies pour Maths et ses ECs
- ❌ Impossible de saisir des notes en Informatique
- ✅ Délibération : Maths compte, Informatique ne compte pas
- ✅ Historique : L'audit enregistre qui a déprogrammé Info et quand

---

## 🔄 Migration: Rétrocompatibilité

**Important:** Votre base de données a été mise à jour le **7 mars 2026**

✅ **Toutes les UEs/ECs existantes sont marquées comme programmées par défaut (is_programmed = 1)**

Cela signifie:
- Aucun changement au comportement existant
- Vous pouvez commencer à utiliser le système progressivement
- Vos données historiques restent intactes
- Les rapports existants continueront à fonctionner correctement

---

## 📂 Fichiers Modifiés/Créés

### Fichiers Créés
```
/migrations/
├── add_is_programmed_columns.sql       (Script de migration)
└── run_migration.php                    (Exécution de la migration)

/pages/domaine/tabs/
└── programmation.php                    (Nouvel onglet)
```

### Fichiers Modifiés
```
/pages/domaine/
├── contenu_main.php                     (Ajout onglet au menu)

/pages/domaine/tabs/
├── notes.php                            (Filtre is_programmed)
├── notes_fixed.php                      (Filtre is_programmed)

/database/
└── (Triggers créés dans la migration)
```

---

## 🐛 Troubleshooting

### Q: Je ne vois pas le nouvel onglet
**R:** 
- Vérifiez que vous êtes connecté (utilisateur avec permission Cotes-S)
- Vérifiez que vous avez modifié les paramètres d'URL correctement
- Rafraîchissez la page

### Q: Pourquoi mes UEs/ECs n'apparaissent pas en cotation ?
**R:** 
- Vérifiez qu'elles sont programmées (onglet Programmation)
- Vérifiez que vous avez sélectionné le bon semestre
- Vérifiez que des étudiants sont inscrits à la mention

### Q: Comment voir l'historique des changements ?
**R:** Requête SQL:
```sql
SELECT * FROM t_audit_programmation 
ORDER BY date_modification DESC 
LIMIT 50;
```

---

## ✅ Checklist: Premier Déploiement

- [ ] Accédez à **Programmation des UEs/ECs**
- [ ] Sélectionnez une promotion et un semestre
- [ ] Testez de cocher/décocher une UE
- [ ] Vérifiez que l'audit enregistre le changement
- [ ] Vérifiez que la cotation filtre correctement
- [ ] Testez la délibération avec UEs programées vs déprogramées

---

## 📞 Support

Pour toute question ou problème :
1. Vérifiez les logs : `logs/` dossier
2. Exécutez : `SELECT * FROM t_audit_programmation ORDER BY date_modification DESC`
3. Contactez l'administrateur

---

**✨ Bonne utilisation du système de programmation pedagogique ! ✨**
