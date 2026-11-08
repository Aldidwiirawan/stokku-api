# T-204 — Profil: ubah nama, password, dan foto

## Ringkasan
Pengguna sekarang bisa mengubah **nama**, mengganti **password** (wajib memasukkan password lama dan konfirmasi), dan mengunggah **foto profil**. Setelah password diganti, semua perangkat lain otomatis keluar (tokennya dicabut), kecuali perangkat yang sedang dipakai. URL foto (`photo_url`) dibentuk dari alamat yang dipakai aplikasi untuk menghubungi API, jadi foto tetap terbuka saat API diakses dari HP lewat IP laptop.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `app/Http/Controllers/ProfileController.php` | Dibuat | Aksi `update` (nama/password) dan `updatePhoto` (unggah foto) |
| `app/Http/Requests/UpdateProfileRequest.php` | Dibuat | Validasi `name`, `password` + `password_confirmation`, `current_password` |
| `app/Http/Requests/UpdateProfilePhotoRequest.php` | Dibuat | Validasi `photo`: gambar jpg/jpeg/png/webp, maks 2 MB |
| `app/Support/StorageUrl.php` | Dibuat | Helper URL file di disk public berdasarkan host request |
| `app/Http/Resources/UserResource.php` | Diubah | `photo_url` memakai `StorageUrl` (sebelumnya dari `APP_URL`) |
| `routes/api.php` | Diubah | `PUT /profile`, `POST /profile/photo` di grup `auth:sanctum` + `active` |
| `tests/Feature/ProfileTest.php` | Dibuat | 15 test nama, password, pencabutan token, foto, dan `photo_url` |
| `public/storage` (tidak masuk Git) | Dibuat | Link ke `storage/app/public` hasil `php artisan storage:link` |
| `D:\dev\stokku\docs\TASKS.md` (di luar repo) | Diubah | Catatan di T-206 (pakai `StorageUrl`) dan T-601 (storage:link, HTTPS/proxy) |

## Konsep yang dipakai

**Disk `public` dan `storage:link`**
- Apa itu: Laravel menyimpan file unggahan di folder `storage/app/public`. Masalahnya, web server hanya boleh menyajikan isi folder `public/` (folder lain berisi kode dan `.env`, jadi tidak boleh dibuka dari internet). `php artisan storage:link` membuat **jalan pintas** `public/storage` yang menunjuk ke `storage/app/public`, seperti *shortcut* di desktop. Di Windows, jalan pintas ini berbentuk *junction*.
- Kenapa dipakai: supaya foto bisa dibuka lewat URL `http://.../storage/photos/users/xxx.jpg`, tanpa membuka akses ke folder lain.

**Unggah file multipart**
- Apa itu: format request untuk mengirim file dan teks sekaligus (`multipart/form-data`). Di Laravel, file dibaca dengan `$request->file('photo')`, lalu `->store('folder', 'disk')` menyimpannya dengan nama acak dan mengembalikan path-nya.
- Kenapa dipakai: JSON tidak bisa membawa file biner secara efisien. Aplikasi Flutter nanti mengirim foto dengan `FormData` dari Dio.

**Aturan validasi `current_password` dan `confirmed`**
- Apa itu: `current_password:sanctum` mengecek apakah nilai yang dikirim sama dengan password pengguna yang login (lewat guard `sanctum`). `confirmed` mewajibkan field pasangan `password_confirmation` dengan isi yang sama.
- Kenapa dipakai: PRD F-20 mewajibkan password lama saat mengganti password. Kalau HP tertinggal dalam keadaan login, orang lain tidak bisa mengganti password tanpa tahu password lama. Konfirmasi mencegah salah ketik password baru.

**Host request dan `asset()`**
- Apa itu: *host* adalah alamat server yang diketik client, misalnya `localhost:8000` atau `192.168.1.10:8000`. Fungsi `asset('storage/...')` membentuk URL lengkap memakai host dari request yang sedang berjalan.
- Kenapa dipakai: saat uji di HP fisik, aplikasi menghubungi `http://192.168.1.10:8000` (IP laptop). Kalau `photo_url` dibentuk dari `APP_URL` (`http://localhost:8000`), HP akan mencari foto di **dirinya sendiri** (karena `localhost` di HP = HP itu), dan foto gagal tampil.

**`Storage::fake()` di test**
- Apa itu: mengganti disk sungguhan dengan disk sementara khusus test.
- Kenapa dipakai: test unggah foto tidak meninggalkan file sampah di `storage/app/public`, dan kita bisa memeriksa file dengan `assertExists` / `assertMissing`.

## Alur kode langkah demi langkah

**A. Ganti password: `PUT /api/v1/profile`**
1. Middleware `auth:sanctum` + `active` (T-203) → pengguna sudah login dan aktif.
2. `UpdateProfileRequest` memvalidasi:

```php
'name' => ['required', 'string', 'max:255'],
'password' => ['nullable', 'string', 'min:8', 'confirmed'],
'current_password' => ['required_with:password', 'nullable', 'string', 'current_password:sanctum'],
```
- `nullable` pada `password` → boleh tidak dikirim (hanya ubah nama).
- `required_with:password` → `current_password` wajib **hanya jika** `password` diisi.
- `current_password:sanctum` → dibandingkan dengan password pengguna pemilik token.

3. `ProfileController::update`:

```php
$user->name = $request->validated('name');
$newPassword = $request->validated('password');
if ($newPassword !== null) {
    $user->password = $newPassword;          // di-hash otomatis (cast 'hashed')
}
$user->save();

if ($newPassword !== null) {
    $currentTokenId = $user->currentAccessToken()->id;
    $user->tokens()->where('id', '!=', $currentTokenId)->delete();
}
```
- `$user->tokens()` → semua token milik pengguna ini saja (token pengguna lain tidak tersentuh).
- `where('id', '!=', $currentTokenId)` → kecualikan token yang sedang dipakai, jadi pengguna tidak ikut ter-logout dari HP yang dipakainya sekarang.

4. Respons: `UserResource` → `{ "data": { ... } }`.

**B. Unggah foto: `POST /api/v1/profile/photo`**
1. `UpdateProfilePhotoRequest`: `photo` wajib, berupa gambar (`image` mengecek isi file, bukan cuma namanya), tipe jpg/jpeg/png/webp, maksimal 2048 KB.
2. `ProfileController::updatePhoto`:

```php
$oldPath = $user->photo_path;
$user->photo_path = $request->file('photo')->store('photos/users', 'public');
$user->save();

if ($oldPath !== null) {
    Storage::disk('public')->delete($oldPath);
}
```
- `store('photos/users', 'public')` → simpan ke `storage/app/public/photos/users/<nama-acak>.jpg`, mengembalikan `photos/users/<nama-acak>.jpg`.
- Foto lama dihapus **setelah** foto baru tersimpan.

3. `UserResource` → `photo_url` = `StorageUrl::publicUrl($user->photo_path)`:

```php
return asset('storage/'.$path);   // http://<host-request>/storage/photos/users/<nama-acak>.jpg
```

## Keputusan desain

- **Token lain dicabut setelah ganti password (permintaan Aldi).** Alasan orang mengganti password biasanya karena curiga password lamanya diketahui orang lain. Kalau token di perangkat lain tetap berlaku, orang itu tetap bisa masuk walaupun password sudah diganti. Token yang sedang dipakai tidak dicabut, karena pemiliknya jelas yang sedang memakai HP ini, dan memaksanya login ulang hanya merepotkan. Mengubah nama saja **tidak** mencabut token.
- **`photo_url` dari host request, bukan `APP_URL` (permintaan Aldi).** Dibuat sebagai helper `StorageUrl::publicUrl()`, supaya foto bahan (T-206) memakai aturan yang sama (sudah dicatat di TASKS T-206). Catatan untuk hosting (T-601): kalau server berada di belakang proxy HTTPS, *trusted proxies* harus diatur supaya URL berawalan `https://`. **Trusted hosts** juga harus diatur (usul Aldi): karena URL dibentuk dari header `Host` yang dikirim client, tanpa daftar host tepercaya orang bisa mengirim `Host: situs-jahat.com` sehingga respons berisi URL foto ke situs lain (*Host header injection*). Pengaturan ini diaktifkan hanya di produksi, supaya uji lokal lewat IP laptop tetap jalan.
- **Simpan foto baru dulu, hapus foto lama belakangan.** Kalau urutannya dibalik dan penyimpanan foto baru gagal, pengguna kehilangan foto lama tanpa pengganti.
- **Batas 2 MB di server, padahal aplikasi mengompres ≤ 1 MB (F-12).** Server sedikit lebih longgar sebagai pengaman, tapi tetap menolak file besar supaya disk dan kuota tidak cepat penuh.
- **`mimes:jpg,jpeg,png,webp` selain `image`.** `image` sudah menolak non-gambar; `mimes` mempersempit ke format yang pasti bisa ditampilkan Flutter dan menolak format seperti BMP/GIF yang tidak diperlukan.
- **Password minimal 8 karakter.** Akun demo memakai `password` (8 karakter), jadi masih sesuai. Aturan kerumitan (huruf besar, angka, simbol) tidak diwajibkan, supaya tetap ramah untuk staf dapur.
- **Nama maksimal 255 karakter**, sesuai panjang kolom `users.name`.
- **Satu endpoint `PUT /profile` untuk nama dan password**, sesuai PRD §11. Akibatnya, `name` selalu wajib dikirim, termasuk saat hanya mengganti password. Layar Ubah Profil di aplikasi selalu mengisi nama yang sekarang, jadi tidak merepotkan.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Test otomatis:
   ```powershell
   php artisan test --filter=ProfileTest
   php artisan test
   php artisan route:list --path=api/v1/profile
   ```
   Harapan: 15 test profil lulus; total 100 test lulus; 2 route (`PUT api/v1/profile`, `POST api/v1/profile/photo`).

2. Link storage (cukup sekali per komputer; di laptop Aldi **sudah dijalankan**):
   ```powershell
   php artisan storage:link
   ```
   Kalau sudah ada, Laravel menampilkan pesan bahwa link sudah ada; itu wajar.

3. Thunder Client: lihat langkah lengkap di laporan T-204 atau ringkasan berikut.
   - Login → salin token.
   - `POST http://localhost:8000/api/v1/profile/photo`, tab Auth → Bearer, header `Accept: application/json`, tab **Body → Form**, centang **Files**, field `photo` → pilih file JPG/PNG ≤ 2 MB.
   - Harapan: `200`, `data.photo_url` berisi `http://localhost:8000/storage/photos/users/....jpg`; buka URL itu di browser → foto tampil.
   - Uji dari HP/IP laptop: jalankan `php artisan serve --host=0.0.0.0`, panggil API lewat `http://<IP-laptop>:8000/...` → `photo_url` ikut berawalan IP laptop.

## Kalau error, cek ini

- **Foto 404 saat `photo_url` dibuka** → `php artisan storage:link` belum dijalankan di komputer/server itu, atau folder `public/storage` terhapus. Jalankan ulang perintahnya.
- **422 "Foto wajib diisi." padahal sudah memilih file** → di Thunder Client, body bukan tipe **Form** dengan field bertipe **File**, atau nama field bukan `photo`. Jangan mengirim foto lewat body JSON.
- **422 "Password lama salah."** → password lama tidak cocok. Ingat: setelah ganti password, akun demo tidak lagi memakai `password`.
- **Setelah ganti password, aplikasi/Thunder Client lain mendapat 401** → memang begitu; semua token lain dicabut. Login ulang dengan password baru.
- **`photo_url` berisi `localhost` saat diakses dari HP** → aplikasi memanggil API lewat `localhost`, atau `ASSET_URL` diisi di `.env` (yang akan menimpa host request). Panggil API lewat IP laptop.

## Bahan menjelaskan saat wawancara

**1. "Bagaimana kamu menangani unggah foto profil?"**
Aplikasi mengirim foto lewat multipart. Server memvalidasi bahwa file itu gambar asli dengan format tertentu dan maksimal 2 MB, lalu menyimpannya dengan nama acak di disk public. Foto lama baru dihapus setelah foto baru tersimpan, supaya pengguna tidak kehilangan foto kalau ada kegagalan. File di `storage` dibuka lewat `storage:link`, jadi folder lain di server tetap tertutup.

**2. "Kenapa setelah ganti password, perangkat lain ter-logout?"**
Karena orang biasanya mengganti password saat curiga password lamanya diketahui orang lain. Kalau token lama di perangkat lain tetap berlaku, orang itu masih bisa masuk. Jadi saya cabut semua token kecuali token yang sedang dipakai, supaya pemilik akun tidak ikut ter-logout.

**3. "Kenapa URL foto tidak memakai APP_URL?"**
Waktu uji di HP fisik, aplikasi memanggil API lewat IP laptop. Kalau URL foto memakai `localhost` dari `APP_URL`, HP mencari foto di dirinya sendiri dan gagal. Jadi URL foto saya bentuk dari host request dengan `asset()`, dan saya buat helper supaya foto bahan memakai aturan yang sama.

## Coba sendiri (latihan)

Buat endpoint `DELETE /api/v1/profile/photo` (wajib token + aktif) yang menghapus file foto dari disk public, mengosongkan `photo_path`, dan membalas `UserResource` dengan `photo_url` bernilai `null`. Tulis test dengan `Storage::fake('public')`: unggah foto dulu, panggil endpoint hapus, lalu pastikan file sudah tidak ada dan `photo_url` bernilai `null`.
