<?php

namespace App\Http\Requests;

use App\Rules\UniqueCategoryName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Dipakai untuk tambah (POST) dan ubah (PUT) kategori.
 * Hak akses Admin sudah dicek middleware role:admin di route.
 */
class CategoryRequest extends FormRequest
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
        // Saat ubah, route berisi kategori yang sedang diubah: /categories/{category}.
        // Saat tambah, tidak ada, jadi null.
        $currentCategoryId = $this->route('category')?->id;

        return [
            'name' => ['required', 'string', 'max:100', new UniqueCategoryName($currentCategoryId)],
        ];
    }
}
