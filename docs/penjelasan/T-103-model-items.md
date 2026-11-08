# T-103 — Model & migration `items`

## Ringkasan
Sekarang database punya tabel `items` untuk menyimpan bahan baku dapur: SKU, nama, kategori, satuan, stok, stok minimum, harga, foto, dan keterangan. Setiap bahan wajib masuk satu kategori, dan kategori yang masih dipakai tidak bisa dihapus. Bahan yang dihapus tidak benar-benar hilang (*soft delete*), sehingga riwayat transaksinya nanti tetap aman.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `database/migrations/2026_11_08_011434_create_items_table.php` | Dibuat | Membuat tabel `items` dengan index `name`, unique `sku`, FK `category_id` (restrict), soft deletes |
| `app/Models/Item.php` | Dibuat | Model Eloquent: `SoftDeletes`, casts `price`/`stock`/`min_stock`, daftar kolom yang boleh diisi massal |
| `database/factories/ItemFactory.php` | Dibuat | Data bahan palsu yang realistis (15 bahan dapur ayam/ikan bakar + satuan + harga), SKU `BHN-0001` |
| `database/seeders/ItemSeeder.php` | Dibuat | Kerangka seeder, **masih kosong**; diisi di T-106 |
| `tests/Feature/ItemTest.php` | Dibuat | 6 test: format SKU, casts, `stock` tidak bisa diisi massal, SKU unik, soft delete, FK restrict |

## Konsep yang dipakai

**Foreign key (FK)**
- Apa itu: kolom yang menunjuk ke `id` di tabel lain. `items.category_id` berisi `id` dari tabel `categories`. Database memastikan nilai itu benar-benar ada, jadi tidak mungkin ada bahan dengan kategori "hantu".
- Kenapa dipakai: setiap bahan harus punya kategori yang valid.

**`restrictOnDelete()`**
- Apa itu: aturan "jangan izinkan menghapus baris induk (kategori) selama masih ada baris anak (bahan) yang menunjuk ke situ". Pilihan lain: `cascadeOnDelete()` (bahan ikut terhapus) atau `nullOnDelete()` (kategori bahan dikosongkan).
- Kenapa dipakai: PRD F-14 meminta kategori yang masih dipakai tidak bisa dihapus. Nanti controller akan mengubah penolakan ini menjadi respons 409 dengan pesan yang ramah. Database tetap menjadi pengaman terakhir.

**Index biasa (`->index()`)**
- Apa itu: "daftar isi" untuk satu kolom, supaya database bisa mencari tanpa membaca seluruh tabel. Bedanya dengan `unique`: index biasa membolehkan nilai kembar.
- Kenapa dipakai: daftar bahan nanti sering dicari dan diurutkan berdasarkan `name` (fitur cari F-09). Nama bahan boleh kembar, jadi cukup index biasa, bukan unique.

**Soft delete**
- Apa itu: saat dihapus, baris tidak dibuang. Kolom `deleted_at` diisi waktu penghapusan, dan Eloquent otomatis menyembunyikan baris itu dari query biasa. Ibarat memindahkan berkas ke lemari arsip, bukan ke mesin penghancur kertas.
- Kenapa dipakai: PRD F-13. Bahan yang sudah punya riwayat transaksi tidak boleh hilang, karena laporan dan jejak audit (`stock_movements`) masih menunjuk ke bahan itu.

**Cast `decimal:2`**
- Apa itu: `price` dibaca sebagai teks berformat 2 angka di belakang koma, misalnya `"38000.00"`.
- Kenapa dipakai: uang tidak boleh disimpan sebagai `float`, karena `float` bisa tidak presisi (0.1 + 0.2 ≠ 0.3). Kolom `decimal(12,2)` menyimpan angka persis, dan cast `decimal:2` menjaga formatnya tetap konsisten di JSON.

**Unsigned integer**
- Apa itu: bilangan bulat yang tidak boleh negatif.
- Kenapa dipakai: stok tidak masuk akal kalau negatif. **Catatan penting:** PostgreSQL tidak punya tipe unsigned, jadi di Supabase kolom `stock` dan `min_stock` menjadi `integer` biasa (lihat Keputusan desain).

## Alur kode langkah demi langkah

1. `php artisan migrate` menjalankan `up()`. Urutan file penting: migration `categories` (005113) jalan **sebelum** `items` (011434), karena FK hanya bisa dibuat ke tabel yang sudah ada.

```php
$table->string('sku', 50)->unique();
$table->string('name', 150)->index();
// Kategori tidak bisa dihapus selama masih dipakai barang.
$table->foreignId('category_id')->constrained()->restrictOnDelete();
$table->string('unit', 20);
$table->unsignedInteger('stock')->default(0);
$table->unsignedInteger('min_stock')->default(0);
$table->decimal('price', 12, 2);
$table->softDeletes();
```
- `foreignId('category_id')` → kolom `bigint` (tipenya sama dengan `categories.id`).
- `->constrained()` → Laravel menebak tabel tujuan dari nama kolom: `category_id` → tabel `categories`, kolom `id`.
- `decimal('price', 12, 2)` → total 12 digit, 2 di belakang koma; maksimal 9.999.999.999,99.
- `softDeletes()` → menambah kolom `deleted_at` (boleh kosong).

2. Model `Item`:

```php
#[Fillable(['sku', 'name', 'category_id', 'unit', 'min_stock', 'price', 'description'])]
class Item extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'stock' => 'integer', 'min_stock' => 'integer'];
    }
}
```
- `stock` tidak ada di Fillable: `Item::create([... 'stock' => 50])` tetap menghasilkan stok 0. Stok hanya boleh berubah lewat transaksi (T-208), supaya selalu ada jejaknya di `stock_movements`.
- `SoftDeletes` → `$item->delete()` hanya mengisi `deleted_at`; `Item::find()` tidak lagi menemukannya, tapi `Item::withTrashed()->find()` masih bisa.

3. `ItemFactory` saat test memanggil `Item::factory()->create()`:

```php
$ingredient = fake()->randomElement(self::INGREDIENTS);

return [
    'sku' => $this->uniqueSku(),            // BHN-0001 .. BHN-9999, tidak kembar
    'name' => $ingredient['name'],          // contoh: Ayam Potong Utuh
    'category_id' => Category::factory(),   // buat kategori baru otomatis
    'unit' => $ingredient['unit'],          // ekor
    'stock' => fake()->numberBetween(0, 100),
    'min_stock' => fake()->numberBetween(5, 20),
    'price' => $ingredient['price'],        // 38000
];
```
- Nama, satuan, dan harga diambil dari **baris yang sama**, jadi tidak ada "Ayam Potong Utuh per liter".
- `'category_id' => Category::factory()` → Laravel membuat kategori dulu, lalu memakai `id`-nya. Karena T-102 sudah punya fallback nama, test yang membuat 20 bahan (= 20 kategori) tetap aman.
- Factory boleh mengisi `stock` walaupun tidak fillable, karena factory Laravel berjalan dalam mode *unguarded* (aturan Fillable dilewati khusus untuk factory).
- `sprintf('BHN-%04d', $number)` → angka diberi nol di depan sampai 4 digit: 7 → `BHN-0007`.

## Keputusan desain

- **`stock` dan `photo_path` tidak fillable.** `stock` mengikuti PRD (hanya berubah lewat transaksi). `photo_path` diisi dari hasil upload, sama seperti keputusan di `User` (T-101).
- **Stok tidak bisa negatif: belum dijaga di database PostgreSQL.** `unsignedInteger` hanya benar-benar berlaku di MySQL; PostgreSQL dan SQLite mengabaikannya. Pengamannya ada di logika transaksi (T-208): stok keluar/terbuang tidak boleh melebihi stok, di dalam `DB::transaction()` + `lockForUpdate()`, ditambah validasi `min_stock ≥ 0` di Form Request. Alternatifnya adalah CHECK constraint di PostgreSQL (`CHECK (stock >= 0)`). Ini tidak dipasang karena butuh SQL khusus PostgreSQL yang tidak jalan di SQLite untuk test. **Keputusan Aldi: opsi A** (tanpa CHECK constraint). Sebagai gantinya, T-208 wajib punya test bahwa stok tidak pernah negatif, termasuk dua transaksi keluar berturut-turut.
- **Kategori di factory tidak dicocokkan dengan bahan.** Bisa saja "Ayam Potong Utuh" masuk kategori "Kemasan". Untuk test ini tidak masalah, karena yang diuji adalah aturan, bukan kecocokan isi. Data demo yang rapi dan cocok dibuat di seeder T-106.
- **Nama bahan di factory boleh kembar.** Kolom `name` memang tidak unik (dua cabang supplier bisa punya "Beras" dengan SKU berbeda), jadi tidak perlu `unique()`.
- **SKU tetap terpakai setelah soft delete.** Bahan yang dihapus masih menyimpan SKU-nya, jadi SKU itu tidak bisa dipakai bahan baru. Ini sengaja (jejak audit jelas). Pesan validasinya nanti diatur di Form Request (T-207).
- **Relasi `category()` belum ditambahkan** karena semua relasi dikerjakan di T-105.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Status migration:
   ```powershell
   php artisan migrate:status
   ```
   Harapan: `2026_11_08_011434_create_items_table` → `Ran` di batch `[5]`.

2. Lihat struktur tabel di Supabase dari terminal:
   ```powershell
   php artisan db:table items
   ```
   Harapan: 13 kolom, index `items_name_index` dan `items_sku_unique`, foreign key `items_category_id_foreign` dengan On Delete `restrict`.

3. Test otomatis (SQLite in-memory):
   ```powershell
   php artisan test --filter=ItemTest
   php artisan test
   ```
   Harapan: 6 test `ItemTest` lulus; total 14 test lulus.

4. Contoh hasil factory tanpa menyimpan ke database:
   ```powershell
   php artisan tinker
   ```
   ```php
   App\Models\Item::factory()->make()->only(['sku', 'name', 'unit', 'price', 'stock']);
   // contoh: ["sku" => "BHN-0412", "name" => "Arang Batok", "unit" => "karung", "price" => "75000.00", "stock" => 63]
   ```
   Catatan: `make()` pada `Item` tetap **membuat kategori sungguhan** di database (karena `Category::factory()` harus punya `id`). Agar Supabase tidak terisi data acak, pakai `make(['category_id' => 1])` atau cukup jalankan `php artisan test`.

## Kalau error, cek ini

- **`SQLSTATE[42P01]: relation "categories" does not exist`** saat migrate → migration `items` jalan sebelum `categories`. Cek tanggal di nama file: file `categories` harus lebih awal.
- **`SQLSTATE[23503]: Foreign key violation ... items_category_id_foreign`** → (a) menyimpan bahan dengan `category_id` yang tidak ada, atau (b) menghapus kategori yang masih dipakai bahan. Untuk (b), ini perilaku yang benar (restrict).
- **Bahan "hilang" setelah dihapus tapi SKU-nya tidak bisa dipakai lagi** → itu soft delete: barisnya masih ada dengan `deleted_at` terisi. Cek dengan `Item::withTrashed()->where('sku', 'BHN-0001')->first()`.
- **`stock` selalu 0 padahal sudah dikirim** → memang begitu, karena `stock` tidak fillable. Stok hanya berubah lewat transaksi.

## Bahan menjelaskan saat wawancara

**1. "Kenapa memakai soft delete untuk barang?"**
Karena barang punya riwayat transaksi. Kalau barangnya dihapus permanen, riwayat stok masuk/keluar jadi menunjuk ke data yang tidak ada dan laporan jadi rusak. Dengan soft delete, barang hanya disembunyikan dari daftar, tapi datanya tetap ada untuk jejak audit.

**2. "Kenapa stok tidak bisa diubah langsung lewat form barang?"**
Supaya setiap perubahan stok selalu tercatat sebagai transaksi: siapa, kapan, berapa, dan alasannya. Kalau stok bisa diedit langsung, angka bisa berubah tanpa jejak. Karena itu `stock` tidak saya masukkan ke fillable. Perubahan stok hanya lewat endpoint transaksi, di dalam database transaction.

**3. "Kenapa harga pakai decimal, bukan float?"**
Float menyimpan angka secara perkiraan, jadi bisa muncul selisih kecil seperti 0,30000000000000004. Untuk uang harus persis, jadi saya pakai `decimal(12,2)` di database dan cast `decimal:2` di model.

## Coba sendiri (latihan)

Tambahkan state `lowStock()` di `ItemFactory` yang membuat bahan dengan stok di bawah atau sama dengan stok minimum (misalnya `stock` 2, `min_stock` 10). Lalu tulis satu test di `ItemTest` yang membuat 3 bahan dengan state itu dan memastikan setiap bahan memenuhi `stock <= min_stock`. Jalankan `php artisan test` sampai lulus.
