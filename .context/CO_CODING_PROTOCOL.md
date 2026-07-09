# CO_CODING_PROTOCOL.md — Claude Code ⇄ Codex sans conflit
# Deux agents, une seule progression. Comme un seul développeur qui change d'outil.

---

## 🧭 LE PRINCIPE

Claude Code et Codex partagent :
- le **même code** (le dépôt git),
- le **même contexte** (`.context/`, `PROJECT_CONTEXT.md`, `SYSTEM_PROMPT.md`),
- la **même progression** (`HANDOFF.md` + `.context/memory/PROGRESS_TRACKER.md`).

Ils ne travaillent **jamais en même temps**. Tu (KingKouda) utilises l'un, puis
l'autre quand le premier atteint sa limite. La continuité est garantie par le
carnet `HANDOFF.md` et par git.

---

## 🔁 LE RITUEL (à chaque session, quel que soit l'agent)

### 1. AU DÉMARRAGE — « Je prends le relais »
```
a. git pull               (récupérer le dernier état du binôme)
b. Lire HANDOFF.md        (où on s'est arrêté + PROCHAINE TÂCHE)
c. Lire PROGRESS_TRACKER  (détail de l'avancement)
d. Annoncer : "Je reprends sur [tâche]. Voici mon plan."
```

### 2. PENDANT — « Je fais UNE tâche »
```
- Traiter UNIQUEMENT la "PROCHAINE TÂCHE" du HANDOFF.
- Processus 8 étapes : la BDD avant la logique, plan avant code.
- Écrire/mettre à jour les tests Pest.
- Vérifier : php artisan test && ./vendor/bin/pint
```

### 3. À LA FIN — « Je passe le relais »
```
a. Mettre à jour PROGRESS_TRACKER.md (✅ ce qui est fait)
b. Mettre à jour HANDOFF.md :
   - entrée datée dans le JOURNAL DES PASSATIONS
   - réécrire le bloc "PROCHAINE TÂCHE"
c. git add -A && git commit -m "feat|fix: ... [par Claude Code|Codex]"
d. git push
```

> Si tu manques de temps ou de tokens en plein milieu : commit quand même l'état
> partiel, et écris dans HANDOFF.md exactement où tu en es et ce qu'il reste.

---

## 🛡️ LES 6 COMMANDEMENTS ANTI-CONFLIT

1. **Git est la vérité.** Toujours `git pull` avant, `git push` après.
2. **Une seule tâche à la fois**, celle du HANDOFF. Pas d'initiative parallèle.
3. **Toujours mettre à jour HANDOFF.md** avant de t'arrêter. Sans exception.
4. **Ne jamais refaire** ce qui est ✅ DONE, ni re-décider ce qui est dans DECISIONS_LOG.
5. **Commits petits et fréquents**, message clair + nom de l'agent.
6. **En cas de doute sur l'état**, relire le code réel (`git log`, `git diff`)
   plutôt que supposer.

---

## 🌿 GIT — mise en place (une seule fois)

```bash
git init
git add -A
git commit -m "chore: système de contexte DigiTrove"
git remote add origin <url-du-repo-privé>
git branch -M main
git push -u origin main
```

⚠️ Vérifie que `.env`, `storage/`, et toute base de données sont dans `.gitignore`
**avant** le premier commit. L'ancien projet avait committé sa base SQLite.

---

## ✅ CHECKLIST DE PASSATION

```
[ ] Code committé et poussé (git push)
[ ] Aucun secret dans le commit (.env, clés, base de données)
[ ] PROGRESS_TRACKER.md à jour
[ ] HANDOFF.md : entrée journal + PROCHAINE TÂCHE réécrite
[ ] php artisan test / pint verts (ou état noté si partiel)
[ ] Décisions importantes ajoutées à DECISIONS_LOG.md
```

Si cette checklist est faite, l'autre agent reprend en 30 secondes, sans rien perdre.
