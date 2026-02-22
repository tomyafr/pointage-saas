# ⏱ Pointage Atelier — SaaS de saisie des heures par OF

Application web de pointage des heures de production, compatible Microsoft Business Central.

## 🏗 Architecture

```
pointage-saas/
├── index.php              ← Page de connexion
├── operator.php           ← Interface opérateur (saisie heures)
├── chef.php               ← Interface chef d'atelier (suivi + sync BC)
├── api.php                ← API REST (intégration BC)
├── logout.php             ← Déconnexion
├── .htaccess              ← Sécurité Apache
├── includes/
│   └── config.php         ← Configuration DB + BC + fonctions
├── assets/
│   └── style.css          ← Design responsive mobile-first
└── db.sql                 ← Schéma base de données
```

## 🚀 Déploiement sur Hostinger

### 1. Base de données
1. Aller dans **hPanel → Bases de données MySQL**
2. Créer une nouvelle base de données (ex: `u123456789_pointage`)
3. Noter le nom d'utilisateur et mot de passe
4. Aller dans **phpMyAdmin** et importer le fichier `db.sql`

### 2. Configuration
1. Ouvrir `includes/config.php`
2. Modifier les constantes DB :
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'u123456789_pointage');
   define('DB_USER', 'u123456789_user');
   define('DB_PASS', 'votre_mot_de_passe');
   ```

### 3. Upload des fichiers
1. Aller dans **hPanel → Gestionnaire de fichiers** (ou FTP)
2. Uploader tout le contenu de `pointage-saas/` dans `public_html/` (ou un sous-dossier)
3. Vérifier que `.htaccess` est bien uploadé

### 4. SSL
- Hostinger active le SSL automatiquement
- Vérifier que le site est accessible en HTTPS

## 👤 Comptes par défaut

| Nom | Rôle | Mot de passe |
|-----|------|-------------|
| ADMIN | Chef d'atelier | password123 |
| DUPONT | Opérateur | password123 |
| MARTIN | Opérateur | password123 |
| DURAND | Opérateur | password123 |

> ⚠️ **Changez tous les mots de passe en production !**
> Pour créer un hash : `php -r "echo password_hash('nouveau_mdp', PASSWORD_BCRYPT);"` 

## 📱 Utilisation

### Opérateur
1. Se connecter avec nom + mot de passe sur smartphone
2. Saisir le numéro d'OF et les heures travaillées
3. Consulter le total de la semaine dans l'onglet "Ma semaine"

### Chef d'atelier
1. Se connecter → vue globale par OF
2. Cliquer sur un OF pour voir le détail par opérateur/jour
3. Cocher les OF à synchroniser
4. Cliquer "Synchroniser vers Business Central"

## 🔗 Intégration Microsoft Business Central

### Configuration dans Azure AD
1. Créer une **App Registration** dans Azure Portal
2. Ajouter la permission `Dynamics 365 Business Central → API.ReadWrite.All`
3. Créer un **Client Secret**
4. Reporter les valeurs dans `config.php` :
   ```php
   define('BC_TENANT_ID', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
   define('BC_CLIENT_ID', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
   define('BC_CLIENT_SECRET', 'votre_secret');
   define('BC_COMPANY_ID', 'votre-company-id');
   ```

### Mode simulation
Par défaut, l'application est en **mode simulation** (les données sont marquées comme sync mais ne sont pas envoyées à BC). Pour activer l'envoi réel :
- Dans `chef.php`, changer `$simulationMode = true;` en `$simulationMode = false;`

### API REST
L'API est disponible pour que BC puisse aussi interroger les données :

```bash
# Lister les pointages de la semaine
curl -H "X-API-KEY: VOTRE_CLE" \
  "https://votre-site.com/api.php?action=pointages&date_from=2025-01-20&date_to=2025-01-26"

# Pointages d'un OF spécifique
curl -H "X-API-KEY: VOTRE_CLE" \
  "https://votre-site.com/api.php?action=pointages_of&of=OF-2025-001"

# Résumé hebdo format BC
curl -H "X-API-KEY: VOTRE_CLE" \
  "https://votre-site.com/api.php?action=weekly_summary"

# Marquer comme synchronisé (callback BC)
curl -X POST -H "X-API-KEY: VOTRE_CLE" \
  -H "Content-Type: application/json" \
  -d '{"ids":[1,2,3]}' \
  "https://votre-site.com/api.php?action=mark_synced"
```

> ⚠️ Changez la clé API dans `api.php` avant la mise en production !

## 🔒 Sécurité & Conformité SaaS
L'application inclut des fonctionnalités de niveau entreprise :

- **Protection Brute Force** : Limitation automatique après 5 tentatives échouées de la même IP (blocage de 15 min).
- **Journal d'Audit** : Chaque connexion, création, suppression de pointage ou changement de mot de passe est enregistré en base avec l'IP.
- **Gestion des Mots de Passe** : Les utilisateurs peuvent changer leur mot de passe via l'onglet "Mon Profil".
- **Conformité RGPD** : Page intégrée de politique de confidentialité et respect des principes de minimisation des données.
- **Sessions Sécurisées** : Utilisation de cookies sécurisés et système de backup pour environnements Serverless (Vercel).

## 🛠 Installation & Maintenance
1. Exécutez le script dans `db.sql` pour créer les tables.
2. Configurez les accès dans `includes/config.php`.
3. Pour voir les logs d'activité, interrogez la table `audit_logs` en SQL.
