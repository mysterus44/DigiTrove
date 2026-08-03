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
| **Données Analytiques** | `events` (partitionnée) · `analytics_sessions` · rollups journaliers currency-safe |

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
-- 🅲.P3C PAIEMENTS & REMBOURSEMENTS — SCHÉMA MERGÉ (D-028 ; choix 1A–5A validés)
-- ÉTAT : P3C-A `payments` MERGÉ (PR #6 -> 4a077db ; 4 fonctions / 5 triggers dont 2
--        constraint triggers différés). P3C-B `payment_webhook_events` MERGÉ (PR #8
--        -> 51c4847 ; 3 fonctions / 3 triggers immédiats : immutabilité+transitions,
--        cohérence webhook↔paiement, suppression contrôlée par rétention ; pas de statut
--        'duplicate'). Durcissement P3C-B.1 MERGÉ (PR #9 -> 13932ac) : index de rejeu
--        `(provider, external_event_id)` restreint aux signés (D-028.4). P3C-C `refunds`
--        MERGÉ (PR #10 -> 122332a ; migration 000007 ; 5 fonctions / 6 triggers dont
--        2 constraint triggers différés ; commit final intégré 1270c53).
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
CREATE UNIQUE INDEX pwe_provider_external_event_unique ON payment_webhook_events (provider, external_event_id) WHERE external_event_id IS NOT NULL AND signature_verified = true;
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
ALTER TABLE refunds ADD CONSTRAINT refunds_provider_reference_not_blank_check CHECK (provider_refund_reference IS NULL OR length(btrim(provider_refund_reference)) > 0);
ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive_check       CHECK (amount_minor > 0);
ALTER TABLE refunds ADD CONSTRAINT refunds_currency_format_check       CHECK (char_length(currency) = 3 AND currency = upper(currency));
ALTER TABLE refunds ADD CONSTRAINT refunds_status_check                CHECK (status IN ('pending','processing','succeeded','failed','cancelled'));  -- PAS de requires_review
ALTER TABLE refunds ADD CONSTRAINT refunds_reason_note_not_blank_check CHECK (reason_note_sanitized IS NULL OR length(btrim(reason_note_sanitized)) > 0);
ALTER TABLE refunds ADD CONSTRAINT refunds_provider_metadata_object_check CHECK (provider_metadata IS NULL OR jsonb_typeof(provider_metadata) = 'object');
ALTER TABLE refunds ADD CONSTRAINT refunds_cycle_dates_check CHECK (
    (processing_at    IS NULL OR processing_at    >= requested_at) AND
    (succeeded_at     IS NULL OR succeeded_at     >= requested_at) AND
    (failed_at        IS NULL OR failed_at        >= requested_at) AND
    (cancelled_at     IS NULL OR cancelled_at     >= requested_at) AND
    (last_verified_at IS NULL OR last_verified_at >= requested_at)
);
-- Une contrainte CASE ... ELSE FALSE END IS TRUE lie chaque état terminal à sa date
-- exclusive et empêche tout contournement PostgreSQL par CHECK = UNKNOWN.
CREATE UNIQUE INDEX refunds_provider_reference_unique ON refunds (provider, provider_refund_reference) WHERE provider_refund_reference IS NOT NULL;
CREATE INDEX refunds_payment_id_index        ON refunds (payment_id);
CREATE INDEX refunds_payment_id_status_index ON refunds (payment_id, status);
CREATE INDEX refunds_status_requested_index  ON refunds (status, requested_at DESC);
-- refunds.provider = payments.provider et refunds.currency = payments.currency :
-- comparaisons inter-lignes -> garanties par trigger (une FK/CHECK ne compare pas deux tables).

-- ─────────────────────────────────────────────────────────────────────────────
-- CATALOGUE DES FONCTIONS/TRIGGERS P3C (noms stables ; implémentés dans les migrations)
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
--        Exception contrôlée : initiated_by_user_id peut devenir NULL uniquement pendant
--        l'action FK imbriquée ON DELETE SET NULL ; un UPDATE applicatif direct est refusé.
--        provider_refund_reference : NULL->valeur puis figé.
--        Mutables : status, provider_status/metadata, reason_note_sanitized, dates de cycle,
--        last_verified_at, updated_at. Transitions (D-028.5) :
--          pending -> processing|failed|cancelled ; processing -> succeeded|failed|cancelled
--          Terminaux : succeeded|failed|cancelled. Aucune transition terminal -> autre.
--  T10 enforce_refund_cumulative_cap()     / refunds_enforce_cumulative_cap_trigger   IMMÉDIAT (D-028.6)
--        BEFORE INSERT (déjà 'succeeded') OU BEFORE UPDATE faisant passer status -> 'succeeded' :
--          1. SELECT ... FROM payments WHERE id = NEW.payment_id FOR UPDATE   (sérialise la concurrence)
--          2. payment existe ET payment.status = 'succeeded'
--          3. NEW.provider = payment.provider ET NEW.currency = payment.currency
--          4. somme = SUM(amount_minor) des refunds 'succeeded' du paiement EXCLUANT la ligne courante
--             (WHERE id <> NEW.id) + NEW.amount_minor
--          5. RAISE si somme > payments.amount_minor
--        (≠ trigger différé P3B : les refunds naissent dans des transactions séparées ;
--         seul un verrou de ligne immédiat empêche le dépassement concurrent.)
--  T11 validate_refund_order_consistency() / DEFERRABLE INITIALLY DEFERRED, monté sur
--        refunds_validate_order_consistency_trigger  (AFTER INSERT/UPDATE ON refunds),
--        orders_validate_refund_consistency_trigger  (AFTER INSERT/UPDATE ON orders).
--        Au COMMIT, pour l'unique paiement 'succeeded' de la commande (D-028.3) :
--          somme refunds 'succeeded' = 0                     => order.status = 'paid'
--          0 < somme < payments.amount_minor                 => order.status = 'partially_refunded'
--          somme = payments.amount_minor                     => order.status = 'refunded'
--        (Trigger de STATUT distinct du trigger de PLAFOND T10. Aucune récursion : les triggers
--         ne réécrivent pas orders ; le RefundService met à jour order.status, le trigger vérifie.)
--        `payments.status='succeeded'` est terminal et la création d'un refund exige déjà ce
--        statut ; aucun troisième trigger différé sur payments n'est donc nécessaire à ce gate.

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

-- ============================================================================
-- ===== P4 LIVRAISON — PLAN FINALISÉ (D-029 + D-029.1 ; schéma cible, NON migré) =====
-- Audit contradictoire validé par KingKouda (D-029.1, choix B–A–B) :
--   B — colonnes de contenu de `product_files` rendues IMMUABLES par migration
--       additive (nouvelle version = nouvelle ligne) ;
--   A — composition des bundles SNAPSHOTÉE à la commande
--       (`order_item_bundle_components`) ; la lignée G3 n'utilise JAMAIS la
--       composition courante de `product_bundles` ;
--   B — AUCUN DEFAULT commercial en BDD : `max_downloads` et `expires_at`
--       explicites à chaque insertion ; TTL/quota/rétention = config applicative.
-- Sous-gates (D-029.2 : quatre gates ISOLÉS, plus le hotfix additif P4-A2.1 ; un
-- invariant par gate, jamais de gate composite — chaque gate a sa branche, sa
-- migration unique, sa frontière
-- de rollback et doit être MERGÉ dans la stable avant le gate suivant) :
--   P4-A0 — ProductFile Content Immutability ✅ MERGÉ (PR #11 -> a047571 ;
--           parents abaea6e + 8b822c1 ; fonction G0 + trigger BEFORE UPDATE
--           confirmés, suite 116/1666, rollback isolé 000008 vert)
--           branche `p4-a0-product-file-immutability`
--           migration `2026_07_14_000008_harden_product_files_content_immutability.php`
--           frontière harness `000008` (down() ne retire que G0).
--   P4-A1 — Bundle Purchase Snapshot ✅ MERGÉ (PR #12 -> 93d1f17 ; parents
--           a1e2e7f + 94b018c ; table + S1/S2/S3 confirmés, S3 sans mutation,
--           0 S4 / 0 cardinalité, suite 133/1882, rollback isolé 000009 vert)
--           branche `p4-a1-bundle-purchase-snapshots`
--           migration `2026_07_14_000009_create_order_item_bundle_components_table.php`
--           frontière harness `000009` (down() ne retire que la table + S1/S2/S3 ;
--           P4-A0 préservé).
--   P4-A2 — Download Grants ✅ MERGÉ (PR #13 -> 77f3766 ; parents 1b6e401 +
--           cea5f2d ; 4 fonctions / 5 triggers confirmés ; G4 différé sur
--           download_grants ET orders ; G3 ne lit jamais payments et prend
--           orders FOR UPDATE ; aucun DEFAULT commercial ; aucun index avec now() ;
--           18 tests / 315 assertions ; suite 151/2188 ; rollback 000010 vert)
--           branche `p4-a2-download-grants`
--           migration `2026_07_14_000010_create_download_grants_table.php`
--           frontière harness `000010` (down() ne retire que les objets grants ;
--           P4-A0 + P4-A1 préservés).
--   P4-A2.1 — Download Grant Integrity Hardening ✅ MERGÉ PR #14 (`2c25e2a`)
--           branche `p4-a2-1-grant-integrity-hardening`
--           migration additive
--           `2026_07_14_000011_harden_download_grants_integrity.php`
--           G3 : bénéficiaire null-safe strict avec orders.user_id ; G2 :
--           updated_at avance uniquement avec consommation/révocation ; 4 fonctions
--           / 5 triggers inchangés ; rollback restaure exactement G2/G3 de 000010.
--   P4-B0 — Frontière de privilèges PostgreSQL runtime (gate préalable, D-029.6)
--           migration ACL réservée
--           `2026_07_14_000012_harden_database_runtime_privileges.php`
--           + script cluster `docker/postgres/provision-runtime-roles.sql`.
--           Trois rôles : `digitrove` (migrateur/propriétaire, plus runtime),
--           `digitrove_runtime` (LOGIN restreint, sans TEMP/DDL/TRIGGER, sans
--           UPDATE table-level ni colonne `downloads_count`),
--           `digitrove_download_executor` (NOLOGIN, propriétaire de G5).
--           G5 devient `SECURITY DEFINER` (propriétaire exécuteur, search_path
--           épinglé, objets qualifiés) ; G2 vérifie `current_user =
--           digitrove_download_executor` comme preuve d'origine PRINCIPALE,
--           `pg_trigger_depth()` restant secondaire. REVOKE TEMP/CREATE/EXECUTE
--           à PUBLIC + default privileges TABLES/SEQUENCES.
--           ✅ MERGÉ PR #15 → `6d23e546` (parents `a3eac5e` + `9b69f192`).
--           Frontière active sur la stable ; branche distante conservée.
--           Faisabilité prouvée : la danse SET LOCAL ROLE donne la propriété de
--           G5 à l'exécuteur sans lui laisser de CREATE permanent, et un trigger
--           SECURITY DEFINER se déclenche même sans EXECUTE pour le rôle
--           déclencheur (session_user=runtime, current_user=executor).
--           Attention (PG 16.14) : la FORME GLOBALE `ALTER DEFAULT PRIVILEGES
--           FOR ROLE r REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC` fonctionne et fait
--           naître les fonctions futures sans EXECUTE PUBLIC ; la forme
--           `IN SCHEMA public` ne retire PAS le privilège intégré global. Les
--           défauts suivent le rôle créateur (pas d'héritage) → migrateur ET
--           exécuteur (via SET ROLE) reçoivent chacun le leur ; les fonctions
--           existantes gardent un REVOKE explicite. 13 tests / 84 assertions ;
--           suite 171/2385 ; Pint 117.
--   P4-B  — Download Logs ✅ MERGÉ PR #16 → `98441014`
--           (parents `d7c53cf` + `49692e25`) ; branche distante conservée.
--           migration `2026_07_14_000013_create_download_logs_table.php`
--           (frontière harness `000013`). 29 migrations au total.
--           ➜ SCHÉMA P4 COMPLET (A0 → A1 → A2 → A2.1 → B0 → B).
--           Contrat D-029.5 intact : table à 15 colonnes, 10 CHECK, FK RESTRICT,
--           uniques et index inchangés, 1A/2A/3A + R1A/R2A/R3A préservées.
--           Durcissement D-029.6 : préconditions fail-closed (rôles, ACL,
--           défauts globaux) AVANT toute création ; **G5 `SECURITY DEFINER`
--           possédée par `digitrove_download_executor`** (search_path épinglé
--           `pg_catalog, public, pg_temp`, objets qualifiés, EXECUTE retiré à
--           PUBLIC et au runtime) ; **G2 exige `current_user =
--           digitrove_download_executor` ET `pg_trigger_depth() > 1`** —
--           l'identité effective est l'autorité, la profondeur une défense
--           secondaire. Un trigger forgé même par le PROPRIÉTAIRE superuser est
--           refusé (23514) ; le runtime est arrêté plus tôt (42501).
--           ACL `download_logs` : PUBLIC révoqué, runtime DML + séquence sans
--           TRIGGER/TRUNCATE/REFERENCES, exécuteur sans droit sur la table.
--           G5 reçoit `UPDATE (updated_at) ON orders` — privilège minimal requis
--           par `FOR UPDATE OF orders` (SELECT seul refusé, mesuré PG 16.14).
--           Rollback : non vide refusé fail-closed ; vide → G5 supprimée sous
--           l'exécuteur et G2 restaurée OCTET POUR OCTET à son état post-`000012`,
--           P4-B0 intact. 19 tests / 603 assertions ; suite 190/2975 ; Pint 121.
-- Ordre des merges OBLIGATOIRE : `000009` ne se crée qu'après merge de `000008`,
-- `000010` après `000009`, le hotfix `000011` après `000010`, puis **P4-B0
-- `000012` (ACL) avant P4-B**, et enfin P4-B renuméroté `000013` seulement après
-- merge du gate P4-B0.
-- Responsabilités : A0 = référence de contenu historiquement stable ;
-- A1 = composition de bundle achetée indépendante du pivot mutable courant ;
-- A2/A2.1 = grant, quota structurel, expiration, révocation et intégrité G1-G4 ;
-- B = tentative, journal métier et consommation atomique compteur+log via G5/G6.
-- Aucune consommation applicative réelle avant P4-B : les fonctions BDD de P4-A2
-- sont testées, mais aucun endpoint/service de téléchargement n'existe avant la
-- fin du schéma P4 ; le futur service utilisera A2 + B ensemble (aucun compteur
-- de production sans journal une fois la fonctionnalité exposée).
-- `licenses` est EXCLU de P4 (option produit non décidée — cf. D-029 ; ne pas créer).
-- Unité du grant : order_item × product_file (D-009 + SECURITE_TELECHARGEMENT.md).
-- Token brut = random_bytes(32), envoyé UNE fois (e-mail) ; en BDD UNIQUEMENT son
-- hash SHA-256 (VARCHAR(64) hex lowercase). Jamais dans logs/exceptions/metadata.
-- La possession du token livré à orders.customer_email est la preuve d'accès
-- (checkout invité inclus) ; user_id est une DÉNORMALISATION de support/audit
-- (D-029.4, finding 3), jamais une preuve d'autorisation ni la source d'autorité
-- du bénéficiaire — celle-ci reste `grant -> order_item -> order`, avec
-- orders.customer_email (CITEXT NOT NULL) comme snapshot d'identité obligatoire ;
-- G3 vérifie la cohérence initiale avec orders.user_id lorsqu'il est renseigné.
-- ÉMISSION INITIALE (D-029.4, option A) : à l'événement futur OrderPaid, le
-- service crée les grants pour les ProductFiles ACTIFS À CET INSTANT, éligibles
-- et de lignée valide. Les lignes download_grants constituent alors le SNAPSHOT
-- APPLICATIF des fichiers livrés. PostgreSQL NE garantit PAS qu'un ProductFile
-- existait au moment de l'achat : G3 prouve la lignée, jamais la temporalité.
-- => « absence d'upgrade implicite = GARANTIE APPLICATIVE, PAS invariant
-- PostgreSQL ». Un ProductFile ajouté APRÈS l'émission initiale ne reçoit aucun
-- grant automatique, n'est jamais sélectionné par une rotation ni une réémission
-- support, et n'est inclus par aucun listener rétroactif ; l'y rattacher relèverait
-- d'une opération métier distincte `upgrade entitlement` — hors P4-A2, hors P4-B,
-- hors MVP, soumise à une décision produit explicite (ne jamais l'appeler rotation).
-- ROTATION = révocation de l'ancien grant + NOUVELLE ligne conservant EXACTEMENT
-- le même order_item_id + product_file_id, nouveau digest, historique conservé
-- (aucun compteur de version de token, aucune remise à zéro en place). La
-- réémission support obéit à la même règle. TOUT changement de product_file_id est
-- une nouvelle attribution commerciale, jamais une rotation.
-- Précondition de création : commande en statut LIVRABLE (paid|partially_refunded)
-- après confirmation SERVEUR (D-010). pending/payment_review/cancelled/expired/
-- refunded ne livrent jamais. Commande gratuite paid : livrable sans ligne
-- payments (flux gratuit D-028.2).
-- Remboursement TOTAL : la transaction qui passe la commande à `refunded` doit
-- révoquer tous les grants actifs (invariant différé bidirectionnel ci-dessous).
-- Remboursement PARTIEL : AUCUNE révocation automatique possible — `refunds` ne
-- porte qu'un payment_id, aucune allocation par ligne (limite P3C-C assumée) ;
-- révocation manuelle/support seulement. Un ciblage par ligne exigerait une table
-- d'allocation refund→order_item et une décision dédiée (hors P4).
-- Versionnement : le grant pointe la ligne product_files ACHETÉE — garanti par G0
-- (D-029.1-B durci par D-029.2) : product_id, storage_disk, storage_path,
-- checksum_sha256, size_bytes, mime_type, version et created_at sont FIGÉS après
-- insertion (identité du contenu, `version` inclus : l'étiquette de version ne
-- peut pas être renommée après l'achat, l'historique prouve de façon stable la
-- version associée à la ligne). Toute nouvelle version = NOUVELLE ligne ; une
-- ancienne ligne se DÉSACTIVE, ne se réécrit jamais ; aucune réactivation ou
-- réécriture silencieuse ; jamais d'upgrade implicite. Remplacement critique =>
-- désactivation de l'ancienne ligne + révocation explicite + réémission vers la
-- nouvelle. Émission exigée sur fichier is_active. Mutables : is_active, position,
-- et original_name — audité D-029.2 : libellé d'AFFICHAGE uniquement (nom de
-- fichier présenté au client au téléchargement) ; il ne résout jamais le fichier
-- (storage_path), ne produit aucune clé de stockage, ne vérifie aucune intégrité
-- (checksum_sha256) et ne prouve aucune version (version + checksum).
-- Bundles : la lignée d'un fichier de bundle se prouve UNIQUEMENT contre le
-- snapshot `order_item_bundle_components` figé à la commande (D-029.1-A) — un
-- produit ajouté au bundle APRÈS l'achat n'est jamais livrable à un ancien acheteur,
-- un produit retiré reste réémissible. Aucun repli sur la composition courante,
-- NI en P4-A2, NI ailleurs (D-029.3).
-- Fail-closed — FORMULATION EXACTE (corrigée par D-029.4, finding 1) :
--   * snapshot TOTALEMENT ABSENT sur un order_item bundle : détectable, aucun
--     grant enfant émis (G3 refuse) ;
--   * composant absent du snapshot : aucun grant possible POUR CE COMPOSANT
--     (il n'y a simplement pas de ligne prouvant l'achat) ;
--   * snapshot PARTIELLEMENT copié : **NON détectable comme incomplet** par
--     P4-A2 — P4-A1 ne stocke ni en-tête, ni compteur attendu, ni preuve de
--     complétude, et toute comparaison ultérieure au pivot courant est interdite ;
--   * risque résiduel : SOUS-livraison possible (un composant oublié n'est jamais
--     livrable), JAMAIS de sur-livraison ;
--   * exhaustivité : garantie UNIQUEMENT par le futur OrderService (un seul
--     INSERT ... SELECT) et ses tests — pattern coupon_redemptions : table créée
--     en P4-A1, ALIMENTÉE par le checkout. Ne jamais écrire que P4-A2 détecte un
--     snapshot incomplet.
-- Bundles imbriqués EXCLUS (D-029.3, Q1=A) : un composant de `products.type =
-- 'bundle'` est REFUSÉ par S3. Le CHECK P2 n'interdit que l'auto-inclusion directe
-- et la prévention des cycles indirects reste reportée ; sans elle, un aplatissement
-- récursif serait exposé aux cycles, et conserver le bundle imbriqué tel quel
-- livrerait un achat incomplet en silence. L'exclusion est donc fail-closed
-- explicite, jusqu'à une décision produit ET une protection anti-cycle dédiées.
-- BUNDLE VIDE (D-029.3, garde-fou) : la BDD AUTORISE techniquement un snapshot
-- vide — aucune contrainte n'impose « au moins une ligne » pour un order_item
-- bundle (un tel invariant exigerait un constraint trigger différé, écarté :
-- il relirait le pivot mutable). Le futur OrderService REFUSE la commande d'un
-- bundle vide AVANT la création de l'order_item. Si une copie échoue ou est
-- oubliée, P4-A2 reste fail-closed : aucun grant n'est émis. C'est une GARANTIE
-- APPLICATIVE, jamais un invariant PostgreSQL.
-- Statut du grant DÉRIVÉ (revoked_at / expires_at / downloads_count) : AUCUN enum
-- stocké — `expired` dépend de l'horloge, PostgreSQL ne pourrait pas garantir la
-- cohérence d'un statut matérialisé. Seul download_logs.status est un enum stocké.
-- Suppression physique des grants INTERDITE (révocation seulement, trace à vie).
-- download_logs est la SEULE table P4 purgeable (rétention contrôlée, RGPD).

-- P4-A0 — DURCISSEMENT `product_files` (gate isolé, migration additive `000008` ;
-- la migration P2 mergée `2026_07_12_000004` n'est JAMAIS éditée — pattern P3C-B.1).
-- Trigger G0 : BEFORE UPDATE, colonnes d'IDENTITÉ DU CONTENU figées (D-029.2) :
-- product_id, storage_disk, storage_path, checksum_sha256, size_bytes, mime_type,
-- version, created_at. Mutables : is_active, position, original_name (libellé
-- d'affichage uniquement — voir l'audit D-029.2 ci-dessus). Aucun prevent-delete
-- ajouté : la suppression d'un fichier vendu sera bloquée par le futur RESTRICT
-- de download_grants ; les fichiers invendus restent purgeables. Ce gate ne crée
-- AUCUNE table (ni snapshot bundle, ni grant, ni log).

-- P4-A1 — SNAPSHOT DES COMPOSANTS DE BUNDLE À LA COMMANDE (gate isolé,
-- migration `000009`, créée uniquement APRÈS merge de `000008`).
-- Figé à la création de la commande par le futur OrderService ; immuable ensuite.
-- Contrat D-029.3 : trois fonctions / trois triggers (S1 prevent-delete,
-- S2 immutabilité, S3 validation immédiate). Aucune quantité (le pivot n'en a
-- pas) ; aucune `position` (ordre d'affichage mutable, inutile à P4-A2 — pas de
-- duplication « au cas où »). Snapshots textuels JUSTIFIÉS : `products.name/slug`
-- sont mutables (aucun trigger) et `child_product_id` est SET NULL, donc la FK
-- seule ne suffit pas à l'audit — pattern D-027 (`order_items`) : la preuve
-- d'achat (nom + slug achetés) survit à une purge légale du produit. Doublon
-- composant déjà impossible en amont (PK composite du pivot) ; l'unique partiel
-- reste la garde anti double-copie concurrente et n'empêche jamais le même
-- composant dans deux OrderItems ou deux commandes distincts.
CREATE TABLE order_item_bundle_components (
    id                          BIGSERIAL PRIMARY KEY,
    order_item_id               BIGINT NOT NULL REFERENCES order_items(id) ON DELETE RESTRICT,
    child_product_id            BIGINT REFERENCES products(id) ON DELETE SET NULL,
    child_product_name_snapshot TEXT NOT NULL,
    child_product_slug_snapshot TEXT NOT NULL,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE order_item_bundle_components ADD CONSTRAINT oibc_child_name_not_blank_check CHECK (length(btrim(child_product_name_snapshot)) > 0);
ALTER TABLE order_item_bundle_components ADD CONSTRAINT oibc_child_slug_not_blank_check CHECK (length(btrim(child_product_slug_snapshot)) > 0);
CREATE UNIQUE INDEX oibc_order_item_child_unique
    ON order_item_bundle_components (order_item_id, child_product_id) WHERE child_product_id IS NOT NULL;
CREATE INDEX oibc_order_item_id_index    ON order_item_bundle_components (order_item_id);
CREATE INDEX oibc_child_product_id_index ON order_item_bundle_components (child_product_id);
-- Triggers S1/S2/S3 (D-029.3, détail dans le catalogue ci-dessous) : S1
-- prevent-delete absolu ; S2 immutabilité totale hors la seule nullification
-- child_product_id non-NULL->NULL via l'action FK imbriquée ON DELETE SET NULL
-- (pattern order_items.product_id / refunds.initiated_by_user_id) ; S3 validation
-- immédiate BEFORE INSERT (order_item bundle, product_id non NULL, composant
-- existant, composant NON-bundle, composant ∈ product_bundles au moment de la copie).

-- P4-A2 — DROITS DE TÉLÉCHARGEMENT (gate isolé, migration `000010`, créée
-- uniquement APRÈS merge de `000009`)
CREATE TABLE download_grants (
    id                  BIGSERIAL PRIMARY KEY,
    public_id           UUID   NOT NULL,               -- identifiant public ≠ secret
    order_item_id       BIGINT NOT NULL REFERENCES order_items(id) ON DELETE RESTRICT,
    product_file_id     BIGINT NOT NULL REFERENCES product_files(id) ON DELETE RESTRICT,
    user_id             BIGINT REFERENCES users(id) ON DELETE SET NULL,  -- audit seul
    token_hash          VARCHAR(64) NOT NULL,          -- SHA-256 ; token brut JAMAIS stocké
    expires_at          TIMESTAMPTZ NOT NULL,          -- EXPLICITE à l'insertion (TTL en config applicative, 72 h recommandé)
    max_downloads       BIGINT NOT NULL,               -- EXPLICITE à l'insertion, AUCUN DEFAULT commercial (D-029.1-B ; 5 recommandé en config)
    downloads_count     BIGINT NOT NULL DEFAULT 0,
    revoked_at          TIMESTAMPTZ,                   -- set-once, apparié au motif
    revoked_reason_code VARCHAR(100),                  -- ex: refund|fraud|file_replaced|support
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
ALTER TABLE download_grants ADD CONSTRAINT download_grants_public_id_unique  UNIQUE (public_id);
ALTER TABLE download_grants ADD CONSTRAINT download_grants_token_hash_unique UNIQUE (token_hash);
ALTER TABLE download_grants ADD CONSTRAINT download_grants_token_hash_format_check CHECK (token_hash ~ '^[0-9a-f]{64}$');
ALTER TABLE download_grants ADD CONSTRAINT download_grants_expires_after_created_check CHECK (expires_at > created_at);
ALTER TABLE download_grants ADD CONSTRAINT download_grants_max_downloads_positive_check CHECK (max_downloads >= 1);
ALTER TABLE download_grants ADD CONSTRAINT download_grants_count_within_quota_check CHECK (downloads_count >= 0 AND downloads_count <= max_downloads);
-- Révocation appariée, stricte face à CHECK = UNKNOWN :
ALTER TABLE download_grants ADD CONSTRAINT download_grants_revocation_pair_check CHECK (
    (CASE
        WHEN revoked_at IS NULL THEN revoked_reason_code IS NULL
        ELSE revoked_reason_code IS NOT NULL AND length(btrim(revoked_reason_code)) > 0
    END) IS TRUE
);
-- Un seul grant ACTIF par couple (réémission possible après révocation, quantité
-- multi-unités gérée par max_downloads, licences hors P4).
-- ⚠️ D-029.4, finding 2 : un grant EXPIRÉ mais NON RÉVOQUÉ reste dans ce prédicat
-- et bloque donc toute nouvelle émission pour le même couple — il doit être
-- RÉVOQUÉ avant réémission (motif recommandé `expired_reissue`). Aucun index
-- partiel n'utilisera `now()` (prédicat non immutable, donc interdit). Expiration
-- et révocation restent deux notions DISTINCTES : l'expiration n'écrit rien, aucun
-- job ne révoque implicitement, la réémission révoque explicitement au préalable.
CREATE UNIQUE INDEX download_grants_active_pair_unique
    ON download_grants (order_item_id, product_file_id) WHERE revoked_at IS NULL;
CREATE INDEX download_grants_order_item_id_index   ON download_grants (order_item_id);
CREATE INDEX download_grants_product_file_id_index ON download_grants (product_file_id);
CREATE INDEX download_grants_user_id_index         ON download_grants (user_id);
CREATE INDEX download_grants_active_expiry_index   ON download_grants (expires_at) WHERE revoked_at IS NULL;

-- P4-A2.1 — DURCISSEMENT ADDITIF G2/G3 (migration `000011`, `000010` immuable)
-- Vulnérabilités reproduites avant correction :
--   1. une commande invitée (`orders.user_id IS NULL`) acceptait un user_id arbitraire ;
--   2. updated_at pouvait être modifié isolément sans transition de cycle de vie.
-- G3 remplace la branche nullable ambiguë par la matrice stricte PostgreSQL :
--     NEW.user_id IS NOT DISTINCT FROM orders.user_id
-- soit invité->NULL uniquement et compte->même identifiant uniquement.
-- G2 n'accepte un changement de updated_at que s'il est strictement croissant ET
-- accompagne exactement une consommation downloads_count + 1 ou une révocation
-- NULL->(timestamp + motif). Une nullification user_id causée par la FK SET NULL
-- reste autorisée avec updated_at inchangé ; nullification/remplacement manuels
-- restent refusés. Aucune donnée n'est réécrite. Aucun objet n'est ajouté : les
-- signatures et liaisons des 4 fonctions / 5 triggers G1–G4 sont conservées.
-- Le down() restaure textuellement les versions G2/G3 de 000010 ; le harness
-- compare les définitions pg_get_functiondef avant up() et après down().
-- Validation post-merge : 27 migrations ; P4-A2.1 7/113 ; suite 158/2301 ;
-- Pint 112 ; rollback isolé vert ; 4 fonctions / 5 triggers et G4 différé intacts.

-- P4-B — JOURNAL DE CONSOMMATION (D-029.5 ; migration future `000012`).
-- STATUT : P4-B PLANIFIÉ — NON IMPLÉMENTÉ. Ce bloc est le contrat exact de la
-- prochaine migration, pas la description d'objets déjà présents.
-- Décisions : 1A consommation à `started` ; 2A rétention NOT NULL explicite ;
-- 3A HMAC IP versionné ; R1A une tentative authentifiée regroupe Range/retries ;
-- R2A HEAD ne consomme rien et ne journalise rien ; R3A `completed` signifie
-- remise réussie au mécanisme de livraison, JAMAIS réception intégrale client.
-- Un token de grant INCONNU n'entre jamais ici : ces essais vont au rate limiting
-- et aux logs de sécurité, avec réponse publique uniforme/non énumérable.
CREATE TABLE download_logs (
    id                       BIGSERIAL PRIMARY KEY,
    public_id                UUID NOT NULL,
    download_grant_id        BIGINT NOT NULL,
    status                   VARCHAR(20) NOT NULL,     -- started|completed|denied ; aucun DEFAULT
    quota_consumed           BOOLEAN NOT NULL,         -- marqueur historique immuable ; aucun DEFAULT
    attempt_token_hash       VARCHAR(64),              -- SHA-256 du secret de tentative, jamais le secret
    attempt_expires_at       TIMESTAMPTZ,              -- courte, explicite, immuable ; aucun DEFAULT
    denial_reason_code       VARCHAR(64),              -- code fermé sanitizé, jamais une exception libre
    ip_hash                  VARCHAR(64),              -- HMAC-SHA-256 ; jamais l'IP brute
    ip_hash_key_version      SMALLINT,                 -- version de clé HMAC, jamais la clé
    user_agent               VARCHAR(500),
    bytes_sent               BIGINT,                   -- mesure optionnelle, jamais preuve de réception
    terminal_at              TIMESTAMPTZ,              -- set-once lors de completed/denied
    retention_until          TIMESTAMPTZ NOT NULL,     -- explicite ; aucun DEFAULT (365 j = config recommandée)
    created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT download_logs_download_grant_id_foreign
        FOREIGN KEY (download_grant_id) REFERENCES download_grants(id) ON DELETE RESTRICT
);
ALTER TABLE download_logs ADD CONSTRAINT download_logs_public_id_unique
    UNIQUE (public_id);
ALTER TABLE download_logs ADD CONSTRAINT download_logs_status_check
    CHECK (status IN ('started', 'completed', 'denied'));
-- Cohérence stricte anti-CHECK=UNKNOWN. Le trigger G5 distingue en plus un
-- denied consommant (uniquement issu de started) d'un denied direct non consommant.
ALTER TABLE download_logs ADD CONSTRAINT download_logs_state_consistency_check CHECK (
    (CASE
        WHEN status = 'started' THEN
            quota_consumed IS TRUE
            AND attempt_token_hash IS NOT NULL
            AND attempt_expires_at IS NOT NULL
            AND denial_reason_code IS NULL
            AND terminal_at IS NULL
        WHEN status = 'completed' THEN
            quota_consumed IS TRUE
            AND attempt_token_hash IS NOT NULL
            AND attempt_expires_at IS NOT NULL
            AND denial_reason_code IS NULL
            AND terminal_at IS NOT NULL
        WHEN status = 'denied' THEN
            denial_reason_code IS NOT NULL
            AND terminal_at IS NOT NULL
            AND (
                (quota_consumed IS TRUE
                    AND attempt_token_hash IS NOT NULL
                    AND attempt_expires_at IS NOT NULL)
                OR
                (quota_consumed IS FALSE
                    AND attempt_token_hash IS NULL
                    AND attempt_expires_at IS NULL)
            )
        ELSE FALSE
    END) IS TRUE
);
ALTER TABLE download_logs ADD CONSTRAINT download_logs_denial_reason_code_check CHECK (
    (CASE
        WHEN denial_reason_code IS NULL THEN status <> 'denied'
        ELSE denial_reason_code IN (
            'authorization_denied',
            'quota_exhausted',
            'grant_expired',
            'grant_revoked',
            'order_not_deliverable',
            'product_file_unavailable',
            'attempt_expired',
            'delivery_interrupted',
            'storage_failure',
            'internal_error'
        )
    END) IS TRUE
);
ALTER TABLE download_logs ADD CONSTRAINT download_logs_attempt_hash_format_check
    CHECK (attempt_token_hash IS NULL OR attempt_token_hash ~ '^[0-9a-f]{64}$');
ALTER TABLE download_logs ADD CONSTRAINT download_logs_attempt_window_check CHECK (
    (CASE
        WHEN attempt_token_hash IS NULL THEN attempt_expires_at IS NULL
        ELSE attempt_expires_at IS NOT NULL
            AND attempt_expires_at > created_at
            AND attempt_expires_at <= retention_until
    END) IS TRUE
);
ALTER TABLE download_logs ADD CONSTRAINT download_logs_terminal_timestamp_check
    CHECK (terminal_at IS NULL OR terminal_at >= created_at);
ALTER TABLE download_logs ADD CONSTRAINT download_logs_retention_after_created_check
    CHECK (retention_until > created_at);
ALTER TABLE download_logs ADD CONSTRAINT download_logs_ip_identity_check CHECK (
    (CASE
        WHEN ip_hash IS NULL THEN ip_hash_key_version IS NULL
        ELSE ip_hash ~ '^[0-9a-f]{64}$'
            AND ip_hash_key_version IS NOT NULL
            AND ip_hash_key_version > 0
    END) IS TRUE
);
ALTER TABLE download_logs ADD CONSTRAINT download_logs_user_agent_not_blank_check
    CHECK (user_agent IS NULL OR length(btrim(user_agent, E' \t\n\r\f\v')) > 0);
ALTER TABLE download_logs ADD CONSTRAINT download_logs_bytes_sent_non_negative_check
    CHECK (bytes_sent IS NULL OR bytes_sent >= 0);

CREATE UNIQUE INDEX download_logs_attempt_token_hash_unique
    ON download_logs (attempt_token_hash) WHERE attempt_token_hash IS NOT NULL;
CREATE INDEX download_logs_grant_created_index
    ON download_logs (download_grant_id, created_at DESC);
CREATE INDEX download_logs_active_attempts_index
    ON download_logs (download_grant_id, attempt_expires_at)
    WHERE status = 'started';
CREATE INDEX download_logs_terminal_retention_index
    ON download_logs (retention_until)
    WHERE status IN ('completed', 'denied');

-- INSERT `started` : quota_consumed=true, secret/expiration présents, motif et
-- terminal_at NULL, bytes_sent NULL à la naissance, rétention explicite. Dans la
-- même transaction courte, G5 verrouille Order PUIS Grant, revalide, insère et
-- incrémente le compteur de +1. La livraison ne commence qu'après COMMIT.
-- INSERT direct `denied` : quota_consumed=false, aucun secret/expiration,
-- motif fermé + terminal_at présents, aucun incrément. INSERT completed interdit.
-- Transitions : started -> completed|denied seulement ; quota et secret figés ;
-- terminal_at set-once ; denied exige son motif ; quota jamais restitué.
-- completed/denied sont terminaux. Exception unique à leur immutabilité :
-- retention_until peut seulement être prolongé. bytes_sent est NULL ou monotone
-- pendant started, devient immuable au terminal, et n'a jamais à égaler size_bytes.
-- Secret de tentative : CSPRNG distinct du token du grant et des public_id,
-- digest SHA-256 uniquement, digest différent de download_grants.token_hash,
-- aucun préfixe. public_id + secret requis ; public_id seul ne donne aucun droit.
-- attempt_expires_at est courte, explicite, sans DEFAULT, non prolongeable ; après
-- expiration une nouvelle tentative et une nouvelle unité sont requises.
-- Range/retries valides réutilisent CETTE ligne et son grant/ProductFile : aucun
-- log ni incrément par segment. Révocation ou remboursement total restent
-- autoritaires. HEAD ne crée ni log, ni secret, ni incrément.
-- completed = remise au mécanisme (Laravel/X-Accel/X-Sendfile/URL temporaire),
-- pas preuve de réception client. Une panne après commit peut laisser started
-- consommé ; une réconciliation future explicite le terminalise, jamais un timeout BDD.

-- ─────────────────────────────────────────────────────────────────────────────
-- CATALOGUE DES FONCTIONS/TRIGGERS P4 (noms stables). G0/S1-S3/G1-G4
-- vérifient et refusent sans mutation. D-029.5 introduit une exception explicite :
-- G5 est l'autorité PostgreSQL qui apparie l'INSERT started et l'unique incrément
-- du grant dans la même transaction ; aucune autre fonction P4 ne mute une table.
-- Ordre de verrouillage global étendu : orders -> payments -> coupons ->
-- refunds/agrégats -> download_grants.
-- ─────────────────────────────────────────────────────────────────────────────
-- P4-A0 → P4-A2 (un gate isolé par migration — D-029.2)
--  G0 enforce_product_files_content_immutability() /
--        product_files_enforce_content_immutability_trigger (gate P4-A0, `000008`)
--        BEFORE UPDATE ON product_files. Figés après insertion (identité du
--        contenu, D-029.2) : product_id, storage_disk, storage_path,
--        checksum_sha256, size_bytes, mime_type, version, created_at. Mutables :
--        is_active, position, original_name (libellé d'affichage uniquement).
--        Toute nouvelle version de contenu = NOUVELLE ligne ; le même
--        product_file_id ne peut plus jamais pointer vers un autre contenu ni
--        changer d'étiquette de version après l'achat.
--  S1 prevent_order_item_bundle_components_delete() /
--        order_item_bundle_components_prevent_delete_trigger (gate P4-A1, `000009`)
--        BEFORE DELETE -> RAISE 23514 (snapshot d'achat, jamais supprimé ; un
--        DELETE multi-lignes échoue à la première ligne, donc entièrement).
--  S2 enforce_order_item_bundle_component_immutability() /
--        order_item_bundle_components_enforce_immutability_trigger
--        BEFORE UPDATE. Comparaisons `IS DISTINCT FROM` (résistantes à NULL) ;
--        affectation à valeur identique acceptée ; aucune réécriture silencieuse ;
--        RAISE 23514 stable. Tout figé (id, order_item_id, child_product_id,
--        child_product_name_snapshot, child_product_slug_snapshot, created_at) ;
--        SEULE exception : child_product_id non-NULL -> NULL via l'action FK
--        imbriquée ON DELETE SET NULL, toutes les autres colonnes identiques
--        (pattern order_items.product_id / refunds.initiated_by_user_id ; un
--        UPDATE applicatif direct de child_product_id reste refusé).
--  S3 validate_order_item_bundle_component() /
--        order_item_bundle_components_validate_trigger        IMMÉDIAT (D-029.3)
--        BEFORE INSERT. VÉRIFIE et REFUSE uniquement — ne crée ni ne modifie
--        aucune ligne. Contrôles, dans l'ordre :
--          1. order_item existe (sinon la FK porte le message final, pattern P3C) ;
--          2. order_item.product_type_snapshot = 'bundle'  -> sinon RAISE :
--             un order_item DIRECT ne reçoit jamais de composant ;
--          3. order_item.product_id IS NOT NULL             -> sinon RAISE :
--             sans le produit bundle, la lignée n'est pas prouvable (fail-closed) ;
--          4. child_product existe ;
--          5. child_product.type <> 'bundle'                -> sinon RAISE :
--             bundles imbriqués EXCLUS (D-029.3, Q1=A) ;
--          6. EXISTS (SELECT 1 FROM product_bundles WHERE bundle_id =
--             order_item.product_id AND child_product_id = NEW.child_product_id)
--             -> sinon RAISE : le composant n'appartient pas au bundle acheté.
--        Le contrôle 6 lit le pivot AU MOMENT DE LA COPIE (création de la
--        commande) — c'est la seule lecture légitime du pivot ; aucune
--        comparaison au pivot courant n'existe après le COMMIT.
--  EXHAUSTIVITÉ (D-029.3, Q2=A) — GARANTIE APPLICATIVE, PAS BDD. Un trigger
--        ligne-par-ligne prouve que chaque ligne est valide, jamais que TOUTES
--        les lignes ont été copiées. Le futur OrderService copie via un UNIQUE
--        `INSERT INTO order_item_bundle_components ... SELECT ... FROM
--        product_bundles WHERE bundle_id = ...` : exhaustif ET cohérent par
--        construction (une seule requête = un seul instantané, aucun mélange
--        avant/après possible), dans la MÊME transaction que la création de
--        l'order_item, après `SELECT ... FROM products WHERE id = <bundle_id>
--        FOR UPDATE`. Options écartées : constraint trigger différé (il relit le
--        pivot au COMMIT -> faux refus d'un checkout légitime sous concurrence,
--        et dépendance permanente au pivot mutable — anti-pattern interdit) ;
--        fonction de copie atomique (elle MUTERAIT, contre le principe « les
--        triggers vérifient et refusent, le service exécute les mutations »).
--        RISQUE RÉSIDUEL ASSUMÉ, JAMAIS PRÉSENTÉ COMME ÉLIMINÉ PAR POSTGRESQL :
--        un rôle SQL privilégié peut insérer tardivement une ligne pour un
--        composant ajouté au bundle après l'achat (S3 la validerait, le pivot
--        courant la contenant). Couverture : permissions BDD minimales, absence
--        d'API de mutation directe, et tests — cohérent avec D-027.
--  G1 prevent_download_grants_delete()      / download_grants_prevent_delete_trigger
--        BEFORE DELETE -> RAISE 23514 (un grant se révoque, ne se supprime jamais).
--  G2 enforce_download_grants_immutability() / download_grants_enforce_immutability_trigger
--        BEFORE UPDATE. Figés : id, public_id, order_item_id, product_file_id,
--        token_hash, expires_at, max_downloads, created_at. user_id : non-NULL->NULL
--        UNIQUEMENT via l'action FK imbriquée ON DELETE SET NULL (pattern refunds).
--        downloads_count : monotone, +1 EXACTEMENT par UPDATE, refusé si
--        OLD.revoked_at IS NOT NULL, si OLD.expires_at <= now() ou si le quota est
--        atteint (défense en profondeur du CHECK). Consommation RÉELLE seulement
--        en P4-B : P4-A2 crée la colonne et ses bornes, aucune requête utilisateur
--        n'incrémente avant P4-B. revoked_at + revoked_reason_code : set-once
--        APPARIÉS (NULL->valeur ensemble), IRRÉVERSIBLES — timestamp -> NULL,
--        timestamp A -> timestamp B et modification du motif après révocation sont
--        tous refusés ; consommation et révocation jamais combinées dans le même
--        UPDATE. Aucune réécriture silencieuse ; valeur identique acceptée.
--  G3 validate_download_grant_delivery()    / download_grants_validate_delivery_trigger
--        BEFORE INSERT (immédiat) :
--          1. verrouille la commande du order_item : SELECT ... FROM orders ...
--             FOR UPDATE (sérialise contre un remboursement/annulation concurrent ;
--             respecte l'ordre de verrouillage global) ;
--          2. order.status IN ('paid','partially_refunded') — SOURCE D'AUTORITÉ
--             UNIQUE de l'éligibilité financière (D-029.4) : les constraint
--             triggers différés P3C garantissent déjà au COMMIT que ces états
--             impliquent un paiement réussi (ou une commande gratuite légitime).
--             G3/G4 NE relisent PAS `payments` : aucune nécessité démontrée,
--             aucune sémantique financière nouvelle. Non livrables : pending,
--             payment_review, cancelled, expired, refunded ;
--          3. order_item.product_id NOT NULL (lignée non prouvable sinon -> refus) ;
--          4. product_file.is_active = true à l'émission (un fichier désactivé
--             bloque les NOUVELLES émissions sans réécrire les grants existants) ;
--          4b. cohérence initiale NEW.user_id avec orders.user_id lorsqu'il est
--             renseigné (dénormalisation d'audit, D-029.4 finding 3) ;
--          5. lignée fichier (D-029.3) — reconnaissance du cas par
--             order_item.product_type_snapshot, figé par le trigger P3B :
--               * DIRECT (<> 'bundle') : product_file.product_id =
--                 order_item.product_id, strictement ;
--               * BUNDLE ('bundle') : product_file.product_id =
--                 order_item.product_id (fichiers propres du bundle) OU
--                 product_file.product_id IN (SELECT child_product_id FROM
--                 order_item_bundle_components WHERE order_item_id =
--                 NEW.order_item_id AND child_product_id IS NOT NULL) ;
--               * SNAPSHOT ABSENT sur un order_item bundle : AUCUN grant enfant
--                 (fail-closed, message stable) — aucun repli sur
--                 product_bundles, aucune supposition ;
--             SNAPSHOT d'achat uniquement (D-029.1-A), JAMAIS la composition
--             courante de product_bundles ;
--          6. NEW.downloads_count = 0 et NEW.revoked_at IS NULL à la naissance ;
--          7. token_hash au format attendu ; max_downloads et expires_at EXPLICITES
--             (bornés par les CHECK ; aucun DEFAULT commercial ne les fournit).
--        L'inexistence du order_item/product_file reste au message FK (pattern P3C).
--        G3 ne génère JAMAIS de token, ne crée aucune autre ligne, n'envoie aucun
--        e-mail, et ne modifie ni Order, ni Payment, ni ProductFile, ni le snapshot
--        bundle, ni aucun log : il VÉRIFIE et REFUSE.
--        ⚠️ RÈGLE D'ORCHESTRATION ROTATION (anti-deadlock) : toute rotation doit
--        verrouiller `orders` D'ABORD (ordre global), PUIS révoquer l'ancien grant,
--        PUIS insérer le nouveau. Révoquer avant de verrouiller orders croiserait
--        les verrous avec un remboursement concurrent (orders -> grants) et créerait
--        un cycle de deadlock.
--  G4 validate_download_grant_order_consistency() / DEFERRABLE INITIALLY DEFERRED,
--        monté sur download_grants_validate_order_consistency_trigger
--        (AFTER INSERT OR UPDATE OF revoked_at ON download_grants) ET
--        orders_validate_download_consistency_trigger (AFTER UPDATE OF status ON orders).
--        Au COMMIT : tout grant ACTIF (revoked_at IS NULL) => sa commande est en
--        statut livrable (paid|partially_refunded). Une commande refunded/cancelled/
--        expired/pending/payment_review ne conserve AUCUN grant actif au commit ;
--        le RefundService révoque les grants et change orders.status dans la MÊME
--        transaction (ordre des opérations réparable). Aucune mutation automatique
--        de orders.status ; les téléchargements déjà consommés restent en historique.
-- P4-B
--  G2 (remplacée en place par `000012`, aucun trigger grant supplémentaire) :
--        `enforce_download_grants_immutability()` conserve intégralement le contrat
--        P4-A2.1 (identité, nullification FK user, révocation, quota, updated_at),
--        mais refuse tout UPDATE direct du compteur à profondeur 1. L'exact +1
--        n'est accepté que dans l'UPDATE imbriqué émis par G5
--        (`pg_trigger_depth() > 1`). Le rôle runtime n'a aucun privilège DDL pour
--        fabriquer un autre trigger. Le down() de `000012` restaure textuellement
--        la définition G2 provenant de `000011`.
--  G5 enforce_download_logs_integrity() / download_logs_enforce_integrity_trigger
--        BEFORE INSERT OR UPDATE. Nouvelle fonction + nouveau trigger.
--        INSERT started : lecture minimale de la lignée, verrou `orders FOR UPDATE`
--        PUIS `download_grants FOR UPDATE`, relecture et validation de l'Order
--        livrable, du grant non révoqué/non expiré, du quota et du ProductFile ;
--        digest de tentative distinct du token_hash du grant ; UPDATE exact +1 du
--        compteur et insertion atomiques. Toute erreur rollbacke les deux.
--        INSERT denied direct : grant connu, quota_consumed=false, aucun secret,
--        motif fermé et terminal_at présents, aucun UPDATE du grant. INSERT
--        completed ou denied consommant direct refusé.
--        UPDATE : seules progression monotone de bytes_sent pendant started,
--        extension de retention_until et transitions started->completed|denied
--        sont admises. quota_consumed, grant, public_id, secret/expiration, HMAC,
--        identité et created_at sont figés ; terminal_at set-once avec la transition.
--        Après terminalisation, seule une extension de rétention est admise ; aucun
--        retour, aucune seconde terminalisation, aucune réécriture silencieuse.
--        Range/retries ne provoquent aucun INSERT : lookup unique digest + public_id,
--        même grant donc même ProductFile, secret non expiré, Order/Grant revalidés.
--        HEAD ne passe jamais par G5 et n'écrit rien.
--  G6 enforce_download_logs_retention_delete() / download_logs_retention_delete_trigger
--        BEFORE DELETE. Nouvelle fonction + nouveau trigger. Suppression autorisée
--        uniquement si retention_until <= transaction_timestamp() ET statut terminal
--        completed|denied. Un DELETE multi-lignes contenant une ligne non éligible
--        échoue atomiquement. Aucun décrément, restitution de quota ou effet sur
--        Grant/OrderItem/ProductFile. Job de purge hors P4-B.
--  CATALOGUE P4-B EXACT : 2 nouvelles fonctions + 2 nouveaux triggers ; 1 fonction
--        G2 remplacée en place ; aucun constraint trigger P4-B, aucune fonction ou
--        trigger supplémentaire.

-- ─────────────────────────────────────────────────────────────────────────────
-- PLAN DE TESTS PostgreSQL RÉEL P4 (jamais SQLite ; SET CONSTRAINTS ALL IMMEDIATE
-- pour forcer les différés ; SQLSTATE + nom de contrainte/message exacts)
-- ─────────────────────────────────────────────────────────────────────────────
-- SCHÉMA : types physiques (uuid/bigint/varchar(64)/timestamptz), FK RESTRICT/SET NULL,
--   CHECK nommés, index partiels, fonctions/triggers présents, différés confirmés,
--   absence licenses/events/P5 ; INSERT sans max_downloads ou expires_at explicites
--   refusé (aucun DEFAULT commercial — D-029.1-B).
-- DURCISSEMENT G0 (gate P4-A0) : UPDATE de product_id/storage_path/checksum_sha256/
--   size_bytes/mime_type/storage_disk/version/created_at refusé (message stable) ;
--   is_active/position/original_name mutables ; nouvelle ligne pour une nouvelle
--   version acceptée ; désactivation de l'ancienne acceptée ; comportements P2
--   antérieurs non cassés (suite Catalog verte).
-- SNAPSHOT BUNDLE — MATRICE P4-A1 (S1/S2/S3), gate `000009` :
--   SCHÉMA : table, types physiques, nullabilité, FK (order_item_id RESTRICT,
--     child_product_id SET NULL), CHECK nommés, unique partiel, index, 3 fonctions,
--     3 triggers, timings (2 BEFORE UPDATE/DELETE + 1 BEFORE INSERT), aucun différé.
--   SNAPSHOT VALIDE : bundle à un composant ; bundle à plusieurs composants ; deux
--     OrderItems du même bundle ; deux commandes du même bundle ; même composant
--     dans deux OrderItems distincts (accepté).
--   BUNDLE VIDE : prouver que la BDD ACCEPTE un order_item bundle SANS aucune
--     ligne de snapshot (aucune contrainte de cardinalité minimale) ET documenter
--     dans le test que le refus incombe au futur OrderService (garantie
--     applicative) ; prouver côté P4-A2 (gate suivant) qu'un tel order_item
--     n'émet aucun grant enfant (fail-closed).
--   PRODUIT DIRECT : aucun snapshot créé ; insertion sur un order_item non-bundle
--     REFUSÉE par S3 (message stable).
--   INTÉGRITÉ (S3) : composant hors du bundle refusé ; composant d'un AUTRE bundle
--     refusé ; composant de type 'bundle' refusé (imbrication) ; order_item.product_id
--     NULL refusé ; product inexistant -> message FK ; order_item inexistant ->
--     message FK ; doublon (order_item_id, child_product_id) -> 23505 index nommé.
--   IMMUTABILITÉ (S2) : chaque colonne métier refusée séparément ; UPDATE à valeur
--     identique accepté ; UPDATE multi-colonnes mêlant figée et figée refusé
--     atomiquement ; SQL brut et Eloquent refusés à l'identique ; nullification
--     manuelle de child_product_id refusée, mais action FK ON DELETE SET NULL admise.
--   SUPPRESSION (S1) : DELETE simple refusé ; DELETE multi-lignes refusé en bloc ;
--     suppression via relation refusée ; DELETE order_item déjà refusé par P3B ;
--     DELETE product composant -> child_product_id NULL, snapshot et textes préservés.
--   HISTORIQUE (le coeur du gate) : ajout de C au pivot APRÈS l'achat -> snapshot
--     inchangé, C jamais acheté ; retrait de B du pivot APRÈS l'achat -> snapshot
--     inchangé, B toujours reconnu comme acheté (réémission tardive possible) ;
--     aucune requête du plan ne relit le pivot après le COMMIT.
--   CONCURRENCE (2 connexions réelles) : checkout vs ajout de composant, checkout vs
--     retrait de composant -> snapshot cohérent (jamais un mélange avant/après) grâce
--     au verrou `products FOR UPDATE` + INSERT...SELECT unique ; double copie
--     simultanée du même snapshot -> une seule réussit (23505) ; deux commandes de
--     bundles distincts -> aucun blocage mutuel.
--   ROLLBACK ISOLÉ : frontière `000009` (voir table de préservation ci-dessous).
-- TOKEN : hash 64 hex accepté ; hash invalide/majuscule refusé ; unicité ; token brut
--   absent de toute colonne ; réémission après révocation OK ; deux grants actifs même
--   couple refusés (index partiel).
-- AUTORISATION : commande paid livrable ; partially_refunded livrable ; pending/
--   payment_review/cancelled/expired/refunded refusés ; commande gratuite paid
--   livrable sans payment ; order_item.product_id NULL refusé ; fichier inactif
--   refusé ; fichier d'un autre produit refusé ; fichier enfant de bundle accepté ;
--   fichier hors bundle refusé ; snapshot bundle TOTALEMENT absent refusé ;
--   snapshot PARTIEL non détectable — test documentant explicitement la limite
--   (sous-livraison possible, jamais de sur-livraison) ; user_id incohérent avec
--   orders.user_id refusé.
-- FICHIER AJOUTÉ APRÈS L'ACHAT (D-029.4, option A) : G3 le validerait
--   TECHNIQUEMENT (la lignée produit est correcte) — le test doit le prouver ET
--   consigner que l'absence d'émission automatique est une GARANTIE APPLICATIVE
--   (le listener n'émet qu'à OrderPaid ; rotation et réémission conservent le même
--   product_file_id), jamais un invariant PostgreSQL.
-- ROTATION / RÉÉMISSION : rotation conserve le même order_item_id + product_file_id
--   avec un nouveau digest ; l'ancien grant doit être révoqué d'abord (sinon
--   l'unique partiel refuse en 23505) ; un grant EXPIRÉ non révoqué bloque la
--   réémission jusqu'à révocation `expired_reissue` ; une rotation vers un AUTRE
--   product_file_id relève du service (upgrade), la lignée G3 seule ne la refuse pas.
-- LIMITES : consommation +1 OK ; dépassement quota refusé (CHECK + trigger) ;
--   consommation après expiration refusée ; consommation après révocation refusée ;
--   décrément/écart > 1 refusé ; révocation set-once appariée au motif ;
--   dé-révocation refusée ; combinaison consommation+révocation refusée.
-- CONCURRENCE (2 connexions réelles) : une seule utilisation restante -> une
--   transaction réussit, l'autre échoue proprement ; aucun compteur > quota ;
--   grants distincts sans blocage mutuel ; émission de grant vs remboursement total
--   concurrent sérialisés par le verrou orders (aucun grant actif orphelin).
-- REMBOURSEMENTS : refund partiel -> grants conservés ; refund total + révocation
--   dans la même transaction -> commit OK ; refund total sans révocation -> refus au
--   COMMIT ; révocation puis changement de statut dans tout ordre transactionnel -> réparable ;
--   rollback préserve l'état antérieur.
-- SUPPRESSION : DELETE grant refusé ; DELETE user -> user_id NULL, grant intact ;
--   nullification manuelle user_id refusée ; DELETE product bloqué par RESTRICT
--   (via product_files) ; DELETE log avant rétention refusé, après rétention +
--   statut terminal accepté.
-- DOWNLOAD LOGS — MATRICE P4-B (`000012`, PostgreSQL réel) :
--   SCHÉMA : 15 colonnes exactes ; types uuid/bigint/varchar/boolean/timestamptz ;
--     FK grant RESTRICT ; CHECK nommés stricts face à NULL/UNKNOWN ; uniques
--     public_id/digest de tentative ; index grant, tentatives started et purge
--     terminale ; aucun DEFAULT métier ; aucun token/préfixe/IP brute/email/chemin/
--     checksum/JSONB/soft delete/updated_at ; aucun objet P5.
--   TENTATIVE INITIALE : une autorisation crée un seul started, quota_consumed=true,
--     digest unique distinct du token_hash du grant, expiration explicite, aucun
--     secret brut ; compteur +1 dans la même transaction ; erreur INSERT ou compteur
--     rollbacke les deux ; direct denied non consommant et sans incrément ; token
--     inconnu jamais journalisé ; direct completed et denied consommant refusés.
--   MARQUEUR/ÉTAT : started+false, completed+false, direct denied+true et motifs
--     incohérents refusés ; started->completed et started->denied acceptés ; quota,
--     secret et expiration immuables ; terminal->* refusé ; terminal_at obligatoire
--     au terminal ; no-op autorisé sans réécriture ; champs combinés adversariaux.
--   SECRET/FENÊTRE : hash absent/expiration présente et inverse refusés ; uppercase,
--     longueur ou alphabet invalides refusés ; expiration <= created_at ou > rétention
--     refusée ; digest du token de grant refusé ; rotation/prolongation refusées ;
--     public_id seul, mauvais secret, bon secret avec autre public_id refusés.
--   RANGE/RETRIES : Range et retry valides réutilisent le même log, aucun nouvel
--     incrément ; deux retries concurrents restent une seule consommation ; même
--     grant/ProductFile exigé, substitution refusée ; tentative expirée exige une
--     nouvelle autorisation ; révocation et remboursement total entre deux ranges
--     refusent la reprise sans nouvelle consommation.
--   HEAD/PRÉCHARGEMENT : HEAD répété = aucun log, compteur ou secret et réponse non
--     énumérable ; le contrat HTTP interdit query string et préchargement automatique
--     du GET consommant (tests futurs de route, hors migration BDD).
--   COMPLETED/OCTETS : remises Laravel, X-Accel/X-Sendfile et URL temporaire
--     simulées ; bytes_sent NULL accepté ; bytes_sent < size_bytes n'empêche pas
--     completed ; progression monotone pendant started, négatif/recul/édition
--     terminale refusés ; aucune assertion ne prétend prouver la réception client.
--   IP/RÉTENTION/G6 : HMAC 64 lowercase + version positive présents ensemble ;
--     version/hash immuables ; user-agent max 500/non blanc ; rétention obligatoire,
--     passée/réduction refusées, extension acceptée ; purge started/prématurée
--     refusée, terminale échue acceptée ; DELETE mixte atomique ; compteur inchangé.
--   G2/G5 CONCURRENCE (2 connexions) : UPDATE direct downloads_count refusé ;
--     dernière unité concurrente => un started et un seul +1 ; l'autre attend puis
--     échoue/revalide sans dépassement ; transitions terminales concurrentes sans
--     réouverture ; grants distincts sans verrou global ; ordre Order->Grant et
--     absence de deadlock prouvés ; aucune transaction de streaming.
--   CONFIDENTIALITÉ : secret de grant/tentative absent BDD, erreurs, logs et
--     analytics ; aucun secret en query string ; IP brute/clé HMAC absentes ; codes
--     de refus fermés et sanitizés ; requêtes inconnues dans logs sécurité seulement.
--   ROLLBACK P4-B : avec ligne, down() refuse en 23514 et tous les objets/données
--     restent ; table vide, seul `000012` descend, 2 triggers + 2 fonctions P4-B
--     disparaissent, G2 est exactement restaurée depuis `000011`, download_logs
--     disparaît et G0/S1-S3/G1-G4/P4-A2.1 restent identiques.
--   NON-RÉGRESSION : migrate:fresh = 28 migrations ; P2/P3/P4-A restent verts ;
--     download_logs est retiré seulement des assertions globales de tables futures,
--     mais reste ABSENT dans tous les rollbacks dont la frontière précède `000012`.
-- THREAT MODEL P4-B : vol/rejeu du secret (TTL court + revalidation ; risque dans
--   la fenêtre, gate HTTP) ; collision SHA-256 (CSPRNG+unique, risque cryptographique
--   résiduel) ; substitution fichier (lignée log->grant->ProductFile immuable) ;
--   usage après révocation/refund (revalidation Order/Grant) ; fuite query/log
--   (transport interdit + tests) ; retries/Range concurrents (unique+verrous ; rate
--   limit futur) ; tentative expirée (nouvelle unité) ; préchargement/HEAD
--   (action explicite, HEAD sans effet ; risque externe résiduel) ; completed mal
--   interprété/bytes imprécis (sémantique explicite, télémétrie mécanisme) ; panne
--   après commit avant remise (started stale consommé, réconciliation explicite) ;
--   panne après completed (aucune preuve de réception client possible).
-- ROLLBACK ISOLÉ (PhaseMigrationHarness — D-029.2 : UNE frontière par gate).
--   Règle par gate : appliquer les migrations UNIQUEMENT jusqu'à sa frontière
--   (`migrate --path`), exécuter UNIQUEMENT le down() de sa migration
--   (`migrate:rollback --path`), vérifier la disparition de ses seuls objets,
--   la préservation de toutes les migrations antérieures et l'ABSENCE des
--   migrations futures ; nettoyage dans finally ; jamais de `migrate:fresh`
--   comme preuve, jamais de rollback global, jamais de dépendance à un gate futur.
--   | Gate rollbacké      | Supprimé                          | Préservé                    |
--   | P4-A0 / `000008`    | G0 (fn + trigger)                 | P0–P3C (product_files
--   |                     |                                   | redevient mutable — vérifié)|
--   | P4-A1 / `000009`    | order_item_bundle_components +    | P0–P3C + P4-A0 (G0 fn +     |
--   |                     | S1/S2/S3 (3 fn + 3 triggers)      | trigger, products,          |
--   |                     |                                   | product_files,              |
--   |                     |                                   | product_bundles, orders,    |
--   |                     |                                   | order_items) ; `000010`,    |
--   |                     |                                   | `000011`, `000012` absentes |
--   | P4-A2 / `000010`    | download_grants + G1–G4           | P0–P3C + P4-A0 + P4-A1      |
--   | P4-A2.1 / `000011`  | remplacements G2/G3               | table + G1/G4 + déf. G2/G3  |
--   | P4-B  / `000012`    | table vide : download_logs +       | P0–P3C + tout P4-A ; G2     |
--   |                     | 2 fn / 2 triggers G5–G6 ;          | restaurée EXACTEMENT depuis |
--   |                     | avec ligne : rollback REFUSÉ       | `000011`                    |
```

---

## 🅳 BLOC ANALYTIQUE — P5-A0 (D-037)

**État** : fondation PostgreSQL terminée, mergée et validée via PR #26, head
`8d9d8cc798e6a35ae74a36d1d9ae6a9d22bf171a`, merge
`94a8c08c5a9d8448dd161665f69602d84715432b`, CI #32 success. L'ingestion
P5-A1 est terminée, mergée et validée via PR #27, head `955cc340`, merge
`c699c5b9`, CI #33 success (D-038). P5-A2 est terminée, mergée et validée via
PR #28, head `03063db8acf0b974ab9369f72d188f8cb52df71b`, merge
`17aaa4f43fcac0d3ef5e039897f0d30666b9d29d`, CI #35 success (D-039).
P5-A3A/B est terminée, mergée et validée via PR #29, head `31f986dc`, merge
`2bbf2b52`, CI #36 success (D-040). P5-A3C est implémentée sur
`p5-a3c-product-funnel-analytics`, en attente de revue/merge (D-041). P5-A3D,
P6 et P7 ne sont pas commencés.

**Principe non négociable** : l'analytique ne pose aucune FK, aucun verrou et
aucune dépendance de disponibilité sur les tables chaudes du commerce.
`orders`, `order_items`, `payments` et `refunds` restent les sources financières
autoritatives. Les événements sont des observations append-only, non
autoritatives et potentiellement livrées au moins une fois.

### Migrations P5-A0

1. `2026_07_14_000014_create_partitioned_events_table.php`
2. `2026_07_14_000015_create_analytics_sessions_table.php`
3. `2026_07_14_000016_create_analytics_rollups_tables.php`

Chaque frontière possède un rollback PostgreSQL isolé. P5-A0 ne créait aucune
partition calendaire; P5-A2 ajoute uniquement une opération explicite et bornée.

### `events` et `events_default`

Le parent `events` est réellement `PARTITION BY RANGE (occurred_at)`. Il porte
une identité `BIGINT`, `public_id UUID`, les temps `occurred_at/created_at
TIMESTAMPTZ`, des identités molles (`visitor_id`, `user_id`, `session_id`), une
référence d'entité optionnelle, l'attribution, le contexte appareil/pays et un
HMAC IP optionnel versionné. La clé primaire est `(id, occurred_at)` et
l'unicité publique `(public_id, occurred_at)`, conformément aux contraintes
PostgreSQL des tables partitionnées.

`events_default` est la partition de repli. Les partitions calendaires sont
créées par l'opération P5-A2 contrôlée; chacune reçoit explicitement les mêmes
révocations ACL que le parent et aucune ligne DEFAULT n'est déplacée.

Contraintes principales :

- `event_name` et `entity_type` en snake_case minuscule strict;
- `entity_type` et `entity_id` simultanément présents ou absents;
- `properties JSONB` objet, sérialisation limitée à 16 KiB;
- chemins relatifs sans schéma, query, fragment ni CR/LF;
- `referrer_host` canonique, UTM minuscules bornés, pays uppercase;
- `ip_hash` absent avec sa version, ou SHA-256 minuscule avec version positive;
- `created_at >= occurred_at`.

Index B-tree : événement/date, visiteur/date, session/date, entité/date et
campagne UTM/date. Aucun GIN sur `properties` n'est créé avant l'existence d'un
contrat de requête mesuré.

`prevent_analytics_events_mutation` et
`analytics_events_prevent_mutation_trigger` refusent tout `UPDATE` ou `DELETE`
en SQLSTATE `23514`. Aucun trigger n'écrit dans le commerce.

### `analytics_sessions`

Table sans FK : `id UUID PRIMARY KEY`, `visitor_id UUID NOT NULL`, `user_id
BIGINT NULL`, temps de session en `TIMESTAMPTZ`, chemins d'entrée/sortie
relatifs, `page_views INTEGER`, UTM, appareil et pays. Les CHECK verrouillent
l'ordre temporel, les chemins, les formats et les compteurs non négatifs.
Index : visiteur/début, utilisateur/début et campagne/début.

P5-A0 ne crée ni cookie, ni middleware de session, ni écriture runtime.

### Rollups journaliers

Les rollups sont recalculables, sans FK, et utilisent exclusivement des entiers.
P5-A2 corrige leur contrat dimensionnel :

- `daily_sales_stats` : PK `(day, currency)`, compteurs et montants `BIGINT`;
  `net_revenue_minor = gross_revenue_minor - discount_minor + tax_minor -
  refunds_minor`; moyenne entière déterministe;
- `daily_product_stats` : PK `(day, product_id, currency)`, identifiant produit
  acheté immuable, achats/revenu en `BIGINT`; aucune vue ni ajout panier;
- `daily_product_engagement_stats` : PK `(day, product_id)`, vues/ajouts panier
  en `BIGINT`, aucune devise et aucune FK;
- `daily_funnel_stats` : PK `day`, visiteurs, sessions, vues produit, ajouts
  panier, checkouts, achats et nouveaux clients en `BIGINT`.

La devise est toujours `VARCHAR(3)` uppercase. Aucune colonne monétaire
`FLOAT`, `REAL`, `DOUBLE`, `DECIMAL`, `NUMERIC` ou `MONEY`. Le funnel ne force
pas une monotonie artificielle entre mesures indépendantes.

### ACL et confidentialité

`PUBLIC` et `digitrove_runtime` n'ont aucun droit direct sur les tables
analytiques, `events_default` ou `events_id_seq`. D-038 ajoute une autorité
d'ingestion dédiée sans rendre ce DML au runtime.

Ne sont jamais stockés : IP brute, e-mail, cookie, token, secret, payload
webhook, URL complète, query string, fragment ou chemin privé. `campaigns`,
segmentation client et affiliation sont reportés à P6.

### P5-A1 — First-party Event & Session Ingestion (D-038)

**État** : terminé, mergé et validé via PR #27, head `955cc340`, merge
`c699c5b9`, CI #33 success. Migration unique :
`2026_07_14_000017_create_analytics_ingestion_authority.php`. Elle ne crée
aucune table métier et porte le total à 33 migrations.

#### Autorité PostgreSQL

`digitrove_analytics_executor` est un rôle NOLOGIN restreint. Il possède
uniquement INSERT sur `events`, l'usage de `events_id_seq` et
SELECT/INSERT/UPDATE sur `analytics_sessions`. La fonction
`public.ingest_first_party_analytics_event` est SECURITY DEFINER, possédée par
ce rôle, avec `search_path` épinglé et objets qualifiés. `PUBLIC` n'a aucun
EXECUTE; `digitrove_runtime` a seulement EXECUTE et aucun DML analytique direct.
La fonction ne contient ni SQL dynamique, ni DDL, ni lecture Commerce.

La fonction reçoit des valeurs déjà normalisées, génère l'heure serveur,
verrouille le visiteur par advisory lock puis la session réutilisable
`FOR UPDATE`, et insère atomiquement session/événement. Elle retourne uniquement
l'UUID de session effectif. Une session n'est réutilisée que pour le même
visiteur, avant expiration d'inactivité et d'âge maximal, et si son identité est
compatible : session anonyme, ou session du même utilisateur authentifié. Une
session anonyme peut être enrichie au login. Une session identifiée A n'est
jamais réutilisée après logout ni sous B; la fonction crée ou sélectionne une
session compatible sans désidentifier ni muter l'ancienne session. Cette règle
s'applique aux recherches par session demandée et par fallback visiteur.
`last_seen_at` ne recule pas; `page_views` augmente seulement pour `page_view`.

#### Consentement et identité

L'ingestion est désactivée par défaut et fail-closed. Les routes web/CSRF
same-origin sont `GET|POST|DELETE /analytics/consent` et
`POST /analytics/events`. Le consentement est explicite et versionné. Sans
consentement courant `granted`, aucune identité analytique ni écriture n'est
créée. Refus/révocation expirent immédiatement les cookies analytiques sans
toucher à l'identité Commerce.

Les cookies `dt_analytics_consent`, `dt_analytics_visitor` et
`dt_analytics_session` sont first-party, chiffrés/signés par Laravel, HttpOnly,
SameSite Strict et Secure hors local/testing. Le visiteur analytique est un UUID
dédié; le cookie de session ne contient que l'UUID de session.

#### Contrat public et confidentialité

Une requête transporte un seul événement JSON borné :

- `page_view` sans entité et avec propriétés vides;
- `product_view` pour un produit existant, avec `placement` dans
  `catalog|search|recommendation|direct`.

Tout autre événement est refusé, notamment achat, paiement, remboursement,
téléchargement et revenu. Le navigateur ne fournit jamais `user_id`,
`visitor_id`, session effective, timestamp, IP, appareil, montant, devise,
commande ou paiement. Le serveur normalise le chemin relatif sans query ni
fragment, le hostname referrer, les UTM lowercase et une classe d'appareil
grossière. L'IP est uniquement un HMAC-SHA-256 versionné; IP et user-agent bruts
ne sont jamais persistés.

`AnalyticsConfig` borne activation, version de consentement, TTL, âge maximal,
limite par minute, taille des propriétés et clé/version HMAC. Le limiter utilise
un HMAC IP et un digest visiteur, jamais les valeurs brutes. Toute panne interne
répond `204` sans détail SQL et reste indépendante des transactions Commerce.
Les événements sont at-least-once, non financiers et non autoritatifs.

Validation : P5-A1 **74 tests / 400 assertions**, suite complète **716 / 5183**,
Pint **254**, rollback isolé et concurrence PostgreSQL réelle verts. Les
scénarios HTTP, les appels directs sous `digitrove_runtime` et les connexions
concurrentes couvrent logout, changement de compte, upgrade anonyme et même
compte.

### P5-A2 — Rollups autoritatifs et partitions sûres (D-039)

**État** : implémenté, en attente de revue/merge. Migration unique :
`2026_07_14_000018_create_analytics_operations_authority.php`; total **34
migrations**, aucune `000019`.

#### Correction dimensionnelle et identité achetée

`daily_product_engagement_stats(day, product_id)` contient uniquement `views`,
`add_to_carts` et `updated_at`, sans devise, FK ou dépendance au catalogue.
`views` compte les événements `product_view`; `add_to_carts` vaut zéro tant que
cet événement n'est pas autorisé. `daily_product_stats(day, product_id,
currency)` contient uniquement `purchases`, `revenue_minor` et `updated_at`.
Aucune vue n'est dupliquée par devise et aucune devise sentinelle n'existe.

`order_items.purchased_product_id BIGINT NOT NULL` est positif, sans FK et
immuable après insertion. Il est alimenté par le checkout serveur, jamais par
le client, et reste présent si la FK catalogue `product_id` devient NULL. Une
ligne bundle conserve l'identité du bundle acheté; ses composants ne reçoivent
pas le revenu commercial principal. Le rollup n'effectue aucun join catalogue.

#### Formules autoritatives

- ventes : commandes aux statuts payés groupées par jour UTC de `paid_at` et
  devise; remboursements réussis groupés par leur jour UTC `succeeded_at`;
- produit commercial : somme des quantités et totaux snapshots de ligne,
  groupée par `purchased_product_id` et devise de commande;
- engagement : nombre de `product_view` par produit et jour UTC, sans devise;
- funnel : visiteurs, sessions, vues, checkouts, achats et nouveaux clients
  depuis leurs tables autoritatives.

Le recalcul supprime puis réinsère/upsert les quatre projections dans une
transaction `REPEATABLE READ`, sous advisory lock par date. Il est atomique,
idempotent, déterministe et ne modifie aucune table Commerce.

#### Autorité, partitions et ACL

`digitrove_analytics_worker` est un rôle LOGIN dédié : aucun DML/SELECT direct
sur tables ou séquences, seulement EXECUTE sur :

- `refresh_authoritative_daily_analytics(date)`;
- `ensure_analytics_events_month_partition(date)`;
- `audit_analytics_event_partitions()`.

La première fonction SECURITY DEFINER appartient au rôle NOLOGIN
`digitrove_analytics_rollup_executor`, dont les droits sont bornés aux sources
en lecture et projections en écriture. Toutes les fonctions épinglent UTC et
`search_path`; la connexion Laravel dédiée est `pgsql_analytics_worker`.

Le provisionnement mensuel accepte seulement le premier jour d'un mois dans
une fenêtre de ±60 mois, sérialise par advisory lock, vérifie parent et bornes,
refuse une plage déjà occupée dans `events_default`, puis applique les
révocations ACL. Il ne déplace, ne détache et ne supprime aucune ligne ou
partition. L'audit retourne uniquement noms, bornes et volume DEFAULT.

Commandes : `analytics:rollup`, `analytics:partitions:ensure` et
`analytics:partitions:audit`. Le scheduler est désactivé par défaut, borné,
`withoutOverlapping` et `onOneServer`.

Le rollback isolé retire les autorités P5-A2 et la table d'engagement, restaure
les colonnes P5-A0, retire le snapshot ajouté par `000018`, et préserve P5-A0,
P5-A1, les événements, les partitions existantes, Commerce et les rôles
globaux. Validation post-merge : P5-A2 **25/198**, P5-A1 **74/400**, P5-A0
**19/256**, suite complète **741/5381**, Pint **278**, concurrence et rollback
PostgreSQL verts.

### P5-A3A/B — Admin Analytics Read Boundary, Overview et Ventes (D-040)

**État** : terminé, mergé et validé via PR #29, head `31f986dc`, merge
`2bbf2b52`, CI #36 success. La migration `000019` porte le total à **35
migrations** sans ajouter de table métier ni d'index.

- Le schéma et les rollups sont **globaux**. Ni `products`, ni `orders`, ni les
  quatre projections ne portent vendeur, owner ou tenant. Un dashboard vendeur
  exige d'abord un contrat d'ownership Commerce et des dimensions analytiques;
  aucune agrégation existante ne permet de le reconstruire honnêtement.
- Le panel Filament unique `admin` est accessible uniquement à un admin actif et
  non supprimé via `FilamentUser::canAccessPanel()`. La Gate indépendante
  `viewGlobalAnalytics` applique les mêmes invariants. Staff, customer,
  suspended, blocked, soft-deleted et tout autre panel sont refusés.
- Les ACL empêchent `digitrove_runtime`, `digitrove_analytics_worker` et
  `PUBLIC` de lire les rollups. Le worker P5-A2 reste strictement EXECUTE-only.
  La migration `000019` accorde au reader LOGIN restreint
  `digitrove_analytics_reader` uniquement `USAGE` sur `public` et `SELECT` sur
  `daily_sales_stats`, `daily_product_stats`,
  `daily_product_engagement_stats` et `daily_funnel_stats`, sans accès à
  `events`, `analytics_sessions`, Commerce ou aux fonctions d'opération.
- Les seules structures d'accès sont les PK B-tree `(day, currency)`, `(day,
  product_id, currency)`, `(day, product_id)` et `(day)`. Elles suffisent pour
  des fenêtres UTC bornées. Les classements produit font un scan et tri de la
  plage demandée; aucun index ne sera ajouté sans `EXPLAIN` et volume mesuré.
- Les montants restent séparés par devise. Les compteurs de commandes/achats
  peuvent être additionnés, mais jamais les revenus, remises, taxes, refunds ou
  moyennes entre devises. Une journée absente signifie « non calculée », pas
  zéro. Le jour courant n'est pas produit par le scheduler quotidien et doit
  être marqué provisoire/indisponible. Un net négatif reste signé. La métrique
  `add_to_carts` reste indisponible tant que son événement n'est pas autorisé.

**Read models implémentés dans P5-A3A/B** :

1. `AnalyticsOverviewQuery` : plage UTC validée, devise explicite pour l'argent,
   DTO par devise + funnel, fraîcheur `updated_at`, états disabled/missing.
2. `AnalyticsSalesQuery` : série quotidienne currency-safe, plage bornée, ordre
   stable, jamais de moyenne de moyennes.
3. `AnalyticsProductQuery` et `AnalyticsFunnelQuery` sont réservés à P5-A3C et
   ne sont pas créés dans ce gate.

Les deux queries utilisent la connexion `pgsql_analytics_reader`, vérifient
`session_user` et `current_user`, refusent toute transaction ambiante puis
ouvrent une transaction read-only. Elles retournent des DTO immuables et
utilisent un cache Laravel court dont la clé inclut `analytics:v1`, rôle admin,
scope global, UTC, query, plage, devise, page et taille. Fenêtre : 30 jours par
défaut, maximum 366; tableaux limités à 100 lignes par page.

**Écrans implémentés** : Vue d'ensemble et Ventes. Les montants sont séparés par
devise, les trous restent « non calculés », le jour UTC courant présent est
provisoire, le net négatif reste signé et `add_to_carts` affiche « Non suivi ».
Aucun écran ne déclenche rollup, partition, backfill ou activation. Aucun
identifiant visiteur/utilisateur/session, `properties`, `ip_hash`, webhook,
secret, API ou export n'est exposé.

**Décisions humaines appliquées** : admin actif uniquement, staff refusé,
portée globale uniquement et widgets analytiques dans P5-A3. P6 reste CRM et
marketing. Validation : P5-A3 **32/193**, suite complète **773/5575**, Pint
**302**, rollback ACL isolé vert. À cette clôture historique, P5-A3C produits et
tunnel constituait la tâche suivante et P5-A3D/P6/P7 n'étaient pas commencés.

### P5-A3C — Produits et Tunnel (D-041)

**État** : implémenté sur `p5-a3c-product-funnel-analytics`, en attente de
revue/merge. Ce gate ne crée aucune migration, table, fonction, trigger, ACL ou
index : la frontière reste `000019` et le total reste **35 migrations**.

`AnalyticsProductQuery` agrège d'abord séparément les sources autorisées :

- `daily_product_engagement_stats(day, product_id)` fournit les vues globales,
  sans devise;
- `daily_product_stats(day, product_id, currency)` fournit achats et revenu
  uniquement dans la devise sélectionnée;
- `daily_funnel_stats(day)` fournit la couverture calendaire globale.

La réunion conserve un produit présent dans une seule source. Aucun join avec
`products` n'est autorisé par le reader : l'identité affichée est donc
`Produit #<id>`, sans nom ou slug inventé. L'ordre stable peut être revenu,
achats, vues ou ID; la pagination vaut 30 par défaut et 100 maximum. Le revenu
moyen par achat est un entier en unités mineures et vaut `NULL` sans achat.
`add_to_carts` reste « Non suivi ». Vues et achats ne forment pas une cohorte :
aucun taux achats/vues n'est exposé comme conversion.

`AnalyticsFunnelQuery` produit une série complète depuis
`daily_funnel_stats`. Un jour absent reste `NULL`, un zéro présent reste zéro,
et le jour UTC courant présent est provisoire. Les ratios sur les totaux de la
plage sont PostgreSQL `numeric` rendus en chaînes à six décimales, sans plafond;
un dénominateur nul donne `NULL`. L'UI les nomme « Ratios agrégés — non
cohortés » et trace Sessions, Vues, Checkouts et Achats sans relier les trous.

Les deux queries réutilisent `pgsql_analytics_reader`, l'identité vérifiée, la
transaction read-only et les timeouts de D-040. Les clés de cache bornées
restent sous `analytics:v1`; les valeurs sont des tableaux scalaires réhydratés
en DTO immuables afin de fonctionner avec Redis lorsque la désérialisation
d'objets est désactivée. L'accès UI reste réservé à l'admin actif global.
Aucune donnée brute, Commerce ou personnelle, API, export, commande analytique,
vendeur, tenant ou ownership n'est ajouté.

Validation : P5-A3C **18/123**; P5-A3 agrégé **50/316** (32/193 historique +
18/123); suite complète **791/5698**; Pint **318**; PostgreSQL 16 et Redis réels;
**35 migrations** appliquées; `git diff --check` propre. P5-A3D reste optionnel
et non commencé; P6/P7 restent non commencés.

---

## 🔗 LES RELATIONS EN UN COUP D'ŒIL

```
visitors ──(login)──> users ──1:1──> customer_profiles
   │                    │
   │                    ├──1:N──> orders ──1:N──> order_items ──1:N──> download_grants
   │                    │            │                 │                     │
   │                    │            └──1:N──> payments│                     └──1:N──> download_logs
   │                    │                              │
   │                    └──N:M──> customer_segments [P6] └──> products ──1:N──> product_prices
   │                                                          │
   │                                                          ├──1:N──> product_files
   │                                                          │
   └──1:N──> analytics_sessions ──1:N──> events               └──N:M──> categories
                                            ↑                 └──N:M──> product_bundles (self)
                    campaigns [P6] ──(utm_campaign)──┘
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
5. `licenses` : EXCLU de P4 (D-029) — option produit non décidée, à trancher par
   KingKouda avant toute phase licences dédiée.

1. `users` + `customer_profiles` + `visitors` (fondation identité) ✅
2. `categories` + `products` + `product_prices` + `product_files` + pivots catalogue ✅
3. Commerce P3 (bloc ci-dessus) — schéma complet mergé jusqu'à P3C-C (PR #10) ✅
4. P4 en gates isolés, tous mergés dans l'ordre (D-029.2 + P4-A2.1 + D-029.6) :
   P4-A0 durcissement `product_files` (`000008`, PR #11) → P4-A1 snapshot
   `order_item_bundle_components` (`000009`, PR #12) → P4-A2 `download_grants`
   (`000010`, PR #13) → P4-A2.1 hardening G2/G3 (`000011`, PR #14) → P4-B0
   frontière de privilèges runtime (`000012`, PR #15) → P4-B `download_logs`
   (`000013`, PR #16 → `98441014`) ✅ **SCHÉMA P4 COMPLET**
5. **Couche applicative Commerce → Livraison (D-030)** — voir le bloc dédié
   ci-dessous. Aucune migration : `P3-D1` → `P3-D5` puis `P4-C0` → `P4-C6`.
6. `events` partitionnée + rollups (analytique)
7. `campaigns` + `customer_segments` (marketing)
8. Affiliation dédiée (`affiliate_profiles`, `affiliate_links`, `referrals`,
   `affiliate_commissions`, `affiliate_payouts`) après validation produit ultérieure

Ne code aucune logique métier avant que 1→4 soient migrés et testés.
**1→4 sont migrés et testés** (29 migrations, suite 190/2975, Pint 121) : la
couche applicative peut commencer, gate par gate, selon D-030.

---

## 🧩 COUCHE APPLICATIVE COMMERCE → LIVRAISON (D-030)

> Le schéma relationnel P1→P4 est **complet**. Ce bloc ne décrit **aucune
> migration** : il fige l'ordre des gates applicatifs et les invariants BDD que
> chacun doit respecter. Décisions humaines figées : **Q1 = A renforcée**
> (token jamais reconstructible), **Q2 = B renforcée** (job queued portant
> `order_id` seul), **Q3 = A** (refus explicite + allocation Hamilton).
> Nommage : la tarification, le checkout et le paiement relèvent de **P3
> Commerce** ; **P4 Livraison** ne commence qu'à l'émission des grants.

### Graphe des gates

```text
P3-D1 Pricing & Quote Kernel
    ↓
P3-D2 Checkout Order Transaction
    ↓
P3-D3 Payment Initiation
    ↓
P3-D4 Server-side Payment Confirmation
    ↓
P3-D5 OrderPaid Domain Event
    ↓
P4-C0 Queue & Mail Secret Safety
    ↓
P4-C1 Download Grant Issuance
    ├──────────────┐
    ↓              ↓
P4-C2 Refund       P4-C3 Secure Secret Delivery Job
Grant Revocation        ↓
                   P4-C4 Download Authorization
                        ↓
                   P4-C5 HTTP File Delivery
                        ↓
                   P4-C6 Delivery Operations
```

* **P4-C2 doit être mergé avant l'activation réelle de P4-C3.**
* **P4-C1 peut être implémenté comme service non câblé** tant que P4-C2/P4-C3 ne
  sont pas prêts.
* **Aucun téléchargement public n'existe avant P4-C5.**
* **P5 ne commence pas** pendant cette roadmap.

### Table des gates

| Gate | Branche future | Migration | Invariants BDD mobilisés |
|---|---|:--:|---|
| **P3-D1** Pricing & Quote Kernel ✅ *mergé (PR #17 → `78f475e7`)* | `p3-d1-pricing-kernel` | non | *aucune écriture* — prépare `orders_total_formula_check`, `order_items_line_*_formula_check`, `validate_order_items_consistency` |
| **P3-D1.1** Hardening post-merge ✅ *mergé (PR #18 → `0e18d69d`)* | `p3-d1-post-merge-hardening` | non | invariants DTO en miroir des CHECK `orders`/`order_items` ; allowlist fail-closed du garde-fou P4-B |
| **P3-D2** Checkout Order Transaction ✅ *mergé (PR #19 → `4c691864`, D-031 + D-032)* | `p3-d2-checkout-order-transaction` | non | `orders_checkout_idempotency_hash_unique`, **`orders_cart_id_unique`**, `orders_coupon_snapshot_consistency_check`, `validate_order_items_consistency` (différé), `order_items_order_id_product_id_unique`, S1/S2/S3 |
| **P3-D3** Payment Initiation ✅ *mergé (PR #22 → `70379a02`, D-033)* | `p3-d3-payment-initiation` | non | `payments_idempotency_key_hash_unique`, `payments_order_id_attempt_number_unique`, transitions T (D-028.5) |
| **P3-D4 + P3-D5** Server-side Confirmation + OrderPaid ✅ *mergé (PR #23 → `a62563fd`, D-034)* | `p3-d4-d5-payment-confirmation` | non | uniques de rejeu `payment_webhook_events` (`provider_external_event_unique`, `provider_payload_hash_unique`), ladder `payments` `pending→processing→succeeded`, `payments_one_succeeded_per_order` / `_one_requires_review_per_order`, `payments_provider_reference_unique`, `coupon_redemptions_order_id_unique`, constraint triggers `validate_payment_order_consistency` / `validate_coupon_redemption_consistency` (différés), free order `total_minor = 0` sans `payments`. **Aucune table nouvelle** ; usage applicatif des tables P3C existantes. `OrderPaid` = événement `afterCommit` portant `order_id` seul. |
| **P4-C0→C3** Secure Delivery Pipeline ✅ *terminé, mergé et validé (PR #24 → `701cfa4f`, D-035)* | `p4-c0-c3-secure-delivery-pipeline` | non | Job unique `order_id` seul ; tokens CSPRNG mémoire + SHA-256 persistant ; Mailable synchrone non sérialisable, transport `log` refusé ; **G3** (`orders FOR UPDATE`, statut livrable, fichier actif, lignée snapshot bundle, bénéficiaire null-safe), `download_grants_active_pair_unique`, `download_grants_token_hash_unique`, no-upgrade au retry ; **G4** différé bidirectionnel (`download_grants` + `orders`) pour la révocation totale, ladder refunds `pending→processing→succeeded`, cap cumulé refunds ≤ paiement, `refunds_validate_order_consistency` différé. Lien provisoire fragment-only, aucune route. **Aucune table nouvelle** ; usage applicatif. |
| **P4-C4** Download Authorization ✅ *terminé, mergé et validé (PR #25 → `109fde4c`, D-036)* | `p4-c4-c6-download-delivery-operations` | non | Page d'échange DB-free, fragment retiré, Bearer POST seulement, secret de tentative dédié (SHA-256 seul), cookie HttpOnly, refus uniforme ; **G5** (`SECURITY DEFINER`, unique mutante), **G2** (`current_user = digitrove_download_executor`), unique partiel `attempt_token_hash` |
| **P4-C5** HTTP File Delivery ✅ *terminé, mergé et validé (PR #25 → `109fde4c`, D-036)* | `p4-c4-c6-download-delivery-operations` | non | GET/HEAD/Range sur la même tentative ; HEAD inerte ; stream privé borné, X-Accel local opt-in fail-closed ; `storage_path` jamais exposé, aucune URL objet |
| **P4-C6** Delivery Operations ✅ *terminé, mergé et validé (PR #25 → `109fde4c`, D-036)* | `p4-c4-c6-download-delivery-operations` | non | Réconciliation sans restitution de quota, abus par HMAC IP, révocation support set-once, métriques ; **G6** (terminal + rétention échue), G1 prevent-delete des grants |

### Contrat applicatif final de livraison (D-036)

```text
E-mail : /downloads/{grantPublicId}#token=<grant-secret>
  -> page uniforme sans BDD ni ressource externe
  -> history.replaceState() retire le fragment
  -> POST /api/downloads/{grantPublicId}/authorize
       Authorization: Bearer <grant-secret>
  -> G5 : download_log started + downloads_count +1 atomiques
  -> cookie dl_attempt : secret distinct, HttpOnly, SameSite=Strict, TTL court
  -> GET|HEAD /downloads/{grantPublicId}/file
       même attempt, même log, aucun quota supplémentaire
```

* Le grant secret n'apparaît jamais en query string, cookie, HTML serveur, log,
  exception ou stockage navigateur. Seul `download_grants.token_hash` existe en
  base.
* Le secret de tentative est un CSPRNG distinct ; seul
  `download_logs.attempt_token_hash` est persisté. Grant invalide, inconnu,
  expiré, révoqué ou épuisé expose la même réponse.
* Les verrous applicatifs suivent **Order → DownloadGrant → DownloadLog**. GET,
  Range et retries ne créent aucune ligne et ne modifient jamais le quota. HEAD
  ne change ni statut ni compteur.
* Un Range `bytes` unique (`start-end`, `start-`, `-suffix`) est supporté ; tout
  multi-range, overflow, syntaxe ambiguë ou plage hors fichier est refusé en
  `416`.
* Le mode par défaut ouvre `readStream()` sur un disque allowlisté privé, lit par
  chunks bornés et ferme en `finally`. X-Accel n'est possible que pour un disque
  local privé et une configuration interne valide. Aucun `Storage::url()` ou
  `temporaryUrl()` n'est généré.
* `completed` signifie remise au mécanisme, pas réception intégrale. Un
  `started` ancien devient `denied/delivery_interrupted` sans restituer le quota.
  La purge passe exclusivement par G6 et ne concerne que les logs terminaux dont
  la rétention est échue.
* Détection d'abus : `COUNT(DISTINCT ip_hash)` sur fenêtre bornée, jamais IP
  brute. La révocation support est explicite, allowlistée, set-once et non
  automatique.
* Les preuves C1→C6 utilisent des processus PostgreSQL runtime indépendants :
  double autorisation/dernière unité, autorisation contre révocation, deux Range
  sur un attempt, purge contre lecture et double réconciliation restent
  sérialisés et idempotents.
* **Aucune migration `000014`** : les 29 migrations existantes suffisent.
  Validation finale : P4-C4 **18/152**, P4-C5 **13/202**, P4-C6 **5/41**,
  P4C456 **10/72**, suite **623/4542**, Pint **217**. PR #25 mergée au
  `109fde4c`, CI #31 success.

### Premier gate — `P3-D1 — Pricing & Quote Kernel`

Sortie contractuelle :

```text
PricedQuote
- currency          (VARCHAR(3) majuscule)
- subtotalMinor     (BIGINT, unités mineures)
- discountMinor
- taxMinor
- totalMinor
- lines[]
- couponSnapshot|null
```

Chaque ligne porte `product_id`, `product_name_snapshot`, `product_slug_snapshot`,
`product_type_snapshot`, `unit_price_minor`, `quantity`, `line_subtotal_minor`,
`line_discount_minor`, `line_total_minor`.

**Pourquoi la tarification précède le checkout (mesuré sur le code réel)** :
`cart_items` ne porte **aucun prix** (`id, cart_id, product_id, quantity,
timestamps`) ; le prix se résout depuis `product_prices` par devise. Or le
constraint trigger différé `validate_order_items_consistency` (migration
`000002`) exige au COMMIT `SUM(line_subtotal_minor) = orders.subtotal_minor`,
**`SUM(line_discount_minor) = orders.discount_minor`** et `SUM(line_total_minor)
+ orders.tax_minor = orders.total_minor` ; et `orders_coupon_snapshot_consistency_check`
exige `discount_minor > 0` dès qu'un snapshot coupon existe. Une remise de coupon
**doit** donc être répartie sur les lignes, en entiers, avec un reste géré — sinon
le COMMIT échoue en `23514`. L'allocation retenue est **Hamilton (plus grand
reste)**, départage `résidu décroissant → product_id croissant → identifiant de
ligne croissant`, sans aucun `float`, division flottante ni `round()`.

### Contrat d'implémentation P3-D1 (mergé, durci par P3-D1.1 mergé)

> **`P3-D1` ET `P3-D1.1` SONT TERMINÉS ET MERGÉS** : PR #17 → `78f475e7`
> (CI #18 verte), puis PR #18 → `0e18d69d` (CI #19 verte). Le merge de la PR #17
> ayant précédé la revue contradictoire, un audit post-merge a démontré quatre
> défauts de **contrat défensif** — le calcul de prix, lui, était correct et
> aucune corruption monétaire n'était possible :
> **A1** `Money` acceptait `"XOF\n"` (le `$` de PCRE matche avant un saut de ligne
> final) ; **A2** le garde-fou P4-B laissait passer un service de livraison sous
> un namespace neutre (`Services/Fulfilment/GrantIssuer.php`) ; **A3** les DTO de
> pricing n'appliquaient aucun de leurs invariants ; **A4** un `line_id` dupliqué
> écrasait silencieusement une allocation. **`P3-D1.1` a fermé les quatre** :
> ancres `\A…\z` + `Money::assertValidCurrency()` comme source unique, invariants
> en miroir des CHECK dans les constructeurs, allowlist **fail-closed** des
> 7 fichiers autorisés sous `app/Services`, refus des identifiants de ligne
> dupliqués. Aucune migration, aucune politique métier modifiée.
>
> ⚠️ **L'allowlist `P4B_ALLOWED_SERVICE_FILES` est une frontière historique** :
> chaque gate applicatif futur qui ajoute un fichier sous `app/Services` doit
> l'élargir **explicitement**, sinon le garde-fou P4-B échoue — c'est voulu.

Neuf classes, **aucune migration** : `App\Support\IntegerMath` (multiply / add /
subtract avec `OverflowException` au dépassement — PHP promeut silencieusement un
entier débordant en float), `App\Support\Money` (`int` + devise `^[A-Z]{3}$`,
jamais de conversion ni de repli), et `App\Services\Pricing\{PricingService,
DiscountAllocator, PricedQuote, PricedLine, CouponSnapshot, PricingException,
PricingRefusalReason}`. Points de contrat retenus, tous couverts par test :

* **Prix** : `product_prices` sur `(product_id, currency)` avec
  `is_active = true` uniquement. Produit **fail-closed** — `status = published`
  exigé ; `draft`, `archived` et soft-deleted refusés. Lecture stricte de
  l'enum `ProductStatus`, conservatrice, à reconfirmer au gate P3-D2.
* **Fenêtre coupon** : `starts_at <= at <= ends_at`, **inclusive aux deux
  bornes**, avec un instant de référence unique et immuable par tarification
  (jamais deux `now()` susceptibles d'encadrer une frontière).
* **`min_order_minor`** : plancher mesuré sur le **sous-total du panier entier**
  (c'est un plancher de *commande*) ; la **base de remise** reste le sous-total
  **éligible**.
* **Portée** : aucun pivot ⇒ coupon global ; sinon **UNION** produit ∪ catégorie,
  jamais une intersection. Un produit rattaché à plusieurs catégories éligibles
  n'est compté qu'une seule fois.
* **Plafonds** : `max_discount_minor`, puis sous-total éligible ; une remise
  résultante nulle est **refusée** —
  `orders_coupon_snapshot_consistency_check` interdit un snapshot coupon avec
  `discount_minor = 0`.
* **`taxMinor = 0`**, explicitement : aucune politique fiscale, aucun
  `TaxService`, aucune configuration fiscale, aucune conformité revendiquée.
  Toute fiscalité future exigera sa propre décision et son propre gate.
* **Zéro écriture, zéro verrou** : `coupon_redemptions` et
  `coupons.redemptions_count` restent P3-D4 (D-027, point 5) ; les verrous
  `FOR UPDATE` restent P3-D2/P3-D4.

### Contrat transactionnel P3-D2 (D-031, implémenté)

**Ordre de verrouillage** : `carts` (`public_id`, `FOR UPDATE`) → `products` du
panier par `id` croissant (`withTrashed`) → par bundle croissant :
`product_bundles` puis **produits enfants par `id` croissant**. `product_prices`
n'est pas verrouillé (lecture unique de `PricingService` dans la transaction).
Le verrou des **enfants** rend Q1=C applicable : un soft-delete concurrent est
bloqué (`55P03`, prouvé sur deux connexions PDO réelles).

**Q1 = C** — un composant de bundle soft-deleted fait **échouer** le checkout
(`BundleComponentUnavailable`) : S3 ne lit pas `deleted_at` et un snapshot
partiel est structurellement indétectable (D-029.3/5). **Q2 = B** — le Cart
passe à `converted` dans la même transaction, jamais sur rejeu.

**Idempotence** : digest SHA-256 seul en base ; rejeu résolu après le verrou du
Cart mais **avant** toute règle d'état ; égalité prouvée par comparaison de
`cart_id`, acteur, `currency`, `coupon_id`, `customer_email` (le schéma ne
stocke aucun fingerprint). `orders_cart_id_unique` est le **backstop** derrière
`CartAlreadyCheckedOut` ; chaque `23505` est traduit par contrainte, jamais
globalement en « rejeu ».

**Order gratuite** : reste `pending`, aucune ligne `payments`. **Coupon** :
snapshot copié, **aucune** consommation (P3-D4).

**Expiration (D-032)** : `orders.expires_at = placed_at + config('checkout.
pending_ttl_minutes')`. Défaut **30 min**, surchargeable par
`CHECKOUT_PENDING_TTL_MINUTES` **sans toucher au code** ; minutes entières,
minimum 1, plafond 525 600. Une valeur invalide échoue **avant toute écriture**
(`IntegrityFailure`, incident serveur). Le rejeu idempotent **conserve**
l'`expires_at` d'origine. La colonne restant `NOT NULL` sans DEFAULT, aucune
politique commerciale n'entre en base.

**Retry `order_number` (D-032)** : un `23505` place toute la transaction
PostgreSQL en état avorté — un retry nu ne peut recevoir que **`25P02`**
(mesuré). L'INSERT susceptible de collision est donc enveloppé dans une
**transaction Laravel imbriquée** = un vrai `SAVEPOINT` ; le `ROLLBACK TO
SAVEPOINT` préserve le verrou du Cart. 3 essais maximum, puis
`IntegrityFailure`. Seule `orders_order_number_unique` est retentée.

### Points de vigilance figés par D-030

* **Coupon** : les snapshots vont sur `orders` à la création ; `coupon_redemptions`
  et `coupons.redemptions_count` n'existent qu'à la confirmation de paiement (ou au
  passage légitime d'une commande gratuite à `paid`), dans la même transaction,
  sous verrou du coupon. Aucun trigger ne maintient `redemptions_count` : le
  plafond global et le plafond client sont **100 % applicatifs**.
* **Token** : CSPRNG, mémoire vive seulement, SHA-256 en base, jamais
  reconstructible. Reprise = **révoquer puis réémettre** (l'unique partiel actif
  l'impose physiquement), jamais « réessayer avec l'ancien token ». Sémantique
  **at-least-once** assumée pour l'e-mail.
* **Queue** : le job de livraison transporte **`order_id` seul**. Aucun token,
  hash, lien, `storage_path`, IP ou clé HMAC ne traverse la queue. L'e-mail est
  composé et envoyé **synchroniquement dans le worker**.
* **Révocation** : G4 étant différé et monté sur `orders`, un remboursement total
  devient **impossible** si les grants actifs ne sont pas révoqués dans la même
  transaction. D'où P4-C2 avant l'activation de P4-C3.
* **`SECURITE_TELECHARGEMENT.md` a été réécrit par P4-C4/C6** : fragment e-mail,
  Bearer POST, cookie de tentative, G5, Range/HEAD, stockage privé,
  réconciliation et observabilité sans secret sont désormais alignés sur
  D-035/D-036.
