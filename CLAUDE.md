# CLAUDE.md

Project guidance and running work log for **OpenWA** — consumed by Claude Code (and humans)
at the start of each session. Keep this file current: whenever a notable change is made,
append an entry to the [Change Log](#change-log) and update the relevant section.

---

## 1. Project Overview

**OpenWA** is a free, open-source, self-hosted **WhatsApp API Gateway** — an HTTP API in
front of WhatsApp Web. It exposes sessions, messaging, groups, contacts, labels, catalog,
webhooks, and admin/infra endpoints, with a React dashboard for management.

- **Package**: `openwa` (private), version in [package.json](package.json) (`0.1.7` at time of writing).
- **License**: MIT.
- **Runtime**: Node 22 LTS.
- **Stack**: NestJS 11 (TypeScript 5), TypeORM, BullMQ, Socket.IO, `whatsapp-web.js` engine,
  headless Chrome, React dashboard (Vite).
- **Pluggable adapters**: database (SQLite/PostgreSQL), storage (Local/S3), cache (Memory/Redis) —
  swappable by config without touching application code.

GitHub (this fork): https://github.com/AminHassanDouale/openWA_Intec

---

## 2. Key Commands

Run from the repo root unless noted. (Windows host; a Bash tool and PowerShell are both available.)

| Task | Command |
| --- | --- |
| Install deps (also installs dashboard via postinstall) | `npm install` |
| Dev (API + dashboard concurrently) | `npm run dev` |
| API only (watch) | `npm run start:dev` |
| Dashboard only (watch) | `npm run dashboard:dev` |
| Build API | `npm run build` |
| Build dashboard | `npm run dashboard:build` |
| Prod start | `npm run start:prod` |
| Lint / fix | `npm run lint` / `npm run lint:fix` |
| Format | `npm run format` |
| Unit tests | `npm test` |
| Test coverage | `npm run test:cov` |
| E2E tests | `npm run test:e2e` |
| Create migration | `npm run migration:create --name=<Name>` |
| Generate migration | `npm run migration:generate --name=<Name>` |
| Run migrations (dev) | `npm run migration:run` |
| Run migrations (prod) | `npm run migration:run:prod` |
| Revert migration | `npm run migration:revert` |

Docker: `docker-compose.yml` (prod-ish), `docker-compose.dev.yml` (dev). See
[10-devops-infrastructure.md](docs/10-devops-infrastructure.md).

---

## 3. Architecture & Layout

```
src/
  common/        cross-cutting: cache, storage, security, filters,
                 interceptors, transformers, services, utils
  config/        configuration loading / validation
  core/          framework core: hooks, plugins
  database/      data-source + migrations (TypeORM)
  engine/        WhatsApp engine abstraction + adapters (whatsapp-web.js)
  modules/       feature modules (see below)
  plugins/       plugin implementations (engines)
dashboard/       React + Vite management UI
sdk/             client SDK
examples/        integration examples (e.g. notifications toolkit: PHP + Node)
docs/            numbered design/ops docs (01..23) — source of truth for design
scripts/         helper scripts
test/            e2e tests + jest-e2e config
traefik/         reverse-proxy config
```

**Feature modules** (`src/modules/`): `audit`, `auth`, `catalog`, `channel`, `contact`,
`docker`, `events`, `group`, `health`, `infra`, `label`, `message`, `plugins`, `queue`,
`session`, `settings`, `stats`, `status`, `webhook`.

**Engine**: the WhatsApp connection is driven by `whatsapp-web.js` over headless Chrome.
Session lifecycle, QR, reconnection, and health live in `src/engine/` and `src/modules/session/`.

---

## 4. Conventions

- **Language**: TypeScript, NestJS idioms (modules/providers/controllers, DI, DTOs with
  `class-validator`). Match surrounding style; Prettier + ESLint enforced (`.prettierrc`,
  `eslint.config.mjs`).
- **Commits**: Conventional Commits with a scope — e.g. `fix(session): ...`,
  `feat(engine): ...`, `feat(examples): ...`, `diag(engine): ...`. Follow this format.
- **Docs**: design/ops docs are the numbered files in `docs/`. When behavior changes,
  update the matching doc **and** [CHANGELOG.md](CHANGELOG.md) **and** this file's Change Log.
- **Auth/roles**: API is API-key based with roles (`ADMIN` > `OPERATOR` > ...). Infra and
  admin surfaces require elevated roles — see [04-security-design.md](docs/04-security-design.md).
- **Versioning**: SemVer; user-facing changes recorded in [CHANGELOG.md](CHANGELOG.md).

---

## 5. Documentation Map

Authoritative design/ops docs live in [docs/](docs/):

| # | Doc | # | Doc |
| --- | --- | --- | --- |
| 01 | Project overview | 13 | Horizontal scaling |
| 02 | Requirements spec | 14 | Migration guide |
| 03 | System architecture | 15 | Project roadmap |
| 04 | Security design | 16 | Risk management |
| 05 | Database design | 17 | Dashboard design |
| 06 | API specification | 18 | SDK design |
| 07 | API collection | 19 | Plugin architecture |
| 08 | Development guidelines | 20 | Community guidelines |
| 09 | Testing strategy | 21 | Glossary |
| 10 | DevOps / infrastructure | 22 | n8n integration |
| 11 | Operational runbooks | 23 | Third-party notify integration |
| 12 | Troubleshooting / FAQ | 24 | VPS deployment: wa.sahansolution.com |
|    |                       | 25 | API integration reference (all endpoints) |

Also: [README.md](README.md), [INTEGRATION.md](INTEGRATION.md), [CHANGELOG.md](CHANGELOG.md).

---

## 6. Change Log (session / engineering notes)

Newest first. Record every notable change here with date and a one-line rationale.
This complements the user-facing [CHANGELOG.md](CHANGELOG.md) with engineering context.

### 2026-10-10
- **Added `CLAUDE.md`** (this file) as the project guidance + running work log, and
  prepared the repo to publish to the `openWA_Intec` GitHub repository.
- **Added [docs/24-vps-deployment-sahansolution.md](docs/24-vps-deployment-sahansolution.md)** —
  deployment runbook for hosting OpenWA at `https://wa.sahansolution.com` on the Ubuntu 24.04
  VPS (`187.124.32.203`): Docker Compose with the built-in PostgreSQL profile, container bound
  to `127.0.0.1:2785`, fronted by the host web server (nginx/apache) with Let's Encrypt TLS.
- **Deployed & went live** at `https://wa.sahansolution.com`: added DNS `A wa → 187.124.32.203`,
  nginx reverse-proxy vhost → `127.0.0.1:2785`, Let's Encrypt cert (certbot --nginx).
  **As-deployed = shared gateway**: the domain fronts the pre-existing `openwa-api` container
  (from the `taxiconnectonline` compose project) that also runs `dtache`/`taxiconnect` sessions;
  the `/var/www/wa.sahansolution.com` `.env` is unused (port 2785 already taken). Active API key
  is the instance key from the container log (`owa_k1_...`). Restarting `openwa-api` affects all
  tenants. sahansolution gets its own named session `sahan-1`. See doc 24 "AS-DEPLOYED" note.

### 2026-10-11
- **Added [docs/25-api-integration-reference.md](docs/25-api-integration-reference.md)** — full
  HTTP API reference (sessions, messages, **groups**, contacts, labels, status/stories, channels,
  catalog, webhooks + HMAC signing, stats/health/admin) generated from the controllers/DTOs, with
  PHP/Laravel + Node client snippets and a webhook receiver — for integrating OpenWA into other projects.

### Recent engine/session hardening (see git history)
- `fix(session)`: make watchdog init test-safe and non-leaking (fix CI). — `3f3656f`
- `fix(engine)`: take over on conflict to reduce forced logouts / re-scans. — `d427e0e`
- `feat(session)`: session-health indicators (auto-recover + last disconnect). — `f9575cd`
- `feat(session)`: auto-recover sessions with a watchdog (fix stray disconnects). — `58ea902`
- `feat(examples)`: add WhatsApp notifications toolkit (PHP + Node). — `018d636`
- `fix(engine)`: treat null `sendMessage` return as delivered (LID re-read quirk). — `9a06424`
- `fix(engine)`: send to `@c.us` so sender is the phone user, not a null LID user. — `85f0fac`
- `fix(engine)`: restore known-good WhatsApp Web pin + `@lid` recipient resolution. — `1807eaf`
- `fix(docker)`: install full headless-Chrome runtime libs so the engine launches. — `5b5f2b1`

---

## 7. Maintaining This File

When you (Claude) make a change in this repo:
1. Implement the change following the conventions above.
2. Update the relevant `docs/NN-*.md` if design/behavior changed.
3. Add a dated bullet to the [Change Log](#change-log) with a short rationale.
4. For user-facing changes, also update [CHANGELOG.md](CHANGELOG.md) under `[Unreleased]`.
5. Commit with a Conventional Commit message.
