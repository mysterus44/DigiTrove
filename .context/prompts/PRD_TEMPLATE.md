# PRD_TEMPLATE.md — Comment briefer l'agent codeur
# Règle : ne JAMAIS demander une feature sans PRD.

---

## 💡 POURQUOI

❌ « Crée la gestion des produits » → l'agent devine, produit du code générique.
✅ Un PRD → l'agent produit du code aligné, sécurisé, testé.

**Économie de tokens** : le PRD ne réexplique pas le projet. Il **cite les fichiers**
du `.context/` à lire. C'est ça qui évite le gaspillage.

---

## 📋 TEMPLATE

```
Lis d'abord :
- PROJECT_CONTEXT.md
- SYSTEM_PROMPT.md
- .context/architecture/SCHEMA_BDD.md  (les tables concernées)
- .context/skills/[le skill pertinent].md
- .context/checklists/PRE_CODE_CHECKLIST.md

═══════════════════════════════════════════════════════

MISSION : [nom clair]

CONTEXTE : [pourquoi cette feature existe, quelle valeur]

OBJECTIF : permettre à [qui] de :
- [point 1]
- [point 2]

CRITÈRES D'ACCEPTATION :
- [ ] [condition mesurable]

EDGE CASES :
- [ ] Que se passe-t-il si [cas limite] ?

CONTRAINTES :
- Base de données : [tables à créer/utiliser — la BDD avant la logique]
- Sécurité : [validation, policy, rate limit, secrets…]
- UX : [mobile-first, nombre de clics…]

LIVRABLES :
- Migrations + modèles + enums
- Service (logique métier) + Form Request + Policy
- Contrôleur fin + vues
- Tests Pest (unitaire + feature + sécurité)
- Mise à jour PROGRESS_TRACKER.md et HANDOFF.md

PROCESSUS :
1. Annonce ton plan d'abord (migrations, fichiers). Ne code pas encore.
2. Attends ma validation.
3. Implémente : migration → modèle → service → contrôleur → vue → tests.
4. Vérifie : php artisan test && ./vendor/bin/pint
```

---

## ✅ CHECKLIST AVANT D'ENVOYER UN PRD

```
[ ] J'ai indiqué quels fichiers de contexte lire (pas de réexplication)
[ ] J'ai donné le POURQUOI, pas seulement le QUOI
[ ] Les critères d'acceptation sont mesurables
[ ] J'ai pensé aux edge cases
[ ] J'ai précisé les contraintes BDD et sécurité
[ ] J'ai demandé le plan AVANT le code
[ ] J'ai demandé la mise à jour de la mémoire (trackers)
```
