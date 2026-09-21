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

## Deploy Online Gratis ke Render (tanpa menjalankan Docker/NPM di mesin lokal)

Data sudah di **Supabase (PostgreSQL cloud)**, jadi yang perlu di-host hanyalah backend API
dan file statis frontend. Render (render.com) menarik dari GitHub: setiap `git push` ke `main`
otomatis di-build & di-deploy di cloud — mesin lokal tidak perlu `docker` maupun `npm` lagi.

Arsitektur di Render (semua gratis):
- **Web Service** (backend API; instance Free — tidur setelah 15 menit idle, ~1 menit bangun) → `backend/Dockerfile.web`
- **Static Site** (frontend Lit; CDN, selalu hidup) → build `npm run build`, publish `frontend/dist`

### 1. Push kode
```bash
git add -A && git commit -m "ci: dockerfile.web + health route + gitignore" && git push
```
`backend/.env` kini di-ignore (jangan pernah commit secret — repo public).

### 2. Buat Web Service (backend)
1. render.com → **New → Web Service** → hubungkan repo `titikcareer/siberFinance`.
2. **Root Directory**: `backend` · **Runtime**: `Docker` · **Dockerfile Path**: `Dockerfile.web` · **Instance Type**: `Free`.
3. **Health Check Path**: `/api/v1/health`.
4. **Environment** (isi dari `backend/.env.example` + kredensial Supabase Anda):
   ```
   DB_DRIVER=pgsql
   DB_HOST=aws-0-REGION.pooler.supabase.com
   DB_PORT=5432
   DB_DATABASE=postgres
   DB_USERNAME=postgres.PROJECT_REF
   DB_PASSWORD=YOUR_DB_PASSWORD
   DB_SSLMODE=require
   JWT_SECRET=<openssl rand -base64 32>
   JWT_EXPIRY_SECONDS=3600
   JWT_REFRESH_EXPIRY_SECONDS=1209600
   SYNC_ENCRYPTION_KEY=<openssl rand -base64 32>
   APP_ENV=production
   APP_DEBUG=false
   CORS_ALLOWED_ORIGIN=https://<frontend>.onrender.com   # awali dengan * dulu, ganti setelah frontend jadi
   ```
5. Deploy → catat URL-nya, mis. `https://finance-api.onrender.com`.

### 3. Buat Static Site (frontend)
1. render.com → **New → Static Site** → repo yang sama.
2. **Root Directory**: `frontend` · **Build Command**: `npm run build` · **Publish Directory**: `dist`.
3. **Environment**: `VITE_API_BASE_URL=https://finance-api.onrender.com/api/v1`.
4. Deploy → catat URL-nya, mis. `https://finance-app.onrender.com`.

### 4. Finalisasi CORS
Kembali ke backend → ubah `CORS_ALLOWED_ORIGIN` menjadi `https://finance-app.onrender.com` → redeploy.
Verifikasi:
```bash
curl https://finance-api.onrender.com/api/v1/health        # {"status":"ok",...}
curl https://finance-api.onrender.com/api/v1/accounts      # 401 (harus login)
```
Buka URL frontend → register → login. Selesai.

> Catatan: pada free tier, backend tidur setelah 15 menit tanpa traffic; request pertama
> memakan ~1 menit (Render menampilkan halaman loading). Upgrade ke `Starter` ($7/bulan)
> bila ingin selalu responsif instan.

## Keterbatasan yang Masih Perlu Diperhatikan (bukan bug, tapi catatan produksi)
1. **Scheduler recurring** — saat ini generate dipicu manual/saat dashboard dibuka (client-triggered). Untuk produksi, tambahkan cron job server-side yang memanggil `RecurringTransactionService` harian agar tetap jalan walau user tidak membuka app.
2. **Konsolidasi CRDT** — strategi konflik sync saat ini last-write-wins sederhana berbasis timestamp; untuk skenario multi-device sangat intensif, pertimbangkan vector clock per field.
3. **Validasi form kategori pada Budget** — form "Budget Kategori" di frontend saat ini meminta category_id manual (UUID); untuk UX lebih baik, ganti dengan dropdown yang mengambil dari `api.listCategories()`.
4. **Testing otomatis** — PHPUnit untuk backend dan Web Test Runner/Playwright untuk komponen Lit belum dibuat.
5. **Deployment** — sudah tersedia jalur gratis ke Render (lihat bagian *Deploy Online Gratis ke Render* di atas). Opsi Docker Compose (PHP-FPM + Nginx) tetap tersedia di repo untuk self-host di VPS bila diperlukan.
