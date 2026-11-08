# T-106 — Seeder data contoh

## Ringkasan
Dengan satu perintah `php artisan db:seed`, database terisi data contoh yang siap didemokan:
- 2 akun demo: admin dan staf
- 8 kategori dan 30 bahan baku dapur ayam bakar/ikan bakar
- 40 transaksi contoh (masuk, keluar, terbuang dengan alasan, penyesuaian)

Stok setiap bahan selalu sama dengan `stock_after` transaksi terakhirnya, dan 6 bahan sengaja dibuat di bawah stok minimum. Perintah ini aman dijalankan berkali-kali: tidak error dan tidak membuat data dobel.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `database/seeders/DatabaseSeeder.php` | Diubah | Memanggil 4 seeder dengan urutan yang benar; contoh `test@example.com` bawaan Laravel dihapus |
| `database/seeders/UserSeeder.php` | Dibuat | 2 akun demo (`admin@stokku.test`, `staf@stokku.test`, password `password`) |
| `database/seeders/CategorySeeder.php` | Diubah | 8 kategori bahan kuliner |
| `database/seeders/ItemSeeder.php` | Diubah | 30 bahan (SKU `BHN-0001` s.d. `BHN-0030`) dengan satuan, harga, stok minimum, dan stok awal |
| `database/seeders/StockMovementSeeder.php` | Dibuat | 40 transaksi contoh + menyesuaikan stok bahan; hanya jalan jika tabel transaksi masih kosong |
| `tests/Feature/DatabaseSeederTest.php` | Dibuat | 8 test: jumlah data, stok = `stock_after` terakhir, konsistensi tiap transaksi, jenis & alasan lengkap, stok menipis, transaksi hari ini, jalan 2×, data yang diubah pengguna tidak ditimpa |

## Konsep yang dipakai

**Seeder**
- Apa itu: class yang mengisi database dengan data awal atau data contoh. `DatabaseSeeder` adalah seeder utama yang dijalankan oleh `php artisan db:seed`, dan ia memanggil seeder lain lewat `$this->call([...])`.
- Kenapa dipakai: pewawancara harus bisa langsung mencoba aplikasi dengan data yang masuk akal, tanpa Aldi mengetik 30 bahan secara manual.

**Idempotent (aman dijalankan ulang)**
- Apa itu: proses yang hasilnya sama walaupun dijalankan sekali atau berkali-kali. Seperti menekan tombol lift yang sudah menyala: lift tidak datang dua kali.
- Kenapa dipakai: `db:seed` bisa saja dijalankan lagi, misalnya setelah deploy ulang atau karena lupa sudah pernah. Tanpa sifat ini, run kedua akan error (email/SKU kembar) atau membuat data dobel.

**`firstOrCreate` dan `updateOrCreate`**
- Apa itu: `firstOrCreate(['email' => ...], [...])` mencari baris berdasarkan array pertama; kalau tidak ada, membuat baru dengan gabungan kedua array. `updateOrCreate` mirip, tapi kalau barisnya ada, kolom di array kedua **diperbarui**.
- Kenapa dipakai: akun dan kategori cukup dibuat sekali (`firstOrCreate`). Data bahan (nama, harga, satuan) memakai `updateOrCreate` berdasarkan SKU, supaya kalau daftar di seeder diperbaiki, data di database ikut terbarui.

**Transaksi database (`DB::transaction`) dan `lockForUpdate()`**
- Apa itu: `DB::transaction` membungkus banyak perintah menjadi satu kesatuan: semua berhasil, atau semua dibatalkan (*rollback*). `lockForUpdate()` mengunci baris yang dibaca sampai transaksi selesai, supaya proses lain tidak bisa mengubahnya di saat yang sama.
- Kenapa dipakai: aturan proyek mewajibkan setiap perubahan stok memakai keduanya. Di seeder ada alasan tambahan: kalau gagal di transaksi ke-25, tanpa `DB::transaction` tabel akan terisi 24 baris, lalu run berikutnya melewati seeder karena tabel "sudah tidak kosong". Data jadi setengah jadi selamanya.

## Alur kode langkah demi langkah

1. `php artisan db:seed` → `DatabaseSeeder::run()` memanggil seeder dengan urutan: akun → kategori → bahan → transaksi. Urutannya penting karena bahan butuh `category_id`, dan transaksi butuh bahan serta akun.

2. **`UserSeeder`**

```php
$user = User::firstOrCreate(
    ['email' => $email],
    ['name' => $name, 'password' => 'password'],
);

$user->role = $role;          // role tidak fillable → diisi eksplisit
$user->is_active = true;
$user->save();                // hanya menulis ke DB kalau ada yang berubah
```
- Password `'password'` otomatis di-hash oleh cast `hashed` di model `User`.
- Password hanya diisi saat akun **dibuat**. Kalau Aldi mengganti password demo lewat aplikasi, run berikutnya tidak mengembalikannya.

3. **`CategorySeeder`** → `Category::firstOrCreate(['name' => $name])` untuk 8 nama.

4. **`ItemSeeder`**

```php
$categoryIds = Category::pluck('id', 'name');   // ['Daging & Unggas' => 1, ...]

Item::withTrashed()->updateOrCreate(
    ['sku' => $data['sku']],
    ['name' => ..., 'category_id' => $categoryIds[$data['category']], 'unit' => ..., 'price' => ..., 'min_stock' => ...],
);
```
- `pluck('id', 'name')` → satu query untuk semua kategori, bukan 30 query.
- `withTrashed()` → bahan yang sudah di-soft delete tetap ditemukan. Tanpa ini, `updateOrCreate` mengira bahannya tidak ada, mencoba membuat baru dengan SKU yang sama, lalu error karena SKU unik.
- Kolom `stock` **tidak** diisi; bahan baru mulai dari 0 (default kolom).

5. **`StockMovementSeeder`**
   - Kalau `StockMovement::exists()` → tampilkan pesan dan berhenti. Ini yang mencegah transaksi dobel.
   - `plan()` menyusun 40 transaksi berurutan waktu:
     - 30 transaksi "masuk" stok awal dari supplier, 7 hari lalu
     - lalu 10 aktivitas: keluar ×3, terbuang ×4 (kedaluwarsa, rusak, gosong, lainnya + catatan), penyesuaian ×2 (−2 dan +1), masuk ×1
     - 3 di antaranya dibuat hari ini, supaya dashboard punya angka "hari ini"
   - Semua dijalankan di dalam satu `DB::transaction`, dan setiap transaksi diproses oleh `record()`:

```php
$item = Item::where('sku', $entry['sku'])->lockForUpdate()->firstOrFail();
$stockBefore = $item->stock;

if ($type === MovementType::Adjust) {
    $stockAfter = $entry['quantity'];           // stok fisik hasil hitung
    $quantity = $stockAfter - $stockBefore;     // selisih yang disimpan, bisa minus
} else {
    $quantity = $entry['quantity'];
    $stockAfter = $type === MovementType::In ? $stockBefore + $quantity : $stockBefore - $quantity;
}
```
   - Stok baru dihitung dari stok saat ini, bukan dari angka yang ditulis manual. Karena itu `stock_after` pasti cocok.
   - Kalau hasilnya negatif → `RuntimeException`, dan seluruh transaksi database dibatalkan.
   - Lalu `items.stock` diperbarui dan baris `stock_movements` disimpan (`user_id`, `stock_after`, `created_at` diisi eksplisit).

## Keputusan desain

- **Stok awal dicatat sebagai transaksi "masuk", bukan langsung diisi ke `items.stock`.** Dengan begitu setiap angka stok punya jejak, dan aturan "stok = `stock_after` transaksi terakhir" berlaku untuk semua bahan. Akibatnya, dari 40 transaksi, 31 berjenis `in` (30 stok awal + 1 kiriman supplier hari ini).
- **Transaksi hanya dibuat jika tabel kosong (permintaan Aldi).** Alternatifnya mencocokkan transaksi satu per satu, tapi transaksi tidak punya kunci unik alami seperti SKU, jadi caranya rumit dan rawan dobel.
- **Tidak memakai factory/Faker di seeder.** Paket Faker hanya terpasang di lingkungan pengembangan (`require-dev`). Di server produksi (`composer install --no-dev`), seeder yang memakai factory akan error. Data yang ditulis manual juga lebih realistis dan bisa dijelaskan.
- **`updateOrCreate` bahan menimpa nama/harga/satuan/stok minimum dengan isi seeder.** Kalau Admin mengubah harga lewat aplikasi lalu seeder dijalankan lagi, harga kembali ke angka seeder. Ini wajar untuk data demo. `stock` tidak pernah ditimpa.
- **Bahan yang sudah dihapus tidak dipulihkan.** `updateOrCreate` memperbarui datanya, tapi `deleted_at` tetap terisi, jadi keputusan menghapus tetap dihormati.
- **Logika hitung stok ditulis di seeder dulu.** Di T-208 akan ada `App\Services\StockService` dengan logika yang sama. Setelah itu seeder bisa diubah untuk memakai service tersebut, supaya aturannya ada di satu tempat.
- **Waktu transaksi relatif terhadap saat seeder dijalankan** (`now()->subDays(...)`), supaya data selalu terlihat "baru" kapan pun di-seed. Transaksi "hari ini" dibuat 10–30 menit sebelum seeder dijalankan.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Test otomatis (SQLite in-memory):
   ```powershell
   php artisan test --filter=DatabaseSeederTest
   php artisan test
   ```
   Harapan: 8 test seeder lulus; total 47 test lulus.

2. Jalankan seeder (ke database di `.env`, yaitu Supabase; minta izin dulu sesuai TASKS). Pada 2026-11-08 seeder sudah dijalankan **sekali** ke Supabase atas izin Aldi. Hasilnya: 2 akun, 8 kategori, 30 bahan, 40 transaksi, 0 bahan yang stoknya berbeda dari `stock_after` terakhir, dan 6 bahan stok menipis. Run kedua di Supabase tidak dilakukan; keamanan run ulang dibuktikan di SQLite dan di test.
   ```powershell
   php artisan db:seed
   php artisan db:seed
   ```
   Harapan:
   - Run pertama: keempat seeder `DONE`.
   - Run kedua: juga `DONE`, dengan pesan "Tabel stock_movements sudah berisi data, transaksi contoh dilewati."
   - Tidak ada error.

3. Cek di Supabase → Table Editor:
   - `users` 2 baris, `categories` 8, `items` 30, `stock_movements` 40.
   - Di `items`, kolom `stock` bahan BHN-0001 (Ayam Potong Utuh) = **23**, dan transaksi terakhirnya di `stock_movements` punya `stock_after` = 23.

4. Di Tinker:
   ```php
   App\Models\Category::first()?->items->pluck('name');   // bahan kategori Daging & Unggas
   App\Models\Item::lowStock()->pluck('name');            // 6 bahan stok menipis
   App\Models\Item::search('ayam')->pluck('name');        // Ayam Potong Utuh, Ati Ampela Ayam, Telur Ayam
   ```

## Kalau error, cek ini

- **`Unique violation ... users_email_unique`** atau **`items_sku_unique`** → ada seeder yang memakai `create()` biasa atau factory, bukan `firstOrCreate`/`updateOrCreate`. Untuk bahan, cek juga apakah `withTrashed()` hilang (bahan yang di-soft delete mencoba dibuat ulang).
- **`No query results for model [App\Models\User]`** di `StockMovementSeeder` → akun demo belum ada. Pastikan `UserSeeder` dipanggil **sebelum** `StockMovementSeeder` di `DatabaseSeeder`.
- **`Undefined array key "Daging & Unggas"`** di `ItemSeeder` → nama kategori di `ItemSeeder::ITEMS` tidak sama persis dengan `CategorySeeder::NAMES` (perhatikan spasi dan tanda `&`), atau `CategorySeeder` belum dijalankan.
- **Transaksi contoh tidak bertambah setelah seeder dijalankan lagi** → memang begitu. `StockMovementSeeder` hanya jalan jika tabel `stock_movements` kosong.

## Bahan menjelaskan saat wawancara

**1. "Bagaimana memastikan seeder tidak membuat data dobel?"**
Akun dan kategori saya buat dengan `firstOrCreate`, bahan dengan `updateOrCreate` berdasarkan SKU, jadi data yang sudah ada hanya dicari atau diperbarui. Transaksi contoh tidak punya kunci unik, jadi saya buat aturan: hanya diisi kalau tabelnya masih kosong. Semua ini ada test-nya, termasuk menjalankan seeder dua kali.

**2. "Bagaimana memastikan stok contoh konsisten dengan riwayat?"**
Stok tidak saya ketik langsung. Setiap stok, termasuk stok awal, dibuat lewat transaksi. Seeder menghitung stok baru dari stok saat ini, menyimpan `stock_after`, lalu memperbarui stok bahan, semuanya dalam satu database transaction dengan `lockForUpdate`. Ada test yang mengecek stok setiap bahan sama dengan `stock_after` transaksi terakhirnya.

**3. "Kenapa seeder tidak memakai factory?"**
Factory memakai Faker, yang hanya terpasang di lingkungan development. Kalau seeder dijalankan di server produksi tanpa paket development, seeder akan error. Selain itu, data yang ditulis manual lebih realistis untuk demo.

## Coba sendiri (latihan)

Tambahkan satu bahan baru ke `ItemSeeder::ITEMS`: "Jeruk Nipis" (SKU `BHN-0031`, kategori Sayur & Lalapan, satuan kg, harga 18000, stok minimum 3, stok awal 6). Jalankan test dan perhatikan test mana yang gagal, lalu putuskan sendiri: apakah test-nya yang perlu diubah, atau seeder-nya? Pikirkan juga: kalau seeder dijalankan ulang di database yang tabel transaksinya sudah berisi, berapa stok Jeruk Nipis, dan apakah itu masih memenuhi aturan "stok = `stock_after` transaksi terakhir"?
