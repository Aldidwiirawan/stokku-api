<?php

namespace App\Http\Requests;

use App\Enums\MovementType;
use App\Enums\WasteReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/stock-movements
 * Body: item_id, type (in/out/waste/adjust), quantity, reason (khusus waste), note.
 */
class StoreStockMovementRequest extends FormRequest
{
    /**
     * Semua peran boleh mencatat masuk/keluar/terbuang.
     * Penyesuaian (adjust) hanya untuk Admin (PRD §4).
     */
    public function authorize(): bool
    {
        if ($this->input('type') === MovementType::Adjust->value) {
            return $this->user()->isAdmin();
        }

        return true;
    }

    /**
     * Dipanggil Laravel saat authorize() mengembalikan false → 403 dengan pesan jelas.
     */
    protected function failedAuthorization(): never
    {
        abort(403, 'Penyesuaian stok hanya bisa dilakukan Admin.');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Untuk adjust, quantity = stok fisik, jadi boleh 0 (bahan habis saat dihitung).
        // Untuk in/out/waste, jumlah minimal 1.
        $minimumQuantity = $this->input('type') === MovementType::Adjust->value ? 0 : 1;

        return [
            // Hanya bahan aktif; bahan yang sudah di-soft delete tidak bisa ditransaksikan.
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::enum(MovementType::class)],
            'quantity' => ['required', 'integer', 'min:'.$minimumQuantity, 'max:1000000'],
            // Alasan wajib untuk waste, dan tidak boleh diisi untuk jenis lain.
            'reason' => ['nullable', Rule::enum(WasteReason::class), 'required_if:type,waste', 'prohibited_unless:type,waste'],
            // Catatan opsional, tapi wajib kalau alasannya "other" (lainnya).
            'note' => ['nullable', 'string', 'max:255', 'required_if:reason,other'],
        ];
    }
}
