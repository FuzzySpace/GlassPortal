# Phase 29 Addendum — Runtime Exposure Inventory

A **documentation + inspection** addendum to Phase 29. It records the current
public/runtime mapping so the pilot is run against the **canonical** app, and
adds a readiness **warning** when the operator appears to be testing the legacy
billing runtime instead.

> This addendum changes **no infrastructure**. No container was shut down, no
> public port mapping changed, no host NAT / Traefik / Nginx touched, no data
> migrated, no databases merged, and `:18180` is **not** redirected to `:18188`.
> Those are explicitly deferred to a later, approved runtime-consolidation phase.

---

## Canonical vs legacy runtime

| | Canonical (pilot target) | Legacy / reference |
|---|---|---|
| **App** | **GlassPortal** | Standalone billing stack |
| **Public URL** | **http://40.160.61.180:18188** | http://40.160.61.180:18180 |
| **Login** | http://40.160.61.180:18188/login | http://40.160.61.180:18180/login |
| **Role in pilot** | **Test here.** All Phase 24–29 billing/checkout/webhook/entitlement/provisioning work lives here. | Reference only. Do **not** pilot against this. |
| **Repo** | `FuzzySpace/GlassPortal` (canonical — see Phase 28A) | the standalone billing runtime (legacy) |

**Pilot target:** `http://40.160.61.180:18188`.
**Treat `http://40.160.61.180:18180` as legacy/reference** until a later approved
runtime consolidation phase.

This is consistent with the Phase 28A decision
([`docs/architecture/repository-consolidation.md`](../architecture/repository-consolidation.md)):
GlassPortal is the canonical active application and GlassBilling is a bounded
module *inside* it. The standalone billing **runtime** is the deployment-side
counterpart of the standalone billing **repo** — both are legacy/reference.

## Known container mapping (host `lxc-gh-glassbilling-pr2-01`)

Documented from the operator-supplied inventory (not re-derived by changing
anything):

| Container | Exposed port | Role | Notes |
|---|---|---|---|
| `glassportal-source-app-1` | **8088** | **Canonical GlassPortal app** | Backs the `:18188` public URL. The app in this repo. |
| `ghbilling-billing-1` | 8080 | Legacy billing API | Part of the legacy standalone stack. |
| `ghbilling-portal-1` | 3000 | Legacy billing portal/UI | Backs the `:18180` public URL. |
| `ghbilling-postgres-1` | 5432 (local) | Legacy billing database | **Do not** merge/migrate in this phase. |
| `ghbilling-redis-1` | 6379 (local) | Legacy cache/queue | — |
| `ghbilling-mailhog-1` | 1025 / 8025 | Legacy mail catcher | — |

> The exact host NAT that maps public `:18188`→container `8088` and
> `:18180`→`ghbilling-portal-1:3000` is owned by the host and is **out of scope**
> for this phase. It is recorded here for orientation only.

## Is the old billing runtime still running?

**Yes.** The `ghbilling-*` containers (legacy billing API, portal, postgres,
redis, mailhog) remain running and continue to serve `:18180`. This addendum
**intentionally leaves them running** — it neither stops nor reconfigures them.
They are preserved as **legacy / reference** until a future approved phase decides
on runtime consolidation (and, if applicable, redirecting `:18180`→`:18188`).

## Routes on the canonical app (GlassPortal `:18188`)

Authoritative list comes from `php artisan route:list`. Summary of what an
operator/customer can reach on the canonical app:

**Public / auth**
- `GET /login`, `POST /login`, `POST /logout`
- `GET /products`, `GET /products/{slug}` (GlassSite public catalog)
- `GET /.well-known/glassportal/jwks.json`
- `GET /api/health`, `GET /api/connectors/*/health`

**Stripe webhook intake (public, signature-verified)**
- `POST /api/billing/stripe/webhook`

**Admin (staff; billing/pilot areas are owner/admin)**
- `GET /admin` (dashboard)
- `GET /admin/pilot-readiness` — **Phase 29 readiness page**
- `GET /admin/billing` + `…/products`, `…/plans`, `…/customers`,
  `…/subscriptions`, `…/checkout-sessions`, `…/entitlements`,
  `…/change-requests`, `…/events` (+ detail/action routes)
- `GET /admin/provisioning/requests` (+ detail + approve/reject/… actions)
- `GET /admin/customers`, `…/services`, `…/modules`, `…/module-links`,
  `…/site/catalog`, `…/billing-approvals`

**Customer portal (`role:customer`)**
- `GET /portal` (dashboard)
- `GET /portal/billing` + `…/subscriptions`, `…/invoices`, `…/payments`,
  `…/checkout-sessions`, `…/plans`, `…/change-requests` (+ detail routes)
- `POST /portal/billing/checkout/plans/{plan}` (checkout start)
- `POST /portal/billing/change-requests` (+ `/{id}/cancel`)
- `GET /portal/entitlements`, `…/provisioning`, `…/services`, `…/modules`,
  `…/support`, `…/modules/{moduleLink}/launch`

The full set is verifiable any time with `php artisan route:list`.

## Readiness warning (legacy-URL guard)

The pilot readiness checks now include a **Runtime exposure readiness** category
with `runtime.canonical_target`:

- **warning** when the current runtime authority (the configured `APP_URL`, or
  the live request host) matches the **legacy billing URL** — message: *"You
  appear to be testing the LEGACY billing runtime … use the canonical GlassPortal
  pilot target instead."*
- **ready** when it matches the canonical URL, or when it is neither (e.g. local
  dev).

Targets are configured in [`config/pilot.php`](../../config/pilot.php):

```php
'canonical_url'      => env('PILOT_CANONICAL_URL', 'http://40.160.61.180:18188'),
'legacy_billing_url' => env('PILOT_LEGACY_BILLING_URL', 'http://40.160.61.180:18180'),
```

The warning is **config/guidance only** — it never redirects, never changes ports,
and never touches the legacy runtime. Surfaces on the admin pilot readiness page
and in `php artisan glassportal:pilot-readiness`. No secrets are involved.

## What must NOT be changed in this phase

- Do **not** shut down any container (the legacy `ghbilling-*` stack keeps running).
- Do **not** change public port mappings (`:18188`, `:18180` stay as-is).
- Do **not** modify host NAT, Traefik, or Nginx.
- Do **not** migrate data between the legacy billing runtime and GlassPortal.
- Do **not** merge databases (`ghbilling-postgres-1` is left untouched).
- Do **not** redirect `:18180` → `:18188` yet.

## Next step (future, separate phase)

A later **runtime consolidation** phase — with its own approval and ADR — can
decide whether to redirect/retire `:18180`, consolidate databases, and formally
decommission the legacy `ghbilling-*` stack. Until then: pilot on `:18188`,
reference only on `:18180`.
