# T-105 — Relasi Eloquent dan scope

## Ringkasan
Sekarang model-model StokKu saling terhubung. Dari kategori kita bisa mengambil bahan-bahannya (`$category->items`), dari bahan bisa mengambil kategori dan riwayat transaksinya, dan dari transaksi bisa tahu bahan dan pencatatnya. Bahan juga punya dua "filter siap pakai": `Item::lowStock()` untuk bahan yang stoknya menipis, dan `Item::search('ayam')` untuk mencari berdasarkan nama atau SKU tanpa peduli huruf besar/kecil.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `app/Models/Category.php` | Diubah | Relasi `items()` (hasMany) |
| `app/Models/Item.php` | Diubah | Relasi `category()` (belongsTo), `stockMovements()` (hasMany); scope `lowStock()` dan `search()` |
| `app/Models/StockMovement.php` | Diubah | Relasi `item()` (belongsTo, termasuk bahan yang sudah dihapus) dan `user()` (belongsTo) |
| `app/Models/User.php` | Diubah | Relasi `stockMovements()` (hasMany) |
| `tests/Feature/RelationsTest.php` | Dibuat | 6 test semua relasi, termasuk bahan terhapus tetap terbaca dari riwayat |
| `tests/Feature/ItemScopeTest.php` | Dibuat | 6 test scope: batas stok minimum, huruf besar/kecil, SKU, kata kunci kosong, gabungan scope, SQL `ilike` di PostgreSQL |

## Konsep yang dipakai

**Relasi Eloquent**
- Apa itu: method di model yang menjelaskan hubungan antar tabel, sehingga data terkait bisa diambil dengan `$model->namaRelasi` tanpa menulis JOIN/SQL manual.
- Kenapa dipakai: API nanti butuh "kategori beserta jumlah bahannya", "detail bahan + 10 transaksi terakhir", dan "riwayat + nama bahan + nama pencatat". Semuanya jadi satu baris kode.

**`hasMany` dan `belongsTo`**
- Apa itu: dua sisi dari hubungan satu-ke-banyak. Satu kategori **punya banyak** (`hasMany`) bahan; satu bahan **milik** (`belongsTo`) satu kategori. Aturan mudahnya: model yang tabelnya menyimpan kolom `..._id` memakai `belongsTo`.
- Kenapa dipakai: `items.category_id`, `stock_movements.item_id`, dan `stock_movements.user_id` semuanya hubungan satu-ke-banyak (PRD §10).

**Properti relasi vs method relasi**
- Apa itu: `$category->items` (tanpa kurung) langsung mengambil datanya sebagai Collection. `$category->items()` (dengan kurung) mengembalikan query yang masih bisa ditambah syarat, misalnya `$category->items()->where('stock', 0)->count()`.
- Kenapa penting: untuk menghitung atau memfilter, pakai versi dengan kurung supaya database yang bekerja, bukan PHP.

**Local scope (`#[Scope]`)**
- Apa itu: potongan syarat query yang diberi nama dan disimpan di model, supaya bisa dipakai ulang seperti filter siap pakai. Di Laravel 13 ditulis sebagai method `protected` dengan attribute `#[Scope]`, lalu dipanggil seperti method biasa: `Item::lowStock()`.
- Kenapa dipakai: syarat "stok menipis" dipakai di dashboard (F-06), filter daftar bahan (F-09), dan notifikasi (F-21). Kalau aturannya berubah, cukup ubah di satu tempat.

**`whereColumn`**
- Apa itu: membandingkan **dua kolom** di baris yang sama, bukan kolom dengan nilai tetap.
- Kenapa dipakai: stok menipis artinya `stock <= min_stock`, dan nilai `min_stock` berbeda untuk setiap bahan.

**`ilike` dan `whereLike`**
- Apa itu: `LIKE` adalah pencarian pola teks di SQL (`%ayam%` = mengandung "ayam"). Di PostgreSQL, `LIKE` membedakan huruf besar/kecil, jadi "ayam" tidak menemukan "Ayam". `ILIKE` adalah versi PostgreSQL yang tidak membedakan. `whereLike(..., caseSensitive: false)` milik Laravel otomatis memilih `ilike` di PostgreSQL dan `like` di SQLite (yang sudah tidak membedakan huruf besar/kecil untuk huruf A–Z).
- Kenapa dipakai: TASKS meminta `ilike` di PostgreSQL dengan fallback yang tetap jalan di SQLite untuk test. Fitur Laravel ini memberi keduanya tanpa `if` manual.

## Alur kode langkah demi langkah

**A. Relasi**

```php
// Category
public function items(): HasMany
{
    return $this->hasMany(Item::class);          // cari items.category_id = categories.id
}

// StockMovement
public function item(): BelongsTo
{
    return $this->belongsTo(Item::class)->withTrashed();
}
```
- `hasMany(Item::class)` → Laravel menebak kolom penghubung dari nama model: `Category` → `category_id`.
- `belongsTo(Item::class)` → kolom penghubung diambil dari nama method: `item()` → `item_id`.
- `->withTrashed()` → relasi ini juga mengambil bahan yang sudah di-soft delete. Tanpa ini, riwayat transaksi bahan yang sudah dihapus akan punya `item` bernilai `null`, dan aplikasi bisa error saat menampilkan nama bahannya.

Saat kode memanggil `$category->items`, Laravel menjalankan kira-kira:
`select * from items where category_id = ? and deleted_at is null`

**B. Scope `lowStock()`**

```php
#[Scope]
protected function lowStock(Builder $query): void
{
    $query->whereColumn('stock', '<=', 'min_stock');
}
```
- `Item::lowStock()->get()` → Laravel memanggil method ini dan mengisi `$query` otomatis.
- Memakai `<=` (bukan `<`): stok yang **sama dengan** batas minimum sudah dianggap menipis, sesuai PRD F-06.

**C. Scope `search()`**

```php
#[Scope]
protected function search(Builder $query, ?string $term): void
{
    $term = trim((string) $term);
    if ($term === '') {
        return;                                   // kata kunci kosong → tidak memfilter
    }

    $query->where(function (Builder $query) use ($term) {
        $query->whereLike('name', "%{$term}%", caseSensitive: false)
            ->orWhereLike('sku', "%{$term}%", caseSensitive: false);
    });
}
```
- `trim((string) $term)` → buang spasi di awal/akhir; `null` diubah jadi `''`.
- `where(function ...)` → membungkus dua syarat dalam kurung. SQL di Supabase (dicek langsung):
  `where ("name"::text ilike ? or "sku"::text ilike ?) and "stock" <= "min_stock" and "items"."deleted_at" is null`
- Tanpa kurung, hasilnya menjadi `name ilike ? OR sku ilike ? AND stock <= min_stock ...`. Karena `AND` dihitung lebih dulu daripada `OR`, bahan yang namanya cocok akan ikut muncul walaupun stoknya tidak menipis, bahkan yang sudah dihapus. Ini bug yang sering terjadi.
- `use ($term)` → membuat variabel `$term` bisa dipakai di dalam function kecil (closure) itu.

## Keputusan desain

- **`whereLike` bawaan Laravel, bukan `if ($driver === 'pgsql')` manual.** Alternatifnya mengecek jenis database lalu memilih `ilike` atau `like` sendiri. Hasilnya sama, tapi kode lebih panjang dan rawan salah. Laravel sudah menyediakan fitur ini dan sudah teruji.
- **`StockMovement::item()` memakai `withTrashed()`.** Riwayat transaksi adalah jejak audit; bahan yang sudah dihapus harus tetap terlihat namanya di riwayat. Relasi lain (`Category::items()`) **tidak** memakai `withTrashed()`, karena daftar bahan di kategori memang hanya menampilkan bahan yang masih aktif.
- **Catatan penting untuk T-205 (hapus kategori):** `$category->items()` tidak menghitung bahan yang sudah di-soft delete, tetapi FK `restrict` di database **tetap** menghitungnya, karena barisnya masih ada. Jadi kategori yang hanya berisi bahan terhapus akan tampak "kosong" (`items_count = 0`), padahal database menolak penghapusannya, dan hasilnya error 500. Pengecekan 409 di T-205 harus memakai `$category->items()->withTrashed()->exists()`. Test `test_category_items_exclude_soft_deleted_items` menunjukkan perbedaan ini. Atas persetujuan Aldi, catatan ini sudah ditambahkan ke T-205 di `docs/TASKS.md`, lengkap dengan pesan 409: "Kategori tidak bisa dihapus karena masih dipakai oleh bahan, termasuk bahan yang sudah dihapus. Riwayat transaksi bahan tersebut harus tetap tersimpan."
- **Kata kunci kosong tidak memfilter.** Controller nanti bisa langsung memanggil `Item::search($request->query('search'))` tanpa `if` tambahan.
- **Karakter `%` dan `_` di kata kunci tidak di-escape.** Di LIKE, keduanya adalah karakter khusus (`%` = apa saja, `_` = satu karakter). Kalau pengguna mengetik `%`, hasil pencariannya hanya jadi lebih luas, tidak berbahaya karena nilai tetap dikirim lewat parameter `?` (aman dari SQL injection). Untuk aplikasi ini cukup.
- **Test SQL PostgreSQL memakai koneksi palsu.** Test berjalan di SQLite, jadi jalur `ilike` tidak teruji secara otomatis. Solusinya: test membuat koneksi `fake_pgsql` dengan host yang tidak ada (`tidak-ada.invalid`) dan hanya memanggil `toSql()`, yang menyusun teks SQL tanpa menghubungi database. Test **tidak pernah** memakai kredensial Supabase. Pengecekan ke PostgreSQL sungguhan dilakukan sekali secara manual dengan query baca-saja.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Test otomatis:
   ```powershell
   php artisan test --filter=RelationsTest
   php artisan test --filter=ItemScopeTest
   php artisan test
   ```
   Harapan: 6 + 6 test lulus; total 39 test lulus.
   Catatan: jangan menulis `--filter="A|B"` di PowerShell, karena tanda `|` dibaca sebagai pipe oleh `php.bat` Herd. Jalankan satu per satu.

2. Lihat SQL yang dihasilkan scope (tidak menjalankan query):
   ```powershell
   php artisan tinker
   ```
   ```php
   App\Models\Item::search('ayam')->lowStock()->toSql();
   // select * from "items" where ("name"::text ilike ? or "sku"::text ilike ?) and "stock" <= "min_stock" and "items"."deleted_at" is null
   ```

3. Setelah T-106 (data contoh sudah ada), di Tinker:
   ```php
   App\Models\Category::first()?->items;                       // daftar bahan kategori pertama
   App\Models\Item::lowStock()->pluck('name');                 // bahan yang stoknya menipis
   App\Models\Item::search('AYAM')->pluck('name');             // Ayam Potong Utuh, dst.
   App\Models\StockMovement::with(['item', 'user'])->first();  // transaksi + bahan + pencatat
   ```

## Kalau error, cek ini

- **`Call to undefined method ... Builder::lowStock()`** → attribute `#[Scope]` atau baris `use Illuminate\Database\Eloquent\Attributes\Scope;` hilang, atau method-nya tidak menerima `Builder $query` sebagai parameter pertama.
- **`Call to a member function ... on null`** saat membaca `$movement->item->name` → relasi `item()` kehilangan `->withTrashed()`, dan bahannya sudah dihapus.
- **Pencarian "ayam" tidak menemukan "Ayam" di server, padahal test lulus** → ada yang mengganti `whereLike(..., caseSensitive: false)` dengan `where('name', 'like', ...)`. Di SQLite tetap ketemu, tapi di PostgreSQL `like` membedakan huruf besar/kecil.
- **Filter stok menipis "bocor" (bahan yang stoknya aman ikut muncul saat mencari)** → bagian `where(function ...)` di `search()` dihapus, sehingga `OR` tidak lagi dibungkus kurung.

## Bahan menjelaskan saat wawancara

**1. "Apa bedanya hasMany dan belongsTo?"**
Keduanya sisi dari hubungan satu-ke-banyak. Model yang tabelnya menyimpan kolom `_id` memakai `belongsTo`. Contohnya, `items` punya `category_id`, jadi `Item belongsTo Category`, dan sebaliknya `Category hasMany Item`.

**2. "Kenapa pencarian dibungkus where(function)?"**
Karena di SQL, `AND` dihitung lebih dulu daripada `OR`. Kalau syarat "nama atau SKU" tidak dibungkus kurung, filter lain seperti stok menipis atau "belum dihapus" bisa terlewati. Dengan `where(function)`, SQL-nya menjadi `(name ilike ? or sku ilike ?) and ...`, jadi semua filter tetap berlaku.

**3. "Kenapa pakai scope, bukan menulis where langsung di controller?"**
Supaya aturan bisnis ada di satu tempat. "Stok menipis" dipakai di dashboard, daftar bahan, dan notifikasi. Kalau definisinya berubah, misalnya jadi `stock < min_stock`, saya cukup mengubah satu scope, dan namanya juga membuat kode controller mudah dibaca.

## Coba sendiri (latihan)

Buat scope baru `Item::inCategory(?int $categoryId)` yang memfilter bahan berdasarkan `category_id`, dan tidak memfilter apa pun kalau `$categoryId` bernilai `null` (polanya sama seperti kata kunci kosong di `search()`). Tulis dua test di `ItemScopeTest`: satu untuk kategori tertentu, satu untuk `null`. Jalankan `php artisan test --filter=ItemScopeTest` sampai lulus.
