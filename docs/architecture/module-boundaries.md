# GlassPortal — Module Boundaries

This document defines what GlassPortal **is** and **is not**, and how it
relates to the other Glasshouse ecosystem modules. It is a Phase 1 reference
intended to prevent scope creep as integrations land.

The principle: **GlassPortal is a shell. Each capability has an owner
module.** GlassPortal renders, orchestrates, and audits — it does not own
business state.

---

## GlassPortal

**Owns:**

- Unified customer + staff portal UI
- Dashboard shell, layout, navigation, theming
- Customer self-service views (read state from other modules, request
  actions)
- Staff operations views (incident, NOC, provisioning, support surfaces)
- Connector-facing UI surface (forms/tables/wizards that call other modules)
- Role-based access layer (RBAC) for portal sessions
- Staff audit log of portal-initiated actions
- Customer impersonation / "login as owner" workflow (gated + logged)

**Does not own:**

- Billing engine, ledger, invoice generation — see **GlassBilling**.
- Game/server runtime, daemons, file IO — see **GlassPanel**.
- AI model runtime, training, inference scheduling — see **Aria**.
- Authoritative DNS, mail delivery, hypervisor state — see PowerDNS, Mailcow,
  Proxmox.

---

## GlassBilling

**Owns:**

- Billing system of record (invoices, charges, refunds, taxes)
- Product catalog
- Subscriptions and renewals
- Service lifecycle state (active / suspended / terminated)
- Approval workflows for lifecycle changes
- Credential brokerage to downstream provider modules (preferred over the
  portal storing raw provider secrets)

GlassPortal **reads** subscription/invoice state and **requests** lifecycle
actions; it never writes ledger entries directly.

---

## GlassPanel

**Owns:**

- Game/server runtime management
- Game instance start/stop/restart/console
- Pterodactyl compatibility + migration support during transition
- Future eggless-runtime abstraction (post-Pterodactyl)
- Idle detection, suspend/wake workflows (later)
- File operations on game instances

GlassPortal embeds GlassPanel UI surfaces or proxies to its API; GlassPortal
does not run game daemons.

---

## Aria

**Owns:**

- Internal AI operations assistant (staff workflows)
- Customer-facing support assistance flows
- CPU-only baseline mode for routine inference
- Rented GPU burst mode for expensive workloads
- Model selection, prompt management, conversation memory

**Disclosure / safety rules:**

- Aria must **always be disclosed as AI** in customer-facing contexts. Do not
  present Aria as a human agent.
- Use safe branded assistant language (e.g. "Aria, the Glasshouse assistant")
  rather than first-name human personas.
- Aria actions that affect **infrastructure** (provisioning, billing changes,
  service suspension, DNS edits, mailbox creation) must pass through
  explicit approval boundaries enforced by the portal. No silent execution.

GlassPortal hosts the Aria conversation surface but does not run models.

---

## PowerDNS

**Owns:**

- DNS zone/record lifecycle
- Authoritative DNS responses

**Not authoritative for:**

- Business logic about whether a zone *should* exist — that lives in
  GlassBilling / GlassPortal.

GlassPortal exposes zone/record management UI that calls the PowerDNS API.

---

## Mailcow

**Owns:**

- Paid-domain mailbox services (provisioned per customer/domain)
- Abuse monitoring on outbound mail
- Mailbox/alias/domain CRUD via API

**Not in scope:**

- Free public webmail offering — not a goal.

GlassPortal surfaces mailbox provisioning and quota/abuse status; it does not
deliver mail.

---

## Proxmox

**Owns:**

- VPS / CT / VM inventory
- Hypervisor-level provisioning, migration, snapshotting

**Constraints:**

- Raw Proxmox API tokens must **not** sit in the portal database. The portal
  should obtain short-lived credentials via a broker (GlassBilling or a
  dedicated secret backend) when possible.

GlassPortal renders inventory and provisioning status and proxies actions
through an authenticated connector.

---

## Support inbox / ticketing

**Owns:**

- Centralized staff-side access to customer communications

**Not in scope:**

- Public Gmail-style email replacement for end users.

GlassPortal embeds the inbox surface for staff. The provider is pluggable
(see `SUPPORT_INBOX_PROVIDER` in `.env.example`).

---

## Integration patterns

- **Read-mostly UI:** portal calls module API → renders. Cache where safe.
- **Action requests:** portal POSTs intent → module validates + executes →
  module emits event → portal updates UI on event/poll.
- **No cross-DB writes.** GlassPortal does not write to other modules'
  databases directly.
- **Audit at the portal:** every staff-initiated action is logged in the
  portal audit log **in addition to** any audit the downstream module keeps.
