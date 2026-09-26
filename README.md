# RSS Reader

Lecteur RSS/Atom auto-hébergé et multi-utilisateur, développé en PHP, SQLite
et JavaScript natif. Il propose une interface responsive installable comme
PWA et une API JSON.

## Fonctionnalités

- Comptes isolés, sans inscription publique
- Abonnements RSS/Atom, catégories et import/export OPML
- Articles lus, non lus, favoris et recherche plein texte
- Actualisation périodique côté serveur
- Interface responsive et mode PWA

## Prérequis

PHP 8.2 ou plus récent avec PDO SQLite et SQLite FTS5, ainsi que Composer.
La production nécessite HTTPS et une racine web configurée sur `public/`.

## Installation

```bash
composer install
php bin/console app:install administrateur
```

L’installation crée la base, applique les migrations, prépare le stockage et
crée le premier utilisateur. Le mot de passe est demandé dans le terminal.
Pour une installation automatisée, ajoutez `--password-stdin` à la commande
et fournissez le mot de passe sur l’entrée standard.

Configurez ensuite le serveur web pour servir le répertoire `public/`. Les
paramètres d’environnement, le stockage et la tâche cron sont décrits dans
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) et [`docs/PROJECT.md`](docs/PROJECT.md).

## Documentation

- [Fonctionnalités](docs/FEATURES.md)
- [Architecture et configuration](docs/ARCHITECTURE.md)
- [API JSON](docs/API.md)
- [Base de données](docs/DATABASE.md)
- [Frontend et accessibilité](docs/FRONTEND.md)
- [PWA](docs/PWA.md)
- [Flux RSS/Atom](docs/RSS.md)
- [Sécurité](docs/SECURITY.md)
- [Tests et qualité](docs/TESTING.md)
- [Spécification générale](docs/PROJECT.md)

Les règles de contribution destinées aux agents de développement figurent
dans [`AGENTS.md`](AGENTS.md).
