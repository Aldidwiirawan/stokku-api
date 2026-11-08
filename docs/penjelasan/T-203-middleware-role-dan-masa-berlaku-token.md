# T-203 — Middleware role, akun nonaktif, dan masa berlaku token

## Ringkasan
Server sekarang bisa membatasi route berdasarkan peran: cukup tulis `->middleware('role:admin')`, dan Staf otomatis mendapat 403 "Akses ditolak.". Akun yang dinonaktifkan langsung ditolak di **semua** route yang butuh login, walaupun tokennya masih ada. Responsnya membawa `"code": "account_inactive"`, supaya aplikasi tahu harus logout. Token login juga punya **masa berlaku 30 hari**; setelah itu server membalas 401 dan pengguna harus login ulang.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `app/Http/Middleware/EnsureUserHasRole.php` | Dibuat | Middleware `role:...`: tolak pengguna yang perannya tidak ada di daftar (403) |
| `app/Http/Middleware/EnsureUserIsActive.php` | Dibuat | Middleware `active`: tolak akun nonaktif dengan `AccountInactiveException` |
| `app/Exceptions/AccountInactiveException.php` | Dibuat | 403 `{"message": "...", "code": "account_inactive"}`; dipakai middleware `active` **dan** login |
| `app/Http/Controllers/AuthController.php` | Diubah | Login akun nonaktif memakai `AccountInactiveException` (sebelumnya `abort(403, ...)`) |
| `tests/Feature/AuthTest.php` | Diubah | Test login akun nonaktif mengecek field `code` |
| `docs/penjelasan/T-202-...md` | Diubah | Disesuaikan dengan perubahan login di atas |
| `bootstrap/app.php` | Diubah | Mendaftarkan alias `role` dan `active` |
| `routes/api.php` | Diubah | Grup `['auth:sanctum', 'active']` untuk route yang butuh login; logout cukup `auth:sanctum` |
| `config/sanctum.php` | Diubah | `expiration` = `SANCTUM_EXPIRATION`, default 43200 menit (30 hari) |
| `.env.example` | Diubah | `SANCTUM_EXPIRATION=43200` beserta keterangannya |
| `database/factories/UserFactory.php` | Diubah | State `inactive()` |
| `tests/Feature/AccessControlTest.php` | Dibuat | 8 test peran & akun nonaktif (termasuk field `code` dan tidak dicatat di log error) |
| `tests/Feature/TokenExpirationTest.php` | Dibuat | 4 test masa berlaku token |

## Konsep yang dipakai

**Middleware**
- Apa itu: lapisan pemeriksaan yang dilewati request **sebelum** sampai ke controller, seperti satpam berlapis di pintu gedung: satpam pertama mengecek kartu akses (`auth:sanctum`), satpam kedua mengecek kartunya masih aktif (`active`), satpam ketiga mengecek lantai yang boleh dimasuki (`role:admin`). Kalau satu satpam menolak, request berhenti di situ.
- Kenapa dipakai: aturan "hanya Admin" dan "akun harus aktif" dipakai di banyak route. Dengan middleware, aturannya ditulis sekali dan dipasang dengan satu kata di route, bukan diulang di setiap controller.

**Parameter middleware (`role:admin,staf`)**
- Apa itu: teks setelah titik dua dikirim ke method `handle()` sebagai parameter tambahan. `string ...$roles` (*variadic*, tanda `...`) mengumpulkan semua nilai yang dipisah koma menjadi satu array: `['admin', 'staf']`.
- Kenapa dipakai: satu middleware bisa dipakai untuk berbagai kombinasi peran.

**Alias middleware**
- Apa itu: nama pendek untuk class middleware, didaftarkan di `bootstrap/app.php`.
- Kenapa dipakai: menulis `'role:admin'` di route jauh lebih mudah dibaca daripada nama class lengkap.

**Masa berlaku token (token expiration)**
- Apa itu: token dianggap tidak berlaku setelah waktu tertentu sejak dibuat. Sanctum mengecek ini di setiap request: token valid jika `created_at` masih lebih baru dari `sekarang − 43200 menit`.
- Kenapa dipakai: lihat bagian "Kenapa token diberi batas waktu" di Keputusan desain.

**Exception dengan method `render()` dan kode error (`code`)**
- Apa itu: class exception buatan sendiri yang tahu cara mengubah dirinya menjadi respons. Laravel memanggil `render()` milik exception **lebih dulu**, sebelum renderer umum di `bootstrap/app.php`. Field `code` adalah kode error yang stabil untuk dibaca program, sedangkan `message` untuk dibaca manusia.
- Kenapa dipakai: aplikasi harus bereaksi berbeda terhadap dua jenis 403. Akun nonaktif → logout. "Akses ditolak" → tetap login. Mencocokkan teks pesan rapuh (kalau kalimatnya diedit sedikit, aplikasi rusak). Kode `account_inactive` tidak akan berubah walaupun pesannya diubah.

```php
class AccountInactiveException extends AccessDeniedHttpException
{
    public const CODE = 'account_inactive';
    public const MESSAGE = 'Akun Anda nonaktif. Hubungi admin.';

    public function render(): JsonResponse
    {
        return response()->json(['message' => self::MESSAGE, 'code' => self::CODE], 403);
    }
}
```
- `extends AccessDeniedHttpException` → termasuk "error HTTP 403". Laravel tidak mencatat error HTTP ke log, jadi akun nonaktif (kejadian normal, bukan bug) tidak memenuhi log. Ada test yang membuktikannya (`Exceptions::assertNotReported`).
- Dipakai di **dua** tempat, yaitu login (`AuthController`) dan middleware `active`, supaya aplikasi menerima respons yang sama persis.

**Time travel di test (`$this->travel(...)`)**
- Apa itu: fitur test Laravel untuk memajukan "jam" aplikasi tanpa benar-benar menunggu. `$this->travel(43201)->minutes()` membuat aplikasi mengira sekarang sudah 30 hari lebih 1 menit kemudian.
- Kenapa dipakai: mustahil menunggu 30 hari untuk menguji kedaluwarsa token.

## Alur kode langkah demi langkah

Contoh: Staf membuka route Admin, misalnya `DELETE /api/v1/categories/1` nanti di T-205.

1. Request masuk dengan header `Authorization: Bearer <token>`.
2. **`auth:sanctum`**: cari token di `personal_access_tokens`. Tidak ada, salah, atau **lebih dari 30 hari** → 401 "Belum login atau sesi sudah berakhir. Silakan login lagi.".
3. **`active`** (`EnsureUserIsActive`):

```php
if (! $user->is_active) {
    throw new AccountInactiveException;   // 403 + "code": "account_inactive"
}
return $next($request);   // lanjut ke pemeriksaan berikutnya
```
4. **`role:admin`** (`EnsureUserHasRole`):

```php
public function handle(Request $request, Closure $next, string ...$roles): Response
{
    $user = $request->user();
    if ($user === null) {
        throw new AuthenticationException;     // pengaman jika lupa dipasang setelah auth
    }
    if (! in_array($user->role, $roles, true)) {
        abort(403);                             // → "Akses ditolak."
    }
    return $next($request);
}
```
- `$next($request)` → "silakan lewat", teruskan ke middleware berikutnya atau controller.
- `in_array(..., true)` → perbandingan ketat (nilai **dan** tipe sama).
- Staf → `'staf'` tidak ada di `['admin']` → 403 "Akses ditolak.".

5. Urutan di route menentukan urutan pemeriksaan. Karena `active` dipasang sebelum `role:admin`, **Admin yang nonaktif** mendapat pesan "Akun Anda nonaktif...", bukan "Akses ditolak." yang membingungkan.

Susunan route sekarang:

```php
Route::post('/auth/logout', ...)->middleware('auth:sanctum');           // tanpa 'active'

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/auth/me', ...);
    // T-205 dst.: Route::delete('/categories/{category}', ...)->middleware('role:admin');
});
```

**Masa berlaku token** (`config/sanctum.php`):

```php
'expiration' => (int) env('SANCTUM_EXPIRATION', 43200),
```
- `env('SANCTUM_EXPIRATION', 43200)` → ambil dari `.env`; kalau tidak ada, pakai 43200.
- `(int)` → nilai dari `.env` selalu berupa teks, jadi diubah ke angka.

## Keputusan desain

**Kenapa token diberi batas waktu (30 hari)?**
- **Token yang dicuri tidak berlaku selamanya.** Token adalah "kunci" akun. Kalau HP hilang, dijual tanpa direset, atau token bocor (misalnya ter-*screenshot* saat debugging), pemegang token bisa memakai akun itu. Tanpa batas waktu, token curian berlaku **selamanya** sampai pemiliknya sadar dan logout dari perangkat itu, padahal perangkatnya sudah tidak ada. Dengan batas 30 hari, kerusakan maksimal dibatasi.
- **Token "yatim" tidak menumpuk.** Pengguna yang meng-uninstall aplikasi tanpa logout meninggalkan token yang masih aktif di server. Dengan batas waktu, token seperti itu otomatis tidak berlaku.
- **Kenapa 30 hari, bukan 1 jam?** StokKu dipakai staf dapur setiap hari di HP kerja. Kalau harus login setiap jam, aplikasi menyebalkan dan orang tergoda memakai password yang mudah. 30 hari adalah keseimbangan: cukup lama supaya nyaman, cukup pendek supaya token curian tidak berlaku lama.
- **Aplikasi sudah siap.** Saat token kedaluwarsa, server membalas 401; aplikasi menghapus token dan menampilkan "Sesi berakhir, silakan login lagi" (PRD F-04).

**Keputusan lain**
- **Batas 30 hari dihitung sejak login, bukan sejak terakhir dipakai.** Begitulah cara kerja `expiration` di Sanctum (dibandingkan dengan `created_at`). Pengguna yang aktif setiap hari tetap harus login ulang setiap 30 hari. Alternatifnya, token "diperpanjang" setiap dipakai (*sliding expiration*). Ini lebih nyaman, tapi butuh kode tambahan dan token curian yang terus dipakai tidak pernah kedaluwarsa.
- **Nilai bisa diatur lewat `.env`** (`SANCTUM_EXPIRATION`, dalam menit) tanpa mengubah kode. `0` berarti tidak pernah kedaluwarsa. `.env` Aldi tidak perlu diubah; tanpa variabel itu nilai default 30 hari otomatis berlaku (sudah dicek: `php artisan config:show sanctum.expiration` → 43200).
- **`active` dipisah dari `role`.** Pengecekan akun aktif berlaku untuk semua route yang butuh login, sedangkan peran hanya untuk sebagian. Kalau digabung, route yang tidak memakai `role:` (seperti `/auth/me`) tidak akan mengecek status aktif.
- **Akun nonaktif → 403 (bukan 401), dengan `"code": "account_inactive"` (keputusan Aldi).** Sesuai PRD §11: 403 = "peran tidak berhak / akun nonaktif". Di aplikasi (dicatat di T-303/T-305):
  - 403 **dengan** `code: account_inactive` → diperlakukan seperti sesi berakhir: hapus token, kembali ke login, dan tampilkan `message` dari server.
  - 403 **tanpa** `code` → "Akses ditolak", tetap login.

  Alternatifnya, aplikasi mencocokkan teks pesan. Ditolak karena rapuh. Alternatif lain, akun nonaktif dibalas 401, supaya aplikasi otomatis logout. Ditolak karena pengguna akan melihat "Sesi berakhir" dan mengira cukup login ulang, padahal akunnya nonaktif.
- **Logout tidak memakai `active`.** Akun yang baru dinonaktifkan tetap boleh mencabut tokennya sendiri. Mencabut token tidak pernah berbahaya, dan aplikasi bisa membersihkan sesi dengan rapi.
- **Token kedaluwarsa tidak otomatis terhapus dari database**; hanya ditolak. Untuk membersihkannya ada perintah `php artisan sanctum:prune-expired --hours=24`. Atas persetujuan Aldi, penjadwalan harian perintah ini dicatat di T-601, karena butuh scheduler/*cron* di server hosting.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Test otomatis:
   ```powershell
   php artisan test --filter=AccessControlTest
   php artisan test --filter=TokenExpirationTest
   php artisan test
   ```
   Harapan: 8 + 4 test lulus; total 85 test lulus.

2. Lihat middleware di setiap route:
   ```powershell
   php artisan route:list --path=api -v
   ```
   Harapan: `auth/me` punya `Authenticate:sanctum` **dan** `EnsureUserIsActive`; `auth/logout` hanya `Authenticate:sanctum`.

3. Cek masa berlaku token yang dipakai aplikasi:
   ```powershell
   php artisan config:show sanctum.expiration
   ```
   Harapan: `43200`.

4. Thunder Client (akun nonaktif):
   - Login sebagai `staf@stokku.test`, salin token.
   - Di Tinker, nonaktifkan akun staf: `$u = App\Models\User::where('email', 'staf@stokku.test')->first(); $u->is_active = false; $u->save();`
   - `GET /api/v1/auth/me` dengan token tadi → `403 {"message":"Akun Anda nonaktif. Hubungi admin.","code":"account_inactive"}`.
   - Login ulang dengan akun itu (password benar) → respons 403 yang sama persis.
   - `POST /api/v1/auth/logout` dengan token yang sama → `204` (tetap boleh logout).
   - **Jangan lupa aktifkan lagi**: `$u->is_active = true; $u->save();`

   Route khusus Admin baru ada di T-205, jadi uji "Staf → 403" di Thunder Client baru bisa dilakukan setelah itu. Saat ini dibuktikan lewat test.

## Kalau error, cek ini

- **`Target class [role] does not exist`** → alias belum terdaftar di `bootstrap/app.php`, atau baris `use App\Http\Middleware\EnsureUserHasRole;` hilang.
- **Admin juga mendapat 403 "Akses ditolak."** → nilai `role` di database bukan persis `admin` (misalnya ada spasi atau huruf kapital), atau penulisan di route salah (`role:Admin`). Perbandingannya ketat dan peka huruf besar/kecil.
- **Token selalu 401 padahal baru login** → `SANCTUM_EXPIRATION` di `.env` diisi angka sangat kecil, atau jam komputer/server salah. Cek `php artisan config:show sanctum.expiration`, lalu `php artisan config:clear`.
- **Akun nonaktif masih bisa membuka `/auth/me`** → route tersebut ada di luar grup `['auth:sanctum', 'active']`.

## Bahan menjelaskan saat wawancara

**1. "Bagaimana kamu membatasi fitur Admin?"**
Lewat middleware di server, bukan hanya menyembunyikan tombol di aplikasi. Saya buat middleware `role` yang membaca peran pengguna dari token. Di route cukup ditulis `role:admin`. Staf yang mencoba memanggil endpoint Admin langsung, misalnya lewat Postman, tetap ditolak 403. Aplikasi menyembunyikan menu hanya untuk kenyamanan, pengamannya ada di server.

**2. "Kenapa token login kamu kedaluwarsa setelah 30 hari?"**
Karena token adalah kunci akun. Kalau HP hilang atau token bocor, token tanpa batas waktu berlaku selamanya. Dengan batas 30 hari, risikonya dibatasi, dan pengguna harian tidak terganggu karena cukup login sebulan sekali. Saat kedaluwarsa, server membalas 401 dan aplikasi mengarahkan ke halaman login.

**3. "Kalau admin menonaktifkan akun yang sedang login, apa yang terjadi?"**
Request berikutnya dari akun itu langsung ditolak 403 oleh middleware `active`, walaupun tokennya masih ada. Responsnya membawa kode `account_inactive`, jadi aplikasi tahu harus logout dan menampilkan pesannya, bukan sekadar "Akses ditolak". Di T-210 nanti, saat admin menonaktifkan akun, semua token akun itu juga dihapus, jadi ada dua lapis pengaman.

## Coba sendiri (latihan)

Ubah masa berlaku token menjadi **7 hari** hanya lewat `.env` (tanpa mengubah kode), lalu jalankan `php artisan config:show sanctum.expiration` untuk memastikan nilainya berubah. Perhatikan test mana yang gagal setelah perubahan itu dan pikirkan kenapa. Setelah selesai, kembalikan `.env` seperti semula dan jalankan `php artisan config:clear`.
