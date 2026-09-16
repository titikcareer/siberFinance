# Architecture Blueprint — Personal Finance App

> **Status:** Architecture as-built (matches the code in `backend/` and `frontend/`, Phase 2 complete), plus how it is wired for production.

## 1. Design Principles

| Prinsip | Implementasi |
|---|---|
| **Offline-first** | Frontend is the local source of truth: Lit components render from IndexedDB (zero-latency UI), write to an outbox, and reconcile with the server in the background. |
| **Server as validator, not owner** | Slim re-validates every mutation (type/amount/ownership), applies balance effects transactionally, and persists an append-only, AES-256-GCM-encrypted sync log. |
| **CRDT-lite conflict strategy** | Last-write-wins per entity, keyed on `client_timestamp`; balance is NOT derived from sync payloads — server recomputes balances on transaction create/delete so closed books stay consistent. |
| **Defense in depth** | Every data query is scoped `WHERE user_id = :uid`; Row Level Security (RLS) is enabled on all tables WITHOUT policies so Supabase anon/authenticated keys are useless; bcrypt passwords; hashed opaque refresh tokens; full audit trail. |
| **Schema evolution durability** | `TEXT + CHECK` instead of Postgres ENUMs (adding a value is an `ALTER CHECK`, no `ALTER TYPE` / lock-heavy migration). |

## 2. System Context

```
                         ┌─────────────────────────────────────────────┐
                         │                  CLIENT (Browser)            │
┌────────────┐           │  ┌─────────────────────────────────────────┐ │
│   User     │──────────▶│  │  Lit Web Components (11)                │ │
└────────────┘   UI/UX   │  │  app-root → app-shell → 8 feature pages │ │
                         │  └──────────────────┬──────────────────────┘ │
                         │                     │ reads/writes (sync)     │
                         │  ┌──────────────────▼──────────────────────┐ │
                         │  │  IndexedDB (idb)  — local source of truth │ │
                         │  │  stores: accounts, transactions,          │ │
                         │  │  categories, budgets, outbox, meta        │ │
                         │  └──────────────────┬──────────────────────┘ │
                         │                     │ HTTP (fetch)            │
                         │  ┌──────────────────▼──────────────────────┐ │
                         │  │  api.js (REST client + JWT refresh)     │ │
                         │  │  sync.js (SyncEngine: push/pull loop)   │ │
                         │  └─────────────────────────────────────────┘ │
                         └─────────────────────┬─────────────────────────┘
                                              │ HTTPS / JSON, Bearer JWT
                         ┌─────────────────────▼─────────────────────────┐
                         │           BACKEND — Slim Framework 4           │
                         │  ┌──────────────────────────────────────────┐  │
                         │  │ public/index.php → Bootstrap + PHP-DI     │  │
                         │  │ Middleware: CORS, BodyParse, ErrorHandler │  │
                         │  └──────────────────┬───────────────────────┘  │
                         │                     ▼                          │
                         │  ┌──────────────────────────────────────────┐  │
                         │  │ Routes/api.php — 31 endpoints, grouped   │  │
                         │  │  public : /auth/*                       │  │
                         │  │  protected : + JwtAuthMiddleware        │  │
                         │  └──────────────────┬───────────────────────┘  │
                         │                     ▼                          │
                         │  ┌──────────────────────────────────────────┐  │
                         │  │ Controllers (11) — validation + PDO      │  │
                         │  │ Services: Jwt, AuditLogger, SyncEncrypt, │  │
                         │  │   DynamicAllocation, RecurringTx         │  │
                         │  └──────────────────┬───────────────────────┘  │
                         │                     ▼                          │
                         │  ┌──────────────────────────────────────────┐  │
                         │  │   PostgreSQL 15+ (Supabase) — 12 tables   │  │
                         │  │   RLS ON without policies (defense)      │  │
                         │  └──────────────────────────────────────────┘  │
                         └────────────────────────────────────────────────┘
```

## 3. Layer-by-Layer

### 3.1 Frontend (Lit 3 + Vite)

**Responsibilities:** render UI instantly from local cache, capture offline mutations, background-sync.

| Layer | Path | Role |
|---|---|---|
| Entry | `src/main.js` | Registers `<app-root>`, service worker, kicks off `SyncEngine.startBackgroundSync(30_000)` |
| Router/gate | `app-root.js` | Swaps `<app-login>` ⇄ `<app-shell>` on `auth:login` / `auth:logout` |
| Shell | `app-shell.js` | Sidebar nav for 8 pages + logout |
| Feature pages | `app-dashboard.js`, `app-transactions.js`, `app-accounts.js`, `app-categories.js`, `app-budgets.js`, `app-recurring.js`, `app-simulator.js`, `app-audit-log.js` | One component per module; read IndexedDB for instant paint |
| API client | `services/api.js` | `apiFetch` wrapper: Bearer token, single 401→auto-refresh, `isOffline` flag on network failure so callers fall back to IndexedDB |
| Local DB | `services/db.js` | `idb` wrapper, DB `finance-app-db` v1: 6 stores, indexes on `transactions`, outbox |
| Sync | `services/sync.js` | `SyncEngine`: `pushOutbox()` → `api.syncPush` → clear; `pullChanges()` → `api.syncPull(since)` → upsert; device id via `crypto.randomUUID` |
| PWA | `sw.js`, `manifest.json` | App-shell cache; network-first for `/api/*` (offline → 503 JSON), cache-first for the rest |

**Key pattern — offline write path (see `app-transactions.js`):**
```
user action
   → enqueueOutbox(entity_type, entity_id, op, payload, client_timestamp)
   → putRecord('transactions', ...)          // instant local update
   → syncEngine.syncNow()                    // best-effort background push
```

**Key pattern — offline read path (see `app-dashboard.js`):**
```
render() reads getAllRecords('transactions'|'accounts')   ← zero-latency
→ api.netWorth() in parallel; on failure, compute from cache
```

### 3.2 Backend (Slim Framework 4 + PHP-DI)

**Responsibilities:** validation, authorization, balance integrity, analytics, encryption, sync log, audit.

| Layer | Path | Role |
|---|---|---|
| Entry | `public/index.php` | phpdotenv → PHP-DI container → body/RPC → CORS → error middleware → routes |
| DI | `src/Config/dependencies.php` | Binds `JwtService`, `SyncEncryptionService`, `DynamicAllocationEngine`, `RecurringTransactionService`, `JwtAuthMiddleware` |
| DB | `src/Config/Database.php` | PDO singleton; `pgsql:` DSN, `sslmode=require`, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false` |
| Auth middleware | `src/Middleware/JwtAuthMiddleware.php` | Verifies Bearer token; sets `user_id` + claims as request attributes; 401 JSON on failure |
| Routes | `src/Routes/api.php` | 31 endpoints (see §5) |
| Controllers | `src/Controllers/*` | Per-module handlers; rely on `AuditLogger::uuid()` for PKs |
| Services | `src/Services/*` | Pure business logic |

### 3.3 Sync / Offline reconciliation protocol

```
┌─ Client (IndexedDB outbox) ──────────────┐        ┌─ Server (sync_changes) ───────────┐
│ 1. mutation offline → outbox             │        │ 4. decrypt nothing — store token   │
│ 2. [sync] POST /sync/push                │───────▶│    append-only row (AES-256-GCM    │
│    { device_id, changes:[{entity_type,   │        │    encrypted payload)              │
│       entity_id, operation, payload,     │        │ 5. validation/balance effect run  │
│       client_timestamp}] }               │        │    via entity controllers (not    │
│ 3. clear pushed outbox items             │◀───────│    the sync payload itself)       │
│                                          │        │                                    │
│ 6. GET /sync/pull?since&device_id        │───────▶│ 7. rows where server_timestamp >  │
│ 7. upsert payloads into IndexedDB        │◀───────│    since AND device_id != mine    │
│ 8. store lastSyncedAt = server_time      │        │    LIMIT 1000                       │
└──────────────────────────────────────────┘        └────────────────────────────────────┘
```

- **Map payloads → stores:** `account→accounts`, `transaction→transactions`, `budget→budgets`, `category→categories` (`mapEntityToStore`).
- **Echo prevention:** pull excludes the requesting `device_id`.
- **Conflicts:** last-write-wins by `client_timestamp` (documented as CRDT-lite; §7 addresses hardening).

## 4. Security Architecture

| Threat | Mitigation |
|---|---|
| SQL injection | 100% PDO prepared statements, `EMULATE_PREPARES=false` |
| Weak passwords | bcrypt via `password_hash()` (min 8 chars enforced) |
| Token theft | Short-lived access JWT (3600s) + opaque SHA-256-hashed refresh tokens (1.2M s), rotation on refresh |
| Sync payload snooping | AES-256-GCM (`openssl_encrypt`, 12-byte IV, 16-byte tag) — DB operator cannot read plaintext payloads |
| Direct Supabase API access | RLS enabled on ALL 12 tables with zero policies — anon/authenticated keys return nothing |
| Audit / accountability | Every create/update/delete + login logged to `audit_logs` (old/new JSONB, IP, UA) |
| Secrets | `.env` holds `JWT_SECRET`, `SYNC_ENCRYPTION_KEY`, Supabase credentials (⚠ see §8 #1) |

## 5. API Surface (31 endpoints)

**Public — `/api/v1/auth`**
| Method | Endpoint |
|---|---|
| POST | `/auth/register` |
| POST | `/auth/login` |
| POST | `/auth/refresh` |

**Protected — `/api/v1` (JWT)**
| Method | Endpoint |
|---|---|
| GET | `/auth/me` |
| GET · POST | `/accounts` |
| PUT · DELETE | `/accounts/{id}` |
| GET | `/accounts/net-worth` |
| GET · POST | `/categories`, PUT/DELETE `/categories/{id}` |
| GET · POST | `/transactions`, DELETE `/transactions/{id}` |
| GET · POST | `/recurring-transactions`, DELETE `/recurring-transactions/{id}` |
| POST | `/recurring-transactions/generate-due` |
| GET · POST | `/budgets`, PUT/DELETE `/budgets/{id}` |
| GET | `/budgets/status` |
| POST | `/budgets/apply-50-30-20` |
| POST | `/budgets/{budgetId}/recalculate-allocation` |
| GET | `/reports/cashflow` (monthly/yearly) |
| GET | `/reports/expense-breakdown` |
| GET | `/reports/journal-insights` |
| GET | `/audit-logs` |
| POST | `/simulator/run` |
| GET | `/simulator/history` |
| POST | `/sync/push` |
| GET | `/sync/pull` |

**Conventions:** success → `{success:true, data:...}`; error → `{success:false, error:..., details?}`; status codes 201/404/422/500.

## 6. Key Algorithm — Dynamic Allocation Engine (Micro-Budgeting)

`DynamicAllocationEngine::recalculateForBudget` ($ income → daily spend cap):

1. `base_daily_limit = amount_limit / days_in_month`
2. Iterate each day of the month; `adjusted_limit = base + (carryOver / remaining_days_from_today)`
3. For elapsed days, `dailyDelta = adjusted_limit − actual_spent`; today's surplus is deferred to tomorrow
4. Persist `budget_daily_allocations` via `INSERT … ON CONFLICT (budget_id, allocation_date) DO UPDATE`

Flow: user posts → `/budgets/{id}/recalculate-allocation` → returns full 28–31 day array → `app-budgets.js` renders it as a chart.

## 7. Known Architectural Gaps (for the roadmap)

1. **Recurring scheduler is client-triggered** — `generateDue` runs when a client opens the dashboard or taps the button. Production needs a server-side cron (see roadmap Phase A).
2. **LWW conflicts only** — no vector-clock/field-merge; multiple simultaneous devices editing the same budget limit may silently overwrite.
3. **`resync` strategy** — `pull` returns no tombstones for deletion of accounts/categories/recurring templates (only transactions have `deleted_at`). Deleted entities can resurrect on other devices.
4. **Validation depth** — inline in controllers, no `respect/validation` usage yet (declared in composer.json); transfer self-transfer / cross-user `transfer_to_account_id` not explicitly checked.
5. **No automated tests, no CI, no Docker, no config/`.env.example`, no `.gitignore`** — and `backend/vendor` needs `composer install`.
6. **`.env` contains live-looking Supabase credentials in plaintext** — must be rotated + gitignored.
7. **No locale/currency conversions** — single base currency (IDR) per user.