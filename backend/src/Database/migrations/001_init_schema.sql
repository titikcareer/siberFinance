-- ============================================================
-- Personal Finance Management - Initial Schema
-- Target: MySQL 8+ / MariaDB 10.5+
-- ============================================================

CREATE TABLE users (
    id              CHAR(36)      PRIMARY KEY,
    name            VARCHAR(120)  NOT NULL,
    email           VARCHAR(180)  NOT NULL UNIQUE,
    password_hash   VARCHAR(255)  NOT NULL,
    base_currency   VARCHAR(3)    NOT NULL DEFAULT 'IDR',
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE refresh_tokens (
    id          CHAR(36)     PRIMARY KEY,
    user_id     CHAR(36)     NOT NULL,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  DATETIME     NOT NULL,
    revoked     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Bank, Cash, E-Wallet, Investasi, dan akun Liability (hutang/kartu kredit)
CREATE TABLE accounts (
    id             CHAR(36)      PRIMARY KEY,
    user_id        CHAR(36)      NOT NULL,
    name           VARCHAR(120)  NOT NULL,
    type           ENUM('bank','cash','ewallet','investment','liability') NOT NULL,
    balance        DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency       VARCHAR(3)    NOT NULL DEFAULT 'IDR',
    is_archived    TINYINT(1)    NOT NULL DEFAULT 0,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE categories (
    id          CHAR(36)     PRIMARY KEY,
    user_id     CHAR(36)     NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    type        ENUM('income','expense','transfer') NOT NULL,
    parent_id   CHAR(36)     NULL,
    icon        VARCHAR(40)  NULL,
    color       VARCHAR(20)  NULL,
    is_archived TINYINT(1)   NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- Template untuk transaksi berulang (tagihan bulanan, cicilan, dsb)
CREATE TABLE recurring_transactions (
    id                CHAR(36)      PRIMARY KEY,
    user_id           CHAR(36)      NOT NULL,
    account_id        CHAR(36)      NOT NULL,
    category_id       CHAR(36)      NULL,
    type              ENUM('income','expense','transfer') NOT NULL,
    amount            DECIMAL(18,2) NOT NULL,
    description       VARCHAR(255)  NULL,
    frequency         ENUM('daily','weekly','monthly','yearly') NOT NULL,
    interval_count    INT           NOT NULL DEFAULT 1,
    start_date        DATE          NOT NULL,
    end_date          DATE          NULL,
    next_run_date     DATE          NOT NULL,
    is_active         TINYINT(1)    NOT NULL DEFAULT 1,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

CREATE TABLE transactions (
    id                     CHAR(36)      PRIMARY KEY,
    user_id                CHAR(36)      NOT NULL,
    account_id             CHAR(36)      NOT NULL,
    category_id            CHAR(36)      NULL,
    type                   ENUM('income','expense','transfer') NOT NULL,
    amount                 DECIMAL(18,2) NOT NULL,
    transfer_to_account_id CHAR(36)      NULL,
    description            VARCHAR(255)  NULL,
    occurred_at            DATETIME      NOT NULL,
    recurring_id           CHAR(36)      NULL,
    client_uuid            CHAR(36)      NULL COMMENT 'UUID dari client (Lit + IndexedDB) untuk idempotency saat sync',
    sync_version           INT           NOT NULL DEFAULT 1,
    deleted_at             DATETIME      NULL COMMENT 'Soft delete, dibutuhkan agar tombstone bisa di-sync ke client lain',
    created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (transfer_to_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (recurring_id) REFERENCES recurring_transactions(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_client_uuid (user_id, client_uuid)
);

-- === Fitur Inovatif: Contextual Expense Micro-Journaling ===
CREATE TABLE transaction_contexts (
    id               CHAR(36)   PRIMARY KEY,
    transaction_id   CHAR(36)   NOT NULL,
    stress_level     TINYINT    NULL COMMENT '1-5',
    urgency_level    TINYINT    NULL COMMENT '1-5',
    social_situation VARCHAR(60) NULL COMMENT 'sendirian, bersama teman, keluarga, dll',
    mood_tag         VARCHAR(60) NULL,
    note             VARCHAR(500) NULL,
    created_at       DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
);

-- Budgeting: mendukung Envelope System maupun Rule 50/30/20
CREATE TABLE budgets (
    id           CHAR(36)      PRIMARY KEY,
    user_id      CHAR(36)      NOT NULL,
    category_id  CHAR(36)      NULL COMMENT 'NULL = budget agregat (misal grup Needs/Wants/Savings)',
    group_label  VARCHAR(40)   NULL COMMENT 'needs|wants|savings, dipakai untuk Rule 50/30/20',
    period_month CHAR(7)       NOT NULL COMMENT 'format YYYY-MM',
    amount_limit DECIMAL(18,2) NOT NULL,
    rule_type    ENUM('envelope','50_30_20','custom') NOT NULL DEFAULT 'envelope',
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- === Fitur Inovatif: Micro-Budgeting Dynamic Allocation Engine ===
-- Menyimpan snapshot alokasi harian yang sudah disesuaikan (pro-rata surplus/defisit)
CREATE TABLE budget_daily_allocations (
    id              CHAR(36)      PRIMARY KEY,
    budget_id       CHAR(36)      NOT NULL,
    allocation_date DATE          NOT NULL,
    base_daily_limit   DECIMAL(18,2) NOT NULL,
    adjusted_daily_limit DECIMAL(18,2) NOT NULL,
    spent_amount    DECIMAL(18,2) NOT NULL DEFAULT 0,
    carry_over_from_prev DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_budget_date (budget_id, allocation_date)
);

-- === Fitur Inovatif: Predictive "What-If" Financial Simulator ===
CREATE TABLE what_if_scenarios (
    id                CHAR(36) PRIMARY KEY,
    user_id           CHAR(36) NOT NULL,
    name              VARCHAR(120) NOT NULL,
    assumptions_json  JSON     NOT NULL COMMENT 'input user: cicilan baru, kenaikan gaji, dsb',
    projection_json   JSON     NOT NULL COMMENT 'hasil proyeksi 1-60 bulan ke depan',
    horizon_months    INT      NOT NULL DEFAULT 60,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Audit trail untuk keamanan & compliance
CREATE TABLE audit_logs (
    id           CHAR(36)  PRIMARY KEY,
    user_id      CHAR(36)  NULL,
    action       VARCHAR(60) NOT NULL COMMENT 'create|update|delete|login|login_failed',
    entity_type  VARCHAR(60) NOT NULL,
    entity_id    CHAR(36)  NULL,
    old_value    JSON      NULL,
    new_value    JSON      NULL,
    ip_address   VARCHAR(45) NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- === Offline-First Sync Log (differential sync, append-only) ===
-- Setiap perubahan dari client (Lit + IndexedDB) dikirim ke sini sebagai operasi diskrit,
-- server hanya memvalidasi & mengurutkan (last-write-wins per field via updated_at),
-- lalu mengembalikan perubahan lain yang belum dimiliki client (pull).
CREATE TABLE sync_changes (
    id               BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id          CHAR(36)     NOT NULL,
    device_id        VARCHAR(80)  NOT NULL,
    entity_type      VARCHAR(40)  NOT NULL,
    entity_id        CHAR(36)     NOT NULL,
    operation        ENUM('create','update','delete') NOT NULL,
    payload_encrypted MEDIUMTEXT  NOT NULL COMMENT 'AES-256-GCM encrypted JSON payload',
    client_timestamp DATETIME(3)  NOT NULL,
    server_timestamp DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_since (user_id, server_timestamp)
);

CREATE INDEX idx_tx_user_date ON transactions (user_id, occurred_at);
CREATE INDEX idx_tx_account ON transactions (account_id);
CREATE INDEX idx_budget_user_period ON budgets (user_id, period_month);
