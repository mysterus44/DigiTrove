# DigiTrove

DigiTrove is an autonomous e-commerce + CRM/ERP application for digital products.

P0 installs the technical foundation only: Laravel, PostgreSQL, Redis, Filament,
Pest, Pint, CI, and private storage. No business table or business logic is created
at this stage.

## Stack

- Laravel 13.19, PHP 8.3+
- PostgreSQL 16
- Redis 7
- Filament 5
- Pest and Pint
- Private storage disk for digital deliverables

Laravel 11 / Filament 3 were originally planned, but Composer/Packagist blocked
Laravel 11 because of security advisories. Laravel 13.19 and Filament 5 were adopted
without bypassing Composer security checks. See `.context/memory/DECISIONS_LOG.md`
decision D-012.

## Local Setup

PHP is not currently available on this Windows host, so a development PHP image is
provided.

```bash
docker build -f docker/php/Dockerfile -t digitrove-php:dev .
docker compose up -d
docker run --rm -v "%cd%:/app" -w /app digitrove-php:dev composer install
docker run --rm -v "%cd%:/app" -w /app digitrove-php:dev php artisan key:generate
```

On a machine with PHP and Composer installed:

```bash
composer install
cp .env.example .env
php artisan key:generate
docker compose up -d
php artisan serve
```

## Verification

With local PHP:

```bash
php artisan --version
php artisan test
./vendor/bin/pint --test
```

With the Docker PHP image:

```bash
docker run --rm -v "%cd%:/app" -w /app digitrove-php:dev php artisan --version
docker run --rm -v "%cd%:/app" -w /app digitrove-php:dev php artisan test
docker run --rm -v "%cd%:/app" -w /app digitrove-php:dev ./vendor/bin/pint --test
```

## Security Notes

- `.env` is ignored and must never be committed.
- `data/*.sqlite`, `*.db`, `vendor/`, `node_modules/`, and private storage are ignored.
- Password hashing defaults to Argon2id.
- The `private` filesystem disk points to `storage/app/private` and is not publicly served.
- Legacy PHP/JSON/SQLite files are retained only as migration source material.

## Next Phase

P1 is Identity: `users`, `customer_profiles`, and `visitors`, with migrations and tests
before any business logic.
