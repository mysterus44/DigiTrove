# PRD_01_IDENTITE.md — P1 : Identité & CRM (fondation)

```
Lis d'abord :
- .context/architecture/SCHEMA_BDD.md  (bloc « IDENTITÉ & CRM »)
- .context/skills/LARAVEL_PATTERNS.md
- .context/skills/CRM_ANALYTICS.md     (section stitching visitor → user)
- .context/checklists/PRE_CODE_CHECKLIST.md

═══════════════════════════════════════════════════════

MISSION : Couche identité — users, customer_profiles, visitors

CONTEXTE : c'est la fondation de tout. `users` sert l'authentification (table chaude,
maigre). `customer_profiles` sert le CRM (gras, avec des rollups dénormalisés).
`visitors` capture l'identité anonyme AVANT l'inscription — sans elle, aucune
attribution marketing ne sera jamais possible, même rétroactivement.

OBJECTIF :
- Créer les 3 tables exactement comme spécifié dans SCHEMA_BDD.md
- Modèles Eloquent + relations + casts (enums PHP)
- Middleware qui pose un cookie `visitor_id` (UUID, 1re partie) sur chaque visite
- Stitching : au login/inscription, `visitors.user_id` est renseigné et les
  `first_touch_*` sont recopiés dans `customer_profiles`
- Seeder admin qui lit ADMIN_EMAIL et ADMIN_PASSWORD depuis .env

CRITÈRES D'ACCEPTATION :
- [ ] `php artisan migrate` passe sur PostgreSQL (extension citext activée)
- [ ] Mot de passe haché en Argon2id (vérifié par un test)
- [ ] Relation users 1:1 customer_profiles ; visitors N:1 users
- [ ] Enums : UserRole, UserStatus, LifecycleStage
- [ ] Le cookie visitor_id est posé pour un visiteur anonyme
- [ ] Au login, le visitor existant est rattaché au user (stitching)
- [ ] Le seeder admin ne contient AUCUN mot de passe en dur
- [ ] Un DTO/Resource public n'expose jamais password_hash

EDGE CASES :
- [ ] Visiteur avec cookie effacé → nouveau visitor_id, pas de crash
- [ ] Même utilisateur, deux appareils → deux visitors rattachés au même user
- [ ] Inscription sans passer par une visite trackée → visitor créé à la volée

CONTRAINTES :
- Sécurité : Argon2id, rate limiting sur login (5/min), validation Zod-like (Form Request)
- Le middleware de tracking ne doit PAS ralentir la requête (cookie seul, pas de write bloquant)
- Multi-appareils supporté

LIVRABLES :
- Migrations : users, customer_profiles, visitors, customer_segments(+members)
- Modèles + relations + casts + enums
- app/Http/Middleware/TrackVisitor.php
- Listener sur Login/Registered → stitching
- database/seeders/AdminSeeder.php (lit .env)
- Tests Pest : hash Argon2id · relation profil · stitching · cookie posé ·
  password_hash jamais exposé
- PROGRESS_TRACKER.md + HANDOFF.md mis à jour (PROCHAINE TÂCHE = PRD_02_CATALOGUE)

PROCESSUS : annonce ton plan (ordre des migrations, contenu du middleware), attends
validation, puis implémente. Aucune logique métier au-delà du périmètre.
```
