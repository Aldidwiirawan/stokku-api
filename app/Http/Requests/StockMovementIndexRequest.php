<?php

namespace App\Http\Requests;

use App\Enums\MovementType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/stock-movements?type=&from=&to=&item_id=&page=
 * from/to berformat Y-m-d dan dibaca sebagai tanggal WIB (zona bisnis).
 */
class StockMovementIndexRequest extends FormRequest
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
            'type' => ['nullable', Rule::enum(MovementType::class)],
            'item_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.',
        ];
    }

    /**
     * Awal hari "from" di zona bisnis, diubah ke UTC.
     * Contoh: from=2026-11-09 → 2026-11-09 00:00:00 WIB → 2026-11-08 17:00:00 UTC.
     */
    public function fromInUtc(): ?Carbon
    {
        $from = $this->validated('from');

        if ($from === null) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $from, config('app.business_timezone'))
            ->startOfDay()
            ->utc();
    }

    /**
     * Akhir hari "to" di zona bisnis, diubah ke UTC.
     * Contoh: to=2026-11-09 → 2026-11-09 23:59:59 WIB → 2026-11-09 16:59:59 UTC.
     */
    public function toInUtc(): ?Carbon
    {
        $to = $this->validated('to');

        if ($to === null) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $to, config('app.business_timezone'))
            ->endOfDay()
            ->utc();
    }
}
