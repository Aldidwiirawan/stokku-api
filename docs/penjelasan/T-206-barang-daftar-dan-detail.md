# T-206 — Barang: daftar dan detail

## Ringkasan
Aplikasi sekarang bisa menampilkan **daftar bahan** (20 per halaman) yang bisa dicari berdasarkan nama/SKU, difilter per kategori, dan difilter stok menipis, termasuk gabungan semuanya. Ada juga **detail bahan** lengkap dengan kategori dan 10 transaksi terakhir beserta nama pencatatnya. Keduanya terbukti bebas masalah N+1: jumlah query database tetap, berapa pun banyaknya data.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `app/Http/Controllers/ItemController.php` | Dibuat | `index` (daftar + filter + pagination) dan `show` (detail + 10 transaksi terakhir) |
| `app/Http/Requests/ItemIndexRequest.php` | Dibuat | Validasi query string `search`, `category_id`, `low_stock`, `page` |
| `app/Http/Resources/ItemResource.php` | Dibuat | Bentuk JSON bahan (`photo_url`, `is_low_stock`, `category`, `recent_movements`) |
| `app/Http/Resources/StockMovementResource.php` | Dibuat | Bentuk JSON transaksi (`type_label`, `reason_label`, `user`); dipakai lagi di T-208 |
| `routes/api.php` | Diubah | `GET /items`, `GET /items/{item}` (semua peran) |
| `lang/id/validation.php` | Diubah | Nama field `low_stock` → "filter stok menipis", `page` → "halaman" |
| `tests/Feature/ItemApiTest.php` | Dibuat | 14 test daftar, filter, detail, 404, dan bukti tidak ada N+1 |

## Konsep yang dipakai

**Masalah N+1 query**
- Apa itu: kode mengambil daftar berisi N data dengan **1** query, lalu tanpa sadar menjalankan **1 query lagi untuk setiap data** (misalnya mengambil kategori setiap bahan), sehingga totalnya 1 + N query. Ibarat belanja 20 barang di pasar dan bolak-balik ke rumah setiap kali selesai membeli satu barang, padahal bisa sekali jalan dengan satu daftar belanja.
- Kenapa penting: dengan 20 bahan, itu 21 query, bukan 2. Di Supabase (server di Singapura), setiap query butuh waktu perjalanan jaringan, jadi aplikasi terasa lambat dan makin lambat seiring data bertambah. Kriteria selesai T-206: "tidak ada N+1 query".

**Eager loading (`with`, `load`)**
- Apa itu: memberi tahu Eloquent untuk mengambil data relasi **sekaligus di depan**. `Item::with('category')` → 1 query untuk 20 bahan, lalu 1 query `SELECT * FROM categories WHERE id IN (1, 2, 3, ...)` untuk semua kategorinya.
- Kenapa dipakai: inilah obat N+1. Di detail bahan, `'stockMovements' => fn ($q) => $q->with('user')` membuat 10 transaksi dan semua pencatatnya terambil dalam 2 query, bukan 1 + 10.

**`whenLoaded` di API Resource**
- Apa itu: field hanya dikirim kalau relasinya **sudah dimuat** di controller. Kalau belum, field itu tidak muncul, dan resource **tidak** menjalankan query sendiri.
- Kenapa dipakai: mencegah N+1 "tersembunyi" di resource. Di daftar, `recent_movements` tidak dimuat, jadi tidak ikut dikirim. Di detail, dimuat, jadi ikut dikirim.

**Pagination (`paginate(20)`)**
- Apa itu: membagi hasil menjadi halaman berisi 20 data. Laravel otomatis membaca `?page=`, menjalankan 1 query `COUNT` untuk total, dan menambahkan `links` (first/last/prev/next) serta `meta` (current_page, last_page, per_page, total) ke respons.
- Kenapa dipakai: PRD F-08 (20 per halaman, infinite scroll). Aplikasi meminta halaman berikutnya saat pengguna menggulir ke bawah.

**`withQueryString()`**
- Apa itu: link halaman (`links.next`, dst.) ikut membawa parameter yang sedang dipakai (`search`, `category_id`, `low_stock`).
- Kenapa dipakai: tanpa ini, link halaman 2 dari hasil pencarian "ayam" akan menunjuk ke halaman 2 **semua** bahan.

**Menghitung query di test (`DB::enableQueryLog`)**
- Apa itu: Laravel bisa mencatat setiap query yang dijalankan. Test menghitung jumlahnya untuk data sedikit dan data banyak, lalu memastikan keduanya **sama**.
- Kenapa dipakai: bukti nyata, bukan sekadar "sepertinya sudah pakai `with`". Kalau suatu hari ada yang menghapus eager loading, test langsung gagal.

## Alur kode langkah demi langkah

**A. Daftar: `GET /api/v1/items?search=ayam&category_id=1&low_stock=1&page=1`**
1. Middleware `auth:sanctum` + `active` (semua peran boleh).
2. `ItemIndexRequest` memvalidasi query string. `low_stock=mungkin` → 422 "Filter stok menipis harus bernilai benar atau salah.".
3. `ItemController::index`:

```php
$items = Item::query()
    ->with('category')                                         // eager load kategori
    ->search($request->validated('search'))                    // scope T-105 (kosong = tidak memfilter)
    ->when($request->filled('category_id'), function ($query) use ($request) {
        $query->where('category_id', $request->validated('category_id'));
    })
    ->when($request->boolean('low_stock'), function ($query) {
        $query->lowStock();                                    // scope T-105
    })
    ->orderBy('name')->orderBy('id')
    ->paginate(20)
    ->withQueryString();
```
- `when(kondisi, fungsi)` → fungsi hanya dijalankan kalau kondisinya benar. Filter yang tidak dikirim tidak ditambahkan ke query.
- Bahan yang di-soft delete otomatis tidak ikut (trait `SoftDeletes`).
- Query yang dijalankan **selalu 3**: `COUNT` total, ambil 20 bahan, ambil kategorinya.

4. `ItemResource::collection($items)` → `{ "data": [...], "links": {...}, "meta": {...} }`.

**B. Detail: `GET /api/v1/items/1`**
1. Route model binding mencari bahan id 1. Bahan yang sudah di-soft delete **tidak ditemukan** (scope `SoftDeletes` ikut berlaku), jadi Laravel melempar `ModelNotFoundException` → 404 "Data tidak ditemukan." (renderer T-201).
2. `ItemController::show`:

```php
$item->load([
    'category',
    'stockMovements' => function ($query) {
        $query->with('user')                         // pencatat semua transaksi sekaligus
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(10);                             // hanya 10 terbaru
    },
]);
```
- Query yang dijalankan **selalu 4**: bahan, kategori, 10 transaksi, pencatatnya.
3. `ItemResource` → `recent_movements` berisi `StockMovementResource` untuk setiap transaksi.

## Keputusan desain

- **Bukti N+1 dengan dua pengukuran (permintaan Aldi).**
  - Daftar: 3 bahan vs 20 bahan, dengan setiap bahan memakai kategori **berbeda** → keduanya 3 query.
  - Detail: 2 transaksi vs 15 transaksi, dengan setiap transaksi dicatat pengguna **berbeda** → keduanya 4 query.
  - Data dibuat "terburuk" (semua kategori/pengguna berbeda) supaya N+1 pasti terlihat kalau ada.
  - Test ini juga sudah dibuktikan bisa gagal: saat `with('user')` dihapus sementara, jumlah query naik dari 5 ke 13 dan test merah. Setelah dikembalikan, test hijau lagi.
- **Test N+1 memakai `Sanctum::actingAs`.** Cara ini tidak menjalankan query pencarian token, sehingga yang dihitung hanya query milik endpoint, dan angkanya (3 dan 4) mudah dijelaskan.
- **Detail bahan terhapus → 404 (permintaan Aldi).** Tidak perlu kode tambahan, karena route model binding sudah mengikuti scope `SoftDeletes`. Ada test-nya.
- **`price` dikirim sebagai teks** (`"38000.00"`), mengikuti cast `decimal:2`. Angka desimal di JSON bisa berubah presisinya di sisi client; teks menjaga nilainya persis. Disetujui Aldi: `ItemModel` di Flutter mem-parse dengan `num.parse()` (dicatat di TASKS T-402).
- **`category_id` yang tidak ada → daftar kosong, bukan 422.** Memakai aturan `exists` akan menambah 1 query dan tidak memberi manfaat berarti untuk filter.
- **Urut nama lalu id.** Kalau ada dua bahan bernama sama, urutan halaman tetap konsisten (tidak ada bahan yang "melompat" ke halaman lain saat infinite scroll).
- **`StockMovementResource` dibuat sekarang** supaya riwayat di T-208 memakai bentuk JSON yang sama persis dengan `recent_movements`.
- **Tambah/ubah/hapus bahan belum ada**; itu T-207.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Test otomatis:
   ```powershell
   php artisan test --filter=ItemApiTest
   php artisan test
   php artisan route:list --path=api/v1/items
   ```
   Harapan: 14 test bahan lulus (termasuk 2 test N+1); total 134 test lulus; 2 route.

2. Thunder Client: lihat langkah lengkap di laporan T-206. Ringkasnya (data seed): halaman 1 berisi 20 bahan, halaman 2 berisi 10; `search=ayam` → 3 bahan; `category_id=1` → 5 bahan; `low_stock=1` → 6 bahan; `search=ayam&low_stock=1` → hanya Ayam Potong Utuh; `/items/1` → Ayam Potong Utuh dengan 3 transaksi.

## Kalau error, cek ini

- **Test N+1 gagal: "Failed asserting that 13 is identical to 5" (atau angka lain)** → ada eager loading yang hilang, atau ada kode di resource yang mengakses relasi yang belum dimuat (misalnya `$this->user->name` tanpa `whenLoaded`). Cari relasi itu lalu tambahkan ke `with(...)`.
- **`recent_movements` tidak muncul di detail** → relasi `stockMovements` tidak dimuat di `show()`, atau namanya salah ketik di `load([...])`.
- **Hasil pencarian tidak menemukan "ayam" di server tapi ketemu di test** → lihat T-105: pastikan scope `search()` masih memakai `whereLike(..., caseSensitive: false)`.
- **Halaman 2 hasil filter berisi bahan yang tidak sesuai filter** → `->withQueryString()` hilang, atau aplikasi tidak memakai `links.next` dari respons.

## Bahan menjelaskan saat wawancara

**1. "Apa itu N+1 dan bagaimana kamu mencegahnya?"**
N+1 terjadi kalau saya mengambil daftar dengan satu query, lalu tanpa sadar menjalankan satu query lagi untuk setiap baris, misalnya kategori setiap bahan. Saya mencegahnya dengan eager loading, `with('category')`, jadi semua kategori diambil sekaligus. Saya juga punya test yang menghitung jumlah query untuk 3 dan 20 bahan dan memastikan jumlahnya sama.

**2. "Bagaimana kamu tahu test N+1-nya benar-benar bekerja?"**
Saya sengaja menghapus eager loading pencatat transaksi, dan jumlah query naik dari 5 ke 13 sehingga test gagal. Setelah eager loading dikembalikan, test lulus lagi. Jadi test itu benar-benar menjaga, bukan sekadar lulus.

**3. "Kenapa harga dikirim sebagai teks?"**
Supaya presisinya terjaga. Di database kolomnya `decimal(12,2)`, dan cast `decimal:2` menghasilkan teks seperti "38000.00". Kalau dikirim sebagai angka desimal, nilainya bisa berubah sedikit di client. Aplikasi tinggal mem-parse saat menampilkan Rupiah.

## Coba sendiri (latihan)

Tambahkan parameter `?sort=stock` di `GET /api/v1/items` untuk mengurutkan bahan dari stok paling sedikit; tanpa `sort`, tetap urut nama. Validasi `sort` hanya boleh `name` atau `stock` (aturan `in:name,stock`). Tulis satu test untuk urutan stok, lalu jalankan juga test N+1 untuk memastikan jumlah query tidak berubah.
