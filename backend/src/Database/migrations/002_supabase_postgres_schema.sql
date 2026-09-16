-- ============================================================
-- Personal Finance Management - Schema untuk Supabase (PostgreSQL 15+)
-- Jalankan file ini via Supabase SQL Editor (Project -> SQL Editor -> New query)
-- ============================================================

-- pgcrypto menyediakan gen_random_uuid() untuk primary key UUID
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ------------------------------------------------------------
-- Helper: trigger function generik untuk auto-update kolom updated_at
-- (Postgres tidak punya "ON UPDATE CURRENT_TIMESTAMP" seperti MySQL)
-- ------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ============================================================
CREATE TABLE users (
    id              UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    name            VARCHAR(120)  NOT NULL,
    email           VARCHAR(180)  NOT NULL UNIQUE,
    password_hash   VARCHAR(255)  NOT NULL,
    base_currency   VARCHAR(3)    NOT NULL DEFAULT 'IDR',
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ============================================================
CREATE TABLE refresh_tokens (
    id          UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  TIMESTAMPTZ  NOT NULL,
    revoked     BOOLEAN      NOT NULL DEFAULT false,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX idx_refresh_tokens_hash ON refresh_tokens (token_hash);

-- ============================================================
-- Catatan desain: kolom "type"/"status" dipakai sebagai TEXT + CHECK constraint,
-- bukan native ENUM Postgres. Ini pilihan profesional yang umum karena menambah
-- pilihan baru (mis. tambah tipe akun "crypto") hanya perlu ALTER CHECK,
-- tanpa migrasi ALTER TYPE yang lebih rumit & tidak bisa di-rollback dalam transaksi.
-- ============================================================
CREATE TABLE accounts (
    id             UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id        UUID          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name           VARCHAR(120)  NOT NULL,
    type           TEXT          NOT NULL CHECK (type IN ('bank','cash','ewallet','investment','liability')),
    balance        NUMERIC(18,2) NOT NULL DEFAULT 0,
    currency       VARCHAR(3)    NOT NULL DEFAULT 'IDR',
    is_archived    BOOLEAN       NOT NULL DEFAULT false,
    created_at     TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE TRIGGER trg_accounts_updated_at
    BEFORE UPDATE ON accounts
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE INDEX idx_accounts_user ON accounts (user_id) WHERE is_archived = false;

-- ============================================================
CREATE TABLE categories (
    id          UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id     UUID         NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name        VARCHAR(80)  NOT NULL,
    type        TEXT         NOT NULL CHECK (type IN ('income','expense','transfer')),
    parent_id   UUID         NULL REFERENCES categories(id) ON DELETE SET NULL,
    icon        VARCHAR(40)  NULL,
    color       VARCHAR(20)  NULL,
    is_archived BOOLEAN      NOT NULL DEFAULT false
);

CREATE INDEX idx_categories_user ON categories (user_id) WHERE is_archived = false;

-- ============================================================
CREATE TABLE recurring_transactions (
    id                UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id           UUID          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    account_id        UUID          NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    category_id       UUID          NULL REFERENCES categories(id) ON DELETE SET NULL,
    type              TEXT          NOT NULL CHECK (type IN ('income','expense','transfer')),
    amount            NUMERIC(18,2) NOT NULL,
    description       VARCHAR(255)  NULL,
    frequency         TEXT          NOT NULL CHECK (frequency IN ('daily','weekly','monthly','yearly')),
    interval_count    INT           NOT NULL DEFAULT 1,
    start_date        DATE          NOT NULL,
    end_date          DATE          NULL,
    next_run_date     DATE          NOT NULL,
    is_active         BOOLEAN       NOT NULL DEFAULT true,
    created_at        TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE INDEX idx_recurring_due ON recurring_transactions (user_id, next_run_date) WHERE is_active = true;

-- ============================================================
CREATE TABLE transactions (
    id                     UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id                UUID          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    account_id             UUID          NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    category_id            UUID          NULL REFERENCES categories(id) ON DELETE SET NULL,
    type                   TEXT          NOT NULL CHECK (type IN ('income','expense','transfer')),
    amount                 NUMERIC(18,2) NOT NULL CHECK (amount > 0),
    transfer_to_account_id UUID          NULL REFERENCES accounts(id) ON DELETE SET NULL,
    description            VARCHAR(255)  NULL,
    occurred_at            TIMESTAMPTZ   NOT NULL,
    recurring_id           UUID          NULL REFERENCES recurring_transactions(id) ON DELETE SET NULL,
    client_uuid            UUID          NULL, -- UUID dari client (Lit + IndexedDB) untuk idempotency saat sync
    sync_version            INT          NOT NULL DEFAULT 1,
    deleted_at             TIMESTAMPTZ   NULL, -- Soft delete: tombstone agar bisa di-sync ke client lain
    created_at             TIMESTAMPTZ   NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ   NOT NULL DEFAULT now(),
    UNIQUE (user_id, client_uuid)
);

CREATE TRIGGER trg_transactions_updated_at
    BEFORE UPDATE ON transactions
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE INDEX idx_tx_user_date ON transactions (user_id, occurred_at) WHERE deleted_at IS NULL;
CREATE INDEX idx_tx_account ON transactions (account_id);
CREATE INDEX idx_tx_category ON transactions (category_id);

-- === Fitur Inovatif: Contextual Expense Micro-Journaling ===
CREATE TABLE transaction_contexts (
    id               UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    transaction_id   UUID        NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
    stress_level     SMALLINT    NULL CHECK (stress_level BETWEEN 1 AND 5),
    urgency_level    SMALLINT    NULL CHECK (urgency_level BETWEEN 1 AND 5),
    social_situation VARCHAR(60) NULL,
    mood_tag         VARCHAR(60) NULL,
    note             VARCHAR(500) NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_context_transaction ON transaction_contexts (transaction_id);

-- ============================================================
CREATE TABLE budgets (
    id           UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id      UUID          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category_id  UUID          NULL REFERENCES categories(id) ON DELETE CASCADE,
    group_label  VARCHAR(40)   NULL, -- needs|wants|savings untuk Rule 50/30/20
    period_month CHAR(7)       NOT NULL, -- format YYYY-MM
    amount_limit NUMERIC(18,2) NOT NULL CHECK (amount_limit > 0),
    rule_type    TEXT          NOT NULL DEFAULT 'envelope' CHECK (rule_type IN ('envelope','50_30_20','custom')),
    created_at   TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE INDEX idx_budget_user_period ON budgets (user_id, period_month);

-- === Fitur Inovatif: Micro-Budgeting Dynamic Allocation Engine ===
CREATE TABLE budget_daily_allocations (
    id                    UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    budget_id             UUID          NOT NULL REFERENCES budgets(id) ON DELETE CASCADE,
    allocation_date       DATE          NOT NULL,
    base_daily_limit      NUMERIC(18,2) NOT NULL,
    adjusted_daily_limit  NUMERIC(18,2) NOT NULL,
    spent_amount          NUMERIC(18,2) NOT NULL DEFAULT 0,
    carry_over_from_prev  NUMERIC(18,2) NOT NULL DEFAULT 0,
    created_at             TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (budget_id, allocation_date)
);

-- === Fitur Inovatif: Predictive "What-If" Financial Simulator ===
CREATE TABLE what_if_scenarios (
    id                UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id           UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name              VARCHAR(120) NOT NULL,
    assumptions_json  JSONB       NOT NULL,
    projection_json   JSONB       NOT NULL,
    horizon_months    INT         NOT NULL DEFAULT 60,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_whatif_user ON what_if_scenarios (user_id, created_at DESC);

-- ============================================================
CREATE TABLE audit_logs (
    id           UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id      UUID        NULL REFERENCES users(id) ON DELETE SET NULL,
    action       VARCHAR(60) NOT NULL, -- create|update|delete|login|login_failed
    entity_type  VARCHAR(60) NOT NULL,
    entity_id    UUID        NULL,
    old_value    JSONB       NULL,
    new_value    JSONB       NULL,
    ip_address   VARCHAR(45) NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_audit_user_date ON audit_logs (user_id, created_at DESC);

-- === Offline-First Sync Log (differential sync, append-only) ===
CREATE TABLE sync_changes (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id           UUID        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    device_id         VARCHAR(80) NOT NULL,
    entity_type       VARCHAR(40) NOT NULL,
    entity_id         UUID        NOT NULL,
    operation         TEXT        NOT NULL CHECK (operation IN ('create','update','delete')),
    payload_encrypted TEXT        NOT NULL, -- AES-256-GCM encrypted JSON payload
    client_timestamp  TIMESTAMPTZ NOT NULL,
    server_timestamp  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_sync_user_since ON sync_changes (user_id, server_timestamp);

-- ============================================================
-- Row Level Security (RLS)
--
-- Semua tabel diaktifkan RLS TANPA policy sama sekali. Efeknya:
--   - Akses lewat Supabase API (PostgREST) dengan anon/authenticated key -> DITOLAK TOTAL.
--   - Akses lewat koneksi Postgres langsung sebagai user "postgres" (pemilik tabel) -> TETAP BEBAS,
--     karena pemilik tabel otomatis bypass RLS di PostgreSQL (kecuali FORCE ROW LEVEL SECURITY diaktifkan,
--     yang SENGAJA tidak kita pakai di sini).
--
-- Ini penting karena backend Slim kita melakukan autentikasi & otorisasi sendiri (JWT + WHERE user_id = :uid
-- di setiap query), BUKAN lewat Supabase Auth. Jadi RLS di sini berfungsi sebagai lapisan pertahanan tambahan
-- (defense in depth) supaya database tidak bisa diakses langsung dari frontend meski Project URL & anon key
-- ter-expose, TANPA mengganggu backend yang connect langsung sebagai role "postgres".
-- ============================================================
ALTER TABLE users                      ENABLE ROW LEVEL SECURITY;
ALTER TABLE refresh_tokens             ENABLE ROW LEVEL SECURITY;
ALTER TABLE accounts                   ENABLE ROW LEVEL SECURITY;
ALTER TABLE categories                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE recurring_transactions     ENABLE ROW LEVEL SECURITY;
ALTER TABLE transactions               ENABLE ROW LEVEL SECURITY;
ALTER TABLE transaction_contexts       ENABLE ROW LEVEL SECURITY;
ALTER TABLE budgets                    ENABLE ROW LEVEL SECURITY;
ALTER TABLE budget_daily_allocations   ENABLE ROW LEVEL SECURITY;
ALTER TABLE what_if_scenarios          ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs                 ENABLE ROW LEVEL SECURITY;
ALTER TABLE sync_changes               ENABLE ROW LEVEL SECURITY;
