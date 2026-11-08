# T-207 — Barang: tambah, ubah, hapus (Admin)

## Ringkasan
Admin sekarang bisa **menambah** bahan (dengan atau tanpa foto), **mengubah** data dan fotonya, dan **menghapus** bahan (soft delete). Staf yang mencoba mendapat 403. SKU selalu disimpan dalam huruf besar dan tidak boleh kembar, termasuk dengan bahan yang sudah dihapus. Stok **tidak bisa** diubah lewat endpoint ini; kalau ada yang mengirim field `stock`, server menolak dengan pesan yang jelas.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `app/Http/Controllers/ItemController.php` | Diubah | Aksi `store`, `update`, `destroy` + helper foto & penanganan SKU kembar |
| `app/Http/Requests/ItemRequest.php` | Dibuat | Validasi tambah & ubah: SKU unik, angka, foto, larangan field `stock` |
| `app/Models/Item.php` | Diubah | Mutator `sku()`: selalu huruf besar tanpa spasi di awal/akhir |
| `routes/api.php` | Diubah | `apiResource` store/update/destroy dengan `role:admin` |
| `lang/id/validation.php` | Diubah | Pesan `decimal`, `missing`; nama field `stock` → "stok" |
| `tests/Feature/ItemManageTest.php` | Dibuat | 23 test tambah, ubah, hapus, SKU, stok, batas harga, foto, hak akses, simpan bersamaan |

## Konsep yang dipakai

**Method spoofing (`_method=PUT`)**
- Apa itu: aplikasi mengirim request `POST`, tapi menyertakan field `_method=PUT`. Laravel membaca field itu dan memperlakukan request sebagai `PUT`, sehingga masuk ke route `update`.
- Kenapa dipakai: PHP **hanya** membaca isi multipart (file + teks) pada request `POST`. Pada request `PUT` sungguhan, `$request->file('photo')` dan field lain akan kosong. Supaya bisa mengunggah foto saat mengubah bahan, aplikasi memakai `POST` + `_method=PUT` (PRD §11). Kalau tidak ada foto, aplikasi boleh juga memakai `PUT` biasa dengan body JSON.

**Aturan `missing`**
- Apa itu: aturan validasi yang mewajibkan sebuah field **tidak ada sama sekali** di request. Berbeda dari `prohibited`, yang masih menerima nilai kosong (`null` atau `""`).
- Kenapa dipakai: permintaan Aldi, "jika request berisi field stock, tolak". Dengan `missing`, `stock=0` dan `stock` kosong pun ditolak dengan pesan "Stok hanya bisa diubah lewat transaksi.", supaya pengembang aplikasi langsung sadar bahwa stok tidak diatur di form bahan.

**Normalisasi data (mutator + `prepareForValidation`)**
- Apa itu: menyeragamkan bentuk data sebelum disimpan. Untuk SKU: buang spasi, ubah ke huruf besar.
- Kenapa dipakai: kalau semua SKU **disimpan** dalam huruf besar, perbandingan biasa (`=`) sudah otomatis "tidak membedakan huruf besar/kecil". `bhn-0001` yang diketik diubah menjadi `BHN-0001` sebelum dibandingkan, dan pasti bentrok dengan `BHN-0001` yang tersimpan.

**`Rule::unique(...)->ignore($id)`**
- Apa itu: aturan unik bawaan Laravel. `ignore($id)` mengecualikan baris yang sedang diubah, supaya bahan boleh menyimpan SKU-nya sendiri. Aturan ini **tidak** menyaring `deleted_at`, jadi bahan yang sudah di-soft delete ikut diperiksa.
- Kenapa dipakai: SKU bahan yang dihapus tetap "terpakai" (keputusan T-103), karena riwayat transaksinya masih menunjuk ke bahan itu.

## Alur kode langkah demi langkah

**A. Tambah: `POST /api/v1/items` (multipart kalau ada foto)**
1. Middleware: `auth:sanctum` → `active` → `role:admin` (Staf → 403).
2. `ItemRequest::prepareForValidation()` → `" bhn-0100 "` menjadi `"BHN-0100"`.
3. Validasi:

```php
'sku' => ['required', 'string', 'max:50', Rule::unique('items', 'sku')->ignore($currentItemId)],
'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
'min_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
'stock' => ['missing'],
```
- `decimal:0,2` → harga boleh tanpa desimal atau dengan maksimal 2 angka di belakang koma (sesuai kolom `decimal(12,2)`).
- `max:999999999` → harga maksimal Rp999.999.999 (keputusan Aldi). Contoh: `1000000000` atau `999999999.01` → 422 "Harga maksimal 999999999.".
- Pesan khusus di `messages()`: `sku.unique` → "SKU sudah digunakan oleh bahan lain, termasuk bahan yang sudah dihapus.", `stock.missing` → "Stok hanya bisa diubah lewat transaksi.".

4. `ItemController::store`:

```php
$item = new Item($this->itemData($request));   // hanya kolom fillable, tanpa stock & photo
$item->stock = 0;                               // bahan baru selalu stok 0
$newPhotoPath = $this->storePhoto($request);    // null kalau tidak ada foto
$item->photo_path = $newPhotoPath;

try {
    $item->save();
} catch (UniqueConstraintViolationException) {
    $this->deletePhoto($newPhotoPath);          // jangan tinggalkan file sampah
    $this->throwSkuTaken();                     // 422, pesan sama dengan validasi
}
```
- `$item->stock = 0` → **ditemukan oleh test**: tanpa baris ini, respons berisi `"stock": null`. Kolom memang punya default 0 di database, tapi objek `$item` di PHP tidak membaca ulang nilai itu setelah disimpan.
- Respons: `ItemResource` dengan kategori → **201 Created**.

**B. Ubah: `POST /api/v1/items/{item}` + `_method=PUT`**
1. Laravel melihat `_method=PUT` → route `update`. Route model binding: bahan terhapus/tidak ada → 404.
2. Validasi sama seperti tambah; `ignore($currentItemId)` membuat SKU milik sendiri tidak dianggap kembar.
3. `ItemController::update`:

```php
$oldPhotoPath = $item->photo_path;
$newPhotoPath = $this->storePhoto($request);
$item->fill($this->itemData($request));
if ($newPhotoPath !== null) {
    $item->photo_path = $newPhotoPath;
}
// save() dengan try/catch seperti store()
if ($newPhotoPath !== null) {
    $this->deletePhoto($oldPhotoPath);           // foto lama dihapus SETELAH simpan berhasil
}
```
- Tanpa foto baru → `photo_path` tidak disentuh, foto lama tetap.
- `stock` tidak pernah diubah: tidak fillable, tidak ada di `itemData()`, dan ditolak aturan `missing`.

**C. Hapus: `DELETE /api/v1/items/{item}`**

```php
$item->delete();                 // soft delete: isi deleted_at
return response()->noContent();  // 204
```
- File foto **tidak** dihapus, karena bahan masih ada di database dan riwayat transaksinya masih bisa menampilkan bahan itu.

## Keputusan desain

- **SKU huruf besar & unik tanpa membedakan huruf besar/kecil, termasuk bahan terhapus (permintaan Aldi).**
  - SKU dinormalisasi di dua tempat: mutator di model (untuk jalur mana pun) dan `prepareForValidation` (supaya validasi membandingkan bentuk yang sama).
  - **Tidak perlu expression index seperti kategori (T-205).** Kategori menyimpan nama apa adanya ("Bumbu & Rempah") untuk tampilan, jadi butuh index `lower(name)`. SKU selalu disimpan huruf besar, jadi index unik `items_sku_unique` yang sudah ada otomatis berlaku tanpa membedakan huruf besar/kecil. Sebelum memutuskan ini, Supabase dicek dengan query baca-saja: 0 SKU yang belum huruf besar, 0 SKU kembar.
  - Penolakan index (`UniqueConstraintViolationException`, kasus dua Admin menyimpan bersamaan) diubah menjadi 422 dengan pesan yang sama, dan foto yang terlanjur diupload dihapus lagi.
- **Field `stock` ditolak dengan `missing` (permintaan Aldi)**, termasuk nilai `0`. Ini lapisan ketiga setelah `stock` tidak fillable (T-103) dan tidak termasuk di `itemData()`.
- **Foto lama dihapus saat diganti; foto tidak dihapus saat soft delete (permintaan Aldi).** Urutannya: simpan foto baru → simpan data → hapus foto lama, supaya foto tidak hilang kalau ada kegagalan di tengah.
- **Satu `ItemRequest` untuk tambah dan ubah**, sama seperti `CategoryRequest`. Ubah memakai semua field wajib (PUT = ganti seluruh data), karena form di aplikasi selalu mengirim semua field.
- **Batas `min_stock` maksimal 1.000.000** dan **`description` maksimal 1000 karakter**, supaya input tidak masuk akal tertolak lebih awal (kolom `description` bertipe `text`, jadi batas ini murni aturan aplikasi). Disetujui Aldi.
- **Harga maksimal Rp999.999.999 (permintaan Aldi).** Kolom `decimal(12,2)` bisa menampung sampai 9.999.999.999,99 (10 digit sebelum koma + 2 desimal). Kalau aplikasi mengirim angka yang lebih besar dari kapasitas kolom tanpa batas di validasi, PostgreSQL menolak (*numeric field overflow*) dan pengguna mendapat **500**. Dengan batas 999.999.999, ada jarak aman yang jauh dari kapasitas kolom, dan angkanya tetap masuk akal untuk harga bahan dapur (bahan termahal di data contoh, beras sekarung, Rp340.000). Ada test untuk batas tepat (999999999 → 201) dan di atasnya (1000000000, 999999999.01, 99999999999 → 422).
- **Test kategori memakai nama yang tidak ada di `CategoryFactory::NAMES`.** Saat pertama dijalankan, beberapa test gagal secara acak karena kategori uji "Sayur & Lalapan" kadang bentrok dengan kategori acak dari `Item::factory()` (index `lower(name)` dari T-205 bekerja dengan benar). Setelah nama diganti, test dijalankan 6 kali berturut-turut dan selalu lulus.

## Cara mengetes sendiri

Jalankan dari `D:\dev\stokku\stokku-api` di PowerShell.

1. Test otomatis:
   ```powershell
   php artisan test --filter=ItemManageTest
   php artisan test
   php artisan route:list --path=api/v1/items -v
   ```
   Harapan: 23 test lulus; total 157 test lulus; POST/PUT/DELETE punya `EnsureUserHasRole:admin`.

2. Thunder Client: langkah lengkap ada di laporan T-207 (tambah dengan foto, ubah dengan `_method=PUT`, hapus, SKU kembar, Staf 403). Catatan: bahan yang dihapus tetap ada di database (soft delete), jadi SKU yang dipakai saat uji tidak bisa dipakai lagi. Pakai SKU khusus uji seperti `UJI-0001`.

## Kalau error, cek ini

- **Foto tidak tersimpan saat ubah / semua field dianggap kosong** → request dikirim sebagai `PUT` multipart sungguhan. Ganti menjadi `POST` dan tambahkan field `_method` = `PUT` di form.
- **422 "Stok hanya bisa diubah lewat transaksi."** → form mengirim field `stock`. Hapus field itu; stok diubah lewat transaksi (T-208).
- **422 "SKU sudah digunakan..." padahal tidak ada bahan dengan SKU itu di daftar** → SKU itu milik bahan yang sudah dihapus (soft delete). Cek dengan Tinker: `App\Models\Item::withTrashed()->where('sku', 'BHN-0031')->first()`.
- **`photo_url` 404 setelah upload** → `php artisan storage:link` belum dijalankan di komputer/server itu (lihat T-204).

## Bahan menjelaskan saat wawancara

**1. "Kenapa update bahan memakai POST dengan _method=PUT?"**
Karena PHP hanya membaca isi multipart pada request POST. Kalau aplikasi mengirim PUT multipart untuk mengganti foto, server menerima field dan file kosong. Jadi aplikasi mengirim POST dengan field `_method=PUT`, dan Laravel mengarahkannya ke route update. Teknik ini disebut method spoofing.

**2. "Bagaimana kamu memastikan stok tidak diubah lewat form bahan?"**
Ada tiga lapis. `stock` tidak masuk fillable di model, controller hanya mengambil field tertentu, dan Form Request memakai aturan `missing`, sehingga request yang membawa field `stock`, walaupun nilainya 0, langsung ditolak dengan pesan "Stok hanya bisa diubah lewat transaksi." Stok hanya berubah lewat transaksi, supaya selalu ada jejaknya.

**3. "Kenapa SKU tidak butuh expression index seperti nama kategori?"**
Karena SKU selalu disimpan dalam huruf besar lewat mutator. Kalau semua data tersimpan dalam bentuk yang sama, index unik biasa sudah cukup. Nama kategori berbeda: disimpan apa adanya untuk tampilan, jadi butuh index pada `lower(name)`.

## Coba sendiri (latihan)

Tambahkan endpoint `POST /api/v1/items/{item}/restore` (khusus Admin) untuk memulihkan bahan yang sudah di-soft delete. Petunjuk: route model binding biasa tidak menemukan bahan terhapus, jadi pakai `->withTrashed()` pada definisi route, lalu `$item->restore()`. Tulis test: hapus bahan, pulihkan, lalu pastikan `GET /api/v1/items/{id}` kembali 200 dan Staf yang mencoba mendapat 403.
