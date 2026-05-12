# GlassPortal

GlassPortal is the unified customer/staff portal for the **Glasshouse** ecosystem,
maintained under **FuzzySpace/GlassPortal**. It originated from the
GlassMineCraft provisioning-portal development repository and is being split
out into a standalone, modular product.

> Status: **Phase 1 — Baseline cleanup.** Not production-ready. Do not deploy
> against live customer data or production secrets.

## What GlassPortal is

GlassPortal is the UI/orchestration shell that surfaces capabilities owned by
other Glasshouse modules. It is **not** the billing engine, game daemon, or
AI runtime — those live in dedicated services (see
[docs/architecture/module-boundaries.md](docs/architecture/module-boundaries.md)).

Intended integrations:

- **GlassBilling** — invoices, subscriptions, products, lifecycle approvals
- **GlassPanel** — game/server control plane (Pterodactyl-compatible migration)
- **Aria** — internal AI ops assistant + customer support workflows
- **Proxmox** — VPS/CT/VM inventory and provisioning visibility
- **PowerDNS** — DNS zones/records
- **Mailcow** — paid mailbox/domain services + abuse monitoring
- **Support inbox** — staff-side centralized communications

## Repository layout

```
.
├── provisioning portal/   # Original raw-PHP portal (primary working app)
├── laravel/               # Sparse Laravel scaffold (migrations + api route only)
├── windows-installer/     # Inno Setup-based Windows installer + service bits
├── INSTALL.md             # Legacy install notes (Linux + Windows)
└── docs/                  # Phase 1 documentation
```

## Stack (as imported)

- **Language:** PHP 8.x
- **Web app:** Raw PHP under `provisioning portal/`
- **DB:** MariaDB / MySQL — schema in `provisioning portal/database/schema.sql`
- **Future framework target:** Laravel (scaffold present, not yet wired up)
- **Frontend:** Server-rendered PHP + static assets (`provisioning portal/assets/`)
- **Installer:** Inno Setup (`windows-installer/setup.iss`)

A root `composer.json` / `package.json` exist but are currently empty
placeholders. Adopting Laravel fully is a Phase 2 candidate.

## Setup

1. Clone and switch to a working branch.
2. Copy the env template:
   ```
   cp .env.example .env
   ```
3. Provide a local MariaDB/MySQL instance and a database (default name
   `glassportal`).
4. For the legacy raw-PHP portal, see `INSTALL.md` and adjust
   `provisioning portal/database/config.php` to read from environment variables
   (Phase 2 cleanup — current config still ships defaults).

### Common commands

| Purpose            | Command                                              |
|--------------------|------------------------------------------------------|
| Dev server (PHP)   | `php -S 127.0.0.1:8080 -t "provisioning portal"`     |
| DB schema import   | `mysql -u root -p < "provisioning portal/database/schema.sql"` |
| Init DB (CLI)      | `php "provisioning portal/init-db.php"`              |

Laravel commands are not yet wired up because the scaffold is incomplete.

## Known limitations (Phase 1)

- `provisioning portal/database/config.php` and `init-db.php` still contain
  hardcoded fallback credentials (e.g. `glasshouse`, `PortalStrongPass!ChangeMe`).
  These must move to env in Phase 2.
- The `laravel/` directory is **not** a runnable Laravel app — it has only
  migrations and a stub `routes/api.php`.
- Root `composer.json`, `package.json`, and `.env` are empty placeholders.
- No automated tests yet.
- No CI configured.
- Integrations to GlassBilling, GlassPanel, Aria, Proxmox, PowerDNS, Mailcow
  are **not implemented** — env variables for them are placeholders only.

## Do not use in production

This repository is in active reorganization. Do not point it at production
databases, real customer data, or production secret material until Phase 2+
hardening is complete.

## Documentation

- [docs/phase1/baseline-cleanup.md](docs/phase1/baseline-cleanup.md)
- [docs/architecture/module-boundaries.md](docs/architecture/module-boundaries.md)
- [docs/security/secrets-and-access.md](docs/security/secrets-and-access.md)
- [docs/development/local-dev.md](docs/development/local-dev.md)
