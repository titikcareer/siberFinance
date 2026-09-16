# Backend - Personal Finance Management API (Slim Framework)

## Struktur Folder
```
backend/
├── public/index.php          # Entry point, bootstrap Slim + DI + middleware global
├── src/
│   ├── Config/
│   │   ├── Database.php      # PDO singleton
│   │   └── dependencies.php  # DI container (PHP-DI)
│   ├── Controllers/          # Semua HTTP handler (1 controller = 1 modul fitur)
│   ├── Middleware/
│   │   └── JwtAuthMiddleware.php
│   ├── Services/              # Business logic murni (JWT, Audit, Sync Encryption, Dynamic Allocation)
│   ├── Support/               # Helper (JsonResponse)
│   ├── Database/migrations/   # File SQL skema database
│   └── Routes/api.php         # Semua definisi endpoint
├── composer.json
└── .env.example
```

## Database: Supabase (PostgreSQL)

Skema database dibuat di **Supabase SQL Editor**, bukan lewat `composer install` / migration tool.
File: `src/Database/migrations/002_supabase_postgres_schema.sql`

```bash
cp .env.example .env      # lalu isi kredensial koneksi Supabase & JWT_SECRET
composer install          # butuh ekstensi PHP: pdo_pgsql (lihat catatan di bawah)
composer start             # jalan di http://localhost:8080
```

> File `001_init_schema.sql` (MySQL) masih disertakan sebagai referensi/opsi jika suatu saat Anda ingin
> self-host MySQL, tapi **query di seluruh controller & service saat ini sudah ditulis dalam sintaks
> PostgreSQL** (TO_CHAR, INTERVAL, ON CONFLICT, dll) mengikuti skema `002_supabase_postgres_schema.sql`.
> Jadi untuk menjalankan aplikasi ini, gunakan skema `002` + Supabase/PostgreSQL.

### Ekstensi PHP yang dibutuhkan
Pastikan `pdo_pgsql` sudah aktif di instalasi PHP Anda:
```bash
php -m | grep pgsql          # harus muncul: pdo_pgsql, pgsql
# Jika belum ada (Ubuntu/Debian):
sudo apt install php-pgsql
```

## Daftar Endpoint Utama

| Method | Endpoint | Keterangan |
|---|---|---|
| POST | /api/v1/auth/register | Registrasi user |
| POST | /api/v1/auth/login | Login -> access_token + refresh_token |
| POST | /api/v1/auth/refresh | Perbarui access_token |
| GET | /api/v1/auth/me | Profil user login |
| GET/POST | /api/v1/accounts | List / buat akun (bank, cash, ewallet, investment, liability) |
| PUT/DELETE | /api/v1/accounts/{id} | Update / arsip akun |
| GET | /api/v1/accounts/net-worth | Ringkasan Net Worth |
| GET/POST | /api/v1/transactions | List / catat transaksi (income/expense/transfer) |
| DELETE | /api/v1/transactions/{id} | Hapus (reverse efek saldo) |
| POST | /api/v1/budgets | Buat budget (envelope / 50-30-20) |
| GET | /api/v1/budgets/status | Status pemakaian & alert 80% / 100% |
| GET | /api/v1/reports/cashflow | Cashflow bulanan/tahunan |
| GET | /api/v1/reports/expense-breakdown | Data untuk pie/bar chart |
| GET | /api/v1/reports/journal-insights | Korelasi stres/konteks vs pengeluaran |
| POST | /api/v1/simulator/run | **What-If Simulator**: proyeksi hingga 60 bulan |
| POST | /api/v1/budgets/{id}/recalculate-allocation | **Dynamic Allocation Engine** |
| POST /GET | /api/v1/sync/push /pull | **Offline-First Differential Sync** |

Semua endpoint (kecuali `/auth/*` publik) wajib header:
```
Authorization: Bearer <access_token>
```

## Catatan Keamanan
- Password di-hash dengan bcrypt (`password_hash`).
- Semua perubahan data penting dicatat ke tabel `audit_logs` via `AuditLogger`.
- Payload sync (`sync_changes.payload_encrypted`) dienkripsi AES-256-GCM sebelum disimpan.
- Query selalu prepared statement (PDO) untuk mencegah SQL Injection.
