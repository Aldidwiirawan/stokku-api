<?php

namespace Tests\Unit;

use App\Enums\MovementType;
use PHPUnit\Framework\TestCase;

class MovementTypeTest extends TestCase
{
    public function test_values_match_the_prd(): void
    {
        $values = array_map(fn (MovementType $type) => $type->value, MovementType::cases());

        $this->assertSame(['in', 'out', 'waste', 'adjust'], $values);
    }

    public function test_labels_are_in_indonesian(): void
    {
        $this->assertSame('Masuk', MovementType::In->label());
        $this->assertSame('Keluar', MovementType::Out->label());
        $this->assertSame('Terbuang', MovementType::Waste->label());
        $this->assertSame('Penyesuaian', MovementType::Adjust->label());
    }

    public function test_can_be_created_from_database_value(): void
    {
        $this->assertSame(MovementType::Waste, MovementType::from('waste'));
        $this->assertNull(MovementType::tryFrom('hilang'));
    }
}
