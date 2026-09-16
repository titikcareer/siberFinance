# Deployment Docker — Personal Finance App (Backend Slim → Supabase)

Bundle ini memungkinkan menjalankan **satu perintah** di mesin mana pun yang:
- Punya **Docker + Docker Compose**,
- Bisa akses **outbound keluar port 5432/6543** ke `*.supabase.com`.

> ⚠️ **Penting:** jika host Anda hanya mengizinkan egress HTTP(S) (80/443), PHP-FPM
> tidak akan bisa connect ke Supabase (koneksi TCP terbuka tapi handshake Postgres
> dibuang). Jalankan compose ini dari laptop/office/VPS yang egress 5432 terbuka.

## Struktur

```
docker-compose.yml            # 2 service: app + web
backend/Dockerfile            # php:8.2-fpm + pdo_pgsql + composer (no-dev)
backend/.env.example          # template konfigurasi (salin ke .env)
frontend/Dockerfile           # build Lit/Vite (node:20) -> nginx:alpine
frontend/nginx.conf           # SPA fallback + proxy /api -> php-fpm (app:9000)
```

## 1. Siapkan `.env` backend

```bash
cd backend
cp .env.example .env
nano .env     # isi DB_*, JWT_SECRET, SYNC_ENCRYPTION_KEY (lihat file)
```

Generate kunci:
```bash
openssl rand -base64 32        # untuk JWT_SECRET dan SYNC_ENCRYPTION_KEY
```

> **Security:** `.env` berisi secret — pastikan masuk `.gitignore` dan TIDAK ikut
> ke image (Dockerfile sengaja tidak menyalin `.env`; nilainya hanya lewat `env_file`).
> Jika `.env` lama pernah ter-expose (mis. di sandbox), **rotate kredensial Supabase**.

## 2. Jalankan

```bash
docker compose up -d --build
```

- `docker compose up -d`   → build & start
- `docker compose logs -f app` → melihat log backend
- `docker compose down`     → stop (data client ada di IndexedDB browser, bukan server)

## 3. Verifikasi

| Pemeriksaan | Cara |
|---|---|
| Frontend | Buka `http://localhost:8080` → halaman login muncul |
| API langsung | `curl -s http://localhost:8080/api/v1/accounts` → 401 (harus login) |
| Register | `curl -X POST http://localhost:8080/api/v1/auth/register -H 'Content-Type: application/json' -d '{"name":"T","email":"t@t.com","password":"rahasia123"}'` → 201 |
| Login | `curl -X POST http://localhost:8080/api/v1/auth/login ...` → `access_token` + `refresh_token` |

## 4. Catatan jaringan & firewall

Blokir egress di lingkungan pengembangan bukan berarti gagal di lapangan uji.
Jika host dev Anda (mis. sandbox / ISP DPI) memblokir protokol Postgres, deploy ke
host yang egress-nya normal (VPS/cloud/laptop office). Setelah jalan, uji:

```bash
nc -vz aws-0-ap-south-1.pooler.supabase.com 5432   # harus: connected
```

## 5. Hardening lanjutan (opsional)

- Ganti default port `8080` (mis. `"8443:80"` + TLS di depan nginx, atau pasang `nginx-proxy`/Caddy).
- Set `APP_DEBUG=false` di `.env` saat produksi agar error detail tidak bocor.
- Batasi `CORS_ALLOWED_ORIGIN` ke origin nyata (bila frontend & backend beda origin).
- Tambah cron scheduler recurring (lihat `docs/03-IMPLEMENTATION-ROADMAP.md` Phase A) — saat offline, generate tagihan belum berjalan.
- Supabase: aktifkan PITR + backup harian.

## Troubleshooting singkat

| Gejala | Kemungkinan penyebab / solusi |
|---|---|
| App "Database connection failed: timeout" | Egress 5432 diblokir host → jalankan di host lain, atau test `nc -vz` seperti di atas |
| `postgres... password authentication failed` | Kredensial di `.env` salah / sudah dirotasi |
| Halaman index.html muncul, `/api/*` 404 | nginx `location` tidak match; pastikan request memakai `/api/` (ada slash) |
| Frontend build gagal "network" | Docker host butuh akses ke registry npm (registry.npmjs.org:443) & packagist (443) hanya saat build pertama |