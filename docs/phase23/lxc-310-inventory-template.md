# LXC 310 — Legacy GlassPanel Inventory Template

> **READ-ONLY / PASSIVE.** Every command below only *observes* state. There are
> **no** start/stop/rm/prune/migrate/write commands. Do **not** modify, restart,
> tear down, import code from, or copy secrets out of this host. This template
> **locates** env files but never prints their contents — secrets must not be
> exfiltrated into notes or logs.

> **What this host actually is (discovery correction):** despite the
> `*-billing-*` hostname, LXC 310 is a **legacy GlassPanel — Game and Server
> Management Panel**, *not* a billing system. This inventory exists to
> snapshot/preserve it as **Legacy GlassPanel Reference #001** and **Migration
> Center Test Case #001** — **not** to qualify it as a GlassBilling source of
> truth (it is explicitly ruled out as one).

**Target:**

| Attribute | Value |
|---|---|
| Container | LXC 310 |
| Hostname | `lxc-gh-billing-dev-01` (misleading — this is GlassPanel) |
| IP | `10.10.1.40` |
| Legacy stack | `ghpanel` (GlassPanel) |
| Possible ports | 3000, 8080, 5432, 6379, 1025/8025, 80 |

**Confirmed discovery findings (verify during capture):**

| Evidence | Detail |
|---|---|
| Runtime path | `/var/www/html/dev/GHpanel` |
| Backend | `apps/panel` — Laravel 11 API |
| Frontend | `apps/web` — Next.js 14 |
| Agent | `apps/agent` |
| Migrator | `packages/migrator` — Pterodactyl / Pelican imports |
| Composer description | "GlassPanel — Game and Server Management Panel API" |
| DB owner / schema | `glasspanel` |
| Core tables | `nodes`, `servers`, `node_allocations`, `server_backups`, `server_databases`, `server_schedules`, `server_transfers`, `service_templates`, `template_variables` |
| Billing footprint | only `billing_integrations`, `billing_service_links` — *integration hooks*, not a ledger |

**How to use:** open a shell *inside* LXC 310 (e.g. `pct enter 310` from the
Proxmox host, or SSH to `10.10.1.40` if reachable) and run the read-only
commands per section. Capture output into a dated notes file kept **outside**
the container. Redact anything that looks like a credential.

---

## 0. Safety preamble (run first)

```bash
# Confirm you are on the intended host BEFORE collecting anything.
hostname; hostname -I 2>/dev/null; ip -4 addr show 2>/dev/null | awk '/inet /{print $2}'
date -u +"%Y-%m-%dT%H:%M:%SZ"
id; whoami
# Abort if hostname/IP do not match lxc-gh-billing-dev-01 / 10.10.1.40.
```

## 1. Hostname & identity

```bash
hostname
hostname -f 2>/dev/null
cat /etc/hostname
hostnamectl 2>/dev/null
```

## 2. OS version

```bash
cat /etc/os-release
uname -a
lsb_release -a 2>/dev/null
```

## 3. IPs & routes

```bash
ip -4 addr; ip -6 addr
ip route; ip -6 route
cat /etc/hosts
cat /etc/resolv.conf
```

## 4. Disk & memory

```bash
df -hT
free -h
lsblk 2>/dev/null
du -xhd1 / 2>/dev/null | sort -h | tail -20   # largest top-level dirs (read-only)
```

## 5. Running services (processes)

```bash
ps aux --sort=-%mem | head -40
top -b -n1 | head -25
```

## 6. Open ports / listeners

```bash
ss -tulpn 2>/dev/null || netstat -tulpn 2>/dev/null
# Cross-check the "possible services" list (3000/8080/5432/6379/1025/8025/80):
ss -tulpn 2>/dev/null | grep -E ':(80|3000|8080|5432|6379|1025|8025)\b' || true
```

## 7. Docker containers

```bash
docker ps -a
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'
docker images
docker stats --no-stream 2>/dev/null
```

## 8. Docker Compose projects

```bash
docker compose ls 2>/dev/null || docker-compose ls 2>/dev/null
# Locate compose files without modifying anything:
find / -maxdepth 6 -type f \( -name 'docker-compose*.yml' -o -name 'compose*.yml' \) 2>/dev/null
# Inspect the legacy stack labels (read-only) to find its project/working dir:
docker inspect $(docker ps -aq) 2>/dev/null \
  | grep -E 'com.docker.compose.(project|project.working_dir|service)' | sort -u
```

## 9. Docker volumes & networks

```bash
docker volume ls
docker network ls
# Map volumes to mountpoints (read-only inspect):
docker volume inspect $(docker volume ls -q) 2>/dev/null | grep -E '"Name"|"Mountpoint"'
docker network inspect $(docker network ls -q) 2>/dev/null | grep -E '"Name"|"Subnet"'
```

## 10. Git repositories

```bash
# Find repos without touching them:
find / -maxdepth 7 -type d -name '.git' 2>/dev/null
# For each repo dir found, observe only (no fetch/pull/checkout):
#   git -C <repo> remote -v
#   git -C <repo> branch -a
#   git -C <repo> log --oneline -5
#   git -C <repo> status -s
```

## 11. Application env files (LOCATE ONLY — never print contents)

```bash
# List paths + sizes only. DO NOT cat these files.
find / -maxdepth 7 -type f \( -name '.env' -o -name '.env.*' -o -name '*.env' \) \
  -not -path '*/node_modules/*' 2>/dev/null -printf '%p\t%s bytes\t%TY-%Tm-%Td\n'
# To confirm which KEYS exist without revealing VALUES (safe):
#   for f in <paths>; do echo "== $f =="; cut -d= -f1 "$f"; done
```

## 12. Database containers / data

```bash
# Identify DB containers by image (no exec into them required):
docker ps -a --format '{{.Names}}\t{{.Image}}' | grep -Ei 'postgres|mysql|mariadb|redis'
# Read-only: list databases (adjust container name; uses --no-password-friendly read):
#   docker exec -i <pg_container> psql -U postgres -c '\l' 2>/dev/null
#   docker exec -i <pg_container> psql -U glasspanel -c '\dt' glasspanel 2>/dev/null
# Expected (GlassPanel): schema/owner `glasspanel`; tables include nodes, servers,
# node_allocations, service_templates, … plus billing_integrations /
# billing_service_links (integration hooks only — NOT a billing ledger).
# Do NOT dump, alter, or drop. Schema/listing only.
```

## 13. Web roots

```bash
ls -la /var/www 2>/dev/null
# Confirm the known GlassPanel runtime layout (read-only):
ls -la /var/www/html/dev/GHpanel 2>/dev/null
ls -la /var/www/html/dev/GHpanel/apps 2>/dev/null   # expect: panel, web, agent
ls -la /var/www/html/dev/GHpanel/packages 2>/dev/null # expect: migrator
find /var/www /srv /usr/share/nginx 2>/dev/null -maxdepth 3 -type d 2>/dev/null
# Nginx/Apache config (read-only) to find document roots:
nginx -T 2>/dev/null | grep -E 'root|server_name|listen' | sort -u
grep -RInE 'DocumentRoot|root ' /etc/nginx /etc/apache2 2>/dev/null | head -40
```

## 14. Cron jobs

```bash
crontab -l 2>/dev/null
ls -la /etc/cron.d /etc/cron.daily /etc/cron.hourly /etc/cron.weekly 2>/dev/null
cat /etc/crontab 2>/dev/null
# Per-user crontabs (read-only):
for u in $(cut -f1 -d: /etc/passwd); do c=$(crontab -l -u "$u" 2>/dev/null); [ -n "$c" ] && echo "== $u ==" && echo "$c"; done
```

## 15. systemd services

```bash
systemctl list-units --type=service --all --no-pager 2>/dev/null
systemctl list-unit-files --type=service --no-pager 2>/dev/null | grep -E 'enabled|generated' 
# Anything billing/ghpanel-shaped:
systemctl list-units --type=service --no-pager 2>/dev/null | grep -Ei 'ghpanel|billing|panel|docker|postgres|redis|nginx' || true
```

---

## What this template intentionally does NOT do

- No `docker compose up/down`, `restart`, `rm`, `prune`, `stop`, or `kill`.
- No `git fetch/pull/checkout/reset`.
- No database dumps, writes, migrations, `DROP`, or `DELETE`.
- No editing or printing of secret/`.env` values.
- No package installs, no service enable/disable, no firewall changes.
- No importing GHpanel source into any other repo (preservation is by snapshot /
  source-control archive, after security review — not by copy-paste).

## Output handling

- Save captured output to a dated file **outside** LXC 310 (e.g. on the ops
  workstation), e.g. `lxc310-glasspanel-inventory-YYYYMMDD.md`.
- Redact tokens, passwords, keys, and connection strings before sharing.
- File the result under the two preservation labels: **Legacy GlassPanel
  Reference #001** and **Migration Center Test Case #001**.
- Feed findings into [`billing-gap-matrix.md`](./billing-gap-matrix.md) and the
  archive/keep/rebuild decisions in
  [`billing-source-reconciliation.md`](./billing-source-reconciliation.md). This
  inventory confirms LXC 310 is **GlassPanel, not billing** — do not use it to
  source any GlassBilling data.
