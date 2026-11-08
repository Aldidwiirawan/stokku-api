# T-201 — Fondasi API dan format error

## Ringkasan
API StokKu sekarang punya "pintu depan" yang rapi. Semua endpoint berada di bawah `/api/v1/...`, ada endpoint `GET /api/v1/ping` untuk mengecek apakah server bisa dihubungi, dan **setiap error di `/api/*` selalu dikirim sebagai JSON dengan pesan bahasa Indonesia** (401, 403, 404, 422, 429), bukan halaman HTML. Pesan validasi juga sudah berbahasa Indonesia, jadi aplikasi Flutter bisa langsung menampilkannya ke pengguna.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `routes/api.php` | Diubah | Grup `prefix('v1')` + route `GET /ping`; route bawaan `GET /api/user` dihapus (diganti `/auth/me` di T-202) |
| `app/Http/Controllers/PingController.php` | Dibuat | Controller satu aksi yang mengembalikan status API dan waktu server (ISO 8601) |
| `bootstrap/app.php` | Diubah | Tamu tidak diarahkan ke halaman login web; error API dirender jadi JSON Indonesia |
| `lang/id/validation.php` | Dibuat | Pesan validasi Indonesia + nama field (`price` → "harga", dst.) |
| `.env.example` | Diubah | `APP_LOCALE=id` |
| `phpunit.xml` | Diubah | `APP_LOCALE=id` supaya test memakai bahasa yang sama dengan server |
| `tests/Feature/ApiFoundationTest.php` | Dibuat | 9 test: ping, 404 (route & data), 401, 403 (umum & pesan sendiri), 422, 429, halaman web tetap HTML |
| `D:\dev\stokku\docs\TASKS.md` (di luar repo) | Diubah | Keputusan zona waktu dicatat di T-201, T-208, T-209 |

## Konsep yang dipakai

**Versi API (`/api/v1`)**
- Apa itu: nomor versi di alamat API. Kalau suatu hari format respons harus berubah besar, kita bisa membuat `/api/v2` tanpa merusak aplikasi lama yang masih memakai `v1`.
- Kenapa dipakai: aplikasi Android yang sudah terpasang di HP orang tidak bisa dipaksa update. Versi di URL menjaga aplikasi lama tetap jalan.

**Invokable controller**
- Apa itu: controller yang hanya punya satu aksi, ditulis sebagai method `__invoke()`. Di route cukup ditulis `PingController::class` tanpa nama method.
- Kenapa dipakai: ping hanya melakukan satu hal. Memakai controller (bukan closure langsung di `routes/api.php`) juga membuat route bisa di-cache (`php artisan route:cache`) saat produksi.

**API Resource tanpa model (`new JsonResource([...])`)**
- Apa itu: API Resource adalah "pembungkus" yang mengatur bentuk JSON respons. `JsonResource` juga bisa membungkus array biasa, dan otomatis menaruhnya di dalam kunci `data`.
- Kenapa dipakai: aturan proyek menyatakan respons JSON selalu lewat API Resource, dan format PRD untuk satu objek adalah `{ "data": { ... } }`.

**Exception handler dan `render()`**
- Apa itu: saat terjadi error, Laravel melempar *exception* (sinyal error). Exception handler adalah bagian Laravel yang mengubah exception itu menjadi respons HTTP. Dengan `$exceptions->render(function (JenisException $e, Request $request) { ... })`, kita bisa menentukan sendiri isi respons untuk jenis error tertentu. Kalau fungsi itu mengembalikan `null`, Laravel menangani seperti biasa.
- Kenapa dipakai: pesan bawaan Laravel berbahasa Inggris ("Unauthenticated.", "This action is unauthorized."), sedangkan PRD meminta pesan yang bisa langsung ditampilkan ke pengguna Indonesia.

**`shouldRenderJsonWhen`**
- Apa itu: aturan "kapan error harus dikirim sebagai JSON". Di sini: kalau alamatnya `api/*` atau client meminta JSON.
- Kenapa dipakai: tanpa ini, request yang lupa mengirim header `Accept: application/json` akan mendapat halaman HTML, dan aplikasi Flutter gagal membaca JSON-nya.

**Lokalisasi (file `lang/`)**
- Apa itu: Laravel mengambil teks pesan dari file bahasa sesuai `APP_LOCALE`. Dengan `APP_LOCALE=id`, aturan `required` untuk field `name` menghasilkan "Nama wajib diisi." dari `lang/id/validation.php`. Kalau sebuah aturan tidak ada di file Indonesia, Laravel memakai bahasa cadangan (`APP_FALLBACK_LOCALE=en`).
- Kenapa dipakai: pesan validasi tampil per field di form aplikasi (PRD F-11), jadi harus berbahasa Indonesia.

## Alur kode langkah demi langkah

**A. Request sukses: `GET /api/v1/ping`**
1. Request masuk. Laravel membaca `routes/api.php`; semua route di file ini otomatis diberi awalan `/api`.
2. `Route::prefix('v1')` menambah `/v1`, jadi `/ping` menjadi `/api/v1/ping`, dan diarahkan ke `PingController`.
3. Controller mengembalikan:

```php
return new JsonResource([
    'status' => 'ok',
    'app' => 'StokKu API',
    'version' => 'v1',
    'server_time' => now()->toIso8601String(),   // 2026-11-08T02:17:34+00:00
]);
```
4. Hasil: `{"data":{"status":"ok","app":"StokKu API","version":"v1","server_time":"2026-11-08T02:17:34+00:00"}}`.
   - `+00:00` artinya waktu UTC (sesuai keputusan: database dan server tetap UTC; Flutter yang mengubah ke waktu lokal saat menampilkan).

**B. Request gagal: alur error**
1. Di suatu tempat terjadi error, misalnya route tidak ada, atau `Item::findOrFail(999)` tidak menemukan data.
2. Laravel lebih dulu "menyiapkan" exception (`prepareException`). Contoh: `ModelNotFoundException` diubah menjadi `NotFoundHttpException` (404), dan exception aslinya disimpan sebagai *previous*.
3. Fungsi `render()` milik kita dipanggil sesuai jenis exception:

```php
$exceptions->render(function (HttpExceptionInterface $e, Request $request) {
    if (! $request->is('api/*')) {
        return null;
    }
    $status = $e->getStatusCode();
    if ($status === 404) {
        $message = $e->getPrevious() instanceof ModelNotFoundException
            ? 'Data tidak ditemukan.'
            : 'Endpoint tidak ditemukan.';
    }
    // ... 403 dan 429 serupa
    return response()->json(['message' => $message], $status, $e->getHeaders());
});
```
- `HttpExceptionInterface` → menangkap semua error HTTP (403, 404, 429, dll.) dalam satu fungsi, lalu dibedakan dari `getStatusCode()`.
- `getPrevious()` → melihat exception asli. Kalau asalnya `findOrFail()` yang gagal, pesannya "Data tidak ditemukan."; kalau alamatnya salah, "Endpoint tidak ditemukan.".
- `$e->getHeaders()` → header khusus tetap ikut, misalnya `Retry-After: 60` pada 429 (memberi tahu aplikasi berapa detik harus menunggu).

**C. Daftar respons error**

| Kode | Kapan | Isi JSON |
| --- | --- | --- |
| 401 | Tanpa token / token salah / token dicabut | `{"message": "Belum login atau sesi sudah berakhir. Silakan login lagi."}` |
| 403 | Ditolak (misalnya Staf ke route Admin) | `{"message": "Akses ditolak."}`, atau pesan dari `abort(403, '...')` |
| 404 | Route salah | `{"message": "Endpoint tidak ditemukan."}` |
| 404 | Data tidak ada (`findOrFail`) | `{"message": "Data tidak ditemukan."}` |
| 422 | Validasi gagal | `{"message": "Data tidak valid.", "errors": {"name": ["Nama wajib diisi."]}}` |
| 429 | Terlalu sering (rate limit) | `{"message": "Terlalu banyak percobaan. Coba lagi sebentar."}` + header `Retry-After` |

**D. Masalah yang ditemukan test: 401 sempat menjadi 500**
Test `test_request_without_token_returns_json_401` awalnya gagal dengan error `Route [login] not defined`. Penyebabnya, secara bawaan Laravel mengarahkan tamu (belum login) ke halaman web bernama `login`. StokKu tidak punya halaman itu, jadi muncul error 500. Perbaikannya:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->redirectGuestsTo(null);   // tamu tidak diarahkan ke mana pun
})
```
Sekarang yang muncul adalah `AuthenticationException`, yang dirender menjadi JSON 401. Masalah ini muncul **hanya** kalau request tidak mengirim header `Accept: application/json`, sehingga mudah terlewat kalau hanya dites dengan Thunder Client yang selalu mengirim header itu.

## Keputusan desain

- **Pesan 403 dari kode sendiri dipertahankan.** `abort(403)` tanpa pesan, atau penolakan dari Gate/Policy, menjadi "Akses ditolak.". Tapi `abort(403, 'Akun Anda nonaktif.')` tetap memakai pesannya, karena nanti dibutuhkan di T-202/T-203 (akun nonaktif harus diberi tahu alasannya). Caranya: pesan dipakai kalau exception tidak punya *previous* dan pesannya tidak kosong.
- **Penanganan 422 ikut dikerjakan di sini.** Daftar di TASKS menyebut 401/403/404/429, tapi PRD §11 sudah menetapkan format 422 (`"Data tidak valid."` + `errors`). Format default Laravel berbeda (pesannya diambil dari error pertama, misalnya "Nama wajib diisi. (and 1 more error)"), jadi lebih baik diseragamkan sejak awal.
- **Error 500 dibiarkan bawaan Laravel.** Dalam mode produksi (`APP_DEBUG=false`), Laravel sudah mengirim `{"message": "Server Error"}` tanpa membocorkan detail. Aplikasi menampilkan pesan umum + "Coba lagi" (PRD §11), jadi tidak perlu teks khusus dari server.
- **`lang/id/validation.php` hanya berisi aturan yang dipakai.** File bahasa lengkap punya lebih dari 100 aturan; yang tidak dipakai hanya menambah bacaan. Aturan yang belum ada otomatis memakai bahasa Inggris, jadi tidak pernah error.
- **Bagian `values` di file bahasa.** Untuk aturan seperti `required_if:type,waste`, pesan default akan berbunyi "... jika jenis transaksi adalah waste". Dengan `values`, `waste` ditampilkan sebagai "terbuang".
- **`APP_LOCALE=id` juga di `phpunit.xml`.** Test harus berjalan dalam bahasa yang sama dengan server; kalau tidak, test pesan Indonesia akan gagal atau malah menguji pesan bahasa Inggris.
- **`server_time` dikirim dalam UTC (`+00:00`).** Sesuai keputusan zona waktu Aldi: database dan server tetap UTC; format ISO 8601 dengan zona waktu membuat Flutter bisa mengubahnya ke waktu lokal dengan benar.
- **Route uji dibuat di dalam test.** Untuk mengetes 401/403/422/429 tanpa menunggu endpoint T-202–T-210, test mendaftarkan route sementara (`/api/v1/_test/...`) di `setUp()`. Route ini hanya ada selama test berjalan.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. **Langkah Aldi (wajib):** ubah `APP_LOCALE=en` menjadi `APP_LOCALE=id` di file `.env`. (Claude Code tidak membuka `.env`.)

2. Test otomatis:
   ```powershell
   php artisan test --filter=ApiFoundationTest
   php artisan test
   ```
   Harapan: 9 test fondasi lulus; total 56 test lulus.

3. Daftar route API:
   ```powershell
   php artisan route:list --path=api
   ```
   Harapan: hanya `GET|HEAD api/v1/ping ... v1.ping › PingController`.

4. Uji manual:
   ```powershell
   php artisan serve
   ```
   Di terminal lain (pakai `curl.exe`, bukan `curl`, karena di PowerShell 5.1 `curl` adalah alias `Invoke-WebRequest`):
   ```powershell
   curl.exe -i http://localhost:8000/api/v1/ping
   curl.exe -i http://localhost:8000/api/v1/tidak-ada
   ```
   Harapan:
   - ping → `HTTP/1.1 200 OK`, `Content-Type: application/json`, body `{"data":{"status":"ok",...,"server_time":"...+00:00"}}`
   - tidak-ada → `404 Not Found`, `Content-Type: application/json`, body `{"message":"Endpoint tidak ditemukan."}`
   - Bisa juga dibuka di browser; hasilnya JSON, bukan halaman HTML.

## Kalau error, cek ini

- **`Route [login] not defined` (error 500) saat request tanpa token** → baris `$middleware->redirectGuestsTo(null);` di `bootstrap/app.php` hilang.
- **Pesan validasi masih bahasa Inggris saat `php artisan serve`** → `APP_LOCALE` di `.env` belum diubah ke `id`, atau konfigurasi masih ter-cache. Jalankan `php artisan config:clear`.
- **Error masih berupa halaman HTML** → alamat request tidak diawali `/api/` (misalnya salah ketik `/v1/ping`), sehingga dianggap route web.
- **`Target class [PingController] does not exist`** → baris `use App\Http\Controllers\PingController;` di `routes/api.php` hilang, atau nama file/namespace controller tidak sesuai.

## Bahan menjelaskan saat wawancara

**1. "Kenapa semua error API dipaksa jadi JSON?"**
Karena client-nya aplikasi mobile, bukan browser. Aplikasi membaca `message` dan `errors` dari JSON untuk ditampilkan ke pengguna. Kalau server sesekali mengirim HTML, misalnya saat 404, aplikasi gagal parsing dan pengguna melihat error yang membingungkan. Jadi saya pakai `shouldRenderJsonWhen` untuk semua `api/*`, dan saya buat pesan bahasa Indonesia untuk kode yang sering muncul.

**2. "Kenapa ada /v1 di URL?"**
Supaya API bisa berkembang tanpa merusak aplikasi lama. Aplikasi Android yang sudah terpasang tidak bisa dipaksa update. Kalau format respons berubah besar, saya buat `/v2`, dan aplikasi lama tetap memakai `/v1`.

**3. "Ada bug menarik yang kamu temukan?"**
Ada. Request tanpa token seharusnya 401, tapi kalau request-nya tidak mengirim header `Accept: application/json`, hasilnya 500. Penyebabnya, Laravel secara bawaan mengarahkan tamu ke halaman login web, padahal proyek saya hanya API. Saya menemukannya lewat test yang sengaja tidak mengirim header itu, lalu memperbaikinya dengan `redirectGuestsTo(null)`.

## Coba sendiri (latihan)

Tambahkan penanganan **405 Method Not Allowed** di `bootstrap/app.php`, misalnya saat aplikasi mengirim `POST /api/v1/ping` padahal route itu hanya `GET`. Pesannya: "Metode tidak diizinkan untuk endpoint ini.". Lalu tulis satu test di `ApiFoundationTest` yang memanggil `$this->post('/api/v1/ping')` dan memastikan status 405 dengan pesan tersebut.
