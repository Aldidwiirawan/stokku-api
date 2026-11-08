<?php

/*
|--------------------------------------------------------------------------
| Pesan validasi Bahasa Indonesia
|--------------------------------------------------------------------------
| Hanya aturan yang dipakai di StokKu. Aturan yang tidak ada di file ini
| otomatis memakai pesan bahasa Inggris (APP_FALLBACK_LOCALE=en).
| :attribute diganti dengan nama field dari bagian 'attributes' di bawah.
*/

return [

    'after_or_equal' => ':Attribute harus tanggal setelah atau sama dengan :date.',
    'array' => ':Attribute harus berupa daftar.',
    'before_or_equal' => ':Attribute harus tanggal sebelum atau sama dengan :date.',
    'boolean' => ':Attribute harus bernilai benar atau salah.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Password lama salah.',
    'date' => ':Attribute bukan tanggal yang valid.',
    'date_format' => ':Attribute harus berformat :format.',
    'decimal' => ':Attribute harus memiliki :decimal angka di belakang koma.',
    'email' => ':Attribute harus berupa alamat email yang valid.',
    'enum' => ':Attribute yang dipilih tidak valid.',
    'exists' => ':Attribute yang dipilih tidak ditemukan.',
    'gt' => [
        'numeric' => ':Attribute harus lebih dari :value.',
    ],
    'gte' => [
        'numeric' => ':Attribute harus lebih dari atau sama dengan :value.',
    ],
    'image' => ':Attribute harus berupa gambar.',
    'in' => ':Attribute yang dipilih tidak valid.',
    'integer' => ':Attribute harus berupa bilangan bulat.',
    'max' => [
        'array' => ':Attribute maksimal berisi :max item.',
        'file' => ':Attribute maksimal :max kilobyte.',
        'numeric' => ':Attribute maksimal :max.',
        'string' => ':Attribute maksimal :max karakter.',
    ],
    'mimes' => ':Attribute harus berupa file bertipe: :values.',
    'missing' => ':Attribute tidak boleh dikirim.',
    'min' => [
        'array' => ':Attribute minimal berisi :min item.',
        'file' => ':Attribute minimal :min kilobyte.',
        'numeric' => ':Attribute minimal :min.',
        'string' => ':Attribute minimal :min karakter.',
    ],
    'numeric' => ':Attribute harus berupa angka.',
    'prohibited' => ':Attribute tidak boleh diisi.',
    'prohibited_unless' => ':Attribute hanya boleh diisi jika :other adalah :values.',
    'required' => ':Attribute wajib diisi.',
    'required_if' => ':Attribute wajib diisi jika :other adalah :value.',
    'required_with' => ':Attribute wajib diisi jika :values diisi.',
    'string' => ':Attribute harus berupa teks.',
    'unique' => ':Attribute sudah digunakan.',
    'uploaded' => ':Attribute gagal diunggah.',

    /*
    | Nama field yang ditampilkan ke pengguna (menggantikan :attribute).
    */
    'attributes' => [
        'name' => 'nama',
        'email' => 'email',
        'password' => 'password',
        'current_password' => 'password lama',
        'device_name' => 'nama perangkat',
        'role' => 'peran',
        'is_active' => 'status aktif',
        'photo' => 'foto',
        'sku' => 'SKU',
        'category_id' => 'kategori',
        'unit' => 'satuan',
        'price' => 'harga',
        'min_stock' => 'stok minimum',
        'stock' => 'stok',
        'description' => 'keterangan',
        'item_id' => 'bahan',
        'type' => 'jenis transaksi',
        'quantity' => 'jumlah',
        'reason' => 'alasan',
        'note' => 'catatan',
        'from' => 'tanggal mulai',
        'to' => 'tanggal akhir',
        'search' => 'kata kunci',
        'low_stock' => 'filter stok menipis',
        'page' => 'halaman',
    ],

    /*
    | Terjemahan nilai untuk :value pada aturan seperti required_if.
    | Contoh: "Alasan wajib diisi jika jenis transaksi adalah terbuang."
    */
    'values' => [
        'type' => [
            'in' => 'masuk',
            'out' => 'keluar',
            'waste' => 'terbuang',
            'adjust' => 'penyesuaian',
        ],
        'reason' => [
            'other' => 'lainnya',
        ],
    ],

];
