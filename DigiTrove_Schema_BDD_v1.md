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

> **Révisé P3 (D-024/D-027).** Argent en `BIGINT`, devise `VARCHAR(3)` uppercase (jamais
> CHAR(3)/FLOAT/REAL/DOUBLE/DECIMAL/NUMERIC). Prix recalculé dynamiquement (aucun prix
> dans `cart_items`), snapshot définitif uniquement dans `order_items`. Un seul coupon
> par panier et par commande (aucun cumul, aucune notion `is_cumulative`). Panier invité
> = UUID public opaque + `SHA-256(secret)` (secret jamais stocké). Ordre de migration :
> `coupons` → `coupon_currency_rules` → `coupon_products` → `coupon_categories` →
> `carts` → `cart_items` → `orders` → `order_items` → `coupon_redemptions`, puis P3C :
> `payments` → `payment_webhook_events` → `refunds`.

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

-- P3B COMMANDES (D-027). Checkout invité autorisé : user_id et visitor_id sont
-- nullable ; le snapshot customer_email est toujours obligatoire. `failed` reste
-- un statut de tentative de paiement P3C, jamais un statut de commande.
CREATE TABLE orders (
    id                        BIGSERIAL PRIMARY KEY,
    public_id                 UUID NOT NULL UNIQUE,
    order_number              VARCHAR(19) NOT NULL UNIQUE
                              CHECK (order_number ~ '^DGT-[0-9]{4}-[0-9A-HJKMNP-TV-Z]{10}$'),
    cart_id                   BIGINT UNIQUE REFERENCES carts(id) ON DELETE SET NULL,
    checkout_idempotency_hash VARCHAR(64) NOT NULL UNIQUE
                              CHECK (checkout_idempotency_hash ~ '^[0-9a-f]{64}$'),
    user_id                   BIGINT REFERENCES users(id) ON DELETE SET NULL,
    visitor_id                UUID REFERENCES visitors(id) ON DELETE SET NULL,
    customer_email            CITEXT NOT NULL
                              CHECK (btrim(customer_email::text) <> '' AND char_length(customer_email::text) <= 320),
    customer_name_snapshot    TEXT CHECK (customer_name_snapshot IS NULL OR btrim(customer_name_snapshot) <> ''),
    billing_country_code      VARCHAR(2)
                              CHECK (billing_country_code IS NULL OR billing_country_code ~ '^[A-Z]{2}$'),
    coupon_id                 BIGINT REFERENCES coupons(id) ON DELETE SET NULL,
    coupon_code_snapshot                  TEXT,
    coupon_discount_type_snapshot         TEXT,
    coupon_percent_basis_points_snapshot  INT,
    coupon_fixed_amount_minor_snapshot    BIGINT,
    subtotal_minor            BIGINT NOT NULL CHECK (subtotal_minor >= 0),
    discount_minor            BIGINT NOT NULL DEFAULT 0 CHECK (discount_minor >= 0),
    tax_minor                 BIGINT NOT NULL DEFAULT 0 CHECK (tax_minor >= 0),
    total_minor               BIGINT NOT NULL CHECK (total_minor >= 0),
    currency                  VARCHAR(3) NOT NULL
                              CHECK (char_length(currency) = 3 AND currency = upper(currency)),
    status                    TEXT NOT NULL DEFAULT 'pending'
                              CHECK (status IN ('pending','payment_review','paid','partially_refunded','refunded','cancelled','expired')),
    placed_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at                TIMESTAMPTZ NOT NULL,
    paid_at                   TIMESTAMPTZ,
    cancelled_at              TIMESTAMPTZ,
    -- Attribution minimale figée à la création ; aucune IP brute.
    utm_source                VARCHAR(255),
    utm_medium                VARCHAR(255),
    utm_campaign              VARCHAR(255),
    referrer_host             VARCHAR(253),
    ip_hash                   VARCHAR(64) CHECK (ip_hash IS NULL OR ip_hash ~ '^[0-9a-f]{64}$'),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (discount_minor <= subtotal_minor),
    CHECK (total_minor = subtotal_minor - discount_minor + tax_minor),
    CHECK (expires_at > placed_at),
    CHECK (paid_at IS NULL OR paid_at >= placed_at),
    CHECK (cancelled_at IS NULL OR cancelled_at >= placed_at),
    -- En P3, discount_minor représente uniquement une remise coupon. L'absence de
    -- coupon se déduit des snapshots, pas de coupon_id qui peut devenir NULL après
    -- suppression exceptionnelle du coupon référencé.
    CHECK (
        (
            coupon_code_snapshot IS NULL
            AND coupon_discount_type_snapshot IS NULL
            AND coupon_percent_basis_points_snapshot IS NULL
            AND coupon_fixed_amount_minor_snapshot IS NULL
            AND discount_minor = 0
        ) OR (
            coupon_code_snapshot IS NOT NULL AND btrim(coupon_code_snapshot) <> ''
            AND coupon_discount_type_snapshot = 'percent'
            AND coupon_percent_basis_points_snapshot IS NOT NULL
            AND coupon_percent_basis_points_snapshot BETWEEN 1 AND 10000
            AND coupon_fixed_amount_minor_snapshot IS NULL
            AND discount_minor > 0
        ) OR (
            coupon_code_snapshot IS NOT NULL AND btrim(coupon_code_snapshot) <> ''
            AND coupon_discount_type_snapshot = 'fixed'
            AND coupon_percent_basis_points_snapshot IS NULL
            AND coupon_fixed_amount_minor_snapshot IS NOT NULL
            AND coupon_fixed_amount_minor_snapshot > 0
            AND discount_minor > 0
        )
    ),
    CHECK (coupon_id IS NULL OR coupon_code_snapshot IS NOT NULL)
);
CREATE INDEX orders_status_expires_at_index ON orders (status, expires_at);
CREATE INDEX orders_status_placed_at_index ON orders (status, placed_at DESC);
CREATE INDEX orders_user_id_placed_at_index ON orders (user_id, placed_at DESC);
CREATE INDEX orders_visitor_id_placed_at_index ON orders (visitor_id, placed_at DESC);
CREATE INDEX orders_customer_email_placed_at_index ON orders (customer_email, placed_at DESC);
CREATE INDEX orders_coupon_id_placed_at_index ON orders (coupon_id, placed_at DESC);
-- UNIQUE(cart_id) autorise plusieurs NULL mais une seule commande par panier réel.
-- Le format DGT-YYYY-XXXXXXXXXX utilise 10 caractères aléatoires Crockford Base32 :
-- lisible et non séquentiel, avec l'unicité BDD comme dernier garde-fou. Ce numéro
-- n'est jamais un secret ; public_id reste l'identifiant public opaque.

-- Snapshot commercial complet. Une ligne maximum par produit non NULL et commande ;
-- quantity porte le nombre d'unités/licences. Plusieurs anciennes lignes devenues
-- product_id NULL restent possibles après suppression de produits distincts.
CREATE TABLE order_items (
    id                    BIGSERIAL PRIMARY KEY,
    order_id              BIGINT NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    product_id            BIGINT REFERENCES products(id) ON DELETE SET NULL,
    product_name_snapshot TEXT NOT NULL CHECK (btrim(product_name_snapshot) <> ''),
    product_slug_snapshot TEXT NOT NULL CHECK (btrim(product_slug_snapshot) <> ''),
    product_type_snapshot TEXT NOT NULL
                          CHECK (product_type_snapshot IN ('software','course','ebook','bundle','template')),
    unit_price_minor      BIGINT NOT NULL CHECK (unit_price_minor >= 0),
    quantity              INT NOT NULL DEFAULT 1 CHECK (quantity >= 1),
    line_subtotal_minor   BIGINT NOT NULL CHECK (line_subtotal_minor >= 0),
    line_discount_minor   BIGINT NOT NULL DEFAULT 0 CHECK (line_discount_minor >= 0),
    line_total_minor      BIGINT NOT NULL CHECK (line_total_minor >= 0),
    currency              VARCHAR(3) NOT NULL
                          CHECK (char_length(currency) = 3 AND currency = upper(currency)),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (line_subtotal_minor = unit_price_minor * quantity),
    CHECK (line_discount_minor <= line_subtotal_minor),
    CHECK (line_total_minor = line_subtotal_minor - line_discount_minor)
);
CREATE INDEX order_items_order_id_index ON order_items (order_id);
CREATE INDEX order_items_product_id_index ON order_items (product_id);
CREATE UNIQUE INDEX order_items_order_id_product_id_unique
    ON order_items (order_id, product_id) WHERE product_id IS NOT NULL;

-- Historique de consommation créé en P3B, mais alimenté uniquement en P3C après
-- confirmation serveur du paiement. Aucune commande pending ne réserve un quota.
CREATE TABLE coupon_redemptions (
    id                   BIGSERIAL PRIMARY KEY,
    coupon_id            BIGINT REFERENCES coupons(id) ON DELETE SET NULL,
    order_id             BIGINT NOT NULL UNIQUE REFERENCES orders(id) ON DELETE RESTRICT,
    customer_key_version SMALLINT NOT NULL DEFAULT 1 CHECK (customer_key_version > 0),
    customer_key_hash    VARCHAR(64) NOT NULL CHECK (customer_key_hash ~ '^[0-9a-f]{64}$'),
    coupon_code_snapshot TEXT NOT NULL CHECK (btrim(coupon_code_snapshot) <> ''),
    discount_type_snapshot TEXT NOT NULL CHECK (discount_type_snapshot IN ('percent','fixed')),
    discount_minor       BIGINT NOT NULL CHECK (discount_minor >= 0),
    currency             VARCHAR(3) NOT NULL
                         CHECK (char_length(currency) = 3 AND currency = upper(currency)),
    redeemed_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX coupon_redemptions_coupon_customer_index
    ON coupon_redemptions (coupon_id, customer_key_version, customer_key_hash);
CREATE INDEX coupon_redemptions_coupon_redeemed_at_index
    ON coupon_redemptions (coupon_id, redeemed_at DESC);
CREATE INDEX coupon_redemptions_redeemed_at_index ON coupon_redemptions (redeemed_at DESC);

-- TRIGGERS P3B (implémentés dans les trois migrations P3B) :
-- 1. orders_prevent_delete_trigger : BEFORE DELETE, lève toujours une exception explicite.
-- 2. orders_enforce_immutability_trigger : BEFORE UPDATE, seules status, paid_at,
--    cancelled_at et updated_at peuvent évoluer. expires_at reste figé. Exception
--    référentielle strictement limitée à cart_id/user_id/visitor_id/coupon_id passant
--    de non-NULL à NULL, sans autre changement commercial, pour rendre SET NULL viable.
-- 3. order_items_prevent_delete_trigger : BEFORE DELETE, lève toujours une exception.
-- 4. order_items_enforce_immutability_trigger : BEFORE UPDATE, autorise uniquement
--    product_id non-NULL -> NULL, toutes les autres colonnes, updated_at inclus, identiques.
-- 5. orders_validate_items_consistency_trigger et
--    order_items_validate_order_consistency_trigger :
--    CONSTRAINT TRIGGER AFTER ROW, DEFERRABLE INITIALLY DEFERRED, sur INSERT/UPDATE
--    d'orders et INSERT/UPDATE/DELETE d'order_items. Au commit : au moins une ligne,
--    devises identiques, sommes des sous-totaux/remises/totaux cohérentes avec orders.
-- 6. coupon_redemptions_validate_order_consistency_trigger et
--    orders_validate_redemption_consistency_trigger :
--    CONSTRAINT TRIGGER AFTER ROW, DEFERRABLE INITIALLY DEFERRED. Au commit : commande
--    avec snapshots coupon, code/type/remise/devise identiques et statut dans
--    paid/partially_refunded/refunded. L'ordre d'insertion interne à la transaction
--    P3C reste donc libre ; seul l'état final au COMMIT est contrôlé.
-- Les exceptions SET NULL permettent techniquement une nullification SQL directe :
-- permissions BDD minimales, aucune API de mutation et SoftDeletes/désactivation en
-- fonctionnement normal complètent la défense. RESTRICT bloquerait les purges légales ;
-- supprimer les FK ferait perdre l'intégrité référentielle. SET NULL reste le compromis.
-- Flux futur P3C compatible : vérifier le paiement serveur-à-serveur avant de conserver
-- des verrous réseau longs, puis ouvrir une transaction, verrouiller order FOR UPDATE,
-- verrouiller coupon FOR UPDATE, revalider paiement/montant/idempotence, compter les
-- consommations globales et par (version, hash), insérer coupon_redemptions, marquer le
-- paiement succeeded puis la commande paid avec paid_at, et COMMIT. Les triggers
-- différés observent l'état final cohérent ; UNIQUE(order_id) bloque un second débit.
-- Une rotation HMAC doit calculer les identités de toutes les versions encore retenues
-- lors du contrôle par client, sinon le changement de clé contournerait le plafond.

-- ============================================================================
-- 🅲.P3C PAIEMENTS & REMBOURSEMENTS — PLAN FINALISÉ (D-028 ; choix 1A–5A validés)
-- ÉTAT : P3C-A `payments` MERGÉ (PR #6 -> 4a077db ; 4 fonctions / 5 triggers dont 2
--        constraint triggers différés). P3C-B `payment_webhook_events` IMPLÉMENTÉ
--        (branche p3c-b-webhooks ; 3 fonctions / 3 triggers immédiats : immutabilité+
--        transitions, cohérence webhook↔paiement, suppression contrôlée par rétention ;
--        pas de statut 'duplicate' ; D-028 inchangée). P3C-C `refunds` reste à implémenter.
--        Pour payments : `payments.status` est un VARCHAR(20)
--        contraint ; provider est VARCHAR(32) ; les checks amount/currency sont doublés
--        par le trigger immédiat validate_payment_order_amount (défense en profondeur).
-- Ordre migrations : create_payments_table -> create_payment_webhook_events_table
--                    -> create_refunds_table.
-- `coupon_redemptions` existe déjà (P3B) : ALIMENTÉE en P3C, jamais recréée.
-- Argent BIGINT ; devise VARCHAR(3) upper ; provider canonique VARCHAR(32) lowercase.
-- Hash SHA-256 = VARCHAR(64) CHECK ~ '^[0-9a-f]{64}$' ; clés/secrets bruts jamais stockés.
-- Aucune ligne `payments` pour une commande total_minor = 0 (flux gratuit distinct).
-- Aucune validation depuis un retour navigateur (cf. SECURITE_PAIEMENT.md).
-- Les triggers VÉRIFIENT et REFUSENT ; ils ne mutent jamais montant/statut/référence.
-- Ordre de verrouillage global : orders -> payments -> coupons -> refunds/agrégats.
-- ============================================================================

-- Une commande peut avoir PLUSIEURS tentatives ; une seule 'succeeded' ; une seule
-- 'requires_review'. Champs commerciaux figés, seuls statut + cycle + réf (NULL->valeur) bougent.
CREATE TABLE payments (
    id                          BIGSERIAL PRIMARY KEY,
    public_id                   UUID   NOT NULL,
    order_id                    BIGINT NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,
    provider                    VARCHAR(32) NOT NULL,          -- canonique lowercase
    provider_payment_reference  TEXT,                          -- NULL->valeur puis figé
    idempotency_key_hash        VARCHAR(64) NOT NULL,          -- SHA-256 ; clé brute jamais stockée/loguée
    attempt_number              INTEGER NOT NULL,
    amount_minor                BIGINT NOT NULL,               -- > 0 (jamais 0 : commande gratuite = 0 paiement)
    currency                    VARCHAR(3) NOT NULL,
    status                      TEXT NOT NULL DEFAULT 'pending',
    provider_status             TEXT,                          -- métadonnées FILTRÉES uniquement
    provider_method             TEXT,
    provider_metadata           JSONB,                         -- allowlist stricte, jamais de brut
    failure_code                TEXT,
    failure_message_sanitized   TEXT,
    initiated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    processing_at               TIMESTAMPTZ,
    succeeded_at                TIMESTAMPTZ,
    failed_at                   TIMESTAMPTZ,
    cancelled_at                TIMESTAMPTZ,
    expired_at                  TIMESTAMPTZ,
    last_verified_at            TIMESTAMPTZ,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE payments ADD CONSTRAINT payments_public_id_unique UNIQUE (public_id);
ALTER TABLE payments ADD CONSTRAINT payments_idempotency_key_hash_unique UNIQUE (idempotency_key_hash);
ALTER TABLE payments ADD CONSTRAINT payments_order_attempt_unique UNIQUE (order_id, attempt_number);
ALTER TABLE payments ADD CONSTRAINT payments_provider_format_check       CHECK (provider ~ '^[a-z0-9][a-z0-9_-]{0,31}$');
ALTER TABLE payments ADD CONSTRAINT payments_idempotency_hash_format_check CHECK (idempotency_key_hash ~ '^[0-9a-f]{64}$');
ALTER TABLE payments ADD CONSTRAINT payments_attempt_positive_check      CHECK (attempt_number >= 1);
ALTER TABLE payments ADD CONSTRAINT payments_amount_positive_check       CHECK (amount_minor > 0);
ALTER TABLE payments ADD CONSTRAINT payments_currency_format_check       CHECK (char_length(currency) = 3 AND currency = upper(currency));
ALTER TABLE payments ADD CONSTRAINT payments_status_check                CHECK (status IN ('pending','processing','requires_review','succeeded','failed','cancelled','expired'));
ALTER TABLE payments ADD CONSTRAINT payments_cycle_dates_check CHECK (
    (processing_at    IS NULL OR processing_at    >= initiated_at) AND
    (succeeded_at     IS NULL OR succeeded_at     >= initiated_at) AND
    (failed_at        IS NULL OR failed_at        >= initiated_at) AND
    (cancelled_at     IS NULL OR cancelled_at     >= initiated_at) AND
    (expired_at       IS NULL OR expired_at       >= initiated_at) AND
    (last_verified_at IS NULL OR last_verified_at >= initiated_at)
);
-- 🔐 Un seul encaissement final ET une seule revue par commande (D-028.2) :
CREATE UNIQUE INDEX payments_one_succeeded_per_order       ON payments (order_id) WHERE status = 'succeeded';
CREATE UNIQUE INDEX payments_one_requires_review_per_order ON payments (order_id) WHERE status = 'requires_review';
CREATE UNIQUE INDEX payments_provider_reference_unique     ON payments (provider, provider_payment_reference) WHERE provider_payment_reference IS NOT NULL;
CREATE INDEX payments_order_id_index         ON payments (order_id);
CREATE INDEX payments_order_id_status_index  ON payments (order_id, status);
CREATE INDEX payments_status_initiated_index ON payments (status, initiated_at DESC);

-- Journal d'événements fournisseur. SEULE table P3C purgeable (rétention contrôlée).
-- Événement invalide (signature KO) conservé MINIMAL : hash seul, sans payload (D-028.4).
-- JAMAIS de signature brute, secret, payload brut, PAN, CVV, token réutilisable.
CREATE TABLE payment_webhook_events (
    id                          BIGSERIAL PRIMARY KEY,
    provider                    VARCHAR(32) NOT NULL,          -- canonique lowercase
    external_event_id           TEXT,                          -- NULL toléré si invalide/absent
    payment_id                  BIGINT REFERENCES payments(id) ON DELETE RESTRICT,  -- NULL->valeur
    event_type                  TEXT,
    payload_hash                VARCHAR(64) NOT NULL,          -- SHA-256 du corps BRUT (audit/dédup)
    filtered_payload            JSONB,                         -- allowlist ; NULL si invalide
    signature_verified          BOOLEAN NOT NULL,
    processing_status           TEXT NOT NULL DEFAULT 'received',
    received_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    processed_at                TIMESTAMPTZ,
    failed_at                   TIMESTAMPTZ,
    retention_until             TIMESTAMPTZ,
    processing_error_sanitized  TEXT
);
ALTER TABLE payment_webhook_events ADD CONSTRAINT pwe_provider_format_check     CHECK (provider ~ '^[a-z0-9][a-z0-9_-]{0,31}$');
ALTER TABLE payment_webhook_events ADD CONSTRAINT pwe_payload_hash_format_check CHECK (payload_hash ~ '^[0-9a-f]{64}$');
ALTER TABLE payment_webhook_events ADD CONSTRAINT pwe_processing_status_check   CHECK (processing_status IN ('received','processed','ignored','failed'));  -- PAS de 'duplicate'
-- Signé valide => identifiant externe obligatoire :
ALTER TABLE payment_webhook_events ADD CONSTRAINT pwe_signed_requires_external_id_check CHECK (signature_verified = false OR external_event_id IS NOT NULL);
-- Invalide => forme minimale imposée (D-028.4) :
ALTER TABLE payment_webhook_events ADD CONSTRAINT pwe_invalid_minimal_shape_check CHECK (
    signature_verified = true OR (
        processing_status = 'failed' AND filtered_payload IS NULL AND payment_id IS NULL AND failed_at IS NOT NULL
    )
);
-- Anti double-webhook signé (rejeu => ligne existante => réponse idempotente, pas de statut 'duplicate') :
CREATE UNIQUE INDEX pwe_provider_external_event_unique ON payment_webhook_events (provider, external_event_id) WHERE external_event_id IS NOT NULL;
-- Dédup des invalides sans identifiant externe :
CREATE UNIQUE INDEX pwe_provider_payload_hash_unique   ON payment_webhook_events (provider, payload_hash) WHERE signature_verified = false;
CREATE INDEX pwe_payment_id_index          ON payment_webhook_events (payment_id);
CREATE INDEX pwe_processing_received_index ON payment_webhook_events (processing_status, received_at DESC);
CREATE INDEX pwe_retention_until_index     ON payment_webhook_events (retention_until);

-- Remboursements partiels et multiples. Provider ET devise = ceux du paiement (triggers).
CREATE TABLE refunds (
    id                          BIGSERIAL PRIMARY KEY,
    public_id                   UUID   NOT NULL,
    payment_id                  BIGINT NOT NULL REFERENCES payments(id) ON DELETE RESTRICT,
    provider                    VARCHAR(32) NOT NULL,
    provider_refund_reference   TEXT,                          -- NULL->valeur puis figé
    idempotency_key_hash        VARCHAR(64) NOT NULL,          -- SHA-256 ; clé brute jamais stockée
    amount_minor                BIGINT NOT NULL,
    currency                    VARCHAR(3) NOT NULL,
    status                      TEXT NOT NULL DEFAULT 'pending',
    reason_code                 TEXT,
    reason_note_sanitized       TEXT,
    initiated_by_user_id        BIGINT REFERENCES users(id) ON DELETE SET NULL,
    provider_status             TEXT,
    provider_metadata           JSONB,
    requested_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    processing_at               TIMESTAMPTZ,
    succeeded_at                TIMESTAMPTZ,
    failed_at                   TIMESTAMPTZ,
    cancelled_at                TIMESTAMPTZ,
    last_verified_at            TIMESTAMPTZ,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE refunds ADD CONSTRAINT refunds_public_id_unique            UNIQUE (public_id);
ALTER TABLE refunds ADD CONSTRAINT refunds_idempotency_key_hash_unique UNIQUE (idempotency_key_hash);
ALTER TABLE refunds ADD CONSTRAINT refunds_provider_format_check       CHECK (provider ~ '^[a-z0-9][a-z0-9_-]{0,31}$');
ALTER TABLE refunds ADD CONSTRAINT refunds_idempotency_hash_format_check CHECK (idempotency_key_hash ~ '^[0-9a-f]{64}$');
ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive_check       CHECK (amount_minor > 0);
ALTER TABLE refunds ADD CONSTRAINT refunds_currency_format_check       CHECK (char_length(currency) = 3 AND currency = upper(currency));
ALTER TABLE refunds ADD CONSTRAINT refunds_status_check                CHECK (status IN ('pending','processing','succeeded','failed','cancelled'));  -- PAS de requires_review
ALTER TABLE refunds ADD CONSTRAINT refunds_cycle_dates_check CHECK (
    (processing_at    IS NULL OR processing_at    >= requested_at) AND
    (succeeded_at     IS NULL OR succeeded_at     >= requested_at) AND
    (failed_at        IS NULL OR failed_at        >= requested_at) AND
    (cancelled_at     IS NULL OR cancelled_at     >= requested_at) AND
    (last_verified_at IS NULL OR last_verified_at >= requested_at)
);
CREATE UNIQUE INDEX refunds_provider_reference_unique ON refunds (provider, provider_refund_reference) WHERE provider_refund_reference IS NOT NULL;
CREATE INDEX refunds_payment_id_index        ON refunds (payment_id);
CREATE INDEX refunds_payment_id_status_index ON refunds (payment_id, status);
CREATE INDEX refunds_status_requested_index  ON refunds (status, requested_at DESC);
-- refunds.provider = payments.provider et refunds.currency = payments.currency :
-- comparaisons inter-lignes -> garanties par trigger (une FK/CHECK ne compare pas deux tables).

-- ─────────────────────────────────────────────────────────────────────────────
-- CATALOGUE DES FONCTIONS/TRIGGERS P3C (noms stables ; à créer dans les migrations)
-- Principe : les triggers refusent, ne mutent jamais ; le service exécute les mutations.
-- ─────────────────────────────────────────────────────────────────────────────
-- PAIEMENTS
--  T1 prevent_payments_delete()            / payments_prevent_delete_trigger
--        BEFORE DELETE -> RAISE (suppression physique toujours interdite).
--  T2 enforce_payments_immutability()      / payments_enforce_immutability_trigger
--        BEFORE UPDATE. Figés : public_id, order_id, provider, idempotency_key_hash,
--        attempt_number, amount_minor, currency, initiated_at, created_at.
--        provider_payment_reference : NULL->valeur puis figé.
--        Mutables : status (selon machine à états), provider_status/method/metadata,
--        failure_code, failure_message_sanitized, dates de cycle, last_verified_at, updated_at.
--        Transitions autorisées (D-028.5) :
--          pending    -> processing|requires_review|failed|cancelled|expired
--          processing -> succeeded|requires_review|failed|cancelled|expired
--          failed     -> requires_review            (confirmation fournisseur tardive)
--          cancelled  -> requires_review            (confirmation fournisseur tardive)
--          expired    -> requires_review            (paiement tardif)
--          requires_review -> succeeded|failed|cancelled|expired
--          succeeded = TERMINAL. Interdits : failed/cancelled/expired -> succeeded,
--          succeeded -> tout autre statut.
--  T3 validate_payment_order_amount()      / payments_validate_order_amount_trigger
--        BEFORE INSERT (immédiat ; orders immuable, ni verrou ni deferral) :
--        amount_minor = orders.total_minor, currency = orders.currency, orders.total_minor > 0.
--  T4 validate_payment_order_consistency() / DEFERRABLE INITIALLY DEFERRED, monté sur
--        payments_validate_order_consistency_trigger (AFTER INSERT/UPDATE ON payments)
--        ET orders_validate_payment_consistency_trigger (AFTER INSERT/UPDATE ON orders).
--        Au COMMIT (accepte tout ordre d'insertion transactionnel) — D-028.2 :
--          * un payment 'succeeded' => order.status IN (paid,partially_refunded,refunded)
--          * order.status IN (paid,partially_refunded,refunded) ET total_minor>0 => EXACTEMENT un payment 'succeeded'
--          * order.total_minor = 0 => AUCUNE ligne payments (jamais de 'succeeded')
--          * payment 'requires_review' <=> order.status = 'payment_review' (exactement un)
-- WEBHOOKS
--  T5 enforce_webhook_event_immutability() / payment_webhook_events_enforce_immutability_trigger
--        BEFORE UPDATE. Figés : provider, external_event_id, payload_hash, signature_verified,
--        received_at. Mutables : payment_id (NULL->valeur), processing_status (received ->
--        processed|ignored|failed ; états terminaux, non réactivables), processed_at, failed_at,
--        processing_error_sanitized, retention_until, event_type (NULL->valeur).
--  T6 validate_webhook_payment_provider() / payment_webhook_events_provider_match_trigger
--        BEFORE INSERT/UPDATE : si payment_id NOT NULL => provider = payments.provider.
--  T7 enforce_webhook_retention_delete()  / payment_webhook_events_retention_delete_trigger
--        BEFORE DELETE : suppression AUTORISÉE uniquement si retention_until < now() ET
--        processing_status terminal (processed|ignored|failed). Sinon RAISE. (Job de purge
--        NON implémenté en P3C ; seule la garde existe.) -> unique table P3C non "prevent-delete".
-- REMBOURSEMENTS
--  T8 prevent_refunds_delete()             / refunds_prevent_delete_trigger  (BEFORE DELETE -> RAISE)
--  T9 enforce_refunds_immutability()       / refunds_enforce_immutability_trigger
--        BEFORE UPDATE. Figés : public_id, payment_id, provider, idempotency_key_hash,
--        amount_minor, currency, initiated_by_user_id, reason_code, requested_at, created_at.
--        provider_refund_reference : NULL->valeur puis figé.
--        Mutables : status, provider_status/metadata, reason_note_sanitized, dates de cycle,
--        last_verified_at, updated_at. Transitions (D-028.5) :
--          pending -> processing|failed|cancelled ; processing -> succeeded|failed|cancelled
--          Terminaux : succeeded|failed|cancelled. Aucune transition terminal -> autre.
--  T10 enforce_refund_within_capture()     / refunds_enforce_capture_cap_trigger   IMMÉDIAT (D-028.6)
--        BEFORE INSERT (déjà 'succeeded') OU BEFORE UPDATE faisant passer status -> 'succeeded' :
--          1. SELECT ... FROM payments WHERE id = NEW.payment_id FOR UPDATE   (sérialise la concurrence)
--          2. payment existe ET payment.status = 'succeeded'
--          3. NEW.provider = payment.provider ET NEW.currency = payment.currency
--          4. somme = SUM(amount_minor) des refunds 'succeeded' du paiement EXCLUANT la ligne courante
--             (WHERE id <> NEW.id) + NEW.amount_minor
--          5. RAISE si somme > payments.amount_minor
--        (≠ trigger différé P3B : les refunds naissent dans des transactions séparées ;
--         seul un verrou de ligne immédiat empêche le dépassement concurrent.)
--  T11 validate_refund_order_status()      / DEFERRABLE INITIALLY DEFERRED, monté sur
--        refunds_validate_order_consistency_trigger  (AFTER INSERT/UPDATE ON refunds),
--        payments_validate_refund_consistency_trigger (AFTER UPDATE ON payments),
--        orders_validate_refund_consistency_trigger  (AFTER INSERT/UPDATE ON orders).
--        Au COMMIT, pour l'unique paiement 'succeeded' de la commande (D-028.3) :
--          somme refunds 'succeeded' = 0                     => order.status = 'paid'
--          0 < somme < payments.amount_minor                 => order.status = 'partially_refunded'
--          somme = payments.amount_minor                     => order.status = 'refunded'
--        (Trigger de STATUT distinct du trigger de PLAFOND T10. Aucune récursion : les triggers
--         ne réécrivent pas orders ; le RefundService met à jour order.status, le trigger vérifie.)

-- ─────────────────────────────────────────────────────────────────────────────
-- ORCHESTRATIONS SERVEUR P3C (documentées, NON implémentées en P3C)
-- ─────────────────────────────────────────────────────────────────────────────
-- (a) PAIEMENT CONFIRMÉ : 1) vérif fournisseur HORS transaction (webhook signé OU getStatus) ;
--     2) BEGIN ; 3) orders FOR UPDATE ; 4) payment FOR UPDATE ; 5) idempotence ;
--     6) revalider montant/devise ; 7) coupon FOR UPDATE si présent ; 8) contrôle plafonds ;
--     9) INSERT coupon_redemptions ; 10) payment -> succeeded ; 11) order -> paid (paid_at) ;
--     12) COMMIT (constraint triggers différés valident l'état final ; UNIQUE(order_id) bloque un 2e débit).
-- (b) COMMANDE GRATUITE (total_minor = 0) : aucune ligne payments ; validation métier d'éligibilité ;
--     order -> paid par flux gratuit distinct ; aucun webhook ; aucune redemption à remise incohérente.
-- (c) PAIEMENT TARDIF : aucune transition expired/failed/cancelled -> succeeded ;
--     payment -> requires_review ; order -> payment_review ; décision humaine ; aucune livraison auto.
-- (d) REMBOURSEMENT RÉUSSI : BEGIN ; orders FOR UPDATE ; payment FOR UPDATE ; refund -> succeeded
--     (T10 plafond immédiat) ; calcul cumul ; MAJ EXPLICITE order (partiel->partially_refunded,
--     complet->refunded) ; COMMIT (T11 différé vérifie l'état final).

-- ─────────────────────────────────────────────────────────────────────────────
-- PLAN DE TESTS PostgreSQL RÉEL P3C (jamais SQLite ; SET CONSTRAINTS ALL IMMEDIATE pour forcer les différés)
-- ─────────────────────────────────────────────────────────────────────────────
-- FOURNISSEUR : casse uppercase refusée ; espaces refusés ; refund.provider ≠ payment refusé ;
--   webhook.provider ≠ payment refusé ; unicité provider/référence insensible aux variantes interdites.
-- PAIEMENT/COMMANDE : montant ≠ commande refusé ; devise ≠ refusée ; paiement d'une commande gratuite
--   refusé ; commande gratuite 'paid' sans paiement acceptée ; un seul 'succeeded' ; un seul
--   'requires_review' ; payment 'succeeded' + commande non payée refusé au commit ; commande payée
--   non gratuite sans paiement réussi refusée au commit ; 'requires_review' sans 'payment_review'
--   refusé ; 'payment_review' sans paiement en revue refusé.
-- TRANSITIONS : autorisées acceptées ; failed/cancelled/expired -> succeeded refusés ; passage via
--   requires_review accepté ; succeeded terminal ; refund succeeded terminal ; webhook terminal non réactivable.
-- WEBHOOKS : signé sans external_event_id refusé ; invalide minimal accepté ; invalide avec
--   filtered_payload refusé ; invalide lié à un payment refusé ; rejeu même external_event_id
--   idempotent ; rejeu invalide même payload_hash idempotent ; aucun statut 'duplicate' ;
--   suppression avant retention refusée ; suppression après retention terminale acceptée.
-- REMBOURSEMENTS : partiel réussi ; plusieurs réussis ; cumul exact accepté ; dépassement refusé ;
--   MAJ vers succeeded exclut la ligne courante (WHERE id <> NEW.id) ; paiement non réussi refusé ;
--   devise ≠ refusée ; provider ≠ refusé ; deux remboursements concurrents ne dépassent jamais la
--   capture (2 connexions) ; statut commande partiel cohérent ; complet cohérent ; divergence
--   somme/statut refusée au commit.
-- RÉGRESSION P3B : confirmation + redemption + commande payée en une transaction ; double
--   consommation coupon bloquée ; triggers P3B inchangés ; aucun download_grant ; aucune table P4/P5.

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
4. P3 Commerce — ordre exact révisé (D-024/D-027) :
   `coupons` → `coupon_currency_rules` → `coupon_products` → `coupon_categories` →
   `carts` → `cart_items` → `orders` → `order_items` → `coupon_redemptions` →
   `payments` → `payment_webhook_events` → `refunds`.
   (P3B crée `orders`, `order_items`, puis `coupon_redemptions`; cette dernière reste
   vide jusqu'à la confirmation serveur d'un paiement en P3C.)
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
