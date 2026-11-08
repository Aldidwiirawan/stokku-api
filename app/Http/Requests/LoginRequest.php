<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    /**
     * Login boleh dicoba siapa saja (belum ada pengguna yang login).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Dijalankan sebelum validasi. Keyboard HP sering membuat huruf pertama
     * kapital ("Admin@stokku.test"), jadi email diubah ke huruf kecil dulu.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            // Nama perangkat menjadi nama token, contoh: "Samsung A15 - Aldi".
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
