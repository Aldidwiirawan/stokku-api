# T-101 — Kolom `role`, `is_active`, `photo_path` di tabel users

## Ringkasan
Sekarang setiap pengguna punya **peran** (`admin` atau `staf`), **status aktif** (aktif/nonaktif), dan tempat menyimpan **path foto profil**. Pengguna baru otomatis menjadi `staf` yang aktif. Kode bisa bertanya "apakah orang ini admin?" lewat `$user->isAdmin()`.

## File yang dibuat / diubah
| File | Dibuat/Diubah | Fungsinya |
| --- | --- | --- |
| `database/migrations/2026_11_08_001602_add_role_and_is_active_to_users_table.php` | Dibuat | Menambah 3 kolom ke tabel `users` (`up()`) dan menghapusnya lagi (`down()`) |
| `app/Models/User.php` | Diubah | Cast `is_active` ke boolean, tambah helper `isAdmin()` |
| `database/factories/UserFactory.php` | Diubah | Data palsu default `role=staf`, `is_active=true`, plus state `admin()` |
| `tests/Feature/UserRoleTest.php` | Dibuat | Test otomatis: nilai default, state admin, dan `role` tidak bisa diisi lewat mass assignment |

## Konsep yang dipakai

**Migration**
- Apa itu: file PHP yang berisi "resep" perubahan struktur database. Mirip catatan renovasi rumah: "tambah jendela di kamar A". Semua orang yang menjalankan resep yang sama akan mendapat struktur database yang sama.
- Kenapa dipakai: supaya perubahan tabel `users` tercatat di Git dan bisa diulang (di Supabase, di laptop lain, di test), bukan diklik manual di Table Editor.

**`up()` dan `down()`**
- Apa itu: `up()` menjalankan perubahan, `down()` membatalkannya. Ibarat tombol "kerjakan" dan "undo".
- Kenapa dipakai: kriteria selesai T-101 meminta `down()` bisa membatalkan dengan benar. Jadi kalau ada salah, kita bisa `migrate:rollback` tanpa merusak kolom lain.

**`--table=users` vs membuat tabel baru**
- Apa itu: opsi `make:migration` yang membuat kerangka `Schema::table(...)` (ubah tabel yang **sudah ada**), bukan `Schema::create(...)` (buat tabel baru).
- Kenapa dipakai: tabel `users` sudah ada dan sudah berisi struktur bawaan Laravel. Kita tidak mengedit migration lama, tapi menambah migration baru. Migration lama sudah dijalankan di Supabase, jadi kalau diubah, perubahannya tidak akan ikut jalan.

**Nilai default di database**
- Apa itu: nilai yang otomatis diisi database kalau kolom tidak diisi saat `INSERT`.
- Kenapa dipakai: pengguna lama (dan pengguna baru yang dibuat tanpa menyebut `role`) otomatis jadi `staf` yang aktif. Ini pilihan paling aman: hak akses paling rendah.

**Cast (`casts()`)**
- Apa itu: aturan di model untuk mengubah tipe data saat dibaca dari / ditulis ke database.
- Kenapa dipakai: di beberapa database, boolean disimpan sebagai angka `1`/`0` (contoh: SQLite untuk test). Dengan cast `'is_active' => 'boolean'`, di PHP nilainya selalu `true`/`false`. Nanti di JSON API pun keluar `true`/`false`, bukan `1`.

**Mass assignment dan `#[Fillable]`**
- Apa itu: mass assignment = mengisi banyak kolom sekaligus dari array, misalnya `User::create($request->all())`. `#[Fillable]` adalah daftar kolom yang **boleh** diisi dengan cara itu. Kolom di luar daftar akan diabaikan.
- Kenapa dipakai: `role` sengaja **tidak** dimasukkan ke Fillable. Kalau masuk, orang jahat bisa mengirim `"role": "admin"` di body request dan langsung jadi admin. Dengan begini, `role` hanya bisa diisi secara eksplisit di kode, misalnya `$user->role = 'admin';`.

**Factory dan state**
- Apa itu: factory = pabrik data palsu untuk test/seeder. State = variasi dari data default, misalnya "pengguna yang admin".
- Kenapa dipakai: nanti di test kita cukup menulis `User::factory()->admin()->create()` untuk mendapat admin, tanpa mengulang detail kolomnya.

## Alur kode langkah demi langkah

1. Aldi/Claude menjalankan `php artisan migrate`.
2. Laravel mengecek tabel `migrations` di database untuk melihat file mana yang **belum** pernah dijalankan. Yang belum hanya file T-101 ini.
3. Laravel memanggil `up()`:

```php
Schema::table('users', function (Blueprint $table) {
    // Nilai yang dipakai: 'admin' atau 'staf'.
    $table->string('role')->default('staf');
    $table->boolean('is_active')->default(true);
    $table->string('photo_path')->nullable();
});
```
- `Schema::table('users', ...)` → ubah tabel `users` yang sudah ada.
- `string('role')->default('staf')` → kolom teks, kalau tidak diisi otomatis `staf`.
- `boolean('is_active')->default(true)` → kolom benar/salah, default aktif.
- `string('photo_path')->nullable()` → boleh kosong (`NULL`), karena tidak semua orang punya foto.

4. Laravel mencatat nama file ini ke tabel `migrations` dengan nomor batch baru (batch 3). Batch = kelompok migration yang dijalankan bersamaan; `migrate:rollback` membatalkan satu batch terakhir.
5. Kalau nanti `php artisan migrate:rollback` dijalankan, Laravel memanggil `down()`:

```php
$table->dropColumn(['role', 'is_active', 'photo_path']);
```
- Menghapus tepat 3 kolom yang ditambahkan `up()`, tidak menyentuh kolom lain.

6. Di sisi model, saat data user dibaca:

```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',   // 1/0 → true/false
    ];
}

public function isAdmin(): bool
{
    return $this->role === 'admin';
}
```
- `isAdmin()` membandingkan dengan `===` (sama nilai **dan** tipe), jadi hasilnya pasti `true` atau `false`.
- Helper ini nanti dipakai middleware role (T-2xx) supaya pengecekan admin ditulis di satu tempat saja.

## Keputusan desain

- **Migration baru, bukan mengedit migration `create_users_table`.** Migration lama sudah jalan di Supabase. Mengeditnya tidak akan berpengaruh kecuali `migrate:fresh`, yang menghapus semua data.
- **`role` berupa `string`, bukan tipe `enum` database.** Alternatifnya `$table->enum('role', ['admin','staf'])`. Dipilih `string` sesuai PRD karena lebih mudah diubah nanti (menambah peran baru tidak perlu mengubah tipe kolom). Validasi nilai `admin`/`staf` akan dilakukan di Form Request saat endpoint pengguna dibuat.
- **`role`, `is_active`, `photo_path` tidak masuk `#[Fillable]`.** PRD hanya mewajibkan `role`. Tapi `is_active` (diubah admin lewat endpoint khusus `PATCH /users/{id}/status`) dan `photo_path` (diisi dari hasil upload, bukan dari input teks) juga sebaiknya diisi secara eksplisit. Jadi Fillable tetap `name`, `email`, `password`. Kalau nanti ternyata merepotkan, mudah ditambahkan.
- **Tanpa `->after('password')`.** `after()` hanya berlaku di MySQL; di PostgreSQL diabaikan. Supaya tidak menyesatkan, tidak dipakai. Kolom baru otomatis berada di urutan paling akhir tabel.
- **Nama migration memakai `add_role_and_is_active_...` walaupun juga menambah `photo_path`.** Mengikuti nama yang ditulis di TASKS.md supaya konsisten dengan dokumen.
- **`down()` diuji di SQLite sementara, bukan di Supabase.** Supaya data di Supabase tidak tersentuh saat menguji rollback.

## Cara mengetes sendiri

Jalankan dari folder `D:\dev\stokku\stokku-api` di PowerShell.

1. Cek status migration:
   ```powershell
   php artisan migrate:status
   ```
   Harapan: baris `2026_11_08_001602_add_role_and_is_active_to_users_table` berstatus `Ran` di batch `[3]`.

2. Jalankan test otomatis (memakai SQLite in-memory, tidak menyentuh Supabase):
   ```powershell
   php artisan test
   ```
   Harapan: semua test lulus (5 test), termasuk 3 test di `UserRoleTest`.

3. Cek di Supabase: buka dashboard → **Table Editor** → tabel `users`. Harapan: ada kolom `role` (text, default `'staf'`), `is_active` (bool, default `true`), `photo_path` (text, boleh null).

4. Coba di Tinker (opsional; contoh ini memakai `make()` jadi **tidak** menyimpan apa pun ke Supabase):
   ```powershell
   php artisan tinker
   ```
   ```php
   $u = App\Models\User::factory()->admin()->make();  // make() = tidak disimpan ke DB
   $u->isAdmin();      // true
   $u->is_active;      // true
   ```

## Kalau error, cek ini

- **`SQLSTATE[42701]: Duplicate column: column "role" of relation "users" already exists`** → kolom sudah pernah ditambahkan (misalnya manual lewat Table Editor). Hapus kolom manual itu di Supabase, atau cek `migrate:status`; jangan jalankan `migrate:fresh` tanpa pikir panjang karena menghapus semua data.
- **`Nothing to migrate.`** → bukan error; artinya migration ini sudah jalan sebelumnya. Cek dengan `php artisan migrate:status`.
- **Koneksi ke Supabase timeout / `could not translate host name`** → cek internet dan pastikan `.env` memakai host **Session pooler** (port 5432). Jangan bagikan isi `.env`.
- **Test gagal: `no such column: role`** → test memakai database kosong dan menjalankan semua migration dari awal. Pastikan test memakai trait `RefreshDatabase` dan file migration ada di `database/migrations`.

## Bahan menjelaskan saat wawancara

**1. "Kenapa membuat migration baru, bukan mengedit migration users yang lama?"**
Migration lama sudah dijalankan di database. Laravel mencatatnya sebagai "sudah selesai", jadi kalau diedit, perubahannya tidak akan jalan lagi. Migration itu seperti riwayat perubahan: yang lama tidak diubah, perubahan baru ditambahkan sebagai langkah baru. Dengan begitu tim lain dan server produksi bisa mengikuti urutan yang sama.

**2. "Kenapa role tidak dimasukkan ke fillable?"**
Untuk mencegah celah mass assignment. Kalau `role` fillable, pengguna bisa mengirim `role: admin` di request dan langsung jadi admin. Jadi `role` hanya diisi secara sengaja di kode, oleh admin, setelah lolos pengecekan hak akses di server.

**3. "Apa gunanya `down()`?"**
Untuk membatalkan migration. Kalau ada kesalahan setelah deploy, kita bisa rollback dengan aman. `down()` harus kebalikan persis dari `up()`: di sini `up()` menambah tiga kolom, `down()` menghapus tiga kolom yang sama.

## Coba sendiri (latihan)

Tambahkan kolom `phone` (nomor HP, boleh kosong, maksimal 20 karakter) ke tabel `users` dengan migration **baru**. Pastikan `down()` membatalkannya. Ujilah di SQLite atau lewat `php artisan test`, bukan langsung `migrate:rollback` di Supabase. Setelah selesai, pikirkan: apakah `phone` sebaiknya masuk `#[Fillable]`? Kenapa? (Hapus migration latihan itu kalau tidak jadi dipakai.)
