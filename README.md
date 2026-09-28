# RSS Reader

[![CI](https://img.shields.io/github/actions/workflow/status/Manu86/rss-reader/ci.yml?branch=main)](https://github.com/Manu86/rss-reader/actions/workflows/ci.yml)
[![Version](https://img.shields.io/github/v/tag/Manu86/rss-reader?sort=semver)](https://github.com/Manu86/rss-reader/tags)
[![License: MIT](https://img.shields.io/github/license/Manu86/rss-reader)](LICENSE)

Lecteur RSS/Atom auto-hébergé et multi-utilisateur, développé en PHP, SQLite
et JavaScript natif. Il propose une interface responsive installable comme
PWA et une API JSON.

## Captures d’écran desktop

<p align="center">
  <img src="docs/screenshots/rss-reader-desktop-01.png" alt="Vue principale de RSS Reader" width="49%">
  <img src="docs/screenshots/rss-reader-desktop-02.png" alt="Vue détaillée de RSS Reader" width="49%">
</p>

## Fonctionnalités

- Comptes isolés, sans inscription publique (premier compte créé à l'installation, autres comptes via CLI)
- Abonnements RSS/Atom : ajout direct ou découverte sur site web, catégories optionnelles, favicon et images des articles stockées localement
- Actualisation automatique (cron) et manuelle, un flux ou tous, détection de doublons
- Articles : vues Tous / Non lus / Lus / Favoris / par catégorie / par flux, recherche plein texte (FTS5)
- Tags d'articles importés des flux, affichés en lecture seule dans le lecteur
- « Recommandé pour vous » : suggestions locales de non-lus proches de vos favoris (aucune IA, aucun service externe)
- Import/export OPML des abonnements et catégories
- Paramètres : mot de passe, chargement de 25 articles, thème clair/sombre
- Interface accessible (clavier, focus visible, contraste) et mode PWA installable avec shell hors ligne

## Prérequis

PHP 8.2 ou plus récent avec les extensions suivantes : PDO SQLite, SQLite
(avec FTS5, activé par défaut sur les distributions courantes), ctype, curl,
dom, GD, json, mbstring, session. Les comptes PHP-FPM et cron doivent disposer
d’un accès ACL partagé sur `var/media` et ses sous-dossiers. Composer
est requis pour l'installation et la
qualité. La production nécessite HTTPS et une racine web configurée sur
`public/`.

Quand PHP-FPM et le cron utilisent des comptes distincts, accordez-leur un ACL
partagé sur les médias existants et futurs avec le script d’administration :

```bash
bin/fix-media-acl
```

Le compte courant est utilisé par défaut pour le cron et `www-data` pour
PHP-FPM. Pour employer d’autres comptes, passez-les dans cet ordre :
`bin/fix-media-acl CRON_USER PHP_FPM_USER`. Le script utilise
`APP_MEDIA_PATH` quand cette variable est définie et demande les droits
`sudo` nécessaires.

## Installation

```bash
composer install
php bin/console app:install <utilisateur>
```

L'installation crée la base, applique les migrations (schéma initial,
recherche plein texte, thème, tags d'articles), prépare le stockage et crée
le premier utilisateur. Le mot de passe est demandé dans le terminal. Pour
une installation automatisée, ajoutez `--password-stdin` et fournissez le
mot de passe sur l'entrée standard.

Configurez ensuite le serveur web pour servir le répertoire `public/` et
planifiez la synchronisation :

```bash
# toutes les heures, selon votre crontab
php bin/console feeds:refresh
```

Pour activer les recommandations par email, configurez également
`APP_MAILER_DSN`, `APP_MAIL_FROM`, `APP_BASE_URL` et, facultativement,
`APP_MAIL_FROM_NAME`. Exemple :

```bash
APP_MAILER_DSN='smtp://user:password@smtp.example.org:587'
APP_MAIL_FROM='rss@example.org'
APP_MAIL_FROM_NAME='RSS Reader'
APP_BASE_URL='https://rss.example.org'
```

Avec un relais local compatible `sendmail`, par exemple `msmtp` :

```bash
APP_MAILER_DSN='sendmail://default?command=/usr/sbin/sendmail+-t+-i'
```

Les identifiants inclus dans le DSN doivent être encodés comme une URL et ne
doivent jamais être versionnés.

Le secret applicatif est généré dans `var/app.secret` à l'installation ; la
variable d'environnement `APP_SECRET` le remplace le cas échéant. La liste
des commandes CLI disponibles et les exigences de production figurent dans
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) et
[`docs/PROJECT.md`](docs/PROJECT.md).

## Développement et tests

```bash
composer check   # lint + analyse statique + tests (la barre de fin de tâche)
composer audit   # vulnérabilités (accès réseau requis)
npm ci && node tests/Frontend/check.mjs && node tests/Frontend/run.mjs
```

Le frontend exige Node 18+. Détails et structure des tests dans
[`docs/TESTING.md`](docs/TESTING.md).

## Documentation

- [Spécification générale (scope V1, exclusions)](docs/PROJECT.md)
- [Fonctionnalités](docs/FEATURES.md)
- [Architecture, CLI et cron](docs/ARCHITECTURE.md)
- [API JSON](docs/API.md)
- [Base de données et migrations](docs/DATABASE.md)
- [RSS/Atom : parsing et synchronisation](docs/RSS.md)
- [Sécurité](docs/SECURITY.md)
- [Frontend et accessibilité](docs/FRONTEND.md)
- [PWA](docs/PWA.md)
- [Tests et qualité](docs/TESTING.md)

Les règles de contribution destinées aux agents de développement figurent
dans [`AGENTS.md`](AGENTS.md).

## Licence

Code source distribué sous [licence MIT](LICENSE). Dépendances tierces
gérées par Composer (`composer show --license` pour la liste des licences
incluses).
