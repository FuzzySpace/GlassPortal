# Phase 26 — Provisioning Request Engine

## Purpose

Phase 24 built the Stripe-first billing foundation; Phase 25 added billing
service entitlements. Phase 26 adds the **approval-gated request layer** that
future drivers will consume.

**Core product rule:**

> Billing determines entitlement. Provisioning fulfills entitlement.
> **This phase creates provisioning *requests* only; it does not mutate
> infrastructure** — even a "completed" request only updates request + billing
> entitlement state.

---

## Data Model

Two tables (migrations `2026_06_27_000013`–`000014`), cross-DB safe.

### `provisioning_requests` (soft-deleted)

`request_key` (unique), optional links to `billing_service_entitlements`,
`billing_customers`, `organizations`, `users`; `module_key` / `product_key` /
`service_type` / `driver_key`; `requested_action`, `status`, `priority`,
`requires_approval`; approval/assignment columns (`approved_by`/`approved_at`,
`rejected_by`/`rejected_at`, `assigned_to`); lifecycle timestamps
(`scheduled_for`, `started_at`, `completed_at`, `failed_at`, `cancelled_at`);
`idempotency_key` (unique), `reason`, `failure_reason`; and `payload` / `result`
/ `metadata` JSON. Secret-shaped values in those JSON columns are **redacted on
display** (see Security).

### `provisioning_request_events` (append-only)

One immutable row per transition: `event_type`, `previous_status`,
`new_status`, `actor_type`/`actor_id`, `message`, `metadata`, `created_at`.

### Models

`App\Models\ProvisioningRequest` (owns the state machine) and
`ProvisioningRequestEvent`. Reverse `provisioningRequests()` relations added to
`BillingServiceEntitlement`, `BillingCustomer`, `Organization`, `User`.

---

## Request Statuses

`draft`, `pending_approval`, `approved`, `rejected`, `queued`, `running`,
`completed`, `failed`, `cancelled`. **Terminal:** `completed`, `rejected`,
`cancelled` (`failed` can be re-queued).

## Requested Actions

`provision`, `suspend`, `reactivate`, `cancel`, `terminate`, `update`, `migrate`.

## Allowed Transitions

The model owns the explicit map (`ProvisioningRequest::TRANSITIONS`); anything
not listed is rejected:

| From | Allowed → |
|---|---|
| `draft` | pending_approval, cancelled |
| `pending_approval` | approved, rejected, cancelled |
| `approved` | queued, cancelled |
| `queued` | running, cancelled |
| `running` | completed, failed, cancelled |
| `failed` | queued, cancelled |
| `completed` / `rejected` / `cancelled` | — (terminal) |

`ProvisioningRequestService` applies transitions, stamps the relevant timestamp,
records an event, and returns a `ProvisioningRequestResult` DTO. Invalid
transitions fail safely (`ok=false`, status unchanged, no event).

Methods: `createFromEntitlement` (idempotent), `approve(User)`, `reject(User)`,
`queue`, `start`, `complete(result)`, `fail`, `cancel`.

### Safe entitlement hand-off (billing state only)

For `provision` requests the engine reflects progress onto the entitlement's
*billing* lifecycle via `BillingEntitlementService` — never infrastructure:

- request **created** → entitlement `provisioning_pending`
- request **completed** → entitlement `active`
- request **failed** → entitlement `provisioning_failed`

These are best-effort (the entitlement service safely rejects invalid
transitions).

---

## Admin Workflow

Owner/admin only (stacked `role:owner,admin`). Routes are registered **before**
the Phase 5 GlassBilling-bridge `/provisioning/{id}` so the static
`/provisioning/requests` segment is not shadowed:

| Route | Name |
|---|---|
| `GET admin/provisioning/requests` | `admin.provisioning.requests.index` |
| `GET admin/provisioning/requests/{provisioningRequest}` | `admin.provisioning.requests.show` |
| `POST admin/provisioning/requests/{provisioningRequest}/{action}` | `admin.provisioning.requests.action` |

`{action}` ∈ `approve | reject | queue | start | complete | fail | cancel`. The
detail page links the entitlement/customer/org, shows redacted payload/result
and the event history, and offers only the **currently-valid** action buttons.

---

## Customer Visibility

`GET portal/provisioning` (role: customer) — **read-only**, strictly scoped to
the signed-in user's `organization_id`. A customer sees their requests'
service/action/status only; they **cannot** approve/reject/start/complete/fail/
cancel (no portal write routes), cannot see another org's requests, and never
see payload/result/metadata.

---

## Driver Registry Placeholder

`config/provisioning.php` is a **metadata-only** registry of driver keys
(`manual`, `siona`, `glasspanel`, `webhosting`, `dns`, `mail`, `netbox`).
**Nothing here executes** in Phase 26 — no driver makes infrastructure calls;
`executable` is intent metadata, not a capability. The healthcheck reports the
configured driver keys. Driver *execution* is Phase 27+.

---

## Security Boundaries

1. **No infrastructure execution** — the engine only reads billing records and
   writes request + entitlement rows + events. It never calls Stripe, SIONA,
   Proxmox, DNS, NetBox, Mail, or GamePanel (proven by `Http::assertNothingSent`
   across a full lifecycle, including `complete`).
2. **No GHpanel/LXC 310 reuse** (asserted by a source check).
3. **Driver registry is inert** — no `->execute(`/`dispatch(` in the service.
4. **Secret redaction** — `payload`/`result`/`metadata` are redacted on display
   for keys matching `token`/`secret`/`password`/`private_key`/`api_key`/
   `credential`, even for admins (tested with `api_token`/`secret`/`password`/
   `stripe_secret`/`signing_secret`/`private_key`). The portal renders no
   payload at all.
5. **Owner/admin-only actions**; customers/staff/guests blocked. Transitions go
   through the allowed-transition map.
6. **Org-scoped customer reads** — cross-organization access is impossible.

---

## What Is Intentionally Out of Scope (later phases)

Actual infrastructure provisioning; Proxmox/DNS/NetBox/Mail/GamePanel calls;
SIONA provisioning execution; real queue workers executing drivers; an approval
policy engine beyond basic admin actions; agent/ARIA automation; Stripe
checkout/webhooks; customer-initiated provisioning actions.

## Relationship to Phase 27 (Driver Execution)

Phase 27+ adds the **driver execution layer**: a worker picks up `queued`
requests, resolves the `driver_key` to a real driver, executes it against the
owning module (generalizing the SIONA tenant-provisioning pattern), and reports
back via `start`/`complete`/`fail`. The `running`/`completed`/`failed` statuses
and the `result` column are the hand-off points. Billing/entitlement state stays
the source of truth; the request engine remains the approval gate.

---

## Tests

| Suite | File | Coverage |
|---|---|---|
| Unit | `tests/Unit/Provisioning/ProvisioningRequestModelTest.php` | tables, factories, relationships (incl. reverse), helpers, transition map, scopes, redaction. |
| Unit | `tests/Unit/Provisioning/ProvisioningRequestServiceTest.php` | create + idempotency (open + key), approve/reject/queue/start/complete/fail/requeue, invalid fails safely, events prev/new, entitlement → active/provisioning_failed, **no external calls**, no GHpanel, inert driver registry. |
| Feature | `tests/Feature/AdminProvisioningRequestsTest.php` | admin list/detail; full lifecycle via POST; reject/cancel; invalid error; customer/staff/guest blocked; payload/result redacted. |
| Feature | `tests/Feature/PortalProvisioningTest.php` | own-org visibility; cross-org hidden; no-org empty; cannot mutate; payload secrets never rendered. |
| Feature | `tests/Feature/HealthCheckCommandTest.php` | provisioning checks present + exit 0. |

Run: `php artisan test` → **623 passed**.

Healthcheck adds: `provisioning.requests_table`,
`provisioning.request_events_table`, `provisioning.models`,
`provisioning.service`, `provisioning.driver_registry`.

---

## Known Limitations / TODOs

- **No execution** — `complete`/`fail` are operator-driven; no worker runs
  drivers yet (Phase 27).
- Idempotency keys on an open request per entitlement+action; a re-request after
  a terminal request creates a new one (intended).
- `requires_approval=false` creates directly `approved` (auto-approve hook);
  there is no richer approval-policy engine yet.
- `assigned_to`/`scheduled_for` are modelled but not yet driven by any workflow.
- `actor_type`/`actor_id` are a lightweight reference, not a hard FK.
