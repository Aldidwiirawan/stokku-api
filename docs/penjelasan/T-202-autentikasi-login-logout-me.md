# T-202 — Autentikasi: login, logout, me

## Ringkasan
Aplikasi sekarang bisa **login** dengan email + password dan mendapat token, memakai token itu untuk mengambil **data pengguna yang sedang login** (`/auth/me`), dan **logout** untuk mencabut token di perangkat itu. Akun nonaktif tidak bisa login, kredensial salah mendapat pesan yang jelas, dan login dibatasi 5 percobaan per menit untuk menghambat orang yang menebak-nebak password.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `app/Http/Controllers/AuthController.php` | Dibuat | Aksi `login`, `me`, `logout` |
| `app/Http/Requests/LoginRequest.php` | Dibuat | Validasi `email`, `password`, `device_name`; email diubah ke huruf kecil |
| `app/Http/Resources/UserResource.php` | Dibuat | Bentuk JSON data pengguna (tanpa password; `photo_url` lengkap; `created_at` ISO 8601 UTC) |
| `routes/api.php` | Diubah | `POST /auth/login` (throttle 5/menit), `GET /auth/me` & `POST /auth/logout` (wajib token) |
| `app/Models/User.php` | Diubah | Mutator `email()`: email selalu disimpan huruf kecil tanpa spasi di awal/akhir |
| `tests/Feature/AuthTest.php` | Dibuat | 14 test login, validasi, akun nonaktif, throttle, me, logout |
| `tests/Feature/UserEmailTest.php` | Dibuat | 3 test mutator email: saat create, update/factory, dan login akun yang dibuat dengan huruf kapital |

## Konsep yang dipakai

**Token Sanctum (personal access token)**
- Apa itu: string acak yang diberikan server setelah login berhasil, seperti **kartu akses gedung**. Setiap request berikutnya menyertakan kartu itu di header `Authorization: Bearer <token>`. Server menyimpan versi *hash* dari token di tabel `personal_access_tokens`, jadi kalau database bocor, token aslinya tetap tidak terbaca.
- Kenapa dipakai: aplikasi mobile tidak memakai cookie/session seperti website. Token mudah disimpan di HP (`flutter_secure_storage`, T-30x) dan dikirim di setiap request.

**Satu token per perangkat (`device_name`)**
- Apa itu: setiap login membuat token baru dengan nama perangkat, misalnya "Samsung A15". Pengguna yang login di HP dan tablet punya dua token terpisah.
- Kenapa dipakai: logout di HP hanya mencabut token HP; tablet tetap login. Nama perangkat juga memudahkan kalau nanti ingin menampilkan "perangkat yang sedang login".

**Form Request (`LoginRequest`)**
- Apa itu: class khusus validasi. Laravel menjalankannya **sebelum** method controller. Kalau validasi gagal, controller tidak pernah dijalankan dan server langsung membalas 422.
- Kenapa dipakai: aturan proyek, sekaligus membuat controller bersih (hanya logika login).

**`prepareForValidation()`**
- Apa itu: method di Form Request yang dijalankan sebelum validasi, untuk merapikan input.
- Kenapa dipakai: keyboard HP sering otomatis membuat huruf pertama kapital ("Staf@stokku.test"). Di PostgreSQL perbandingan teks peka huruf besar/kecil, jadi email diubah ke huruf kecil dulu.

**Mutator (`Attribute::make(set: ...)`)**
- Apa itu: method di model yang otomatis mengubah nilai **saat diisi** ke model, sebelum disimpan ke database. Seperti petugas loket yang selalu merapikan formulir sebelum diarsipkan, siapa pun yang mengisinya.
- Kenapa dipakai: email harus selalu huruf kecil supaya login (yang mencari dengan huruf kecil) selalu cocok. Dengan mutator di model `User`, semua jalur otomatis konsisten: form admin (T-210), seeder, Tinker, dan factory. Tidak perlu mengingat `strtolower()` di setiap tempat.

```php
protected function email(): Attribute
{
    return Attribute::make(
        set: fn (string $value) => Str::lower(trim($value)),
    );
}
```
- Nama method `email()` = nama kolom; Laravel memanggilnya setiap kali `$user->email = ...`, `User::create([...])`, atau `fill()` dipakai.
- **Batasannya:** pencarian `User::where('email', $input)` **tidak** melewati mutator (itu query ke database, bukan mengisi model). Karena itu `LoginRequest` tetap mengubah input email ke huruf kecil sebelum mencari.

**Rate limiting (`throttle:5,1`)**
- Apa itu: membatasi jumlah request. `5,1` artinya maksimal 5 request per 1 menit. Untuk pengguna yang belum login, hitungannya per alamat IP. Request ke-6 mendapat 429.
- Kenapa dipakai: PRD §6 meminta login dibatasi 5 percobaan/menit, supaya orang tidak bisa mencoba ribuan password secara otomatis (*brute force*).

**API Resource (`UserResource`)**
- Apa itu: class yang menentukan field apa saja yang dikirim ke aplikasi.
- Kenapa dipakai: hanya field yang ditulis di `toArray()` yang dikirim. Password tidak mungkin ikut, walaupun nanti ada kolom baru di tabel `users`.

## Alur kode langkah demi langkah

**A. Login: `POST /api/v1/auth/login`**
1. Request masuk → route → middleware `throttle:5,1` menghitung percobaan. Kalau sudah lebih dari 5 dalam semenit → 429.
2. `LoginRequest`: `prepareForValidation()` mengubah email ke huruf kecil, lalu validasi `required`. Kalau gagal → 422 dengan pesan Indonesia.
3. `AuthController::login`:

```php
$user = User::where('email', $request->validated('email'))->first();

if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
    throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
}

if (! $user->is_active) {
    throw new AccountInactiveException;   // diubah di T-203 (sebelumnya abort(403, '...'))
}

$token = $user->createToken($request->validated('device_name'))->plainTextToken;
```
- `Hash::check(...)` → membandingkan password yang diketik dengan hash di database. Password tidak pernah disimpan dalam bentuk asli.
- `ValidationException::withMessages` → dirender (T-201) menjadi `{"message":"Data tidak valid.","errors":{"email":["Email atau password salah."]}}` (422).
- `AccountInactiveException` → 403 `{"message": "Akun Anda nonaktif. Hubungi admin.", "code": "account_inactive"}`. Field `code` ditambahkan di T-203 supaya aplikasi bisa membedakan akun nonaktif dari 403 lain (lihat file penjelasan T-203).
- `createToken(...)->plainTextToken` → token dalam bentuk asli (contoh `1|exFg...`). Hanya dikirim **sekali** ini; setelah itu server hanya menyimpan hash-nya.

4. Respons 200:
```json
{ "data": { "token": "1|...", "token_type": "Bearer", "user": { "id": 2, "name": "Staf Dapur", ... } } }
```

**B. Me: `GET /api/v1/auth/me`**
1. Middleware `auth:sanctum` membaca header `Authorization: Bearer <token>`, mencari hash-nya di `personal_access_tokens`, lalu menemukan pemiliknya. Tidak ada/salah → 401 (T-201).
2. `me()` mengembalikan `new UserResource($request->user())` → `{ "data": { ... } }`.

**C. Logout: `POST /api/v1/auth/logout`**

```php
$request->user()->currentAccessToken()->delete();

return response()->noContent();   // 204
```
- `currentAccessToken()` → token yang dipakai di request **ini**. Hanya baris itu yang dihapus dari `personal_access_tokens`; token di perangkat lain tidak tersentuh.
- Setelah itu, request dengan token yang sama mendapat 401.

## Keputusan desain

- **Kredensial salah → 422, bukan 401.** Di aplikasi, 401 berarti "sesi berakhir, hapus token, kembali ke login" (PRD F-04). Kalau login yang salah juga 401, interceptor aplikasi bisa salah menangani. 422 dengan `errors.email` membuat pesan bisa tampil di bawah field email, seperti error validasi lainnya. Ini juga pola yang dipakai di dokumentasi Sanctum.
- **Pesan sama untuk email tidak terdaftar dan password salah.** Kalau pesannya berbeda ("email tidak ditemukan"), orang bisa mengecek email mana saja yang terdaftar (*user enumeration*).
- **Status aktif dicek setelah password benar.** Orang yang tidak tahu password tidak bisa mengetahui bahwa akun itu nonaktif; ia hanya melihat "Email atau password salah.".
- **Logout membalas 204 No Content, bukan JSON pesan.** Tidak ada data yang perlu dikirim; aplikasi sudah tahu harus menampilkan apa. 204 adalah kode standar untuk "berhasil, tanpa isi", dan menghindari respons JSON yang tidak lewat API Resource.
- **Respons login memakai `JsonResource` berisi `token` + `UserResource`.** Formatnya mengikuti PRD (`{ "data": { ... } }`). `UserResource` di dalamnya tidak dibungkus `data` lagi, karena Laravel hanya membungkus resource paling luar.
- **Token belum punya masa berlaku** (`config/sanctum.php` → `expiration => null`). Token berlaku sampai logout. Ini cocok untuk aplikasi di HP pribadi. Kalau nanti ingin token kedaluwarsa (misalnya 30 hari), cukup ubah `expiration`; aplikasi sudah siap karena 401 → kembali ke login (F-04).
- **Email dinormalisasi di dua tempat, dengan tugas berbeda (usul Aldi).** Mutator di model menjamin semua email yang **disimpan** huruf kecil, sedangkan `LoginRequest` menjamin email yang **dicari** saat login juga huruf kecil. Alternatifnya, query `whereRaw('lower(email) = ?')`, ditolak karena membuat index `users_email_unique` tidak terpakai dan tetap tidak merapikan data yang disimpan. Data lama di Supabase tidak perlu diubah, karena kedua akun demo sudah huruf kecil.
- **Pengecekan akun nonaktif untuk request setelah login** (misalnya admin menonaktifkan akun yang sedang login) dikerjakan di T-203 (middleware `active`).

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Test otomatis:
   ```powershell
   php artisan test --filter=AuthTest
   php artisan test
   php artisan route:list --path=api
   ```
   Tambahan: `php artisan test --filter=UserEmailTest` (3 test mutator email).
   Harapan: 14 test auth + 3 test email lulus; total 73 test lulus; 4 route (`auth/login`, `auth/logout`, `auth/me`, `ping`).

2. Thunder Client: jalankan `php artisan serve`, lalu:

   **Login**
   - Method `POST`, URL `http://localhost:8000/api/v1/auth/login`
   - Header: `Accept: application/json`
   - Body → JSON:
     ```json
     { "email": "staf@stokku.test", "password": "password", "device_name": "Thunder Client" }
     ```
   - Harapan: `200 OK`, body `{"data":{"token":"<angka>|<teks acak>","token_type":"Bearer","user":{"id":2,"name":"Staf Dapur","email":"staf@stokku.test","role":"staf","is_active":true,"photo_url":null,"created_at":"...+00:00"}}}`. Salin nilai `token`.

   **Me**
   - Method `GET`, URL `http://localhost:8000/api/v1/auth/me`
   - Header: `Accept: application/json`; tab **Auth → Bearer**, tempel token (atau header `Authorization: Bearer <token>`).
   - Harapan: `200 OK`, body `{"data":{"id":2,"name":"Staf Dapur",...}}`.

   **Logout**
   - Method `POST`, URL `http://localhost:8000/api/v1/auth/logout`, header & token sama seperti Me, body kosong.
   - Harapan: `204 No Content`, body kosong.
   - Kirim lagi request **Me** dengan token yang sama → `401`, body `{"message":"Belum login atau sesi sudah berakhir. Silakan login lagi."}`.

   **Kasus gagal (opsional)**
   - Login password salah → `422`, `{"message":"Data tidak valid.","errors":{"email":["Email atau password salah."]}}`.
   - Login 6× dalam semenit → percobaan ke-6 `429`, `{"message":"Terlalu banyak percobaan. Coba lagi sebentar."}`. Tunggu 1 menit sebelum mencoba lagi.

## Kalau error, cek ini

- **401 di `/auth/me` padahal sudah login** → token belum ditempel, salah salin (harus lengkap termasuk bagian `angka|` di depan), atau token sudah dipakai untuk logout. Login ulang untuk mendapat token baru.
- **422 "Email atau password salah." padahal yakin benar** → seeder belum dijalankan ke database yang dipakai, atau password akun sudah pernah diganti. Cek `users` di Supabase.
- **429 terus-menerus** → sudah lebih dari 5 percobaan dalam semenit dari IP yang sama. Tunggu 1 menit.
- **Body respons berupa HTML / error "Route [login] not defined"** → URL tidak diawali `/api/`, atau baris `redirectGuestsTo(null)` di `bootstrap/app.php` (T-201) terhapus.

## Bahan menjelaskan saat wawancara

**1. "Bagaimana alur autentikasi aplikasi kamu?"**
Aplikasi mengirim email, password, dan nama perangkat ke `/auth/login`. Server mengecek password dengan `Hash::check`, memastikan akun aktif, lalu membuat token Sanctum. Token disimpan di secure storage HP dan dikirim di header `Authorization: Bearer` setiap request. Logout menghapus token itu di server, jadi walaupun token dicuri, setelah logout token itu tidak berlaku lagi.

**2. "Kenapa pesan error login tidak membedakan email salah dan password salah?"**
Supaya orang tidak bisa mencari tahu email mana yang terdaftar. Begitu juga status nonaktif: baru diberi tahu kalau password-nya benar. Login juga dibatasi 5 kali per menit untuk menghambat tebak-tebakan password.

**3. "Kenapa logout hanya menghapus token saat ini?"**
Karena satu pengguna bisa login di beberapa perangkat. Setiap login membuat token dengan nama perangkat. Logout di HP tidak boleh mengeluarkan pengguna dari tablet dapur. Kalau perlu "logout dari semua perangkat", cukup `$user->tokens()->delete()`, dan itu dipakai di T-210 saat admin menonaktifkan pengguna.

## Coba sendiri (latihan)

Buat endpoint `POST /api/v1/auth/logout-all` (wajib token) yang menghapus **semua** token milik pengguna yang login dan membalas 204. Tulis test: buat 2 token untuk satu pengguna, panggil `logout-all` dengan salah satunya, lalu pastikan tabel `personal_access_tokens` kosong untuk pengguna itu.
