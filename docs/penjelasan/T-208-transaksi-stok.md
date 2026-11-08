# T-208 — Transaksi stok

## Ringkasan
Aplikasi sekarang bisa mencatat **bahan masuk**, **bahan keluar** (dipakai dapur), **bahan terbuang** (dengan alasan), dan **penyesuaian/stock opname** (khusus Admin). Setiap transaksi langsung mengubah stok bahan dan tercatat di riwayat lengkap dengan `stock_after`. Stok **tidak pernah bisa negatif**: keluar/terbuang yang melebihi stok ditolak, termasuk saat dua transaksi datang bersamaan. Riwayat bisa difilter berdasarkan tanggal (WIB), jenis, dan bahan; Staf hanya melihat transaksinya sendiri.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `app/Services/StockService.php` | Dibuat | Satu-satunya tempat yang mengubah stok: transaksi DB + kunci baris + hitung + catat riwayat |
| `app/Http/Controllers/StockMovementController.php` | Dibuat | `index` (riwayat + filter) dan `store` (catat transaksi) |
| `app/Http/Requests/StoreStockMovementRequest.php` | Dibuat | Validasi transaksi + adjust khusus Admin (403) |
| `app/Http/Requests/StockMovementIndexRequest.php` | Dibuat | Validasi filter riwayat + konversi tanggal WIB → UTC |
| `app/Http/Resources/StockMovementResource.php` | Diubah | Tambah data `item` (stok terbaru, `is_low_stock`) |
| `config/app.php` | Diubah | `business_timezone` (default `Asia/Jakarta`); `timezone` tetap UTC |
| `.env.example` | Diubah | `APP_BUSINESS_TIMEZONE=Asia/Jakarta` |
| `routes/api.php` | Diubah | `GET` & `POST /stock-movements` (semua peran) |
| `lang/id/validation.php` | Diubah | Pesan `prohibited_unless` |
| `tests/Feature/StockMovementApiTest.php` | Dibuat | 24 test transaksi, stok negatif, adjust, riwayat, zona waktu, N+1 |

## Konsep yang dipakai

**Service class (`StockService`)**
- Apa itu: class biasa berisi logika bisnis yang tidak terikat ke HTTP. Controller hanya menerima request dan memanggil service.
- Kenapa dipakai: aturan stok adalah inti aplikasi. Dengan menaruhnya di satu tempat, aturannya mudah diuji dan dijelaskan, dan bisa dipakai ulang (misalnya oleh seeder, atau fitur transfer antar outlet di versi 2).

**Database transaction (`DB::transaction`)**
- Apa itu: membungkus beberapa perintah database menjadi satu kesatuan. Semuanya berhasil, atau semuanya dibatalkan (*rollback*).
- Kenapa dipakai: mengubah `items.stock` dan menulis `stock_movements` harus terjadi bersama. Kalau penulisan riwayat gagal, perubahan stok ikut dibatalkan, jadi stok tidak pernah berubah tanpa jejak.

**Penguncian baris (`lockForUpdate`) dan race condition**
- Apa itu: `SELECT ... FOR UPDATE` mengunci baris bahan sampai transaksi selesai. Transaksi lain yang ingin mengunci bahan yang sama harus **menunggu**.
- Masalah yang dicegah (*race condition*): stok 10, dua staf menekan "Keluar 7" di detik yang sama. Tanpa kunci, keduanya membaca stok 10, keduanya lolos pengecekan (7 ≤ 10), lalu stok menjadi 10 − 7 − 7 = **−4**. Dengan kunci, staf kedua menunggu sampai staf pertama selesai, lalu membaca stok **3**, sehingga ditolak (7 > 3).
- Analogi: buku stok gudang hanya boleh dipegang satu orang. Orang berikutnya antre sampai buku dikembalikan, jadi ia selalu membaca angka terbaru.

**`lockForUpdate` tidak berlaku di SQLite (database test)**
- SQLite tidak punya `SELECT ... FOR UPDATE`. Laravel diam-diam **membuang** perintah kunci itu. Dibuktikan dengan membandingkan SQL yang disusun Laravel:
  ```
  SQLite     : select * from "items" where "items"."id" = ? and "items"."deleted_at" is null
  PostgreSQL : select * from "items" where "items"."id" = ? and "items"."deleted_at" is null for update
  ```
- Artinya: **test otomatis tidak bisa membuktikan kunci bekerja.** Test hanya membuktikan logika ("dua transaksi berturut-turut: yang kedua ditolak"). SQLite sendiri mengunci seluruh database saat menulis, dan test berjalan satu per satu, jadi race condition memang tidak terjadi di test, tapi itu bukan bukti bahwa kode kita benar di PostgreSQL.
- Karena itu dilakukan **uji manual ke Supabase (PostgreSQL)** dengan beberapa proses yang berjalan bersamaan (lihat bagian "Uji manual di Supabase").

**Konversi zona waktu (WIB → UTC)**
- Apa itu: database menyimpan waktu dalam UTC. Pengguna memilih tanggal dalam WIB (UTC+7). "Tanggal 9 Nov" bagi pengguna berarti **9 Nov 00:00–23:59:59 WIB** = **8 Nov 17:00:00 – 9 Nov 16:59:59 UTC**.
- Kenapa dipakai: keputusan Aldi (T-201). Tanpa konversi, transaksi pukul 00:00–06:59 WIB akan masuk ke tanggal yang salah.

## Alur kode langkah demi langkah

**A. Catat transaksi: `POST /api/v1/stock-movements`**
1. Middleware `auth:sanctum` + `active`.
2. `StoreStockMovementRequest::authorize()`: kalau `type=adjust` dan pengguna bukan Admin → `failedAuthorization()` → 403 "Penyesuaian stok hanya bisa dilakukan Admin.". Ini dicek **sebelum** validasi.
3. Validasi:

```php
'item_id'  => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
'type'     => ['required', Rule::enum(MovementType::class)],
'quantity' => ['required', 'integer', 'min:'.$minimumQuantity, 'max:1000000'],   // 1 (adjust: 0)
'reason'   => ['nullable', Rule::enum(WasteReason::class), 'required_if:type,waste', 'prohibited_unless:type,waste'],
'note'     => ['nullable', 'string', 'max:255', 'required_if:reason,other'],
```
- `whereNull('deleted_at')` → bahan yang sudah dihapus tidak bisa ditransaksikan.
- `prohibited_unless:type,waste` → alasan hanya boleh diisi untuk transaksi terbuang.

4. `StockService::record()`:

```php
return DB::transaction(function () use (...) {
    $item = Item::whereKey($itemId)->lockForUpdate()->firstOrFail();   // kunci baris
    $stockBefore = $item->stock;

    if ($type === MovementType::Adjust) {
        $stockAfter = $quantity;                    // stok fisik
        $change = $stockAfter - $stockBefore;       // selisih, bisa minus
        if ($change === 0) { /* 422 "Tidak ada selisih stok." */ }
    } elseif ($type === MovementType::In) {
        $change = $quantity;
        $stockAfter = $stockBefore + $quantity;
    } else {                                        // out & waste
        if ($quantity > $stockBefore) { /* 422 "Jumlah melebihi stok saat ini (X satuan)." */ }
        $change = $quantity;
        $stockAfter = $stockBefore - $quantity;
    }
    // simpan items.stock, lalu catat stock_movements (user_id & stock_after eksplisit)
});
```
- Pengecekan "melebihi stok" dilakukan **setelah** baris dikunci, memakai stok terbaru. Inilah yang membuat dua transaksi bersamaan tidak bisa sama-sama lolos.
- Kalau ada exception (misalnya 422), `DB::transaction` membatalkan semuanya, dan kunci dilepas.

5. Respons **201**: transaksi + `stock_after` + `item.stock` + `item.is_low_stock` (dipakai aplikasi untuk notifikasi stok menipis, F-21).

**B. Riwayat: `GET /api/v1/stock-movements?type=&item_id=&from=&to=&page=`**
1. `StockMovementIndexRequest` memvalidasi. `to` sebelum `from` → 422 "Tanggal akhir tidak boleh sebelum tanggal mulai.".
2. Konversi tanggal:

```php
Carbon::createFromFormat('Y-m-d', $from, config('app.business_timezone'))  // 2026-11-09 di WIB
    ->startOfDay()                                                        // 2026-11-09 00:00:00 WIB
    ->utc();                                                              // 2026-11-08 17:00:00 UTC
```
3. Query: Staf otomatis `where('user_id', $user->id)`; filter lain hanya ditambahkan kalau dikirim; urut terbaru; `with(['item', 'user'])`; 20 per halaman. Selalu **4 query**, berapa pun jumlah transaksinya (ada test-nya).

## Keputusan desain

- **Tidak ada selisih → 422 "Tidak ada selisih stok." (permintaan Aldi).** Penyesuaian dengan selisih 0 tidak mengubah apa pun dan hanya mengotori riwayat.
- **`from` > `to` → 422 (permintaan Aldi)**, dengan pesan khusus yang lebih jelas daripada pesan umum `after_or_equal`.
- **Pesan "melebihi stok" menyebut sisa stok dan satuannya** ("Jumlah melebihi stok saat ini (3 kg)."), supaya staf langsung tahu berapa yang bisa dikeluarkan.
- **Pengecekan stok di `StockService`, bukan di Form Request.** Validasi di Form Request membaca stok **sebelum** baris dikunci, jadi bisa memakai angka lama. Pengecekan yang menentukan harus terjadi di dalam transaksi, setelah `lockForUpdate`.
- **Adjust oleh Staf → 403 (bukan 422).** Ini soal hak akses, bukan isian yang salah (PRD §4: penyesuaian hanya Admin). Pesan dari `failedAuthorization()` dipertahankan oleh renderer T-201.
- **`quantity` untuk adjust = stok fisik, yang disimpan = selisih** (PRD F-17, §10). Riwayat menunjukkan perubahannya (−3, +5), dan `stock_after` menunjukkan hasil hitung fisiknya.
- **Zona waktu bisnis di `config('app.business_timezone')`** (keputusan Aldi, dipakai lagi di T-209). `APP_TIMEZONE` tetap UTC; waktu di respons tetap ISO 8601 UTC (`...+00:00`).
- **Riwayat tetap menampilkan bahan yang sudah dihapus**, karena relasi `StockMovement::item()` memakai `withTrashed()` (T-105). Ada test-nya.
- **Data `item` di respons adalah keadaan bahan sekarang**, bukan saat transaksi. Untuk stok saat transaksi, pakai `stock_after`.

## Uji manual di Supabase

> **Status: sudah dijalankan pada 8 Nov 2026 atas izin Aldi. Berhasil, dan data uji sudah dibersihkan.**

**Cara uji.** Beberapa **proses PHP terpisah** dijalankan bersamaan (dimulai pada detik yang sama), masing-masing memanggil `StockService` langsung ke Supabase. Kenapa bukan lewat Thunder Client? `php artisan serve` di Windows hanya melayani satu request dalam satu waktu, jadi request bersamaan lewat HTTP otomatis antre di **server**, bukan di **database**, dan tidak membuktikan apa-apa tentang kunci.

**Syarat dari Aldi:**
- pembersihan hanya berdasarkan id bahan uji yang dibuat di langkah persiapan, dibungkus `DB::transaction`
- jumlah data sesudah pembersihan harus sama persis dengan sebelum uji
- berhenti dan lapor kalau ada langkah yang gagal

Script diuji dulu di SQLite sementara. Uji coba itu menemukan bahwa runner awalnya **tidak berhenti** saat ada proses yang error; ini diperbaiki dan dibuktikan berhenti dengan benar sebelum dijalankan ke Supabase.

**Hasil di Supabase (PostgreSQL):**

1. **Persiapan.** Jumlah sebelum uji: **30 bahan** (termasuk soft delete), **40 transaksi**. Bahan uji `UJI-LOCK-01` dibuat (id 31) dengan stok awal 10.

2. **Bukti kunci bekerja.** Proses A mengunci bahan dan menahannya 3 detik; proses B pada saat yang sama mencatat "keluar 3".
   ```
   A-tahan-kunci: kunci ditahan lalu dilepas setelah 3.19 detik
   B-keluar-3: BERHASIL keluar 3 → stock_after 7 (menunggu+proses 3.53 detik)
   ```
   B butuh 3,53 detik untuk satu transaksi yang biasanya < 1 detik, karena ia **menunggu** kunci A dilepas. Ini bukti `SELECT ... FOR UPDATE` bekerja di PostgreSQL.

3. **Bukti stok tetap benar.** 5 proses bersamaan, masing-masing "keluar 3", dari stok 7:
   ```
   P1-keluar-3: BERHASIL keluar 3 → stock_after 4 (0.84 detik)
   P2-keluar-3: DITOLAK 422 "Jumlah melebihi stok saat ini (1 kg)." (1.31 detik)
   P3-keluar-3: DITOLAK 422 "Jumlah melebihi stok saat ini (1 kg)." (1.42 detik)
   P4-keluar-3: BERHASIL keluar 3 → stock_after 1 (1.20 detik)
   P5-keluar-3: DITOLAK 422 "Jumlah melebihi stok saat ini (1 kg)." (1.53 detik)
   ```
   - Tepat 2 berhasil (7 → 4 → 1) dan 3 ditolak.
   - Stok akhir **1**, riwayat `10 → 7 → 4 → 1` tanpa lompatan, dan **0** `stock_after` negatif.
   - Waktu proses yang berbeda-beda (0,84–1,53 detik) menunjukkan mereka **antre** di kunci, bukan berjalan sendiri-sendiri.
   - Tanpa kunci, beberapa proses bisa sama-sama membaca stok 7 dan stok akhir bisa jadi negatif.

4. **Pembersihan.** Dalam satu `DB::transaction`, dihapus 4 transaksi dan bahan uji **berdasarkan id 31** (setelah dicek SKU-nya memang `UJI-LOCK-01`). Jumlah sesudah: **30 bahan, 40 transaksi**, sama persis dengan sebelum uji. Pengecekan terpisah juga menunjukkan 0 bahan yang stoknya berbeda dari `stock_after` terakhir. Satu-satunya jejak: id bahan dan transaksi berikutnya akan melompat, karena nomor urut id di PostgreSQL tidak dipakai ulang.

**Catatan dari uji coba di SQLite:** saat 5 proses menulis bersamaan, SQLite langsung menolak dengan `database is locked`, bukan menunggu. Ini perbedaan cara kerja: SQLite mengunci **seluruh database** saat menulis, sedangkan PostgreSQL mengunci **baris** dan membuat proses lain menunggu. Inilah alasan kunci harus dibuktikan di PostgreSQL, bukan di SQLite.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Test otomatis:
   ```powershell
   php artisan test --filter=StockMovementApiTest
   php artisan test
   php artisan route:list --path=api/v1/stock-movements
   ```
   Harapan: 24 test transaksi lulus; total 181 test lulus; 2 route.

2. Thunder Client: langkah lengkap ada di laporan T-208 (masuk, keluar, keluar melebihi stok, terbuang dengan/tanpa alasan, penyesuaian Admin/Staf, riwayat dengan filter). Ingat: transaksi lewat Thunder Client **mengubah stok data demo** di Supabase. Untuk mengembalikannya, catat transaksi kebalikannya (misalnya "masuk" sebanyak yang tadi dikeluarkan).

## Kalau error, cek ini

- **422 "Jumlah melebihi stok saat ini (...)"** → memang stoknya kurang. Lihat stok terbaru di `GET /api/v1/items/{id}`.
- **403 "Penyesuaian stok hanya bisa dilakukan Admin."** → token milik Staf. Login sebagai Admin untuk `type=adjust`.
- **422 "Alasan hanya boleh diisi jika jenis transaksi adalah terbuang."** → field `reason` ikut dikirim untuk `in`/`out`/`adjust`. Hapus field itu.
- **Filter tanggal "kehilangan" transaksi tengah malam** → pastikan `from`/`to` dikirim sebagai tanggal WIB (`Y-m-d`), dan `APP_BUSINESS_TIMEZONE` tidak diubah. Waktu di respons memakai UTC; aplikasi yang mengubahnya ke WIB saat menampilkan.

## Bahan menjelaskan saat wawancara

**1. "Bagaimana kamu mencegah stok menjadi negatif saat dua orang mengeluarkan barang bersamaan?"**
Semua perubahan stok lewat satu `StockService`. Di dalamnya saya memakai `DB::transaction` dan `lockForUpdate`, jadi baris bahan dikunci. Orang kedua menunggu sampai transaksi pertama selesai, lalu membaca stok terbaru. Pengecekan "jumlah tidak boleh melebihi stok" dilakukan setelah baris dikunci, jadi tidak mungkin dua-duanya lolos dengan angka lama.

**2. "Kalau test-mu pakai SQLite, bagaimana kamu tahu kuncinya bekerja?"**
Memang tidak bisa dari test otomatis, karena SQLite tidak mendukung `SELECT FOR UPDATE` dan Laravel membuangnya. Test membuktikan logikanya, misalnya dua transaksi keluar berturut-turut. Untuk kuncinya, saya uji manual ke PostgreSQL di Supabase dengan beberapa proses bersamaan, lalu memastikan proses kedua menunggu dan stok akhirnya benar.

**3. "Kenapa filter tanggal perlu diubah ke UTC?"**
Database menyimpan waktu dalam UTC, sedangkan pengguna berpikir dalam WIB. Tanggal 9 November bagi pengguna dimulai pukul 00.00 WIB, yaitu 8 November pukul 17.00 UTC. Kalau tidak dikonversi, transaksi dini hari masuk ke tanggal yang salah. Saya punya test untuk transaksi pukul 23.30 UTC, yang di WIB sudah tanggal berikutnya.

## Coba sendiri (latihan)

Tambahkan filter `?user_id=` di `GET /api/v1/stock-movements` yang **hanya berlaku untuk Admin** (Admin bisa melihat transaksi satu staf tertentu). Kalau Staf mengirim `user_id` milik orang lain, ia tetap hanya melihat transaksinya sendiri. Tulis dua test: Admin memfilter staf tertentu, dan Staf mengirim `user_id` orang lain tetapi hasilnya tetap miliknya sendiri.
