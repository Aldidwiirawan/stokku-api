<?php

namespace App\Rules;

use App\Models\Category;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Nama kategori harus unik TANPA membedakan huruf besar/kecil.
 * "Bumbu & Rempah" dan "bumbu & rempah" dianggap sama.
 *
 * Aturan bawaan `unique` memakai `=`, yang di PostgreSQL membedakan huruf
 * besar/kecil, jadi dibuat aturan sendiri.
 */
class UniqueCategoryName implements ValidationRule
{
    /**
     * @param  int|null  $ignoreId  id kategori yang sedang diubah (boleh memakai namanya sendiri)
     */
    public function __construct(private ?int $ignoreId = null) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return; // tipe salah sudah ditangani aturan 'string'
        }

        $exists = Category::query()
            // lower() tersedia di PostgreSQL dan SQLite.
            ->whereRaw('lower(name) = ?', [Str::lower(trim($value))])
            ->when($this->ignoreId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            $fail('Nama kategori sudah digunakan.');
        }
    }
}
