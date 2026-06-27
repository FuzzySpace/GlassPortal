# Phase 22 — GlassSite Lite CMS / Public Product Catalog

## Purpose

GlassSite is a lightweight, public-facing catalog layer for the
Glasshouse/GlassHosting ecosystem. It lets owners/admins publish marketing
"product cards" that point visitors to signup, portal login, docs, support,
status, or module-launch paths — backed by a small, safe-by-design catalog
table.

## What GlassSite **is**

- A curated, intentionally-published public product/service/module catalog.
- An admin-managed set of marketing cards rendered at `/products`.
- A thin front door that links out to signup / docs / support / status / CTAs.

## What GlassSite **is not**

- Not a general-purpose CMS or WordPress clone.
- Not a source of truth for billing, provisioning, or inventory.
- Not a surface for customer data, tenant IDs, NetBox/IPAM data, agent tools,
  API/billing tokens, signing secrets, or infrastructure inventory.

**Core rule:** only the explicit, allow-listed columns are ever rendered
publicly. The admin-authored `metadata` JSON is **never** shown on public pages.

---

## Data Model

Table: `public_product_catalog_entries` (migration
`2026_06_27_000002_create_public_product_catalog_entries_table.php`).
Model: `app/Models/PublicProductCatalogEntry.php` (soft-deleted).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `title` | string | required |
| `slug` | string unique | normalized via `Str::slug` on save; public URL key |
| `short_description` | string nullable | |
| `description` | text nullable | |
| `category` | string nullable | display label |
| `product_key` | string(64) nullable, indexed | optional cross-ref |
| `module_key` | string(64) nullable, indexed | optional cross-ref to module registry |
| `starting_price_cents` | unsigned int nullable | display-only marketing price |
| `currency` | string(3) default `USD` | |
| `billing_interval` | string(32) nullable | `monthly` / `yearly` / `one_time` |
| `cta_label`, `cta_url` | string nullable | public call-to-action |
| `docs_url`, `support_url`, `status_url` | string nullable | helpful links |
| `icon` | string(16) nullable | |
| `featured` | boolean default false | |
| `is_public` | boolean default false | publication flag |
| `sort_order` | integer default 0 | |
| `published_at` | timestamp nullable | publication time |
| `metadata` | json nullable | **admin-only, never rendered publicly** |
| timestamps + `deleted_at` | | soft deletes |

The migration is cross-database safe (no `after()`, plain column types) and runs
under the sqlite test/CI path.

### Model API

- **Scopes:** `published()` (`is_public = true` AND `published_at <= now()`),
  `ordered()` (featured first, then `sort_order`, then title), `featured()`.
- **Slug normalization** on `saving` (derives from title when blank; normalizes
  a provided slug). Currency defaults to `USD`.
- **`toPublicArray()`** — allow-listed safe fields only (`PUBLIC_FIELDS` +
  `price_label`); **excludes `metadata`, `is_public`, ids, timestamps**.
- **`priceLabel()`** — e.g. `from $49.00/mo` (display only).
- **`featuredForHomepage()`** — featured published entries; fails safe to an
  empty collection if the table isn't migrated yet.

---

## Admin Workflow

Route area: **`admin/site/catalog`** — **owner/admin only** (the surrounding
staff group is narrowed by a stacked `role:owner,admin` middleware, so
staff/support get a 403). Controller: `App\Http\Controllers\Admin\Site\CatalogController`.

| Action | Route name | Method |
|---|---|---|
| List | `admin.site.catalog.index` | GET `admin/site/catalog` |
| Create form | `admin.site.catalog.create` | GET `admin/site/catalog/create` |
| Store | `admin.site.catalog.store` | POST `admin/site/catalog` |
| Edit form | `admin.site.catalog.edit` | GET `admin/site/catalog/{entry}/edit` |
| Update | `admin.site.catalog.update` | PATCH `admin/site/catalog/{entry}` |
| Publish/unpublish | `admin.site.catalog.publish` | POST `admin/site/catalog/{entry}/publish` |
| Feature toggle | `admin.site.catalog.feature` | POST `admin/site/catalog/{entry}/feature` |
| Delete (soft) | `admin.site.catalog.destroy` | DELETE `admin/site/catalog/{entry}` |

Admins can list, create, edit, publish/unpublish, mark featured, set sort order,
and soft-delete. A "Site Catalog" sidebar link appears for owner/admin only. The
admin forms warn against entering secrets in any field, including metadata.

---

## Public Routes

Unauthenticated. Controller: `App\Http\Controllers\GlassSite\PublicCatalogController`.

| URL | Route name | Shows |
|---|---|---|
| `GET /products` | `public.products.index` | All **published** entries (ordered) |
| `GET /products/{slug}` | `public.products.show` | One published entry; **404** for unpublished/private/future/missing |

The homepage (`/`) renders a "Featured Products" section from
`featuredForHomepage()` when featured published entries exist, plus a "Products"
nav link. If none exist, the section is omitted (no overbuild).

Public detail shows: title, category, short description, description, price
label, CTA button (if `cta_url`), and docs/support/status links (if set).

---

## Security Boundaries

1. **Allow-list rendering** — public views read only safe columns; `metadata` is
   never rendered. Even if an admin pastes a secret into metadata, it cannot
   reach a public page.
2. **Published-scope everywhere** — the public controller applies `published()`
   to every query, so unpublished/private/future entries 404 (no existence
   leak).
3. **No internal data** — the table holds only marketing fields; no customer
   data, tenant IDs, provisioning internals, IPAM/NetBox data, tokens, secrets,
   or inventory.
4. **RBAC** — management is owner/admin only; customers/staff/guests are blocked.
5. **Soft deletes** — removing an entry takes it off the public site while
   preserving the record.

---

## Healthcheck

`php artisan glassportal:healthcheck` adds (section 7n):

| Check | Pass condition |
|---|---|
| `glasssite.catalog_table` | `public_product_catalog_entries` table present |
| `glasssite.public_routes` | `public.products.index` + `public.products.show` registered |
| `glasssite.admin_routes` | `admin.site.catalog.index` registered |

---

## Tests

| Suite | File | Coverage |
|---|---|---|
| Feature | `tests/Feature/GlassSitePublicCatalogTest.php` | index renders without auth; only published shown; unpublished/private/future/missing 404; price + detail links; **no sensitive metadata leak**; homepage featured; healthcheck. |
| Feature | `tests/Feature/AdminCatalogCrudTest.php` | create/update/publish/unpublish/feature/soft-delete; slug derivation; validation; owner/admin allowed; **customer/staff/guest blocked**. |
| Unit | `tests/Unit/PublicProductCatalogEntryTest.php` | `published`/`ordered`/`featured` scopes; slug + currency normalization; `priceLabel`; `toPublicArray` excludes metadata; `featuredForHomepage`. |

Run: `php artisan test` → **521 passed**.

---

## Public URL paths

- `/products` — public catalog listing
- `/products/{slug}` — public product detail
- `/` — homepage featured section (when featured published entries exist)

---

## Future Work

- Whitelisted public metadata (e.g. feature bullet lists) via an explicit
  `public_metadata` column rather than reusing `metadata`.
- Categories/landing pages, search/filtering, and richer media.
- Linking catalog entries to live module-launch flows for authenticated users.
- Seeders for a default catalog; cache for the public listing.
- Markdown rendering for descriptions (currently plain text, `white-space: pre-line`).
