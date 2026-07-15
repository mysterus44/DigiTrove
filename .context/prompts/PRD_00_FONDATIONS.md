# PRD_00_FONDATIONS.md — P0 : Fondations
# Premier prompt à donner à l'agent codeur.

```
Lis d'abord :
- PROJECT_CONTEXT.md
- SYSTEM_PROMPT.md
- .context/memory/DECISIONS_LOG.md
- .context/skills/LARAVEL_PATTERNS.md

═══════════════════════════════════════════════════════

MISSION : Fondations du projet DigiTrove (P0)

CONTEXTE : projet vierge. L'ancien DigiTrove (PHP procédural, JSON + SQLite) est
abandonné (voir .context/context/AUDIT_LEGACY.md). On repart proprement.

OBJECTIF :
- Laravel 13.19 (PHP 8.3+), TypeScript non requis, Blade + Tailwind + Alpine
  (remplace Laravel 11 suite aux advisories Composer/Packagist, voir D-012)
- PostgreSQL 16 branché (JAMAIS MySQL)
- docker-compose.yml : PostgreSQL 16 + Redis 7
- .env.example complet ; .env dans .gitignore ; storage et bases ignorés aussi
- Argon2id activé (config/hashing.php)
- Filament 5 installé (panneau admin vide pour l'instant, voir D-012)
- Pest + Pint configurés
- Disque de stockage `private` déclaré (config/filesystems.php), hors public/
- CI GitHub Actions : type-check, pint, tests

CRITÈRES D'ACCEPTATION :
- [ ] `php artisan --version` OK, `php artisan serve` démarre
- [ ] `docker-compose up -d` lance Postgres + Redis
- [ ] `php artisan test` vert (même sans test métier)
- [ ] `./vendor/bin/pint --test` vert
- [ ] `git status` : aucun secret, aucun .env, aucune base de données
- [ ] Le disque `private` existe et n'est PAS servi publiquement

CONTRAINTES :
- Sécurité : AUCUN secret dans le code. Le .gitignore est écrit AVANT le 1er commit.
- Pas de table créée à ce stade (les migrations viennent en P1).
- Pas de logique métier.

LIVRABLES :
- Projet Laravel 13.19 fonctionnel
- docker-compose.yml
- .env.example (copié depuis celui fourni à la racine du système de contexte)
- config/hashing.php (argon2id), config/filesystems.php (disque private)
- .github/workflows/ci.yml
- README projet avec les commandes
- HANDOFF.md mis à jour (PROCHAINE TÂCHE = PRD_01_IDENTITE)

PROCESSUS : annonce ton plan d'abord (liste des fichiers), attends validation,
puis exécute. Vérifie chaque critère d'acceptation avant de dire « terminé ».
```
