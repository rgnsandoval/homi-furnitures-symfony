# Homi Furniture

An online furniture shop backend with customer and admin roles. Built with Symfony 7.4, PHP 8.4, MySQL 8, Doctrine ORM 3, Twig, AssetMapper and PHPUnit 11, all running in Docker. This phase delivers plain Twig pages; the finished design comes later.

## Ports

| Service | URL or host port |
|---|---|
| Website | http://localhost:8580 |
| phpMyAdmin | http://localhost:7171 |
| Mailpit inbox | http://localhost:7125 |
| Mailpit SMTP | 4125 |
| MySQL | 127.0.0.1:5506 |

The database user is `homi`. Development uses the `homi` database and tests use `homi_test`.

## Setup

```bash
cp .env.local.example .env.local      # then fill in the values
docker compose up -d --build
docker compose exec -T php composer install
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T -e APP_SEEDING=1 php php bin/console doctrine:fixtures:load --no-interaction
```

## Environment variables

Set these in `.env.local`, which git ignores:

| Variable | Purpose |
|---|---|
| `APP_SECRET` | Random 32-character hex string |
| `ADMIN_SEED_PASSWORD` | Password for the seeded admin account |
| `CUSTOMER_SEED_PASSWORD` | Password for the seeded customer accounts |

`APP_SEEDING=1` is passed on the fixtures command so that seeding sends no email.

## Demo accounts

Passwords are the ones you chose in `.env.local`.

| Role | Email |
|---|---|
| Admin | `admin@homi.test` |
| Customer | `customer1@homi.test`, `customer2@homi.test`, `customer3@homi.test` |

Demo vouchers are `WELCOME10` and `LESS500`. GCash and card payments are simulated, and no card details are collected.

## Commands

```bash
docker compose exec -T php php bin/phpunit                        # full test suite
docker compose exec -T php php bin/console app:stock:check        # exits 1 if any variant is low or out of stock
```

## Loading your own catalog

Place these files in `fixtures-data/` and reload the fixtures. No code changes are needed. Without `products.csv`, a built-in sample catalog is loaded. The demo orders use the first 31 products, so a smaller catalog needs matching changes to the demo data.

| File | Columns |
|---|---|
| `categories.csv` | `name, slug, description, display_order, parent_slug` |
| `brands.csv` | `name` |
| `products.csv` | `sku, name, slug, description, price, cost_price, sale_price, material, color, width_cm, depth_cm, height_cm, weight_kg, assembly_required, warranty_months, lead_time_days, category_slug, brand, featured` |
| `variants.csv` | `product_sku, sku, name, color, size, finish, price_adjustment, stock, low_stock_threshold` |

Product images are read from `assets/seed/products/<slug>.jpg`, `.png`, `.webp` or `.svg`. A product with no image gets a generated placeholder.

## How orders work

- Stock lives on product variants. Every product needs at least one variant to be sold.
- Only `OrderTransitionService` and `PaymentTransitionService` may change a status. A Doctrine subscriber rejects any other change at runtime.
- Checkout recomputes all prices from the database. A lock and a unique key ensure a double submit creates one order.
- Revenue counts delivered orders with a paid payment only.
- Payment proofs and return photos are stored outside `public/` and served only to their owner or an admin.
