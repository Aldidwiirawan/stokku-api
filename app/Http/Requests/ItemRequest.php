<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Dipakai untuk tambah (POST /items) dan ubah (PUT /items/{item}) bahan.
 * Hak akses Admin sudah dicek middleware role:admin di route.
 */
class ItemRequest extends FormRequest
{
    public const SKU_TAKEN_MESSAGE = 'SKU sudah digunakan oleh bahan lain, termasuk bahan yang sudah dihapus.';

    public const STOCK_NOT_ALLOWED_MESSAGE = 'Stok hanya bisa diubah lewat transaksi.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * SKU diubah ke huruf besar sebelum validasi, supaya "bhn-0001" dibandingkan
     * sebagai "BHN-0001" (semua SKU di database disimpan huruf besar).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => Str::upper(trim($this->input('sku')))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Saat ubah, route berisi bahan yang sedang diubah; saat tambah, null.
        $currentItemId = $this->route('item')?->id;

        return [
            'sku' => [
                'required', 'string', 'max:50',
                // unique bawaan tidak menyaring deleted_at, jadi SKU bahan yang
                // sudah di-soft delete juga dianggap terpakai.
                Rule::unique('items', 'sku')->ignore($currentItemId),
            ],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'unit' => ['required', 'string', 'max:20'],
            // Maksimal 2 angka di belakang koma. Batas Rp999.999.999 jauh di bawah kapasitas
            // kolom decimal(12,2) (9.999.999.999,99), jadi database tidak akan pernah menolak (500).
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'min_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'description' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            // missing: field "stock" tidak boleh ADA sama sekali di request,
            // apa pun isinya (termasuk 0 atau kosong).
            'stock' => ['missing'],
        ];
    }

    /**
     * Pesan khusus untuk aturan tertentu (menggantikan pesan umum di lang/id/validation.php).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.unique' => self::SKU_TAKEN_MESSAGE,
            'stock.missing' => self::STOCK_NOT_ALLOWED_MESSAGE,
        ];
    }
}
