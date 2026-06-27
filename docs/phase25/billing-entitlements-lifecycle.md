# Phase 25 — Billing Entitlements + Service Lifecycle

## Purpose

Phase 24 built the Stripe-first billing foundation. Phase 25 adds the
**entitlement layer**: GlassBilling's authoritative statement of *what a
customer is allowed to receive* based on billing/subscription state, with a
safe lifecycle state machine.

**Core product rule:**

> Billing determines entitlement. Provisioning fulfills entitlement later.
> Billing must **not** directly mutate Proxmox, DNS, SIONA, GlassPanel/
> GamePanel, NetBox, Mail, or any infrastructure.

The entitlement layer is the contract a future provisioning request engine
(Phase 26) will consume — entitlements are emitted/transitioned here, never
fulfilled here.

---

## Data Model

Two tables (migrations `2026_06_27_000011`–`000012`), cross-DB safe.

### `billing_service_entitlements` (soft-deleted)

Links to `billing_customers` (required), and optionally to
`billing_subscriptions`, `billing_products`, `billing_plans`, `organizations`,
`users`. Carries: `entitlement_key` (unique, idempotency), `service_type`,
`module_key`, `product_key`, `name`, `description`, `status`, `quantity`, and
lifecycle timestamps (`starts_at`, `current_period_start/end`, `trial_ends_at`,
`suspended_at`, `cancelled_at`, `terminated_at`), plus `metadata` (JSON, never
rendered publicly).

### `billing_service_entitlement_events` (append-only)

An immutable audit row per transition: `event_type`, `previous_status`,
`new_status`, `actor_type`/`actor_id`, `reason`, `metadata`, `created_at`.

### Models

`App\Models\BillingServiceEntitlement` (owns the state machine) and
`App\Models\BillingServiceEntitlementEvent`. Reverse `serviceEntitlements()` /
`billingServiceEntitlements()` relations were added to `BillingCustomer`,
`BillingSubscription`, `BillingProduct`, `BillingPlan`, and `Organization`.

Helpers: `isActive()`, `isSuspended()`, `isTerminal()`, `canProvision()`,
`canSuspend()`, `canReactivate()`, `canCancel()`, `canTerminate()`,
`canTransitionTo()`. Scopes: `active`, `pending`, `suspended`, `cancelled`,
`terminated`, `forOrganization`.

---

## Lifecycle Statuses

`pending`, `active`, `past_due`, `suspended`, `cancelled`, `terminated`,
`expired`, `provisioning_pending`, `provisioning_failed`.

**Terminal** (no longer a live grant): `cancelled`, `terminated`, `expired`.

## Allowed Transitions

The model owns the explicit map (`BillingServiceEntitlement::TRANSITIONS`); any
transition not listed is rejected:

| From | Allowed → |
|---|---|
| `pending` | active, cancelled, provisioning_pending |
| `active` | suspended, cancelled, terminated, provisioning_pending, past_due, expired |
| `past_due` | active, suspended, cancelled |
| `provisioning_pending` | active, provisioning_failed |
| `provisioning_failed` | provisioning_pending, cancelled |
| `suspended` | active, cancelled, terminated |
| `cancelled` | terminated |
| `expired` | terminated |
| `terminated` | — (terminal) |

`BillingEntitlementService` applies transitions, stamps the relevant date
(`suspended_at`/`cancelled_at`/`terminated_at`/`starts_at`), records an event,
and returns a `BillingEntitlementResult` DTO (`ok`, `status`, `message`,
`entitlement`, `previousStatus`, `newStatus`, `reason`, `metadata`). Invalid
transitions fail safely (`ok=false`, status unchanged, no event).

Methods: `createFromSubscription` (idempotent on subscription+plan),
`createForCustomer`, `activate`, `suspend`, `reactivate`, `cancel`,
`terminate`, `markProvisioningPending`, `markProvisioningFailed`.

---

## Admin Workflow

Owner/admin only (stacked `role:owner,admin` on the billing route group), under
the Phase 24 admin billing area:

| Route | Name |
|---|---|
| `GET admin/billing/entitlements` | `admin.billing.entitlements` |
| `GET admin/billing/entitlements/{entitlement}` | `admin.billing.entitlements.show` |
| `POST admin/billing/entitlements/{entitlement}/{action}` | `admin.billing.entitlements.action` |

`{action}` ∈ `suspend | reactivate | cancel | terminate | provisioning-pending
| provisioning-failed`. The detail page shows status, customer/product/plan/
subscription links, the full event history, and **only the lifecycle buttons
that are currently valid** (driven by the model's `can*` helpers). The acting
admin is recorded as the event actor.

---

## Customer Visibility

`GET portal/entitlements` (`portal.entitlements`, role: customer) — **read-only**.
Shows the signed-in user's organization's `active` / `pending` / `suspended`
entitlements only, strictly scoped by `organization_id`. A customer can never
see another organization's entitlements and **cannot mutate lifecycle state**
(there are no portal write routes). Metadata is never rendered.

---

## Security Boundaries

1. **No infrastructure mutation.** `BillingEntitlementService` only reads
   billing records and writes entitlement rows + events. It never calls Stripe,
   SIONA, Proxmox, DNS, NetBox, Mail, or GamePanel — proven by an
   `Http::assertNothingSent()` test across a full lifecycle.
2. **No GHpanel/LXC 310 reuse** — entitlements are built clean (asserted by a
   source check).
3. **Admin actions are owner/admin only**; customers/staff/guests are blocked.
   Transitions go through the allowed-transition map (no arbitrary status sets).
4. **Metadata is never rendered** on admin or portal pages (tested with
   `api_token` / `secret` / `password` / `stripe_secret` / `signing_secret`
   values).
5. **Org-scoped customer reads** — cross-organization access is impossible.

---

## What Is Intentionally Out of Scope (later phases)

- Infrastructure provisioning and the **provisioning request engine** (Phase 26).
- Stripe checkout / live Stripe webhook route.
- Service-suspension automation (e.g. auto-suspend on `past_due`).
- Proxmox / DNS / NetBox / Mail / GamePanel calls.
- SIONA provisioning *from* an entitlement.
- Customer upgrades/downgrades; refunds/taxes/credits.

## Relationship to Phase 26 (Provisioning Request Engine)

Phase 26 will introduce a **request → approval → driver** layer that *consumes*
entitlements: when an entitlement `canProvision()`, the engine creates a
provisioning request, an approver gates it, and a driver fulfills it against the
owning module (generalizing the SIONA tenant-provisioning pattern). The
`provisioning_pending` / `provisioning_failed` statuses and the
`markProvisioning*` methods are the hand-off points. Billing still never touches
infrastructure directly.

---

## Tests

| Suite | File | Coverage |
|---|---|---|
| Unit | `tests/Unit/Billing/BillingEntitlementModelTest.php` | tables, factories, relationships (incl. reverse), helpers, transition map, scopes, casts. |
| Unit | `tests/Unit/Billing/BillingEntitlementServiceTest.php` | create-from-subscription, idempotency, all valid transitions, invalid fails safely, reactivate-only-from-suspended, events record prev/new, **no external calls**, no GHpanel reference. |
| Feature | `tests/Feature/AdminEntitlementsTest.php` | admin list/detail; customer/staff/guest blocked; suspend action + event; invalid action error; customer cannot run actions; metadata never rendered. |
| Feature | `tests/Feature/PortalEntitlementsTest.php` | own-org visibility; cross-org hidden; terminal hidden; no-org empty state; no write route; metadata never rendered. |
| Feature | `tests/Feature/HealthCheckCommandTest.php` | entitlement checks present + exit 0. |

Run: `php artisan test` → **588 passed**.

Healthcheck adds: `billing.entitlements_table`, `billing.entitlement_events_table`,
`billing.entitlement_models`, `billing.entitlement_service`.

---

## Known Limitations / TODOs

- Status changes are **manual / API-driven**; no automation links subscription
  status (`past_due`, `canceled`) to entitlement status yet — a future phase
  (or the Stripe webhook phase) will drive these automatically.
- `createFromSubscription` keys idempotency on `sub:{id}:plan:{plan_id}`; a
  subscription that changes plan would create a new entitlement (upgrade/
  downgrade handling is out of scope here).
- `actor_type`/`actor_id` are a lightweight reference, not a hard FK.
- No bulk lifecycle operations; one entitlement at a time.
