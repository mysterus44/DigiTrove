# HANDOFF.md — Carnet de Passation (Claude Code ⇄ Codex)
# 🔴 LE FICHIER LE PLUS IMPORTANT DU CO-CODAGE.
# Quel que soit l'agent, il LIT ceci en premier et le MET À JOUR en dernier.

---

## 📍 ÉTAT ACTUEL

- **Dernier agent** : Codex
- **Date** : 2026-08-05
- **Branche git active** : `p0-foundations-laravel13` (stable). P6-A1.0 a été mergé (PR #32 sur `77652f2`).
- **P6-A1.1 IMPLÉMENTÉ ET VALIDÉ LOCALEMENT**
  **EN ATTENTE DE REVUE/MERGE**
  **P6-A1.2 NON COMMENCÉ**
  P6-A1.1 (Currency-safe Commerce Rollup Authority) ajoute la table `crm_contact_commerce_rollups` (migration 000022), la fonction SECURITY DEFINER `refresh_contact_commerce_rollups_v1` et les tests de contrat et de concurrence PostgreSQL. Suite de tests verte (921 tests, 6424 assertions).

- **P5-A2 AUTHORITATIVE ROLLUPS AND PARTITION OPERATIONS TERMINÉ, MERGÉ ET
  VALIDÉ** via [PR #28](https://github.com/mysterus44/DigiTrove/pull/28), head
  `03063db8acf0b974ab9369f72d188f8cb52df71b`, merge
  `17aaa4f43fcac0d3ef5e039897f0d30666b9d29d`, CI #35 success (D-039).
  La migration unique `000018` sépare
  l'engagement sans devise (`daily_product_engagement_stats`) du commerce
  produit currency-safe, ajoute `order_items.purchased_product_id` immuable sans
  FK, et installe les autorités de rollup/partition. Worker LOGIN EXECUTE-only,
  executor NOLOGIN, trois fonctions SECURITY DEFINER, commandes rollup/ensure/
  audit et scheduler conditionnel. La partition DEFAULT n'est jamais déplacée
  automatiquement. Validation post-merge : **34 migrations**, P5-A2 **25 tests /
  198 assertions**, suite complète **741 / 5381**, Pint **278 fichiers**,
  `git diff --check` propre; rollback isolé et concurrence PostgreSQL verts.
- **P5-A3A/B ADMIN ANALYTICS READ BOUNDARY, OVERVIEW AND SALES TERMINÉ, MERGÉ
  ET VALIDÉ** via PR #29, head `31f986dc84c05f15fb5f2e2f1f3db8ea496d01be`,
  merge `2bbf2b5260bb97c7981cf84a13c84910062a21cc`, CI #36 success (D-040).
  Seul un admin actif et non supprimé accède
  au panel `admin` et à la Gate `viewGlobalAnalytics`; `staff`, `customer` et
  les comptes inactifs sont refusés. La migration `000019` accorde au rôle
  `digitrove_analytics_reader` uniquement `SELECT` sur les quatre rollups. Les
  queries globales Vue d'ensemble/Ventes utilisent une transaction read-only,
  des DTO immuables et un cache court séparé par devise. Aucune lecture de
  données brutes ou Commerce, aucun worker web, aucune API/export/opération UI.
  Validation : **35 migrations**, P5-A3 **32 tests / 193 assertions**, suite
  complète **773 / 5575**, Pint **302 fichiers**, rollback ACL isolé vert.
  P5-A3A/B est clos sur la stable par le commit documentaire `c6790e6`.
- **P5-A3C PRODUCT AND FUNNEL ANALYTICS VIEWS TERMINÉ, MERGÉ ET VALIDÉ** via
  [PR #30](https://github.com/mysterus44/DigiTrove/pull/30), head
  `642f8e359348ca6d65c0dad1e14418d1400a8ff2`, merge
  `87bf83999712360fdacab4537ebc96d81506a543`, CI #37 success (D-041).
  `AnalyticsProductQuery` réunit engagement global sans
  devise et commerce dans la devise choisie sans lire le catalogue; l'UI affiche
  `Produit #<id>` et `add_to_carts` « Non suivi ». `AnalyticsFunnelQuery`
  restitue les volumes calendaires et ratios agrégés non cohortés, sans plafond,
  avec division par zéro à `NULL`, trous non calculés et jour UTC courant
  provisoire. Les caches stockent des tableaux scalaires réhydratés en DTO afin
  de fonctionner avec Redis sans désérialisation d'objets. Aucune migration ni
  ACL : **35 migrations**, aucune `000020`. Validation post-merge : P5-A3C
  **22/178**, P5-A3 agrégé **54/371**, P5-A2 **25/198**, P5-A1 **74/400**,
  P5-A0 **19/256**, suite **795/5753**, Pint **318**, PostgreSQL/Redis réels et
  `git diff --check` propre.
- **P5 ANALYTIQUE TERMINÉ, MERGÉ ET VALIDÉ** (D-042). P5-A3D est reporté au
  durcissement préproduction et ne bloque pas P6; les opérations restent
  CLI/scheduler, désactivées par défaut, sans commande Filament.
- **P6-A0 CRM IDENTITY, CONSENT AND AUTHORIZATION FOUNDATION TERMINÉ, MERGÉ ET
  VALIDÉ** via [PR #31](https://github.com/mysterus44/DigiTrove/pull/31), head
  `3276fef12d94f25e91fe6386e153ae3130424eb1`, merge
  `47888d0992aa5664e82341e52f6c3a68c4b0b15a` (D-043). La migration unique
  `000020` crée `crm_contacts` et `crm_marketing_consent_events`; l'identité
  repose uniquement sur l'e-mail exact normalisé, sans Visitor ni fusion
  approximative. `resolve_crm_contact` et le trigger de liaison exigent tous
  deux un User `active`, non supprimé, vérifié et de même e-mail; `suspended` et
  `blocked` sont refusés. Le ledger promotionnel e-mail est append-only, servi
  par trois fonctions SECURITY DEFINER détenues par `digitrove_crm_executor`
  NOLOGIN; le runtime est EXECUTE-only. La Gate
  `manageCustomerRelationships` reste admin actif et non supprimé uniquement.
  Validation post-merge : **36 migrations**, P6-A0 **40/235**, suite complète
  **835/5988**, Pint **347**, rollback/concurrence/diff-check verts. Aucun flux
  utilisateur, route, UI, job, mail, campagne, segment, rollup, backfill ou
  rétention automatique n'est livré.
- **P6-A1.0 DURABLE ORDER-TO-CRM ATTRIBUTION PIPELINE IMPLÉMENTÉ — EN ATTENTE
  DE REVUE/MERGE** (D-045). La migration unique `000021` crée l'outbox durable
  `crm_order_attribution_outbox`, sans e-mail ni Visitor, et le fait immuable
  `crm_order_attributions`. La transition financière capture uniquement un
  `contact_id` actif déjà prouvé; elle ne résout ni ne crée jamais de contact.
  Après commit, `OrderPaid` reste un signal faible, complété par un sweeper
  borné et un job unique à TTL 3600 secondes qui appellent deux autorités SECURITY DEFINER
  EXECUTE-only. Le trigger suit la transition du prédicat complet `status acquis
  + paid_at non NULL` dans les deux ordres. Les nouvelles créations bornent
  l'e-mail à 254 caractères, mais un replay exact historique reste autorisé
  jusqu'à 320; les
  anciens Orders incompatibles deviennent `unattributable` sans rollback
  financier. Validation : **37 migrations**, P6-A1.0 **48/285**, suite complète
  **883/6273**, Pint **367**, concurrence/rollback/diff-check verts. Aucune
  `000022`, aucun rollup, backfill, UI, segment ou campagne.
- **P5-A1 FIRST-PARTY ANALYTICS INGESTION TERMINÉ, MERGÉ ET VALIDÉ** via
  [PR #27](https://github.com/mysterus44/DigiTrove/pull/27), head
  `955cc34050daa4b8706e752fd9a82f579bebb02b`, merge
  `c699c5b97d987ed7d5e23c99edebf66ba2053f99`, CI #33 success (D-038).
  Migration `000017`, rôle NOLOGIN
  `digitrove_analytics_executor`, fonction SECURITY DEFINER
  `ingest_first_party_analytics_event`, runtime EXECUTE-only, consentement
  versionné et ingestion désactivée par défaut. Les cookies
  `dt_analytics_consent`, `dt_analytics_visitor` et `dt_analytics_session` sont
  first-party, chiffrés/signés, HttpOnly et SameSite Strict. Seuls `page_view`
  et `product_view` sont publics; aucun événement financier, fournisseur tiers,
  IP/user-agent brut, queue ou rollup. La sessionisation est atomique sous
  advisory lock visiteur et verrou de ligne; les tests à connexions
  PostgreSQL indépendantes prouvent l'absence de perte de compteur et de double
  première session. L'autorité PostgreSQL isole désormais les sessions par
  contexte d'authentification : une session anonyme peut être enrichie au
  login, mais une session identifiée n'est réutilisée ni après logout ni sous
  un autre compte. Validation finale : **33 migrations**, P5-A1 **74 tests /
  400 assertions**, suite complète **716 / 5183**, Pint **254 fichiers**,
  `git diff --check` propre.
- **P5-A0 ANALYTICS SCHEMA FOUNDATION TERMINÉ, MERGÉ ET VALIDÉ** via
  [PR #26](https://github.com/mysterus44/DigiTrove/pull/26), head
  `8d9d8cc798e6a35ae74a36d1d9ae6a9d22bf171a`, merge `94a8c08c`, CI #32
  success (D-037). Trois migrations additives : `000014` crée le parent
  `events` partitionné RANGE, `events_default`, l'append-only et ses ACL ;
  `000015` crée `analytics_sessions` sans FK ; `000016` crée trois rollups
  journaliers currency-safe. Cinq modèles/factories structurels, aucune
  ingestion, route, API, session applicative, campagne, segmentation ou P6/P7.
  `PUBLIC` et `digitrove_runtime` n'ont aucun droit sur les objets analytiques.
  Validation PostgreSQL réelle : **32 migrations**, P5-A0 **19 tests / 256
  assertions**, suite complète **642 / 4779**, Pint **235 fichiers**,
  `git diff --check` propre, rollbacks isolés verts, aucune base temporaire.
  Commits fonctionnels : `6c17a78` (events) et `bf51c87` (sessions/rollups).
- **P4-C4 → P4-C6 TERMINÉS, MERGÉS ET VALIDÉS** via
  [PR #25](https://github.com/mysterus44/DigiTrove/pull/25), head
  `07d566fb4016a805fc007f4210bf122ac2fd9bed`, merge `109fde4c`, **CI #31
  success** (D-036, **aucune migration** — 29 inchangées). Surface :
  `GET /downloads/{grantPublicId}` (page d'échange DB-free, fragment retiré),
  `POST /api/downloads/{grantPublicId}/authorize` (Bearer grant, refus uniforme,
  G5 atomique, cookie de tentative `HttpOnly/SameSite=Strict`) et
  `GET|HEAD /downloads/{grantPublicId}/file` (attempt cookie seulement).
  Le hardening `5bd86d3` impose : préflight fichier hors transaction puis
  transaction G5 courte ; livraison en transaction DB courte → I/O stockage
  hors transaction → finalisation DB courte ; stream et X-Accel préparés au
  niveau zéro ; garde runtime du locator et refus des transactions ambiantes.
  Une erreur stockage est finalisée séparément en `denied/storage_failure`,
  sans rendre le quota ; tout stream rejeté par la revalidation finale est
  fermé. `DELIVERY_PIPELINE_ENABLED=false` coupe aussi les tentatives existantes
  au niveau service. Les secrets bruts sont `SensitiveParameter`. Validation :
  filtre P4-C4 **18/152** (autorisation + contrat exacts **12/109**), P4-C5
  **13/202**, P4-C6 **5/41**, P4C456 **10/72**, suite complète **623/4542**,
  Pint **217**, `git diff --check` propre, 29 migrations, aucune `000014`.
  Maximum observé : `exists/size/readStream/xAccelPath/callback = 0`.
  Pipeline toujours **désactivé par défaut**.
- **P4-C0 → P4-C3 TERMINÉS, MERGÉS ET VALIDÉS** via
  [PR #24](https://github.com/mysterus44/DigiTrove/pull/24), head
  `1492cd137a904c6025504fc5fd0cf0d51bd92db9`, merge
  `701cfa4f95700b61d70f242e15feef264adffa8b`, **CI #29 success**
  (macro-gate unique, **D-035**, **aucune migration** — 29 inchangées) :
  pipeline de livraison
  sécurisé **désactivé par défaut**. Listener `QueueSecureDelivery` (dispatch si
  `DELIVERY_PIPELINE_ENABLED=true`) → job `SecureDeliveryJob` **unique, `order_id`
  seul** → `GrantIssuanceService` (tokens CSPRNG en mémoire, SHA-256 en base,
  snapshot bundle unique autorité, **no implicit upgrade**, révoque/réémet au
  retry) → Mailable `OrderDownloadsReady` **synchrone, jamais `ShouldQueue`,
  jamais sérialisable**.
  `RefundCompletionService` : partial ⇒ `partially_refunded` (grants gardés),
  full ⇒ `refunded` + révocation de tous les grants actifs **dans la même
  transaction** (G4). CinetPay/refund/e-mail : adaptateurs réels non inventés
  (scaffolds `MAIL_PROVIDER_SETUP.md` / `REFUND_PROVIDER_SETUP.md`). Validation
  historique : P4-C **50/165**, suite **587/4259**, Pint **191**, 29 migrations.
- **P3-D4 + P3-D5 TERMINÉS, MERGÉS ET VALIDÉS** via
  [PR #23](https://github.com/mysterus44/DigiTrove/pull/23), head `8aad4fc`,
  merge `a62563fdb8aad86bef5cf1ac27b4bebcb5259342` (parents `0b9e7ac` +
  `8aad4fc`), **CI #27 success** (macro-gate unique,
  **D-034**, **aucune migration** — 29 inchangées) : webhook CinetPay signé
  (HMAC `x-token`) + dédupliqué, **contre-appel fournisseur obligatoire** (le
  corps du webhook n'est jamais autoritatif), confirmation atomique
  `Payment pending→processing→succeeded` + `Order → paid` en une transaction,
  argent comparé en entiers, **succès incohérent ⇒ `payment_review`** (jamais
  faux `paid` ni `failed`), coupon consommé exactement une fois au `paid` (jamais
  au checkout), branche gratuite `total_minor = 0` sans Payment
  (`FreeOrderConfirmationService`), **`OrderPaid` (`order_id` seul) après
  COMMIT**. **CinetPay désactivé par défaut** (`PAYMENT_DRIVER` vide, résolu
  fail-closed) ; **PowerPay** = scaffold `docs/integrations/POWERPAY_SETUP.md`
  sans endpoint inventé. Validation : 59 tests P3-D4/D5 (adapter 23, binding 5,
  confirmation 16 dont C3/C4 réels, webhook HTTP 8 dont C1/C2, événement 7 dont
  C5), suite complète **537/4083**, Pint **175**, **29 migrations**, aucune
  `000014`. Fenêtre résiduelle COMMIT→dispatch assumée (pas d'outbox). **Aucune
  livraison P4-C, aucun listener.** Prochaine macro-tâche après merge :
  **P4-C0 + P4-C1 + P4-C2**.
- **P3-D3 TERMINÉ, MERGÉ ET VALIDÉ** via
  [PR #22](https://github.com/mysterus44/DigiTrove/pull/22), head
  `5188e6cc5c82358cdc1e72efe3e8e3345babf930`, merge
  `70379a02e1f220e6dac4f53552b8a5712c6e4815` (parents `6e701a1e` + `5188e6cc`),
  **CI #26 success** : initiation de paiement en deux phases, port fournisseur
  abstrait, **aucune migration**, aucun adaptateur réel. **Durcissement
  pré-merge (5 findings) fermés** : garde `transactionLevel = 0`, horloge
  injectée unique, reprise de réponse perdue, toute exception BDD inconnue ⇒
  `IntegrityFailure` sanitizé, preuves C1–C4 **service-level**. Périmètre exact
  **14 fichiers (+2005/-7)**. Validation post-merge sur la stable `70379a0` :
  P3-D3 **54/246**, P3-D2.1 **20/20**, P3-D2 **71/344**, P3C-A **16/219**, P4-B
  **20/628**, suite complète **478/3894**, Pint **149**, **29 migrations**,
  aucune `000014`. **P3-D4** est le **prochain gate autorisé, NON commencé**.
- **P3-D2.1 TERMINÉ, MERGÉ ET VALIDÉ** via
  [PR #21](https://github.com/mysterus44/DigiTrove/pull/21), head `3adb2824`,
  merge `9b0aa92498a1eaa0dce220bb411df42a9c488e24`, **CI #24 verte** : `23505`
  + nom de contrainte exact, primitive `PostgresConstraintViolation`.
- **P3-D2 TERMINÉ, MERGÉ ET VALIDÉ** via
  [PR #19](https://github.com/mysterus44/DigiTrove/pull/19), head `ef758bbb`,
  merge `4c691864bb2c87d97fbed0091fd9974a259a97b8` (parents `5d07abad` +
  `ef758bbb`), **CI #22 verte**, 13 fichiers, aucune migration. D-031 et D-032.
- **Hotfix temporel P3-D1 TERMINÉ ET MERGÉ** via
  [PR #20](https://github.com/mysterus44/DigiTrove/pull/20), head `0d6e95d9`,
  merge `0854a3933729c6ce4488b9d51e69a1983afaa450` (parents `4c691864` +
  `0d6e95d9`), **CI #23 verte**, **1 fichier de test** (+10/−1) : un test P3-D1
  **préexistant** mélangeait une fenêtre de coupon relative à `now()` avec une
  référence figée au 2026-07-21 12:00 et est devenu rouge au changement de date.
  **Aucune régression métier P3-D2**, aucun code métier touché.
- **P3-D1 TERMINÉ ET MERGÉ** via
  [PR #17](https://github.com/mysterus44/DigiTrove/pull/17), merge
  `78f475e750b0e060fd38c44d6733844807683ff9` (parents `ba48cce1` + `95ab5627`),
  CI run #18 `success`. Périmètre audité : exactement **17 fichiers**, aucune
  migration (29 inchangées), aucune route, aucun contrôleur, aucune écriture BDD.
  9 classes : `IntegerMath`, `Money`, `PricingService`, `DiscountAllocator`,
  `PricedQuote`, `PricedLine`, `CouponSnapshot`, `PricingException`,
  `PricingRefusalReason`. Branche distante conservée à `95ab5627`.
- **P3-D1.1 TERMINÉ ET MERGÉ** via
  [PR #18](https://github.com/mysterus44/DigiTrove/pull/18), merge
  `0e18d69d7216b87118bc024e697cc5629c561846` (parents `78f475e7` + `6349fc19`),
  CI run #19 `success`. Périmètre : **exactement 12 fichiers modifiés**, aucun
  ajout ni suppression, aucune migration. Le merge de la PR #17 ayant précédé la
  revue contradictoire, l'audit post-merge avait démontré **quatre défauts de
  contrat défensif** — A1 devise acceptant `"XOF\n"`, A2 garde-fou P4-B laissant
  passer un service de livraison sous namespace neutre, A3 DTO de pricing sans
  invariants, A4 `line_id` dupliqué écrasé en silence. **Le calcul de prix
  lui-même était correct** ; aucune corruption monétaire n'était possible via
  `PricingService::quote()`. **Les quatre sont fermés et vérifiés sur la
  stable.** Unit **94/117**, Feature P3-D1 **48/167**, P4-B **20/616**, suite
  complète **333/3272**, Pint **132**, 29 migrations inchangées, aucune politique
  métier modifiée. Branches locales `p3-d1-pricing-kernel` et
  `p3-d1-post-merge-hardening` supprimées ; distantes conservées à `95ab5627` et
  `6349fc19`.
- ⚠️ **`P4B_ALLOWED_SERVICE_FILES` (dans `tests/Feature/P4BDownloadLogsTest.php`)
  est une frontière historique fail-closed** : tout gate futur ajoutant un
  fichier sous `app/Services` doit **élargir explicitement** cette allowlist,
  sinon le garde-fou P4-B échoue. C'est le comportement voulu.
- **D-030 FINALISÉE ET VALIDÉE** : roadmap de la couche applicative Commerce →
  Livraison. KingKouda tranche **Q1 = A renforcée** (token CSPRNG jamais
  reconstructible ; reprise = révoquer puis réémettre ; at-least-once assumé),
  **Q2 = B renforcée** (job queued unique portant `order_id` seul, tokens générés
  et e-mail envoyé dans le worker) et **Q3 = A** (coupon scopé sans ligne
  éligible ⇒ refus explicite ; allocation **Hamilton**). Nommage corrigé :
  tarification/checkout/paiement = **P3-D**, livraison = **P4-C**. Douze gates,
  **aucune migration**. **Premier gate : `P3-D1 — Pricing & Quote Kernel`**
  (branche future `p3-d1-pricing-kernel`). P5 non démarré.
- **P4-B0 TERMINÉ ET MERGÉ** via
  [PR #15](https://github.com/mysterus44/DigiTrove/pull/15), merge `6d23e546`
  (parents `a3eac5e` + `9b69f192`) : frontière de privilèges PostgreSQL runtime
  active sur la stable. Branche locale supprimée, distante
  `origin/p4-b0-postgresql-runtime-privileges` conservée à `9b69f192`.
- **P4-B TERMINÉ ET MERGÉ** via
  [PR #16](https://github.com/mysterus44/DigiTrove/pull/16), merge `98441014`
  (parents `d7c53cf` + `49692e25`) : `download_logs` (migration `000013`, 15
  colonnes) avec **G5 `SECURITY DEFINER` possédée par
  `digitrove_download_executor`** (search_path épinglé, objets qualifiés) et
  **autorité de G2 par `current_user = digitrove_download_executor`**, la
  profondeur de trigger n'étant plus qu'une défense secondaire. La vulnérabilité
  historique est **fermée** : un trigger forgé même par le propriétaire superuser
  est refusé (23514), le runtime est arrêté plus tôt (42501). Branche locale
  supprimée, distante `origin/p4-b-download-logs` conservée à `49692e25`.
  **Schéma P4 complet** ; la couche applicative P4 reste entièrement à venir.
- **Commit fondations local** : `4f48fc8 feat: bootstrap Laravel foundations [par Codex]`
- **Merge SITE-00** : `83b6b0c Merge pull request #1 from mysterus44/site-00-static-preview`
- **Merge P1 Identité** : `3f9d132 Merge pull request #2 from mysterus44/p1-identity`
- **Merge P2 Catalogue** : `aff4d05 Merge pull request #3 from mysterus44/p2-catalog`
  (SHA complet `aff4d05d552a78754fc90bcb145fb75aba66dc93`, 2 parents `9a11791` + `fbaa33a`)
- **Merge P3A Coupons et Paniers** :
  `234e303 Merge pull request #4 from mysterus44/p3a-coupons-carts`
  (SHA complet `234e3034f0e1ea5e20af9ca359d19d799c140072`, parents `2288a63` + `1c0d5a2`)
- **Merge P3B Commandes** : [PR #5](https://github.com/mysterus44/DigiTrove/pull/5)
  `f07d225 Merge pull request #5 from mysterus44/p3b-orders`
  (SHA complet `f07d2258c18e196af608f7df97a5816a7cf578f6`, parents `6f7578e` + `499e2bd`)
- **Merge P3C-A Payments** : [PR #6](https://github.com/mysterus44/DigiTrove/pull/6)
  `4a077db Merge pull request #6 from mysterus44/p3c-a-payments`
  (SHA complet `4a077db6720ee07b304a7746bf7545f6dcf743ec`, parents `be1af7f` + `1a792a3` ;
  commits intégrés `c45e44a` + `0d04f77` + `1a792a3`)
- **Merge P3C-B Webhooks** : [PR #8](https://github.com/mysterus44/DigiTrove/pull/8)
  `51c4847 Merge pull request #8 from mysterus44/p3c-b-webhooks`
  (parents `963eef0` + `49ad374` ; commit P3C-B `49ad374`)
- **Merge P3C-B.1 hardening** : [PR #9](https://github.com/mysterus44/DigiTrove/pull/9)
  `13932ac Merge pull request #9 from mysterus44/p3c-b1-webhook-replay-hardening`
  (SHA complet `13932ac11c59be366b859916bd5f15948d7d2cdf`, parents `51c4847` + `c772ac1`)
- **Merge P3C-C Refunds** : [PR #10](https://github.com/mysterus44/DigiTrove/pull/10)
  `122332a Merge pull request #10 from mysterus44/p3c-c-refunds`
  (SHA complet `122332aa5cc9fc25e9bf1898224f5a1da30f6446`, parents `be74854` +
  `1270c53` ; commit final P3C-C intégré `1270c530124fc605ade299f441277edbbf1c5534`)
- **Merge P4-A0 ProductFile Immutability** : [PR #11](https://github.com/mysterus44/DigiTrove/pull/11)
  `a047571 Merge pull request #11 from mysterus44/p4-a0-product-file-immutability`
  (SHA complet `a047571fe4e3453fec39336f297f4241cbb95898`, parents `abaea6e` +
  `8b822c1` ; commit P4-A0 intégré `8b822c1a49710be48371ce1b489a9a213f518d1b`)
- **Merge P4-A1 Bundle Purchase Snapshot** : [PR #12](https://github.com/mysterus44/DigiTrove/pull/12)
  `93d1f17 Merge pull request #12 from mysterus44/p4-a1-bundle-purchase-snapshots`
  (SHA complet `93d1f173b6021fccb7d4df70e24938e02d16f3e3`, parents `a1e2e7f` +
  `94b018c` ; commit P4-A1 intégré `94b018c303d1f91469f6364c664dff4196da97f4`)
- **Merge P4-A2 Download Grants** : [PR #13](https://github.com/mysterus44/DigiTrove/pull/13)
  `77f3766 Merge pull request #13 from mysterus44/p4-a2-download-grants`
  (SHA complet `77f376624fa036aceded6b9095bd927f4adfdb7b`, parents `1b6e401` +
  `cea5f2d` ; commit P4-A2 intégré `cea5f2d47b433add09ebd406b90dafdf3176be56`)
- **Merge P4-A2.1 Download Grant Integrity Hardening** :
  [PR #14](https://github.com/mysterus44/DigiTrove/pull/14)
  `2c25e2a Merge pull request #14 from mysterus44/p4-a2-1-grant-integrity-hardening`
  (SHA complet `2c25e2a412a24ac6ae2e5d51ed6929f3f0a397f7`, parents `0633eb0` +
  `ba834be` ; commit hotfix intégré `ba834befa63a7212c2f2065f51a3f2ae03f5453b`)
- **Plan P4-B Download Logs** : D-029.5 finalisée (1A/2A/3A + R1A/R2A/R3A),
  **PLANIFIÉ — NON IMPLÉMENTÉ** ; branche `p4-b-download-logs` et migration
  `2026_07_14_000012_create_download_logs_table.php` toujours absentes/réservées
- **`origin/main`** : `11130f4` (intact après P4-A2.1 ; aucun push direct)
- **Build/tests** :
  - `docker compose up -d` OK : PostgreSQL 16 + Redis 7 healthy
  - `php artisan --version` OK via `digitrove-php:dev` → Laravel Framework 13.19.0
  - `php artisan test` OK → 2 tests, 2 assertions
  - `./vendor/bin/pint --test` OK → 25 fichiers Laravel
  - `git fsck --full` OK après récupération de l'objet legacy manquant
  - SITE-00 : `php artisan test` OK via `digitrove-php:dev` → 5 tests, 20 assertions
  - SITE-00 : `./vendor/bin/pint --test` OK via `digitrove-php:dev` → 26 fichiers
  - SITE-00 : `npm run build` OK
  - Post-merge SITE-00 : `npm run build` OK, `php artisan test` OK via
    `digitrove-php:dev` → 5 tests, 20 assertions, `./vendor/bin/pint --test` OK
    via `digitrove-php:dev` → 26 fichiers
  - P1 Identité : `php artisan migrate:fresh --env=testing` OK via PostgreSQL réel
    (`digitrove_testing`) → 4 migrations P1
  - P1 Identité : `php artisan test` OK via PostgreSQL réel → 17 tests,
    57 assertions
  - P1 Identité : `./vendor/bin/pint --test` OK → 38 fichiers
  - Post-merge P1 : `php artisan migrate:fresh --env=testing` OK via PostgreSQL
    réel → tables applicatives créées uniquement `users`, `customer_profiles`,
    `visitors`
  - Post-merge P1 : `php artisan test` OK via PostgreSQL réel → 17 tests,
    57 assertions
  - Post-merge P1 : `./vendor/bin/pint --test` OK → 38 fichiers
  - P2 Catalogue : `php artisan migrate:fresh --env=testing` OK via PostgreSQL
    réel → P1 + 6 migrations P2
  - P2 Catalogue : `php artisan test` OK via PostgreSQL réel → 29 tests,
    173 assertions
  - P2 Catalogue : `./vendor/bin/pint --test` OK → 55 fichiers
  - P2 Catalogue : `git diff --check` OK
  - Post-merge P2 (sur `p0-foundations-laravel13` à `aff4d05`, via `digitrove-php:dev`
    + PostgreSQL réel `digitrove_testing`) :
    - `php artisan migrate:fresh --env=testing` OK → 10 migrations (4 P1 + 6 P2)
    - inventaire tables applicatives = `users`, `customer_profiles`, `visitors`,
      `categories`, `products`, `product_prices`, `product_files`,
      `product_category`, `product_bundles` (+ `migrations`) ; extension `citext`
      présente ; **aucune** table commerce/paiement/téléchargement/affiliation/analytics
    - `php artisan test` OK → 29 tests, 173 assertions
    - `./vendor/bin/pint --test` OK → 55 fichiers
    - `git diff --check` OK
  - Post-merge P3A Coupons et Paniers (PostgreSQL réel `digitrove_testing`) :
    - `php artisan migrate:fresh --env=testing` OK → 16 migrations (P1 + P2 + 6 P3A)
    - tables applicatives = `users`, `customer_profiles`, `visitors`, `categories`,
      `products`, `product_prices`, `product_files`, `product_category`,
      `product_bundles`, `coupons`, `coupon_currency_rules`, `coupon_products`,
      `coupon_categories`, `carts`, `cart_items`
    - aucune table commande, paiement, remboursement, livraison ou analytics
    - `php artisan test` OK → 44 tests, 322 assertions
    - tests P3A ciblés OK → 15 tests, 147 assertions
    - `./vendor/bin/pint --test` et `git diff --check` OK
  - Plan final P3B documentaire (D-027, aucun code P3B/P3C) :
    - `php artisan test` OK sur PostgreSQL réel → 44 tests, 322 assertions
    - `./vendor/bin/pint --test` OK → 72 fichiers
    - `git diff --check` OK
  - Post-merge P3B Commandes sur `p0-foundations-laravel13` (PostgreSQL réel) :
    - `php artisan migrate:fresh --env=testing` OK → 19 migrations
    - rollback automatisé des trois migrations P3B OK → tables, fonctions et
      triggers P3B supprimés dans une base PostgreSQL isolée
    - tests P3B ciblés OK → 18 tests, 337 assertions
    - suite complète OK → 62 tests, 650 assertions
    - `./vendor/bin/pint --test` OK → 83 fichiers ; `git diff --check` OK

---

## ✅ CE QUI EST FAIT

- Schéma relationnel v1 conçu → `.context/architecture/SCHEMA_BDD.md`
- Décisions de stack figées → `.context/memory/DECISIONS_LOG.md`
- Audit du legacy réalisé (failles trouvées) → `.context/context/AUDIT_LEGACY.md`
- **P0 Fondations terminé techniquement** :
  - Laravel 13.19 installé (D-012 : Laravel 11 abandonné car bloqué par advisories Composer/Packagist)
  - Filament 5 installé, panneau admin vide créé
  - PostgreSQL 16 + Redis 7 via `docker-compose.yml`
  - Image PHP dev `digitrove-php:dev` avec `intl`, `pdo_pgsql`, `redis`, `zip`, Composer
  - `.env.example` complet ; `.env` ignoré
  - `config/hashing.php` ajouté, Argon2id par défaut
  - disque `private` déclaré, non servi publiquement
  - Pest + Pint configurés
  - CI GitHub Actions ajoutée
  - aucune migration P0 : `database/migrations` est vide
  - `data/users.sqlite` retiré de l'index Git et ignoré
  - scripts legacy avec mots de passe (`data/setup_database.php`, `admin/admin-blog.php`) neutralisés
- **P0.5 Assainissement pré-P1 terminé techniquement** :
  - objet Git manquant `images/offres/tools.png` récupéré via `git fetch --refetch origin`
  - legacy isolé sous `legacy/` sans suppression volontaire
  - `legacy/README.md` ajouté : archive non exécutable, source de migration uniquement
  - `.codex/` ignoré comme outillage local non destiné au commit
  - `DigiTrove_Schema_BDD_v1.md` corrigé : Laravel 13.19, extension `citext`,
    `users.deleted_at`, `status` business sans `deleted`, ordre futur des migrations
  - décisions finales pré-P1 loggées : multi-devises, checkout invité, compte client
    suggéré mais non obligatoire, affiliation future avec compte obligatoire et
    tables dédiées hors P1
  - aucune migration P1, aucune table métier, aucune logique métier ajoutée
- **SITE-00 Vitrine statique de prévisualisation terminé techniquement et mergé** :
  - PR #1 mergée correctement dans `p0-foundations-laravel13`
  - commit de merge : `83b6b0c Merge pull request #1 from mysterus44/site-00-static-preview`
  - branche locale `site-00-static-preview` supprimée après vérification qu'elle
    était mergée
  - branche distante `origin/site-00-static-preview` conservée
  - `origin/main` ne contient pas SITE-00
  - page d'accueil Laravel remplacée par une vitrine/boutique statique premium
  - produits, prix XOF, catégories, avis et aperçus blog issus du contenu legacy
    figés dans la vue
  - images marketing sûres copiées vers `public/images/digitrove/`
  - CTA limités à des ancres ou boutons désactivés, sans route transactionnelle
  - tests HTTP ajoutés : homepage 200, produits/prix visibles, absence de `/checkout`,
    `legacy/`, uploads, liens de livraison, `.zip`, `.pdf`, `download_grants`,
    `product_files`
  - aucune migration, aucune table, aucun modèle métier, aucun contrôleur métier,
    aucun panier, aucun paiement, aucun téléchargement public
- **P1 Identité implémenté et mergé dans `p0-foundations-laravel13`** :
  - PR #2 mergée correctement via
    `3f9d132 Merge pull request #2 from mysterus44/p1-identity`
  - branche locale `p1-identity` supprimée après vérification qu'elle était mergée
  - branche distante `origin/p1-identity` conservée
  - `origin/main` reste intact à `1e41b92 DigiTrove V2`
  - schéma BDD v1 officiellement validé par KingKouda
  - extension PostgreSQL `citext`
  - tables strictement P1 : `users`, `customer_profiles`, `visitors`
  - modèles : `User`, `CustomerProfile`, `Visitor`
  - enums : `UserRole`, `UserStatus`, `LifecycleStage`
  - factories : `UserFactory`, `CustomerProfileFactory`, `VisitorFactory`
  - tests PostgreSQL : extension, tables, colonnes, email CITEXT unique,
    `password_hash` Argon2id, contraintes SQL, relations, SoftDeletes,
    visiteurs anonymes et garde-fou anti tables hors périmètre
  - aucun catalogue, panier, commande, paiement, téléchargement, affiliation,
    analytics, checkout invité ou front métier ajouté
- **P2 Catalogue implémenté et mergé dans `p0-foundations-laravel13`** :
  - PR #3 mergée correctement via
    `aff4d05 Merge pull request #3 from mysterus44/p2-catalog`
    (commits intégrés `d43751d` + `fbaa33a`, base `9a11791`)
  - `origin/main` reste intact à `1e41b92 DigiTrove V2` (P2 absent de `main`)
  - branche locale `p2-catalog` supprimée après vérification `git branch -d` (merge confirmé)
  - branche distante `origin/p2-catalog` conservée
  - `main` local réaligné (pointeur only, `git branch -f main origin/main`) sur `1e41b92`
  - branche créée depuis `origin/p0-foundations-laravel13` à `9a11791`
  - migrations strictement P2 : `categories`, `products`, `product_prices`,
    `product_files`, `product_category`, `product_bundles`
  - modèles : `Category`, `Product`, `ProductPrice`, `ProductFile`
  - enums : `ProductType`, `ProductStatus`
  - factories P2 sans création de vrai fichier digital
  - tests PostgreSQL : tables P2, contraintes, prix multi-devises en `BIGINT`,
    fichiers privés, relations, bundles, SoftDeletes, garde-fous hors périmètre
  - corrections d'audit P2 : index FK inverses explicites, timestamps catégories
    officialisés, `storage_path` durci pour chemins privés relatifs uniquement
  - aucune donnée legacy importée, aucun fichier digital copié, aucun Filament/admin,
    aucune route/API, aucun checkout, paiement, panier, commande, livraison,
    analytics ou affiliation ajouté
  - cycles indirects de bundles toujours non exposés et à traiter avant toute
    écriture métier/admin/API
- **P3A Coupons et Paniers implémenté et mergé** :
  - PR #4 mergée dans `p0-foundations-laravel13` via `234e303`
  - commits intégrés : `81f32fc` (implémentation) + `1c0d5a2` (tests renforcés)
  - branche locale `p3a-coupons-carts` supprimée après preuve du merge
  - branche distante `origin/p3a-coupons-carts` conservée à `1c0d5a2`
  - `origin/main` reste intact à `1e41b92`, sans P3A
  - six migrations strictement P3A : `coupons`, `coupon_currency_rules`,
    `coupon_products`, `coupon_categories`, `carts`, `cart_items`
  - modèles : `Coupon`, `CouponCurrencyRule`, `Cart`, `CartItem`
  - enums : `CouponDiscountType`, `CartStatus`
  - factories sans secret brut, prix panier ou fichier digital réel
  - tests PostgreSQL des contraintes CITEXT, montants `BIGINT`, pivots, UUID/hash,
    FK prudentes, index et absence de tables hors périmètre
  - couverture de régression renforcée : cascades réelles panier → articles et
    coupon → règles/pivots, avec préservation des produits et catégories
  - introspection PostgreSQL verrouillant `coupons.code` en `citext`,
    `carts.public_id` en `uuid` et les devises panier/coupon en `varchar(3)`
  - cohérences coupon inter-tables reportées à la future logique transactionnelle
  - aucun contrôleur, route, API, service, Filament, checkout, commande, paiement,
    webhook, remboursement, téléchargement, import legacy ou déploiement Azure
  - P3B/P3C absents du périmètre et de la PR P3A
- **P3B Commandes implémenté, corrigé et mergé** :
  - [PR #5](https://github.com/mysterus44/DigiTrove/pull/5) mergée dans
    `p0-foundations-laravel13` via `f07d225`
  - commits intégrés : `b42371b` (implémentation) + `499e2bd` (durcissement sécurité/tests)
  - branche locale `p3b-orders` supprimée après preuve du merge
  - branche distante `origin/p3b-orders` conservée à `499e2bd`
  - `origin/main` reste intact à `1e41b92`, sans P3B
  - décision D-027 validée humainement puis appliquée sur `p3b-orders`
  - ordre : `orders` → `order_items` → `coupon_redemptions`
  - suppression et mutations commerciales des commandes/lignes bloquées par triggers
    PostgreSQL ; seules transitions de cycle de vie et nullifications FK contrôlées
  - une ligne maximum par produit non NULL via index unique partiel
  - cohérence lignes/commande et commande/redemption validée au commit par constraint
    triggers différés ; `SET CONSTRAINTS ALL IMMEDIATE` obligatoire dans les tests
  - consommation coupon seulement après paiement serveur confirmé en P3C, sous verrou,
    avec identité `HMAC-SHA-256` versionnée ; aucune réservation pendant `pending`
  - remises P3 limitées aux coupons ; `discount_minor = 0` sans snapshots coupon
  - trois migrations, trois modèles, un enum, trois factories et tests PostgreSQL créés
  - correction post-review : `orders_coupon_snapshot_consistency_check` ferme le cas
    PostgreSQL `CHECK = UNKNOWN` ; tests P3B vérifient SQLSTATE + nom de contrainte
    ou message trigger, scénarios coupon NULL couverts, rollback P3B automatisé
  - audit post-merge : trois tables P3B, six fonctions, huit triggers, dont quatre
    constraint triggers `DEFERRABLE INITIALLY DEFERRED`, confirmés dans PostgreSQL
  - aucun code P3C, checkout, paiement, webhook, remboursement ou livraison

---

## 🛑 PROCHAINE TÂCHE

## ⛔ P6-A1.2 — Currency-safe Commerce Rollup Worker & Reconciliation

**Statut** : NON COMMENCÉ.

**P6-A1.1 TERMINÉ ET VALIDÉ** — Prêt pour revue/merge.

La migration `000022` crée la table `crm_contact_commerce_rollups` et la fonction `SECURITY DEFINER` `refresh_crm_contact_commerce_rollup(BIGINT, VARCHAR)`, toutes deux possédées par `digitrove_crm_executor`.
L'autorité est garantie inaltérable, traitant de la cohérence via `#variable_conflict use_column` et évitant le débordement.
Les cinq tests (Authority, Concurrency, Privileges, Rollback, Schema) valident un contrat exhaustif (17 tests, 74 assertions).
Pint exécuté. P6-A1.1 est contenu sur la branche `p6-a1-1-currency-safe-commerce-rollup`.

### 2026-08-05 — Codex (P6-A1.1 Commerce Rollup Authority implémenté)
- Fait : Implémentation et hardening P6-A1.1 (migration 000022, tests de schémas, concurrence, autorité PostgreSQL, contrats de sécurité).
- État build/tests : Suite complète 921 tests / 6424 assertions, Pint vert, diff clean.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-046 clôturée (implémentation de la projection `crm_contact_commerce_rollups` en BIGINT).
- Laisse à : P6-A1.1 IMPLÉMENTÉ ET VALIDÉ LOCALEMENT. EN ATTENTE DE REVUE/MERGE. P6-A1.2 NON COMMENCÉ.

### 2026-08-05 — Codex (P6-A1.0 clôture)

- Ajout de la migration `000022` créant la table des rollups, possédée par `digitrove_crm_executor`, sans privilège pour `PUBLIC` ou `digitrove_runtime`.
- Ajout de la fonction `refresh_crm_contact_commerce_rollup`, `SECURITY DEFINER`, possédée par `digitrove_crm_executor`.
- Les privilèges `SELECT` sur les tables constitutives ont été octroyés.
- Tous les tests P6-A1.1 (Autorité, Concurrence, Privilèges, Rollback, Schema) sont terminés, verts et formatés par Pint.
- Concurrence PostgreSQL réelle validée avec `pg_advisory_xact_lock`.
- L'immuabilité stricte des commandes contournée uniquement via `session_replication_role` dans les tests afin d'éprouver la condition de suppression des rollups.

### 2026-08-04 — Codex (hardening pré-PR P6-A1.0)

- Les trois constats pré-PR sont fermés dans le gate existant : le trigger suit
  désormais la transition `false → true` de `status acquis + paid_at non NULL`,
  y compris `status` puis `paid_at` et l'ordre inverse, avec une seule outbox.
- `ProcessCrmOrderAttribution` conserve `ShouldBeUnique` et `orderId` seul, avec
  `uniqueFor=3600`; ce TTL dépasse l'horizon déclaré des cinq tentatives et laisse
  le sweeper récupérer un verrou abandonné, PostgreSQL restant idempotent.
- Une nouvelle création reste limitée à 254 caractères. La recherche idempotente
  précède ce contrôle strict après une enveloppe initiale valide jusqu'à 320 : un
  replay historique exact 255..320 retourne la même Order sans mutation; tout
  conflit d'e-mail reste refusé.
- Validation réelle : **37 migrations**, P6-A1.0 **48/285**, P6-A0 **40/235**,
  P5-A3 **54/371**, P5-A2 **25/198**, P4-C **86/559**, P4-B **20/560**, P3-D2
  **91/364**, P3-B **18/354**, suite complète **883/6273**, Pint **367** et
  `git diff --check` verts. Aucune `000022`; P6-A1.1+ restent non commencés.

### 2026-08-03 — Codex (P6-A1.0 attribution Order vers CRM durable)

- Branche `p6-a1-0-durable-order-crm-attribution`, base exacte `8f4e91c`; une
  migration `000021`, deux tables, cinq fonctions et trois triggers.
- D-045 résout les blocages de D-044 : nouveaux e-mails checkout `3..254`,
  schéma historique `VARCHAR(320)` conservé, outbox dans la transaction
  financière et résolution CRM après commit. Une incompatibilité historique
  devient `unattributable/invalid_email_contract`, jamais un rollback financier.
- L'outbox ne contient aucune PII. `contact_id_snapshot` capture seulement un
  contact actif exact déjà présent et protège l'historique d'une anonymisation
  puis recréation au même e-mail. Le resolver n'est jamais appelé par le trigger.
- Le runtime a uniquement EXECUTE sur `list_due_crm_order_attributions` et
  `process_crm_order_attribution`; `digitrove_crm_executor` reste NOLOGIN. Job
  unique, listener `OrderPaid` faible, commande sweeper et scheduler 5 minutes
  sont fail-closed et désactivés par défaut.
- Validation réelle à l'implémentation initiale, désormais supersédée par le
  hardening ci-dessus : **37 migrations**, P6-A1.0 **44/239**, P6-A0 **40/235**,
  P5-A3 **54/371**, P5-A2 **25/198**, P5-A1 **74/400**, P5-A0 **19/256**,
  P4-C **86/559**, P4-B **20/560**, P3-D2 **91/364**, P3-B **18/354**, suite
  **879/6227**, Pint **367**, rollback isolé, concurrence et diff-check verts.
- Un correctif post-commit a durci la précision `TIMESTAMPTZ(6)` des lignes
  immédiatement dues. Deux autres commits `fix:` ont adapté des sentinelles
  historiques sans réduire leur couverture. P6-A1.1+, P6-A2+, P7 et P5-A3D ne
  sont pas commencés.

### 2026-08-03 — Codex (clôture P6-A0 et audit d'architecture P6-A1, état historique avant D-045)

- PR #31 prouvée : head `3276fef1`, merge `47888d09`, parents `a11de061` et
  `3276fef1`; les six commits P6-A0 sont ancêtres de la stable. Aucun CI GitHub
  n'était visible avant le merge.
- Le hardening final exige `UserStatus::Active` dans l'autorité
  `resolve_crm_contact` et dans le trigger de liaison; les comptes `suspended`
  et `blocked` sont refusés par PostgreSQL et par le runtime sanitizé, tandis
  que le compte actif vérifié reste accepté.
- Validation locale post-merge : **36 migrations**, P6-A0 **40/235**, P5-A3
  **54/371**, P5-A2 **25/198**, P5-A1 **74/400**, P5-A0 **19/256**, P4-C
  **86/559**, P4-B **20/560**, P3-D2 **91/364**, P3-B **18/354**, suite
  complète **835/5988**, Pint **347**, rollback/concurrence/diff-check verts.
- D-044 retient une attribution Order vers CRM immuable et séparée, puis une
  projection `(contact_id, currency)` reconstruite idempotemment depuis les
  Orders acquis et Refunds réussis. `orders.total_minor`, `orders.currency`,
  `orders.paid_at` et `refunds.succeeded_at` sont les sources financières.
- À cet instant, P6-A1.0 restait bloqué par le contrat e-mail divergent et la
  sémantique transactionnelle; D-045 a depuis levé ces deux blocages. P6-A1.1+,
  P7 et P5-A3D ne sont pas commencés.

### 2026-08-03 — Codex (P6-A0 identité, consentement et autorisation)

- Migration unique `000020` : `crm_contacts` et ledger append-only
  `crm_marketing_consent_events`; 36 migrations à cette frontière, avant le
  `000021` désormais livré séparément par P6-A1.0.
- Identité exacte `trim` + CITEXT, achats invités prouvés par l'e-mail figé de
  l'Order, comptes liés seulement si actifs/vérifiés et e-mail exact; aucun
  Visitor, alias folding, backfill ou fusion approximative.
- Consentement limité à `email/promotional`; checkout = grant avec Order exact,
  account settings = grant/withdraw avec User vérifié; version de politique
  configurée, idempotence SHA-256, timestamp serveur, legacy et analytics ignorés.
- Autorité PostgreSQL : executor NOLOGIN/NOINHERIT, trois SECURITY DEFINER à
  search_path fixe, runtime EXECUTE-only et PUBLIC sans accès direct.
- Config désactivée par défaut, services fail-closed, paramètres sensibles,
  erreurs sanitizées et Gate CRM admin actif/non supprimé uniquement.
- Validation pré-hardening : P6-A0 **33/211**, P5-A3 **54/371**, P5-A2 **25/198**, P5-A1
  **74/400**, P4-C **86/559**, P4-B **20/560**, P3-D2 **91/364**, P3-B
  **18/354**, suite complète **828/5964**, Pint **347**, 36 migrations et
  `git diff --check` propre. Rollback et deux concurrences PostgreSQL verts.
- P6-A1+, P7 et P5-A3D non commencés; aucun flux utilisateur CRM ni envoi.

### 2026-08-03 — Codex (clôture P5 et audit d'architecture P6)

- PR #30 prouvée : parents `c6790e6` + `642f8e35`, merge `87bf8399`, CI #37
  success; les six commits P5-A3C sont ancêtres de la stable.
- Validation post-merge : P5-A3C **22/178**, P5-A3 **54/371**, P5-A2
  **25/198**, P5-A1 **74/400**, P5-A0 **19/256**, suite **795/5753**, Pint
  **318**, 35 migrations et diff-check propre.
- D-042 clôt P5 et reporte P5-A3D au durcissement préproduction. Aucun contrôle
  opérationnel analytique n'est exposé dans Filament.
- Identité : achats invités réels, e-mail de commande immuable, compte/email
  mutable séparé, profil compte seulement, aucun contact unifié ni stitching
  applicatif. Le booléen `marketing_consent` et la LTV sans devise du profil ne
  sont pas des autorités P6.
- Consentement analytique P5 distinct du marketing; seul l'e-mail de livraison
  transactionnel existe. Segments recommandés : définition allowlistée/
  versionnée + membership matérialisé, après rollups par contact et devise.
- Paniers persistants présents, mais aucun flux public de création/abandon ni
  e-mail d'invité avant checkout; relances reportées. **AFFILIATION NON FONDÉE
  — HORS PREMIER GATE P6**.
- Gate CRM futur réservé aux admins actifs via une autorisation distincte
  `manageCustomerRelationships`; exports privés/audités seulement après identité,
  consentement et membership fiables.

### 2026-08-03 — Codex (P5-A3C produits et tunnel)

- Ajout des read models/DTO produit et funnel, widgets Filament Produits/Tunnel
  et tests PostgreSQL/Redis/UI/sécurité, sans migration ni nouvelle ACL.
- Le produit reste identifié honnêtement par `Produit #<id>`; les vues sont
  globales, achats/revenus sont filtrés par devise et aucun taux achats/vues
  n'est présenté comme cohorte. Les ratios tunnel sont agrégés non cohortés.
- Auto-audit : caches durcis en payloads scalaires pour Redis avec
  désérialisation d'objets désactivée; allowlist P4-B étendue explicitement aux
  huit services/read models P5-A3C, sans affaiblir sa frontière fail-closed.
- Validation : P5-A3C **18/123**, P5-A3 total **50/316**, suite complète
  **791/5698**, Pint **318**, **35 migrations**, `git diff --check` propre.

### 2026-08-03 — Codex (clôture post-merge P5-A3A/B)

- PR #29 mergée : head `31f986dc`, merge `2bbf2b52`, parents `51b8d4b` et
  `31f986dc`, CI #36 success; les quatre commits P5-A3A/B sont intégrés.
- Validation post-merge : P5-A3 **32/193**, P5-A2 **25/198**, P5-A1/P5-A0,
  P4-C **86/559**, P4-B **20/560**, P3-D2 **91/364**, P3-B **18/354**, suite
  complète **773/5575**, Pint **302**, **35 migrations**, `000019` appliquée et
  `git diff --check` propre.
- Reader LOGIN/ACL, accès admin actif, transactions read-only, séparation des
  devises, rollback isolé et contrat CI restent verts. À cette clôture,
  P5-A3C était encore non commencé.

### 2026-08-03 — Codex (P5-A3A/B admin analytics read boundary et dashboard)

- Décisions humaines appliquées : admin actif uniquement, `staff` refusé,
  portée globale uniquement, widgets analytiques en P5-A3; P6 reste CRM et
  marketing.
- Migration `000019`, rôle reader LOGIN restreint, connexion
  `pgsql_analytics_reader`, policy/Gate et accès Filament fail-closed.
- Vue d'ensemble et Ventes lisent seulement les quatre rollups, séparent les
  devises, signalent les jours non calculés et le jour UTC courant provisoire;
  net négatif conservé et `add_to_carts` affiché « Non suivi ».
- Validation : P5-A3 **32/193**, P5-A2 **25/198**, P5-A1 **74/400**, P5-A0
  **19/256**, P4-C **86/559**, P4-B **20/560**, P3-D2 **91/364**, P3-B **18/354**, suite complète **773/5575**, Pint **302**, **35 migrations**,
  `git diff --check` propre.

### 2026-07-25 — Codex (clôture post-merge P5-A2 et audit P5-A3)

- PR #28 mergée : head `03063db8`, merge `17aaa4f4`, parents `d7c4d62a` et
  `03063db8`, CI #35 success; les quatre commits P5-A2 sont intégrés.
- Validation post-merge : P5-A2 **25/198**, P5-A1 **74/400**, P5-A0 **19/256**,
  P4-C **86/559**, P4-B **20/560**, P3-D2 **91/364**, P3-B **18/354**, suite
  **741/5381**, Pint **278**, **34 migrations**, `git diff --check` propre.
- Audit P5-A3 : un panel sans autorisation production, aucune policy analytique,
  rollups globaux sans tenant, aucune connexion web autorisée à les lire,
  ChartWidget/StatsOverviewWidget disponibles dans Filament, aucun widget
  applicatif, aucun code P5-A3/P6/P7.

### 2026-07-25 — Codex (P5-A2 rollups et partitions autoritatifs)

- Diagnostic fermé : engagement produit sans devise séparé des achats/revenus
  par devise; aucune devise sentinelle ni duplication des vues.
- `purchased_product_id` conserve l'identité commerciale immuable, y compris
  après suppression catalogue; un bundle reste attribué au bundle acheté.
- Rollups UTC autoritatifs, worker/executor dédiés, ACL EXECUTE-only, partitions
  mensuelles bornées et DEFAULT non déplacée.
- Validation : **34 migrations**, P5-A2 **24/182**, suite **740/5365**, Pint
  **278**, rollback isolé, ACL et concurrence PostgreSQL verts.
- Laisse à : revue/merge P5-A2, puis plan P5-A3 dans une exécution séparée.

### 2026-07-25 — Codex (clôture post-merge P5-A1)

- PR #27 mergée : head `955cc340`, merge `c699c5b9`, parents `5bff49bf` et
  `955cc340`, CI #33 success.
- Les cinq commits P5-A1 sont ancêtres de la stable synchronisée `0/0`.
- Validation post-merge : P5-A1 **74/400**, P5-A0 **19/256**, P4-C **86/559**,
  P4-B **20/560**, P3-B **18/354**, Pint **254**, **33 migrations** et
  `git diff --check` propre.
- Prochaine tâche : **P5-A2 — Authoritative Rollups and Safe Partition
  Operations**. P5-A3, P6 et P7 non commencés.

### 2026-07-24 — Codex (P5-A1 first-party analytics ingestion)

- Clôture P5-A0 : commit `5bff49b`, poussé sur
  `origin/p0-foundations-laravel13`.
- P5-A1 : `000017` ajoute uniquement l'autorité PostgreSQL; aucune table métier.
  `digitrove_runtime` n'a toujours aucun DML direct et reçoit seulement EXECUTE
  sur une fonction SECURITY DEFINER possédée par le rôle NOLOGIN dédié.
- Consentement explicite/versionné, identité analytique dédiée créée seulement
  après consentement, révocation immédiate des écritures futures, cookies
  chiffrés HttpOnly/SameSite Strict, HTTPS hors local/testing.
- Endpoint web/CSRF same-origin, un événement JSON borné par requête,
  normalisation serveur, HMAC-SHA-256 versionné de l'IP, rate limiting sur
  digests. `page_view` et `product_view` seulement.
- Connexion Laravel dédiée refusée : le même rôle runtime n'apportait aucune
  isolation d'identité mesurable; garde stricte contre toute transaction
  Commerce ambiante et invocation préparée unique.
- Hardening confidentialité : les deux recherches SQL de session exigent une
  session anonyme ou le même utilisateur authentifié. Logout A → anonyme et
  A → B créent une session compatible sans muter la session A; anonyme → A
  enrichit la même session; A → A la réutilise. Les appels directs sous
  `digitrove_runtime` et un test concurrent à deux processus verrouillent cette
  politique dans PostgreSQL.
- Validation réelle : P5-A1 **74/400**, P5-A0 **19/256**, P4-C **86/559**,
  P4-B **20/560**, P3-B **18/354**, suite **716/5183**, Pint **254**,
  **33 migrations**, rollback isolé et concurrence verts.
- P5-A2, P6 et P7 non commencés.

## 🗃️ Archive de passation P3-D4/D5 (supersédée par l'état en tête)

## 🎯 Après merge du macro-gate : `P4-C0 + P4-C1 + P4-C2` (historique)

**`P3-D4 + P3-D5` SONT IMPLÉMENTÉS** sur `p3-d4-d5-payment-confirmation`
(macro-gate unique, **D-034**), **en attente de revue/merge**. **Aucune
migration.** Voir le bloc d'état en tête et D-034 pour le détail figé (webhook
non autoritatif, contre-appel obligatoire, argent en entiers, ladder
`pending→processing→succeeded`, `payment_review` sur succès incohérent, coupon au
`paid` seulement, free order sans Payment, `OrderPaid` après COMMIT, CinetPay
désactivé, PowerPay scaffold, fenêtre résiduelle sans outbox).

**La prochaine macro-tâche, après merge, est `P4-C0 + P4-C1 + P4-C2`** (Queue &
Mail Secret Safety → Grant Issuance → Refund Grant Revocation, D-030). **NE PAS**
commencer P4-C avant le merge de P3-D4/D5. `P4-C0` bloque tous les gates de
livraison ; **`P4-C2` doit être mergé avant l'activation réelle de `P4-C3`**.
⚠️ Réécrire `.context/skills/SECURITE_TELECHARGEMENT.md` (partiellement périmé)
avant le gate `P4-C4`.

Invariants D-033/D-034 hérités, utiles à P4-C :
- `payment.public_id` est la **clé d'idempotence fournisseur** (publique,
  stable) ; la **clé brute appelant n'est jamais persistée, loguée ni envoyée**
  au fournisseur (digest SHA-256 seul) ;
- **une seule tentative `pending|processing` vivante par Order** (règle
  applicative, sérialisée par le verrou Order) ; nouvelles tentatives seulement
  après `failed|cancelled|expired` ;
- **timeout fournisseur ambigu ⇒ tentative laissée `pending`**, jamais `failed` —
  la qualification est P3-D4 ;
- **aucune confirmation** : Order reste `pending`, aucun `succeeded`, aucun
  `orders.status = paid`, aucun coupon consommé, aucun webhook, `OrderPaid`,
  refund ni DownloadGrant ;
- classification des `23505` exclusivement via `PostgresConstraintViolation`.

**P3-D3 est mergé (CI #26 vert) et clôturé** ; **P3-D4 est désormais le prochain
gate autorisé, NON commencé.**

### Historique : P3-D2.1

**`P3-D2.1` EST TERMINÉ, MERGÉ ET VALIDÉ** —
[PR #21](https://github.com/mysterus44/DigiTrove/pull/21), head `3adb2824`,
merge `9b0aa92498a1eaa0dce220bb411df42a9c488e24`, **CI #24 verte**, 7 fichiers
(+403/−9), **aucune migration**. Stable avant ce commit documentaire :
`9b0aa92498a1eaa0dce220bb411df42a9c488e24`.

⚠️ **`App\Support\PostgresConstraintViolation` doit être réutilisée en P3-D3**
pour **toute** classification de contrainte PostgreSQL (`payments_idempotency_
key_hash_unique`, `payments_order_id_attempt_number_unique`, les uniques
partiels de `payment_webhook_events`…). Elle ne s'applique **que** lorsque le
contrat exige une paire structurée SQLSTATE + nom de contrainte : **toute
exception de paiement n'est pas une violation d'unicité** — un échec fournisseur,
un timeout réseau ou une erreur de sérialisation `40001` relèvent d'autres
branches et ne doivent jamais passer par cette primitive.

### Historique : ce qu'a fermé P3-D2.1

**P3-D2 reste fonctionnellement terminé** : aucune régression métier n'a été
observée. Le défaut est **défensif** — `OrderService` classait certaines erreurs
sur la seule présence d'un nom de contrainte dans le message d'un `Throwable`,
sans exiger le SQLSTATE PostgreSQL `23505`. **Preuve RED** : une exception
applicative dont le message contenait `orders_order_number_unique` déclenchait un
**retry injustifié** (générateur appelé 2 fois au lieu d'1) ; des messages
usurpant `orders_cart_id_unique` / `orders_checkout_idempotency_hash_unique`
étaient traduits à tort en `CartAlreadyCheckedOut` / `IdempotencyConflict`.

Correctif : primitive `App\Support\PostgresConstraintViolation` — SQLSTATE lu
dans le champ **structuré** `errorInfo[0]` (jamais déduit d'un texte), nom de
contrainte extrait **après** confirmation du `23505` et comparé par **égalité
exacte**. Aucun `str_contains()` ne classe plus une erreur BDD en production.
**Ce pattern devra être réutilisé en P3-D3 pour les contraintes de `payments`.**

Compteurs : Unit P3-D2.1 **20/20**, P3-D2 **71/344** (était 66/323), suite
complète **424/3641** (était 399/3599), Pint **140**, **29 migrations**.

### Détail du durcissement P3-D3 (revue pré-merge)

Cinq findings de revue fermés sur la même branche/PR #22 : (A) `initiate()`
refuse tout `transactionLevel > 0` ; (B) `$now` injecté unique, `isExpired()`
partagé (`>= expires_at`), plus aucun `isFuture()` ; (C) un rejeu payable
**rappelle le fournisseur** avec le même `payment.public_id` même si une
référence existe, pour récupérer les instructions perdues ; (D) toute exception
BDD inconnue ⇒ `IntegrityFailure` sanitizé, `name()` qui lève ⇒
`ProviderUnavailable`, plus de `throw $exception` brut ; (E) preuves C1–C4
service-level sur connexions runtime indépendantes. La suite P3-D3 tourne
**sans transaction enveloppante** (concern `InteractsWithPaymentsDatabase`),
condition nécessaire pour prouver la garde (A). ⚠️ **Pour P3-D4**, tout gate
touchant le paiement doit rester compatible avec cette garde et réutiliser
`PostgresConstraintViolation` (23505 + nom exact) sans classer par texte.

---

## Ensuite : `P3-D3 — Payment Initiation` — branche future `p3-d3-payment-initiation`

**P3-D2 EST TERMINÉ, MERGÉ ET VALIDÉ** — [PR #19](https://github.com/mysterus44/DigiTrove/pull/19),
head `ef758bbb`, merge `4c691864`, CI #22 verte. Le **hotfix temporel P3-D1** est
également fermé — [PR #20](https://github.com/mysterus44/DigiTrove/pull/20),
head `0d6e95d9`, merge `0854a393`, CI #23 verte : il corrige un test P3-D1
**préexistant** qui mélangeait une fenêtre de coupon relative à `now()` avec une
référence figée, **sans aucune régression métier P3-D2**.

Stable avant le commit documentaire : **`0854a3933729c6ce4488b9d51e69a1983afaa450`**.
Validation post-merge : P3-D1 **48/167**, P3-D2 **66/323**, P4-B **20/620**,
suite complète **399/3599**, Pint **138**, **29 migrations**, aucune `000014`.

**Le prochain gate autorisé est `P3-D3 — Payment Initiation`, non commencé.**

Invariants à respecter en P3-D3 :
- **l'Order et ses `order_items` sont la source autoritative** — le panier est
  `converted` et peut avoir changé depuis ; ne jamais relire `carts` pour un
  montant ;
- **aucune donnée tarifaire venant du client n'est acceptée** ;
- une **commande gratuite reste `pending`** : atteindre `paid` est P3-D4 ;
- **aucun coupon n'est consommé au checkout** ; `coupon_redemptions` et
  `coupons.redemptions_count` n'arrivent qu'à la **confirmation serveur du
  paiement** (P3-D4), sous verrou. Une commande `pending` ne réserve rien ;
- le **TTL `pending` est validé côté serveur** (D-032) et un **rejeu idempotent
  conserve l'expiration d'origine** ;
- la **clé d'idempotence brute n'est jamais persistée** (digest SHA-256 seul) ;
- les collisions d'`order_number` passent par un **savepoint PostgreSQL** — un
  retry nu ne recevrait que `25P02` ;
- le **Cart devient `converted` dans la transaction de checkout** ;
- **aucun Payment**, **aucun événement `OrderPaid`**, **aucun DownloadGrant**
  n'existe encore ;
- ⚠️ tout nouveau fichier sous `app/Services` impose d'élargir explicitement
  `P4B_ALLOWED_SERVICE_FILES`.

### Historique : ce qu'a livré P3-D2

D-031 : **Q1 = C** (composant de bundle soft-deleted ⇒ checkout refusé, aucun
snapshot partiel) et **Q2 = B** (Cart → `converted` dans la même transaction).
**Aucune migration.**

3 classes : `App\Services\Checkout\{OrderService, CheckoutException,
CheckoutRefusalReason}`. Verrouillage `carts` → `products` du panier →
`product_bundles` + **produits enfants** (c'est ce dernier verrou qui rend Q1=C
applicable). Idempotence par digest SHA-256 seul, rejeu résolu avant toute règle
d'état, comparaison de colonnes faute de fingerprint, `orders_cart_id_unique` en
backstop. Order gratuite `pending`, aucune consommation de coupon.

**Finalisation pré-publication (D-032)** — deux défauts relevés en revue et
fermés avant tout push :
1. les **30 minutes d'expiration étaient codées en dur** (décision commerciale
   implicite) → `config/checkout.php` + `CHECKOUT_PENDING_TTL_MINUTES`, défaut
   30, minutes entières ≥ 1, valeur invalide = échec **avant toute écriture**,
   rejeu conservant l'`expires_at` d'origine ;
2. le **retry d'`order_number` n'avait aucun savepoint** : reproduction sous
   `digitrove_runtime` → après `23505` toute commande suivante reçoit **`25P02`
   (transaction avortée)**, le retry était non fonctionnel. Corrigé par une
   transaction Laravel imbriquée (vrai `SAVEPOINT`), 3 essais maximum, verrou du
   Cart préservé. Primitive `App\Support\OrderNumberGenerator` extraite (sous
   `app/Support`, donc allowlist P4-B inchangée).

Validation : P3-D2 **66/308**, P4-B **20/619**, suite complète **399/3584**,
Pint **138**, 29 migrations inchangées. Concurrence prouvée sur bases jetables +
deux connexions PDO réelles (`55P03` ×2, `23505` sur `orders_cart_id_unique`).

⚠️ **La branche n'est PAS publiée** : le push échoue faute d'identifiants Git
dans la session. Quatre commits locaux à pousser :
`git push -u origin p3-d2-checkout-order-transaction`.

**Ne pas commencer P3-D3 avant publication, revue et merge.**

### Historique : audit pré-implémentation P3-D2

**`P3-D1` ET `P3-D1.1` SONT TERMINÉS ET MERGÉS** (PR #17 → `78f475e7`, CI #18
verte ; PR #18 → `0e18d69d`, CI #19 verte). Le noyau de tarification est en
place, durci et validé sur la stable. La prochaine tâche est la **planification**
de `P3-D2 — Checkout Order Transaction` (branche future
`p3-d2-checkout-order-transaction`) — **non commencée**.

Rappels utiles pour ce gate, issus de D-030 et du schéma :
- transaction **unique** créant `Order` + `order_items` + le snapshot exhaustif
  `order_item_bundle_components` (un seul `INSERT … SELECT` après
  `SELECT … FROM products WHERE id = <bundle> FOR UPDATE`) ;
- **bundle vide refusé AVANT** la création de l'order_item (garantie applicative,
  jamais un invariant PostgreSQL — D-029.3 point 7) ;
- idempotence par `orders.checkout_idempotency_hash` (unique, 64 hex) ;
- `validate_order_items_consistency` est **différé** : les tests devront forcer
  `SET CONSTRAINTS ALL IMMEDIATE` ;
- **aucune** ligne `coupon_redemptions`, **aucun** incrément de
  `coupons.redemptions_count` — cela reste P3-D4 (D-027 point 5) ;
- le `PricedQuote` de P3-D1 fournit déjà tous les montants et snapshots : P3-D2
  **ne recalcule rien** ;
- ⚠️ tout nouveau fichier sous `app/Services` impose d'élargir explicitement
  `P4B_ALLOWED_SERVICE_FILES`, sinon le garde-fou P4-B échoue.

### Historique : ce qu'a fermé P3-D1.1

Chaque défaut a été reproduit par sonde exécutée avant correction, puis rejoué
après merge :
- **A1** — `Money::of(100, "XOF\n")` était accepté : en PCRE, `$` matche aussi
  juste avant un saut de ligne final. Corrigé par les ancres absolues
  `/\A[A-Z]{3}\z/`, et `Money::assertValidCurrency()` devient la **source unique**
  du contrat, réutilisée par `PricedQuote`.
- **A2** — le garde-fou P4-B, rétréci pendant P3-D1 pour laisser passer
  `app/Services/Pricing`, laissait passer `Services/Fulfilment/GrantIssuer.php`
  et `DeliveryManager.php` (sonde avec fichiers réels : test **passant**).
  Remplacé par une **allowlist fail-closed** des 7 fichiers autorisés, chemins
  normalisés, récursive, indépendante de l'OS, nommant les intrus. **Chaque gate
  futur devra élargir cette liste explicitement.**
- **A3** — `PricedLine`, `PricedQuote` et `CouponSnapshot` acceptaient des états
  incohérents (remise > sous-total, `lines` vide, snapshot coupon avec remise
  nulle, devise `'zzz'`…). Invariants ajoutés aux constructeurs, en miroir exact
  des CHECK `orders`/`order_items`, toute l'arithmétique via `IntegerMath`.
- **A4** — deux parts partageant un `line_id` s'écrasaient : `allocate(10, …)`
  retournait une somme de 5 **sans exception**. Refus explicite des `line_id`
  dupliqués, `line_id < 1` et `product_id < 1`.

Points à confirmer en revue (inchangés depuis P3-D1) :
- deux jugements conservateurs pris faute de règle explicite dans D-030 :
  (1) seul `products.status = 'published'` est vendable ; (2) `min_order_minor`
  est mesuré sur le **panier entier**, la remise portant sur le sous-total
  **éligible** ;
- `declare(strict_types=1)` sur les fichiers du noyau — nécessaire pour que
  `Money::of(1.5, …)` lève au lieu de tronquer.

Rappel du contexte : le schéma P1→P4 est complet (29 migrations) et **D-030 est
finalisée et validée** — la couche applicative est planifiée en 12 gates, **aucun
ne crée de migration**.

**Objectif unique du gate** : transformer `(panier, devise, coupon?)` en un devis
immuable `PricedQuote`, avec la remise **allouée aux lignes**. **Zéro écriture
BDD, zéro Order, zéro route, zéro paiement, zéro grant, zéro migration.**

Sortie contractuelle :

```text
PricedQuote { currency, subtotalMinor, discountMinor, taxMinor, totalMinor,
              lines[], couponSnapshot|null }
```

Chaque ligne : `product_id`, `product_name_snapshot`, `product_slug_snapshot`,
`product_type_snapshot`, `unit_price_minor`, `quantity`, `line_subtotal_minor`,
`line_discount_minor`, `line_total_minor`.

**Pourquoi ce gate d'abord (mesuré sur le code)** : `cart_items` ne porte **aucun
prix** ; le prix se résout depuis `product_prices` par devise. Or le constraint
trigger différé `validate_order_items_consistency` (`000002`) exige au COMMIT
`SUM(line_subtotal_minor) = orders.subtotal_minor`, **`SUM(line_discount_minor) =
orders.discount_minor`** et `SUM(line_total_minor) + orders.tax_minor =
orders.total_minor` ; `orders_coupon_snapshot_consistency_check` exige
`discount_minor > 0` dès qu'un snapshot coupon existe. Une remise non allouée fait
donc échouer le COMMIT en `23514`.

**Règles figées (Q3 = A)** : coupon scopé (`coupon_products` /
`coupon_categories`) sans ligne éligible ⇒ **refus explicite de validation**,
jamais de retrait silencieux ; allocation **Hamilton (plus grand reste)**,
départage `résidu décroissant → product_id croissant → id de ligne croissant` ;
`0 <= line_discount_minor <= line_subtotal_minor` ; **somme exacte** ; aucun
`float`, aucune division flottante, aucun `round()` sur les montants ; produit
sans prix dans la devise demandée ⇒ **refus**, jamais de repli sur une autre
devise (D-018).

**Tests attendus** : arithmétique entière pure ; produit direct ; bundle ; devise
absente ⇒ refus ; coupon `percent` plafonné par `max_discount_minor` ; coupon
`fixed` par devise ; `min_order_minor` non atteint ; coupon scopé sans ligne
éligible ⇒ refus ; **Σ remises de lignes == remise Order sur restes non
divisibles** ; départage stable sur résidus égaux ; coupon expiré / inactif ;
absence de `float` dans le code.

**Gates suivants (ne pas anticiper)** : P3-D2 checkout → P3-D3 initiation →
P3-D4 confirmation serveur → P3-D5 `OrderPaid` → **P4-C0 Queue & Mail Secret
Safety** (bloque tout le reste) → P4-C1 émission → P4-C2 révocation refund
(**avant** activation de P4-C3) → P4-C3 job de remise → P4-C4 autorisation →
P4-C5 remise HTTP → P4-C6 opérations.

Garde-fous inchangés : une feature à la fois, la BDD avant la logique, arrêt
obligatoire pour validation humaine avant toute migration d'une nouvelle phase,
aucun push direct sur `main`.

## ⚠️ POINTS D'ATTENTION

- ✅ **`.context/skills/SECURITE_TELECHARGEMENT.md` est réaligné sur D-035/D-036.**
  L'ancien UPDATE direct du compteur, `grant_id` inexistant et exemple
  `Storage::download()` ont été retirés. Le guide décrit désormais fragment →
  Bearer POST → cookie de tentative, G5/G6, HEAD/Range, stream privé,
  réconciliation, rate limiting et observabilité sans secret.
- ✅ **P4-C0 ferme la frontière queue/mail** : Redis et failed jobs fichier dans
  `.env.example`, job explicitement `afterCommit`, payload Laravel réel
  introspecté (`order_id` seul), Mailable non-queueable/non-sérialisable et
  `MAIL_MAILER=log` refusé avant toute émission.
- ✅ **P4-C2 satisfait G4** : un remboursement total passe l'Order à `refunded`
  et révoque tous ses grants actifs dans la même transaction ; un partiel les
  conserve.
- ⚠️ `coupons.redemptions_count` n'est maintenu par **aucun trigger** : plafond
  global et plafond client sont 100 % applicatifs, sous `FOR UPDATE` (gate P3-D4).
- ✅ Les anciens `DOWNLOAD_LINK_TTL_HOURS` / `DOWNLOAD_MAX_PER_GRANT` non
  autoritatifs ont été retirés de `.env.example`. P4-C utilise exclusivement les
  valeurs bornées `DELIVERY_GRANT_TTL_MINUTES` /
  `DELIVERY_GRANT_MAX_DOWNLOADS` via `DeliveryConfig`.
- 🚨 **Legacy** : deux mots de passe en clair étaient dans l'historique git de l'ancien
  dépôt. Les scripts concernés sont neutralisés, mais les valeurs historiques doivent
  rester considérées compromises.
- Le legacy est archivé sous `legacy/` pour migration de contenu uniquement. Ne pas
  exécuter ce code PHP et ne pas servir ce dossier publiquement.
- `legacy/data/users.sqlite` peut exister localement pour audit/migration, mais reste
  ignoré par Git (`*.sqlite`).
- PHP/Composer ne sont pas installés sur le host Windows. Utiliser l'image :
  `docker build -f docker/php/Dockerfile -t digitrove-php:dev .`
- Redis DigiTrove est exposé sur le port hôte `6380` pour éviter le conflit avec un
  conteneur existant `8fi-redis` sur `6379`.
- Le schéma BDD v1 attend la validation finale de KingKouda avant P1.
- Multi-devises validé : `currency` obligatoire sur les montants, montants en
  `BIGINT` unités mineures. Pour P2, prix fixes par devise via `product_prices`
  (D-018) avec `currency VARCHAR(3)` contraint longueur 3 + majuscules (D-019) ;
  conversion automatique et taux de change reportés.
- Checkout invité validé : un visiteur peut acheter via `visitors` + e-mail sans
  créer de compte. Le compte reste fortement suggéré pour historique d'achat,
  promotions, annonces, avantages CRM et future affiliation.
- Affiliation future validée : compte obligatoire, rattachement à `users`, tables
  dédiées à prévoir plus tard (`affiliate_profiles`, `affiliate_links`,
  `referrals`, `affiliate_commissions`, `affiliate_payouts`). Ne pas migrer en P1.
- P1 reste strictement limité à `users`, `customer_profiles`, `visitors` + extension
  PostgreSQL `citext`.
- `git fsck --full` ne signale plus de `missing blob`; les `dangling tree` restants
  sont des objets non référencés et ne bloquent pas P1.
- La question ouverte du champ `usb` (produits legacy) n'est pas tranchée — voir
  `AUDIT_LEGACY.md`.
- Blocage précédent résolu : pas de push direct sur `origin/main`; la suite passe par
  la branche dédiée `p0-foundations-laravel13`.

---

## 📖 JOURNAL DES PASSATIONS (le plus récent en haut)

### 2026-08-04 — Codex (Clôture P6-A1.0 et Audit P6-A1.1)
- Validation post-merge PR #32 sur `77652f2` : P6-A1.0 **48/285**, P6-A0 **40/235**,
  suite complète **883/6273**, Pint **367**, 37 migrations, diff-check verts.
- Commit `7d53dc5` (clôture initiale incomplète) : `git add .` utilisé au lieu du
  staging explicite — déviation de protocole sans impact sur le contenu (4 docs) ;
  D-046 contenait « append-only / reconstruction » (contradictoire), un choix ouvert
  « executor ou dédié », et mentionnait un worker dans le périmètre A1.1.
  HANDOFF conservait une phrase obsolète disant que P6-A1.0 devait être reviewé.
- Commit correctif (présent) : D-046 complétée avec le contrat définitif (population,
  source financière, commandes gratuites, schéma exact, types monétaires, cycle de
  vie, autorité, ACL, découpage A1.1/A1.2/A1.3, matrice de tests). HANDOFF corrigé.
  DigiTrove_Schema_BDD_v1.md mis à jour avec le contrat futur P6-A1.1.
  Staging explicite fichier par fichier.
- Aucune branche, migration, rôle PG, code P6-A1.1 créés.


### 2026-07-24 — Codex (clôture post-merge P5-A0)
- [PR #26](https://github.com/mysterus44/DigiTrove/pull/26) mergée : head
  `8d9d8cc798e6a35ae74a36d1d9ae6a9d22bf171a`, merge
  `94a8c08c5a9d8448dd161665f69602d84715432b`, parents `9a2a8f1` +
  `8d9d8cc`, CI #32 success.
- Stable synchronisée `0/0`. PostgreSQL confirme `events` RANGE,
  `events_default`, append-only, zéro FK, rollups currency-safe et zéro DML
  analytique pour `digitrove_runtime`.
- Validation post-merge : P5-A0 **19/256**, P4-C **86/559**, P4-B **20/560**,
  P3-B **18/354**, Pint **235**, 32 migrations et `git diff --check` propre.
- D-037 conservée intégralement. Laisse à : P5-A1 first-party, sans P5-A2/P6/P7.

### 2026-07-24 — Codex (P5-A0 Analytics Schema Foundation)
- Clôture P4-C4/C6 enregistrée sur la stable par `9a2a8f1`, puis poussée vers
  `origin/p0-foundations-laravel13`. Branche P5-A0 créée depuis ce commit.
- P5-A0 : migrations `000014`/`000015`/`000016`, parent `events` RANGE avec
  partition DEFAULT, append-only, sessions molles et trois rollups journaliers
  currency-safe. Cinq modèles et cinq factories structurels.
- Frontière fail-closed : aucune FK analytique, aucune ingestion/API/service/job,
  aucun droit `PUBLIC` ou `digitrove_runtime`; chaque partition future devra
  recevoir une révocation explicite. Aucun GIN sans contrat de requête.
- Validation : 32 migrations appliquées; P5-A0 **19/256**; P4-C **86/559**;
  P4-B **20/560**; P3-D4 **52/138**; P3-B **18/354**; catalogue **12/99**;
  suite complète **642/4779**; Pint **235**; rollbacks isolés verts;
  `git diff --check` propre; aucune base temporaire résiduelle.
- Décision : **D-037**. Laisse à : revue/CI/merge humain de P5-A0, puis plan
  P5-A1 dans une nouvelle exécution. P5-A1, P6 et P7 non commencés.

### 2026-07-22 — Claude Code (clôture post-merge P3-D2 + hotfix temporel P3-D1)
- **Deux merges prouvés.** P3-D2 : `4c691864`, parents `5d07abad` + `ef758bbb`
  (CI #22). Hotfix : `0854a393`, parents `4c691864` + `0d6e95d9` (CI #23). Les
  trois SHA confirmés ancêtres de la stable — aucun squash, aucun rebase, aucun
  commit fonctionnel perdu, le hotfix bien **postérieur** au merge P3-D2.
- **Synchronisation** : le `git pull --ff-only` a échoué sur un timeout réseau,
  mais le `git fetch` initial avait déjà rapatrié les objets ; fast-forward
  effectué hors réseau via `git merge --ff-only origin/…`, qui ne peut par
  construction créer aucun commit de merge. Stable locale = distante =
  `0854a393`, **0/0**.
- **Périmètres** : P3-D2 = **13 fichiers**, aucune migration, aucune route,
  contrôleur, Payment, CouponRedemption, event, listener, job, DownloadGrant,
  P3-D3, P4-C ni P5. Hotfix = **exclusivement**
  `tests/Feature/P3D1PricingKernelDatabaseTest.php` : référence figée conservée
  et déclarée **avant** le coupon, `starts_at`/`ends_at` centrés sur elle, plus
  aucune dépendance à `now()`, assertions de déterminisme inchangées.
- **Validations en isolation, séquentielles** : test temporel ciblé **1/2**,
  P3-D1 **48/167**, P3-D2 **66/323**, P4-B **20/620**, suite complète
  **399/3599**, Pint **138**, `git diff --check` propre, **29 migrations**,
  aucune `000014`, bases = `digitrove` + `digitrove_testing` seulement, worktree
  inchangé après les tests. Aucun `CouponNotStarted`.
- **Concurrence P3-D2 confirmée** sous les vraies identités : seed `digitrove`,
  A et B `digitrove_runtime`, `TEMP` refusé pour A et B ; soft-delete concurrent
  d'un composant **`55P03`**, deux checkouts du même Cart **`55P03`**, seconde
  Order du même Cart **`23505`** sur `orders_cart_id_unique`. **Aucun `42501`**,
  aucun élargissement d'ACL.
- **Leçon consignée** : une suite mélangeant une factory relative à `now()` et
  une référence figée porte la même bombe à retardement. Une vérification ciblée
  des suites P3A/P3B/P3C reste souhaitable avant P3-D3.
- Aucun code métier ni test modifié pendant cette clôture ; seuls les documents
  de suivi. Aucun P3-D3 commencé.
  Laisse à : **planifier `P3-D3 — Payment Initiation`**.

### 2026-07-21 — Claude Code (finalisation P3-D2 avant publication, D-032)
- Garde-fous : branche `p3-d2-checkout-order-transaction` à `c90c3383`, worktree
  propre, **distante absente** (jamais publiée), base `5d07abad`, `origin/main`
  intact, `D-032` libre. Aucun rebase, aucun amend des deux commits existants.
- **Défaut 1 — 30 minutes codées en dur.** `CART_TTL_MINUTES = 30` dans
  `OrderService` était une décision commerciale implicite ; `orders.expires_at`
  est `NOT NULL` sans DEFAULT et D-024 ne donnait les 30 min que comme
  recommandation. Corrigé par `config/checkout.php` +
  `CHECKOUT_PENDING_TTL_MINUTES` dans `.env.example`. Validation stricte
  (`is_int` hors booléen, ou `/\A[1-9][0-9]*\z/`), plafond 525 600, refus
  **avant toute écriture** classé `IntegrityFailure` (incident serveur, jamais
  faute client). 13 valeurs invalides couvertes. Le rejeu **conserve**
  l'`expires_at` d'origine même si la config a changé entre-temps.
- **Défaut 2 — retry `order_number` dans une transaction avortée.** Prouvé
  empiriquement sous `digitrove_runtime` sur la table `orders` réelle : après
  `23505 orders_order_number_unique`, toute commande suivante de la même
  transaction reçoit *« current transaction is aborted »* (**25P02**) — le
  `try/catch` sans savepoint était donc **inopérant** et dégradait en
  `IntegrityFailure` trompeur. La même sonde avec `SAVEPOINT` /
  `ROLLBACK TO SAVEPOINT` réussit la 2ᵉ tentative (2 lignes visibles).
  Corrigé par une **transaction Laravel imbriquée** (vrai savepoint), 3 essais,
  verrou du Cart préservé, seule `orders_order_number_unique` retentée.
- **Primitive extraite** : `App\Support\OrderNumberGenerator`, non `final` et
  résolue par le conteneur — c'est ce qui permet d'exercer la collision **de
  bout en bout** sans ajouter de callback de test à `checkout()`. Sous
  `app/Support`, donc **l'allowlist P4-B reste inchangée**.
- **Idempotence auditée, inchangée** : clé brute jamais stockée/loguée/exposée
  (test dédié), digest 64 hex, SHA-256 documenté comme identifiant
  d'idempotence et non comme authentification.
- Validation : P3-D2 **66/308** (était 47/171), suite complète **399/3584**
  (était 380/3446), Pint **138**, `git diff --check` propre, 29 migrations
  inchangées. Non-régressions P3-D2 toutes confirmées.
- **Branche volontairement non publiée** (consigne de mission) ; 4 commits
  locaux. Aucun P3-D3, P4-C ni P5.
  Laisse à : **publier, faire relire et merger P3-D2**.

### 2026-07-21 — Claude Code (P3-D2 Checkout Order Transaction implémenté)
- Garde-fous : stable `5d07abad` (0/0), worktree propre, `origin/main` intact,
  29 migrations, aucune branche `p3-d2-*`, `D-031` libre. Branche créée depuis la
  stable, **merge-base exact `5d07abad`**, sans rebase ni force-push.
- **TDD** : suite écrite d'abord, RED observé (classes `Checkout` absentes), puis
  implémentation minimale jusqu'au vert.
- **3 classes livrées, aucune migration** : `OrderService`, `CheckoutException`,
  `CheckoutRefusalReason` (enum fermé de 16 refus).
- **Verrouillage** `carts` → `products` du panier (`withTrashed`, `id` ↑) →
  `product_bundles` → **produits enfants** (`id` ↑). Le verrou des enfants est
  ce qui rend **Q1=C** applicable ; prouvé par `55P03` sur deux connexions.
- **Q1 = C** : composant soft-deleted ou bundle imbriqué ⇒
  `BundleComponentUnavailable`, **rien n'est écrit** ; bundle sans composant ⇒
  `BundleEmpty`. Aucun filtrage silencieux, aucun snapshot partiel.
- **Q2 = B** : Cart → `converted` dans la même transaction, après toutes les
  écritures ; jamais reconverti sur rejeu ; reste `active` sur rollback.
- **Idempotence** : clé brute jamais persistée ni loguée (test dédié), digest
  SHA-256 seul ; rejeu résolu **avant** toute règle d'état ; égalité par
  comparaison `cart_id`/acteur/`currency`/`coupon_id`/`customer_email` ;
  `CartAlreadyCheckedOut` pour une autre clé sur le même panier ; chaque `23505`
  traduit par contrainte (`cart_id_unique`, `idempotency_hash_unique`,
  `order_number_unique`), **jamais globalement en « rejeu »** ; retry borné à 3
  pour la seule collision d'`order_number`.
- **Propriété** : refus **uniforme** `CartUnavailable` pour un panier inexistant
  comme pour celui d'autrui (anti-énumération de `public_id`).
- **Snapshot bundle** : un seul `INSERT … SELECT` par bundle, sans filtre, avec
  **comparaison du nombre de lignes** au compte mesuré sous verrou →
  `BundleSnapshotMismatch` + rollback total.
- **Correction de méthode en cours de gate** : ma première version des tests de
  concurrence passait un callback au service — un **seam de test dans du code de
  production**, anti-pattern. Remplacé par le pattern éprouvé du projet (base
  jetable `PhaseMigrationHarness` + deux connexions PDO réelles, comme P4-A1),
  sans aucune trace dans le service.
- **Allowlist P4-B élargie de exactement 3 chemins** `Checkout/*`, sans
  wildcard ; test prouvant que `Checkout/UnexpectedService.php` reste refusé.
- Validation : P3-D2 **47/171**, P4-B **20/619**, P3-D1 Unit 94/117 et Feature
  48/167, P3B 18/357, P3A 15/139, Catalogue 12/111, suite complète **380/3446**,
  Pint **136**, `git diff --check` propre, 29 migrations inchangées.
- Aucun Payment, CouponRedemption, event, listener, job, notification, mail,
  route, contrôleur, Request, config, migration, P3-D3, P4-C ni P5.
  Laisse à : **revue et merge de la PR P3-D2**, puis clôture post-merge.

### 2026-07-21 — Claude Code (clôture post-merge P3-D1.1)
- **Merge P3-D1.1 prouvé** : [PR #18](https://github.com/mysterus44/DigiTrove/pull/18),
  merge `0e18d69d7216b87118bc024e697cc5629c561846`, parents
  `78f475e750b0e060fd38c44d6733844807683ff9` (base) +
  `6349fc19b70858ef4bc0186e4adf2687742fd8c4` (head), head confirmé ancêtre de la
  stable, CI run #19 `success`. Stable synchronisée en fast-forward, 0/0.
- **Périmètre mergé audité** : **exactement 12 fichiers, tous modifiés**, aucun
  ajout ni suppression — 5 classes (`Money`, `PricedLine`, `PricedQuote`,
  `CouponSnapshot`, `DiscountAllocator`), 2 suites de tests, 5 documents. Aucune
  migration, route, contrôleur, Request, modèle, factory, config, `OrderService`,
  checkout, paiement, event, listener, job, P4-C ni P5.
- **A1→A4 revérifiés par relecture du code sur la stable** : `CURRENCY_PATTERN`
  vaut `'/\A[A-Z]{3}\z/'` et `Money::assertValidCurrency()` est réutilisée par
  `PricedQuote` ; `P4B_ALLOWED_SERVICE_FILES` liste les 7 fichiers `Pricing/*`
  avec comparaison récursive sur chemins normalisés (`array_diff`), `app/Jobs` et
  `app/Listeners` prouvés absents, test synthétique couvrant `GrantIssuer`,
  `DeliveryManager`, `StreamManager`, `RateLimiter`, `DownloadService` et
  `Pricing/UnexpectedService.php` ; les trois DTO lèvent sur chaque invariant via
  `IntegerMath`, `taxMinor !== 0` refusé, snapshot coupon ⟺ remise positive ;
  `DiscountAllocator` refuse `line_id` dupliqué et ids non positifs.
- **Auto-audit rejoué sur la stable** : `"XOF\n"`, `"XOF\r\n"`, `"\nXOF"`,
  `"xof"`, `"XOFF"` refusés et `'XOF'` accepté ; les 7 constructions incohérentes
  refusées ; duplication de `line_id` refusée ; Hamilton inchangé (999 lignes /
  D=998 → `sum=998, max=1, min=0`) ; ligne gratuite jamais remisée ; immutabilité
  profonde intacte (4 tentatives de mutation, dont imbriquée, échouent). Aucun
  fichier de sonde laissé.
- **Validation post-merge** : Unit **94/117**, Feature P3-D1 **48/167**, P4-B
  **20/616**, P3A **15/139**, P3B **18/357**, Catalogue **12/111** ; suite
  complète **333/3272** ; Pint **132** ; `git diff --check` propre ;
  **29 migrations inchangées**, aucune `000014` ; PostgreSQL 16 et Redis 7
  `healthy` ; bases = `digitrove` + `digitrove_testing` seulement ; `app/Services`
  ne contient que les 7 fichiers `Pricing/*`.
- **Nettoyage** : branches locales `p3-d1-post-merge-hardening` (était `6349fc1`)
  et `p3-d1-pricing-kernel` (était `95ab562`) supprimées après preuve du merge ;
  distantes conservées à `6349fc19` et `95ab5627` ; `origin/main` toujours
  `11130f4d`. Aucun nouveau merge, aucune correction de code.
- Aucune décision nouvelle. Aucun code P3-D2, P4-C ni P5 créé.
  Laisse à : **planifier `P3-D2 — Checkout Order Transaction`** (non commencé).

### 2026-07-21 — Claude Code (audit post-merge P3-D1 + hardening P3-D1.1)
- **Merge P3-D1 prouvé** : [PR #17](https://github.com/mysterus44/DigiTrove/pull/17),
  merge `78f475e750b0e060fd38c44d6733844807683ff9`, parents `ba48cce1` (base) +
  `95ab5627` (head), head confirmé ancêtre de la stable, distante conservée à
  `95ab5627`, `origin/main` intact `11130f4d`. Stable synchronisée en fast-forward,
  0/0. Périmètre : **exactement 17 fichiers**, aucune migration, route,
  contrôleur, Request, config, modèle, factory, secret, P4-C ni P5.
- **Audit contradictoire post-merge** (le merge ayant précédé la revue) conduit
  par **sondes exécutées, jamais par raisonnement** — méthode qui a payé : trois
  de mes propres affirmations de la mission précédente étaient partiellement
  fausses.
- **Quatre anomalies démontrées puis fermées** sur `p3-d1-post-merge-hardening`
  (créée depuis `78f475e7`, merge-base exact) :
  **A1** `Money::of(100, "XOF\n")` accepté — le `$` de PCRE matche avant un saut
  de ligne final ; corrigé par `/\A[A-Z]{3}\z/` + `Money::assertValidCurrency()`
  source unique. **A2** garde-fou P4-B affaibli par mon propre rétrécissement :
  sonde avec fichiers réels `Services/Fulfilment/{GrantIssuer,DeliveryManager}.php`
  → test **passant** ; remplacé par une **allowlist fail-closed** des 7 fichiers
  autorisés + test synthétique sans créer de fichier. **A3** DTO de pricing sans
  aucun invariant (remise > sous-total, `lines` vide, snapshot coupon avec remise
  nulle, devise `'zzz'` acceptés) ; invariants ajoutés aux constructeurs en
  miroir des CHECK `orders`/`order_items`. **A4** `line_id` dupliqué écrasé en
  silence — `allocate(10, …)` retournait une somme de 5 sans exception ; refus
  explicite ajouté.
- **Portée honnête des anomalies** : toutes de **défense en profondeur**. Le
  calcul de prix était correct et **aucune corruption monétaire n'était possible**
  via `PricingService::quote()` ; A1 était fail-closed en aval et A4 inatteignable
  (`cart_items.id` est une PK). A2 était la plus sérieuse, et de ma responsabilité
  directe.
- **Vérifié inchangé** : `IntegerMath` (17 cas limites, `PHP_INT_MIN × -1` et
  `-1 × PHP_INT_MIN` refusés, aucun montant valide refusé à tort) ; immutabilité
  profonde de `PricedQuote` (4 tentatives de mutation, dont imbriquée, échouent ;
  copie de tableau sans aliasing) ; Hamilton (999 lignes / D=998 → `sum=998,
  max=1, min=0`, prouvant ≤ +1 par ligne et O(n)) ; ligne gratuite jamais
  remisée ; **aucune politique métier modifiée**.
- **Auto-audit post-correctif** : A1 → 7 variantes refusées ; A2 → sonde réelle
  désormais refusée, l'allowlist nommant les deux intrus ; A3 → aucune
  incohérence constructible ; A4 → duplication refusée avant allocation. Aucun
  faux positif sur les 7 classes autorisées. Sondes supprimées, worktree propre,
  aucun résidu.
- Validation : Unit **94/117** (était 36/55), Feature P3-D1 **48/167** inchangée,
  P4-B **20/616** (était 19/612), P3A 15/139, P3B 18/357, Catalogue 12/111 ;
  suite complète **333/3272** (était 274/3206) ; Pint **132** ; `git diff --check`
  propre ; **29 migrations inchangées**, aucune `000014` ; PostgreSQL et Redis
  healthy ; aucune base temporaire résiduelle.
- Aucun code P3-D2, P4-C ni P5 créé. `main` et la stable non modifiés ; branche
  locale et distante `p3-d1-pricing-kernel` conservées.
  Laisse à : **revue et merge de la PR P3-D1.1**, puis clôture. Ne pas commencer
  P3-D2 avant ce merge.

### 2026-07-21 — Claude Code (P3-D1 Pricing & Quote Kernel implémenté)
- Garde-fous : stable locale = distante `ba48cce1` (0/0), worktree propre,
  `origin/main` intact `11130f4d`, 29 migrations, aucune `000014`, aucune branche
  `p3-d1-*` préexistante. Branche `p3-d1-pricing-kernel` créée depuis la stable,
  **merge-base exact `ba48cce1`**, sans rebase ni force-push.
- **Audit du schéma réel avant code** — trois constats qui ont façonné le gate :
  `cart_items` ne porte **aucun prix** (`id, cart_id, product_id, quantity`) ;
  `product_prices` est unique sur `(product_id, currency)` avec `is_active` ; et
  `validate_order_items_consistency` exige au COMMIT
  `SUM(line_discount_minor) = orders.discount_minor` **exactement**, ce qui rend
  l'allocation entière de la remise obligatoire et non optionnelle.
- **TDD strict** : suites écrites d'abord et **échec observé** avant toute ligne
  de production (Unit 35 échecs par classe absente, puis Feature 42 échecs), puis
  implémentation minimale jusqu'au vert.
- **9 classes livrées, aucune migration** : `App\Support\IntegerMath` (garde
  d'overflow — PHP promeut silencieusement un entier débordant en float),
  `App\Support\Money` (`final readonly`, `int` seul, devise `^[A-Z]{3}$`
  **refusée** si non canonique plutôt que normalisée, aucune conversion), et
  `App\Services\Pricing\{PricingService, DiscountAllocator, PricedQuote,
  PricedLine, CouponSnapshot, PricingException, PricingRefusalReason}`.
- **Hamilton** implémenté exactement : `numeratorᵢ = D × sᵢ`,
  `baseᵢ = intdiv(numeratorᵢ, S)`, `remainderᵢ = numeratorᵢ % S`, puis
  distribution du reste unité par unité selon **résidu ↓ → `product_id` ↑ → id de
  ligne ↑**. Garde défensive : une ligne n'absorbe jamais plus que son propre
  sous-total (protège une ligne gratuite d'un résidu nul gagnant un départage).
- **Refus explicites** (enum fermé `PricingRefusalReason`) : panier vide, produit
  indisponible, prix absent dans la devise, coupon inactif / pas encore actif /
  expiré, règle de devise manquante pour un coupon fixe, minimum non atteint,
  **coupon scopé sans ligne éligible** (Q3 = A, jamais de retrait silencieux),
  remise résultante nulle.
- **Zéro écriture prouvée deux fois** : capture du journal SQL (aucun
  `insert|update|delete|truncate|merge`, aucun `FOR UPDATE`) **et** comparaison
  octet à octet de `carts`/`cart_items`/`coupons`/`products`/`product_prices`
  avant/après. `redemptions_count` inchangé ; 0 Order, 0 CouponRedemption,
  0 Payment, 0 DownloadGrant, 0 DownloadLog.
- **Absence de N+1 prouvée deux fois** : `Model::preventLazyLoading()` sur un
  panier de 5 lignes avec coupon scopé par catégorie, **et** comparaison de
  volumétrie 2 lignes vs 8 lignes → nombre de requêtes identique (aucun nombre
  fragile figé).
- **Auto-audit contradictoire** : prix client falsifié en mémoire, devise absente,
  prix inactif, coupon global sans règle, coupon scopé vide, produit multi-
  catégories, portées produit ET catégorie simultanées, collection mélangée, ids
  non séquentiels, quantité 100 000, prix à `PHP_INT_MAX`, basis points 10 000,
  montant fixe > sous-total, plafond < remise, bornes `starts_at`/`ends_at`
  exactes, résidus parfaitement égaux, produit gratuit avec coupon, panier vide,
  produit soft-deleted. Cinq vecteurs manquants ont été ajoutés en test après
  l'audit ; aucun n'a révélé de défaut fonctionnel.
- **Audit statique** : aucune occurrence **fonctionnelle** de `float`, `double`,
  `round(`, `ceil(`, `floor(`, `number_format(`, cast `(float)`, écriture BDD,
  verrou, route ou `request()` dans les fichiers du gate — les seules
  correspondances sont des commentaires.
- **Deux jugements conservateurs signalés pour la revue** (aucune règle explicite
  dans D-030, choix fail-closed retenus) : seul `status = 'published'` est
  vendable ; `min_order_minor` mesuré sur le panier entier, remise sur le
  sous-total éligible.
- **Adaptation historique** : le garde-fou de périmètre de `P4BDownloadLogsTest`
  assertait `app/Services` inexistant. Assertion **restreinte, pas supprimée** —
  `Listeners`/`Jobs` toujours prouvés absents et aucun namespace de service ne
  peut correspondre à `download|delivery|grant`. Suite P4-B 19/**612** (était 603).
- **`declare(strict_types=1)`** introduit sur les fichiers du gate (nouveauté dans
  le dépôt) : sans lui `Money::of(1.5, 'XOF')` serait coercé en `1`. Pint reste
  vert (preset `laravel`).
- Validation : Unit **36/55**, Feature **48/167**, suite complète **274/3206**
  (base 190/2975), Pint **132**, `git diff --check` propre, 29 migrations
  inchangées, P3A 15/139, P3B 18/357, Catalogue 12/111 verts.
- Aucune migration, route, contrôleur, Request, event, listener, job,
  notification, mail, config, paiement, checkout persistant, P4-C ni P5 créés.
  `main` et la stable non modifiés.
  Laisse à : **revue et merge de la PR P3-D1**, puis clôture post-merge.
  Ne pas commencer P3-D2 avant ce merge.

### 2026-07-21 — Claude Code (D-030 : roadmap applicative Commerce → Livraison)
- Garde-fous : stable locale = distante = `50d043ec` (0/0), worktree propre,
  `origin/main` intact `11130f4d`, `98441014`/`49692e25`/`6d23e546` confirmés
  ancêtres, 29 migrations, aucune `000014`, aucune branche applicative, un seul
  `git fetch --all --prune`. Numéro de décision vérifié libre : **D-030**.
- **Audit applicatif (mission P4-C0 précédente, verdict `DÉCISIONS COUCHE
  APPLICATIVE REQUISES`)** : couche applicative **intégralement absente** —
  `app/Services|Actions|Events|Listeners|Jobs|Notifications|Mail|Policies|Support|
  Http\Requests|Http\Middleware` n'existent pas ; `Http/Controllers` ne contient
  que la classe abstraite ; `routes/web.php` = page d'accueil ; `AppServiceProvider`
  et `withMiddleware()` vides. `OrderService`, `OrderPaid`, `IssueDownloadGrants`,
  `DownloadService`, `DownloadController` **absents, sans alias**.
- **Décisions humaines intégrées** : **Q1 = A renforcée**, **Q2 = B renforcée**,
  **Q3 = A** (détail complet en D-030).
- **Correction de nommage appliquée** : le gate de tarification n'est plus
  `P4-C1 — Pricing & Money kernel` mais **`P3-D1 — Pricing & Quote Kernel`**.
  Tarification / checkout / paiement = **P3-D1→P3-D5** ; livraison =
  **P4-C0→P4-C6**. Douze gates, douze branches futures, **aucune migration**.
- **Correction factuelle du rapport d'audit** : le driver d'échec de queue par
  défaut est **`database-uuids`** (`config/queue.php` L124, table `failed_jobs`),
  **pas `file`** — `file` n'est qu'un override de `.env.example`. Vérifié aussi :
  défaut `database` (L16), `after_commit = false` sur `database`/`beanstalkd`/
  `sqs`/`redis` (L44/L53/L64/L73), batching `job_batches` (L105-107). **Aucune
  migration `jobs`, `job_batches` ni `failed_jobs` n'existe.**
- **Gate préalable `P4-C0 — Queue & Mail Secret Safety`** ajouté : il doit être
  mergé avant tout job de livraison. Son sous-point « stockage des failed jobs »
  (`failed_jobs` en base / désactivés / autre stockage sûr) est **laissé
  explicitement OUVERT**, à trancher au gate sur le code réel — jamais tranché
  silencieusement ici.
- **Contrainte d'ordonnancement figée** : G4 étant différé et monté sur
  `download_grants` ET `orders`, **P4-C2 (révocation) doit être mergé avant
  l'activation réelle de P4-C3** ; P4-C1 peut vivre comme service non câblé ;
  aucun téléchargement public avant P4-C5.
- **Onze dettes consignées et rattachées à leur gate** (env DOWNLOAD non lues,
  queue/failed/batching sans tables, `after_commit=false`, `MAIL_MAILER=log`,
  tests en `sync`, enums DownloadLog absents, compteur coupon applicatif, pas de
  `Money`, G4/révocation, skill périmé).
- **`SECURITE_TELECHARGEMENT.md` déclaré partiellement périmé** ; non modifié ici
  (cinq documents autorisés) ; **réécriture obligatoire avant le gate P4-C4**.
- Validation : `php artisan test` **190/2975**, `./vendor/bin/pint --test`
  **121 fichiers**, `git diff --check` propre, **29 migrations inchangées**.
- **Mission strictement documentaire** : aucun fichier PHP, migration, test,
  route, contrôleur, service, repository, event, listener, job, notification,
  mailable, middleware, policy, config, Docker, CI, script SQL, rôle PostgreSQL,
  table, fonction, trigger ni `.env` créé ou modifié. Aucune branche. Aucun P5.
  Seuls les cinq documents autorisés sont modifiés.
  Laisse à : **implémenter `P3-D1 — Pricing & Quote Kernel`** sur la branche
  `p3-d1-pricing-kernel`.

### 2026-07-21 — Claude Code (clôture post-merge P4-B)
- **P4-B mergé** via [PR #16](https://github.com/mysterus44/DigiTrove/pull/16),
  merge `98441014` (parents `d7c53cf` + `49692e25`, sujet « Merge pull request #16
  from mysterus44/p4-b-download-logs »). `49692e25` confirmé ancêtre de la stable ;
  stable synchronisée en fast-forward à `98441014` (0/0).
- Périmètre mergé audité (`d7c53cf..98441014`) : **seule la migration `000013`**
  côté migrations (`000001`–`000012` inchangées, P4-B0 intact), plus modèle,
  factory, relation, suites de tests et 5 documents. Aucun endpoint, contrôleur,
  service, streaming, secret ni objet P5.
- Provisioning `php artisan db:provision-runtime-roles` rejoué **deux fois** :
  idempotent, aucun secret affiché. Identités prouvées : migrations
  `session_user=current_user=digitrove` ; métier
  `session_user=current_user=digitrove_runtime`. 29 migrations, `000012` avant
  `000013`, aucune `000014`.
- Introspection post-merge : `download_logs` **15 colonnes** (aucun `updated_at`),
  1 FK, 10 CHECK, 1 unique, 6 index ; **G5 possédée par
  `digitrove_download_executor`**, `SECURITY DEFINER`, `search_path=pg_catalog,
  public, pg_temp`, ACL `{executor=X/executor}` et objets tous qualifiés
  (`public.download_grants`/`orders`/`order_items`/`product_files`) ; **G6**
  possédée par le migrateur, SECURITY INVOKER, sans EXECUTE PUBLIC/runtime ;
  **G2 unique** avec autorité `current_user = 'digitrove_download_executor'` +
  profondeur secondaire, protections P4-A2/P4-A2.1 préservées ; **6 fonctions /
  7 triggers P4**, G4 toujours différé.
- ACL : runtime SELECT/INSERT/UPDATE/DELETE sur `download_logs` mais **sans**
  TRIGGER/TRUNCATE, **sans** `downloads_count`, **sans** TEMP ni CREATE ;
  exécuteur sans CREATE permanent, limité à `downloads_count`/`updated_at` du
  grant plus le verrou `orders`, et sans droit sur `download_logs`.
- Validation : **P4-B 19/603**, **suite complète 190/2975**, **Pint 121**,
  `git diff --check` propre, zéro base ou rôle temporaire résiduel.
- Clôture : branche locale `p4-b-download-logs` supprimée (était `49692e2`),
  distante conservée à `49692e25` ; `origin/main` toujours `11130f4` ; aucun
  nouveau merge, aucune correction de code.
  Laisse à : choisir la prochaine étape dans le roadmap (non commencée).

### 2026-07-20 — Claude Code (reprise P4-B après P4-B0)
- Garde-fous : stable `d7c53cf` (locale = distante, 0/0), P4-B `8cf24a8` (locale =
  distante), `origin/main` `11130f4`, merge-base `ccf9c383` (base documentaire
  connue, aucun commit inconnu).
- **Stable intégrée par MERGE** (`git merge --no-ff`, jamais rebase) →
  `fca10d9` (parents `8cf24a8` + `d7c53cf`). Conflits sur les 5 documents
  seulement (résolus en gardant la version stable post-P4-B0, statut
  `P4-B0 TERMINÉ ET MERGÉ` préservé) ; les 11 tests historiques ont fusionné
  automatiquement. Migrations `000001`–`000011` identiques à la stable.
- `git mv` : `000012_create_download_logs_table.php` → **`000013`**. Ordre final
  `000011` P4-A2.1 → `000012` P4-B0 → `000013` P4-B ; 29 migrations ; aucune
  seconde `000012`.
- **Préconditions fail-closed** ajoutées à `000013` (rôles, attributs, membership
  SET, runtime sans TEMP/CREATE/`downloads_count`, défauts globaux de fonctions) :
  rien n'est créé si la frontière P4-B0 n'est pas en force.
- **G5 → `SECURITY DEFINER` possédée par `digitrove_download_executor`** via la
  danse prouvée ; `search_path` épinglé `pg_catalog, public, pg_temp` ; objets
  qualifiés ; EXECUTE temporaire au migrateur uniquement pour attacher le trigger,
  puis révoqué sous l'identité propriétaire. ACL finale
  `{digitrove_download_executor=X/…}`, runtime et PUBLIC sans EXECUTE.
- **G2 : autorité non forgeable** — l'exact `+1` exige désormais
  `current_user = 'digitrove_download_executor'` ET `pg_trigger_depth() > 1`.
  **Preuve décisive** : un trigger forgé par le **propriétaire superuser** à
  profondeur 2 est refusé en 23514 (l'ancienne G2 l'aurait accepté) ; le runtime
  est arrêté encore plus tôt, en 42501.
- **Finding d'implémentation** : `SELECT … FOR UPDATE OF orders` exige un
  privilège de verrou — SELECT seul est refusé (mesuré), un `UPDATE (updated_at)`
  de colonne suffit. La migration accorde donc ce grant minimal à l'exécuteur ;
  G5 n'écrit jamais `orders`.
- Adaptations tests : suite P4-B sous runtime (chemins métier et offensifs), les
  sondes internes qui doivent atteindre G2/G1 seedent leur propre grant dans une
  transaction propriétaire (isolation de visibilité entre connexions) ; le test de
  rollback vide s'arrête désormais à `000012` (frontière P4-B0) ; compteurs
  historiques 28 → 29 ; message G2 `trigger` → `executor` dans P4-A2/P4-A2.1 ; le
  premier test P4-B0 reformulé (sur cette branche `download_logs` existe
  légitimement via `000013`, la preuve d'absence restant dans son rollback isolé).
- Validation réelle : **P4-B 19 tests / 603 assertions** ; **suite complète
  190/2975** ; **Pint 121** ; 29 migrations ; introspection conforme (G5 exécuteur
  + SECURITY DEFINER + search_path, G6 migrateur, G2 unique avec identité et
  profondeur, 6 fonctions / 7 triggers P4, G4 toujours différé, runtime sans
  compteur/TRIGGER/TRUNCATE, exécuteur sans CREATE, aucun P5) ; zéro base
  temporaire résiduelle.
- Aucun endpoint, route, contrôleur, service, streaming, listener, job, e-mail ni
  code P5. Migrations `000001`–`000012` inchangées ; `main` et la stable non
  modifiés.
  Laisse à : review + merge de la PR P4-B, puis clôture post-merge.

### 2026-07-20 — Claude Code (clôture post-merge P4-B0)
- **P4-B0 mergé** via [PR #15](https://github.com/mysterus44/DigiTrove/pull/15),
  merge `6d23e546` (parents `a3eac5e` + `9b69f192`, sujet « Merge pull request #15
  from mysterus44/p4-b0-postgresql-runtime-privileges »). `9b69f192` confirmé
  ancêtre de la stable ; stable synchronisée en fast-forward à `6d23e546` (0/0).
- Périmètre mergé audité (`a3eac5e..6d23e546`) : seule la migration `000012`
  ajoutée côté migrations (`000001`–`000011` intactes) ; migration ACL,
  provisioning, commande Artisan, double connexion, config test, CI, harness,
  suite P4-B0, adaptations P2/P3/P4-A + 5 documents. Aucune table `download_logs`,
  aucune migration `000013`, aucun G5/G6 réel (`SECURITY DEFINER` uniquement en
  commentaires de `000012`, 0 `CREATE FUNCTION`/`CREATE TRIGGER`), aucun
  endpoint/service/streaming/P5, aucun secret.
- Provisioning `php artisan db:provision-runtime-roles` rejoué **deux fois** :
  idempotent, aucun secret affiché. Identités prouvées : migrations
  `session_user=current_user=digitrove` ; requêtes métier
  `session_user=current_user=digitrove_runtime`. 28 migrations, `download_logs`
  absent.
- Introspection : `digitrove_runtime` non-superuser NOINHERIT (CONNECT+USAGE oui ;
  TEMP/CREATE/CREATE FUNCTION/TRIGGER non ; UPDATE table-level et `downloads_count`
  non ; `revoked_at`/`revoked_reason_code`/SELECT/INSERT oui ; DELETE non ; EXECUTE
  refusé sur les **26** fonctions trigger de `public`) ; `digitrove_download_
  executor` NOLOGIN, sans CREATE, SELECT orders/order_items/download_grants/
  product_files + UPDATE `downloads_count`/`updated_at` seulement ; membership
  unique `digitrove → executor` (SET oui, INHERIT/ADMIN non). `pg_default_acl` :
  défauts FONCTIONS **globaux (ns=0)** pour migrateur ET exécuteur (sans PUBLIC),
  défauts TABLES/SEQUENCES (ns=2200) pour le runtime.
- Validation : suite P4-B0 **13/84** ; suite complète **171/2385** ; Pint **117** ;
  zéro base/rôle de sonde résiduel ; 3 rôles cluster attendus présents. (Un premier
  run complet avait affiché 1 échec transitoire sur un test de concurrence —
  contention de verrou provoquée par mes requêtes d'introspection lancées en
  parallèle ; le run isolé suivant est intégralement vert. Aucun code modifié.)
- Clôture : branche locale `p4-b0-postgresql-runtime-privileges` supprimée
  (`git branch -d`, était `9b69f19`) ; distante conservée à `9b69f192` ; P4-B
  toujours à `8cf24a8` (local et distant) ; `origin/main` toujours `11130f4`.
  Aucun nouveau merge, aucune correction de code.
  Laisse à : reprise de P4-B (rebase, renumérotation `000013`, G5
  `SECURITY DEFINER`, autorité `current_user` dans G2).


### 2026-07-20 — Claude Code (P4-B0 frontière de privilèges implémentée)
- Branche `p4-b0-postgresql-runtime-privileges` créée depuis la stable `a3eac5e`
  (merge-base vérifié) ; `p4-b-download-logs` laissée intacte à `8cf24a8`.
- **Gate de faisabilité passé AVANT tout code** (base temporaire nettoyée,
  migrateur non-superuser propriétaire) : la danse `GRANT CREATE` temporaire →
  `SET LOCAL ROLE executor` → `CREATE FUNCTION … SECURITY DEFINER` → `RESET ROLE`
  → `REVOKE CREATE` donne la propriété de G5 à l'exécuteur sans lui laisser de
  CREATE permanent ; et un trigger `SECURITY DEFINER` **se déclenche même sans
  EXECUTE pour le rôle déclencheur**, avec `session_user=digitrove_runtime` et
  `current_user=digitrove_download_executor` à l'intérieur.
- Trois findings : (1) un `REVOKE EXECUTE … FROM PUBLIC` par fonction doit venir du
  **propriétaire**, sinon no-op silencieux ; (2) l'exécuteur exige **SELECT** en
  plus de UPDATE (`SET col = col + 1` lit la colonne) ; (3) default privileges des
  FONCTIONS (PG 16.14) : la **forme GLOBALE** `ALTER DEFAULT PRIVILEGES FOR ROLE r
  REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC` FONCTIONNE (nouvelle fonction née sans
  EXECUTE PUBLIC) ; la forme **`IN SCHEMA public`** ne retire PAS le privilège
  intégré global (aucune entrée `pg_default_acl`). Les défauts suivent le **rôle
  créateur** (pas d'héritage) → migrateur ET exécuteur (via `SET ROLE`) reçoivent
  chacun le leur ; les fonctions EXISTANTES gardent le REVOKE explicite. Corolaire :
  PUBLIC privé d'EXECUTE, attacher un trigger à G5 exige `GRANT EXECUTE … TO
  digitrove` (sinon `CREATE TRIGGER` échoue pour un migrateur non-superuser).
- Livré : `docker/postgres/provision-runtime-roles.sql` idempotent (mot de passe
  par GUC de session paramétré, jamais en clair), `php artisan
  db:provision-runtime-roles`, migration ACL `000012` fail-closed (rôles,
  attributs, memberships, chemin SET du migrateur), fermeture TEMP/CREATE/EXECUTE,
  DML runtime en verbes explicites, `download_grants` sans UPDATE table-level et
  sans `downloads_count`, grants minimaux de l'exécuteur, **défauts globaux REVOKE
  EXECUTE pour migrateur + exécuteur** (fonctions futures nées verrouillées),
  `down()` qui ne réouvre jamais la frontière (défauts de fonctions conservés).
- Double connexion `pgsql` (runtime) / `pgsql_migration` (propriétaire) ;
  `RefreshesDatabaseAsMigrator` fait migrer sous le propriétaire tout en gardant
  les requêtes sous le runtime ; `RefreshesDatabaseAsOwner` épingle P4-A2/P4-A2.1
  au propriétaire (leurs sondes visent des colonnes que le runtime ne peut pas
  atteindre — sinon 42501 masquerait 23514 et la couverture trigger serait
  perdue) ; `PhaseMigrationHarness` administre/migre en propriétaire et expose
  `runtimePdo()`.
- Validation : suite P4-B0 **13/84** sous le vrai rôle runtime (tous les vecteurs
  de contournement historiques refusés en 42501 sans incrément, chemin
  `SECURITY DEFINER` prouvé en base isolée, défauts globaux prouvés — fonction
  future née sans EXECUTE PUBLIC pour migrateur ET exécuteur —, rollback ACL sans
  réouverture y compris sur les défauts de fonctions) ;
  suite complète **171/2381** ; Pint **117** ; 28 migrations ; zéro base
  temporaire résiduelle. CI : création de la base de test, provisioning des rôles,
  puis étape d'assertion de frontière qui échoue à la moindre régression d'ACL.
- Aucune table `download_logs`, aucune fonction G5/G6, aucune migration `000013`,
  aucun endpoint/route/service/streaming/P5.
  Laisse à : merge de la PR P4-B0, puis rebasage/correction de P4-B.

### 2026-07-19 — Claude Code (audit offensif P4-B + plan P4-B0, D-029.6)
- P4-B implémenté et poussé sur `p4-b-download-logs` (`8cf24a8`) plus tôt dans la
  session (19/599, suite 177/2885, Pint 116). Avant PR, audit offensif de
  l'autorité G2/G5 demandé.
- **Vulnérabilité prouvée** (transactions réelles, ROLLBACK, rôle `digitrove`
  superuser confirmé) : G2 autorisait l'incrément dès `pg_trigger_depth() > 1`, qui
  démontre l'imbrication mais jamais l'origine. Sondes : UPDATE direct (profondeur
  1) refusé 23514 ; trigger TEMP BEFORE, trigger TEMP AFTER et trigger PERMANENT
  sur `products` (profondeur 2) → `downloads_count` 0→1 avec **0 `download_logs`** ;
  G5 légitime → +1 avec 1 log. G5 est aujourd'hui la seule fonction qui met à jour
  le compteur, mais c'est incident, non contraint.
- ACL PG16.14 mesurées (rôle non privilégié frais) : TEMP accordé via PUBLIC
  (datacl NULL) = le vecteur ; CREATE sur `public` refusé (défaut PG15+) ; EXECUTE
  des fonctions accordé à PUBLIC ; `REVOKE TEMPORARY … FROM PUBLIC` ramène TEMP à
  refusé. PR P4-B **non ouverte**.
- Décision **D-029.6** (option A renforcée validée par KingKouda) : gate préalable
  **P4-B0** — 3 rôles (`digitrove` migrateur/propriétaire, `digitrove_runtime`
  restreint, `digitrove_download_executor` NOLOGIN), G5 `SECURITY DEFINER`
  (propriétaire exécuteur, search_path épinglé, objets qualifiés), G2 vérifiant
  `current_user = digitrove_download_executor` comme preuve d'origine principale
  (profondeur = défense secondaire), fermeture TEMP/CREATE/EXECUTE + ALTER DEFAULT
  PRIVILEGES, privilèges runtime au niveau colonne seulement (jamais
  `downloads_count`, jamais UPDATE table-level), double connexion Laravel
  (`pgsql` runtime + `pgsql_migration` migrateur), provisioning cluster par script
  idempotent + migration ACL `000012`. P4-B renuméroté `000013` après P4-B0.
- Infrastructure inspectée : `config/database.php`, `phpunit.xml`, `.env.example`,
  `docker-compose.yml`, `.github/workflows/ci.yml` (CI tourne en superuser —
  n'attrape pas la sonde aujourd'hui), `PhaseMigrationHarness`. Aucun script d'init
  Postgres existant.
- Mission strictement documentaire : aucun code/rôle/privilège/migration/script/
  branche/test créé. Fichier parasite `public/fonts-manifest.dev.json` (cache Vite
  dev) supprimé localement (non suivi, hors commit). Branche `p4-b-download-logs`
  inchangée à `8cf24a8`. Documents mis à jour : DECISIONS_LOG (D-029.6), schéma v1
  (bloc P4 + P4-B0), PROGRESS_TRACKER, HANDOFF, CLAUDE.md.
  Laisse à : implémenter P4-B0 sur une branche dédiée, puis rebaser/corriger P4-B.

### 2026-07-19 — Codex (plan final P4-B + décision D-029.5)
- Stable locale/distante confirmée à `0d3014016beb9f16137fcd993f8a3c8033e57c72`,
  ahead/behind `0/0`, `origin/main` intact `11130f4`; aucune branche, migration
  `000012`, classe DownloadLog, table `download_logs` ni artefact P5.
- Décisions 1A/2A/3A et R1A/R2A/R3A consignées : consommation à `started`,
  rétention explicite, HMAC IP versionné, secret de tentative dédié et haché,
  Range/retries regroupés, HEAD sans effet, `completed` = remise au mécanisme.
- Schéma exact des 15 colonnes, CHECK anti-UNKNOWN, uniques/index, machine d'état,
  concurrence Order→Grant, G5/G6 (2 fonctions/2 triggers), remplacement futur de
  G2, rollback fail-closed `000012`, matrice de tests et threat model finalisés.
- Aucun code, branche, migration, fonction, trigger, endpoint P4-B ou P5 créé.
  Laisse à : implémentation P4-B isolée sur la branche/migration réservées.

### 2026-07-18 — Codex (clôture post-merge P4-A2.1)
- Merge prouvé : [PR #14](https://github.com/mysterus44/DigiTrove/pull/14), SHA
  `2c25e2a412a24ac6ae2e5d51ed6929f3f0a397f7`, parents `0633eb0` + `ba834be` ;
  hotfix `ba834bef` ancêtre de la stable, `origin/main` intact `11130f4`.
- PostgreSQL 16 et Redis 7 healthy. Validation post-merge : 27 migrations ;
  P4-A2.1 7/113 ; P4-A2 18/315 ; P4-A1 17/216 ; P4-A0 9/130 ; Catalog 12/112 ;
  P3B 18/358 ; P3C-A 16/220 ; P3C-B 13/188 ; P3C-C 16/460 ; suite complète
  158/2301 ; Pint 112 ; diff-check propre.
- G3 null-safe et G2 `updated_at` confirmés par transactions PostgreSQL ; topologie
  inchangée (4 fonctions / 5 triggers), G4 toujours différé sur grants et orders.
  Rollback isolé `000011` vert, définitions G2/G3 originales restaurées exactement,
  aucune base temporaire résiduelle.
- Migration `000010` inchangée ; P2/P3/P4-A0/P4-A1/P4-A2 préservés. Branche locale
  du hotfix supprimée, distante conservée. Aucun P4-B/P5 créé.
- Laisse à : plan technique P4-B dans une exécution séparée, branche future
  `p4-b-download-logs`, migration réservée `000012`.

### 2026-07-18 — Codex (P4-A2.1 Download Grant Integrity Hardening)
- Garde-fous : stable locale/distante `0633eb0`, merge-base exact, branche dédiée
  `p4-a2-1-grant-integrity-hardening`, `origin/main` intact `11130f4`, aucun P4-B/P5.
- Reproduction avant correctif, transactions annulées : bénéficiaire arbitraire sur
  commande invitée accepté par G3 ; `updated_at + 1 day` isolé accepté par G2.
- Correctif : migration additive `000011` remplaçant uniquement les fonctions G2/G3.
  G3 compare le bénéficiaire avec `IS NOT DISTINCT FROM`; G2 lie le timestamp à une
  transition réelle, exige une progression stricte et préserve la nullification FK.
  `000010` n'est pas modifiée ; aucune donnée, table, colonne, FK, CHECK, index ou
  liaison de trigger n'est changée.
- Tests : matrice invité/compte SQL + Eloquent, timestamps passé/futur/identique,
  transitions consommation/révocation, nullification FK, updates adversariaux,
  non-régression quota/expiration/révocation et rollback isolé exact de `000011`.
- Validation : 27 migrations ; P4-A2.1 7/113 ; suite 158/2301 ; Pint 112 ;
  diff-check propre ; 4 fonctions / 5 triggers ; aucune base temporaire résiduelle.
- Laisse à : review/merge du hotfix, puis clôture. P4-B renuméroté `000012`, non
  démarré ; P5 non démarré.

### 2026-07-17 — Claude Code (clôture post-merge P4-A2)
- Fait : **P4-A2 mergé** via [PR #13](https://github.com/mysterus44/DigiTrove/pull/13),
  merge `77f376624fa036aceded6b9095bd927f4adfdb7b` (exactement deux parents
  `1b6e401` + `cea5f2d`, sujet « Merge pull request #13 from
  mysterus44/p4-a2-download-grants »). Commit P4-A2 `cea5f2d` intégré et ancêtre de
  la stable. Stable synchronisée fast-forward (`1b6e401..77f3766`, 20 fichiers).
  `origin/main` intact `11130f4`.
- Infrastructure : le daemon Docker Desktop était arrêté au démarrage de la mission
  → relancé, puis **uniquement** les services du projet démarrés (`docker compose
  up -d`) ; PostgreSQL 16 + Redis healthy avant toute validation.
- Introspection PostgreSQL post-merge : `download_grants` avec les 13 colonnes
  exactes ; **aucun** token brut / `token_prefix` / IP / user-agent / JSONB /
  metadata / `last_downloaded_at` / statut texte / soft-delete ; FK `order_item_id`
  et `product_file_id` **RESTRICT**, `user_id` **SET NULL** ; 5 CHECK nommés
  (token SHA-256 hex minuscule, `expires_at > created_at`, `max_downloads >= 1`,
  `0 <= downloads_count <= max_downloads`, appariement de révocation en
  `CASE … IS TRUE`) ; `public_id` et `token_hash` uniques ; index partiel
  `download_grants_active_pair_unique … WHERE (revoked_at IS NULL)` — **aucun
  prédicat avec `now()`** ; **4 fonctions / 5 triggers physiques**, tous non
  internes et actifs, les deux triggers G4 `DEFERRABLE INITIALLY DEFERRED` sur
  `download_grants` ET `orders`. Vérifié : **G3/G4 ne mutent rien**, **G3 ne lit
  jamais `payments`** et prend `orders FOR UPDATE`, G2 utilise
  `pg_trigger_depth() > 1`, **aucun trigger `download%` sur `refunds`**.
- Périmètre mergé (`1b6e401..77f3766`) audité : migration `000010`, modèle
  `DownloadGrant`, factory, relations `OrderItem`/`ProductFile`, test P4-A2,
  9 tests historiques adaptés, 5 documents. Aucun `000011`, DownloadLog, route,
  contrôleur, service, listener, job, notification, email, endpoint, streaming,
  token brut, P5. Migrations `000001`–`000009` inchangées.
- **Delta d'assertions expliqué** : 1882 − 9 + 315 = **2188** (jamais 1882 + 315).
  Les −9 = 9 itérations `Schema::hasTable('download_grants')->toBeFalse()` devenues
  fausses (5 entrées de listes multi-lignes + 4 éléments de `foreach` inline) ;
  3 assertions chaînées remplacées par `download_logs` et `toBe(25)`→`toBe(26)`
  sont neutres ; les 4 assertions des rollbacks isolés antérieurs conservées.
- Non-régression : G0/P4-A0 (1 fonction), S1/S2/S3 de P4-A1 (3 fonctions), 5
  fonctions refunds, catalogue et commerce intacts ; `download_logs` absent.
- Validation : `migrate:fresh` 26 migrations ; suite complète **151 / 2188** ; Pint
  **110** ; `git diff --check` propre ; rollback isolé `000010` vert ; aucune base
  temporaire résiduelle.
- Nettoyage : branche locale `p4-a2-download-grants` supprimée (`git branch -d`,
  merge confirmé) ; distante conservée à `cea5f2d`.
- Décisions : aucune nouvelle (D-029.4 inchangée ; merge consigné).
- Laisse historiquement à P4-B ; cette réservation est désormais remplacée par le
  hotfix P4-A2.1 `000011`, et P4-B passe à `000012`. P4-B/P5 non démarrés.

### 2026-07-16 — Claude Code (P4-A2 Download Grants implémenté)
- Fait : gate **P4-A2** sur branche `p4-a2-download-grants` (depuis `1b6e401`, état
  Git prouvé). Migration unique `2026_07_14_000010_create_download_grants_table.php` :
  table `download_grants` (`public_id` uuid unique, `order_item_id`/`product_file_id`
  **RESTRICT**, `user_id` **SET NULL** audit, `token_hash` varchar(64) unique +
  CHECK `^[0-9a-f]{64}$`, `expires_at`/`max_downloads` **NOT NULL sans DEFAULT**,
  `downloads_count` défaut technique 0, `revoked_at`/`revoked_reason_code` appariés,
  timestamps) ; CHECK `expires_at > created_at`, `max_downloads >= 1`,
  `0 <= downloads_count <= max_downloads`, appariement de révocation en
  `CASE … IS TRUE` ; index unique partiel `download_grants_active_pair_unique
  WHERE revoked_at IS NULL` + `active_expiry` partiel + 3 index FK. **4 fonctions /
  5 triggers** : G1 prevent-delete, G2 immutabilité (ROW `IS DISTINCT FROM`,
  `user_id` via `pg_trigger_depth() > 1`, compteur +1 borné refusé si révoqué/
  expiré/quota, révocation set-once irréversible et jamais combinée à une
  consommation), G3 validation d'émission (`orders FOR UPDATE`, statut livrable,
  fichier actif, cohérence `user_id`↔acheteur, lignée directe/bundle, grant né
  actif), **G4 cohérence différée montée sur `download_grants` ET `orders`**.
  Modèle `DownloadGrant` (`token_hash` masqué), `DownloadGrantFactory` (digest d'un
  token jeté, jamais de token brut ; refuse une commande non livrable), relations
  `OrderItem::downloadGrants()` / `ProductFile::downloadGrants()`.
- Deux findings corrigés en cours de gate : (1) le state `revoked()` de la factory
  produisait une ligne **non-insérable** (G3 exige un grant né actif) → supprimé,
  la révocation ne s'obtient que par UPDATE ; (2) `max_downloads = -1` viole **deux**
  CHECK simultanément (`0 <= -1` faux) → PostgreSQL rapporte le quota, attente de
  test alignée. Les CHECK de quota restent shadowés par G2/G3 (défense en
  profondeur, assertés structurellement) — documenté, jamais affaibli.
- Tests : `tests/Feature/P4A2DownloadGrantsTest.php` — **18 tests / 315 assertions** :
  schéma physique complet (colonnes, types, **absence de DEFAULT commercial**, FK
  `r`/`r`/`n`, 7 contraintes nommées, index partiels, **aucun index avec `now()`**,
  4 fonctions, 4 triggers sur grants + 1 différé sur orders, aucun objet P4-B),
  émission valide + **token brut absent de la ligne**, `partially_refunded` accepté
  et 5 statuts non livrables refusés, fichier hors achat / inactif refusés, lignée
  bundle contre le **snapshot uniquement** (aucun repli sur `product_bundles`),
  **snapshot absent refusé / snapshot partiel indétectable documenté**, **fichier
  ajouté après l'achat accepté par G3** (option A : garantie applicative, jamais
  PostgreSQL), digest format/unicité, bornes quota/expiration, immutabilité colonne
  par colonne, compteur structurel borné, révocation irréversible + motif figé +
  jamais combinée, **un seul grant actif** + rotation revoke→réémission (même
  `product_file_id`) + **grant expiré non révoqué bloquant → `expired_reissue`**,
  `user_id` nullifié seulement par la FK, DELETE refusé (grant et product_file),
  **G4 différé** (refund total sans révocation refusé au COMMIT, réparable dans la
  même transaction ; partiel ne révoque rien), **concurrence 2 connexions** (verrou
  `orders` → 55P03, doublon → 23505), rollback isolé `000010`.
- Validation : 26 migrations ; suite complète **151 / 2188** (baseline 133/1882 +
  18/315, zéro régression) ; Pint **110** ; `git diff --check` propre ; aucune base
  temporaire résiduelle ; migrations `000001`–`000009` intactes.
- Décisions : aucune nouvelle (note d'exécution sous D-029.4).
- Laisse à : review humaine + merge de la PR `p4-a2-download-grants` →
  `p0-foundations-laravel13`, puis clôture documentaire post-merge, puis **plan P4-B**
  (désormais réservé à `000012` après le hotfix) en exécution séparée. P4-B et P5
  non démarrés.

### 2026-07-16 — Claude Code (plan final P4-A2 + décision D-029.4)
- Fait : **finalisation documentaire du plan P4-A2** (exécution documentaire,
  décision **D-029.4**). L'audit a établi par introspection que `orders.status` est
  déjà une source d'autorité fiable pour « payé » (constraint triggers différés P3C
  `orders_validate_payment_consistency` / `orders_validate_refund_consistency` /
  `refunds_validate_order_consistency`, tous `deferrable=true`, plus l'unique
  partiel `payments_one_succeeded_per_order`) → **G3/G4 ne reliront pas `payments`**.
  Question soulevée et tranchée : **aucun snapshot BDD des ProductFiles achetés
  n'existe** (P4-A1 ne snapshotte que les composants de bundle), donc la lignée G3
  accepterait techniquement un fichier ajouté après l'achat.
- **KingKouda a tranché : option A** → les grants émis à `OrderPaid` **sont** le
  snapshot **applicatif** des fichiers livrés ; « absence d'upgrade implicite =
  garantie APPLICATIVE, pas invariant PostgreSQL ». Un fichier postérieur ne reçoit
  aucun grant automatique et n'est jamais choisi par une rotation ou une réémission
  (qui conservent le même `order_item_id + product_file_id`) ; l'y rattacher serait
  un **`upgrade entitlement`**, hors P4-A2/P4-B/MVP.
- **Trois findings corrigés** : (1) la formulation « snapshot **incomplet**
  détecté en fail-closed » était **fausse** — P4-A2 ne détecte que l'absence
  TOTALE ; un snapshot partiel est indétectable (sous-livraison possible, jamais de
  sur-livraison) ; corrigé dans le contrat cible de D-029.3 et dans le schéma, sans
  réécrire l'historique ; (2) un grant **expiré non révoqué** reste dans le
  prédicat `WHERE revoked_at IS NULL` et bloque la réémission → révocation
  `expired_reissue` préalable obligatoire, **aucun index avec `now()`** ; (3)
  `user_id` requalifié en **dénormalisation de support/audit** (la source
  d'autorité reste `grant → order_item → order`).
- État build/tests : `git diff --check` propre ; **aucun fichier PHP touché** (docs
  seuls). Baseline inchangée : 25 migrations, 133 tests / 1882 assertions, Pint 106.
- Décisions prises (→ DECISIONS_LOG.md) : **D-029.4**.
- Laisse à : **implémentation P4-A2** (`p4-a2-download-grants`, migration `000010`),
  exécution séparée. Aucune migration, branche, classe ou test créés ici.

### 2026-07-16 — Claude Code (clôture post-merge P4-A1)
- Fait : **P4-A1 mergé** via [PR #12](https://github.com/mysterus44/DigiTrove/pull/12),
  merge `93d1f173b6021fccb7d4df70e24938e02d16f3e3` (exactement deux parents
  `a1e2e7f` + `94b018c`, sujet « Merge pull request #12 from
  mysterus44/p4-a1-bundle-purchase-snapshots »). Commit P4-A1 `94b018c` intégré et
  ancêtre de la stable. `p0-foundations-laravel13` synchronisé fast-forward
  (`a1e2e7f..93d1f17`, 10 fichiers). `origin/main` intact `11130f4`.
- Introspection PostgreSQL post-merge : table `order_item_bundle_components` avec
  les six colonnes exactes (aucune quantity/position/updated_at/jsonb/metadata) ;
  FK `order_item_id` **RESTRICT** + `child_product_id` **SET NULL** ; CHECK
  not-blank `btrim(col, E' \t\n\r\f\v')` ; unique partiel
  `oibc_order_item_child_unique ... WHERE (child_product_id IS NOT NULL)` ; index
  `oibc_order_item_id_index` / `oibc_child_product_id_index` (aucun index
  inattendu) ; **3 fonctions / 3 triggers** S1 (BEFORE DELETE), S2 (BEFORE UPDATE,
  `IS DISTINCT FROM` + `pg_trigger_depth() > 1`), S3 (BEFORE INSERT), tous non
  internes (`tgisinternal=f`), actifs (`O`), **non deferrable** ; **S3 ne mute
  rien** (aucun INSERT/UPDATE/DELETE ni affectation à `NEW` dans
  `pg_get_functiondef`) ; **0 constraint trigger de cardinalité, 0 fonction de
  copie, 0 S4** — bundle vide toujours accepté par la BDD (garde-fou applicatif).
- Périmètre mergé (`a1e2e7f..93d1f17`) audité : migration `000009`, modèle
  `OrderItemBundleComponent`, factory, relation `OrderItem::bundleComponents()`,
  test P4-A1, adaptation historique P4-A0, et 4 documents (le schéma v1 était déjà
  finalisé par D-029.3). Aucun `000010`/`000011`, DownloadGrant, DownloadLog,
  OrderService, CheckoutService, route, contrôleur, service, job, listener, token,
  endpoint, P5. Migrations `000001`–`000008` inchangées.
- Non-régression : G0/P4-A0 présent (1 fonction + 1 trigger) ; `products`,
  `product_files`, `product_bundles`, `orders`, `order_items`, `payments`,
  `refunds`, `payment_webhook_events` intactes ; 5 fonctions refunds présentes ;
  `download_grants`/`download_logs` absents.
- Validation : `migrate:fresh` 25 migrations ; suite complète **133 / 1882** ; Pint
  **106** ; `git diff --check` propre ; rollback isolé `000009` vert (P4-A0/G0 et
  P0–P3C préservés) ; aucune base temporaire résiduelle.
- Nettoyage : branche locale `p4-a1-bundle-purchase-snapshots` supprimée
  (`git branch -d`, merge confirmé) ; distante conservée à `94b018c`.
- Décisions : aucune nouvelle (D-029.3 inchangée ; merge consigné).
- Laisse à : **plan technique P4-A2 — Download Grants** (branche
  `p4-a2-download-grants`, migration `000010`), exécution séparée. P4-A2/P4-B/P5
  non démarrés.

### 2026-07-16 — Claude Code (P4-A1 Bundle Purchase Snapshot implémenté)
- Fait : gate **P4-A1** sur branche `p4-a1-bundle-purchase-snapshots` (depuis
  `a1e2e7f`, état Git prouvé). Migration unique
  `2026_07_14_000009_create_order_item_bundle_components_table.php` : table
  `order_item_bundle_components` (FK `order_item_id` RESTRICT + `child_product_id`
  SET NULL, snapshots textuels name/slug, `created_at`, CHECK not-blank nommés,
  unique partiel `oibc_order_item_child_unique WHERE child_product_id IS NOT NULL`,
  index `oibc_order_item_id_index`/`oibc_child_product_id_index`) et **3 fonctions /
  3 triggers** (S1 prevent-delete, S2 immutabilité ROW + exception FK
  `pg_trigger_depth() > 1`, S3 validation BEFORE INSERT). Modèle
  `OrderItemBundleComponent` (`$timestamps = false`, `created_at` immutable_datetime),
  `OrderItemBundleComponentFactory` (part toujours d'un achat cohérent ; ne crée
  jamais de lien pivot absent, ne modifie jamais un pivot existant ni l'order_item),
  relation `OrderItem::bundleComponents()`.
- Deux findings corrigés en cours de gate : (1) **`btrim/1` ne retire que les
  espaces** → CHECK durci en `btrim(col, E' \t\n\r\f\v')` (un snapshot de
  tabulations passait) — renforcement, jamais un affaiblissement ; (2) `order_number`
  du test de concurrence hors alphabet Crockford (`O` interdit). Les fixtures PDO
  brutes groupent order+order_item dans une transaction explicite (le trigger
  différé P3B valide au COMMIT).
- Tests : `tests/Feature/P4A1BundlePurchaseSnapshotTest.php` — **17 tests /
  217 assertions** : schéma physique complet (types, nullabilité, FK `r`/`n`, CHECK,
  index partiel + prédicat, 3 fonctions, 3 triggers, timings, **0 trigger différé,
  0 contrainte de cardinalité**), snapshot valide 1..N, factory par défaut + ordre
  des states, même composant dans deux order_items/commandes, order_item direct
  refusé, chaque violation S3 (child NULL, autre bundle, non attaché, **bundle
  imbriqué refusé**, bundle purgé, FK autorité pour l'inexistence), doublon 23505,
  CHECK blank/whitespace + NOT NULL, S1 (DELETE simple/multiple/relation), S2
  (colonne par colonne, valeur identique acceptée, multi-colonnes, SQL brut +
  Eloquent), **nullification FK** (UPDATE manuel et swap refusés, DELETE product réel
  autorisé → NULL + textes préservés), **historique** (C ajouté après achat absent du
  snapshot, B retiré toujours reconnu, snapshot tardif de B refusé), **bundle vide
  accepté par la BDD** (règle applicative documentée dans le test), **concurrence
  2 connexions** (verrou `products FOR UPDATE` → 55P03, INSERT...SELECT unique,
  double copie → 23505), zéro effet collatéral, rollback isolé `000009`.
- **Risque résiduel documenté dans le test lui-même** : un rôle SQL privilégié peut
  insérer tardivement un composant ajouté après l'achat (S3 lit légitimement le pivot
  à la copie) — PostgreSQL ne l'empêche pas ; permissions, absence d'API de mutation
  et tests le couvrent. Jamais présenté comme une garantie.
- Validation : 25 migrations ; suite complète **133 / 1882** (baseline 116/1666 +
  17/217, zéro régression) ; Pint **106** ; `git diff --check` propre ; aucune base
  temporaire résiduelle ; migrations `000001`–`000008` intactes.
- Décisions : aucune nouvelle (note d'exécution sous D-029.3, dont le durcissement
  `btrim` signalé).
- Laisse à : review humaine + merge de la PR `p4-a1-bundle-purchase-snapshots` →
  `p0-foundations-laravel13`, puis clôture documentaire post-merge, puis P4-A2
  (`000010`) en exécution séparée. P4-A2/B et P5 non démarrés.

### 2026-07-16 — Claude Code (plan final P4-A1 + décisions D-029.3)
- Fait : **finalisation documentaire du plan P4-A1** (exécution en lecture/analyse,
  décision **D-029.3**). Introspection PostgreSQL prouvant le problème :
  `product_bundles` n'a **ni timestamps, ni trigger, ni historique** (PK composite
  `(bundle_id, child_product_id)`, FK CASCADE, colonne `position` seule) — sa
  composition est librement mutable et ne conserve aucune trace du vendu. Un
  order_item bundle se reconnaît par `product_type_snapshot = 'bundle'` (figé P3B).
  `products.name/slug` sont mutables (aucun trigger) → snapshots textuels justifiés.
- **KingKouda a tranché : Q1=A, Q2=A** → (1) **bundles imbriqués EXCLUS** : S3
  refuse tout composant `type='bundle'` (les cycles indirects restent non protégés ;
  l'aplatissement serait exposé aux cycles, le conserver livrerait un achat
  incomplet en silence) ; (2) **S3 validation immédiate BEFORE INSERT** (order_item
  bundle, `product_id` non NULL, composant existant/non-bundle/∈ pivot à la copie ;
  vérifie et refuse, ne mute jamais) + **exhaustivité APPLICATIVE** via un unique
  `INSERT ... SELECT` de l'OrderService dans la même transaction, après `products
  FOR UPDATE`. Options écartées documentées : constraint trigger différé (faux
  refus sous concurrence + dépendance permanente au pivot) et fonction de copie
  atomique (muterait, contre le principe « triggers vérifient, service mute »).
  Risque résiduel assumé (insertion tardive par rôle SQL privilégié, couvert par
  permissions/absence d'API/tests) — **jamais présenté comme éliminé par PostgreSQL**.
- Objets P4-A1 arrêtés : table + **3 fonctions / 3 triggers** (S1/S2/S3) ; aucune
  quantité (le pivot n'en a pas) ; aucune `position` ; aucun fallback pivot en
  P4-A2 ; fail-closed si snapshot absent ou incomplet.
  *(Entrée historique — formulation corrigée depuis par D-029.4, finding 1 : seul
  un snapshot TOTALEMENT absent est détectable ; un snapshot partiel ne l'est pas.)*
- **Garde-fou bundle vide** (précision validée) : la BDD autorise techniquement un
  snapshot vide (aucune cardinalité minimale — l'imposer exigerait le constraint
  trigger différé écarté) ; le futur OrderService refuse la commande d'un bundle
  vide avant la création de l'order_item ; une copie échouée/oubliée laisse P4-A2
  fail-closed. Garantie APPLICATIVE, jamais un invariant PostgreSQL.
- État build/tests : `git diff --check` propre ; **aucun fichier PHP touché** (docs
  seuls). Baseline inchangée : 24 migrations, 116 tests / 1666 assertions, Pint 102.
- Décisions prises (→ DECISIONS_LOG.md) : **D-029.3**.
- Laisse à : **implémentation P4-A1** (`p4-a1-bundle-purchase-snapshots`, migration
  `000009`), exécution séparée. Aucune migration, branche, classe ou test créés ici.

### 2026-07-16 — Claude Code (clôture post-merge P4-A0)
- Fait : **P4-A0 mergé** via [PR #11](https://github.com/mysterus44/DigiTrove/pull/11),
  merge `a047571fe4e3453fec39336f297f4241cbb95898` (exactement deux parents
  `abaea6e` + `8b822c1`, sujet « Merge pull request #11 from
  mysterus44/p4-a0-product-file-immutability »). Commit P4-A0 `8b822c1` intégré et
  ancêtre de la stable. `p0-foundations-laravel13` synchronisé fast-forward
  (`abaea6e..a047571`, 6 fichiers). `origin/main` intact `11130f4`.
- Introspection PostgreSQL post-merge : 1 fonction
  `enforce_product_file_content_immutability` (corps `IS DISTINCT FROM` sur id +
  product_id + storage_disk + storage_path + checksum_sha256 + size_bytes +
  mime_type + version + created_at, RAISE 23514 + DETAIL colonne, retourne NEW sans
  réécriture), 1 trigger `product_files_enforce_content_immutability_trigger`
  BEFORE UPDATE FOR EACH ROW sur `product_files`, non interne (`tgisinternal=f`),
  actif (`tgenabled=O`). Aucune autre fonction/trigger/table P4. Migration `000008`
  ne crée que la fonction + le trigger (aucune colonne modifiée, aucune donnée
  réécrite, aucune table, aucun index).
- Périmètre mergé (`abaea6e..a047571`) audité : uniquement migration `000008`,
  test `P4A0ProductFileImmutabilityTest`, et 4 documents de suivi
  (DECISIONS_LOG/PROGRESS_TRACKER/CLAUDE.md/HANDOFF ; le schéma v1 était déjà
  finalisé par le commit D-029.2). Aucun modèle/enum/factory/table snapshot/grant/
  log/route/contrôleur/service/job/listener/token/endpoint/P5. Migrations
  `000001`–`000007` inchangées. `original_name` : seul usage code = `$fillable`
  (libellé d'affichage confirmé).
- Validation : `migrate:fresh` 24 migrations ; suite complète **116 / 1666** ;
  Pint **102** ; `git diff --check` propre ; rollback isolé frontière `000008` vert
  (données `product_files` préservées, P0–P3C intacts, mutabilité retrouvée après
  down() — documenté) ; aucune base temporaire résiduelle.
- Nettoyage : branche locale `p4-a0-product-file-immutability` supprimée
  (`git branch -d`, merge confirmé) ; distante conservée à `8b822c1`.
- Décisions : aucune nouvelle (D-029/D-029.1/D-029.2 inchangées ; merge consigné).
- Laisse à : **plan P4-A1 — Bundle Purchase Snapshot** (branche
  `p4-a1-bundle-purchase-snapshots`, migration `000009`), exécution séparée. Aucun
  code P4-A1/A2/B/P5 démarré.

### 2026-07-16 — Claude Code (P4-A0 ProductFile Content Immutability implémenté)
- Fait : gate **P4-A0** sur branche `p4-a0-product-file-immutability` (depuis
  `abaea6e`, état Git prouvé conforme). Migration ADDITIVE unique
  `2026_07_14_000008_harden_product_files_content_immutability.php` : fonction
  `enforce_product_file_content_immutability` (comparaisons `IS DISTINCT FROM`
  résistantes à NULL, RAISE 23514 avec message stable + DETAIL nommant la colonne)
  + trigger `product_files_enforce_content_immutability_trigger` BEFORE UPDATE.
  Figés : `id` (précédent P3B/P3C, signalé dans D-029.2) + les huit colonnes
  D-029.2. Mutables : `original_name`/`position`/`is_active`. La migration P2
  mergée n'est pas touchée ; aucune table/modèle/enum/factory créé.
- Tests : `tests/Feature/P4A0ProductFileImmutabilityTest.php` — 9 tests /
  132 assertions : introspection physique (fonction, trigger BEFORE UPDATE,
  colonnes surveillées exactes), refus colonne par colonne (valeur d'origine
  préservée), UPDATE même-valeur accepté, mutables seuls acceptés, refus atomique
  des mélanges (`original_name`+`storage_path`, `position`+`version`), SQL brut et
  Eloquent refusés pareil, « nouvelle version = nouvelle ligne » (A intacte puis
  désactivée, B active), absence d'effets collatéraux, rollback isolé frontière
  `000008` avec données `product_files` préservées et mutabilité retrouvée après
  down() (comportement documenté honnêtement).
- Validation : suite complète **116 tests / 1666 assertions** (baseline 107/1534
  + 9/132, zéro régression, aucune adaptation historique nécessaire), Pint
  **102 fichiers**, `git diff --check` propre, aucune base temporaire résiduelle.
- Décisions : aucune nouvelle (note factuelle dans D-029.2 : `id` figé par
  alignement sur le précédent projet).
- Laisse à : review humaine + merge de la PR `p4-a0-product-file-immutability` →
  `p0-foundations-laravel13`, puis clôture documentaire post-merge, puis P4-A1
  (`000009`) dans une exécution séparée. P4-A1/A2/B et P5 non démarrés.

### 2026-07-15 — Claude Code (D-029.2 : version figée + gates P4 isolés)
- Fait : correction documentaire pré-implémentation (**D-029.2**), sur état Git
  prouvé (`6ba74e4` = distant, push manuel D-029.1 confirmé, aucun artefact P4).
  (1) **`product_files.version` devient IMMUABLE** avec le contenu (une étiquette
  de version renommable après achat rendait l'historique improuvable) ; G0 fige
  désormais `product_id`, `storage_disk`, `storage_path`, `checksum_sha256`,
  `size_bytes`, `mime_type`, `version`, `created_at` ; `original_name` audité dans
  le code (aucun usage de résolution/clé/intégrité/preuve — libellé d'affichage au
  téléchargement) → mutable, distinction écrite. (2) **Gate composite P4-A abandonné**
  (sa frontière `000010` n'aurait pas retiré `000008`/`000009`) → QUATRE gates
  isolés : P4-A0 `p4-a0-product-file-immutability` (`000008`) → P4-A1
  `p4-a1-bundle-purchase-snapshots` (`000009`) → P4-A2 `p4-a2-download-grants`
  (`000010`) → P4-A2.1 (`000011`) → P4-B `p4-b-download-logs` (`000012`), chacun
  avec sa frontière de
  rollback, merge obligatoire avant le gate suivant, migration N+1 jamais créée
  avant merge du gate N.
- État build/tests : aucun fichier PHP touché (docs seuls) ; baseline inchangée
  (23 migrations, 107 tests / 1534 assertions).
- Décisions prises (→ DECISIONS_LOG.md) : **D-029.2**.
- Laisse à : **P4-A0 — ProductFile Content Immutability** (exécution séparée,
  périmètre strict dans PROCHAINE TÂCHE). Aucune branche/migration/classe P4 créée ici.

### 2026-07-15 — Claude Code (audit contradictoire P4 + décisions D-029.1)
- Fait : **audit final en lecture seule du plan P4** au commit `202e4b8` (preuves
  Git : push confirmé, `origin/main` intact, commit strictement documentaire — la
  « contradiction » du rapport précédent était l'état pré-commit `94d0dec`, parent
  de `202e4b8`). Verdict initial : `DÉCISIONS P4 REQUISES` — trois failles réelles :
  (1) `product_files` mutable in-place ⇒ « version achetée » non garantie ;
  (2) `product_bundles` sans historique ⇒ lignée bundle prouvée sur la composition
  courante, pas celle de l'achat ; (3) `max_downloads DEFAULT 5` figeait une
  politique commerciale dans le schéma.
- **KingKouda a tranché : B–A–B** → amendement **D-029.1** consigné, documents du
  plan mis à jour : P4-A devient TROIS migrations (`000008` durcissement G0
  `product_files`, `000009` snapshot `order_item_bundle_components` S1/S2,
  `000010` `download_grants` sans DEFAULT commercial, frontière rollback `000010`) ;
  P4-B `download_logs` est désormais réservé à `000012` après P4-A2.1. Précisions
  d'audit intégrées : règle
  anti-deadlock de rotation (verrou `orders` d'abord), sémantique stricte
  `download_logs.status` (jamais de token inconnu en table), autorisation =
  conjonction pure sur colonnes vérifiables.
- État build/tests : aucun fichier PHP touché (docs seuls) ; baseline inchangée
  (23 migrations, 107 tests / 1534 assertions).
- Décisions prises (→ DECISIONS_LOG.md) : **D-029.1** (B–A–B).
- Laisse à : **implémentation P4-A** sur `p4-a-download-grants` (exécution séparée,
  voir PROCHAINE TÂCHE). Aucune branche/migration/classe P4 créée ici.

### 2026-07-15 — Claude Code (plan final P4 Delivery & Download Integrity)
- Fait : **finalisation documentaire du plan P4** (exécution strictement documentaire,
  décision **D-029**). Garde-fous Git confirmés : `p0-foundations-laravel13` à
  `94d0dec` = distant, worktree propre, `122332a`/`1270c53` ancêtres, `origin/main`
  intact à `11130f4`, aucune branche/migration/classe P4 existante. Bloc P4 de
  `DigiTrove_Schema_BDD_v1.md` réécrit : schéma cible `download_grants` (P4-A,
  `000008`) et `download_logs` (P4-B, `000009`), CHECK nommés anti-`CHECK = UNKNOWN`,
  index partiels (un grant actif par couple), catalogue G1–G6 (prevent-delete,
  immutabilité + consommation +1 bornée, préconditions d'émission sous verrou
  `orders FOR UPDATE` avec lignée produit/bundle, cohérence différée bidirectionnelle
  grant↔commande, journal append-only, purge contrôlée par rétention), threat model
  et plan de tests (dont concurrence à 2 connexions sur la dernière utilisation).
- Contrat prouvé sans nouvelle décision humaine : D-009/D-010/D-014/D-028 + schéma
  v1 + `SECURITE_TELECHARGEMENT.md`. `licenses` exclu de P4 (option produit non
  décidée). Limite P3C-C documentée : remboursement partiel non ciblable par ligne
  ⇒ aucune révocation automatique en partiel ; révocation totale exigée au commit
  quand la commande passe à `refunded`.
- État build/tests : `git diff --check` OK ; **aucun fichier PHP touché** (docs
  seuls : DECISIONS_LOG, schéma v1, PROGRESS_TRACKER, HANDOFF, CLAUDE.md). Baseline
  inchangée : 23 migrations, 107 tests / 1534 assertions, Pint 100 fichiers.
- Décisions prises (→ DECISIONS_LOG.md) : **D-029** plan P4.
- Laisse à : **validation humaine du plan P4**, puis implémentation P4-A
  (`p4-a-download-grants`, migration `000008`) dans une exécution séparée. Aucune
  migration, modèle, enum, factory, route, service ou logique P4/P5 créés ici.

### 2026-07-15 — Codex (clôture post-merge P3C-C Refunds)
- Fait : **P3C-C mergé** via [PR #10](https://github.com/mysterus44/DigiTrove/pull/10),
  merge `122332aa5cc9fc25e9bf1898224f5a1da30f6446` (parents `be74854` + `1270c53`).
  Le commit final audité `1270c530124fc605ade299f441277edbbf1c5534` est intégré.
- PostgreSQL réel : 23 migrations ; table `refunds`, cinq fonctions, six triggers dont
  deux constraint triggers `DEFERRABLE INITIALLY DEFERRED`; plafond cumulatif sous
  verrou Payment, concurrence réelle, mapping Refund↔Order et nullification contrôlée
  de l'initiateur confirmés. Rollback isolé `000007` sans objet/base résiduel.
- Validation : P3C-C **16/461**, P3C-B **13/189**, P3C-A **16/221**, P3B **18/359**,
  suite complète **107/1534**, Pint **100**, `git diff --check` propre.
- Nettoyage : branche locale `p3c-c-refunds` supprimée ; branche distante conservée à
  `1270c53`. `origin/main` intact à `11130f4`. Aucune nouvelle décision D-028.
- Laisse à : **plan P4 — intégrité delivery/download**, exécution séparée. Aucun code P4/P5.

### 2026-07-15 — Claude Code (clôture post-merge P3C-B.1)
- Fait : **durcissement P3C-B.1 mergé** via [PR #9](https://github.com/mysterus44/DigiTrove/pull/9)
  → `13932ac` (2 parents `51c4847` + `c772ac1`). `p0-foundations-laravel13` synchronisé
  fast-forward (`963eef0..13932ac`, inclut aussi le merge P3C-B PR #8 `51c4847`).
- Validation post-merge (PostgreSQL réel) : 22 migrations ; **index de rejeu durci confirmé**
  par introspection (`WHERE (external_event_id IS NOT NULL) AND (signature_verified = true)`) ;
  P3C-B **13/191**, suite complète **91/1081**, Pint **95**, `git diff --check` propre, aucune
  base temporaire résiduelle. Migration mergée `000005` inchangée.
- Nettoyage : branches locales `p3c-b-webhooks` (49ad374) et `p3c-b1-webhook-replay-hardening`
  (c772ac1) supprimées (mergées) ; distantes conservées. `origin/main` intact `11130f4`.
- Décisions : aucune nouvelle (D-028.4 déjà consignée : rejeu restreint aux signés).
- Laisse à : **P3C-C `refunds`** (branche dédiée, migration `000007`, exécution séparée).

### 2026-07-15 — Claude Code (durcissement P3C-B.1 — unicité de rejeu)
- Contexte : P3C-B mergé via **PR #8** (`51c4847`, parents `963eef0` + `49ad374`). Review
  adversariale post-merge : l'index `payment_webhook_events_provider_external_event_unique`
  avait le prédicat `WHERE external_event_id IS NOT NULL` (non restreint aux signés). Un
  webhook **non signé** peut porter un `external_event_id` → il réserve `(provider,
  external_event_id)` et **bloque l'événement signé légitime** (poisoning/DoS). KingKouda
  a validé le durcissement.
- Fait : branche `p3c-b1-webhook-replay-hardening` (depuis `51c4847`). Migration **additive**
  `2026_07_14_000006_harden_webhook_external_event_unique.php` (jamais d'édition de `000005`
  mergée) : DROP + recrée l'index en `WHERE external_event_id IS NOT NULL AND
  signature_verified = true` ; `down()` restaure l'ancien. `external_event_id` reste conservé
  sur les invalides pour l'audit ; invalides dédupliqués par `(provider, payload_hash)
  WHERE signature_verified=false`. Test adversarial ajouté (`P3CBWebhooksSchemaTest`) : un
  non signé ne bloque plus un signé ; deux signés restent en conflit. D-028.4 : note factuelle.
- Validation (PostgreSQL réel) : 22 migrations ; index durci confirmé par introspection ;
  P3C-B **13/191**, P3C-A **16/223**, P3B **18/360**, suite complète **91/1081**, Pint **95**,
  `git diff --check` propre, aucune base temporaire résiduelle.
- Laisse à : review + merge de `p3c-b1-webhook-replay-hardening` ; puis P3C-C `refunds`.

### 2026-07-15 — Claude Code (P3C-B payment_webhook_events implémenté)
- Fait : gate **P3C-B** sur branche `p3c-b-webhooks` (depuis la clôture P3C-A `963eef0`).
  Migration `2026_07_14_000005_create_payment_webhook_events_table.php` : table
  `payment_webhook_events` (provider `VARCHAR(32)` canonique, `external_event_id`/`payment_id`
  nullables, `payload_hash VARCHAR(64)`, `filtered_payload JSONB`, `signature_verified`,
  `processing_status` 4 valeurs `received/processed/ignored/failed` — **pas de `duplicate`**,
  dates de cycle + rétention). CHECK stricts (provider/hash regex, payload objet, cohérence
  statut/dates en `CASE…ELSE FALSE END IS TRUE`, signé⇒external_id, forme minimale de
  l'invalide, anti-`CHECK = UNKNOWN`). FK `payment_id` **RESTRICT**. 2 index uniques partiels
  de rejeu : `(provider, external_event_id) WHERE NOT NULL` et `(provider, payload_hash)
  WHERE signature_verified=false`. **3 fonctions / 3 triggers immédiats** :
  `enforce_webhook_event_immutability` (ROW figée + `payment_id`/`event_type`/dates set-once
  + `retention_until` extensible seulement + machine à états received→terminal),
  `validate_webhook_payment_consistency` (BEFORE INSERT/UPDATE OF payment_id : lien ⇒ signé
  + provider = paiement ; inexistence laissée à la FK), `enforce_webhook_event_retention_delete`
  (BEFORE DELETE : autorisé seulement si statut terminal + `retention_until ≤ now`).
  Enum `WebhookProcessingStatus`, modèle `PaymentWebhookEvent` (payload_hash masqué),
  relation `Payment::webhookEvents()`, `PaymentWebhookEventFactory` (états processed/ignored/
  failed/invalidSignatureMinimal/forPayment ; aucun secret, aucun appel réseau).
- Tests : `tests/Feature/P3CBWebhooksSchemaTest.php` (12 tests / 187 assertions), incluant
  rollback isolé par frontière (`PhaseMigrationHarness`, frontière `…000005`, down() du seul
  gate, P3C-A + P3B préservés, aucune migration Refund/P4/P5 appliquée).
- Régression : suite complète **90 tests / 1077 assertions** verte, Pint **94 fichiers**,
  `git diff --check` propre, aucune base temporaire résiduelle. Adaptation : `payment_webhook_events`
  retiré des listes « table interdite » de P1/P2/P3A/P3B/P3C-A (refunds/download_grants/P4/P5
  restent interdits). Le rollback isolé P3C-A reste correct (sa frontière `…000004` exclut `…000005`).
- Décisions : aucune nouvelle (implémentation fidèle à D-028.4/D-028.5 ; note factuelle dans D-028).
- Laisse à : review + merge de `p3c-b-webhooks` ; puis P3C-C `refunds`. Aucun webhook HTTP,
  fournisseur concret, SDK, contrôleur, route, service, job de purge créés.

### 2026-07-15 — Claude Code (clôture post-merge P3C-A)
- Fait : **P3C-A Payments mergé** via [PR #6](https://github.com/mysterus44/DigiTrove/pull/6)
  → merge commit `4a077db6720ee07b304a7746bf7545f6dcf743ec` (2 parents `be1af7f` + `1a792a3`,
  commits intégrés `c45e44a` + `0d04f77` + `1a792a3`). `p0-foundations-laravel13` synchronisé
  fast-forward (`be1af7f..4a077db`).
- Validation post-merge (PostgreSQL réel) : 20 migrations ; rollbacks isolés **2/54** ;
  P3B **18/361** ; P3C-A **16/225** ; suite complète **78/896** ; Pint **89** ; `git diff --check`
  propre. Audit BDD : table `payments` (types uuid/bigint/varchar(32|64|3)/jsonb/timestamptz,
  FK `order_id` RESTRICT), 4 fonctions + 5 triggers P3C-A dont 2 constraint triggers
  `DEFERRABLE INITIALLY DEFERRED`, 3 index uniques partiels. Non-régression P3B : 6 fonctions,
  8 triggers, 4 différés, `orders_coupon_snapshot_consistency_check` intacts. Aucune table
  `payment_webhook_events`/`refunds`/`download_grants`/`licenses`/`events`. `PhaseMigrationHarness`
  opérationnel (frontières exactes, aucun `--step`, nettoyage `finally`, aucune base temporaire
  résiduelle, `digitrove_testing` accessible).
- Nettoyage : branche locale `p3c-a-payments` supprimée (mergée), `origin/p3c-a-payments`
  conservée à `1a792a3` ; `origin/main` intact `1e41b92`. Aucune correction post-merge nécessaire.
- Décisions : aucune nouvelle (D-028 inchangée ; merge consigné). P3C-B/P3C-C non démarrés.
- Laisse à : **plan BDD P3C-B `payment_webhook_events`** (exécution séparée).

### 2026-07-14 — Claude Code (isolation des tests de rollback P3B/P3C-A)
- Contexte : le correctif précédent `0d04f77` (calcul dynamique du `--step`) corrigeait
  le **symptôme** (faux échec dû à un `--step` figé obsolète) mais **pas la cause** :
  la base temporaire exécutait toutes les migrations (`migrate:fresh`) puis rollbackait
  « du gate jusqu'à la fin » — donc le test P3B rollbackait déjà `payments`, et les deux
  tests rollbackeraient les gates P3C-B/C futurs. Isolation de phase **non prouvée**.
- Fait : nouveau helper `tests/Support/PhaseMigrationHarness.php`. Chaque test de rollback
  applique **uniquement** les migrations jusqu'à la frontière du gate via
  `migrate --path=<fichiers>` (aucune migration postérieure exécutée ; refus explicite si
  une migration au-delà de la frontière apparaît) puis rollbacke **uniquement** les
  migrations du gate via `migrate:rollback --path=<fichiers du gate>`. Preuves réelles
  depuis la table `migrations` : liste appliquée (se termine à la frontière), liste des
  `down()` exécutés (exactement le gate), `current_database()` = base temporaire, objets
  antérieurs préservés, nettoyage garanti dans `finally`. **Plus aucun `--step`,
  `migrate:fresh` ni `count - gateIndex`** dans les deux tests.
  - P3B : frontière `…000003_coupon_redemptions` ; down() = orders, order_items,
    coupon_redemptions ; `payments` jamais créée ; P1/P2/P3A préservés.
  - P3C-A : frontière `…000004_payments` ; down() = payments seul ; P3B préservé
    (6 fonctions, 8 triggers, `orders_coupon_snapshot_consistency_check`) ; aucune table
    webhook/refund appliquée.
- Validations : rollbacks isolés 2/54 · suite complète **78 tests / 896 assertions** ·
  Pint **89 fichiers** · `git diff --check` propre · aucune base temporaire résiduelle.
- Périmètre : uniquement 2 tests + 1 helper + docs. **Aucune migration, fonction, trigger,
  modèle, enum, factory ou relation modifié. D-028.2 inchangée.**
- Laisse à : review finale de l'isolation, puis merge de `p3c-a-payments` ; ensuite P3C-B.

### 2026-07-14 — Claude Code (P3C-A Payments implémenté)
- Fait : gate technique **P3C-A** sur branche `p3c-a-payments` (depuis `be1af7f`).
  Migration `2026_07_14_000004_create_payments_table.php` : table `payments` (identité
  `public_id`/`order_id RESTRICT`/`provider VARCHAR(32)` lowercase/`idempotency_key_hash
  VARCHAR(64)`/`attempt_number`, montants `BIGINT` > 0, devise `VARCHAR(3)`, statut
  contraint 7 valeurs, métadonnées fournisseur FILTRÉES, dates de cycle). Contraintes
  nommées, index partiels (`one_succeeded`/`one_requires_review` par commande, réf
  fournisseur), FK RESTRICT. **4 fonctions / 5 triggers** : prevent-delete, immutabilité
  + machine à états, cohérence immédiate montant/devise (free/amount/currency), cohérence
  différée paiement↔commande (constraint triggers DEFERRABLE INITIALLY DEFERRED sur
  `payments` et `orders`). Enum `PaymentStatus`, modèle `Payment` (hash masqué), relation
  `Order::payments()`, `PaymentFactory` (états, hash factices valides, aucun secret).
- Tests : `tests/Feature/P3CAPaymentsSchemaTest.php` (16 tests / 209 assertions),
  incluant rollback isolé de phase (harness de frontière — voir entrée du 2026-07-14
  ci-dessus), transitions, cohérence différée, commande gratuite, unicité,
  immutabilité, concurrence non traitée ici.
- Régression : suite complète verte, `git diff --check` propre. La cohérence
  bidirectionnelle est **imposée par D-028.2** ; seule l'**option d'adaptation des
  fixtures** a été retenue par l'utilisateur (question interactive) : fixtures P3B
  `paid`/`payment_review` reçoivent un paiement cohérent ; `payments` retiré des listes
  « table interdite » de P1/P2/P3A/P3B (webhooks/refunds restent interdits).
  Isolation des rollbacks : voir l'entrée dédiée. Aucun trigger/fonction P3B modifié.
- Décisions : aucune nouvelle (implémentation fidèle à D-028 ; strictness = D-028.2).
- Laisse à : review humaine + merge de `p3c-a-payments` ; puis P3C-B webhooks (branche
  dédiée). Aucun webhook HTTP, fournisseur concret, SDK, contrôleur, route créés.

### 2026-07-14 — Claude Code (plan final P3C Paiements & Remboursements)
- Fait : **finalisation documentaire du plan BDD P3C** après validation humaine des
  choix `1A`–`5A`. Décision **D-028** enregistrée. Réécriture de la section P3C de
  `DigiTrove_Schema_BDD_v1.md` : tables `payments`, `payment_webhook_events`, `refunds`
  (provider canonique `VARCHAR(32)` lowercase, `public_id UUID`, `idempotency_key_hash
  VARCHAR(64)`, `attempt_number`, montants `BIGINT` > 0, devise `VARCHAR(3)`), index
  partiels (`one_succeeded`/`one_requires_review` par commande, réf fournisseur, dédup
  webhook signé et invalide-par-hash), catalogue de triggers **T1–T11** (prevent-delete,
  immutabilité + transitions, cohérence immédiate montant/devise, constraint triggers
  différés paiement↔commande et remboursement↔commande, plafond remboursement IMMÉDIAT
  avec `FOR UPDATE`, garde de rétention webhook), orchestrations serveur et plan de tests.
- Divergences corrigées vs brouillon D-024 : `idempotency_key`→`idempotency_key_hash`,
  ajout `public_id`/`attempt_number`, `ON DELETE CASCADE`→`RESTRICT`, suppression du
  `raw_payload` (métadonnées filtrées), provider `TEXT`→`VARCHAR(32)` lowercase, ajout
  cohérence différée + machine à états, webhook invalide minimal + dédup par hash,
  cumul remboursements par trigger immédiat verrouillant (≠ différé P3B).
- État build/tests : `git diff --check` OK ; **aucun fichier PHP touché** (exécution
  documentaire). Tests non relancés (aucun code modifié ; baseline P3B inchangée :
  19 migrations, 62 tests / 650 assertions).
- Décisions prises (→ DECISIONS_LOG.md) : **D-028** intégrité P3C.
- Laisse à : **implémentation P3C sur une branche dédiée** (nouvelle exécution), après
  ce plan validé. Ne rien implémenter ici.

### 2026-07-14 — Codex (clôture post-merge P3B)
- Fait : [PR #5](https://github.com/mysterus44/DigiTrove/pull/5) confirmée et mergée
  dans `p0-foundations-laravel13` via
  `f07d2258c18e196af608f7df97a5816a7cf578f6` ; commits `b42371b` et `499e2bd`
  intégrés, absents de `origin/main` resté à `1e41b92`.
- Fait : audit PostgreSQL post-merge confirmé : tables `orders`, `order_items`,
  `coupon_redemptions`, six fonctions, huit triggers, quatre constraint triggers
  différés et contrainte coupon durcie contre `CHECK = UNKNOWN`.
- État build/tests : `migrate:fresh` OK (19 migrations), tests P3B OK (18 tests,
  337 assertions), suite complète OK (62 tests, 650 assertions), Pint OK (83 fichiers),
  `git diff --check` OK. Rollback P3B isolé automatisé toujours vert.
- Nettoyage : branche locale `p3b-orders` supprimée ; branche distante conservée.
- Laisse à : plan P3C Paiements/Remboursements uniquement, dans une exécution séparée.
  Aucun code P3C n'est démarré.

### 2026-07-14 — Codex (correctif post-review P3B)
- Fait : correction de la contrainte coupon `orders_coupon_snapshot_consistency_check`
  avec branches strictes et `CASE ... ELSE FALSE END IS TRUE`, afin de refuser les
  expressions PostgreSQL `CHECK = UNKNOWN`.
- Fait : durcissement des tests P3B : helpers SQLSTATE/contrainte/message, scénarios
  coupon NULL et branches percent/fixed, reproductions critiques verrouillées, rollback
  automatisé des trois migrations P3B en base PostgreSQL isolée.
- État build/tests : `migrate:fresh` OK (19 migrations), tests P3B OK (18 tests,
  337 assertions), suite complète OK (62 tests, 650 assertions), Pint OK (83 fichiers),
  `git diff --check` OK.
- Laisse à : review finale de `p3b-orders`, puis PR vers `p0-foundations-laravel13`
  si l'audit est propre. P3C reste strictement interdit.

### 2026-07-14 — Codex (implémentation P3B Commandes)
- Fait : branche `p3b-orders` créée depuis `6f7578e`; trois migrations réversibles
  ajoutées pour `orders`, `order_items` et `coupon_redemptions`, sans artefact P3C.
- Fait : immutabilité et suppression physique bloquées par triggers PostgreSQL,
  agrégats/devises et redemption coupon contrôlés au commit par constraint triggers
  différés, snapshots historiques et nullifications FK testés.
- Fait : modèles, `OrderStatus`, factories et relations P1/P2/P3A minimales ajoutés ;
  aucun service, route, contrôleur, paiement, webhook, checkout ou téléchargement.
- État build/tests : 19 migrations et rollback P3B OK ; tests P3B 17/200 ; suite
  complète 61/513 ; Pint 83 fichiers ; diff-check OK.
- Laisse à : review technique et PR de `p3b-orders` vers
  `p0-foundations-laravel13`. P3C reste interdit avant merge et nouveau plan validé.

### 2026-07-14 — Codex (plan final P3B Commandes)
- Fait : garde-fous Git confirmés sur `p0-foundations-laravel13` à `ba48b5d`, local
  synchronisé `0/0` avec origin, `main` intact et aucun artefact P3B/P3C existant.
- Fait : D-027 et le schéma P3B final consignés : immutabilité PostgreSQL, une ligne
  par produit, HMAC client versionné, consommation après paiement, trois migrations et
  constraint triggers différés. `CLAUDE.md` a été réaligné sur l'état P3A réel.
- État build/tests : `git diff --check` OK ; suite PostgreSQL complète OK (44 tests,
  322 assertions) ; Pint OK (72 fichiers). Aucune migration ni logique P3B/P3C créée.
- Décisions prises (→ DECISIONS_LOG.md) : D-027 ; remises P3 limitées aux coupons,
  `expires_at` immuable et compromis `SET NULL` protégé par triggers + permissions BDD.
- Laisse à : validation humaine du plan P3B final avant toute branche ou migration.

### 2026-07-13 — Codex (clôture post-merge P3A)
- Fait : PR #4 confirmée et mergée dans `p0-foundations-laravel13` via
  `234e3034f0e1ea5e20af9ca359d19d799c140072` ; commits `81f32fc` et `1c0d5a2`
  intégrés, absents de `origin/main` resté à `1e41b92`.
- Fait : base locale synchronisée par fast-forward ; branche locale
  `p3a-coupons-carts` supprimée et branche distante conservée.
- État build/tests : `migrate:fresh` OK (16 migrations et 15 tables applicatives
  P1/P2/P3A), tests P3A OK (15 tests, 147 assertions), suite complète OK
  (44 tests, 322 assertions), Pint OK (72 fichiers), diff-check OK.
- Décisions prises (→ DECISIONS_LOG.md) : D-026, P3A clos ; P3B reste derrière
  un plan d'implémentation validé et P3C demeure non démarré.
- Laisse à : plan P3B Commandes uniquement, sans code avant validation humaine.

### 2026-07-13 — Codex (durcissement tests P3A)
- Fait : ajout de deux tests comportementaux PostgreSQL prouvant les cascades
  `carts` → `cart_items` et `coupons` → règles devise/pivots, sans supprimer les
  produits ni catégories référencés.
- Fait : introspection `information_schema.columns` ajoutée pour verrouiller les
  types physiques `citext`, `uuid` et `varchar(3)` ; aucune migration, modèle,
  factory ou logique métier modifié.
- État build/tests : `migrate:fresh` OK (16 migrations), tests P3A OK (15 tests,
  147 assertions), suite complète OK (44 tests, 322 assertions), Pint et diff-check OK.
- Laisse à : review finale puis PR de P3A vers `p0-foundations-laravel13`.
  P3B/P3C restent non démarrés.

### 2026-07-13 — Codex (P3A Coupons et Paniers)
- Fait : audit de reprise classé `P3A NON DÉMARRÉ`, base propre/synchronisée à
  `2288a63`, puis création de `p3a-coupons-carts`. Implémentation stricte des six
  tables P3A, modèles, enums, factories et tests PostgreSQL ; aucun artefact partiel
  de Claude n'était présent à récupérer.
- État build/tests initial : `migrate:fresh`, tests P3A, suite complète, Pint et
  diff-check étaient verts. Les compteurs actuels sont consignés dans l'entrée
  de durcissement de couverture ci-dessus.
- Décisions prises (→ DECISIONS_LOG.md) : D-025, durcissements P3A (`secret_hash`,
  expiration obligatoire, limites positives, FK produit restrictive, index explicites).
- Laisse à : review/PR de P3A vers `p0-foundations-laravel13`. P3B/P3C interdits tant
  que P3A n'est pas revu et mergé.

### 2026-07-13 — Claude Code (plan P3)
- Fait : décisions humaines P3 consignées (D-024) et **plan BDD P3 Commerce finalisé**.
  Réécriture du BLOC COMMERCE de `DigiTrove_Schema_BDD_v1.md` : argent `BIGINT`, devise
  `VARCHAR(3)` uppercase (fin du `CHAR(3)`), prix panier dynamique (aucun prix dans
  `cart_items`), snapshot étendu dans `order_items`, `coupon_currency_rules` (règle
  par devise), un seul coupon par panier/commande (suppression `is_cumulative`), panier
  invité `public_id` + `SHA-256(secret)`, `payments` avec idempotence + un seul
  `succeeded` par commande, `payment_webhook_events` (anti double-webhook, payload
  allowlisté), `refunds` partiels avec garde cumul, `coupon_redemptions`. Aucune
  migration/modèle/logique P3 créé.
- État build/tests : `git diff --check` OK ; `php artisan test` OK (29 tests,
  173 assertions) ; `./vendor/bin/pint --test` OK (55 fichiers) — inchangés (docs seuls).
- Décisions prises (→ DECISIONS_LOG.md) : D-024, décisions de schéma P3 validées.
- Ouvertes : #5 coupon multi-devises tranché (règle par devise) ; non bloquantes à
  confirmer à l'implémentation (expiration panier 7 j, commande pending 30 min,
  anonymisation invité, paiement tardif `requires_review`).
- Laisse à : **validation humaine du plan BDD P3 complet** avant d'écrire la moindre
  migration. Ne pas démarrer P3 (ni Filament, ni paiement, ni webhook, ni download).

### 2026-07-13 — Claude Code (suite)
- Fait : création du point d'entrée `CLAUDE.md` (miroir court d'`AGENTS.md`, sans
  duplication) après confirmation du push manuel de `bc13f13` sur
  `origin/p0-foundations-laravel13`. Documenté via D-023.
- État build/tests : `git diff --check` OK ; `php artisan test` OK (29 tests,
  173 assertions) ; `./vendor/bin/pint --test` OK (55 fichiers) — inchangés,
  `CLAUDE.md` est purement documentaire.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-023, `CLAUDE.md` point
  d'entrée Claude Code miroir d'`AGENTS.md`.
- Laisse à : plan BDD P3 Commerce (aucun code), à faire valider par KingKouda.

### 2026-07-13 — Claude Code
- Fait : audit post-merge P2 et clôture. Merge PR #3 confirmé localement
  (`aff4d05 Merge pull request #3 from mysterus44/p2-catalog`, 2 parents
  `9a11791` + `fbaa33a`, commits `d43751d` + `fbaa33a`) ; `origin/main` intact à
  `1e41b92` et P2 absent de `main`. `p0-foundations-laravel13` synchronisé sur
  `origin/p0-foundations-laravel13` (fast-forward, worktree propre, aucun reset/rebase).
  Branche locale `p2-catalog` supprimée (`git branch -d`, merge confirmé) ;
  `origin/p2-catalog` conservée. `main` local réaligné par pointeur seul
  (`git branch -f main origin/main`) sur `1e41b92` — 3 conditions prouvées
  (main non active, `4f48fc8` ancêtre de `origin/p0-foundations-laravel13`,
  `origin/main` toujours `1e41b92`) ; aucun `4f48fc8`/P1/P2 perdu (tous atteignables
  depuis `p0-foundations-laravel13`).
- État build/tests : post-merge P2 via `digitrove-php:dev` + PostgreSQL réel
  `digitrove_testing` (conteneur `digitrove-postgres-1`) —
  `php artisan migrate:fresh --env=testing` OK (10 migrations : 4 P1 + 6 P2),
  inventaire = `users`, `customer_profiles`, `visitors`, `categories`, `products`,
  `product_prices`, `product_files`, `product_category`, `product_bundles`
  (+ `migrations`) ; extension `citext` présente ; aucune table hors périmètre ;
  `php artisan test` OK (29 tests, 173 assertions) ; `./vendor/bin/pint --test` OK
  (55 fichiers) ; `git diff --check` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-022, P2 clos et mergé ; P3
  Commerce reste derrière un plan BDD validé avant toute migration.
- Note : le SHA `afff4d05` de la consigne humaine est une coquille pour `aff4d05`
  (SHA réel du merge). `CLAUDE.md` est absent de la racine (miroir d'`AGENTS.md` à recréer).
- Laisse à : production du plan BDD P3 Commerce UNIQUEMENT (aucun code), à faire
  valider par KingKouda. Push effectué sur `origin/p0-foundations-laravel13` seul.

### 2026-07-12 — Codex
- Fait : corrections d'audit P2 appliquées sur `p2-catalog` sans élargir le
  périmètre. Ajout des index FK inverses `categories_parent_id_index`,
  `product_category_category_id_index`, `product_bundles_child_product_id_index`,
  officialisation des timestamps catégories et durcissement BDD de
  `product_files.storage_path`.
- État build/tests : `php artisan migrate:fresh --env=testing` OK via PostgreSQL
  réel, `php artisan test` OK (29 tests, 173 assertions),
  `./vendor/bin/pint --test` OK (55 fichiers), `git diff --check` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-021, index relationnels,
  timestamps catégories et chemins privés durcis.
- Laisse à : nouvel audit P2 après validations finales et push de la correction.

### 2026-07-12 — Codex
- Fait : P2 Catalogue implémenté sur `p2-catalog` conformément à la baseline
  publiée. Ajout strict des migrations, modèles, enums, factories et tests pour
  `categories`, `products`, `product_prices`, `product_files`, `product_category`
  et `product_bundles`.
- État build/tests : `php artisan migrate:fresh --env=testing` OK via PostgreSQL
  réel, `php artisan test` OK (29 tests, 173 assertions),
  `./vendor/bin/pint --test` OK (55 fichiers), `git diff --check` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-020, P2 reste un socle
  schéma sans exposition métier ; cycles indirects de bundles non exposés et à
  traiter avant toute écriture métier.
- Laisse à : review humaine de `p2-catalog`, puis PR vers
  `p0-foundations-laravel13` si validations finales vertes. Ne pas démarrer P3.

### 2026-07-11 — Codex
- Fait : décisions humaines P2 enregistrées. Le schéma catalogue est révisé :
  prix sortis de `products`, ajout de `product_prices`, prix fixes par devise,
  bundles tarifés comme produits autonomes via `product_prices`, et
  `product_price_history` reportée. Baseline durcie ensuite : la devise catalogue
  P2 est documentée en `VARCHAR(3)` avec contraintes longueur 3 + majuscules.
- État build/tests : `php artisan test` OK via PostgreSQL réel (17 tests,
  57 assertions), `./vendor/bin/pint --test` OK (38 fichiers).
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-018, prix catalogue
  séparés par devise et bundles autonomes ; D-019, devise catalogue en
  `VARCHAR(3)` contraint.
- Laisse à : validation humaine du plan BDD `P2 — Catalogue` révisé, puis aucune
  migration P2 tant que ce plan n'est pas validé.

### 2026-07-11 — Codex
- Fait : audit post-merge P1 confirmé. La PR #2 a été mergée dans
  `p0-foundations-laravel13` via `3f9d132`, `origin/main` reste intact à
  `1e41b92`, la branche locale `p1-identity` a été supprimée et la branche distante
  `origin/p1-identity` est conservée.
- État build/tests : post-merge P1 sur `p0-foundations-laravel13`,
  `php artisan migrate:fresh --env=testing` OK via PostgreSQL réel avec uniquement
  `users`, `customer_profiles`, `visitors`, `php artisan test` OK (17 tests,
  57 assertions), `./vendor/bin/pint --test` OK (38 fichiers).
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-017, P1 est clos après
  merge ; P2 Catalogue nécessite validation humaine du plan BDD avant tout code.
- Laisse à : validation humaine du plan `P2 — Catalogue`, puis implémentation P2
  uniquement si le plan est validé.

### 2026-07-10 — Codex
- Fait : P1 Identité implémenté sur `p1-identity` après validation officielle du
  schéma BDD v1. Ajout strict de l'extension `citext`, des tables `users`,
  `customer_profiles`, `visitors`, des modèles, enums, factories et tests P1.
- État build/tests : `php artisan migrate:fresh --env=testing` OK via PostgreSQL
  réel (`digitrove_testing`), `php artisan test` OK via PostgreSQL réel (17 tests,
  57 assertions), `./vendor/bin/pint --test` OK (38 fichiers).
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-016, P1 se limite au socle
  identité et au futur rattachement optionnel `visitor -> user`; aucun middleware
  avancé ni checkout invité n'est livré en P1.
- Laisse à : review humaine de `p1-identity`, puis PR vers `p0-foundations-laravel13`.

### 2026-07-10 — Codex
- Fait : audit post-merge SITE-00 confirmé. La PR #1 a été mergée dans
  `p0-foundations-laravel13` via `83b6b0c`, `origin/main` ne contient pas SITE-00,
  la branche locale `site-00-static-preview` a été supprimée et la branche distante
  `origin/site-00-static-preview` est conservée.
- État build/tests : post-merge sur `p0-foundations-laravel13`, `npm run build` OK,
  `php artisan test` OK via `digitrove-php:dev` (5 tests, 20 assertions),
  `./vendor/bin/pint --test` OK via `digitrove-php:dev` (26 fichiers).
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : aucune nouvelle décision ;
  confirmation que SITE-00 reste statique et que P1 reste bloqué.
- Laisse à : validation humaine finale de `DigiTrove_Schema_BDD_v1.md`, puis P1
  Identité seulement si KingKouda valide explicitement le schéma.

### 2026-07-09 — Codex
- Fait : SITE-00 vitrine statique de prévisualisation, avec landing/boutique
  mobile-first, catalogue legacy figé, catégories, avis, FAQ, aperçu blog SEO,
  CTA non transactionnels et images marketing copiées dans `public/images/digitrove/`.
- État build/tests : `php artisan test` OK via `digitrove-php:dev` (5 tests,
  20 assertions), `./vendor/bin/pint --test` OK via `digitrove-php:dev`
  (26 fichiers), `npm run build` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-015, SITE-00 reste une
  prévisualisation statique sans backend métier, sans checkout, sans paiement,
  sans panier et sans téléchargement.
- Laisse à : revue de `site-00-static-preview`, puis validation humaine finale du
  schéma BDD v1. P1 reste bloqué.

### 2026-07-09 — Codex
- Fait : décisions humaines finales pré-P1 documentées : multi-devises, checkout
  invité, compte client suggéré mais non obligatoire, affiliation future avec compte
  obligatoire et tables dédiées hors P1.
- État build/tests : `php artisan test` OK via `digitrove-php:dev` (2 tests,
  2 assertions), `./vendor/bin/pint --test` OK (25 fichiers).
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-014, `currency` obligatoire,
  montants en `BIGINT`, prix fixes par devise recommandés avant P2/P3, achat invité
  via `visitors` + e-mail, affiliation rattachée à `users` sans rôle simple.
- Laisse à : validation humaine finale du schéma corrigé `DigiTrove_Schema_BDD_v1.md`,
  puis P1 Identité strictement limité à `users`, `customer_profiles`, `visitors`
  + extension PostgreSQL `citext`.

### 2026-07-09 — Codex
- Fait : P0.5 assainissement pré-P1, récupération de l'objet Git manquant, isolation
  du legacy sous `legacy/`, correction documentaire du schéma BDD v1.
- État build/tests : `php artisan test` OK via `digitrove-php:dev` (2 tests),
  `./vendor/bin/pint --test` OK (25 fichiers), `php artisan --version` OK
  (Laravel Framework 13.19.0), `git fsck --full` OK sans `missing blob`.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-013, P0.5 avant P1,
  `citext` avant tables, `users.deleted_at` pour SoftDeletes, `status` sans
  `deleted`, analytics/rollups sans FK intentionnels, ordre futur des migrations.
- Laisse à : validation humaine du schéma corrigé `DigiTrove_Schema_BDD_v1.md`,
  puis P1 Identité strictement limité à `users`, `customer_profiles`, `visitors`.

### 2026-07-09 — Codex
- Fait : finalisation documentaire P0, alignement Laravel 13.19 + Filament 5, branche dédiée.
- État build/tests : `docker compose up -d` OK, `php artisan --version` OK,
  `php artisan test` OK, `./vendor/bin/pint --test` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-012, abandon de Laravel 11
  bloqué par advisories Composer/Packagist ; adoption Laravel 13.19 + Filament 5 ;
  aucun contournement Composer.
- Laisse à : revue humaine de `p0-foundations-laravel13`, puis P1 après validation
  humaine du schéma BDD.

### 2026-07-09 — Codex
- Fait : P0 fondations Laravel/Filament/PostgreSQL/Redis/Pest/Pint/CI terminé.
- État build/tests : `docker compose up -d` OK, `php artisan --version` OK,
  `php artisan test` OK, `./vendor/bin/pint --test` OK.
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : D-012, passage à Laravel 13.19
  + Filament 5 pour éviter d'installer Laravel 11 bloqué par advisories Composer.
- Sécurité : `.gitignore` ajouté, `.env` ignoré, `data/users.sqlite` sorti de
  l'index Git, scripts legacy à mots de passe neutralisés.
- Laisse à : P1 — Identité & CRM (`.context/prompts/PRD_01_IDENTITE.md`).

<!-- TEMPLATE à copier en haut à chaque passation :
### AAAA-MM-JJ — [Claude Code | Codex]
- Fait : …
- État build/tests : …
- Décisions prises (→ aussi dans DECISIONS_LOG.md) : …
- Laisse à : … (= réécris le bloc PROCHAINE TÂCHE ci-dessus)
-->
