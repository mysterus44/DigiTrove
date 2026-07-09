# PRE_CODE_CHECKLIST.md — À vérifier AVANT de coder
# Obligatoire avant chaque feature. Aucune exception.

---

## 🧠 PHASE 1 — COMPRÉHENSION (2 min)
```
[ ] J'ai lu HANDOFF.md → je sais où le binôme s'est arrêté
[ ] J'ai lu DECISIONS_LOG.md → je ne re-décide pas ce qui est décidé
[ ] J'ai compris POURQUOI cette feature existe (valeur pour qui ?)
[ ] J'ai identifié les cas limites
```

## 🗄️ PHASE 2 — BASE DE DONNÉES (règle DigiTrove : la BDD avant la logique)
```
[ ] Les tables concernées existent-elles déjà dans SCHEMA_BDD.md ?
[ ] Si non : j'ai proposé la structure AVANT de coder (et je l'ai fait valider)
[ ] Argent en BIGINT (unités mineures) ? Aucun FLOAT ?
[ ] Les valeurs historiques sont-elles snapshotées là où l'histoire compte ?
[ ] Index pensés pour les requêtes réelles (pas d'index décoratif) ?
[ ] Contraintes CHECK sur les énumérations ?
[ ] Si c'est de l'analytique : pas de clé étrangère vers les tables chaudes ?
```

## 🏗️ PHASE 3 — ARCHITECTURE (5 min)
```
[ ] La logique métier va dans un Service (pas dans le contrôleur)
[ ] La validation va dans un Form Request
[ ] L'autorisation va dans une Policy (jamais un if sur le rôle)
[ ] Les effets de bord partent en Event/Listener/Job (idempotents)
[ ] Je vérifie qu'une fonction similaire n'existe pas déjà (DRY)
[ ] Écritures multiples → DB::transaction()
[ ] Performance : pas de N+1, pagination prévue
```

## 🔐 PHASE 4 — SÉCURITÉ (le réflexe DigiTrove)
```
[ ] Aucun secret dans le code (.env uniquement)
[ ] Requêtes préparées (jamais de concaténation SQL)
[ ] Si fichiers : disque privé, jamais public/, jamais d'URL directe
[ ] Si token : stocké HACHÉ, expiration + quota + révocation vérifiés
[ ] Si paiement : signature + getStatus + montant en entiers + idempotence
[ ] Si données perso : IP hachée, minimum nécessaire (RGPD)
[ ] Rate limiting sur les routes sensibles (login, checkout, download)
[ ] Échec = message générique (pas de fuite d'information)
```

## 💻 PHASE 5 — PENDANT LE CODE
```
[ ] Types de retour explicites partout (pas de mixed paresseux)
[ ] Enums PHP au lieu de chaînes magiques
[ ] final sur les classes de service, readonly sur les dépendances
[ ] Pas de dd() / dump() / var_dump oubliés
[ ] Pas d'erreur silencieuse (jamais de try/catch vide)
```

## ✅ PHASE 6 — AVANT DE DIRE « TERMINÉ »
```
[ ] Tests Pest écrits ET passants
[ ] php artisan test → vert
[ ] ./vendor/bin/pint → vert
[ ] Test manuel sur mobile (375px)
[ ] Aucune régression sur l'existant
[ ] PROGRESS_TRACKER.md mis à jour
[ ] HANDOFF.md mis à jour (journal + PROCHAINE TÂCHE)
```

## 🚀 PHASE 7 — AVANT COMMIT
```
[ ] Message clair : feat|fix|chore: description [par Claude Code|Codex]
[ ] AUCUN secret dans le commit (.env, clés, base de données)
[ ] git status vérifié ligne par ligne
[ ] Décision importante ? → DECISIONS_LOG.md
```

---

## 📋 TEMPLATE DE FEATURE

```
FEATURE : [nom]
VALEUR : en tant que [qui], je veux [quoi] pour [pourquoi]
TABLES CONCERNÉES : [existantes ? à créer ?]
CRITÈRES D'ACCEPTATION :
  - [ ] …
EDGE CASES :
  - [ ] Que se passe-t-il si … ?
SÉCURITÉ :
  - [ ] Auth ? Policy ? Validation ? Rate limit ?
TESTS :
  - [ ] Unitaire : …
  - [ ] Feature : …
  - [ ] Sécurité (accès refusé) : …
```
