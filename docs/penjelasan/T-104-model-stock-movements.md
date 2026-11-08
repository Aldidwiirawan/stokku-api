# T-104 — Model & migration `stock_movements`

## Ringkasan
Sekarang database punya tabel `stock_movements` untuk mencatat setiap perubahan stok: bahan masuk, keluar (dipakai dapur), terbuang, dan penyesuaian (stock opname). Jenis transaksi dan alasan bahan terbuang disimpan sebagai teks di database, tapi di PHP dibaca sebagai **enum** (`MovementType`, `WasteReason`) yang punya label berbahasa Indonesia. Riwayat hanya punya `created_at` karena transaksi yang sudah dicatat tidak pernah diubah.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `app/Enums/MovementType.php` | Dibuat | Enum jenis transaksi: `in`, `out`, `waste`, `adjust` + `label()` (Masuk, Keluar, Terbuang, Penyesuaian) |
| `app/Enums/WasteReason.php` | Dibuat | Enum alasan terbuang: `expired`, `damaged`, `cooking_error`, `other` + `label()` |
| `database/migrations/2026_11_08_012344_create_stock_movements_table.php` | Dibuat | Membuat tabel `stock_movements` dengan FK restrict ke `items` & `users`, index `item_id`, `user_id`, dan `created_at` |
| `app/Models/StockMovement.php` | Dibuat | Model: tanpa `updated_at`, cast `type`/`reason` ke enum, daftar Fillable |
| `tests/Unit/MovementTypeTest.php` | Dibuat | Unit test nilai & label `MovementType` |
| `tests/Unit/WasteReasonTest.php` | Dibuat | Unit test nilai & label `WasteReason` |
| `tests/Feature/StockMovementTest.php` | Dibuat | 8 test: cast enum, tersimpan sebagai teks, `reason` boleh kosong, quantity adjust negatif, hanya `created_at`, mass assignment, jenis tak dikenal ditolak, FK restrict |

## Konsep yang dipakai

**Enum (PHP 8.1+)**
- Apa itu: tipe data yang nilainya hanya boleh salah satu dari daftar tetap. Mirip pilihan di formulir kertas: "Jenis: ☐ Masuk ☐ Keluar ☐ Terbuang ☐ Penyesuaian". Tidak bisa diisi pilihan lain.
- Kenapa dipakai: tanpa enum, jenis transaksi hanya string biasa, dan salah ketik seperti `'wast'` tidak ketahuan. Dengan enum, `MovementType::Waste` dicek oleh PHP dan editor bisa melengkapi namanya otomatis.

**Backed enum (`enum MovementType: string`)**
- Apa itu: enum yang setiap pilihannya punya nilai string. `MovementType::Waste` punya nilai `'waste'`. Nilai string inilah yang disimpan di database.
- Kenapa dipakai: database tidak mengerti enum PHP, jadi butuh nilai teks yang tetap. Nilai teksnya sama persis dengan yang ditulis di PRD dan API (`in`, `out`, `waste`, `adjust`).

**Method di enum (`label()`) dan `match`**
- Apa itu: enum boleh punya function. `match ($this)` adalah cara ringkas memilih hasil berdasarkan nilai, mirip `switch` tapi langsung mengembalikan nilai, dan PHP akan error kalau ada pilihan yang lupa ditangani.
- Kenapa dipakai: teks untuk pengguna ("Terbuang", "Gosong/Salah Olah") ditulis di satu tempat. Nanti API Resource cukup memanggil `$movement->type->label()`.

**Enum cast di Eloquent**
- Apa itu: `'type' => MovementType::class` di `casts()` membuat Eloquent otomatis mengubah teks `'waste'` dari database menjadi `MovementType::Waste` saat dibaca, dan sebaliknya saat disimpan.
- Kenapa dipakai: kode lain selalu bekerja dengan enum, tidak dengan string mentah. Kalau ada yang mencoba mengisi nilai yang tidak dikenal (`'hilang'`), PHP langsung melempar `ValueError`.

**`const UPDATED_AT = null`**
- Apa itu: memberi tahu Eloquent bahwa tabel ini tidak punya kolom `updated_at`, jadi Eloquent hanya mengisi `created_at`.
- Kenapa dipakai: riwayat transaksi adalah **jejak audit**, yaitu catatan resmi yang tidak boleh diubah. Kalau ada kesalahan, koreksinya dicatat sebagai transaksi baru (misalnya `adjust`), bukan dengan mengedit baris lama.

**Unit test vs feature test**
- Apa itu: unit test menguji satu bagian kecil secara terpisah, tanpa Laravel dan tanpa database (di sini: enum). Feature test menjalankan Laravel lengkap dengan database SQLite in-memory.
- Kenapa dipakai: enum hanya PHP murni, jadi cukup unit test yang sangat cepat (`tests/Unit`). Model butuh database, jadi pakai feature test (`tests/Feature`).

## Alur kode langkah demi langkah

1. `php artisan migrate` membuat tabel. Urutannya: `users` → `categories` → `items` → `stock_movements`, karena tabel ini punya FK ke `items` dan `users`.

```php
$table->foreignId('item_id')->index()->constrained()->restrictOnDelete();
$table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
$table->string('type', 20);
$table->integer('quantity');                  // boleh negatif (untuk adjust)
$table->string('reason', 30)->nullable();     // hanya terisi untuk waste
$table->unsignedInteger('stock_after');
$table->string('note', 255)->nullable();
$table->timestamp('created_at')->useCurrent()->index();
```
- `->index()` pada `item_id` dan `user_id` → PostgreSQL **tidak** otomatis membuat index untuk kolom foreign key (MySQL melakukannya, PostgreSQL tidak). Urutan penulisannya penting: `->index()` harus sebelum `->constrained()`, karena setelah `constrained()` yang diatur adalah foreign key, bukan kolomnya.
- `integer('quantity')` (bukan unsigned): untuk `adjust`, quantity berisi selisih yang bisa minus, misalnya stok sistem 10, stok fisik 7 → `-3`.
- `timestamp('created_at')->useCurrent()` → kalau tidak diisi, database mengisi waktu saat ini. Eloquent biasanya sudah mengisinya; ini pengaman tambahan.
- `->index()` pada `created_at` → riwayat sering difilter tanggal (`from`, `to`) dan diurutkan terbaru, jadi perlu index.

2. Model menyimpan transaksi. Contoh cara menyimpannya nanti di `StockService` (T-208), sama seperti helper di test:

```php
$movement = new StockMovement;
$movement->fill(array_merge(['item_id' => $item->id], $input)); // type, quantity, reason, note
$movement->user_id = $user->id;          // diisi eksplisit: siapa yang login
$movement->stock_after = $stockAfter;    // diisi eksplisit: hasil hitungan server
$movement->save();
```
- `fill()` hanya mengisi kolom yang ada di `#[Fillable]`. Kalau `$input` membawa `user_id` atau `stock_after`, keduanya diabaikan.
- Saat `save()`, enum cast mengubah `MovementType::Waste` menjadi `'waste'`, dan Eloquent mengisi `created_at` saja.

3. Saat dibaca kembali:

```php
$movement->type;                // MovementType::Waste (bukan string)
$movement->type->label();       // "Terbuang"
$movement->reason?->label();    // "Gosong/Salah Olah", atau null kalau reason kosong
```
- `?->` (*nullsafe operator*) → kalau `reason` null (misalnya transaksi `in`), hasilnya null dan tidak error.

## Keputusan desain

- **`user_id` dan `stock_after` tidak fillable.** Polanya sama seperti `role` (T-101) dan `stock` (T-103): data yang **dihitung server** tidak boleh bisa diisi dari input. Kalau fillable, orang bisa mengirim `user_id` milik orang lain atau `stock_after` palsu.
- **FK `item_id` dan `user_id` memakai restrict.** Alternatif `cascadeOnDelete()` akan ikut menghapus riwayat saat bahan/pengguna dihapus permanen, dan jejak audit hilang. Dalam praktik, bahan hanya di-soft delete dan pengguna hanya dinonaktifkan, jadi restrict tidak mengganggu alur normal.
- **Validasi "waste wajib reason" belum di database.** Aturan seperti "reason wajib kalau type = waste" dan "note wajib kalau reason = other" dicek di Form Request + `StockService` (T-208), bukan di database, karena membutuhkan CHECK constraint khusus PostgreSQL (alasan yang sama seperti opsi A di T-103).
- **`type` disimpan sebagai `string(20)`, bukan tipe enum database.** Sesuai PRD. Menambah jenis baru nanti (misalnya `transfer` di versi 2) cukup menambah `case` di enum PHP, tanpa mengubah tipe kolom.
- **Enum ditaruh di `app/Enums/`.** Ini sesuai TASKS (`App\Enums\MovementType`). Catatan teknis: perintah `make:enum` menaruh file di `app/` kalau folder `app/Enums` belum ada, dan di `app/Enums` kalau folder itu sudah ada. Jadi enum pertama dibuat dengan `make:enum Enums/MovementType`, enum berikutnya cukup `make:enum WasteReason`.
- **Tanpa factory.** Sesuai TASKS (`-m` saja). Transaksi contoh dibuat lewat seeder (T-106) dan nanti lewat `StockService`, supaya `stock_after` selalu konsisten dengan stok bahan.
- **Index untuk `item_id` dan `user_id` (ditambahkan setelah migration pertama, disetujui Aldi).** Foreign key hanya menjaga data tetap valid; ia tidak membuat pencarian cepat. Di PostgreSQL, index untuk kolom FK harus dibuat sendiri. Dua query penting memakai kolom ini: "10 transaksi terakhir bahan X" (detail bahan, F-10) dan "riwayat milik staf Y" (F-18). Tanpa index, database harus membaca seluruh tabel riwayat, yang makin lama makin besar.
- **Kenapa migration diubah lewat rollback, bukan migration baru.** Aturan umumnya: migration yang sudah jalan **jangan diedit**, buat migration baru (seperti di T-101). Di sini dibuat pengecualian karena:
  1. migration ini baru saja dijalankan dan belum pernah di-commit/push, jadi belum ada orang lain atau server lain yang menjalankannya;
  2. tabelnya terbukti kosong (0 baris), jadi rollback tidak menghapus data apa pun;
  3. hasilnya lebih rapi: satu migration berisi struktur lengkap, bukan "buat tabel" lalu "tambah index" terpisah.

  Prosedur yang dijalankan (dengan izin eksplisit Aldi):
  - cek `php artisan migrate:status` → batch 6 (batch terakhir) **hanya** berisi `create_stock_movements_table`. Ini penting karena `migrate:rollback --step=1` membatalkan satu batch terakhir; kalau batch itu berisi migration lain, migration lain ikut terbatalkan.
  - cek jumlah baris `stock_movements` di Supabase = 0;
  - `php artisan migrate:rollback --step=1` (tepat 1 step) → ubah migration → uji di SQLite sementara → `php artisan migrate`.
  - hasil: migration kembali `Ran` di batch 6, index `stock_movements_item_id_index` dan `stock_movements_user_id_index` ada di Supabase.

  Kalau tabelnya sudah berisi data atau migration sudah di-push, caranya adalah migration baru `add_indexes_to_stock_movements_table` dengan `Schema::table(...)`.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Status migration:
   ```powershell
   php artisan migrate:status
   ```
   Harapan: `2026_11_08_012344_create_stock_movements_table` → `Ran` di batch `[6]`.

2. Struktur di Supabase:
   ```powershell
   php artisan db:table stock_movements
   ```
   Harapan: 9 kolom (tanpa `updated_at`), index `stock_movements_created_at_index`, `stock_movements_item_id_index`, `stock_movements_user_id_index`, dua foreign key dengan On Delete `restrict`.

3. Test otomatis:
   ```powershell
   php artisan test --filter=MovementTypeTest
   php artisan test --filter=WasteReasonTest
   php artisan test --filter=StockMovementTest
   php artisan test
   ```
   Harapan: total 27 test lulus.

4. Coba enum di Tinker (tidak menyentuh database):
   ```powershell
   php artisan tinker
   ```
   ```php
   App\Enums\MovementType::from('waste')->label();      // "Terbuang"
   App\Enums\WasteReason::CookingError->value;          // "cooking_error"
   App\Enums\MovementType::tryFrom('hilang');           // null
   App\Enums\MovementType::from('hilang');              // ValueError
   ```

## Kalau error, cek ini

- **`ValueError: "hilang" is not a valid backing value for enum App\Enums\MovementType`** → ada kode yang mengisi `type` (atau `reason`) dengan teks di luar daftar enum. Periksa ejaan, atau validasi dulu dengan `Rule::enum(MovementType::class)` di Form Request (dipakai di T-208).
- **`SQLSTATE[42703]: column "updated_at" ... does not exist`** → baris `public const UPDATED_AT = null;` di model hilang atau salah tulis, sehingga Eloquent mencoba mengisi `updated_at`.
- **`Class "App\Enums\MovementType" not found`** → file enum tidak berada di `app/Enums/` atau namespace-nya bukan `App\Enums`. Jalankan `composer dump-autoload` setelah memindahkan file.
- **`Method ... ForeignKeyDefinition::index does not exist`** → `->index()` ditulis setelah `->constrained()`. Pindahkan `->index()` ke sebelum `->constrained()`.
- **`SQLSTATE[23503]: Foreign key violation`** saat `forceDelete()` bahan/pengguna → bahan/pengguna itu sudah punya riwayat transaksi. Ini memang disengaja (restrict); pakai soft delete atau nonaktifkan pengguna.

## Bahan menjelaskan saat wawancara

**1. "Kenapa memakai enum, bukan string biasa?"**
Supaya nilainya terkontrol. Jenis transaksi cuma boleh in, out, waste, atau adjust. Dengan enum, salah ketik langsung ketahuan, editor bisa melengkapi otomatis, dan label bahasa Indonesianya ada di satu tempat lewat method `label()`. Di database tetap disimpan sebagai teks, jadi mudah dibaca.

**2. "Kenapa tabel riwayat tidak punya updated_at?"**
Karena riwayat transaksi adalah jejak audit dan tidak boleh diedit. Kalau ada salah catat, koreksinya dibuat sebagai transaksi baru, misalnya penyesuaian. Jadi kolom `updated_at` tidak ada gunanya, dan saya matikan dengan `UPDATED_AT = null`.

**3. "Kenapa ada kolom stock_after, padahal stok bisa dihitung dari jumlah semua transaksi?"**
Bisa dihitung, tapi makin lama makin berat, dan susah dicek kalau ada selisih. `stock_after` menyimpan stok tepat setelah transaksi itu, jadi riwayat bisa dibaca seperti mutasi rekening bank: setiap baris langsung menunjukkan saldo akhirnya.

## Coba sendiri (latihan)

Tambahkan method `isStockReducing(): bool` di enum `MovementType` yang mengembalikan `true` untuk jenis yang selalu mengurangi stok (keluar dan terbuang) dan `false` untuk yang lain. Gunakan `match`. Lalu tambahkan satu test di `tests/Unit/MovementTypeTest.php` yang mengecek keempat jenis. Jalankan `php artisan test --filter=MovementTypeTest` sampai lulus.
