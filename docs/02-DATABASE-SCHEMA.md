# Database Schema — Personal Finance App (PostgreSQL 15+ / Supabase)

> Lived from `backend/src/Database/migrations/002_supabase_postgres_schema.sql` (active). The MySQL `001_init_schema.sql` is retained only as a self-host reference; controllers use Postgres syntax (`TO_CHAR`, `INTERVAL`, `ON CONFLICT`).

## ER Overview

```
users ─┬─< refresh_tokens
       ├─< accounts ─< transactions ─< transaction_contexts
       ├─< categories (self-REF parent_id)
       ├─< recurring_transactions
       ├─< budgets ─< budget_daily_allocations
       ├─< what_if_scenarios
       ├─< audit_logs (user_id SET NULL on delete)
       └─< sync_changes

transactions ── REF accounts (account_id, transfer_to_account_id)
             ── REF categories (category_id, SET NULL)
             ── REF recurring_transactions (recurring_id, SET NULL)
budgets ────── REF categories (category_id, CASCADE)  + group_label (needs|wants|savings)
```

## Conventions

- **PKs:** `UUID DEFAULT gen_random_uuid()` (from `pgcrypto`)
- **Booleans over ENUMs:** `TEXT + CHECK` everywhere, for `ALTER CHECK`-level evolution
- **Timestamps:** `TIMESTAMPTZ`, `now()` defaults; shared `set_updated_at()` trigger keeps `updated_at` fresh
- **Money:** `NUMERIC(18,2)`; amounts `CHECK (amount > 0)` on transactions
- **Row-Level Security:** enabled on all tables, zero policies (owner role `postgres` bypasses; anon/authenticated blocked)

---

## 1. `users`

| Column | Type | Constraints |
|---|---|---|
| id | UUID | PK `DEFAULT gen_random_uuid()` |
| name | VARCHAR(120) | NOT NULL |
| email | VARCHAR(180) | NOT NULL, UNIQUE |
| password_hash | VARCHAR(255) | NOT NULL (bcrypt) |
| base_currency | VARCHAR(3) | NOT NULL DEFAULT 'IDR' |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() |
| updated_at | TIMESTAMPTZ | NOT NULL DEFAULT now(), trigger-updated |

**Purpose:** app users & credential store. `AuditLogger` sets `user_id=NULL` on delete (FK `SET NULL`) to preserve audit permanence.

## 2. `refresh_tokens`

| Column | Type | Constraints |
|---|---|---|
| id | UUID | PK |
| user_id | UUID | NOT NULL, FK→users ON DELETE CASCADE |
| token_hash | VARCHAR(255) | NOT NULL (SHA-256 of opaque token) |
| expires_at | TIMESTAMPTZ | NOT NULL |
| revoked | BOOLEAN | NOT NULL DEFAULT false |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() |

**Index:** `idx_refresh_tokens_hash (token_hash)` — fast lookup on refresh/revoke.
**Security:** server stores only the hash, never the raw token; rotation issues a new token each refresh.

## 3. `accounts`

| Column | Type | Constraints |
|---|---|---|
| id | UUID | PK |
| user_id | UUID | NOT NULL, FK→users ON DELETE CASCADE |
| name | VARCHAR(120) | NOT NULL |
| type | TEXT | NOT NULL, CHECK in (`bank`,`cash`,`ewallet`,`investment`,`liability`) |
| balance | NUMERIC(18,2) | NOT NULL DEFAULT 0 |
| currency | VARCHAR(3) | NOT NULL DEFAULT 'IDR' |
| is_archived | BOOLEAN | NOT NULL DEFAULT false |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() |
| updated_at | TIMESTAMPTZ | NOT NULL DEFAULT now(), trigger |

**Index:** `idx_accounts_user (user_id) WHERE is_archived = false`.
**Notes:** delete = soft archive to keep transaction history consistent. Net Worth = Σ(assets) − Σ(liability).

## 4. `categories`

| Column | Type | Constraints |
|---|---|---|
| id | UUID | PK |
| user_id | UUID | NOT NULL, FK→users ON DELETE CASCADE |
| name | VARCHAR(80) | NOT NULL |
| type | TEXT | NOT NULL, CHECK in (`income`,`expense`,`transfer`) |
| parent_id | UUID | NULL, FK→categories ON DELETE SET NULL (hierarchy) |
| icon | VARCHAR(40) | NULL |
| color | VARCHAR(20) | NULL |
| is_archived | BOOLEAN | NOT NULL DEFAULT false |

**Index:** `idx_categories_user (user_id) WHERE is_archived = false`.
**Notes:** supports hierarchical (parent/child) categorization; used by budget envelope targeting and breakdown charts.

## 5. `recurring_transactions`

| Column | Type | Constraints |
|---|---|---|
| id | UUID | PK |
| user_id | UUID | NOT NULL, FK→users ON DELETE CASCADE |
| account_id | UUID | NOT NULL, FK→accounts ON DELETE CASCADE |
| category_id | UUID | NULL, FK→categories ON DELETE SET NULL |
| type | TEXT | NOT NULL, CHECK in (`income`,`expense`,`transfer`) |
| amount | NUMERIC(18,2) | NOT NULL |
| description | VARCHAR(255) | NULL |
| frequency | TEXT | NOT NULL, CHECK in (`daily`,`weekly`,`monthly`,`yearly`) |
| interval_count | INT | NOT NULL DEFAULT 1 |
| start_date | DATE | NOT NULL |
| end_date | DATE | NULL |
| next_run_date | DATE | NOT NULL |
| is_active | BOOLEAN | NOT NULL DEFAULT true |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() |

**Index:** `idx_recurring_due (user_id, next_run_date) WHERE is_active = true` — efficient "what's due today" scan for `RecurringTransactionService`.

## 6. `transactions`

| Column | Type | Constraints |
|---|---|---|
| id | UUID | PK |
| user_id | UUID | NOT NULL, FK→users ON DELETE CASCADE |
| account_id | UUID | NOT NULL, FK→accounts ON DELETE CASCADE |
| category_id | UUID | NULL, FK→categories ON DELETE SET NULL |
| type | TEXT | NOT NULL, CHECK in (`income`,`expense`,`transfer`) |
| amount | NUMERIC(18,2) | NOT NULL, CHECK (amount > 0) |
| transfer_to_account_id | UUID | NULL, FK→accounts ON DELETE SET NULL |
| description | VARCHAR(255) | NULL |
| occurred_at | TIMESTAMPTZ | NOT NULL |
| recurring_id | UUID | NULL, FK→recurring_transactions ON DELETE SET NULL |
| client_uuid | UUID | NULL — idempotency key for sync dedup |
| sync_version | INT | NOT NULL DEFAULT 1 |
| deleted_at | TIMESTAMPTZ | NULL — soft-delete tombstone (synced to other devices) |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() |
| updated_at | TIMESTAMPTZ | NOT NULL DEFAULT now(), trigger |

**Indexes:**
- `idx_tx_user_date (user_id, occurred_at) WHERE deleted_at IS NULL` — ledger queries
- `idx_tx_account (account_id)` — account history
- `idx_tx_category (category_id)` — category analytics

**Constraints:** `UNIQUE (user_id, client_uuid)` — the CRDT-lite idempotency guarantee during push.
**Balance effect:** income +, expense −, transfer −/+ applied to `accounts.balance` inside a DB transaction; destroy reverses it then tombstones.

## 7. `transaction_contexts` (Innovative — Micro-Journaling)

| Column | Type | Constraints |
|---|---|---|
| id | UUID | PK |
| transaction_id | UUID | NOT NULL, FK→transactions ON DELETE CASCADE |
| stress_level | SMALLINT | NULL, CHECK BETWEEN 1 AND 5 |
| urgency_level | SMALLINT | NULL, CHECK BETWEEN 1 AND 5 |
| social_situation | VARCHAR(60) | NULL |
| mood_tag | VARCHAR(60) | NULL |
| note | VARCHAR(500) | NULL |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() |

**Index:** `idx_context_transaction (transaction_id)`.
**Reports use:** `journalInsights` correlates stress≥4 + weekend vs. spend → insight text.

## 8. `budgets`

| Column | Type | Constraints |
|---|---|---|
| id | UUID | PK |
| user_id | UUID | NOT NULL, FK→users ON DELETE CASCADE |
| category_id | UUID | NULL, FK→categories ON DELETE CASCADE |
| group_label | VARCHAR(40) | NULL (`needs`/`wants`/`savings` for 50/30/20) |
| period_month | CHAR(7) | NOT NULL (YYYY-MM) |
| amount_limit | NUMERIC(18,2) | NOT NULL, CHECK (amount_limit > 0) |
| rule_type | TEXT | NOT NULL DEFAULT 'envelope', CHECK in (`envelope`,`50_30_20`,`custom`) |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() |

**Index:** `idx_budget_user_period (user_id, period_month)`.
**Note:** budget is either category-scoped (envelope) or group-scoped (50/30/20), not both.

## 9. `budget_daily_allocations` (Innovative — Dynamic Allocation Engine)

| Column | Type | Constraints |
|---|---|---|
| id | UUID | PK |
| budget_id | UUID | NOT NULL, FK→budgets ON DELETE CASCADE |
| allocation_date | DATE | NOT NULL |
| base_daily_limit | NUMERIC(18,2) | NOT NULL |
| adjusted_daily_limit | NUMERIC(18,2) | NOT NULL |
| spent_amount | NUMERIC(18,2) | NOT NULL DEFAULT 0 |
| carry_over_from_prev | NUMERIC(18,2) | NOT NULL DEFAULT 0 |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() |

**Constraint:** `UNIQUE (budget_id, allocation_date)` — upsert target of the engine's `ON CONFLICT`.
**Notes:** one row per day per budget; adjusted limit = base ± pro-rated carry-over of surplus/defecit to remaining days.

## 10. `what_if_scenarios` (Innovative — Simulator)

| Column | Type | Constraints |
|---|---|---|
| id | UUID | PK |
| user_id | UUID | NOT NULL, FK→users ON DELETE CASCADE |
| name | VARCHAR(120) | NOT NULL |
| assumptions_json | JSONB | NOT NULL |
| projection_json | JSONB | NOT NULL (full 60-month series + runway + baseline) |
| horizon_months | INT | NOT NULL DEFAULT 60 |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() |

**Index:** `idx_whatif_user (user_id, created_at DESC)` — history listing.

## 11. `audit_logs`

| Column | Type | Constraints |
|---|---|---|
| id | UUID | PK |
| user_id | UUID | NULL, FK→users ON DELETE SET NULL |
| action | VARCHAR(60) | NOT NULL (`create`,`update`,`delete`,`login`,`login_failed`) |
| entity_type | VARCHAR(60) | NOT NULL |
| entity_id | UUID | NULL |
| old_value | JSONB | NULL |
| new_value | JSONB | NULL |
| ip_address | VARCHAR(45) | NULL |
| user_agent | VARCHAR(255) | NULL |
| created_at | TIMESTAMPTZ | NOT NULL DEFAULT now() |

**Index:** `idx_audit_user_date (user_id, created_at DESC)`.
**Notes:** append-only accountability log written via `AuditLogger::log()`; `old_value`/`new_value` capture before/after state for human review in `app-audit-log.js`.

## 12. `sync_changes` (Offline-First Differential Sync)

| Column | Type | Constraints |
|---|---|---|
| id | BIGINT | PK, `GENERATED ALWAYS AS IDENTITY` (monotonic cursor for pull) |
| user_id | UUID | NOT NULL, FK→users ON DELETE CASCADE |
| device_id | VARCHAR(80) | NOT NULL |
| entity_type | VARCHAR(40) | NOT NULL |
| entity_id | UUID | NOT NULL |
| operation | TEXT | NOT NULL, CHECK in (`create`,`update`,`delete`) |
| payload_encrypted | TEXT | NOT NULL — AES-256-GCM envelope (base64: IV‖tag‖ciphertext) |
| client_timestamp | TIMESTAMPTZ | NOT NULL |
| server_timestamp | TIMESTAMPTZ | NOT NULL DEFAULT now() |

**Index:** `idx_sync_user_since (user_id, server_timestamp)` — differential pull cursor.
**Notes:** append-only; pull excludes the requesting device (`device_id != :me`), LIMIT 1000. `BIGINT identity` column enables high-watermark `>` cursor queries without clock-skew issues.

---

## Migration & RLS Strategy

- **Apply:** run `002_supabase_postgres_schema.sql` once via Supabase SQL Editor. Idempotent guard: `CREATE EXTENSION IF NOT EXISTS "pgcrypto"; CREATE OR REPLACE FUNCTION set_updated_at()`.
- **Evolve:** forward-only migrations appended (e.g. `003_*.sql`). Prefer `ALTER TABLE … ADD COLUMN`, `ADD CONSTRAINT`, `CREATE INDEX CONCURRENTLY` for non-blocking changes in production.
- **RLS:** tables stay RLS-enabled with no policies → the Slim backend (connecting as table owner `postgres`) transparently bypasses RLS while PostgREST anon/authenticated roles are denied. Do **not** add policies unless you begin issuing PostgreSQL-native auth tokens.
- **Backups:** enable Supabase daily backups + PITR; `pg_dump` before any destructive migration.