<?php

namespace Tests\Unit;

use App\Models\Child;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_it_classifies_muac_at_each_threshold(): void
    {
        $this->assertSame('SAM', Child::classifyMuac(110));
        $this->assertSame('SAM', Child::classifyMuac(115));
        $this->assertSame('MAM', Child::classifyMuac(120));
        $this->assertSame('Normal', Child::classifyMuac(125));
        $this->assertSame('Normal', Child::classifyMuac(130));
    }

    public function test_setting_muac_sets_the_derived_fi_value(): void
    {
        $child = new Child();
        $child->muac_mm = 120;

        $this->assertSame('MAM', $child->fi);
    }
}
