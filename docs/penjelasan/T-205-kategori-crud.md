# T-205 — Kategori CRUD

## Ringkasan
Semua pengguna yang login bisa melihat daftar kategori beserta jumlah bahan aktifnya. **Admin** bisa menambah, mengubah, dan menghapus kategori; Staf yang mencoba mendapat 403. Nama kategori tidak boleh kembar walaupun beda huruf besar/kecil atau ada spasi di awal/akhir. Aturan ini dijaga oleh validasi **dan** oleh database (expression index), jadi tetap aman walaupun dua Admin menyimpan di saat bersamaan. Kategori yang masih dipakai bahan, **termasuk bahan yang sudah dihapus**, tidak bisa dihapus (409) dengan pesan yang menjelaskan alasannya.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `app/Http/Controllers/CategoryController.php` | Dibuat | `index`, `store`, `update`, `destroy`; penolakan index unik → 422 |
| `database/migrations/2026_11_08_062443_add_lower_name_unique_index_to_categories_table.php` | Dibuat | Expression index unik `categories_name_lower_unique` pada `lower(name)` |
| `app/Http/Requests/CategoryRequest.php` | Dibuat | Validasi `name` untuk tambah & ubah |
| `app/Rules/UniqueCategoryName.php` | Dibuat | Aturan validasi: nama unik tanpa membedakan huruf besar/kecil |
| `app/Http/Resources/CategoryResource.php` | Dibuat | `id`, `name`, `items_count` |
| `app/Models/Category.php` | Diubah | Mutator `name()`: buang spasi di awal/akhir |
| `routes/api.php` | Diubah | `GET /categories` (semua) + `apiResource` store/update/destroy (`role:admin`) |
| `bootstrap/app.php` | Diubah | Renderer error menangani 409 (pesan dari kode, tanpa stack trace) |
| `tests/Feature/CategoryApiTest.php` | Dibuat | 20 test daftar, tambah, ubah, hapus, hak akses, 409, index database & simulasi simpan bersamaan |

## Konsep yang dipakai

**CRUD dan `apiResource`**
- Apa itu: CRUD = *Create, Read, Update, Delete* (tambah, lihat, ubah, hapus). `Route::apiResource('categories', ...)` membuat route standar sekaligus: `POST /categories` → `store`, `PUT /categories/{category}` → `update`, `DELETE /categories/{category}` → `destroy` (dan lainnya, yang dibatasi dengan `->only([...])`).
- Kenapa dipakai: nama route dan method controller jadi seragam mengikuti kebiasaan Laravel, sehingga mudah ditebak orang lain.

**Route model binding**
- Apa itu: kalau parameter route bernama `{category}` dan method controller menerima `Category $category`, Laravel otomatis mencari kategori dengan id tersebut. Kalau tidak ada → 404 "Data tidak ditemukan." (renderer T-201).
- Kenapa dipakai: controller tidak perlu menulis `Category::findOrFail($id)` di setiap method.

**Custom validation rule (`UniqueCategoryName`)**
- Apa itu: aturan validasi buatan sendiri dalam bentuk class yang punya method `validate()`. Kalau gagal, panggil `$fail('pesan')`.
- Kenapa dipakai: aturan bawaan `unique:categories,name` memakai `=`, yang di PostgreSQL membedakan huruf besar/kecil ("Bumbu & Rempah" ≠ "bumbu & rempah"). Aldi meminta keduanya dianggap sama.

**`withCount` / `loadCount`**
- Apa itu: menghitung jumlah relasi (di sini: bahan) langsung lewat database dan menaruh hasilnya di properti `items_count`. `withCount` dipakai saat mengambil banyak data, `loadCount` untuk model yang sudah ada.
- Kenapa dipakai: menghitung jumlah bahan per kategori dalam **satu** query, bukan satu query per kategori (masalah *N+1 query*).

**Expression index (index berbasis ekspresi)**
- Apa itu: index biasa menyimpan **nilai kolom apa adanya**. Expression index menyimpan **hasil sebuah ekspresi**, di sini `lower(name)`. Bayangkan buku tamu yang, selain mencatat nama persis seperti ditulis tamu, juga punya daftar nama yang semuanya ditulis ulang dengan huruf kecil. Kalau daftar itu diberi aturan "tidak boleh ada yang sama" (`UNIQUE`), maka "Bumbu & Rempah" dan "bumbu & rempah" sama-sama menjadi `bumbu & rempah`, dan yang kedua ditolak.
- Index unik biasa `categories_name_unique` membandingkan nilai asli, jadi menganggap keduanya berbeda. Expression index `categories_name_lower_unique` membandingkan hasil `lower(name)`, jadi menganggap keduanya sama.
- Bonus: query `WHERE lower(name) = ?` (yang dipakai `UniqueCategoryName`) juga bisa memakai index ini, sehingga pengecekan tetap cepat walaupun datanya banyak.
- Kenapa dipakai: validasi di aplikasi hanya mengecek **sebelum** menyimpan. Kalau dua Admin menekan "Simpan" dengan nama yang sama di saat yang persis bersamaan, keduanya bisa lolos pengecekan (belum ada yang tersimpan), lalu dua-duanya tersimpan. Database adalah satu-satunya tempat yang melihat semua penyimpanan secara berurutan, jadi aturan "tidak boleh kembar" yang dijaga database tidak bisa diakali oleh waktu. Kejadian seperti ini disebut *race condition*.
- Cara membuatnya (`database/migrations/..._add_lower_name_unique_index_to_categories_table.php`):

```php
public function up(): void
{
    DB::statement('CREATE UNIQUE INDEX categories_name_lower_unique ON categories (lower(name))');
}

public function down(): void
{
    DB::statement('DROP INDEX categories_name_lower_unique');
}
```
- `DB::statement(...)` → menjalankan SQL langsung, karena Schema Builder Laravel (`$table->unique(...)`) hanya menerima nama kolom, bukan ekspresi.
- Sintaks ini sama di PostgreSQL (Supabase) dan SQLite (test), jadi satu migration cukup untuk keduanya.
- Syarat sebelum membuatnya: data yang sudah ada tidak boleh kembar dalam huruf kecil. Kalau ada, `CREATE UNIQUE INDEX` gagal. Karena itu Supabase dicek dulu dengan query baca-saja (hasilnya 0 nama kembar).

**`UniqueConstraintViolationException`**
- Apa itu: jenis `QueryException` khusus yang dilempar Laravel saat database menolak data karena melanggar index unik (PostgreSQL kode `23505`, SQLite "UNIQUE constraint failed").
- Kenapa dipakai: supaya yang ditangkap hanya penolakan karena data kembar, bukan semua error database. Error lain (misalnya koneksi putus) tetap menjadi 500, sehingga tidak tersembunyi.

**HTTP 409 Conflict**
- Apa itu: kode status untuk "permintaan masuk akal, tapi bentrok dengan keadaan data saat ini".
- Kenapa dipakai: menghapus kategori yang masih dipakai bahan bukan kesalahan input (422) dan bukan masalah hak akses (403), tapi konflik dengan data lain (PRD F-14).

## Alur kode langkah demi langkah

**A. Tambah: `POST /api/v1/categories` (Admin)**
1. Middleware: `auth:sanctum` → `active` → `role:admin`. Staf berhenti di sini dengan 403 "Akses ditolak.".
2. Middleware bawaan `TrimStrings` membuang spasi di awal/akhir input (`" bumbu & rempah "` → `"bumbu & rempah"`).
3. `CategoryRequest`:

```php
$currentCategoryId = $this->route('category')?->id;   // null saat tambah

return [
    'name' => ['required', 'string', 'max:100', new UniqueCategoryName($currentCategoryId)],
];
```
4. `UniqueCategoryName::validate`:

```php
$exists = Category::query()
    ->whereRaw('lower(name) = ?', [Str::lower(trim($value))])
    ->when($this->ignoreId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreId))
    ->exists();

if ($exists) {
    $fail('Nama kategori sudah digunakan.');
}
```
- `lower(name)` (fungsi SQL) dan `Str::lower(...)` (PHP) → kedua sisi dibandingkan dalam huruf kecil.
- `?` → nilai dikirim sebagai parameter, aman dari SQL injection walaupun memakai `whereRaw`.
- `when(...)` → saat **ubah**, kategori itu sendiri dikecualikan, jadi Admin boleh menyimpan nama yang sama atau hanya mengganti huruf besar/kecilnya ("bumbu & rempah" → "Bumbu & Rempah").

5. `CategoryController::store` → `Category::create(...)` (mutator `name()` men-trim lagi sebagai pengaman) → `loadCount('items')` → `CategoryResource`. Laravel otomatis membalas **201 Created** karena modelnya baru dibuat.

6. Kalau **database** menolak karena expression index (kasus simpan bersamaan):

```php
try {
    $category = Category::create(['name' => $request->validated('name')]);
} catch (UniqueConstraintViolationException) {
    $this->throwNameAlreadyUsed();   // ValidationException → 422 "Nama kategori sudah digunakan."
}
```
- Aplikasi menerima respons **yang sama persis** dengan validasi biasa, jadi tidak perlu membedakan "ditolak validasi" dan "ditolak database".
- `update()` memakai pola yang sama.
- `throwNameAlreadyUsed(): never` → tipe `never` artinya method ini **tidak pernah selesai normal**; ia selalu melempar exception.

**B. Hapus: `DELETE /api/v1/categories/{category}` (Admin)**

```php
if ($category->items()->withTrashed()->exists()) {
    abort(409, 'Kategori tidak bisa dihapus karena masih dipakai oleh bahan, termasuk bahan yang sudah dihapus. Riwayat transaksi bahan tersebut harus tetap tersimpan.');
}

$category->delete();
return response()->noContent();   // 204
```
- `withTrashed()` → bahan yang sudah di-soft delete ikut dihitung (catatan dari T-105). Tanpa ini, kategori yang hanya berisi bahan terhapus akan lolos pengecekan, lalu database menolak karena FK restrict, dan pengguna mendapat **500**.
- `abort(409, '...')` → renderer di `bootstrap/app.php` membalas `{"message": "..."}`.

**C. Renderer 409 di `bootstrap/app.php`**

```php
} elseif ($status === 409) {
    $message = $e->getMessage() !== '' ? $e->getMessage() : 'Data bentrok dengan data lain.';
}
```
Tanpa tambahan ini, 409 memakai respons bawaan Laravel, yang dalam mode debug lokal (`APP_DEBUG=true`) ikut menyertakan *stack trace* (daftar file dan baris kode) di JSON. Itu tidak boleh terlihat oleh aplikasi.

## Keputusan desain

- **Nama unik tanpa membedakan huruf besar/kecil dan spasi (permintaan Aldi).** Dicek di aturan validasi, bukan di database. Huruf besar/kecil yang diketik Admin tetap disimpan apa adanya ("Bumbu & Rempah"); hanya **perbandingannya** yang memakai huruf kecil.
- **Spasi dibuang di tiga lapis:** middleware `TrimStrings` (input HTTP), `trim()` di aturan validasi, dan mutator `name()` di model (untuk seeder/Tinker).
- **Dua lapis pengaman: validasi + expression index (keputusan Aldi).**
  - Validasi `UniqueCategoryName` memberi pesan yang ramah di hampir semua kasus.
  - Expression index `lower(name)` di database menutup celah *race condition* (dua Admin menyimpan bersamaan).
  - Penolakan dari database diubah menjadi 422 dengan pesan yang sama, bukan 500.
  - Index dibuat lewat migration baru (batch 7 di Supabase) setelah dicek dengan query baca-saja bahwa tidak ada nama kembar dalam huruf kecil.
- **Index lama `categories_name_unique` dibiarkan.** Ia tidak berbahaya (semua yang lolos index baru pasti lolos index lama) dan menghapusnya hanya menambah migration tanpa manfaat berarti.
- **Test simpan bersamaan memakai model event.** Balapan sungguhan sulit dibuat di test, jadi test memasang `Category::creating(...)` / `Category::updating(...)` yang menyisipkan nama kembar langsung ke tabel **setelah** validasi lolos dan **sebelum** kategori disimpan. Ini persis celah waktu yang terjadi saat dua Admin menyimpan bersamaan.
- **Satu `CategoryRequest` untuk tambah dan ubah.** Aturannya sama; bedanya hanya id yang dikecualikan, yang diambil dari route.
- **Daftar kategori tanpa pagination.** Jumlah kategori kecil (PRD: 8 kategori contoh), dan aplikasi butuh semuanya untuk dropdown filter.
- **`items_count` hanya bahan aktif**, karena itu yang relevan untuk pengguna. Akibatnya, kategori bisa terlihat "0 bahan" tapi tetap tidak bisa dihapus (karena bahan terhapus). Pesan 409 menjelaskan alasan itu dengan jelas.
- **Hapus berhasil → 204**, sama seperti logout (tidak ada data untuk dikirim).
- **`PATCH` juga diterima** untuk ubah (bawaan `apiResource`), tapi aplikasi memakai `PUT` sesuai PRD.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Test otomatis:
   ```powershell
   php artisan test --filter=CategoryApiTest
   php artisan test
   php artisan route:list --path=api/v1/categories -v
   ```
   Harapan: 20 test kategori lulus; total 120 test lulus; route POST/PUT/DELETE punya `EnsureUserHasRole:admin`, sedangkan GET tidak.

   Cek index di Supabase:
   ```powershell
   php artisan migrate:status
   php artisan db:table categories
   ```
   Harapan: migration `..._add_lower_name_unique_index_to_categories_table` `Ran` di batch `[7]`; di bagian Index ada `categories_name_lower_unique ... btree, unique` (kolomnya tampil kosong karena index ini berbasis ekspresi, bukan kolom).

2. Thunder Client: jalankan `php artisan serve`, login Admin dan Staf (simpan kedua token), lalu ikuti langkah di laporan T-205:
   - Admin menambah `Bahan Kering` → 201
   - Admin mengubahnya → 200
   - Admin menghapusnya → 204
   - Staf menambah → 403
   - Admin menghapus `Daging & Unggas` → 409
   - Admin menambah `" daging & unggas "` → 422

## Kalau error, cek ini

- **403 "Akses ditolak." padahal login sebagai Admin** → token yang dipakai milik akun Staf. Cek dengan `GET /api/v1/auth/me` → `role` harus `admin`.
- **404 "Data tidak ditemukan." saat ubah/hapus** → id di URL tidak ada. Lihat id yang benar dari `GET /api/v1/categories`.
- **Error 500 saat menghapus kategori** → kemungkinan `withTrashed()` di `destroy()` terhapus, sehingga kategori yang hanya berisi bahan terhapus lolos pengecekan dan ditolak database (FK restrict).
- **Nama kembar beda huruf besar/kecil lolos** → aturan `new UniqueCategoryName(...)` hilang dari `CategoryRequest`, atau diganti `unique:categories,name` biasa. (Database tetap menolak lewat expression index, tapi pesannya bergantung pada `try`/`catch` di controller.)
- **Migration index gagal: `could not create unique index ... Key (lower((name)::text))=(...) is duplicated`** → di tabel sudah ada nama yang kembar dalam huruf kecil. Ganti salah satu nama dulu, lalu jalankan `php artisan migrate` lagi.

## Bahan menjelaskan saat wawancara

**1. "Bagaimana kamu memastikan nama kategori tidak dobel?"**
Dua lapis. Pertama, aturan validasi sendiri yang membandingkan nama dalam huruf kecil, karena aturan `unique` bawaan di PostgreSQL membedakan huruf besar/kecil. Kedua, expression index unik pada `lower(name)` di database, untuk kasus dua admin menyimpan di saat yang sama. Kalau database yang menolak, saya tangkap `UniqueConstraintViolationException` dan ubah menjadi 422 dengan pesan yang sama, jadi aplikasi tidak pernah melihat error 500.

**2. "Kenapa kategori tanpa bahan aktif tetap tidak bisa dihapus?"**
Karena bahan yang dihapus memakai soft delete. Barisnya masih ada, dan riwayat transaksinya masih menunjuk ke kategori itu. Database menolak penghapusan lewat foreign key restrict, jadi saya cek lebih dulu dengan `withTrashed()` dan membalas 409 dengan pesan yang menjelaskan alasannya, bukan error 500.

**3. "Kenapa 409, bukan 422 atau 400?"**
422 untuk input yang tidak valid, misalnya nama kosong. Di sini inputnya valid, tapi bentrok dengan keadaan data, yaitu masih dipakai bahan. 409 Conflict memang untuk situasi seperti itu, dan aplikasi cukup menampilkan pesan dari server.

## Coba sendiri (latihan)

Tambahkan parameter `?search=` di `GET /api/v1/categories` untuk mencari kategori berdasarkan nama, tanpa membedakan huruf besar/kecil (petunjuk: lihat cara `Item::search()` di T-105). Kalau `search` kosong, kembalikan semua kategori. Tulis dua test: satu yang memfilter (`?search=bumbu` hanya mengembalikan "Bumbu & Rempah"), satu tanpa `search`.
