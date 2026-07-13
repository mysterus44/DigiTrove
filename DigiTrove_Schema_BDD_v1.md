# DigiTrove — Architecture Relationnelle v1
# Cible : PostgreSQL 16 · Laravel 13.19 · Argon2id · Money en entiers
# Auteur : proposé par ARIA-DEV pour KingKouda — à challenger avant migration

---

## 🧭 LES 4 ENTITÉS, ET LEURS SATELLITES

Tu demandes 4 entités. En réalité, chacune a besoin de tables satellites, sinon le
modèle casse dès la première analyse sérieuse. Voici la carte :

| Ton entité | Tables réelles |
|------------|----------------|
| **Utilisateurs / Clients** | `users` (auth) · `customer_profiles` (CRM) · `visitors` (anonymes) |
| **Produits Digitaux** | `products` · `product_prices` · `product_files` (livrables) · `product_bundles` · `categories` |
| **Commandes** | `orders` · `order_items` · `payments` · `download_grants` |
| **Données Analytiques** | `events` (partitionnée) · `analytics_sessions` · `campaigns` · rollups |

### Décisions humaines finales avant P1

- **Multi-devises confirmé** : chaque montant doit porter une `currency` obligatoire.
  Les montants restent en `BIGINT` unités mineures, jamais en `FLOAT`.
- **Prix multi-devises tranchés pour P2** : prix fixes par devise via
  `product_prices`. Conversion automatique et taux de change sont reportés.
- **Checkout invité autorisé** : un visiteur peut acheter sans compte via `visitors`
  + e-mail. Le compte client est fortement suggéré, mais non imposé.
- **Modèle `visitor → user` confirmé** : un visiteur peut devenir utilisateur plus
  tard pour récupérer historique, promotions, annonces, avantages CRM et accès futur
  à l'affiliation.
- **Affiliation future** : elle exige un compte et sera rattachée à `users`, mais ne
  doit pas être modélisée comme un simple rôle utilisateur. Prévoir plus tard des
  tables dédiées (`affiliate_profiles`, `affiliate_links`, `referrals`,
  `affiliate_commissions`, `affiliate_payouts`). Ces tables ne font pas partie de P1.

### Décisions humaines finales avant P2 Catalogue

- **Prix fixes par devise** : chaque produit peut avoir un prix commercial distinct
  par devise. Pas de conversion automatique en P2, pas de table de taux de change.
- **Prix séparés de `products`** : `products` ne porte plus `price_minor`,
  `compare_at_price_minor` ni `currency`. Les prix vivent dans `product_prices`.
- **Bundles tarifés comme produits** : un bundle reste un produit avec
  `products.type = 'bundle'` et possède son propre prix dans `product_prices`,
  indépendant de la somme de ses produits enfants.
- **Historique des prix reporté** : `product_price_history`, promotions avancées,
  conversion automatique, taux de change, checkout, commandes, paiements et
  download grants sont explicitement hors P2.

---

## 🅰️ BLOC IDENTITÉ & CRM

Séparation volontaire : **l'authentification n'est pas le CRM.** Un `users` maigre
et rapide, un `customer_profiles` gras pour le marketing.

```sql
-- Extension PostgreSQL obligatoire avant toute colonne CITEXT.
-- À exécuter dans une migration dédiée avant les tables P1.
CREATE EXTENSION IF NOT EXISTS citext;

-- Authentification uniquement. Table chaude, lue à chaque requête.
CREATE TABLE users (
    id                BIGSERIAL PRIMARY KEY,
    email             CITEXT NOT NULL UNIQUE,
    password_hash     TEXT   NOT NULL,              -- Argon2id
    role              TEXT   NOT NULL DEFAULT 'customer'
                      CHECK (role IN ('customer','admin','staff')),
    status            TEXT   NOT NULL DEFAULT 'active'
                      CHECK (status IN ('active','suspended','blocked')),
    email_verified_at TIMESTAMPTZ,
    last_login_at     TIMESTAMPTZ,
    deleted_at        TIMESTAMPTZ NULL,            -- Laravel SoftDeletes
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Profil CRM. Contient des agrégats DÉNORMALISÉS volontairement :
-- le dashboard ne doit jamais recalculer la LTV sur 10M de lignes.
CREATE TABLE customer_profiles (
    user_id             BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    first_name          TEXT,
    last_name           TEXT,
    phone               TEXT,
    country_code        CHAR(2),
    locale              TEXT DEFAULT 'fr',
    marketing_consent   BOOLEAN NOT NULL DEFAULT false,
    consent_updated_at  TIMESTAMPTZ,
    lifecycle_stage     TEXT NOT NULL DEFAULT 'lead'
                        CHECK (lifecycle_stage IN ('lead','prospect','customer','repeat','churned')),
    -- Rollups maintenus par événement de commande (pas de COUNT() à la volée)
    orders_count        INT    NOT NULL DEFAULT 0,
    lifetime_value_minor BIGINT NOT NULL DEFAULT 0,
    first_order_at      TIMESTAMPTZ,
    last_order_at       TIMESTAMPTZ,
    -- Attribution du PREMIER contact : d'où vient ce client, à vie
    first_touch_source   TEXT,
    first_touch_medium   TEXT,
    first_touch_campaign TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON customer_profiles (lifecycle_stage);
CREATE INDEX ON customer_profiles (last_order_at DESC);

-- 🔑 LA TABLE QUE 90% DES SCHÉMAS E-COMMERCE OUBLIENT.
-- Sans elle, tu ne sauras JAMAIS d'où vient un client : il visite 5 fois
-- anonymement, puis achète. Sans visitor_id, ces 5 visites sont perdues.
-- Le lien visitor -> user est optionnel et progressif : l'achat invité reste autorisé,
-- puis le compte peut être créé plus tard pour rattacher historique et avantages CRM.
CREATE TABLE visitors (
    id                  UUID PRIMARY KEY,            -- posé en cookie 1re partie
    user_id             BIGINT REFERENCES users(id) ON DELETE SET NULL, -- rattaché au login
    first_seen_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    first_touch_source   TEXT,
    first_touch_medium   TEXT,
    first_touch_campaign TEXT,
    first_landing_page   TEXT,
    country_code        CHAR(2)
);
CREATE INDEX ON visitors (user_id);

-- P1 s'arrête strictement ici : extension `citext`, `users`,
-- `customer_profiles`, `visitors`. Aucun catalogue, commerce, affiliation ou
-- événement analytique ne doit être migré en P1.

-- Segmentation CRM. Définition stockée en JSONB = segments dynamiques.
CREATE TABLE customer_segments (
    id          BIGSERIAL PRIMARY KEY,
    name        TEXT NOT NULL,
    description TEXT,
    definition  JSONB NOT NULL,       -- ex: {"lifetime_value_minor": {">=": 50000}}
    is_dynamic  BOOLEAN NOT NULL DEFAULT true,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE customer_segment_members (
    segment_id BIGINT REFERENCES customer_segments(id) ON DELETE CASCADE,
    user_id    BIGINT REFERENCES users(id) ON DELETE CASCADE,
    added_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (segment_id, user_id)
);
```

---

## 🅱️ BLOC CATALOGUE (produits digitaux)

Distinction cruciale : **le produit est une fiche marketing, le fichier est le
livrable.** Un « vaste pack de formation » = 1 produit, 40 fichiers.

```sql
CREATE TABLE categories (
    id        BIGSERIAL PRIMARY KEY,
    parent_id BIGINT REFERENCES categories(id) ON DELETE SET NULL,
    slug      TEXT NOT NULL UNIQUE,
    name      TEXT NOT NULL,
    position  INT  NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON categories (parent_id);

CREATE TABLE products (
    id                    BIGSERIAL PRIMARY KEY,
    slug                  TEXT NOT NULL UNIQUE,      -- SEO
    name                  TEXT NOT NULL,
    type                  TEXT NOT NULL
                          CHECK (type IN ('software','course','ebook','bundle','template')),
    status                TEXT NOT NULL DEFAULT 'draft'
                          CHECK (status IN ('draft','published','archived')),
    short_description     TEXT,
    long_description      TEXT,
    cover_image_path      TEXT,
    meta_title            TEXT,
    meta_description      TEXT,
    -- Rollups pour le tri "meilleures ventes" sans agrégation
    sales_count           INT NOT NULL DEFAULT 0,
    rating_avg            NUMERIC(3,2) DEFAULT 0,
    rating_count          INT NOT NULL DEFAULT 0,
    published_at          TIMESTAMPTZ,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ                -- soft delete Laravel
);
CREATE INDEX ON products (status, published_at DESC);
CREATE INDEX ON products (type);

-- 💰 Prix fixes par devise. Les montants restent en BIGINT, jamais FLOAT/DECIMAL.
-- Un bundle possède son propre prix ici, indépendant de ses produits enfants.
CREATE TABLE product_prices (
    id          BIGSERIAL PRIMARY KEY,
    product_id  BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    currency    VARCHAR(3) NOT NULL CHECK (
                    char_length(currency) = 3
                    AND currency = upper(currency)
                ), -- code ISO 4217 attendu
    price_minor BIGINT NOT NULL CHECK (price_minor >= 0),
    compare_at_price_minor BIGINT,
    is_active   BOOLEAN NOT NULL DEFAULT true,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (product_id, currency),
    CHECK (compare_at_price_minor IS NULL OR compare_at_price_minor >= price_minor)
);
CREATE INDEX ON product_prices (currency, is_active);

CREATE TABLE product_category (
    product_id  BIGINT REFERENCES products(id) ON DELETE CASCADE,
    category_id BIGINT REFERENCES categories(id) ON DELETE CASCADE,
    PRIMARY KEY (product_id, category_id)
);
CREATE INDEX ON product_category (category_id);

-- 🔐 LE LIVRABLE. Jamais d'URL publique. Chemin privé + checksum.
CREATE TABLE product_files (
    id              BIGSERIAL PRIMARY KEY,
    product_id      BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    storage_disk    TEXT NOT NULL DEFAULT 'private',  -- disque Laravel privé / S3
    storage_path    TEXT NOT NULL,                    -- jamais exposé au client
    original_name   TEXT NOT NULL,
    mime_type       TEXT,
    size_bytes      BIGINT NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,                -- intégrité + anti-corruption
    version         TEXT DEFAULT '1.0',
    position        INT NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT true,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (
        length(btrim(storage_path)) > 0
        AND storage_path !~* '^[a-z][a-z0-9+.-]*://'
        AND storage_path !~ '^[A-Za-z]:[\\/]'
        AND storage_path !~ '^[\\/]'
        AND storage_path !~ '(^|[\\/])public([\\/]|$)'
        AND storage_path !~ '(^|[\\/])\.\.([\\/]|$)'
    )
);
CREATE INDEX ON product_files (product_id, is_active);

-- Packs : un produit "bundle" contient d'autres produits.
CREATE TABLE product_bundles (
    bundle_id BIGINT REFERENCES products(id) ON DELETE CASCADE,
    child_product_id  BIGINT REFERENCES products(id) ON DELETE CASCADE,
    position  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (bundle_id, child_product_id),
    CHECK (bundle_id <> child_product_id)       -- pas d'auto-inclusion directe
);
CREATE INDEX ON product_bundles (child_product_id);

-- Note P2 : la prévention des cycles indirects de bundles (A contient B qui contient A)
-- sera traitée au niveau Service/tests plus tard, pas par cette contrainte SQL simple.

-- Reporté hors P2 : audit/historique des prix, conversion automatique de devises,
-- taux de change, promotions avancées, checkout, commandes, paiements,
-- download_grants et toute livraison active.

-- Licences (logiciels). Optionnel selon ton catalogue.
-- Note migration : cette table se crée après `order_items`, car elle y référence.
CREATE TABLE licenses (
    id               BIGSERIAL PRIMARY KEY,
    product_id       BIGINT NOT NULL REFERENCES products(id),
    order_item_id    BIGINT REFERENCES order_items(id) ON DELETE SET NULL,
    user_id          BIGINT REFERENCES users(id) ON DELETE SET NULL,
    license_key_hash TEXT NOT NULL UNIQUE,      -- on stocke le HASH, pas la clé
    activation_limit INT NOT NULL DEFAULT 1,
    activations_count INT NOT NULL DEFAULT 0,
    expires_at       TIMESTAMPTZ,
    revoked_at       TIMESTAMPTZ,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE reviews (
    id         BIGSERIAL PRIMARY KEY,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    user_id    BIGINT REFERENCES users(id) ON DELETE SET NULL,
    rating     SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    body       TEXT,
    status     TEXT NOT NULL DEFAULT 'pending'
               CHECK (status IN ('pending','approved','rejected')),
    -- Preuve d'achat : un avis vérifié vaut dix avis anonymes
    verified_purchase BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON reviews (product_id, status);
```

---

## 🅲 BLOC COMMERCE — P3 (carts → orders → order_items → payments → refunds)

> **Révisé P3 (D-024).** Argent en `BIGINT`, devise `VARCHAR(3)` uppercase (jamais
> CHAR(3)/FLOAT/REAL/DOUBLE/DECIMAL/NUMERIC). Prix recalculé dynamiquement (aucun prix
> dans `cart_items`), snapshot définitif uniquement dans `order_items`. Un seul coupon
> par panier et par commande (aucun cumul, aucune notion `is_cumulative`). Panier invité
> = UUID public opaque + `SHA-256(secret)` (secret jamais stocké). Ordre de migration :
> `coupons` → `coupon_currency_rules` → `coupon_products` → `coupon_categories` →
> `carts` → `cart_items` → `orders` → `order_items` → `payments` →
> `payment_webhook_events` → `refunds` → `coupon_redemptions`.

```sql
-- ===== P3 COMMERCE =====

-- Coupon : code insensible à la casse. Fixe => montants dans coupon_currency_rules ;
-- pourcentage => percent_basis_points (1..10000 = 0,01 %..100 %).
CREATE TABLE coupons (
    id                   BIGSERIAL PRIMARY KEY,
    code                 CITEXT  NOT NULL UNIQUE,           -- insensible à la casse (D-024)
    discount_type        TEXT    NOT NULL CHECK (discount_type IN ('percent','fixed')),
    percent_basis_points INT     CHECK (percent_basis_points BETWEEN 1 AND 10000), -- NULL si fixed
    max_redemptions      INT     CHECK (max_redemptions IS NULL OR max_redemptions >= 0),
    redemptions_count    INT     NOT NULL DEFAULT 0 CHECK (redemptions_count >= 0),
    max_redemptions_per_customer INT CHECK (max_redemptions_per_customer IS NULL OR max_redemptions_per_customer >= 0),
    starts_at            TIMESTAMPTZ,
    ends_at              TIMESTAMPTZ,
    is_active            BOOLEAN NOT NULL DEFAULT true,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- Cohérence type <-> pourcentage (mono-ligne, garantie par CHECK) :
    CHECK (
        (discount_type = 'percent' AND percent_basis_points IS NOT NULL) OR
        (discount_type = 'fixed'   AND percent_basis_points IS NULL)
    ),
    CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at >= starts_at)
);
-- ⚠️ Qu'un coupon 'fixed' possède AU MOINS une ligne coupon_currency_rules N'EST PAS
-- vérifiable par un CHECK mono-ligne (ça dépend d'une autre table). Garantie par le
-- futur CouponService (validation transactionnelle) + tests ; un trigger de cohérence
-- reste optionnel. Les contraintes internes d'une ligne restent en BDD (ci-dessous).

-- Règle de remise PAR DEVISE (remplace l'ancien coupon_amounts) : aucune conversion
-- automatique (D-018/D-024). fixed_amount_minor => remise fixe ; max_discount_minor =>
-- plafond d'une remise pourcentage ; min_order_minor => minimum d'éligibilité.
CREATE TABLE coupon_currency_rules (
    id                 BIGSERIAL PRIMARY KEY,
    coupon_id          BIGINT NOT NULL REFERENCES coupons(id) ON DELETE CASCADE,
    currency           VARCHAR(3) NOT NULL
                       CHECK (char_length(currency) = 3 AND currency = upper(currency)),
    fixed_amount_minor BIGINT CHECK (fixed_amount_minor IS NULL OR fixed_amount_minor >= 0),
    min_order_minor    BIGINT NOT NULL DEFAULT 0 CHECK (min_order_minor >= 0),
    max_discount_minor BIGINT CHECK (max_discount_minor IS NULL OR max_discount_minor >= 0),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (coupon_id, currency)
);

-- Éligibilité : aucune ligne => coupon global. Sinon restreint aux cibles listées.
CREATE TABLE coupon_products (
    coupon_id  BIGINT NOT NULL REFERENCES coupons(id) ON DELETE CASCADE,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    PRIMARY KEY (coupon_id, product_id)
);
CREATE TABLE coupon_categories (
    coupon_id   BIGINT NOT NULL REFERENCES coupons(id) ON DELETE CASCADE,
    category_id BIGINT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    PRIMARY KEY (coupon_id, category_id)
);

-- Panier : source n°1 de relance marketing. Invité = public_id UUID opaque + secret
-- aléatoire transmis SEULEMENT dans un cookie sécurisé (HttpOnly, SameSite, signé/chiffré) ;
-- en base on ne garde que SHA-256(secret). Mono-devise. Un seul coupon (coupon_id).
CREATE TABLE carts (
    id           BIGSERIAL PRIMARY KEY,
    public_id    UUID NOT NULL UNIQUE,             -- identifiant opaque exposé
    secret_hash  TEXT,                             -- SHA-256(secret) ; secret JAMAIS stocké
    visitor_id   UUID   REFERENCES visitors(id) ON DELETE SET NULL,
    user_id      BIGINT REFERENCES users(id) ON DELETE SET NULL,   -- suppression prudente
    coupon_id    BIGINT REFERENCES coupons(id) ON DELETE SET NULL, -- un seul coupon
    currency     VARCHAR(3)                        -- NULL jusqu'au 1er produit ; mono-devise
                 CHECK (currency IS NULL OR (char_length(currency)=3 AND currency=upper(currency))),
    status       TEXT NOT NULL DEFAULT 'active'
                 CHECK (status IN ('active','converted','abandoned','expired')),
    expires_at   TIMESTAMPTZ,
    abandoned_at TIMESTAMPTZ,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON carts (status, expires_at);
CREATE INDEX ON carts (user_id);
CREATE INDEX ON carts (visitor_id);

-- AUCUN prix / remise / devise ici : prix recalculé dynamiquement au checkout (D-024).
CREATE TABLE cart_items (
    id         BIGSERIAL PRIMARY KEY,
    cart_id    BIGINT NOT NULL REFERENCES carts(id) ON DELETE CASCADE,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    quantity   INT NOT NULL DEFAULT 1 CHECK (quantity >= 1),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (cart_id, product_id)
);
CREATE INDEX ON cart_items (cart_id);

-- Checkout invité autorisé : user_id nullable, visitor_id + email snapshot suffisent.
-- Le statut `failed` ne représente PAS une tentative de paiement échouée (celle-ci vit
-- dans payments.status) : une commande dont le paiement échoue reste `pending`/`payment_review`.
CREATE TABLE orders (
    id              BIGSERIAL PRIMARY KEY,
    order_number    TEXT NOT NULL UNIQUE,           -- DGT-2026-000123, jamais l'id
    user_id         BIGINT REFERENCES users(id) ON DELETE SET NULL,  -- NULL = invité
    visitor_id      UUID   REFERENCES visitors(id) ON DELETE SET NULL,
    email           CITEXT NOT NULL,                -- snapshot client, même en invité
    status          TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','payment_review','paid','partially_refunded','refunded','cancelled','expired')),
    currency        VARCHAR(3) NOT NULL
                    CHECK (char_length(currency)=3 AND currency=upper(currency)),
    subtotal_minor  BIGINT NOT NULL DEFAULT 0 CHECK (subtotal_minor >= 0),
    discount_minor  BIGINT NOT NULL DEFAULT 0 CHECK (discount_minor >= 0),
    tax_minor       BIGINT NOT NULL DEFAULT 0 CHECK (tax_minor >= 0),
    total_minor     BIGINT NOT NULL DEFAULT 0 CHECK (total_minor >= 0),
    coupon_id       BIGINT REFERENCES coupons(id) ON DELETE SET NULL,  -- un seul coupon (D-024)
    coupon_code_snapshot           TEXT,            -- figés à la commande
    coupon_type_snapshot           TEXT CHECK (coupon_type_snapshot IS NULL OR coupon_type_snapshot IN ('percent','fixed')),
    coupon_discount_minor_snapshot BIGINT CHECK (coupon_discount_minor_snapshot IS NULL OR coupon_discount_minor_snapshot >= 0),
    -- 📊 Attribution figée AU MOMENT DE L'ACHAT. Ne jamais la recalculer.
    utm_source      TEXT,
    utm_medium      TEXT,
    utm_campaign    TEXT,
    placed_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    paid_at         TIMESTAMPTZ,
    expires_at      TIMESTAMPTZ,                    -- péremption d'une commande pending
    ip_hash         TEXT,                           -- haché, RGPD
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (total_minor = subtotal_minor - discount_minor + tax_minor)
);
CREATE INDEX ON orders (user_id, placed_at DESC);
CREATE INDEX ON orders (status, placed_at DESC);
CREATE INDEX ON orders (email);
CREATE INDEX ON orders (visitor_id);
-- Un seul coupon par commande via coupon_id (pas de pivot commande/coupons).

-- 🔴 LE POINT LE PLUS IMPORTANT DU SCHÉMA : on SNAPSHOT nom, slug, type et prix.
-- Si tu changes/archives/supprimes le produit demain, les factures d'hier NE BOUGENT
-- PAS. Jamais de JOIN sur products pour un prix historique. product_id nullable.
CREATE TABLE order_items (
    id                    BIGSERIAL PRIMARY KEY,
    order_id              BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id            BIGINT REFERENCES products(id) ON DELETE SET NULL,  -- nullable (D-024)
    product_name_snapshot TEXT   NOT NULL,
    product_slug_snapshot TEXT,
    product_type_snapshot TEXT   NOT NULL,
    unit_price_minor      BIGINT NOT NULL CHECK (unit_price_minor >= 0),
    quantity              INT    NOT NULL DEFAULT 1 CHECK (quantity >= 1),
    line_subtotal_minor   BIGINT NOT NULL CHECK (line_subtotal_minor >= 0),
    line_discount_minor   BIGINT NOT NULL DEFAULT 0 CHECK (line_discount_minor >= 0),
    line_total_minor      BIGINT NOT NULL CHECK (line_total_minor >= 0),
    currency              VARCHAR(3) NOT NULL
                          CHECK (char_length(currency)=3 AND currency=upper(currency)),
    CHECK (line_subtotal_minor = unit_price_minor * quantity),
    CHECK (line_total_minor    = line_subtotal_minor - line_discount_minor)
);
CREATE INDEX ON order_items (order_id);
CREATE INDEX ON order_items (product_id);

-- Une commande peut avoir PLUSIEURS tentatives de paiement. Séparer paiement et
-- commande, sinon les retries corrompent l'état. Aucune validation sur retour navigateur.
CREATE TABLE payments (
    id              BIGSERIAL PRIMARY KEY,
    order_id        BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    provider        TEXT   NOT NULL,               -- cinetpay, wave, stripe...
    provider_ref    TEXT,                          -- référence côté opérateur
    idempotency_key TEXT   NOT NULL UNIQUE,        -- 🔐 généré côté DigiTrove, 1 par tentative
    amount_minor    BIGINT NOT NULL CHECK (amount_minor >= 0),
    currency        VARCHAR(3) NOT NULL
                    CHECK (char_length(currency)=3 AND currency=upper(currency)),
    status          TEXT   NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','processing','requires_review','succeeded','failed','cancelled','expired')),
    raw_payload     JSONB,                         -- filtré/allowlisté avant stockage
    processed_at    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON payments (order_id);
-- Référence fournisseur unique quand elle existe :
CREATE UNIQUE INDEX ON payments (provider, provider_ref) WHERE provider_ref IS NOT NULL;
-- 🔐 Un seul encaissement final valide par commande :
CREATE UNIQUE INDEX ON payments (order_id) WHERE status = 'succeeded';

-- Idempotence des webhooks : un événement fournisseur rejoué est refusé en base.
-- JAMAIS de secret fournisseur, signature brute, PAN, ni token de paiement réutilisable.
CREATE TABLE payment_webhook_events (
    id                BIGSERIAL PRIMARY KEY,
    provider          TEXT   NOT NULL,
    external_event_id TEXT   NOT NULL,
    payment_id        BIGINT REFERENCES payments(id) ON DELETE SET NULL,
    signature_valid   BOOLEAN NOT NULL,
    payload_sha256    TEXT,                         -- empreinte du brut, pour audit
    payload_filtered  JSONB,                        -- allowlist stricte uniquement
    received_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    processed_at      TIMESTAMPTZ,
    process_status    TEXT NOT NULL DEFAULT 'received'
                      CHECK (process_status IN ('received','processed','ignored','failed')),
    purge_after       TIMESTAMPTZ,                  -- politique de rétention documentée
    UNIQUE (provider, external_event_id)            -- 🔐 anti double-webhook
);
CREATE INDEX ON payment_webhook_events (payment_id);

-- Remboursements partiels et multiples possibles. Devise = celle du paiement.
CREATE TABLE refunds (
    id                  BIGSERIAL PRIMARY KEY,
    payment_id          BIGINT NOT NULL REFERENCES payments(id) ON DELETE RESTRICT,
    amount_minor        BIGINT NOT NULL CHECK (amount_minor > 0),
    currency            VARCHAR(3) NOT NULL
                        CHECK (char_length(currency)=3 AND currency=upper(currency)),
    provider_refund_ref TEXT,
    status              TEXT NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','succeeded','failed')),
    reason              TEXT,
    created_by          BIGINT REFERENCES users(id) ON DELETE SET NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON refunds (payment_id);
CREATE UNIQUE INDEX ON refunds (payment_id, provider_refund_ref) WHERE provider_refund_ref IS NOT NULL;
-- ⚠️ SUM(refunds.amount_minor WHERE status='succeeded') <= payments.amount_minor et
-- refunds.currency = payments.currency : NON exprimables par CHECK mono-ligne. Garantie
-- par un trigger PostgreSQL transactionnel (SELECT ... FOR UPDATE sur payments) + le
-- futur RefundService + tests de concurrence. À ne pas confier à PHP seul.

-- Consommation de coupon : 1 seule par commande (UNIQUE order_id). Permet le contrôle
-- des plafonds global / par email. L'incrément SÛR (course entre 2 commandes) exige un
-- verrou transactionnel dans CouponService ; la BDD garantit l'unicité par commande.
CREATE TABLE coupon_redemptions (
    id             BIGSERIAL PRIMARY KEY,
    coupon_id      BIGINT NOT NULL REFERENCES coupons(id) ON DELETE RESTRICT,
    order_id       BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    email          CITEXT NOT NULL,                 -- client normalisé (plafond par email)
    user_id        BIGINT REFERENCES users(id) ON DELETE SET NULL,
    discount_minor BIGINT NOT NULL CHECK (discount_minor >= 0),
    currency       VARCHAR(3) NOT NULL
                   CHECK (char_length(currency)=3 AND currency=upper(currency)),
    redeemed_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (order_id)                               -- 🔐 un seul coupon consommé par commande
);
CREATE INDEX ON coupon_redemptions (coupon_id);
CREATE INDEX ON coupon_redemptions (coupon_id, email);

-- ===== P4 LIVRAISON (HORS P3 — référence uniquement, non migré en P3) =====

-- 🔐 LIVRAISON SÉCURISÉE : le cœur de ton business digital.
-- On ne donne JAMAIS l'URL du fichier. On donne un token, dont on ne stocke
-- que le HASH (exactement comme un mot de passe).
CREATE TABLE download_grants (
    id              BIGSERIAL PRIMARY KEY,
    order_item_id   BIGINT NOT NULL REFERENCES order_items(id) ON DELETE CASCADE,
    product_file_id BIGINT NOT NULL REFERENCES product_files(id) ON DELETE CASCADE,
    user_id         BIGINT REFERENCES users(id) ON DELETE SET NULL,
    token_hash      TEXT   NOT NULL UNIQUE,        -- SHA-256 du token envoyé
    expires_at      TIMESTAMPTZ NOT NULL,          -- ex: +72h
    max_downloads   INT    NOT NULL DEFAULT 5,
    downloads_count INT    NOT NULL DEFAULT 0,
    revoked_at      TIMESTAMPTZ,                   -- révocation si fraude/remboursement
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON download_grants (order_item_id);
CREATE INDEX ON download_grants (expires_at);

-- Journal de téléchargement : détection d'abus (partage de lien)
CREATE TABLE download_logs (
    id         BIGSERIAL PRIMARY KEY,
    grant_id   BIGINT NOT NULL REFERENCES download_grants(id) ON DELETE CASCADE,
    ip_hash    TEXT,
    user_agent TEXT,
    bytes_sent BIGINT,
    status     TEXT NOT NULL CHECK (status IN ('started','completed','denied')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON download_logs (grant_id, created_at DESC);
```

---

## 🅳 BLOC ANALYTIQUE (le plus mal conçu d'habitude)

**Principe non négociable : l'analytique ne partage pas les tables chaudes du
commerce.** Écrire 500 events/seconde sur une table liée par clé étrangère à
`orders` finira par ralentir tes paiements.

```sql
-- Table APPEND-ONLY, partitionnée par mois.
-- Pas de FK vers users/orders : on garde les ids en colonnes "molles".
-- Objectif : écritures massives, jamais de verrou sur le transactionnel.
CREATE TABLE events (
    id           BIGSERIAL,
    occurred_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    visitor_id   UUID,
    user_id      BIGINT,               -- volontairement SANS clé étrangère
    session_id   UUID,
    event_name   TEXT NOT NULL,        -- page_view, add_to_cart, purchase...
    entity_type  TEXT,                 -- product, order, article
    entity_id    BIGINT,
    properties   JSONB NOT NULL DEFAULT '{}',   -- flexible, indexable en GIN
    page_url     TEXT,
    referrer     TEXT,
    utm_source   TEXT,
    utm_medium   TEXT,
    utm_campaign TEXT,
    device_type  TEXT,
    country_code CHAR(2),
    ip_hash      TEXT,                 -- haché, jamais l'IP brute (RGPD)
    PRIMARY KEY (id, occurred_at)
) PARTITION BY RANGE (occurred_at);

-- Une partition par mois. Purger = DROP PARTITION (instantané).
CREATE TABLE events_2026_07 PARTITION OF events
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');

CREATE INDEX ON events (event_name, occurred_at DESC);
CREATE INDEX ON events (visitor_id, occurred_at DESC);
CREATE INDEX ON events USING GIN (properties);   -- requêtes sur le JSONB

-- Sessions analytiques (pour taux de rebond, durée, parcours)
-- Pas de FK volontairement : découplage analytique des tables chaudes.
CREATE TABLE analytics_sessions (
    id           UUID PRIMARY KEY,
    visitor_id   UUID NOT NULL,
    user_id      BIGINT,
    started_at   TIMESTAMPTZ NOT NULL,
    ended_at     TIMESTAMPTZ,
    entry_page   TEXT,
    exit_page    TEXT,
    page_views   INT NOT NULL DEFAULT 0,
    utm_source   TEXT,
    utm_medium   TEXT,
    utm_campaign TEXT,
    device_type  TEXT,
    country_code CHAR(2)
);
CREATE INDEX ON analytics_sessions (visitor_id, started_at DESC);

-- Campagnes marketing (le "M" de ton back-office)
CREATE TABLE campaigns (
    id            BIGSERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    channel       TEXT NOT NULL,   -- email, facebook, tiktok, seo, affiliate
    utm_campaign  TEXT UNIQUE,     -- la jointure logique avec events/orders
    budget_minor  BIGINT DEFAULT 0,
    starts_at     DATE,
    ends_at       DATE,
    is_active     BOOLEAN NOT NULL DEFAULT true,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Affiliation future (post-P1) :
-- tables dédiées à prévoir plus tard, par exemple `affiliate_profiles`,
-- `affiliate_links`, `referrals`, `affiliate_commissions`, `affiliate_payouts`.
-- Un affilié doit être rattaché à un `user`; ce n'est pas un simple rôle.

-- 📊 ROLLUPS : le dashboard lit CES tables, jamais `events` directement.
-- Rafraîchis par un job planifié (Laravel Scheduler, toutes les heures).
-- Pas de FK volontairement : snapshots analytiques recalculables et découplés.
CREATE TABLE daily_sales_stats (
    day               DATE PRIMARY KEY,
    orders_count      INT NOT NULL DEFAULT 0,
    revenue_minor     BIGINT NOT NULL DEFAULT 0,
    refunds_minor     BIGINT NOT NULL DEFAULT 0,
    new_customers     INT NOT NULL DEFAULT 0,
    avg_order_minor   BIGINT NOT NULL DEFAULT 0
);

CREATE TABLE daily_product_stats (
    day           DATE   NOT NULL,
    product_id    BIGINT NOT NULL,
    views         INT NOT NULL DEFAULT 0,
    add_to_carts  INT NOT NULL DEFAULT 0,
    purchases     INT NOT NULL DEFAULT 0,
    revenue_minor BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (day, product_id)
);

-- Tunnel de conversion : le chiffre que tu regarderas tous les matins
CREATE TABLE daily_funnel_stats (
    day             DATE PRIMARY KEY,
    visitors        INT NOT NULL DEFAULT 0,
    product_views   INT NOT NULL DEFAULT 0,
    add_to_carts    INT NOT NULL DEFAULT 0,
    checkouts       INT NOT NULL DEFAULT 0,
    purchases       INT NOT NULL DEFAULT 0
);
```

---

## 🔗 LES RELATIONS EN UN COUP D'ŒIL

```
visitors ──(login)──> users ──1:1──> customer_profiles
   │                    │
   │                    ├──1:N──> orders ──1:N──> order_items ──1:N──> download_grants
   │                    │            │                 │                     │
   │                    │            └──1:N──> payments│                     └──1:N──> download_logs
   │                    │                              │
   │                    └──N:M──> customer_segments    └──> products ──1:N──> product_prices
   │                                                          │
   │                                                          ├──1:N──> product_files
   │                                                          │
   └──1:N──> analytics_sessions ──1:N──> events               └──N:M──> categories
                                            ↑                 └──N:M──> product_bundles (self)
                          campaigns ──(utm_campaign)──┘
```

---

## ⚔️ CE QUE JE CHALLENGE DANS TA DEMANDE

**1. « SQL » → prends PostgreSQL, pas MySQL.**
Tu parles de Big Data et d'analyse poussée. PostgreSQL te donne le partitionnement
natif, JSONB indexable (GIN), les window functions, et un chemin de sortie vers
ClickHouse/DuckDB le jour où tu dépasses 100M d'events. MySQL te bloquera.

**2. « Architecture type Laravel » → prends Laravel, pas « type Laravel ».**
Ton indépendance ne vient pas de l'absence de framework, elle vient de l'absence
de plateforme tierce (Shopify, Gumroad). Laravel est un outil, pas une dépendance
commerciale. « Type Laravel » = tu réécris mal ce que Laravel fait bien.

**3. Filament plutôt que Nova.**
Filament est gratuit, plus moderne, et couvre ton besoin CRM/ERP. Nova est payant
et n'apporte rien de plus ici. Aucune raison de payer.

**4. Ne mets jamais l'argent en FLOAT.**
`price DECIMAL` à la rigueur, `BIGINT` en unités mineures de préférence. En XOF il
n'y a pas de centimes : un entier suffit et ne dérive jamais.

**5. Le snapshot de prix dans `order_items` n'est pas négociable.**
C'est la faute qui coûte le plus cher : sans lui, une promo appliquée demain
réécrit ta comptabilité d'hier. Ton expert-comptable ne s'en remettra pas.

**6. `visitors` avant tout le reste.**
Sans identité anonyme persistante, tu ne pourras jamais répondre à « ce client de
150 000 FCFA, il vient de quelle campagne ? ». Et donc jamais optimiser ton
marketing. C'est la table qui rend le CRM utile plutôt que décoratif.

**7. Le token de téléchargement se hache.**
Un lien expirable qui stocke le token en clair en base, c'est un mot de passe en
clair. On stocke `token_hash`, on compare, on incrémente, on révoque.

---

## ▶️ ORDRE D'IMPLÉMENTATION SUGGÉRÉ

Ordre technique des migrations à respecter avant P1 :

0. Extensions PostgreSQL (`citext`) avant toute table utilisant `CITEXT`. ✅ (P1)
1. Types/enums/checks partagés avant usage.
2. P1 strictement limité à `users`, `customer_profiles`, `visitors` + extension `citext`. ✅ mergé
3. P2 : `categories`, `products`, `product_prices`, `product_files`, `product_category`,
   `product_bundles`. ✅ mergé (PR #3).
4. P3 Commerce — ordre exact (D-024) :
   `coupons` → `coupon_currency_rules` → `coupon_products` → `coupon_categories` →
   `carts` → `cart_items` → `orders` → `order_items` → `payments` →
   `payment_webhook_events` → `refunds` → `coupon_redemptions`.
   (Coupons avant `orders` car `orders.coupon_id` est une FK ; `orders` avant `order_items`.)
5. `licenses` après `order_items` (P4, optionnel).

1. `users` + `customer_profiles` + `visitors` (fondation identité) ✅
2. `categories` + `products` + `product_prices` + `product_files` + pivots catalogue ✅
3. Commerce P3 (bloc ci-dessus) — plan validé, aucune migration écrite tant que non validé
4. `download_grants` + `download_logs` (P4, livraison sécurisée)
5. `events` partitionnée + rollups (analytique)
6. `campaigns` + `customer_segments` (marketing)
7. Affiliation dédiée (`affiliate_profiles`, `affiliate_links`, `referrals`,
   `affiliate_commissions`, `affiliate_payouts`) après validation produit ultérieure

Ne code aucune logique métier avant que 1→4 soient migrés et testés.
