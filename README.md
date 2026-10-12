# Label Inspection — Frontend (Laravel)

Aplikasi frontend **Label Inspection System**: capture label kemasan via kamera
(FRONT + BACK), auto-crop split 2 label per sisi, OCR teks per label via layanan
eksternal FastAPI, lalu compare hasil Check terhadap Reference (acuan).

> Repo ini hanya berisi **kode aplikasi**. Dokumen perencanaan internal
> (`planning/*.md`, roadmap checkpoint) sengaja tidak di-push — pelacakan
> tugas berjalan lewat **GitHub Issues** di repo ini.

---

## 1. Tech Stack

| Lapisan   | Teknologi (terverifikasi dari kode)                          |
|-----------|--------------------------------------------------------------|
| Backend   | Laravel `^8.75`, PHP `^7.3\|^8.0` (disarankan PHP 8.0), Guzzle 7 |
| Auth      | Laravel Sanctum `^2.11`, token Bearer di `sessionStorage`    |
| Database  | MySQL, database `label_inspection`                           |
| Frontend  | Blade templates + Tailwind CSS via CDN + **Vanilla JS only** |
| JS runtime| `public/js/*.js` dimuat langsung via `<script>` (bukan Mix)  |
| Asset build| Laravel Mix 6 (opsional, lihat peringatan §8)               |
| OCR/AI    | Layanan eksternal FastAPI di `127.0.0.1:9000` (terpisah)     |
| Env lokal | Laragon (Windows), `php artisan serve` → `http://localhost:8000` |

Aturan arsitektur yang dijaga: **tanpa framework JS** (no React/Vue/Alpine/jQuery),
**tanpa CSS framework selain Tailwind CDN**, semua proses berat (kamera, crop,
render OCR) berjalan **client-side**.

---

## 2. Alur & Halaman

| Route         | Halaman   | Fungsi |
|---------------|-----------|--------|
| `/`           | Landing   | Halaman publik |
| `/login`      | Sign In   | Login **NIK Karyawan 9 digit** (tanpa password), tema biru `#0284C7` |
| `/dashboard`  | Dashboard | 2 kartu menu: Reference & Checking |
| `/reference`  | Reference | Scan dual FRONT → BACK, rotate manual, Simpan → auto-crop split 2 label/sisi → OCR ganda → kelola Choose/library |
| `/check`      | Check     | 2 sesi (Sesi 1 FRONT, Sesi 2 BACK), split-OCR per label, Process → Process Result 4 capture, compare-similarity |

**Login:** cukup kirim NIK 9 digit ke `POST /api/login` → terima `{ token, user }`.
Token Sanctum berlaku **60 menit**; token lama otomatis dihapus setiap login baru.
Semua halaman privat wajib memanggil `App.protectPage()` (cek `GET /api/user`,
redirect ke `/login` bila 401).

**Akun test (seeder, DEV ONLY):**

| NIK         | Nama          |
|-------------|---------------|
| `000000000` | Dev Operator  |
| `000000001` | Admin         |
| `000000002` | Operator QC 1 |

---

## 3. Syarat (Prerequisites)

- PHP 8.0 (sesuai `composer.json`: `^7.3|^8.0`) + ekstensi standar Laravel 8
- Composer 2
- MySQL/MariaDB (via Laragon atau standalone)
- Node.js + NPM **hanya bila perlu compile asset Mix** (opsional)
- Layanan FastAPI OCR di `http://127.0.0.1:9000` **hanya bila butuh OCR/compare**
  (halaman tetap terbuka tanpanya; request OCR yang akan gagal)

---

## 4. Setup Cepat (Fresh Clone)

```powershell
# 1. Clone & masuk direktori
git clone https://github.com/ajidwi02/label-inspection-frontend.git
cd label-inspection-frontend

# 2. Install dependency PHP
composer install

# 3. Siapkan environment (JANGAN pernah commit file .env)
copy .env.example .env
php artisan key:generate

# 4. Buat database MySQL bernama label_inspection, lalu sesuaikan .env:
#    DB_HOST=127.0.0.1  DB_PORT=3306  DB_DATABASE=label_inspection
#    DB_USERNAME=root   DB_PASSWORD=(password mysql lokalmu)

# 5. Migrasi + seed akun test
php artisan migrate --seed

# 6. Jalankan server
php artisan serve
# Buka http://localhost:8000/login lalu login dengan NIK 000000000
```

Akses dari HP/laptop lain satu jaringan:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

---

## 5. Konfigurasi `.env` Penting

Salin dari `.env.example`. Nilai sensitif **wajib diganti di tiap environment**
dan tidak boleh di-commit (repo ini me-ignore `.env`).

| Key | Kegunaan | Default lokal |
|-----|----------|---------------|
| `DB_DATABASE` | Nama database MySQL | `label_inspection` |
| `TEST_SIGNAL_URL` | Endpoint status FastAPI | `http://127.0.0.1:9000/api/test/status` |
| `OCR_CALLBACK_API_KEY` / `OCR_CALLBACK_SECRET` | Verifikasi webhook `X-API-Key` + HMAC | ganti dengan secret acak |
| `OCR_POLL_TIMEOUT` / `OCR_POLL_INTERVAL` | Polling hasil OCR (OCR bisa 60–90 dtk/label) | `150` / `2` |
| `CHECK_EXTERNAL_URL` / `CHECK_OCR_URL` | Forward capture & OCR Check ke FastAPI | port `9000` |
| `FASTAPI_REF_LIBRARY_URL` | Reference Library permanen (FastAPI) | port `9000` |
| `CHECK_CAPTURE_MAX_SIZE` | Batas upload per capture (KB) | `5120` |

> Detail tiap variabel + komentar ada di `.env.example` — itu satu-satunya
> referensi config yang di-push ke repo.

---

## 6. Struktur Proyek

```
app/Http/Controllers/Api/   Auth, Reference, CheckCapture, CheckSplitOcr,
                            CheckOcrCallback, CheckCompareCallback,
                            OcrCallback, LabelCapture, TestSignal
app/Models/                 User (nik), Reference, ReferenceOcrResult,
                            CheckingSession, CheckCapture, CheckOcrResult, LabelCapture
app/Services/               Guzzle clients ke FastAPI (OCR, external, library)
routes/api.php              endpoint API (auth:sanctum; webhook publik via HMAC)
routes/web.php              5 halaman: /, /login, /dashboard, /reference, /check
resources/views/            Blade: layouts/app + landing/login/dashboard/reference/check
public/js/                  RUNTIME JS (check.js, reference.js, login.js,
                            dashboard.js, app.js) — dimuat langsung, BUKAN Mix
resources/js|css/           SUMBER Mix (jangan disamakan dengan public/js!)
database/migrations/        skema: users(nik), references(+name/session/active/
                            library), checking_sessions, check_captures,
                            check_ocr_results, ref_library, dsb.
tests/                      Example suite (smoke test)
```

### API utama (ringkas)

- `POST /api/login` (rate-limit `throttle:login`, 5/mnt) · `POST /api/logout` · `GET /api/user`
- References: `GET/POST /api/references`, `/latest`, `/session/{key}`, `/batch`, `/activate`, `/import`, `/references-library*`
- Split-OCR Check: `POST /api/check/split-preview` → polling `GET /api/check/split-ocr-status`
- Check sessions: `POST /api/check/captures`, `GET /api/check/sessions`, `.../process`, `.../compare-similarity`, `.../compare-result`
- Webhook **publik** (tanpa Sanctum, verifikasi HMAC): `POST /api/ocr/callback`, `/api/check/ocr/callback`, `/api/check/compare/callback`
- Daftar lengkap + throttle per-route: lihat `routes/api.php`.

---

## 7. Perintah Harian

```powershell
php artisan serve                        # server dev :8000
php artisan migrate                      # migrasi baru
php artisan migrate --seed               # migrasi + akun test
php artisan view:clear                   # wajib tiap ubah Blade/JS
php artisan test --filter=Example        # smoke test (harus 2 passed)
node --check public/js/check.js          # validasi sintaks JS
node --check public/js/reference.js
php -l routes/api.php                    # validasi sintaks PHP
```

Checklist tiap perubahan Blade/JS: sintaks OK → `view:clear` → smoke test
2 passed → grep pastikan ID Blade baru unik → uji manual 360/768/1280px
tanpa scroll-x.

---

## 8. ⚠️ Peringatan Penting

1. **`resources/js/app.js` ≠ `public/js/app.js`.** File sumber Mix (24 byte)
   dan file runtime auth helpers (2,4 KB) hanya kebetulan bernama sama.
   `webpack.mix.js` meng-output ke `public/js` — **jangan jalankan
   `npm run dev/prod` tanpa backup `public/js/`**, hasilnya menimpa JS runtime.
2. **HTTP 429 = rate-limit/throttle, bukan gambar gelap.** Angka throttle di
   `routes/api.php` dan limiter di `RouteServiceProvider` **dilarang diubah**
   tanpa persetujuan owner.
3. **Jangan ubah skema DB** tanpa migration baru + persetujuan; jangan tambah
   package Composer/NPM tanpa persetujuan.
4. Jangan refactor kode yang sudah jalan kecuali diminta eksplisit.

---

## 9. Troubleshooting

| Gejala | Penyebab umum & solusi |
|--------|------------------------|
| `401` lalu dilempar ke `/login` | Token 60 menit kedaluwarsa / belum login → login ulang dengan NIK |
| OCR Front failed + console `429` | Kena throttle/antrean (lihat §8.2), bukan kualitas gambar |
| Halaman berubah tapi tampilan lama | Cache Blade/JS → `php artisan view:clear`; JS memakai `?v=filemtime` otomatis |
| `SQLSTATE` saat migrate | Database `label_inspection` belum dibuat / kredensial `.env` salah |
| OCR/split/compare gagal total | FastAPI `127.0.0.1:9000` tidak jalan (opsional untuk tampilan halaman) |
| `419` / session aneh | Normal — auth memakai token Bearer, bukan cookie session |

---

## 10. Pelacakan Tugas & Keamanan

- Backlog + bug terbuka dilacak di **GitHub Issues** repo ini (sumber:
  roadmap internal `ROADMAP_CHECKPOINT.md` v1.2.8 yang tidak di-push).
- **Tidak di-push ke repo:** `.env`, `planning/*.md`, roadmap checkpoint,
  isi `storage/` (captures/logs), `vendor/`, `node_modules/`.
- Ganti `OCR_CALLBACK_API_KEY/SECRET` di production; webhook callback
  memverifikasi `X-API-Key` + HMAC-SHA256.
- Akun NIK `000000000/1/2` hanya untuk development.

## Lisensi

Framework Laravel berlisensi [MIT](https://opensource.org/licenses/MIT).
Kode aplikasi di repo ini mengikuti lisensi yang sama kecuali dinyatakan lain.
