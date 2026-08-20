# GeniusPay — mise en place

Adaptateur de paiement GeniusPay pour DigiTrove. **Désactivé par défaut**, comme
CinetPay : `PAYMENT_DRIVER` vide, et un adaptateur sélectionné mais incomplet
refuse dans son propre constructeur, **avant toute requête HTTP**.

Ce document ne décrit **que** ce que la documentation publique de GeniusPay
(`pay.genius.ci/docs/api`) énonce. Tout ce qu'elle ne dit pas est signalé comme
tel et **refusé plutôt que deviné** — même discipline que
[`POWERPAY_SETUP.md`](POWERPAY_SETUP.md).

---

## 1. Discipline sandbox-first — non négociable

Les identifiants portent leur environnement dans leur propre préfixe :

| rôle | sandbox | production |
|---|---|---|
| clé publique | `pk_sandbox_…` | `pk_live_…` |
| secret d'API | `sk_sandbox_…` | `sk_live_…` |
| secret de webhook | `whsec_sandbox_…` | `whsec_live_…` |

**L'adaptateur n'inspecte jamais ce préfixe et ne choisit jamais un
environnement.** Basculer vers `live` est une décision humaine prise dans `.env`,
et nulle part ailleurs — après que le sandbox a été prouvé de bout en bout, et
seulement sur confirmation explicite de KingKouda.

Aucun secret n'est jamais committé. `.env.example` ne contient que des
emplacements vides.

---

## 2. Variables d'environnement

```dotenv
PAYMENT_DRIVER=geniuspay

GENIUSPAY_ENVIRONMENT=sandbox  # sandbox | live — controle croise, pas un selecteur
GENIUSPAY_API_KEY=            # X-API-Key      (pk_sandbox_…)
GENIUSPAY_API_SECRET=         # X-API-Secret   (sk_sandbox_…)
GENIUSPAY_WEBHOOK_SECRET=     # HMAC webhook   (whsec_sandbox_…)
GENIUSPAY_BASE_URL=https://pay.genius.ci/api/v1/merchant
GENIUSPAY_CONNECT_TIMEOUT=5
GENIUSPAY_TIMEOUT=15
GENIUSPAY_WEBHOOK_TOLERANCE=300
```

Les cinq premières sont obligatoires. Une seule manquante ⇒
`ProviderConfigurationFailure`, **aucun appel réseau**. HTTPS est exigé partout
sauf en `local`/`testing`, et la vérification TLS n'est jamais désactivée.

---

## 3. Endpoints

| opération | méthode | chemin |
|---|---|---|
| initiation | `POST` | `{base_url}/payments` |
| vérification serveur | `GET` | `{base_url}/payments/{reference}` |

Authentification par deux en-têtes sur chaque requête : `X-API-Key` et
`X-API-Secret`. Les secrets sont lus depuis la configuration uniquement et
n'apparaissent jamais dans une exception ni dans un log.

### Montants et devise

Les montants sont des **entiers en unités mineures** — jamais un flottant, jamais
une chaîne à séparateur. `parseIntegerAmount()` refuse `150.00`, `1.5e3`,
`15 000`, `15,000`.

`currency` est **toujours envoyé explicitement**. Le défaut du fournisseur n'est
jamais utilisé.

> ⚠️ **Seul `XOF` est accepté.** GeniusPay convertit automatiquement les devises
> non-XOF. Le montant capturé chez le fournisseur différerait alors de
> `orders.total_minor`, et **chaque confirmation partirait en revue manuelle**.
> L'adaptateur refuse donc toute autre devise **avant l'appel réseau** — une
> ambiguïté qu'on évite plutôt qu'on gère. Le jour où une seconde devise est
> réellement vendue, `GeniusPayProvider::SUPPORTED_CURRENCY` est le seul endroit
> à rouvrir, délibérément.

### La `reference` est le seul identifiant

L'initiation retourne `id`, `reference` (`MTX-XXXXXXXXXX`) et
`checkout_url`/`payment_url`. **La documentation n'expose aucun champ permettant
au marchand de fournir son propre identifiant**, et l'endpoint de vérification
n'accepte que la `reference`.

Conséquence directe : c'est la `reference` — persistée dans
`payments.provider_payment_reference` à l'initiation — qui identifie la tentative
chez ce fournisseur, **et non `payments.public_id`** comme chez CinetPay.

La `reference` est interpolée dans un chemin d'URL, donc son **jeu de caractères**
est contraint (`^[A-Za-z0-9_-]{1,64}$`) : ni `/`, ni séquence `..`, ni espace, ni
caractère de contrôle ne peuvent réécrire le chemin de la requête.

---

## 4. Webhooks signés

```
X-Webhook-Signature = HMAC-SHA256( X-Webhook-Timestamp . "." . CORPS JSON BRUT , whsec_… )
```

Trois règles, toutes appliquées :

1. **Les octets bruts, jamais un JSON redécodé.** `json_decode` puis
   `json_encode` réordonne les clés, supprime les espaces insignifiants et
   réécrit les échappements : le condensé différerait de ce que le fournisseur a
   signé. Le corps exact est lu via `$request->getContent()`.
2. **`hash_equals`**, jamais `===` — comparaison en temps constant.
3. **Fenêtre de rejeu** de `GENIUSPAY_WEBHOOK_TOLERANCE` secondes (300 par
   défaut), **symétrique** : une horloge fournisseur légèrement en avance est une
   dérive NTP ordinaire, pas une attaque. Sans cette fenêtre, une notification
   légitime capturée resterait rejouable indéfiniment.

Le corps du webhook **n'est jamais autoritatif**, même signé : une signature
valide n'autorise que le **contre-appel serveur**, dont le résultat normalisé est
la seule vérité externe (D-034, inchangé).

**Déduplication** par l'`id` (UUID) de l'événement, via l'index unique existant
`payment_webhook_events (provider, external_event_id)`. **Aucune migration.**

---

## 5. Mapping des statuts

| GeniusPay | `NormalizedPaymentStatus` | raison |
|---|---|---|
| `pending` | `Pending` | |
| `processing` | `Processing` | |
| `completed` | `Succeeded` | seul statut qui confirme de l'argent |
| `failed` | `Failed` | |
| `cancelled` | `Cancelled` | |
| `expired` | `Cancelled` | terminal et non payé — `Unknown` laisserait le paiement en attente indéfiniment |
| `refunded` | `Unknown` ⚠️ | voir ci-dessous |

`refunded` ne peut pas être `Succeeded` — ce serait confirmer de l'argent rendu —
ni `Failed` — l'argent est bien passé avant d'être rendu. `Unknown` est
fail-closed, et c'est précisément pourquoi **un remboursement ne transite jamais
par `verifyPayment()`** : il a son propre chemin d'entrée.

Côté client, aucun texte ne distingue « annulé » et « expiré » : la page d'état de
commande affiche « Cette commande n'est plus valide. » pour les deux. Vérifié,
pas supposé.

---

## 6. ⚠️ Limite fournisseur — remboursements partiels

**GeniusPay ne documente aucun champ de montant remboursé**, ni sur le webhook
`payment.refunded`, ni sur `GET /payments/{reference}`. Ce n'est pas une lacune de
lecture : c'est une lacune réelle de leur documentation publique.

**Décision (arbitrée) : tout `payment.refunded` est traité comme un remboursement
total**, d'un montant égal au montant capturé du paiement. Aucun montant partiel
n'est inféré, puisqu'aucun champ documenté ne l'expose.

Si GeniusPay supporte des remboursements partiels sans les exposer dans son API,
le cas est **indétectable depuis DigiTrove quel que soit l'effort d'ingénierie**.
Ce n'est donc pas un défaut de l'adaptateur mais une limite du fournisseur.

> **Action hors code, en cours** : demander au support GeniusPay une confirmation
> **écrite** de leur politique de remboursement partiel, pour lever ou confirmer
> cette hypothèse avant qu'un remboursement partiel réel ne teste la limite en
> production. Suivi comme dette nommée dans `HANDOFF.md`.

Aucun endpoint de remboursement n'existe côté GeniusPay : DigiTrove ne peut pas
déclencher un remboursement, seulement en constater un.

---

## 7. Ce que la documentation ne dit pas

Signalé plutôt que deviné :

- **Les champs additionnels de la requête d'initiation** (description, e-mail
  client, URL de retour, URL de notification) ne sont pas documentés
  publiquement. L'adaptateur n'envoie donc que `amount` et `currency`. L'URL de
  notification est à configurer **dans le dashboard marchand**.
- **La forme exacte du corps du webhook** n'est pas publiée champ par champ.
  L'adaptateur lit de façon défensive les deux formes possibles (objet imbriqué
  sous `data`, ou plat) et **refuse fermé** si aucune ne fournit les champs
  attendus. Aucun nom de champ n'est inventé : ceux qui sont lus proviennent des
  statuts et identifiants que la documentation nomme.
- **Aucune clé d'idempotence ni référence marchande à l'initiation.** GeniusPay génère la
  `reference` et n'accepte, d'après sa documentation, aucun identifiant fourni par le
  marchand. Le contrat `PaymentProvider` de DigiTrove exige pourtant qu'`initiate()` soit
  idempotent sur `paymentPublicId`. **Conséquence** : lorsqu'un client rejoue une initiation
  dont il n'a jamais reçu la réponse, le rappel crée une **seconde** transaction chez
  GeniusPay, et la finalisation refuse alors la référence divergente
  (`ProviderReferenceConflict`). Rien n'est corrompu côté DigiTrove — la référence stockée
  n'est jamais écrasée — mais la reprise échoue et une transaction orpheline reste ouverte
  côté fournisseur. **À poser au support** en même temps que la question du §6. Une réponse
  positive ne coûte qu'un champ de plus dans la requête d'initiation.
- **Le format de `X-Webhook-Timestamp`** n'est pas précisé. Un entier Unix en
  secondes est accepté, une date ISO-8601 en repli ; tout le reste est **refusé**,
  jamais coercé. Le timestamp est signé **verbatim**, donc son format n'affecte
  pas le calcul du HMAC — seulement la fenêtre de fraîcheur.

Chacun de ces points se referme par une observation en sandbox, pas par une
supposition. Le premier appel réel qui les tranche doit être documenté ici.

---

## 8. Tests

Aucun appel réseau réel. `Http::fake()` + `Http::preventStrayRequests()` : une
requête sortante non simulée fait échouer le test au lieu de partir en silence.
Les identifiants utilisés en test sont des valeurs de test, jamais des clés
sandbox réelles.
