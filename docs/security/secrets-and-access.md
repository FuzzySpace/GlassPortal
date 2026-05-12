# GlassPortal — Secrets & Access

Phase 1 reference for how GlassPortal handles secrets, credentials, staff
access, customer impersonation, and AI-initiated actions. This is policy
direction — not all of it is implemented yet. Items marked **Phase 2+** are
required before any production use.

## Principles

1. **No plaintext key storage.** Secrets must not live in the portal database
   in plaintext. If GlassPortal stores connector credentials, they must be
   encrypted at rest with an app-managed key, and the decryption key must
   not live in the same datastore.
2. **Prefer a credential broker.** GlassBilling (or a dedicated secret
   service) should broker downstream provider credentials (Proxmox,
   PowerDNS, Mailcow). The portal asks for a short-lived token rather than
   holding long-lived provider API keys.
3. **Env, not source.** All runtime configuration goes through environment
   variables (see `.env.example`). No secrets in committed PHP/JSON/INI.
4. **Audit everything.** Every staff action that touches customer state or
   infrastructure is recorded with actor, target, intent, result, and a
   correlation id.

## Current state (Phase 1 — honest assessment)

- Several fallback credential strings live in source today and are flagged in
  `docs/phase1/baseline-cleanup.md`:
  - `provisioning portal/database/config.php` (DB user/password fallback)
  - `provisioning portal/init-db.php` (MariaDB root password fallback)
  - `windows-installer/` templates (same root password)
- There is no centralized audit log table yet.
- There is no encrypted credential store yet.
- There is no AI approval gate yet.

These are acceptable for **Phase 1 baseline preservation** but **must be
remediated before production**.

## Phase 2+ requirements

### Secret storage

- Move all DB/connector credentials to env-driven configuration.
- Add an encrypted credential vault table for any connector credentials the
  portal must persist (e.g. per-tenant API tokens). Use authenticated
  encryption (AES-256-GCM or libsodium) keyed by a server-side master key.
- Support a pluggable secret backend (`SECRET_BACKEND` env). Initial
  implementations: `env`, `file`, **HashiCorp Vault** (or equivalent —
  AWS KMS / GCP Secret Manager / 1Password Connect).
- Never log secret values. Redact at the logger boundary.

### Staff access

- RBAC roles must be enforced server-side, not just in the UI.
- All staff actions write to an `audit_log` table:
  `actor_id`, `actor_role`, `action`, `target_type`, `target_id`,
  `payload_summary`, `result`, `ip`, `user_agent`, `correlation_id`,
  `created_at`.
- Sensitive read access (e.g. viewing a customer's payment method or
  mailbox contents) is also audited.

### Customer impersonation / "login as owner"

- Disabled by default; requires explicit role.
- Requires a documented business reason captured at the time of use.
- Approval-controlled for high-risk targets (e.g. enterprise tenants) via a
  second-staff approval workflow.
- Banner visible to the staff member throughout the impersonated session
  identifying the impersonation.
- Logged with start time, end time, reason, approver, and a full
  action-trail diff. No silent impersonation.
- Impersonation must **not** be usable to reset MFA or to perform
  irreversible billing actions without an additional confirmation step.

### AI-initiated actions (Aria)

- Aria may **propose** actions freely.
- Aria may **execute** read-only actions automatically.
- Aria **must not** execute infrastructure-changing actions (provisioning,
  suspend/terminate, billing changes, DNS writes, mailbox creation,
  Proxmox state changes) without an explicit approval boundary:
  - Customer-facing: the customer must confirm in-UI.
  - Staff-facing: a staff member must confirm; for high-risk actions, a
    second staff approver is required.
- Every Aria-initiated action is recorded in the same `audit_log` with
  `actor_role = 'aria'` and a link to the conversation/turn that produced it.
- Aria identity must be disclosed in customer-facing contexts (see
  `docs/architecture/module-boundaries.md`).

### Network / transport

- All connector calls over TLS. Reject plaintext HTTP for external module
  endpoints in non-local environments.
- Webhook receivers must verify signatures with per-module shared secrets
  stored via the secret backend, not in the DB.

## Incident handling

- Suspected credential leak → rotate via the secret backend, invalidate
  affected sessions, write an `audit_log` entry of class `security.rotation`.
- The portal must support emergency revoke of all active staff sessions
  (kill-switch) without a full deploy.
