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
| **Produits Digitaux** | `products` · `product_files` (livrables) · `product_bundles` · `categories` |
| **Commandes** | `orders` · `order_items` · `payments` · `download_grants` |
| **Données Analytiques** | `events` (partitionnée) · `analytics_sessions` · `campaigns` · rollups |

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
    position  INT  NOT NULL DEFAULT 0
);

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
    -- 💰 ENTIERS. Jamais FLOAT sur de l'argent. XOF : minor = 1.
    price_minor           BIGINT NOT NULL CHECK (price_minor >= 0),
    compare_at_price_minor BIGINT CHECK (compare_at_price_minor >= 0), -- prix barré
    currency              CHAR(3) NOT NULL DEFAULT 'XOF',
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

-- Audit des prix. Un prix qui change ne doit pas effacer l'histoire.
CREATE TABLE product_price_history (
    id          BIGSERIAL PRIMARY KEY,
    product_id  BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    price_minor BIGINT NOT NULL,
    changed_by  BIGINT REFERENCES users(id),
    changed_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE product_category (
    product_id  BIGINT REFERENCES products(id) ON DELETE CASCADE,
    category_id BIGINT REFERENCES categories(id) ON DELETE CASCADE,
    PRIMARY KEY (product_id, category_id)
);

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
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON product_files (product_id, is_active);

-- Packs : un produit "bundle" contient d'autres produits.
CREATE TABLE product_bundles (
    bundle_id BIGINT REFERENCES products(id) ON DELETE CASCADE,
    child_id  BIGINT REFERENCES products(id) ON DELETE CASCADE,
    position  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (bundle_id, child_id),
    CHECK (bundle_id <> child_id)               -- pas d'auto-inclusion
);

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

## 🅲 BLOC COMMERCE (commandes, paiement, livraison sécurisée)

```sql
-- Panier : la source n°1 de revenus marketing (relance panier abandonné).
CREATE TABLE carts (
    id           BIGSERIAL PRIMARY KEY,
    visitor_id   UUID REFERENCES visitors(id) ON DELETE SET NULL,
    user_id      BIGINT REFERENCES users(id) ON DELETE CASCADE,
    status       TEXT NOT NULL DEFAULT 'active'
                 CHECK (status IN ('active','converted','abandoned')),
    abandoned_at TIMESTAMPTZ,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE cart_items (
    id         BIGSERIAL PRIMARY KEY,
    cart_id    BIGINT NOT NULL REFERENCES carts(id) ON DELETE CASCADE,
    product_id BIGINT NOT NULL REFERENCES products(id),
    quantity   INT NOT NULL DEFAULT 1 CHECK (quantity > 0),
    UNIQUE (cart_id, product_id)
);

-- Note migration : créer `coupons` avant `orders` si `orders.coupon_id` reste une FK.
CREATE TABLE coupons (
    id             BIGSERIAL PRIMARY KEY,
    code           TEXT NOT NULL UNIQUE,
    discount_type  TEXT NOT NULL CHECK (discount_type IN ('percent','fixed')),
    discount_value BIGINT NOT NULL,
    max_redemptions INT,
    redemptions_count INT NOT NULL DEFAULT 0,
    starts_at      TIMESTAMPTZ,
    ends_at        TIMESTAMPTZ,
    is_active      BOOLEAN NOT NULL DEFAULT true
);

CREATE TABLE orders (
    id              BIGSERIAL PRIMARY KEY,
    order_number    TEXT NOT NULL UNIQUE,          -- DGT-2026-000123, jamais l'id
    user_id         BIGINT REFERENCES users(id) ON DELETE SET NULL, -- NULL = invité
    visitor_id      UUID   REFERENCES visitors(id) ON DELETE SET NULL,
    email           CITEXT NOT NULL,               -- même en invité
    status          TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','paid','failed','refunded','partially_refunded','cancelled')),
    subtotal_minor  BIGINT NOT NULL,
    discount_minor  BIGINT NOT NULL DEFAULT 0,
    tax_minor       BIGINT NOT NULL DEFAULT 0,
    total_minor     BIGINT NOT NULL,
    currency        CHAR(3) NOT NULL DEFAULT 'XOF',
    coupon_id       BIGINT REFERENCES coupons(id) ON DELETE SET NULL,
    -- 📊 Attribution figée AU MOMENT DE L'ACHAT. Ne jamais la recalculer.
    utm_source      TEXT,
    utm_medium      TEXT,
    utm_campaign    TEXT,
    placed_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    paid_at         TIMESTAMPTZ,
    ip_hash         TEXT,                          -- haché, RGPD
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON orders (user_id, placed_at DESC);
CREATE INDEX ON orders (status, placed_at DESC);
CREATE INDEX ON orders (email);

-- 🔴 LE POINT LE PLUS IMPORTANT DU SCHÉMA :
-- on SNAPSHOT le nom et le prix. Si tu changes le prix d'un produit demain,
-- les factures d'hier NE DOIVENT PAS changer. Jamais de JOIN sur products
-- pour retrouver un prix historique.
CREATE TABLE order_items (
    id                   BIGSERIAL PRIMARY KEY,
    order_id             BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id           BIGINT REFERENCES products(id) ON DELETE SET NULL,
    product_name_snapshot TEXT   NOT NULL,
    product_type_snapshot TEXT   NOT NULL,
    unit_price_minor     BIGINT NOT NULL,
    quantity             INT    NOT NULL DEFAULT 1 CHECK (quantity > 0),
    line_total_minor     BIGINT NOT NULL
);
CREATE INDEX ON order_items (order_id);
CREATE INDEX ON order_items (product_id);

-- Une commande peut avoir PLUSIEURS tentatives de paiement.
-- Séparer paiement et commande, sinon les retries corrompent l'état.
CREATE TABLE payments (
    id              BIGSERIAL PRIMARY KEY,
    order_id        BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    provider        TEXT   NOT NULL,               -- cinetpay, wave, stripe...
    provider_ref    TEXT,                          -- référence côté opérateur
    idempotency_key TEXT   NOT NULL UNIQUE,        -- 🔐 anti double-traitement webhook
    amount_minor    BIGINT NOT NULL,
    currency        CHAR(3) NOT NULL,
    status          TEXT   NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','succeeded','failed','refunded')),
    raw_payload     JSONB,                         -- réponse brute, pour audit
    processed_at    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ON payments (order_id);
CREATE INDEX ON payments (provider, provider_ref);

CREATE TABLE refunds (
    id           BIGSERIAL PRIMARY KEY,
    payment_id   BIGINT NOT NULL REFERENCES payments(id),
    amount_minor BIGINT NOT NULL,
    reason       TEXT,
    created_by   BIGINT REFERENCES users(id),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

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
   │                    └──N:M──> customer_segments    └──> products ──1:N──> product_files
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

0. Extensions PostgreSQL (`citext`) avant toute table utilisant `CITEXT`.
1. Types/enums/checks partagés avant usage.
2. P1 strictement limité à `users`, `customer_profiles`, `visitors`.
3. `coupons` avant `orders` si `orders.coupon_id` reste une FK.
4. `orders` avant `order_items`.
5. `licenses` après `order_items`.

1. `users` + `customer_profiles` + `visitors` (fondation identité)
2. `products` + `product_files` + `categories` (catalogue)
3. `carts` → `orders` → `order_items` → `payments` (commerce)
4. `download_grants` + `download_logs` (livraison sécurisée)
5. `events` partitionnée + rollups (analytique)
6. `campaigns` + `customer_segments` (marketing)

Ne code aucune logique métier avant que 1→4 soient migrés et testés.
