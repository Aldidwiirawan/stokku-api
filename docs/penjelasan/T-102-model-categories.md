# T-102 — Model & migration `categories`

## Ringkasan
Sekarang database punya tabel `categories` untuk mengelompokkan bahan baku dapur, misalnya "Daging & Unggas" atau "Bumbu & Rempah". Laravel punya model `Category` untuk membaca/menulis tabel itu. Ada juga factory yang bisa membuat kategori palsu bernama kategori kuliner untuk keperluan test.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `database/migrations/2026_11_08_005113_create_categories_table.php` | Dibuat | Membuat tabel `categories` (`up()`) dan menghapusnya (`down()`) |
| `app/Models/Category.php` | Dibuat | Model Eloquent untuk tabel `categories`; hanya `name` yang boleh diisi massal |
| `database/factories/CategoryFactory.php` | Dibuat | Pembuat data kategori palsu: pakai 12 nama kategori bahan kuliner dulu, setelah habis pakai nama pengganti "Kategori Bahan N" |
| `database/seeders/CategorySeeder.php` | Dibuat | Kerangka seeder, **masih kosong**; diisi di T-106 |
| `tests/Feature/CategoryTest.php` | Dibuat | Test: nama dari factory valid & unik, factory sanggup membuat 20 kategori sekaligus, nama ganda ditolak database |

## Konsep yang dipakai

**Model (Eloquent)**
- Apa itu: class PHP yang mewakili satu tabel. Satu objek `Category` = satu baris di tabel `categories`. Eloquent adalah nama sistem ORM Laravel. ORM (*Object-Relational Mapping*) artinya kita mengolah data lewat objek PHP, bukan menulis SQL manual.
- Kenapa dipakai: nanti controller cukup menulis `Category::create([...])` atau `Category::all()`, tanpa SQL.

**Konvensi nama tabel**
- Apa itu: Laravel otomatis menebak nama tabel dari nama model: `Category` → `categories` (huruf kecil, bentuk jamak bahasa Inggris).
- Kenapa dipakai: karena nama mengikuti aturan, kita tidak perlu menulis `protected $table = 'categories';`.

**`make:model -mfs`**
- Apa itu: satu perintah untuk membuat model sekaligus **m**igration, **f**actory, dan **s**eeder.
- Kenapa dipakai: hemat waktu, dan nama keempat file otomatis konsisten.

**Index `unique`**
- Apa itu: aturan di database bahwa nilai satu kolom tidak boleh sama di dua baris. Database juga membuat *index* (mirip daftar isi buku) supaya pencarian berdasarkan kolom itu cepat.
- Kenapa dipakai: tidak boleh ada dua kategori bernama "Bumbu & Rempah". Ini jadi pengaman terakhir di database, walaupun nanti Form Request juga akan memvalidasi `unique` dan memberi pesan yang ramah.

**Factory dan `fake()->unique()`**
- Apa itu: factory membuat data palsu. `fake()` adalah library Faker. `unique()` memastikan nilai yang sama tidak dikembalikan dua kali.
- Kenapa dipakai: kolom `name` unik di database, jadi factory harus menghasilkan nama berbeda setiap kali. Kalau tidak, test yang membuat beberapa kategori akan error.

**Exception dan `try` / `catch`**
- Apa itu: exception adalah "sinyal error" yang dilempar kode saat sesuatu gagal. `try { ... } catch (JenisError) { ... }` artinya: coba jalankan kode di `try`; kalau muncul error jenis itu, jangan berhenti, jalankan rencana cadangan di `catch`.
- Kenapa dipakai: saat 12 nama di daftar sudah terpakai semua, `fake()->unique()` melempar `OverflowException` karena tidak ada lagi nilai unik. Kita menangkap error itu lalu membuat nama pengganti, jadi factory tidak pernah gagal walaupun diminta membuat banyak kategori.

**Seeder**
- Apa itu: class untuk mengisi database dengan data awal/contoh.
- Kenapa dibiarkan kosong: `-s` membuat filenya sekarang, tapi data kategori asli (8 kategori) adalah tugas T-106. Satu task = satu fokus.

## Alur kode langkah demi langkah

1. `php artisan migrate` dijalankan. Laravel melihat file `create_categories_table` belum tercatat di tabel `migrations`, lalu memanggil `up()`:

```php
Schema::create('categories', function (Blueprint $table) {
    $table->id();                                  // bigint auto increment, primary key
    $table->string('name', 100)->unique();         // maks 100 karakter, tidak boleh kembar
    $table->timestamps();                          // created_at & updated_at
});
```
- `Schema::create` (bukan `Schema::table` seperti T-101) → membuat tabel **baru**.
- `string('name', 100)` → di PostgreSQL jadi `varchar(100)`, sesuai PRD.
- `->unique()` → membuat index unik bernama `categories_name_unique`.

2. Model `Category` siap dipakai:

```php
#[Fillable(['name'])]
class Category extends Model
{
    use HasFactory;
}
```
- `#[Fillable(['name'])]` → PHP Attribute (label di atas class) yang berarti hanya `name` boleh diisi lewat `Category::create([...])`. Gayanya sama dengan model `User`.
- `HasFactory` → membuat `Category::factory()` bisa dipanggil.

3. Saat test memanggil `Category::factory()->count(20)->create()`, untuk setiap kategori `definition()` memanggil `uniqueName()`:

```php
private function uniqueName(): string
{
    try {
        return fake()->unique()->randomElement(self::NAMES);
    } catch (OverflowException) {
        // Semua nama di daftar sudah terpakai.
        $number = fake()->unique()->numberBetween(count(self::NAMES) + 1, 99999);

        return 'Kategori Bahan '.$number;
    }
}
```
- `randomElement(self::NAMES)` → ambil satu nama acak dari daftar 12 nama.
- `unique()` → nama yang sudah keluar tidak akan dipilih lagi.
- Kategori ke-1 sampai ke-12 mendapat nama dari daftar.
- Kategori ke-13: daftar sudah habis, Faker melempar `OverflowException`, lalu kode masuk ke `catch`.
- Di `catch`, dipilih angka unik mulai 13 (`count(self::NAMES) + 1`), misalnya `Kategori Bahan 4821`. `unique()` juga dipakai di sini supaya angkanya tidak kembar.
- Hasilnya 20 baris di tabel `categories` dengan nama berbeda semua.
- "Satu test" di sini penting: Laravel mengosongkan ingatan `unique()` di awal setiap test, jadi setiap test mulai lagi dari 12 nama di daftar.

4. Kalau ada yang mencoba menyimpan nama yang sudah ada, database menolak dan Laravel melempar `QueryException`. Test `test_category_name_must_be_unique_in_database` membuktikan hal ini.

## Keputusan desain

- **Nama kategori dari daftar tetap, bukan `fake()->word()`.** Kata acak dari Faker berbahasa Inggris dan tidak masuk akal ("lorem", "voluptas"). Daftar tetap membuat data test dan demo terlihat seperti dapur sungguhan.
- **Daftar disimpan sebagai `public const NAMES`.** Dengan begitu test bisa mengecek "nama yang dihasilkan pasti dari daftar ini" tanpa menyalin daftarnya.
- **Ada nama pengganti setelah daftar habis.** Versi awal factory hanya sanggup membuat 12 kategori per test. Masalahnya, di T-103 `ItemFactory` kemungkinan membuat satu kategori baru untuk setiap barang (`Category::factory()`). Test yang membuat 20 barang akan butuh 20 kategori, dan langsung gagal dengan `OverflowException`. Karena itu ditambah fallback (rencana cadangan) "Kategori Bahan N".
  - Alternatif 1: menambah daftar sampai ratusan nama. Ditolak karena merepotkan dan tetap ada batasnya.
  - Alternatif 2: menyimpan penghitung sendiri (`static $counter`). Ditolak karena nilai `static` tidak ter-reset di antara test, jadi hasil test bisa berbeda tergantung urutan test dijalankan.
  - Yang dipilih (`try`/`catch`) paling sederhana dibaca dan memanfaatkan `unique()` milik Faker yang memang di-reset Laravel di setiap test.
- **Angka nama pengganti acak, bukan berurutan 13, 14, 15.** Membuat angka berurutan butuh penghitung yang di-reset per test (masalah yang sama seperti alternatif 2). Angka acak yang unik sudah cukup, karena yang penting namanya tidak kembar.
- **Catatan performa kecil:** setelah daftar habis, setiap kategori baru membuat Faker mencoba dulu sampai 10.000 kali sebelum menyerah dan masuk `catch`. Untuk puluhan kategori, ini hanya beberapa milidetik, jadi tidak masalah untuk test.
- **Relasi `items()` belum ditambahkan.** Tabel `items` baru dibuat di T-103 dan semua relasi disatukan di T-105, sesuai TASKS.
- **Tidak menambah `description` atau kolom lain.** PRD hanya meminta `id`, `name`, dan `timestamps`.
- **Import yang tidak terpakai di seeder dihapus Pint.** Laravel Pint adalah alat perapi gaya kode PHP; ia menghapus `use ...WithoutModelEvents` yang tidak dipakai.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Status migration:
   ```powershell
   php artisan migrate:status
   ```
   Harapan: `2026_11_08_005113_create_categories_table` berstatus `Ran` di batch `[4]`.

2. Test otomatis (SQLite in-memory, tidak menyentuh Supabase):
   ```powershell
   php artisan test
   ```
   Harapan: 8 test lulus, termasuk 3 test di `CategoryTest`. Hanya test kategori:
   ```powershell
   php artisan test --filter=CategoryTest
   ```

3. Supabase → **Table Editor** → tabel `categories`. Harapan: kolom `id` (int8), `name` (varchar 100), `created_at`, `updated_at`. Tabelnya masih kosong; itu wajar, karena data diisi di T-106.

4. Lihat contoh hasil factory tanpa menyimpan ke database:
   ```powershell
   php artisan tinker
   ```
   ```php
   App\Models\Category::factory()->count(3)->make()->pluck('name');
   // contoh: ["Kemasan", "Bumbu & Rempah", "Buah"]

   App\Models\Category::factory()->count(15)->make()->pluck('name');
   // 12 nama dari daftar + 3 nama seperti "Kategori Bahan 52817"
   ```
   Di Tinker, ingatan `unique()` tidak di-reset di antara perintah. Jadi perintah kedua bisa langsung berisi lebih banyak nama "Kategori Bahan". Ketik `exit` lalu buka Tinker lagi untuk mulai dari awal.

## Kalau error, cek ini

- **`OverflowException: Maximum retries of 10000 reached without finding a unique value`** → seharusnya tidak muncul lagi dari `CategoryFactory`, karena sudah ditangkap `catch`. Kalau tetap muncul, cek apakah baris `use OverflowException;` di atas file factory terhapus. Tanpa baris itu, PHP mencari class `Database\Factories\OverflowException` yang tidak ada, sehingga `catch` tidak pernah cocok.
- **`SQLSTATE[23505]: Unique violation ... categories_name_unique`** (PostgreSQL) atau **`UNIQUE constraint failed: categories.name`** (SQLite) → kamu menyimpan nama kategori yang sudah ada. Ini memang perilaku yang diinginkan.
- **`Add [name] to fillable property to allow mass assignment`** atau `name` tidak tersimpan → atribut `#[Fillable(['name'])]` hilang atau salah ketik di model.
- **`relation "categories" already exists`** saat migrate → tabel pernah dibuat manual di Supabase. Hapus tabel manual itu dari Table Editor (pastikan kosong), lalu jalankan `php artisan migrate` lagi.

## Bahan menjelaskan saat wawancara

**1. "Kenapa nama kategori dibuat unique di database, padahal nanti divalidasi juga di Form Request?"**
Validasi di Form Request untuk memberi pesan yang ramah ke pengguna. Index unique di database adalah pengaman terakhir: kalau ada dua request bersamaan, atau ada kode lain yang lupa validasi, database tetap menolak data kembar.

**2. "Apa itu factory dan kenapa perlu?"**
Factory adalah pembuat data palsu. Di test, saya butuh data kategori tanpa mengetik manual setiap kali. Saya buat factory-nya memakai nama kategori kuliner yang realistis, dan memakai `unique()` karena kolom `name` tidak boleh kembar. Kalau daftar namanya habis, factory otomatis memakai nama pengganti seperti "Kategori Bahan 13", jadi test yang butuh banyak data tidak gagal.

**3. "Bedanya `Schema::create` dan `Schema::table`?"**
`Schema::create` membuat tabel baru, seperti `categories` di task ini. `Schema::table` mengubah tabel yang sudah ada, seperti saat saya menambah kolom `role` ke `users` di T-101.

## Coba sendiri (latihan)

Tambahkan state baru di `CategoryFactory` bernama `named(string $name)` supaya di test bisa menulis `Category::factory()->named('Bahan Pokok')->create()`. Lalu tulis satu test kecil di `CategoryTest` yang membuktikan nama kategori yang tersimpan memang `Bahan Pokok`. Jalankan `php artisan test` sampai lulus.
