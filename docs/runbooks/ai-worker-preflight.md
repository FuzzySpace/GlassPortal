# Runbook — AI / Operator Preflight (mandatory)

**Audience:** any AI worker or operator about to make changes in GlassPortal.
**Purpose:** prevent **context drift** between documented decisions and runtime
reality. Run this preflight **before** changing code. It is a checklist — **not**
an autonomous agent. No AI agents are created or run by this process.

> If any step's reality conflicts with the documented state
> ([`docs/state/`](../state/)), **STOP and reconcile with the operator** before
> editing code. Do not assume; do not "fix" infrastructure to match docs.

---

## Why

Phases 23–29 made GlassPortal the canonical app while a **legacy billing runtime
still runs** (`:18180`) and a **standalone billing repo** still exists. The most
likely failure mode is acting on a stale assumption — editing the wrong repo,
targeting the wrong URL, or touching a system that must not change. This preflight
makes the current truth explicit first.

## Preflight checklist

Work top to bottom. Don't skip.

### 1. Confirm repository
- [ ] You are in **`FuzzySpace/GlassPortal`** (the canonical active repo).
- [ ] You are **not** editing the standalone `FuzzySpace/GlassBilling`, SIONA, or
      GHpanel/LXC 310 repos.
- [ ] Read [`docs/state/repository-map.md`](../state/repository-map.md).

### 2. Confirm branch
- [ ] Confirm the active development branch (`git branch --show-current`).
- [ ] Confirm it matches the branch you were told to work on; if a different
      branch was named that doesn't exist, **ask** — do not silently create one.

### 3. Confirm public runtime
- [ ] Canonical pilot target is **`http://40.160.61.180:18188`** (GlassPortal).
- [ ] **`http://40.160.61.180:18180`** is **legacy/reference** — never the target.
- [ ] Read [`docs/state/runtime-map.md`](../state/runtime-map.md).
- [ ] If `APP_URL` / the pilot target points at `:18180`, treat it as a blocker.

### 4. Confirm Docker services
- [ ] Identify which container backs the canonical app
      (`glassportal-source-app-1` → `:8088` → public `:18188`).
- [ ] If Docker is available, confirm services are up; if not, use the local
      sqlite validation path and say so explicitly.
- [ ] Do **not** stop, restart, or reconfigure any container as part of code work.

### 5. Confirm old runtimes
- [ ] The legacy `ghbilling-*` stack (billing API, portal, postgres, redis,
      mailhog) is expected to **still be running** and must be left untouched.
- [ ] Do **not** migrate data or merge databases from the legacy runtime.

### 6. Confirm phase objective
- [ ] Read [`docs/state/phase-status.md`](../state/phase-status.md): what is the
      active phase, and what is explicitly out of scope?
- [ ] Confirm the task at hand fits the active phase's scope and constraints.

### 7. Run the automated readiness gate
- [ ] `php artisan glassportal:healthcheck` — expect exit 0.
- [ ] `php artisan glassportal:pilot-readiness` — review blocked/warning items.
      Note the **Runtime exposure** and **State & drift-guard** checks: they warn
      if the pilot target is `:18180`, if the canonical URL is not `:18188`, or if
      a state/decision doc is missing.

### 8. Identify blockers before any code change
List blockers explicitly and resolve or escalate **before** editing:
- Wrong repo / wrong branch.
- Pilot target is the legacy `:18180`, or canonical URL is not `:18188`.
- A required decision/state doc is missing (repository-consolidation, runtime-map,
  repository-map).
- A blocked readiness check that the task depends on.
- Any request that would touch a "do not modify" system (below).

## Hard "do not" list (this phase)

- Do **not** create autonomous AI agents.
- Do **not** change public routing / port mappings / NAT / Traefik / Nginx.
- Do **not** stop containers.
- Do **not** migrate or merge databases.
- Do **not** alter Stripe behavior.
- Do **not** modify production infrastructure, the SIONA repo, the standalone
  GlassBilling repo, or GHpanel/LXC 310.

Only after this preflight is clean (or its blockers are explicitly accepted by the
operator) should code changes begin.
