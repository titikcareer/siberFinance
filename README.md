# Personal Finance Management App

Full-stack: **Slim Framework 4 (PHP)** sebagai backend API, **Lit (lit.dev)** sebagai frontend Web Components dengan arsitektur **Offline-First** (IndexedDB).

## Struktur Repo
```
finance-app/
├── backend/     -> lihat backend/README.md
└── frontend/    -> lihat frontend/README.md
```

## Status Implementasi (Phase 2 — Lengkap)

### ✅ Core MVP — SEMUA POIN TERPENUHI
| Fitur | Backend | Frontend |
|---|---|---|
| Auth (register/login/refresh, JWT) | ✅ | ✅ |
| Multi-akun (bank/cash/e-wallet/investasi/liability) | ✅ CRUD | ✅ Halaman `app-accounts` |
| Multi-kategori (income/expense/transfer) | ✅ CRUD (baru) | ✅ Halaman `app-categories` (baru) |
| Transaksi (income/expense/transfer) | ✅ | ✅ |
| **Recurring transactions** | ✅ CRUD + `RecurringTransactionService` — auto-generate, menangani tagihan yang terlewat (baru) | ✅ Halaman `app-recurring` + auto-trigger saat buka Dashboard (baru) |
| Net Worth | ✅ | ✅ |
| **Budget Envelope System** | ✅ CRUD penuh (index/update/delete ditambahkan) | ✅ Form buat budget per kategori |
| **Budget Rule 50/30/20** | ✅ endpoint `apply-50-30-20`, auto-hitung dari rata-rata income 3 bulan (baru) | ✅ Tombol "Auto Rule 50/30/20" (baru) |
| Alert threshold 80%/100% | ✅ | ✅ Progress bar + **toast notification otomatis** di Dashboard (baru) |
| Cashflow bulanan/tahunan | ✅ | ✅ **Chart bar di Dashboard** (baru) |
| Breakdown pengeluaran visual | ✅ | ✅ Doughnut chart |
| **Audit trail log** | ✅ dicatat di semua create/update/delete | ✅ **Halaman viewer `app-audit-log`** (baru) |

### ✅ Fitur Inovatif — SEMUA POIN TERPENUHI
| Fitur | Backend | Frontend |
|---|---|---|
| Predictive What-If Simulator | ✅ `/simulator/run` | ✅ UI + Chart.js proyeksi 60 bulan |
| Contextual Expense Micro-Journaling | ✅ tabel `transaction_contexts` + endpoint insight | ✅ Form konteks di transaksi + **kartu insight di Dashboard** (baru) |
| Micro-Budgeting Dynamic Allocation Engine | ✅ `DynamicAllocationEngine` | ✅ **Visualisasi bar chart alokasi harian** di halaman Budgeting (baru) |
| Offline-First Sync (Lit + IndexedDB ↔ Slim differential sync) | ✅ AES-256-GCM | ✅ `SyncEngine` + outbox pattern + fallback baca cache saat offline |

Semua file `.php` sudah lolos `php -l` (syntax check) dan seluruh frontend sudah lolos `npm run build` tanpa error, di setiap iterasi.

## Peta Halaman Frontend
| Halaman | Komponen | Fungsi |
|---|---|---|
| Dashboard | `app-dashboard.js` | Net worth, status budget + toast alert, breakdown pengeluaran, cashflow chart, insight micro-journaling |
| Transaksi | `app-transactions.js` | CRUD transaksi + form konteks micro-journaling + offline-first outbox |
| Akun & Aset | `app-accounts.js` | CRUD akun bank/cash/ewallet/investasi/liability |
| Kategori | `app-categories.js` | CRUD kategori income/expense/transfer |
| Budgeting | `app-budgets.js` | Envelope budget, Rule 50/30/20, status threshold, visualisasi Dynamic Allocation Engine |
| Berulang | `app-recurring.js` | CRUD template recurring + generate manual |
| What-If Simulator | `app-simulator.js` | Simulasi skenario keuangan hingga 60 bulan |
| Audit Log | `app-audit-log.js` | Riwayat seluruh perubahan data |

## Menjalankan Secara Lokal

**Backend:**
```bash
cd backend
cp .env.example .env   # sesuaikan DB & JWT_SECRET
composer install
mysql -u root -p finance_app < src/Database/migrations/001_init_schema.sql
composer start          # http://localhost:8080
```

**Frontend:**
```bash
cd frontend
npm install
npm run dev             # http://localhost:5173
```

> Catatan: `npm install` & `npm run build` sudah diverifikasi berhasil berulang kali di lingkungan pengembangan ini.
> `composer install` perlu dijalankan di mesin Anda sendiri (sandbox pengembangan ini tidak memiliki akses ke Packagist), namun seluruh file `.php` sudah lolos `php -l` di setiap iterasi perubahan.

## Keterbatasan yang Masih Perlu Diperhatikan (bukan bug, tapi catatan produksi)
1. **Scheduler recurring** — saat ini generate dipicu manual/saat dashboard dibuka (client-triggered). Untuk produksi, tambahkan cron job server-side yang memanggil `RecurringTransactionService` harian agar tetap jalan walau user tidak membuka app.
2. **Konsolidasi CRDT** — strategi konflik sync saat ini last-write-wins sederhana berbasis timestamp; untuk skenario multi-device sangat intensif, pertimbangkan vector clock per field.
3. **Validasi form kategori pada Budget** — form "Budget Kategori" di frontend saat ini meminta category_id manual (UUID); untuk UX lebih baik, ganti dengan dropdown yang mengambil dari `api.listCategories()`.
4. **Testing otomatis** — PHPUnit untuk backend dan Web Test Runner/Playwright untuk komponen Lit belum dibuat.
5. **Deployment** — containerize dengan Docker Compose (PHP-FPM + Nginx + MySQL) untuk kemudahan onboarding tim.
