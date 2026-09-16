# Implementation Roadmap — Personal Finance App

> Current baseline: **Phase 2 complete** (all core MVP + 3 innovative features implemented, frontend builds, PHP passes `php -l`). Phases A–D take the working prototype to a production-safe, tested, deployable product. Each step is actionable with acceptance criteria.

## Legend
- 🔭 = depends on prior phase
- 🐛 = an existing fix-listed gap from `README.md` / architecture audit

---

## Phase 0 — Repo Hygiene & Secrets (Do first, ~½ day)

| Step | Task | Why / Acceptance |
|---|---|---|
| 0.1 | Add **`.gitignore`** at repo root (`vendor/`, `node_modules/`, `dist/`, `.env`, `.DS_Store`, `*.log`) | No secrets/logs in VCS |
| 0.2 | Create **`backend/.env.example`** with blank placeholders + comments for every var in `.env` | Onboarding without leaking creds |
| 0.3 | **Rotate** the live-looking Supabase credentials + `JWT_SECRET` + `SYNC_ENCRYPTION_KEY` in `.env`; commit only `.env.example` | Credential exposure from current plaintext `.env` |
| 0.4 | Create `frontend/README.md` (referenced but missing from main README) and wire it together | Docs consistency |
| 0.5 | `composer install` in `backend/`; confirm Slim/PHP-DI/firebase-jwt actually resolve; `composer start` smoke test | Verifies the declared stack runs |

---

## Phase A — Production Backend Hardening (~2–3 days)

### A1. Server-side recurring scheduler 🐛
- Add migration `003_recurring_job_log.sql` (idempotent job log: `job_id`, `run_date`, `user_id`, `status`, `created_at`).
- New CLI entry `backend/bin/recurring.php` calling `RecurringTransactionService` for all due `is_active` templates with `next_run_date <= today`, guarded so concurrent cron invocations can't double-generate (locking row / advisory lock).
- Wire a cron (or Supabase pg_cron / Edge Function) to run **daily at 00:05**.
- **AC:** no UI action required for recurring bills to materialize; `generate-due` endpoint stays as a manual fallback.

### A2. Sync tombstones for all entity types 🐛
- Add `deleted_at TIMESTAMPTZ NULL` + partial index to `accounts`, `categories`, `recurring_transactions`, `budgets`, `what_if_scenarios` (`003_*.sql`).
- `SyncController::pull` returns `operation='delete'` rows referencing the tombstone so clients call `deleteRecord(store, id)` (update `mapEntityToStore` + `pullChanges` in `sync.js`).
- **AC:** delete an account on device A → device B removes its cached copy on next pull (no resurrection).

### A3. Validation service (respect/validation) 🐛
- Extract inline controller checks into a `Validator` service, or start using the declared `respect/validation` package.
- Add missing rules: **transfer self-transfer rejection**, `transfer_to_account_id` must be owned by the same `user_id`, category type must match transaction type, budget `category_id` XOR `group_label`.
- **AC:** 422 responses with structured error `details` array, consistently shaped.

### A4. Rate limiting & brute-force protection
- Middleware on `/auth/*` (per-IP + per-email) using a `login_attempts` table or in-memory store; exponential backoff after N failures.
- **AC:** register/login route rejects burst attempts (429) without locking out real users.

### A5. Refresh-token revocation housekeeping
- Delete expired `refresh_tokens` via daily maintenance job; revoke on logout endpoint (`POST /auth/logout`) if added.
- **AC:** token table doesn't grow unbounded; logout invalidates the opaque token.

---

## Phase B — Conflict Resolution & Sync Robustness (~2–3 days)

| Step | Task | Detail / AC |
|---|---|---|
| B1 | **Field-level merge** (upgrade CRDT-lite) | Store LWW per field by `client_timestamp`; on pull re-apply only newer fields. Extend `sync_changes` with `field_mask JSONB` or split payload into per-field ops. |
| B2 | **Resync endpoint** | `POST /sync/reset` → server returns full snapshot of owned rows; client rebuilds IndexedDB. Handles missed tombstones/cursor drift. |
| B3 | **Pull pagination/backpressure** | Cursor-based pagination beyond current `LIMIT 1000`; client loops until `server_time` reached. |
| B4 | **Sync integrity check** | Client verifies `entity_version` before overwrite; detect & surface conflicts (UI badge: "conflict — resolved by latest edit"). |
| B5 | **Conflict strategy documentation + tests** | Document LWW/merge semantics in `docs/`; add test cases for same-entity concurrent edit from two devices. |

**AC:** two devices editing the same budget limit no longer silently drop one edit (field-merge or explicit conflict UI).

---

## Phase C — Automated Testing & CI (~3–4 days)

| Step | Task | Detail / AC |
|---|---|---|
| C1 | **PHPUnit** + `phpunit.xml` | Unit: JwtService, DynamicAllocationEngine, SyncEncryptionService, RecurringTransactionService. Integration: in-memory / Supabase-local test DB, spin Slim `App` with a test container. |
| C2 | **API contract tests** | Exercise all 31 routes: happy-path + 401/404/422 + business rules (A3). |
| C3 | **Frontend tests** | Web Test Runner + `@web/test-runner` (or Vitest): `sync.js` outbox/pull flow with mocked `api` + fake-indexeddb; Lit component render tests for `app-transactions`, `app-budgets`. |
| C4 | **CI pipeline** | GitHub Actions (or GitLab CI): lint (`php -l`), PHPUnit, frontend test + `npm run build` per push; gating merge. |

---

## Phase D — Deployment & Ops (~2–3 days)

| Step | Task | Detail / AC |
|---|---|---|
| D1 | **Docker build backend** | `Dockerfile` (php:8.2-fpm, `pdo_pgsql`, composer install --no-dev --optimize-autoloader) + `docker-compose.yml` (app + nginx + optional MySQL for self-host) . |
| D2 | **Frontend deploy** | `npm run build` → serve `dist/` statically (Nginx/CDN/Vercel/Netlify), PWA manifest icons + service-worker precache updated. |
| D3 | **Env sealing** | Supabase `connection_pooler` + project password rotation; kill anon key exposure; confirm RLS blocks PostgREST. CORS restricted to real origin. |
| D4 | **Observability** | Monolog file/JSON logs; error handler redacts PII; alerting on 5xx & failed sync rate; DB monitoring (Supabase dashboard). |
| D5 | **Backups & DR** | Supabase PITR + daily pg_dump to object storage; documented restore runbook. |
| D6 | **Load/stress sanity** | `ab`/k6 on read-heavy GET endpoints (net-worth, dashboard aggregate) with realistic JWT traffic; tune indexes if needed. |

---

## Phase E — Product Growth (as roadmap backlog)

| Step | Task | Notes |
|---|---|---|
| E1 | **Multi-currency** | Move `currency` onto transactions/settings; FX rates service; per-account display currency; Net Worth in base currency. Requires schema evolution (003+) + report GROUP BY currency. |
| E2 | **Recurring transfer support** | `recurring_transactions` already allows `type='transfer'`; complete UX + tests. |
| E3 | **Excel/CSV export & import** | Analytics export (`reports/export`), CSV import with `client_uuid` idempotency. |
| E4 | **Push notifications for budget alerts** | Threshold 80/100% → Web Push (offline-capable via service worker) instead of toast-only. |
| E5 | **Envelope-to-account link** | Auto-move/sweep envelope surplus to savings account (ties E3 + DynamicAllocationEngine). |
| E6 | **Simulator enhancements** | Monte-Carlo variance, scenario comparison (A/B side-by-side), inflation slider; store assumptions as JSONB (already modeled). |
| E7 | **Insights AI layer** | Extend `journalInsights` with trend deltas & regression (stress_corr), not just weekend/high-stress heuristic. |
| E8 | **i18n (EN)** | UI strings extracted; locale-based formatting; RTL not needed. |

---

## Execution Order Summary

```
Phase 0  ▸ secrets & hygiene            (½ day)   ← DO FIRST
Phase A  ▸ backend hardening            2–3 days  ← unblocks cron + sync integrity
Phase B  ▸ conflict resolution          2–3 days  ← unblocks multi-device reliability
Phase C  ▸ tests + CI                   3–4 days  ← unblocks safe refactor/deploy
Phase D  ▸ deployment & ops             2–3 days  ← maybe first in parallel if demo needed
Phase E  ▸ growth backlog               ongoing
```

**Fastest path to production demo (alt ordering):** 0 → A1+A2 → D (deploy) → C, then B/E. Each phase is independently shippable; D1–D3 unblock a public demo quickly.