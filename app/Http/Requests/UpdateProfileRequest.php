<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Setiap pengguna yang login boleh mengubah profilnya sendiri
     * (route ini sudah dijaga auth:sanctum + active).
     */
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
            'name' => ['required', 'string', 'max:255'],
            // Password opsional. Kalau diisi, wajib ada password_confirmation yang sama.
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            // Wajib kalau password diisi; dicek dengan password yang tersimpan
            // milik pengguna yang login lewat guard sanctum.
            'current_password' => ['required_with:password', 'nullable', 'string', 'current_password:sanctum'],
        ];
    }
}
