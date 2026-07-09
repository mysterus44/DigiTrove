# 🤝 CO-CODAGE DIGITROVE — Claude Code + Codex (guide express)

Tu codes DigiTrove en alternant **Claude Code** et **Codex** selon tes limites
d'utilisation. Les deux suivent la même progression, sans conflit, comme un seul
développeur.

---

## ⚙️ MISE EN PLACE (une seule fois)

```bash
cd digitrove
git init && git add -A && git commit -m "chore: système de contexte DigiTrove"
# Crée un repo PRIVÉ sur GitHub, puis :
git remote add origin <url-de-ton-repo>
git branch -M main && git push -u origin main
```

Le repo GitHub est le **point de synchronisation** entre les deux agents.

---

## 🔁 TON RYTHME DE TRAVAIL

### Avec Claude Code
```
1. Ouvre Claude Code dans le dossier
2. Tape :  /reprendre        → il lit HANDOFF.md et te dit où on en est
3. Tu valides son plan, il code UNE tâche
4. Tape :  /passation        → il sauvegarde tout (HANDOFF + tracker + commit)
5. Fais :  git push
```

### Quand Claude Code atteint sa limite → bascule sur Codex
```
1. Ouvre Codex dans le MÊME dossier
2. Fais :  git pull          → récupère le travail de Claude Code
3. Codex lit AGENTS.md automatiquement → dis-lui :
   "Lis HANDOFF.md et reprends la PROCHAINE TÂCHE"
4. À la fin : demande-lui de mettre à jour HANDOFF.md + PROGRESS_TRACKER.md + commit
5. Fais :  git push
```

Puis retour sur Claude Code, `git pull`, `/reprendre`. À l'infini, sans rien perdre.

---

## 🧠 POURQUOI ÇA MARCHE SANS CONFLIT

| Élément | Rôle |
|---------|------|
| `HANDOFF.md` | Carnet de passation : qui a fait quoi, prochaine tâche |
| `.context/memory/PROGRESS_TRACKER.md` | Avancement détaillé |
| `.context/CO_CODING_PROTOCOL.md` | Les 6 règles anti-conflit |
| `CLAUDE.md` ⇄ `AGENTS.md` | Mêmes règles pour les deux agents (miroirs) |
| **git** | Source de vérité unique du code |

Les agents ne travaillent **jamais en même temps**. Tu fais le pont.

---

## ✅ LA SEULE DISCIPLINE À TENIR

1. **Avant** de coder : `git pull`
2. **Après** avoir codé : mettre à jour HANDOFF.md, commit, `git push`

---

## 🎩 ET L'ASSISTANT CHEF DE PROJET ?

`ACP_CHEF_DE_PROJET.md` est à coller dans une **conversation Claude séparée** (pas
dans l'agent codeur). Il ne code pas : il te fabrique les prompts à donner à l'agent
codeur, te challenge, et t'aiguille phase par phase. C'est ton copilote stratégique.
