<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi query string GET /items, contoh:
 * /api/v1/items?search=ayam&category_id=1&low_stock=1&page=2
 */
class ItemIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            // Kategori yang tidak ada cukup menghasilkan daftar kosong (tanpa query tambahan).
            'category_id' => ['nullable', 'integer'],
            // boolean menerima 1/0 (juga true/false dari JSON).
            'low_stock' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
