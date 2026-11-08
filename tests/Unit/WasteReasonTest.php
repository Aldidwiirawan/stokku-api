<?php

namespace Tests\Unit;

use App\Enums\WasteReason;
use PHPUnit\Framework\TestCase;

class WasteReasonTest extends TestCase
{
    public function test_values_match_the_prd(): void
    {
        $values = array_map(fn (WasteReason $reason) => $reason->value, WasteReason::cases());

        $this->assertSame(['expired', 'damaged', 'cooking_error', 'other'], $values);
    }

    public function test_labels_are_in_indonesian(): void
    {
        $this->assertSame('Kedaluwarsa', WasteReason::Expired->label());
        $this->assertSame('Rusak/Busuk', WasteReason::Damaged->label());
        $this->assertSame('Gosong/Salah Olah', WasteReason::CookingError->label());
        $this->assertSame('Lainnya', WasteReason::Other->label());
    }
}
